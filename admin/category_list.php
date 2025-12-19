<?php
// Start session and include database connection
session_start();
require_once '../includes/db.php';

// --- HANDLE FILTERING & SEARCH ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// UPDATED QUERY for Many-to-Many Relationship
// We join 'product_categories' (pc) instead of 'products' directly.
$sql = "SELECT c.*, COUNT(pc.product_id) as product_count 
        FROM categories c 
        LEFT JOIN product_categories pc ON c.category_id = pc.category_id 
        WHERE 1=1";
$params = [];

if (!empty($search_query)) {
    $sql .= " AND c.name LIKE :search";
    $params[':search'] = "%$search_query%";
}

// Group by category to make the COUNT work, then order alphabetically
$sql .= " GROUP BY c.category_id ORDER BY c.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'admin_header.php';
?>

<div class="page-header">
    <h1 class="page-title">Categories</h1>
    <a href="category_form.php" class="btn-primary">Add category</a>
</div>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form">
        <div class="filter-group search-group">
            <button type="button" class="search-submit-btn" onclick="this.form.submit()" title="Click to Search">
                <i class="icon fa-search"></i>
            </button>
            <input type="text" name="search" class="form-input search-input-field"
                   placeholder="Search Category"
                   value="<?= htmlspecialchars($search_query) ?>">
        </div>
    </form>
</div>

<table class="data-table">
    <thead>
        <tr>
            <!-- 1. Checkbox Column -->
            <th style="width: 40px;"><input type="checkbox"></th>

            <!-- 2. Category Name Column -->
            <th>Category</th>

            <!-- 3. Product Quantity Column -->
            <th>Product quantity</th>

            <!-- 4. Action Column -->
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($categories) > 0): ?>
            <?php foreach ($categories as $row): ?>
                <tr>
                    <td><input type="checkbox" name="category_ids[]" value="<?= $row['category_id'] ?>"></td>

                    <td>
                        <span style="font-weight: 500; color: #333;"><?= htmlspecialchars($row['name']) ?></span>
                        <?php if (!empty($row['description'])): ?>
                            <div style="font-size: 0.85em; color: #888; margin-top: 2px;">
                                <?= htmlspecialchars(substr($row['description'], 0, 50)) . (strlen($row['description']) > 50 ? '...' : '') ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td><?= $row['product_count'] ?></td>

                    <td>
                        <a href="category_form.php?id=<?= $row['category_id'] ?>" class="action-btn edit-btn" title="Edit">
                            <i class="icon fa-edit"></i>
                        </a>

                        <a href="category_delete.php?id=<?= $row['category_id'] ?>"
                            class="action-btn delete-btn"
                            title="Delete"
                            onclick="return confirm('Are you sure? Deleting a category might affect products assigned to it.');">
                            <i class="icon fa-trash"></i>
                        </a>
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

<?php include 'admin_footer.php'; ?>