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

// 3. Determine Progress Step (1 to 4)
$status = $order['order_status'];
$step = 1; // Default Pending
if (stripos($status, 'Processing') !== false) $step = 2;
if (stripos($status, 'Shipped') !== false) $step = 3;
if (stripos($status, 'Delivered') !== false || stripos($status, 'Completed') !== false) $step = 4;

$can_review = ($step == 4); // Only allow review if Delivered/Completed

// 4. Handle Review Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_review'])) {
    $prod_id = $_POST['product_id'];
    $rating = $_POST['rating'];
    $comment = $_POST['comment'];

    // Check if already reviewed
    $check = $pdo->prepare("SELECT review_id FROM reviews WHERE user_id = ? AND product_id = ?");
    $check->execute([$user_id, $prod_id]);

    if ($check->rowCount() == 0) {
        $stmtR = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
        $stmtR->execute([$prod_id, $user_id, $rating, $comment]);
        echo "<div class='alert alert-success container mt-3'>Review submitted successfully!</div>";
    } else {
        echo "<div class='alert alert-warning container mt-3'>You already reviewed this item.</div>";
    }
}

// 5. Fetch Order Items + Check if User Reviewed them
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
    <a href="orders.php" class="btn btn-outline-secondary mb-3">&larr; Back to Orders</a>

    <!-- ORDER HEADER -->
    <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span>Order #<?php echo $order['order_id']; ?></span>
        
        <div>
            <!-- PDF BUTTON -->
            <a href="receipt.php?order_id=<?php echo $order['order_id']; ?>" target="_blank" class="btn btn-sm btn-light text-primary">
                <i class="fas fa-file-pdf"></i> Receipt
            </a>
            <span class="ms-3"><?php echo date('M d, Y h:i A', strtotime($order['order_date'])); ?></span>
        </div>
    </div>
            <span><?php echo date('M d, Y h:i A', strtotime($order['order_date'])); ?></span>
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
                    <h3 class="text-success">$<?php echo number_format($order['total_amount'], 2); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- TRACKING PROGRESS BAR -->
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

    <!-- ORDER ITEMS LIST -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            Items in this order
        </div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <tbody>
                    <?php foreach ($items as $item): 
                        $imgUrl = !empty($item['file_path']) ? $item['file_path'] : "https://via.placeholder.com/60";
                    ?>
                    <tr>
                        <!-- Image -->
                        <td style="width: 80px;" class="align-middle text-center p-3">
                            <img src="<?php echo htmlspecialchars($imgUrl); ?>" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px;">
                        </td>
                        
                        <!-- Details -->
                        <td class="align-middle">
                            <h6 class="mb-0"><?php echo htmlspecialchars($item['name']); ?></h6>
                            <small class="text-muted">Qty: <?php echo $item['quantity']; ?></small>
                        </td>
                        
                        <!-- Price -->
                        <td class="align-middle">
                            $<?php echo number_format($item['price_at_purchase'], 2); ?>
                        </td>

                        <!-- ACTION: REVIEW BUTTON -->
                        <td class="align-middle text-end p-3">
                            <?php if ($can_review): ?>
                                <?php if ($item['has_reviewed'] > 0): ?>
                                    <button class="btn btn-sm btn-success" disabled>
                                        <i class="fas fa-check"></i> Reviewed
                                    </button>
                                <?php else: ?>
                                    <!-- Button to Open Modal -->
                                    <button class="btn btn-sm btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#reviewModal" 
                                            onclick="setReviewProduct(<?php echo $item['product_id']; ?>, '<?php echo addslashes($item['name']); ?>')">
                                        <i class="fas fa-star"></i> Rate Product
                                    </button>
                                <?php endif; ?>
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

<!-- REVIEW MODAL (POPUP) -->
<div class="modal fade" id="reviewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
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
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" name="submit_review" class="btn btn-primary">Submit Review</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Javascript to pass Product ID to Modal -->
<script>
function setReviewProduct(id, name) {
    document.getElementById('modalProductId').value = id;
    document.getElementById('modalProductName').innerText = name;
}
</script>

<?php include 'includes/footer.php'; ?>