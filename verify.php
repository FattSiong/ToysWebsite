<?php
require 'includes/db.php';

$message = "";
$color = "red";

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // 1. Check if token exists in the new table
    $stmt = $pdo->prepare("SELECT user_id, expires_at FROM email_verifications WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if ($row) {
        // 2. Check if expired
        if (strtotime($row['expires_at']) < time()) {
            $message = "This verification link has expired.";
        } else {
            // 3. Activate User
            $update = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE user_id = ?");
            $update->execute([$row['user_id']]);

            // 4. Delete the used token (Cleanup)
            $del = $pdo->prepare("DELETE FROM email_verifications WHERE token = ?");
            $del->execute([$token]);

            $message = "Account verified successfully! You can now <a href='login.php'>Login</a>.";
            $color = "green";
        }
    } else {
        $message = "Invalid verification token.";
    }
} else {
    $message = "No token provided.";
}
?>

<!DOCTYPE html>
<html>
<head><title>Verification</title></head>
<body style="text-align: center; padding-top: 50px; font-family: Arial;">
    <h1 style="color: <?php echo $color; ?>"><?php echo $message; ?></h1>
</body>
</html>