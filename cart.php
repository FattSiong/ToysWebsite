<?php 
// 1. START SESSION & DB FIRST
require_once 'includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Check Login
if (!isset($_SESSION['user_id'])) {
    // Show header briefly to display error nicely
    include 'includes/header.php'; 
    include 'includes/navbar.php'; 
    echo "<div class='container mt-5'><div class='alert alert-warning text-center'>Please login to view cart.</div></div>";
    include 'includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];

// 2. HANDLE ACTIONS (REMOVE / CHECKOUT) BEFORE NAVBAR LOADS
if (isset($_GET['remove'])) {
    $remove_id = $_GET['remove'];
    $stmt = $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ?");
    $stmt->execute([$remove_id]);
    // Refresh to show updated count immediately
    header("Location: cart.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout'])) {
    if (isset($_POST['quantities'])) {
        foreach ($_POST['quantities'] as $item_id => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?");
                $stmt->execute([$qty, $item_id]);
            }
        }
    }
    header("Location: checkout.php");
    exit;
}

// 3. NOW INCLUDE NAVBAR
// Navbar will count items AFTER we deleted/updated them
include 'includes/header.php'; 
include 'includes/navbar.php'; 

// 4. FETCH DISPLAY DATA
$sql = "SELECT ci.cart_item_id, ci.quantity, p.name, p.price, p.product_id, p.stock_quantity, pm.file_path 
        FROM cart c 
        JOIN cart_items ci ON c.cart_id = ci.cart_id 
        JOIN products p ON ci.product_id = p.product_id 
        LEFT JOIN product_media pm ON p.product_id = pm.product_id AND pm.is_primary = 1
        WHERE c.user_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$items = $stmt->fetchAll();

$total = 0;
?>

<div class="container mt-4 mb-5">
    <h2 class="mb-4">Your Shopping Cart</h2>

    <?php if (empty($items)): ?>
        <div class="alert alert-info text-center py-5">
            <h4>Your cart is empty.</h4>
            <a href="index.php" class="btn btn-primary mt-3">Go Shopping</a>
        </div>
    <?php else: ?>
        
        <form method="post" action="cart.php" id="cartForm">
            <div class="row">
                <!-- Left Side: Items List -->
                <div class="col-lg-8">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="p-3">Product</th>
                                        <th class="p-3">Price</th>
                                        <th class="p-3" style="width: 150px;">Quantity</th>
                                        <th class="p-3 text-end">Subtotal</th>
                                        <th class="p-3 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): 
                                        $subtotal = $item['price'] * $item['quantity'];
                                        $total += $subtotal;
                                        $imgUrl = !empty($item['file_path']) ? $item['file_path'] : "https://via.placeholder.com/50";
                                    ?>
                                    <tr class="cart-row">
                                        <td class="p-3 align-middle">
                                            <div class="d-flex align-items-center">
                                                <img src="<?php echo htmlspecialchars($imgUrl); ?>" style="width: 50px; height: 50px; object-fit: cover; margin-right: 15px; border-radius: 4px;">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                                    <small class="text-muted">In Stock: <?php echo $item['stock_quantity']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-3 align-middle">
                                            <span class="unit-price" data-price="<?php echo $item['price']; ?>">
                                                RM <?php echo number_format($item['price'], 2); ?>
                                            </span>
                                        </td>
                                        <td class="p-3 align-middle">
                                            <input type="number" 
                                                   name="quantities[<?php echo $item['cart_item_id']; ?>]" 
                                                   value="<?php echo $item['quantity']; ?>" 
                                                   min="1" 
                                                   max="<?php echo $item['stock_quantity']; ?>" 
                                                   class="form-control form-control-sm text-center qty-input">
                                        </td>
                                        <td class="p-3 align-middle text-end fw-bold row-subtotal">
                                            RM <?php echo number_format($subtotal, 2); ?>
                                        </td>
                                        <td class="p-3 align-middle text-center">
                                            <a href="cart.php?remove=<?php echo $item['cart_item_id']; ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Remove this item?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Right Side: Summary -->
                <div class="col-lg-4">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h4 class="card-title">Order Summary</h4>
                            <hr>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Subtotal</span>
                                <strong id="cart-total-display">RM <?php echo number_format($total, 2); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-4">
                                <span class="h5">Total</span>
                                <span class="h5 text-primary" id="cart-grand-total">RM <?php echo number_format($total, 2); ?></span>
                            </div>
                            
                            <button type="submit" name="checkout" class="btn btn-success w-100 btn-lg">Proceed to Checkout</button>
                            <a href="index.php" class="btn btn-outline-secondary w-100 mt-2">Continue Shopping</a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- JAVASCRIPT FOR INSTANT CALCULATION -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const qtyInputs = document.querySelectorAll('.qty-input');
    qtyInputs.forEach(input => {
        input.addEventListener('input', function() {
            updateCartVisuals();
        });
    });

    function updateCartVisuals() {
        let grandTotal = 0;
        const rows = document.querySelectorAll('.cart-row');
        
        rows.forEach(row => {
            const priceElement = row.querySelector('.unit-price');
            const price = parseFloat(priceElement.getAttribute('data-price'));
            const qtyInput = row.querySelector('.qty-input');
            let qty = parseInt(qtyInput.value);
            if (isNaN(qty) || qty < 1) qty = 1;

            const subtotal = price * qty;
            row.querySelector('.row-subtotal').innerText = 'RM ' + subtotal.toFixed(2);
            grandTotal += subtotal;
        });

        document.getElementById('cart-total-display').innerText = 'RM ' + grandTotal.toFixed(2);
        document.getElementById('cart-grand-total').innerText = 'RM ' + grandTotal.toFixed(2);
    }
});
</script>

<?php include 'includes/footer.php'; ?>