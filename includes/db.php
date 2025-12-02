<?php
// includes/db.php

// 1. Database Credentials
$host = 'localhost';
$db_name = 'toy website database';
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
?>