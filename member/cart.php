<?php
require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Check Login
if (!isset($_SESSION['user_id'])) {
    include '../includes/header.php';
    echo "<div class='container mt-5'><div class='alert alert-warning text-center'>Please login to view cart.</div></div>";
    include '../includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle Remove
if (isset($_GET['remove'])) {
    $remove_id = $_GET['remove'];
    $stmt = $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ?");
    $stmt->execute([$remove_id]);
    header("Location: cart.php");
    exit;
}

// Handle Checkout / Update
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

include '../includes/header.php';

// Fetch Data
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

<link rel="stylesheet" href="../css/cart.css">

<div class="cart-wrapper">
    <h2 class="mb-4">Your Shopping Cart</h2>

    <?php if (empty($items)): ?>
        <div class="alert alert-info text-center py-5">
            <h4>Your cart is empty.</h4>
            <a href="../index.php" class="btn btn-primary mt-3">Go Shopping</a>
        </div>
    <?php else: ?>

        <form method="post" action="cart.php" id="cartForm">
            <div class="cart-grid">

                <div class="cart-list-section">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th style="text-align:right;">Subtotal</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item):
                                $subtotal = $item['price'] * $item['quantity'];
                                $total += $subtotal;
                                // Handle Image Path
                                $imgUrl = !empty($item['file_path']) ? $item['file_path'] : "https://via.placeholder.com/50";
                                if (strpos($imgUrl, 'http') !== 0 && strpos($imgUrl, '../') !== 0) {
                                    if (strpos($imgUrl, 'images/') === 0) $imgUrl = '../' . str_replace('images/', 'uploads/', $imgUrl);
                                    else $imgUrl = '../' . $imgUrl;
                                }
                            ?>
                                <tr class="cart-row">
                                    <td>
                                        <div class="cart-item-info">
                                            <img src="<?php echo htmlspecialchars($imgUrl); ?>" class="cart-img" alt="Product">
                                            <div>
                                                <span class="item-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                                <span class="item-stock">Stock: <?php echo $item['stock_quantity']; ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="unit-price" data-price="<?php echo $item['price']; ?>">
                                            RM <?php echo number_format($item['price'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <input type="number"
                                            name="quantities[<?php echo $item['cart_item_id']; ?>]"
                                            value="<?php echo $item['quantity']; ?>"
                                            min="1"
                                            max="<?php echo $item['stock_quantity']; ?>"
                                            class="qty-input">
                                    </td>
                                    <td style="text-align:right; font-weight:bold;" class="row-subtotal">
                                        RM <?php echo number_format($subtotal, 2); ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <a href="cart.php?remove=<?php echo $item['cart_item_id']; ?>"
                                            class="btn-delete"
                                            onclick="return confirm('Remove this item?');">
                                            <i class="icon fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="cart-summary-section">
                    <h4 class="summary-title">Order Summary</h4>

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <strong id="cart-total-display">RM <?php echo number_format($total, 2); ?></strong>
                    </div>

                    <div class="summary-row">
                        <span>Shipping</span>
                        <span>Free</span>
                    </div>

                    <div class="summary-total">
                        <span>Total</span>
                        <span style="color: #007bff;" id="cart-grand-total">RM <?php echo number_format($total, 2); ?></span>
                    </div>

                    <button type="submit" name="checkout" class="btn-checkout">Proceed to Checkout</button>
                    <a href="../index.php" class="btn-continue">Continue Shopping</a>
                </div>

            </div>
        </form>
    <?php endif; ?>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const qtyInputs = document.querySelectorAll('.qty-input');
        qtyInputs.forEach(input => {
            input.addEventListener('input', updateCartVisuals);
            input.addEventListener('change', updateCartVisuals);
        });

        function updateCartVisuals() {
            let grandTotal = 0;
            document.querySelectorAll('.cart-row').forEach(row => {
                const price = parseFloat(row.querySelector('.unit-price').getAttribute('data-price'));
                let qty = parseInt(row.querySelector('.qty-input').value);
                if (isNaN(qty) || qty < 1) qty = 1;

                const subtotal = price * qty;
                row.querySelector('.row-subtotal').innerText = 'RM ' + subtotal.toFixed(2);
                grandTotal += subtotal;
            });

            const formattedTotal = 'RM ' + grandTotal.toFixed(2);
            document.getElementById('cart-total-display').innerText = formattedTotal;
            document.getElementById('cart-grand-total').innerText = formattedTotal;
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
