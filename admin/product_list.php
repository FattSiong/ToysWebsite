<?php
// Start session and include database connection
session_start();
require_once '../includes/db.php';

// --- 0. HANDLE AJAX STATUS UPDATE ---
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
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid input']);
        }
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

// --- 2. PREPARE FILTERS & PAGINATION ---

// A. Categories
try {
    $cat_stmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// B. Get Current Values
$current_tab = $_GET['status'] ?? 'All';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = $_GET['category'] ?? '';
$min_price = $_GET['min_price'] ?? '';
$max_price = $_GET['max_price'] ?? '';
$sort_option = $_GET['sort'] ?? '';

// Pagination Variables
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Show 10 products per page
$offset = ($page - 1) * $limit;

// C. Build Query Logic
// We need two queries: one for Counting Total Rows (for pagination) and one for Fetching Data

// Base Condition & Joins
$base_sql = " FROM products p 
              LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1";

if (!empty($category_filter)) {
    $base_sql .= " INNER JOIN product_categories pc ON p.product_id = pc.product_id";
}

$where_clause = " WHERE 1=1";
$params = [];

// 1. Status Filter
if ($current_tab !== 'All') {
    $where_clause .= " AND p.status = :status";
    $params[':status'] = $current_tab;
} else {
    $where_clause .= " AND p.status != 'Archived'";
}

// 2. Search Filter
if (!empty($search_query)) {
    $where_clause .= " AND (p.name LIKE :search_name OR p.description LIKE :search_desc)";
    $params[':search_name'] = "%$search_query%";
    $params[':search_desc'] = "%$search_query%";
}

// 3. Category Filter
if (!empty($category_filter)) {
    $where_clause .= " AND pc.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

// 4. Price Filter
if ($min_price !== '') {
    $where_clause .= " AND p.price >= :min_price";
    $params[':min_price'] = $min_price;
}
if ($max_price !== '') {
    $where_clause .= " AND p.price <= :max_price";
    $params[':max_price'] = $max_price;
}

// --- D. Execute Count Query ---
// Note: using COUNT(DISTINCT p.product_id) ensures accuracy with joins
$count_query = "SELECT COUNT(DISTINCT p.product_id) " . $base_sql . $where_clause;
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// --- E. Execute Data Query ---
$sql = "SELECT DISTINCT p.*, pm.file_path " . $base_sql . $where_clause;

// 5. SORT LOGIC
switch ($sort_option) {
    case 'name_asc':
        $sql .= " ORDER BY p.name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY p.name DESC";
        break;
    case 'price_asc':
        $sql .= " ORDER BY p.price ASC";
        break;
    case 'price_desc':
        $sql .= " ORDER BY p.price DESC";
        break;
    case 'quantity_asc':
        $sql .= " ORDER BY p.stock_quantity ASC";
        break;
    case 'quantity_desc':
        $sql .= " ORDER BY p.stock_quantity DESC";
        break;
    default:
        $sql .= " ORDER BY p.product_id DESC";
        break;
}

// 6. Pagination Limit
$sql .= " LIMIT :limit OFFSET :offset";

// Bind limit/offset specifically as Integers for PDO
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
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
    <a href="?status=All" class="tab-link <?= $current_tab == 'All' ? 'active' : '' ?>">
        All <span class="count-badge" id="badge-all"><?= $counts['total'] ?? 0 ?></span>
    </a>
    <a href="?status=Active" class="tab-link <?= $current_tab == 'Active' ? 'active' : '' ?>">
        Activated <span class="count-badge" id="badge-active"><?= $counts['active_count'] ?? 0 ?></span>
    </a>
    <a href="?status=Inactive" class="tab-link <?= $current_tab == 'Inactive' ? 'active' : '' ?>">
        Inactive <span class="count-badge" id="badge-inactive"><?= $counts['inactive_count'] ?? 0 ?></span>
    </a>
    <a href="?status=Archived" class="tab-link <?= $current_tab == 'Archived' ? 'active' : '' ?>">
        Archived <span class="count-badge" id="badge-archived"><?= $counts['archived_count'] ?? 0 ?></span>
    </a>
</div>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form" id="filterForm">

        <?php if ($current_tab !== 'All'): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($current_tab) ?>">
        <?php endif; ?>

        <div class="filter-group search-group">
            <button type="button" class="search-submit-btn" onclick="this.form.submit()" title="Click to Filter">
                <i class="icon fa-search"></i>
            </button>
            <input type="text" name="search" class="form-input search-input-field"
                placeholder="Search Product"
                value="<?= htmlspecialchars($search_query) ?>">
        </div>

        <div class="filter-group">
            <select name="category" class="form-select" onchange="this.form.submit()">
                <option value="">Category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>" <?= $category_filter == $cat['category_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <i class="icon fa-filter icon-right"></i>
        </div>

        <div class="filter-group price-toggle-group">
            <?php
            $has_price = ($min_price !== '' || $max_price !== '');
            $display_btn = $has_price ? 'none' : 'flex';
            $display_inputs = $has_price ? 'flex' : 'none';
            ?>
            <button type="button" class="price-trigger-btn" id="priceBtn" style="display: <?= $display_btn ?>;">
                <span>Price Range</span>
                <i class="icon fa-filter icon-right" style="font-size: 10px; margin-left: auto;"></i>
            </button>
            <div class="direct-inputs-wrapper" id="priceInputs" style="display: <?= $display_inputs ?>;">
                <button type="button" class="btn-close-price" id="closePriceBtn" title="Clear Price">&times;</button>
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

<table class="data-table" data-current-tab="<?= htmlspecialchars($current_tab) ?>">
    <thead>
        <?php
        // Helper function to generate sort links maintaining current filters
        function getSortParams($colName, $currentSort) {
            $params = $_GET; // Copy current URL parameters (search, category, etc.)
            
            // Default Icon
            $icon = 'fa-sort';
            $activeClass = '';
            
            // Logic for Product Name
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
            
            // Logic for Price
            if ($colName === 'price') {
                if ($currentSort === 'price_asc') {
                    $params['sort'] = 'price_desc';
                    $icon = 'fa-sort-asc'; // Low to High
                    $activeClass = 'active';
                } elseif ($currentSort === 'price_desc') {
                    $params['sort'] = 'price_asc';
                    $icon = 'fa-sort-desc'; 
                    $activeClass = 'active';
                } else {
                    $params['sort'] = 'price_asc';
                }
            }
            
            // Logic for Quantity
            if ($colName === 'quantity') {
                if ($currentSort === 'quantity_asc') {
                    $params['sort'] = 'quantity_desc';
                    $icon = 'fa-sort-asc';
                    $activeClass = 'active';
                } elseif ($currentSort === 'quantity_desc') {
                    $params['sort'] = 'quantity_asc';
                    $icon = 'fa-sort-desc';
                    $activeClass = 'active';
                } else {
                    $params['sort'] = 'quantity_asc';
                }
            }

            return [
                'url' => '?' . http_build_query($params),
                'icon' => $icon,
                'class' => $activeClass
            ];
        }
        
        $sortName = getSortParams('name', $sort_option);
        $sortPrice = getSortParams('price', $sort_option);
        $sortQty  = getSortParams('quantity', $sort_option);
        ?>

        <tr>
            <th style="width: 40px;"><input type="checkbox"></th>
            <th>
                <a href="<?= $sortName['url'] ?>" class="sortable-link <?= $sortName['class'] ?>">
                    Product <i class="icon fa <?= $sortName['icon'] ?>"></i>
                </a>
            </th>
            <th>
                <a href="<?= $sortPrice['url'] ?>" class="sortable-link <?= $sortPrice['class'] ?>">
                    Price <i class="icon fa <?= $sortPrice['icon'] ?>"></i>
                </a>
            </th>
            <th>
                <a href="<?= $sortQty['url'] ?>" class="sortable-link <?= $sortQty['class'] ?>">
                    Inventory quantity <i class="icon fa <?= $sortQty['icon'] ?>"></i>
                </a>
            </th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($products) > 0): ?>
            <?php foreach ($products as $row): ?>
                <tr id="row-<?= $row['product_id'] ?>">
                    <td><input type="checkbox" name="product_ids[]" value="<?= $row['product_id'] ?>"></td>

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
                                <input type="checkbox"
                                    class="toggle-status"
                                    data-id="<?= $row['product_id'] ?>"
                                    data-current-status="<?= $row['status'] ?>"
                                    <?= $row['status'] == 'Active' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <span class="status-text"><?= $row['status'] == 'Active' ? 'Activated' : 'Deactivated' ?></span>
                        </div>
                    </td>

                    <td>
                        <a href="product_form.php?id=<?= $row['product_id'] ?>" class="action-btn edit-btn" title="Edit">
                            <i class="icon fa-edit"></i>
                        </a>

                        <?php if ($row['status'] === 'Archived'): ?>
                            <!-- Disabled Delete Button for Archived items -->
                            <span class="action-btn"
                                title="Already Archived"
                                style="opacity: 0.3; cursor: not-allowed; color: #ccc;">
                                <i class="icon fa-trash"></i>
                            </span>
                        <?php else: ?>
                            <!-- Active Delete Button for other items -->
                            <a href="javascript:void(0)"
                                class="action-btn delete-btn"
                                data-id="<?= $row['product_id'] ?>"
                                data-status="<?= $row['status'] ?>"
                                title="Archive Product">
                                <i class="icon fa-trash"></i>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="6" style="text-align:center; padding: 30px; color: #888;">No products found.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- PAGINATION CONTROLS -->
<?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">
            Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> products
        </div>
        <div class="pagination-links">
            <?php
            // Helper to keep current filters in pagination links
            function getPageUrl($pageNum) {
                $params = $_GET;
                $params['page'] = $pageNum;
                return '?' . http_build_query($params);
            }

            // Define how many pages to show around the current page
            $adjacents = 1; 
            
            // Calculate start and end for the "middle" window
            // We want to verify we don't go below 1 or above total_pages
            $start_loop = max(1, $page - $adjacents);
            $end_loop   = min($total_pages, $page + $adjacents);
            ?>

            <!-- Previous Button -->
            <?php if ($page > 1): ?>
                <a href="<?= getPageUrl($page - 1) ?>" class="page-link" title="Previous Page">
                    <i class="icon fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="page-link disabled">
                    <i class="icon fa-chevron-left"></i>
                </span>
            <?php endif; ?>

            <!-- Page Numbers Logic -->
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php
                // Logic: Show if it's First Page, Last Page, or within the "Middle Window"
                if ($i == 1 || $i == $total_pages || ($i >= $start_loop && $i <= $end_loop)): 
                ?>
                    <?php if ($i == $page): ?>
                        <span class="page-link active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= getPageUrl($i) ?>" class="page-link"><?= $i ?></a>
                    <?php endif; ?>
                
                <?php else: ?>
                    <!-- If we are skipping pages, show ellipsis '...' BUT only once per gap -->
                    <?php 
                    // Use exact conditions to ensure we only print "..." once for the left gap and once for the right gap
                    // Left Gap: if we are at the position right before the start_loop
                    // Right Gap: if we are at the position right after the end_loop
                    if ($i == $start_loop - 1 || $i == $end_loop + 1): 
                    ?>
                        <span class="page-link disabled">...</span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endfor; ?>

            <!-- Next Button -->
            <?php if ($page < $total_pages): ?>
                <a href="<?= getPageUrl($page + 1) ?>" class="page-link" title="Next Page">
                    <i class="icon fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="page-link disabled">
                    <i class="icon fa-chevron-right"></i>
                </span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script src="../js/product.js"></script>

<?php include 'admin_footer.php'; ?>