<?php
// Start session and include database connection (Uncomment when ready)
session_start();
// require_once '../includes/db.php'; 

// --- MOCK DATA  ---
$members = [
    [
        'id' => 1,
        'name' => 'Alice Johnson',
        'email' => 'alice@example.com',
        'phone' => '012-345 6789',
        'orders' => 12,
        'spent' => 1250.00
    ],
    [
        'id' => 2,
        'name' => 'Bob Smith',
        'email' => 'bob.smith@test.com',
        'phone' => '019-876 5432',
        'orders' => 3,
        'spent' => 150.50
    ],
    [
        'id' => 3,
        'name' => 'Charlie Brown',
        'email' => 'charlie@domain.com',
        'phone' => '017-112 2334',
        'orders' => 0,
        'spent' => 0.00
    ]
];

// Handle Search Logic (Placeholder)
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$sort_option = $_GET['sort'] ?? '';

$sql = "";

// 1. SORT LOGIC
switch ($sort_option) {
    case 'name_asc':
        $sql .= " ORDER BY u.full_name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY u.full_name DESC";
        break;
    case 'email_asc':
        $sql .= " ORDER BY u.email ASC";
        break;
    case 'email_desc':
        $sql .= " ORDER BY u.email DESC";
        break;
    case 'phone_asc':
        $sql .= " ORDER BY u.phone_number ASC";
        break;
    case 'phone_desc':
        $sql .= " ORDER BY u.phone_number DESC";
        break;
    case 'order_asc':
        $sql .= " ORDER BY u.order ASC";
        break;
    case 'order_desc':
        $sql .= " ORDER BY u.order DESC";
        break;
    case 'spent_asc':
        $sql .= " ORDER BY u.spent ASC";
        break;
    case 'spent_desc':
        $sql .= " ORDER BY u.spent DESC";
        break;
    default:
        $sql .= " ORDER BY o.user_id DESC";
        break;
}

// 1. Include the Admin Header
include '../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Members</h1>
</div>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form">
        <div class="filter-group search-group">
            <button type="button" class="search-submit-btn" onclick="this.form.submit()" title="Click to Search">
                <i class="icon fa-search"></i>
            </button>
            <input type="text" name="search" class="form-input search-input-field"
                placeholder="Search Member"
                value="<?= htmlspecialchars($search_query) ?>">
        </div>

        <div class="filter-group sort-group">
            <select name="sort" class="form-select" onchange="this.form.submit()">
                <option value="" <?= empty($sort_option) ? 'selected' : '' ?> hidden>Sort</option>
                <option value="name_asc" <?= $sort_option == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                <option value="name_desc" <?= $sort_option == 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                <option value="email_asc" <?= $sort_option == 'email_asc' ? 'selected' : '' ?>>Email (A-Z)</option>
                <option value="email_desc" <?= $sort_option == 'email_desc' ? 'selected' : '' ?>>Email (Z-A)</option>
                <option value="phone_asc" <?= $sort_option == 'phone_asc' ? 'selected' : '' ?>>Phone (Low-High)</option>
                <option value="phone_desc" <?= $sort_option == 'phone_desc' ? 'selected' : '' ?>>Phone (High-Low)</option>
                <option value="order_asc" <?= $sort_option == 'order_asc' ? 'selected' : '' ?>>Order (Low-High)</option>
                <option value="order_desc" <?= $sort_option == 'order_desc' ? 'selected' : '' ?>>Order (High-Low)</option>
                <option value="spent_asc" <?= $sort_option == 'spent_asc' ? 'selected' : '' ?>>Spent (Low-High)</option>
                <option value="spent_desc" <?= $sort_option == 'spent_desc' ? 'selected' : '' ?>>Spent (High-Low)</option>
            </select>
        </div>
    </form>
</div>

<table class="data-table">
    <thead>
        <tr>
            <th style="width: 40px;"><input type="checkbox"></th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone Number</th>
            <th>Orders</th>
            <th>Total Spent</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($members) > 0): ?>
            <?php foreach ($members as $row): ?>
                <tr>
                    <td><input type="checkbox" name="member_ids[]" value="<?= $row['id'] ?>"></td>
                    <td><span style="font-weight: 500; color: #333;"><?= htmlspecialchars($row['name']) ?></span></td>
                    <td><?= htmlspecialchars($row['email']) ?></td>
                    <td><?= htmlspecialchars($row['phone']) ?></td>
                    <td><?= $row['orders'] ?></td>
                    <td>$<?= number_format($row['spent'], 2) ?></td>

                    <td>
                        <a href="member_details.php?id=<?= $row['id'] ?>" class="action-btn" title="View Details">
                            <i class="icon fa-eye"></i>
                        </a>
                    </td>

                </tr>
            <?php endforeach; ?>
        <?php else: ?>
        <?php endif; ?>
    </tbody>
</table>

<?php include 'admin_footer.php'; ?>