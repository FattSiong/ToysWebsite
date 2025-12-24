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
    // Logic: Keep old photo filename by default. If new upload, replace it.
    $photo = $user['profile_photo'];
    $f = get_file('photo');
    if ($f) {
        // Ensure directory exists
        if (!file_exists('images/profiles')) {
            mkdir('images/profiles', 0777, true);
        }
        // Remove old photo if it exists to save space
        if ($user['profile_photo'] && file_exists("images/profiles/$user[profile_photo]")) {
            unlink("images/profiles/$user[profile_photo]");
        }
        // Save new photo and get the generated filename (e.g., '65a1b2c3.jpg')
        // The path will be saved to the database as just the filename.
        $photo = save_photo($f, 'images/profiles');
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
    if (strlen($new_pass) < 6) $_err['new_password'] = 'Password must be at least 6 chars';
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

    // 3. Handle Delete
    if (is_post() && post('action') == 'delete_address') {
        $addr_id = req('address_id');
        $stm = $_db->prepare("DELETE FROM shipping_addresses WHERE shipping_address_id = ? AND user_id = ?");
        $stm->execute([$addr_id, $user['user_id']]);
        temp('info', 'Address removed.');
        redirect('profile.php');
    }

    // 4. Fetch Addresses
    $stm = $_db->prepare("SELECT * FROM shipping_addresses WHERE user_id = ? ORDER BY shipping_address_id DESC");
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
                    $img_path = "images/profiles/no_profile_image.jpg";
                    if (!empty($user['profile_photo']) && file_exists("images/profiles/" . $user['profile_photo'])) {
                        $img_path = "images/profiles/" . $user['profile_photo'];
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
                    <?php html_password('current_password');
                    err('current_password'); ?>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <?php html_password('new_password');
                    err('new_password'); ?>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <?php html_password('confirm_password');
                    err('confirm_password'); ?>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Update Password</button>
            </form>
        </div>
    </div>

    <?php if ($user['role'] !== 'admin'): ?>
        <div class="address-section">
            <div class="profile-header">
                <h2>Address Book</h2>
            </div>

            <div class="profile-grid">
                <div class="profile-card">
                    <h3><?= $edit_mode ? 'Edit Address' : 'Add New Address' ?></h3>

                    <form method="post" class="scroll-trigger">
                        <input type="hidden" name="action" value="<?= $edit_mode ? 'update_address' : 'add_address' ?>">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="address_id" value="<?= $edit_id ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Receiver Name</label>
                            <?php html_text('receiver_name');
                            err('receiver_name'); ?>
                        </div>

                        <div class="form-group">
                            <label>Address Line 1</label>
                            <?php html_text('line1');
                            err('line1'); ?>
                        </div>

                        <div class="form-group">
                            <label>Line 2 (Optional)</label>
                            <?php html_text('line2'); ?>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Postcode</label>
                                <?php html_text('postcode');
                                err('postcode'); ?>
                            </div>
                            <div class="form-group">
                                <label>City</label>
                                <?php html_text('city');
                                err('city'); ?>
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
                            ]); ?>
                            <?php err('state'); ?>
                        </div>

                        <div class="form-group">
                            <label>Receiver Phone</label>
                            <?php html_text('addr_phone', "placeholder='01x-xxxxxxx'");
                            err('addr_phone'); ?>
                        </div>

                        <button class="btn btn-primary btn-block"><?= $edit_mode ? 'Save Changes' : 'Save Address' ?></button>

                        <?php if ($edit_mode): ?>
                            <a href="profile.php" class="btn btn-block" style="text-align:center; display:block; background:#dc3545; color:white; margin-top:5px; padding:13px; border-radius:5px;">Cancel</a>
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

<?php include 'includes/footer.php'; ?>