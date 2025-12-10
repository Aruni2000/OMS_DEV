<?php
// Include DB connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// =======================================
// CONFIGURATION
// =======================================
$api_url = "https://v1.api.curfox.com/api/public/merchant/order/tracking-info";
$tenant = "royalexpress";

// Fetch API key from couriers table for courier_id = 13
$courier_query = "SELECT api_key FROM couriers WHERE courier_id = 13";
$courier_result = $conn->query($courier_query);

if ($courier_result->num_rows === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Courier with ID 13 not found in database."
    ]);
    exit;
}

$courier_row = $courier_result->fetch_assoc();
$api_token = $courier_row['api_key'];

// Check if API key exists
if (empty($api_token)) {
    echo json_encode([
        "success" => false,
        "message" => "API key is empty for courier_id = 13. Please configure the API key in the couriers table."
    ]);
    exit;
}

$status_map = [
    'CONFIRMED' => 'waiting',
    'CANCELLED' => 'cancel',
    'DEFAULT WAREHOUSE CHANGE' => 'processing',
    'RECEIVED AT DESTINATION WAREHOUSE' => 'processing',
    'RETURNED TO MERCHANT' => 'return complete',
    'ASSIGN TO RETURN RIDER' => 'return complete',
    'RECEIVED FAILED BY WALKING CUSTOMER' => 'return complete',
    'PICKUP RIDER ASSIGNED' => 'pickup',
    'PICKED UP' => 'pickup',
    'DELIVERED BY PICKUP RIDER' => 'pickup',
    'DISPATCH TO ORIGIN WAREHOUSE' => 'pickup',
    'CHANGE DESTINATION' => 'pending to deliver',
    'RESCHEDULED' => 'pending to deliver',
    'FAILED TO DELIVER' => 'pending to deliver',
    'RETURN RESCHEDULE' => 'return pending',
    'RETURN TO ORIGIN WAREHOUSE (INVALID DESTINATION)' => 'return',
    'RETURN TO WAREHOUSE (FAILED TO RETURN)' => 'return',
    'RECEIVED TO ORIGIN WAREHOUSE (FAILED TO DELIVER)' => 'return',
    'RETURN TO CLIENT' => 'return',
    'RECEIVED FAILED ORDER' => 'return',
    'RECEIVED RETURN FROM MAIN WAREHOUSE' => 'return',
    'ASSIGNED TO RETURN RIDER' => 'return',
    'INVALID DESTINATION' => 'return',
    'RECEIVED FROM WAREHOUSE (FAILED TO RETURN)' => 'return',
    'ASSIGNED TO DESTINATION RIDER' => 'courier dispatch',
    'DELIVERED' => 'delivered',
    'PARTIALLY DELIVERED' => 'delivered',
    'DISPATCHED FROM ORIGIN WAREHOUSE' => 'transfer',
    'RECEIVED TO ORIGIN WAREHOUSE' => 'transfer',
    'RETURN TO ORIGIN WAREHOUSE' => 'return transfer',
    'RETURN TO DESTINATION WAREHOUSE' => 'return transfer',
    'DISPATCHED RETURN FROM ORIGIN WAREHOUSE' => 'return transfer',
];

// =======================================
// FETCH ORDERS
// =======================================
$sql = "SELECT order_id, tracking_number FROM order_header WHERE courier_id = 13 AND tracking_number IS NOT NULL";
$result = $conn->query($sql);

if ($result->num_rows === 0) {
    echo json_encode([
        "success" => true,
        "message" => "No orders found for courier_id = 13"
    ]);
    exit;
}

// =======================================
// FUNCTION TO CALL ROYAL EXPRESS API USING GET
// =======================================
function fetchTrackingStatus($api_url, $api_token, $tenant, $waybill_number)
{
    $url = $api_url . "?waybill_number=" . urlencode($waybill_number);

    $headers = [
        "Accept: application/json",
        "Authorization: Bearer $api_token",
        "X-tenant: $tenant"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ["error" => "CURL Error: $error"];
    }

    return json_decode($response, true);
}

// =======================================
// PROCESS ORDERS
// =======================================
$updated = 0;
$errors = [];

while ($row = $result->fetch_assoc()) {
    $order_id = $row['order_id'];
    $waybill_number = trim($row['tracking_number']);

    $api_response = fetchTrackingStatus($api_url, $api_token, $tenant, $waybill_number);

    if (isset($api_response['error'])) {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => $api_response['error']];
        continue;
    }

    if (!isset($api_response['data']) || count($api_response['data']) == 0) {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => "No data found"];
        continue;
    }

    $latest_status = $api_response['data'][0]['status']['name'] ?? null;
    if (!$latest_status) {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => "No status name found"];
        continue;
    }

    $mapped_status = $status_map[$latest_status] ?? null;
    if (!$mapped_status) {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => "Unmapped status: $latest_status"];
        continue;
    }

    $update_stmt = $conn->prepare("UPDATE order_header SET status = ?, updated_at = NOW() WHERE order_id = ?");
    $update_stmt->bind_param("si", $mapped_status, $order_id);

    if ($update_stmt->execute()) {
        $updated++;
    } else {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => $update_stmt->error];
    }

    $update_stmt->close();
}

// =======================================
// FINAL RESPONSE
// =======================================
$response = [
    "success" => true,
    "updated_orders" => $updated,
    "total_orders" => $result->num_rows,
    "errors" => $errors
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>