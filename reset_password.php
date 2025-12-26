<?php
// reset_password.php
require 'base.php';
include 'includes/header.php';

$token = req('token');

if (is_post()) {
    $pass = req('password');
    $confirm = req('confirm_password');

    if ($error = check_password($pass)) {
        $_err['password'] = $error;
    } else {
        // 1. Find user by token AND check expiry
        $stm = $_db->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_expires_at > NOW()");
        $stm->execute([$token]);
        $user = $stm->fetch();

        if ($user) {
            // 2. Hash new password
            $hash = password_hash($pass, PASSWORD_DEFAULT);

            // 3. Update password AND clear token/expiry
            // (Fixed: used array syntax $user['user_id'])
            $stm = $_db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL WHERE user_id = ?");
            $stm->execute([$hash, $user['user_id']]);

            temp('info', 'Password reset successful! You can now login.');
            redirect('login.php');
        } else {
            $_err['general'] = "Invalid or expired token. Please request a new one.";
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
                <label for="password">New Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="password" class="form-control" value="<?= encode($password ?? '') ?>">
                    <i class="fas icon-eye-slash toggle-password" data-target="password"></i>
                </div>
                <span class="error"><?= err('password') ?></span>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" value="<?= encode($confirm_password ?? '') ?>">
                    <i class="fas icon-eye-slash toggle-password" data-target="confirm_password"></i>
                </div>
                <span class="error"><?= err('confirm_password') ?></span>
            </div>
            <button class="btn btn-primary btn-block">Reset Password</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>