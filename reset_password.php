<?php
// reset_password.php
require 'base.php';
include 'includes/header.php';

$token = req('token');

if (is_post()) {
    $pass = req('password');
    $confirm = req('confirm_password');
    
    if ($pass == '' || $pass !== $confirm) {
        $_err['password'] = "Passwords do not match or empty.";
    } else {
        // Find user by token
        $stm = $_db->prepare("SELECT * FROM users WHERE reset_token = ?");
        $stm->execute([$token]);
        $user = $stm->fetch();

        if ($user) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            // Update password AND clear token
            $stm = $_db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL WHERE user_id = ?");
            $stm->execute([$hash, $user->user_id]);
            
            temp('info', 'Password reset! You can now login.');
            redirect('login.php');
        } else {
            $_err['general'] = "Invalid or expired token.";
        }
    }
}
?>

<div class="auth-container">
    <div class="auth-card">
        <h2>Reset Password</h2>
        <?php if (isset($_err['general'])): ?>
            <div class="alert alert-danger"><?= $_err['general'] ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label>New Password</label>
                <?php html_password('password'); ?>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <?php html_password('confirm_password'); ?>
            </div>
            <button class="btn btn-primary btn-block">Reset Password</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>