<?php 
include '../includes/header.php'; 

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='../login.php';</script>";
    exit;
}

$user_id = $_SESSION['user_id'];

// --- HANDLE ORDER CANCELLATION ---
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
        // Since all orders are paid, both Pending and Processing are valid for cancellation
        $cancellable_statuses = ['Pending', 'Processing'];

        if (in_array($status, $cancellable_statuses)) {
            try {
                $pdo->beginTransaction();

                // 2. Update Status to 'Refunded'
                // Since the user has already paid, a cancellation is effectively a REFUND.
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

                // 5. Reverse Earned Points (Take back points given for this order)
                if ($points_earned > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")->execute([$points_earned, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Reversed (Refund)')")
                        ->execute([$user_id, $cancel_order_id, -$points_earned]);
                }

                // 6. Return Redeemed Points (Give back points used for discount)
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

// 2. Fetch Orders
$stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();
?>

<link rel="stylesheet" href="../css/orders.css">
<style>
.alert { padding: 15px; margin: 20px auto; max-width: 1100px; border-radius: 8px; }
.success-msg { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.error-msg { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
</style>

<div class="container">
    <div class="page-header">
        <h2>My Orders</h2>
        <span class="order-count"><?php echo count($orders); ?> Orders</span>
    </div>

    <?php if(empty($orders)): ?>
        <div style="text-align:center; padding:50px; background:#fff; border-radius:10px;">
            <p>No orders found.</p>
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
        // 1. Get Status: Trim spaces and Capitalize first letter (e.g., "pending" -> "Pending")
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
                // If status is unknown (e.g. "New"), use Grey
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