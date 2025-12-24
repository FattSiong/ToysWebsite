<?php 
include '../includes/header.php'; 

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='../login.php';</script>";
    exit;
}

$user_id = $_SESSION['user_id'];

// --- 1. KEEP YOUR ORIGINAL CANCELLATION LOGIC ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_cancel'])) {
    $cancel_order_id = $_POST['order_id'];
    $reason = $_POST['cancel_reason']; 

    // A. Verify Order
    $stmtCheck = $pdo->prepare("SELECT order_id, order_status, points_redeemed, points_earned FROM orders WHERE order_id = ? AND user_id = ?");
    $stmtCheck->execute([$cancel_order_id, $user_id]);
    $targetOrder = $stmtCheck->fetch();

    if ($targetOrder) {
        $status = $targetOrder['order_status'];
        $points_redeemed = $targetOrder['points_redeemed'];
        $points_earned = $targetOrder['points_earned'];

        // 1. Define Cancellable Statuses
        $cancellable_statuses = ['Pending', 'Processing'];

        if (in_array($status, $cancellable_statuses)) {
            try {
                $pdo->beginTransaction();

                // 2. Update Status to 'Refunded'
                $updateStmt = $pdo->prepare("UPDATE orders SET order_status = 'Refunded', cancellation_reason = ? WHERE order_id = ?");
                $updateStmt->execute([$reason, $cancel_order_id]);

                // 3. Mark Payment as Refunded
                $pdo->prepare("UPDATE payments SET status = 'Refunded' WHERE order_id = ?")->execute([$cancel_order_id]);

                // 4. Restock Items
                $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmtItems->execute([$cancel_order_id]);
                $itemsToRestock = $stmtItems->fetchAll();

                foreach ($itemsToRestock as $item) {
                    $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?")
                        ->execute([$item['quantity'], $item['product_id']]);
                }

                // 5. Reverse Earned Points
                if ($points_earned > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")->execute([$points_earned, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Reversed (Refund)')")
                        ->execute([$user_id, $cancel_order_id, -$points_earned]);
                }

                // 6. Return Redeemed Points
                if ($points_redeemed > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points + ? WHERE user_id = ?")->execute([$points_redeemed, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Restored (Refund)')")
                        ->execute([$user_id, $cancel_order_id, $points_redeemed]);
                }

                $pdo->commit();
                echo "<div class='alert success-msg'>Order #$cancel_order_id has been cancelled and marked as <strong>Refunded</strong>.</div>";

            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert error-msg'>Error: " . $e->getMessage() . "</div>";
            }
        } else {
            echo "<div class='alert error-msg'>Order cannot be cancelled. Current status: $status</div>";
        }
    }
}

// --- 2. NEW: FILTERING LOGIC (From Friend's Branch) ---

// Initialize default query
$sql = "SELECT * FROM orders WHERE user_id = ?";
$params = [$user_id];

// Get Filter Values from URL
$f_start_date = $_GET['start_date'] ?? '';
$f_end_date = $_GET['end_date'] ?? '';
$f_status = $_GET['status'] ?? '';
$f_min = $_GET['min_amount'] ?? '';
$f_max = $_GET['max_amount'] ?? '';

// 2.1 Date Filter
if (!empty($f_start_date)) {
    $sql .= " AND DATE(order_date) >= ?";
    $params[] = $f_start_date;
}
if (!empty($f_end_date)) {
    $sql .= " AND DATE(order_date) <= ?";
    $params[] = $f_end_date;
}

// 2.2 Status Filter
if (!empty($f_status)) {
    $sql .= " AND order_status LIKE ?"; // Use LIKE to be case-insensitive
    $params[] = $f_status;
}

// 2.3 Price Filter
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
// -----------------------------------------------------
?>

<link rel="stylesheet" href="../css/orders.css">
<style>
.alert { padding: 15px; margin: 20px auto; max-width: 1100px; border-radius: 8px; }
.success-msg { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.error-msg { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

/* Simple styles for the filter box to match your theme */
.filter-card {
    max-width: 1100px;
    margin: 0 auto 30px auto;
    background: #fff;
    border: 1px solid #e1e4e8;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.filter-row { display: flex; gap: 15px; flex-wrap: wrap; }
.filter-col { flex: 1; min-width: 200px; }
.filter-label { display: block; font-weight: bold; font-size: 0.9em; margin-bottom: 5px; color: #555; }
.filter-input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
.filter-actions { margin-top: 15px; text-align: right; }
</style>

<div class="container">
    <div class="page-header">
        <h2>My Orders</h2>
        <span class="order-count"><?php echo count($orders); ?> Orders</span>
    </div>

    <div class="filter-card">
        <form method="GET" action="orders.php">
            <div class="filter-row">
                <div class="filter-col">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-input">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo ($f_status == 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="processing" <?php echo ($f_status == 'processing') ? 'selected' : ''; ?>>Processing</option>
                        <option value="shipped" <?php echo ($f_status == 'shipped') ? 'selected' : ''; ?>>Shipped</option>
                        <option value="delivered" <?php echo ($f_status == 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                        <option value="completed" <?php echo ($f_status == 'completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo ($f_status == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="refunded" <?php echo ($f_status == 'refunded') ? 'selected' : ''; ?>>Refunded</option>
                    </select>
                </div>

                <div class="filter-col">
                    <label class="filter-label">From Date</label>
                    <input type="date" name="start_date" class="filter-input" value="<?php echo htmlspecialchars($f_start_date); ?>">
                </div>
                <div class="filter-col">
                    <label class="filter-label">To Date</label>
                    <input type="date" name="end_date" class="filter-input" value="<?php echo htmlspecialchars($f_end_date); ?>">
                </div>

                <div class="filter-col">
                    <label class="filter-label">Price (RM)</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="number" name="min_amount" class="filter-input" placeholder="Min" value="<?php echo htmlspecialchars($f_min); ?>">
                        <input type="number" name="max_amount" class="filter-input" placeholder="Max" value="<?php echo htmlspecialchars($f_max); ?>">
                    </div>
                </div>
            </div>
            
            <div class="filter-actions">
                <a href="orders.php" class="btn btn-filter-reset">
                    <i class="fas fa-undo"></i> Reset
                </a>
                <button type="submit" class="btn btn-filter-apply">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
            </div>
        </form>
    </div>
    <?php if(empty($orders)): ?>
        <div style="text-align:center; padding:50px; background:#fff; border-radius:10px;">
            <p>No orders found matching your criteria.</p>
            <a href="orders.php" class="btn btn-secondary">Clear Filters</a>
            <a href="../index.php" class="btn btn-primary">Start Shopping</a>
        </div>
    <?php else: ?>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
    <?php foreach ($orders as $order): 
        // 1. Get Status
        $raw_status = !empty($order['order_status']) ? $order['order_status'] : 'Pending';
        $status = ucfirst(strtolower(trim($raw_status))); 
        
        // 2. Default Badge (Grey)
        $badgeClass = 'status-secondary'; 
        $canCancel = false;

        // 3. Assign Color Class
        switch($status) {
            case 'Pending':
                $badgeClass = 'status-pending'; // Yellow
                $canCancel = true; 
                break;
            case 'Processing':
                $badgeClass = 'status-processing'; // Blue
                $canCancel = true;
                break;
            case 'Shipped':
                $badgeClass = 'status-shipped'; // Cyan
                break;
            case 'Delivered':
            case 'Completed':
                $badgeClass = 'status-completed'; // Green
                break;
            case 'Cancelled':
                $badgeClass = 'status-cancelled'; // Red
                break;
            case 'Refunded':
                $badgeClass = 'status-refunded'; // Black
                break;
            default:
                $badgeClass = 'status-secondary';
                break;
        }
    ?>
    <tr>
        <td style="font-weight:bold;">#<?php echo $order['order_id']; ?></td>
        <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
        <td class="price-text">RM <?php echo number_format($order['total_amount'], 2); ?></td>
        <td>
            <span class="order-status-badge <?php echo $badgeClass; ?>">
                <?php echo htmlspecialchars($status); ?>
            </span>
            <?php if(strtolower($status) == 'cancelled' && !empty($order['cancellation_reason'])): ?>
                <br><small style="color:#888; font-size:0.8em;"><?php echo htmlspecialchars($order['cancellation_reason']); ?></small>
            <?php endif; ?>
        </td>
        <td style="text-align:right;">
            <a href="order_detail.php?id=<?php echo $order['order_id']; ?>" class="btn btn-view">View</a>
            
            <?php if ($canCancel): ?>
                <button class="btn btn-cancel" 
                        onclick="openCancelModal(<?php echo $order['order_id']; ?>)">
                    Cancel
                </button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="customCancelModal" class="custom-modal">
    <div class="modal-content">
        <form method="post">
            <div class="modal-header">
                <h3 class="modal-title">Cancel Order</h3>
                <span class="close-btn" onclick="closeCancelModal()">&times;</span>
            </div>
            
            <div class="modal-body">
                <p>Are you sure you want to cancel <strong>Order #<span id="displayOrderId"></span></strong>?</p>
                <p style="font-size:0.9rem; color:#666; margin-bottom:15px;">
                    This order has been paid. Cancelling it will initiate a <strong>Refund</strong> to your account.
                </p>

                <input type="hidden" name="order_id" id="inputOrderId">

                <label style="font-weight:bold; display:block; margin-bottom:5px;">Reason for Cancellation:</label>
                <select name="cancel_reason" class="form-select" required>
                    <option value="">Select a reason...</option>
                    <option value="Changed my mind">Changed my mind</option>
                    <option value="Found a cheaper price">Found a cheaper price</option>
                    <option value="Ordered by mistake">Ordered by mistake</option>
                    <option value="Processing too slow">Processing too slow</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">Keep Order</button>
                <button type="submit" name="confirm_cancel" class="btn btn-danger">Confirm Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    var modal = document.getElementById("customCancelModal");

    function openCancelModal(orderId) {
        document.getElementById("displayOrderId").innerText = orderId;
        document.getElementById("inputOrderId").value = orderId;
        modal.style.display = "block"; 
    }

    function closeCancelModal() {
        modal.style.display = "none";
    }

    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
</script>

<?php include '../includes/footer.php'; ?>