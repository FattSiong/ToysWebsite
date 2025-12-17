<?php
require_once 'vendor/autoload.php';
include 'includes/header.php';
include 'includes/navbar.php';

// STRIPE KEY
$stripeSecretKey = 'sk_test_51SeuhSEicrNwy8rsAMacjNRNiv1UvST0Tlpfo9CCmD7dZFuQZBJGx7dmC5WFPiugMkCvTmvlimnKB6Fps8AkhNAv00VUXMtoc8'; // REPLACE KEY
\Stripe\Stripe::setApiKey($stripeSecretKey);

$session_id = $_GET['session_id'] ?? null;
$order_id = $_GET['order_id'] ?? null;
$payment_success = false;
$points_earned_now = 0;

if ($session_id && $order_id) {
    try {
        $session = \Stripe\Checkout\Session::retrieve($session_id);

        if ($session->payment_status == 'paid') {
            $payment_success = true;

            // Fetch Order to prevent double processing
            $checkStmt = $pdo->prepare("SELECT order_status, total_amount FROM orders WHERE order_id = ?");
            $checkStmt->execute([$order_id]);
            $currentOrder = $checkStmt->fetch();

            if ($currentOrder && $currentOrder['order_status'] == 'Pending Payment') {
                $amount_paid = $currentOrder['total_amount']; // Use DB amount

                // --- POINT EARNING LOGIC ---
                // Earn 1 Point per $1 spent
                $points_earned_now = floor($amount_paid);

                // 1. Update Order (Status + Points Earned)
                $stmt = $pdo->prepare("UPDATE orders SET order_status = 'Processing', points_earned = ? WHERE order_id = ?");
                $stmt->execute([$points_earned_now, $order_id]);

                // 2. Add Points to User Account
                if (isset($_SESSION['user_id'])) {
                    $user_id = $_SESSION['user_id'];
                    $pdo->prepare("UPDATE users SET current_points = current_points + ? WHERE user_id = ?")->execute([$points_earned_now, $user_id]);

                    // Log History
                    $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Earned from Purchase')")
                        ->execute([$user_id, $order_id, $points_earned_now]);
                }

                // 3. Record Payment
                $txn_id = $session->payment_intent;
                $paySql = "INSERT INTO payments (order_id, payment_method, amount, status, transaction_id) VALUES (?, 'Stripe', ?, 'Completed', ?)";
                $pdo->prepare($paySql)->execute([$order_id, $amount_paid, $txn_id]);

                // 4. Clear Cart
                $pdo->prepare("DELETE FROM cart_items WHERE cart_id = (SELECT cart_id FROM cart WHERE user_id = ?)")->execute([$_SESSION['user_id']]);
            }
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}
?>

<div class="container mt-5 mb-5 text-center">
    <?php if ($payment_success): ?>
        <div class="card shadow-lg border-0 p-5 d-inline-block">
            <i class="fas fa-check-circle text-success display-1 mb-3"></i>
            <h1 class="text-success">Payment Successful!</h1>
            <p>Order #<?php echo $order_id; ?> confirmed.</p>

            <?php if ($points_earned_now > 0): ?>
                <div class="alert alert-warning d-inline-block mt-3 px-4">
                    <i class="fas fa-coins"></i> You earned <strong><?php echo $points_earned_now; ?></strong> points!
                </div>
            <?php endif; ?>

            <hr class="my-4">
            <a href="receipt.php?order_id=<?php echo $order_id; ?>" target="_blank" class="btn btn-dark">
                <i class="fas fa-file-pdf"></i> Download Receipt
            </a>
            <a href="orders.php" class="btn btn-primary">View Order History</a>
            <a href="index.php" class="btn btn-outline-secondary">Continue Shopping</a>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">Payment verification failed.</div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>