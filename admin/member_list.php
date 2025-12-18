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

// 1. Include the Admin Header
include './admin_header.php';
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

<?php
// Include Admin Footer
include './admin_footer.php';
?>