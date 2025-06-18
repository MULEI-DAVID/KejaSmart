<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'kejasmart');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'KejaSmart');
define('APP_URL', 'http://localhost/kejasmart'); // Update with actual URL
define('APP_ENV', 'development'); // 'production' or 'development'

// Security Settings
define('PEPPER', 'your-random-pepper-string-here'); // For password hashing
define('TOKEN_EXPIRY', 3600); // 1 hour in seconds
define('LOCKOUT_THRESHOLD', 5); // Max login attempts
define('LOCKOUT_TIME', 900); // 15 minutes in seconds

// M-Pesa Configuration (if applicable)
define('MPESA_ENV', 'sandbox'); // 'sandbox' or 'production'
define('MPESA_CONSUMER_KEY', 'your-consumer-key');
define('MPESA_CONSUMER_SECRET', 'your-consumer-secret');
define('MPESA_SHORTCODE', '174379'); // Sandbox: 174379
define('MPESA_PASSKEY', 'your-passkey');
define('MPESA_CALLBACK_URL', APP_URL.'/mpesa_callback.php');

// Email Settings
define('MAIL_FROM', 'no-reply@kejasmart.com');
define('MAIL_FROM_NAME', 'KejaSmart System');

// Create database connection
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Set timezone if needed
    $conn->exec("SET time_zone = '+3:00'"); // East Africa Time
    
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    
    // Don't expose errors in production
    if (APP_ENV === 'development') {
        die("Database connection failed: " . $e->getMessage());
    } else {
        die("System temporarily unavailable. Please try again later.");
    }
}

// Session Configuration
session_set_cookie_params([
    'lifetime' => 86400, // 1 day
    'path' => '/',
    'domain' => '', // Set to your domain in production
    'secure' => (APP_ENV === 'production'), // HTTPS only in production
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// Include functions
require_once __DIR__.'/functions.php';
?>