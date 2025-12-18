<?php
// login.php
require 'base.php';
include 'includes/header.php';

// --- Process Login Submission ---
if (is_post()) {
    // 1. Inputs
    $email    = req('email');
    $password = req('password');

    // 2. Validation (Check for empty fields)
    if ($email == '') {
        $_err['email'] = 'Email is required';
    } elseif (!is_email($email)) {
        $_err['email'] = 'Invalid email format';
    }

    if ($password == '') {
        $_err['password'] = 'Password is required';
    }

    // 3. Authentication Logic (If no validation errors)
    if (!$_err) {
        global $_db;
        
        // Fetch user by email
        $stmt = $_db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(); // Returns object because of base.php settings

        if ($user) {
            // Verify Password
            if (password_verify($password, $user->password_hash)) {
                // --- SUCCESS ---
                
                // Regenerate session ID for security
                session_regenerate_id(true);

                // Store User Data in Session
                // Note: user object properties accessed with -> because of PDO::FETCH_OBJ
                $_SESSION['user'] = $user; 

                // Explicitly set ID and Role for easier access if needed elsewhere
                $_SESSION['user_id'] = $user->user_id;
                $_SESSION['role']    = $user->role;
                $_SESSION['username'] = $user->username;

                // Redirect based on Role
                if ($user->role === 'admin') {
                    redirect('admin/product_list.php'); 
                } else {
                    redirect('index.php');
                }
            } else {
                // Password incorrect
                $_err['general'] = 'Invalid email or password.';
            }
        } else {
            // User not found
            $_err['general'] = 'Invalid email or password.';
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
                <label for="email">Email Address <span>*</span></label>
                <?php html_text('email'); ?>
                <?php err('email'); ?>
            </div>

            <div class="form-group">
                <label for="password">Password <span>*</span></label>
                <?php html_password('password'); ?>
                <?php err('password'); ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>