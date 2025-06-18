<?php
require_once 'config.php';
require_once 'functions.php'; // For utility functions

$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Start transaction for atomic operations
    $conn->begin_transaction();
    
    try {
        $userType = $_POST['user_type'];
        $firstName = sanitizeInput($_POST['first_name']);
        $lastName = sanitizeInput($_POST['last_name']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'];
        $phone = sanitizeInput($_POST['phone']);

        // Validate common fields
        if (empty($firstName) || empty($lastName)) {
            throw new Exception("First and last name are required.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address.");
        }

        if (strlen($password) < 8) {
            throw new Exception("Password must be at least 8 characters.");
        }

        if ($password !== $confirmPassword) {
            throw new Exception("Passwords do not match.");
        }

        if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
            throw new Exception("Invalid phone number format.");
        }

        // Check if email exists in users table
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("Email already registered.");
        }
        $stmt->close();

        // Generate verification token
        $verificationToken = bin2hex(random_bytes(16));
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Create base user record
        $stmt = $conn->prepare("INSERT INTO users (email, password, user_type, verification_token) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $email, $hashedPassword, $userType, $verificationToken);
        $stmt->execute();
        $userId = $stmt->insert_id;
        $stmt->close();

        if ($userType == "landlord") {
            // Landlord-specific registration
            $country = sanitizeInput($_POST['country']);
            
            // Fix: Proper checkbox handling
            $addPropertyNow = isset($_POST['add_property']) && $_POST['add_property'] == 'yes';

            $stmt = $conn->prepare("INSERT INTO landlords 
                (id, first_name, last_name, phone, country, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->bind_param("issss", $userId, $firstName, $lastName, $phone, $country);
            $stmt->execute();
            $stmt->close();

            // Handle property registration if requested and fields exist
            if ($addPropertyNow && isset($_POST['property_name']) && is_array($_POST['property_name'])) {
                foreach ($_POST['property_name'] as $index => $name) {
                    if (!empty($name) && !empty($_POST['property_location'][$index])) {
                        $location = sanitizeInput($_POST['property_location'][$index]);
                        $units = (int)($_POST['property_units'][$index] ?? 0);
                        
                        $stmt = $conn->prepare("INSERT INTO properties 
                            (landlord_id, property_name, location, number_of_units) 
                            VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("issi", $userId, 
                            sanitizeInput($name), 
                            $location, 
                            $units);
                        $stmt->execute();
                        $propertyId = $stmt->insert_id;
                        $stmt->close();
                    }
                }
            }

            // Send approval notification to admin
            notifyAdmin("New landlord registration requires approval: $email");

        } elseif ($userType == "tenant") {
            // Tenant-specific registration
            $idNumber = sanitizeInput($_POST['id_number']);
            $emergencyContact = sanitizeInput($_POST['emergency_contact']);
            $apartmentName = sanitizeInput($_POST['apartment_name']);
            $unitName = sanitizeInput($_POST['unit_name']);

            // Check for duplicate ID number
            $stmt = $conn->prepare("SELECT id FROM tenants WHERE id_number = ?");
            $stmt->bind_param("s", $idNumber);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                throw new Exception("ID number already registered.");
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO tenants 
                (id, first_name, last_name, id_number, phone, emergency_contact) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $userId, $firstName, $lastName, $idNumber, $phone, $emergencyContact);
            $stmt->execute();
            $stmt->close();

            // Find or create unit
            $unitId = findOrCreateUnit($apartmentName, $unitName, $conn);
            
            // Create lease agreement
            $leaseStart = date('Y-m-d');
            $leaseEnd = date('Y-m-d', strtotime('+1 year'));
            $stmt = $conn->prepare("INSERT INTO tenant_units 
                (tenant_id, unit_id, lease_start, lease_end, monthly_rent, status) 
                VALUES (?, ?, ?, ?, ?, 'active')");
            $defaultRent = 15000; // Set your default rent amount
            $stmt->bind_param("iissd", $userId, $unitId, $leaseStart, $leaseEnd, $defaultRent);
            $stmt->execute();
            $stmt->close();
        }

        // Commit transaction if all operations succeeded
        $conn->commit();

        // Send verification email
        sendVerificationEmail($email, $verificationToken);

        // Redirect with success
        $_SESSION['registration_success'] = true;
        header("Location: login.php?type=$userType");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
    }
}

// Helper function to find or create unit
function findOrCreateUnit($propertyName, $unitName, $conn) {
    // Try to find existing property
    $stmt = $conn->prepare("SELECT id FROM properties WHERE property_name = ?");
    $stmt->bind_param("s", $propertyName);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $propertyId = null;
    if ($result->num_rows > 0) {
        $propertyId = $result->fetch_assoc()['id'];
    } else {
        // Create new property if not found
        $stmt = $conn->prepare("INSERT INTO properties (property_name, location) VALUES (?, 'Location not specified')");
        $stmt->bind_param("s", $propertyName);
        $stmt->execute();
        $propertyId = $stmt->insert_id;
    }
    $stmt->close();
    
    // Try to find existing unit
    $stmt = $conn->prepare("SELECT id FROM units WHERE property_id = ? AND unit_name = ?");
    $stmt->bind_param("is", $propertyId, $unitName);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $unitId = $result->fetch_assoc()['id'];
        $stmt->close();
        return $unitId;
    }
    
    // Create new unit if not found
    $stmt = $conn->prepare("INSERT INTO units (property_id, unit_name) VALUES (?, ?)");
    $stmt->bind_param("is", $propertyId, $unitName);
    $stmt->execute();
    $unitId = $stmt->insert_id;
    $stmt->close();
    
    return $unitId;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register | KejaSmart</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .registration-container { max-width: 1000px; }
    .form-section { 
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      padding: 25px;
      margin-bottom: 20px;
    }
    .nav-tabs .nav-link {
      color: #495057;
      font-weight: 500;
    }
    .nav-tabs .nav-link.active {
      color: #28a745;
      font-weight: 600;
      border-bottom: 3px solid #28a745;
      background: transparent;
    }
    .property-group {
      border: 1px dashed #dee2e6;
      border-radius: 5px;
      padding: 15px;
      margin-bottom: 15px;
    }
    .form-required:after {
      content: " *";
      color: #dc3545;
    }
  </style>
</head>
<body>
<div class="container py-5 registration-container">
  <div class="text-center mb-5">
    <h2><i class="fas fa-home text-success me-2"></i>Create Your KejaSmart Account</h2>
    <p class="text-muted">Join as a landlord or tenant to get started</p>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      <h5 class="alert-heading">Registration Errors</h5>
      <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <ul class="nav nav-tabs nav-justified mb-4" id="registerTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="landlord-tab" data-bs-toggle="tab" data-bs-target="#landlord" type="button">
        <i class="fas fa-user-tie me-2"></i>Landlord Registration
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tenant-tab" data-bs-toggle="tab" data-bs-target="#tenant" type="button">
        <i class="fas fa-user me-2"></i>Tenant Registration
      </button>
    </li>
  </ul>

  <form action="register.php" method="POST" id="registrationForm">
    <div class="tab-content">
      <!-- Landlord Registration -->
      <div class="tab-pane fade show active" id="landlord" role="tabpanel">
        <input type="hidden" name="user_type" value="landlord">
        
        <div class="form-section">
          <h4 class="mb-4"><i class="fas fa-info-circle text-success me-2"></i>Basic Information</h4>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label form-required">First Name</label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">Last Name</label>
              <input type="text" name="last_name" class="form-control" required>
            </div>
            
            <div class="col-md-6">
              <label class="form-label form-required">Email</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">Phone Number</label>
              <input type="tel" name="phone" class="form-control" required>
              <small class="text-muted">Format: 07XXXXXXXX or 2547XXXXXXXX</small>
            </div>
            
            <div class="col-12">
              <label class="form-label form-required">Country</label>
              <select name="country" class="form-select" required>
                <option value="">Select Country</option>
                <option value="Kenya" selected>Kenya</option>
                <option value="Uganda">Uganda</option>
                <option value="Tanzania">Tanzania</option>
                <option value="Rwanda">Rwanda</option>
              </select>
            </div>
          </div>
        </div>

        <div class="form-section">
          <h4 class="mb-4"><i class="fas fa-home text-success me-2"></i>Property Information</h4>
          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" name="add_property" id="addPropertyCheck" value="yes">
            <label class="form-check-label" for="addPropertyCheck">
              <strong>Add property now</strong> (you can add more later)
            </label>
          </div>
          
          <div id="propertyFields" style="display: none;">
            <div id="propertyContainer">
              <div class="property-group">
                <div class="row g-3">
                  <div class="col-md-5">
                    <label class="form-label">Property Name</label>
                    <input type="text" name="property_name[]" class="form-control" placeholder="e.g. Greenview Apartments">
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Location</label>
                    <input type="text" name="property_location[]" class="form-control" placeholder="e.g. Kilimani, Nairobi">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Units</label>
                    <input type="number" name="property_units[]" class="form-control" placeholder="0" min="0">
                  </div>
                </div>
              </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-success mt-2" id="addPropertyBtn">
              <i class="fas fa-plus me-1"></i>Add Another Property
            </button>
          </div>
        </div>
      </div>

      <!-- Tenant Registration -->
      <div class="tab-pane fade" id="tenant" role="tabpanel">
        <input type="hidden" name="user_type" value="tenant">
        
        <div class="form-section">
          <h4 class="mb-4"><i class="fas fa-info-circle text-success me-2"></i>Personal Information</h4>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label form-required">First Name</label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">Last Name</label>
              <input type="text" name="last_name" class="form-control" required>
            </div>
            
            <div class="col-md-6">
              <label class="form-label form-required">National ID</label>
              <input type="text" name="id_number" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">Email</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            
            <div class="col-md-6">
              <label class="form-label form-required">Phone Number</label>
              <input type="tel" name="phone" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">Emergency Contact</label>
              <input type="tel" name="emergency_contact" class="form-control" required>
              <small class="text-muted">Someone we can contact in case of emergency</small>
            </div>
          </div>
        </div>

        <div class="form-section">
          <h4 class="mb-4"><i class="fas fa-home text-success me-2"></i>Rental Information</h4>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label form-required">Apartment Name</label>
              <input type="text" name="apartment_name" class="form-control" required placeholder="e.g. Greenview Apartments">
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">Unit Name/Number</label>
              <input type="text" name="unit_name" class="form-control" required placeholder="e.g. A1 or 12B">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Common Security Section -->
    <div class="form-section">
      <h4 class="mb-4"><i class="fas fa-lock text-success me-2"></i>Account Security</h4>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label form-required">Password</label>
          <input type="password" name="password" class="form-control" required minlength="8">
          <div class="form-text">Minimum 8 characters</div>
        </div>
        <div class="col-md-6">
          <label class="form-label form-required">Confirm Password</label>
          <input type="password" name="confirm_password" class="form-control" required>
        </div>
      </div>
      
      <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" required id="termsCheck">
        <label class="form-check-label" for="termsCheck">
          I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms of Service</a> 
          and <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>
        </label>
      </div>
    </div>

    <div class="d-grid mt-4">
      <button type="submit" class="btn btn-success btn-lg py-3">
        <i class="fas fa-user-plus me-2"></i>Create Account
      </button>
    </div>
  </form>
</div>

<!-- Terms and Privacy Modals -->
<div class="modal fade" id="termsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Terms of Service</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Your terms of service content here -->
        <p>This is where your terms of service would appear...</p>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="privacyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Privacy Policy</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Your privacy policy content here -->
        <p>This is where your privacy policy would appear...</p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Property field toggle
  document.getElementById('addPropertyCheck').addEventListener('change', function() {
    document.getElementById('propertyFields').style.display = this.checked ? 'block' : 'none';
  });

  // Add property field
  document.getElementById('addPropertyBtn').addEventListener('click', function() {
    const container = document.getElementById('propertyContainer');
    const newGroup = document.createElement('div');
    newGroup.className = 'property-group mt-3';
    newGroup.innerHTML = `
      <div class="row g-3">
        <div class="col-md-5">
          <label class="form-label">Property Name</label>
          <input type="text" name="property_name[]" class="form-control">
        </div>
        <div class="col-md-5">
          <label class="form-label">Location</label>
          <input type="text" name="property_location[]" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Units</label>
          <input type="number" name="property_units[]" class="form-control" min="0">
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-outline-danger mt-2 remove-property">
        <i class="fas fa-trash me-1"></i>Remove Property
      </button>
    `;
    container.appendChild(newGroup);
    
    // Add remove functionality
    newGroup.querySelector('.remove-property').addEventListener('click', function() {
      container.removeChild(newGroup);
    });
  });

  // Tab switching - update hidden user_type field
  document.querySelectorAll('#registerTabs .nav-link').forEach(tab => {
    tab.addEventListener('click', function() {
      document.querySelector('input[name="user_type"]').value = 
        this.id.includes('landlord') ? 'landlord' : 'tenant';
    });
  });

  // Form validation
  document.getElementById('registrationForm').addEventListener('submit', function(e) {
    const password = document.querySelector('input[name="password"]');
    const confirmPassword = document.querySelector('input[name="confirm_password"]');
    
    if (password.value !== confirmPassword.value) {
      e.preventDefault();
      alert('Passwords do not match!');
      confirmPassword.focus();
    }
    
    if (!document.getElementById('termsCheck').checked) {
      e.preventDefault();
      alert('You must agree to the terms and conditions');
    }
  });
</script>
</body>
</html>