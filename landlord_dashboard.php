<?php
session_start();
if (!isset($_SESSION['landlord_name'])) {
    header("Location: login.php");
    exit();
}
$landlord_name = $_SESSION['landlord_name'];
$page = $_GET['page'] ?? 'dashboard_overview';
include $page . '.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Landlord Dashboard | KejaSmart</title>
  <link rel="icon" href="assets/img/favicon.png" type="image/png" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <style>
    body {
      background-color: #f8f9fa;
      font-family: 'Segoe UI', sans-serif;
    }

    /* Sidebar */
    #sidebar {
      position: fixed;
      top: 56px;
      left: 0;
      bottom: 0;
      width: 240px;
      background-color: #e9f7ef;
      padding: 20px;
      overflow-y: auto;
      transition: transform 0.3s ease;
      z-index: 1050;
    }

    #sidebar a {
      display: block;
      color: #198754;
      padding: 10px;
      border-radius: 5px;
      text-decoration: none;
    }

    #sidebar a:hover, #sidebar a.active {
      background-color: #c3f0d1;
      font-weight: bold;
    }

    /* Responsive Sidebar */
    @media (max-width: 768px) {
      #sidebar {
        transform: translateX(-100%);
      }

      #sidebar.show {
        transform: translateX(0);
      }

      #content {
        margin-left: 0 !important;
      }
    }

    /* Navbar */
    .navbar-custom {
      background-color: #fff;
      box-shadow: 0 2px 4px rgba(0,0,0,0.05);
      z-index: 1100;
    }

    .navbar-brand {
      font-weight: bold;
      color: #198754 !important;
    }
.nav-link {
  padding: 0.5rem 0.75rem;
}

.nav-icons {
  color: #198754;
}

    .nav-icons i {
      font-size: 1.2rem;
      margin: 0 8px;
      color: #198754;
    }

    .dropdown-menu-end {
      right: 0;
      left: auto;
    }

    #content {
      margin-left: 240px;
      margin-top: 56px;
      padding: 20px;
    }

    .stat-card {
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
      border: none;
    }

    .chart-placeholder {
      height: 250px;
      background-color: #eee;
      border: 2px dashed #ccc;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #888;
      font-style: italic;
    }

    @media (max-width: 768px) {
      .navbar-brand {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
      }

      .navbar-right {
        margin-left: auto;
      }
    }

    @media (min-width: 769px) {
      .navbar-brand {
        position: relative;
        left: 0;
        transform: none;
      }

      .navbar-right {
        margin-left: auto;
        display: flex;
        align-items: center;
      }
    }
  </style>
</head>
<body>

<!-- Top Navbar -->
<nav class="navbar navbar-expand-lg navbar-custom fixed-top">
  <div class="container-fluid">
    <!-- Sidebar toggle (mobile only) -->
    <button class="btn d-lg-none" onclick="toggleSidebar()">
      <i class="fas fa-bars text-success"></i>
    </button>

    <!-- Brand -->
    <a class="navbar-brand" href="index.html">KejaSmart</a>

    <!-- Right-side icons -->
    <div class="navbar-right d-flex align-items-center ms-auto">
      <!-- Desktop icons -->
      <div class="d-none d-lg-flex align-items-center gap-3">
  <a class="nav-link" href="#" title="Notifications">
    <i class="fas fa-bell text-success fs-5"></i>
  </a>
  <a class="nav-link" href="#" title="Messages">
    <i class="fas fa-envelope text-success fs-5"></i>
  </a>
  <div class="dropdown">
    <a class="nav-link dropdown-toggle text-dark" href="#" data-bs-toggle="dropdown" style="min-width: 120px;">
      <?= $landlordName ?>
    </a>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item" href="#">Profile</a></li>
      <li><a class="dropdown-item" href="#">Settings</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
    </ul>
  </div>
</div>


      <!-- Mobile dropdown -->
      <div class="dropdown d-lg-none">
        <a class="btn text-success dropdown-toggle" href="#" data-bs-toggle="dropdown">
          <i class="fas fa-user-circle"></i>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="#"><i class="fas fa-bell me-2"></i>Notifications</a></li>
          <li><a class="dropdown-item" href="#"><i class="fas fa-envelope me-2"></i>Messages</a></li>
          <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
          <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Settings</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<!-- Sidebar -->
<div id="sidebar">
  <p class="fw-bold">Dashboard</p>
  <a href="?page=dashboard_overview" class="<?= $page == 'dashboard' ? 'active' : '' ?>">Overview</a>
  <a href="?page=tenants" class="<?= $page == 'tenants' ? 'active' : '' ?>">Tenants</a>
  <a href="?page=properties" class="<?= $page == 'properties' ? 'active' : '' ?>">Properties & Units</a>
  <a href="?page=payments" class="<?= $page == 'payments' ? 'active' : '' ?>">Payments</a>
  <a href="?page=requests" class="<?= $page == 'requests' ? 'active' : '' ?>">Service Requests</a>
  <a href="?page=leases" class="<?= $page == 'leases' ? 'active' : '' ?>">Lease Agreements</a>
  <a href="?page=reports" class="<?= $page == 'reports' ? 'active' : '' ?>">Reports & Analytics</a>
  <a href="?page=settings" class="<?= $page == 'settings' ? 'active' : '' ?>">Settings</a>
  <a href="index.html" class="text-danger mt-3 d-block">Logout</a>
</div>

<!-- Main Content -->
<div id="content">
  <?php
    switch ($page) {
      case 'tenants': include 'tenants_content.php'; break;
      case 'properties': include 'properties_content.php'; break;
      case 'payments': include 'payments_content.php'; break;
      case 'requests': include 'requests_content.php'; break;
      case 'leases': include 'leases_content.php'; break;
      case 'reports': include 'reports_content.php'; break;
      case 'settings': include 'settings_content.php'; break;
      default: include 'dashboard_overview.php'; break;
    }
  ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const sidebar = document.getElementById('sidebar');
  function toggleSidebar() {
    sidebar.classList.toggle('show');
  }

  document.addEventListener('click', function (e) {
    if (window.innerWidth <= 768 && sidebar.classList.contains('show')) {
      if (!sidebar.contains(e.target) && !e.target.closest('button')) {
        sidebar.classList.remove('show');
      }
    }
  });
</script>
</body>
</html>