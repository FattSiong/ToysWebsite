<?php
session_start();
require_once '../includes/db.php';

// ==========================================
// 1. INITIALIZATION
// ==========================================
$user_id        = null;
$user           = null;
$stats          = [];
$chart_labels   = [];
$chart_data     = [];
$recent_orders  = [];
$error_msg      = '';

// Check ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $error_msg = "No member specified.";
} else {
    $user_id = (int)$_GET['id'];
}

// ==========================================
// 2. FETCH DATA
// ==========================================
if ($user_id) {
    try {
        // A. Fetch User Profile
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // B. Fetch Aggregated Stats (Orders, Spent, Last Purchase)
            $stats_sql = "SELECT 
                            COUNT(*) as total_orders, 
                            SUM(total_amount) as total_spent,
                            MAX(order_date) as last_purchase
                          FROM orders 
                          WHERE user_id = ? AND order_status != 'Cancelled'";
            $stmt = $pdo->prepare($stats_sql);
            $stmt->execute([$user_id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // C. Fetch Chart Data (Last 12 Months)
            $chart_sql = "SELECT 
                            DATE_FORMAT(order_date, '%b %Y') as month_label, 
                            SUM(total_amount) as monthly_spent 
                          FROM orders 
                          WHERE user_id = ? AND order_status != 'Cancelled' 
                          GROUP BY YEAR(order_date), MONTH(order_date) 
                          ORDER BY order_date ASC 
                          LIMIT 12";
            $stmt = $pdo->prepare($chart_sql);
            $stmt->execute([$user_id]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $chart_labels[] = $row['month_label'];
                $chart_data[]   = $row['monthly_spent'];
            }

            // D. Fetch Recent Orders
            $orders_sql = "SELECT 
                            o.order_id, 
                            o.order_date, 
                            o.order_status, 
                            o.total_amount,
                            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) as item_count
                           FROM orders o
                           WHERE o.user_id = ? 
                           ORDER BY o.order_date DESC 
                           LIMIT 10";
            $stmt = $pdo->prepare($orders_sql);
            $stmt->execute([$user_id]);
            $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error_msg = "Member not found.";
        }
    } catch (PDOException $e) {
        $error_msg = "Database Error: " . $e->getMessage();
    }
}

// Helper: Profile Pic Logic
$profile_pic = 'https://ui-avatars.com/api/?name=User&background=random';
if ($user) {
    if (!empty($user['profile_photo'])) {
        $profile_pic = '../uploads/' . $user['profile_photo'];
    } else {
        $profile_pic = 'https://ui-avatars.com/api/?name=' . urlencode($user['username']) . '&background=random';
    }
}

include '../includes/header.php';
?>

<link rel="stylesheet" href="../css/member_details.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="details-container">

    <div class="details-nav">
        <a href="member_list.php" class="back-btn"><i class="icon fa-arrow-left"></i> Back</a>
    </div>

    <?php if ($error_msg): ?>
        <div class="error-banner">
            <?= htmlspecialchars($error_msg) ?>
        </div>
    <?php else: ?>

        <div class="profile-card">
            <div class="profile-main">
                <img src="<?= htmlspecialchars($profile_pic) ?>" alt="Profile" class="profile-avatar">
                <div class="profile-text">
                    <h1 class="user-name"><?= htmlspecialchars($user['username']) ?></h1>
                    <span class="user-role badge-role"><?= ucfirst($user['role']) ?></span>
                    <p class="user-joined">Member since <?= date('M Y', strtotime($user['created_at'])) ?></p>
                </div>
            </div>
            <div class="profile-contact">
                <div class="contact-item">
                    <span class="label">Email</span>
                    <span class="value"><?= htmlspecialchars($user['email']) ?></span>
                </div>
                <div class="contact-item">
                    <span class="label">Phone</span>
                    <span class="value"><?= htmlspecialchars($user['phone_number'] ?? 'N/A') ?></span>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="icon fa-shopping-cart"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Orders</span>
                    <span class="stat-value"><?= number_format($stats['total_orders'] ?? 0) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="icon fa-arrow-trend-up"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Spent</span>
                    <span class="stat-value">$<?= number_format($stats['total_spent'] ?? 0, 2) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-purple"><i class="icon fa-award"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Loyalty Points</span>
                    <span class="stat-value"><?= number_format($user['current_points'] ?? 0) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-orange"><i class="icon fa-calendar"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Last Purchase</span>
                    <span class="stat-value">
                        <?= !empty($stats['last_purchase']) ? date('M d, Y', strtotime($stats['last_purchase'])) : 'Never' ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="chart-section">
            <h3 class="section-title">Purchase History</h3>
            <div class="chart-wrapper">
                <canvas id="purchaseHistoryChart"></canvas>
            </div>
        </div>

        <div class="table-section">
            <h3 class="section-title">Recent Orders</h3>
            <div class="table-responsive">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_orders) > 0): ?>
                            <?php foreach ($recent_orders as $order): ?>
                                <tr>
                                    <td>#<?= $order['order_id'] ?></td>
                                    <td><?= date('M d, Y H:i', strtotime($order['order_date'])) ?></td>
                                    <td><?= $order['item_count'] ?> items</td>
                                    <td class="font-weight-bold">$<?= number_format($order['total_amount'], 2) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower($order['order_status']) ?>">
                                            <?= htmlspecialchars($order['order_status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center" style="padding: 20px;">No orders found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            const memberChartData = {
                labels: <?= json_encode($chart_labels) ?>,
                data: <?= json_encode($chart_data) ?>
            };
        </script>
        <script src="../js/member.js"></script>

    <?php endif; ?>
</div>

<?php include 'admin_footer.php'; ?>