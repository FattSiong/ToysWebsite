<?php
// register.php

// 1. Include necessary files
require 'includes/db.php';
include 'includes/header.php';

// Initialize variables
$error = '';
$success = '';

// 2. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    // 3. Validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        // 4. Check if Email exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$username, $email]);

        if ($stmt->rowCount() > 0) {
            $error = "Email is already registered.";
        } else {
            // 5. Register the user
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // Transaction: Ensure both inserts happen, or neither
            $pdo->beginTransaction();

            try {
                // A. Insert User (Without activation code)
                $sql = "INSERT INTO users (username, email, password_hash, role, is_verified) VALUES (?, ?, ?, 'member', 0)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$username, $email, $password_hash]);

                // Get the ID of the user we just created
                $new_user_id = $pdo->lastInsertId();

                // B. Generate Token & Expiry (24 Hours)
                $token = bin2hex(random_bytes(16));
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

                // C. Insert into email_verifications table
                $sql_verify = "INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)";
                $stmt_verify = $pdo->prepare($sql_verify);
                $stmt_verify->execute([$new_user_id, $token, $expires_at]);

                // Commit the transaction
                $pdo->commit();

                // 6. Simulate Email Sending
                $verify_link = "http://localhost:8001/verify.php?token=" . $token;

                $email_content = "To: $email\nSubject: Verify Account\n\nLink:\n$verify_link\n\n(Expires on: $expires_at)\n\n";
                file_put_contents('email_log.txt', $email_content, FILE_APPEND);

                $success = "Registration successful! Please check <b>email_log.txt</b> to verify.";

            } catch (Exception $e) {
                $pdo->rollBack(); // Undo changes if something failed
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
?>

<!-- 7. HTML Form Layout -->
<div class="auth-container">
    <div class="auth-card">
        <h2>Create Account</h2>
        <p>Join us to buy the best toys!</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
            <div class="auth-footer">
                <a href="login.php" class="btn btn-primary btn-block">Go to Login</a>
            </div>
        <?php else: ?>
            <form method="POST" action="register.php">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                    <small>Min 6 characters</small>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Register</button>
            </form>
            <div class="auth-footer">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>