<?php
// Start session and include database connection
session_start();
require_once '../includes/db.php';

// --- 0. HANDLE BULK ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['selected_ids'])) {
    $ids = array_map('intval', $_POST['selected_ids']);
    $action = $_POST['bulk_action'];
    
    if (!empty($ids)) {
        $inQuery = implode(',', $ids);
        
        try {
            if ($action === 'activate') {
                $pdo->query("UPDATE products SET status = 'Active' WHERE product_id IN ($inQuery)");
            } elseif ($action === 'deactivate') {
                $pdo->query("UPDATE products SET status = 'Inactive' WHERE product_id IN ($inQuery)");
            } elseif ($action === 'delete') {
                // MODIFIED: Soft delete (Archive) instead of hard delete per user request.
                // This keeps the record in the database but unlists it (status = 'Archived').
                $pdo->query("UPDATE products SET status = 'Archived' WHERE product_id IN ($inQuery)");
            }
            // Redirect to avoid resubmission
            header("Location: product_list.php?status=" . ($_GET['status'] ?? 'All'));
            exit;
        } catch (PDOException $e) {
            $error_message = "Bulk action failed: " . $e->getMessage();
        }
    }
}

// --- 0.5. HANDLE SINGLE AJAX STATUS UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        $product_id = isset($input['id']) ? intval($input['id']) : null;
        $status = isset($input['status']) ? $input['status'] : null;
        if ($product_id && in_array($status, ['Active', 'Inactive', 'Archived'])) {
            try {
                $sql = "UPDATE products SET status = :status WHERE product_id = :id";
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([':status' => $status, ':id' => $product_id]);
                echo json_encode(['success' => $result]);
            } catch (PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        } else { echo json_encode(['success' => false, 'message' => 'Invalid input']); }
        exit;
    }
}

// --- 1. HANDLE TAB COUNTS ---
$count_sql = "SELECT 
                SUM(CASE WHEN status != 'Archived' THEN 1 ELSE 0 END) as total,
                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive_count,
                SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) as archived_count
              FROM products";
$count_stmt = $pdo->query($count_sql);
$counts = $count_stmt->fetch(PDO::FETCH_ASSOC);

// NEW: API Endpoint to return counts as JSON for JS updates
if (isset($_GET['action']) && $_GET['action'] === 'get_counts') {
    header('Content-Type: application/json');
    echo json_encode($counts);
    exit;
}

// --- 2. PREPARE FILTERS & PAGINATION ---
try {
    $cat_stmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $categories = []; }

$current_tab = $_GET['status'] ?? 'All';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = $_GET['category'] ?? '';
$min_price = $_GET['min_price'] ?? '';
$max_price = $_GET['max_price'] ?? '';
$sort_option = $_GET['sort'] ?? '';

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; 
$offset = ($page - 1) * $limit;

$base_sql = " FROM products p LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1";
if (!empty($category_filter)) { $base_sql .= " INNER JOIN product_categories pc ON p.product_id = pc.product_id"; }

$where_clause = " WHERE 1=1";
$params = [];

if ($current_tab !== 'All') {
    $where_clause .= " AND p.status = :status";
    $params[':status'] = $current_tab;
} else {
    $where_clause .= " AND p.status != 'Archived'";
}

if (!empty($search_query)) {
    $where_clause .= " AND (p.name LIKE :search_name OR p.description LIKE :search_desc)";
    $params[':search_name'] = "%$search_query%";
    $params[':search_desc'] = "%$search_query%";
}
if (!empty($category_filter)) {
    $where_clause .= " AND pc.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}
if ($min_price !== '') { $where_clause .= " AND p.price >= :min_price"; $params[':min_price'] = $min_price; }
if ($max_price !== '') { $where_clause .= " AND p.price <= :max_price"; $params[':max_price'] = $max_price; }

$count_query = "SELECT COUNT(DISTINCT p.product_id) " . $base_sql . $where_clause;
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// NEW: Redirect if page > total_pages (and total_pages > 0)
// This handles cases where items are deleted and the user refreshes or navigates to a now-empty page
if ($page > $total_pages && $total_pages > 0) {
    $redirectParams = $_GET;
    $redirectParams['page'] = $total_pages;
    header("Location: ?" . http_build_query($redirectParams));
    exit;
}

$sql = "SELECT DISTINCT p.*, pm.file_path " . $base_sql . $where_clause;
switch ($sort_option) {
    case 'name_asc': $sql .= " ORDER BY p.name ASC"; break;
    case 'name_desc': $sql .= " ORDER BY p.name DESC"; break;
    case 'price_asc': $sql .= " ORDER BY p.price ASC"; break;
    case 'price_desc': $sql .= " ORDER BY p.price DESC"; break;
    case 'quantity_asc': $sql .= " ORDER BY p.stock_quantity ASC"; break;
    case 'quantity_desc': $sql .= " ORDER BY p.stock_quantity DESC"; break;
    default: $sql .= " ORDER BY p.product_id DESC"; break;
}
$sql .= " LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'admin_header.php';
?>

<div class="page-header">
    <h1 class="page-title">Products</h1>
    <a href="product_form.php" class="btn-primary">Add product</a>
</div>

<div class="tabs-container">
    <a href="?status=All" class="tab-link <?= $current_tab == 'All' ? 'active' : '' ?>">All <span class="count-badge" id="badge-all"><?= $counts['total'] ?? 0 ?></span></a>
    <a href="?status=Active" class="tab-link <?= $current_tab == 'Active' ? 'active' : '' ?>">Activated <span class="count-badge" id="badge-active"><?= $counts['active_count'] ?? 0 ?></span></a>
    <a href="?status=Inactive" class="tab-link <?= $current_tab == 'Inactive' ? 'active' : '' ?>">Inactive <span class="count-badge" id="badge-inactive"><?= $counts['inactive_count'] ?? 0 ?></span></a>
    <a href="?status=Archived" class="tab-link <?= $current_tab == 'Archived' ? 'active' : '' ?>">Archived <span class="count-badge" id="badge-archived"><?= $counts['archived_count'] ?? 0 ?></span></a>
</div>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form" id="filterForm">
        <?php if ($current_tab !== 'All'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($current_tab) ?>"><?php endif; ?>
        <div class="filter-group search-group">
            <button type="button" class="search-submit-btn" onclick="this.form.submit()"><i class="icon fa-search"></i></button>
            <input type="text" name="search" class="form-input search-input-field" placeholder="Search Product" value="<?= htmlspecialchars($search_query) ?>">
        </div>
        <div class="filter-group">
            <select name="category" class="form-select" onchange="this.form.submit()">
                <option value="">Category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>" <?= $category_filter == $cat['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <i class="icon fa-filter icon-right"></i>
        </div>
        <div class="filter-group price-toggle-group">
            <?php $has_price = ($min_price !== '' || $max_price !== ''); ?>
            <button type="button" class="price-trigger-btn" id="priceBtn" style="display: <?= $has_price ? 'none' : 'flex' ?>;"><span>Price Range</span><i class="icon fa-filter icon-right" style="font-size: 10px; margin-left: auto;"></i></button>
            <div class="direct-inputs-wrapper" id="priceInputs" style="display: <?= $has_price ? 'flex' : 'none' ?>;">
                <button type="button" class="btn-close-price" id="closePriceBtn">&times;</button>
                <input type="number" name="min_price" id="minInput" class="direct-input" placeholder="Min" min="0" step="0.01" value="<?= htmlspecialchars($min_price) ?>">
                <span class="direct-separator">-</span>
                <input type="number" name="max_price" id="maxInput" class="direct-input" placeholder="Max" min="0" step="0.01" value="<?= htmlspecialchars($max_price) ?>">
            </div>
        </div>
        <div class="filter-group sort-group">
            <select name="sort" class="form-select" onchange="this.form.submit()">
                <option value="" <?= empty($sort_option) ? 'selected' : '' ?> hidden>Sort</option>
                <option value="name_asc" <?= $sort_option == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                <option value="name_desc" <?= $sort_option == 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                <option value="price_asc" <?= $sort_option == 'price_asc' ? 'selected' : '' ?>>Price (Low-High)</option>
                <option value="price_desc" <?= $sort_option == 'price_desc' ? 'selected' : '' ?>>Price (High-Low)</option>
                <option value="quantity_asc" <?= $sort_option == 'quantity_asc' ? 'selected' : '' ?>>Quantity (Low-High)</option>
                <option value="quantity_desc" <?= $sort_option == 'quantity_desc' ? 'selected' : '' ?>>Quantity (High-Low)</option>
            </select>
        </div>
    </form>
</div>

<!-- BULK ACTION WRAPPER FORM -->
<form method="POST" action="" id="bulkForm">
    <input type="hidden" name="bulk_action" id="bulk-action-input" value="">
    
    <table class="data-table" data-current-tab="<?= htmlspecialchars($current_tab) ?>">
        <thead>
            <?php
            function getSortParams($colName, $currentSort) {
                $params = $_GET; 
                $icon = 'fa-sort'; $activeClass = '';
                if ($colName === 'name') {
                    if ($currentSort === 'name_asc') { $params['sort'] = 'name_desc'; $icon = 'fa-sort-desc'; $activeClass = 'active'; }
                    elseif ($currentSort === 'name_desc') { $params['sort'] = 'name_asc'; $icon = 'fa-sort-asc'; $activeClass = 'active'; }
                    else { $params['sort'] = 'name_asc'; }
                }
                if ($colName === 'price') {
                    if ($currentSort === 'price_asc') { $params['sort'] = 'price_desc'; $icon = 'fa-sort-asc'; $activeClass = 'active'; }
                    elseif ($currentSort === 'price_desc') { $params['sort'] = 'price_asc'; $icon = 'fa-sort-desc'; $activeClass = 'active'; }
                    else { $params['sort'] = 'price_asc'; }
                }
                if ($colName === 'quantity') {
                    if ($currentSort === 'quantity_asc') { $params['sort'] = 'quantity_desc'; $icon = 'fa-sort-asc'; $activeClass = 'active'; }
                    elseif ($currentSort === 'quantity_desc') { $params['sort'] = 'quantity_asc'; $icon = 'fa-sort-desc'; $activeClass = 'active'; }
                    else { $params['sort'] = 'quantity_asc'; }
                }
                return ['url' => '?' . http_build_query($params), 'icon' => $icon, 'class' => $activeClass];
            }
            $sortName = getSortParams('name', $sort_option);
            $sortPrice = getSortParams('price', $sort_option);
            $sortQty  = getSortParams('quantity', $sort_option);
            ?>

            <!-- DEFAULT HEADER (Visible when nothing is selected) -->
            <tr id="default-header">
                <th style="width: 40px;"><input type="checkbox" id="selectAllDefault"></th>
                <th><a href="<?= $sortName['url'] ?>" class="sortable-link <?= $sortName['class'] ?>">Product <i class="icon fa <?= $sortName['icon'] ?>"></i></a></th>
                <th><a href="<?= $sortPrice['url'] ?>" class="sortable-link <?= $sortPrice['class'] ?>">Price <i class="icon fa <?= $sortPrice['icon'] ?>"></i></a></th>
                <th><a href="<?= $sortQty['url'] ?>" class="sortable-link <?= $sortQty['class'] ?>">Inventory quantity <i class="icon fa <?= $sortQty['icon'] ?>"></i></a></th>
                <th>Status</th>
                <th>Action</th>
            </tr>

            <!-- BULK HEADER (Visible when items selected) -->
            <tr id="bulk-header" class="bulk-header">
                <th style="width: 40px;">
                    <input type="checkbox" id="selectAllBulk">
                </th>
                <th>
                    <span class="selected-count" id="selected-count">0 Selected</span>
                </th>
                <th colspan="4">
                    <div class="bulk-buttons" style="justify-content: flex-end;">
                        <!-- Activate Button: Show on Inactive, Archived, All -->
                        <?php if ($current_tab === 'Inactive' || $current_tab === 'Archived' || $current_tab === 'All'): ?>
                            <button type="button" class="btn-bulk" data-action="activate">Activate</button>
                        <?php endif; ?>

                        <!-- Deactivate Button: Show on Active, All -->
                        <?php if ($current_tab === 'Active' || $current_tab === 'All'): ?>
                            <button type="button" class="btn-bulk" data-action="deactivate">Deactivate</button>
                        <?php endif; ?>

                        <!-- Delete Button: Show on Active, Inactive, All (Not Archived) -->
                        <?php if ($current_tab !== 'Archived'): ?>
                            <button type="button" class="btn-bulk btn-bulk-delete" data-action="delete" data-confirm="Are you sure you want to delete these products? They will be archived.">Delete</button>
                        <?php endif; ?>
                    </div>
                </th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($products) > 0): ?>
                <?php foreach ($products as $row): ?>
                    <tr id="row-<?= $row['product_id'] ?>">
                        <td><input type="checkbox" name="selected_ids[]" value="<?= $row['product_id'] ?>" class="row-checkbox"></td>
                        <td>
                            <div class="product-cell">
                                <?php
                                $db_path = $row['file_path'];
                                $img_src = !empty($db_path) ? (strpos($db_path, '../') === 0 ? $db_path : '../' . $db_path) : 'https://placehold.co/40?text=No+Img';
                                ?>
                                <img src="<?= htmlspecialchars($img_src) ?>" alt="Product" class="product-thumb">
                                <span><?= htmlspecialchars($row['name']) ?></span>
                            </div>
                        </td>
                        <td>$<?= number_format($row['price'], 2) ?></td>
                        <td><?= $row['stock_quantity'] ?> in stock</td>
                        <td>
                            <div class="status-wrapper">
                                <label class="status-toggle">
                                    <input type="checkbox" class="toggle-status" data-id="<?= $row['product_id'] ?>" data-current-status="<?= $row['status'] ?>" <?= $row['status'] == 'Active' ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <span class="status-text"><?= $row['status'] == 'Active' ? 'Activated' : 'Deactivated' ?></span>
                            </div>
                        </td>
                        <td>
                            <a href="product_form.php?id=<?= $row['product_id'] ?>" class="action-btn edit-btn" title="Edit"><i class="icon fa-edit"></i></a>
                            <?php if ($row['status'] === 'Archived'): ?>
                                <span class="action-btn" style="opacity: 0.3; cursor: not-allowed; color: #ccc;"><i class="icon fa-trash"></i></span>
                            <?php else: ?>
                                <a href="javascript:void(0)" class="action-btn delete-btn" data-id="<?= $row['product_id'] ?>" data-status="<?= $row['status'] ?>" title="Archive Product"><i class="icon fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" style="text-align:center; padding: 30px; color: #888;">No products found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</form>

<?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> products</div>
        <div class="pagination-links">
            <?php
            function getPageUrl($pageNum) { $params = $_GET; $params['page'] = $pageNum; return '?' . http_build_query($params); }
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../js/product.js"></script>
<?php include 'admin_footer.php'; ?>