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
        
        // 3. Save Token to DB (Update users table)
        // Ensure your 'users' table has a 'reset_token' column (your SQL dump showed it does)
        $stm = $_db->prepare("UPDATE users SET reset_token = ? WHERE user_id = ?");
        $stm->execute([$token, $user->user_id]);

        // 4. Send Email
        $reset_link = base("reset_password.php?token=$token");
        
        $m = get_mail();
        $m->addAddress($email);
        $m->Subject = "Reset Your Password";
        $m->Body = "Click this link to reset your password: <a href='$reset_link'>$reset_link</a>";
        $m->isHTML(true);
        $m->send();
    }

    // Always show success message to prevent email enumeration
    temp('info', 'If an account exists, a reset link has been sent.');
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
    </div>
</div>

<?php include 'includes/footer.php'; ?>