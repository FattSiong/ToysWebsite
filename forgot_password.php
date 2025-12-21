<?php
// forgot_password.php
require 'base.php';
include 'includes/header.php';

if (is_post()) {
    $email = req('email');
    
    // 1. Check if user exists
    $stm = $_db->prepare("SELECT * FROM users WHERE email = ?");
    $stm->execute([$email]);
    $user = $stm->fetch();

    if ($user) {
        // 2. Generate Token
        $token = bin2hex(random_bytes(32));
        
        // 3. Set Expiry (1 hour from now)
        $expiry = date('Y-m-d H:i:s', time() + 3600);

        // 4. Save Token & Expiry to DB
        // (Fixed: used array syntax $user['user_id'])
        $stm = $_db->prepare("UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE user_id = ?");
        $stm->execute([$token, $expiry, $user['user_id']]);

        // 5. Send Email
        $reset_link = base("reset_password.php?token=$token");
        
        $m = get_mail();
        $m->addAddress($email);
        $m->Subject = "Reset Your Password";
        $m->Body = "
            <h3>Password Reset Request</h3>
            <p>Click the link below to reset your password. This link expires in 1 hour.</p>
            <p><a href='$reset_link'>$reset_link</a></p>
            <p>If you did not request this, please ignore this email.</p>
        ";
        $m->isHTML(true);
        $m->send();
    }

    // Always show success message (Security best practice)
    temp('info', 'If an account exists with that email, a reset link has been sent.');
}
?>

<div class="auth-container">
    <div class="auth-card">
        <h2>Forgot Password?</h2>
        <?php if ($msg = temp('info')): ?>
            <div class="alert alert-success"><?= $msg ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <label>Enter your email address</label>
                <?php html_text('email'); ?>
            </div>
            <button class="btn btn-primary btn-block">Send Reset Link</button>
        </form>
        
        <div class="auth-footer">
            <a href="login.php">Back to Login</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>