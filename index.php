<?php 
include 'includes/header.php'; 
include 'includes/navbar.php'; 

// Helper function to generate star HTML
function displayStars($rating) {
    $rating = round($rating * 2) / 2; // Round to nearest 0.5
    $output = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $output .= '<i class="fas fa-star text-warning"></i>'; // Full Star
        } elseif ($i - 0.5 <= $rating) {
            $output .= '<i class="fas fa-star-half-alt text-warning"></i>'; // Half Star
        } else {
            $output .= '<i class="far fa-star text-muted"></i>'; // Empty Star
        }
    }
    return $output;
}
?>

<!-- Main Content -->
<div class="shop-container">
    <div class="welcome-banner mb-5 text-center p-5 bg-light rounded-3 shadow-sm">
        <h1 class="display-4 fw-bold text-primary">Welcome to ToyShop</h1>
        <p class="lead text-secondary">Find the best toys for your kids!</p>
    </div>

    <div class="row">
        <div class="col-12 mb-3">
            <h2>Our Toys</h2>
        </div>

        <?php
        // SEARCH LOGIC
        $search = isset($_GET['search']) ? "%" . $_GET['search'] . "%" : "%";
        $cat_id = isset($_GET['category']) ? $_GET['category'] : null;

        // SQL QUERY UPDATED FOR NEW DB STRUCTURE
        $sql = "SELECT p.*, pm.file_path,
                (SELECT AVG(rating) FROM reviews r WHERE r.product_id = p.product_id) as avg_rating,
                (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.product_id) as review_count
                FROM products p 
                LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1
                ";
        
        // If a category is selected, we must JOIN the new 'product_categories' table
        if ($cat_id) {
            $sql .= " JOIN product_categories pc ON p.product_id = pc.product_id ";
        }

        // Fix: Status is now 'Active' (Capital A)
        $sql .= " WHERE p.name LIKE ? AND p.stock_quantity > 0 AND p.status = 'Active'";
        
        $params = [$search];

        if ($cat_id) {
            $sql .= " AND pc.category_id = ?";
            $params[] = $cat_id;
        }

        // Prevent duplicates if product has multiple categories
        $sql .= " GROUP BY p.product_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        if (count($products) > 0) {
            foreach ($products as $product) {
                $imgUrl = !empty($product['file_path']) ? $product['file_path'] : "https://via.placeholder.com/300";
                $rating = $product['avg_rating'] ?? 0;
                $count = $product['review_count'];
                ?>
                <div class="col-md-3 mb-4">
                    <div class="card h-100 shadow-sm border-0 product-card">
                        <div style="height: 200px; padding: 10px; display: flex; align-items: center; justify-content: center;">
                            <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                        </div>
                        <div class="card-body text-center">
                            <h5 class="card-title text-truncate"><?php echo htmlspecialchars($product['name']); ?></h5>
                            <div class="mb-2">
                                <small>
                                    <?php echo displayStars($rating); ?>
                                    <span class="text-muted ms-1">(<?php echo $count; ?>)</span>
                                </small>
                            </div>
                            <p class="card-text text-primary fw-bold fs-5">RM <?php echo number_format($product['price'], 2); ?></p>
                            <a href="product_detail.php?id=<?php echo $product['product_id']; ?>" class="btn btn-outline-primary w-100 rounded-pill">View Details</a>
                        </div>
                    </div>
                </div>
                <?php
            }
        } else {
            echo "<div class='col-12'><div class='alert alert-warning text-center'>No products found matching your search.</div></div>";
        }
        ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>