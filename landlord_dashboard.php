<?php
// Start session and check authentication
session_start();

// Database configuration
require_once 'config.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['landlord_id'])) {
    header("Location: login.php");
    exit();
}

// Get landlord data from database
$landlordId = $_SESSION['landlord_id'];
$stmt = $pdo->prepare("SELECT * FROM landlords WHERE id = ?");
$stmt->execute([$landlordId]);
$landlord = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$landlord) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Get dashboard stats
$propertiesCount = $pdo->query("SELECT COUNT(*) FROM properties WHERE landlord_id = $landlordId")->fetchColumn();
$tenantsCount = $pdo->query("SELECT COUNT(*) FROM tenants WHERE landlord_id = $landlordId")->fetchColumn();
$activeLeases = $pdo->query("SELECT COUNT(*) FROM leases WHERE landlord_id = $landlordId AND end_date > NOW()")->fetchColumn();
$totalPayments = $pdo->query("SELECT SUM(amount) FROM payments WHERE landlord_id = $landlordId")->fetchColumn();
$pendingRequests = $pdo->query("SELECT COUNT(*) FROM service_requests WHERE landlord_id = $landlordId AND status = 'pending'")->fetchColumn();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_tenant'])) {
        // Handle tenant addition
        require_once 'api/add_tenant.php';
    } elseif (isset($_POST['add_property'])) {
        // Handle property addition
        require_once 'api/add_property.php';
    } elseif (isset($_POST['add_lease'])) {
        // Handle lease addition
        require_once 'api/add_lease.php';
    } elseif (isset($_POST['update_profile'])) {
        // Handle profile update
        require_once 'api/update_profile.php';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Landlord Dashboard | KejaSmart</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/apexcharts@3.35.0/dist/apexcharts.min.css" rel="stylesheet">
  <style>
    :root {
      --keja-primary: #198754;
      --keja-primary-light: rgba(25, 135, 84, 0.1);
      --keja-secondary: #6c757d;
      --keja-light: #f8f9fa;
      --keja-dark: #111;
    }
    
    body {
      font-family: 'Segoe UI', sans-serif;
      background-color: #f8f9fa;
      overflow-x: hidden;
    }
    
    /* Navigation */
    .navbar-dashboard {
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      height: 56px;
    }
    
    .sidebar {
      width: 250px;
      height: calc(100vh - 56px);
      position: fixed;
      top: 56px;
      left: 0;
      background: white;
      box-shadow: 1px 0 10px rgba(0,0,0,0.05);
      transition: all 0.3s;
      z-index: 1000;
      overflow-y: auto;
    }
    
    .sidebar-collapsed {
      transform: translateX(-100%);
    }
    
    .sidebar .nav-link {
      color: var(--keja-secondary);
      border-radius: 0.25rem;
      margin: 0.25rem 1rem;
      padding: 0.75rem 1rem;
      display: flex;
      align-items: center;
      transition: all 0.2s;
    }
    
    .sidebar .nav-link:hover, 
    .sidebar .nav-link.active {
      background-color: var(--keja-primary-light);
      color: var(--keja-primary);
    }
    
    .sidebar .nav-link.active {
      border-left: 3px solid var(--keja-primary);
      font-weight: 500;
    }
    
    .sidebar .nav-link i {
      width: 24px;
      text-align: center;
      margin-right: 10px;
    }
    
    .main-content {
      margin-left: 250px;
      padding: 20px;
      transition: all 0.3s;
      min-height: calc(100vh - 56px);
    }
    
    .main-content-expanded {
      margin-left: 0;
    }
    
    /* Overlay for mobile sidebar */
    .sidebar-overlay {
      position: fixed;
      top: 56px;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: rgba(0,0,0,0.5);
      z-index: 999;
      display: none;
    }
    
    /* Cards */
    .stat-card {
      border-left: 4px solid var(--keja-primary);
      transition: all 0.3s ease;
      height: 100%;
    }
    
    .hover-box {
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      border-left: 4px solid var(--keja-primary);
      height: 100%;
    }
    
    .hover-box:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 15px rgba(0,0,0,0.1);
    }
    
    /* Dashboard content sections */
    .dashboard-section {
      display: none;
      animation: fadeIn 0.3s ease;
    }
    
    .dashboard-section.active {
      display: block;
    }
    
    /* Empty states */
    .empty-state {
      text-align: center;
      padding: 3rem 0;
    }
    
    .empty-state i {
      font-size: 3rem;
      color: #adb5bd;
      margin-bottom: 1rem;
    }
    
    /* Animations */
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    /* Responsive */
    @media (max-width: 992px) {
      .sidebar {
        transform: translateX(-100%);
      }
      
      .sidebar-expanded {
        transform: translateX(0);
      }
      
      .main-content {
        margin-left: 0;
      }
    }
    
    /* Better scrollbar for sidebar */
    .sidebar::-webkit-scrollbar {
      width: 6px;
    }
    
    .sidebar::-webkit-scrollbar-track {
      background: #f1f1f1;
    }
    
    .sidebar::-webkit-scrollbar-thumb {
      background: #c1c1c1;
      border-radius: 3px;
    }
    
    .sidebar::-webkit-scrollbar-thumb:hover {
      background: #a8a8a8;
    }
    
    /* Status badges */
    .badge-pending {
      background-color: #ffc107;
      color: #000;
    }
    
    .badge-completed {
      background-color: #198754;
      color: #fff;
    }
    
    .badge-overdue {
      background-color: #dc3545;
      color: #fff;
    }
  </style>
</head>
<body>
  <!-- Top Navigation -->
  <nav class="navbar navbar-expand-lg navbar-light bg-white navbar-dashboard sticky-top">
    <div class="container-fluid">
      <button class="btn btn-link text-dark me-2 d-lg-none" id="sidebarToggle">
        <i class="fas fa-bars"></i>
      </button>
      
      <a class="navbar-brand fw-bold text-success" href="index.html">
        <i class="fas fa-home me-1"></i> KejaSmart
      </a>
      
      <div class="d-flex align-items-center ms-auto">
        <!-- Notifications -->
        <div class="dropdown me-3">
          <button class="btn btn-light position-relative rounded-circle" data-bs-toggle="dropdown">
            <i class="fas fa-bell"></i>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $pendingRequests; ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end py-0" style="width: 300px;">
            <li class="dropdown-header bg-light py-2 border-bottom">
              <h6 class="mb-0">Notifications</h6>
            </li>
            <?php if ($pendingRequests > 0): ?>
              <?php 
              $stmt = $pdo->prepare("SELECT * FROM service_requests WHERE landlord_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 5");
              $stmt->execute([$landlordId]);
              $requests = $stmt->fetchAll();
              ?>
              <?php foreach ($requests as $request): ?>
                <li>
                  <a class="dropdown-item py-2 border-bottom" href="#" data-section="requests">
                    <div class="d-flex">
                      <div class="flex-shrink-0 text-warning me-2">
                        <i class="fas fa-tools"></i>
                      </div>
                      <div>
                        <p class="mb-0"><?php echo htmlspecialchars($request['title']); ?></p>
                        <small class="text-muted"><?php echo htmlspecialchars($request['property_name']); ?></small>
                      </div>
                    </div>
                  </a>
                </li>
              <?php endforeach; ?>
              <li class="dropdown-footer bg-light py-2 text-center">
                <a href="#" data-section="requests" class="text-success">View All Notifications</a>
              </li>
            <?php else: ?>
              <li class="py-3 text-center">
                <p class="text-muted mb-0">No new notifications</p>
              </li>
            <?php endif; ?>
          </ul>
        </div>
        
        <!-- User Profile -->
        <div class="dropdown">
          <button class="btn btn-light dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown">
            <div class="me-2 d-none d-sm-block">
              <div class="fw-bold" id="landlordName"><?php echo htmlspecialchars($landlord['full_name']); ?></div>
              <small class="text-muted">Landlord</small>
            </div>
            <i class="fas fa-user-circle fa-lg text-success"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="#" data-section="profile"><i class="fas fa-user me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="#" data-section="settings"><i class="fas fa-cog me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </nav>

  <!-- Sidebar Overlay (Mobile) -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- Dashboard Layout -->
  <div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar sidebar-collapsed" id="sidebar">
      <ul class="nav flex-column pt-3">
        <li class="nav-item">
          <a class="nav-link active" href="#" data-section="dashboard">
            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#" data-section="tenants">
            <i class="fas fa-users me-2"></i> Tenants
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#" data-section="properties">
            <i class="fas fa-home me-2"></i> Properties
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#" data-section="payments">
            <i class="fas fa-money-bill-wave me-2"></i> Payments
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#" data-section="requests">
            <i class="fas fa-tools me-2"></i> Service Requests
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#" data-section="leases">
            <i class="fas fa-file-contract me-2"></i> Lease Agreements
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#" data-section="reports">
            <i class="fas fa-chart-bar me-2"></i> Reports
          </a>
        </li>
        <li class="nav-item mt-3">
          <a class="nav-link" href="#" data-section="settings">
            <i class="fas fa-cog me-2"></i> Settings
          </a>
        </li>
      </ul>
      
      <!-- Sidebar footer -->
      <div class="sidebar-footer px-3 py-2 text-center text-muted small border-top">
        <div>KejaSmart v1.0</div>
        <div>&copy; <?php echo date('Y'); ?> All Rights Reserved</div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
      <!-- Dashboard Overview Section -->
      <div class="dashboard-section active" id="dashboard-section">
        <div class="container-fluid py-3">
          <h2 class="mb-4">Dashboard Overview</h2>
          
          <!-- Stats Cards -->
          <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3">
              <div class="card stat-card hover-box h-100">
                <div class="card-body">
                  <div class="d-flex justify-content-between">
                    <div>
                      <h6 class="card-title text-muted">Properties</h6>
                      <h3 class="card-text"><?php echo $propertiesCount; ?></h3>
                    </div>
                    <i class="fas fa-home fa-2x text-success opacity-50"></i>
                  </div>
                  <div class="mt-2">
                    <a href="#" data-section="properties" class="text-success small">View all properties</a>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="card stat-card hover-box h-100">
                <div class="card-body">
                  <div class="d-flex justify-content-between">
                    <div>
                      <h6 class="card-title text-muted">Tenants</h6>
                      <h3 class="card-text"><?php echo $tenantsCount; ?></h3>
                    </div>
                    <i class="fas fa-users fa-2x text-success opacity-50"></i>
                  </div>
                  <div class="mt-2">
                    <a href="#" data-section="tenants" class="text-success small">View all tenants</a>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="card stat-card hover-box h-100">
                <div class="card-body">
                  <div class="d-flex justify-content-between">
                    <div>
                      <h6 class="card-title text-muted">Active Leases</h6>
                      <h3 class="card-text"><?php echo $activeLeases; ?></h3>
                    </div>
                    <i class="fas fa-file-contract fa-2x text-success opacity-50"></i>
                  </div>
                  <div class="mt-2">
                    <a href="#" data-section="leases" class="text-success small">View all leases</a>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="card stat-card hover-box h-100">
                <div class="card-body">
                  <div class="d-flex justify-content-between">
                    <div>
                      <h6 class="card-title text-muted">Total Income</h6>
                      <h3 class="card-text">Ksh <?php echo number_format($totalPayments, 2); ?></h3>
                    </div>
                    <i class="fas fa-wallet fa-2x text-success opacity-50"></i>
                  </div>
                  <div class="mt-2">
                    <a href="#" data-section="payments" class="text-success small">View payments</a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Recent Activity -->
          <div class="row g-4">
            <div class="col-lg-6">
              <div class="card hover-box h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                  <h5 class="mb-0">Recent Payments</h5>
                  <a href="#" class="btn btn-sm btn-success" data-section="payments">View All</a>
                </div>
                <div class="card-body">
                  <?php
                  $stmt = $pdo->prepare("SELECT p.*, t.full_name as tenant_name 
                                        FROM payments p
                                        JOIN tenants t ON p.tenant_id = t.id
                                        WHERE p.landlord_id = ?
                                        ORDER BY p.payment_date DESC LIMIT 5");
                  $stmt->execute([$landlordId]);
                  $recentPayments = $stmt->fetchAll();
                  
                  if (count($recentPayments) > 0): ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($recentPayments as $payment): ?>
                        <div class="list-group-item">
                          <div class="d-flex justify-content-between align-items-center">
                            <div>
                              <h6 class="mb-0"><?php echo htmlspecialchars($payment['tenant_name']); ?></h6>
                              <small class="text-muted"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></small>
                            </div>
                            <div class="text-end">
                              <h6 class="mb-0 text-success">Ksh <?php echo number_format($payment['amount'], 2); ?></h6>
                              <small class="text-muted"><?php echo ucfirst($payment['payment_method']); ?></small>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="text-center py-4">
                      <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                      <h5>No payments recorded</h5>
                      <p class="text-muted">Payment history will appear here</p>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="card hover-box h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                  <h5 class="mb-0">Pending Requests</h5>
                  <a href="#" class="btn btn-sm btn-success" data-section="requests">View All</a>
                </div>
                <div class="card-body">
                  <?php
                  $stmt = $pdo->prepare("SELECT * FROM service_requests 
                                        WHERE landlord_id = ? AND status = 'pending'
                                        ORDER BY created_at DESC LIMIT 5");
                  $stmt->execute([$landlordId]);
                  $pendingRequests = $stmt->fetchAll();
                  
                  if (count($pendingRequests) > 0): ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($pendingRequests as $request): ?>
                        <a href="#" class="list-group-item list-group-item-action">
                          <div class="d-flex justify-content-between align-items-start">
                            <div>
                              <h6 class="mb-1"><?php echo htmlspecialchars($request['title']); ?></h6>
                              <p class="mb-1"><?php echo htmlspecialchars($request['description']); ?></p>
                              <small class="text-muted"><?php echo htmlspecialchars($request['property_name']); ?></small>
                            </div>
                            <span class="badge badge-pending">Pending</span>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="text-center py-4">
                      <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                      <h5>No pending requests</h5>
                      <p class="text-muted">Service requests will appear here</p>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tenants Section -->
      <div class="dashboard-section" id="tenants-section">
        <div class="container-fluid py-3">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Tenant Management</h2>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTenantModal">
              <i class="fas fa-plus me-1"></i> Add Tenant
            </button>
          </div>
          
          <div class="card hover-box">
            <div class="card-body">
              <?php
              $stmt = $pdo->prepare("SELECT * FROM tenants WHERE landlord_id = ?");
              $stmt->execute([$landlordId]);
              $tenants = $stmt->fetchAll();
              
              if (count($tenants) > 0): ?>
                <div class="table-responsive">
                  <table class="table table-hover">
                    <thead>
                      <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Property</th>
                        <th>Status</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($tenants as $tenant): ?>
                        <tr>
                          <td><?php echo htmlspecialchars($tenant['full_name']); ?></td>
                          <td><?php echo htmlspecialchars($tenant['email']); ?></td>
                          <td><?php echo htmlspecialchars($tenant['phone']); ?></td>
                          <td>
                            <?php
                            if ($tenant['property_id']) {
                              $propertyStmt = $pdo->prepare("SELECT name FROM properties WHERE id = ?");
                              $propertyStmt->execute([$tenant['property_id']]);
                              $property = $propertyStmt->fetch();
                              echo $property ? htmlspecialchars($property['name']) : 'N/A';
                            } else {
                              echo 'N/A';
                            }
                            ?>
                          </td>
                          <td>
                            <span class="badge bg-success">Active</span>
                          </td>
                          <td>
                            <button class="btn btn-sm btn-outline-primary">View</button>
                            <button class="btn btn-sm btn-outline-secondary">Edit</button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="text-center py-5">
                  <i class="fas fa-users fa-3x text-muted mb-3"></i>
                  <h4>No tenants added yet</h4>
                  <p class="text-muted">Add your first tenant to get started</p>
                  <button class="btn btn-success mt-3" data-bs-toggle="modal" data-bs-target="#addTenantModal">
                    <i class="fas fa-plus me-1"></i> Add Tenant
                  </button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Properties Section -->
      <div class="dashboard-section" id="properties-section">
        <div class="container-fluid py-3">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Property Management</h2>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPropertyModal">
              <i class="fas fa-plus me-1"></i> Add Property
            </button>
          </div>
          
          <div class="card hover-box">
            <div class="card-body">
              <?php
              $stmt = $pdo->prepare("SELECT * FROM properties WHERE landlord_id = ?");
              $stmt->execute([$landlordId]);
              $properties = $stmt->fetchAll();
              
              if (count($properties) > 0): ?>
                <div class="row g-4">
                  <?php foreach ($properties as $property): ?>
                    <div class="col-md-6 col-lg-4">
                      <div class="card h-100">
                        <div class="card-body">
                          <div class="d-flex justify-content-between align-items-start mb-3">
                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($property['name']); ?></h5>
                            <span class="badge bg-success"><?php echo $property['units']; ?> units</span>
                          </div>
                          <p class="text-muted mb-2">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            <?php echo htmlspecialchars($property['location']); ?>
                          </p>
                          <p class="card-text mb-3"><?php echo htmlspecialchars($property['description']); ?></p>
                          <div class="d-flex justify-content-between align-items-center">
                            <a href="#" class="btn btn-sm btn-outline-primary">View Details</a>
                            <small class="text-muted">
                              Added <?php echo date('M j, Y', strtotime($property['created_at'])); ?>
                            </small>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="text-center py-5">
                  <i class="fas fa-home fa-3x text-muted mb-3"></i>
                  <h4>No properties added yet</h4>
                  <p class="text-muted">Add your first property to get started</p>
                  <button class="btn btn-success mt-3" data-bs-toggle="modal" data-bs-target="#addPropertyModal">
                    <i class="fas fa-plus me-1"></i> Add Property
                  </button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Payments Section -->
      <div class="dashboard-section" id="payments-section">
        <div class="container-fluid py-3">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Payment Records</h2>
            <div class="btn-group">
              <button class="btn btn-outline-success">Export</button>
              <button class="btn btn-outline-success">Filter</button>
            </div>
          </div>
          
          <div class="card hover-box">
            <div class="card-body">
              <?php
              $stmt = $pdo->prepare("SELECT p.*, t.full_name as tenant_name, pr.name as property_name
                                    FROM payments p
                                    JOIN tenants t ON p.tenant_id = t.id
                                    JOIN properties pr ON t.property_id = pr.id
                                    WHERE p.landlord_id = ?
                                    ORDER BY p.payment_date DESC");
              $stmt->execute([$landlordId]);
              $payments = $stmt->fetchAll();
              
              if (count($payments) > 0): ?>
                <div class="table-responsive">
                  <table class="table table-hover">
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Tenant</th>
                        <th>Property</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($payments as $payment): ?>
                        <tr>
                          <td><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                          <td><?php echo htmlspecialchars($payment['tenant_name']); ?></td>
                          <td><?php echo htmlspecialchars($payment['property_name']); ?></td>
                          <td class="fw-bold">Ksh <?php echo number_format($payment['amount'], 2); ?></td>
                          <td><?php echo ucfirst($payment['payment_method']); ?></td>
                          <td>
                            <span class="badge bg-success">Completed</span>
                          </td>
                          <td>
                            <button class="btn btn-sm btn-outline-primary">Receipt</button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="text-center py-5">
                  <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                  <h4>No payment records</h4>
                  <p class="text-muted">Payment history will appear here</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Service Requests Section -->
      <div class="dashboard-section" id="requests-section">
        <div class="container-fluid py-3">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Service Requests</h2>
            <div class="btn-group">
              <button class="btn btn-outline-success">All</button>
              <button class="btn btn-outline-success">Pending</button>
              <button class="btn btn-outline-success">Resolved</button>
            </div>
          </div>
          
          <div class="card hover-box">
            <div class="card-body">
              <?php
              $stmt = $pdo->prepare("SELECT * FROM service_requests 
                                    WHERE landlord_id = ?
                                    ORDER BY created_at DESC");
              $stmt->execute([$landlordId]);
              $requests = $stmt->fetchAll();
              
              if (count($requests) > 0): ?>
                <div class="table-responsive">
                  <table class="table table-hover">
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Title</th>
                        <th>Property</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($requests as $request): ?>
                        <tr>
                          <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                          <td><?php echo htmlspecialchars($request['title']); ?></td>
                          <td><?php echo htmlspecialchars($request['property_name']); ?></td>
                          <td><?php echo htmlspecialchars(substr($request['description'], 0, 50)); ?>...</td>
                          <td>
                            <?php if ($request['status'] == 'pending'): ?>
                              <span class="badge badge-pending">Pending</span>
                            <?php else: ?>
                              <span class="badge bg-success">Completed</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <button class="btn btn-sm btn-outline-primary">View</button>
                            <button class="btn btn-sm btn-outline-success">Resolve</button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="text-center py-5">
                  <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                  <h4>No service requests</h4>
                  <p class="text-muted">Tenant requests will appear here</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Lease Agreements Section -->
      <div class="dashboard-section" id="leases-section">
        <div class="container-fluid py-3">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Lease Agreements</h2>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addLeaseModal">
              <i class="fas fa-plus me-1"></i> Create Lease
            </button>
          </div>
          
          <div class="card hover-box">
            <div class="card-body">
              <?php
              $stmt = $pdo->prepare("SELECT l.*, t.full_name as tenant_name, p.name as property_name
                                    FROM leases l
                                    JOIN tenants t ON l.tenant_id = t.id
                                    JOIN properties p ON l.property_id = p.id
                                    WHERE l.landlord_id = ?
                                    ORDER BY l.start_date DESC");
              $stmt->execute([$landlordId]);
              $leases = $stmt->fetchAll();
              
              if (count($leases) > 0): ?>
                <div class="table-responsive">
                  <table class="table table-hover">
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
                          <td><?php echo htmlspecialchars($lease['tenant_name']); ?></td>
                          <td><?php echo htmlspecialchars($lease['property_name']); ?></td>
                          <td><?php echo date('M j, Y', strtotime($lease['start_date'])); ?></td>
                          <td><?php echo date('M j, Y', strtotime($lease['end_date'])); ?></td>
                          <td>Ksh <?php echo number_format($lease['monthly_rent'], 2); ?></td>
                          <td>
                            <?php if (strtotime($lease['end_date']) > time()): ?>
                              <span class="badge bg-success">Active</span>
                            <?php else: ?>
                              <span class="badge bg-secondary">Expired</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <button class="btn btn-sm btn-outline-primary">View</button>
                            <button class="btn btn-sm btn-outline-secondary">Renew</button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="text-center py-5">
                  <i class="fas fa-file-contract fa-3x text-muted mb-3"></i>
                  <h4>No lease agreements</h4>
                  <p class="text-muted">Create your first lease agreement</p>
                  <button class="btn btn-success mt-3" data-bs-toggle="modal" data-bs-target="#addLeaseModal">
                    <i class="fas fa-plus me-1"></i> Create Lease
                  </button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Reports Section -->
      <div class="dashboard-section" id="reports-section">
        <div class="container-fluid py-3">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Reports & Analytics</h2>
            <div class="btn-group">
              <button class="btn btn-outline-success">Monthly</button>
              <button class="btn btn-outline-success">Quarterly</button>
              <button class="btn btn-outline-success">Annual</button>
            </div>
          </div>
          
          <div class="row g-4">
            <div class="col-lg-8">
              <div class="card hover-box h-100">
                <div class="card-header bg-white border-bottom">
                  <h5 class="mb-0">Income Report</h5>
                </div>
                <div class="card-body">
                  <div id="incomeChart" style="height: 300px;"></div>
                </div>
              </div>
            </div>
            <div class="col-lg-4">
              <div class="card hover-box h-100">
                <div class="card-header bg-white border-bottom">
                  <h5 class="mb-0">Occupancy Rate</h5>
                </div>
                <div class="card-body">
                  <div id="occupancyChart" style="height: 300px;"></div>
                </div>
              </div>
            </div>
          </div>
          
          <div class="row mt-4">
            <div class="col-md-6">
              <div class="card hover-box h-100">
                <div class="card-header bg-white border-bottom">
                  <h5 class="mb-0">Payment Methods</h5>
                </div>
                <div class="card-body">
                  <div id="paymentMethodsChart" style="height: 250px;"></div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card hover-box h-100">
                <div class="card-header bg-white border-bottom">
                  <h5 class="mb-0">Request Status</h5>
                </div>
                <div class="card-body">
                  <div id="requestStatusChart" style="height: 250px;"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Settings Section -->
      <div class="dashboard-section" id="settings-section">
        <div class="container-fluid py-3">
          <h2 class="mb-4">Account Settings</h2>
          
          <div class="row">
            <div class="col-lg-6">
              <div class="card hover-box mb-4">
                <div class="card-header bg-white border-bottom">
                  <h5 class="mb-0">Profile Information</h5>
                </div>
                <div class="card-body">
                  <form method="POST" action="api/update_profile.php">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="mb-3">
                      <label class="form-label">Full Name</label>
                      <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($landlord['full_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Email</label>
                      <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($landlord['email']); ?>" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Phone Number</label>
                      <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($landlord['phone']); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-success">Update Profile</button>
                  </form>
                </div>
              </div>
            </div>
            
            <div class="col-lg-6">
              <div class="card hover-box mb-4">
                <div class="card-header bg-white border-bottom">
                  <h5 class="mb-0">Security</h5>
                </div>
                <div class="card-body">
                  <form method="POST" action="api/update_password.php">
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
                    <button type="submit" class="btn btn-success">Change Password</button>
                  </form>
                </div>
              </div>
              
              <div class="card hover-box border-danger">
                <div class="card-header bg-white border-bottom border-danger">
                  <h5 class="mb-0 text-danger">Danger Zone</h5>
                </div>
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <h6 class="mb-1">Delete Account</h6>
                      <p class="small text-muted mb-0">Permanently remove your account and all data</p>
                    </div>
                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">Delete Account</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Profile Section -->
      <div class="dashboard-section" id="profile-section">
        <div class="container-fluid py-3">
          <div class="row">
            <div class="col-lg-4">
              <div class="card hover-box sticky-top" style="top: 80px;">
                <div class="card-body text-center">
                  <div class="avatar-lg mx-auto mb-3">
                    <div class="avatar-title bg-success bg-opacity-10 text-success rounded-circle display-5">
                      <i class="fas fa-user"></i>
                    </div>
                  </div>
                  <h4><?php echo htmlspecialchars($landlord['full_name']); ?></h4>
                  <p class="text-muted">Landlord</p>
                  <hr>
                  <div class="text-start">
                    <p><i class="fas fa-envelope me-2 text-muted"></i> <?php echo htmlspecialchars($landlord['email']); ?></p>
                    <p><i class="fas fa-phone me-2 text-muted"></i> <?php echo htmlspecialchars($landlord['phone']); ?></p>
                    <p><i class="fas fa-calendar-alt me-2 text-muted"></i> Joined <?php echo date('F Y', strtotime($landlord['created_at'])); ?></p>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-lg-8">
              <div class="card hover-box mb-4">
                <div class="card-header bg-white border-bottom">
                  <h5 class="mb-0">Activity</h5>
                </div>
                <div class="card-body">
                  <?php
                  $stmt = $pdo->prepare("SELECT * FROM landlord_activity 
                                        WHERE landlord_id = ? 
                                        ORDER BY activity_date DESC 
                                        LIMIT 10");
                  $stmt->execute([$landlordId]);
                  $activities = $stmt->fetchAll();
                  
                  if (count($activities) > 0): ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($activities as $activity): ?>
                        <div class="list-group-item border-0">
                          <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 me-3">
                              <div class="avatar-sm bg-success bg-opacity-10 text-success rounded-circle p-2">
                                <i class="fas <?php 
                                  switch($activity['activity_type']) {
                                    case 'property_added': echo 'fa-home'; break;
                                    case 'tenant_added': echo 'fa-user-plus'; break;
                                    case 'payment_received': echo 'fa-money-bill-wave'; break;
                                    default: echo 'fa-bell';
                                  }
                                ?>"></i>
                              </div>
                            </div>
                            <div class="flex-grow-1">
                              <p class="mb-0"><?php echo htmlspecialchars($activity['description']); ?></p>
                              <small class="text-muted"><?php echo date('M j, Y h:i A', strtotime($activity['activity_date'])); ?></small>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="text-center py-4">
                      <i class="fas fa-history fa-3x text-muted mb-3"></i>
                      <h5>No recent activity</h5>
                      <p class="text-muted">Your activity will appear here</p>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              
              <div class="card hover-box">
                <div class="card-header bg-white border-bottom">
                  <h5 class="mb-0">Connected Properties</h5>
                </div>
                <div class="card-body">
                  <?php
                  $stmt = $pdo->prepare("SELECT * FROM properties WHERE landlord_id = ? LIMIT 3");
                  $stmt->execute([$landlordId]);
                  $properties = $stmt->fetchAll();
                  
                  if (count($properties) > 0): ?>
                    <div class="row g-3">
                      <?php foreach ($properties as $property): ?>
                        <div class="col-md-6">
                          <div class="card border-0 shadow-sm">
                            <div class="card-body">
                              <h6 class="card-title"><?php echo htmlspecialchars($property['name']); ?></h6>
                              <p class="card-text text-muted small">
                                <i class="fas fa-map-marker-alt me-1"></i>
                                <?php echo htmlspecialchars($property['location']); ?>
                              </p>
                              <div class="d-flex justify-content-between align-items-center">
                                <span class="badge bg-success bg-opacity-10 text-success">
                                  <?php echo $property['units']; ?> units
                                </span>
                                <a href="#" data-section="properties" class="text-success small">View</a>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="text-center mt-3">
                      <a href="#" data-section="properties" class="btn btn-sm btn-outline-success">View All Properties</a>
                    </div>
                  <?php else: ?>
                    <div class="text-center py-4">
                      <i class="fas fa-home fa-3x text-muted mb-3"></i>
                      <h5>No properties connected</h5>
                      <p class="text-muted">Add properties to see them here</p>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Scroll Up Button -->
  <button class="scroll-up btn btn-success rounded-circle shadow" id="scrollTop">
    <i class="fas fa-arrow-up"></i>
  </button>

  <!-- Add Tenant Modal -->
  <div class="modal fade" id="addTenantModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add New Tenant</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="tenantForm" method="POST" action="">
          <input type="hidden" name="add_tenant" value="1">
          <div class="modal-body">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" required>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required>
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Phone Number</label>
                <input type="tel" name="phone" class="form-control" required>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">National ID</label>
                <input type="text" name="national_id" class="form-control" required>
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Property</label>
                <select class="form-select" name="property_id" required>
                  <option value="" selected disabled>Select property</option>
                  <?php
                  $stmt = $pdo->prepare("SELECT id, name FROM properties WHERE landlord_id = ?");
                  $stmt->execute([$landlordId]);
                  while ($property = $stmt->fetch()): ?>
                    <option value="<?php echo $property['id']; ?>"><?php echo htmlspecialchars($property['name']); ?></option>
                  <?php endwhile; ?>
                </select>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Unit Number</label>
                <input type="text" name="unit_number" class="form-control" required>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Move-in Date</label>
              <input type="date" name="move_in_date" class="form-control" required>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Add Tenant</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Add Property Modal -->
  <div class="modal fade" id="addPropertyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add New Property</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="propertyForm" method="POST" action="">
          <input type="hidden" name="add_property" value="1">
          <div class="modal-body">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Property Name</label>
                <input type="text" name="name" class="form-control" required>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Property Type</label>
                <select class="form-select" name="type" required>
                  <option value="" selected disabled>Select type</option>
                  <option value="apartment">Apartment</option>
                  <option value="house">House</option>
                  <option value="commercial">Commercial</option>
                  <option value="other">Other</option>
                </select>
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Location</label>
                <input type="text" name="location" class="form-control" required>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Total Units</label>
                <input type="number" name="units" class="form-control" required>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="description" rows="3"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Add Property</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Add Lease Modal -->
  <div class="modal fade" id="addLeaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Create Lease Agreement</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="leaseForm" method="POST" action="">
          <input type="hidden" name="add_lease" value="1">
          <div class="modal-body">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Tenant</label>
                <select class="form-select" name="tenant_id" required>
                  <option value="" selected disabled>Select tenant</option>
                  <?php
                  $stmt = $pdo->prepare("SELECT id, full_name FROM tenants WHERE landlord_id = ?");
                  $stmt->execute([$landlordId]);
                  while ($tenant = $stmt->fetch()): ?>
                    <option value="<?php echo $tenant['id']; ?>"><?php echo htmlspecialchars($tenant['full_name']); ?></option>
                  <?php endwhile; ?>
                </select>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Property/Unit</label>
                <select class="form-select" name="property_id" required>
                  <option value="" selected disabled>Select property/unit</option>
                  <?php
                  $stmt = $pdo->prepare("SELECT id, name FROM properties WHERE landlord_id = ?");
                  $stmt->execute([$landlordId]);
                  while ($property = $stmt->fetch()): ?>
                    <option value="<?php echo $property['id']; ?>"><?php echo htmlspecialchars($property['name']); ?></option>
                  <?php endwhile; ?>
                </select>
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" required>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" required>
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Monthly Rent (Ksh)</label>
                <input type="number" name="monthly_rent" class="form-control" required>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Deposit (Ksh)</label>
                <input type="number" name="deposit" class="form-control" required>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Terms and Conditions</label>
              <textarea class="form-control" name="terms" rows="5" required></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Create Lease</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete Account Modal -->
  <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title">Confirm Account Deletion</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>Are you sure you want to delete your account? This action cannot be undone. All your data will be permanently removed.</p>
          <p class="fw-bold">To confirm, please enter your password:</p>
          <form id="deleteAccountForm" method="POST" action="api/delete_account.php">
            <div class="mb-3">
              <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="deleteAccountForm" class="btn btn-danger">Delete Account</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.35.0/dist/apexcharts.min.js"></script>
  <script>
    // DOM Elements
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const mainContent = document.getElementById('mainContent');
    const navLinks = document.querySelectorAll('.sidebar .nav-link');
    const dashboardSections = document.querySelectorAll('.dashboard-section');
    
    // Initialize dashboard
    document.addEventListener('DOMContentLoaded', function() {
      // Sidebar toggle functionality
      sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('sidebar-collapsed');
        sidebarOverlay.style.display = sidebar.classList.contains('sidebar-collapsed') ? 'none' : 'block';
      });
      
      // Close sidebar when clicking outside (on mobile)
      sidebarOverlay.addEventListener('click', function() {
        sidebar.classList.add('sidebar-collapsed');
        sidebarOverlay.style.display = 'none';
      });
      
      // Navigation between sections
      navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
          e.preventDefault();
          
          // Update active nav item
          navLinks.forEach(navLink => navLink.classList.remove('active'));
          this.classList.add('active');
          
          // Show corresponding section
          const sectionId = this.getAttribute('data-section') + '-section';
          dashboardSections.forEach(section => {
            section.classList.remove('active');
            if(section.id === sectionId) {
              section.classList.add('active');
            }
          });
          
          // Close sidebar on mobile after selection
          if(window.innerWidth < 992) {
            sidebar.classList.add('sidebar-collapsed');
            sidebarOverlay.style.display = 'none';
          }
          
          // Scroll to top of section
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });
      });
      
      // Handle form submissions with fetch API
      document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', async function(e) {
          e.preventDefault();
          
          const formData = new FormData(this);
          const action = this.getAttribute('action');
          
          try {
            const response = await fetch(action, {
              method: 'POST',
              body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
              alert(result.message);
              // Refresh the page to show new data
              window.location.reload();
            } else {
              alert('Error: ' + result.message);
            }
          } catch (error) {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
          }
        });
      });
      
      // Scroll to Top
      const scrollBtn = document.getElementById("scrollTop");
      window.onscroll = () => {
        scrollBtn.style.display = window.scrollY > 300 ? "block" : "none";
      };
      scrollBtn.onclick = () => {
        window.scrollTo({ top: 0, behavior: "smooth" });
      };
      
      // Initialize sidebar as expanded on large screens
      if(window.innerWidth >= 992) {
        sidebar.classList.remove('sidebar-collapsed');
      }
      
      // Initialize charts if reports section exists
      if(document.getElementById('reports-section')) {
        initCharts();
      }
    });
    
    // Window resize handler
    window.addEventListener('resize', function() {
      if(window.innerWidth >= 992) {
        sidebarOverlay.style.display = 'none';
      } else if(!sidebar.classList.contains('sidebar-collapsed')) {
        sidebarOverlay.style.display = 'block';
      }
    });
    
    // Initialize charts
    function initCharts() {
      // Income Chart
      const incomeOptions = {
        series: [{
          name: 'Income',
          data: [100000, 110000, 95000, 120000, 115000, 125000, 130000]
        }],
        chart: {
          type: 'area',
          height: '100%',
          toolbar: { show: false }
        },
        colors: ['#198754'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        fill: {
          type: 'gradient',
          gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.7,
            opacityTo: 0.2,
          }
        },
        xaxis: {
          categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul']
        },
        tooltip: {
          y: {
            formatter: function (val) {
              return "Ksh " + val.toLocaleString()
            }
          }
        }
      };
      const incomeChart = new ApexCharts(document.querySelector("#incomeChart"), incomeOptions);
      incomeChart.render();
      
      // Occupancy Chart
      const occupancyOptions = {
        series: [85],
        chart: {
          height: '100%',
          type: 'radialBar',
        },
        plotOptions: {
          radialBar: {
            startAngle: -135,
            endAngle: 135,
            hollow: { margin: 0, size: '70%' },
            dataLabels: {
              name: { offsetY: -10, color: '#333', fontSize: '13px' },
              value: { 
                color: '#333',
                fontSize: '30px',
                formatter: function (val) { return val + '%'; }
              }
            },
            track: { background: '#e0e0e0', strokeWidth: '97%', margin: 5 }
          }
        },
        fill: {
          type: 'gradient',
          gradient: {
            shade: 'dark',
            shadeIntensity: 0.15,
            gradientToColors: ['#198754'],
            inverseColors: false,
            opacityFrom: 1,
            opacityTo: 1,
            stops: [0, 50, 65, 91]
          },
        },
        stroke: { dashArray: 4 },
        labels: ['Occupancy Rate'],
      };
      const occupancyChart = new ApexCharts(document.querySelector("#occupancyChart"), occupancyOptions);
      occupancyChart.render();
      
      // Payment Methods Chart
      const paymentMethodsOptions = {
        series: [65, 25, 10],
        chart: {
          type: 'donut',
          height: '100%'
        },
        labels: ['M-Pesa', 'Bank Transfer', 'Cash'],
        colors: ['#198754', '#0d6efd', '#6c757d'],
        legend: {
          position: 'bottom'
        },
        responsive: [{
          breakpoint: 480,
          options: {
            chart: { width: 200 },
            legend: { position: 'bottom' }
          }
        }]
      };
      const paymentMethodsChart = new ApexCharts(document.querySelector("#paymentMethodsChart"), paymentMethodsOptions);
      paymentMethodsChart.render();
      
      // Request Status Chart
      const requestStatusOptions = {
        series: [{
          name: 'Requests',
          data: [30, 40, 35, 50, 49, 60, 70]
        }],
        chart: {
          type: 'bar',
          height: '100%',
          toolbar: { show: false }
        },
        colors: ['#198754'],
        plotOptions: {
          bar: {
            borderRadius: 4,
            horizontal: false,
          }
        },
        dataLabels: { enabled: false },
        xaxis: {
          categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
        }
      };
      const requestStatusChart = new ApexCharts(document.querySelector("#requestStatusChart"), requestStatusOptions);
      requestStatusChart.render();
    }

    
async function requestPayment(tenantId, phone, amount) {
    try {
        const response = await fetch('/payments/initiate_payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                phone: phone,
                amount: amount,
                reference: `RENT_${tenantId}_${new Date().toISOString().slice(0,7)}`
            })
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            showAlert('success', 'Payment request sent! The tenant will receive an M-Pesa prompt.');
            refreshPaymentHistory(); // Refresh the transactions list
        } else {
            showAlert('danger', result.message);
        }
    } catch (error) {
        showAlert('danger', 'Network error: ' + error.message);
    }
}

// Example button click handler
document.querySelectorAll('.request-payment-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tenantId = this.dataset.tenantId;
        const phone = this.dataset.tenantPhone;
        const amount = prompt('Enter rent amount (KES):', '5000');
        
        if (amount && !isNaN(amount)) {
            requestPayment(tenantId, phone, parseFloat(amount));
        }
    });
});
  </script>
</body>
</html>