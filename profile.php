<?php
// profile.php
require 'base.php';
include 'includes/header.php';

// 1. Ensure User is Logged In
if (!$_user) {
    redirect('login.php');
}

// 2. Fetch latest user data
$stm = $_db->prepare("SELECT * FROM users WHERE user_id = ?");
$stm->execute([$_user['user_id']]);
$user = $stm->fetch();

// 3. Set Global Defaults
$username     = $user['username'];
$phone_number = $user['phone_number'];

// --- POST HANDLER: Update Profile Info ---
if (is_post() && post('action') == 'update_profile') {
    // ... (Keep existing Profile Update logic) ...
    $username = req('username');
    $phone    = req('phone_number');
    $phone_changed = ($phone !== $user['phone_number']);

    if ($username == '') {
        $_err['username'] = 'Username cannot be empty';
    } elseif ($username !== $user['username'] && is_exists($username, 'users', 'username')) {
        $_err['username'] = 'Username already taken';
    }

    if ($phone_changed) {
        if ($phone == '') {
            // Depending on rules, maybe allow empty? Assuming NOT allowed for this logic.
            $_err['phone_number'] = 'Phone number is required';
        } else {
            // Regex Check
            if (!preg_match('/^01[0-9]-?[0-9]{7,8}$/', $phone)) {
                $_err['phone_number'] = 'Invalid format. Use 01x-xxxxxxx';
            } else {
                // Role-Based Uniqueness Check
                // "Check if phone not used by others 'member' account if user is member..."
                // "Else if user is admin, check if phone not used by others admin."

                $stm = $_db->prepare("SELECT COUNT(*) FROM users WHERE phone_number = ? AND role = ? AND user_id != ?");
                $stm->execute([$phone, $user['role'], $user['user_id']]);
                $count = $stm->fetchColumn();

                if ($count > 0) {
                    $_err['phone_number'] = "This phone number is already used by another " . $user['role'];
                }
            }
        }
    }

    // 3. Handle Photo Upload
    $photo = $user['profile_photo'];
    $f = get_file('photo');
    if ($f) {
        // Ensure directory exists (Changed to uploads/profile)
        if (!file_exists('uploads/profiles')) {
            mkdir('uploads/profiles', 0777, true);
        }
        
        // Remove old photo from the NEW location if it exists
        // (Note: If you have old photos in 'images/profiles', you might want to manually move them or check both)
        if ($user['profile_photo'] && file_exists("uploads/profiles/$user[profile_photo]")) {
            unlink("uploads/profiles/$user[profile_photo]");
        }
        
        // Save new photo to 'uploads/profile'
        $photo = save_photo($f, 'uploads/profiles');
    }

    if (!$_err) {
        $stm = $_db->prepare("UPDATE users SET username = ?, phone_number = ?, profile_photo = ? WHERE user_id = ?");
        $stm->execute([$username, $phone, $photo, $user['user_id']]);

        $user['username'] = $username;
        $user['profile_photo'] = $photo;
        $_SESSION['user'] = $user;
        $_SESSION['username'] = $username;

        if ($phone_changed) {
            // DO NOT update phone in DB yet.
            // Store it in session and redirect to verification
            $_SESSION['temp_new_phone'] = $phone;
            temp('info', 'Profile details updated. Please verify your new phone number.');
            redirect('verify_phone.php');
        } else {
            temp('info', 'Profile updated successfully!');
            redirect('profile.php');
        }
    }
}

// --- POST HANDLER: Change Password ---
if (is_post() && post('action') == 'change_password') {
    // ... (Keep existing Password logic) ...
    $current_pass = req('current_password');
    $new_pass     = req('new_password');
    $confirm_pass = req('confirm_password');

    if (!password_verify($current_pass, $user['password_hash'])) $_err['current_password'] = 'Incorrect current password';
    // Validate New Password
    if ($error = check_password($new_pass)) {
        $_err['new_password'] = $error;
    }
    if ($new_pass !== $confirm_pass) $_err['confirm_password'] = 'Passwords do not match';

    if (!$_err) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stm = $_db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stm->execute([$new_hash, $user['user_id']]);
        temp('info', 'Password changed successfully!');
        redirect('profile.php');
    }
}

// ============================================================================
// ADDRESS LOGIC (Add / Edit / List)
// ============================================================================
$addresses = [];
$edit_mode = false;
$edit_id   = null;

// Initialize Form Variables
$receiver_name = '';
$line1 = '';
$line2 = '';
$city = '';
$state = '';
$postcode = '';
$addr_phone = '';

if ($user['role'] !== 'admin') {

    // 1. Check if Editing
    if (req('edit_id')) {
        $edit_id = req('edit_id');
        $stm = $_db->prepare("SELECT * FROM shipping_addresses WHERE shipping_address_id = ? AND user_id = ?");
        $stm->execute([$edit_id, $user['user_id']]);
        $data = $stm->fetch();

        if ($data) {
            $edit_mode = true;
            // Populate form variables from DB
            $receiver_name = $data['receiver_name'];
            $line1         = $data['line1'];
            $line2         = $data['line2'];
            $city          = $data['city'];
            $state         = $data['state'];
            $postcode      = $data['postcode'];
            $addr_phone    = $data['phone'];
        }
    }

    // 2. Handle Form Submission (Add OR Update)
    if (is_post() && (post('action') == 'add_address' || post('action') == 'update_address')) {
        // Grab values from POST (overwrites DB values if validation fails)
        $receiver_name = req('receiver_name');
        $line1    = req('line1');
        $line2    = req('line2');
        $city     = req('city');
        $state    = req('state');
        $postcode = req('postcode');
        $addr_phone = req('addr_phone');

        // Validation
        if ($receiver_name == '') $_err['receiver_name'] = 'Receiver Name is required';
        if ($line1 == '')    $_err['line1'] = 'Address Line 1 is required';
        if ($city == '')     $_err['city']  = 'City is required';
        if ($state == '')    $_err['state'] = 'State is required';
        if ($postcode == '') $_err['postcode'] = 'Postcode is required';

        if ($addr_phone == '') {
            $_err['addr_phone'] = 'Phone is required';
        } elseif (!preg_match('/^01[0-9]-?[0-9]{7,8}$/', $addr_phone)) {
            $_err['addr_phone'] = 'Invalid format. Use 01x-xxxxxxx';
        }

        if (!$_err) {
            if (post('action') == 'update_address') {
                // UPDATE
                $stm = $_db->prepare("
                    UPDATE shipping_addresses 
                    SET receiver_name=?, line1=?, line2=?, city=?, state=?, postcode=?, phone=?
                    WHERE shipping_address_id=? AND user_id=?
                ");
                $stm->execute([$receiver_name, $line1, $line2, $city, $state, $postcode, $addr_phone, req('address_id'), $user['user_id']]);
                temp('info', 'Address updated successfully!');
            } else {
                // INSERT
                $stm = $_db->prepare("
                    INSERT INTO shipping_addresses (user_id, receiver_name, line1, line2, city, state, postcode, phone)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stm->execute([$user['user_id'], $receiver_name, $line1, $line2, $city, $state, $postcode, $addr_phone]);
                temp('info', 'Address added successfully!');
            }
            redirect('profile.php'); // Clear URL params
        }
    }

    if (is_post() && post('action') == 'delete_address') {
        $addr_id = req('address_id');
        $stm = $_db->prepare("DELETE FROM shipping_addresses WHERE shipping_address_id = ? AND user_id = ?");
        $stm->execute([$addr_id, $user['user_id']]);
        temp('info', 'Address removed.');
        redirect('profile.php');
    }

    if (is_post() && post('action') == 'set_default') {
        $addr_id = req('address_id');

        // 1. Reset all addresses for this user to 0
        $stm = $_db->prepare("UPDATE shipping_addresses SET is_default = 0 WHERE user_id = ?");
        $stm->execute([$user['user_id']]);

        // 2. Set the selected address to 1
        $stm = $_db->prepare("UPDATE shipping_addresses SET is_default = 1 WHERE shipping_address_id = ? AND user_id = ?");
        $stm->execute([$addr_id, $user['user_id']]);

        temp('info', 'Default address updated successfully!');
        redirect('profile.php');
    }

    $stm = $_db->prepare("SELECT * FROM shipping_addresses WHERE user_id = ? ORDER BY is_default DESC, shipping_address_id DESC");
    $stm->execute([$user['user_id']]);
    $addresses = $stm->fetchAll();
}
?>

<link rel="stylesheet" href="css/profile.css">

<div class="profile-container">
    <div class="profile-header">
        <h1>My Profile</h1>
    </div>

    <?php if ($msg = temp('info')): ?>
        <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>

    <div class="profile-grid">
        <div class="profile-card">
            <h3>Personal Details</h3>
            <form method="post" enctype="multipart/form-data" class="scroll-trigger">
                <input type="hidden" name="action" value="update_profile">

                <div class="profile-img-container">
                    <?php
                    // Default image stays in images/profiles/ as it is a static asset
                    $img_path = "uploads/profiles/no_profile_image.jpg";
                    
                    // Check if the user has a custom photo in the NEW location
                    if (!empty($user['profile_photo']) && file_exists("uploads/profiles/" . $user['profile_photo'])) {
                        $img_path = "uploads/profiles/" . $user['profile_photo'];
                    }
                    ?>
                    <img src="<?= $img_path ?>" alt="Profile" class="profile-img">
                    <br>
                    <label for="photo" class="change-photo-btn">Change Photo</label>
                    <input type="file" id="photo" name="photo" style="display: none;" onchange="this.form.submit()">
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="text" value="<?= htmlspecialchars($user['email']) ?>" disabled style="background: #f9f9f9;">
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <?php html_text('username');
                    err('username'); ?>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <?php html_text('phone_number'); ?>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Update Details</button>
            </form>
        </div>

        <div class="profile-card">
            <h3>Change Password</h3>
            <form method="post" class="scroll-trigger">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label>Current Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="current_password" name="current_password" class="form-control">
                        <i class="fas icon-eye-slash toggle-password" data-target="current_password"></i>
                    </div>
                    <span class="error"><?= err('current_password') ?></span>
                </div>

                <div class="form-group">
                    <label>New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="new_password" name="new_password" class="form-control">
                        <i class="fas icon-eye-slash toggle-password" data-target="new_password"></i>
                    </div>
                    <span class="error"><?= err('new_password') ?></span>
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control">
                        <i class="fas fa-eye toggle-password" data-target="confirm_password"></i>
                    </div>
                    <span class="error"><?= err('confirm_password') ?></span>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Update Password</button>
            </form>
        </div>
    </div>

    <?php if ($user['role'] !== 'admin'): ?>
        <div class="profile-card">
            <h3><?= $edit_mode ? 'Edit Address' : 'Add New Address' ?></h3>

            <form method="post" class="scroll-trigger">
                <input type="hidden" name="action" value="<?= $edit_mode ? 'update_address' : 'add_address' ?>">
                <?php if ($edit_mode): ?><input type="hidden" name="address_id" value="<?= $edit_id ?>"><?php endif; ?>

                <div class="map-widget">
                    <div class="map-header">
                        <div class="map-title">
                            <i class="fas fa-map-marked-alt" style="color: #007bff;"></i>
                            Address Finder
                        </div>
                    </div>

                    <div class="map-search-group">
                        <i class="fas fa-search search-icon-marker"></i>
                        <input type="text" id="address_search" class="map-search-input" placeholder="Search address (e.g. KLCC, 1 Utama)...">

                        <button type="button" id="btn-locate-me" class="btn-locate" title="Use My Current Location">
                            <i class="fas fa-location-arrow"></i> Use My Location
                        </button>
                    </div>

                    <div id="map"></div>
                    <div style="margin-top: 10px; font-size: 0.85em; color: #6c757d;">
                        <i class="fas fa-info-circle"></i> Tip: You can drag the red pin to fine-tune your location.
                    </div>
                </div>
                <div class="form-group"><label>Receiver Name</label><?php html_text('receiver_name', 'id="receiver_name"');
                                                                    err('receiver_name'); ?></div>

                <div class="form-group">
                    <label>Address Line 1</label>
                    <?php html_text('line1', 'id="line1"'); ?>
                    <?php err('line1'); ?>
                </div>

                <div class="form-group">
                    <label>Line 2 (Optional)</label>
                    <?php html_text('line2', 'id="line2"'); ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Postcode</label>
                        <?php html_text('postcode', 'id="postcode"'); ?>
                        <?php err('postcode'); ?>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <?php html_text('city', 'id="city"'); ?>
                        <?php err('city'); ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>State</label>
                    <?php html_select('state', [
                        'Johor' => 'Johor',
                        'Kedah' => 'Kedah',
                        'Kelantan' => 'Kelantan',
                        'Melaka' => 'Melaka',
                        'Negeri Sembilan' => 'Negeri Sembilan',
                        'Pahang' => 'Pahang',
                        'Penang' => 'Penang',
                        'Perak' => 'Perak',
                        'Perlis' => 'Perlis',
                        'Sabah' => 'Sabah',
                        'Sarawak' => 'Sarawak',
                        'Selangor' => 'Selangor',
                        'Terengganu' => 'Terengganu',
                        'Kuala Lumpur' => 'Kuala Lumpur',
                        'Putrajaya' => 'Putrajaya',
                        'Labuan' => 'Labuan'
                    ], 'Select'); ?>
                    <?php err('state'); ?>
                </div>

                <div class="form-group"><label>Receiver Phone</label><?php html_text('addr_phone', "id='addr_phone' placeholder='01x-xxxxxxx'");
                                                                        err('addr_phone'); ?></div>

                <button class="btn btn-primary btn-block"><?= $edit_mode ? 'Save Changes' : 'Save Address' ?></button>

                <?php if ($edit_mode): ?>
                    <a href="profile.php" class="btn btn-primary btn-block btn-cancel">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="address-scroll-container">
            <?php if (empty($addresses)): ?>
                <p style="color: #777; text-align:center; padding: 20px;">You have not added any shipping addresses yet.</p>
            <?php else: ?>
                <?php foreach ($addresses as $addr): ?>
                    <?php
                    // Highlight the one being edited
                    $active_style = ($edit_mode && $addr['shipping_address_id'] == $edit_id) ? "border-color: #ffc107; box-shadow: 0 0 5px rgba(255,193,7,0.5);" : "";
                    ?>
                    <div class="address-card" style="margin-bottom: 20px; <?= $active_style ?>">
                        <div class="address-tag">Shipping Address</div>
                        <div style="font-weight: bold; font-size: 1.1em; margin: 10px 0 5px;">
                            <?= encode($addr['receiver_name']) ?>
                        </div>

                        <div style="color: #555; line-height: 1.5;">
                            <?= encode($addr['line1']) ?><br>
                            <?php if ($addr['line2']) echo encode($addr['line2']) . "<br>"; ?>
                            <?= encode($addr['postcode']) ?> <?= encode($addr['city']) ?><br>
                            <?= encode($addr['state']) ?>
                        </div>

                        <div style="margin-top: 10px; color: #333;">
                            📞 <?= encode($addr['phone']) ?>
                        </div>

                        <div class="address-actions">
                            <?php if ($addr['is_default'] == 1): ?>
                                <span class="btn-active-default">
                                    ✓ Default
                                </span>
                            <?php else: ?>
                                <form method="post" style="display: inline;" class="scroll-trigger">
                                    <input type="hidden" name="action" value="set_default">
                                    <input type="hidden" name="address_id" value="<?= $addr['shipping_address_id'] ?>">
                                    <button class="btn btn-sm btn-set-default">Set Default</button>
                                </form>
                            <?php endif; ?>
                            <a href="?edit_id=<?= $addr['shipping_address_id'] ?>" class="btn btn-sm btn-edit scroll-trigger">Edit</a>

                            <form method="post" style="display: inline;" class="scroll-trigger">
                                <input type="hidden" name="action" value="delete_address">
                                <input type="hidden" name="address_id" value="<?= $addr['shipping_address_id'] ?>">
                                <button onclick="return confirm('Are you sure?')" class="btn btn-sm btn-danger">Remove</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
</div>
</div>
<?php endif; ?>
</div>

<script src="js/address_map.js"></script>

<script async
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDYNHVklDNXKLgYsqptzsqTcUVPvHqNVy0&libraries=places&callback=initMap">
</script>

<?php include 'includes/footer.php'; ?>