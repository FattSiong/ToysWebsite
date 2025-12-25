<?php
// ToysWebsite/member/points.php
include '../includes/header.php';

// 1. Security: Ensure User is Logged In
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='../login.php';</script>";
    exit;
}

$user_id = $_SESSION['user_id'];
// Use $_db if available (from base.php), otherwise try $pdo
$conn = isset($_db) ? $_db : (isset($pdo) ? $pdo : null);

if (!$conn) {
    die("Database connection error.");
}

// 2. Fetch Current User Points
$stmtUser = $conn->prepare("SELECT current_points FROM users WHERE user_id = ?");
$stmtUser->execute([$user_id]);
$current_points = $stmtUser->fetchColumn() ?: 0;

// 3. Fetch Point Transactions History
$stmtTrans = $conn->prepare("
    SELECT * FROM point_transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmtTrans->execute([$user_id]);
$transactions = $stmtTrans->fetchAll();
?>

<link rel="stylesheet" href="../css/public.css">
<link rel="stylesheet" href="../css/points.css">

<div class="points-container">
    <div class="points-card">
        <div class="points-header">
            <div class="points-label">Current Balance</div>
            <div class="points-value"><?php echo number_format($current_points); ?> pts</div>
            <div>Use points to redeem discounts on your next order!</div>
        </div>
    </div>

    <div class="points-card history-section">
        <h3>Transaction History</h3>
        
        <?php if (count($transactions) > 0): ?>
            <table class="trans-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Order ID</th>
                        <th style="text-align:right;">Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td class="date-col">
                                <?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($t['description']); ?>
                            </td>
                            <td>
                                <?php if ($t['order_id']): ?>
                                    <a href="order_detail.php?id=<?= $t['order_id'] ?>">#<?= $t['order_id'] ?></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <?php 
                                    $amount = $t['amount'];
                                    $class = $amount >= 0 ? 'amount-plus' : 'amount-minus';
                                    $sign = $amount > 0 ? '+' : '';
                                    echo "<span class='$class'>$sign" . number_format($amount) . "</span>";
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align:center; padding: 20px; color:#777;">No point transactions found.</p>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>