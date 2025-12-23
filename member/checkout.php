<?php
require_once '../vendor/autoload.php';
include '../includes/header.php';

// --- CONFIG ---
const POINTS_PER_DOLLAR_EARNED = 1;
const POINTS_EXCHANGE_RATE = 0.10;
const MIN_POINTS_THRESHOLD = 50;

// STRIPE KEYS
$stripeSecretKey = 'sk_test_51SeuhSEicrNwy8rsAMacjNRNiv1UvST0Tlpfo9CCmD7dZFuQZBJGx7dmC5WFPiugMkCvTmvlimnKB6Fps8AkhNAv00VUXMtoc8';
\Stripe\Stripe::setApiKey($stripeSecretKey);

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href='../login.php';</script>";
    exit;
}
$user_id = $_SESSION['user_id'];

// 1. FETCH USER & SAVED ADDRESSES
$userStmt = $pdo->prepare("SELECT username, phone_number, current_points FROM users WHERE user_id = ?");
$userStmt->execute([$user_id]);
$currentUser = $userStmt->fetch();
$current_points = $currentUser['current_points'] ?? 0;

$addrStmt = $pdo->prepare("SELECT * FROM shipping_addresses WHERE user_id = ? ORDER BY is_default DESC, shipping_address_id DESC");
$addrStmt->execute([$user_id]);
$savedAddresses = $addrStmt->fetchAll();

// 2. FETCH CART
$sql = "SELECT ci.*, p.name, p.price, p.product_id FROM cart c JOIN cart_items ci ON c.cart_id = ci.cart_id JOIN products p ON ci.product_id = p.product_id WHERE c.user_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

$cart_total = 0;
foreach ($cart_items as $item) $cart_total += ($item['price'] * $item['quantity']);

if (empty($cart_items)) {
    echo "<div class='alert alert-warning'>Cart is empty.</div>";
    include '../includes/footer.php';
    exit;
}

// 3. POINTS LOGIC
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

// =========================================================
// 4. PROCESS "PLACE ORDER" (MODIFIED LOGIC)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['place_order'])) {
    try {
        // --- STEP A: SAVE DATA TO SESSION (DO NOT WRITE TO DB YET) ---

        $orderData = [
            'user_id' => $user_id,
            'address_mode' => $_POST['address_mode'],
            'points_to_redeem' => $points_to_redeem,
            'discount_amount'  => $discount_amount,
            'final_total'      => $final_total,
            'cart_items'       => $cart_items // We save cart state just in case
        ];

        // Handle Address Data
        if ($_POST['address_mode'] === 'saved' && !empty($_POST['selected_address_id'])) {
            $orderData['shipping_id'] = $_POST['selected_address_id'];
        } else {
            // Store New Address Fields to insert later
            $orderData['new_address'] = [
                'recipient_name' => $_POST['receiver_name'],
                'line1' => $_POST['address_line1'],
                'line2' => $_POST['address_line2'],
                'city'  => $_POST['city'],
                'state' => $_POST['state'],
                'postcode' => $_POST['zip'],
                'phone' => $_POST['phone'],
                'is_default' => (empty($savedAddresses) ? 1 : 0)
            ];
        }

        // Save to Session
        $_SESSION['pending_order'] = $orderData;

        // --- STEP B: CREATE STRIPE SESSION ---
        $domain = "http://localhost:8001/member"; // Adjust to your actual path

        $line_items_stripe = [[
            'price_data' => [
                'currency' => 'myr',
                'product_data' => ['name' => 'ToysWebsite Order'], // Generic name since we are aggregating total
                'unit_amount' => round($final_total * 100),
            ],
            'quantity' => 1,
        ]];

        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $line_items_stripe,
            'mode' => 'payment',
            // Pass session_id to success page to verify
            'success_url' => $domain . '/success.php?session_id={CHECKOUT_SESSION_ID}&order_id=' . $order_id,
            'cancel_url' => $domain . '/checkout.php?error=cancelled',
        ]);

        header("HTTP/1.1 303 See Other");
        header("Location: " . $checkout_session->url);
        exit;
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}
?>

<link rel="stylesheet" href="../css/checkout.css">

<div class="checkout-wrapper">
    <form method="post" id="checkoutForm">
        <div class="checkout-grid">

            <div class="checkout-form-section">
                <h3 class="section-heading"><i class="fas fa-truck"></i> Shipping Details</h3>

                <div class="address-tabs">
                    <input type="radio" name="address_mode" value="saved" id="tab-saved" class="tab-radio"
                        <?php echo !empty($savedAddresses) ? 'checked' : ''; ?>
                        <?php echo empty($savedAddresses) ? 'disabled' : ''; ?>>
                    <label for="tab-saved" class="tab-label">My Addresses</label>

                    <input type="radio" name="address_mode" value="new" id="tab-new" class="tab-radio"
                        <?php echo empty($savedAddresses) ? 'checked' : ''; ?>>
                    <label for="tab-new" class="tab-label">Add New Address</label>
                </div>

                <div id="section-saved" class="address-section">
                    <?php if (!empty($savedAddresses)): ?>
                        <div class="saved-address-grid">
                            <?php foreach ($savedAddresses as $idx => $addr):
                                $isChecked = ($addr['is_default'] == 1 || $idx === 0) ? 'checked' : '';
                                $display_name = !empty($addr['receiver_name']) ? $addr['receiver_name'] : $currentUser['username'];
                            ?>
                                <label class="address-card-label">
                                    <input type="radio" name="selected_address_id" value="<?php echo $addr['shipping_address_id']; ?>"
                                        class="address-card-input" <?php echo $isChecked; ?>>
                                    <div class="address-card">
                                        <div class="addr-header">
                                            <span><?php echo htmlspecialchars($display_name); ?></span>
                                            <?php if ($addr['is_default']): ?>
                                                <span class="badge-default">Default</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="addr-details">
                                            <?php echo htmlspecialchars($addr['phone']); ?><br>
                                            <?php echo htmlspecialchars($addr['line1']); ?>
                                            <?php echo !empty($addr['line2']) ? ", " . htmlspecialchars($addr['line2']) : ""; ?><br>
                                            <?php echo htmlspecialchars($addr['postcode'] . " " . $addr['city'] . ", " . $addr['state']); ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No saved addresses found.</p>
                    <?php endif; ?>
                </div>

                <div id="section-new" class="address-section">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Receiver Name</label>
                            <input type="text" class="form-input new-addr-field" name="receiver_name"
                                value="<?php echo htmlspecialchars($currentUser['username']); ?>"
                                placeholder="e.g. John Doe">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-input new-addr-field" name="phone" placeholder="0123456789"
                                value="<?php echo htmlspecialchars($currentUser['phone_number'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Address Line 1</label>
                        <input type="text" class="form-input new-addr-field" name="address_line1" placeholder="House No, Street Name">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Address Line 2 (Optional)</label>
                        <input type="text" class="form-input" name="address_line2" placeholder="Apartment, Suite, etc.">
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">City</label>
                            <input type="text" class="form-input new-addr-field" name="city">
                        </div>
                        <div class="form-group">
                            <label class="form-label">State</label>
                            <select class="form-select new-addr-field" name="state">
                                <option value="">Select State...</option>
                                <?php
                                $states = ["Johor", "Kedah", "Kelantan", "Melaka", "Negeri Sembilan", "Pahang", "Perak", "Perlis", "Pulau Pinang", "Sabah", "Sarawak", "Selangor", "Terengganu", "W.P. Kuala Lumpur", "W.P. Labuan", "W.P. Putrajaya"];
                                foreach ($states as $st) echo "<option value='$st'>$st</option>";
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="width: 50%;">
                        <label class="form-label">Postcode</label>
                        <input type="text" class="form-input new-addr-field" name="zip" placeholder="53100" pattern="\d{5}" maxlength="5">
                    </div>
                </div>
            </div>

            <div class="checkout-summary-card">
                <h3 class="section-heading"><i class="fas fa-shopping-cart"></i> Order Summary</h3>
                <div class="summary-items-list">
                    <?php foreach ($cart_items as $item): ?>
                        <div class="summary-item">
                            <div>
                                <h6><?php echo htmlspecialchars($item['name']); ?></h6>
                                <small>Qty: <?php echo $item['quantity']; ?></small>
                            </div>
                            <div class="item-price">RM <?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="points-section">
                    <div class="points-header">
                        <span><i class="fas fa-coins text-warning"></i> Available Points:</span>
                        <strong><?php echo $current_points; ?></strong>
                    </div>
                    <?php if ($current_points >= MIN_POINTS_THRESHOLD): ?>
                        <div class="points-toggle">
                            <input class="switch-input" type="checkbox" name="use_points" id="usePoints" value="1"
                                onchange="this.form.submit()" <?php echo $use_points ? 'checked' : ''; ?>>
                            <label class="switch-label" for="usePoints">Toggle</label>
                            <span>Redeem Points</span>
                        </div>
                        <div style="font-size:0.85rem; color:#666; margin-top:5px;">
                            (Max discount: RM <?php echo number_format($current_points * POINTS_EXCHANGE_RATE, 2); ?>)
                        </div>
                    <?php else: ?>
                        <small class="text-muted">Need <?php echo (MIN_POINTS_THRESHOLD - $current_points); ?> more.</small>
                    <?php endif; ?>
                </div>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <strong>RM <?php echo number_format($cart_total, 2); ?></strong>
                </div>
                <?php if ($discount_amount > 0): ?>
                    <div class="summary-row discount-text">
                        <span>Points Discount</span>
                        <strong>- RM <?php echo number_format($discount_amount, 2); ?></strong>
                    </div>
                <?php endif; ?>
                <div class="total-row">
                    <span>Total</span>
                    <span>RM <?php echo number_format($final_total, 2); ?></span>
                </div>

                <button type="submit" name="place_order" class="btn-place-order">Place Order & Pay</button>
            </div>
        </div>
    </form>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const tabSaved = document.getElementById('tab-saved');
        const tabNew = document.getElementById('tab-new');

        const sectionSaved = document.getElementById('section-saved');
        const sectionNew = document.getElementById('section-new');

        const newFields = document.querySelectorAll('.new-addr-field');

        function updateView() {
            const isNew = tabNew ? tabNew.checked : true;

            if (isNew) {
                if (sectionSaved) sectionSaved.style.display = 'none';
                if (sectionNew) sectionNew.style.display = 'block';
                newFields.forEach(field => field.setAttribute('required', 'required'));
            } else {
                if (sectionSaved) sectionSaved.style.display = 'block';
                if (sectionNew) sectionNew.style.display = 'none';
                newFields.forEach(field => field.removeAttribute('required'));
            }
        }

        if (tabSaved) tabSaved.addEventListener('change', updateView);
        if (tabNew) tabNew.addEventListener('change', updateView);

        updateView();
    });
</script>

<?php include '../includes/footer.php'; ?>