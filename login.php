<?php
// Secure session settings
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com");

require_once 'config.php';

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// Check for "Remember me"
if (isset($_COOKIE['remember_email'])) {
    $rememberedEmail = $_COOKIE['remember_email'];
} else {
    $rememberedEmail = '';
}

// Rate-limiting: check failed attempts (both by email and IP)
function isLockedOut($conn, $email, $ip) {
    // Check by email
    $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip_address = ?) AND success = 0 AND attempt_time > (NOW() - INTERVAL 15 MINUTE)");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $stmt->bind_result($attempts);
    $stmt->fetch();
    $stmt->close();
    
    if ($attempts >= 5) {
        return true;
    }
    
    // Progressive delay for 2-4 failed attempts
    if ($attempts >= 2) {
        $delay = min(($attempts - 1) * 5, 15); // 5, 10, 15 seconds
        sleep($delay);
    }
    
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid request. Please try again.";
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']);
        $ip_address = $_SERVER['REMOTE_ADDR'];

        if (isLockedOut($conn, $email, $ip_address)) {
            $errors[] = "Too many failed attempts. Please try again after 15 minutes.";
        } else {
            // Check in all user tables (admin, landlord, tenant)
            $validUserTypes = [
                ['table' => 'admins', 'redirect' => 'admin_dashboard.php'],
                ['table' => 'landlords', 'redirect' => 'landlord_dashboard.php'],
                ['table' => 'tenants', 'redirect' => 'tenant_dashboard.php']
            ];

            $loginSuccess = false;
            $genericError = "Invalid email or password."; // Generic error message

            foreach ($validUserTypes as $userType) {
                // Validate table name against whitelist
                if (!in_array($userType['table'], ['admins', 'landlords', 'tenants'])) {
                    continue;
                }

                $stmt = $conn->prepare("SELECT id, password, status FROM " . $userType['table'] . " WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 1) {
                    $stmt->bind_result($id, $hashed_password, $status);
                    $stmt->fetch();

                    if ($userType['table'] === 'landlords' && $status !== 'approved') {
                        $errors[] = "Landlord account pending approval.";
                    } elseif (password_verify($password, $hashed_password)) {
                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        // Success
                        $_SESSION['user_id'] = $id;
                        $_SESSION['user_type'] = $userType['table'];
                        $_SESSION['email'] = $email;
                        $_SESSION['last_activity'] = time();

                        if ($remember) {
                            setcookie("remember_email", $email, [
                                'expires' => time() + (86400 * 30),
                                'path' => '/',
                                'secure' => true,
                                'httponly' => true,
                                'samesite' => 'Strict'
                            ]);
                        } else {
                            setcookie("remember_email", "", [
                                'expires' => time() - 3600,
                                'path' => '/'
                            ]);
                        }

                        // Log success with IP
                        $logStmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 1)");
                        $logStmt->bind_param("ss", $email, $ip_address);
                        $logStmt->execute();
                        $logStmt->close();

                        header("Location: " . $userType['redirect']);
                        exit();
                    }
                }
                $stmt->close();
            }

            if (empty($errors)) {
                // Log failed attempt with IP
                $logStmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 0)");
                $logStmt->bind_param("ss", $email, $ip_address);
                $logStmt->execute();
                $logStmt->close();
                
                // Use generic error message to avoid revealing if email exists
                $errors[] = $genericError;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login | KejaSmart</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body {
      margin: 0;
      padding: 0;
      min-height: 100vh;
      background: url('images/heroimage.jpg') no-repeat center center fixed;
      background-size: cover;
      font-family: 'Segoe UI', sans-serif;
    }
    .overlay {
      background-color: rgba(255, 255, 255, 0.92);
      min-height: 100vh;
      padding-bottom: 50px;
    }
    .login-container {
      max-width: 450px;
      margin: 100px auto;
      padding: 30px;
      background-color: #ffffff;
      border-radius: 12px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
    }
    .btn-theme {
      background-color: lightgreen;
      color: black;
    }
    .btn-theme:hover {
      background-color: green;
      color: white;
    }
    footer {
      background-color: #111;
      color: white;
      padding: 20px 0;
      margin-top: 100px;
    }
    footer a {
      color: lightgreen;
      text-decoration: none;
    }
    footer a:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>

<div class="overlay">

  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg navbar-light bg-light sticky-top">
    <div class="container">
      <a class="navbar-brand fw-bold text-success" href="index.html">KejaSmart</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <div class="ms-auto d-flex align-items-center">
          <ul class="navbar-nav me-3 mb-2 mb-lg-0">
            <li class="nav-item"><a class="nav-link" href="index.html">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="index.html#features">Features</a></li>
            <li class="nav-item"><a class="nav-link" href="index.html#how-it-works">How It Works</a></li>
            <li class="nav-item"><a class="nav-link" href="index.html#faqs">FAQs</a></li>
            <li class="nav-item"><a class="nav-link" href="index.html#contact">Contact</a></li>
          </ul>
          <div class="d-flex">
            <a href="login.php" class="btn btn-outline-success me-2">Login</a>
            <a href="register.php" class="btn btn-success">Sign Up</a>
          </div>
        </div>
      </div>
    </div>
  </nav>

  <!-- Login Form -->
  <div class="container">
    <div class="login-container">
      <h4 class="text-center mb-4 text-success">Login to KejaSmart</h4>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
          <?php foreach ($errors as $error) echo "<div>$error</div>"; ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <div class="mb-3">
          <label for="email" class="form-label">Email address</label>
          <input type="email" class="form-control" name="email" id="email" value="<?= htmlspecialchars($rememberedEmail) ?>" required />
        </div>
        <div class="mb-3">
          <label for="password" class="form-label">Password</label>
          <input type="password" class="form-control" name="password" id="password" required minlength="8" />
          <div class="form-text">Minimum 8 characters</div>
        </div>
        <div class="form-check mb-4">
          <input type="checkbox" class="form-check-input" name="remember" id="remember" <?= $rememberedEmail ? 'checked' : '' ?>>
          <label class="form-check-label" for="remember">Remember me</label>
        </div>
        <div class="d-grid">
          <button type="submit" class="btn btn-theme">Login</button>
        </div>
        <div class="text-center mt-3">
          <small>Don't have an account? <a href="register.php">Register here</a></small><br>
          <small><a href="forgot_password.php">Forgot password?</a></small>
        </div>
      </form>
    </div>
  </div>

  <!-- Footer -->
  <footer class="text-center mt-5">
    <div class="container">
      <p class="mb-1">
        <a href="#">About</a> |
        <a href="#">Privacy Policy</a> |
        <a href="#">Terms</a>
      </p>
      <p class="mb-1">Contact: info@kejasmart.com | +254 700 000 000</p>
      <div>
        <a href="#" class="me-2"><i class="fab fa-facebook-f"></i></a>
        <a href="#" class="me-2"><i class="fab fa-twitter"></i></a>
        <a href="#"><i class="fab fa-instagram"></i></a>
      </div>
      <p class="mt-2 mb-0">&copy; 2025 KejaSmart. All rights reserved.</p>
    </div>
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>