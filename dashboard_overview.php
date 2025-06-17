<?php
session_start();
require_once 'db_connect.php'; // Ensure you have DB connection here

// Sample session check
if (!isset($_SESSION['landlord_id'])) {
  header('Location: login.php');
  exit();
}

$landlord_id = $_SESSION['landlord_id'];

// Fetch dashboard values
$totalCollected = 0;
$totalProperties = 0;
$vacantUnits = 0;
$activeTenants = 0;
$rentDueThisWeek = 0;
$monthlyIncome = [];
$occupancyRates = [];

$query1 = $conn->prepare("SELECT SUM(amount) as total FROM payments WHERE landlord_id = ? AND MONTH(date) = MONTH(CURDATE())");
$query1->execute([$landlord_id]);
$totalCollected = $query1->fetch()['total'] ?? 0;

$query2 = $conn->prepare("SELECT COUNT(*) FROM properties WHERE landlord_id = ?");
$query2->execute([$landlord_id]);
$totalProperties = $query2->fetchColumn();

$query3 = $conn->prepare("SELECT COUNT(*) FROM units WHERE landlord_id = ? AND is_occupied = 0");
$query3->execute([$landlord_id]);
$vacantUnits = $query3->fetchColumn();

$query4 = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE landlord_id = ?");
$query4->execute([$landlord_id]);
$activeTenants = $query4->fetchColumn();

$query5 = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE landlord_id = ? AND WEEK(due_date) = WEEK(CURDATE())");
$query5->execute([$landlord_id]);
$rentDueThisWeek = $query5->fetchColumn();

// Fetch Monthly Income for Chart
for ($i = 1; $i <= 12; $i++) {
    $stmt = $conn->prepare("SELECT SUM(amount) as monthly FROM payments WHERE landlord_id = ? AND MONTH(date) = ?");
    $stmt->execute([$landlord_id, $i]);
    $monthlyIncome[] = (float)($stmt->fetch()['monthly'] ?? 0);
}

// Occupancy rate per property (Pie Chart)
$stmt = $conn->prepare("SELECT p.name, COUNT(u.unit_id) as total, SUM(u.is_occupied) as occupied FROM properties p
  LEFT JOIN units u ON p.property_id = u.property_id WHERE p.landlord_id = ? GROUP BY p.property_id");
$stmt->execute([$landlord_id]);
while ($row = $stmt->fetch()) {
  $occupancyRates[] = [
    'label' => $row['name'],
    'value' => ($row['total'] > 0) ? round(($row['occupied'] / $row['total']) * 100) : 0
  ];
}

?>

<?php include 'layout_topbar_sidebar.php'; ?>

<div id="content" class="pt-4">
  <!-- Stat Cards -->
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card stat-card p-3 text-center">
        <h6>Total Collected This Month</h6>
        <h4>KES <?= number_format($totalCollected, 2) ?></h4>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card stat-card p-3 text-center">
        <h6>Total Properties</h6>
        <h4><?= $totalProperties ?></h4>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card stat-card p-3 text-center">
        <h6>Vacant Units</h6>
        <h4><?= $vacantUnits ?></h4>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card stat-card p-3 text-center">
        <h6>Active Tenants</h6>
        <h4><?= $activeTenants ?></h4>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card stat-card p-3 text-center">
        <h6>Rent Due This Week</h6>
        <h4><?= $rentDueThisWeek ?> tenants</h4>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Monthly Income</strong></div>
        <div class="card-body">
          <canvas id="monthlyIncomeChart"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="card">
        <div class="card-header"><strong>Occupancy Rate</strong></div>
        <div class="card-body">
          <canvas id="occupancyChart"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="card">
        <div class="card-header"><strong>Rent Collection Rate</strong></div>
        <div class="card-body">
          <canvas id="rentCollectionChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const incomeCtx = document.getElementById('monthlyIncomeChart');
  const occupancyCtx = document.getElementById('occupancyChart');
  const rentCtx = document.getElementById('rentCollectionChart');

  // Monthly Income Line Chart
  new Chart(incomeCtx, {
    type: 'line',
    data: {
      labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
      datasets: [{
        label: 'KES',
        data: <?= json_encode($monthlyIncome) ?>,
        borderColor: '#198754',
        backgroundColor: 'rgba(25, 135, 84, 0.1)',
        fill: true,
        tension: 0.3
      }]
    },
    options: { responsive: true }
  });

  // Occupancy Rate Pie Chart
  const occupancyData = <?= json_encode(array_column($occupancyRates, 'value')) ?>;
  const occupancyLabels = <?= json_encode(array_column($occupancyRates, 'label')) ?>;

  new Chart(occupancyCtx, {
    type: 'pie',
    data: {
      labels: occupancyLabels,
      datasets: [{
        data: occupancyData,
        backgroundColor: ['#198754', '#d4edda', '#a2d5ab']
      }]
    },
    options: { responsive: true }
  });

  // Rent Collection Rate Donut Chart (example static)
  new Chart(rentCtx, {
    type: 'doughnut',
    data: {
      labels: ['Paid', 'Unpaid'],
      datasets: [{
        data: [<?= $totalCollected ?>, 10000 - <?= $totalCollected ?>],
        backgroundColor: ['#198754', '#f8d7da']
      }]
    },
    options: { responsive: true }
  });
</script>
