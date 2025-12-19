<?php
// login.php
require 'base.php';
include 'includes/header.php';

// --- Process Login Submission ---
if (is_post()) {
    // 1. Inputs
    $login_id = req('login_id'); // Can be email OR username
    $password = req('password');
    $remember = req('remember'); // Checkbox value (1 or null)

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
        
        // Fetch user by email OR username
        $stmt = $_db->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$login_id, $login_id]);
        $user = $stmt->fetch(); // Returns object because of base.php settings

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
                    
                    // 1. Generate a random token
                    $token = bin2hex(random_bytes(32)); // 64 chars
                    
                    // 2. Hash it for the database (security best practice)
                    $token_hash = hash('sha256', $token);
                    
                    // 3. Store hash in DB
                    $update = $_db->prepare("UPDATE users SET remember_token = ? WHERE user_id = ?");
                    $update->execute([$token_hash, $user->user_id]);
                    
                    // 4. Set Cookie (lasts 30 days)
                    // Format: user_id:token (so we know who to look up)
                    $cookie_value = $user->user_id . ':' . $token;
                    setcookie('remember_me', $cookie_value, time() + (86400 * 30), "/", "", false, true); 
                    
                    // Ensure the Session Cookie also lasts longer to match (optional but good UX)
                    // Note: You can't easily change an active session's cookie lifetime on the fly without
                    // destroying/restarting, but the remember_me cookie handles the re-login anyway.

                } else {
                    // USER DOES NOT WANT TO BE REMEMBERED
                    
                    // Explicitly clear any existing cookie to prevent auto-login next time
                    if (isset($_COOKIE['remember_me'])) {
                        // Must match the setcookie parameters exactly (path /)
                        setcookie('remember_me', '', time() - 3600, "/");
                        unset($_COOKIE['remember_me']);
                    }
                    
                    // Clear the token in the database for extra security
                    $update = $_db->prepare("UPDATE users SET remember_token = NULL WHERE user_id = ?");
                    $update->execute([$user->user_id]);
                    
                    // FORCE Session Cookie to be strictly temporary (Session-only)
                    // This tells the browser "Delete this cookie when you close"
                    if (isset($_COOKIE[session_name()])) {
                        setcookie(session_name(), $_COOKIE[session_name()], 0, "/");
                    }
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

<!-- Link to Auth CSS -->
<link rel="stylesheet" href="css/auth.css">

<div class="auth-container">
    <div class="auth-card">
        <h2 style="text-align:center; margin-bottom: 1.5rem; color: #333;">Welcome Back</h2>
        
        <!-- General Login Error -->
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

            <!-- Remember Me Checkbox -->
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