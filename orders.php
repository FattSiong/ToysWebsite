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
    $reason = $_POST['cancel_reason']; // Capture the reason

    // A. Verify Order
    $stmtCheck = $pdo->prepare("SELECT order_id, order_status, points_redeemed, points_earned FROM orders WHERE order_id = ? AND user_id = ?");
    $stmtCheck->execute([$cancel_order_id, $user_id]);
    $targetOrder = $stmtCheck->fetch();

    if ($targetOrder) {
        $status = $targetOrder['order_status'];
        $points_redeemed = $targetOrder['points_redeemed'];
        $points_earned = $targetOrder['points_earned'];

        // ALLOW CANCELLATION
        if ($status == 'Pending' || $status == 'Pending Payment' || $status == 'Processing') {
            try {
                $pdo->beginTransaction();

                // 1. Update Status AND Save Reason
                $updateStmt = $pdo->prepare("UPDATE orders SET order_status = 'Cancelled', cancellation_reason = ? WHERE order_id = ?");
                $updateStmt->execute([$reason, $cancel_order_id]);

                // 2. Logic for Paid Orders
                if ($status == 'Processing') {
                    $pdo->prepare("UPDATE payments SET status = 'Refunded' WHERE order_id = ?")->execute([$cancel_order_id]);

                    $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                    $stmtItems->execute([$cancel_order_id]);
                    $itemsToRestock = $stmtItems->fetchAll();

                    foreach ($itemsToRestock as $item) {
                        $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?")
                            ->execute([$item['quantity'], $item['product_id']]);
                    }

                    if ($points_earned > 0) {
                        $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")->execute([$points_earned, $user_id]);
                        $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Reversed (Cancelled Order)')")
                            ->execute([$user_id, $cancel_order_id, -$points_earned]);
                    }
                }

                // 3. Refund Points Used
                if ($points_redeemed > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points + ? WHERE user_id = ?")->execute([$points_redeemed, $user_id]);
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Refunded (Cancelled Order)')")
                        ->execute([$user_id, $cancel_order_id, $points_redeemed]);
                }

                $pdo->commit();
                echo "<div class='alert alert-success container mt-3'>Order #$cancel_order_id cancelled successfully. Reason recorded.</div>";

            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert alert-danger container mt-3'>Error: " . $e->getMessage() . "</div>";
            }
        } else {
            echo "<div class='alert alert-warning container mt-3'>This order cannot be cancelled.</div>";
        }
    }
}

// 2. Fetch Orders
$stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();
?>

<div class="container mt-4 mb-5">
    <h2 class="mb-4">Order History</h2>

    <?php if(empty($orders)): ?>
        <div class="alert alert-info">No orders found. <a href="index.php">Go shopping!</a></div>
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
                                $db_status = $order['order_status'];
                                if (empty($db_status)) $db_status = 'Pending';

                                $display_text = $db_status; 
                                $badge_class = 'bg-secondary'; 
                                $can_cancel = false;

                                if (stripos($db_status, 'Pending') !== false) {
                                    $display_text = "Pending / Unpaid";
                                    $badge_class = 'bg-warning text-dark'; 
                                    $can_cancel = true;
                                } 
                                elseif (stripos($db_status, 'Processing') !== false) {
                                    $display_text = "Paid - Packing";
                                    $badge_class = 'bg-primary'; 
                                    $can_cancel = true; 
                                } 
                                elseif (stripos($db_status, 'Shipped') !== false) {
                                    $display_text = "On the way!";
                                    $badge_class = 'bg-info text-dark'; 
                                } 
                                elseif (stripos($db_status, 'Completed') !== false || stripos($db_status, 'Delivered') !== false) {
                                    $display_text = "Delivered";
                                    $badge_class = 'bg-success'; 
                                } 
                                elseif (stripos($db_status, 'Cancelled') !== false) {
                                    $display_text = "Cancelled";
                                    $badge_class = 'bg-danger'; 
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
                                    <?php if($db_status == 'Cancelled' && !empty($order['cancellation_reason'])): ?>
                                        <br><small class="text-muted" style="font-size:10px;">Reason: <?php echo htmlspecialchars($order['cancellation_reason']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 align-middle text-end">
                                    <a href="order_detail.php?id=<?php echo $order['order_id']; ?>" class="btn btn-sm btn-outline-primary mb-1">View</a>

                                    <!-- CANCEL BUTTON: NOW OPENS MODAL -->
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
            <p class="text-muted small">If you have already paid, the amount will be refunded and any points earned will be reversed.</p>
            
            <input type="hidden" name="order_id" id="modalOrderIdInput">
            
            <div class="mb-3">
                <label class="form-label fw-bold">Please select a reason:</label>
                <select name="cancel_reason" class="form-select" required>
                    <option value="">Select a reason...</option>
                    <option value="Changed my mind">Changed my mind</option>
                    <option value="Found a cheaper price elsewhere">Found a cheaper price elsewhere</option>
                    <option value="Ordered by mistake">Ordered by mistake</option>
                    <option value="Shipping cost is too high">Shipping cost is too high</option>
                    <option value="Delivery time is too long">Delivery time is too long</option>
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
// Function to pass Order ID to the Modal
function setCancelOrder(id) {
    document.getElementById('modalOrderIdText').innerText = id;
    document.getElementById('modalOrderIdInput').value = id;
}
</script>

<?php include 'includes/footer.php'; ?>