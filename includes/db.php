<?php
// includes/db.php

// 1. Database Credentials
$host = 'localhost';
$db_name = 'toy_website_database';
$username = 'root';
$password = '';

// 2. DSN (Data Source Name)
$dsn = "mysql:host=$host;dbname=$db_name;charset=utf8mb4";

// 3. Set Options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // Return arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                // Better security
];

// 4. Create Connection
try {
    $pdo = new PDO($dsn, $username, $password, $options);
    //echo "Connected successfully"; // Uncomment this line to test, then comment it out again
} catch (\PDOException $e) {
    // If connection fails, stop everything and show error
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// ... (Database connection code above) ...

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// FORCE LOGIN FOR TESTING (Remove this part when you finish the project!)
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;         // Assume User ID 1 is logged in
    $_SESSION['username'] = 'Tester';
    $_SESSION['role'] = 'member';     // Give them member role
}

?>