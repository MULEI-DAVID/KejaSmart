<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'kejasmart');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'KejaSmart');
define('APP_URL', 'http://localhost/kejasmart');
define('APP_ENV', 'production');

// Security Settings
define('PEPPER', 'c1isvFdxMDdmjOlvxpecFw');
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

// Session Configuration
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Admin authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch admin data
$admin = [];
try {
    $stmt = $conn->prepare("
        SELECT u.id, a.full_name AS name, u.email, a.role, a.join_date 
        FROM users u
        JOIN admins a ON u.id = a.id
        WHERE u.id = :id
    ");
    $stmt->bindValue(':id', $_SESSION['admin_id'], PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 1) {
        $admin = $stmt->fetch();
        $names = explode(' ', $admin['name']);
        $admin['avatar'] = strtoupper(substr($names[0], 0, 1) . (count($names) > 1 ? substr($names[1], 0, 1) : '');
    } else {
        session_destroy();
        header("Location: login.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Admin fetch error: " . $e->getMessage());
    die("System error occurred. Please try again later.");
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

// Fetch statistics
$queries = [
    'landlords' => "SELECT COUNT(*) AS total FROM landlords",
    'tenants' => "SELECT COUNT(*) AS total FROM tenants",
    'properties' => "SELECT COUNT(*) AS total FROM properties",
    'active_leases' => "SELECT COUNT(*) AS total FROM leases WHERE status = 'active'",
    'pending_tickets' => "SELECT COUNT(*) AS total FROM support_tickets WHERE status = 'open'",
    'monthly极_revenue' => "SELECT SUM(amount) AS total FROM payments WHERE MONTH(payment_date) = MONTH(CURRENT_DATE())",
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid CSRF token";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Add Landlord
    if (isset($_POST['add_landlord'])) {
        $firstName = trim($_POST['first_name']);
        $lastName = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $password = bin2hex(random_bytes(8)); // Temporary password
        
        $errors = [];
        if (empty($firstName)) $errors[] = "First name is required";
        if (empty($lastName)) $errors[] = "Last name is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
        if (empty($phone)) $errors[] = "Phone number is required";
        
        if (empty($errors)) {
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                // Create user
                $hashedPassword = password_hash($password . PEPPER, PASSWORD_DEFAULT);
                $userSql = "INSERT INTO users (email, password, user_type, is_verified) 
                            VALUES (:email, :password, 'landlord', 1)";
                $userStmt = $conn->prepare($userSql);
                $userStmt->bindValue(':email', $email, PDO::PARAM_STR);
                $userStmt->bindValue(':password', $hashedPassword, PDO::PARAM_STR);
                $userStmt->execute();
                $userId = $conn->lastInsertId();
                
                // Create landlord
                $landlordSql = "INSERT INTO landlords (id, first_name, last_name, phone, status) 
                                VALUES (:id, :fname, :lname, :phone, 'approved')";
                $landlordStmt = $conn->prepare($landlordSql);
                $landlordStmt->bindValue(':id', $userId, PDO::PARAM_INT);
                $landlordStmt->bindValue(':fname', $firstName, PDO::PARAM_STR);
                $landlordStmt->bindValue(':lname', $lastName, PDO::PARAM_STR);
                $landlordStmt->bindValue(':phone', $phone, PDO::PARAM_STR);
                $landlordStmt->execute();
                
                $conn->commit();
                
                $_SESSION['success'] = "Landlord added successfully! Temporary password: $password";
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Landlord add error: " . $e->getMessage());
                $_SESSION['error'] = "Error adding landlord. Please try again.";
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
    }
    
    // Add Tenant
    if (isset($_POST['add_tenant'])) {
        $firstName = trim($_POST['first_name']);
        $lastName = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $nationalId = trim($_POST['national_id']);
        $property = (int)$_POST['property']);
        $unit = trim($_POST['unit']);
        $password = bin2hex(random_bytes(8)); // Temporary password
        
        $errors = [];
        if (empty($firstName)) $errors[] = "First name is required";
        if (empty($lastName)) $errors[] = "Last name is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
        if (empty($phone)) $errors[] = "Phone number is required";
        if (empty($nationalId)) $errors[] = "National ID is required";
        if ($property <= 0) $errors[] = "Invalid property selection";
        if (empty($unit)) $errors[] = "Unit number is required";
        
        if (empty($errors)) {
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                // Create user
                $hashedPassword = password_hash($password . PEPPER, PASSWORD_DEFAULT);
                $userSql = "INSERT INTO users (email, password, user_type, is_verified) 
                            VALUES (:email, :password, 'tenant', 1)";
                $userStmt = $conn->prepare($userSql);
                $userStmt->bindValue(':email', $email, PDO::PARAM_STR);
                $userStmt->bindValue(':password', $hashedPassword, PDO::PARAM_STR);
                $userStmt->execute();
                $userId = $conn->lastInsertId();
                
                // Create tenant
                $tenantSql = "INSERT INTO tenants (id, first_name, last_name, phone, national_id) 
                              VALUES (:id, :fname, :lname, :phone, :national_id)";
                $tenantStmt = $conn->prepare($tenantSql);
                $tenantStmt->bindValue(':id', $userId, PDO::PARAM_INT);
                $tenantStmt->bindValue(':fname', $firstName, PDO::PARAM_STR);
                $tenantStmt->bindValue(':lname', $lastName, PDO::PARAM_STR);
                $tenantStmt->bindValue(':phone', $phone, PDO::PARAM_STR);
                $tenantStmt->bindValue(':national_id', $nationalId, PDO::PARAM_STR);
                $tenantStmt->execute();
                
                // Get unit ID
                $unitStmt = $conn->prepare("SELECT id FROM units WHERE property_id = :prop_id AND name = :unit_name");
                $unitStmt->bindValue(':prop_id', $property, PDO::PARAM_INT);
                $unitStmt->bindValue(':unit_name', $unit, PDO::PARAM_STR);
                $unitStmt->execute();
                $unitData = $unitStmt->fetch();
                
                if ($unitData) {
                    $unitId = $unitData['id'];
                    
                    // Create lease
                    $leaseSql = "INSERT INTO leases (tenant_id, unit_id, start_date, end_date, monthly_rent, status)
                                VALUES (:tenant_id, :unit_id, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 
                                (SELECT rent_amount FROM units WHERE id = :unit_id), 'active')";
                    $leaseStmt = $conn->prepare($leaseSql);
                    $leaseStmt->bindValue(':tenant_id', $userId, PDO::PARAM_INT);
                    $leaseStmt->bindValue(':unit_id', $unitId, PDO::PARAM_INT);
                    $leaseStmt->execute();
                    
                    // Update unit status
                    $updateUnit = $conn->prepare("UPDATE units SET status = 'occupied' WHERE id = :unit_id");
                    $updateUnit->bindValue(':unit_id', $unitId, PDO::PARAM_INT);
                    $updateUnit->execute();
                }
                
                $conn->commit();
                
                $_SESSION['success'] = "Tenant added successfully! Temporary password: $password";
            } catch (PDOException $e) {
                $conn->rollBack();
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
        $county = trim($_POST['county']);
        $town = trim($_POST['town']);
        $owner = (int)$_POST['owner']);
        $category = (int)$_POST['category']);
        $units = (int)$_POST['units'];
        
        $errors = [];
        if (empty($name)) $errors[] = "Property name is required";
        if (empty($location)) $errors[] = "Location is required";
        if (empty($county)) $errors[] = "County is required";
        if (empty($town)) $errors[] = "Town is required";
        if ($owner <= 0) $errors[] = "Invalid owner selection";
        if ($category <= 0) $errors[] = "Invalid category selection";
        if ($units <= 0) $errors[] = "Number of units must be positive";
        
        if (empty($errors)) {
            try {
                $sql = "INSERT INTO properties (landlord_id, category_id, name, location, county, town, number_of_units, status) 
                        VALUES (:owner, :category, :name, :location, :county, :town, :units, 'active')";
                
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
                $stmt->bindValue(':category', $category, PDO::PARAM_INT);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':location', $location, PDO::PARAM_STR);
                $stmt->bindValue(':county', $county, PDO::PARAM_STR);
                $stmt->bindValue(':town', $town, PDO::PARAM_STR);
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

// Fetch data for display
$data_sets = [
    'landlords' => "
        SELECT l.id, u.email, CONCAT(l.first_name, ' ', l.last_name) AS name, l.phone, 
               (SELECT COUNT(*) FROM properties WHERE landlord_id = l.id) AS properties,
               l.status, l.approved_at AS join_date 
        FROM landlords l
        JOIN users u ON l.id = u.id
        ORDER BY l.approved_at DESC LIMIT 10
    ",
    
    'tenants' => "
        SELECT t.id, u.email, CONCAT(t.first_name, ' ', t.last_name) AS name, t.phone, 
               p.name AS property, 
               u.name AS unit, 
               l.status, l.end_date AS lease_end 
        FROM tenants t
        JOIN users u ON t.id = u.id
        JOIN leases l ON t.id = l.tenant_id
        JOIN units u ON l.unit_id = u.id
        JOIN properties p ON u.property_id = p.id
        WHERE l.status = 'active'
        ORDER BY l.end_date DESC LIMIT 10
    ",
    
    'properties' => "
        SELECT p.id, p.name, p.location, 
               CONCAT(l.first_name, ' ', l.last_name) AS owner, 
               p.number_of_units AS units, p.status,
               (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'occupied') AS occupied,
               pc.name AS type
        FROM properties p
        JOIN landlords l ON p.landlord_id = l.id
        LEFT JOIN property_categories pc ON p.category_id = pc.id
        ORDER BY p.id DESC LIMIT 10
    ",
    
    'payments' => "
        SELECT p.id, p.payment_date, 
               CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, 
               pr.name AS property, p.amount, p.payment_method AS method 
        FROM payments p
        JOIN leases l ON p.lease_id = l.id
        JOIN tenants t ON l.tenant_id = t.id
        JOIN units u ON l.unit_id = u.id
        JOIN properties pr ON u.property_id = pr.id
        ORDER BY p.payment_date DESC LIMIT 10
    ",
    
    'maintenance' => "
        SELECT mr.id, mr.created_at, p.name AS property, 
               mr.unit, mr.description, mr.urgency, mr.status 
        FROM maintenance_requests mr
        JOIN properties p ON mr.property_id = p.id
        ORDER BY mr.created_at DESC LIMIT 10
    ",
    
    'tickets' => "
        SELECT id, created_at, subject, user_email, priority, status 
        FROM support_tickets ORDER BY created_at DESC LIMIT 10
    ",
    
    'mpesa' => "
        SELECT transaction_id, transaction_date, amount, sender_phone, 
               recipient, property, status 
        FROM mpesa_transactions ORDER BY transaction_date DESC LIMIT 10
    "
];

$display_data = [];
foreach ($data_sets as $key => $sql) {
    try {
        $stmt = $conn->query($sql);
        $display_data[$key] = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Data fetch error ($key): " . $e->getMessage());
        $display_data[$key] = [];
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

// Fetch property categories for dropdowns
$categories = [];
try {
    $stmt = $conn->query("SELECT id, name FROM property_categories");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

// Fetch landlords for dropdowns
$landlords = [];
try {
    $stmt = $conn->query("
        SELECT l.id, CONCAT(l.first_name, ' ', l.last_name) AS name 
        FROM landlords l
        WHERE l.status = 'approved'
    ");
    $landlords = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Landlords fetch error: " . $e->getMessage());
}

// Fetch properties for dropdowns
$properties = [];
try {
    $stmt = $conn->query("SELECT id, name FROM properties");
    $properties = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Properties fetch error: " . $e->getMessage());
}

// Check if first login
$firstLogin = isset($_SESSION['first_login']) ? $_SESSION['first_login'] : false;
unset($_SESSION['first_login']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KejaSmart - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --keja-primary: #198754;
            --keja-primary-light: rgba(25, 135, 84, 0.1);
            --keja-secondary: #6c757d;
            --keja-light: #f8f9fa;
            --keja-dark: #111;
            --keja-success: #198754;
            --keja-warning: #ffc107;
            --keja-danger: #dc3545;
            --keja-info: #0dcaf0;
            --keja-purple: #6f42c1;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            overflow-x: hidden;
            color: #333;
        }
        
        .navbar-dashboard {
            height: 60px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            padding: 0 20px;
        }
        
        .sidebar-toggle {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--keja-secondary);
            margin-right: 15px;
            cursor: pointer;
            display: none;
        }
        
        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--keja-primary);
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .logo i {
            margin-right: 8px;
            font-size: 1.1rem;
        }
        
        .user-info {
            margin-left: auto;
            display: flex;
            align-items: center;
        }
        
        .notifications {
            position: relative;
            margin-right: 20px;
            font-size: 1.2rem;
            color: var(--keja-secondary);
            cursor: pointer;
        }
        
        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--keja-danger);
            color: white;
            font-size: 0.7rem;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--keja-primary-light);
            color: var(--keja-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .user-details {
            text-align: right;
        }
        
        .user-details h3 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .user-details p {
            font-size: 0.8rem;
            color: var(--keja-secondary);
        }
        
        .sidebar {
            width: 250px;
            height: calc(100vh - 60px);
            position: fixed;
            top: 60px;
            left: 0;
            background: white;
            box-shadow: 1px 0 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            z-index: 999;
            overflow-y: auto;
            padding: 20px 0;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .menu-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            color: var(--keja-secondary);
            text-decoration: none;
            margin-bottom: 5px;
        }
        
        .menu-item:hover, .menu-item.active {
            background-color: var(--keja-primary-light);
            color: var(--keja-primary);
        }
        
        .menu-item.active {
            border-left: 3px solid var(--keja-primary);
            font-weight: 500;
        }
        
        .menu-item i {
            width: 24px;
            text-align: center;
            margin-right: 12px;
            font-size: 1.1rem;
        }
        
        .sidebar-footer {
            padding: 15px 20px;
            border-top: 1px solid #e9ecef;
            margin-top: 20px;
            font-size: 0.8rem;
            color: var(--keja-secondary);
            text-align: center;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 30px;
            margin-top: 60px;
            transition: all 0.3s;
        }
        
        .welcome {
            margin-bottom: 30px;
        }
        
        .welcome h1 {
            font-size: 1.8rem;
            color: var(--keja-dark);
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .welcome p {
            color: var(--keja-secondary);
            font-size: 1rem;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 25px;
            transition: transform 0.3s ease;
            border-left: 4px solid var(--keja-primary);
            cursor: pointer;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--keja-dark);
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: var(--keja-primary-light);
            color: var(--keja-primary);
        }
        
        .card-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--keja-primary);
        }
        
        .card-label {
            color: var(--keja-secondary);
            font-size: 0.9rem;
        }
        
        .status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status.pending {
            background: rgba(255, 193, 7, 0.1);
            color: var(--keja-warning);
        }
        
        .status.completed {
            background: rgba(25, 135, 84, 0.1);
            color: var(--keja-success);
        }
        
        .status.active {
            background: rgba(13, 110, 253, 0.1);
            color: var(--keja-info);
        }
        
        .status.overdue {
            background: rgba(220, 53, 69, 0.1);
            color: var(--keja-danger);
        }
        
        .status.in-progress {
            background: rgba(13, 202, 240, 0.1);
            color: var(--keja-info);
        }
        
        .status.open {
            background: rgba(111, 66, 193, 0.1);
            color: var(--keja-purple);
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 30px;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .table-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 16px 24px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background-color: #f9fafb;
            color: var(--keja-secondary);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        tr:hover {
            background-color: #f9fafb;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--keja-primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: #157347;
        }
        
        .btn-outline-primary {
            background: transparent;
            border: 1px solid var(--keja-primary);
            color: var(--keja-primary);
        }
        
        .btn-outline-primary:hover {
            background: var(--keja-primary-light);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.875rem;
        }
        
        .dashboard-section {
            display: none;
        }
        
        .dashboard-section.active {
            display: block;
            animation: fadeIn 0.5s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar-toggle {
                display: block;
            }
            
            .user-details h3, .user-details p {
                display: none;
            }
            
            .sidebar-overlay {
                position: fixed;
                top: 60px;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0,0,0,0.5);
                z-index: 998;
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .card {
                padding: 20px;
            }
            
            th, td {
                padding: 12px 16px;
            }
        }
        
        .scroll-top {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--keja-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 100;
            opacity: 0;
            transition: opacity 0.3s;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .scroll-top.show {
            opacity: 1;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .section-title {
            border-bottom: 2px solid var(--keja-primary);
            padding-bottom: 10px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .filter-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .modal-content {
            border-radius: 12px;
            overflow: hidden;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            background: var(--keja-primary);
            color: white;
            border: none;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .form-floating > label {
            padding: 1rem .75rem;
        }
        
        .settings-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .settings-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .settings-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .dropdown-menu {
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border: none;
        }
        
        .welcome-message {
            background: linear-gradient(135deg, #198754, #0d6efd);
            color: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .welcome-message h2 {
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .welcome-message p {
            font-size: 1.1rem;
            max-width: 700px;
            margin-bottom: 20px;
        }
        
        .pagination {
            margin: 0;
        }
        
        .page-item .page-link {
            border-radius: 8px;
            margin: 0 4px;
            border: 1px solid #dee2e6;
        }
        
        .page-item.active .page-link {
            background: var(--keja-primary);
            border-color: var(--keja-primary);
        }
        
        .alert {
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="navbar-dashboard">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <a href="index.html" class="logo">
            <i class="fas fa-home"></i> KejaSmart
        </a>
        
        <div class="user-info">
            <div class="notifications dropdown">
                <i class="fas fa-bell" id="notificationsDropdown" data-bs-toggle="dropdown"></i>
                <span class="notification-count"><?= $stats['pending_tickets'] ?></span>
                <div class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown">
                    <h6 class="dropdown-header">Notifications</h6>
                    <?php if ($stats['pending_tickets'] > 0): ?>
                        <div class="dropdown-item">
                            <div><?= $stats['pending_tickets'] ?> pending support tickets</div>
                            <small class="text-muted">Requires attention</small>
                        </div>
                    <?php endif; ?>
                    <?php if ($stats['active_leases'] > 0): ?>
                        <div class="dropdown-item">
                            <div><?= $stats['active_leases'] ?> active leases</div>
                            <small class="text-muted">View leases</small>
                        </div>
                    <?php endif; ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-center" href="#">View all notifications</a>
                </div>
            </div>
            
            <div class="user-profile dropdown">
                <div class="d-flex align-items-center" id="profileDropdown" data-bs-toggle="dropdown">
                    <div class="user-avatar">
                        <?= $admin['avatar'] ?>
                    </div>
                    <div class="user-details">
                        <h3><?= htmlspecialchars($admin['name']) ?></h3>
                        <p>Super Admin</p>
                    </div>
                </div>
                <div class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                    <h6 class="dropdown-header">Signed in as</h6>
                    <div class="dropdown-item disabled">
                        <strong><?= $admin['email'] ?></strong>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i> My Profile</a>
                    <a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i> Settings</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <ul class="sidebar-menu">
            <li>
                <a href="#" class="menu-item active" data-section="dashboard">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="landlords">
                    <i class="fas fa-user-tie"></i> Manage Landlords
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="tenants">
                    <i class="fas fa-users"></i> Manage Tenants
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="properties">
                    <i class="fas fa-home"></i> Properties Overview
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="payments">
                    <i class="fas fa-money-bill-wave"></i> Payment Monitor
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="maintenance">
                    <i class="fas fa-tools"></i> Maintenance Logs
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="leases">
                    <i class="fas fa-file-contract"></i> Lease Documents
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="reports">
                    <i class="fas fa-chart-bar"></i> Reports & Analytics
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="tickets">
                    <i class="fas fa-ticket-alt"></i> Support Tickets
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="settings">
                    <i class="fas fa-cog"></i> System Settings
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="mpesa">
                    <i class="fas fa-mobile-alt"></i> M-PESA Transactions
                </a>
            </li>
            <li>
                <a href="logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
        
        <div class="sidebar-footer">
            <div>KejaSmart v2.0</div>
            <div>&copy; <?= date('Y') ?> All Rights Reserved</div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Dashboard Section -->
        <div class="dashboard-section active" id="dashboard-section">
            <?php if ($firstLogin): ?>
                <div class="welcome-message">
                    <h2>Welcome to KejaSmart Admin Dashboard, <?= htmlspecialchars($admin['name']) ?>!</h2>
                    <p>As the system administrator, you have full access to manage all aspects of the platform. Here's a quick guide:</p>
                    <ul>
                        <li><strong>Manage Landlords:</strong> Add, edit, and manage property owners</li>
                        <li><strong>Manage Tenants:</strong> Oversee tenant registrations and lease agreements</li>
                        <li><strong>Properties:</strong> View and manage all properties in the system</li>
                        <li><strong>Reports:</strong> Generate financial and occupancy reports</li>
                        <li><strong>System Settings:</strong> Configure platform-wide settings</li>
                    </ul>
                    <button class="btn btn-light">Download Admin Guide</button>
                </div>
            <?php endif; ?>
            
            <div class="welcome">
                <h1>System Overview</h1>
                <p>Welcome back, <?= htmlspecialchars($admin['name']) ?>. Here's what's happening with the system today</p>
            </div>
            
            <div class="dashboard-grid">
                <div class="card" data-section="landlords">
                    <div class="card-header">
                        <div class="card-title">Landlords</div>
                        <div class="card-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $stats['landlords'] ?></div>
                    <div class="card-label">Registered landlords</div>
                </div>
                
                <div class="card" data-section="tenants">
                    <div class="card-header">
                        <div class="card-title">Tenants</div>
                        <div class="card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $stats['tenants'] ?></div>
                    <div class="card-label">Active tenants</div>
                </div>
                
                <div class="card" data-section="properties">
                    <div class="card-header">
                        <div class="card-title">Properties</div>
                        <div class="card-icon">
                            <i class="fas fa-home"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $stats['properties'] ?></div>
                    <div class="card-label">Managed properties</div>
                </div>
                
                <div class="card" data-section="payments">
                    <div class="card-header">
                        <div class="card-title">Monthly Revenue</div>
                        <div class="card-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                    </div>
                    <div class="card-value">Ksh <?= number_format($stats['monthly_revenue'], 2) ?></div>
                    <div class="card-label">Total payments processed</div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>System Activity</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="activityChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>User Distribution</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="userChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-lg-6">
                    <div class="table-container">
                        <div class="table-header">
                            <h5 class="mb-0">Recent Support Tickets</h5>
                            <a href="#" class="btn btn-sm btn-primary" data-section="tickets">View All</a>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($display_data['tickets'] as $ticket): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($ticket['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($ticket['subject']) ?></td>
                                        <td>
                                            <span class="status <?= $ticket['status'] ?>">
                                                <?= ucfirst($ticket['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="table-container">
                        <div class="table-header">
                            <h5 class="mb-0">Pending M-PESA Transactions</h5>
                            <a href="#" class="btn btn-sm btn-primary" data-section="mpesa">View All</a>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($display_data['mpesa'] as $transaction): ?>
                                    <?php if ($transaction['status'] === 'pending'): ?>
                                        <tr>
                                            <td><?= date('M j, H:i', strtotime($transaction['transaction_date'])) ?></td>
                                            <td>Ksh <?= number_format($transaction['amount'], 2) ?></td>
                                            <td>
                                                <span class="status pending">Pending</span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Manage Landlords Section -->
        <div class="dashboard-section" id="landlords-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Manage Landlords</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLandlordModal">
                    <i class="fas fa-plus me-2"></i> Add Landlord
                </button>
            </div>
            
            <div class="filter-controls">
                <div class="form-group">
                    <select class="form-select" id="landlordFilter">
                        <option>All Landlords</option>
                        <option>Active</option>
                        <option>Pending</option>
                        <option>Suspended</option>
                    </select>
                </div>
                <div class="form-group">
                    <input type="text" class="form-control" placeholder="Search landlords...">
                </div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Properties</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_data['landlords'] as $landlord): ?>
                            <tr>
                                <td><?= htmlspecialchars($landlord['name']) ?></td>
                                <td><?= htmlspecialchars($landlord['email']) ?></td>
                                <td><?= htmlspecialchars($landlord['phone']) ?></td>
                                <td><?= $landlord['properties'] ?></td>
                                <td>
                                    <span class="status <?= $landlord['status'] ?>">
                                        <?= ucfirst($landlord['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('M j, Y', strtotime($landlord['join_date'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="d-flex justify-content-between mt-3">
                <div>Showing 1-10 of <?= $stats['landlords'] ?> landlords</div>
                <nav>
                    <ul class="pagination">
                        <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                        <li class="page-item"><a class="page-link" href="#">Next</a></li>
                    </ul>
                </nav>
            </div>
        </div>
        
        <!-- Add Landlord Modal -->
        <div class="modal fade" id="addLandlordModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Landlord</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" name="phone" class="form-control" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_landlord" class="btn btn-primary">Add Landlord</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Manage Tenants Section -->
        <div class="dashboard-section" id="tenants-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Manage Tenants</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data极-bs-target="#addTenantModal">
                    <i class="fas fa-plus me-2"></i> Add Tenant
                </button>
            </div>
            
            <div class="filter-controls">
                <div class="form-group">
                    <select class="form-select" id="tenantFilter">
                        <option>All Tenants</option>
                        <option>Active Leases</option>
                        <option>Pending Verification</option>
                        <option>Overdue Payments</option>
                    </select>
                </div>
                <div class="form-group">
                    <input type="text" class="form-control" placeholder="Search tenants...">
                </div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Property</th>
                            <th>Unit</th>
                            <th>Lease End</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_data['tenants'] as $tenant): ?>
                            <tr>
                                <td><?= htmlspecialchars($tenant['name']) ?></td>
                                <td><?= htmlspecialchars($tenant['email']) ?></td>
                                <td><?= htmlspecialchars($tenant['phone']) ?></td>
                                <td><?= htmlspecialchars($tenant['property']) ?></td>
                                <td><?= htmlspecialchars($tenant['unit']) ?></td>
                                <td><?= date('M j, Y', strtotime($tenant['lease_end'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="d-flex justify-content-between mt-3">
                <div>Showing 1-10 of <?= $stats['tenants'] ?> tenants</div>
                <nav>
                    <ul class="pagination">
                        <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                        <li class="page-item"><a class="page-link" href="#">Next</a></li>
                    </ul>
                </nav>
            </div>
        </div>
        
        <!-- Add Tenant Modal -->
        <div class="modal fade" id="addTenantModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Tenant</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">First Name</label>
                                        <input type="text" name="first_name" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" name="last_name" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Phone Number</label>
                                        <input type="tel" name="phone" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">National ID</label>
                                        <input type="text" name="national_id" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Property</label>
                                        <select name="property" class="form-select" required>
                                            <option value="">Select Property</option>
                                            <?php foreach ($properties as $property): ?>
                                                <option value="<?= $property['id'] ?>"><?= htmlspecialchars($property['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Unit Number</label>
                                <input type="text" name="unit" class="form-control" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_tenant" class="btn btn-primary">Add Tenant</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Properties Section -->
        <div class="dashboard-section" id="properties-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Properties Overview</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPropertyModal">
                    <i class="fas fa-plus me-2"></i> Add Property
                </button>
            </div>
            
            <div class="filter-controls">
                <div class="form-group">
                    <select class="form-select" id="propertyFilter">
                        <option>All Properties</option>
                        <option>Active</option>
                        <option>Maintenance</option>
                        <option>Vacant</option>
                    </select>
                </div>
                <div class="form-group">
                    <input type="text" class="form-control" placeholder="Search properties...">
                </div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Location</th>
                            <th>Owner</th>
                            <th>Type</th>
                            <th>Units</th>
                            <th>Occupied</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_data['properties'] as $property): ?>
                            <tr>
                                <td><?= htmlspecialchars($property['name']) ?></td>
                                <td><?= htmlspecialchars($property['location']) ?></td>
                                <td><?= htmlspecialchars($property['owner']) ?></td>
                                <td><?= htmlspecialchars($property['type']) ?></td>
                                <td><?= $property['units'] ?></td>
                                <td><?= $property['occupied'] ?></td>
                                <td>
                                    <span class="status <?= $property['status'] ?>">
                                        <?= ucfirst($property['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="d-flex justify-content-between mt-3">
                <div>Showing 1-10 of <?= $stats['properties'] ?> properties</div>
                <nav>
                    <ul class="pagination">
                        <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                        <li class="page-item"><a class="page-link" href="#">Next</a></li>
                    </ul>
                </nav>
            </div>
        </div>
        
        <!-- Add Property Modal -->
        <div class="modal fade" id="addPropertyModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Property</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Property Name</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Location</label>
                                        <input type="text" name="location" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">County</label>
                                        <input type="text" name="county" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Town</label>
                                        <input type="text" name="town" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Number of Units</label>
                                        <input type="number" name="units" class="form-control" min="1" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Owner</label>
                                        <select name="owner" class="form-select" required>
                                            <option value="">Select Landlord</option>
                                            <?php foreach ($landlords as $landlord): ?>
                                                <option value="<?= $landlord['id'] ?>"><?= htmlspecialchars($landlord['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Property Type</label>
                                        <select name="category" class="form-select" required>
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_property" class="btn btn-primary">Add Property</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Payment Monitor Section -->
        <div class="dashboard-section" id="payments-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Payment Monitor</h2>
                <button class="btn btn-primary">
                    <i class="fas fa-download me-2"></i> Export Report
                </button>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h5 class="mb-0">Recent Payments</h5>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Tenant</th>
                            <th>Property</th>
                            <th>Amount</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_data['payments'] as $payment): ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                <td><?= htmlspecialchars($payment['tenant_name']) ?></td>
                                <td><?= htmlspecialchars($payment['property']) ?></td>
                                <td>Ksh <?= number_format($payment['amount'], 2) ?></td>
                                <td><?= ucfirst($payment['method']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Maintenance Logs Section -->
        <div class="dashboard-section" id="maintenance-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Maintenance Logs</h2>
                <button class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i> Create Ticket
                </button>
            </极div>
            
            <div class="table-container">
                <div class="table-header">
                    <h5 class="mb-0">Recent Maintenance Requests</h5>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Property</th>
                            <th>Unit</th>
                            <th>Description</th>
                            <th>Urgency</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_data['maintenance'] as $request): ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($request['created_at'])) ?></td>
                                <td><?= htmlspecialchars($request['property']) ?></td>
                                <td><?= htmlspecialchars($request['unit']) ?></td>
                                <td><?= substr(htmlspecialchars($request['description']), 0, 50) ?>...</td>
                                <td>
                                    <span class="status <?= $request['urgency'] ?>">
                                        <?= ucfirst($request['urgency']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status <?= $request['status'] ?>">
                                        <?= ucfirst($request['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- System Settings Section -->
        <div class="dashboard-section" id="settings-section">
            <h2 class="mb-4">System Settings</h2>
            
            <div class="settings-card">
                <div class="settings-section">
                    <h5 class="section-title">General Settings</h5>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-floating mb-3">
                                    <input type="text" name="system_name" class="form-control" 
                                           value="<?= htmlspecialchars($settings['system_name']) ?>" required>
                                    <label>System Name</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating mb-3">
                                    <input type="email" name="system_email" class="form-control" 
                                           value="<?= htmlspecialchars($settings['system_email']) ?>" required>
                                    <label>System Email</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <select name="currency" class="form-select" required>
                                        <option value="KES" <?= $settings['currency'] === 'KES' ? 'selected' : '' ?>>KES (Kenyan Shilling)</option>
                                        <option value="USD" <?= $settings['currency'] === 'USD' ? 'selected' : '' ?>>USD (US Dollar)</option>
                                        <option value="EUR" <?= $settings['currency'] === 'EUR' ? 'selected' : '' ?>>EUR (Euro)</option>
                                    </select>
                                    <label>Currency</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <select name="due_day" class="form-select" required>
                                        <?php for ($day = 1; $day <= 28; $day++): ?>
                                            <option value="<?= $day ?>" <?= $settings['payment_due_day'] == $day ? 'selected' : '' ?>>
                                                <?= $day ?><?= $day === 1 ? 'st' : ($day === 2 ? 'nd' : ($day === 3 ? 'rd' : 'th')) ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <label>Rent Due Day</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end">
                            <button type="submit" name="update_settings" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="settings-section">
                    <h5 class="section-title">Security Settings</h5>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Changing these settings may affect system security. Modify with caution.
                    </div>
                    
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="2faSwitch">
                        <label class="form-check-label" for="2faSwitch">
                            Enable Two-Factor Authentication for Admins
                        </label>
                    </div>
                    
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="pwComplexity" checked>
                        <label class="form-check-label" for="pwComplexity">
                            Enforce Password Complexity Requirements
                        </label>
                    </div>
                    
                    <div class="d-flex justify-content-end">
                        <button class="btn btn-outline-primary">
                            <i class="fas fa-shield-alt me-2"></i> Update Security Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- M-PESA Transactions Section -->
        <div class="dashboard-section" id="mpesa-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>M-PESA Transactions</h2>
                <button class="btn btn-primary">
                    <i class="fas fa-sync me-2"></i> Refresh
                </button>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h5 class="mb-0">Recent Transactions</h5>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Transaction ID</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Sender</th>
                            <th>Property</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_data['mpesa'] as $transaction): ?>
                            <tr>
                                <td><?= $transaction['transaction_id'] ?></td>
                                <td><?= date('M j, H:i', strtotime($transaction['transaction_date'])) ?></td>
                                <td>Ksh <?= number_format($transaction['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($transaction['sender_phone']) ?></td>
                                <td><?= htmlspecialchars($transaction['property']) ?></td>
                                <td>
                                    <span class="status <?= $transaction['status'] ?>">
                                        <?= ucfirst($transaction['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Reports & Analytics Section -->
        <div class="dashboard-section" id="reports-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Reports & Analytics</h2>
                <div>
                    <button class="btn btn-primary me-2">
                        <i class="fas fa-download me-2"></i> Export PDF
                    </button>
                    <button class="btn btn-outline-primary">
                        <i class="fas fa-calendar me-2"></i> Select Date Range
                    </button>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5>Financial Performance</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>Occupancy Rate</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="occupancyChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Support Tickets Section -->
        <div class="dashboard-section" id="tickets-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Support Tickets</h2>
                <button class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i> New Ticket
                </button>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h5 class="mb-0">Recent Support Tickets</h5>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Subject</th>
                            <th>User</th>
                            <th>Priority</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_data['tickets'] as $ticket): ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($ticket['created_at'])) ?></td>
                                <td><?= htmlspecialchars($ticket['subject']) ?></td>
                                <td><?= htmlspecialchars($ticket['user_email']) ?></td>
                                <td>
                                    <span class="status <?= $ticket['priority'] ?>">
                                        <?= ucfirst($ticket['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status <?= $ticket['status'] ?>">
                                        <?= ucfirst($ticket['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Lease Documents Section -->
        <div class="dashboard-section" id="leases-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Lease Documents</h2>
                <button class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i> New Lease
                </button>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h5 class="mb-0">Active Leases</h5>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Property</th>
                            <th>Unit</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Rent</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_data['tenants'] as $tenant): ?>
                            <tr>
                                <td><?= htmlspecialchars($tenant['name']) ?></td>
                                <td><?= htmlspecialchars($tenant['property']) ?></td>
                                <td><?= htmlspecialchars($tenant['unit']) ?></td>
                                <td><?= date('M j, Y', strtotime('-1 year', strtotime($tenant['lease_end']))) ?></td>
                                <td><?= date('M j, Y', strtotime($tenant['lease_end'])) ?></td>
                                <td>Ksh 25,000</td>
                                <td>
                                    <span class="status active">Active</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Scroll to Top Button -->
        <div class="scroll-top" id="scrollTop">
            <i class="fas fa-arrow-up"></i>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar on mobile
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('sidebarOverlay').style.display = 'block';
        });
        
        document.getElementById('sidebarOverlay').addEventListener('click', function() {
            document.getElementById('sidebar').classList.remove('active');
            this.style.display = 'none';
        });
        
        // Section navigation
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Remove active class from all menu items
                document.querySelectorAll('.menu-item').forEach(i => {
                    i.classList.remove('active');
                });
                
                // Add active class to clicked menu item
                this.classList.add('active');
                
                // Hide all sections
                document.querySelectorAll('.dashboard-section').forEach(section => {
                    section.classList.remove('active');
                });
                
                // Show target section
                const sectionId = this.dataset.section + '-section';
                document.getElementById(sectionId).classList.add('active');
                
                // Close sidebar on mobile after selection
                if (window.innerWidth < 992) {
                    document.getElementById('sidebar').class极.remove('active');
                    document.getElementById('sidebarOverlay').style.display = 'none';
                }
            });
        });
        
        // Card navigation
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('click', function() {
                const section = this.dataset.section;
                
                // Update menu active state
                document.querySelectorAll('.menu-item').forEach(item => {
                    item.classList.remove('active');
                    if (item.dataset.section === section) {
                        item.classList.add('active');
                    }
                });
                
                // Hide all sections
                document.querySelectorAll('.dashboard-section').forEach(section => {
                    section.classList.remove('active');
                });
                
                // Show target section
                document.getElementById(section + '-section').classList.add('active');
            });
        });
        
        // Scroll to top functionality
        window.addEventListener('scroll', function() {
            const scrollTop = document.getElementById('scrollTop');
            if (window.pageYOffset > 300) {
                scrollTop.classList.add('show');
            } else {
                scrollTop.classList.remove('show');
            }
        });
        
        document.getElementById('scrollTop').addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        // Charts initialization
        document.addEventListener('DOMContentLoaded', function() {
            // System Activity Chart
            const activityCtx = document.getElementById('activityChart').getContext('2d');
            new Chart(activityCtx, {
                type: 'line',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [{
                        label: 'User Activity',
                        data: [120, 190, 170, 220, 180, 150, 200],
                        borderColor: '#198754',
                        tension: 0.3,
                        fill: true,
                        backgroundColor: 'rgba(25, 135, 84, 0.1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
            
            // User Distribution Chart
            const userCtx = document.getElementById('userChart').getContext('2d');
            new Chart(userCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Landlords', 'Tenants', 'Admins'],
                    datasets: [{
                        data: [<?= $stats['landlords'] ?>, <?= $stats['tenants'] ?>, 1],
                        backgroundColor: ['#198754', '#0dcaf0', '#6f42c1']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right' }
                    }
                }
            });
            
            // Revenue Chart
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            new Chart(revenueCtx, {
                type: 'bar',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Revenue (Ksh)',
                        data: [1200000, 1900000, 1500000, 1800000, 2200000, 2400000],
                        backgroundColor: '#198754'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
            
            // Occupancy Chart
            const occupancyCtx = document.getElementById('occupancyChart').getContext('2d');
            new Chart(occupancyCtx, {
                type: 'pie',
                data: {
                    labels: ['Occupied', 'Vacant'],
                    datasets: [{
                        data: [85, 15],
                        backgroundColor: ['#198754', '#6c757d']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        });
    </script>
</body>
</html>