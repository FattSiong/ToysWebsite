<?php
// admin_verify_email.php
require 'base.php';
include 'includes/header.php';

// Security: Must be partially logged in via login.php
if (!isset($_SESSION['partial_login_id'])) {
    redirect('login.php');
}

$user_id = $_SESSION['partial_login_id'];
$error = '';
$message = '';
$step = isset($_SESSION['admin_email_otp']) ? 2 : 1; // Step 1: Input Email, Step 2: Verify OTP

// --- Helper: Check Uniqueness ---
function is_email_unique_global($email, $user_id) {
    global $_db;
    $stmt = $_db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?");
    $stmt->execute([$email, $user_id]);
    return $stmt->fetchColumn() == 0;
}

if (is_post()) {
    // --- ACTION 1: SEND OTP ---
    if (post('action') === 'send_otp') {
        $email = trim(post('email'));

        if (!is_email($email)) {
            $error = "Invalid email format.";
        } elseif (!is_email_unique_global($email, $user_id)) {
            $error = "This email is already registered by another user.";
        } else {
            // Generate OTP
            $otp = rand(100000, 999999);
            
            // Send Email
            try {
                $mail = get_mail();
                $mail->addAddress($email);
                $mail->Subject = 'Admin Setup - Email Verification';
                $mail->Body    = "Your verification code is: <b>{$otp}</b>";
                $mail->isHTML(true);

                if ($mail->send()) {
                    $_SESSION['admin_email_otp'] = [
                        'email' => $email,
                        'otp' => $otp,
                        'expires' => time() + 300 // 5 mins
                    ];
                    $step = 2;
                    $message = "OTP sent to $email. Please check your inbox.";
                } else {
                    $error = "Failed to send email. " . $mail->ErrorInfo;
                }
            } catch (Exception $e) {
                $error = "System Error: " . $e->getMessage();
            }
        }
    }
    // --- ACTION 2: VERIFY OTP ---
    elseif (post('action') === 'verify_otp') {
        $input_otp = trim(post('otp_code'));
        $session_data = $_SESSION['admin_email_otp'] ?? null;

        if (!$session_data) {
            $error = "Session expired. Please try again.";
            $step = 1;
        } elseif (time() > $session_data['expires']) {
            $error = "OTP expired. Please request a new one.";
            unset($_SESSION['admin_email_otp']);
            $step = 1;
        } elseif ($input_otp != $session_data['otp']) {
            $error = "Invalid OTP code.";
            $step = 2; // Stay on verify step
        } else {
            // Success! Update DB
            try {
                $stmt = $_db->prepare("UPDATE users SET email = ? WHERE user_id = ?");
                $stmt->execute([$session_data['email'], $user_id]);
                
                unset($_SESSION['admin_email_otp']);
                
                // Redirect to next step (Phone Verification)
                redirect('verify_phone.php');
                
            } catch (PDOException $e) {
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }
    // --- ACTION 3: CANCEL/CHANGE EMAIL ---
    elseif (post('action') === 'change_email') {
        unset($_SESSION['admin_email_otp']);
        $step = 1;
    }
}
?>

<link rel="stylesheet" href="css/auth.css">

<div class="auth-container">
    <div class="auth-card">
        <h2>Admin First-Time Setup</h2>
        <p>Please link your email address to continue.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="post">
                <input type="hidden" name="action" value="send_otp">
                <div class="form-group">
                    <label>Email Address <span>*</span></label>
                    <input type="email" name="email" class="form-control" required placeholder="admin@example.com">
                    <small class="form-text text-muted">This will be used for account recovery.</small>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Send Verification Code</button>
            </form>

        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="verify_otp">
                <div class="form-group">
                    <label>Enter OTP Sent to <?= htmlspecialchars($_SESSION['admin_email_otp']['email']) ?></label>
                    <input type="text" name="otp_code" class="otp-input" maxlength="6" required placeholder="______">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Verify Email</button>
            </form>

            <form method="post" style="margin-top: 15px;">
                <input type="hidden" name="action" value="change_email">
                <button type="submit" class="btn btn-primary btn-block" style="background-color: #6c757d; color: white; border: none;">
                    <i class="fas fa-arrow-left"></i> Change Email Address
                </button>
            </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="logout.php">Cancel Setup & Logout</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>