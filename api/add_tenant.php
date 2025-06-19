<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['landlord_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$landlordId = $_SESSION['landlord_id'];
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    $stmt = $pdo->prepare("INSERT INTO tenants (landlord_id, property_id, full_name, email, phone, national_id, unit_number, move_in_date) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $landlordId,
        $data['property_id'],
        $data['full_name'],
        $data['email'],
        $data['phone'],
        $data['national_id'],
        $data['unit_number'],
        $data['move_in_date']
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Tenant added successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>