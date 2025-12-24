<?php
// member_list.php
session_start();

// 1. Include the Admin Header and DB
include '../includes/header.php';
if (!isset($pdo)) {
    include '../includes/db.php';
}

// --- 1. PREPARE FILTERS & PAGINATION ---

// Search & Sort Inputs
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_option = $_GET['sort'] ?? '';

// Pagination Variables
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// --- 2. BUILD SQL QUERY ---

// Base SQL
$base_sql = " FROM users u
              LEFT JOIN orders o ON u.user_id = o.user_id
              WHERE u.role = 'member'";
$params = [];

// Apply Search
if (!empty($search_query)) {
    // UPDATED: Use unique parameter names for each field to prevent "Invalid parameter number" error
    $base_sql .= " AND (u.username LIKE :search_name OR u.email LIKE :search_email OR u.phone_number LIKE :search_phone)";
    $params[':search_name'] = "%$search_query%";
    $params[':search_email'] = "%$search_query%";
    $params[':search_phone'] = "%$search_query%";
}

// --- 3. COUNT TOTAL RECORDS (For Pagination) ---
// We count DISTINCT user_ids because of the LEFT JOIN with orders
$count_sql = "SELECT COUNT(DISTINCT u.user_id) " . $base_sql;
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Handle Page Overflow (e.g. deleting last item on page 2)
if ($page > $total_pages && $total_pages > 0) {
    $redirectParams = $_GET;
    $redirectParams['page'] = $total_pages;
    header("Location: ?" . http_build_query($redirectParams));
    exit;
}

// --- 4. FETCH DATA ---
$sql = "SELECT 
            u.user_id as id, 
            u.username as name, 
            u.email, 
            u.phone_number as phone, 
            u.created_at,
            COUNT(o.order_id) as total_orders, 
            COALESCE(SUM(CASE WHEN o.order_status != 'Cancelled' THEN o.total_amount ELSE 0 END), 0) as total_spent
        " . $base_sql;

// Group By
$sql .= " GROUP BY u.user_id";

// Apply Sorting
switch ($sort_option) {
    case 'name_asc':
        $sql .= " ORDER BY u.username ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY u.username DESC";
        break;
    case 'email_asc':
        $sql .= " ORDER BY u.email ASC";
        break;
    case 'email_desc':
        $sql .= " ORDER BY u.email DESC";
        break;
    case 'order_asc':
        $sql .= " ORDER BY total_orders ASC";
        break;
    case 'order_desc':
        $sql .= " ORDER BY total_orders DESC";
        break;
    case 'spent_asc':
        $sql .= " ORDER BY total_spent ASC";
        break;
    case 'spent_desc':
        $sql .= " ORDER BY total_spent DESC";
        break;
    default:
        $sql .= " ORDER BY u.user_id DESC";
        break;
}

// Limit & Offset
$sql .= " LIMIT :limit OFFSET :offset";

// Execute
try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $members = $stmt->fetchAll();
} catch (PDOException $e) {
    $members = [];
    $error_message = "Error fetching members: " . $e->getMessage();
}
?>

<div class="page-header">
    <h1 class="page-title">Members</h1>
</div>

<?php if (isset($error_message)): ?>
    <div style="background: #fee2e2; color: #b91c1c; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
        <?= htmlspecialchars($error_message) ?>
    </div>
<?php endif; ?>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form">
        <div class="filter-group search-group">
            <button type="submit" class="search-submit-btn">
                <i class="icon fa-search"></i>
            </button>
            <input type="text" name="search" class="form-input search-input-field"
                placeholder="Search Member"
                value="<?= htmlspecialchars($search_query) ?>">

            <?php if (!empty($search_query)): ?>
                <a href="member_list.php" class="clear-search-btn" title="Clear Search"><i class="icon fa-close"></i></a>
            <?php endif; ?>
        </div>

        <div class="filter-group sort-group">
            <select name="sort" class="form-select" onchange="this.form.submit()">
                <option value="" <?= empty($sort_option) ? 'selected' : '' ?> hidden>Sort</option>
                <option value="name_asc" <?= $sort_option == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                <option value="name_desc" <?= $sort_option == 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                <option value="email_asc" <?= $sort_option == 'email_asc' ? 'selected' : '' ?>>Email (A-Z)</option>
                <option value="email_desc" <?= $sort_option == 'email_desc' ? 'selected' : '' ?>>Email (Z-A)</option>
                <option value="order_desc" <?= $sort_option == 'order_desc' ? 'selected' : '' ?>>Most Orders</option>
                <option value="order_asc" <?= $sort_option == 'order_asc' ? 'selected' : '' ?>>Least Orders</option>
                <option value="spent_desc" <?= $sort_option == 'spent_desc' ? 'selected' : '' ?>>Highest Spenders</option>
                <option value="spent_asc" <?= $sort_option == 'spent_asc' ? 'selected' : '' ?>>Lowest Spenders</option>
            </select>
        </div>
    </form>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <?php
            function getSortParams($colName, $currentSort)
            {
                $params = $_GET;
                $icon = 'fa-sort';
                $activeClass = '';

                if ($colName === 'name') {
                    if ($currentSort === 'name_asc') {
                        $params['sort'] = 'name_desc';
                        $icon = 'fa-sort-desc';
                        $activeClass = 'active';
                    } elseif ($currentSort === 'name_desc') {
                        $params['sort'] = 'name_asc';
                        $icon = 'fa-sort-asc';
                        $activeClass = 'active';
                    } else {
                        $params['sort'] = 'name_asc';
                    }
                }

                if ($colName === 'email') {
                    if ($currentSort === 'email_asc') {
                        $params['sort'] = 'email_desc';
                        $icon = 'fa-sort-desc';
                        $activeClass = 'active';
                    } elseif ($currentSort === 'email_desc') {
                        $params['sort'] = 'email_asc';
                        $icon = 'fa-sort-asc';
                        $activeClass = 'active';
                    } else {
                        $params['sort'] = 'email_asc';
                    }
                }

                if ($colName === 'orders') {
                    if ($currentSort === 'order_asc') {
                        $params['sort'] = 'order_desc';
                        $icon = 'fa-sort-asc';
                        $activeClass = 'active';
                    } elseif ($currentSort === 'order_desc') {
                        $params['sort'] = 'order_asc';
                        $icon = 'fa-sort-desc';
                        $activeClass = 'active';
                    } else {
                        $params['sort'] = 'order_desc';
                    }
                }

                if ($colName === 'spent') {
                    if ($currentSort === 'spent_asc') {
                        $params['sort'] = 'spent_desc';
                        $icon = 'fa-sort-asc';
                        $activeClass = 'active';
                    } elseif ($currentSort === 'spent_desc') {
                        $params['sort'] = 'spent_asc';
                        $icon = 'fa-sort-desc';
                        $activeClass = 'active';
                    } else {
                        $params['sort'] = 'spent_desc';
                    }
                }
                return ['url' => '?' . http_build_query($params), 'icon' => $icon, 'class' => $activeClass];
            }
            $sortName   = getSortParams('name', $sort_option);
            $sortEmail  = getSortParams('email', $sort_option);
            $sortOrders = getSortParams('orders', $sort_option);
            $sortSpent  = getSortParams('spent', $sort_option);
            ?>

            <tr>
                <th><a href="<?= $sortName['url'] ?>" class="sortable-link <?= $sortName['class'] ?>">Name <i class="icon fa <?= $sortName['icon'] ?>"></i></a></th>
                <th><a href="<?= $sortEmail['url'] ?>" class="sortable-link <?= $sortEmail['class'] ?>">Email <i class="icon fa <?= $sortEmail['icon'] ?>"></i></a></th>
                <th>Phone Number</th>
                <th style="text-align: center;"><a href="<?= $sortOrders['url'] ?>" class="sortable-link <?= $sortOrders['class'] ?>">Orders <i class="icon fa <?= $sortOrders['icon'] ?>"></i></a></th>
                <th style="text-align: right;"><a href="<?= $sortSpent['url'] ?>" class="sortable-link <?= $sortSpent['class'] ?>">Total Spent <i class="icon fa <?= $sortSpent['icon'] ?>"></i></a></th>
                <th style="text-align: center;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($members) > 0): ?>
                <?php foreach ($members as $row): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <span class="user-name"><?= htmlspecialchars($row['name']) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($row['email']) ?></td>
                        <td><?= htmlspecialchars($row['phone'] ?? 'N/A') ?></td>
                        <td style="text-align: center;"><?= $row['total_orders'] ?></td>
                        <td style="text-align: right;">$<?= number_format($row['total_spent'], 2) ?></td>
                        <td style="text-align: center;">
                            <a href="member_details.php?id=<?= $row['id'] ?>" class="action-btn" title="View Details">
                                <i class="icon fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 30px; color: #888;">
                        No members found.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> members</div>
        <div class="pagination-links">
            <?php
            function getPageUrl($pageNum)
            {
                $params = $_GET;
                $params['page'] = $pageNum;
                return '?' . http_build_query($params);
            }
            $adjacents = 1;
            $start_loop = max(1, $page - $adjacents);
            $end_loop   = min($total_pages, $page + $adjacents);
            ?>

            <?php if ($page > 1): ?>
                <a href="<?= getPageUrl($page - 1) ?>" class="page-link"><i class="icon fa-chevron-left"></i></a>
            <?php else: ?>
                <span class="page-link disabled"><i class="icon fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == 1 || $i == $total_pages || ($i >= $start_loop && $i <= $end_loop)): ?>
                    <?php if ($i == $page): ?>
                        <span class="page-link active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= getPageUrl($i) ?>" class="page-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($i == $start_loop - 1 || $i == $end_loop + 1): ?>
                        <span class="page-link disabled">...</span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= getPageUrl($page + 1) ?>" class="page-link"><i class="icon fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="page-link disabled"><i class="icon fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<?php include 'admin_footer.php'; ?>