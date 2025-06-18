<?php
class MpesaIntegration {
    private $consumerKey;
    private $consumerSecret;
    private $shortCode;
    private $passKey;
    private $callbackUrl;
    private $environment;
    
    public function __construct() {
        $this->consumerKey = 'your_consumer_key';
        $this->consumerSecret = 'your_consumer_secret';
        $this->shortCode = '174379'; // Sandbox: 174379
        $this->passKey = 'your_passkey';
        $this->callbackUrl = 'https://yourdomain.com/mpesa_callback.php';
        $this->environment = 'sandbox'; // or 'production'
    }
    
    /**
     * Get M-Pesa access token
     */
    private function getAccessToken() {
        $url = ($this->environment == 'sandbox') 
            ? 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' 
            : 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        
        $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $credentials
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response);
        return $data->access_token ?? null;
    }
    
    /**
     * Initiate STK Push payment request
     */
    public function stkPush($phone, $amount, $reference, $description) {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return ['success' => false, 'message' => 'Failed to get access token'];
        }

        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortCode . $this->passKey . $timestamp);
        
        // Format phone number (07... → 2547...)
        $phone = preg_replace('/^0/', '254', $phone);
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        $payload = [
            'BusinessShortCode' => $this->shortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $this->shortCode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->callbackUrl,
            'AccountReference' => $reference,
            'TransactionDesc' => $description
        ];
        
        $url = ($this->environment == 'sandbox')
            ? 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            : 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode == 200,
            'response' => json_decode($response, true)
        ];
    }
    
    /**
     * Handle M-Pesa callback
     */
    public function handleCallback($callbackData) {
        // Log raw callback data
        $this->logCallback($callbackData);
        
        if (!isset($callbackData['Body']['stkCallback'])) {
            return ['success' => false, 'message' => 'Invalid callback format'];
        }
        
        $callback = $callbackData['Body']['stkCallback'];
        $resultCode = $callback['ResultCode'];
        $resultDesc = $callback['ResultDesc'];
        
        if ($resultCode == 0) {
            // Successful payment
            $metadata = $callback['CallbackMetadata']['Item'];
            $amount = $metadata[0]['Value'];
            $receipt = $metadata[1]['Value'];
            $phone = $metadata[4]['Value'];
            
            return [
                'success' => true,
                'amount' => $amount,
                'receipt' => $receipt,
                'phone' => $phone,
                'message' => 'Payment processed successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => $resultDesc
            ];
        }
    }
    
    /**
     * Log callback data
     */
    private function logCallback($data) {
        $logFile = 'mpesa_callback.log';
        $logEntry = date('Y-m-d H:i:s') . " - " . json_encode($data) . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

// Example usage in your payment processing script:
// $mpesa = new MpesaIntegration();
// $response = $mpesa->stkPush('0712345678', 5000, 'RENT_JULY_2023', 'July Rent Payment');
// if ($response['success']) {
//     // Payment initiated successfully
// } else {
//     // Handle error
// }

// Example callback handler:
// if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//     $mpesa = new MpesaIntegration();
//     $callbackData = json_decode(file_get_contents('php://input'), true);
//     $result = $mpesa->handleCallback($callbackData);
//     
//     if ($result['success']) {
//         // Update your database, send notifications, etc.
//     }
//     
//     // Always respond to M-Pesa
//     header('Content-Type: application/json');
//     echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
// }
?>