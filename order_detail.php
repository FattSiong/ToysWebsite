<?php 
include 'includes/header.php'; 
include 'includes/navbar.php'; 

// 1. Security & Validation
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$order_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

// 2. Fetch Order Details
$sqlOrder = "SELECT o.*, sa.line1, sa.city, sa.state, sa.postcode, sa.phone 
             FROM orders o 
             JOIN shipping_addresses sa ON o.shipping_address_id = sa.shipping_address_id
             WHERE o.order_id = ? AND o.user_id = ?";
$stmt = $pdo->prepare($sqlOrder);
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if (!$order) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Order not found.</div></div>";
    include 'includes/footer.php';
    exit;
}

// 3. Determine Progress & Eligibility
$status = $order['order_status'];
$order_date_ts = strtotime($order['order_date']);
$current_ts = time();
$days_diff = floor(($current_ts - $order_date_ts) / (60 * 60 * 24));

// Step Logic for Progress Bar
$step = 1;
if (stripos($status, 'Processing') !== false) $step = 2;
if (stripos($status, 'Shipped') !== false) $step = 3;
if (stripos($status, 'Delivered') !== false || stripos($status, 'Completed') !== false) $step = 4;
if (stripos($status, 'Refunded') !== false) $step = 4; // Keep bar full if refunded

// Logic Variables
$can_review = ($step == 4 && stripos($status, 'Refunded') === false); 
$is_delivered = ($step == 4);
$is_refunded = (stripos($status, 'Refunded') !== false);

// Refund Eligibility: Status is Delivered AND within 14 days AND not already refunded
$can_refund = ($is_delivered && !$is_refunded && $days_diff <= 14);

// 4. Handle Actions (Review OR Refund)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // --- A. HANDLE REFUND REQUEST ---
    if (isset($_POST['confirm_refund'])) {
        $reason = $_POST['refund_reason'];
        
        if ($can_refund) {
            try {
                $pdo->beginTransaction();

                // 1. Update Order Status
                $stmtRef = $pdo->prepare("UPDATE orders SET order_status = 'Refunded', refund_reason = ? WHERE order_id = ?");
                $stmtRef->execute([$reason, $order_id]);

                // 2. Update Payment Status
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
                $points_earned = $order['points_earned'];
                if ($points_earned > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")->execute([$points_earned, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Reversed (Refunded Order)')")
                        ->execute([$user_id, $order_id, -$points_earned]);
                }

                // 5. Return Points Redeemed (Give back points used for discount)
                $points_redeemed = $order['points_redeemed'];
                if ($points_redeemed > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points + ? WHERE user_id = ?")->execute([$points_redeemed, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Returned (Refunded Order)')")
                        ->execute([$user_id, $order_id, $points_redeemed]);
                }

                $pdo->commit();
                // Refresh page
                echo "<script>window.location.href='order_detail.php?id=$order_id';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert alert-danger container mt-3'>Error processing refund: " . $e->getMessage() . "</div>";
            }
        }
    }

    // --- B. HANDLE REVIEW SUBMISSION ---
    if (isset($_POST['submit_review'])) {
        $prod_id = $_POST['product_id'];
        $rating = $_POST['rating'];
        $comment = $_POST['comment'];

        $check = $pdo->prepare("SELECT review_id FROM reviews WHERE user_id = ? AND product_id = ?");
        $check->execute([$user_id, $prod_id]);

        if ($check->rowCount() == 0) {
            try {
                $pdo->beginTransaction();
                
                $stmtR = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
                $stmtR->execute([$prod_id, $user_id, $rating, $comment]);
                $new_review_id = $pdo->lastInsertId();

                // Drag & Drop Media Upload
                if (isset($_FILES['review_media']) && !empty($_FILES['review_media']['name'][0])) {
                    $target_dir = "images/reviews/";
                    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

                    $total_files = count($_FILES['review_media']['name']);
                    for ($i = 0; $i < $total_files; $i++) {
                        $file_name = $_FILES['review_media']['name'][$i];
                        $file_tmp = $_FILES['review_media']['tmp_name'][$i];
                        $file_type = $_FILES['review_media']['type'][$i];
                        
                        $new_file_name = "review_" . $new_review_id . "_" . time() . "_" . basename($file_name);
                        $target_file = $target_dir . $new_file_name;
                        $media_type = (strpos($file_type, 'video') !== false) ? 'video' : 'image';

                        if (move_uploaded_file($file_tmp, $target_file)) {
                            $sqlMedia = "INSERT INTO review_media (review_id, file_path, media_type) VALUES (?, ?, ?)";
                            $pdo->prepare($sqlMedia)->execute([$new_review_id, $target_file, $media_type]);
                        }
                    }
                }
                $pdo->commit();
                echo "<div class='alert alert-success container mt-3'>Review submitted successfully!</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert alert-danger container mt-3'>Error: " . $e->getMessage() . "</div>";
            }
        } else {
            echo "<div class='alert alert-warning container mt-3'>You already reviewed this item.</div>";
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

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="orders.php" class="btn btn-outline-secondary">&larr; Back to Orders</a>
        
        <!-- REFUND BUTTON (Only visible if eligible) -->
        <?php if ($can_refund): ?>
            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#refundModal">
                <i class="fas fa-undo"></i> Request Refund
            </button>
        <?php elseif ($is_refunded): ?>
            <span class="badge bg-danger p-2 fs-6">Refunded</span>
        <?php endif; ?>
    </div>

    <!-- ORDER HEADER -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between">
            <span>Order #<?php echo $order['order_id']; ?></span>
            
            <div>
                <!-- PDF BUTTON -->
                <a href="receipt.php?order_id=<?php echo $order['order_id']; ?>" target="_blank" class="btn btn-sm btn-light text-primary">
                    <i class="fas fa-file-pdf"></i> Receipt
                </a>
                <span class="ms-3"><?php echo date('M d, Y h:i A', strtotime($order['order_date'])); ?></span>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h5>Shipping To:</h5>
                    <p class="mb-0 text-muted">
                        <?php echo htmlspecialchars($order['line1']); ?><br>
                        <?php echo htmlspecialchars($order['city'] . ', ' . $order['state'] . ' ' . $order['postcode']); ?><br>
                        Phone: <?php echo htmlspecialchars($order['phone']); ?>
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <h5>Total Amount:</h5>
                    <h3 class="text-success">RM <?php echo number_format($order['total_amount'], 2); ?></h3>
                    <?php if ($is_refunded): ?>
                        <small class="text-danger fw-bold">Amount Refunded</small>
                        <?php if (!empty($order['refund_reason'])): ?>
                            <br><small class="text-muted">Reason: <?php echo htmlspecialchars($order['refund_reason']); ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- TRACKING -->
    <div class="track">
        <div class="step <?php echo $step >= 1 ? 'active' : ''; ?>">
            <span class="icon"><i class="fas fa-file-invoice"></i></span>
            <span class="text">Received</span>
        </div>
        <div class="step <?php echo $step >= 2 ? 'active' : ''; ?>">
            <span class="icon"><i class="fas fa-box-open"></i></span>
            <span class="text">Packing</span>
        </div>
        <div class="step <?php echo $step >= 3 ? 'active' : ''; ?>">
            <span class="icon"><i class="fas fa-truck"></i></span>
            <span class="text">On the way</span>
        </div>
        <div class="step <?php echo $step >= 4 ? 'active' : ''; ?>">
            <span class="icon"><i class="fas fa-check"></i></span>
            <span class="text">Delivered</span>
        </div>
    </div>

    <!-- ITEMS -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">Items in this order</div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <tbody>
                    <?php foreach ($items as $item): 
                        $imgUrl = !empty($item['file_path']) ? $item['file_path'] : "https://via.placeholder.com/60";
                    ?>
                    <tr>
                        <td style="width: 80px;" class="align-middle text-center p-3">
                            <img src="<?php echo htmlspecialchars($imgUrl); ?>" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px;">
                        </td>
                        <td class="align-middle">
                            <h6 class="mb-0"><?php echo htmlspecialchars($item['name']); ?></h6>
                            <small class="text-muted">Qty: <?php echo $item['quantity']; ?></small>
                        </td>
                        <td class="align-middle">
                            RM <?php echo number_format($item['price_at_purchase'], 2); ?>
                        </td>
                        <td class="align-middle text-end p-3">
                            <?php if ($can_review): ?>
                                <?php if ($item['has_reviewed'] > 0): ?>
                                    <button class="btn btn-sm btn-success" disabled><i class="fas fa-check"></i> Reviewed</button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#reviewModal" 
                                            onclick="setReviewProduct(<?php echo $item['product_id']; ?>, '<?php echo addslashes($item['name']); ?>')">
                                        <i class="fas fa-star"></i> Rate Product
                                    </button>
                                <?php endif; ?>
                            <?php elseif ($is_refunded): ?>
                                <span class="badge bg-secondary">Refunded</span>
                            <?php else: ?>
                                <small class="text-muted">Wait for delivery</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- REVIEW MODAL -->
<div class="modal fade" id="reviewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Rate: <span id="modalProductName"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="product_id" id="modalProductId">
            <div class="mb-3">
                <label class="form-label">Rating</label>
                <select name="rating" class="form-select">
                    <option value="5">★★★★★ (5/5) Excellent</option>
                    <option value="4">★★★★☆ (4/5) Good</option>
                    <option value="3">★★★☆☆ (3/5) Average</option>
                    <option value="2">★★☆☆☆ (2/5) Poor</option>
                    <option value="1">★☆☆☆☆ (1/5) Terrible</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Comment</label>
                <textarea name="comment" class="form-control" rows="3" placeholder="What did you like or dislike?" required></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Add Photos/Videos</label>
                <div class="drop-zone" id="drop-zone">
                    <span class="drop-zone__prompt">
                        <i class="fas fa-cloud-upload-alt fa-2x"></i><br>
                        Drag & drop files here or <span class="text-primary text-decoration-underline">Browse</span>
                    </span>
                    <input type="file" name="review_media[]" class="drop-zone__input" multiple accept="image/*,video/*">
                </div>
                <div id="file-list"></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" name="submit_review" class="btn btn-primary">Submit Review</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- REFUND MODAL -->
<div class="modal fade" id="refundModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title">Request Refund</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p><strong>Note:</strong> Refunds are only available within 14 days of delivery.</p>
            <div class="mb-3">
                <label class="form-label fw-bold">Reason for Refund:</label>
                <select name="refund_reason" class="form-select" required>
                    <option value="">Select a reason...</option>
                    <option value="Item defective/damaged">Item defective/damaged</option>
                    <option value="Wrong item received">Wrong item received</option>
                    <option value="Item does not match description">Item does not match description</option>
                    <option value="Missing parts/accessories">Missing parts/accessories</option>
                    <option value="Change of mind">Change of mind</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="alert alert-warning">
                By confirming, your payment will be refunded, and points earned from this purchase will be deducted.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="confirm_refund" class="btn btn-danger">Confirm Refund</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
// Review Modal Logic
function setReviewProduct(id, name) {
    document.getElementById('modalProductId').value = id;
    document.getElementById('modalProductName').innerText = name;
    document.getElementById('file-list').innerHTML = '';
}

// Drag & Drop Logic
document.addEventListener("DOMContentLoaded", () => {
    const dropZone = document.getElementById("drop-zone");
    const inputElement = dropZone.querySelector(".drop-zone__input");
    const fileListDisplay = document.getElementById("file-list");

    if(dropZone) {
        dropZone.addEventListener("click", () => inputElement.click());
        inputElement.addEventListener("change", () => updateFileList(inputElement.files));
        dropZone.addEventListener("dragover", (e) => { e.preventDefault(); dropZone.classList.add("drop-zone--over"); });
        ["dragleave", "dragend"].forEach(type => dropZone.addEventListener(type, () => dropZone.classList.remove("drop-zone--over")));
        dropZone.addEventListener("drop", (e) => {
            e.preventDefault();
            dropZone.classList.remove("drop-zone--over");
            if (e.dataTransfer.files.length) {
                inputElement.files = e.dataTransfer.files;
                updateFileList(inputElement.files);
            }
        });
    }

    function updateFileList(files) {
        fileListDisplay.innerHTML = "";
        if (files.length > 0) {
            for (const file of files) {
                const div = document.createElement("div");
                div.innerHTML = `<i class="fas fa-paperclip"></i> ${file.name}`;
                fileListDisplay.appendChild(div);
            }
        }
    }
});
</script>

<?php include 'includes/footer.php'; ?>
