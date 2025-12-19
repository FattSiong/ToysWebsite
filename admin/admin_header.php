<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/icons.css">
</head>

<body>
    <div class="admin-wrapper">

        <!-- Overlay for mobile/collapsed state -->
        <div id="sidebarOverlay" class="sidebar-overlay"></div>

        <!-- Toggle Button (Visible when sidebar is hidden) -->
        <div class="top-header-bar">
            <button id="sidebarToggle" class="sidebar-toggle-btn">
                <i class="icon fa-bars"></i>
            </button>
        </div>

        <nav class="sidebar">
            <div class="sidebar-header">
                <h2>Admin</h2>
            </div>
            <ul class="sidebar-menu">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>

                <li>
                    <a href="dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="icon fa-home"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="product_list.php" class="<?= $current_page == 'product_list.php' ? 'active' : '' ?>">
                        <i class="icon fa-box"></i> Products
                    </a>
                </li>
                <li>
                    <a href="category_list.php" class="<?= $current_page == 'category_list.php' ? 'active' : '' ?>">
                        <i class="icon fa-list"></i> Categories
                    </a>
                </li>
                <li>
                    <a href="order_list.php" class="<?= $current_page == 'order_list.php' ? 'active' : '' ?>">
                        <i class="icon fa-shopping-cart"></i> Orders
                    </a>
                </li>
                <li>
                    <a href="member_list.php" class="<?= $current_page == 'member_list.php' ? 'active' : '' ?>">
                        <i class="icon fa-users"></i> Members
                    </a>
                </li>
                <li>
                    <a href="../logout.php" class="logout-link">
                        <i class="icon fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </nav>

        <main class="main-content">