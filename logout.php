<?php
// logout.php
require 'base.php'; // Includes session_start()

// 1. Unset all session variables
$_SESSION = [];

// 2. Destroy the session cookie (PHPSESSID)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the "Remember Me" cookie (Crucial if you implemented it)
if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 3600, "/");
    unset($_COOKIE['remember_me']);
}

// 4. Destroy the session
session_destroy();

// 5. Redirect to Home or Login
redirect('login.php');
?>