<?php
// includes/navbar.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($pdo)) {
    require_once __DIR__ . '/db.php';
}

$is_logged_in = isset($_SESSION['user_id']);
$role = $_SESSION['role'] ?? 'guest';
$current_user_id = $_SESSION['user_id'] ?? 0;

// Fetch Categories
try {
    $stmt_cat = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
    $header_categories = $stmt_cat->fetchAll();
} catch (PDOException $e) {
    $header_categories = [];
}

// --- COUNTERS ---
$cart_count = 0;
$wishlist_count = 0;

if ($is_logged_in) {
    try {
        // 1. Count Cart
        $sql_cart = "SELECT SUM(quantity) as total FROM cart c JOIN cart_items ci ON c.cart_id = ci.cart_id WHERE c.user_id = ?";
        $stmt = $pdo->prepare($sql_cart);
        $stmt->execute([$current_user_id]);
        $cart_count = $stmt->fetch()['total'] ?? 0;

        // 2. Count Wishlist
        $sql_wish = "SELECT COUNT(*) as total FROM wishlist WHERE user_id = ?";
        $stmt = $pdo->prepare($sql_wish);
        $stmt->execute([$current_user_id]);
        $wishlist_count = $stmt->fetch()['total'] ?? 0;

    } catch (Exception $e) {
        // Ignore errors
    }
}
?>

<header>
    <div class="header-container">
        <a href="index.php" class="logo">
            <i class="fas fa-robot"></i> ToyShop
        </a>

        <div class="search-bar">
            <form action="index.php" method="GET">
                <input type="text" name="search" placeholder="Search toys..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                <button type="submit"><i class="fas fa-search"></i></button>
            </form>
        </div>

        <nav>
            <ul class="nav-links">
                <li class="dropdown">
                    <a href="#" class="dropbtn">Categories <i class="fas fa-chevron-down"></i></a>
                    <div class="dropdown-content">
                        <a href="index.php">All Toys</a>
                        <?php foreach ($header_categories as $cat): ?>
                            <a href="index.php?category=<?php echo $cat['category_id']; ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </li>

                <?php if ($role === 'admin'): ?>
                    <li><a href="admin/product_list.php">Admin Panel</a></li>
                    <li><a href="logout.php">Logout</a></li>
                <?php elseif ($role === 'member'): ?>
                    <li><a href="orders.php">Order History</a></li>
                    <li><a href="logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php">Register</a></li>
                <?php endif; ?>

                <?php if ($role !== 'admin'): ?>
                
                <!-- WISHLIST ICON -->
                <li>
                    <a href="wishlist.php" class="cart-icon" style="position: relative; display: inline-block;">
                        <i class="fas fa-heart"></i>
                        <?php if ($wishlist_count > 0): ?>
                            <span class="cart-notification"><?php echo $wishlist_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- CART ICON -->
                <li>
                    <a href="cart.php" class="cart-icon" style="position: relative; display: inline-block;">
                        <i class="fas fa-shopping-bag"></i>
                        <?php if ($cart_count > 0): ?>
                            <span class="cart-notification"><?php echo $cart_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>
<main>