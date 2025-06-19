<?php
// Simulate session and database for demonstration
session_start();

// Simulate user authentication
if (!isset($_SESSION['landlord_id'])) {
    $_SESSION['landlord_id'] = 1;
    $_SESSION['first_login'] = true;
}

// Simulate user data
$landlord = [
    'id' => 1,
    'full_name' => 'Jane Smith',
    'email' => 'jane@example.com',
    'phone' => '+254 712 345 678',
    'join_date' => '2023-01-15'
];

// Dashboard stats (simulated)
$propertiesCount = 3;
$tenantsCount = 12;
$activeLeases = 8;
$totalPayments = 185600;
$pendingRequests = 2;

// Properties data
$properties = [
    [
        'id' => 1,
        'name' => 'Greenview Apartments',
        'location' => 'Westlands, Nairobi',
        'units' => 12,
        'occupied' => 11,
        'monthly_rent' => 285000,
        'type' => 'Apartment Building'
    ],
    [
        'id' => 2,
        'name' => 'Riverfront Villas',
        'location' => 'Karen, Nairobi',
        'units' => 8,
        'occupied' => 6,
        'monthly_rent' => 245000,
        'type' => 'Townhouses'
    ],
    [
        'id' => 3,
        'name' => 'Sunset Heights',
        'location' => 'Kilimani, Nairobi',
        'units' => 6,
        'occupied' => 6,
        'monthly_rent' => 252000,
        'type' => 'Apartment Building'
    ]
];

// Tenants data
$tenants = [
    [
        'id' => 1,
        'name' => 'John Kamau',
        'property' => 'Greenview Apartments',
        'unit' => '4B',
        'rent_due' => 25000,
        'status' => 'current',
        'lease_end' => '2024-01-14'
    ],
    [
        'id' => 2,
        'name' => 'Sarah Wanjiku',
        'property' => 'Riverfront Villas',
        'unit' => '2A',
        'rent_due' => 35000,
        'status' => 'overdue',
        'lease_end' => '2024-02-28'
    ],
    [
        'id' => 3,
        'name' => 'David Ochieng',
        'property' => 'Sunset Heights',
        'unit' => '5C',
        'rent_due' => 42000,
        'status' => 'current',
        'lease_end' => '2023-12-15'
    ]
];

// Recent payments
$recentPayments = [
    [
        'date' => '2023-10-15',
        'tenant' => 'John Kamau',
        'property' => 'Greenview Apartments',
        'amount' => 25000,
        'method' => 'M-PESA',
        'status' => 'completed'
    ],
    [
        'date' => '2023-10-14',
        'tenant' => 'Sarah Wanjiku',
        'property' => 'Riverfront Villas',
        'amount' => 35000,
        'method' => 'Bank Transfer',
        'status' => 'completed'
    ],
    [
        'date' => '2023-10-12',
        'tenant' => 'David Ochieng',
        'property' => 'Sunset Heights',
        'amount' => 42000,
        'method' => 'M-PESA',
        'status' => 'completed'
    ]
];

// Maintenance requests
$maintenanceRequests = [
    [
        'id' => 1,
        'date' => '2023-10-14',
        'property' => 'Greenview Apartments',
        'unit' => '4B',
        'description' => 'Kitchen sink leaking',
        'urgency' => 'High',
        'status' => 'pending'
    ],
    [
        'id' => 2,
        'date' => '2023-10-12',
        'property' => 'Riverfront Villas',
        'unit' => '2A',
        'description' => 'AC not cooling properly',
        'urgency' => 'Medium',
        'status' => 'in-progress'
    ],
    [
        'id' => 3,
        'date' => '2023-10-10',
        'property' => 'Sunset Heights',
        'unit' => '5C',
        'description' => 'Broken window in living room',
        'urgency' => 'High',
        'status' => 'completed'
    ]
];

// Check if first login
$firstLogin = isset($_SESSION['first_login']) ? $_SESSION['first_login'] : true;
unset($_SESSION['first_login']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_property'])) {
        // Add new property
        $newProperty = [
            'id' => count($properties) + 1,
            'name' => $_POST['property_name'],
            'location' => $_POST['location'],
            'units' => (int)$_POST['units'],
            'occupied' => 0,
            'monthly_rent' => 0,
            'type' => $_POST['property_type']
        ];
        $properties[] = $newProperty;
        $propertiesCount = count($properties);
        $firstLogin = false;
    } elseif (isset($_POST['add_tenant'])) {
        // Add new tenant
        $newTenant = [
            'id' => count($tenants) + 1,
            'name' => $_POST['full_name'],
            'property' => $_POST['property'],
            'unit' => $_POST['unit'],
            'rent_due' => (int)$_POST['rent_due'],
            'status' => 'current',
            'lease_end' => $_POST['lease_end']
        ];
        $tenants[] = $newTenant;
        $tenantsCount = count($tenants);
    }
}
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
                    <?= substr($landlord['full_name'], 0, 1) ?>
                </div>
                <div class="user-details">
                    <h3><?= htmlspecialchars($landlord['full_name']) ?></h3>
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
                <a href="#" class="menu-item active" data-section="dashboard">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="properties">
                    <i class="fas fa-home"></i> Properties
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="tenants">
                    <i class="fas fa-users"></i> Tenants
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="leases">
                    <i class="fas fa-file-contract"></i> Leases
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="payments">
                    <i class="fas fa-money-bill-wave"></i> Payments
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="maintenance">
                    <i class="fas fa-tools"></i> Maintenance
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="reports">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" data-section="profile">
                    <i class="fas fa-user"></i> My Profile
                </a>
            </li>
            <li>
                <a href="#" class="menu-item">
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
        <!-- Dashboard Section -->
        <div class="dashboard-section active" id="dashboard-section">
            <?php if ($firstLogin): ?>
                <div class="welcome-message">
                    <h2>Welcome to KejaSmart, <?= htmlspecialchars($landlord['full_name']) ?>!</h2>
                    <p>We're excited to help you manage your properties efficiently. Let's get started by setting up your first property and tenant information.</p>
                    <button class="btn btn-light">Get Started Guide</button>
                </div>
            <?php endif; ?>
            
            <div class="welcome">
                <h1>Welcome back, <?= htmlspecialchars($landlord['full_name']) ?>!</h1>
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
                            <button class="btn btn-outline-primary" disabled>Next Step</button>
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
                            <button class="btn btn-outline-primary" disabled>Next Step</button>
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
                            <button class="btn btn-outline-primary" disabled>Next Step</button>
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
                            <a href="#" class="btn btn-sm btn-primary" data-section="payments">View All</a>
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
                                            <td><?= date('M j, Y', strtotime($payment['date'])) ?></td>
                                            <td><?= htmlspecialchars($payment['tenant']) ?></td>
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
                            <a href="#" class="btn btn-sm btn-primary" data-section="maintenance">View All</a>
                        </div>
                        <?php if ($pendingRequests > 0): ?>
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
                                        <?php if ($request['status'] === 'pending'): ?>
                                            <tr>
                                                <td><?= date('M j, Y', strtotime($request['date'])) ?></td>
                                                <td><?= htmlspecialchars($request['property']) ?></td>
                                                <td>
                                                    <span class="status pending">Pending</span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
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
        <div class="dashboard-section" id="properties-section">
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
                                        <span class="badge bg-success"><?= $property['units'] ?> units</span>
                                    </div>
                                    <p class="text-muted">
                                        <i class="fas fa-map-marker-alt me-2"></i>
                                        <?= $property['location'] ?>
                                    </p>
                                    <p class="mb-1"><strong>Occupancy:</strong> <?= round(($property['occupied'] / $property['units']) * 100) ?>%</p>
                                    <p><strong>Monthly Revenue:</strong> Ksh <?= number_format($property['monthly_rent'], 2) ?></p>
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <a href="#" class="btn btn-sm btn-outline-primary">View Details</a>
                                        <small class="text-muted">
                                            <?= $property['type'] ?>
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
        <div class="dashboard-section" id="tenants-section">
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
                                    <td><?= $tenant['name'] ?></td>
                                    <td><?= $tenant['property'] ?></td>
                                    <td><?= $tenant['unit'] ?></td>
                                    <td>Ksh <?= number_format($tenant['rent_due'], 2) ?></td>
                                    <td>
                                        <span class="status <?= $tenant['status'] ?>">
                                            <?= ucfirst($tenant['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($tenant['lease_end'])) ?></td>
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
        <div class="dashboard-section" id="leases-section">
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
                            <?php foreach ($tenants as $tenant): ?>
                                <tr>
                                    <td><?= $tenant['name'] ?></td>
                                    <td><?= $tenant['property'] ?></td>
                                    <td><?= date('M j, Y', strtotime('-1 year', strtotime($tenant['lease_end']))) ?></td>
                                    <td><?= date('M j, Y', strtotime($tenant['lease_end'])) ?></td>
                                    <td>Ksh <?= number_format($tenant['rent_due'], 2) ?></td>
                                    <td>
                                        <span class="status active">Active</span>
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
        <div class="dashboard-section" id="payments-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Payment Records</h2>
                <div>
                    <button class="btn btn-outline-primary me-2">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                    <button class="btn btn-primary" id="filterPaymentsBtn">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </div>
            
            <?php if (count($recentPayments) > 0): ?>
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
                            <?php foreach ($recentPayments as $payment): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($payment['date'])) ?></td>
                                    <td><?= $payment['tenant'] ?></td>
                                    <td><?= $payment['property'] ?></td>
                                    <td>Ksh <?= number_format($payment['amount'], 2) ?></td>
                                    <td><?= $payment['method'] ?></td>
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
                    <button class="btn btn-primary">Configure Payment Settings</button>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Maintenance Section -->
        <div class="dashboard-section" id="maintenance-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Maintenance Requests</h2>
                <div class="btn-group">
                    <button class="btn btn-outline-primary active">All</button>
                    <button class="btn btn-outline-primary">Pending</button>
                    <button class="btn btn-outline-primary">Resolved</button>
                </div>
            </div>
            
            <?php if (count($maintenanceRequests) > 0): ?>
                <div class="filter-controls">
                    <div class="form-group">
                        <select class="form-select" id="maintenanceFilter">
                            <option>All Properties</option>
                            <option>Greenview Apartments</option>
                            <option>Riverfront Villas</option>
                            <option>Sunset Heights</option>
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
                            <?php foreach ($maintenanceRequests as $request): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($request['date'])) ?></td>
                                    <td><?= $request['property'] ?></td>
                                    <td><?= $request['unit'] ?></td>
                                    <td><?= $request['description'] ?></td>
                                    <td><?= $request['urgency'] ?></td>
                                    <td>
                                        <span class="status <?= $request['status'] ?>">
                                            <?= ucfirst($request['status']) ?>
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
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Reports Section -->
        <div class="dashboard-section" id="reports-section">
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
        <div class="dashboard-section" id="profile-section">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>My Profile</h2>
                <button class="btn btn-outline-primary">
                    <i class="fas fa-edit me-1"></i> Edit Profile
                </button>
            </div>
            
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?= substr($landlord['full_name'], 0, 1) ?>
                    </div>
                    <h3><?= $landlord['full_name'] ?></h3>
                    <p>Landlord at KejaSmart</p>
                </div>
                <div class="profile-body">
                    <div class="profile-row">
                        <div class="profile-label">Full Name</div>
                        <div class="profile-value"><?= $landlord['full_name'] ?></div>
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
                        <div class="profile-label">Account Type</div>
                        <div class="profile-value">Landlord</div>
                    </div>
                    <div class="profile-row">
                        <div class="profile-label">Member Since</div>
                        <div class="profile-value"><?= date('F j, Y', strtotime($landlord['join_date'])) ?></div>
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
                            <form>
                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control">
                                </div>
                                <button type="submit" class="btn btn-primary">Update Password</button>
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
                            <label class="form-label">Number of Units</label>
                            <input type="number" class="form-control" name="units" placeholder="Enter number of units" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Property Type</label>
                            <select class="form-select" name="property_type" required>
                                <option>Apartment Building</option>
                                <option>Single Family Home</option>
                                <option>Commercial Property</option>
                                <option>Multi-family Complex</option>
                            </select>
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
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="full_name" placeholder="Enter tenant's full name" required>
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
                            <label class="form-label">Property</label>
                            <select class="form-select" name="property" required>
                                <option>Select property</option>
                                <?php foreach ($properties as $property): ?>
                                    <option value="<?= $property['name'] ?>"><?= $property['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Unit</label>
                            <input type="text" class="form-control" name="unit" placeholder="Enter unit number" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Monthly Rent (Ksh)</label>
                            <input type="number" class="form-control" name="rent_due" placeholder="Enter monthly rent" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Lease End Date</label>
                            <input type="date" class="form-control" name="lease_end" required>
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
                    
                    // Close sidebar on mobile after selection
                    if (window.innerWidth < 992) {
                        sidebar.classList.remove('active');
                        sidebarOverlay.style.display = 'none';
                    }
                    
                    // Scroll to top
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            });
            
            // Handle card clicks (dashboard cards)
            cards.forEach(card => {
                card.addEventListener('click', function() {
                    const target = this.getAttribute('data-section');
                    if (!target) return;
                    
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
            
            // Handle "View All" buttons
            document.querySelectorAll('.btn-primary[data-section]').forEach(button => {
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
            
            // Modal buttons
            document.querySelectorAll('#addPropertyBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = new bootstrap.Modal(document.getElementById('addPropertyModal'));
                    modal.show();
                });
            });
            
            document.getElementById('addTenantBtn').addEventListener('click', function() {
                const modal = new bootstrap.Modal(document.getElementById('addTenantModal'));
                modal.show();
            });
            
            document.getElementById('createLeaseBtn').addEventListener('click', function() {
                const modal = new bootstrap.Modal(document.getElementById('createLeaseModal'));
                modal.show();
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
            
            // Income Report Chart
            const incomeReportCtx = document.getElementById('incomeReportChart').getContext('2d');
            const incomeReportChart = new Chart(incomeReportCtx, {
                type: 'bar',
                data: {
                    labels: ['May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct'],
                    datasets: [{
                        label: 'Income (Ksh)',
                        data: [120000, 150000, 180000, 90000, 210000, 240000],
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
                        data: [85, 15],
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
                        data: [78, 15, 7],
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
        }
    </script>
</body>
</html>