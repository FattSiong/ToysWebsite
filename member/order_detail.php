<?php
include '../includes/header.php';

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='../login.php';</script>";
    exit;
}

$order_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

// 2. Fetch Order Details (ADDED: sa.receiver_name)
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

// 3. Determine Progress & Labels
$status = $order['order_status'];
$step = 1;

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

// 4. Handle Review Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_review'])) {
    $prod_id = $_POST['product_id'];
    $rating = $_POST['rating'];
    $comment = $_POST['comment'];

    $check = $pdo->prepare("SELECT review_id FROM reviews WHERE user_id = ? AND product_id = ?");
    $check->execute([$user_id, $prod_id]);

    if ($check->rowCount() == 0) {
        try {
            $pdo->beginTransaction();

            // A. Insert Review Text
            $stmtR = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
            $stmtR->execute([$prod_id, $user_id, $rating, $comment]);
            $new_review_id = $pdo->lastInsertId();

            // B. Handle File Uploads (Images & Videos)
            if (isset($_FILES['review_media']) && !empty($_FILES['review_media']['name'][0])) {

                // --- UPDATED PATH HERE ---
                $target_dir = "../uploads/reviews/";

                // Create folder if it doesn't exist
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }

                $total_files = count($_FILES['review_media']['name']);

                for ($i = 0; $i < $total_files; $i++) {
                    $file_name = $_FILES['review_media']['name'][$i];
                    $file_tmp = $_FILES['review_media']['tmp_name'][$i];
                    $file_type = $_FILES['review_media']['type'][$i];

                    // Generate Unique Name: review_ID_TIMESTAMP_INDEX.ext
                    $ext = pathinfo($file_name, PATHINFO_EXTENSION);
                    $new_file_name = "review_" . $new_review_id . "_" . time() . "_" . $i . "." . $ext;
                    $target_file = $target_dir . $new_file_name;

                    // Detect Type
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
    <a href="orders.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Orders</a>

    <div class="order-header-card">
        <div class="header-top">
            <span>Order #<?php echo $order['order_id']; ?></span>
            <span><?php echo date('M d, Y • h:i A', strtotime($order['order_date'])); ?></span>
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

<script>
    var modal = document.getElementById("reviewModal");
    var uploadArea = document.getElementById("uploadArea");
    var fileInput = document.getElementById("fileInput");
    var filePreview = document.getElementById("filePreview");

    // MASTER LIST of files (Stores all selected files)
    var allFiles = [];

    // --- MODAL LOGIC ---
    function openReviewModal(id, name) {
        document.getElementById('modalProductId').value = id;
        document.getElementById('modalProductName').innerText = name;

        // Reset everything when opening
        allFiles = [];
        updateFileInput(); // Clears the input
        modal.style.display = "block";
    }

    function closeReviewModal() {
        modal.style.display = "none";
    }

    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }

    // --- DRAG & DROP & UPLOAD LOGIC ---

    // 1. Click triggers browse
    uploadArea.addEventListener('click', (e) => {
        // Prevent triggering if clicking on child elements might bubble up weirdly
        if (e.target !== fileInput) {
            fileInput.click();
        }
    });

    // 2. Drag Effects
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('highlight');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('highlight');
    });

    // 3. Handle Dropped Files
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('highlight');
        const droppedFiles = e.dataTransfer.files;
        handleNewFiles(droppedFiles);
    });

    // 4. Handle Browsed Files
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            handleNewFiles(fileInput.files);
        }
    });

    // --- CORE FUNCTIONS ---

    // A. Add new files to our Master List
    function handleNewFiles(files) {
        Array.from(files).forEach(file => {
            // Optional: Prevent exact duplicates by name & size
            const exists = allFiles.some(f => f.name === file.name && f.size === file.size);
            if (!exists) {
                allFiles.push(file);
            }
        });

        // Sync the master list to the UI and the Input
        updateFileInput();
    }

    // B. Remove a file from the list
    function removeFile(index) {
        allFiles.splice(index, 1); // Remove from array
        updateFileInput(); // Re-sync
    }

    // C. Sync Array -> Hidden Input -> UI
    function updateFileInput() {
        // 1. Use DataTransfer to simulate a file selection in the input
        const dataTransfer = new DataTransfer();
        allFiles.forEach(file => {
            dataTransfer.items.add(file);
        });

        // 2. Assign the accumulated files back to the hidden input
        fileInput.files = dataTransfer.files;

        // 3. Re-draw the previews
        renderPreviews();
    }

    // D. Render the thumbnails
    function renderPreviews() {
        filePreview.innerHTML = ""; // Clear current

        allFiles.forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const div = document.createElement('div');
                div.className = 'preview-item';

                // Content (Image/Video + Remove Btn)
                let content = `<div class="remove-btn" onclick="event.stopPropagation(); removeFile(${index})">&times;</div>`;

                if (file.type.startsWith('image/')) {
                    content += `<img src="${e.target.result}"><span class="file-type-badge">IMG</span>`;
                } else if (file.type.startsWith('video/')) {
                    content += `<video src="${e.target.result}"></video><span class="file-type-badge">VID</span>`;
                } else {
                    // Fallback for other files
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