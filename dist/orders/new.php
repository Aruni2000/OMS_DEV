<?php
/**
 * FDE Bulk New Parcel API
 */

session_start();
header('Content-Type: application/json');
ob_start();

/* =========================
   Helper: Read JSON + POST
========================= */
$rawInput = json_decode(file_get_contents("php://input"), true);
if (is_array($rawInput)) {
    $_POST = array_merge($_POST, $rawInput);
}

/* =========================
   Logging Function
========================= */
function logAction($conn, $userId, $action, $orderId, $details)
{
    $stmt = $conn->prepare("
        INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    if ($stmt) {
        $stmt->bind_param("isis", $userId, $action, $orderId, $details);
        $stmt->execute();
        $stmt->close();
    }
}

/* =========================
   Normalize Phone
========================= */
function normalizePhone($phone)
{
    $phone = preg_replace('/\D+/', '', $phone);
    return substr($phone, 0, 12);
}

function normalizeCity($city)
{
    return trim(ucwords(strtolower($city)));
}

/* =========================
   Call FDE API (with retry)
========================= */
function callFdeApi(array $apiData, int $retry = 1): array
{
    $url = "https://www.fdedomestic.com/api/parcel/new_api_v1.php";

    for ($i = 0; $i <= $retry; $i++) {

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $apiData,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if ($i === $retry) {
                return ['success' => false, 'message' => "Connection error: $error"];
            }
            sleep(1);
            continue;
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'message' => "HTTP Error: $httpCode"];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['status'])) {
            return ['success' => false, 'message' => 'Invalid API response', 'raw' => $response];
        }

        $messages = [
            200 => 'Successful insert',
            201 => 'Inactive Client',
            202 => 'Invalid order id',
            203 => 'Invalid weight',
            204 => 'Empty or invalid parcel description',
            205 => 'Empty or invalid name',
            206 => 'Contact number 1 is not valid',
            207 => 'Contact number 2 is not valid',
            208 => 'Empty or invalid address',
            209 => 'Invalid City',
            210 => 'Unsuccessful insert, try again',
            211 => 'Invalid API key',
            212 => 'Invalid or inactive client',
            213 => 'Invalid exchange value',
            214 => 'System maintain mode is activated'
        ];

        $status = (int)$data['status'];

        return [
            'success'      => $status === 200,
            'status_code'  => $status,
            'message'      => $messages[$status] ?? "Unknown error ($status)",
            'data'         => $data,
            'raw_response' => $response
        ];
    }
}

/* =========================
   Parcel Data
========================= */
function getParcelData($orderId, $conn)
{
    $stmt = $conn->prepare("
        SELECT GROUP_CONCAT(description SEPARATOR ', ') AS description_text,
               SUM(quantity) AS total_qty
        FROM order_items WHERE order_id = ?
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $desc = $row['description_text'] ?: 'General Items';
    $desc = substr($desc, 0, 100);
    $weight = max(0.5, min(10, ($row['total_qty'] ?? 1) * 0.5));

    return [
        'description' => $desc,
        'weight' => number_format($weight, 1)
    ];
}

/* =========================
   Extract Waybill
========================= */
function extractWaybill($result)
{
    return $result['data']['waybill_no'] ?? null;
}

/* =========================
   MAIN
========================= */
try {

    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        throw new Exception('Authentication required');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }

    if (empty($_POST['order_ids']) || empty($_POST['carrier_id'])) {
        throw new Exception('Missing required parameters');
    }

    $orderIds = json_decode($_POST['order_ids'], true);
    if (!is_array($orderIds) || empty($orderIds)) {
        throw new Exception('Invalid order IDs');
    }

    $carrierId = (int)$_POST['carrier_id'];
    $dispatchNotes = $_POST['dispatch_notes'] ?? '';
    $userId = $_SESSION['user_id'] ?? 0;

    /* Courier */
    $stmt = $conn->prepare("
        SELECT api_key, client_id 
        FROM couriers 
        WHERE courier_id = ? AND status = 'active' AND has_api_new = 1
    ");
    $stmt->bind_param("i", $carrierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc();

    if (!$courier) {
        throw new Exception('Invalid courier');
    }

    /* Orders */
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types = str_repeat('i', count($orderIds));

    $stmt = $conn->prepare("
        SELECT oh.*, c.name as customer_name, c.phone as customer_phone, c.address_line1 as customer_address1, c.address_line2 as customer_address2, ct.city_name
        FROM order_header oh 
        LEFT JOIN customers c ON oh.customer_id = c.customer_id 
        LEFT JOIN city_table ct ON oh.city_id = ct.city_id
        WHERE oh.order_id IN ($placeholders) AND oh.status = 'pending'
    ");
    $stmt->bind_param($types, ...$orderIds);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (!$orders) {
        throw new Exception('No pending orders found');
    }

    $conn->autocommit(false);

    $success = 0;
    $processed = [];
    $failed = [];

    foreach ($orders as $order) {

        $orderId = $order['order_id'];

        try {

            $parcel = getParcelData($orderId, $conn);

            $apiData = [
                'api_key' => $courier['api_key'],
                'client_id' => $courier['client_id'],
                'order_id' => $orderId,
                'parcel_weight' => $parcel['weight'],
                'parcel_description' => $parcel['description'],
                'recipient_name' => $order['full_name'] ?: $order['customer_name'],
                'recipient_contact_1' => $order['mobile'] ?: $order['customer_phone'],
                'recipient_contact_2' => '',
                'recipient_address' => trim(($order['address_line1'] ?? $order['customer_address1'] ?? '') . ' ' . ($order['address_line2'] ?? $order['customer_address2'] ?? '')),
                'recipient_city' => $order['city_name'] ?: '',
                'amount' => $apiAmount,
                'exchange' => '0'
            ];
            logAction($conn, $userId, 'fde_api_payload', $orderId, json_encode($apiData));

            $result = callFdeApi($apiData, 1);

            if (!$result['success']) {
                throw new Exception($result['message']);
            }

            $waybill = extractWaybill($result) ?: "FDE-$orderId-" . date('Ymd');

            $stmt = $conn->prepare("
                UPDATE order_header
                SET status='dispatch', courier_id=?, tracking_number=?, dispatch_note=?, updated_at=NOW()
                WHERE order_id=?
            ");
            $stmt->bind_param("issi", $carrierId, $waybill, $dispatchNotes, $orderId);
            $stmt->execute();

            $stmt = $conn->prepare("UPDATE order_items SET status='dispatch' WHERE order_id=?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();

            logAction($conn, $userId, 'fde_dispatch_success', $orderId, "Waybill: $waybill");

            $processed[] = ['order_id' => $orderId, 'waybill' => $waybill];
            $success++;

        } catch (Exception $e) {

            $failed[] = ['order_id' => $orderId, 'error' => $e->getMessage()];
            logAction($conn, $userId, 'fde_dispatch_failed', $orderId, $e->getMessage());
        }
    }

    if ($success > 0) {
        $conn->commit();
    } else {
        $conn->rollback();
    }

    ob_clean();
    echo json_encode([
        'success' => $success > 0,
        'processed_count' => $success,
        'failed_count' => count($failed),
        'processed_orders' => $processed,
        'failed_orders' => $failed
    ]);

} catch (Exception $e) {

    if (isset($conn)) {
        $conn->rollback();
    }

    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

} finally {
    if (isset($conn)) {
        $conn->autocommit(true);
    }
    ob_end_flush();
}
?>
