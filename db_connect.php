<?php
$host = 'localhost';         // Database host
$db   = 'keja_smart';        // Database name
$user = 'root';              // MySQL username
$pass = '';                  // MySQL password
$charset = 'utf8mb4';        // Character set

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Show errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Use native prepared statements
];

try {
    $conn = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
?>
