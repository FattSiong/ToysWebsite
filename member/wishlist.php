<?php 
include '../includes/header.php'; 

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    echo "<div style='text-align:center; margin-top:50px;'><h3>Please login to view wishlist.</h3><a href='../login.php'>Login</a></div>";
    include '../includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];

// 2. Handle Remove Logic
if (isset($_GET['remove'])) {
    $pid = $_GET['remove'];
    $pdo->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?")->execute([$user_id, $pid]);
    // Redirect to self to prevent resubmission issues
    echo "<script>window.location.href='wishlist.php';</script>";
    exit;
}

// 3. Fetch Wishlist Items
$sql = "SELECT w.*, p.name, p.price, p.description, pm.file_path 
        FROM wishlist w
        JOIN products p ON w.product_id = p.product_id
        LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1
        WHERE w.user_id = ? ORDER BY w.added_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$items = $stmt->fetchAll();
?>

<link rel="stylesheet" href="../css/wishlist.css">

<div class="wishlist-container">
    <div class="page-header">
        <h2><i class="fas fa-heart" style="color: #dc3545;"></i> My Wishlist</h2>
        <span class="item-count"><?php echo count($items); ?> Items</span>
    </div>

    <?php if (empty($items)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="far fa-heart"></i></div>
            <h4>Your wishlist is empty.</h4>
            <p>Save items you want to view later!</p>
            <a href="../index.php" class="btn-shop">Find some toys</a>
        </div>
    <?php else: ?>
        <div class="wishlist-grid">
            <?php foreach ($items as $item): 
                // Fix Image Path Logic
                $imgUrl = "https://via.placeholder.com/300?text=No+Image";
                if (!empty($item['file_path'])) {
                    // Check if path already has 'uploads' or needs adjustment
                    if (strpos($item['file_path'], 'http') === 0) {
                        $imgUrl = $item['file_path'];
                    } else {
                        // Ensure we point to ../uploads/products/
                        $cleanPath = str_replace('images/products/', 'uploads/products/', $item['file_path']);
                        if (strpos($cleanPath, 'uploads/') === false) $cleanPath = 'uploads/products/' . $cleanPath;
                        $imgUrl = "../" . $cleanPath;
                    }
                }
            ?>
            <div class="wishlist-card">
                <div class="card-img-box">
                    <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                </div>
                
                <div class="card-body">
                    <h5 class="card-title" title="<?php echo htmlspecialchars($item['name']); ?>">
                        <?php echo htmlspecialchars($item['name']); ?>
                    </h5>
                    <p class="card-price">RM <?php echo number_format($item['price'], 2); ?></p>
                    
                    <div class="card-actions">
                        <a href="product_detail.php?id=<?php echo $item['product_id']; ?>" class="btn btn-view">
                            View Details
                        </a>
                        <a href="wishlist.php?remove=<?php echo $item['product_id']; ?>" class="btn btn-remove" onclick="return confirm('Remove this item?');">
                            <i class="fas fa-trash"></i> Remove
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>