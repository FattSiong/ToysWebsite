<?php 
include 'includes/header.php'; 
include 'includes/navbar.php'; 

if (!isset($_SESSION['user_id'])) {
    echo "<div class='container mt-5 text-center'><h3>Please login to view wishlist.</h3></div>";
    include 'includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle Remove Logic from this page
if (isset($_GET['remove'])) {
    $pid = $_GET['remove'];
    $pdo->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?")->execute([$user_id, $pid]);
    echo "<script>window.location.href='wishlist.php';</script>";
}

// Fetch Wishlist Items
$sql = "SELECT w.*, p.name, p.price, p.description, pm.file_path 
        FROM wishlist w
        JOIN products p ON w.product_id = p.product_id
        LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1
        WHERE w.user_id = ? ORDER BY w.added_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$items = $stmt->fetchAll();
?>

<div class="container mt-4">
    <h2 class="mb-4 text-danger"><i class="fas fa-heart"></i> My Wishlist</h2>

    <?php if (empty($items)): ?>
        <div class="alert alert-light border text-center py-5">
            <h4>Your wishlist is empty.</h4>
            <a href="index.php" class="btn btn-primary mt-3">Find some toys</a>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($items as $item): 
                $imgUrl = !empty($item['file_path']) ? $item['file_path'] : "https://via.placeholder.com/300";
            ?>
            <div class="col-md-3 mb-4">
                <div class="card h-100 shadow-sm">
                    <img src="<?php echo htmlspecialchars($imgUrl); ?>" class="card-img-top" style="height: 200px; object-fit: contain; padding:10px;">
                    <div class="card-body text-center">
                        <h5 class="card-title"><?php echo htmlspecialchars($item['name']); ?></h5>
                        <p class="text-success fw-bold">$<?php echo number_format($item['price'], 2); ?></p>
                        
                        <div class="d-grid gap-2">
                            <a href="product_detail.php?id=<?php echo $item['product_id']; ?>" class="btn btn-sm btn-primary">View Details</a>
                            <a href="wishlist.php?remove=<?php echo $item['product_id']; ?>" class="btn btn-sm btn-outline-danger">Remove</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>