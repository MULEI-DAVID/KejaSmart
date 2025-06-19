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
    $stmt = $pdo->prepare("INSERT INTO properties (landlord_id, name, type, location, units, description) 
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $landlordId,
        $data['name'],
        $data['type'],
        $data['location'],
        $data['units'],
        $data['description']
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Property added successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>