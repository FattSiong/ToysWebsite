<?php
// 1. Load the layout and DB
require 'includes/db.php';
include 'includes/header.php';

$error = '';
$success = '';

// 2. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    // Basic Validation
    if ($password !== $confirm) {
        $error = "Passwords do not match!";
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$username]); // Assuming username is the email/login ID

        if ($stmt->rowCount() > 0) {
            $error = "Username/Email already taken!";
        } else {
            // 3. Hash Password (REQUIRED by Assignment)
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // 4. Insert into DB
            $sql = "INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, 'member')";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$username, $hash, $username])) {
                $success = "Registration successful! You can now login.";
            } else {
                $error = "Something went wrong.";
            }
        }
    }
}
?>

<div style="max-width: 400px; margin: auto;">
    <h2>Register Member</h2>

    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>

    <?php if ($success): ?>
        <p style="color: green;"><?php echo $success; ?></p>
    <?php else: ?>

    <form method="POST" action="">
        <div class="form-group">
            <label>Username / Email</label>
            <input type="text" name="username" required>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn btn-primary">Register</button>
    </form>

    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>