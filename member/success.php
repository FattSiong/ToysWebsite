<?php
require_once '../vendor/autoload.php';
require_once '../includes/db.php'; 

if (session_status() === PHP_SESSION_NONE) session_start();

// Config Stripe
$stripeSecretKey = 'sk_test_51SeuhSEicrNwy8rsAMacjNRNiv1UvST0Tlpfo9CCmD7dZFuQZBJGx7dmC5WFPiugMkCvTmvlimnKB6Fps8AkhNAv00VUXMtoc8';
\Stripe\Stripe::setApiKey($stripeSecretKey);

// Check if we have the necessary data
if (!isset($_GET['session_id']) || !isset($_SESSION['pending_order'])) {
    echo "<script>window.location.href='../index.php';</script>";
    exit;
}

$session_id = $_GET['session_id'];
$orderData = $_SESSION['pending_order'];
$user_id = $_SESSION['user_id'];
$points_earned_now = 0;
$order_id = 0;
$payment_success = false;

try {
    // 1. Verify Payment
    $isFreeOrder = ($session_id === 'free_order_bypass' && $orderData['final_total'] <= 0);
    $stripe_txn_id = null;

    if ($isFreeOrder) {
        $payment_success = true;
        $stripe_txn_id = "FREE-" . uniqid();
    } else {
        $session = \Stripe\Checkout\Session::retrieve($session_id);
        if ($session->payment_status == 'paid') {
            $payment_success = true;
            $stripe_txn_id = $session->payment_intent;
        }
    }

    if ($payment_success) {
        
        // BEGIN TRANSACTION
        $pdo->beginTransaction();

        // --- A. INSERT SHIPPING ADDRESS (If New) ---
        $shipping_id = null;
        if (isset($orderData['new_address'])) {
            $na = $orderData['new_address'];
            $addrSql = "INSERT INTO shipping_addresses (user_id, receiver_name, line1, line2, city, state, postcode, phone, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($addrSql)->execute([
                $user_id, $na['recipient_name'], $na['line1'], $na['line2'], $na['city'], 
                $na['state'], $na['postcode'], $na['phone'], $na['is_default']
            ]);
            $shipping_id = $pdo->lastInsertId();
        } else {
            $shipping_id = $orderData['shipping_id'];
        }

        // --- B. CALCULATE POINTS EARNED ---
        // Earn 1 point per RM 1 paid (based on final total)
        $amount_paid = $orderData['final_total'];
        $points_earned_now = floor($amount_paid);

        // --- C. INSERT ORDER (WITH VOUCHER ID) ---
        $voucher_usage_id = isset($orderData['voucher_usage_id']) ? $orderData['voucher_usage_id'] : null;
        
        $orderSql = "INSERT INTO orders (
            user_id, 
            shipping_address_id, 
            voucher_usage_id, 
            total_amount, 
            order_status, 
            points_redeemed, 
            point_discount, 
            points_earned
        ) VALUES (?, ?, ?, ?, 'Pending', ?, ?, ?)";
        
        $pdo->prepare($orderSql)->execute([
            $user_id, 
            $shipping_id,
            $voucher_usage_id,
            $amount_paid, 
            $orderData['points_to_redeem'], 
            $orderData['point_discount'],
            $points_earned_now
        ]);
        $order_id = $pdo->lastInsertId();

        // --- D. INSERT ORDER ITEMS & DEDUCT STOCK ---
        foreach ($orderData['cart_items'] as $item) {
            $oiSql = "INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, ?)";
            $pdo->prepare($oiSql)->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);

            $deductSql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ?";
            $pdo->prepare($deductSql)->execute([$item['quantity'], $item['product_id']]);
        }

        // --- E. HANDLE VOUCHER STATUS ---
        if ($voucher_usage_id) {
            $vUpd = "UPDATE voucher_usage SET is_used = 1, used_at = NOW() WHERE id = ?";
            $pdo->prepare($vUpd)->execute([$voucher_usage_id]);
        }

        // --- F. HANDLE POINTS (Deduct Used & Add Earned) ---
        if ($orderData['points_to_redeem'] > 0) {
            $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")
                ->execute([$orderData['points_to_redeem'], $user_id]);
            
            $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Redeemed for Order')")
                ->execute([$user_id, $order_id, -$orderData['points_to_redeem']]);
        }

        if ($points_earned_now > 0) {
            $pdo->prepare("UPDATE users SET current_points = current_points + ? WHERE user_id = ?")
                ->execute([$points_earned_now, $user_id]);

            $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Earned from Purchase')")
                ->execute([$user_id, $order_id, $points_earned_now]);
        }

        // --- G. RECORD PAYMENT ---
        $paySql = "INSERT INTO payments (order_id, payment_method, amount, status, transaction_id) VALUES (?, 'Stripe', ?, 'Completed', ?)";
        $pdo->prepare($paySql)->execute([$order_id, $amount_paid, $stripe_txn_id]);

        // --- H. CLEAR CART ---
        $pdo->prepare("DELETE FROM cart_items WHERE cart_id = (SELECT cart_id FROM cart WHERE user_id = ?)")
            ->execute([$user_id]);

        $pdo->commit();
        unset($_SESSION['pending_order']);

    } else {
        $payment_success = false;
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<div class='container mt-5 alert alert-danger'>Error processing order: " . $e->getMessage() . "</div>";
    exit;
}

include '../includes/header.php';
?>

<link rel="stylesheet" href="../css/success.css">

<div class="success-wrapper">
    <?php if ($payment_success): ?>
        
        <div class="success-card">
            <i class="fas fa-check-circle icon-success"></i>
            
            <h1 class="success-title">Payment Successful!</h1>
            <p class="success-message">Thank you for your purchase.</p>
            <span class="order-ref">Order #<?php echo $order_id; ?></span>
            
            <br>
            <?php 
                $badgeClass = ($points_earned_now == 0) ? 'points-badge zero-points' : 'points-badge'; 
            ?>
            <div class="<?php echo $badgeClass; ?>">
                <i class="fas fa-coins"></i>
                <span>You earned <strong><?php echo $points_earned_now; ?></strong> points!</span>
            </div>
            
            <div class="action-buttons">
                <a href="orders.php" class="btn-home">View Order History</a>
                <?php if (file_exists('receipt.php')): ?>
                    <a href="receipt.php?order_id=<?php echo $order_id; ?>" target="_blank" class="btn-receipt">
                        <i class="fas fa-file-pdf"></i> Download Receipt
                    </a>
                <?php endif; ?>
                <a href="../index.php" class="link-back">&larr; Back to Shop</a>
            </div>
        </div>

    <?php else: ?>
        
        <div class="success-card" style="border-top-color: #dc3545;">
            <i class="fas fa-times-circle icon-success" style="color: #dc3545;"></i>
            <h1 class="success-title">Payment Failed</h1>
            <p class="success-message">We could not verify your payment.</p>
            <div class="action-buttons">
                <a href="cart.php" class="btn-home" style="background-color: #dc3545; box-shadow: 0 4px 6px rgba(220, 53, 69, 0.3);">
                    Return to Cart
                </a>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>