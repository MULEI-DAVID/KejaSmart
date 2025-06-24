<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'kejasmart');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'KejaSmart');
define('APP_URL','http://kejasmart.test/');
define('APP_ENV', 'development');

// Security Settings
define('PEPPER', 'your-random-pepper-string-here');
define('TOKEN_EXPIRY', 3600);
define('LOCKOUT_THRESHOLD', 5);
define('LOCKOUT_TIME', 900);

// Create database connection
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
    $conn->exec("SET time_zone = '+3:00'");
    
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("System temporarily unavailable. Please try again later.");
}

// Session Configuration for LOCALHOST - MUST BE BEFORE session_start()
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => false, // Changed to false for localhost
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    session_start();
}

// Admin authentication check
if (!isset($_SESSION['admin_id']) && basename($_SERVER['PHP_SELF']) !== 'login.php' && basename($_SERVER['PHP_SELF']) !== 'register.php') {
    // Only redirect to login if not already on login page
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        header("Location: login.php");
        exit();
    }
}

// Fetch admin data if logged in
$admin = [];
if (isset($_SESSION['admin_id'])) {
    try {
        $stmt = $conn->prepare("SELECT id, name, email, role, join_date FROM admins WHERE id = :id");
        $stmt->bindValue(':id', $_SESSION['admin_id'], PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() === 1) {
            $admin = $stmt->fetch();
            $admin['avatar'] = substr($admin['name'], 0, 1) . 
                              (($spacePos = strpos($admin['name'], ' ')) !== false ? 
                              substr($admin['name'], $spacePos + 1, 1) : '');
        } else {
            // Invalid session, destroy it
            session_destroy();
            header("Location: login.php");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Admin fetch error: " . $e->getMessage());
        die("System error occurred. Please try again later.");
    }
}

// Initialize dashboard statistics
$stats = [
    'landlords' => 0,
    'tenants' => 0,
    'properties' => 0,
    'active_leases' => 0,
    'pending_tickets' => 0,
    'monthly_revenue' => 0,
    'mpesa_transactions' => 0
];

// Fetch statistics only if admin is logged in
if (isset($_SESSION['admin_id'])) {
    $queries = [
        'landlords' => "SELECT COUNT(*) AS total FROM landlords",
        'tenants' => "SELECT COUNT(*) AS total FROM tenants",
        'properties' => "SELECT COUNT(*) AS total FROM properties",
        'active_leases' => "SELECT COUNT(*) AS total FROM leases WHERE status = 'active'",
        'pending_tickets' => "SELECT COUNT(*) AS total FROM support_tickets WHERE status = 'open'",
        'monthly_revenue' => "SELECT SUM(amount) AS total FROM payments WHERE MONTH(payment_date) = MONTH(CURRENT_DATE())",
        'mpesa_transactions' => "SELECT COUNT(*) AS total FROM mpesa_transactions"
    ];

    foreach ($queries as $key => $sql) {
        try {
            $stmt = $conn->query($sql);
            $row = $stmt->fetch();
            $stats[$key] = $row['total'] ?? 0;
        } catch (PDOException $e) {
            error_log("Statistics error ($key): " . $e->getMessage());
        }
    }
}

// Handle form submissions (only if admin is logged in)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['admin_id'])) {
    // Add Landlord
    if (isset($_POST['add_landlord'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        
        $errors = [];
        if (empty($name)) $errors[] = "Name is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
        if (empty($phone)) $errors[] = "Phone number is required";
        
        if (empty($errors)) {
            try {
                $sql = "INSERT INTO landlords (name, email, phone, status, join_date) 
                        VALUES (:name, :email, :phone, 'active', NOW())";
                
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':email', $email, PDO::PARAM_STR);
                $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
                $stmt->execute();
                
                $_SESSION['success'] = "Landlord added successfully!";
            } catch (PDOException $e) {
                error_log("Landlord add error: " . $e->getMessage());
                $_SESSION['error'] = "Error adding landlord. Please try again.";
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
    }
    
    // Add Tenant
    if (isset($_POST['add_tenant'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $property = (int)$_POST['property'];
        $unit = trim($_POST['unit']);
        
        $errors = [];
        if (empty($name)) $errors[] = "Name is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
        if (empty($phone)) $errors[] = "Phone number is required";
        if ($property <= 0) $errors[] = "Invalid property selection";
        if (empty($unit)) $errors[] = "Unit number is required";
        
        if (empty($errors)) {
            try {
                $sql = "INSERT INTO tenants (name, email, phone, property_id, unit, status, lease_end) 
                        VALUES (:name, :email, :phone, :property, :unit, 'active', DATE_ADD(NOW(), INTERVAL 1 YEAR))";
                
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':email', $email, PDO::PARAM_STR);
                $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
                $stmt->bindValue(':property', $property, PDO::PARAM_INT);
                $stmt->bindValue(':unit', $unit, PDO::PARAM_STR);
                $stmt->execute();
                
                $_SESSION['success'] = "Tenant added successfully!";
            } catch (PDOException $e) {
                error_log("Tenant add error: " . $e->getMessage());
                $_SESSION['error'] = "Error adding tenant. Please try again.";
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
    }
    
    // Add Property
    if (isset($_POST['add_property'])) {
        $name = trim($_POST['name']);
        $location = trim($_POST['location']);
        $owner = (int)$_POST['owner'];
        $units = (int)$_POST['units'];
        
        $errors = [];
        if (empty($name)) $errors[] = "Property name is required";
        if (empty($location)) $errors[] = "Location is required";
        if ($owner <= 0) $errors[] = "Invalid owner selection";
        if ($units <= 0) $errors[] = "Number of units must be positive";
        
        if (empty($errors)) {
            try {
                $sql = "INSERT INTO properties (name, location, owner_id, units, status) 
                        VALUES (:name, :location, :owner, :units, 'active')";
                
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':location', $location, PDO::PARAM_STR);
                $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
                $stmt->bindValue(':units', $units, PDO::PARAM_INT);
                $stmt->execute();
                
                $_SESSION['success'] = "Property added successfully!";
            } catch (PDOException $e) {
                error_log("Property add error: " . $e->getMessage());
                $_SESSION['error'] = "Error adding property. Please try again.";
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
    }
    
    // Update Settings
    if (isset($_POST['update_settings'])) {
        $system_name = trim($_POST['system_name']);
        $system_email = trim($_POST['system_email']);
        $currency = trim($_POST['currency']);
        $due_day = (int)$_POST['due_day'];
        
        $errors = [];
        if (empty($system_name)) $errors[] = "System name is required";
        if (!filter_var($system_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid system email";
        if (!in_array($currency, ['KES', 'USD', 'EUR'])) $errors[] = "Invalid currency";
        if ($due_day < 1 || $due_day > 28) $errors[] = "Invalid due day (1-28)";
        
        if (empty($errors)) {
            try {
                $sql = "UPDATE system_settings 
                        SET system_name = :sysname, 
                            system_email = :sysemail,
                            currency = :currency,
                            payment_due_day = :dueday 
                        WHERE id = 1";
                
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(':sysname', $system_name, PDO::PARAM_STR);
                $stmt->bindValue(':sysemail', $system_email, PDO::PARAM_STR);
                $stmt->bindValue(':currency', $currency, PDO::PARAM_STR);
                $stmt->bindValue(':dueday', $due_day, PDO::PARAM_INT);
                $stmt->execute();
                
                $_SESSION['success'] = "Settings updated successfully!";
            } catch (PDOException $e) {
                error_log("Settings update error: " . $e->getMessage());
                $_SESSION['error'] = "Error updating settings. Please try again.";
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
    }
    
    // Refresh page
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Fetch data for display (only if admin is logged in)
$display_data = [];
if (isset($_SESSION['admin_id'])) {
    $data_sets = [
        'landlords' => "SELECT id, name, email, phone, 
                       (SELECT COUNT(*) FROM properties WHERE owner_id = landlords.id) AS properties,
                       status, join_date 
                       FROM landlords ORDER BY join_date DESC LIMIT 10",
        
        'tenants' => "SELECT t.id, t.name, t.email, t.phone, p.name AS property, 
                     t.unit, t.status, t.lease_end 
                     FROM tenants t 
                     JOIN properties p ON t.property_id = p.id 
                     ORDER BY lease_end DESC LIMIT 10",
        
        'properties' => "SELECT p.id, p.name, p.location, l.name AS owner, 
                        p.units, p.status,
                        (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'occupied') AS occupied,
                        p.type
                        FROM properties p
                        JOIN landlords l ON p.owner_id = l.id
                        ORDER BY p.id DESC LIMIT 10",
        
        'payments' => "SELECT p.id, p.payment_date, t.name AS tenant_name, 
                      pr.name AS property, p.amount, p.method 
                      FROM payments p
                      JOIN tenants t ON p.tenant_id = t.id
                      JOIN properties pr ON t.property_id = pr.id
                      ORDER BY payment_date DESC LIMIT 10",
        
        'maintenance' => "SELECT mr.id, mr.created_at, p.name AS property, 
                         mr.unit, mr.description, mr.urgency, mr.status 
                         FROM maintenance_requests mr
                         JOIN properties p ON mr.property_id = p.id
                         ORDER BY created_at DESC LIMIT 10",
        
        'tickets' => "SELECT id, created_at, subject, user_email, priority, status 
                     FROM support_tickets ORDER BY created_at DESC LIMIT 10",
        
        'mpesa' => "SELECT transaction_id, transaction_date, amount, sender_phone, 
                   recipient, property, status 
                   FROM mpesa_transactions ORDER BY transaction_date DESC LIMIT 10"
    ];

    foreach ($data_sets as $key => $sql) {
        try {
            $stmt = $conn->query($sql);
            $display_data[$key] = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Data fetch error ($key): " . $e->getMessage());
            $display_data[$key] = [];
        }
    }
}

// Get system settings
$settings = [
    'system_name' => 'KejaSmart',
    'system_email' => 'noreply@kejasmart.com',
    'currency' => 'KES',
    'payment_due_day' => 5,
    'timezone' => 'Africa/Nairobi'
];

try {
    $stmt = $conn->query("SELECT * FROM system_settings WHERE id = 1");
    if ($row = $stmt->fetch()) {
        $settings = array_merge($settings, $row);
    }
} catch (PDOException $e) {
    error_log("Settings fetch error: " . $e->getMessage());
}

// Check if first login
$firstLogin = isset($_SESSION['first_login']) ? $_SESSION['first_login'] : false;
unset($_SESSION['first_login']);
?>