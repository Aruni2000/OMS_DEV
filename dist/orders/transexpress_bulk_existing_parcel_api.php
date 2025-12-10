<?php
/**
 * Transexpress Bulk Existing Parcel API Handler
 * @version 2.0
 * @date 2025
 * API Endpoint: https://portal.transexpress.lk/api/orders/upload/single-manual
 */

session_start();
header('Content-Type: application/json');
ob_start();

// Disable PHP HTML error output
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Logging function
function logAction($conn, $user_id, $action, $order_id, $details) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param("isis", $user_id, $action, $order_id, $details);
        $stmt->execute();
        $stmt->close();
    }
}

// Call Transexpress Single Order API (Existing Parcel)
function callTransexpressExistingApi($orderData, $apiKey) {
    $apiUrl = "https://portal.transexpress.lk/api/orders/upload/single-manual";
    
    // Validate required fields
    $required = ['waybill_id', 'customer_name', 'address', 'phone_no', 'cod', 'city_id'];
    foreach ($required as $field) {
        if (empty($orderData[$field]) && $orderData[$field] !== '0' && $orderData[$field] !== 0) {
            return ['success' => false, 'message' => "Missing required field: $field"];
        }
    }
    
    // Validate waybill format (must be exactly 8 characters)
    if (strlen($orderData['waybill_id']) !== 8) {
        return ['success' => false, 'message' => "Invalid waybill format. Must be 8 characters."];
    }
    
    // Validate phone number
    $cleanPhone = preg_replace('/[^0-9]/', '', $orderData['phone_no']);
    if (strlen($cleanPhone) < 9 || strlen($cleanPhone) > 10) {
        return ['success' => false, 'message' => "Invalid phone number. Must be 9-10 digits."];
    }
    
    // Prepare payload
    $payload = [
        'waybill_id' => $orderData['waybill_id'],
        'customer_name' => $orderData['customer_name'],
        'address' => $orderData['address'],
        'phone_no' => $cleanPhone,
        'cod' => floatval($orderData['cod']),
        'city_id' => intval($orderData['city_id'])
    ];
    
    // Add optional fields
    if (!empty($orderData['order_no'])) $payload['order_no'] = $orderData['order_no'];
    if (!empty($orderData['description'])) $payload['description'] = $orderData['description'];
    if (!empty($orderData['phone_no2'])) $payload['phone_no2'] = preg_replace('/[^0-9]/', '', $orderData['phone_no2']);
    if (!empty($orderData['district_id'])) $payload['district_id'] = intval($orderData['district_id']);
    if (!empty($orderData['note'])) $payload['note'] = $orderData['note'];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Accept: application/json",
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("TransExpress cURL Error: $error");
        return ['success' => false, 'message' => "Connection error: $error"];
    }
    
    if ($httpCode === 401) {
        return ['success' => false, 'message' => "Authentication failed: Invalid API key"];
    }
    
    if ($httpCode !== 200) {
        $errorDetail = $response ? " - " . substr($response, 0, 200) : "";
        error_log("TransExpress HTTP Error $httpCode: $errorDetail");
        return ['success' => false, 'message' => "Server error: HTTP $httpCode$errorDetail"];
    }

    // Parse response
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("TransExpress JSON Error: " . json_last_error_msg());
        return ['success' => false, 'message' => "Invalid JSON response"];
    }
    
    // Check success patterns
    // Pattern 1: {"success": "Record successfully added", "order": {...}}
    if (isset($data['success']) && is_string($data['success']) && 
        stripos($data['success'], 'success') !== false && isset($data['order'])) {
        return [
            'success' => true,
            'waybill_id' => $data['order']['waybill_id'] ?? $orderData['waybill_id'],
            'order_id' => $data['order']['order_no'] ?? $data['order']['id'] ?? null,
            'message' => $data['success']
        ];
    }
    
    // Pattern 2: {"success": true, ...}
    if (isset($data['success']) && $data['success'] === true) {
        return [
            'success' => true,
            'waybill_id' => $data['waybill_id'] ?? $data['data']['waybill_id'] ?? $orderData['waybill_id'],
            'message' => $data['message'] ?? 'Order processed successfully'
        ];
    }
    
    // Pattern 3: {"status": "success", ...}
    if (isset($data['status']) && $data['status'] === 'success') {
        return [
            'success' => true,
            'waybill_id' => $data['data']['waybill_id'] ?? $data['waybill_id'] ?? $orderData['waybill_id'],
            'message' => $data['message'] ?? 'Order processed successfully'
        ];
    }
    
    // Error patterns
    if (isset($data['success']) && ($data['success'] === false || 
        (is_string($data['success']) && stripos($data['success'], 'error') !== false))) {
        $errorMsg = $data['error'] ?? $data['message'] ?? $data['success'];
        return ['success' => false, 'message' => $errorMsg];
    }
    
    if (isset($data['status']) && $data['status'] === 'error') {
        return ['success' => false, 'message' => $data['message'] ?? $data['error'] ?? 'API Error'];
    }
    
    if (isset($data['error'])) {
        return ['success' => false, 'message' => $data['error']];
    }
    
    error_log("TransExpress Unknown Response: " . json_encode($data));
    return ['success' => false, 'message' => 'Unknown API response format'];
}

// Get parcel description
function getParcelData($orderId, $conn) {
    $stmt = $conn->prepare("SELECT GROUP_CONCAT(description SEPARATOR ', ') as description_text, SUM(quantity) as total_qty FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    $desc = $result['description_text'] ?? 'General Items';
    $qty = $result['total_qty'] ?? 1;
    $desc = "Order Items ($qty) - " . $desc;
    $desc = strlen($desc) > 500 ? substr($desc, 0, 497) . '...' : $desc;

    return $desc;
}

// Format waybill to 8 digits
function formatWaybillId($rawWaybill) {
    $numericPart = preg_replace('/[^0-9]/', '', $rawWaybill);
    if (strlen($numericPart) >= 8) {
        return substr($numericPart, -8);
    }
    return str_pad($numericPart, 8, '0', STR_PAD_LEFT);
}

try {
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

    // Validate request
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        throw new Exception('Authentication required');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }
    if (!isset($_POST['order_ids']) || !isset($_POST['carrier_id'])) {
        throw new Exception('Missing required parameters');
    }

    $orderIds = json_decode($_POST['order_ids'], true);
    $carrierId = (int)$_POST['carrier_id'];
    $dispatchNotes = $_POST['dispatch_notes'] ?? '';
    $userId = $_SESSION['user_id'] ?? 0;

    if (!is_array($orderIds) || empty($orderIds)) {
        throw new Exception('Invalid order IDs');
    }

    // Get courier details
    $stmt = $conn->prepare("SELECT courier_name, api_key FROM couriers WHERE courier_id = ? AND status = 'active' AND has_api_existing = 1");
    $stmt->bind_param("i", $carrierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc();
    if (!$courier || empty($courier['api_key'])) {
        throw new Exception('Invalid courier or missing API credentials');
    }

    // Get tracking numbers
    $orderCount = count($orderIds);
    $stmt = $conn->prepare("SELECT id, tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' ORDER BY created_at ASC LIMIT ?");
    $stmt->bind_param("ii", $carrierId, $orderCount);
    $stmt->execute();
    $tracking = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (count($tracking) < $orderCount) {
        throw new Exception("Need $orderCount tracking numbers, only " . count($tracking) . " available");
    }

    // Get orders with city validation
    $placeholders = str_repeat('?,', count($orderIds)-1) . '?';
    $stmt = $conn->prepare("
        SELECT oh.*, c.name as customer_name, c.phone as customer_phone,
               c.address_line1 as customer_address1, c.address_line2 as customer_address2,
               ct.city_id, ct.city_name
        FROM order_header oh
        LEFT JOIN customers c ON oh.customer_id = c.customer_id
        LEFT JOIN city_table ct ON c.city_id = ct.city_id
        WHERE oh.order_id IN ($placeholders) AND oh.status = 'pending' AND ct.is_active = 1
    ");
    $stmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (empty($orders)) {
        throw new Exception('No valid pending orders found with active cities');
    }

    // Process orders
    $conn->autocommit(false);
    $successCount = 0;
    $failedOrders = [];
    $processedOrders = [];

    foreach ($orders as $index => $order) {
        try {
            // Validate city
            if (empty($order['city_id']) || empty($order['city_name'])) {
                throw new Exception("Invalid or missing city for order {$order['order_id']}");
            }
            
            // Format waybill
            $rawWaybill = $tracking[$index]['tracking_id'];
            $waybillId = formatWaybillId($rawWaybill);
            $trackingRecordId = $tracking[$index]['id'];
            
            // Prepare API data
            $description = getParcelData($order['order_id'], $conn);
            $codAmount = ($order['pay_status'] === 'paid') ? 0 : $order['total_amount'];
            $cleanPhone = preg_replace('/[^0-9]/', '', $order['mobile'] ?: $order['customer_phone']);
            $fullAddress = trim(($order['address_line1'] ?? $order['customer_address1'] ?? '') . ' ' . 
                               ($order['address_line2'] ?? $order['customer_address2'] ?? ''));
            
            $apiData = [
                'waybill_id' => $waybillId,
                'order_no' => (string)$order['order_id'],
                'customer_name' => $order['full_name'] ?: $order['customer_name'],
                'address' => $fullAddress,
                'description' => $description,
                'phone_no' => $cleanPhone,
                'phone_no2' => !empty($order['mobile2']) ? preg_replace('/[^0-9]/', '', $order['mobile2']) : '',
                'cod' => $codAmount,
                'city_id' => $order['city_id'],
                'note' => $dispatchNotes
            ];
            
            // Call API
            $apiResult = callTransexpressExistingApi($apiData, $courier['api_key']);
            
            if (!$apiResult['success']) {
                throw new Exception($apiResult['message']);
            }
            
            $trackingNumber = $apiResult['waybill_id'] ?? $waybillId;
            
            // Update tracking status
            $stmt = $conn->prepare("UPDATE tracking SET status = 'used', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $trackingRecordId);
            $stmt->execute();
            
            // Update order header
            $stmt = $conn->prepare("UPDATE order_header SET courier_id = ?, tracking_number = ?, status = 'dispatch', dispatch_note = ?, updated_at = NOW() WHERE order_id = ?");
            $stmt->bind_param("issi", $carrierId, $trackingNumber, $dispatchNotes, $order['order_id']);
            $stmt->execute();
            
            // Update order items
            $stmt = $conn->prepare("UPDATE order_items SET status = 'dispatch' WHERE order_id = ?");
            $stmt->bind_param("i", $order['order_id']);
            $stmt->execute();
            
            // Log success
            logAction($conn, $userId, 'transexpress_dispatch', $order['order_id'], 
                     "Order {$order['order_id']} dispatched via TransExpress - Tracking: $trackingNumber");
            
            $successCount++;
            $processedOrders[] = [
                'order_id' => $order['order_id'],
                'tracking_number' => $trackingNumber,
                'customer_name' => $order['full_name'] ?: $order['customer_name']
            ];
            
        } catch (Exception $e) {
            $failedOrders[] = [
                'order_id' => $order['order_id'],
                'tracking_number' => $rawWaybill ?? 'N/A',
                'error' => $e->getMessage()
            ];
            logAction($conn, $userId, 'transexpress_dispatch_failed', $order['order_id'], 
                     "Order {$order['order_id']} failed - Error: {$e->getMessage()}");
        }
    }

    if ($successCount > 0) {
        $conn->commit();
    } else {
        $conn->rollback();
    }

    // Response
    $response = [
        'success' => $successCount > 0,
        'processed_count' => $successCount,
        'total_count' => count($orderIds),
        'failed_count' => count($failedOrders),
        'processed_orders' => $processedOrders,
        'failed_orders' => $failedOrders,
        'message' => "Successfully dispatched $successCount of " . count($orderIds) . " orders"
    ];

    ob_clean();
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->autocommit(true);
    ob_end_flush();
}
?>