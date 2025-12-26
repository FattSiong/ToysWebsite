<?php
// Start session and include database connection
session_start();
require_once '../includes/db.php';

// =========================================
// 1. HANDLE REQUESTS (POST/AJAX)
// =========================================

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $order_id = intval($_POST['order_id']);
    $new_status = $_POST['new_status'];
    $allowed_statuses = [
        'Pending',
        'Processing',
        'Shipped',
        'Delivered',
        'Completed',
        'Cancelled',
        'Refund Requested',
        'Refund Rejected',
        'Refunded'
    ];

    if (in_array($new_status, $allowed_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET order_status = ? WHERE order_id = ?");
            $stmt->execute([$new_status, $order_id]);

            // Fetch fresh statistics for AJAX response
            $stats_sql = "SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN order_status = 'Pending' THEN 1 ELSE 0 END) as pending,
                            SUM(CASE WHEN order_status = 'Processing' THEN 1 ELSE 0 END) as processing,
                            SUM(CASE WHEN order_status = 'Shipped' THEN 1 ELSE 0 END) as shipped,
                            SUM(CASE WHEN order_status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
                            SUM(CASE WHEN order_status = 'Completed' THEN 1 ELSE 0 END) as completed,
                            SUM(CASE WHEN order_status IN ('Cancelled', 'Refund Requested', 'Refund Rejected', 'Refunded') THEN 1 ELSE 0 END) as cancel_refund
                          FROM orders";
            $new_stats = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC);

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode(['success' => true, 'stats' => $new_stats]);
                exit;
            }
        } catch (PDOException $e) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
        }
    }
    // Fallback redirect
    header("Location: order_list.php?status=" . ($_GET['status'] ?? 'All'));
    exit;
}

// =========================================
// 2. FETCH DATA FOR VIEW
// =========================================

// Fetch Order Statistics (For top cards)
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN order_status = 'Pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN order_status = 'Processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN order_status = 'Shipped' THEN 1 ELSE 0 END) as shipped,
                SUM(CASE WHEN order_status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN order_status = 'Completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN order_status IN ('Cancelled', 'Refund Requested', 'Refund Rejected', 'Refunded') THEN 1 ELSE 0 END) as cancel_refund
              FROM orders";
$stats = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC);

// Prepare Filters & Pagination
$current_tab = $_GET['status'] ?? 'All';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_option = $_GET['sort'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$base_sql = " FROM orders o JOIN users u ON o.user_id = u.user_id";
$where_clause = " WHERE 1=1";
$params = [];

// Apply Status Filter
if ($current_tab !== 'All') {
    if ($current_tab === 'Cancel/Refund') {
        $where_clause .= " AND o.order_status IN ('Cancelled', 'Refund Requested', 'Refund Rejected', 'Refunded')";
    } else {
        $where_clause .= " AND o.order_status = :status";
        $params[':status'] = $current_tab;
    }
}

// Apply Search Filter
if (!empty($search_query)) {
    $where_clause .= " AND (o.order_id LIKE :search_id OR u.username LIKE :search_user OR u.email LIKE :search_email)";
    $params[':search_id'] = "%$search_query%";
    $params[':search_user'] = "%$search_query%";
    $params[':search_email'] = "%$search_query%";
}

// Count Total Records
$count_query = "SELECT COUNT(*) " . $base_sql . $where_clause;
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Sorting Logic
$order_by = "o.order_date DESC";
switch ($sort_option) {
    case 'id_asc':
        $order_by = "o.order_id ASC";
        break;
    case 'id_desc':
        $order_by = "o.order_id DESC";
        break;
    case 'date_asc':
        $order_by = "o.order_date ASC";
        break;
    case 'date_desc':
        $order_by = "o.order_date DESC";
        break;
    case 'item_asc':
        $order_by = "total_items ASC";
        break;
    case 'item_desc':
        $order_by = "total_items DESC";
        break;
    case 'total_asc':
        $order_by = "o.total_amount ASC";
        break;
    case 'total_desc':
        $order_by = "o.total_amount DESC";
        break;
}

// Fetch Orders
$sql = "SELECT o.*, u.username, u.email,
        (SELECT SUM(quantity) FROM order_items WHERE order_id = o.order_id) as total_items
        " . $base_sql . $where_clause . " 
        ORDER BY $order_by 
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getStatusColor($status)
{
    switch ($status) {
        case 'Completed':
            return 'status-green';
        case 'Delivered':
        case 'Shipped':
        case 'Processing':
            return 'status-blue';
        case 'Pending':
        case 'Refund Requested':
            return 'status-orange';
        case 'Cancelled':
        case 'Refunded':
        case 'Refund Rejected':
            return 'status-red';
        default:
            return 'status-gray';
    }
}

// Sort Helper
function getSortParams($colName, $currentSort)
{
    $params = $_GET;
    $icon = 'fa-sort';
    $activeClass = '';
    $nextSort = $colName . '_desc'; // default

    // Toggle logic
    if ($currentSort === $colName . '_desc') {
        $nextSort = $colName . '_asc';
        $icon = 'fa-sort-desc';
        $activeClass = 'active';
    } elseif ($currentSort === $colName . '_asc') {
        $nextSort = $colName . '_desc';
        $icon = 'fa-sort-asc';
        $activeClass = 'active';
    }

    $params['sort'] = $nextSort;
    $params['page'] = 1;
    return ['url' => '?' . http_build_query($params), 'icon' => $icon, 'class' => $activeClass];
}

include '../includes/header.php';
?>

<link rel="stylesheet" href="../css/order_details.css">

<div class="page-header">
    <h1 class="page-title">Orders Management</h1>
    <a href="refund_requests.php" class="btn-danger-solid">
        <i class="icon fa-file-lines"></i> Refund Requests
    </a>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-label">Total Orders</span>
        <span class="stat-value" id="stat-total"><?= number_format($stats['total']) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label c-pending">Pending</span>
        <span class="stat-value" id="stat-pending"><?= number_format($stats['pending']) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label c-processing">Processing</span>
        <span class="stat-value" id="stat-processing"><?= number_format($stats['processing']) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label c-shipped">Shipped</span>
        <span class="stat-value" id="stat-shipped"><?= number_format($stats['shipped']) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label c-delivered">Delivered</span>
        <span class="stat-value" id="stat-delivered"><?= number_format($stats['delivered']) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label c-completed">Completed</span>
        <span class="stat-value" id="stat-completed"><?= number_format($stats['completed']) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label c-cancel">Cancel/Refund</span>
        <span class="stat-value" id="stat-cancel-refund"><?= number_format($stats['cancel_refund']) ?></span>
    </div>
</div>

<div class="tabs-container">
    <a href="?status=All" class="tab-link <?= $current_tab == 'All' ? 'active' : '' ?>">All Orders</a>
    <a href="?status=Pending" class="tab-link <?= $current_tab == 'Pending' ? 'active' : '' ?>">Pending</a>
    <a href="?status=Processing" class="tab-link <?= $current_tab == 'Processing' ? 'active' : '' ?>">Processing</a>
    <a href="?status=Shipped" class="tab-link <?= $current_tab == 'Shipped' ? 'active' : '' ?>">Shipped</a>
    <a href="?status=Delivered" class="tab-link <?= $current_tab == 'Delivered' ? 'active' : '' ?>">Delivered</a>
    <a href="?status=Completed" class="tab-link <?= $current_tab == 'Completed' ? 'active' : '' ?>">Completed</a>
    <a href="?status=Cancel/Refund" class="tab-link <?= $current_tab == 'Cancel/Refund' ? 'active' : '' ?>">Cancel/Refund</a>
</div>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form">
        <?php if ($current_tab !== 'All'): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($current_tab) ?>">
        <?php endif; ?>

        <div class="filter-group search-group">
            <button type="submit" class="search-submit-btn"><i class="icon fa-search"></i></button>
            <input type="text" name="search" class="form-input search-input-field" placeholder="Search Order ID, Name, Email" value="<?= htmlspecialchars($search_query) ?>">
            <?php if (!empty($search_query)): ?>
                <a href="?status=<?= htmlspecialchars($current_tab) ?>" class="clear-search-btn"><i class="icon fa-close"></i></a>
            <?php endif; ?>
        </div>

        <div class="filter-group">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="All">All Status</option>
                <?php
                $status_options = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Completed', 'Cancelled', 'Refund Requested', 'Refund Rejected', 'Refunded'];
                foreach ($status_options as $opt):
                ?>
                    <option value="<?= $opt ?>" <?= $current_tab == $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
            <i class="icon fa-filter icon-right"></i>
        </div>

        <div class="filter-group sort-group">
            <select name="sort" class="form-select" onchange="this.form.submit()">
                <option value="" <?= empty($sort_option) ? 'selected' : '' ?> hidden>Sort</option>
                <option value="id_desc" <?= $sort_option == 'id_desc' ? 'selected' : '' ?>>Order ID (Desc)</option>
                <option value="id_asc" <?= $sort_option == 'id_asc' ? 'selected' : '' ?>>Order ID (Asc)</option>
                <option value="date_desc" <?= $sort_option == 'date_desc' ? 'selected' : '' ?>>Date (Newest)</option>
                <option value="date_asc" <?= $sort_option == 'date_asc' ? 'selected' : '' ?>>Date (Oldest)</option>
                <option value="item_desc" <?= $sort_option == 'item_desc' ? 'selected' : '' ?>>Items (High-Low)</option>
                <option value="item_asc" <?= $sort_option == 'item_asc' ? 'selected' : '' ?>>Items (Low-High)</option>
                <option value="total_desc" <?= $sort_option == 'total_desc' ? 'selected' : '' ?>>Total (High-Low)</option>
                <option value="total_asc" <?= $sort_option == 'total_asc' ? 'selected' : '' ?>>Total (Low-High)</option>
            </select>
        </div>
    </form>
</div>

<table class="data-table">
    <thead>
        <?php
        $sortId = getSortParams('id', $sort_option);
        $sortDate = getSortParams('date', $sort_option);
        $sortItems = getSortParams('item', $sort_option);
        $sortTotal = getSortParams('total', $sort_option);
        ?>
        <tr>
            <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
            <th><a href="<?= $sortId['url'] ?>" class="sortable-link <?= $sortId['class'] ?>">Order ID <i class="icon fa <?= $sortId['icon'] ?>"></i></a></th>
            <th>Customer Info</th>
            <th><a href="<?= $sortDate['url'] ?>" class="sortable-link <?= $sortDate['class'] ?>">Order Date <i class="icon fa <?= $sortDate['icon'] ?>"></i></a></th>
            <th><a href="<?= $sortItems['url'] ?>" class="sortable-link <?= $sortItems['class'] ?>">Items <i class="icon fa <?= $sortItems['icon'] ?>"></i></a></th>
            <th><a href="<?= $sortTotal['url'] ?>" class="sortable-link <?= $sortTotal['class'] ?>">Total <i class="icon fa <?= $sortTotal['icon'] ?>"></i></a></th>
            <th>Status</th>
            <th style="text-align: center;">Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($orders) > 0): ?>
            <?php foreach ($orders as $row): ?>
                <tr>
                    <td><input type="checkbox" name="selected_ids[]" value="<?= $row['order_id'] ?>"></td>
                    <td><strong>#<?= htmlspecialchars($row['order_id']) ?></strong></td>
                    <td>
                        <div style="display: flex; flex-direction: column;">
                            <span style="font-weight: 600; color: #1d1d1f;"><?= htmlspecialchars($row['username']) ?></span>
                            <span style="font-size: 12px; color: #86868b;"><?= htmlspecialchars($row['email']) ?></span>
                        </div>
                    </td>
                    <td>
                        <?= date('M d, Y', strtotime($row['order_date'])) ?><br>
                        <span style="font-size: 11px; color: #86868b;"><?= date('h:i A', strtotime($row['order_date'])) ?></span>
                    </td>
                    <td><span style="font-weight: 500; color: #1d1d1f;"><?= intval($row['total_items']) ?> Items</span></td>
                    <td>$<?= number_format($row['total_amount'], 2) ?></td>
                    <td><span class="text-status <?= getStatusColor($row['order_status']) ?>"><?= htmlspecialchars($row['order_status']) ?></span></td>
                    <td style="text-align: center;">
                        <button type="button" class="view-order-btn" data-id="<?= $row['order_id'] ?>" style="border:none; background:transparent; cursor:pointer; color:#6e6e73; transition: color 0.2s;">
                            <i class="icon fa fa-eye" style="font-size:16px;"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="8" style="text-align:center; padding: 40px; color: #86868b;">No orders found.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> orders</div>
        <div class="pagination-links">
            <?php function getPageUrl($pageNum)
            {
                $params = $_GET;
                $params['page'] = $pageNum;
                return '?' . http_build_query($params);
            } ?>
            <a href="<?= $page > 1 ? getPageUrl($page - 1) : '#' ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>"><i class="icon fa-chevron-left"></i></a>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="<?= getPageUrl($i) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a href="<?= $page < $total_pages ? getPageUrl($page + 1) : '#' ?>" class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="icon fa-chevron-right"></i></a>
        </div>
    </div>
<?php endif; ?>

<div class="modal-overlay" id="order-details-modal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Order Details</h3>
            <button type="button" class="btn-close-modal" id="closeOrderModalBtn">&times;</button>
        </div>
        <div class="modal-body"></div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" id="cancelOrderModalBtn">Close</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../js/order.js"></script>
<?php include 'admin_footer.php'; ?>