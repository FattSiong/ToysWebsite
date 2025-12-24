<?php
include '../includes/header.php';

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='../login.php';</script>";
    exit;
}

$order_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

// 2. Fetch Order Details (Kept your original query with receiver_name)
$sqlOrder = "SELECT o.*, sa.receiver_name, sa.line1, sa.city, sa.state, sa.postcode, sa.phone 
             FROM orders o 
             JOIN shipping_addresses sa ON o.shipping_address_id = sa.shipping_address_id
             WHERE o.order_id = ? AND o.user_id = ?";
$stmt = $pdo->prepare($sqlOrder);
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if (!$order) {
    echo "<div style='text-align:center; padding:50px;'><h3>Order not found.</h3><a href='orders.php'>Back</a></div>";
    include '../includes/footer.php';
    exit;
}

// 3. Determine Progress, Labels & Refund Eligibility
$status = $order['order_status'];
$step = 1;

// --- ADDED: Refund Eligibility Logic ---
$order_date_ts = strtotime($order['order_date']);
$current_ts = time();
$days_diff = floor(($current_ts - $order_date_ts) / (60 * 60 * 24));

$is_delivered = (stripos($status, 'Delivered') !== false || stripos($status, 'Completed') !== false);
$is_refunded = (stripos($status, 'Refunded') !== false);
// Refund Eligibility: Status is Delivered AND within 14 days AND not already refunded
$can_refund = ($is_delivered && !$is_refunded && $days_diff <= 14);

// Default Labels & Icons
$label_step2 = "Packing";
$icon_step2  = "fa-box-open";
$class_step2 = "";

$label_step4 = "Delivered";
$icon_step4  = "fa-check-circle";
$class_step4 = "";

// LOGIC FOR SPECIAL STATUSES
if ($status == 'Cancelled') {
    $step = 2; // Stop at step 2
    $label_step2 = "Cancelled";
    $icon_step2  = "fa-times-circle";
    $class_step2 = "step-danger"; // Red
} elseif ($status == 'Refunded') {
    $step = 4; // Complete
    $label_step4 = "Refunded";
    $icon_step4  = "fa-undo";
    $class_step4 = "step-danger"; // Red
} else {
    // Normal Flow
    if (stripos($status, 'Processing') !== false) $step = 2;
    if (stripos($status, 'Shipped') !== false) $step = 3;
    if (stripos($status, 'Delivered') !== false || stripos($status, 'Completed') !== false) $step = 4;
}

$can_review = ($step == 4 && $status != 'Refunded');

// 4. Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // --- A. HANDLE REFUND REQUEST (ADDED) ---
    if (isset($_POST['confirm_refund'])) {
        $reason = $_POST['refund_reason'];

        if ($can_refund) {
            try {
                $pdo->beginTransaction();

                // 1. Update Order Status
                $stmtRef = $pdo->prepare("UPDATE orders SET order_status = 'Refunded', refund_reason = ? WHERE order_id = ?");
                $stmtRef->execute([$reason, $order_id]);

                // 2. Update Payment Status (Assuming table exists)
                $pdo->prepare("UPDATE payments SET status = 'Refunded' WHERE order_id = ?")->execute([$order_id]);

                // 3. Restock Items
                $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmtItems->execute([$order_id]);
                $itemsToRestock = $stmtItems->fetchAll();

                foreach ($itemsToRestock as $item) {
                    $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?")
                        ->execute([$item['quantity'], $item['product_id']]);
                }

                // 4. Reverse Points Earned (Take back points given for purchase)
                // Note: Checking if column exists to prevent crash if not in DB
                if (isset($order['points_earned']) && $order['points_earned'] > 0) {
                    $points_earned = $order['points_earned'];
                    $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")->execute([$points_earned, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Reversed (Refunded Order)')")
                        ->execute([$user_id, $order_id, -$points_earned]);
                }

                // 5. Return Points Redeemed (Give back points used for discount)
                if (isset($order['points_redeemed']) && $order['points_redeemed'] > 0) {
                    $points_redeemed = $order['points_redeemed'];
                    $pdo->prepare("UPDATE users SET current_points = current_points + ? WHERE user_id = ?")->execute([$points_redeemed, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Returned (Refunded Order)')")
                        ->execute([$user_id, $order_id, $points_redeemed]);
                }

                $pdo->commit();
                echo "<script>alert('Refund processed successfully.'); window.location.href='order_detail.php?id=$order_id';</script>";
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error processing refund: " . addslashes($e->getMessage()) . "');</script>";
            }
        }
    }

    // --- B. HANDLE REVIEW SUBMISSION (KEPT ORIGINAL) ---
    if (isset($_POST['submit_review'])) {
        $prod_id = $_POST['product_id'];
        $rating = $_POST['rating'];
        $comment = $_POST['comment'];

        $check = $pdo->prepare("SELECT review_id FROM reviews WHERE user_id = ? AND product_id = ?");
        $check->execute([$user_id, $prod_id]);

        if ($check->rowCount() == 0) {
            try {
                $pdo->beginTransaction();

                // Insert Review Text
                $stmtR = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
                $stmtR->execute([$prod_id, $user_id, $rating, $comment]);
                $new_review_id = $pdo->lastInsertId();

                // Handle File Uploads
                if (isset($_FILES['review_media']) && !empty($_FILES['review_media']['name'][0])) {
                    $target_dir = "../uploads/reviews/";
                    if (!file_exists($target_dir)) {
                        mkdir($target_dir, 0777, true);
                    }

                    $total_files = count($_FILES['review_media']['name']);

                    for ($i = 0; $i < $total_files; $i++) {
                        $file_name = $_FILES['review_media']['name'][$i];
                        $file_tmp = $_FILES['review_media']['tmp_name'][$i];
                        $file_type = $_FILES['review_media']['type'][$i];

                        // Generate Unique Name
                        $ext = pathinfo($file_name, PATHINFO_EXTENSION);
                        $new_file_name = "review_" . $new_review_id . "_" . time() . "_" . $i . "." . $ext;
                        $target_file = $target_dir . $new_file_name;

                        $media_type = (strpos($file_type, 'video') !== false) ? 'video' : 'image';

                        if (move_uploaded_file($file_tmp, $target_file)) {
                            $pdo->prepare("INSERT INTO review_media (review_id, file_path, media_type) VALUES (?, ?, ?)")
                                ->execute([$new_review_id, $target_file, $media_type]);
                        }
                    }
                }
                $pdo->commit();
                echo "<script>alert('Review submitted successfully!'); window.location.href='order_detail.php?id=$order_id';</script>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
            }
        }
    }
}

// 5. Fetch Order Items
$sqlItems = "SELECT oi.*, p.name, p.product_id, pm.file_path,
             (SELECT COUNT(*) FROM reviews r WHERE r.user_id = ? AND r.product_id = p.product_id) as has_reviewed
             FROM order_items oi
             JOIN products p ON oi.product_id = p.product_id
             LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1
             WHERE oi.order_id = ?";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$user_id, $order_id]);
$items = $stmtI->fetchAll();
?>

<link rel="stylesheet" href="../css/tracking.css">

<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
        <a href="orders.php" class="btn-back" style="margin-bottom:0;"><i class="fas fa-arrow-left"></i> Back to Orders</a>

        <div>
            <?php if ($can_refund): ?>
                <button onclick="openRefundModal()" class="btn-refund">
                    <i class="fas fa-undo"></i> Request Refund
                </button>
            <?php elseif ($is_refunded): ?>
                <span style="background:#dc3545; color:white; padding:8px 15px; border-radius:20px; font-size:0.9rem; font-weight:600;">Refunded</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="order-header-card">
        <div class="header-top">
            <span>Order #<?php echo $order['order_id']; ?></span>
            <div>
                <a href="receipt.php?order_id=<?php echo $order['order_id']; ?>" target="_blank" style="color:white; text-decoration:none; margin-right:15px; font-size:0.95rem;">
                    <i class="fas fa-file-pdf"></i> Receipt
                </a>
                <span><?php echo date('M d, Y • h:i A', strtotime($order['order_date'])); ?></span>
            </div>
        </div>
        <div class="header-body">
            <div style="display:flex; justify-content:space-between; flex-wrap: wrap; gap: 20px;">
                <div class="info-group">
                    <h5>Shipping Address</h5>
                    <div class="info-text">
                        <strong><?php echo htmlspecialchars($order['receiver_name']); ?></strong><br>
                        <?php echo htmlspecialchars($order['line1']); ?><br>
                        <?php echo htmlspecialchars($order['city'] . ', ' . $order['state'] . ' ' . $order['postcode']); ?><br>
                        Phone: <?php echo htmlspecialchars($order['phone']); ?>
                    </div>
                </div>
                <div class="info-group" style="text-align:right;">
                    <h5>Total Amount</h5>
                    <h2 class="total-amount">RM <?php echo number_format($order['total_amount'], 2); ?></h2>

                    <?php if ($is_refunded): ?>
                        <div style="margin-top:10px; color:#dc3545; font-size:0.9rem;">
                            <strong>Refunded</strong><br>
                            Reason: <?php echo htmlspecialchars($order['refund_reason'] ?? 'N/A'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="track-wrapper">
        <div class="track">
            <div class="step <?php echo $step >= 1 ? 'active' : ''; ?>">
                <span class="icon"><i class="fas fa-receipt"></i></span>
                <span class="text">Received</span>
            </div>

            <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $class_step2; ?>">
                <span class="icon"><i class="fas <?php echo $icon_step2; ?>"></i></span>
                <span class="text"><?php echo $label_step2; ?></span>
            </div>

            <div class="step <?php echo $step >= 3 ? 'active' : ''; ?>">
                <span class="icon"><i class="fas fa-shipping-fast"></i></span>
                <span class="text">On Way</span>
            </div>

            <div class="step <?php echo $step >= 4 ? 'active' : ''; ?> <?php echo $class_step4; ?>">
                <span class="icon"><i class="fas <?php echo $icon_step4; ?>"></i></span>
                <span class="text"><?php echo $label_step4; ?></span>
            </div>
        </div>
    </div>

    <div class="items-card">
        <div class="items-header">Items in this Order</div>

        <div class="table-scroll">
            <table class="table">
                <tbody>
                    <?php foreach ($items as $item):
                        $imgUrl = !empty($item['file_path']) ? "../" . $item['file_path'] : "https://via.placeholder.com/80";
                    ?>
                        <tr>
                            <td style="width: 120px;">
                                <img src="<?php echo htmlspecialchars($imgUrl); ?>" class="product-img" alt="Product">
                            </td>
                            <td>
                                <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="product-qty">Quantity: <?php echo $item['quantity']; ?></div>
                            </td>
                            <td class="item-price">
                                RM <?php echo number_format($item['price_at_purchase'], 2); ?>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($can_review): ?>
                                    <?php if ($item['has_reviewed'] > 0): ?>
                                        <button class="btn-reviewed" disabled><i class="fas fa-check"></i> Reviewed</button>
                                    <?php else: ?>
                                        <button class="btn-review"
                                            onclick="openReviewModal(<?php echo $item['product_id']; ?>, '<?php echo addslashes($item['name']); ?>')">
                                            <i class="far fa-star"></i> Rate Product
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size:0.85rem; color:#999; font-style:italic;">
                                        <?php echo ($status == 'Refunded' || $status == 'Cancelled') ? "Order " . $status : "Review after delivery"; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="reviewModal" class="custom-modal">
    <div class="modal-content">
        <form method="post" enctype="multipart/form-data">
            <div class="modal-header">
                <h4 style="margin:0;">Rate: <span id="modalProductName"></span></h4>
                <button type="button" onclick="closeReviewModal()" style="border:none; background:none; font-size:1.5rem; cursor:pointer;">&times;</button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="product_id" id="modalProductId">

                <label class="form-label">Your Rating</label>
                <select name="rating" class="form-select">
                    <option value="5">★★★★★ (5/5) Excellent</option>
                    <option value="4">★★★★☆ (4/5) Good</option>
                    <option value="3">★★★☆☆ (3/5) Average</option>
                    <option value="2">★★☆☆☆ (2/5) Poor</option>
                    <option value="1">★☆☆☆☆ (1/5) Terrible</option>
                </select>

                <label class="form-label">Comment</label>
                <textarea name="comment" class="form-control" rows="3" placeholder="Write your experience..." required></textarea>

                <label class="form-label">Add Photos/Videos</label>
                <div class="upload-area" id="uploadArea">
                    <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <p class="upload-text">Drag & Drop files here or <span class="browse-link">Browse</span></p>
                    <input type="file" name="review_media[]" id="fileInput" multiple accept="image/*,video/*" style="display:none;">
                </div>
                <div id="filePreview" class="file-preview-container"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-review" style="border-color:#ccc; color:#333; margin-right:10px;" onclick="closeReviewModal()">Cancel</button>
                <button type="submit" name="submit_review" class="btn-review" style="background:#007bff; color:white;">Submit Review</button>
            </div>
        </form>
    </div>
</div>

<div id="refundModal" class="custom-modal">
    <div class="modal-content">
        <form method="post">
            <div class="modal-header" style="background:#dc3545; color:white;">
                <h4 style="margin:0;"><i class="fas fa-undo"></i> Request Refund</h4>
                <button type="button" onclick="closeRefundModal()" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:white;">&times;</button>
            </div>

            <div class="modal-body">
                <p style="color:#666; font-size:0.9rem;"><strong>Note:</strong> Refunds are only available within 14 days of delivery.</p>

                <label class="form-label">Reason for Refund</label>
                <select name="refund_reason" class="form-select" required>
                    <option value="">Select a reason...</option>
                    <option value="Item defective/damaged">Item defective/damaged</option>
                    <option value="Wrong item received">Wrong item received</option>
                    <option value="Item does not match description">Item does not match description</option>
                    <option value="Missing parts/accessories">Missing parts/accessories</option>
                    <option value="Change of mind">Change of mind</option>
                    <option value="Other">Other</option>
                </select>

                <div style="background:#fff3cd; color:#856404; padding:15px; border-radius:8px; margin-top:15px; font-size:0.9rem;">
                    <i class="fas fa-exclamation-triangle"></i> By confirming, your payment will be refunded, and points earned from this purchase will be deducted.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-review" style="border-color:#ccc; color:#333; margin-right:10px;" onclick="closeRefundModal()">Cancel</button>
                <button type="submit" name="confirm_refund" class="btn-review" style="background:#dc3545; color:white; border-color:#dc3545;">Confirm Refund</button>
            </div>
        </form>
    </div>
</div>

<script>
    // --- REVIEW MODAL LOGIC ---
    var modal = document.getElementById("reviewModal");
    var uploadArea = document.getElementById("uploadArea");
    var fileInput = document.getElementById("fileInput");
    var filePreview = document.getElementById("filePreview");
    var allFiles = [];

    function openReviewModal(id, name) {
        document.getElementById('modalProductId').value = id;
        document.getElementById('modalProductName').innerText = name;
        allFiles = [];
        updateFileInput();
        modal.style.display = "block";
    }

    function closeReviewModal() {
        modal.style.display = "none";
    }

    // --- REFUND MODAL LOGIC ---
    var refundModal = document.getElementById("refundModal");

    function openRefundModal() {
        refundModal.style.display = "block";
    }

    function closeRefundModal() {
        refundModal.style.display = "none";
    }

    // Close Modals on Outside Click
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
        if (event.target == refundModal) {
            refundModal.style.display = "none";
        }
    }

    // --- DRAG & DROP & UPLOAD LOGIC (Kept from original) ---
    uploadArea.addEventListener('click', (e) => {
        if (e.target !== fileInput) fileInput.click();
    });

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('highlight');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('highlight');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('highlight');
        const droppedFiles = e.dataTransfer.files;
        handleNewFiles(droppedFiles);
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) handleNewFiles(fileInput.files);
    });

    function handleNewFiles(files) {
        Array.from(files).forEach(file => {
            const exists = allFiles.some(f => f.name === file.name && f.size === file.size);
            if (!exists) allFiles.push(file);
        });
        updateFileInput();
    }

    function removeFile(index) {
        allFiles.splice(index, 1);
        updateFileInput();
    }

    function updateFileInput() {
        const dataTransfer = new DataTransfer();
        allFiles.forEach(file => dataTransfer.items.add(file));
        fileInput.files = dataTransfer.files;
        renderPreviews();
    }

    function renderPreviews() {
        filePreview.innerHTML = "";
        allFiles.forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const div = document.createElement('div');
                div.className = 'preview-item';
                let content = `<div class="remove-btn" onclick="event.stopPropagation(); removeFile(${index})">&times;</div>`;
                if (file.type.startsWith('image/')) {
                    content += `<img src="${e.target.result}"><span class="file-type-badge">IMG</span>`;
                } else if (file.type.startsWith('video/')) {
                    content += `<video src="${e.target.result}"></video><span class="file-type-badge">VID</span>`;
                } else {
                    content += `<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:10px;">FILE</div>`;
                }
                div.innerHTML = content;
                filePreview.appendChild(div);
            }
            reader.readAsDataURL(file);
        });
    }
</script>

<?php include '../includes/footer.php'; ?>