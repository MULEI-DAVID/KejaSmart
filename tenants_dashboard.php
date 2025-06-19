<?php
session_start();
require_once 'config.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$tenantId = $_SESSION['tenant_id'] ?? null;

// Get tenant data
$stmt = $pdo->prepare("SELECT u.*, t.* 
                      FROM users u
                      JOIN tenants t ON u.id = t.id
                      WHERE u.id = ?");
$stmt->execute([$userId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Get active lease
$activeLease = null;
$stmt = $pdo->prepare("SELECT l.*, u.name AS unit_name, p.name AS property_name,
                      ut.name AS unit_type, ut.base_rent
                      FROM leases l
                      JOIN units u ON l.unit_id = u.id
                      JOIN properties p ON u.property_id = p.id
                      JOIN unit_types ut ON u.type_id = ut.id
                      WHERE l.tenant_id = ? 
                      AND l.status = 'active'
                      AND CURDATE() BETWEEN l.start_date AND l.end_date
                      ORDER BY l.start_date DESC
                      LIMIT 1");
$stmt->execute([$userId]);
$activeLease = $stmt->fetch(PDO::FETCH_ASSOC);

// Dashboard stats
$balance = 0;
$monthsRemaining = 0;
$leaseProgress = 0;
$unreadNotices = 0;
$pendingRequests = 0;

if ($activeLease) {
    // Calculate rent balance
    $stmt = $pdo->prepare("SELECT 
        (SELECT SUM(amount) FROM payments 
         WHERE lease_id = ? AND status = 'completed') AS total_paid,
        (SELECT monthly_rent FROM leases WHERE id = ?) AS monthly_rent");
    $stmt->execute([$activeLease['id'], $activeLease['id']]);
    $rentData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $totalPaid = $rentData['total_paid'] ?? 0;
    $monthlyRent = $rentData['monthly_rent'] ?? 0;
    
    $startDate = new DateTime($activeLease['start_date']);
    $today = new DateTime();
    $monthsOccupied = $startDate->diff($today)->m + ($startDate->diff($today)->y * 12);
    
    $balance = max(0, ($monthsOccupied * $monthlyRent) - $totalPaid);
    
    $endDate = new DateTime($activeLease['end_date']);
    $monthsRemaining = $endDate->diff($today)->m + ($endDate->diff($today)->y * 12);
    $totalMonths = $startDate->diff($endDate)->m + ($startDate->diff($endDate)->y * 12);
    $leaseProgress = ($totalMonths > 0) ? (($monthsOccupied / $totalMonths) * 100) : 0;
    
    // Maintenance requests
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests 
                          WHERE tenant_id = ? AND status IN ('pending', 'assigned')");
    $stmt->execute([$userId]);
    $pendingRequests = $stmt->fetchColumn();
}

// Unread notices
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications 
                      WHERE user_id = ? AND user_type = 'tenant' AND is_read = 0");
$stmt->execute([$userId]);
$unreadNotices = $stmt->fetchColumn();

// Recent payments
$recentPayments = [];
if ($activeLease) {
    $stmt = $pdo->prepare("SELECT * FROM payments 
                          WHERE lease_id = ? 
                          ORDER BY payment_date DESC 
                          LIMIT 5");
    $stmt->execute([$activeLease['id']]);
    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// All payments
$allPayments = [];
if ($activeLease) {
    $stmt = $pdo->prepare("SELECT * FROM payments 
                          WHERE lease_id = ? 
                          ORDER BY payment_date DESC");
    $stmt->execute([$activeLease['id']]);
    $allPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Pending maintenance requests
$stmt = $pdo->prepare("SELECT * FROM maintenance_requests 
                      WHERE tenant_id = ? 
                      AND status IN ('pending', 'assigned')
                      ORDER BY created_at DESC 
                      LIMIT 5");
$stmt->execute([$userId]);
$pendingRequestsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// All maintenance requests
$allMaintenanceRequests = [];
$stmt = $pdo->prepare("SELECT * FROM maintenance_requests 
                      WHERE tenant_id = ? 
                      ORDER BY created_at DESC");
$stmt->execute([$userId]);
$allMaintenanceRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent notices
$stmt = $pdo->prepare("SELECT * FROM notifications 
                      WHERE user_id = ? AND user_type = 'tenant'
                      ORDER BY created_at DESC 
                      LIMIT 5");
$stmt->execute([$userId]);
$recentNotices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// All notices
$allNotices = [];
$stmt = $pdo->prepare("SELECT * FROM notifications 
                      WHERE user_id = ? AND user_type = 'tenant'
                      ORDER BY created_at DESC");
$stmt->execute([$userId]);
$allNotices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Property information
$propertyInfo = [];
if ($activeLease) {
    $stmt = $pdo->prepare("SELECT p.*, pm.name AS manager_name, pm.phone AS manager_phone
                          FROM properties p
                          LEFT JOIN property_managers pm ON p.manager_id = pm.id
                          WHERE p.id = (SELECT property_id FROM units WHERE id = ?)");
    $stmt->execute([$activeLease['unit_id']]);
    $propertyInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_payment'])) {
    $amount = filter_input(INPUT_POST, 'amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $method = filter_input(INPUT_POST, 'method', FILTER_SANITIZE_STRING);
    $reference = filter_input(INPUT_POST, 'reference', FILTER_SANITIZE_STRING);
    $paymentDate = date('Y-m-d');
    
    if ($amount && $method && $activeLease) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO payments 
                                  (lease_id, amount, payment_date, method, reference, status, created_at)
                                  VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$activeLease['id'], $amount, $paymentDate, $method, $reference]);
            
            $message = "New payment submitted: Ksh " . number_format($amount, 2) . " via $method";
            $stmt = $pdo->prepare("INSERT INTO notifications 
                                  (user_id, user_type, title, message, created_at)
                                  VALUES (?, 'tenant', 'Payment Submitted', ?, NOW())");
            $stmt->execute([$userId, $message]);
            
            $pdo->commit();
            $_SESSION['success'] = "Payment submitted successfully! Awaiting landlord confirmation.";
            header("Refresh:0");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error submitting payment: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Please fill all required fields";
    }
}

// Handle maintenance request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $urgency = filter_input(INPUT_POST, 'urgency', FILTER_SANITIZE_STRING);
    
    if ($title && $description && $activeLease) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO maintenance_requests 
                                  (tenant_id, unit_id, property_id, title, category, description, urgency, status, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([
                $userId,
                $activeLease['unit_id'],
                $activeLease['property_id'] ?? null,
                $title,
                $category,
                $description,
                $urgency
            ]);
            
            $message = "New maintenance request: $title";
            $stmt = $pdo->prepare("INSERT INTO notifications 
                                  (user_id, user_type, title, message, created_at)
                                  VALUES (?, 'tenant', 'Maintenance Request', ?, NOW())");
            $stmt->execute([$userId, $message]);
            
            $pdo->commit();
            $_SESSION['success'] = "Maintenance request submitted successfully!";
            header("Refresh:0");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error submitting request: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Please fill all required fields";
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstName = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
    $lastName = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $nationalId = filter_input(INPUT_POST, 'national_id', FILTER_SANITIZE_STRING);
    $emergencyName = filter_input(INPUT_POST, 'emergency_name', FILTER_SANITIZE_STRING);
    $emergencyContact = filter_input(INPUT_POST, 'emergency_contact', FILTER_SANITIZE_STRING);
    
    if ($firstName && $lastName && $phone) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE users SET 
                                  first_name = ?, last_name = ?, phone = ?
                                  WHERE id = ?");
            $stmt->execute([$firstName, $lastName, $phone, $userId]);
            
            $stmt = $pdo->prepare("UPDATE tenants SET 
                                  national_id = ?, emergency_name = ?, emergency_contact = ?
                                  WHERE id = ?");
            $stmt->execute([$nationalId, $emergencyName, $emergencyContact, $userId]);
            
            $pdo->commit();
            $_SESSION['success'] = "Profile updated successfully!";
            header("Refresh:0");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error updating profile: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Please fill all required fields";
    }
}

// Handle notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notification_action'])) {
    $action = $_POST['action'];
    $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_SANITIZE_NUMBER_INT);
    
    if ($notificationId) {
        try {
            if ($action === 'mark_read') {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
                $stmt->execute([$notificationId]);
                $_SESSION['success'] = "Notification marked as read";
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
                $stmt->execute([$notificationId]);
                $_SESSION['success'] = "Notification deleted";
            }
            header("Refresh:0");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error processing request: " . $e->getMessage();
        }
    }
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $settings = [
        'notify_email' => isset($_POST['notify_email']) ? 1 : 0,
        'notify_sms' => isset($_POST['notify_sms']) ? 1 : 0,
        'notify_push' => isset($_POST['notify_push']) ? 1 : 0,
        'rent_reminders' => isset($_POST['rent_reminders']) ? 1 : 0,
        'maintenance_updates' => isset($_POST['maintenance_updates']) ? 1 : 0,
        'community_news' => isset($_POST['community_news']) ? 1 : 0,
        'promotional_offers' => isset($_POST['promotional_offers']) ? 1 : 0,
        'language' => filter_input(INPUT_POST, 'language', FILTER_SANITIZE_STRING),
        'timezone' => filter_input(INPUT_POST, 'timezone', FILTER_SANITIZE_STRING)
    ];
    
    try {
        $jsonSettings = json_encode($settings);
        $stmt = $pdo->prepare("UPDATE tenants SET settings = ? WHERE id = ?");
        $stmt->execute([$jsonSettings, $userId]);
        $_SESSION['success'] = "Settings updated successfully!";
        header("Refresh:0");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating settings: " . $e->getMessage();
    }
}

// Get tenant settings
$tenantSettings = [
    'notify_email' => 1,
    'notify_sms' => 1,
    'notify_push' => 0,
    'rent_reminders' => 1,
    'maintenance_updates' => 1,
    'community_news' => 1,
    'promotional_offers' => 0,
    'language' => 'English',
    'timezone' => 'Africa/Nairobi'
];

if (!empty($tenant['settings'])) {
    $savedSettings = json_decode($tenant['settings'], true);
    if ($savedSettings) {
        $tenantSettings = array_merge($tenantSettings, $savedSettings);
    }
}

// Payment methods
$paymentMethods = [
    [
        'name' => 'M-PESA',
        'icon' => 'fas fa-mobile-alt',
        'instructions' => [
            'Go to M-PESA Menu',
            'Select Lipa na M-PESA',
            'Enter Business Number: 123456',
            'Enter Account Number: ' . ($tenantId ?: 'TENANT-' . $userId),
            'Enter Amount',
            'Enter your PIN'
        ]
    ],
    [
        'name' => 'Bank Transfer',
        'icon' => 'fas fa-university',
        'details' => [
            'Bank Name: Equity Bank',
            'Account Name: KejaSmart Properties',
            'Account Number: 0123456789',
            'Branch: Westlands'
        ]
    ],
    [
        'name' => 'Credit Card',
        'icon' => 'fas fa-credit-card',
        'instructions' => 'Secure online payment via Stripe'
    ]
];

// Document types
$documentTypes = ['Lease Agreement', 'Payment Receipt', 'ID Document', 'Utility Bill', 'Other'];

// Documents
$documents = [
    [
        'id' => 1,
        'name' => 'Lease Agreement - 2023',
        'type' => 'Lease Agreement',
        'date' => '2023-01-15',
        'size' => '2.4 MB',
        'url' => '#'
    ],
    [
        'id' => 2,
        'name' => 'June 2023 Payment Receipt',
        'type' => 'Payment Receipt',
        'date' => '2023-06-05',
        'size' => '0.8 MB',
        'url' => '#'
    ],
    [
        'id' => 3,
        'name' => 'National ID Copy',
        'type' => 'ID Document',
        'date' => '2023-03-10',
        'size' => '1.2 MB',
        'url' => '#'
    ]
];

// Timezones
$timezones = [
    'Africa/Nairobi' => 'East Africa Time (EAT)',
    'UTC' => 'Coordinated Universal Time (UTC)',
    'Europe/London' => 'British Summer Time (BST)',
    'America/New_York' => 'Eastern Daylight Time (EDT)'
];

// Languages
$languages = [
    'English' => 'English',
    'Swahili' => 'Swahili',
    'French' => 'French',
    'Spanish' => 'Spanish'
];

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $docName = filter_input(INPUT_POST, 'doc_name', FILTER_SANITIZE_STRING);
    $docType = filter_input(INPUT_POST, 'doc_type', FILTER_SANITIZE_STRING);
    
    if ($docName && $docType) {
        $_SESSION['success'] = "Document uploaded successfully!";
        header("Refresh:0");
        exit();
    } else {
        $_SESSION['error'] = "Please fill all required fields";
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = filter_input(INPUT_POST, 'current_password', FILTER_SANITIZE_STRING);
    $newPassword = filter_input(INPUT_POST, 'new_password', FILTER_SANITIZE_STRING);
    $confirmPassword = filter_input(INPUT_POST, 'confirm_password', FILTER_SANITIZE_STRING);
    
    if ($currentPassword && $newPassword && $confirmPassword) {
        if ($newPassword === $confirmPassword) {
            try {
                // Verify current password
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($currentPassword, $user['password'])) {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $userId]);
                    
                    $_SESSION['success'] = "Password changed successfully!";
                    header("Refresh:0");
                    exit();
                } else {
                    $_SESSION['error'] = "Current password is incorrect";
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error changing password: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "New passwords do not match";
        }
    } else {
        $_SESSION['error'] = "Please fill all required fields";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Dashboard | KejaSmart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        }
        
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f8f9fa;
            color: #333;
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
        
        .user-profile img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--keja-primary);
            font-size: 1rem;
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
        
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 25px;
        }
        
        .profile-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .profile-header {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--keja-primary-light);
            color: var(--keja-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 15px;
        }
        
        .notice-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #6f42c1;
        }
        
        .notice-title {
            color: #6f42c1;
            margin-bottom: 10px;
        }
        
        .notice-date {
            color: var(--keja-secondary);
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        
        .lease-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .progress-bar {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            margin: 20px 0;
        }
        
        .progress-value {
            height: 100%;
            background: var(--keja-primary);
            width: <?= $leaseProgress ?>%;
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
            
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .card {
                padding: 20px;
            }
            
            .user-details h3, .user-details p {
                display: none;
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
                <?php if ($unreadNotices > 0): ?>
                    <span class="notification-count"><?= $unreadNotices ?></span>
                <?php endif; ?>
            </div>
            
            <div class="user-profile">
                <div class="avatar">
                    <i class="fas fa-user"></i>
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
                <a href="#" class="menu-item active" data-section="dashboard">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="payments">
                    <i class="fas fa-money-bill-wave"></i> Payments
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="lease">
                    <i class="fas fa-file-contract"></i> Lease Agreement
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="maintenance">
                    <i class="fas fa-tools"></i> Maintenance
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="notices">
                    <i class="fas fa-bell"></i> Notifications
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="documents">
                    <i class="fas fa-folder"></i> Documents
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="profile">
                    <i class="fas fa-user"></i> Profile
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="settings">
                    <i class="fas fa-cog"></i> Settings
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
            <div class="welcome">
                <h1>Welcome back, <?= htmlspecialchars($tenant['first_name']) ?>!</h1>
                <p>Here's what's happening with your tenancy today</p>
            </div>
            
            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Rent Balance</div>
                        <div class="card-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                    </div>
                    <div class="card-value">Ksh <?= number_format($balance, 2) ?></div>
                    <div class="card-label">Due in 5 days</div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Lease Duration</div>
                        <div class="card-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $monthsRemaining ?> Months</div>
                    <?php if ($activeLease): ?>
                        <div class="card-label">Expires on <?= date('M j, Y', strtotime($activeLease['end_date'])) ?></div>
                    <?php else: ?>
                        <div class="card-label">No active lease</div>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Maintenance</div>
                        <div class="card-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $pendingRequests ?></div>
                    <div class="card-label">Pending requests</div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Notifications</div>
                        <div class="card-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                    </div>
                    <div class="card-value"><?= $unreadNotices ?></div>
                    <div class="card-label">Unread notifications</div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-6">
                    <div class="table-container">
                        <div class="table-header p-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Payments</h5>
                            <a href="#" class="btn btn-sm btn-primary" data-section="payments">View All</a>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentPayments)): ?>
                                    <?php foreach ($recentPayments as $payment): ?>
                                        <tr>
                                            <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                            <td><?= htmlspecialchars($payment['description'] ?? 'Rent Payment') ?></td>
                                            <td>Ksh <?= number_format($payment['amount'], 2) ?></td>
                                            <td>
                                                <span class="status completed">Completed</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4">
                                            <i class="fas fa-money-bill-wave fa-2x text-muted mb-3"></i>
                                            <p>No recent payments</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="table-container">
                        <div class="table-header p-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Pending Requests</h5>
                            <a href="#" class="btn btn-sm btn-primary" data-section="maintenance">View All</a>
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
                                <?php if (!empty($pendingRequestsList)): ?>
                                    <?php foreach ($pendingRequestsList as $request): ?>
                                        <tr>
                                            <td><?= date('M j, Y', strtotime($request['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($request['title']) ?></td>
                                            <td>
                                                <span class="status pending">Pending</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center py-4">
                                            <i class="fas fa-tools fa-2x text-muted mb-3"></i>
                                            <p>No pending requests</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payments Section -->
        <div class="dashboard-section" id="payments-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Payment History</h3>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#makePaymentModal">
                    <i class="fas fa-plus me-2"></i> Make Payment
                </button>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($allPayments)): ?>
                            <?php foreach ($allPayments as $payment): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                    <td><?= htmlspecialchars($payment['description'] ?? 'Rent Payment') ?></td>
                                    <td>Ksh <?= number_format($payment['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($payment['method'] ?? 'MPESA') ?></td>
                                    <td>
                                        <span class="status <?= $payment['status'] === 'completed' ? 'completed' : 'pending' ?>">
                                            <?= ucfirst($payment['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="#" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <i class="fas fa-money-bill-wave fa-2x text-muted mb-3"></i>
                                    <p>No payment records found</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Payment Methods -->
            <div class="mt-5">
                <h4 class="mb-4">Payment Methods</h4>
                <div class="row">
                    <?php foreach ($paymentMethods as $method): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-primary text-white rounded-circle p-3 me-3">
                                        <i class="<?= $method['icon'] ?> fa-2x"></i>
                                    </div>
                                    <h5 class="mb-0"><?= $method['name'] ?></h5>
                                </div>
                                
                                <?php if (isset($method['instructions'])): ?>
                                    <?php if (is_array($method['instructions'])): ?>
                                        <ol class="mb-3">
                                            <?php foreach ($method['instructions'] as $step): ?>
                                                <li><?= $step ?></li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php else: ?>
                                        <p><?= $method['instructions'] ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if (isset($method['details'])): ?>
                                    <div class="bg-light p-3 rounded mb-3">
                                        <?php foreach ($method['details'] as $detail): ?>
                                            <div class="d-flex justify-content-between">
                                                <span><?= explode(':', $detail)[0] ?>:</span>
                                                <strong><?= explode(':', $detail)[1] ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Make Payment Form -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Make Payment</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Amount (Ksh)</label>
                                    <input type="number" class="form-control" name="amount" 
                                           value="<?= $monthlyRent ?? 0 ?>" min="100" step="100" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Payment Method</label>
                                    <select class="form-select" name="method" required>
                                        <option value="">Select method</option>
                                        <option value="M-PESA">M-PESA</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="Credit Card">Credit Card</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Transaction Reference</label>
                            <input type="text" class="form-control" name="reference" required>
                            <div class="form-text">Enter your M-PESA code or bank transaction ID</div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> 
                            Payments may take 1-2 business days to be verified by your landlord
                        </div>
                        
                        <button type="submit" name="make_payment" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i> Submit Payment
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Property Manager Contact -->
            <?php if (!empty($propertyInfo)): ?>
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Property Management Contact</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary text-white rounded-circle p-3 me-3">
                            <i class="fas fa-user-tie fa-2x"></i>
                        </div>
                        <div>
                            <h5><?= $propertyInfo['manager_name'] ?? 'Not Assigned' ?></h5>
                            <p class="mb-1">Property: <?= $propertyInfo['name'] ?></p>
                            <p class="mb-1">Phone: <?= $propertyInfo['manager_phone'] ?? 'N/A' ?></p>
                            <p class="mb-0">Email: <?= $propertyInfo['manager_email'] ?? 'N/A' ?></p>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn btn-outline-primary me-2">
                            <i class="fas fa-phone me-1"></i> Call Manager
                        </button>
                        <button class="btn btn-outline-secondary">
                            <i class="fas fa-envelope me-1"></i> Send Email
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Lease Agreement Section -->
        <div class="dashboard-section" id="lease-section">
            <div class="lease-card">
                <h3 class="mb-4">Lease Agreement</h3>
                
                <?php if ($activeLease): ?>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <h6>Property Information</h6>
                                <p><strong>Property:</strong> <?= htmlspecialchars($activeLease['property_name']) ?></p>
                                <p><strong>Unit:</strong> <?= htmlspecialchars($activeLease['unit_name']) ?></p>
                                <p><strong>Type:</strong> <?= htmlspecialchars($activeLease['unit_type']) ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <h6>Lease Details</h6>
                                <p><strong>Start Date:</strong> <?= date('M j, Y', strtotime($activeLease['start_date'])) ?></p>
                                <p><strong>End Date:</strong> <?= date('M j, Y', strtotime($activeLease['end_date'])) ?></p>
                                <p><strong>Monthly Rent:</strong> Ksh <?= number_format($activeLease['monthly_rent'], 2) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <h6>Lease Progress</h6>
                        <div class="progress-bar">
                            <div class="progress-value"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <span><?= $monthsRemaining ?> months remaining</span>
                            <span><?= round($leaseProgress) ?>% complete</span>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <h6>Terms & Conditions</h6>
                        <p class="text-muted">
                            The tenant agrees to pay rent on or before the <?= $activeLease['payment_due_day'] ?> of each month. 
                            The property must be maintained in good condition, and any damages beyond normal wear and tear 
                            will be charged to the tenant.
                        </p>
                    </div>
                    
                    <button class="btn btn-primary">
                        <i class="fas fa-download me-2"></i> Download Lease Agreement
                    </button>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-contract fa-3x text-muted mb-3"></i>
                        <h4>No active lease agreement</h4>
                        <p class="text-muted">Contact your landlord for lease information</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Maintenance Section -->
        <div class="dashboard-section" id="maintenance-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Maintenance Requests</h3>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRequestModal">
                    <i class="fas fa-plus me-2"></i> New Request
                </button>
            </div>
            
            <!-- Request Status Tabs -->
            <ul class="nav nav-tabs mb-4" id="requestTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">Pending (<?= $pendingRequests ?>)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="inprogress-tab" data-bs-toggle="tab" data-bs-target="#inprogress" type="button" role="tab">In Progress</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed" type="button" role="tab">Completed</button>
                </li>
            </ul>
            
            <div class="tab-content" id="requestTabsContent">
                <!-- Pending Requests Tab -->
                <div class="tab-pane fade show active" id="pending" role="tabpanel">
                    <?php if (!empty($allMaintenanceRequests)): ?>
                        <?php foreach ($allMaintenanceRequests as $request): ?>
                            <?php if (in_array($request['status'], ['pending', 'assigned'])): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5><?= htmlspecialchars($request['title']) ?></h5>
                                            <p class="text-muted"><?= date('M j, Y \a\t g:i a', strtotime($request['created_at'])) ?></p>
                                            <p><?= htmlspecialchars($request['description']) ?></p>
                                            <p><strong>Category:</strong> <?= $request['category'] ?> | 
                                               <strong>Urgency:</strong> <?= ucfirst($request['urgency']) ?></p>
                                        </div>
                                        <div>
                                            <span class="status <?= $request['status'] === 'pending' ? 'pending' : 'completed' ?>">
                                                <?= ucfirst($request['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <?php if (!empty($request['photo'])): ?>
                                            <img src="<?= htmlspecialchars($request['photo']) ?>" alt="Request photo" class="img-thumbnail me-2" width="100">
                                        <?php endif; ?>
                                        <?php if ($request['status'] === 'pending'): ?>
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-times me-1"></i> Cancel Request
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                            <h4>No pending maintenance requests</h4>
                            <p class="text-muted">All your maintenance issues are resolved</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- In Progress Tab -->
                <div class="tab-pane fade" id="inprogress" role="tabpanel">
                    <?php if (!empty($allMaintenanceRequests)): ?>
                        <?php foreach ($allMaintenanceRequests as $request): ?>
                            <?php if ($request['status'] === 'assigned'): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5><?= htmlspecialchars($request['title']) ?></h5>
                                            <p class="text-muted">Submitted: <?= date('M j, Y', strtotime($request['created_at'])) ?></p>
                                            <p><?= htmlspecialchars($request['description']) ?></p>
                                            <p><strong>Assigned To:</strong> Maintenance Team</p>
                                        </div>
                                        <div>
                                            <span class="status pending">In Progress</span>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <?php if (!empty($request['photo'])): ?>
                                            <img src="<?= htmlspecialchars($request['photo']) ?>" alt="Request photo" class="img-thumbnail me-2" width="100">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (count(array_filter($allMaintenanceRequests, fn($r) => $r['status'] === 'assigned')) === 0): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-hourglass-half fa-3x text-info mb-3"></i>
                                <h4>No requests in progress</h4>
                                <p class="text-muted">Your pending requests will appear here once assigned</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-hourglass-half fa-3x text-info mb-3"></i>
                            <h4>No requests in progress</h4>
                            <p class="text-muted">Your pending requests will appear here once assigned</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Completed Tab -->
                <div class="tab-pane fade" id="completed" role="tabpanel">
                    <?php if (!empty($allMaintenanceRequests)): ?>
                        <?php foreach ($allMaintenanceRequests as $request): ?>
                            <?php if ($request['status'] === 'completed'): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5><?= htmlspecialchars($request['title']) ?></h5>
                                            <p class="text-muted">Completed: <?= date('M j, Y', strtotime($request['completed_at'])) ?></p>
                                            <p><?= htmlspecialchars($request['description']) ?></p>
                                        </div>
                                        <div>
                                            <span class="status completed">Completed</span>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <?php if (!empty($request['photo'])): ?>
                                            <img src="<?= htmlspecialchars($request['photo']) ?>" alt="Request photo" class="img-thumbnail me-2" width="100">
                                        <?php endif; ?>
                                        <div class="mt-3">
                                            <h6>Technician Feedback:</h6>
                                            <div class="bg-light p-3 rounded">
                                                <p class="mb-0"><?= $request['resolution_notes'] ?? 'Issue resolved successfully' ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (count(array_filter($allMaintenanceRequests, fn($r) => $r['status'] === 'completed')) === 0): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h4>No completed requests</h4>
                                <p class="text-muted">Your completed requests will appear here</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                            <h4>No completed requests</h4>
                            <p class="text-muted">Your completed requests will appear here</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Notifications Section -->
        <div class="dashboard-section" id="notices-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Notifications</h3>
                <button class="btn btn-outline-secondary" id="markAllRead">
                    <i class="fas fa-check-circle me-2"></i> Mark All as Read
                </button>
            </div>
            
            <div class="d-flex mb-4">
                <div class="me-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="showUnread" checked>
                        <label class="form-check-label" for="showUnread">Show Unread Only</label>
                    </div>
                </div>
                <div class="w-50">
                    <input type="text" class="form-control" placeholder="Search notifications...">
                </div>
            </div>
            
            <?php if (!empty($allNotices)): ?>
                <?php foreach ($allNotices as $notice): ?>
                    <div class="notice-card <?= $notice['is_read'] ? '' : 'unread' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h4 class="notice-title"><?= htmlspecialchars($notice['title']) ?></h4>
                                <p class="notice-date"><?= date('M j, Y \a\t g:i a', strtotime($notice['created_at'])) ?></p>
                                <p><?= htmlspecialchars($notice['message']) ?></p>
                            </div>
                            <div>
                                <?php if (!$notice['is_read']): ?>
                                    <span class="badge bg-danger">New</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-3">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="notification_id" value="<?= $notice['id'] ?>">
                                <?php if (!$notice['is_read']): ?>
                                    <button type="submit" name="notification_action" value="mark_read" class="btn btn-sm btn-outline-primary me-2">
                                        <i class="fas fa-check me-1"></i> Mark as Read
                                    </button>
                                <?php endif; ?>
                                <button type="submit" name="notification_action" value="delete" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-trash-alt me-1"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                    <h4>No notifications</h4>
                    <p class="text-muted">You'll see important updates here</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Documents Section -->
        <div class="dashboard-section" id="documents-section">
            <h3 class="mb-4">My Documents</h3>
            
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="input-group">
                        <input type="text" class="form-control" placeholder="Search documents...">
                        <button class="btn btn-outline-secondary" type="button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                        <i class="fas fa-upload me-2"></i> Upload Document
                    </button>
                </div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Type</th>
                            <th>Upload Date</th>
                            <th>Size</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($documents)): ?>
                            <?php foreach ($documents as $doc): ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-file-contract text-primary me-2"></i>
                                        <?= htmlspecialchars($doc['name']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($doc['type']) ?></td>
                                    <td><?= date('M j, Y', strtotime($doc['date'])) ?></td>
                                    <td><?= $doc['size'] ?></td>
                                    <td>
                                        <a href="<?= $doc['url'] ?>" class="btn btn-sm btn-outline-primary me-2" target="_blank">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="<?= $doc['url'] ?>" class="btn btn-sm btn-outline-success me-2" download>
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-danger delete-document" data-id="<?= $doc['id'] ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                    <h4>No documents uploaded</h4>
                                    <p class="text-muted">Upload your important documents</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Profile Section -->
        <div class="dashboard-section" id="profile-section">
            <div class="profile-grid">
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <h4><?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?></h4>
                        <p class="text-muted">Tenant</p>
                    </div>
                    
                    <div class="mb-4">
                        <h6>Account Information</h6>
                        <p><i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($tenant['email']) ?></p>
                        <p><i class="fas fa-phone me-2"></i> <?= htmlspecialchars($tenant['phone']) ?></p>
                        <p><i class="fas fa-calendar me-2"></i> Joined <?= date('M Y', strtotime($tenant['created_at'])) ?></p>
                    </div>
                    
                    <div class="mb-4">
                        <h6>Emergency Contact</h6>
                        <p><i class="fas fa-user-circle me-2"></i> <?= htmlspecialchars($tenant['emergency_name'] ?? 'Not set') ?></p>
                        <p><i class="fas fa-phone me-2"></i> <?= htmlspecialchars($tenant['emergency_contact'] ?? 'Not set') ?></p>
                    </div>
                </div>
                
                <div class="profile-card">
                    <h4 class="mb-4">Personal Information</h4>
                    
                    <form method="POST">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>First Name</label>
                                    <input type="text" class="form-control" name="first_name" 
                                           value="<?= htmlspecialchars($tenant['first_name']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Last Name</label>
                                    <input type="text" class="form-control" name="last_name" 
                                           value="<?= htmlspecialchars($tenant['last_name']) ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Phone Number</label>
                                    <input type="text" class="form-control" name="phone" 
                                           value="<?= htmlspecialchars($tenant['phone']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>National ID</label>
                                    <input type="text" class="form-control" name="national_id" 
                                           value="<?= htmlspecialchars($tenant['national_id']) ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <h6>Emergency Contact</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Contact Name</label>
                                        <input type="text" class="form-control" name="emergency_name" 
                                               value="<?= htmlspecialchars($tenant['emergency_name']) ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Phone Number</label>
                                        <input type="text" class="form-control" name="emergency_contact" 
                                               value="<?= htmlspecialchars($tenant['emergency_contact']) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Settings Section -->
        <div class="dashboard-section" id="settings-section">
            <h3 class="mb-4">Account Settings</h3>
            
            <div class="row">
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">Security Settings</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="passwordForm">
                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" class="form-control" name="new_password" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i> Password must be at least 8 characters long and include a number
                                </div>
                                <button type="submit" name="change_password" class="btn btn-primary">Update Password</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">Notification Preferences</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <h6>Notification Methods</h6>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="notify_email" 
                                               id="emailNotifications" <?= $tenantSettings['notify_email'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="emailNotifications">Email Notifications</label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="notify_sms" 
                                               id="smsNotifications" <?= $tenantSettings['notify_sms'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="smsNotifications">SMS Notifications</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="notify_push" 
                                               id="pushNotifications" <?= $tenantSettings['notify_push'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="pushNotifications">Push Notifications</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <h6>Notification Types</h6>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="rent_reminders" 
                                               id="rentReminders" <?= $tenantSettings['rent_reminders'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="rentReminders">Rent Payment Reminders</label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="maintenance_updates" 
                                               id="maintenanceUpdates" <?= $tenantSettings['maintenance_updates'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="maintenanceUpdates">Maintenance Updates</label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="community_news" 
                                               id="communityNews" <?= $tenantSettings['community_news'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="communityNews">Community News</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="promotional_offers" 
                                               id="promotionalOffers" <?= $tenantSettings['promotional_offers'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="promotionalOffers">Promotional Offers</label>
                                    </div>
                                </div>
                                
                                <button type="submit" name="update_settings" class="btn btn-primary">Save Preferences</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">Account Preferences</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Language</label>
                                    <select class="form-select" name="language">
                                        <?php foreach ($languages as $code => $name): ?>
                                            <option value="<?= $code ?>" <?= $tenantSettings['language'] == $code ? 'selected' : '' ?>>
                                                <?= $name ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Time Zone</label>
                                    <select class="form-select" name="timezone">
                                        <?php foreach ($timezones as $code => $name): ?>
                                            <option value="<?= $code ?>" <?= $tenantSettings['timezone'] == $code ? 'selected' : '' ?>>
                                                <?= $name ?> (<?= $code ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <button type="submit" name="update_settings" class="btn btn-primary">Save Preferences</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0">Danger Zone</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6>Deactivate Account</h6>
                                    <p class="text-muted mb-0">Temporarily disable your account</p>
                                </div>
                                <button class="btn btn-outline-danger">
                                    Deactivate
                                </button>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6>Delete Account</h6>
                                    <p class="text-muted mb-0">Permanently remove your account</p>
                                </div>
                                <button class="btn btn-outline-danger">
                                    Delete Account
                                </button>
                            </div>
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

    <!-- Make Payment Modal -->
    <div class="modal fade" id="makePaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Make Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="paymentForm">
                        <div class="mb-3">
                            <label class="form-label">Payment Amount (Ksh)</label>
                            <input type="number" class="form-control" name="amount" 
                                   value="<?= $monthlyRent ?? 0 ?>" min="100" step="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="method" required>
                                <option value="">Select method</option>
                                <option value="M-PESA">M-PESA</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Credit Card">Credit Card</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transaction Reference</label>
                            <input type="text" class="form-control" name="reference" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="paymentForm" name="make_payment" class="btn btn-primary">Submit Payment</button>
                </div>
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
                <div class="modal-body">
                    <form method="POST" id="maintenanceForm">
                        <div class="mb-3">
                            <label class="form-label">Request Title</label>
                            <input type="text" class="form-control" name="title" placeholder="Brief description of the issue" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="Plumbing">Plumbing</option>
                                <option value="Electrical">Electrical</option>
                                <option value="HVAC">HVAC</option>
                                <option value="Appliance">Appliance</option>
                                <option value="Structural">Structural</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" rows="4" name="description" placeholder="Describe the issue in detail" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Upload Photo (Optional)</label>
                            <input type="file" class="form-control" name="photo">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Urgency</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="urgency" id="low" value="low" checked>
                                    <label class="form-check-label" for="low">Low</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="urgency" id="medium" value="medium">
                                    <label class="form-check-label" for="medium">Medium</label>
                                </div>
                                <极
                                    <input class="form-check-input" type="radio" name="urgency" id="high" value="high">
                                    <label class="form-check-label" for="high">High (Emergency)</label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="maintenanceForm" name="submit_request" class="btn btn-primary">Submit Request</button>
                </极>
            </div>
        </div>
    </div>
    
    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadDocumentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="documentForm">
                        <div class="mb-3">
                            <label class="form-label">Document Name</label>
                            <input type="text" class="form-control" name="doc_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document Type</label>
                            <select class="form-select" name="doc_type" required>
                                <option value="">Select type</option>
                                <?php foreach ($documentTypes as $type): ?>
                                    <option value="<?= $type ?>"><?= $type ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Upload File</label>
                            <input type="file" class="form-control" name="document" required>
                            <div class="form-text">Max file size: 10MB. Supported formats: PDF, JPG, PNG, DOC</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="documentForm" name="upload_document" class="btn btn-primary">Upload</button>
                </div>
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
            
            // Handle "View All" buttons
            document.querySelectorAll('[data-section]').forEach(button => {
                if (button.classList.contains('menu-item')) return;
                
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const target = this.getAttribute('data-section');
                    
                    // Remove active class from all menu items
                    menuItems.forEach(i => i.classList.remove('active'));
                    
                    // Find the matching menu item and activate it
                    menuItems.forEach(item => {
                        if (item.getAttribute('data-section') === target) {
                            item.classList.add('active');
                        }
                    });
                    
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
            
            // Mark all notifications as read
            document.getElementById('markAllRead').addEventListener('click', function(e) {
                e.preventDefault();
                fetch('mark_all_read.php', { method: 'POST' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        }
                    });
            });
        });
    </script>
</body>
</html>