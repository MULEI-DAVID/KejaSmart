<?php
require_once __DIR__.'mpesa_integration.php';
require_once __DIR__.'config.php';

header('Content-Type: application/json');

// 1. Authenticate the request (e.g., check landlord session)
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'landlords') {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

// 2. Validate input
$data = json_decode(file_get_contents('php://input'), true);
if (empty($data['phone']) || empty($data['amount'])) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Missing required fields']));
}

// 3. Process payment
try {
    $mpesa = new MpesaIntegration();
    $response = $mpesa->stkPush(
        $data['phone'],
        $data['amount'],
        $data['reference'] ?? 'RENT_' . date('Y-m'),
        $data['description'] ?? 'Rent Payment'
    );

    if ($response['success']) {
        // Log the transaction in your database
        $stmt = $conn->prepare("INSERT INTO payments 
            (landlord_id, tenant_phone, amount, reference, request_id, status) 
            VALUES (?, ?, ?, ?, ?, 'requested')");
        $stmt->bind_param("ssdss", 
            $_SESSION['user_id'],
            $data['phone'],
            $data['amount'],
            $data['reference'] ?? 'RENT_' . date('Y-m'),
            $response['response']['CheckoutRequestID']
        );
        $stmt->execute();
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'message' => 'Payment request sent to tenant'
        ]);
    } else {
        throw new Exception($response['response']['errorMessage'] ?? 'Payment initiation failed');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>