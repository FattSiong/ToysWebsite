<?php 
include 'includes/header.php'; 
include 'includes/navbar.php'; 

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    echo "<div class='container mt-5'><div class='alert alert-warning text-center'>Please login to view cart.</div></div>";
    include 'includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];

// --- HANDLE ACTIONS (UPDATE or REMOVE) ---

// A. Handle "Remove Item" (via GET link)
if (isset($_GET['remove'])) {
    $remove_id = $_GET['remove'];
    
    // Delete the specific item from the database
    // We strictly link to cart_items to ensure we only delete valid IDs
    $stmt = $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ?");
    $stmt->execute([$remove_id]);
    
    echo "<script>window.location.href='cart.php';</script>"; // Refresh page
    exit;
}

// B. Handle "Update Quantities" (via POST form)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_cart'])) {
    // $_POST['quantities'] is an array: [cart_item_id => new_number]
    if (isset($_POST['quantities'])) {
        foreach ($_POST['quantities'] as $item_id => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                // Check stock (Optional but recommended)
                // For now, we just update the quantity
                $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?");
                $stmt->execute([$qty, $item_id]);
            }
        }
    }
    echo "<div class='container mt-3'><div class='alert alert-success'>Cart updated!</div></div>";
}

// --- FETCH CART DATA ---
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

<div class="container mt-4">
    <h2 class="mb-4">Your Shopping Cart</h2>

    <?php if (empty($items)): ?>
        <div class="alert alert-info text-center py-5">
            <h4>Your cart is empty.</h4>
            <a href="index.php" class="btn btn-primary mt-3">Go Shopping</a>
        </div>
    <?php else: ?>
        
        <!-- START FORM (Wraps the table so we can submit changes) -->
        <form method="post" action="cart.php">
            
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
                                    <tr>
                                        <td class="p-3 align-middle">
                                            <div class="d-flex align-items-center">
                                                <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="..." style="width: 50px; height: 50px; object-fit: cover; margin-right: 15px; border-radius: 4px;">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                                    <small class="text-muted">In Stock: <?php echo $item['stock_quantity']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-3 align-middle">$<?php echo number_format($item['price'], 2); ?></td>
                                        
                                        <!-- QUANTITY INPUT -->
                                        <td class="p-3 align-middle">
                                            <!-- The name is an array: quantities[cart_item_id] -->
                                            <input type="number" 
                                                   name="quantities[<?php echo $item['cart_item_id']; ?>]" 
                                                   value="<?php echo $item['quantity']; ?>" 
                                                   min="1" 
                                                   max="<?php echo $item['stock_quantity']; ?>" 
                                                   class="form-control form-control-sm text-center">
                                        </td>
                                        
                                        <td class="p-3 align-middle text-end fw-bold">$<?php echo number_format($subtotal, 2); ?></td>
                                        
                                        <!-- REMOVE BUTTON -->
                                        <td class="p-3 align-middle text-center">
                                            <a href="cart.php?remove=<?php echo $item['cart_item_id']; ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Are you sure you want to remove this item?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- UPDATE BUTTON -->
                    <div class="d-flex justify-content-end mb-3">
                        <button type="submit" name="update_cart" class="btn btn-outline-primary">
                            <i class="fas fa-sync-alt"></i> Update Cart
                        </button>
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
                                <strong>$<?php echo number_format($total, 2); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-4">
                                <span class="h5">Total</span>
                                <span class="h5 text-primary">$<?php echo number_format($total, 2); ?></span>
                            </div>
                            <a href="checkout.php" class="btn btn-success w-100 btn-lg">Proceed to Checkout</a>
                            <a href="index.php" class="btn btn-outline-secondary w-100 mt-2">Continue Shopping</a>
                        </div>
                    </div>
                </div>
            </div>
            
        </form>
        <!-- END FORM -->
        
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>