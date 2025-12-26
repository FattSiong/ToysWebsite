<?php
require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// --- HANDLE COLLECT VOUCHER ---
if (isset($_POST['collect_voucher'])) {
    $vid = $_POST['voucher_id'];
    
    // Check validity
    $check = $pdo->prepare("SELECT voucher_id FROM voucher_usage WHERE voucher_id = ? AND user_id = ?");
    $check->execute([$vid, $user_id]);
    
    if ($check->rowCount() == 0) {
        $pdo->prepare("INSERT INTO voucher_usage (voucher_id, user_id) VALUES (?, ?)")->execute([$vid, $user_id]);
        $_SESSION['msg'] = "Voucher collected successfully!";
    }
    header("Location: vouchers.php");
    exit;
}

include '../includes/header.php';

// 1. Fetch Available (Not collected, Not expired)
$sql_avail = "SELECT * FROM vouchers v 
              WHERE (v.expiry_date >= ? OR v.expiry_date IS NULL)
              AND v.voucher_id NOT IN (SELECT voucher_id FROM voucher_usage WHERE user_id = ?)";
$stmt = $pdo->prepare($sql_avail);
$stmt->execute([$today, $user_id]);
$available_vouchers = $stmt->fetchAll();

// 2. Fetch My Vouchers (Collected)
$sql_my = "SELECT vu.*, v.* FROM voucher_usage vu 
           JOIN vouchers v ON vu.voucher_id = v.voucher_id 
           WHERE vu.user_id = ? 
           ORDER BY vu.is_used ASC, (v.expiry_date IS NULL) DESC, v.expiry_date ASC";
$stmt = $pdo->prepare($sql_my);
$stmt->execute([$user_id]);
$my_vouchers = $stmt->fetchAll();

// Separate My Vouchers into Active and History
$my_active = [];
$my_history = []; // Used or Expired

foreach ($my_vouchers as $v) {
    $is_expired = ($v['expiry_date'] && $v['expiry_date'] < $today);
    if ($v['is_used'] || $is_expired) {
        $my_history[] = $v;
    } else {
        $my_active[] = $v;
    }
}
?>

<link rel="stylesheet" href="../css/vouchers.css">

<div class="voucher-container">
    <div class="page-header">
        <h2><i class="fas fa-ticket-alt"></i> Voucher Center</h2>
        <p>Collect and use vouchers to save on your orders!</p>
    </div>

    <?php if(isset($_SESSION['msg'])): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $_SESSION['msg']; unset($_SESSION['msg']); ?>
        </div>
    <?php endif; ?>

    <div class="voucher-tabs">
        <button class="tab-btn active" onclick="openTab(event, 'tab-available')">Available (<?= count($available_vouchers) ?>)</button>
        <button class="tab-btn" onclick="openTab(event, 'tab-mine')">My Vouchers (<?= count($my_active) ?>)</button>
        <button class="tab-btn" onclick="openTab(event, 'tab-history')">History</button>
    </div>

    <div id="tab-available" class="tab-content active">
        <?php if(empty($available_vouchers)): ?>
            <div class="empty-state">
                <i class="far fa-sad-tear"></i>
                <p>No new vouchers available to collect right now.</p>
            </div>
        <?php else: ?>
            <div class="voucher-grid">
                <?php foreach($available_vouchers as $v): ?>
                    <?php renderVoucherCard($v, 'collect'); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="tab-mine" class="tab-content">
        <?php if(empty($my_active)): ?>
            <div class="empty-state">
                <i class="fas fa-ticket-alt"></i>
                <p>You haven't collected any active vouchers yet.</p>
                <button onclick="document.querySelector('.tab-btn.active').click()" class="btn-link">Go Collect Some!</button>
            </div>
        <?php else: ?>
            <div class="voucher-grid">
                <?php foreach($my_active as $v): ?>
                    <?php renderVoucherCard($v, 'use'); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="tab-history" class="tab-content">
        <?php if(empty($my_history)): ?>
            <div class="empty-state">
                <p>No used or expired vouchers.</p>
            </div>
        <?php else: ?>
            <div class="voucher-grid">
                <?php foreach($my_history as $v): ?>
                    <?php renderVoucherCard($v, 'history'); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
// Helper Function to Render Card
function renderVoucherCard($v, $action) {
    // Determine Type Display
    if ($v['discount_type'] == 'free_shipping') {
        $main_text = "Free<br>Shipping";
        $sub_text = "Shipping Fee Waived";
        $icon = "fa-truck";
        $color_class = "v-blue";
    } elseif ($v['discount_type'] == 'percentage') {
        $main_text = intval($v['discount_value']) . "%<br>OFF";
        $sub_text = "Discount";
        $icon = "fa-percent";
        $color_class = "v-orange";
    } else {
        $main_text = "RM" . intval($v['discount_value']) . "<br>OFF";
        $sub_text = "Fixed Discount";
        $icon = "fa-money-bill-wave";
        $color_class = "v-red";
    }
    
    // Min Spend Text
    $min_spend_text = ($v['min_spend'] > 0) ? "Min. Spend RM" . $v['min_spend'] : "No Min. Spend";
    
    // Expiry Text
    $expiry_text = $v['expiry_date'] ? "Exp: " . date("d M Y", strtotime($v['expiry_date'])) : "No Expiry Date";
    
    // Status Logic for History
    $overlay = "";
    if ($action == 'history') {
        if (isset($v['is_used']) && $v['is_used']) {
            $overlay = '<div class="stamp used">USED</div>';
        } else {
            $overlay = '<div class="stamp expired">EXPIRED</div>';
        }
    }
    ?>
    <div class="coupon-card <?php echo $color_class; ?> <?php echo ($action=='history') ? 'disabled' : ''; ?>">
        <?php echo $overlay; ?>
        
        <div class="coupon-left">
            <div class="coupon-inner">
                <i class="fas <?php echo $icon; ?> coupon-icon"></i>
            </div>
        </div>
        
        <div class="coupon-center">
            <h3 class="coupon-title"><?php echo $main_text; ?></h3>
            <p class="coupon-sub"><?php echo $sub_text; ?></p>
            <div class="coupon-meta">
                <span><?php echo $min_spend_text; ?></span>
                <span><?php echo $expiry_text; ?></span>
            </div>
        </div>
        
        <div class="coupon-right">
            <?php if ($action == 'collect'): ?>
                <form method="POST">
                    <input type="hidden" name="voucher_id" value="<?php echo $v['voucher_id']; ?>">
                    <button type="submit" name="collect_voucher" class="btn-action">Collect</button>
                </form>
            <?php elseif ($action == 'use'): ?>
                <a href="checkout.php" class="btn-action">Use</a>
            <?php else: ?>
                <span class="code-display"><?php echo htmlspecialchars($v['code']); ?></span>
            <?php endif; ?>
        </div>
        
        <div class="circle c-top"></div>
        <div class="circle c-bottom"></div>
    </div>
    <?php
}
?>

<script>
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
        tabcontent[i].classList.remove("active");
    }
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }
    document.getElementById(tabName).style.display = "block";
    setTimeout(() => document.getElementById(tabName).classList.add("active"), 10);
    evt.currentTarget.classList.add("active");
}
// Default Open
document.getElementById("tab-available").style.display = "block";
</script>

<?php include '../includes/footer.php'; ?>