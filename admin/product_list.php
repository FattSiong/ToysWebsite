<?php
// Start session and include database connection
session_start();
require_once '../includes/db.php';

// --- 0. HANDLE AJAX STATUS UPDATE (Merged from product_update_status.php) ---
// This handles both Toggling Status and Archiving
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);

        $product_id = isset($input['id']) ? intval($input['id']) : null;
        // Accept 'Active', 'Inactive', or 'Archived'
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
// Updated: 'total' (for All tab) now excludes 'Archived'
$count_sql = "SELECT 
                SUM(CASE WHEN status != 'Archived' THEN 1 ELSE 0 END) as total,
                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive_count,
                SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) as archived_count
              FROM products";
$count_stmt = $pdo->query($count_sql);
$counts = $count_stmt->fetch(PDO::FETCH_ASSOC);

// --- 2. PREPARE FILTERS (Updated Logic) ---

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

// We use DISTINCT because a product might have multiple categories, 
// and we don't want it appearing twice in the list if we join loosely.
$sql = "SELECT DISTINCT p.*, pm.file_path 
        FROM products p 
        LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1";

// NEW: If a Category is selected, we must JOIN the 'product_categories' table
if (!empty($category_filter)) {
    $sql .= " INNER JOIN product_categories pc ON p.product_id = pc.product_id";
}

$sql .= " WHERE 1=1";
$params = [];

// 1. Status Filter
if ($current_tab !== 'All') {
    $sql .= " AND p.status = :status";
    $params[':status'] = $current_tab;
} else {
    $sql .= " AND p.status != 'Archived'";
}

// 2. Search Filter
if (!empty($search_query)) {
    $sql .= " AND (p.name LIKE :search_name OR p.description LIKE :search_desc)";
    $params[':search_name'] = "%$search_query%";
    $params[':search_desc'] = "%$search_query%";
}

// 3. Category Filter (UPDATED)
// We check the joined 'pc' table for the category_id
if (!empty($category_filter)) {
    $sql .= " AND pc.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

// 4. Price Filter
if ($min_price !== '') {
    $sql .= " AND p.price >= :min_price";
    $params[':min_price'] = $min_price;
}
if ($max_price !== '') {
    $sql .= " AND p.price <= :max_price";
    $params[':max_price'] = $max_price;
}

$sql .= " ORDER BY p.product_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
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
            <input type="text" name="search" class="filter-input search-input-field"
                placeholder="Search product..."
                value="<?= htmlspecialchars($search_query) ?>">
        </div>

        <div class="filter-group">
            <select name="category" class="filter-select" onchange="this.form.submit()">
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
    </form>
</div>

<table class="data-table">
    <thead>
        <tr>
            <th style="width: 40px;"><input type="checkbox"></th>
            <th>Product</th>
            <th>Price</th>
            <th>Inventory quantity</th>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script src="../js/product.js"></script>

<?php include 'admin_footer.php'; ?>