<?php
class MpesaIntegration {
    private $consumerKey;
    private $consumerSecret;
    private $shortCode;
    private $passKey;
    private $callbackUrl;
    private $environment;
    private $landlordId; // Track landlord ID for logging
    
    public function __construct($credentials) {
        $this->consumerKey = $credentials['consumerKey'];
        $this->consumerSecret = $credentials['consumerSecret'];
        $this->shortCode = $credentials['shortCode'];
        $this->passKey = $credentials['passKey'];
        $this->callbackUrl = $credentials['callbackUrl'];
        $this->environment = $credentials['environment'];
        $this->landlordId = $credentials['landlordId'] ?? null;
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode != 200) {
            $this->logError("Access token request failed with HTTP $httpCode");
            return null;
        }
        
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
        
        $responseData = json_decode($response, true);
        $success = $httpCode == 200;
        
        if (!$success) {
            $this->logError("STK Push failed: " . ($responseData['errorMessage'] ?? 'Unknown error'));
        }
        
        return [
            'success' => $success,
            'response' => $responseData,
            'checkoutRequestID' => $responseData['CheckoutRequestID'] ?? null
        ];
    }
    
    /**
     * Handle M-Pesa callback
     */
    public function handleCallback($callbackData) {
        // Log raw callback data with landlord identifier
        $this->logCallback($callbackData);
        
        if (!isset($callbackData['Body']['stkCallback'])) {
            return ['success' => false, 'message' => 'Invalid callback format'];
        }
        
        $callback = $callbackData['Body']['stkCallback'];
        $resultCode = $callback['ResultCode'];
        $resultDesc = $callback['ResultDesc'];
        $checkoutRequestID = $callback['CheckoutRequestID'] ?? '';
        
        $result = [
            'success' => $resultCode == 0,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'checkout_request_id' => $checkoutRequestID
        ];
        
        if ($resultCode == 0) {
            // Successful payment
            $metadata = $callback['CallbackMetadata']['Item'];
            
            // Map metadata to named values
            $metadataMap = [];
            foreach ($metadata as $item) {
                $metadataMap[$item['Name']] = $item['Value'];
            }
            
            $result['amount'] = $metadataMap['Amount'] ?? null;
            $result['receipt'] = $metadataMap['MpesaReceiptNumber'] ?? null;
            $result['phone'] = $metadataMap['PhoneNumber'] ?? null;
            $result['transaction_date'] = $metadataMap['TransactionDate'] ?? null;
            $result['account_reference'] = $metadataMap['AccountReference'] ?? null;
        }
        
        return $result;
    }
    
    /**
     * Log callback data with landlord identifier
     */
    private function logCallback($data) {
        $logFile = 'mpesa_callback.log';
        $landlordId = $this->landlordId ?? 'unknown';
        $logEntry = sprintf(
            "[%s] [Landlord: %s] %s\n",
            date('Y-m-d H:i:s'),
            $landlordId,
            json_encode($data)
        );
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * Log error messages
     */
    private function logError($message) {
        $logFile = 'mpesa_errors.log';
        $landlordId = $this->landlordId ?? 'unknown';
        $logEntry = sprintf(
            "[%s] [Landlord: %s] %s\n",
            date('Y-m-d H:i:s'),
            $landlordId,
            $message
        );
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

/**
 * Get landlord's M-Pesa credentials from database
 * 
 * @param int $landlordId
 * @return array
 */
function getLandlordMpesaCredentials($landlordId) {
    // Database connection - use your actual connection method
    $db = new PDO('mysql:host=localhost;dbname=kejasmart', 'username', 'password');
    
    $stmt = $db->prepare("
        SELECT 
            mpesa_consumer_key, 
            mpesa_consumer_secret,
            mpesa_short_code,
            mpesa_pass_key,
            mpesa_environment
        FROM landlords 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $landlordId]);
    $landlord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$landlord) {
        return null;
    }
    
    // Get base callback URL from system settings
    $baseCallback = 'https://yourdomain.com/mpesa_callback.php'; // Default
    $settingsStmt = $db->prepare("
        SELECT setting_value 
        FROM system_settings 
        WHERE setting_key = 'mpesa_callback_base'
    ");
    $settingsStmt->execute();
    $setting = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($setting) {
        $baseCallback = $setting['setting_value'];
    }
    
    return [
        'consumerKey' => $landlord['mpesa_consumer_key'],
        'consumerSecret' => $landlord['mpesa_consumer_secret'],
        'shortCode' => $landlord['mpesa_short_code'],
        'passKey' => $landlord['mpesa_pass_key'],
        'callbackUrl' => $baseCallback . '?landlord_id=' . $landlordId,
        'environment' => $landlord['mpesa_environment'],
        'landlordId' => $landlordId
    ];
}

// Example Usage for Payment Initiation:
// $landlordId = 123; // Get from session or request
// $credentials = getLandlordMpesaCredentials($landlordId);
//
// if ($credentials) {
//     $mpesa = new MpesaIntegration($credentials);
//     $response = $mpesa->stkPush('0712345678', 100, 'RENT_JULY', 'July Rent');
//     
//     if ($response['success']) {
//         // Save checkoutRequestID to database
//         $checkoutRequestID = $response['checkoutRequestID'];
//         // Create record in mpesa_transactions table
//     } else {
//         // Handle error
//     }
// } else {
//     // Handle missing credentials
// }

// Example Callback Handler:
// if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//     $landlordId = $_GET['landlord_id'] ?? null;
//     
//     if ($landlordId) {
//         $credentials = getLandlordMpesaCredentials($landlordId);
//         
//         if ($credentials) {
//             $mpesa = new MpesaIntegration($credentials);
//             $callbackData = json_decode(file_get_contents('php://input'), true);
//             $result = $mpesa->handleCallback($callbackData);
//             
//             if ($result['success']) {
//                 // Process successful payment:
//                 // 1. Find lease by account_reference ($result['account_reference'])
//                 // 2. Create payment record
//                 // 3. Update mpesa_transactions table
//             }
//         }
//     }
//     
//     // Always respond to M-Pesa
//     header('Content-Type: application/json');
//     echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
// }