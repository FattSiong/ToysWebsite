<?php
session_start();
require_once '../includes/db.php';

$page_title           = 'Refund Requests';

// --- 1. HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = intval($_POST['order_id']);
    $action = $_POST['action'];

    // Fetch order details first to get user and point data
    $stmtOrder = $pdo->prepare("SELECT user_id, points_redeemed, points_earned, order_status FROM orders WHERE order_id = ?");
    $stmtOrder->execute([$order_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        if ($action === 'approve') {
            try {
                $pdo->beginTransaction();

                // 1. Update Order Status
                $stmt = $pdo->prepare("UPDATE orders SET order_status = 'Refunded' WHERE order_id = ?");
                $stmt->execute([$order_id]);

                // 2. Update Payment Status AND Payment Method
                // Updates status to 'Refunded' and overwrites payment_method to 'Refunded' as requested
                $stmtPayment = $pdo->prepare("UPDATE payments SET status = 'Refunded', payment_method = 'Refunded' WHERE order_id = ?");
                $stmtPayment->execute([$order_id]);

                // 3. Restock Items
                $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmtItems->execute([$order_id]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?")
                        ->execute([$item['quantity'], $item['product_id']]);
                }

                // 4. Handle Points Logic
                $user_id = $order['user_id'];
                $points_earned = $order['points_earned'];
                $points_redeemed = $order['points_redeemed'];

                // A. Reverse Earned Points (Prevent keeping points from refunded order)
                if ($points_earned > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")
                        ->execute([$points_earned, $user_id]);
                    
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Reversed (Refund Approved)')")
                        ->execute([$user_id, $order_id, -$points_earned]);
                }

                // B. Return Redeemed Points (Give back points used for discount)
                if ($points_redeemed > 0) {
                    $pdo->prepare("UPDATE users SET current_points = current_points + ? WHERE user_id = ?")
                        ->execute([$points_redeemed, $user_id]);

                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Points Restored (Refund Approved)')")
                        ->execute([$user_id, $order_id, $points_redeemed]);
                }

                $pdo->commit();

            } catch (Exception $e) {
                $pdo->rollBack();
                // Optional: error handling
            }

        } elseif ($action === 'reject') {
            // Update status to 'Refund Rejected'
            $stmt = $pdo->prepare("UPDATE orders SET order_status = 'Refund Rejected' WHERE order_id = ?");
            $stmt->execute([$order_id]);
        }
        
        // Refresh page
        header("Location: refund_requests.php");
        exit;
    }
}

// --- 2. GET STATS ---
$stats_sql = "SELECT 
    COUNT(*) as total_requests,
    SUM(CASE WHEN order_status = 'Refund Requested' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN order_status = 'Refunded' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN order_status = 'Refunded' THEN total_amount ELSE 0 END) as total_refunded_amount
    FROM orders 
    WHERE order_status IN ('Refund Requested', 'Refunded', 'Refund Rejected')";
$stats = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC);

// --- 3. FILTER & SEARCH ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = $_GET['status'] ?? 'All';

$sql = "SELECT o.*, u.username, u.email,
        (SELECT GROUP_CONCAT(p.name SEPARATOR ', ') FROM order_items oi JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id) as products
        FROM orders o JOIN users u ON o.user_id = u.user_id
        WHERE o.order_status IN ('Refund Requested', 'Refunded', 'Refund Rejected')";

$params = [];
if (!empty($search_query)) {
    $sql .= " AND (o.order_id LIKE :search_id OR u.username LIKE :search_user OR u.email LIKE :search_email)";
    $params[':search_id'] = "%$search_query%";
    $params[':search_user'] = "%$search_query%";
    $params[':search_email'] = "%$search_query%";
}
if ($status_filter !== 'All') {
    if ($status_filter === 'Pending') $sql .= " AND o.order_status = 'Refund Requested'";
    elseif ($status_filter === 'Refunded') $sql .= " AND o.order_status = 'Refunded'";
    elseif ($status_filter === 'Rejected') $sql .= " AND o.order_status = 'Refund Rejected'";
}
$sql .= " ORDER BY o.order_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<link rel="stylesheet" href="../css/order_details.css">

<div class="form-page-header">
    <a href="order_list.php" class="back-link"><i class="icon fa-arrow-left"></i> Back</a>
    <h1 class="page-title"><?= htmlspecialchars($page_title) ?></h1>
</div>

<div class="refund-stats-grid">
    <div class="r-stat-card"><div class="r-stat-title">Total Requests</div><div class="r-stat-value val-blue"><?= $stats['total_requests'] ?></div></div>
    <div class="r-stat-card"><div class="r-stat-title">Pending</div><div class="r-stat-value val-orange"><?= $stats['pending'] ?></div></div>
    <div class="r-stat-card"><div class="r-stat-title">Refunded</div><div class="r-stat-value val-green"><?= $stats['approved'] ?></div></div>
    <div class="r-stat-card"><div class="r-stat-title">Total Refunded Amount</div><div class="r-stat-value val-purple">$<?= number_format($stats['total_refunded_amount'], 2) ?></div></div>
</div>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form">
        <div class="filter-group search-group">
            <button type="submit" class="search-submit-btn"><i class="icon fa-search"></i></button>
            <input type="text" name="search" class="form-input search-input-field" placeholder="Search by order #, name or email..." value="<?= htmlspecialchars($search_query) ?>">

            <?php if (!empty($search_query)): ?>
                <a href="?status=<?= htmlspecialchars($status_filter) ?>" class="clear-search-btn" title="Clear Search">
                    <i class="icon fa-close"></i>
                </a>
            <?php endif; ?>
        </div>

        <div class="filter-group">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="All" <?= $status_filter == 'All' ? 'selected' : '' ?>>All Status</option>
                <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Refunded" <?= $status_filter == 'Refunded' ? 'selected' : '' ?>>Refunded</option>
                <option value="Rejected" <?= $status_filter == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <i class="icon fa-filter icon-right"></i>
        </div>
    </form>
</div>

<?php if (count($refunds) > 0): ?>
    <?php foreach ($refunds as $row): ?>
        <?php
        $badgeClass = 'pending'; $badgeText = 'Pending';
        if ($row['order_status'] == 'Refunded') { $badgeClass = 'approved'; $badgeText = 'Refunded'; }
        if ($row['order_status'] == 'Refund Rejected') { $badgeClass = 'rejected'; $badgeText = 'Rejected'; }
        ?>
        <div class="refund-card">
            <div class="rc-header">
                <div class="rc-id-group">
                    <a href="#" class="view-order-btn rc-order-id" data-id="<?= $row['order_id'] ?>">#ORD-<?= $row['order_id'] ?></a>
                    <span class="rc-badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                </div>
                <?php if ($row['order_status'] == 'Refund Requested'): ?>
                    <div class="rc-actions">
                        <form method="POST" onsubmit="return confirm('Approve this refund? Status will change to Refunded.');">
                            <input type="hidden" name="order_id" value="<?= $row['order_id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn-approve"><i class="fa fa-check"></i> Approve</button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Reject this refund?');">
                            <input type="hidden" name="order_id" value="<?= $row['order_id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn-reject"><i class="fa fa-times"></i> Reject</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <div class="rc-grid">
                <div><span class="rc-label">Customer</span><div class="rc-value"><?= htmlspecialchars($row['username']) ?></div><div class="rc-email"><?= htmlspecialchars($row['email']) ?></div></div>
                <div><span class="rc-label">Product(s)</span><div class="rc-value"><?= mb_strimwidth(htmlspecialchars($row['products']), 0, 40, "...") ?></div></div>
                <div><span class="rc-label">Refund Amount</span><div class="rc-value rc-amount">$<?= number_format($row['total_amount'], 2) ?></div></div>
                <div><span class="rc-label">Order Date</span><div class="rc-value"><?= date('M d, Y', strtotime($row['order_date'])) ?></div><span class="rc-label" style="margin-top:6px;">Request Date</span><div class="rc-value" style="color:#666;"><?= date('M d, Y', strtotime($row['order_date'] . ' + 3 days')) ?></div></div>
            </div>
            <div class="rc-reason-box">
                <div class="rc-reason-tag"><i class="fa fa-exclamation-circle"></i> Reason Provided</div>
                <div class="rc-reason-text"><?= !empty($row['refund_reason']) ? htmlspecialchars($row['refund_reason']) : 'No specific reason provided by customer.' ?></div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div style="text-align:center; padding:50px; color:#888;">No refund requests found.</div>
<?php endif; ?>

<div class="modal-overlay" id="order-details-modal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Order Details</h3>
            <button class="btn-close-modal">&times;</button>
        </div>
        <div class="modal-body"></div>
        <div class="modal-footer">
            <button class="btn-secondary btn-close-modal">Close</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../js/order.js"></script>
<?php include 'admin_footer.php'; ?>