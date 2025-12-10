<?php
// =======================================
// KOOMBIYO COURIER API INTEGRATION
// =======================================

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set JSON headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// --------------------------------------
// FETCH KOOMBIYO COURIER DETAILS
// --------------------------------------
$courier_id = 12; // Koombiyo courier ID

$courier_sql = "SELECT api_key FROM couriers WHERE courier_id = ? AND has_api_existing = 1 LIMIT 1";
$stmt = $conn->prepare($courier_sql);
$stmt->bind_param("i", $courier_id);
$stmt->execute();
$courier_result = $stmt->get_result();
$stmt->close();

if ($courier_result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Courier not found or API not configured."]);
    exit;
}

$courier_data = $courier_result->fetch_assoc();
$api_key = $courier_data['api_key'] ?? null;

if (empty($api_key)) {
    echo json_encode(["success" => false, "message" => "Courier API key is missing."]);
    exit;
}

// Koombiyo API endpoint
$api_url = "https://application.koombiyodelivery.lk/api/Orderhistory/users";

// --------------------------------------
// FETCH ORDERS FROM DB
// --------------------------------------
$sql = "SELECT order_id, tracking_number FROM order_header WHERE courier_id = ? AND tracking_number IS NOT NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $courier_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if ($result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "No Koombiyo orders found in DB"]);
    exit;
}

// --------------------------------------
// FUNCTION: FETCH STATUS FROM KOOMBIYO
// --------------------------------------
function fetchKoombiyoStatus($api_url, $api_key, $tracking_number)
{
    $postData = http_build_query([
        'apikey' => $api_key,
        'waybillid' => $tracking_number
    ]);

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => $error];
    return json_decode($response, true);
}

// --------------------------------------
// FUNCTION: MAP KOOMBIYO STATUS TO LOCAL
// --------------------------------------
function mapKoombiyoStatus($status)
{
    $mapping = [
        'Processing' => 'processing',
        'Collected by Koombiyo' => 'pending to deliver',
        'Dispatch to Destination' => 'pending to deliver',
        'Received at Destination' => 'processing',
        'Out for Delivery' => 'courier dispatch',
        'Delivered' => 'delivered',
        'Rescheduled' => 'pending to deliver',
        'Partially Delivered' => 'delivered',
        'Return to Client' => 'return complete',
        'Failed to Deliver' => 'return',
        'Return to HO' => 'return transfer',
        'Different Destination' => 'return transfer',
        'Received at HO' => 'return transfer',
        'Received at Warehouse' => 'processing',
        'Client Received' => 'return complete',
        'Purchase by Koombiyo' => 'done',
        'Delivered Not Confirmed' => 'pending to deliver',
        'Partially Delivered Not Confirmed' => 'delivered',
        'Confirmed By Branch' => 'pending to deliver',
        'Hold' => 'hold',
        'Pending Different Destination' => 'processing',
        'On QC' => 'processing',
        'Picked' => 'processing',
        'Exchange Collected' => 'return transfer',
        'Exchange Received' => 'return complete',
    ];

    return $mapping[$status] ?? $status;
}

// --------------------------------------
// PROCESS EACH ORDER
// --------------------------------------
$updated = 0;
$errors = [];

while ($row = $result->fetch_assoc()) {
    $order_id = $row['order_id'];
    $tracking_number = trim($row['tracking_number']);

    if (empty($tracking_number)) {
        $errors[] = ["order_id" => $order_id, "error" => "Empty tracking number"];
        continue;
    }

    $api_response = fetchKoombiyoStatus($api_url, $api_key, $tracking_number);

    if (isset($api_response['error'])) {
        $errors[] = ["order_id" => $order_id, "waybill" => $tracking_number, "error" => $api_response['error']];
        continue;
    }

    if (empty($api_response['order_history'])) {
        $errors[] = ["order_id" => $order_id, "waybill" => $tracking_number, "error" => "Order Not Found"];
        continue;
    }

    $last_entry = end($api_response['order_history']);
    $delivery_status = $last_entry['status'] ?? null;
    $last_update_time = $last_entry['date'] ?? date('Y-m-d H:i:s');

    if (!$delivery_status) {
        $errors[] = ["order_id" => $order_id, "waybill" => $tracking_number, "error" => "No status found"];
        continue;
    }

    $status_update = mapKoombiyoStatus($delivery_status);

    $update_stmt = $conn->prepare("UPDATE order_header SET status = ?, updated_at = ? WHERE order_id = ?");
    $update_stmt->bind_param("ssi", $status_update, $last_update_time, $order_id);

    if ($update_stmt->execute()) {
        if ($update_stmt->affected_rows > 0) {
            $updated++;
        } else {
            $errors[] = ["order_id" => $order_id, "waybill" => $tracking_number, "error" => "No rows updated"];
        }
    } else {
        $errors[] = ["order_id" => $order_id, "waybill" => $tracking_number, "error" => $update_stmt->error];
    }

    $update_stmt->close();
}

// --------------------------------------
// FINAL RESPONSE
// --------------------------------------
echo json_encode([
    "success" => true,
    "updated_orders" => $updated,
    "error_count" => count($errors),
    "errors" => $errors
], JSON_PRETTY_PRINT);

$conn->close();
?>
