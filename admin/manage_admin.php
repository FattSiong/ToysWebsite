<?php
// admin/manage_admin.php
session_start();

// 1. Include Header & DB
require_once '../includes/header.php';
if (!isset($pdo)) {
    require_once '../includes/db.php';
}

// 2. SECURITY CHECK
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    echo "<script>alert('Access Denied. Managers only.'); window.location.href='dashboard.php';</script>";
    exit;
}

// 3. HANDLE POST ACTIONS
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- ADD NEW ADMIN ---
    if (isset($_POST['action']) && $_POST['action'] === 'add_admin') {
        $name = trim($_POST['name'] ?? '');
        $ic = trim($_POST['ic'] ?? '');

        if (empty($name) || empty($ic)) {
            $error = "Name and IC are required.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username '$name' already exists.";
            } else {
                $password_hash = password_hash($ic, PASSWORD_DEFAULT);
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, status, created_at) VALUES (?, ?, 'admin', 'Active', NOW())");
                    $stmt->execute([$name, $password_hash]);
                    $message = "New Admin '$name' added successfully.";
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // --- TOGGLE STATUS ---
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
        $user_id = $_POST['user_id'];
        $current_status = $_POST['current_status'];
        $new_status = ($current_status === 'Active') ? 'Inactive' : 'Active';

        try {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ? AND role = 'admin'");
            $stmt->execute([$new_status, $user_id]);
            $message = "Admin status updated to $new_status.";
        } catch (PDOException $e) {
            $error = "Error updating status.";
        }
    }

    // --- DELETE ADMIN ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete_admin') {
        $user_id = $_POST['user_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ? AND role = 'admin'");
            $stmt->execute([$user_id]);
            $message = "Admin account deleted.";
        } catch (PDOException $e) {
            $error = "Error deleting admin.";
        }
    }
}

// 4. PREPARE FILTERS & SORTING
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_option = $_GET['sort'] ?? 'date_desc';

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// 5. BUILD SQL
$base_sql = " FROM users WHERE role = 'admin'";
$params = [];

if (!empty($search_query)) {
    $base_sql .= " AND (username LIKE :search)";
    $params[':search'] = "%$search_query%";
}

$count_sql = "SELECT COUNT(*) " . $base_sql;
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

if ($page > $total_pages && $total_pages > 0) {
    header("Location: ?page=$total_pages&search=" . urlencode($search_query) . "&sort=" . urlencode($sort_option));
    exit;
}

$order_by = " ORDER BY created_at DESC";
switch ($sort_option) {
    case 'name_asc':   $order_by = " ORDER BY username ASC"; break;
    case 'name_desc':  $order_by = " ORDER BY username DESC"; break;
    case 'status_asc': $order_by = " ORDER BY status ASC"; break;
    case 'status_desc':$order_by = " ORDER BY status DESC"; break;
    case 'date_asc':   $order_by = " ORDER BY created_at ASC"; break;
    case 'date_desc':  $order_by = " ORDER BY created_at DESC"; break;
}

$sql = "SELECT user_id, username, status, created_at " . $base_sql . $order_by . " LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$admins = $stmt->fetchAll();
?>

<link rel="stylesheet" href="../css/manage_admin.css">

<div class="page-header">
    <h1 class="page-title">Manage Admins</h1>
</div>

<?php if ($message): ?>
    <div class="alert-box alert-success">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-box alert-error">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="filter-bar d-flex-between">
    <form action="" method="GET" class="filter-form" style="flex-grow: 1; max-width: 600px;">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_option) ?>">
        
        <div class="filter-group search-group" style="width: 100%;">
            <button type="submit" class="search-submit-btn">
                <i class="icon fa-search"></i>
            </button>
            <input type="text" name="search" class="form-input search-input-field"
                placeholder="Search Admin Name..."
                value="<?= htmlspecialchars($search_query) ?>">

            <?php if (!empty($search_query)): ?>
                <a href="manage_admin.php" class="clear-search-btn" title="Clear Search"><i class="icon fa-close"></i></a>
            <?php endif; ?>
        </div>
    </form>

    <button onclick="openModal('addAdminModal')" class="btn-primary btn-add-admin">
        <i class="fas fa-user-plus"></i> <span class="btn-text">Add New Admin</span>
    </button>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <?php
            function getSortParams($colName, $currentSort) {
                $params = $_GET;
                $icon = 'fa-sort';
                $activeClass = '';
                $nextSort = $colName . '_asc'; 
                
                if (strpos($currentSort, $colName) === 0) {
                    $activeClass = 'active';
                    if ($currentSort === $colName . '_asc') {
                        $nextSort = $colName . '_desc';
                        $icon = 'fa-sort-asc';
                    } else {
                        $nextSort = $colName . '_asc';
                        $icon = 'fa-sort-desc';
                    }
                }
                $params['sort'] = $nextSort;
                $params['page'] = 1; 
                return ['url' => '?' . http_build_query($params), 'icon' => $icon, 'class' => $activeClass];
            }

            $sortName   = getSortParams('name', $sort_option);
            $sortStatus = getSortParams('status', $sort_option);
            $sortDate   = getSortParams('date', $sort_option);
            ?>
            <tr>
                <th><a href="<?= $sortName['url'] ?>" class="sortable-link <?= $sortName['class'] ?>">Username <i class="icon fa <?= $sortName['icon'] ?>"></i></a></th>
                <th><a href="<?= $sortStatus['url'] ?>" class="sortable-link <?= $sortStatus['class'] ?>">Status <i class="icon fa <?= $sortStatus['icon'] ?>"></i></a></th>
                <th><a href="<?= $sortDate['url'] ?>" class="sortable-link <?= $sortDate['class'] ?>">Created At <i class="icon fa <?= $sortDate['icon'] ?>"></i></a></th>
                <th style="text-align: center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($admins) > 0): ?>
                <?php foreach ($admins as $row): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="avatar-circle"><?= strtoupper(substr($row['username'], 0, 1)) ?></div>
                                <span class="user-name" style="margin-left: 10px;"><?= htmlspecialchars($row['username']) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?= $row['status'] === 'Active' ? 'active' : 'inactive' ?>">
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        </td>
                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                        
                        <td style="text-align: center;">
                            <div class="action-btn-group">
                                <form action="" method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">
                                    <input type="hidden" name="current_status" value="<?= $row['status'] ?>">
                                    <?php if ($row['status'] === 'Active'): ?>
                                        <button type="submit" class="btn-icon-soft toggle-off" title="Deactivate" onclick="return confirm('Deactivate this admin?')"><i class="fas fa-ban"></i></button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-icon-soft toggle-on" title="Activate" onclick="return confirm('Activate this admin?')"><i class="fas fa-check"></i></button>
                                    <?php endif; ?>
                                </form>
                                <form action="" method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_admin">
                                    <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">
                                    <button type="submit" class="btn-icon-soft delete" title="Delete" onclick="return confirm('Are you sure you want to PERMANENTLY delete this admin?')"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 30px; color: #888;">No admins found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> admins</div>
        <div class="pagination-links">
            <?php
            function getPageUrl($pageNum) {
                $params = $_GET;
                $params['page'] = $pageNum;
                return '?' . http_build_query($params);
            }
            ?>
            <?php if ($page > 1): ?>
                <a href="<?= getPageUrl($page - 1) ?>" class="page-link"><i class="icon fa-chevron-left"></i></a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="<?= getPageUrl($i) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="<?= getPageUrl($page + 1) ?>" class="page-link"><i class="icon fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div id="addAdminModal" class="modal-overlay">
    <div class="modal-box popup-modern">
        <div class="modal-header">
            <h3>Add New Admin</h3>
            <button class="btn-close-modal" onclick="closeModal('addAdminModal')">&times;</button>
        </div>
        
        <form action="" method="POST">
            <input type="hidden" name="action" value="add_admin">
            
            <div class="modal-body">
                <div class="popup-icon-header">
                    <div class="icon-circle">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <p>Enter details to create a new administrator account.</p>
                </div>

                <div class="form-group input-with-icon">
                    <label>Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="name" class="form-input" placeholder="e.g. admin_john" required>
                    </div>
                </div>
                
                <div class="form-group input-with-icon">
                    <label>IC Number (Password)</label>
                    <div class="input-wrapper">
                        <i class="fas fa-id-card input-icon"></i>
                        <input type="text" name="ic" class="form-input" placeholder="e.g. 901020105566" required>
                    </div>
                    <span class="helper-text"><i class="fas fa-info-circle"></i> This IC will be used as the default password.</span>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('addAdminModal')">Cancel</button>
                <button type="submit" class="btn-primary">Create Admin</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }
    window.onclick = function(e) { if(e.target.classList.contains('modal-overlay')) e.target.classList.remove('open'); }
</script>

<?php include 'admin_footer.php'; ?>