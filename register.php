<?php
require_once 'config.php';

$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $userType = $_POST['user_type'];

    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];

    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address.";
    }

    if ($userType == "landlord") {
        // Check for duplicate landlord email
        $stmt = $conn->prepare("SELECT id FROM landlords WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Email already exists for a landlord.";
        }
        $stmt->close();
    }

    if ($userType == "tenant") {
        $idNumber = trim($_POST['id_number']);
        // Check for duplicate tenant email
        $stmt = $conn->prepare("SELECT id FROM tenants WHERE email = ? OR id_number = ?");
        $stmt->bind_param("ss", $email, $idNumber);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Email or ID number already exists for a tenant.";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        if ($userType == "landlord") {
            $phone = trim($_POST['phone']);
            $country = trim($_POST['country']);
            $addPropertyNow = isset($_POST['add_property']) ? $_POST['add_property'] : 'no';

            $stmt = $conn->prepare("INSERT INTO landlords (first_name, last_name, email, phone, country, password, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->bind_param("ssssss", $firstName, $lastName, $email, $phone, $country, $hashedPassword);
            $stmt->execute();
            $landlord_id = $stmt->insert_id;
            $stmt->close();

            if ($addPropertyNow == "yes" && isset($_POST['property_name'])) {
                $propertyNames = $_POST['property_name'];
                $locations = $_POST['property_location'];
                $units = $_POST['property_units'];

                for ($i = 0; $i < count($propertyNames); $i++) {
                    $stmt = $conn->prepare("INSERT INTO properties (landlord_id, property_name, location, number_of_units) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("issi", $landlord_id, $propertyNames[$i], $locations[$i], $units[$i]);
                    $stmt->execute();
                }
            }

            header("Location: login.php?type=landlord");
            exit();
        }

        if ($userType == "tenant") {
            $apartment = trim($_POST['apartment_name']);
            $unit = trim($_POST['unit_name']);

            $stmt = $conn->prepare("INSERT INTO tenants (first_name, last_name, id_number, email, apartment_name, unit_name, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssissss", $firstName, $lastName, $idNumber, $email, $apartment, $unit, $hashedPassword);
            $stmt->execute();
            $stmt->close();

            header("Location: login.php?type=tenant");
            exit();
        }
    }
}
?>

<!-- Begin HTML for Registration Page -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Register | KejaSmart</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { background-color: #f4f4f4; }
    .container { margin-top: 60px; }
    .form-section {
      background-color: #fff;
      border-radius: 8px;
      padding: 25px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }
    .btn-theme {
      background-color: #28a745;
      color: white;
    }
    .btn-theme:hover {
      background-color: #218838;
    }
    .property-group {
      border: 1px dashed #ccc;
      padding: 10px;
      margin-bottom: 10px;
      border-radius: 5px;
    }
  </style>
</head>
<body>

<div class="container">
  <h2 class="text-center mb-4">Register to KejaSmart</h2>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e) echo "<li>$e</li>"; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form action="register.php" method="POST">
    <div class="row">
      <!-- Landlord Form -->
      <div class="col-md-6">
        <div class="form-section">
          <h4>Landlord Registration</h4>
          <input type="hidden" name="user_type" value="landlord">
          <div class="mb-3"><input type="text" class="form-control" name="first_name" placeholder="First Name" required></div>
          <div class="mb-3"><input type="text" class="form-control" name="last_name" placeholder="Last Name" required></div>
          <div class="mb-3"><input type="email" class="form-control" name="email" placeholder="Email" required></div>
          <div class="mb-3"><input type="tel" class="form-control" name="phone" placeholder="Phone Number"></div>
          <div class="mb-3"><input type="text" class="form-control" name="country" placeholder="Country"></div>

          <label class="form-label">Add Property Now?</label>
          <div class="mb-3">
            <label><input type="radio" name="add_property" value="yes"> Yes</label>
            <label class="ms-3"><input type="radio" name="add_property" value="no" checked> No</label>
          </div>

          <div id="propertyFields" style="display: none;">
            <div id="propertyRepeater">
              <div class="property-group">
                <input type="text" name="property_name[]" class="form-control mb-2" placeholder="Property Name">
                <input type="text" name="property_location[]" class="form-control mb-2" placeholder="Location">
                <input type="number" name="property_units[]" class="form-control" placeholder="Number of Units">
              </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addPropertyField()">+ Add Another Property</button>
          </div>

          <div class="mb-3"><input type="password" class="form-control" name="password" placeholder="Password" required></div>
          <div class="mb-3"><input type="password" class="form-control" name="confirm_password" placeholder="Confirm Password" required></div>
        </div>
      </div>

      <!-- Tenant Form -->
      <div class="col-md-6">
        <div class="form-section">
          <h4>Tenant Registration</h4>
          <input type="hidden" name="user_type" value="tenant">
          <div class="mb-3"><input type="text" class="form-control" name="first_name" placeholder="First Name" required></div>
          <div class="mb-3"><input type="text" class="form-control" name="last_name" placeholder="Last Name" required></div>
          <div class="mb-3"><input type="text" class="form-control" name="id_number" placeholder="National ID" required></div>
          <div class="mb-3"><input type="email" class="form-control" name="email" placeholder="Email" required></div>
          <div class="mb-3"><input type="text" class="form-control" name="apartment_name" placeholder="Apartment Name" required></div>
          <div class="mb-3"><input type="text" class="form-control" name="unit_name" placeholder="Unit Name" required></div>
          <div class="mb-3"><input type="password" class="form-control" name="password" placeholder="Password" required></div>
          <div class="mb-3"><input type="password" class="form-control" name="confirm_password" placeholder="Confirm Password" required></div>
        </div>
      </div>
    </div>

    <div class="text-center mt-3">
      <button type="submit" class="btn btn-theme px-5">Register</button>
    </div>
  </form>
</div>

<script>
  document.querySelectorAll('input[name="add_property"]').forEach(input => {
    input.addEventListener('change', function () {
      document.getElementById('propertyFields').style.display = this.value === 'yes' ? 'block' : 'none';
    });
  });

  function addPropertyField() {
    const repeater = document.getElementById('propertyRepeater');
    const div = document.createElement('div');
    div.className = 'property-group';
    div.innerHTML = `
      <input type="text" name="property_name[]" class="form-control mb-2" placeholder="Property Name">
      <input type="text" name="property_location[]" class="form-control mb-2" placeholder="Location">
      <input type="number" name="property_units[]" class="form-control" placeholder="Number of Units">
    `;
    repeater.appendChild(div);
  }
</script>

</body>
</html>
