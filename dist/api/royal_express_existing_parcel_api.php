<?php
/**
 * Royal Express API Integration (Final Fixed Version)
 * --------------------------------------------------
 * Handles single order creation with proper success/error detection.
 */

function callRoyalExpressSingleOrderApi($data, $courier_id, $conn) {
    $url = "https://v1.api.curfox.com/api/public/merchant/order/single";

    $stmt = $conn->prepare("SELECT api_key FROM couriers WHERE courier_id = ? LIMIT 1");
    $stmt->bind_param("i", $courier_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $api_key = $row['api_key'] ?? null;


    $headers = [
        "Accept: application/json",
        "Authorization: Bearer $api_key",
        "Content-Type: application/json",
        "X-tenant: royalexpress"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return [
            'success' => false,
            'http_code' => 0,
            'error_type' => 'network_error',
            'error_message' => "cURL Error: $error_msg"
        ];
    }

    curl_close($ch);
    $decoded = json_decode($response, true);

    return [
        'success' => ($http_code === 200),
        'http_code' => $http_code,
        'response' => $decoded,
        'raw' => $response
    ];
}

/**
 * Parse Royal Express API Response
 */
function parseRoyalExpressResponse($api_response) {
    $http_code = $api_response['http_code'];
    $res = $api_response['response'] ?? [];

    //  Success case
    if ($http_code === 200 && isset($res['data'][0])) {
        return [
            'success' => true,
            'tracking_number' => $res['data'][0],
            'message' => $res['message'] ?? 'Royal Express order created successfully.'
        ];
    }

    // Auth error
    if ($http_code === 401 || ($res['message'] ?? '') === 'Unauthenticated.') {
        return [
            'success' => false,
            'error_type' => 'auth_error',
            'error_message' => 'Invalid Bearer Token or tenant.'
        ];
    }

    // Validation error
    if ($http_code === 422 && isset($res['errors'])) {
        return [
            'success' => false,
            'error_type' => 'validation_error',
            'error_message' => flattenRoyalExpressErrors($res['errors'])
        ];
    }

    //  Unknown error
    return [
        'success' => false,
        'error_type' => 'unknown_error',
        'error_message' => $res['message'] ?? 'Unknown API error. HTTP Code: ' . $http_code,
        'raw_response' => $api_response['raw']
    ];
}

/**
 * Flatten Nested Validation Errors
 */
function flattenRoyalExpressErrors($errors) {
    $flat = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($errors));
    foreach ($iterator as $error) {
        $flat[] = trim($error);
    }
    return implode(' | ', $flat);
}
