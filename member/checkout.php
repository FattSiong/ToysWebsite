<?php
require_once '../vendor/autoload.php';
include '../includes/header.php';

// --- CONFIG ---
const POINTS_PER_DOLLAR_EARNED = 1;
const POINTS_EXCHANGE_RATE = 0.10;
const MIN_POINTS_THRESHOLD = 50;
const SHIPPING_COST = 10.00;         // Standard Fee
const FREE_SHIPPING_THRESHOLD = 100; // <--- ADDED: Free shipping if spend > 100

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

// 2. FETCH AVAILABLE VOUCHERS
$vSql = "SELECT vu.id AS usage_id, v.* FROM voucher_usage vu 
         JOIN vouchers v ON vu.voucher_id = v.voucher_id 
         WHERE vu.user_id = ? 
         AND vu.is_used = 0 
         AND (v.expiry_date IS NULL OR v.expiry_date >= CURDATE())";
$vStmt = $pdo->prepare($vSql);
$vStmt->execute([$user_id]);
$myVouchers = $vStmt->fetchAll();

// 3. FETCH CART
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

// =========================================================
// 4. VOUCHER, SHIPPING & POINTS CALCULATION LOGIC
// =========================================================

// A. Initialize Shipping (Check Threshold logic here)
$shipping_fee = SHIPPING_COST; 

// --- ADDED: Automatic Free Shipping Threshold ---
if ($cart_total > FREE_SHIPPING_THRESHOLD) {
    $shipping_fee = 0;
}

$selected_usage_id = isset($_POST['voucher_usage_id']) ? $_POST['voucher_usage_id'] : '';
$voucher_discount = 0;
$applied_voucher = null;

if (!empty($selected_usage_id)) {
    foreach ($myVouchers as $v) {
        if ($v['usage_id'] == $selected_usage_id) {
            // Check Min Spend for Voucher
            if ($cart_total >= $v['min_spend']) {
                $applied_voucher = $v;
                
                if ($v['discount_type'] == 'fixed') {
                    $voucher_discount = $v['discount_value'];
                } elseif ($v['discount_type'] == 'percentage') {
                    $voucher_discount = $cart_total * ($v['discount_value'] / 100);
                } else {
                    // Voucher overrides shipping fee to 0 (useful if cart < 100)
                    $shipping_fee = 0; 
                }
            }
            break;
        }
    }
}

// Cap discount at cart total
if ($voucher_discount > $cart_total) $voucher_discount = $cart_total;

// Calculate Total
$total_after_voucher = ($cart_total - $voucher_discount) + $shipping_fee;

// B. Points Calculation
$use_points = isset($_POST['use_points']);
$point_discount = 0;
$points_to_redeem = 0;

if ($use_points && $current_points >= MIN_POINTS_THRESHOLD) {
    $max_point_discount = $current_points * POINTS_EXCHANGE_RATE;
    
    if ($max_point_discount >= $total_after_voucher) {
        $point_discount = $total_after_voucher;
        $points_to_redeem = $total_after_voucher / POINTS_EXCHANGE_RATE;
    } else {
        $point_discount = $max_point_discount;
        $points_to_redeem = $current_points;
    }
}

$final_total = $total_after_voucher - $point_discount;

// =========================================================
// 5. PROCESS "PLACE ORDER"
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['place_order'])) {
    try {
        $orderData = [
            'user_id' => $user_id,
            'address_mode' => $_POST['address_mode'],
            'points_to_redeem' => $points_to_redeem,
            'point_discount'   => $point_discount,
            'voucher_usage_id' => $applied_voucher ? $applied_voucher['usage_id'] : null,
            'voucher_discount' => $voucher_discount,
            'shipping_fee'     => $shipping_fee,
            'final_total'      => $final_total,
            'cart_items'       => $cart_items
        ];

        // Address Handling
        if ($_POST['address_mode'] === 'saved' && !empty($_POST['selected_address_id'])) {
            $orderData['shipping_id'] = $_POST['selected_address_id'];
        } else {
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

        $_SESSION['pending_order'] = $orderData;

        // Bypass Payment if total is 0
        if ($final_total <= 0) {
            echo "<script>window.location.href='success.php?session_id=free_order_bypass';</script>";
            exit;
        }

        // Stripe
        $domain = "http://localhost:8001/member"; 
        
        $line_items_stripe = [[
            'price_data' => [
                'currency' => 'myr',
                'product_data' => ['name' => 'Order Total (Inc. Shipping)'],
                'unit_amount' => round($final_total * 100),
            ],
            'quantity' => 1,
        ]];

        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $line_items_stripe,
            'mode' => 'payment',
            'success_url' => $domain . '/success.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $domain . '/checkout.php?error=cancelled',
        ]);

        header("Location: " . $checkout_session->url);
        exit;
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}
?>

<link rel="stylesheet" href="../css/checkout.css">
<style>
    /* ... (Keep your existing CSS styles) ... */
    .voucher-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
        margin-bottom: 15px;
        max-height: 300px;
        overflow-y: auto;
        padding-right: 5px;
    }
    .voucher-card {
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        padding: 10px 15px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fff;
        transition: 0.2s;
        position: relative;
    }
    .voucher-card:hover {
        border-color: #b0b0b0;
        background: #f9f9f9;
    }
    .voucher-card input[type="radio"] {
        display: none;
    }
    .voucher-card.selected {
        border-color: #0d6efd;
        background-color: #f0f7ff;
    }
    .voucher-card.selected::after {
        content: '\f00c';
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        position: absolute;
        top: 5px;
        right: 5px;
        color: #0d6efd;
        font-size: 12px;
    }
    .voucher-card.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        background: #f5f5f5;
        border-color: #eee;
    }
    
    .v-blue { border-left: 5px solid #17a2b8; }
    .v-orange { border-left: 5px solid #fd7e14; }
    .v-red { border-left: 5px solid #dc3545; }
    .v-gray { border-left: 5px solid #6c757d; }

    .v-info h6 { margin: 0; font-size: 14px; font-weight: bold; color: #333; }
    .v-info small { color: #666; font-size: 12px; }
    .v-icon { font-size: 18px; margin-right: 15px; width: 25px; text-align: center; }
    
    .text-blue { color: #17a2b8; }
    .text-orange { color: #fd7e14; }
    .text-red { color: #dc3545; }
</style>

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

                <hr>

                <div style="margin-bottom: 20px;">
                    <h4 style="font-size: 16px; margin-bottom: 10px; margin-top: 10px;">Select a Voucher</h4>
                    
                    <div class="voucher-grid">
                        <label class="voucher-card v-gray <?php echo empty($selected_usage_id) ? 'selected' : ''; ?>">
                            <input type="radio" name="voucher_usage_id" value="" 
                                onchange="this.form.submit()" 
                                <?php echo empty($selected_usage_id) ? 'checked' : ''; ?>>
                            <div style="display:flex; align-items:center;">
                                <i class="fas fa-ban v-icon text-muted"></i>
                                <div class="v-info">
                                    <h6>Don't use voucher</h6>
                                </div>
                            </div>
                        </label>

                        <?php if (empty($myVouchers)): ?>
                            <p class="text-muted" style="font-size:0.9rem; font-style:italic;">No vouchers available.</p>
                        <?php else: ?>
                            <?php foreach($myVouchers as $v): 
                                $isDisabled = ($cart_total < $v['min_spend']);
                                $isSelected = ($v['usage_id'] == $selected_usage_id);
                                
                                // Styles
                                $colorClass = 'v-red';
                                $iconClass = 'fa-money-bill-wave';
                                $textClass = 'text-red';
                                
                                if($v['discount_type'] == 'percentage') {
                                    $colorClass = 'v-orange';
                                    $iconClass = 'fa-percent';
                                    $textClass = 'text-orange';
                                    $desc = intval($v['discount_value']) . '% OFF';
                                } elseif($v['discount_type'] == 'fixed') {
                                    $desc = 'RM' . intval($v['discount_value']) . ' OFF';
                                } else {
                                    $colorClass = 'v-blue';
                                    $iconClass = 'fa-truck';
                                    $textClass = 'text-blue';
                                    $desc = 'Free Shipping';
                                }
                            ?>
                            <label class="voucher-card <?php echo $colorClass; ?> <?php echo $isSelected ? 'selected' : ''; ?> <?php echo $isDisabled ? 'disabled' : ''; ?>">
                                <input type="radio" name="voucher_usage_id" value="<?php echo $v['usage_id']; ?>" 
                                    onchange="this.form.submit()" 
                                    <?php echo $isSelected ? 'checked' : ''; ?>
                                    <?php echo $isDisabled ? 'disabled' : ''; ?>>
                                
                                <div style="display:flex; align-items:center;">
                                    <i class="fas <?php echo $iconClass; ?> v-icon <?php echo $textClass; ?>"></i>
                                    <div class="v-info">
                                        <h6><?php echo $desc; ?></h6>
                                        <small><?php echo $isDisabled ? "Min Spend RM " . $v['min_spend'] : $v['code']; ?></small>
                                    </div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="points-section">
                    <div class="points-header">
                        <span><i class="fas fa-coins text-warning"></i> Available Points:</span>
                        <strong><?php echo $current_points; ?></strong>
                    </div>
                    <?php if ($current_points >= MIN_POINTS_THRESHOLD): ?>
                        <div class="points-toggle">
                            <input class="switch-input" type="checkbox" name="use_points" id="usePoints" value="1"
                                onchange="this.form.submit()" 
                                <?php echo $use_points ? 'checked' : ''; ?>
                                <?php echo ($total_after_voucher <= 0) ? 'disabled' : ''; ?>>
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

                <div class="summary-row" style="margin-top: 15px;">
                    <span>Subtotal</span>
                    <strong>RM <?php echo number_format($cart_total, 2); ?></strong>
                </div>

                <div class="summary-row">
                    <span>Shipping Fee</span>
                    <?php if ($shipping_fee == 0): ?>
                        <strong style="color: #28a745;">FREE</strong>
                    <?php else: ?>
                        <strong>RM <?php echo number_format($shipping_fee, 2); ?></strong>
                    <?php endif; ?>
                </div>

                <?php if ($cart_total < FREE_SHIPPING_THRESHOLD): ?>
                    <div style="font-size: 0.85rem; color: #17a2b8; margin-top: -10px; margin-bottom: 10px; text-align: right;">
                        <i class="fas fa-info-circle"></i> Spend <strong>RM <?php echo number_format(FREE_SHIPPING_THRESHOLD - $cart_total, 2); ?></strong> more for Free Shipping!
                    </div>
                <?php else: ?>
                     <div style="font-size: 0.85rem; color: #28a745; margin-top: -10px; margin-bottom: 10px; text-align: right;">
                        <i class="fas fa-check-circle"></i> Free Shipping Qualification Met!
                    </div>
                <?php endif; ?>

                <?php if ($voucher_discount > 0): ?>
                    <div class="summary-row discount-text" style="color: #28a745;">
                        <span><i class="fas fa-tag"></i> Voucher</span>
                        <strong>- RM <?php echo number_format($voucher_discount, 2); ?></strong>
                    </div>
                <?php endif; ?>

                <?php if ($point_discount > 0): ?>
                    <div class="summary-row discount-text">
                        <span><i class="fas fa-coins"></i> Points Redeem</span>
                        <strong>- RM <?php echo number_format($point_discount, 2); ?></strong>
                    </div>
                <?php endif; ?>

                <div class="total-row">
                    <span>Total</span>
                    <span>RM <?php echo number_format($final_total, 2); ?></span>
                </div>

                <button type="submit" name="place_order" class="btn-place-order">
                    Place Order <?php echo ($final_total > 0) ? '& Pay' : ''; ?>
                </button>
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
                if(sectionSaved) sectionSaved.style.display = 'none';
                if(sectionNew) sectionNew.style.display = 'block';
                newFields.forEach(field => field.setAttribute('required', 'required'));
            } else {
                if(sectionSaved) sectionSaved.style.display = 'block';
                if(sectionNew) sectionNew.style.display = 'none';
                newFields.forEach(field => field.removeAttribute('required'));
            }
        }
        if (tabSaved) tabSaved.addEventListener('change', updateView);
        if (tabNew) tabNew.addEventListener('change', updateView);
        updateView();
    });
</script>

<?php include '../includes/footer.php'; ?>