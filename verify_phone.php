<?php
// verify_phone.php
require 'base.php';

// --- Determine User ID and Role for AJAX checks ---
$current_user_id = null;
$current_role = null;

if (isset($_SESSION['partial_login_id'])) {
    $current_user_id = $_SESSION['partial_login_id'];
    // Fetch role from DB since session might not have it fully loaded
    $stmt = $_db->prepare("SELECT role FROM users WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $current_role = $stmt->fetchColumn();
} elseif (isset($_SESSION['user'])) {
    $current_user_id = $_SESSION['user']['user_id'];
    $current_role = $_SESSION['user']['role'];
}

// =================================================================
// 1. HANDLE AJAX OTP REQUESTS (Shared for both modes)
// =================================================================
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'send_otp') {
    header('Content-Type: application/json');
    
    $phone = $_POST['phone'] ?? '';
    
    // Basic Validation
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($clean_phone) < 10 || strlen($clean_phone) > 11) {
        echo json_encode(['success' => false, 'message' => 'Invalid phone number format.']);
        exit;
    }

    // --- UNIQUE PHONE CHECK (Based on Requirements) ---
    // Rule: Phone cannot be repeated between non-members (admin/manager).
    // It CAN be repeated if the user is a member, or if it clashes with a member (implied).
    if (in_array($current_role, ['admin', 'manager'])) {
        $stmt = $_db->prepare("SELECT COUNT(*) FROM users WHERE phone_number = ? AND role IN ('admin', 'manager') AND user_id != ?");
        $stmt->execute([$clean_phone, $current_user_id]);
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'This phone number is already in use by another administrator.']);
            exit;
        }
    } 
    // Note: If role is member, we usually enforce global uniqueness or per-role uniqueness. 
    // Assuming standard uniqueness for members isn't strictly requested to fail against admins, 
    // but usually, phone should be unique globally. 
    // If strict global uniqueness is required, remove the "AND role IN..." part. 
    // Following strict prompt: "phone number can not be repeated between non-members"

    // Convert to International Format
    if (substr($clean_phone, 0, 1) === '0') {
        $clean_phone = '+60' . substr($clean_phone, 1);
    }

    // Generate OTP
    $otp = rand(100000, 999999);
    
    // Store in Session
    $_SESSION['temp_otp_data'] = [
        'phone' => $phone, // Store original input for matching later
        'otp' => $otp,
        'expires' => time() + 300
    ];

    // --- TWILIO API INTEGRATION ---
    $sid    = 'AC9a8e2474a4a03570a0a8d20750b1cef3';
    $token  = 'd31e770b8b01b26654b88ec14f645ae0';
    $sender = '+14177445222'; 

    $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $post_data = [
        'From' => $sender,
        'To'   => $clean_phone,
        'Body' => "Your ToyShop Verification Code is: $otp"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        echo json_encode(['success' => true, 'message' => "SMS sent to $phone!"]);
    } else {
        error_log("Twilio Error: $response");
        echo json_encode([
            'success' => true, 
            'message' => "SMS Failed (Trial Limitation). Your Code is: $otp" 
        ]);
    }
    exit;
}

// =================================================================
// 2. DETERMINE MODE (Login vs Update)
// =================================================================
include 'includes/header.php';

$mode = '';
$user_id = null;
$target_phone = '';

if (isset($_SESSION['partial_login_id'])) {
    // MODE A: Login Verification (Member OR Admin First Time)
    $mode = 'login';
    $user_id = $_SESSION['partial_login_id'];
    $target_phone = $_SESSION['temp_otp_data']['phone'] ?? ''; 
} 
elseif (isset($_SESSION['temp_new_phone']) && isset($_SESSION['user'])) {
    // MODE B: Profile Update Verification
    $mode = 'update';
    $user_id = $_SESSION['user']['user_id'];
    $target_phone = $_SESSION['temp_new_phone'];
} 
else {
    redirect('login.php');
}

$error = '';

// =================================================================
// 3. HANDLE FINAL SUBMISSION
// =================================================================
if (is_post() && !isset($_POST['ajax_action'])) {
    $entered_otp = req('otp_code');
    $phone_input = req('phone_number');
    
    $session_data = $_SESSION['temp_otp_data'] ?? null;

    if (!$session_data) {
        $error = "Please click 'Send OTP' first.";
    } elseif ($phone_input !== $session_data['phone']) {
        $error = "Phone number changed. Please send OTP again.";
    } elseif ($phone_input !== $target_phone && $mode === 'update') {
        $error = "Phone number does not match the one requested in profile.";
    } elseif (time() > $session_data['expires']) {
        $error = "OTP expired. Send a new one.";
    } elseif ($entered_otp != $session_data['otp']) {
        $error = "Invalid OTP code.";
    } else {
        
        // --- FINAL UNIQUENESS CHECK (Double Check) ---
        // Fetch role again to be sure
        $stmt_role = $_db->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt_role->execute([$user_id]);
        $role = $stmt_role->fetchColumn();

        $valid = true;
        if (in_array($role, ['admin', 'manager'])) {
            $stmt = $_db->prepare("SELECT COUNT(*) FROM users WHERE phone_number = ? AND role IN ('admin', 'manager') AND user_id != ?");
            $stmt->execute([$session_data['phone'], $user_id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Phone number already in use by another administrator.";
                $valid = false;
            }
        }

        if ($valid) {
            // --- SUCCESS ---
            
            // 1. Update DB
            $stm = $_db->prepare("UPDATE users SET phone_number = ? WHERE user_id = ?");
            $stm->execute([$session_data['phone'], $user_id]);

            // Cleanup OTP data
            unset($_SESSION['temp_otp_data']);

            if ($mode === 'login') {
                // --- FINALIZE LOGIN ---
                $stm = $_db->prepare("SELECT * FROM users WHERE user_id = ?");
                $stm->execute([$user_id]);
                $user = $stm->fetch();

                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['username'] = $user['username'];

                // Remember Me
                if (isset($_SESSION['remember_choice']) && $_SESSION['remember_choice'] == 1) {
                    $token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $token);
                    $update = $_db->prepare("UPDATE users SET remember_token = ? WHERE user_id = ?");
                    $update->execute([$token_hash, $user['user_id']]);
                    setcookie('remember_me', $user['user_id'] . ':' . $token, time() + (86400 * 30), "/", "", false, true);
                }
                
                unset($_SESSION['partial_login_id']);
                unset($_SESSION['remember_choice']);

                // Redirect based on Role
                if (in_array($user['role'], ['admin', 'manager'])) {
                    redirect('admin/dashboard.php');
                } else {
                    redirect('index.php');
                }

            } else {
                // --- FINALIZE PROFILE UPDATE ---
                $_SESSION['user']['phone_number'] = $session_data['phone'];
                unset($_SESSION['temp_new_phone']);

                temp('info', 'Phone number verified and updated successfully!');
                redirect('profile.php');
            }
        }
    }
}
?>

<div class="auth-container">
    <div class="auth-card">
        <h2><?= $mode === 'login' ? 'Phone Verification' : 'Verify New Number' ?></h2>
        <p style="color:#666; margin-bottom:20px;">
            <?= $mode === 'login' 
                ? 'Please verify your phone number to complete login.' 
                : 'We need to verify your new number before updating it.' 
            ?>
        </p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" id="verifyForm">

            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone_number" id="phone_number" class="form-control" 
                       placeholder="012xxxxxxx" 
                       value="<?= htmlspecialchars($target_phone) ?>" 
                       <?= $mode === 'update' ? 'readonly style="background:#f9f9f9;"' : '' ?>
                       required>
                <?php if ($mode === 'update'): ?>
                    <small style="color: #666;">You cannot edit this here. <a href="profile.php">Go back</a> to change.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>OTP Code</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" name="otp_code" id="otp_code" class="form-control" 
                           placeholder="6-digit code" maxlength="6" style="flex: 1;">
                    
                    <button type="button" id="btnSendOtp" class="btn btn-secondary" style="white-space: nowrap;">
                        Send OTP
                    </button>
                </div>
                <small id="otpHelp" style="color: #666; display:none;">Code sent!</small>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top: 20px;">
                <?= $mode === 'login' ? 'Verify & Login' : 'Verify & Update' ?>
            </button>
        </form>
        
        <div class="auth-footer">
            <a href="logout.php">Cancel Setup</a>
        </div>
    </div>
</div>

<script src="js/verify_phone.js"></script>

<?php include 'includes/footer.php'; ?>