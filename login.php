<?php
// login.php

require 'includes/db.php';
include 'includes/header.php';

$error = '';

// Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        // 1. Check Database for the Email
        $stmt = $pdo->prepare("SELECT user_id, username, password_hash, role, is_verified FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // 2. Verify Password
        if ($user && password_verify($password, $user['password_hash'])) {
            
            // 3. Check if Email is Verified
            if ($user['is_verified'] == 1) {
                // SUCCESS: Log them in
                
                // Prevent session fixation attacks
                session_regenerate_id(true);

                // Store user info in Session
                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];

                // Redirect based on Role
                if ($user['role'] === 'admin') {
                    header("Location: admin/product_list.php");
                } else {
                    header("Location: index.php");
                }
                exit;

            } else {
                // FAILURE: Not verified
                $error = "Your email is not verified. Please check your inbox.";
            }

        } else {
            // FAILURE: Wrong email OR password
            $error = "Invalid email or password.";
        }
    }
}
?>

<!-- HTML Login Form -->
<div class="auth-container">
    <div class="auth-card">
        <h2>Welcome Back</h2>
        <p>Login to continue shopping</p>
    
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required placeholder="name@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
        
        <div class="auth-footer">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>