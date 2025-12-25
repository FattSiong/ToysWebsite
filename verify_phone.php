<?php
// verify_phone.php
require 'base.php';

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

    // Convert to International Format
    if (substr($clean_phone, 0, 1) === '0') {
        $clean_phone = '+60' . substr($clean_phone, 1);
    }

    // Generate OTP
    $otp = rand(100000, 999999);
    
    // Store in Session
    $_SESSION['temp_otp_data'] = [
        'phone' => $phone,
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
    // MODE A: Login Verification
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
    // Neither? Kick them out.
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
        // Extra security for update mode
        $error = "Phone number does not match the one requested in profile.";
    } elseif (time() > $session_data['expires']) {
        $error = "OTP expired. Send a new one.";
    } elseif ($entered_otp != $session_data['otp']) {
        $error = "Invalid OTP code.";
    } else {
        // --- SUCCESS ---
        
        // 1. Update DB (Common for both)
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
            redirect('index.php');

        } else {
            // --- FINALIZE PROFILE UPDATE ---
            // Update session user data immediately
            $_SESSION['user']['phone_number'] = $session_data['phone'];
            unset($_SESSION['temp_new_phone']); // Clear the temp request

            temp('info', 'Phone number verified and updated successfully!');
            redirect('profile.php');
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
            <?php if ($mode === 'login'): ?>
                <a href="logout.php">Cancel Login</a>
            <?php else: ?>
                <a href="profile.php">Cancel Update</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="js/verify_phone.js"></script>

<?php include 'includes/footer.php'; ?>