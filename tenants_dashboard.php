<?php
// Tenant Dashboard Implementation
session_start();

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

// Check if user is logged in
if (!isset($_SESSION['tenant_id'])) {
    // Simulate tenant login for demo purposes
    $_SESSION['tenant_id'] = 1;
    $_SESSION['tenant_name'] = 'John Kamau';
    $_SESSION['tenant_email'] = 'john@example.com';
    $_SESSION['property_id'] = 1;
}

// Get tenant ID
$tenant_id = $_SESSION['tenant_id'];

// Fetch tenant data
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    // Handle case where tenant doesn't exist
    $tenant = [
        'id' => $tenant_id,
        'first_name' => 'John',
        'last_name' => 'Kamau',
        'email' => 'john@example.com',
        'phone' => '+254712345678',
        'national_id' => '12345678',
        'emergency_contact' => '+254798765432',
        'emergency_name' => 'Sarah Wanjiku',
        'occupation' => 'Software Engineer',
        'employer' => 'Tech Solutions Ltd'
    ];
}

// Fetch active lease
$stmt = $pdo->prepare("SELECT leases.*, units.name AS unit_name, properties.name AS property_name 
                      FROM leases 
                      JOIN units ON leases.unit_id = units.id
                      JOIN properties ON units.property_id = properties.id
                      WHERE tenant_id = ? AND status = 'active'");
$stmt->execute([$tenant_id]);
$lease = $stmt->fetch();

// If no active lease, create demo data
if (!$lease) {
    $lease = [
        'id' => 1,
        'start_date' => '2023-01-15',
        'end_date' => '2024-01-14',
        'monthly_rent' => 25000,
        'deposit_paid' => 50000,
        'payment_due_day' => 5,
        'unit_name' => '4B',
        'property_name' => 'Greenview Apartments'
    ];
}

// Fetch recent payments
$stmt = $pdo->prepare("SELECT * FROM payments WHERE lease_id = ? ORDER BY payment_date DESC LIMIT 5");
$stmt->execute([$lease['id']]);
$recentPayments = $stmt->fetchAll();

// If no payments, create demo data
if (empty($recentPayments)) {
    $recentPayments = [
        [
            'id' => 1,
            'payment_date' => '2023-10-15',
            'amount' => 25000,
            'payment_method' => 'M-PESA',
            'reference_number' => 'RF123456',
            'status' => 'completed'
        ],
        [
            'id' => 2,
            'payment_date' => '2023-09-15',
            'amount' => 25000,
            'payment_method' => 'M-PESA',
            'reference_number' => 'RF123455',
            'status' => 'completed'
        ]
    ];
}

// Fetch maintenance requests
$stmt = $pdo->prepare("SELECT * FROM maintenance_requests WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$tenant_id]);
$maintenanceRequests = $stmt->fetchAll();

// If no maintenance requests, create demo data
if (empty($maintenanceRequests)) {
    $maintenanceRequests = [
        [
            'id' => 1,
            'created_at' => '2023-10-14',
            'title' => 'Kitchen sink leaking',
            'description' => 'The kitchen sink has a leak under the cabinet',
            'urgency' => 'high',
            'status' => 'completed'
        ],
        [
            'id' => 2,
            'created_at' => '2023-10-10',
            'title' => 'Broken window',
            'description' => 'Window in living room has a crack',
            'urgency' => 'medium',
            'status' => 'in-progress'
        ]
    ];
}

// Fetch notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND user_type = 'tenant' ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$tenant_id]);
$notifications = $stmt->fetchAll();

// If no notifications, create demo data
if (empty($notifications)) {
    $notifications = [
        [
            'id' => 1,
            'title' => 'Rent Due Reminder',
            'message' => 'Your rent payment is due in 3 days',
            'created_at' => '2023-10-12'
        ],
        [
            'id' => 2,
            'title' => 'Maintenance Update',
            'message' => 'Your maintenance request #2 has been assigned',
            'created_at' => '2023-10-11'
        ]
    ];
}

// Calculate rent balance and next payment
$nextPayment = $lease['monthly_rent'];
$dueDate = date('Y-m-') . $lease['payment_due_day'];
if (date('d') > $lease['payment_due_day']) {
    $dueDate = date('Y-m-d', strtotime('+1 month', strtotime($dueDate)));
}
$daysUntilDue = floor((strtotime($dueDate) - time()) / (60 * 60 * 24));

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_payment'])) {
        // Process payment
        $amount = $_POST['amount'];
        $method = $_POST['method'];
        $reference = $_POST['reference'];
        
        // In a real system, this would save to database
        $newPayment = [
            'id' => count($recentPayments) + 1,
            'payment_date' => date('Y-m-d'),
            'amount' => $amount,
            'payment_method' => $method,
            'reference_number' => $reference,
            'status' => 'pending'
        ];
        array_unshift($recentPayments, $newPayment);
        
        // Add notification
        $newNotification = [
            'id' => count($notifications) + 1,
            'title' => 'Payment Submitted',
            'message' => 'Your payment of Ksh ' . number_format($amount, 2) . ' has been submitted',
            'created_at' => date('Y-m-d H:i:s')
        ];
        array_unshift($notifications, $newNotification);
        
        $paymentSuccess = true;
    }
    
    if (isset($_POST['submit_request'])) {
        // Process maintenance request
        $title = $_POST['title'];
        $description = $_POST['description'];
        $urgency = $_POST['urgency'];
        
        $newRequest = [
            'id' => count($maintenanceRequests) + 1,
            'created_at' => date('Y-m-d H:i:s'),
            'title' => $title,
            'description' => $description,
            'urgency' => $urgency,
            'status' => 'pending'
        ];
        array_unshift($maintenanceRequests, $newRequest);
        
        // Add notification
        $newNotification = [
            'id' => count($notifications) + 1,
            'title' => 'Maintenance Request Submitted',
            'message' => 'Your request "' . $title . '" has been submitted',
            'created_at' => date('Y-m-d H:i:s')
        ];
        array_unshift($notifications, $newNotification);
        
        $requestSuccess = true;
    }
    
    if (isset($_POST['update_profile'])) {
        // Update profile information
        $tenant['first_name'] = $_POST['first_name'];
        $tenant['last_name'] = $_POST['last_name'];
        $tenant['email'] = $_POST['email'];
        $tenant['phone'] = $_POST['phone'];
        $tenant['emergency_contact'] = $_POST['emergency_contact'];
        $tenant['emergency_name'] = $_POST['emergency_name'];
        
        $profileSuccess = true;
    }
}

// Determine active section
$section = isset($_GET['section']) ? $_GET['section'] : 'dashboard';
$validSections = ['dashboard', 'payments', 'lease', 'maintenance', 'notifications', 'profile'];
if (!in_array($section, $validSections)) {
    $section = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Dashboard | KejaSmart</title>
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
                <span class="notification-count">2</span>
            </div>
            
            <div class="user-profile">
                <div class="user-avatar">
                    <?= substr($tenant['first_name'], 0, 1) ?>
                </div>
                <div class="user-details">
                    <h3><?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?></h3>
                    <p>Tenant</p>
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
                <a href="?section=payments" class="menu-item <?= $section === 'payments' ? 'active' : '' ?>" data-section="payments">
                    <i class="fas fa-money-bill-wave"></i> Payments
                </a>
            </li>
            <li>
                <a href="?section=lease" class="menu-item <?= $section === 'lease' ? 'active' : '' ?>" data-section="lease">
                    <i class="fas fa-file-contract"></i> Lease Agreement
                </a>
            </li>
            <li>
                <a href="?section=maintenance" class="menu-item <?= $section === 'maintenance' ? 'active' : '' ?>" data-section="maintenance">
                    <i class="fas fa-tools"></i> Maintenance
                </a>
            </li>
            <li>
                <a href="?section=notifications" class="menu-item <?= $section === 'notifications' ? 'active' : '' ?>" data-section="notifications">
                    <i class="fas fa-bell"></i> Notifications
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
        <!-- Success Messages -->
        <?php if (isset($paymentSuccess)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Payment submitted successfully! Awaiting landlord confirmation.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($requestSuccess)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Maintenance request submitted successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($profileSuccess)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Profile updated successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Dashboard Section -->
        <div class="dashboard-section <?= $section === 'dashboard' ? 'active' : '' ?>" id="dashboard-section">
            <div class="welcome">
                <h1>Welcome, <?= htmlspecialchars($tenant['first_name']) ?>!</h1>
                <p>Here's your tenant dashboard at <?= $lease['property_name'] ?></p>
            </div>
            
            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Next Payment</div>
                        <div class="card-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div class="card-value">Ksh <?= number_format($nextPayment, 2) ?></div>
                    <div class="card-label">Due in <?= $daysUntilDue ?> days</div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Lease Status</div>
                        <div class="card-icon">
                            <i class="fas fa-file-contract"></i>
                        </div>
                    </div>
                    <div class="card-value">Active</div>
                    <div class="card-label">Expires on <?= date('M j, Y', strtotime($lease['end_date'])) ?></div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Maintenance</div>
                        <div class="card-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= count($maintenanceRequests) ?></div>
                    <div class="card-label">Requests this month</div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Notifications</div>
                        <div class="card-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= count($notifications) ?></div>
                    <div class="card-label">Unread notifications</div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-6">
                    <div class="table-container">
                        <div class="table-header p-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Payments</h5>
                            <a href="?section=payments" class="btn btn-sm btn-primary" data-section="payments">View All</a>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentPayments as $payment): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                        <td>Ksh <?= number_format($payment['amount'], 2) ?></td>
                                        <td><?= $payment['payment_method'] ?></td>
                                        <td>
                                            <span class="status <?= $payment['status'] ?>">
                                                <?= ucfirst($payment['status']) ?>
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
                        <div class="table-header p-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Maintenance Requests</h5>
                            <a href="?section=maintenance" class="btn btn-sm btn-primary" data-section="maintenance">View All</a>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($maintenanceRequests as $request): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($request['created_at'])) ?></td>
                                        <td><?= $request['title'] ?></td>
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
            </div>
            
            <div class="row mt-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5>Payment History</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="paymentHistoryChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>Property Manager</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="user-avatar me-3">
                                    <i class="fas fa-user-tie"></i>
                                </div>
                                <div>
                                    <h5 class="mb-0">Jane Smith</h5>
                                    <p class="text-muted">Property Manager</p>
                                </div>
                            </div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-phone me-2"></i> Phone</span>
                                    <span>+254 722 123 456</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-envelope me-2"></i> Email</span>
                                    <span>jane@kejasmart.com</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-clock me-2"></i> Availability</span>
                                    <span>Mon-Fri, 9am-5pm</span>
                                </li>
                            </ul>
                            <div class="mt-3">
                                <button class="btn btn-primary w-100">
                                    <i class="fas fa-comment-dots me-2"></i> Send Message
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payments Section -->
        <div class="dashboard-section <?= $section === 'payments' ? 'active' : '' ?>" id="payments-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Rent Payments</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#makePaymentModal">
                    <i class="fas fa-plus me-2"></i> Make Payment
                </button>
            </div>
            
            <div class="row">
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div>
                                    <h5 class="mb-0">Current Balance</h5>
                                    <p class="text-muted">Next payment due: <?= date('M j, Y', strtotime($dueDate)) ?></p>
                                </div>
                                <div class="text-end">
                                    <h3 class="mb-0">Ksh <?= number_format($nextPayment, 2) ?></h3>
                                    <span class="text-success">No overdue payments</span>
                                </div>
                            </div>
                            
                            <div class="progress mb-4" style="height: 10px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: 85%" aria-valuenow="85" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <span>Paid: Ksh <?= number_format($nextPayment * 0.85, 2) ?></span>
                                <span>Total: Ksh <?= number_format($nextPayment, 2) ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Payment History</h5>
                        </div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                        <th>Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPayments as $payment): ?>
                                        <tr>
                                            <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                            <td>Ksh <?= number_format($payment['amount'], 2) ?></td>
                                            <td><?= $payment['payment_method'] ?></td>
                                            <td><?= $payment['reference_number'] ?></td>
                                            <td>
                                                <span class="status <?= $payment['status'] ?>">
                                                    <?= ucfirst($payment['status']) ?>
                                                </span>
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
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>Payment Methods</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-primary text-white rounded-circle p-3 me-3">
                                        <i class="fas fa-mobile-alt fa-2x"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-0">M-PESA</h5>
                                        <p class="text-muted">Mobile Money Payment</p>
                                    </div>
                                </div>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item">1. Go to M-PESA Menu</li>
                                    <li class="list-group-item">2. Select Lipa na M-PESA</li>
                                    <li class="list-group-item">3. Enter Business Number: 123456</li>
                                    <li class="list-group-item">4. Enter Account Number: TENANT-<?= $tenant_id ?></li>
                                    <li class="list-group-item">5. Enter Amount</li>
                                    <li class="list-group-item">6. Enter your PIN</li>
                                </ul>
                            </div>
                            
                            <div class="mb-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-info text-white rounded-circle p-3 me-3">
                                        <i class="fas fa-university fa-2x"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-0">Bank Transfer</h5>
                                        <p class="text-muted">Direct Bank Deposit</p>
                                    </div>
                                </div>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Bank Name:</span>
                                        <strong>Equity Bank</strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Account Name:</span>
                                        <strong>KejaSmart Properties</strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Account Number:</span>
                                        <strong>0123456789</strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Branch:</span>
                                        <strong>Westlands</strong>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Lease Agreement Section -->
        <div class="dashboard-section <?= $section === 'lease' ? 'active' : '' ?>" id="lease-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Lease Agreement</h2>
                <button class="btn btn-primary">
                    <i class="fas fa-download me-2"></i> Download Lease
                </button>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-4">
                                <h5>Property Information</h5>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Property:</span>
                                        <strong><?= $lease['property_name'] ?></strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Unit:</span>
                                        <strong><?= $lease['unit_name'] ?></strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Address:</span>
                                        <strong>Westlands, Nairobi</strong>
                                    </li>
                                </ul>
                            </div>
                            
                            <div class="mb-4">
                                <h5>Landlord Information</h5>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Name:</span>
                                        <strong>Jane Smith</strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Phone:</span>
                                        <strong>+254 722 123 456</strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Email:</span>
                                        <strong>jane@kejasmart.com</strong>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-4">
                                <h5>Lease Terms</h5>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Start Date:</span>
                                        <strong><?= date('M j, Y', strtotime($lease['start_date'])) ?></strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>End Date:</span>
                                        <strong><?= date('M j, Y', strtotime($lease['end_date'])) ?></strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Monthly Rent:</span>
                                        <strong>Ksh <?= number_format($lease['monthly_rent'], 2) ?></strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Security Deposit:</span>
                                        <strong>Ksh <?= number_format($lease['deposit_paid'], 2) ?></strong>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Payment Due:</span>
                                        <strong><?= $lease['payment_due_day'] ?>th of each month</strong>
                                    </li>
                                </ul>
                            </div>
                            
                            <div class="mb-4">
                                <h5>Lease Status</h5>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="progress" style="height: 10px; width: 200px;">
                                            <div class="progress-bar bg-success" role="progressbar" style="width: 65%" aria-valuenow="65" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <small><?= round(65) ?>% of lease completed</small>
                                    </div>
                                    <span class="badge bg-success">Active</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5>Terms & Conditions</h5>
                        <div class="bg-light p-3 rounded">
                            <p>The tenant agrees to pay the monthly rent of Ksh <?= number_format($lease['monthly_rent'], 2) ?> on or before the <?= $lease['payment_due_day'] ?>th day of each month.</p>
                            <p>The security deposit of Ksh <?= number_format($lease['deposit_paid'], 2) ?> will be refunded within 30 days of lease termination, less any deductions for damages beyond normal wear and tear.</p>
                            <p>The tenant shall maintain the premises in a clean and sanitary condition and shall not make any alterations to the premises without the landlord's written consent.</p>
                            <p>Either party may terminate this lease by giving 60 days written notice prior to the termination date.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Maintenance Section -->
        <div class="dashboard-section <?= $section === 'maintenance' ? 'active' : '' ?>" id="maintenance-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Maintenance Requests</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRequestModal">
                    <i class="fas fa-plus me-2"></i> New Request
                </button>
            </div>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">My Requests</h5>
                        </div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Urgency</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($maintenanceRequests as $request): ?>
                                        <tr>
                                            <td><?= date('M j, Y', strtotime($request['created_at'])) ?></td>
                                            <td><?= $request['title'] ?></td>
                                            <td><?= ucfirst($request['urgency']) ?></td>
                                            <td>
                                                <span class="status <?= $request['status'] ?>">
                                                    <?= ucfirst($request['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary me-1">View</button>
                                                <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Maintenance Team</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="user-avatar me-3">
                                    <i class="fas fa-user-hard-hat"></i>
                                </div>
                                <div>
                                    <h5 class="mb-0">Maintenance Team</h5>
                                    <p class="text-muted">Available Mon-Fri, 8am-5pm</p>
                                </div>
                            </div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-phone me-2"></i> Phone</span>
                                    <span>+254 733 987 654</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-envelope me-2"></i> Email</span>
                                    <span>maintenance@kejasmart.com</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-clock me-2"></i> Response Time</span>
                                    <span>24-48 hours</span>
                                </li>
                            </ul>
                            <div class="mt-3">
                                <button class="btn btn-outline-primary w-100">
                                    <i class="fas fa-phone me-2"></i> Call Maintenance
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Notifications Section -->
        <div class="dashboard-section <?= $section === 'notifications' ? 'active' : '' ?>" id="notifications-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Notifications</h2>
                <button class="btn btn-outline-primary">
                    <i class="fas fa-check-circle me-2"></i> Mark All as Read
                </button>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <div class="list-group">
                        <?php foreach ($notifications as $notification): ?>
                            <a href="#" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1"><?= $notification['title'] ?></h5>
                                    <small><?= date('M j', strtotime($notification['created_at'])) ?></small>
                                </div>
                                <p class="mb-1"><?= $notification['message'] ?></p>
                                <small class="text-primary">Click to view details</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Profile Section -->
        <div class="dashboard-section <?= $section === 'profile' ? 'active' : '' ?>" id="profile-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>My Profile</h2>
                <button class="btn btn-outline-primary">
                    <i class="fas fa-edit me-1"></i> Edit Profile
                </button>
            </div>
            
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?= substr($tenant['first_name'], 0, 1) ?>
                    </div>
                    <h3><?= $tenant['first_name'] . ' ' . $tenant['last_name'] ?></h3>
                    <p>Tenant at KejaSmart</p>
                </div>
                <div class="profile-body">
                    <form method="POST">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">First Name</label>
                                    <input type="text" class="form-control" name="first_name" value="<?= $tenant['first_name'] ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" class="form-control" name="last_name" value="<?= $tenant['last_name'] ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" value="<?= $tenant['email'] ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone" value="<?= $tenant['phone'] ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Emergency Contact Name</label>
                                    <input type="text" class="form-control" name="emergency_name" value="<?= $tenant['emergency_name'] ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Emergency Contact Phone</label>
                                    <input type="tel" class="form-control" name="emergency_contact" value="<?= $tenant['emergency_contact'] ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Occupation</label>
                                    <input type="text" class="form-control" name="occupation" value="<?= $tenant['occupation'] ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Employer</label>
                                    <input type="text" class="form-control" name="employer" value="<?= $tenant['employer'] ?>">
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scroll to top button -->
    <div class="scroll-top" id="scrollTop">
        <i class="fas fa-arrow-up"></i>
    </div>

    <!-- Make Payment Modal -->
    <div class="modal fade" id="makePaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Make Rent Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Payment Amount (Ksh)</label>
                            <input type="number" class="form-control" name="amount" value="<?= $nextPayment ?>" min="100" step="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="method" required>
                                <option value="M-PESA">M-PESA</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cash">Cash</option>
                                <option value="Credit Card">Credit Card</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transaction Reference</label>
                            <input type="text" class="form-control" name="reference" placeholder="Enter M-PESA code or transaction ID" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_payment" class="btn btn-primary">Submit Payment</button>
                    </div>
                </form>
            </div>
                        </div>
    </div>

    <!-- New Request Modal -->
    <div class="modal fade" id="newRequestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Maintenance Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Request Title</label>
                            <input type="text" class="form-control" name="title" placeholder="Brief description of the issue" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" rows="4" name="description" placeholder="Describe the issue in detail" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Urgency</label>
                                    <select class="form-select" name="urgency" required>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High (Emergency)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Upload Photo (Optional)</label>
                                    <input type="file" class="form-control" name="photo">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_request" class="btn btn-primary">Submit Request</button>
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
                    if (this.getAttribute('href') === 'logout.php') return;
                    
                    e.preventDefault();
                    
                    // Remove active class from all items
                    menuItems.forEach(i => i.classList.remove('active'));
                    
                    // Add active class to clicked item
                    this.classList.add('active');
                    
                    // Get target section
                    const target = this.getAttribute('data-section');
                    
                    // Hide all sections
                    dashboardSections.forEach(section => {
                        section.classList.remove('active');
                    });
                    
                    // Show target section
                    document.getElementById(`${target}-section`).classList.add('active');
                    
                    // Close sidebar on mobile
                    if (window.innerWidth < 992) {
                        sidebar.classList.remove('active');
                        sidebarOverlay.style.display = 'none';
                    }
                    
                    // Scroll to top
                    window.scrollTo({ top: 0, behavior: 'smooth' });
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
            
            // Initialize charts
            initCharts();
        });
        
        // Initialize charts
        function initCharts() {
            // Payment History Chart
            const paymentCtx = document.getElementById('paymentHistoryChart').getContext('2d');
            const paymentChart = new Chart(paymentCtx, {
                type: 'bar',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct'],
                    datasets: [{
                        label: 'Rent Paid (Ksh)',
                        data: [25000, 25000, 25000, 25000, 25000, 25000, 25000, 25000, 25000, 25000],
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
        }
    </script>
</body>
</html>