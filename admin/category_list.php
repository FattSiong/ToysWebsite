<?php
// Start session and include database connection
session_start();
require_once '../includes/db.php';

// --- HANDLE BULK ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['selected_ids'])) {
    $ids = array_map('intval', $_POST['selected_ids']);
    $action = $_POST['bulk_action'];

    if (!empty($ids) && $action === 'delete') {
        $inQuery = implode(',', $ids);
        try {
            // Delete product associations first if strictly enforced, or just delete category
            // Assuming cascading delete or simple deletion is allowed
            $pdo->query("DELETE FROM product_categories WHERE category_id IN ($inQuery)");
            $pdo->query("DELETE FROM categories WHERE category_id IN ($inQuery)");

            header("Location: category_list.php");
            exit;
        } catch (PDOException $e) {
            $error_message = "Bulk action failed: " . $e->getMessage();
        }
    }
}

// --- HANDLE FILTERING & SEARCH ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_option = $_GET['sort'] ?? '';

// Pagination Variables
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Base SQL structure
$base_sql = " FROM categories c LEFT JOIN product_categories pc ON c.category_id = pc.category_id WHERE 1=1";
$params = [];

if (!empty($search_query)) {
    $base_sql .= " AND c.name LIKE :search";
    $params[':search'] = "%$search_query%";
}

// --- COUNT QUERY ---
$count_sql = "SELECT COUNT(DISTINCT c.category_id)" . $base_sql;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// --- MAIN QUERY ---
$sql = "SELECT c.*, COUNT(pc.product_id) as product_count" . $base_sql;
$sql .= " GROUP BY c.category_id";

// SORT LOGIC
switch ($sort_option) {
    case 'name_asc':
        $sql .= " ORDER BY c.name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY c.name DESC";
        break;
    case 'count_high':
        $sql .= " ORDER BY product_count DESC, c.name ASC";
        break;
    case 'count_low':
        $sql .= " ORDER BY product_count ASC, c.name ASC";
        break;
    default:
        $sql .= " ORDER BY c.category_id DESC";
        break;
}

$sql .= " LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Categories</h1>
    <a href="category_form.php" class="btn-primary">Add category</a>
</div>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form">
        <div class="filter-group search-group">
            <button type="submit" class="search-submit-btn">
                <i class="icon fa-search"></i>
            </button>
            <input type="text" name="search" class="form-input search-input-field" placeholder="Search Category" value="<?= htmlspecialchars($search_query) ?>">

            <?php if (!empty($search_query)): ?>
                <a href="category_list.php" class="clear-search-btn" title="Clear Search"><i class="icon fa-close"></i></a>
            <?php endif; ?>
        </div>

        <div class="filter-group sort-group">
            <select name="sort" class="form-select" onchange="this.form.submit()">
                <option value="" <?= empty($sort_option) ? 'selected' : '' ?> hidden>Sort</option>
                <option value="name_asc" <?= $sort_option == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                <option value="name_desc" <?= $sort_option == 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                <option value="count_high" <?= $sort_option == 'count_high' ? 'selected' : '' ?>>Most Products</option>
                <option value="count_low" <?= $sort_option == 'count_low' ? 'selected' : '' ?>>Least Products</option>
            </select>
        </div>
    </form>
</div>

<form method="POST" action="" id="bulkForm">
    <input type="hidden" name="bulk_action" id="bulk-action-input" value="">

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
                if ($colName === 'count') {
                    if ($currentSort === 'count_low') {
                        $params['sort'] = 'count_high';
                        $icon = 'fa-sort-asc';
                        $activeClass = 'active';
                    } elseif ($currentSort === 'count_high') {
                        $params['sort'] = 'count_low';
                        $icon = 'fa-sort-desc';
                        $activeClass = 'active';
                    } else {
                        $params['sort'] = 'count_low';
                    }
                }
                return ['url' => '?' . http_build_query($params), 'icon' => $icon, 'class' => $activeClass];
            }
            $sortName = getSortParams('name', $sort_option);
            $sortCount = getSortParams('count', $sort_option);
            ?>

            <tr id="default-header">
                <th style="width: 40px;"><input type="checkbox" id="selectAllDefault"></th>
                <th><a href="<?= $sortName['url'] ?>" class="sortable-link <?= $sortName['class'] ?>">Category <i class="icon fa <?= $sortName['icon'] ?>"></i></a></th>
                <th><a href="<?= $sortCount['url'] ?>" class="sortable-link <?= $sortCount['class'] ?>">Product quantity <i class="icon fa <?= $sortCount['icon'] ?>"></i></a></th>
                <th>Action</th>
            </tr>

            <tr id="bulk-header" class="bulk-header">
                <th style="width: 40px;">
                    <input type="checkbox" id="selectAllBulk">
                </th>
                <th>
                    <span class="selected-count" id="selected-count">0 Selected</span>
                </th>
                <th colspan="2">
                    <div class="bulk-buttons" style="justify-content: flex-end;">
                        <button type="button" class="btn-bulk btn-bulk-delete" data-action="delete" data-confirm="Are you sure? This will delete selected categories.">Delete</button>
                    </div>
                </th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($categories) > 0): ?>
                <?php foreach ($categories as $row): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="<?= $row['category_id'] ?>" class="row-checkbox"></td>
                        <td>
                            <span class="row-title"><?= htmlspecialchars($row['name']) ?></span>
                            <?php if (!empty($row['description'])): ?>
                                <div class="row-subtitle">
                                    <?= htmlspecialchars(substr($row['description'], 0, 50)) . (strlen($row['description']) > 50 ? '...' : '') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['product_count'] ?></td>
                        <td>
                            <a href="category_form.php?id=<?= $row['category_id'] ?>" class="action-btn edit-btn" title="Edit"><i class="icon fa-edit"></i></a>
                            <a href="category_delete.php?id=<?= $row['category_id'] ?>" class="action-btn delete-btn" title="Delete" onclick="return confirm('Are you sure?');"><i class="icon fa-trash"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align:center; padding: 30px; color: #888;">No categories found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</form>

<?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> categories</div>
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
            <?php else: ?><span class="page-link disabled"><i class="icon fa-chevron-left"></i></span><?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == 1 || $i == $total_pages || ($i >= $start_loop && $i <= $end_loop)): ?>
                    <?php if ($i == $page): ?><span class="page-link active"><?= $i ?></span>
                    <?php else: ?><a href="<?= getPageUrl($i) ?>" class="page-link"><?= $i ?></a><?php endif; ?>
                <?php else: ?>
                    <?php if ($i == $start_loop - 1 || $i == $end_loop + 1): ?><span class="page-link disabled">...</span><?php endif; ?>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= getPageUrl($page + 1) ?>" class="page-link"><i class="icon fa-chevron-right"></i></a>
            <?php else: ?><span class="page-link disabled"><i class="icon fa-chevron-right"></i></span><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include 'admin_footer.php'; ?>