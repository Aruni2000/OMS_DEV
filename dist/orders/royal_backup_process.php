<?php
/**
 * Royal Express Existing Parcel API Integration
 * API Documentation: Curfox Royal Express API
 */

/**
 * Build the payload for Royal Express API
 */
function buildRoyalExpressPayload($data) {
    $api_amount = ($data['order_status'] === 'Paid') ? 0 : $data['total_amount'];
    $clean_phone = preg_replace('/[^0-9]/', '', $data['phone_no']);

    return array(
        'bearer_token' => $data['bearer_token'],
        'tenant' => $data['tenant'],
        'merchant_business_id' => $data['merchant_business_id'],
        'tracking_id' => $data['tracking_id'],
        'order_no' => $data['order_no'],
        'customer_name' => $data['customer_name'],
        'address' => trim($data['address_line1'] . ' ' . $data['address_line2']),
        'city' => $data['city'],
        'state' => $data['state'],
        'phone_no' => $clean_phone,
        'cod_amount' => $api_amount,
        'description' => 'Order #' . $data['order_no'] . ' - ' . count($data['items']) . ' items',
        'note' => $data['note'] ?? ''
    );
}

/**
 * Call the Royal Express API
 */
function callRoyalExpressExistingParcelApi($payload) {
    $api_url = "https://v1.api.curfox.com/api/public/merchant/order/single";

    $json_payload = json_encode($payload);

    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $payload['bearer_token'],
        'Content-Type: application/json',
        'X-Tenant: ' . $payload['tenant']
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    // Execute request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Log request/response for debugging
    error_log("Royal Express API Request: " . $json_payload);
    error_log("Royal Express API Response (HTTP $http_code): " . $response);

    if ($curl_error) {
        error_log("Royal Express cURL Error: " . $curl_error);
    }

    return array(
        'http_code' => $http_code,
        'response' => $response,
        'curl_error' => $curl_error
    );
}

/**
 * Parse the API response
 */
function parseRoyalExpressExistingResponse($api_response) {
    $http_code = $api_response['http_code'];
    $response = $api_response['response'];
    $curl_error = $api_response['curl_error'];

    // Handle cURL errors
    if (!empty($curl_error)) {
        return array(
            'success' => false,
            'error' => 'Connection error: ' . $curl_error
        );
    }

    // Handle HTTP errors
    if ($http_code != 200 && $http_code != 201) {
        return array(
            'success' => false,
            'error' => 'API returned error code: ' . $http_code
        );
    }

    // Parse JSON response
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array(
            'success' => false,
            'error' => 'Invalid JSON response from API'
        );
    }

    // Check for success in response
    if (isset($data['success']) && $data['success'] === true) {
        return array(
            'success' => true,
            'tracking_id' => $data['data']['tracking_id'] ?? $data['tracking_id'] ?? null,
            'message' => $data['message'] ?? 'Order created successfully'
        );
    } elseif (isset($data['status']) && $data['status'] === 'success') {
        return array(
            'success' => true,
            'tracking_id' => $data['data']['tracking_id'] ?? $data['tracking_id'] ?? null,
            'message' => $data['message'] ?? 'Order created successfully'
        );
    } else {
        $error_message = $data['message'] ?? $data['error'] ?? 'Unknown API error';
        return array(
            'success' => false,
            'error' => $error_message
        );
    }
}
?>
