<?php
// login.php
require 'base.php';
include 'includes/header.php';

// --- Process Login Submission ---
if (is_post()) {
    // 1. Inputs
    $login_id = req('login_id');
    $password = req('password');
    $remember = req('remember');

    // 2. Validation
    if ($login_id == '') {
        $_err['login_id'] = 'Email or Username is required';
    }

    if ($password == '') {
        $_err['password'] = 'Password is required';
    }

    // 3. Authentication Logic
    if (!$_err) {
        global $_db;

        $stmt = $_db->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$login_id, $login_id]);
        $user = $stmt->fetch();

        if ($user) {
            // --- CHECK LOCKOUT STATUS ---
            if ($user['lockout_until'] && new DateTime() < new DateTime($user['lockout_until'])) {
                // User is currently locked out
                $unlock_time = date('H:i', strtotime($user['lockout_until']));
                $_err['general'] = "Too many failed attempts. Please try again after $unlock_time.";
            } else {
                // --- VERIFY PASSWORD ---
                if (password_verify($password, $user['password_hash'])) {

                    // (A) LOGIN SUCCESS: Reset failed attempts and lockout
                    $reset_stmt = $_db->prepare("UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE user_id = ?");
                    $reset_stmt->execute([$user['user_id']]);

                    // --- MEMBER: First-time Phone Verification ---
                    if ($user['role'] === 'member' && empty($user['phone_number'])) {
                        $_SESSION['partial_login_id'] = $user['user_id'];
                        $_SESSION['remember_choice'] = $remember;
                        redirect('verify_phone.php');
                    }

                    // --- ADMIN/MANAGER: First-time Setup (Email First) ---
                    if (in_array($user['role'], ['admin', 'manager']) && empty($user['email'])) {
                        $_SESSION['partial_login_id'] = $user['user_id'];
                        $_SESSION['remember_choice'] = $remember;
                        // Admins must set up email first, then phone
                        redirect('admin_verify_email.php');
                    }

                    // --- ADMIN/MANAGER: Email done, but Phone missing ---
                    if (in_array($user['role'], ['admin', 'manager']) && empty($user['phone_number'])) {
                        $_SESSION['partial_login_id'] = $user['user_id'];
                        $_SESSION['remember_choice'] = $remember;
                        redirect('verify_phone.php');
                    }

                    session_regenerate_id(true);

                    $_SESSION['user'] = $user;
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role']    = $user['role'];
                    $_SESSION['username'] = $user['username'];

                    // Remember Me Logic
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $token_hash = hash('sha256', $token);
                        $update = $_db->prepare("UPDATE users SET remember_token = ? WHERE user_id = ?");
                        $update->execute([$token_hash, $user['user_id']]);
                        $cookie_value = $user['user_id'] . ':' . $token;
                        setcookie('remember_me', $cookie_value, time() + (86400 * 30), "/", "", false, true);
                    } else {
                        if (isset($_COOKIE['remember_me'])) {
                            setcookie('remember_me', '', time() - 3600, "/");
                            unset($_COOKIE['remember_me']);
                        }
                        $update = $_db->prepare("UPDATE users SET remember_token = NULL WHERE user_id = ?");
                        $update->execute([$user['user_id']]);
                    }

                    if (in_array($user['role'], ['admin', 'manager'])) {
                        redirect('admin/dashboard.php');
                    } else {
                        redirect('index.php');
                    }
                } else {
                    // (B) LOGIN FAILED: Update failed attempts
                    $attempts = $user['failed_attempts'] + 1;

                    if ($attempts >= 5) {
                        // Lockout for 15 minutes (900 seconds)
                        $lockout_time = date('Y-m-d H:i:s', time() + 900);
                        $update = $_db->prepare("UPDATE users SET failed_attempts = ?, lockout_until = ? WHERE user_id = ?");
                        $update->execute([$attempts, $lockout_time, $user['user_id']]);

                        $_err['general'] = 'Too many failed attempts. Account locked for 15 minutes.';
                    } else {
                        // Just increment counter
                        $remaining = 5 - $attempts;
                        $update = $_db->prepare("UPDATE users SET failed_attempts = ? WHERE user_id = ?");
                        $update->execute([$attempts, $user['user_id']]);

                        $_err['general'] = "Invalid username/email or password. ($remaining attempts remaining)";
                    }
                }
            }
        } else {
            // User not found
            $_err['general'] = 'Invalid username/email or password.';
        }
    }
}
?>

<link rel="stylesheet" href="css/auth.css">

<div class="auth-container">
    <div class="auth-card">
        <h2 style="text-align:center; margin-bottom: 1.5rem; color: #333;">Welcome Back</h2>

        <?php if (isset($_err['general'])): ?>
            <div class="alert alert-danger">
                <?= encode($_err['general']) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="login_id">Email or Username <span>*</span></label>
                <?php html_text('login_id'); ?>
                <?php err('login_id'); ?>
            </div>

            <div class="form-group">
                <label for="password">Password <span>*</span></label>
                <?php html_password('password'); ?>
                <?php err('password'); ?>
            </div>

            <div class="form-group" style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" name="remember" id="remember" value="1" style="width: auto;">
                    <label for="remember" style="margin: 0; font-weight: normal; cursor: pointer;">Remember Me</label>
                </div>
                <a href="forgot_password.php" style="font-size: 0.9em;">Forgot Password?</a>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>