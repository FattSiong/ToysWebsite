<?php
// includes/header.php

// 1. Path & Session Setup
$base_path = file_exists('base.php') ? '' : '../';

if (!isset($_db)) {
    require_once __DIR__ . '/../base.php';
}

$is_logged_in = isset($_SESSION['user_id']);
$role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? 'Guest';
$current_page = basename($_SERVER['PHP_SELF']);

// 2. Identify Page Type
// UPDATED: Allow 'manager' to be recognized as an admin page user
$is_admin_page = (($role === 'admin' || $role === 'manager') && strpos($_SERVER['PHP_SELF'], '/admin/') !== false);

// Default to placeholder
$profile_pic = $base_path . 'images/no-image.jpg'; 

// If logged in and has photo, use it
if ($is_logged_in && !empty($_SESSION['user']['profile_photo'])) {
    $profile_pic = $base_path . 'images/profiles/' . $_SESSION['user']['profile_photo'];
}

// Optional: Logic to get actual cart count
$cart_count = 0;
if ($is_logged_in && isset($pdo)) { 
    $stmtC = $pdo->prepare("SELECT SUM(quantity) FROM cart_items ci JOIN cart c ON ci.cart_id = c.cart_id WHERE c.user_id = ?");
    $stmtC->execute([$_SESSION['user_id']]);
    $cart_count = $stmtC->fetchColumn() ?: 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Toy Shop</title>

    <link rel="stylesheet" href="<?= $base_path ?>css/public.css">
    <link rel="stylesheet" href="<?= $base_path ?>css/main_sidebar.css">
    

    <?php if ($is_admin_page): ?>
        <link rel="stylesheet" href="<?= $base_path ?>css/admin.css">
        <link rel="stylesheet" href="<?= $base_path ?>css/icons.css">
    <?php else: ?>
        <link rel="stylesheet" href="<?= $base_path ?>css/auth.css">
        <link rel="stylesheet" href="<?= $base_path ?>css/icons.css">
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?= $base_path ?>js/sidebar.js" defer></script>
</head>

<body>

    <div id="sidebarOverlay" class="sidebar-overlay"></div>

    <nav class="sidebar">
        <div class="sidebar-header">
            <h2><?php echo $is_logged_in ? htmlspecialchars($username) : 'Menu'; ?></h2>
        </div>

        <ul class="sidebar-menu">
            <?php if ($role !== 'admin' && $role !== 'manager'): ?>
                <li>
                    <a href="<?= $base_path ?>index.php" class="<?= $current_page == 'index.php' ? 'active' : '' ?>">
                        <i class="icon fas fa-home"></i> Home
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($is_logged_in): ?>

                <?php if ($role === 'admin' || $role === 'manager'): ?>
                    <li class="menu-label" style="padding: 10px 20px; color: #888; font-size: 0.8em; text-transform: uppercase;">Admin Tools</li>

                    <li>
                        <a href="<?= $base_path ?>admin/dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                            <i class="icon fa-home"></i> Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>admin/product_list.php" class="<?= $current_page == 'product_list.php' ? 'active' : '' ?>">
                            <i class="icon fas fa-box"></i> Products
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>admin/category_list.php" class="<?= $current_page == 'category_list.php' ? 'active' : '' ?>">
                            <i class="icon fas fa-list"></i> Categories
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>admin/order_list.php" class="<?= $current_page == 'order_list.php' ? 'active' : '' ?>">
                            <i class="icon fas fa-shopping-cart"></i> Orders
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>admin/member_list.php" class="<?= $current_page == 'member_list.php' ? 'active' : '' ?>">
                            <i class="icon fas fa-users"></i> Members
                        </a>
                    </li>

                    <?php if ($role === 'manager'): ?>
                    <li>
                        <a href="<?= $base_path ?>admin/manage_admin.php" class="<?= $current_page == 'manage_admin.php' ? 'active' : '' ?>">
                            <i class="icon fas fa-user-shield"></i> Manage Admin
                        </a>
                    </li>
                    <?php endif; ?>

                    <li class="menu-label" style="padding: 10px 20px; color: #888; font-size: 0.8em; text-transform: uppercase;">Account</li>
                <?php endif; ?>

                <li>
                    <a href="<?= $base_path ?>profile.php" class="<?= $current_page == 'profile.php' ? 'active' : '' ?>">
                        <i class="icon fas fa-user"></i> My Profile
                    </a>
                </li>

                <?php if ($role !== 'admin' && $role !== 'manager'): ?>
                    <li>
                        <a href="<?= $base_path ?>member/points.php" class="<?= $current_page == 'points.php' ? 'active' : '' ?>">
                            <i class="icon fas fa-coins"></i> My Points
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>member/orders.php" class="<?= $current_page == 'orders.php' ? 'active' : '' ?>">
                            <i class="icon fas fa-box-open"></i> My Orders
                        </a>
                    </li>

                    <li>
                        <a href="<?= $base_path ?>member/wishlist.php" class="<?= $current_page == 'wishlist.php' ? 'active' : '' ?>">
                            <i class="icon fas fa-heart"></i> My Wishlist
                        </a>
                    </li>
                <?php endif; ?>

                <li>
                    <a href="<?= $base_path ?>logout.php" class="logout-link">
                        <i class="icon fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>

            <?php else: ?>
                <li>
                    <a href="<?= $base_path ?>login.php" class="<?= $current_page == 'login.php' ? 'active' : '' ?>">
                        <i class="icon fas fa-sign-in-alt"></i> Login
                    </a>
                </li>
                <li>
                    <a href="<?= $base_path ?>register.php" class="<?= $current_page == 'register.php' ? 'active' : '' ?>">
                        <i class="icon fas fa-user-plus"></i> Register
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>

    <header>
        <div class="header-container">
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="sidebarToggle" class="sidebar-toggle-btn">
                    <i class="icon fas fa-bars"></i>
                </button>

                <a href="<?= $base_path ?><?= ($role === 'admin' || $role === 'manager') ? 'admin/dashboard.php' : 'index.php' ?>" class="logo">
                    <i class="icon fas fa-robot"></i> ToyShop
                </a>
            </div>

            <?php if ($role !== 'admin' && $role !== 'manager'): ?>
                <div class="search-bar">
                    <form action="<?= $base_path ?>index.php" method="GET">
                        <input type="text" name="search" placeholder="Search toys...">
                        <button type="submit"><i class="icon fas fa-search"></i></button>
                    </form>
                </div>
            <?php endif; ?>

            <nav>
                <ul class="nav-links">
                    <?php if ($is_logged_in && $role !== 'admin' && $role !== 'manager'): ?>
                        <li>
                            <a href="<?= $base_path ?>member/cart.php" class="cart-icon">
                                <i class="icon fas fa-shopping-cart"></i>
                                <span class="badge"><?php echo $cart_count; ?></span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($is_logged_in): ?>
                        <li><a href="<?= $base_path ?>profile.php"><i class="icon fas fa-user"></i></a></li>
                    <?php else: ?>
                        <li><a href="<?= $base_path ?>login.php">Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main>