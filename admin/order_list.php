<?php
// Start session
session_start();

// --- MOCK DATA (For Design Purposes) ---
// Remove this block when you implement real database fetching
$orders = [
    [
        'order_id' => 1001,
        'order_date' => '2025-10-24 14:30:00',
        'status' => 'Pending',
        'payment_method' => 'Credit Card'
    ],
    [
        'order_id' => 1002,
        'order_date' => '2025-10-25 09:15:00',
        'status' => 'Completed',
        'payment_method' => 'PayPal'
    ],
    [
        'order_id' => 1003,
        'order_date' => '2025-10-26 18:45:00',
        'status' => 'Cancelled',
        'payment_method' => 'Bank Transfer'
    ]
];

// Handle Search Logic (Placeholder)
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$sort_option = $_GET['sort'] ?? '';

$sql = "";

// 1. SORT LOGIC
switch ($sort_option) {
    case 'id_asc':
        $sql .= " ORDER BY o.order_id ASC";
        break;
    case 'id_desc':
        $sql .= " ORDER BY o.order_id DESC";
        break;
    case 'date_asc':
        $sql .= " ORDER BY o.order_date ASC";
        break;
    case 'date_desc':
        $sql .= " ORDER BY o.order_date DESC";
        break;
    default:
        $sql .= " ORDER BY o.order_id DESC";
        break;
}

// 1. Include the Admin Header
include '../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Orders</h1>
</div>

<div class="filter-bar">
    <form action="" method="GET" class="filter-form">
        <div class="filter-group search-group">
            <button type="button" class="search-submit-btn" onclick="this.form.submit()" title="Click to Search">
                <i class="icon fa-search"></i>
            </button>
            <input type="text" name="search" class="form-input search-input-field"
                placeholder="Search Order"
                value="<?= htmlspecialchars($search_query) ?>">
        </div>

        <div class="filter-group sort-group">
            <select name="sort" class="form-select" onchange="this.form.submit()">
                <option value="" <?= empty($sort_option) ? 'selected' : '' ?> hidden>Sort</option>
                <option value="id_asc" <?= $sort_option == 'id_asc' ? 'selected' : '' ?>>ID (Oldest - Newest)</option>
                <option value="id_desc" <?= $sort_option == 'id_desc' ? 'selected' : '' ?>>ID (Newest - Oldest)</option>
                <option value="date_asc" <?= $sort_option == 'date_asc' ? 'selected' : '' ?>>Date (Oldest - Newest)</option>
                <option value="date_desc" <?= $sort_option == 'date_desc' ? 'selected' : '' ?>>Date (Newest - Oldest)</option>
            </select>
        </div>
    </form>
</div>

<table class="data-table">
    <thead>
        <tr>
            <th style="width: 40px;"><input type="checkbox"></th>
            <th>Order ID</th>
            <th>Order Date</th>
            <th>Order Status</th>
            <th>Payment Method</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($orders) > 0): ?>
            <?php foreach ($orders as $row): ?>
                <tr>
                    <td><input type="checkbox" name="order_ids[]" value="<?= $row['order_id'] ?>"></td>
                    <td>
                        <span style="font-weight: 500; color: #333;"><?= htmlspecialchars($row['order_id']) ?></span>
                    </td>
                    <td><?= date('M d, Y h:i A', strtotime($row['order_date'])) ?></td>
                    <td>
                        <?php
                        $statusColor = '#333';
                        if ($row['status'] === 'Completed') $statusColor = 'green';
                        if ($row['status'] === 'Cancelled') $statusColor = 'red';
                        if ($row['status'] === 'Pending') $statusColor = 'orange';
                        ?>
                        <span style="color: <?= $statusColor ?>; font-weight: 500;">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($row['payment_method']) ?></td>
                    <td>
                        <a href="order_details.php?id=<?= $row['order_id'] ?>" class="action-btn" title="View Order">
                            <i class="icon fa-eye"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="6" style="text-align:center; padding: 30px; color: #888;">No orders found.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php include 'admin_footer.php'; ?>