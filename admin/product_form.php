<?php
session_start();
require_once '../includes/db.php';
require_once 'uploadMedia.php';

// Initialize variables
$product_id = null;
$name = '';
$description = '';
$price = '';
$stock_quantity = '';
$status = 'Inactive';
$assigned_category_ids = []; // CHANGED: Now an array for multiple IDs
$media_files = [];
$is_edit = false;
$page_title = 'Add Product';

// Fetch Categories for Checkbox List
try {
    $cat_stmt = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

// Check if we are in Edit Mode
if (isset($_GET['id'])) {
    $product_id = $_GET['id'];
    $is_edit = true;
    $page_title = 'Edit Product';

    // Fetch Product Data
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        $name = $product['name'];
        $description = $product['description'];
        $price = $product['price'];
        $stock_quantity = $product['stock_quantity'];
        $status = $product['status'];

        // --- UPDATED: Fetch Assigned Categories from Bridge Table ---
        $cat_assign_stmt = $pdo->prepare("SELECT category_id FROM product_categories WHERE product_id = ?");
        $cat_assign_stmt->execute([$product_id]);
        $assigned_category_ids = $cat_assign_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch Existing Media
        $media_stmt = $pdo->prepare("SELECT * FROM product_media WHERE product_id = ? ORDER BY is_primary DESC, media_id ASC");
        $media_stmt->execute([$product_id]);
        $media_files = $media_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Get Basic Data
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = !empty($_POST['price']) ? $_POST['price'] : 0.00;
    $stock_quantity = !empty($_POST['stock_quantity']) ? $_POST['stock_quantity'] : 0;
    $status = isset($_POST['status']) ? 'Active' : 'Inactive';

    // Get Selected Categories (Array)
    $selected_categories = isset($_POST['category_ids']) ? $_POST['category_ids'] : [];

    try {
        $pdo->beginTransaction();

        if ($is_edit) {
            // UPDATE Product (removed category_id column from here)
            $sql = "UPDATE products SET 
                    name = :name, 
                    description = :description, 
                    price = :price, 
                    stock_quantity = :stock_quantity, 
                    status = :status 
                    WHERE product_id = :product_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':price' => $price,
                ':stock_quantity' => $stock_quantity,
                ':status' => $status,
                ':product_id' => $product_id
            ]);
        } else {
            // INSERT Product (removed category_id column from here)
            $sql = "INSERT INTO products (name, description, price, stock_quantity, status) 
                    VALUES (:name, :description, :price, :stock_quantity, :status)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':price' => $price,
                ':stock_quantity' => $stock_quantity,
                ':status' => $status
            ]);
            $product_id = $pdo->lastInsertId();
        }

        // --- UPDATED: Handle Category Links ---
        // 1. Clear existing links
        $del_cat_sql = "DELETE FROM product_categories WHERE product_id = ?";
        $pdo->prepare($del_cat_sql)->execute([$product_id]);

        // 2. Insert new links
        if (!empty($selected_categories)) {
            $ins_cat_sql = "INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)";
            $ins_cat_stmt = $pdo->prepare($ins_cat_sql);
            foreach ($selected_categories as $cat_id) {
                $ins_cat_stmt->execute([$product_id, $cat_id]);
            }
        }

        // --- (Media Handling Code Remains the Same) ---
        // 2. Handle Deletion of Existing Media
        if (!empty($_POST['deleted_media_ids'])) {
            $delete_ids_str = $_POST['deleted_media_ids'];
            $delete_ids = explode(',', $delete_ids_str);
            $delete_ids = array_filter(array_map('intval', $delete_ids));

            if (!empty($delete_ids)) {
                $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));

                $sql_get = "SELECT file_path FROM product_media WHERE media_id IN ($placeholders) AND product_id = ?";
                $params_get = $delete_ids;
                $params_get[] = $product_id;
                $stmt_get = $pdo->prepare($sql_get);
                $stmt_get->execute($params_get);
                $files_to_delete = $stmt_get->fetchAll(PDO::FETCH_COLUMN);

                foreach ($files_to_delete as $db_path) {
                    $physical_path = '../' . $db_path;
                    if (file_exists($physical_path)) {
                        unlink($physical_path);
                    }
                }

                $sql_del = "DELETE FROM product_media WHERE media_id IN ($placeholders) AND product_id = ?";
                $params_del = $delete_ids;
                $params_del[] = $product_id;
                $pdo->prepare($sql_del)->execute($params_del);
            }
        }

        // 3. Handle File Uploads
        if (isset($_FILES['product_images'])) {
            uploadProductMedia($pdo, $product_id, $_FILES['product_images']);
        }

        // 4. Handle Primary Media Update
        if (isset($_POST['primary_media_id'])) {
            $primary_id = $_POST['primary_media_id'];
            $sql_reset = "UPDATE product_media SET is_primary = 0 WHERE product_id = ?";
            $pdo->prepare($sql_reset)->execute([$product_id]);
            $sql_set = "UPDATE product_media SET is_primary = 1 WHERE media_id = ? AND product_id = ?";
            $pdo->prepare($sql_set)->execute([$primary_id, $product_id]);
        }

        $pdo->commit();
        header('Location: product_list.php?success=1');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "Error: " . $e->getMessage();
        exit;
    }
}

include 'admin_header.php';
?>

<link rel="stylesheet" href="../css/product_form.css">

<style>
    .category-checkbox-list {
        max-height: 150px;
        overflow-y: auto;
        border: 1px solid #d2d2d7;
        border-radius: 6px;
        padding: 10px;
        background: #fafafa;
    }

    .cat-item {
        display: block;
        margin-bottom: 5px;
        cursor: pointer;
        font-size: 14px;
        color: #333;
    }

    .cat-item input {
        margin-right: 8px;
    }
</style>

<div class="form-page-header">
    <a href="product_list.php" class="back-link">
        <i class="icon fa-arrow-left"></i> Back
    </a>
    <h1 class="page-title"><?= $page_title ?></h1>
</div>

<form action="" method="POST" enctype="multipart/form-data" class="product-form">

    <input type="hidden" name="deleted_media_ids" id="deleted_media_ids" value="">

    <div class="form-left">
        <div class="card">
            <div class="card-header">Product Information</div>
            <div class="card-body">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" class="form-input" placeholder="e.g., Short-sleeved T-shirt" value="<?= htmlspecialchars($name) ?>" required>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-textarea" placeholder="Tell us something about this product..."><?= htmlspecialchars($description) ?></textarea>
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
                        <div style="font-size: 0.8em; color: #999; margin-top: 5px;">(or drag and drop here)</div>
                    </div>
                    <input type="file" id="file-upload" name="product_images[]" multiple style="display: none;">
                </label>

                <div id="preview-container" class="media-preview-grid"></div>

                <?php if (!empty($media_files)): ?>
                    <label class="existing-label">Existing Media (Select a Primary Image)</label>
                    <div class="media-preview-grid">
                        <?php foreach ($media_files as $media): ?>
                            <div class="preview-item">
                                <button type="button" class="preview-remove-btn" onclick="markForDeletion(this, <?= $media['media_id'] ?>)">×</button>
                                <?php
                                // --- CHANGE STARTS HERE --- 
                                // Only show the "Primary" star selection if it is an IMAGE
                                if ($media['media_type'] === 'image'):
                                ?>
                                    <label class="primary-select-overlay" title="Set as Main Photo">
                                        <input type="radio" name="primary_media_id" value="<?= $media['media_id'] ?>" <?= $media['is_primary'] ? 'checked' : '' ?>>
                                        <span class="star-icon"><i class="icon fa-star"></i></span>
                                    </label>
                                <?php endif;
                                // --- CHANGE ENDS HERE --- 
                                ?>

                                <?php
                                $path_from_db = $media['file_path'];
                                $display_path = (strpos($path_from_db, '../') === 0) ? $path_from_db : '../' . ltrim($path_from_db, '/');
                                ?>
                                <?php if ($media['media_type'] === 'video'): ?>
                                    <video src="<?= htmlspecialchars($display_path) ?>" controls></video>
                                    <span class="preview-type-badge">VIDEO</span>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($display_path) ?>" alt="Product Media">
                                    <?php if ($media['is_primary']): ?>
                                        <span class="preview-type-badge" style="background: #0071e3;">PRIMARY</span>
                                    <?php endif; ?>
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
                        <?php if (empty($categories)): ?>
                            <div style="color: #999; font-size: 13px;">No categories available.</div>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <label class="cat-item">
                                    <input type="checkbox"
                                        name="category_ids[]"
                                        value="<?= $cat['category_id'] ?>"
                                        <?= in_array($cat['category_id'], $assigned_category_ids) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="helper-text" style="margin-top:5px;">Select all that apply.</div>
                </div>

                <div class="form-group">
                    <label for="stock">Inventory Stock</label>
                    <input type="number" id="stock" name="stock_quantity" class="form-input" value="<?= htmlspecialchars($stock_quantity) ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Pricing</div>
            <div class="card-body">
                <div class="form-group">
                    <label for="price">Price ($)</label>
                    <input type="number" step="0.01" id="price" name="price" class="form-input" value="<?= htmlspecialchars($price) ?>">
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