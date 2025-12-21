<?php
// verify_email.php
// Assuming base.php is in the root directory
require 'base.php'; 
include 'includes/header.php';

// --- Helper function for generating unique username ---
function generateUniqueUsername($pdo, $baseName = 'Member') {
    $base = $baseName . substr(bin2hex(random_bytes(3)), 0, 6);
    $i = 1;
    $name = $base;

    // Use the global PDO object defined in base.php
    global $_db;

    do {
        $stmt = $_db->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$name]);

        if ($stmt->rowCount() > 0) {
            $name = $base . $i;
            $i++;
        }
    } while ($stmt->rowCount() > 0 && $i < 100);
    return $name;
}
// ----------------------------------------------------


if (!isset($_SESSION['temp_email'])) {
    redirect('register.php');
}

$email = $_SESSION['temp_email'];
$error = '';
$message = '';
$resend_success = false;
global $_db; // Ensure access to the global DB object


// --- MAIN LOGIC CONTROLLER ---

if (is_post()) {
    
    // ACTION 1: RESEND OTP (Check this first or use exclusive if/elseif)
    if (post('resend')) {
        
        $stmt = $_db->prepare("SELECT password_hash FROM registration_tokens WHERE email = ?");
        $stmt->execute([$email]);
        $token_data = $stmt->fetch();

        if ($token_data) {
            // Regenerate new OTP and expiry
            $otp = rand(100000, 999999);
            $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            // Update existing token with new values
            $sql = "UPDATE registration_tokens SET token = ?, expires_at = ?, created_at = CURRENT_TIMESTAMP WHERE email = ?";
            $update_stmt = $_db->prepare($sql);
            $update_stmt->execute([$otp, $expiry, $email]);

            // 4. Send Real Email via PHPMailer using the global get_mail() function
            try {
                $mail = get_mail(); // Use the dedicated mail function from base.php
                
                $mail->addAddress($email);
                $mail->Subject = 'New Verification Code: ' . $otp;
                $mail->Body    = "Your NEW OTP code for account verification is: <b>{$otp}</b>. This code expires in 5 minutes.";
                $mail->isHTML(true);
                
                if ($mail->send()) {
                    // *** FIX: Use session/temp variable to show success message on next page load ***
                    temp('resend_success', 'New code sent to your email. Check your inbox!');
                    redirect('verify_email.php'); // Redirect to clear POST data
                } else {
                    $error = "Email failed to resend. Mailer Error: " . $mail->ErrorInfo;
                }
            } catch (Exception $e) {
                $error = "Email failed to resend. System Error: " . $e->getMessage();
            }
            
        } else {
            $error = "Could not find account data. Please start registration again.";
        }
    }
    // ACTION 2: VERIFY OTP
    elseif (post('verify_otp')) {
        $otp_input = trim(post('otp_code'));

        $stmt = $_db->prepare("SELECT * FROM registration_tokens WHERE email = ?");
        $stmt->execute([$email]);
        $token_data = $stmt->fetch(); // Using PDO::FETCH_OBJ from base.php

        if ($token_data) {
            if ($token_data->token == $otp_input) {
                if (strtotime($token_data->expires_at) < time()) {
                    $error = "Code expired. Please request a new code.";
                    $stmt_del_exp = $_db->prepare("DELETE FROM registration_tokens WHERE email = ?");
                    $stmt_del_exp->execute([$email]);
                } else {
                    // --- SUCCESS: MIGRATE ---
                    try {
                        $_db->beginTransaction();

                        // ** NEW CHECK: Check if email already exists in main users table **
                        $check_existing = $_db->prepare("SELECT user_id FROM users WHERE email = ?");
                        $check_existing->execute([$email]);

                        if ($check_existing->rowCount() > 0) {
                            // User already verified/exists. Cleanup token and error out.
                            $stmt_del = $_db->prepare("DELETE FROM registration_tokens WHERE email = ?");
                            $stmt_del->execute([$email]);
                            $_db->commit();
                            unset($_SESSION['temp_email']);
                            
                            echo "<script>alert('Error: This email is already verified and active. Please login.'); window.location.href='login.php';</script>";
                            exit;
                        }

                        $generated_username = generateUniqueUsername($_db, 'Member');

                        // Insert into USERS
                        $sql_insert = "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'member')";
                        $stmt_insert = $_db->prepare($sql_insert);
                        $stmt_insert->execute([
                            $generated_username, 
                            $token_data->email, 
                            $token_data->password_hash
                        ]);

                        // Delete token from registration_tokens
                        $stmt_del = $_db->prepare("DELETE FROM registration_tokens WHERE email = ?");
                        $stmt_del->execute([$email]);

                        $_db->commit();
                        unset($_SESSION['temp_email']);

                        // *** MODIFIED SUCCESS MESSAGE ***
                        echo "<script>alert('Account Verified Successfully! Please Login.'); window.location.href='login.php';</script>";
                        exit;

                    } catch (Exception $e) {
                        $_db->rollBack();
                        $error = "System Error: Account creation failed. SQLSTATE: " . $e->getMessage();
                    }
                }
            } else {
                $error = "Invalid OTP Code.";
            }
        } else {
            $error = "Verification failed. Code not found. Please resend code.";
        }
    }
} // End is_post()

// --- Display temporary success message after redirect ---
$temp_message = temp('resend_success');
if ($temp_message) {
    $message = $temp_message;
}

?>

<!-- Link to Auth CSS -->
<link rel="stylesheet" href="css/auth.css">

<div class="auth-container">
    <div class="auth-card">
        <h2>Verify Email</h2>
        <p>We sent a 6-digit code to: <b><?php echo htmlspecialchars($_SESSION['temp_email'] ?? 'your email'); ?></b></p>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <input type="text" name="otp_code" class="otp-input" required maxlength="6" placeholder="______" autocomplete="off">
            </div>
            <!-- Added value="1" to ensure the POST variable is always set -->
            <button type="submit" name="verify_otp" value="1" class="btn btn-primary btn-block">Verify & Create Account</button>
        </form>

        <div class="auth-footer" style="margin-top: 20px;">
            <!-- RESEND BUTTON -->
            <form method="POST">
                <!-- Added value="1" to ensure the POST variable is always set -->
                <button type="submit" name="resend" value="1" class="btn" style="background: none; border: none; color: #007bff; padding: 0; cursor: pointer; text-decoration: underline;">
                    Didn't receive the code? Resend now.
                </button>
            </form>
            <a href="register.php" style="margin-top: 10px; display: block; color: #6c757d; text-decoration: none;">Cancel Registration</a>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>