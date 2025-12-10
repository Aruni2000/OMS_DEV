<?php
/**
 * TransExpress API - Debug Version to Find the Exact Issue
 */

function callTransExpressApi($api_data) {
    $api_url = 'https://portal.transexpress.lk/api/orders/upload/single-auto';
    
    // Log what we received
    error_log("=== TransExpress API Debug ===");
    error_log("Received API data: " . json_encode($api_data, JSON_UNESCAPED_UNICODE));
    
    // Validate phone number format - this is often the issue
    $clean_phone = preg_replace('/[^0-9]/', '', $api_data['phone_no']);
    if (strlen($clean_phone) !== 10) {
        error_log("Phone validation failed: Original='{$api_data['phone_no']}' Cleaned='$clean_phone' Length=" . strlen($clean_phone));
        return json_encode(['success' => false, 'error' => "Invalid phone number format. Must be exactly 10 digits. Got: {$clean_phone}"]);
    }
    
    // Validate customer name - remove any special characters that might cause issues
    $clean_name = trim(preg_replace('/[^a-zA-Z0-9\s\-\.]/', '', $api_data['customer_name']));
    if (strlen($clean_name) < 2) {
        return json_encode(['success' => false, 'error' => "Customer name too short or contains invalid characters"]);
    }
    
    // Validate address
    $clean_address = trim($api_data['address']);
    if (strlen($clean_address) < 5) {
        return json_encode(['success' => false, 'error' => "Address too short"]);
    }
    
    // Build payload - exactly as the curl example shows
    $payload = array(
        "order_no" => (string)$api_data['order_no'], // Ensure string
        "customer_name" => $clean_name,
        "address" => $clean_address,
        "description" => substr($api_data['description'] ?? '', 0, 500), // Limit to 500 chars
        "phone_no" => $clean_phone, // Use cleaned phone
        "phone_no2" => "", // Empty string, not null
        "cod" => (float)$api_data['cod'],
        "city_id" => (int)$api_data['city_id'],
        "note" => substr($api_data['note'] ?? '', 0, 255) // Limit note length
    );
    
    error_log("Final payload: " . json_encode($payload, JSON_UNESCAPED_UNICODE));
    
    $curl = curl_init();
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_data['api_key']
        ),
        CURLOPT_SSL_VERIFYPEER => false
    ));
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        curl_close($curl);
        error_log("cURL Error: $error");
        return json_encode(['success' => false, 'error' => 'Connection failed: ' . $error]);
    }
    
    curl_close($curl);
    
    error_log("API Response ($http_code): $response");
    error_log("=== End Debug ===");
    
    return $response;
}

function parseTransExpressResponse($response) {
    $result = json_decode($response, true);
    
    if (!$result) {
        error_log("Failed to parse JSON response: $response");
        return ['success' => false, 'error' => 'Invalid JSON response'];
    }
    
    // Check for success
    if (isset($result['success']) && isset($result['order']['waybill_id'])) {
        return [
            'success' => true,
            'waybill_id' => $result['order']['waybill_id']
        ];
    }
    
    // Log the full error response for debugging
    error_log("API Error Response: " . json_encode($result, JSON_UNESCAPED_UNICODE));
    
    // Extract error message
    $error = 'Unknown API error';
    if (isset($result['message'])) {
        $error = $result['message'];
    } elseif (isset($result['error'])) {
        $error = $result['error'];
    } elseif (isset($result['errors']) && is_array($result['errors'])) {
        $error_parts = [];
        foreach ($result['errors'] as $field => $messages) {
            if (is_array($messages)) {
                $error_parts[] = "$field: " . implode(', ', $messages);
            } else {
                $error_parts[] = "$field: $messages";
            }
        }
        $error = implode(' | ', $error_parts);
    }
    
    return ['success' => false, 'error' => $error];
}
?>