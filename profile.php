<?php
// profile.php
require 'base.php';
include 'includes/header.php';

// 1. Ensure User is Logged In
if (!$_user) {
    redirect('login.php');
}

// 2. Fetch latest user data (in case session is stale)
$stm = $_db->prepare("SELECT * FROM users WHERE user_id = ?");
$stm->execute([$_user->user_id]);
$user = $stm->fetch();

// --- POST HANDLER: Update Profile Info ---
if (is_post() && post('action') == 'update_profile') {
    $username = req('username');
    $phone    = req('phone_number');
    
    // Simple Validation
    if ($username == '') {
        $_err['username'] = 'Username cannot be empty';
    } elseif ($username !== $user->username && is_exists($username, 'users', 'username')) {
        $_err['username'] = 'Username already taken';
    }

    // Process Photo Upload if provided
    $photo = $user->profile_photo; // Keep old photo by default
    $f = get_file('photo'); // Helper from base.php
    
    if ($f) {
        // Create directory if not exists
        if (!file_exists('images/profiles')) {
            mkdir('images/profiles', 0777, true);
        }
        
        // Remove old photo if exists
        if ($user->profile_photo && file_exists("images/profiles/$user->profile_photo")) {
            unlink("images/profiles/$user->profile_photo");
        }

        // Save new photo (Resize to 200x200 using base.php helper)
        $photo = save_photo($f, 'images/profiles'); 
    }

    if (!$_err) {
        $stm = $_db->prepare("UPDATE users SET username = ?, phone_number = ?, profile_photo = ? WHERE user_id = ?");
        $stm->execute([$username, $phone, $photo, $user->user_id]);
        
        // Update Session
        $user->username = $username;
        $user->phone_number = $phone;
        $user->profile_photo = $photo;
        $_SESSION['user'] = $user;
        $_SESSION['username'] = $username;

        temp('info', 'Profile updated successfully!');
        redirect('profile.php');
    }
}

// --- POST HANDLER: Change Password ---
if (is_post() && post('action') == 'change_password') {
    $current_pass = req('current_password');
    $new_pass     = req('new_password');
    $confirm_pass = req('confirm_password');

    // Verify Old Password
    if (!password_verify($current_pass, $user->password_hash)) {
        $_err['current_password'] = 'Incorrect current password';
    }

    // Validate New Password
    if (strlen($new_pass) < 6) {
        $_err['new_password'] = 'Password must be at least 6 chars';
    }
    if ($new_pass !== $confirm_pass) {
        $_err['confirm_password'] = 'Passwords do not match';
    }

    if (!$_err) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        
        $stm = $_db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stm->execute([$new_hash, $user->user_id]);

        temp('info', 'Password changed successfully!');
        redirect('profile.php');
    }
}
?>

<div class="shop-container" style="max-width: 800px; margin: 40px auto;">
    <h1>My Profile</h1>
    
    <?php if ($msg = temp('info')): ?>
        <div class="alert alert-success" style="padding: 10px; background: #d4edda; color: #155724; border-radius: 5px; margin-bottom: 20px;">
            <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="profile-layout" style="display: flex; gap: 40px; flex-wrap: wrap;">
        
        <div class="profile-left" style="flex: 1; min-width: 300px;">
            <form method="post" enctype="multipart/form-data" class="card" style="padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                <input type="hidden" name="action" value="update_profile">
                
                <div style="text-align: center; margin-bottom: 20px;">
                    <?php 
                        $img = $user->profile_photo ? "images/profiles/$user->profile_photo" : "images/no-image.jpg"; 
                    ?>
                    <img src="<?= $img ?>" alt="Profile" style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 3px solid #eee;">
                    
                    <div style="margin-top: 10px;">
                        <label for="photo" style="cursor: pointer; color: #007bff; font-size: 0.9em;">Change Photo</label>
                        <input type="file" id="photo" name="photo" style="display: none;" onchange="this.form.submit()">
                    </div>
                </div>

                <div class="form-group">
                    <label>Email (Cannot change)</label>
                    <input type="text" value="<?= htmlspecialchars($user->email) ?>" disabled style="background: #f9f9f9; cursor: not-allowed;">
                </div>

                <div class="form-group">
                    <label>Username</label>
                    <?php html_text('username', "value='$user->username'"); ?>
                    <?php err('username'); ?>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone_number" value="<?= htmlspecialchars($_POST['phone_number'] ?? $user->phone_number) ?>">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Update Profile</button>
            </form>
        </div>

        <div class="profile-right" style="flex: 1; min-width: 300px;">
            <form method="post" class="card" style="padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                <h3 style="margin-top: 0;">Change Password</h3>
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label>Current Password</label>
                    <?php html_password('current_password'); ?>
                    <?php err('current_password'); ?>
                </div>

                <div class="form-group">
                    <label>New Password</label>
                    <?php html_password('new_password'); ?>
                    <?php err('new_password'); ?>
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <?php html_password('confirm_password'); ?>
                    <?php err('confirm_password'); ?>
                </div>

                <button type="submit" class="btn btn-warning" style="width: 100%; margin-top: 10px;">Change Password</button>
            </form>
            
            <div style="margin-top: 20px; text-align: right;">
                <a href="logout.php" class="btn" style="color: red;">Logout</a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>