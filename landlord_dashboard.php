<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'password');
define('DB_NAME', 'kejasmart');
define('DB_CHARSET', 'utf8mb4');

// Establish database connection
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) {
    header("Location: login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT users.*, landlords.* FROM users 
                      JOIN landlords ON users.id = landlords.id 
                      WHERE users.id = ?");
$stmt->execute([$user_id]);
$landlord = $stmt->fetch();

if (!$landlord) {
    header("Location: login.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new property
    if (isset($_POST['add_property'])) {
        $name = $_POST['property_name'];
        $location = $_POST['location'];
        $county = $_POST['county'];
        $town = $_POST['town'];
        $units = (int)$_POST['units'];
        $category_id = (int)$_POST['category_id'];
        $description = $_POST['description'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO properties (landlord_id, category_id, name, description, location, county, town, number_of_units) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$landlord['id'], $category_id, $name, $description, $location, $county, $town, $units]);
        
        $_SESSION['success'] = "Property added successfully!";
        header("Location: dashboard.php");
        exit();
    }
    
    // Add new tenant
    if (isset($_POST['add_tenant'])) {
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $national_id = $_POST['national_id'];
        $unit_id = (int)$_POST['unit_id'];
        $rent_due = (float)$_POST['rent_due'];
        
        // Create user account first
        $password = password_hash('TempPass123', PASSWORD_DEFAULT); // Temporary password
        $stmt = $pdo->prepare("INSERT INTO users (email, password, user_type) VALUES (?, ?, 'tenant')");
        $stmt->execute([$email, $password]);
        $tenant_user_id = $pdo->lastInsertId();
        
        // Create tenant profile
        $stmt = $pdo->prepare("INSERT INTO tenants (id, first_name, last_name, phone, national_id) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_user_id, $first_name, $last_name, $phone, $national_id]);
        
        // Create lease agreement
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+1 year'));
        $stmt = $pdo->prepare("INSERT INTO leases (tenant_id, unit_id, start_date, end_date, monthly_rent, status) 
                              VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$tenant_user_id, $unit_id, $start_date, $end_date, $rent_due]);
        
        // Update unit status
        $stmt = $pdo->prepare("UPDATE units SET status = 'occupied' WHERE id = ?");
        $stmt->execute([$unit_id]);
        
        $_SESSION['success'] = "Tenant added successfully!";
        header("Location: dashboard.php?section=tenants");
        exit();
    }
    
    // Record payment
    if (isset($_POST['record_payment'])) {
        $lease_id = (int)$_POST['lease_id'];
        $amount = (float)$_POST['amount'];
        $method = $_POST['method'];
        $reference = $_POST['reference'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO payments (lease_id, amount, payment_date, payment_method, reference_number, status, recorded_by) 
                              VALUES (?, ?, CURDATE(), ?, ?, 'completed', ?)");
        $stmt->execute([$lease_id, $amount, $method, $reference, $landlord['id']]);
        
        $_SESSION['success'] = "Payment recorded successfully!";
        header("Location: dashboard.php?section=payments");
        exit();
    }
    
    // Create maintenance request
    if (isset($_POST['create_request'])) {
        $unit_id = (int)$_POST['unit_id'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $urgency = $_POST['urgency'];
        
        // Get tenant ID for the unit
        $stmt = $pdo->prepare("SELECT tenant_id FROM leases 
                              WHERE unit_id = ? AND status = 'active' 
                              ORDER BY end_date DESC LIMIT 1");
        $stmt->execute([$unit_id]);
        $lease = $stmt->fetch();
        $tenant_id = $lease ? $lease['tenant_id'] : null;
        
        $stmt = $pdo->prepare("INSERT INTO maintenance_requests (tenant_id, unit_id, title, description, urgency) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $unit_id, $title, $description, $urgency]);
        
        $_SESSION['success'] = "Maintenance request created!";
        header("Location: dashboard.php?section=maintenance");
        exit();
    }
    
    // Update maintenance status
    if (isset($_POST['update_request'])) {
        $request_id = (int)$_POST['request_id'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("UPDATE maintenance_requests SET status = ? WHERE id = ?");
        $stmt->execute([$status, $request_id]);
        
        $_SESSION['success'] = "Maintenance request updated!";
        header("Location: dashboard.php?section=maintenance");
        exit();
    }
    
    // Update profile
    if (isset($_POST['update_profile'])) {
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $national_id = $_POST['national_id'];
        $kra_pin = $_POST['kra_pin'] ?? '';
        $address = $_POST['address'] ?? '';
        $county = $_POST['county'] ?? '';
        
        // Update users table
        $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->execute([$email, $landlord['id']]);
        
        // Update landlords table
        $stmt = $pdo->prepare("UPDATE landlords SET first_name = ?, last_name = ?, phone = ?, national_id = ?, kra_pin = ?, address = ?, county = ? 
                              WHERE id = ?");
        $stmt->execute([$first_name, $last_name, $phone, $national_id, $kra_pin, $address, $county, $landlord['id']]);
        
        $_SESSION['success'] = "Profile updated successfully!";
        header("Location: dashboard.php?section=profile");
        exit();
    }
    
    // Change password
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $_SESSION['error'] = "New passwords do not match!";
            header("Location: dashboard.php?section=profile");
            exit();
        }
        
        // Verify current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$landlord['id']]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($current_password, $user['password'])) {
            $_SESSION['error'] = "Current password is incorrect!";
            header("Location: dashboard.php?section=profile");
            exit();
        }
        
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_password, $landlord['id']]);
        
        $_SESSION['success'] = "Password changed successfully!";
        header("Location: dashboard.php?section=profile");
        exit();
    }
}

// Determine current section
$section = $_GET['section'] ?? 'dashboard';
$valid_sections = ['dashboard', 'properties', 'tenants', 'leases', 'payments', 'maintenance', 'reports', 'profile'];
if (!in_array($section, $valid_sections)) {
    $section = 'dashboard';
}

// Fetch data for dashboard
$propertiesCount = 0;
$tenantsCount = 0;
$activeLeases = 0;
$totalPayments = 0;
$pendingRequests = 0;

if ($section === 'dashboard') {
    // Count properties
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM properties WHERE landlord_id = ?");
    $stmt->execute([$landlord['id']]);
    $propertiesCount = $stmt->fetchColumn();

    // Count tenants
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT leases.tenant_id) 
                          FROM leases 
                          JOIN units ON leases.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? AND leases.status = 'active'");
    $stmt->execute([$landlord['id']]);
    $tenantsCount = $stmt->fetchColumn();

    // Count active leases
    $stmt = $pdo->prepare("SELECT COUNT(*) 
                          FROM leases 
                          JOIN units ON leases.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? AND leases.status = 'active'");
    $stmt->execute([$landlord['id']]);
    $activeLeases = $stmt->fetchColumn();

    // Total payments
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(payments.amount), 0) 
                          FROM payments 
                          JOIN leases ON payments.lease_id = leases.id 
                          JOIN units ON leases.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? AND payments.status = 'completed'");
    $stmt->execute([$landlord['id']]);
    $totalPayments = $stmt->fetchColumn();

    // Pending maintenance requests
    $stmt = $pdo->prepare("SELECT COUNT(*) 
                          FROM maintenance_requests 
                          JOIN units ON maintenance_requests.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? AND maintenance_requests.status = 'pending'");
    $stmt->execute([$landlord['id']]);
    $pendingRequests = $stmt->fetchColumn();

    // Recent payments
    $stmt = $pdo->prepare("SELECT payments.*, tenants.first_name, tenants.last_name, properties.name AS property_name 
                          FROM payments 
                          JOIN leases ON payments.lease_id = leases.id 
                          JOIN tenants ON leases.tenant_id = tenants.id 
                          JOIN units ON leases.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? 
                          ORDER BY payments.payment_date DESC 
                          LIMIT 5");
    $stmt->execute([$landlord['id']]);
    $recentPayments = $stmt->fetchAll();

    // Pending maintenance requests
    $stmt = $pdo->prepare("SELECT maintenance_requests.*, properties.name AS property_name, units.name AS unit_name 
                          FROM maintenance_requests 
                          JOIN units ON maintenance_requests.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? AND maintenance_requests.status = 'pending' 
                          ORDER BY maintenance_requests.created_at DESC 
                          LIMIT 5");
    $stmt->execute([$landlord['id']]);
    $maintenanceRequests = $stmt->fetchAll();
}

// Fetch data for properties section
if ($section === 'properties') {
    $stmt = $pdo->prepare("SELECT properties.*, property_categories.name AS category_name 
                          FROM properties 
                          LEFT JOIN property_categories ON properties.category_id = property_categories.id 
                          WHERE landlord_id = ?");
    $stmt->execute([$landlord['id']]);
    $properties = $stmt->fetchAll();
    
    // Get categories for dropdown
    $stmt = $pdo->prepare("SELECT * FROM property_categories");
    $stmt->execute();
    $categories = $stmt->fetchAll();
}

// Fetch data for tenants section
if ($section === 'tenants') {
    $stmt = $pdo->prepare("SELECT tenants.*, leases.id AS lease_id, leases.monthly_rent, leases.start_date, leases.end_date, 
                          units.name AS unit_name, properties.name AS property_name 
                          FROM tenants 
                          JOIN leases ON tenants.id = leases.tenant_id 
                          JOIN units ON leases.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? AND leases.status = 'active'");
    $stmt->execute([$landlord['id']]);
    $tenants = $stmt->fetchAll();
    
    // Get available units for adding tenant
    $stmt = $pdo->prepare("SELECT units.id, units.name, properties.name AS property_name 
                          FROM units 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? AND units.status = 'vacant'");
    $stmt->execute([$landlord['id']]);
    $vacantUnits = $stmt->fetchAll();
}

// Fetch data for leases section
if ($section === 'leases') {
    $stmt = $pdo->prepare("SELECT leases.*, tenants.first_name, tenants.last_name, 
                          units.name AS unit_name, properties.name AS property_name 
                          FROM leases 
                          JOIN tenants ON leases.tenant_id = tenants.id 
                          JOIN units ON leases.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? 
                          ORDER BY leases.end_date DESC");
    $stmt->execute([$landlord['id']]);
    $leases = $stmt->fetchAll();
}

// Fetch data for payments section
if ($section === 'payments') {
    $stmt = $pdo->prepare("SELECT payments.*, tenants.first_name, tenants.last_name, 
                          properties.name AS property_name, units.name AS unit_name 
                          FROM payments 
                          JOIN leases ON payments.lease_id = leases.id 
                          JOIN tenants ON leases.tenant_id = tenants.id 
                          JOIN units ON leases.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? 
                          ORDER BY payments.payment_date DESC");
    $stmt->execute([$landlord['id']]);
    $payments = $stmt->fetchAll();
    
    // Get active leases for recording payments
    $stmt = $pdo->prepare("SELECT leases.id, tenants.first_name, tenants.last_name, units.name AS unit_name 
                          FROM leases 
                          JOIN tenants ON leases.tenant_id = tenants.id 
                          JOIN units ON leases.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? AND leases.status = 'active'");
    $stmt->execute([$landlord['id']]);
    $activeLeasesList = $stmt->fetchAll();
}

// Fetch data for maintenance section
if ($section === 'maintenance') {
    $stmt = $pdo->prepare("SELECT maintenance_requests.*, tenants.first_name, tenants.last_name, 
                          properties.name AS property_name, units.name AS unit_name 
                          FROM maintenance_requests 
                          LEFT JOIN tenants ON maintenance_requests.tenant_id = tenants.id 
                          JOIN units ON maintenance_requests.unit_id = units.id 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ? 
                          ORDER BY maintenance_requests.created_at DESC");
    $stmt->execute([$landlord['id']]);
    $maintenanceList = $stmt->fetchAll();
    
    // Get units for creating maintenance requests
    $stmt = $pdo->prepare("SELECT units.id, units.name, properties.name AS property_name 
                          FROM units 
                          JOIN properties ON units.property_id = properties.id 
                          WHERE properties.landlord_id = ?");
    $stmt->execute([$landlord['id']]);
    $allUnits = $stmt->fetchAll();
}

// Fetch data for reports section
if ($section === 'reports') {
    // Income report (last 6 months)
    $incomeReport = [];
    for ($i = 5; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total 
                              FROM payments 
                              JOIN leases ON payments.lease_id = leases.id 
                              JOIN units ON leases.unit_id = units.id 
                              JOIN properties ON units.property_id = properties.id 
                              WHERE properties.landlord_id = ? 
                              AND DATE_FORMAT(payment_date, '%Y-%m') = ?");
        $stmt->execute([$landlord['id'], $month]);
        $result = $stmt->fetch();
        $incomeReport[] = [
            'month' => $month,
            'amount' => $result['total']
        ];
    }
    
    // Occupancy rate
    $stmt = $pdo->prepare("SELECT 
                          (SELECT COUNT(*) FROM units 
                           JOIN properties ON units.property_id = properties.id 
                           WHERE properties.landlord_id = ? AND units.status = 'occupied') AS occupied,
                          (SELECT COUNT(*) FROM units 
                           JOIN properties ON units.property_id = properties.id 
                           WHERE properties.landlord_id = ?) AS total");
    $stmt->execute([$landlord['id'], $landlord['id']]);
    $occupancy = $stmt->fetch();
    $occupancyRate = $occupancy['total'] > 0 ? round(($occupancy['occupied'] / $occupancy['total']) * 100) : 0;
    
    // Payment status
    $stmt = $pdo->prepare("SELECT 
                          (SELECT COUNT(*) FROM leases 
                           JOIN units ON leases.unit_id = units.id 
                           JOIN properties ON units.property_id = properties.id 
                           WHERE properties.landlord_id = ? AND leases.status = 'active'
                           AND CURDATE() <= DATE_ADD(leases.start_date, INTERVAL 5 DAY)) AS on_time,
                          (SELECT COUNT(*) FROM leases 
                           JOIN units ON leases.unit_id = units.id 
                           JOIN properties ON units.property_id = properties.id 
                           WHERE properties.landlord_id = ? AND leases.status = 'active'
                           AND CURDATE() > DATE_ADD(leases.start_date, INTERVAL 5 DAY)) AS late");
    $stmt->execute([$landlord['id'], $landlord['id']]);
    $paymentStatus = $stmt->fetch();
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
    <title>KejaSmart - Landlord Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }
        
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            overflow-x: hidden;
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
        
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 30px;
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
        }
        
        .scroll-top.show {
            opacity: 1;
        }
        
        .property-card {
            transition: all 0.3s ease;
            border-left: 3px solid var(--keja-primary);
            cursor: pointer;
        }
        
        .property-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 10px rgba(0,0,0,0.08);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #adb5bd;
            margin-bottom: 20px;
            opacity: 0.7;
        }
        
        .empty-state h3 {
            color: var(--keja-dark);
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: var(--keja-secondary);
            font-size: 1.1rem;
            margin-bottom: 25px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
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
        
        .onboarding-steps {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .step {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .step:last-child {
            border-bottom: none;
        }
        
        .step:hover {
            background-color: #f9fafb;
        }
        
        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--keja-primary-light);
            color: var(--keja-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-right: 20px;
            flex-shrink: 0;
        }
        
        .step-content {
            flex-grow: 1;
        }
        
        .step-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .step-description {
            color: var(--keja-secondary);
            font-size: 0.95rem;
            margin-bottom: 0;
        }
        
        .step-action {
            margin-left: 15px;
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
        
        .profile-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .profile-header {
            background: linear-gradient(135deg, #198754, #0d6efd);
            padding: 30px;
            text-align: center;
            color: white;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: white;
            color: var(--keja-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto 15px;
        }
        
        .profile-body {
            padding: 30px;
        }
        
        .profile-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .profile-label {
            font-weight: 600;
            min-width: 150px;
            color: var(--keja-secondary);
        }
        
        .profile-value {
            flex-grow: 1;
        }
        
        .modal-content {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .modal-header {
            background: var(--keja-primary);
            color: white;
        }
        
        .form-floating > label {
            padding: 1rem .75rem;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="navbar-dashboard">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <a href="#" class="logo">
            <i class="fas fa-home"></i> KejaSmart
        </a>
        
        <div class="user-info">
            <div class="notifications">
                <i class="fas fa-bell"></i>
                <?php if ($pendingRequests > 0): ?>
                    <span class="notification-count"><?= $pendingRequests ?></span>
                <?php endif; ?>
            </div>
            
            <div class="user-profile">
                <div class="user-avatar">
                    <?= substr($landlord['first_name'], 0, 1) . substr($landlord['last_name'], 0, 1) ?>
                </div>
                <div class="user-details">
                    <h3><?= htmlspecialchars($landlord['first_name'] . ' ' . $landlord['last_name']) ?></h3>
                    <p>Landlord</p>
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
                <a href="?section=dashboard" class="menu-item <?= $section === 'dashboard' ? 'active' : '' ?>" data-section="dashboard">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="?section=properties" class="menu-item <?= $section === 'properties' ? 'active' : '' ?>" data-section="properties">
                    <i class="fas fa-home"></i> Properties
                </a>
            </li>
            <li>
                <a href="?section=tenants" class="menu-item <?= $section === 'tenants' ? 'active' : '' ?>" data-section="tenants">
                    <i class="fas fa-users"></i> Tenants
                </a>
            </li>
            <li>
                <a href="?section=leases" class="menu-item <?= $section === 'leases' ? 'active' : '' ?>" data-section="leases">
                    <i class="fas fa-file-contract"></i> Leases
                </a>
            </li>
            <li>
                <a href="?section=payments" class="menu-item <?= $section === 'payments' ? 'active' : '' ?>" data-section="payments">
                    <i class="fas fa-money-bill-wave"></i> Payments
                </a>
            </li>
            <li>
                <a href="?section=maintenance" class="menu-item <?= $section === 'maintenance' ? 'active' : '' ?>" data-section="maintenance">
                    <i class="fas fa-tools"></i> Maintenance
                </a>
            </li>
            <li>
                <a href="?section=reports" class="menu-item <?= $section === 'reports' ? 'active' : '' ?>" data-section="reports">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </li>
            <li>
                <a href="?section=profile" class="menu-item <?= $section === 'profile' ? 'active' : '' ?>" data-section="profile">
                    <i class="fas fa-user"></i> My Profile
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
        <!-- Success/Error messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <!-- Dashboard Section -->
        <div class="dashboard-section <?= $section === 'dashboard' ? 'active' : '' ?>" id="dashboard-section">
            <?php if ($firstLogin): ?>
                <div class="welcome-message">
                    <h2>Welcome to KejaSmart, <?= htmlspecialchars($landlord['first_name']) ?>!</h2>
                    <p>We're excited to help you manage your properties efficiently. Let's get started by setting up your first property and tenant information.</p>
                    <button class="btn btn-light">Get Started Guide</button>
                </div>
            <?php endif; ?>
            
            <div class="welcome">
                <h1>Welcome back, <?= htmlspecialchars($landlord['first_name']) ?>!</h1>
                <p>Here's what's happening with your properties today</p>
            </div>
            
            <?php if ($firstLogin): ?>
                <div class="onboarding-steps">
                    <h3 class="mb-4">Quick Setup Guide</h3>
                    <div class="step">
                        <div class="step-icon">
                            <i class="fas fa-home"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">Add Your First Property</div>
                            <div class="step-description">Start by adding information about your property including location, type, and units.</div>
                        </div>
                        <div class="step-action">
                            <button class="btn btn-primary" id="addPropertyBtn">Add Property</button>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">Add Tenants</div>
                            <div class="step-description">Create tenant profiles and assign them to your properties.</div>
                        </div>
                        <div class="step-action">
                            <button class="btn btn-outline-primary" id="addTenantBtn">Add Tenant</button>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-icon">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">Create Lease Agreements</div>
                            <div class="step-description">Set up lease terms and rental agreements for your tenants.</div>
                        </div>
                        <div class="step-action">
                            <button class="btn btn-outline-primary" id="createLeaseBtn">Create Lease</button>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-icon">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">Configure Payment Settings</div>
                            <div class="step-description">Set up your preferred payment methods and rental rates.</div>
                        </div>
                        <div class="step-action">
                            <button class="btn btn-outline-primary">Next Step</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="dashboard-grid">
                <div class="card" data-section="properties">
                    <div class="card-header">
                        <div class="card-title">Properties</div>
                        <div class="card-icon">
                            <i class="fas fa-home"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $propertiesCount ?></div>
                    <div class="card-label">Total properties managed</div>
                </div>
                
                <div class="card" data-section="tenants">
                    <div class="card-header">
                        <div class="card-title">Tenants</div>
                        <div class="card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $tenantsCount ?></div>
                    <div class="card-label">Active tenants</div>
                </div>
                
                <div class="card" data-section="leases">
                    <div class="card-header">
                        <div class="card-title">Leases</div>
                        <div class="card-icon">
                            <i class="fas fa-file-contract"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $activeLeases ?></div>
                    <div class="card-label">Active lease agreements</div>
                </div>
                
                <div class="card" data-section="payments">
                    <div class="card-header">
                        <div class="card-title">Revenue</div>
                        <div class="card-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                    </div>
                    <div class="card-value">Ksh <?= number_format($totalPayments, 2) ?></div>
                    <div class="card-label">Total payments received</div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-6">
                    <div class="table-container">
                        <div class="table-header p-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Payments</h5>
                            <a href="?section=payments" class="btn btn-sm btn-primary" data-section="payments">View All</a>
                        </div>
                        <?php if (count($recentPayments) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Tenant</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPayments as $payment): ?>
                                        <tr>
                                            <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                            <td><?= htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']) ?></td>
                                            <td>Ksh <?= number_format($payment['amount'], 2) ?></td>
                                            <td>
                                                <span class="status completed">Completed</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state py-5">
                                <i class="fas fa-money-bill-wave"></i>
                                <h3>No Payment Records</h3>
                                <p>You haven't received any payments yet. Payments will appear here once tenants start paying rent.</p>
                                <button class="btn btn-primary">Set Up Payments</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="table-container">
                        <div class="table-header p-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Pending Requests</h5>
                            <a href="?section=maintenance" class="btn btn-sm btn-primary" data-section="maintenance">View All</a>
                        </div>
                        <?php if (count($maintenanceRequests) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Property</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($maintenanceRequests as $request): ?>
                                        <tr>
                                            <td><?= date('M j, Y', strtotime($request['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($request['property_name']) ?></td>
                                            <td>
                                                <span class="status pending">Pending</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state py-5">
                                <i class="fas fa-tools"></i>
                                <h3>No Pending Requests</h3>
                                <p>You don't have any pending maintenance requests. All your properties are in good condition!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5>Monthly Income (Ksh)</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="incomeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>Payment Methods</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="paymentMethodsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Properties Section -->
        <div class="dashboard-section <?= $section === 'properties' ? 'active' : '' ?>" id="properties-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Property Management</h2>
                <button class="btn btn-primary" id="addPropertyBtn">
                    <i class="fas fa-plus me-2"></i> Add Property
                </button>
            </div>
            
            <?php if ($propertiesCount > 0): ?>
                <div class="filter-controls">
                    <div class="form-group">
                        <select class="form-select" id="propertyFilter">
                            <option>All Properties</option>
                            <option>Active</option>
                            <option>Vacant</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" class="form-control" placeholder="Search properties...">
                    </div>
                </div>
                
                <div class="row">
                    <?php foreach ($properties as $property): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card property-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="card-title"><?= $property['name'] ?></h5>
                                        <span class="badge bg-success"><?= $property['number_of_units'] ?> units</span>
                                    </div>
                                    <p class="text-muted">
                                        <i class="fas fa-map-marker-alt me-2"></i>
                                        <?= $property['location'] ?>, <?= $property['town'] ?>
                                    </p>
                                    <p class="mb-1"><strong>Category:</strong> <?= $property['category_name'] ?></p>
                                    <p><strong>Status:</strong> <?= ucfirst($property['status']) ?></p>
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <a href="#" class="btn btn-sm btn-outline-primary">View Details</a>
                                        <small class="text-muted">
                                            <?= $property['county'] ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-home"></i>
                    <h3>No Properties Added Yet</h3>
                    <p>Get started by adding your first property. This will allow you to manage units, tenants, leases, and payments.</p>
                    <button class="btn btn-primary" id="addPropertyBtn">Add Your First Property</button>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Tenants Section -->
        <div class="dashboard-section <?= $section === 'tenants' ? 'active' : '' ?>" id="tenants-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Tenant Management</h2>
                <button class="btn btn-primary" id="addTenantBtn">
                    <i class="fas fa-plus me-2"></i> Add Tenant
                </button>
            </div>
            
            <?php if ($tenantsCount > 0): ?>
                <div class="filter-controls">
                    <div class="form-group">
                        <select class="form-select" id="tenantFilter">
                            <option>All Tenants</option>
                            <option>Current</option>
                            <option>Overdue</option>
                            <option>Lease Ending</option>
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
                                <th>Property</th>
                                <th>Unit</th>
                                <th>Rent Due</th>
                                <th>Status</th>
                                <th>Lease End</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tenants as $tenant): ?>
                                <tr>
                                    <td><?= $tenant['first_name'] . ' ' . $tenant['last_name'] ?></td>
                                    <td><?= $tenant['property_name'] ?></td>
                                    <td><?= $tenant['unit_name'] ?></td>
                                    <td>Ksh <?= number_format($tenant['monthly_rent'], 2) ?></td>
                                    <td>
                                        <span class="status active">Active</span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($tenant['end_date'])) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1">View</button>
                                        <button class="btn btn-sm btn-outline-secondary">Message</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No Tenants Added Yet</h3>
                    <p>Add tenants to your properties to start managing leases and collecting rent payments.</p>
                    <button class="btn btn-primary" id="addTenantBtn">Add Your First Tenant</button>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Leases Section -->
        <div class="dashboard-section <?= $section === 'leases' ? 'active' : '' ?>" id="leases-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Lease Agreements</h2>
                <button class="btn btn-primary" id="createLeaseBtn">
                    <i class="fas fa-plus me-2"></i> Create Lease
                </button>
            </div>
            
            <?php if ($activeLeases > 0): ?>
                <div class="filter-controls">
                    <div class="form-group">
                        <select class="form-select" id="leaseFilter">
                            <option>All Leases</option>
                            <option>Active</option>
                            <option>Expired</option>
                            <option>Upcoming Renewal</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" class="form-control" placeholder="Search leases...">
                    </div>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>Property</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Rent</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leases as $lease): ?>
                                <tr>
                                    <td><?= $lease['first_name'] . ' ' . $lease['last_name'] ?></td>
                                    <td><?= $lease['property_name'] ?></td>
                                    <td><?= date('M j, Y', strtotime($lease['start_date'])) ?></td>
                                    <td><?= date('M j, Y', strtotime($lease['end_date'])) ?></td>
                                    <td>Ksh <?= number_format($lease['monthly_rent'], 2) ?></td>
                                    <td>
                                        <span class="status <?= $lease['status'] === 'active' ? 'active' : 'pending' ?>">
                                            <?= ucfirst($lease['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1">View</button>
                                        <button class="btn btn-sm btn-outline-secondary">Renew</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-contract"></i>
                    <h3>No Lease Agreements</h3>
                    <p>Create lease agreements to formalize rental arrangements with your tenants.</p>
                    <button class="btn btn-primary" id="createLeaseBtn">Create Your First Lease</button>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Payments Section -->
        <div class="dashboard-section <?= $section === 'payments' ? 'active' : '' ?>" id="payments-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Payment Records</h2>
                <div>
                    <button class="btn btn-outline-primary me-2">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                    <button class="btn btn-primary" id="recordPaymentBtn">
                        <i class="fas fa-plus me-1"></i> Record Payment
                    </button>
                </div>
            </div>
            
            <?php if (count($payments) > 0): ?>
                <div class="filter-controls">
                    <div class="form-group">
                        <select class="form-select" id="paymentFilter">
                            <option>All Payments</option>
                            <option>This Month</option>
                            <option>Last Month</option>
                            <option>This Quarter</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" class="form-control" placeholder="Search payments...">
                    </div>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Tenant</th>
                                <th>Property</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                    <td><?= $payment['first_name'] . ' ' . $payment['last_name'] ?></td>
                                    <td><?= $payment['property_name'] ?></td>
                                    <td>Ksh <?= number_format($payment['amount'], 2) ?></td>
                                    <td><?= ucfirst($payment['payment_method']) ?></td>
                                    <td>
                                        <span class="status completed">Completed</span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>No Payment Records</h3>
                    <p>You haven't received any payments yet. Set up your payment methods and invite tenants to make payments.</p>
                    <button class="btn btn-primary" id="recordPaymentBtn">Record Payment</button>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Maintenance Section -->
        <div class="dashboard-section <?= $section === 'maintenance' ? 'active' : '' ?>" id="maintenance-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Maintenance Requests</h2>
                <button class="btn btn-primary" id="createRequestBtn">
                    <i class="fas fa-plus me-2"></i> Create Request
                </button>
            </div>
            
            <?php if (count($maintenanceList) > 0): ?>
                <div class="filter-controls">
                    <div class="form-group">
                        <select class="form-select" id="maintenanceFilter">
                            <option>All Requests</option>
                            <option>Pending</option>
                            <option>In Progress</option>
                            <option>Completed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" class="form-control" placeholder="Search requests...">
                    </div>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Property</th>
                                <th>Unit</th>
                                <th>Description</th>
                                <th>Urgency</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenanceList as $request): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($request['created_at'])) ?></td>
                                    <td><?= $request['property_name'] ?></td>
                                    <td><?= $request['unit_name'] ?></td>
                                    <td><?= $request['title'] ?></td>
                                    <td><?= ucfirst($request['urgency']) ?></td>
                                    <td>
                                        <span class="status <?= $request['status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $request['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1">View</button>
                                        <button class="btn btn-sm btn-outline-success">Resolve</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-tools"></i>
                    <h3>No Maintenance Requests</h3>
                    <p>You don't have any maintenance requests at this time. All your properties are in good condition!</p>
                    <button class="btn btn-primary" id="createRequestBtn">Create New Request</button>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Reports Section -->
        <div class="dashboard-section <?= $section === 'reports' ? 'active' : '' ?>" id="reports-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Financial Reports</h2>
                <div class="btn-group">
                    <button class="btn btn-outline-primary active">Monthly</button>
                    <button class="btn btn-outline-primary">Quarterly</button>
                    <button class="btn btn-outline-primary">Annual</button>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5>Income Report (Last 6 Months)</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="incomeReportChart"></canvas>
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
            
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Expense Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="expenseChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Payment Status</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="paymentStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Profile Section -->
        <div class="dashboard-section <?= $section === 'profile' ? 'active' : '' ?>" id="profile-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>My Profile</h2>
                <button class="btn btn-outline-primary" id="editProfileBtn">
                    <i class="fas fa-edit me-1"></i> Edit Profile
                </button>
            </div>
            
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?= substr($landlord['first_name'], 0, 1) . substr($landlord['last_name'], 0, 1) ?>
                    </div>
                    <h3><?= $landlord['first_name'] . ' ' . $landlord['last_name'] ?></h3>
                    <p>Landlord at KejaSmart</p>
                </div>
                <div class="profile-body">
                    <div class="profile-row">
                        <div class="profile-label">Full Name</div>
                        <div class="profile-value"><?= $landlord['first_name'] . ' ' . $landlord['last_name'] ?></div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">Email Address</div>
                        <div class="profile-value"><?= $landlord['email'] ?></div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">Phone Number</div>
                        <div class="profile-value"><?= $landlord['phone'] ?></div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">National ID</div>
                        <div class="profile-value"><?= $landlord['national_id'] ?></div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">KRA PIN</div>
                        <div class="profile-value"><?= $landlord['kra_pin'] ?? 'Not provided' ?></div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">Address</div>
                        <div class="profile-value"><?= $landlord['address'] ?? 'Not provided' ?></div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">County</div>
                        <div class="profile-value"><?= $landlord['county'] ?? 'Not provided' ?></div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">Account Status</div>
                        <div class="profile-value">
                            <span class="badge bg-success">Approved</span>
                        </div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">Member Since</div>
                        <div class="profile-value"><?= date('F j, Y', strtotime($landlord['created_at'])) ?></div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Change Password</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" name="new_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-primary">Update Password</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Notification Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                                <label class="form-check-label" for="emailNotifications">Email Notifications</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="smsNotifications" checked>
                                <label class="form-check-label" for="smsNotifications">SMS Notifications</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="paymentReminders" checked>
                                <label class="form-check-label" for="paymentReminders">Payment Reminders</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="maintenanceAlerts" checked>
                                <label class="form-check-label" for="maintenanceAlerts">Maintenance Alerts</label>
                            </div>
                            <button class="btn btn-primary">Save Settings</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scroll to top button -->
    <div class="scroll-top" id="scrollTop">
        <i class="fas fa-arrow-up"></i>
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
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Property Name</label>
                            <input type="text" class="form-control" name="property_name" placeholder="Enter property name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" placeholder="Enter property location" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">County</label>
                            <input type="text" class="form-control" name="county" placeholder="Enter county" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Town</label>
                            <input type="text" class="form-control" name="town" placeholder="Enter town" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Number of Units</label>
                            <input type="number" class="form-control" name="units" placeholder="Enter number of units" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Property Type</label>
                            <select class="form-select" name="category_id" required>
                                <option value="">Select property type</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>"><?= $category['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description (Optional)</label>
                            <textarea class="form-control" name="description" placeholder="Describe the property"></textarea>
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

    <!-- Add Tenant Modal -->
    <div class="modal fade" id="addTenantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Tenant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" placeholder="Enter first name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" placeholder="Enter last name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" placeholder="Enter email address" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone" placeholder="Enter phone number" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">National ID</label>
                            <input type="text" class="form-control" name="national_id" placeholder="Enter national ID" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Unit</label>
                            <select class="form-select" name="unit_id" required>
                                <option value="">Select unit</option>
                                <?php foreach ($vacantUnits as $unit): ?>
                                    <option value="<?= $unit['id'] ?>"><?= $unit['property_name'] ?> - <?= $unit['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Monthly Rent (Ksh)</label>
                            <input type="number" class="form-control" name="rent_due" placeholder="Enter monthly rent" required>
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

    <!-- Record Payment Modal -->
    <div class="modal fade" id="recordPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Lease</label>
                            <select class="form-select" name="lease_id" required>
                                <option value="">Select lease</option>
                                <?php foreach ($activeLeasesList as $lease): ?>
                                    <option value="<?= $lease['id'] ?>"><?= $lease['first_name'] . ' ' . $lease['last_name'] ?> - <?= $lease['unit_name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount (Ksh)</label>
                            <input type="number" class="form-control" name="amount" step="0.01" placeholder="Enter amount" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="method" required>
                                <option value="mpesa">M-Pesa</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference" placeholder="Enter reference number">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="record_payment" class="btn btn-primary">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Request Modal -->
    <div class="modal fade" id="createRequestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Maintenance Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Unit</label>
                            <select class="form-select" name="unit_id" required>
                                <option value="">Select unit</option>
                                <?php foreach ($allUnits as $unit): ?>
                                    <option value="<?= $unit['id'] ?>"><?= $unit['property_name'] ?> - <?= $unit['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" placeholder="Enter request title" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" placeholder="Describe the issue" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Urgency</label>
                            <select class="form-select" name="urgency" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="emergency">Emergency</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_request" class="btn btn-primary">Create Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div class="modal fade" id="editProfileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name" value="<?= $landlord['first_name'] ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name" value="<?= $landlord['last_name'] ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= $landlord['email'] ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone" value="<?= $landlord['phone'] ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">National ID</label>
                            <input type="text" class="form-control" name="national_id" value="<?= $landlord['national_id'] ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">KRA PIN</label>
                            <input type="text" class="form-control" name="kra_pin" value="<?= $landlord['kra_pin'] ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address"><?= $landlord['address'] ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">County</label>
                            <input type="text" class="form-control" name="county" value="<?= $landlord['county'] ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // DOM Elements
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainContent = document.getElementById('mainContent');
        const menuItems = document.querySelectorAll('.menu-item');
        const dashboardSections = document.querySelectorAll('.dashboard-section');
        const scrollBtn = document.getElementById('scrollTop');
        const cards = document.querySelectorAll('.card');
        
        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle sidebar on mobile
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                sidebarOverlay.style.display = sidebar.classList.contains('active') ? 'block' : 'none';
            });
            
            // Close sidebar when clicking outside on mobile
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.style.display = 'none';
            });
            
            // Navigation between sections
            menuItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    if (this.getAttribute('href') === 'logout.php' || 
                        this.getAttribute('href') === 'index.html') return;
                    
                    // Close sidebar on mobile after selection
                    if (window.innerWidth < 992) {
                        sidebar.classList.remove('active');
                        sidebarOverlay.style.display = 'none';
                    }
                });
            });
            
            // Handle card clicks (dashboard cards)
            cards.forEach(card => {
                card.addEventListener('click', function() {
                    const target = this.getAttribute('data-section');
                    if (!target) return;
                    
                    window.location.href = `?section=${target}`;
                });
            });
            
            // Scroll to top functionality
            window.addEventListener('scroll', function() {
                if (window.scrollY > 300) {
                    scrollBtn.classList.add('show');
                } else {
                    scrollBtn.classList.remove('show');
                }
            });
            
            scrollBtn.addEventListener('click', function() {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
            
            // Modal buttons
            document.querySelectorAll('#addPropertyBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = new bootstrap.Modal(document.getElementById('addPropertyModal'));
                    modal.show();
                });
            });
            
            document.querySelectorAll('#addTenantBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = new bootstrap.Modal(document.getElementById('addTenantModal'));
                    modal.show();
                });
            });
            
            document.querySelectorAll('#createLeaseBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    alert('Lease creation functionality would open here');
                });
            });
            
            document.querySelectorAll('#recordPaymentBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = new bootstrap.Modal(document.getElementById('recordPaymentModal'));
                    modal.show();
                });
            });
            
            document.querySelectorAll('#createRequestBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = new bootstrap.Modal(document.getElementById('createRequestModal'));
                    modal.show();
                });
            });
            
            document.querySelectorAll('#editProfileBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = new bootstrap.Modal(document.getElementById('editProfileModal'));
                    modal.show();
                });
            });
            
            // Initialize charts
            initCharts();
        });
        
        // Initialize charts
        function initCharts() {
            // Monthly Income Chart
            const incomeCtx = document.getElementById('incomeChart').getContext('2d');
            const incomeChart = new Chart(incomeCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'Monthly Income (Ksh)',
                        data: [120000, 150000, 180000, 90000, 210000, 240000, 170000, 190000, 220000, 250000, 230000, 260000],
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        borderColor: '#198754',
                        borderWidth: 2,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#198754',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
            
            // Payment Methods Chart
            const paymentMethodsCtx = document.getElementById('paymentMethodsChart').getContext('2d');
            const paymentMethodsChart = new Chart(paymentMethodsCtx, {
                type: 'doughnut',
                data: {
                    labels: ['M-PESA', 'Bank Transfer', 'Cash'],
                    datasets: [{
                        data: [65, 25, 10],
                        backgroundColor: [
                            '#198754',
                            '#0d6efd',
                            '#6c757d'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
            
            <?php if ($section === 'reports'): ?>
                // Income Report Chart
                const incomeReportCtx = document.getElementById('incomeReportChart').getContext('2d');
                const incomeReportChart = new Chart(incomeReportCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_map(function($item) { 
                            return date('M Y', strtotime($item['month'] . '-01')); 
                        }, $incomeReport)) ?>,
                        datasets: [{
                            label: 'Income (Ksh)',
                            data: <?= json_encode(array_column($incomeReport, 'amount')) ?>,
                            backgroundColor: '#198754',
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    drawBorder: false
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
                
                // Occupancy Chart
                const occupancyCtx = document.getElementById('occupancyChart').getContext('2d');
                const occupancyChart = new Chart(occupancyCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Occupied', 'Vacant'],
                        datasets: [{
                            data: [<?= $occupancy['occupied'] ?>, <?= $occupancy['total'] - $occupancy['occupied'] ?>],
                            backgroundColor: [
                                '#198754',
                                '#e9ecef'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '80%',
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
                
                // Expense Chart
                const expenseCtx = document.getElementById('expenseChart').getContext('2d');
                const expenseChart = new Chart(expenseCtx, {
                    type: 'pie',
                    data: {
                        labels: ['Maintenance', 'Utilities', 'Taxes', 'Insurance', 'Other'],
                        datasets: [{
                            data: [35, 25, 20, 15, 5],
                            backgroundColor: [
                                '#198754',
                                '#0d6efd',
                                '#ffc107',
                                '#dc3545',
                                '#6c757d'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
                
                // Payment Status Chart
                const paymentStatusCtx = document.getElementById('paymentStatusChart').getContext('2d');
                const paymentStatusChart = new Chart(paymentStatusCtx, {
                    type: 'bar',
                    data: {
                        labels: ['On Time', 'Late', 'Overdue'],
                        datasets: [{
                            label: 'Payments',
                            data: [<?= $paymentStatus['on_time'] ?>, <?= $paymentStatus['late'] ?>, 0],
                            backgroundColor: [
                                '#198754',
                                '#ffc107',
                                '#dc3545'
                            ],
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    drawBorder: false
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            <?php endif; ?>
        }
    </script>
</body>
</html>