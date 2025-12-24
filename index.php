<?php 
include 'includes/header.php'; 

// Ensure database connection is available (using base.php or includes/db.php)
// $pdo is usually set in base.php/includes/header.php
if (!isset($pdo) && isset($_db)) {
    $pdo = $_db;
}

// 1. Fetch Categories for the Dropdown
$cat_stmt = $pdo->prepare("SELECT * FROM categories ORDER BY name ASC");
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll();

// 2. Helper function for Stars
function displayStars($rating) {
    $rating = round($rating * 2) / 2;
    $output = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $output .= '<i class="fas fa-star text-warning"></i>';
        } elseif ($i - 0.5 <= $rating) {
            $output .= '<i class="fas fa-star-half-alt text-warning"></i>';
        } else {
            $output .= '<i class="far fa-star text-muted" style="color: #ccc !important;"></i>';
        }
    }
    return $output;
}
?>

<link rel="stylesheet" href="css/index.css">

<div class="container mt-4 mb-5">
    
    <div class="hero-section">
        <h1>Welcome to ToyShop!</h1>
        <p>Discover the perfect toys to spark joy and imagination.</p>
        <a href="#products" class="btn-hero">Explore Collection</a>
    </div>

    <div class="filter-bar" id="products">
        <form action="index.php" method="GET" class="filter-form">
            <div class="filter-group">
                <input type="text" 
                       name="search" 
                       class="filter-search" 
                       placeholder="Search for toys..." 
                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            </div>

            <div class="filter-group">
                <select name="category" class="filter-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['category_id']; ?>" 
                            <?php echo (isset($_GET['category']) && $_GET['category'] == $cat['category_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-filter">Search</button>
            <?php if(isset($_GET['search']) || isset($_GET['category'])): ?>
                <a href="index.php" class="btn-reset">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="product-grid">
        <?php
        // Prepare Query
        $search = isset($_GET['search']) ? "%" . $_GET['search'] . "%" : "%";
        $cat_id = isset($_GET['category']) && $_GET['category'] !== '' ? $_GET['category'] : null;

        $sql = "SELECT p.*, pm.file_path,
                (SELECT AVG(rating) FROM reviews r WHERE r.product_id = p.product_id) as avg_rating,
                (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.product_id) as review_count
                FROM products p 
                LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1";
        
        if ($cat_id) {
            $sql .= " JOIN product_categories pc ON p.product_id = pc.product_id ";
        }

        $sql .= " WHERE p.name LIKE ? AND p.stock_quantity > 0 AND p.status = 'Active'";
        
        $params = [$search];

        if ($cat_id) {
            $sql .= " AND pc.category_id = ?";
            $params[] = $cat_id;
        }

        $sql .= " GROUP BY p.product_id ORDER BY p.product_id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        if (count($products) > 0) {
            foreach ($products as $product) {
                $imgUrl = !empty($product['file_path']) ? $product['file_path'] : "images/products/no_image.jpg";
                $rating = $product['avg_rating'] ?? 0;
                $count = $product['review_count'];
                ?>

                <a href="member/product_detail.php?id=<?php echo $product['product_id']; ?>" class="product-card-link">
                    <div class="product-card">

                        <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">

                        <div class="card-body">
                            <h3 title="<?php echo htmlspecialchars($product['name']); ?>">
                                <?php echo htmlspecialchars($product['name']); ?>
                            </h3>

                            <div class="rating-container">
                                <?php 
                                // Star Logic with 'icon' class added
                                $r = round($rating * 2) / 2;
                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= $r) {
                                        // Full Star
                                        echo '<i class="icon fas fa-star star-active"></i>';
                                    } elseif ($i - 0.5 <= $r) {
                                        // Half Star (Requires the new .fa-star-half-alt in icons.css)
                                        echo '<i class="icon fas fa-star-half-alt star-active"></i>';
                                    } else {
                                        // Empty Star (Gray Full Star)
                                        echo '<i class="icon fas fa-star star-inactive"></i>'; 
                                    }
                                }
                                ?>
                                <span class="text-muted"> (<?php echo $count; ?>)</span>
                            </div>

                            <div class="price">RM <?php echo number_format($product['price'], 2); ?></div>
                        </div>
                    </div>
                </a>

                <?php
            }
        } else {
            echo "<div style='grid-column: 1 / -1;'><div class='alert alert-warning text-center'>No toys found.</div></div>";
        }
        ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>