<?php
session_start();
// Include header (which should include db.php, but we check to be safe)
require_once '../includes/header.php';
if (!isset($pdo)) {
    require_once '../includes/db.php';
}

// ==========================================
// 1. DATA INITIALIZATION
// ==========================================
$total_revenue   = 0;
$total_orders    = 0;
$total_products  = 0;
$total_customers = 0;

$trend_labels    = [];
$trend_revenue   = [];
$trend_orders    = [];
$cat_labels      = [];
$cat_data        = [];
$inv_in_stock    = [];
$inv_low_stock   = [];
$inv_out_stock   = [];
$top_products    = [];

// ==========================================
// 2. DATA FETCHING
// ==========================================
try {
    // A. Top Cards Data
    $total_revenue   = $pdo->query("SELECT SUM(total_amount) FROM orders WHERE order_status != 'Cancelled'")->fetchColumn() ?: 0;
    $total_orders    = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?: 0;
    $total_products  = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() ?: 0;
    $total_customers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn() ?: 0;

    // B. Sales Trend (Last 7 Months)
    $trend_sql = "SELECT 
                    DATE_FORMAT(order_date, '%b') as month_name, 
                    SUM(total_amount) as total,
                    COUNT(*) as order_count 
                  FROM orders 
                  WHERE order_status != 'Cancelled' AND order_date >= DATE_SUB(NOW(), INTERVAL 7 MONTH)
                  GROUP BY YEAR(order_date), MONTH(order_date) 
                  ORDER BY order_date ASC";
    $stmt = $pdo->query($trend_sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $trend_labels[]  = $row['month_name'];
        $trend_revenue[] = $row['total'];
        $trend_orders[]  = $row['order_count'];
    }

    // C. Sales By Category
    $cat_sql = "SELECT c.name, SUM(oi.quantity * oi.price_at_purchase) as revenue
                FROM order_items oi
                JOIN products p ON oi.product_id = p.product_id
                JOIN product_categories pc ON p.product_id = pc.product_id
                JOIN categories c ON pc.category_id = c.category_id
                JOIN orders o ON oi.order_id = o.order_id
                WHERE o.order_status != 'Cancelled'
                GROUP BY c.category_id
                ORDER BY revenue DESC LIMIT 5";
    $stmt = $pdo->query($cat_sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cat_labels[] = $row['name'];
        $cat_data[]   = $row['revenue'];
    }

    // D. Inventory Logic (Helper Function)
    function fetchInventoryData($pdo, $condition)
    {
        $labels = [];
        $data   = [];
        $sql = "SELECT c.name, COUNT(p.product_id) as count
                FROM products p
                JOIN product_categories pc ON p.product_id = pc.product_id
                JOIN categories c ON pc.category_id = c.category_id
                WHERE $condition
                GROUP BY c.category_id
                ORDER BY count DESC
                LIMIT 5";
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $labels[] = $row['name'];
            $data[]   = $row['count'];
        }
        return ['labels' => $labels, 'data' => $data];
    }

    $inv_in_stock  = fetchInventoryData($pdo, "p.stock_quantity >= 100");
    $inv_low_stock = fetchInventoryData($pdo, "p.stock_quantity > 0 AND p.stock_quantity < 100");
    $inv_out_stock = fetchInventoryData($pdo, "p.stock_quantity = 0");

    // E. Top Selling Products
    $top_sql = "SELECT p.name, SUM(oi.quantity) as sold, SUM(oi.quantity * oi.price_at_purchase) as revenue 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.product_id 
                JOIN orders o ON oi.order_id = o.order_id
                WHERE o.order_status != 'Cancelled'
                GROUP BY p.product_id 
                ORDER BY sold DESC LIMIT 5";
    $stmt = $pdo->query($top_sql);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Dashboard Error: " . $e->getMessage());
}
?>

<link rel="stylesheet" href="../css/dashboard.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="dashboard-container">
    <div class="page-header">
        <h1 class="page-title">Dashboard</h1>
    </div>

    <div class="dashboard-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Total Revenue</span>
                <div class="stat-icon icon-blue">$</div>
            </div>
            <div class="stat-value">$<?= number_format($total_revenue, 2) ?></div>
            <div class="stat-desc">Total earnings</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Total Orders</span>
                <div class="stat-icon icon-green"><i class="icon fa-shopping-cart"></i></div>
            </div>
            <div class="stat-value"><?= number_format($total_orders) ?></div>
            <div class="stat-desc">Orders processed</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Total Products</span>
                <div class="stat-icon icon-purple"><i class="icon fa-box"></i></div>
            </div>
            <div class="stat-value"><?= number_format($total_products) ?></div>
            <div class="stat-desc">In catalog</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Members</span>
                <div class="stat-icon icon-orange"><i class="icon fa-users"></i></div>
            </div>
            <div class="stat-value"><?= number_format($total_customers) ?></div>
            <div class="stat-desc">Active users</div>
        </div>
    </div>

    <div class="grid-2-col">
        <div class="chart-card">
            <h3 class="chart-header">Sales Trend</h3>
            <div class="canvas-container">
                <canvas id="salesTrendChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3 class="chart-header">Sales by Category</h3>
            <div class="canvas-container">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>

    <div class="grid-2-equal">
        <div class="chart-card">
            <div class="chart-header-flex">
                <h3 class="chart-title-sm">Inventory Status</h3>
                <select id="inventoryFilter" class="chart-select">
                    <option value="inStock">In Stock</option>
                    <option value="lowStock" selected>Low Stock</option>
                    <option value="outStock">Out of Stock</option>
                </select>
            </div>
            <div class="canvas-container">
                <canvas id="inventoryChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3 class="chart-header">Top Selling Products</h3>
            <div class="table-container">
                <table class="top-products-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Sold</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_products)): ?>
                            <?php foreach ($top_products as $prod): ?>
                                <tr>
                                    <td><?= htmlspecialchars($prod['name']) ?></td>
                                    <td><?= $prod['sold'] ?></td>
                                    <td>$<?= number_format($prod['revenue'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" style="text-align:center; color: #999;">No sales data found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const dashboardData = {
        salesTrend: {
            labels: <?= json_encode($trend_labels) ?>,
            revenue: <?= json_encode($trend_revenue) ?>,
            orders: <?= json_encode($trend_orders) ?>
        },
        salesCategory: {
            labels: <?= json_encode($cat_labels) ?>,
            data: <?= json_encode($cat_data) ?>
        },
        inventory: {
            inStock: <?= json_encode($inv_in_stock) ?>,
            lowStock: <?= json_encode($inv_low_stock) ?>,
            outStock: <?= json_encode($inv_out_stock) ?>
        }
    };
</script>

<script src="../js/dashboard.js"></script>

<?php include 'admin_footer.php'; ?>