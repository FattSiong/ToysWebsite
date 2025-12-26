<?php
session_start();
require_once '../includes/db.php';
require_once 'validation.php';
require_once 'uploadMedia.php';

// ==========================================
// 1. INITIALIZATION
// ==========================================
$product_id             = null;
$name                   = '';
$description            = '';
$price                  = '';
$stock_quantity         = '';
$status                 = 'Inactive';
$assigned_category_ids  = [];
$media_files            = [];
$is_edit                = false;
$page_title             = 'Add Product';
$errors                 = [];

// Fetch Categories
try {
    $cat_stmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
    error_log("Error fetching categories: " . $e->getMessage());
}

// ==========================================
// 2. CHECK EDIT MODE
// ==========================================
if (isset($_GET['id'])) {
    $product_id = (int)$_GET['id'];
    $is_edit = true;
    $page_title = 'Edit Product';

    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        $name           = $product['name'];
        $description    = $product['description'];
        $price          = $product['price'];
        $stock_quantity = $product['stock_quantity'];
        $status         = $product['status'];

        $cat_stmt = $pdo->prepare("SELECT category_id FROM product_categories WHERE product_id = ?");
        $cat_stmt->execute([$product_id]);
        $assigned_category_ids = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

        $media_stmt = $pdo->prepare("SELECT * FROM product_media WHERE product_id = ? ORDER BY is_primary DESC, media_id ASC");
        $media_stmt->execute([$product_id]);
        $media_files = $media_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ==========================================
// 3. HANDLE FORM SUBMISSION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize
    $name           = trim($_POST['name']);
    $description    = trim($_POST['description']);
    $status         = isset($_POST['status']) ? 'Active' : 'Inactive';
    $selected_cats  = isset($_POST['category_ids']) ? $_POST['category_ids'] : [];

    // Get Raw Inputs (No defaults applied)
    $raw_price = $_POST['price'];
    $raw_stock = $_POST['stock_quantity'];

    // 3. Run Validation
    $val_name = validateName($name, "Product Name");
    if ($val_name) $errors[] = $val_name;

    $val_price = validatePrice($raw_price);
    if ($val_price) $errors[] = $val_price;

    $val_stock = validateQuantity($raw_stock);
    if ($val_stock) $errors[] = $val_stock;

    // Proceed if no errors
    if (empty($errors)) {
        $price = (float)$raw_price;
        $stock_quantity = (int)$raw_stock;

        try {
            $pdo->beginTransaction();

            if ($is_edit) {
                $sql = "UPDATE products SET name=?, description=?, price=?, stock_quantity=?, status=? WHERE product_id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$name, $description, $price, $stock_quantity, $status, $product_id]);
            } else {
                $sql = "INSERT INTO products (name, description, price, stock_quantity, status) VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$name, $description, $price, $stock_quantity, $status]);
                $product_id = $pdo->lastInsertId();
            }

            // Categories
            $pdo->prepare("DELETE FROM product_categories WHERE product_id = ?")->execute([$product_id]);
            if (!empty($selected_cats)) {
                $ins_cat = $pdo->prepare("INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)");
                foreach ($selected_cats as $cat_id) $ins_cat->execute([$product_id, $cat_id]);
            }

            // Media Deletion
            if (!empty($_POST['deleted_media_ids'])) {
                $del_ids = array_filter(array_map('intval', explode(',', $_POST['deleted_media_ids'])));
                if (!empty($del_ids)) {
                    $placeholders = implode(',', array_fill(0, count($del_ids), '?'));
                    $stmt_get = $pdo->prepare("SELECT file_path FROM product_media WHERE media_id IN ($placeholders) AND product_id = ?");
                    $params = $del_ids;
                    $params[] = $product_id;
                    $stmt_get->execute($params);
                    foreach ($stmt_get->fetchAll(PDO::FETCH_COLUMN) as $db_path) {
                        if (file_exists('../' . $db_path)) unlink('../' . $db_path);
                    }
                    $pdo->prepare("DELETE FROM product_media WHERE media_id IN ($placeholders) AND product_id = ?")->execute($params);
                }
            }

            // New Uploads
            if (isset($_FILES['product_images'])) {
                uploadProductMedia($pdo, $product_id, $_FILES['product_images']);
            }

            // Primary Image
            if (isset($_POST['primary_media_id'])) {
                $pdo->prepare("UPDATE product_media SET is_primary = 0 WHERE product_id = ?")->execute([$product_id]);
                $pdo->prepare("UPDATE product_media SET is_primary = 1 WHERE media_id = ? AND product_id = ?")->execute([$_POST['primary_media_id'], $product_id]);
            }

            $pdo->commit();
            header('Location: product_list.php?success=1');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database Error: " . $e->getMessage();
        }
    } else {
        // Keep user input if validation failed
        $price = $raw_price;
        $stock_quantity = $raw_stock;
    }
}

include '../includes/header.php';
?>

<link rel="stylesheet" href="../css/product_form.css">

<div class="form-page-header">
    <a href="product_list.php" class="back-link"><i class="icon fa-arrow-left"></i> Back</a>
    <h1 class="page-title"><?= htmlspecialchars($page_title) ?></h1>
</div>

<?php if (!empty($errors)): ?>
    <div class="error-banner">
        <?php foreach ($errors as $err): ?>
            <p><?= htmlspecialchars($err) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form action="" method="POST" enctype="multipart/form-data" class="product-form" novalidate>
    <input type="hidden" name="deleted_media_ids" id="deleted_media_ids" value="">

    <div class="form-left">
        <div class="card">
            <div class="card-header">Product Information</div>
            <div class="card-body">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" class="form-input"
                        placeholder="e.g., Remote Control Race Car"
                        value="<?= htmlspecialchars($name) ?>" required maxlength="50">
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-textarea"
                        placeholder="Tell us something about this product..."><?= htmlspecialchars($description) ?></textarea>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Media</div>
            <div class="card-body">
                <label class="media-upload-box" id="drop-zone" for="file-upload">
                    <div class="upload-content">
                        <div class="plus-sign">+</div>
                        <div class="upload-prompt">Add images or videos</div>
                    </div>
                    <input type="file" id="file-upload" name="product_images[]" multiple style="display: none;">
                </label>
                <div id="preview-container" class="media-preview-grid"></div>
                <?php if (!empty($media_files)): ?>
                    <label class="existing-label">Existing Media (Select a Primary Image)</label>
                    <div class="media-preview-grid">
                        <?php foreach ($media_files as $media):
                            $display_path = (strpos($media['file_path'], '../') === 0) ? $media['file_path'] : '../' . ltrim($media['file_path'], '/');
                        ?>
                            <div class="preview-item">
                                <button type="button" class="preview-remove-btn" onclick="markForDeletion(this, <?= $media['media_id'] ?>)">×</button>
                                <?php if ($media['media_type'] === 'image'): ?>
                                    <label class="primary-select-overlay">
                                        <input type="radio" name="primary_media_id" value="<?= $media['media_id'] ?>" <?= $media['is_primary'] ? 'checked' : '' ?>>
                                        <span class="star-icon"><i class="icon fa-star"></i></span>
                                    </label>
                                    <img src="<?= htmlspecialchars($display_path) ?>" alt="Product Media">
                                <?php else: ?>
                                    <video src="<?= htmlspecialchars($display_path) ?>" controls></video>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="form-right">
        <div class="card">
            <div class="card-header">Product Status</div>
            <div class="card-body">
                <div class="status-control">
                    <label class="status-label">Activate Product</label>
                    <label class="status-toggle">
                        <input type="checkbox" name="status" <?= $status == 'Active' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Organization</div>
            <div class="card-body">
                <div class="form-group">
                    <label>Categories</label>
                    <div class="category-checkbox-list">
                        <?php foreach ($categories as $cat): ?>
                            <label class="cat-item">
                                <input type="checkbox" name="category_ids[]" value="<?= $cat['category_id'] ?>"
                                    <?= in_array($cat['category_id'], $assigned_category_ids) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="stock">Inventory Stock</label>
                    <input type="number" id="stock" name="stock_quantity" class="form-input"
                        value="<?= htmlspecialchars($stock_quantity) ?>" min="0" required>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Pricing</div>
            <div class="card-body">
                <div class="form-group">
                    <label for="price">Price ($)</label>
                    <input type="number" step="0.01" id="price" name="price" class="form-input"
                        value="<?= htmlspecialchars($price) ?>" min="0" required>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a href="product_list.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-primary">Save Product</button>
        </div>
    </div>
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../js/uploadMedia.js"></script>
<script src="../js/product.js"></script>
<?php include 'admin_footer.php'; ?>