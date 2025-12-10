<?php

function addKoombiyoOrder($orderData, $apiKey = 'muABqMKZgkaZDAnbBWev') {
    $url = 'https://application.koombiyodelivery.lk/api/Addorders/users';
    
    // Required fields validation
    $required = ['orderWaybillid', 'receiverName', 'receiverStreet', 'receiverDistrict', 'receiverCity', 'receiverPhone'];
    foreach ($required as $field) {
        if (empty($orderData[$field])) {
            return ['success' => false, 'error' => "Missing required field: $field"];
        }
    }
    
    $postData = [
        'apikey' => $apiKey,
        'orderWaybillid' => $orderData['orderWaybillid'],
        'orderNo' => $orderData['orderNo'] ?? $orderData['orderWaybillid'],
        'receiverName' => $orderData['receiverName'],
        'receiverStreet' => $orderData['receiverStreet'],
        'receiverDistrict' => $orderData['receiverDistrict'],
        'receiverCity' => $orderData['receiverCity'],
        'receiverPhone' => $orderData['receiverPhone'],
        'description' => $orderData['description'] ?? '',
        'spclNote' => $orderData['spclNote'] ?? '',
        'getCod' => $orderData['getCod'] ?? '0'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Connection error: ' . $error];
    }
    
    $data = json_decode($response, true);
    
    // Check if API returned success in the response body
    if (isset($data['status']) && $data['status'] === 'success') {
        return ['success' => true, 'data' => $data];
    } else {
        return [
            'success' => false, 
            'error' => $data['message'] ?? 'Unknown API error',
            'http_code' => $httpCode,
            'full_response' => $data
        ];
    }
}


?>