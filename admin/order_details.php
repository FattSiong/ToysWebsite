<?php
require_once '../includes/db.php';

if (!isset($_GET['id'])) {
    echo "<div class='error-box'>Invalid Request</div>";
    exit;
}

$order_id = intval($_GET['id']);

// 1. Fetch Order Data
$sql = "SELECT o.*, u.username, u.email, u.phone_number as user_phone,
               sa.receiver_name, sa.line1, sa.line2, sa.city, sa.state, sa.postcode, sa.phone as shipping_phone,
               p_pay.payment_method, p_pay.payment_date, p_pay.status as payment_status, p_pay.transaction_id
        FROM orders o 
        JOIN users u ON o.user_id = u.user_id
        JOIN shipping_addresses sa ON o.shipping_address_id = sa.shipping_address_id
        LEFT JOIN payments p_pay ON o.order_id = p_pay.order_id
        WHERE o.order_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "<div class='error-box'>Order not found.</div>";
    exit;
}

// 2. Fetch Items
$item_sql = "SELECT oi.*, p.name, c.name as category_name,
             (SELECT file_path FROM product_media pm WHERE pm.product_id = p.product_id AND pm.is_primary = 1 LIMIT 1) as image
             FROM order_items oi 
             JOIN products p ON oi.product_id = p.product_id
             LEFT JOIN product_categories pc ON p.product_id = pc.product_id
             LEFT JOIN categories c ON pc.category_id = c.category_id
             WHERE oi.order_id = ?";
$item_stmt = $pdo->prepare($item_sql);
$item_stmt->execute([$order_id]);
$items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Progress Logic
$status = $order['order_status'];
$progress_width = 0;
if ($status == 'Processing') $progress_width = 33;
elseif ($status == 'Shipped') $progress_width = 66;
elseif ($status == 'Delivered') $progress_width = 100;
elseif ($status == 'Completed') $progress_width = 100;

function isStepActive($current_status, $step_name)
{
    $order_flow = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Completed'];
    return array_search($current_status, $order_flow) >= array_search($step_name, $order_flow);
}
?>

<div class="order-details-wrapper">
    <div class="order-status-track">
        <div class="status-header">
            <div class="status-header-left">
                <span class="status-label">Current Status</span>
                <span class="current-status-text"><?= htmlspecialchars($status) ?></span>
            </div>

            <div class="status-actions">
                <?php if ($status == 'Pending'): ?>
                    <form method="POST" action="order_list.php" class="status-update-form">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
                        <input type="hidden" name="new_status" value="Processing">
                        <button type="submit" class="btn-primary">Mark as Processing</button>
                    </form>
                <?php elseif ($status == 'Processing'): ?>
                    <form method="POST" action="order_list.php" class="status-update-form">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
                        <input type="hidden" name="new_status" value="Shipped">
                        <button type="submit" class="btn-primary">Mark as Shipped</button>
                    </form>
                <?php elseif ($status == 'Shipped'): ?>
                    <form method="POST" action="order_list.php" class="status-update-form">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
                        <input type="hidden" name="new_status" value="Delivered">
                        <button type="submit" class="btn-primary">Mark as Delivered</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!in_array($status, ['Cancelled', 'Refunded', 'Refund Requested', 'Refund Rejected'])): ?>
            <div class="track-steps">
                <div class="track-progress-bar" style="width: <?= $progress_width ?>%;"></div>

                <?php
                $steps = ['Pending' => 'Order Placed', 'Processing' => 'Processing', 'Shipped' => 'Shipped', 'Delivered' => 'Delivered'];
                foreach ($steps as $key => $label):
                    $active = isStepActive($status, $key) ? 'active' : '';
                ?>
                    <div class="step <?= $active ?>">
                        <div class="step-icon"><i class="icon fa fa-check"></i></div>
                        <div class="step-text"><?= $label ?></div>
                        <?php if ($key == 'Pending'): ?>
                            <div class="step-date"><?= date('M d, Y', strtotime($order['order_date'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="error-box" style="margin-bottom:0;">
                Order stopped. Status: <strong><?= htmlspecialchars($status) ?></strong>.
            </div>
        <?php endif; ?>
    </div>

    <div class="info-grid-row">
        <div class="info-card">
            <h4 class="info-card-title"><i class="icon fa fa-user"></i> Customer</h4>
            <div class="info-row">
                <div class="info-label">Name</div>
                <div class="info-value"><?= htmlspecialchars($order['username']) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Email</div>
                <div class="info-value"><?= htmlspecialchars($order['email']) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Phone</div>
                <div class="info-value"><?= htmlspecialchars($order['user_phone'] ?? 'N/A') ?></div>
            </div>
        </div>
        <div class="info-card">
            <h4 class="info-card-title"><i class="icon fa fa-location-dot"></i> Shipping</h4>
            <div class="info-row">
                <div class="info-label">Receiver</div>
                <div class="info-value"><?= htmlspecialchars($order['receiver_name']) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Address</div>
                <div class="info-value">
                    <?= htmlspecialchars($order['line1']) ?><br>
                    <?= !empty($order['line2']) ? htmlspecialchars($order['line2']) . '<br>' : '' ?>
                    <?= htmlspecialchars($order['city'] . ', ' . $order['state'] . ' ' . $order['postcode']) ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Phone</div>
                <div class="info-value"><?= htmlspecialchars($order['shipping_phone']) ?></div>
            </div>
        </div>
    </div>

    <div class="info-card" style="margin-bottom: 24px;">
        <h4 class="info-card-title"><i class="icon fa fa-credit-card"></i> Payment Information</h4>
        <div class="payment-grid">
            <div class="info-row">
                <div class="info-label">Method</div>
                <div class="info-value"><?= htmlspecialchars($order['payment_method'] ?? 'N/A') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Date</div>
                <div class="info-value"><?= !empty($order['payment_date']) ? date('M d, Y', strtotime($order['payment_date'])) : '-' ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Status</div>
                <div class="info-value" style="color: green;"><?= htmlspecialchars($order['payment_status'] ?? 'Pending') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Transaction ID</div>
                <div class="info-value" style="font-family: monospace; color: #0071e3;"><?= htmlspecialchars($order['transaction_id'] ?? '-') ?></div>
            </div>
        </div>
    </div>

    <div class="info-card">
        <h4 class="info-card-title"><i class="icon fa fa-box"></i> Order Items</h4>
        <div class="modal-item-list">
            <?php foreach ($items as $item): ?>
                <div class="modal-item">
                    <?php $img = !empty($item['image']) ? '../' . $item['image'] : 'https://placehold.co/60?text=No+Img'; ?>
                    <img src="<?= htmlspecialchars($img) ?>" class="modal-item-thumb" alt="Product">
                    <div class="modal-item-details">
                        <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                        <span class="item-meta"><?= htmlspecialchars($item['category_name'] ?? 'General') ?></span>
                    </div>
                    <div class="item-qty">x<?= $item['quantity'] ?></div>
                    <div class="item-price">$<?= number_format($item['price_at_purchase'], 2) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="summary-section">
            <div class="summary-row"><span>Subtotal</span><span>$<?= number_format($order['total_amount'] + $order['point_discount'], 2) ?></span></div>
            <?php if ($order['point_discount'] > 0): ?>
                <div class="summary-row"><span>Discount (Points)</span><span style="color: #d60000;">-$<?= number_format($order['point_discount'], 2) ?></span></div>
            <?php endif; ?>
            <div class="summary-row"><span>Shipping</span><span>$0.00</span></div>
            <div class="summary-row total"><span>Total</span><span>$<?= number_format($order['total_amount'], 2) ?></span></div>
        </div>
    </div>
</div>