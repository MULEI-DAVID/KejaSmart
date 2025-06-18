<?php
/**
 * KejaSmart Application Functions
 */

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate secure password hash
 */
function generateHash($password) {
    return password_hash($password . PEPPER, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against hash
 */
function verifyPassword($password, $hash) {
    return password_verify($password . PEPPER, $hash);
}

/**
 * Generate random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Send verification email
 */
function sendVerificationEmail($email, $token) {
    $subject = APP_NAME . " - Verify Your Email";
    $verificationUrl = APP_URL . "/verify.php?token=" . urlencode($token);
    
    $message = <<<EMAIL
    <html>
    <body>
        <h2>Welcome to {APP_NAME}</h2>
        <p>Please click the link below to verify your email address:</p>
        <p><a href="{$verificationUrl}">Verify Email</a></p>
        <p>If you didn't request this, please ignore this email.</p>
    </body>
    </html>
    EMAIL;
    
    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion()
    ];
    
    return mail($email, $subject, $message, implode("\r\n", $headers));
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Redirect with message
 */
function redirect($url, $message = null, $type = 'success') {
    if ($message) {
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type
        ];
    }
    header("Location: $url");
    exit();
}

/**
 * Display flash message
 */
function displayFlash() {
    if (isset($_SESSION['flash'])) {
        $message = $_SESSION['flash']['message'];
        $type = $_SESSION['flash']['type'];
        unset($_SESSION['flash']);
        
        echo "<div class='alert alert-$type'>$message</div>";
    }
}

/**
 * Check for brute force attempts
 */
function checkLoginAttempts($email, $conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts 
                           WHERE email = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$email, LOCKOUT_TIME]);
    $attempts = $stmt->fetchColumn();
    
    if ($attempts >= LOCKOUT_THRESHOLD) {
        return true; // Account is locked
    }
    return false;
}

/**
 * Log login attempt
 */
function logLoginAttempt($email, $success, $conn) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)");
    $stmt->execute([$email, $ip, (int)$success]);
}

/**
 * Get user by ID
 */
function getUserById($id, $conn) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Get user by email
 */
function getUserByEmail($email, $conn) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Notify admin
 */
function notifyAdmin($message) {
    // In a real app, this would send an email or notification
    error_log("ADMIN NOTIFICATION: " . $message);
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return 'KSh ' . number_format($amount, 2);
}

/**
 * Get current user role
 */
function currentUserRole() {
    return $_SESSION['user_type'] ?? null;
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    return currentUserRole() === $role;
}

/**
 * Get current user data
 */
function currentUser($conn) {
    if (!isLoggedIn()) return null;
    
    $userType = currentUserRole();
    $userId = $_SESSION['user_id'];
    
    switch ($userType) {
        case 'admin':
            $table = 'admins';
            break;
        case 'landlord':
            $table = 'landlords';
            break;
        case 'tenant':
            $table = 'tenants';
            break;
        default:
            return null;
    }
    
    $stmt = $conn->prepare("SELECT u.*, t.* FROM users u 
                           JOIN $table t ON u.id = t.id 
                           WHERE u.id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}