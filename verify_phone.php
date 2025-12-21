<?php
// verify_phone.php
require 'base.php';
// We do NOT include header.php immediately if we want to handle pure JSON AJAX responses clean.
// However, checking headers is easier.

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'send_otp') {
    header('Content-Type: application/json');
    
    $phone = $_POST['phone'] ?? '';
    
    // 1. Basic Validation
    // Remove non-numeric characters just in case
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Check if valid length (Malaysian numbers are usually 10-11 digits)
    if (strlen($clean_phone) < 10 || strlen($clean_phone) > 11) {
        echo json_encode(['success' => false, 'message' => 'Invalid phone number format.']);
        exit;
    }

    // Convert to International Format (E.164)
    // Twilio requires +601... format. 
    // If user typed 0123456789, we change it to +60123456789
    if (substr($clean_phone, 0, 1) === '0') {
        $clean_phone = '+60' . substr($clean_phone, 1);
    }

    // 2. Generate OTP
    $otp = rand(100000, 999999);
    
    // 3. Store in Session
    $_SESSION['temp_otp_data'] = [
        'phone' => $phone, // Keep original input for display/db
        'otp' => $otp,
        'expires' => time() + 300
    ];

    // =================================================================
    // START: TWILIO API INTEGRATION
    // =================================================================

    // TODO: Replace these with your real keys from console.twilio.com
    $sid    = 'AC9a8e2474a4a03570a0a8d20750b1cef3';
    $token  = 'd31e770b8b01b26654b88ec14f645ae0';
    $sender = '+14177445222'; // Your Twilio Trial Number

    $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $post_data = [
        'From' => $sender,
        'To'   => $clean_phone,
        'Body' => "Your ToyShop Verification Code is: $otp"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Fix for local XAMPP SSL issues
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // =================================================================
    // END: TWILIO API INTEGRATION
    // =================================================================

    if ($http_code >= 200 && $http_code < 300) {
        echo json_encode(['success' => true, 'message' => "SMS sent to $phone!"]);
    } else {
        // Log the error for your debugging (don't show user the raw error)
        error_log("Twilio Error: $response");
        
        // Fallback for assignment: If API fails (e.g., unverified number), 
        // show the code in alert so you can still login.
        echo json_encode([
            'success' => true, // Pretend success so they can continue
            'message' => "SMS Failed (Trial Limitation). Your Code is: $otp" 
        ]);
    }
    exit;
}

// --- 2. STANDARD PAGE LOGIC ---
include 'includes/header.php';

// Security Check: Must have passed login credential check first
if (!isset($_SESSION['partial_login_id'])) {
    redirect('login.php');
}

$user_id = $_SESSION['partial_login_id'];
$error = '';

// --- 3. FINAL VERIFICATION SUBMISSION ---
if (is_post() && !isset($_POST['ajax_action'])) {
    $entered_otp = req('otp_code');
    $phone_input = req('phone_number');
    
    $session_data = $_SESSION['temp_otp_data'] ?? null;

    if (!$session_data) {
        $error = "Please click 'Send OTP' first.";
    } elseif ($phone_input !== $session_data['phone']) {
        $error = "Phone number changed. Please send OTP again.";
    } elseif (time() > $session_data['expires']) {
        $error = "OTP expired. Send a new one.";
    } elseif ($entered_otp != $session_data['otp']) {
        $error = "Invalid OTP code.";
    } else {
        // --- SUCCESS: Finalize Login ---
        
        // 1. Update DB
        $stm = $_db->prepare("UPDATE users SET phone_number = ? WHERE user_id = ?");
        $stm->execute([$session_data['phone'], $user_id]);

        // 2. Fetch Full User Data
        $stm = $_db->prepare("SELECT * FROM users WHERE user_id = ?");
        $stm->execute([$user_id]);
        $user = $stm->fetch();

        // 3. Set Real Session
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];

        // 4. Handle "Remember Me" (passed from login.php)
        if (isset($_SESSION['remember_choice']) && $_SESSION['remember_choice'] == 1) {
            $token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $update = $_db->prepare("UPDATE users SET remember_token = ? WHERE user_id = ?");
            $update->execute([$token_hash, $user['user_id']]);
            setcookie('remember_me', $user['user_id'] . ':' . $token, time() + (86400 * 30), "/", "", false, true);
        }
        // Cleanup
        unset($_SESSION['partial_login_id']);
        unset($_SESSION['remember_choice']);
        unset($_SESSION['temp_otp_data']);

        redirect('index.php');
    }
}
?>

<div class="auth-container">
    <div class="auth-card">
        <h2>Phone Verification</h2>
        <p style="color:#666; margin-bottom:20px;">Please verify your phone number to complete login.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" id="verifyForm">

            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone_number" id="phone_number" class="form-control" 
                       placeholder="012xxxxxxx" 
                       value="<?= htmlspecialchars($_SESSION['temp_otp_data']['phone'] ?? '') ?>" 
                       required>
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
                Verify & Login
            </button>
        </form>
        
        <div class="auth-footer">
            <a href="logout.php">Cancel Login</a>
        </div>
    </div>
</div>

<script src="js/verify_phone.js"></script>

<?php include 'includes/footer.php'; ?>