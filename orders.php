<?php 
include 'includes/header.php'; 
include 'includes/navbar.php'; 

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();
?>

<div class="container mt-4">
    <h2 class="mb-4">Order History</h2>

    <?php if(empty($orders)): ?>
        <div class="alert alert-info">
            You haven't placed any orders yet. <a href="index.php">Go shopping!</a>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="p-3">Order ID</th>
                            <th class="p-3">Date</th>
                            <th class="p-3">Total Amount</th>
                            <th class="p-3">Status</th>
                            <th class="p-3 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): 
                            
                            // 1. GET STATUS FROM DB
                            $db_status = $order['order_status'];

                            // 2. SAFETY FIX: If database is empty, assume it is 'Pending'
                            if (empty($db_status)) {
                                $db_status = 'Pending';
                            }

                            // 3. MAP STATUS TO FRIENDLY TEXT & COLOR
                            $display_text = $db_status; // Default text
                            $badge_class = 'bg-secondary'; // Default gray

                            // We use 'stripos' to check if the word exists (case-insensitive)
                            if (stripos($db_status, 'Pending') !== false) {
                                $display_text = "We've received your order";
                                $badge_class = 'bg-warning text-dark'; // Yellow
                            } 
                            elseif (stripos($db_status, 'Processing') !== false) {
                                $display_text = "Packing your toys...";
                                $badge_class = 'bg-primary'; // Blue
                            } 
                            elseif (stripos($db_status, 'Shipped') !== false) {
                                $display_text = "Your package is on its way!";
                                $badge_class = 'bg-info text-dark'; // Teal
                            } 
                            elseif (stripos($db_status, 'Completed') !== false) {
                                $display_text = "Delivered";
                                $badge_class = 'bg-success'; // Green
                            } 
                            elseif (stripos($db_status, 'Cancelled') !== false) {
                                $display_text = "Order Cancelled";
                                $badge_class = 'bg-danger'; // Red
                            }
                        ?>
                        <tr>
                            <td class="p-3 align-middle">#<?php echo $order['order_id']; ?></td>
                            <td class="p-3 align-middle"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                            <td class="p-3 align-middle"><strong>$<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                            
                            <!-- STATUS BADGE -->
                            <td class="p-3 align-middle">
                                <span class="badge <?php echo $badge_class; ?> rounded-pill px-3 py-2">
                                    <?php echo htmlspecialchars($display_text); ?>
                                </span>
                            </td>

                            <!-- ACTION BUTTON -->
                            <td class="p-3 align-middle text-end">
                                <a href="order_detail.php?id=<?php echo $order['order_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    View Items
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>