<?php
// includes/header.php

// Ensure DB connection is available
if (!isset($pdo) && isset($_db)) {
    $pdo = $_db; 
} elseif (!isset($_db)) {
    require_once __DIR__ . '/../base.php'; 
    $pdo = $_db;
}

// Ensure session is started if base.php didn't do it (safety check)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check login status based on what base.php or login.php sets
$is_logged_in = isset($_SESSION['user_id']);
$role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? 'Guest';

// Fetch Categories
try {
    $db = isset($pdo) ? $pdo : $_db; 
    $stmt_cat = $db->query("SELECT * FROM categories ORDER BY name ASC");
    $header_categories = $stmt_cat->fetchAll();
} catch (PDOException $e) {
    $header_categories = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Toy Shop</title>
    
    <!-- CSS Links -->
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/auth.css">
    
    <!-- Scripts & Fonts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<header>
    <div class="header-container">
        <a href="/index.php" class="logo">
            <i class="fas fa-robot"></i> ToyShop
        </a>

        <div class="search-bar">
            <form action="/index.php" method="GET">
                <input type="text" name="search" placeholder="Search toys...">
                <button type="submit"><i class="fas fa-search"></i></button>
            </form>
        </div>

        <nav>
            <ul class="nav-links">
                <li class="dropdown">
                    <a href="#" class="dropbtn">Categories <i class="fas fa-chevron-down"></i></a>
                    <div class="dropdown-content">
                        <a href="/index.php">All Toys</a>
                        <?php foreach ($header_categories as $cat): ?>
                            <a href="/index.php?category=<?php echo $cat->category_id; ?>">
                                <?php echo htmlspecialchars($cat->name); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </li>

                <?php if ($is_logged_in): ?>
                    
                    <?php if ($role === 'admin'): ?>
                        <li><a href="/admin/dashboard.php">Admin Panel</a></li>
                    <?php else: ?>
                        <li><a href="/cart.php" class="cart-icon">
                            <i class="fas fa-shopping-bag"></i>
                            <span class="badge">0</span>
                        </a></li>
                        <li><a href="/profile.php"><i class="fas fa-user"></i> <?= htmlspecialchars($username) ?></a></li>
                    <?php endif; ?>
                    
                    <li><a href="/logout.php">Logout</a></li>
                    
                <?php else: ?>
                    <li><a href="/login.php">Login</a></li>
                    <li><a href="/register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

<main>