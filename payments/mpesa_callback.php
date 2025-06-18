<?php
require_once __DIR__.'mpesa_integration.php';
require_once __DIR__.'config.php';

// 1. Verify the request is from M-Pesa (in production)
$allowed_ips = ['196.201.214.200', '196.201.214.206']; // M-Pesa IPs
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && !in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    http_response_code(403);
    die(json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']));
}

// 2. Process the callback
$callbackData = json_decode(file_get_contents('php://input'), true);
$mpesa = new MpesaIntegration();
$result = $mpesa->handleCallback($callbackData);

if ($result['success']) {
    // 3. Update your database
    $conn->begin_transaction();
    
    try {
        // Update payment status
        $stmt = $conn->prepare("UPDATE payments 
            SET status = 'completed', 
                receipt_number = ?,
                completed_at = NOW()
            WHERE request_id = ?");
        $stmt->bind_param("ss", $result['receipt'], $callbackData['Body']['stkCallback']['CheckoutRequestID']);
        $stmt->execute();
        
        // Record transaction
        $stmt = $conn->prepare("INSERT INTO transactions 
            (payment_id, amount, phone, receipt_number, metadata)
            VALUES (LAST_INSERT_ID(), ?, ?, ?, ?)");
        $stmt->bind_param("dsss", 
            $result['amount'],
            $result['phone'],
            $result['receipt'],
            json_encode($callbackData)
        );
        $stmt->execute();
        
        $conn->commit();
        
        // 4. Send notifications (SMS/email)
        // You would call your notification service here
    } catch (Exception $e) {
        $conn->rollback();
        // Log the error
        file_put_contents('payment_errors.log', date('[Y-m-d H:i:s] ') . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

// 5. Always respond to M-Pesa
header('Content-Type: application/json');
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
?>