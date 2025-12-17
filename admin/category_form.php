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

// Fetch ALL Products for the selection list
try {
    $prod_stmt = $pdo->query("SELECT product_id, name FROM products ORDER BY name ASC");
    $all_products = $prod_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_products = [];
}

// Check if we are in Edit Mode
if (isset($_GET['id'])) {
    $category_id = $_GET['id'];
    $is_edit = true;
    $page_title = 'Edit Category';

    // Fetch Category Data
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE category_id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($category) {
        $name = $category['name'];

        // --- UPDATED: Fetch currently assigned products using the BRIDGE TABLE ---
        $assigned_stmt = $pdo->prepare("SELECT product_id FROM product_categories WHERE category_id = ?");
        $assigned_stmt->execute([$category_id]);
        $assigned_product_ids = $assigned_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Get Data
    $name = trim($_POST['name']);
    $selected_products = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];

    // Validation
    if (empty($name)) {
        $errors[] = "Category Name is required.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($is_edit) {
                // UPDATE Category Name
                $sql = "UPDATE categories SET name = :name WHERE category_id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':name' => $name, ':id' => $category_id]);
            } else {
                // INSERT Category Name
                $sql = "INSERT INTO categories (name) VALUES (:name)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':name' => $name]);
                $category_id = $pdo->lastInsertId();
            }

            // --- UPDATED: Handle Many-to-Many Relationship ---
            
            // 1. Remove ALL existing links for this category in the bridge table
            // This is the cleanest way to handle updates: clear all, then re-add selected.
            $delete_sql = "DELETE FROM product_categories WHERE category_id = ?";
            $pdo->prepare($delete_sql)->execute([$category_id]);

            // 2. Insert the new selections
            if (!empty($selected_products)) {
                $insert_sql = "INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)";
                $insert_stmt = $pdo->prepare($insert_sql);

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
    <a href="category_list.php" class="back-link">
        <i class="icon fa-arrow-left"></i> Back
    </a>
    <h1 class="page-title"><?= $page_title ?></h1>
</div>

<?php if (!empty($errors)): ?>
    <div class="error-box">
        <?php foreach ($errors as $error): ?>
            <p class="error-text"><?= htmlspecialchars($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form action="" method="POST" class="category-form-container" novalidate>

    <div class="card">
        <div class="card-header">Category Information</div>
        <div class="card-body">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" class="form-input" placeholder="e.g., Summer Collection" value="<?= htmlspecialchars($name) ?>" required>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Category Products</div>
        <div class="card-body">
            <div class="product-list-container">
                <?php if (empty($all_products)): ?>
                    <p class="empty-message">No products found.</p>
                <?php else: ?>
                    <?php foreach ($all_products as $prod): ?>
                        <label class="product-item-label">
                            <input type="checkbox" 
                                   class="product-checkbox" 
                                   name="product_ids[]" 
                                   value="<?= $prod['product_id'] ?>" 
                                   <?= in_array($prod['product_id'], $assigned_product_ids) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($prod['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p class="helper-text">
                Select products to include in this category.
            </p>
        </div>
    </div>

    <div class="form-actions">
        <a href="category_list.php" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-primary">Save Category</button>
    </div>

</form>

<?php include 'admin_footer.php'; ?>