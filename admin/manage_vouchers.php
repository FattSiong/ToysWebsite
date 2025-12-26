<?php
session_start();
require_once '../includes/db.php';

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] == 'member') {
    header("Location: ../login.php");
    exit;
}

$success = "";
$error = "";
$edit_mode = false;
$edit_data = [];

// --- 1. CHECK FOR EDIT MODE ---
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE voucher_id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_data = $stmt->fetch();
    
    if ($edit_data) {
        $edit_mode = true;
    } else {
        $error = "Voucher not found.";
    }
}

// --- 2. HANDLE FORM SUBMISSION (CREATE OR UPDATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_voucher'])) {
    $code = strtoupper(trim($_POST['code']));
    $type = $_POST['discount_type'];
    $value = $_POST['discount_value'];
    $min_spend = !empty($_POST['min_spend']) ? $_POST['min_spend'] : 0.00;
    $expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $update_id = !empty($_POST['voucher_id']) ? $_POST['voucher_id'] : null;

    // VALIDATION
    if ($type === 'percentage' && $value > 100) {
        $error = "Percentage discount cannot exceed 100%.";
    } elseif (empty($code) || ($type !== 'free_shipping' && $value === '')) {
        $error = "Code and Value are required.";
    } else {
        if ($type === 'free_shipping') $value = 0;

        try {
            // Check for duplicate code
            $sqlCheck = "SELECT voucher_id FROM vouchers WHERE code = ?";
            $paramsCheck = [$code];
            
            if ($update_id) {
                $sqlCheck .= " AND voucher_id != ?";
                $paramsCheck[] = $update_id;
            }
            
            $check = $pdo->prepare($sqlCheck);
            $check->execute($paramsCheck);

            if ($check->rowCount() > 0) {
                $error = "Voucher code '$code' already exists.";
            } else {
                if ($update_id) {
                    $stmt = $pdo->prepare("UPDATE vouchers SET code=?, discount_type=?, discount_value=?, min_spend=?, expiry_date=? WHERE voucher_id=?");
                    $stmt->execute([$code, $type, $value, $min_spend, $expiry, $update_id]);
                    $success = "Voucher updated successfully!";
                    $edit_mode = false;
                    $edit_data = []; 
                } else {
                    $stmt = $pdo->prepare("INSERT INTO vouchers (code, discount_type, discount_value, min_spend, expiry_date) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$code, $type, $value, $min_spend, $expiry]);
                    $success = "Voucher created successfully!";
                }
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// --- 3. HANDLE DELETE ---
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $pdo->prepare("DELETE FROM vouchers WHERE voucher_id = ?")->execute([$id]);
        header("Location: manage_vouchers.php?msg=deleted");
        exit;
    } catch (PDOException $e) {
        $error = "Error deleting voucher.";
    }
}

// --- 4. PREPARE LIST (Filters, Sorting, Pagination) ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_option = $_GET['sort'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build Query
$where_clause = " WHERE 1=1";
$params = [];

if (!empty($search_query)) {
    $where_clause .= " AND code LIKE :search";
    $params[':search'] = "%$search_query%";
}
if (!empty($type_filter)) {
    $where_clause .= " AND discount_type = :type";
    $params[':type'] = $type_filter;
}

// STATUS FILTER LOGIC
$today = date('Y-m-d');
if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        // Active includes valid dates AND no-expiry
        $where_clause .= " AND (expiry_date >= :today OR expiry_date IS NULL)";
        $params[':today'] = $today;
    } elseif ($status_filter === 'expired') {
        $where_clause .= " AND expiry_date < :today";
        $params[':today'] = $today;
    } elseif ($status_filter === 'no_expiry') {
        // New Status: No Expiry only
        $where_clause .= " AND expiry_date IS NULL";
    }
}

// Count Records
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers $where_clause");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// SORTING LOGIC
$order_by = " ORDER BY expiry_date ASC, voucher_id DESC"; // Default
switch ($sort_option) {
    case 'code_asc':   $order_by = " ORDER BY code ASC"; break;
    case 'code_desc':  $order_by = " ORDER BY code DESC"; break;
    case 'type_asc':   $order_by = " ORDER BY discount_type ASC"; break; // New Sorting
    case 'type_desc':  $order_by = " ORDER BY discount_type DESC"; break; // New Sorting
    case 'value_asc':  $order_by = " ORDER BY discount_value ASC"; break;
    case 'value_desc': $order_by = " ORDER BY discount_value DESC"; break;
    case 'min_asc':    $order_by = " ORDER BY min_spend ASC"; break;
    case 'min_desc':   $order_by = " ORDER BY min_spend DESC"; break;
    case 'date_asc':   $order_by = " ORDER BY expiry_date ASC"; break;
    case 'date_desc':  $order_by = " ORDER BY expiry_date DESC"; break;
}

// Fetch Data
$sql = "SELECT * FROM vouchers $where_clause $order_by LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$vouchers = $stmt->fetchAll();

include '../includes/header.php';
?>

<link rel="stylesheet" href="../css/manage_vouchers.css">
<style>
    .badge-free-shipping { background-color: #e3f2fd; color: #0d47a1; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.9em; }
    .btn-cancel { background-color: #6c757d; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block; text-align: center; margin-top: 10px;}
    .btn-cancel:hover { background-color: #5a6268; }
</style>

<div class="page-header">
    <h1 class="page-title">Manage Vouchers</h1>
</div>

<?php if ($success) echo "<div class='alert-box success'>$success</div>"; ?>
<?php if ($error) echo "<div class='alert-box error'>$error</div>"; ?>
<?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated') echo "<div class='alert-box success'>Voucher updated successfully.</div>"; ?>

<div class="card" id="formCard">
    <div class="card-header">
        <h3><?php echo $edit_mode ? "Edit Voucher: " . htmlspecialchars($edit_data['code']) : "Create New Voucher"; ?></h3>
    </div>
    <div class="card-body">
        <form method="POST" class="voucher-form-grid">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="voucher_id" value="<?php echo $edit_data['voucher_id']; ?>">
            <?php endif; ?>

            <div class="form-group">
                <label>Voucher Code <span class="req">*</span></label>
                <div class="input-icon-wrapper">
                    <i class="fas fa-ticket-alt input-icon"></i>
                    <input type="text" name="code" class="form-input" required 
                           value="<?php echo $edit_mode ? htmlspecialchars($edit_data['code']) : ''; ?>"
                           placeholder="e.g. SUMMER50" style="padding-left: 35px; text-transform: uppercase;">
                </div>
            </div>

            <div class="form-group">
                <label>Type</label>
                <select name="discount_type" id="discountType" class="form-select" onchange="toggleValueInput()">
                    <option value="percentage" <?php echo ($edit_mode && $edit_data['discount_type'] == 'percentage') ? 'selected' : ''; ?>>Percentage (%)</option>
                    <option value="fixed" <?php echo ($edit_mode && $edit_data['discount_type'] == 'fixed') ? 'selected' : ''; ?>>Fixed Amount (RM)</option>
                    <option value="free_shipping" <?php echo ($edit_mode && $edit_data['discount_type'] == 'free_shipping') ? 'selected' : ''; ?>>Free Shipping</option>
                </select>
            </div>

            <div class="form-group">
                <label>Value <span class="req">*</span></label>
                <input type="number" step="0.01" name="discount_value" id="discountValue" class="form-input" 
                       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['discount_value']) : ''; ?>"
                       placeholder="10">
                <span class="helper-text" id="valueHelper">Amount or Percentage.</span>
            </div>

            <div class="form-group">
                <label>Min. Spend (RM)</label>
                <input type="number" step="0.01" name="min_spend" class="form-input" 
                       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['min_spend']) : '0.00'; ?>">
            </div>

            <div class="form-group">
                <label>Expiry Date</label>
                <input type="date" name="expiry_date" class="form-input" 
                       value="<?php echo $edit_mode ? htmlspecialchars($edit_data['expiry_date']) : ''; ?>">
                <span class="helper-text">Leave empty for no expiry.</span>
            </div>

            <div class="form-group button-group">
                <label>&nbsp;</label>
                <button type="submit" name="save_voucher" class="btn-primary" style="width: 100%;">
                    <?php echo $edit_mode ? "Update Voucher" : "Create Voucher"; ?>
                </button>
                <?php if ($edit_mode): ?>
                    <a href="manage_vouchers.php" class="btn-cancel" style="width: 100%; text-align: center;">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header card-header-flex">
        <h3>Voucher List</h3>
        <span class="text-muted" style="font-size: 0.9em;">Total: <?= $total_records ?></span>
    </div>

    <div class="filter-bar" style="padding: 15px 20px; border-bottom: 1px solid #eee; background: #fafafa;">
        <form method="GET" class="filter-form" style="display: flex; gap: 10px; width: 100%; flex-wrap: wrap;">
            <div class="filter-group" style="flex: 1; min-width: 200px;">
                <input type="text" name="search" class="form-input" placeholder="Search code..." value="<?= htmlspecialchars($search_query) ?>">
            </div>
            
            <div class="filter-group">
                <select name="type" class="form-select" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="percentage" <?= $type_filter=='percentage'?'selected':'' ?>>Percentage</option>
                    <option value="fixed" <?= $type_filter=='fixed'?'selected':'' ?>>Fixed Amount</option>
                    <option value="free_shipping" <?= $type_filter=='free_shipping'?'selected':'' ?>>Free Shipping</option>
                </select>
            </div>

            <div class="filter-group">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="active" <?= $status_filter=='active'?'selected':'' ?>>Active (Includes No Expiry)</option>
                    <option value="expired" <?= $status_filter=='expired'?'selected':'' ?>>Expired</option>
                    <option value="no_expiry" <?= $status_filter=='no_expiry'?'selected':'' ?>>No Expiry Only</option>
                </select>
            </div>

            <?php if($sort_option): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_option) ?>"><?php endif; ?>
            
            <button type="submit" class="btn-secondary">Search</button>
            <?php if(!empty($search_query) || !empty($type_filter) || !empty($status_filter)): ?>
                <a href="manage_vouchers.php" class="btn-cancel" style="padding: 8px 12px; margin:0;">Reset</a>
            <?php endif; ?>
        </form>
    </div>
    
    <table class="data-table">
        <thead>
            <?php
            function sortLink($col, $currSort) {
                $newSort = $col . '_asc';
                $icon = 'fa-sort';
                $class = '';
                if ($currSort === $col . '_asc') {
                    $newSort = $col . '_desc';
                    $icon = 'fa-sort-up';
                    $class = 'active';
                } elseif ($currSort === $col . '_desc') {
                    $newSort = $col . '_asc';
                    $icon = 'fa-sort-down';
                    $class = 'active';
                }
                $params = $_GET;
                $params['sort'] = $newSort;
                $url = '?' . http_build_query($params);
                return "<a href='$url' class='sortable-link $class'> " . ucfirst(str_replace('_', ' ', $col)) . " <i class='fas $icon' style='margin-left:5px; font-size: 0.8em;'></i></a>";
            }
            ?>
            <tr>
                <th><?= sortLink('code', $sort_option) ?></th>
                <th><?= sortLink('type', $sort_option) ?></th>
                <th><?= sortLink('value', $sort_option) ?></th>
                <th><?= sortLink('min', $sort_option) ?></th>
                <th><?= sortLink('date', $sort_option) ?> (Expiry)</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vouchers)): ?>
                <tr><td colspan="7" style="text-align:center; padding: 20px;">No vouchers found matching your criteria.</td></tr>
            <?php else: ?>
                <?php foreach($vouchers as $v): 
                    $is_expired = ($v['expiry_date'] && $v['expiry_date'] < date('Y-m-d'));
                    $status_class = $is_expired ? 'status-expired' : 'status-active';
                    $status_text = $is_expired ? 'Expired' : 'Active';
                    if (!$v['expiry_date']) { $status_class = 'status-active'; $status_text = 'No Expiry'; }
                ?>
                <tr>
                    <td><span class="voucher-badge"><?= htmlspecialchars($v['code']) ?></span></td>
                    <td>
                         <?php 
                            if ($v['discount_type'] == 'free_shipping') echo '<span class="badge-free-shipping">Free Shipping</span>';
                            else echo ucfirst($v['discount_type']);
                        ?>
                    </td>
                    <td>
                        <?php 
                            if ($v['discount_type'] == 'free_shipping') echo '-';
                            elseif ($v['discount_type'] == 'fixed') echo 'RM '.number_format($v['discount_value'], 2);
                            else echo number_format($v['discount_value']).'%';
                        ?>
                    </td>
                    <td>RM <?= number_format($v['min_spend'], 2) ?></td>
                    <td><?= $v['expiry_date'] ? date('d M Y', strtotime($v['expiry_date'])) : '<span class="text-muted">∞ Forever</span>' ?></td>
                    <td><span class="status-pill <?= $status_class ?>"><?= $status_text ?></span></td>
                    <td>
                        <a href="?edit=<?= $v['voucher_id'] ?>" class="action-btn edit-btn" title="Edit"><i class="fas fa-edit"></i></a>
                        <a href="?delete=<?= $v['voucher_id'] ?>" class="action-btn delete-btn" onclick="return confirm('Delete?')" title="Delete"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if ($total_pages > 1): ?>
        <div class="pagination-container" style="padding: 15px 20px; display:flex; justify-content: space-between; align-items: center; border-top: 1px solid #eee;">
            <div class="pagination-info text-muted">Page <?= $page ?> of <?= $total_pages ?></div>
            <div class="pagination-links">
                <?php
                function pageUrl($p) {
                    $params = $_GET;
                    $params['page'] = $p;
                    return '?' . http_build_query($params);
                }
                ?>
                <?php if ($page > 1): ?><a href="<?= pageUrl($page-1) ?>" class="page-link">&laquo; Prev</a><?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="<?= pageUrl($i) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a href="<?= pageUrl($page+1) ?>" class="page-link">Next &raquo;</a><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function toggleValueInput() {
        const type = document.getElementById('discountType').value;
        const valueInput = document.getElementById('discountValue');
        const helper = document.getElementById('valueHelper');

        if (type === 'free_shipping') {
            valueInput.value = '0';
            valueInput.readOnly = true;
            valueInput.style.backgroundColor = '#f5f5f5';
            helper.textContent = 'Value not needed for Free Shipping.';
        } else {
            valueInput.readOnly = false;
            valueInput.style.backgroundColor = '#fff';
            if (type === 'percentage') {
                helper.textContent = 'Enter percentage (Max 100).';
                valueInput.setAttribute('max', '100');
            } else {
                helper.textContent = 'Enter fixed amount in RM.';
                valueInput.removeAttribute('max');
            }
        }
    }
    document.addEventListener('DOMContentLoaded', toggleValueInput);
</script>

<?php include 'admin_footer.php'; ?>