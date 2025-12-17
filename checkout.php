<?php 
require_once 'vendor/autoload.php'; 
include 'includes/header.php'; 
include 'includes/navbar.php'; 

// --- POINT SYSTEM CONFIGURATION ---
const POINTS_PER_DOLLAR_EARNED = 1; // Spend $1, get 1 Point
const POINTS_EXCHANGE_RATE = 0.10;  // 1 Point = $0.10 discount (10 points = $1)
const MIN_POINTS_THRESHOLD = 50;    // Must have 50 points to redeem

// STRIPE KEYS
$stripeSecretKey = 'sk_test_51SeuhSEicrNwy8rsAMacjNRNiv1UvST0Tlpfo9CCmD7dZFuQZBJGx7dmC5WFPiugMkCvTmvlimnKB6Fps8AkhNAv00VUXMtoc8'; // REPLACE WITH YOUR KEY
\Stripe\Stripe::setApiKey($stripeSecretKey);

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='index.php';</script>";
    exit;
}

$user_id = $_SESSION['user_id'];

// 1. Fetch User (And their Points)
$userStmt = $pdo->prepare("SELECT full_name, current_points FROM users WHERE user_id = ?");
$userStmt->execute([$user_id]);
$currentUser = $userStmt->fetch();
$current_points = $currentUser['current_points'];

// 2. Fetch Cart
$sql = "SELECT ci.*, p.name, p.price, p.product_id FROM cart c JOIN cart_items ci ON c.cart_id = ci.cart_id JOIN products p ON ci.product_id = p.product_id WHERE c.user_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

// 3. Calculate Cart Total
$cart_total = 0;
foreach($cart_items as $item) {
    $cart_total += ($item['price'] * $item['quantity']);
}

if (empty($cart_items)) {
    echo "<div class='alert alert-warning'>Cart is empty.</div>";
    include 'includes/footer.php';
    exit;
}

// 4. Handle "Use Points" Logic
$use_points = isset($_POST['use_points']); // Checkbox checked?
$discount_amount = 0;
$points_to_redeem = 0;

if ($use_points && $current_points >= MIN_POINTS_THRESHOLD) {
    // Calculate potential discount
    $max_discount = $current_points * POINTS_EXCHANGE_RATE;
    
    // Logic: Don't let discount be more than the total price
    if ($max_discount >= $cart_total) {
        $discount_amount = $cart_total; // Free order!
        $points_to_redeem = $cart_total / POINTS_EXCHANGE_RATE;
    } else {
        $discount_amount = $max_discount;
        $points_to_redeem = $current_points;
    }
}

$final_total = $cart_total - $discount_amount;

// 5. PROCESS ORDER
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['place_order'])) {
    try {
        $pdo->beginTransaction();

        // A. Save Address
        $addrSql = "INSERT INTO shipping_addresses (user_id, line1, line2, city, state, postcode, phone) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $pdo->prepare($addrSql)->execute([$user_id, $_POST['address_line1'], $_POST['address_line2'], $_POST['city'], $_POST['state'], $_POST['zip'], $_POST['phone']]);
        $shipping_id = $pdo->lastInsertId();

        // B. Create Order (Record points used and discount amount)
        $orderSql = "INSERT INTO orders (user_id, shipping_address_id, total_amount, order_status, points_redeemed, point_discount) VALUES (?, ?, ?, 'Pending Payment', ?, ?)";
        $pdo->prepare($orderSql)->execute([$user_id, $shipping_id, $final_total, $points_to_redeem, $discount_amount]);
        $order_id = $pdo->lastInsertId();

        // C. Save Items
        foreach ($cart_items as $item) {
            $oiSql = "INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, ?)";
            $pdo->prepare($oiSql)->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);
        }

        // D. DEDUCT POINTS FROM USER (Immediate deduction)
        if ($points_to_redeem > 0) {
            $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")->execute([$points_to_redeem, $user_id]);
            
            // Log Transaction
            $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Redeemed for Order')")
                ->execute([$user_id, $order_id, -$points_to_redeem]);
        }

        $pdo->commit();

        // E. STRIPE
        $domain = "http://localhost/ToysWebsite-main/app"; 
        
        $line_items_stripe = [];
        
        // Stripe requires line items. Since we applied a custom discount,
        // the easiest way is to create one custom line item for the "Total"
        // OR add the products and a coupon. 
        // SIMPLIFIED APPROACH: Just charge the final total as one item "Order #123"
        $line_items_stripe[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => ['name' => 'Order #' . $order_id],
                'unit_amount' => round($final_total * 100), // Cents
            ],
            'quantity' => 1,
        ];

        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $line_items_stripe,
            'mode' => 'payment',
            'success_url' => $domain . '/success.php?session_id={CHECKOUT_SESSION_ID}&order_id=' . $order_id,
            'cancel_url' => $domain . '/checkout.php?error=cancelled',
        ]);

        header("HTTP/1.1 303 See Other");
        header("Location: " . $checkout_session->url);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}
?>

<div class="container mt-4">
    <h2 class="mb-4">Checkout</h2>
    
    <!-- We need a form that wraps everything so the checkbox works -->
    <form method="post">
    <div class="row g-5">
        <!-- LEFT: Shipping Form -->
        <div class="col-md-7">
            <div class="card shadow-sm mb-3">
                <div class="card-header">Shipping Address</div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Inputs -->
                        <div class="col-12"><label>Full Name</label><input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($currentUser['full_name'] ?? ''); ?>" required></div>
                        <div class="col-12"><label>Phone</label><input type="text" class="form-control" name="phone" required></div>
                        <div class="col-12"><label>Address Line 1</label><input type="text" class="form-control" name="address_line1" required></div>
                        <div class="col-12"><label>Address Line 2</label><input type="text" class="form-control" name="address_line2"></div>
                        <div class="col-md-5"><label>City</label><input type="text" class="form-control" name="city" required></div>
                        <div class="col-md-4"><label>State</label><input type="text" class="form-control" name="state" required></div>
                        <div class="col-md-3"><label>Zip</label><input type="text" class="form-control" name="zip" required></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Order Summary -->
        <div class="col-md-5">
            <div class="card shadow-sm bg-light">
                <div class="card-body">
                    <h4 class="mb-3">Order Summary</h4>
                    <ul class="list-group mb-3">
                        <?php foreach($cart_items as $item): ?>
                        <li class="list-group-item d-flex justify-content-between lh-sm">
                            <div><h6 class="my-0"><?php echo htmlspecialchars($item['name']); ?></h6><small class="text-muted">x<?php echo $item['quantity']; ?></small></div>
                            <span class="text-muted">$<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                        </li>
                        <?php endforeach; ?>

                        <!-- POINTS SECTION -->
                        <li class="list-group-item bg-white">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span><i class="fas fa-coins text-warning"></i> Your Points:</span>
                                <strong><?php echo $current_points; ?></strong>
                            </div>
                            
                            <?php if ($current_points >= MIN_POINTS_THRESHOLD): ?>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="use_points" id="usePoints" value="1" 
                                           onchange="this.form.submit()" <?php echo $use_points ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="usePoints">Redeem Points</label>
                                </div>
                                <small class="text-muted">Use <?php echo $current_points; ?> points for discount.</small>
                            <?php else: ?>
                                <small class="text-muted">Earn <?php echo (MIN_POINTS_THRESHOLD - $current_points); ?> more points to redeem!</small>
                            <?php endif; ?>
                        </li>

                        <!-- TOTALS -->
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Subtotal</span>
                            <strong>$<?php echo number_format($cart_total, 2); ?></strong>
                        </li>
                        
                        <?php if($discount_amount > 0): ?>
                        <li class="list-group-item d-flex justify-content-between text-success">
                            <span>Point Discount</span>
                            <strong>-$<?php echo number_format($discount_amount, 2); ?></strong>
                        </li>
                        <?php endif; ?>

                        <li class="list-group-item d-flex justify-content-between">
                            <span class="h5">Total to Pay</span>
                            <strong class="h5">$<?php echo number_format($final_total, 2); ?></strong>
                        </li>
                    </ul>
                    
                    <button class="w-100 btn btn-primary btn-lg" type="submit" name="place_order">Place Order</button>
                </div>
            </div>
        </div>
    </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>