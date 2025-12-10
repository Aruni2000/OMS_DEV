<?php
/**
 * TransExpress Existing Parcel API (Manual Waybill)
 * API Documentation: https://portal.transexpress.lk/api/orders/upload/single-manual
 */

/**
 * Call TransExpress Existing Parcel API with manual waybill
 * 
 * @param array $data API parameters
 * @return string JSON response or error message
 */
function callTransExpressExistingParcelApi($data) {
    // API endpoint for manual waybill orders
    $api_url = 'https://portal.transexpress.lk/api/orders/upload/single-manual';
    
    // Validate required fields
    $required_fields = ['api_key', 'waybill_id', 'customer_name', 'address', 'phone_no', 'cod', 'city_id'];
    
    foreach ($required_fields as $field) {
        if (empty($data[$field]) && $data[$field] !== '0') {
            return json_encode([
                'success' => false,
                'error' => "Missing required field: $field"
            ]);
        }
    }
    
    // Validate waybill_id format (must be exactly 8 characters)
    if (strlen($data['waybill_id']) !== 8) {
        return json_encode([
            'success' => false,
            'error' => "Invalid waybill_id format. Must be exactly 8 characters."
        ]);
    }
    
    // Validate phone number format (9-10 digits)
    $clean_phone = preg_replace('/[^0-9]/', '', $data['phone_no']);
    if (strlen($clean_phone) < 9 || strlen($clean_phone) > 10) {
        return json_encode([
            'success' => false,
            'error' => "Invalid phone number format. Must be 9-10 digits."
        ]);
    }
    
    // Prepare API payload
    $payload = array(
        'waybill_id' => $data['waybill_id'],
        'customer_name' => $data['customer_name'],
        'address' => $data['address'],
        'phone_no' => $clean_phone,
        'cod' => floatval($data['cod']),
        'city_id' => intval($data['city_id'])
    );
    
    // Add optional fields if provided
    if (!empty($data['order_no'])) {
        $payload['order_no'] = $data['order_no'];
    }
    
    if (!empty($data['description'])) {
        $payload['description'] = $data['description'];
    }
    
    if (!empty($data['phone_no2'])) {
        $payload['phone_no2'] = preg_replace('/[^0-9]/', '', $data['phone_no2']);
    }
    
    if (!empty($data['district_id'])) {
        $payload['district_id'] = intval($data['district_id']);
    }
    
    if (!empty($data['note'])) {
        $payload['note'] = $data['note'];
    }
    
    // Initialize cURL
    $curl = curl_init();
    
    // Set cURL options
    curl_setopt_array($curl, array(
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $data['api_key']
        ),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    
    // Execute cURL request
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    // Check for cURL errors
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        curl_close($curl);
        error_log("TransExpress API cURL Error: " . $error);
        return "Curl error: " . $error;
    }
    
    curl_close($curl);
    
    // Log response for debugging
    error_log("TransExpress API Response (HTTP $http_code): " . $response);
    
    return $response;
}

/**
 * Parse TransExpress Existing Parcel API response - FIXED VERSION
 * 
 * @param string $response Raw API response
 * @return array Parsed result with success status and data
 */
function parseTransExpressExistingResponse($response) {
    // Handle cURL errors
    if (strpos($response, 'Curl error:') === 0) {
        return [
            'success' => false,
            'error' => 'Network connection error. Please check internet connectivity.'
        ];
    }
    
    // Try to decode JSON response
    $decoded = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response from API.'
        ];
    }
    
    // SUCCESS PATTERNS
    
    // Pattern 1: {"success": "Record successfully added", "order": {...}} - YOUR API FORMAT
    if (isset($decoded['success']) && is_string($decoded['success']) && 
        stripos($decoded['success'], 'success') !== false && isset($decoded['order'])) {
        
        return [
            'success' => true,
            'waybill_id' => $decoded['order']['waybill_id'] ?? null,
            'order_id' => $decoded['order']['order_no'] ?? $decoded['order']['id'] ?? null,
            'message' => $decoded['success']
        ];
    }
    
    // Pattern 2: {"success": true, ...}
    if (isset($decoded['success']) && $decoded['success'] === true) {
        return [
            'success' => true,
            'waybill_id' => $decoded['waybill_id'] ?? $decoded['data']['waybill_id'] ?? null,
            'order_id' => $decoded['order_id'] ?? $decoded['data']['order_id'] ?? null,
            'message' => $decoded['message'] ?? 'Order processed successfully'
        ];
    }
    
    // Pattern 3: {"status": "success", ...}
    if (isset($decoded['status']) && $decoded['status'] === 'success') {
        return [
            'success' => true,
            'waybill_id' => $decoded['data']['waybill_id'] ?? $decoded['waybill_id'] ?? null,
            'order_id' => $decoded['data']['order_id'] ?? $decoded['order_id'] ?? null,
            'message' => $decoded['message'] ?? 'Order processed successfully'
        ];
    }
    
    // ERROR PATTERNS
    
    // Pattern 1: {"success": false, ...} or {"success": "error message"}
    if (isset($decoded['success'])) {
        if ($decoded['success'] === false || (is_string($decoded['success']) && stripos($decoded['success'], 'error') !== false)) {
            return [
                'success' => false,
                'error' => $decoded['error'] ?? $decoded['message'] ?? $decoded['success']
            ];
        }
    }
    
    // Pattern 2: {"status": "error", ...}
    if (isset($decoded['status']) && $decoded['status'] === 'error') {
        return [
            'success' => false,
            'error' => $decoded['message'] ?? $decoded['error'] ?? 'API Error'
        ];
    }
    
    // Pattern 3: {"error": "message"}
    if (isset($decoded['error'])) {
        return [
            'success' => false,
            'error' => $decoded['error']
        ];
    }
    
    // DEFAULT: Unknown format - log for analysis
    error_log("TransExpress Unknown Response: " . json_encode($decoded));
    return [
        'success' => false,
        'error' => 'Unknown API response format'
    ];
}

/**
 * Get user-friendly error message
 * 
 * @param string $error_message Raw error message from API
 * @return string User-friendly error message
 */
function getTransExpressExistingErrorMessage($error_message) {
    $error_lower = strtolower($error_message);
    
    if (strpos($error_lower, 'waybill') !== false) {
        return 'Waybill number already used or invalid';
    }
    
    if (strpos($error_lower, 'city') !== false) {
        return 'Invalid city selected';
    }
    
    if (strpos($error_lower, 'phone') !== false) {
        return 'Invalid phone number format';
    }
    
    if (strpos($error_lower, 'address') !== false) {
        return 'Invalid or incomplete address';
    }
    
    if (strpos($error_lower, 'unauthorized') !== false) {
        return 'Invalid API key or unauthorized access';
    }
    
    return ucfirst($error_message);
}
?>