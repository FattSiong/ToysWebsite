<?php
// Start session and include database connection
session_start();
require_once '../includes/db.php';

// --- HANDLE FILTERING & SEARCH ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_option = $_GET['sort'] ?? '';

// Pagination Variables
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Show 10 categories per page
$offset = ($page - 1) * $limit;

// Base SQL structure
$base_sql = " FROM categories c 
              LEFT JOIN product_categories pc ON c.category_id = pc.category_id 
              WHERE 1=1";
$params = [];

if (!empty($search_query)) {
    $sql .= " AND c.name LIKE :search";
    $params[':search'] = "%$search_query%";
}

// --- COUNT QUERY (For Pagination) ---
// We use COUNT(DISTINCT c.category_id) because of the LEFT JOIN with product_categories
$count_sql = "SELECT COUNT(DISTINCT c.category_id)" . $base_sql;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// --- MAIN QUERY ---
// UPDATED QUERY for Many-to-Many Relationship
$sql = "SELECT c.*, COUNT(pc.product_id) as product_count" . $base_sql;

// Group by category to make the COUNT work
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

// Add LIMIT and OFFSET
$sql .= " LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);

// Bind parameters dynamically
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
// Bind Limit and Offset as Integers
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
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

<table class="data-table">
    <thead>
        <?php
        // Helper function to generate sort links maintaining current filters
        function getSortParams($colName, $currentSort) {
            $params = $_GET; // Copy current URL parameters (search, etc.)
            
            // Default Icon
            $icon = 'fa-sort';
            $activeClass = '';
            
            // Logic for Category Name
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
            
            // Logic for Product Quantity (Count)
            if ($colName === 'count') {
                if ($currentSort === 'count_low') {
                    $params['sort'] = 'count_high'; // Toggle to High-Low
                    $icon = 'fa-sort-asc'; // Visual: Low to High arrow
                    $activeClass = 'active';
                } elseif ($currentSort === 'count_high') {
                    $params['sort'] = 'count_low'; // Toggle to Low-High
                    $icon = 'fa-sort-desc'; // Visual: High to Low arrow
                    $activeClass = 'active';
                } else {
                    $params['sort'] = 'count_low'; // Default action
                }
            }

            return [
                'url' => '?' . http_build_query($params),
                'icon' => $icon,
                'class' => $activeClass
            ];
        }
        
        $sortName = getSortParams('name', $sort_option);
        $sortCount = getSortParams('count', $sort_option);
        ?>
        <tr>
            <th style="width: 40px;"><input type="checkbox"></th>
            <th>
                <a href="<?= $sortName['url'] ?>" class="sortable-link <?= $sortName['class'] ?>">
                    Category <i class="icon fa <?= $sortName['icon'] ?>"></i>
                </a>
            </th>
            <th>
                <a href="<?= $sortCount['url'] ?>" class="sortable-link <?= $sortCount['class'] ?>">
                    Product quantity <i class="icon fa <?= $sortCount['icon'] ?>"></i>
                </a>
            </th>
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

<!-- PAGINATION CONTROLS -->
<?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">
            Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> categories
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
                    <!-- Ellipsis Logic -->
                    <?php 
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

<?php include 'admin_footer.php'; ?>