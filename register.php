<?php
require 'base.php';
include 'includes/header.php';

// --- Process Form Submission ---
if (is_post()) {
    // 1. Inputs
    $email            = req('email');
    $password         = req('password');
    $confirm_password = req('confirm_password');

    // 2. Validation
    if ($email == '') {
        $_err['email'] = 'Email is required';
    } elseif (!is_email($email)) {
        $_err['email'] = 'Invalid email format';
    } elseif (is_exists($email, 'users', 'email')) { // Use base.php helper
        $_err['email'] = 'Email already registered';
    }

    if ($error = check_password($password)) {
        $_err['password'] = $error;
    }

    if ($confirm_password == '') {
        $_err['confirm_password'] = 'Confirm password is required';
    } elseif ($confirm_password !== $password) {
        $_err['confirm_password'] = 'Passwords do not match';
    }

    // 3. Execution (If no errors)
    if (!$_err) {
        // Prepare Data
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $otp = rand(100000, 999999);
        $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        try {
            global $_db;
            $_db->beginTransaction();

            // Insert or Update Token
            if (is_exists($email, 'registration_tokens', 'email')) {
                $stm = $_db->prepare("UPDATE registration_tokens SET password_hash = ?, token = ?, expires_at = ?, created_at = NOW() WHERE email = ?");
                $stm->execute([$password_hash, $otp, $expiry, $email]);
            } else {
                $stm = $_db->prepare("INSERT INTO registration_tokens (email, password_hash, token, expires_at) VALUES (?, ?, ?, ?)");
                $stm->execute([$email, $password_hash, $otp, $expiry]);
            }

            $_db->commit();

            // Send Email
            $m = get_mail();
            $m->addAddress($email);
            $m->Subject = "Verify Your Account - OTP: $otp";
            $m->Body = "
                <h1>Verify Your Account</h1>
                <p>Your verification code is: <b style='font-size: 20px; letter-spacing: 2px;'>$otp</b></p>
                <p>This code expires in 5 minutes.</p>
            ";
            $m->isHTML(true);

            if ($m->send()) {
                // Success: Redirect to verify page
                temp('temp_email', $email); // Use base.php helper
                $_SESSION['temp_email'] = $email; // Fallback for direct session access
                redirect('verify_email.php');
            } else {
                $_err['general'] = "Failed to send email. Error: " . $m->ErrorInfo;
            }

        } catch (Exception $e) {
            if ($_db->inTransaction()) $_db->rollBack();
            $_err['general'] = "System Error: " . $e->getMessage();
        }
    }
}
?>

<!-- Link to Auth CSS -->
<link rel="stylesheet" href="css/auth.css">

<div class="auth-container">
    <div class="auth-card">
        <h2 style="text-align:center; margin-bottom: 1.5rem; color: #333;">Create Account</h2>
        
        <!-- System Level Error -->
        <?php if (isset($_err['general'])): ?>
            <div class="alert alert-danger"><?= encode($_err['general']) ?></div>
        <?php endif; ?>

        <form method="post">
            
            <div class="form-group">
                <label for="email">Email Address <span>*</span></label>
                <?php html_text('email'); ?>
                <?php err('email'); ?>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
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

            <button type="submit" class="btn btn-primary">Send Verification Code</button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="login.php">Log in here</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>