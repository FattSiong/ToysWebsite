<?php
require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$product_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
$msg = "";

// --- HELPER: Fix Image Paths ---
function getSafePath($path)
{
    if (empty($path)) return "https://via.placeholder.com/500?text=No+Image";
    if (strpos($path, 'http') === 0) return $path;
    if (strpos($path, 'images/products/') !== false) $path = str_replace('images/products/', 'uploads/products/', $path);
    if (strpos($path, '/') === false) $path = 'uploads/products/' . $path;
    if (strpos($path, '../') !== 0) $path = '../' . $path;
    return $path;
}

// --- 1. FETCH PRODUCT & MEDIA ---
$sql = "SELECT * FROM products WHERE product_id = ? AND status = 'Active'";
$stmt = $pdo->prepare($sql);
$stmt->execute([$product_id]);
$product = $stmt->fetch();

$mediaList = [];
$jsMediaData = []; 

if ($product) {
    $mStmt = $pdo->prepare("SELECT * FROM product_media WHERE product_id = ? ORDER BY is_primary DESC");
    $mStmt->execute([$product_id]);
    $mediaList = $mStmt->fetchAll();

    foreach ($mediaList as $m) {
        $ext = pathinfo($m['file_path'], PATHINFO_EXTENSION);
        $jsMediaData[] = [
            'src' => getSafePath($m['file_path']),
            'type' => in_array(strtolower($ext), ['mp4', 'webm', 'ogg']) ? 'vid' : 'img'
        ];
    }
}

if (empty($jsMediaData)) {
    $jsMediaData[] = ['src' => 'https://via.placeholder.com/500?text=No+Image', 'type' => 'img'];
}

// --- 2. HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $product) {
    if (!$user_id) {
        echo "<script>window.location.href='../login.php';</script>";
        exit;
    }

    // Toggle Wishlist
    if (isset($_POST['toggle_wishlist'])) {
        $check = $pdo->prepare("SELECT wishlist_id FROM wishlist WHERE user_id=? AND product_id=?");
        $check->execute([$user_id, $product_id]);
        if ($check->rowCount() > 0) {
            $pdo->prepare("DELETE FROM wishlist WHERE user_id=? AND product_id=?")->execute([$user_id, $product_id]);
        } else {
            $pdo->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?,?)")->execute([$user_id, $product_id]);
        }
        header("Location: product_detail.php?id=$product_id");
        exit;
    }

    // Add Cart
    if (isset($_POST['add_to_cart'])) {
        $qty = max(1, (int)$_POST['quantity']);
        
        // Check/Create Cart
        $cCheck = $pdo->prepare("SELECT cart_id FROM cart WHERE user_id=?");
        $cCheck->execute([$user_id]);
        $cart = $cCheck->fetch();
        if (!$cart) {
            $pdo->prepare("INSERT INTO cart (user_id) VALUES (?)")->execute([$user_id]);
            $cart_id = $pdo->lastInsertId();
        } else {
            $cart_id = $cart['cart_id'];
        }

        // Add Item
        $iCheck = $pdo->prepare("SELECT cart_item_id, quantity FROM cart_items WHERE cart_id=? AND product_id=?");
        $iCheck->execute([$cart_id, $product_id]);
        $existing = $iCheck->fetch();

        if ($existing) {
            $newQty = $existing['quantity'] + $qty;
            $pdo->prepare("UPDATE cart_items SET quantity=? WHERE cart_item_id=?")->execute([$newQty, $existing['cart_item_id']]);
        } else {
            $pdo->prepare("INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?,?,?)")->execute([$cart_id, $product_id, $qty]);
        }
        $msg = "<div class='alert alert-success text-center'>Added to cart!</div>";
    }
}

include '../includes/header.php';

if (!$product) {
    echo "<div class='container mt-5 text-center'><h2>Product Not Found</h2></div>";
    include '../includes/footer.php';
    exit;
}

// --- 3. REVIEWS & STATS ---
$in_wishlist = false;
if ($user_id) {
    $wCheck = $pdo->prepare("SELECT wishlist_id FROM wishlist WHERE user_id=? AND product_id=?");
    $wCheck->execute([$user_id, $product_id]);
    if ($wCheck->rowCount() > 0) $in_wishlist = true;
}

$rStmt = $pdo->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM reviews WHERE product_id=?");
$rStmt->execute([$product_id]);
$stats = $rStmt->fetch();
$avg_rating = round($stats['avg'] ?? 0, 1);
$total_reviews = $stats['cnt'];

// --- UPDATED SQL: Fetch Profile Image ---
$stmtReviews = $pdo->prepare("
    SELECT r.*, u.username, u.profile_photo 
    FROM reviews r 
    JOIN users u ON r.user_id = u.user_id 
    WHERE r.product_id = ? 
    ORDER BY r.created_at DESC
");
$stmtReviews->execute([$product_id]);
$reviews = $stmtReviews->fetchAll();

// Helper for Stars
function showStars($r) {
    $out = '';
    $r = round($r * 2) / 2;
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $r) $out .= '<i class="icon fas fa-star star-active"></i>';
        elseif ($i - 0.5 <= $r) $out .= '<i class="icon fas fa-star-half-alt star-active"></i>';
        else $out .= '<i class="icon fas fa-star star-inactive"></i>';
    }
    return $out;
}
?>

<link rel="stylesheet" href="../css/product_detail.css">

<div class="pd-wrapper">
    <?php echo $msg; ?>
    <a href="../index.php" class="btn-back" style="display:block; margin-bottom:20px; text-decoration:none; color:#666;">
        <i class="icon fas fa-arrow-left"></i> Back to Shop
    </a>

    <div class="pd-grid">
        <div class="pd-media-column">
            <div class="main-image-box">
                <?php if (count($jsMediaData) > 1): ?>
                    <button class="nav-btn prev-btn" onclick="moveMedia(-1)">&#10094;</button>
                    <button class="nav-btn next-btn" onclick="moveMedia(1)">&#10095;</button>
                <?php endif; ?>

                <div id="mediaContent" style="width:100%; height:100%; display:flex; align-items:center; justify-content:center;">
                    </div>
            </div>

            <?php if (count($mediaList) > 1): ?>
                <div class="thumbnail-strip">
                    <?php foreach ($jsMediaData as $idx => $m): ?>
                        <div class="thumb-box" id="thumb-<?php echo $idx; ?>" onclick="setMediaIndex(<?php echo $idx; ?>)">
                            <?php if ($m['type'] === 'vid'): ?>
                                <video src="<?php echo $m['src']; ?>#t=0.1" preload="metadata"></video>
                            <?php else: ?>
                                <img src="<?php echo $m['src']; ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="pd-info-column">
            <h1 class="pd-title"><?php echo htmlspecialchars($product['name']); ?></h1>

            <div class="pd-meta">
                <span><?php echo showStars($avg_rating); ?> <small>(<?php echo $avg_rating; ?>)</small></span>
                <span><?php echo $total_reviews; ?> Reviews</span>

                <?php if ($product['stock_quantity'] > 0): ?>
                    <span style="color: green;"><i class="icon fas fa-check-circle"></i> In Stock</span>
                <?php else: ?>
                    <span style="color: red;"><i class="icon fas fa-times-circle"></i> Out of Stock</span>
                <?php endif; ?>
            </div>

            <div class="pd-price">RM <?php echo number_format($product['price'], 2); ?></div>

            <div class="pd-description">
                <div class="desc-content" id="descContent">
                    <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                </div>
            </div>

            <button type="button" id="toggleDescBtn" class="toggle-desc-btn" onclick="toggleDescription()">
                Read More <i class="icon fas fa-chevron-down" style="font-size: 0.8em;"></i>
            </button>

            <?php if ($product['stock_quantity'] > 0): ?>
                <form method="post" class="pd-actions">
                    <div class="qty-wrapper">
                        <span class="qty-label">Qty</span>
                        <input type="number" name="quantity" value="1" min="1" max="<?php echo $product['stock_quantity']; ?>">
                    </div>

                    <button type="submit" name="add_to_cart" class="btn-add">Add to Cart</button>

                    <button type="submit" name="toggle_wishlist" class="btn-wish <?php echo $in_wishlist ? 'active' : ''; ?>" title="Wishlist">
                        <i class="icon fas fa-heart"></i>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="reviews-section">
        <h3>Customer Reviews</h3>

        <?php if (empty($reviews)): ?>
            <div style="padding: 40px; text-align: center; background: #f9f9f9; border-radius: 12px;">
                <p class="text-muted mb-0">No reviews yet. Be the first to review this product!</p>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $rev): ?>
                <div class="review-item">
                    
                    <div class="review-header">
                        <div class="d-flex align-items-center">
                            <div style="width: 40px; height: 40px; border-radius: 50%; overflow: hidden; margin-right: 12px; background: #ddd; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold;">
                                <?php 
                                    // Name Logic
                                    $displayName = !empty($rev['username']) ? $rev['username'] : 'Customer';
                                    
                                    // Image Logic
                                    $hasImage = !empty($rev['profile_image']);
                                    $imagePath = $hasImage ? "../images/profiles/" . $rev['profile_image'] : "";
                                ?>

                                <?php if ($hasImage && file_exists($imagePath)): ?>
                                    <img src="<?php echo htmlspecialchars($imagePath); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($displayName, 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <span class="user-name"><?php echo htmlspecialchars($displayName); ?></span>
                        </div>
                        <span class="review-date"><?php echo date('d M Y', strtotime($rev['created_at'])); ?></span>
                    </div>

                    <div class="star-rating-display">
                        <?php 
                            for ($i = 1; $i <= 5; $i++) {
                                echo ($i <= $rev['rating']) ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>';
                            }
                        ?>
                    </div>

                    <div class="review-content">
                        <div class="review-text collapsed" id="review-text-<?php echo $rev['review_id']; ?>">
                            <?php echo nl2br(htmlspecialchars($rev['comment'])); ?>
                        </div>
                        
                        <button class="read-more-btn" 
                            id="btn-<?php echo $rev['review_id']; ?>" 
                            onclick="toggleReview(<?php echo $rev['review_id']; ?>)">
                            Read More
                        </button>
                    </div>

                    <?php 
                        $stmtMedia = $pdo->prepare("SELECT * FROM review_media WHERE review_id = ?");
                        $stmtMedia->execute([$rev['review_id']]);
                        $revMedia = $stmtMedia->fetchAll();
                    ?>

                    <?php if (!empty($revMedia)): ?>
                        <div class="review-media-grid">
                            <?php foreach ($revMedia as $rm): 
                                $fileUrl = $rm['file_path'];
                                $type = $rm['media_type'];
                            ?>
                                <?php if ($type == 'video'): ?>
                                    <video class="media-thumbnail" 
                                           src="<?php echo htmlspecialchars($fileUrl); ?>" 
                                           onclick="openMediaModal('<?php echo htmlspecialchars($fileUrl); ?>', 'video')">
                                    </video>
                                <?php else: ?>
                                    <img class="media-thumbnail" 
                                         src="<?php echo htmlspecialchars($fileUrl); ?>" 
                                         onclick="openMediaModal('<?php echo htmlspecialchars($fileUrl); ?>', 'image')">
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div id="mediaLightbox" class="media-lightbox" onclick="closeMediaModal(event)">
    <button class="lightbox-close" onclick="closeMediaModal(event)">&times;</button>
    <div id="lightboxContainer" style="display:flex; justify-content:center; align-items:center; width:100%;"></div>
</div>

<script>
    // --- 1. PRODUCT DESCRIPTION TOGGLE ---
    document.addEventListener("DOMContentLoaded", function() {
        const content = document.getElementById('descContent');
        const btn = document.getElementById('toggleDescBtn');
        if (content && content.scrollHeight > 120) {
            content.classList.add('desc-collapsed');
            btn.style.display = 'inline-block';
        }
    });

    function toggleDescription() {
        const content = document.getElementById('descContent');
        const btn = document.getElementById('toggleDescBtn');
        if (content.classList.contains('desc-collapsed')) {
            content.style.maxHeight = content.scrollHeight + "px";
            content.classList.remove('desc-collapsed');
            btn.innerHTML = 'Read Less <i class="icon fas fa-chevron-up" style="font-size: 0.8em;"></i>';
        } else {
            content.style.maxHeight = null;
            content.classList.add('desc-collapsed');
            btn.innerHTML = 'Read More <i class="icon fas fa-chevron-down" style="font-size: 0.8em;"></i>';
        }
    }

    // --- 2. PRODUCT MEDIA GALLERY ---
    const mediaList = <?php echo json_encode($jsMediaData); ?>;
    let currentIndex = 0;

    function renderMedia(index) {
        if (index < 0) index = mediaList.length - 1;
        if (index >= mediaList.length) index = 0;
        currentIndex = index;
        const item = mediaList[index];
        const container = document.getElementById('mediaContent');
        container.innerHTML = ''; 

        if (item.type === 'vid') {
            const vid = document.createElement('video');
            vid.src = item.src;
            vid.controls = true;
            vid.autoplay = false;
            vid.style.maxWidth = '100%';
            vid.style.maxHeight = '100%';
            container.appendChild(vid);
        } else {
            const img = document.createElement('img');
            img.src = item.src;
            img.style.maxWidth = '100%';
            img.style.maxHeight = '100%';
            img.style.objectFit = 'contain';
            container.appendChild(img);
        }

        document.querySelectorAll('.thumb-box').forEach(el => el.classList.remove('active'));
        const activeThumb = document.getElementById('thumb-' + index);
        if (activeThumb) activeThumb.classList.add('active');
    }

    function moveMedia(direction) { renderMedia(currentIndex + direction); }
    function setMediaIndex(index) { renderMedia(index); }
    document.addEventListener("DOMContentLoaded", () => { if (mediaList.length > 0) renderMedia(0); });

    // --- 3. REVIEWS "READ MORE" LOGIC ---
    document.addEventListener("DOMContentLoaded", function() {
        // Find all review text blocks
        const reviewTexts = document.querySelectorAll('.review-text');
        
        reviewTexts.forEach(textBlock => {
            // Check actual text height vs allowed height
            if (textBlock.scrollHeight > textBlock.clientHeight) {
                // If text is long, show the button next to it
                const btn = textBlock.nextElementSibling;
                if (btn && btn.classList.contains('read-more-btn')) {
                    btn.style.display = 'inline-block'; 
                }
            } else {
                // If text is short, remove the collapsed limitation
                textBlock.classList.remove('collapsed');
            }
        });
    });

    function toggleReview(id) {
        const textBlock = document.getElementById('review-text-' + id);
        const btn = document.getElementById('btn-' + id);
        
        if (textBlock.classList.contains('collapsed')) {
            // Expand
            textBlock.classList.remove('collapsed');
            btn.innerText = "Show Less";
        } else {
            // Collapse
            textBlock.classList.add('collapsed');
            btn.innerText = "Read More";
        }
    }

    // --- 4. LIGHTBOX LOGIC ---
    var lightbox = document.getElementById('mediaLightbox');
    var container = document.getElementById('lightboxContainer');

    function openMediaModal(src, type) {
        container.innerHTML = "";
        if (type === 'video') {
            var video = document.createElement('video');
            video.src = src;
            video.className = "lightbox-content";
            video.controls = true;
            video.autoplay = true;
            container.appendChild(video);
        } else {
            var img = document.createElement('img');
            img.src = src;
            img.className = "lightbox-content";
            container.appendChild(img);
        }
        lightbox.style.display = "flex";
        document.body.style.overflow = "hidden";
    }

    function closeMediaModal(event) {
        if (event.target === lightbox || event.target.classList.contains('lightbox-close')) {
            lightbox.style.display = "none";
            container.innerHTML = "";
            document.body.style.overflow = "auto";
        }
    }
</script>

<?php include '../includes/footer.php'; ?>