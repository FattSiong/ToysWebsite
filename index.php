<?php
// 1. Debugging (Remove these 3 lines when you are done development)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. Include the database and layout
// We use 'require' for db.php because the page breaks without it
require 'includes/db.php';
include 'includes/header.php';

// 3. Fetch Products from Database
try {
    // Simple query to get all products
    // We order by product_id DESC so new items show first
    $stmt = $pdo->query("SELECT * FROM products ORDER BY product_id DESC");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    echo "<p style='color:red'>Error fetching products: " . $e->getMessage() . "</p>";
}
?>

<div class="shop-container">
    <div class="welcome-banner">
        <h1>Welcome to Our Toy Shop!</h1>
        <p>Find the best toys for your kids here.</p>
    </div>

    <h2>Latest Products</h2>

    <?php if ($products): ?>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>

                <div class="product-card">
                    <?php
                        $imagePath = "images/products/" . $product['product_photo'];
                        if (empty($product['product_photo']) || !file_exists($imagePath)) {
                            $imagePath = "images/no-image.jpg"; // You should create a dummy placeholder image
                        }
                    ?>
                    <img src="<?php echo $imagePath; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">

                    <div class="card-body">
                        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                        <p class="price">RM <?php echo number_format($product['price'], 2); ?></p>

                        <div class="card-actions">
                            <a href="product_detail.php?id=<?php echo $product['product_id']; ?>" class="btn">View Details</a>

                            <button class="btn btn-primary add-to-cart" data-id="<?php echo $product['product_id']; ?>">Add to Cart</button>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>No products found. (Admin needs to add some!)</p>
    <?php endif; ?>
</div>

<?php
// 6. Include the footer
include 'includes/footer.php';
?>