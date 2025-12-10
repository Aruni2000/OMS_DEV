<?php
/**
 * Royal Express Bulk Existing Parcel API Handler
 * @version 1.4
 * @date 2025
 * API Endpoint: https://v1.api.curfox.com/api/public/merchant/order/bulk
 */

// Start output buffering first
ob_start();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set headers
header('Content-Type: application/json');

// Error handling
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/OMS/logs/royal_express_errors.log');

// Logging function
function logAction($conn, $user_id, $action, $order_id, $details) {
    try {
        $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("isis", $user_id, $action, $order_id, $details);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Logging failed: " . $e->getMessage());
    }
}

/**
 * Call Royal Express Bulk Order API
 */
function callRoyalExpressBulkOrderApi($data, $apiKey) {
    $url = "https://v1.api.curfox.com/api/public/merchant/order/bulk";

    $headers = [
        "Accept: application/json",
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json",
        "X-tenant: royalexpress"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("RoyalExpress cURL Error: $error");
        return ['success' => false, 'message' => "Connection error: $error"];
    }

    if ($http_code === 401) {
        return ['success' => false, 'message' => "Authentication failed: Invalid API key or tenant"];
    }

    if ($http_code !== 200) {
        $errorDetail = $response ? " - " . substr($response, 0, 200) : "";
        error_log("RoyalExpress HTTP Error $http_code: $errorDetail");
        return ['success' => false, 'message' => "Server error: HTTP $http_code$errorDetail"];
    }

    // Parse response
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("RoyalExpress JSON Error: " . json_last_error_msg());
        return ['success' => false, 'message' => "Invalid JSON response"];
    }

    if (isset($data['data']) && is_array($data['data']) && !empty($data['data'][0])) {
        return [
            'success' => true,
            'tracking_number' => $data['data'][0],
            'message' => $data['message'] ?? 'Order created successfully'
        ];
    }

    if (isset($data['errors']) && is_array($data['errors'])) {
        $errors = flattenRoyalExpressErrors($data['errors']);
        return ['success' => false, 'message' => "Validation error: $errors"];
    }

    if (isset($data['message'])) {
        return ['success' => false, 'message' => $data['message']];
    }

    error_log("RoyalExpress Unknown Response: " . json_encode($data));
    return ['success' => false, 'message' => 'Unknown API response format'];
}

/**
 * Flatten nested validation errors
 */
function flattenRoyalExpressErrors($errors) {
    $flat = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($errors));
    foreach ($iterator as $error) {
        $flat[] = trim($error);
    }
    return implode(' | ', $flat);
}

/**
 * Get parcel description
 */
function getParcelData($orderId, $conn) {
    try {
        $stmt = $conn->prepare("SELECT GROUP_CONCAT(description SEPARATOR ', ') as description_text, SUM(quantity) as total_qty FROM order_items WHERE order_id = ?");
        if (!$stmt) {
            return "Order #$orderId items";
        }
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $desc = $result['description_text'] ?? 'General Items';
        $qty = $result['total_qty'] ?? 1;
        $desc = "Order Items ($qty) - " . $desc;
        $desc = strlen($desc) > 500 ? substr($desc, 0, 497) . '...' : $desc;

        return $desc;
    } catch (Exception $e) {
        return "Order #$orderId items";
    }
}

// Main execution
try {
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception('Database connection failed');
    }

    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        throw new Exception('Authentication required');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }

    if (!isset($_POST['order_ids'])) {
        throw new Exception('Missing order_ids parameter');
    }

    if (!isset($_POST['carrier_id'])) {
        throw new Exception('Missing carrier_id parameter');
    }

    $orderIds = json_decode($_POST['order_ids'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid order_ids JSON: ' . json_last_error_msg());
    }

    $carrierId = (int)$_POST['carrier_id'];
    $dispatchNotes = $_POST['dispatch_notes'] ?? '';
    $userId = $_SESSION['user_id'] ?? 0;

    if (!is_array($orderIds) || empty($orderIds)) {
        throw new Exception('order_ids must be a non-empty array');
    }

    // Fetch courier info including API key, client_id, and origin details
    $stmt = $conn->prepare("SELECT courier_name, api_key, client_id, origin_city_name, origin_state_name FROM couriers WHERE courier_id = ? AND status = 'active'");
    $stmt->bind_param("i", $carrierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$courier) {
        throw new Exception('Invalid or inactive courier (ID: ' . $carrierId . ')');
    }

    $apiKey = $courier['api_key'];
    $merchantBusinessId = $courier['client_id']; // <-- client_id used as merchant_business_id
    $originCity = $courier['origin_city_name'] ;
    $originState = $courier['origin_state_name'] ;

    // Fetch tracking numbers
    $orderCount = count($orderIds);
    $stmt = $conn->prepare("SELECT id, tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' ORDER BY id ASC LIMIT ?");
    $stmt->bind_param("ii", $carrierId, $orderCount);
    $stmt->execute();
    $tracking = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($tracking) < $orderCount) {
        throw new Exception("Insufficient tracking numbers: need $orderCount, only " . count($tracking) . " available");
    }

    // Fetch orders with city/state
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $sql = "
        SELECT oh.*, 
               c.name as customer_name, 
               c.phone as customer_phone,
               c.address_line1, 
               c.address_line2,
               ct.city_name, 
               st.name as state_name
        FROM order_header oh
        LEFT JOIN customers c ON oh.customer_id = c.customer_id
        LEFT JOIN city_table ct ON c.city_id = ct.city_id
        LEFT JOIN state_table st ON ct.state_id = st.id
        WHERE oh.order_id IN ($placeholders) 
        AND oh.status = 'pending' 
        AND ct.is_active = 1 
        AND ct.city_name IS NOT NULL 
        AND st.name IS NOT NULL
    ";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($orderIds));
    $stmt->bind_param($types, ...$orderIds);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($orders)) {
        throw new Exception('No valid pending orders found with city/state data.');
    }

    $conn->autocommit(false);
    $successCount = 0;
    $failedOrders = [];
    $processedOrders = [];

    foreach ($orders as $index => $order) {
        $waybillNumber = 'N/A';
        try {
            if (empty($order['city_name']) || empty($order['state_name'])) {
                throw new Exception("Missing city or state");
            }

            $waybillNumber = trim($tracking[$index]['tracking_id']);
            $trackingRecordId = $tracking[$index]['id'];

            $description = getParcelData($order['order_id'], $conn);
            $codAmount = (strtolower($order['pay_status']) === 'paid') ? 0 : floatval($order['total_amount']);

            $cleanPhone = preg_replace('/[^0-9]/', '', $order['mobile'] ?: $order['customer_phone'] ?: '');
            $cleanPhone2 = '';

            $fullAddress = trim(($order['address_line1'] ?? '') . ' ' . ($order['address_line2'] ?? ''));
            if (empty($fullAddress)) {
                throw new Exception("Missing customer address");
            }
            if (empty($cleanPhone)) {
                throw new Exception("Missing customer phone");
            }

            $royal_data = [
                "general_data" => [
                    "merchant_business_id" => $merchantBusinessId,
                    "origin_city_name" => $originCity,
                    "origin_state_name" => $originState
                ],
                "order_data" => [
                    [
                        "waybill_number" => $waybillNumber,
                        "order_no" => (string)$order['order_id'],
                        "customer_name" => $order['full_name'] ?: $order['customer_name'] ?: 'Customer',
                        "customer_address" => $fullAddress,
                        "customer_phone" => $cleanPhone,
                        "customer_secondary_phone" => $cleanPhone2,
                        "destination_city_name" => $order['city_name'],
                        "destination_state_name" => $order['state_name'],
                        "cod" => $codAmount,
                        "description" => $description,
                        "weight" => floatval($order['weight'] ?? 1),
                        "remark" => $dispatchNotes ?: ($order['notes'] ?? '')
                    ]
                ]
            ];

            $apiResult = callRoyalExpressBulkOrderApi($royal_data, $apiKey);

            if (!$apiResult['success']) {
                throw new Exception($apiResult['message']);
            }

            $trackingNumber = $apiResult['tracking_number'] ?? $waybillNumber;

            // Update tracking status
            $stmt = $conn->prepare("UPDATE tracking SET status = 'used', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $trackingRecordId);
            $stmt->execute();
            $stmt->close();

            // Update order header
            $stmt = $conn->prepare("UPDATE order_header SET courier_id = ?, tracking_number = ?, status = 'dispatch', dispatch_note = ?, updated_at = NOW() WHERE order_id = ?");
            $stmt->bind_param("issi", $carrierId, $trackingNumber, $dispatchNotes, $order['order_id']);
            $stmt->execute();
            $stmt->close();

            // Update order items
            $stmt = $conn->prepare("UPDATE order_items SET status = 'dispatch' WHERE order_id = ?");
            $stmt->bind_param("i", $order['order_id']);
            $stmt->execute();
            $stmt->close();

            logAction($conn, $userId, 'royalexpress_dispatch', $order['order_id'], 
                      "Order {$order['order_id']} dispatched via Royal Express - Tracking: $trackingNumber");

            $successCount++;
            $processedOrders[] = [
                'order_id' => $order['order_id'],
                'tracking_number' => $trackingNumber,
                'customer_name' => $order['full_name'] ?: $order['customer_name']
            ];

        } catch (Exception $e) {
            $failedOrders[] = [
                'order_id' => $order['order_id'],
                'tracking_number' => $waybillNumber,
                'error' => $e->getMessage()
            ];
            logAction($conn, $userId, 'royalexpress_dispatch_failed', $order['order_id'], 
                      "Order {$order['order_id']} failed - Error: {$e->getMessage()}");
        }
    }

    if ($successCount > 0) {
        $conn->commit();
    } else {
        $conn->rollback();
    }

    $response = [
        'success' => $successCount > 0,
        'processed_count' => $successCount,
        'total_count' => count($orderIds),
        'failed_count' => count($failedOrders),
        'processed_orders' => $processedOrders,
        'failed_orders' => $failedOrders,
        'message' => "Successfully dispatched $successCount of " . count($orderIds) . " orders via Royal Express"
    ];

    ob_clean();
    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Royal Express Error: " . $e->getMessage());
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => 'system_error'
    ], JSON_PRETTY_PRINT);
} finally {
    if (isset($conn)) {
        $conn->autocommit(true);
    }
    ob_end_flush();
}
?>
