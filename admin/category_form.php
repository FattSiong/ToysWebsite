<?php
session_start();
require_once '../includes/db.php';

// Initialize variables
$category_id = null;
$name = '';
$assigned_product_ids = [];
$is_edit = false;
$page_title = 'Add Category';
$errors = [];

// 1. Fetch Categories for the Dropdown
try {
    $cat_stmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// 2. Fetch ALL Products with details AND their Categories
try {
    // We use GROUP_CONCAT to get a comma-separated list of category IDs for each product
    $sql = "SELECT p.*, pm.file_path, GROUP_CONCAT(pc.category_id) as category_ids
            FROM products p 
            LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1
            LEFT JOIN product_categories pc ON p.product_id = pc.product_id
            GROUP BY p.product_id
            ORDER BY p.name ASC";
    $prod_stmt = $pdo->query($sql);
    $all_products = $prod_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_products = [];
}

// Check if we are in Edit Mode
if (isset($_GET['id'])) {
    $category_id = $_GET['id'];
    $is_edit = true;
    $page_title = 'Edit Category';

    $stmt = $pdo->prepare("SELECT * FROM categories WHERE category_id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($category) {
        $name = $category['name'];
        $assigned_stmt = $pdo->prepare("SELECT product_id FROM product_categories WHERE category_id = ?");
        $assigned_stmt->execute([$category_id]);
        $assigned_product_ids = $assigned_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $selected_products = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];

    if (empty($name)) {
        $errors[] = "Category Name is required.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($is_edit) {
                $stmt = $pdo->prepare("UPDATE categories SET name = :name WHERE category_id = :id");
                $stmt->execute([':name' => $name, ':id' => $category_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (:name)");
                $stmt->execute([':name' => $name]);
                $category_id = $pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM product_categories WHERE category_id = ?")->execute([$category_id]);

            if (!empty($selected_products)) {
                $insert_stmt = $pdo->prepare("INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)");
                foreach ($selected_products as $prod_id) {
                    $insert_stmt->execute([$prod_id, $category_id]);
                }
            }

            $pdo->commit();
            header('Location: category_list.php?success=1');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database Error: " . $e->getMessage();
        }
    }
}

include 'admin_header.php';
?>

<link rel="stylesheet" href="../css/category_form.css">

<div class="form-page-header">
    <a href="category_list.php" class="back-link"><i class="icon fa-arrow-left"></i> Back</a>
    <h1 class="page-title"><?= $page_title ?></h1>
</div>

<?php if (!empty($errors)): ?>
    <div class="error-box">
        <?php foreach ($errors as $error): ?>
            <p class="error-text"><?= htmlspecialchars($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form action="" method="POST" id="mainForm" novalidate class="category-form-container">
    <div id="hidden-inputs-container"></div>

    <div class="card">
        <div class="card-header">Category Information</div>
        <div class="card-body">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" class="form-input" value="<?= htmlspecialchars($name) ?>" required>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header card-header-flex">
            <span>Category Products</span>
            <button type="button" class="btn-add-products" id="headerAddBtn" style="display: none;">+ Add products</button>
        </div>
        <div class="card-body">
            <div id="empty-state" class="empty-state">
                <h4>Add products to the category</h4>
                <p>Products added will be displayed here.</p>
                <button type="button" class="btn-choose" id="emptyAddBtn">Choose products</button>
            </div>

            <table class="products-table" id="selected-table" style="display: none;">
                <thead>
                    <tr>
                        <th style="width: 50px;">No.</th>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th style="width: 50px;"></th>
                    </tr>
                </thead>
                <tbody id="selected-tbody">
                    </tbody>
            </table>
        </div>
    </div>

    <div class="form-actions">
        <a href="category_list.php" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-primary">Save Category</button>
    </div>
</form>

<div class="modal-overlay" id="productModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Select products</h3>
            <button type="button" class="btn-close-modal" id="closeModalBtn">&times;</button>
        </div>
        
        <div class="modal-filters filter-bar">
            <div class="filter-group search-group">
                <button type="button" class="search-submit-btn">
                    <i class="icon fa-search"></i>
                </button>
                <input type="text" id="modalSearch" class="form-input search-input-field" placeholder="Search product...">
            </div>

            <div class="filter-group">
                <select id="modalCategory" class="form-select">
                    <option value="">Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="icon fa-filter icon-right"></i>
            </div>

            <div class="filter-group price-toggle-group">
                <button type="button" class="price-trigger-btn" id="modalPriceBtn">
                    <span>Price Range</span>
                    <i class="icon fa-filter icon-right" style="font-size: 10px; margin-left: auto;"></i>
                </button>
                <div class="direct-inputs-wrapper" id="modalPriceInputs" style="display: none;">
                    <button type="button" class="btn-close-price" id="modalClosePriceBtn" title="Clear Price">&times;</button>
                    <input type="number" id="modalMinPrice" class="direct-input" placeholder="Min" min="0" step="0.01">
                    <span class="direct-separator">-</span>
                    <input type="number" id="modalMaxPrice" class="direct-input" placeholder="Max" min="0" step="0.01">
                </div>
            </div>
        </div>

        <div class="modal-body">
            <table class="modal-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="selectAllModal"></th>
                        <th style="width: 50px;">No.</th>
                        <th>Products</th>
                        <th>Price</th>
                        <th>Inventory</th>
                    </tr>
                </thead>
                <tbody id="modal-tbody">
                    </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" id="cancelModalBtn">Cancel</button>
            <button type="button" class="btn-primary" id="addSelectedBtn">Add</button>
        </div>
    </div>
</div>

<script>
    const allProducts = <?= json_encode($all_products) ?>;
    let currentSelection = <?= json_encode(array_map('strval', $assigned_product_ids)) ?>;
</script>

<script src="../js/category.js"></script>

<?php include 'admin_footer.php'; ?>