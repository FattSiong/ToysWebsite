<?php 
// 1. START SESSION & DB CONNECTION FIRST
// We do not include header/navbar yet!
require_once 'includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$product_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
$msg = ""; 

// 2. FETCH PRODUCT (Needed for logic)
$sql = "SELECT p.*, pm.file_path 
        FROM products p 
        LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1
        WHERE p.product_id = ? AND p.status = 'Active'";
$stmt = $pdo->prepare($sql);
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if ($product) {
    $product_exists = true;
} else {
    $product_exists = false;
}

// 3. HANDLE ACTIONS (ADD TO CART / WISHLIST)
// This runs BEFORE the navbar loads, so the count will be correct!
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $product_exists) {
    
    if (!$user_id) {
        echo "<script>alert('Please login first.'); window.location.href='login.php';</script>";
        exit;
    }

    // A. Wishlist Logic
    if (isset($_POST['toggle_wishlist'])) {
        $checkW = $pdo->prepare("SELECT wishlist_id FROM wishlist WHERE user_id = ? AND product_id = ?");
        $checkW->execute([$user_id, $product_id]);
        
        if ($checkW->rowCount() > 0) {
            $pdo->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?")->execute([$user_id, $product_id]);
        } else {
            $pdo->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)")->execute([$user_id, $product_id]);
        }
        // Redirect to refresh page immediately
        header("Location: product_detail.php?id=$product_id");
        exit;
    }

    // B. Add to Cart Logic
    if (isset($_POST['add_to_cart'])) {
        $qty = (int)$_POST['quantity'];
        if ($qty < 1) $qty = 1;

        // Get/Create Cart
        $cartStmt = $pdo->prepare("SELECT cart_id FROM cart WHERE user_id = ?");
        $cartStmt->execute([$user_id]);
        $cart = $cartStmt->fetch();

        if (!$cart) {
            $pdo->prepare("INSERT INTO cart (user_id) VALUES (?)")->execute([$user_id]);
            $cart_id = $pdo->lastInsertId();
        } else {
            $cart_id = $cart['cart_id'];
        }

        // Add/Update Item
        $checkItem = $pdo->prepare("SELECT cart_item_id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ?");
        $checkItem->execute([$cart_id, $product_id]);
        $existingItem = $checkItem->fetch();

        if ($existingItem) {
            $newQty = $existingItem['quantity'] + $qty;
            if($newQty > $product['stock_quantity']) $newQty = $product['stock_quantity'];
            $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?")->execute([$newQty, $existingItem['cart_item_id']]);
        } else {
            $pdo->prepare("INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, ?)")->execute([$cart_id, $product_id, $qty]);
        }
        
        $msg = "<div class='container mt-3'><div class='alert alert-success'>Added $qty items to cart!</div></div>";
    }
}

// 4. NOW INCLUDE NAVBAR
// The database is updated, so the Navbar will fetch the NEW count.
include 'includes/header.php'; 
include 'includes/navbar.php'; 

if (!$product_exists) {
    echo "<div class='container mt-5'><div class='alert alert-warning text-center'>Product not found.</div></div>";
    include 'includes/footer.php';
    exit;
}

// 5. FETCH DISPLAY DATA
$in_wishlist = false;
if ($user_id) {
    $stmtW = $pdo->prepare("SELECT wishlist_id FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmtW->execute([$user_id, $product_id]);
    if ($stmtW->rowCount() > 0) $in_wishlist = true;
}

$stmtAvg = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = ?");
$stmtAvg->execute([$product_id]);
$stats = $stmtAvg->fetch();
$avg_rating = round($stats['avg_rating'] ?? 0, 1);
$total_reviews = $stats['total_reviews'];

$stmtReviews = $pdo->prepare("SELECT r.*, u.username as full_name FROM reviews r JOIN users u ON r.user_id = u.user_id WHERE r.product_id = ? ORDER BY r.created_at DESC");
$stmtReviews->execute([$product_id]);
$reviews = $stmtReviews->fetchAll();

// Helper Function
function displayStars($rating) {
    $output = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) $output .= '<i class="fas fa-star checked"></i>';
        elseif ($i - 0.5 <= $rating) $output .= '<i class="fas fa-star-half-alt checked"></i>';
        else $output .= '<i class="far fa-star checked"></i>';
    }
    return $output;
}
?>

<!-- HTML DISPLAY -->
<?php echo $msg; ?>

<div class="container mt-4">
    <a href="index.php" class="btn btn-outline-secondary mb-3">&larr; Back to Shop</a>
    
    <div class="card shadow-sm mb-5">
        <div class="row g-0">
            <div class="col-md-6">
                <?php $imgUrl = !empty($product['file_path']) ? $product['file_path'] : "https://via.placeholder.com/600x400"; ?>
                <img src="<?php echo htmlspecialchars($imgUrl); ?>" class="img-fluid rounded-start" style="width:100%; min-height:400px; object-fit:contain; background:#f8f9fa;">
            </div>
            
            <div class="col-md-6">
                <div class="card-body p-5">
                    <h1 class="display-5 fw-bold"><?php echo htmlspecialchars($product['name']); ?></h1>
                    
                    <div class="mb-3">
                        <span class="h4 text-warning"><?php echo $avg_rating; ?></span>
                        <span class="ms-2 me-2"><?php echo displayStars($avg_rating); ?></span>
                        <span class="text-muted small">(<?php echo $total_reviews; ?> reviews)</span>
                    </div>

                    <h2 class="text-success my-3">RM <?php echo number_format($product['price'], 2); ?></h2>
                    <p class="card-text text-muted mb-4"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                    <p><strong>Availability:</strong> 
                        <?php if($product['stock_quantity'] > 0): ?>
                            <span class="text-success">In Stock (<?php echo $product['stock_quantity']; ?>)</span>
                        <?php else: ?>
                            <span class="text-danger">Out of Stock</span>
                        <?php endif; ?>
                    </p>
                    <hr>
                    
                    <?php if($product['stock_quantity'] > 0): ?>
                    <form method="post" class="row align-items-end g-3">
                        <div class="col-md-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" value="1" min="1" max="<?php echo $product['stock_quantity']; ?>" class="form-control form-control-lg">
                        </div>
                        <div class="col-md-7">
                            <button type="submit" name="add_to_cart" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-shopping-cart"></i> Add to Cart
                            </button>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" name="toggle_wishlist" class="btn btn-lg w-100 <?php echo $in_wishlist ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                <i class="<?php echo $in_wishlist ? 'fas' : 'far'; ?> fa-heart"></i>
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- REVIEWS SECTION -->
    <div class="row">
        <div class="col-12">
            <h3>Customer Reviews</h3>
            <hr>
            <?php if (count($reviews) > 0): ?>
                <?php foreach ($reviews as $rev): 
                    // FETCH MEDIA FOR THIS REVIEW
                    $stmtMedia = $pdo->prepare("SELECT * FROM review_media WHERE review_id = ?");
                    $stmtMedia->execute([$rev['review_id']]);
                    $mediaFiles = $stmtMedia->fetchAll();
                ?>
                    <div class="card mb-3 review-card">
                        <!-- ADDED text-start HERE -->
                        <div class="card-body text-start">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($rev['full_name']); ?></h5>
                                <small class="text-muted"><?php echo date('M d, Y', strtotime($rev['created_at'])); ?></small>
                            </div>
                            
                            <!-- Stars -->
                            <div class="mb-2 mt-1">
                                <?php echo displayStars($rev['rating']); ?>
                            </div>
                            
                            <!-- Comment -->
                            <p class="card-text"><?php echo nl2br(htmlspecialchars($rev['comment'])); ?></p>
                            
                            <!-- DISPLAY MEDIA -->
                            <?php if ($mediaFiles): ?>
                                <div class="mt-3 d-flex gap-2 flex-wrap">
                                    <?php foreach ($mediaFiles as $media): ?>
                                        <?php if ($media['media_type'] == 'image'): ?>
                                            <a href="<?php echo $media['file_path']; ?>" target="_blank">
                                                <img src="<?php echo $media['file_path']; ?>" style="width: 100px; height: 100px; object-fit: cover; border-radius: 5px; border: 1px solid #ddd;">
                                            </a>
                                        <?php elseif ($media['media_type'] == 'video'): ?>
                                            <video width="150" height="100" controls style="border-radius: 5px;">
                                                <source src="<?php echo $media['file_path']; ?>" type="video/mp4">
                                            </video>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-light border text-center py-5">
                    <p class="text-muted">No reviews yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>