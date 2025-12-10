<?php
// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Allow access from Transexpress domain
header('Access-Control-Allow-Origin: https://portal.transexpress.lk/api/tracking');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// TRANSEXPRESS CONFIGURATION
$api_url = "https://portal.transexpress.lk/api/tracking"; // POST API

// Fetch API key from couriers table for courier_id = 14
$courier_query = "SELECT api_key FROM couriers WHERE courier_id = 14";
$courier_result = $conn->query($courier_query);

if ($courier_result->num_rows === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Courier with ID 14 not found in database."
    ]);
    exit;
}

$courier_row = $courier_result->fetch_assoc();
$api_token = $courier_row['api_key'];

// Check if API key exists
if (empty($api_token)) {
    echo json_encode([
        "success" => false,
        "message" => "API key is empty for courier_id = 14. Please configure the API key in the couriers table."
    ]);
    exit;
}

// =======================================
// FETCH ALL ORDERS WITH COURIER_ID = 14
// =======================================
$sql = "SELECT order_id, tracking_number FROM order_header WHERE courier_id = 14 AND tracking_number IS NOT NULL";
$result = $conn->query($sql);

if ($result->num_rows === 0) {
    echo json_encode(["message" => "No orders found for courier_id = 14"]);
    exit;
}

// =======================================
// FUNCTION TO CALL TRANSEXPRESS API
// =======================================
function fetchTransexpStatus($api_url, $api_token, $waybill_number)
{
    $payload = json_encode(['waybill_id' => $waybill_number]);

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $api_token"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => "CURL Error: $error"];
    }

    return json_decode($response, true);
}

// =======================================
// STATUS MAPPING (Transexpress → System)
// =======================================
function mapTransexpStatus($status)
{
    $status_map = [
        'Processing' => 'processing',
        'Collected and Dispatched to Destination' => 'pending to deliver',
        'Received at Destination' => 'processing',
        'Out for Delivery' => 'courier dispatch',
        'Different Destination' => 'hold',
        'Delivered' => 'delivered',
        'Partially Delivered' => 'delivered',
        'Returned to Branch' => 'return',
        'Rescheduled' => 'pending to deliver',
        'Failed to Deliver' => 'pending to deliver',
        'Returned to HO' => 'return transfer',
        'Received at HO (Returned Item)' => 'return transfer',
        'Returned to Client' => 'return complete',
        'Re-delivery' => 'pending to deliver',
        'Received by Client' => 'return complete',
        'Cancelled' => 'cancel',
        'Purchased by TranEx' => 'done',
        'Return to HO (Invalid Destination)' => 'return transfer',
        'Received by HO (Invalid Destination)' => 'return transfer',
        'HO Clearance' => 'processing',
        'Re-assign Rider' => 'courier dispatch',
    ];

    $status = trim($status);
    return $status_map[$status] ?? 'processing'; // Default fallback
}

// =======================================
// PROCESS ORDERS
// =======================================
$updated = 0;
$errors = [];

while ($row = $result->fetch_assoc()) {
    $order_id = $row['order_id'];
    $waybill_number = trim($row['tracking_number']);

    // Validate waybill
    if (strlen($waybill_number) !== 8) {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => "Waybill must be 8 characters"];
        continue;
    }

    $api_response = fetchTransexpStatus($api_url, $api_token, $waybill_number);

    if (isset($api_response['error'])) {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => $api_response['error']];
        continue;
    }

    if (!isset($api_response['data']) || empty($api_response['data'])) {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => "No data returned from API"];
        continue;
    }

    $order_data = $api_response['data'];
    $delivery_status = $order_data['status_history'][0]['status'] ?? $order_data['current_status'] ?? null;
    $last_update_time = $order_data['completed_date'] ?? date('Y-m-d H:i:s');

    if (!$delivery_status) {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => "No status available"];
        continue;
    }

    // Map to your system status
    $status_update = mapTransexpStatus($delivery_status);

    // Update DB
    $stmt = $conn->prepare("UPDATE order_header SET status = ?, updated_at = ? WHERE order_id = ?");
    $stmt->bind_param("ssi", $status_update, $last_update_time, $order_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $updated++;
        } else {
            $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => "No rows updated"];
        }
    } else {
        $errors[] = ["order_id" => $order_id, "waybill" => $waybill_number, "error" => $stmt->error];
    }

    $stmt->close();
}

// =======================================
// FINAL RESPONSE
// =======================================
$response = [
    "success" => true,
    "updated_orders" => $updated,
    "error_count" => count($errors),
    "errors" => $errors
];

echo json_encode($response, JSON_PRETTY_PRINT);

// Close connection
$conn->close();
?>