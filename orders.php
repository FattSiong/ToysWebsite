<?php 
include 'includes/header.php'; 
include 'includes/navbar.php'; 

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$user_id = $_SESSION['user_id'];

// --- HANDLE ORDER CANCELLATION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_cancel'])) {
    $cancel_order_id = $_POST['order_id'];
    $reason = $_POST['cancel_reason'];

    $stmtCheck = $pdo->prepare("SELECT order_id, order_status, points_redeemed, points_earned FROM orders WHERE order_id = ? AND user_id = ?");
    $stmtCheck->execute([$cancel_order_id, $user_id]);
    $targetOrder = $stmtCheck->fetch();

    if ($targetOrder) {
        $status = strtolower($targetOrder['order_status']); // Normalize to lowercase for checking
        $points_redeemed = $targetOrder['points_redeemed'];
        $points_earned = $targetOrder['points_earned'];

        // ALLOW CANCELLATION ONLY FOR: pending OR processing
        if ($status == 'pending' || $status == 'processing') {
            try {
                $pdo->beginTransaction();
                
                // 1. Update Status
                $updateStmt = $pdo->prepare("UPDATE orders SET order_status = 'cancelled', cancellation_reason = ? WHERE order_id = ?");
                $updateStmt->execute([$reason, $cancel_order_id]);

                // 2. Logic for Paid Orders (Processing)
                if ($status == 'processing') {
                    // Refund Payment
                    $pdo->prepare("UPDATE payments SET status = 'Refunded' WHERE order_id = ?")->execute([$cancel_order_id]);
                    
                    // Restock Items
                    $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                    $stmtItems->execute([$cancel_order_id]);
                    $itemsToRestock = $stmtItems->fetchAll();
                    foreach ($itemsToRestock as $item) {
                        $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?")->execute([$item['quantity'], $item['product_id']]);
                    }
                    
                    // Reverse Points Earned
                    if ($points_earned > 0) {
                        $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")->execute([$points_earned, $user_id]);
                        $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Reversed (Cancelled)')")->execute([$user_id, $cancel_order_id, -$points_earned]);
                    }
                }

                // 3. Refund Points Redeemed (For both Pending and Processing)
                if ($points_redeemed > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points + ? WHERE user_id = ?")->execute([$points_redeemed, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Refunded (Cancelled)')")->execute([$user_id, $cancel_order_id, $points_redeemed]);
                }

                $pdo->commit();
                echo "<div class='alert alert-success container mt-3'>Order #$cancel_order_id cancelled successfully.</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert alert-danger container mt-3'>Error: " . $e->getMessage() . "</div>";
            }
        }
    }
}

// --- FILTERING LOGIC ---

// Initialize default query
$sql = "SELECT * FROM orders WHERE user_id = ?";
$params = [$user_id];

// Get Filter Values
$f_start_date = $_GET['start_date'] ?? '';
$f_end_date = $_GET['end_date'] ?? '';
$f_status = $_GET['status'] ?? '';
$f_min = $_GET['min_amount'] ?? '';
$f_max = $_GET['max_amount'] ?? '';

// 1. Date Filter
if (!empty($f_start_date)) {
    $sql .= " AND DATE(order_date) >= ?";
    $params[] = $f_start_date;
}
if (!empty($f_end_date)) {
    $sql .= " AND DATE(order_date) <= ?";
    $params[] = $f_end_date;
}

// 2. Status Filter
if (!empty($f_status)) {
    $sql .= " AND order_status LIKE ?"; // Use LIKE to be case-insensitive
    $params[] = $f_status;
}

// 3. Price Filter
if (!empty($f_min)) {
    $sql .= " AND total_amount >= ?";
    $params[] = $f_min;
}
if (!empty($f_max)) {
    $sql .= " AND total_amount <= ?";
    $params[] = $f_max;
}

$sql .= " ORDER BY order_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();
?>

<div class="container mt-4 mb-5">
    <h2 class="mb-4">Order History</h2>

    <!-- FILTER SECTION -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white cursor-pointer" data-bs-toggle="collapse" data-bs-target="#filterPanel">
            <i class="fas fa-filter text-primary"></i> Filter Options 
            <small class="text-muted">(Click to expand)</small>
        </div>
        <div class="collapse show" id="filterPanel">
            <div class="card-body bg-light">
                <form method="GET" action="orders.php">
                    <div class="row g-3">
                        <!-- Updated Status List -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo ($f_status == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo ($f_status == 'processing') ? 'selected' : ''; ?>>Processing</option>
                                <option value="delivered" <?php echo ($f_status == 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                <option value="completed" <?php echo ($f_status == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo ($f_status == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="refunded" <?php echo ($f_status == 'refunded') ? 'selected' : ''; ?>>Refunded</option>
                            </select>
                        </div>

                        <!-- Date Range -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">From Date</label>
                            <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_start_date); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">To Date</label>
                            <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_end_date); ?>">
                        </div>

                        <!-- Price Range -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Price (RM)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="min_amount" class="form-control" placeholder="Min" value="<?php echo htmlspecialchars($f_min); ?>">
                                <span class="input-group-text">-</span>
                                <input type="number" name="max_amount" class="form-control" placeholder="Max" value="<?php echo htmlspecialchars($f_max); ?>">
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="col-12 text-end mt-3">
                            <a href="orders.php" class="btn btn-sm btn-secondary me-2">Reset</a>
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i> Apply Filter</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ORDER LIST -->
    <?php if(empty($orders)): ?>
        <div class="alert alert-info text-center">
            No orders found matching your criteria. <a href="orders.php">Clear Filters</a>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="p-3">Order ID</th>
                                <th class="p-3">Date</th>
                                <th class="p-3">Total</th>
                                <th class="p-3">Status</th>
                                <th class="p-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): 
                                // Normalize status to lowercase for comparison
                                $db_status_raw = $order['order_status'];
                                $status_check = strtolower($db_status_raw);

                                $display_text = ucfirst($status_check); // Capitalize first letter
                                $badge_class = 'bg-secondary'; 
                                $can_cancel = false;

                                // --- MAP STATUS TO COLORS & LOGIC ---
                                
                                if ($status_check == 'pending') {
                                    $badge_class = 'bg-warning text-dark'; 
                                    $can_cancel = true; // Can cancel
                                } 
                                elseif ($status_check == 'processing') {
                                    $badge_class = 'bg-primary'; 
                                    $can_cancel = true; // Can cancel (refund)
                                } 
                                elseif ($status_check == 'delivered') {
                                    $badge_class = 'bg-info text-dark'; 
                                } 
                                elseif ($status_check == 'completed') {
                                    $badge_class = 'bg-success'; 
                                } 
                                elseif ($status_check == 'cancelled') {
                                    $badge_class = 'bg-danger'; 
                                }
                                elseif ($status_check == 'refunded') {
                                    $badge_class = 'bg-secondary'; 
                                }
                            ?>
                            <tr>
                                <td class="p-3 align-middle">#<?php echo $order['order_id']; ?></td>
                                <td class="p-3 align-middle"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                <td class="p-3 align-middle"><strong>RM <?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                
                                <td class="p-3 align-middle">
                                    <span class="badge <?php echo $badge_class; ?> rounded-pill px-3 py-2">
                                        <?php echo htmlspecialchars($display_text); ?>
                                    </span>
                                    <?php if($status_check == 'cancelled' && !empty($order['cancellation_reason'])): ?>
                                        <br><small class="text-muted" style="font-size:10px;">Reason: <?php echo htmlspecialchars($order['cancellation_reason']); ?></small>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="p-3 align-middle text-end">
                                    <a href="order_detail.php?id=<?php echo $order['order_id']; ?>" class="btn btn-sm btn-outline-primary mb-1">View</a>

                                    <?php if ($can_cancel): ?>
                                        <button class="btn btn-sm btn-outline-danger mb-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#cancelModal"
                                                onclick="setCancelOrder(<?php echo $order['order_id']; ?>)">
                                            Cancel
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- CANCELLATION MODAL -->
<div class="modal fade" id="cancelModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title">Cancel Order</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p>Are you sure you want to cancel <strong>Order #<span id="modalOrderIdText"></span></strong>?</p>
            <p class="text-muted small">If paid, the amount will be refunded and points reversed.</p>
            <input type="hidden" name="order_id" id="modalOrderIdInput">
            <div class="mb-3">
                <label class="form-label fw-bold">Please select a reason:</label>
                <select name="cancel_reason" class="form-select" required>
                    <option value="">Select a reason...</option>
                    <option value="Changed my mind">Changed my mind</option>
                    <option value="Found a cheaper price">Found a cheaper price</option>
                    <option value="Ordered by mistake">Ordered by mistake</option>
                    <option value="Shipping cost too high">Shipping cost too high</option>
                    <option value="Other">Other</option>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Order</button>
            <button type="submit" name="confirm_cancel" class="btn btn-danger">Confirm Cancellation</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
function setCancelOrder(id) {
    document.getElementById('modalOrderIdText').innerText = id;
    document.getElementById('modalOrderIdInput').value = id;
}
</script>

<?php include 'includes/footer.php'; ?>
