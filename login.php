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
            // Verify Password
            if (password_verify($password, $user->password_hash)) {
                // --- SUCCESS ---
                
                session_regenerate_id(true);

                // Store User Data in Session
                $_SESSION['user'] = $user; 
                $_SESSION['user_id'] = $user->user_id;
                $_SESSION['role']    = $user->role;
                $_SESSION['username'] = $user->username;

                // --- REMEMBER ME LOGIC ---
                if ($remember) {
                    // USER WANTS TO BE REMEMBERED
                    $token = bin2hex(random_bytes(32)); 
                    $token_hash = hash('sha256', $token);
                    
                    $update = $_db->prepare("UPDATE users SET remember_token = ? WHERE user_id = ?");
                    $update->execute([$token_hash, $user->user_id]);
                    
                    // Set Persistent Cookie (30 days)
                    $cookie_value = $user->user_id . ':' . $token;
                    setcookie('remember_me', $cookie_value, time() + (86400 * 30), "/", "", false, true); 

                } else {
                    // USER DOES NOT WANT TO BE REMEMBERED
                    
                    // 1. Clear any existing Remember Me cookie
                    if (isset($_COOKIE['remember_me'])) {
                        setcookie('remember_me', '', time() - 3600, "/");
                        unset($_COOKIE['remember_me']);
                    }
                    
                    // 2. Clear the token in the database
                    $update = $_db->prepare("UPDATE users SET remember_token = NULL WHERE user_id = ?");
                    $update->execute([$user->user_id]);
                    
                    // *** FIX: REMOVED THE MANUAL SESSION COOKIE RESET HERE ***
                    // PHP defaults to "Session Cookie" (expires on close) automatically.
                    // Doing nothing here is the correct behavior.
                }

                // Redirect based on Role
                if ($user->role === 'admin') {
                    redirect('admin/product_list.php'); 
                } else {
                    redirect('index.php');
                }
            } else {
                $_err['general'] = 'Invalid username/email or password.';
            }
        } else {
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

            <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" name="remember" id="remember" value="1" style="width: auto;">
                <label for="remember" style="margin: 0; font-weight: normal; cursor: pointer;">Remember Me</label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>