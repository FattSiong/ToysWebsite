<?php 
require_once 'vendor/autoload.php'; 
include 'includes/header.php'; 
include 'includes/navbar.php'; 

// --- POINT SYSTEM CONFIGURATION ---
const POINTS_PER_DOLLAR_EARNED = 1; 
const POINTS_EXCHANGE_RATE = 0.10;  
const MIN_POINTS_THRESHOLD = 50;    

// STRIPE KEYS
$stripeSecretKey = 'sk_test_51SeuhSEicrNwy8rsAMacjNRNiv1UvST0Tlpfo9CCmD7dZFuQZBJGx7dmC5WFPiugMkCvTmvlimnKB6Fps8AkhNAv00VUXMtoc8';
\Stripe\Stripe::setApiKey($stripeSecretKey);

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='index.php';</script>";
    exit;
}

$user_id = $_SESSION['user_id'];

// 1. Fetch User (Using 'username' as name)
$userStmt = $pdo->prepare("SELECT username, current_points FROM users WHERE user_id = ?");
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
$use_points = isset($_POST['use_points']); 
$discount_amount = 0;
$points_to_redeem = 0;

if ($use_points && $current_points >= MIN_POINTS_THRESHOLD) {
    $max_discount = $current_points * POINTS_EXCHANGE_RATE;
    if ($max_discount >= $cart_total) {
        $discount_amount = $cart_total; 
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

        // B. Create Order 
        $orderSql = "INSERT INTO orders (user_id, shipping_address_id, total_amount, order_status, points_redeemed, point_discount) VALUES (?, ?, ?, 'Pending Payment', ?, ?)";
        $pdo->prepare($orderSql)->execute([$user_id, $shipping_id, $final_total, $points_to_redeem, $discount_amount]);
        $order_id = $pdo->lastInsertId();

        // C. Save Items
        foreach ($cart_items as $item) {
            $oiSql = "INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, ?)";
            $pdo->prepare($oiSql)->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);
        }

        // D. DEDUCT POINTS
        if ($points_to_redeem > 0) {
            $pdo->prepare("UPDATE users SET current_points = current_points - ? WHERE user_id = ?")->execute([$points_to_redeem, $user_id]);
            $pdo->prepare("INSERT INTO point_transactions (user_id, order_id, amount, description) VALUES (?, ?, ?, 'Redeemed for Order')")
                ->execute([$user_id, $order_id, -$points_to_redeem]);
        }

        $pdo->commit();

        // E. STRIPE
        $domain = "http://localhost/ToysWebsite-main/app"; 
        
        $line_items_stripe = [];
        $line_items_stripe[] = [
            'price_data' => [
                'currency' => 'myr', 
                'product_data' => ['name' => 'Order #' . $order_id],
                'unit_amount' => round($final_total * 100), 
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

<div class="container mt-4 mb-5">
    <h2 class="mb-4">Checkout</h2>
    
    <form method="post">
    <div class="row g-5">
        <!-- LEFT: Shipping Form -->
        <div class="col-md-7">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-primary text-white">Shipping Address</div>
                <div class="card-body text-start">
                    <div class="row g-3">
                        <!-- Name -->
                        <div class="col-12">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($currentUser['username'] ?? ''); ?>" required>
                        </div>
                        
                        <!-- Phone Number -->
                        <div class="col-12">
                            <label class="form-label">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text">+60</span>
                                <input type="tel" class="form-control" name="phone" placeholder="123456789" 
                                       pattern="^01[0-9]{8,9}$" 
                                       title="Please enter a valid Malaysian mobile number (e.g., 0123456789)." required>
                            </div>
                            <small class="text-muted">Format: 01xxxxxxxxx</small>
                        </div>

                        <!-- Address -->
                        <div class="col-12">
                            <label class="form-label">Address Line 1</label>
                            <input type="text" class="form-control" name="address_line1" placeholder="House No, Street Name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Unit / Floor <span class="text-muted">(Optional)</span></label>
                            <input type="text" class="form-control" name="address_line2" placeholder="Apartment, Suite, etc.">
                        </div>

                        <!-- City -->
                        <div class="col-md-5">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" required>
                        </div>

                        <!-- State Dropdown -->
                        <div class="col-md-4">
                            <label class="form-label">State</label>
                            <select class="form-select" name="state" required>
                                <option value="">Choose...</option>
                                <option value="Johor">Johor</option>
                                <option value="Kedah">Kedah</option>
                                <option value="Kelantan">Kelantan</option>
                                <option value="Melaka">Melaka</option>
                                <option value="Negeri Sembilan">Negeri Sembilan</option>
                                <option value="Pahang">Pahang</option>
                                <option value="Perak">Perak</option>
                                <option value="Perlis">Perlis</option>
                                <option value="Pulau Pinang">Pulau Pinang</option>
                                <option value="Sabah">Sabah</option>
                                <option value="Sarawak">Sarawak</option>
                                <option value="Selangor">Selangor</option>
                                <option value="Terengganu">Terengganu</option>
                                <option value="W.P. Kuala Lumpur">W.P. Kuala Lumpur</option>
                                <option value="W.P. Labuan">W.P. Labuan</option>
                                <option value="W.P. Putrajaya">W.P. Putrajaya</option>
                            </select>
                        </div>

                        <!-- Postcode -->
                        <div class="col-md-3">
                            <label class="form-label">Postcode</label>
                            <input type="text" class="form-control" name="zip" placeholder="53100" 
                                   pattern="\d{5}" 
                                   maxlength="5" 
                                   title="Please enter a valid 5-digit postcode" required>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Order Summary -->
        <div class="col-md-5">
            <div class="card shadow-sm bg-light">
                <div class="card-body text-start">
                    <h4 class="mb-3">Order Summary</h4>
                    <ul class="list-group mb-3">
                        <?php foreach($cart_items as $item): ?>
                        <li class="list-group-item d-flex justify-content-between lh-sm">
                            <div><h6 class="my-0"><?php echo htmlspecialchars($item['name']); ?></h6><small class="text-muted">x<?php echo $item['quantity']; ?></small></div>
                            <span class="text-muted">RM <?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
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
                            <strong>RM <?php echo number_format($cart_total, 2); ?></strong>
                        </li>
                        
                        <?php if($discount_amount > 0): ?>
                        <li class="list-group-item d-flex justify-content-between text-success">
                            <span>Point Discount</span>
                            <strong>-RM <?php echo number_format($discount_amount, 2); ?></strong>
                        </li>
                        <?php endif; ?>

                        <li class="list-group-item d-flex justify-content-between">
                            <span class="h5">Total to Pay</span>
                            <strong class="h5">RM <?php echo number_format($final_total, 2); ?></strong>
                        </li>
                    </ul>
                    
                    <!-- PLACE ORDER BUTTON WAS MISSING IN YOUR SCREENSHOT -->
                    <button class="w-100 btn btn-primary btn-lg mt-3" type="submit" name="place_order">
                        Proceed to Payment
                    </button>
                </div>
            </div>
        </div>
    </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>