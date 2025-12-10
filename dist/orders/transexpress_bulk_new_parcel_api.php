<?php
/**
 * Transexpress Bulk New Parcel API Handler - Fixed Version
 * Version: 2.2
 * Date: 2025
 */

session_start();
header('Content-Type: application/json');
ob_start();

// Logging function
function logAction($conn, $user_id, $action, $order_id, $details) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param("isis", $user_id, $action, $order_id, $details);
        $stmt->execute();
        $stmt->close();
    }
}

// Call Transexpress bulk API
function callTransexpressBulkApi($apiData, $apiKey) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://portal.transexpress.lk/api/orders/upload/auto",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($apiData),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['success' => false, 'message' => "Connection error: $error"];
    if ($httpCode !== 200) return ['success' => false, 'message' => "Server error: $httpCode", 'raw_response' => $response];

    $data = json_decode($response, true);
    if (!$data) return ['success' => false, 'message' => 'Invalid JSON response', 'raw_response' => $response];

    return ['success' => true, 'data' => $data, 'raw_response' => $response];
}

// Extract waybills from API response
function extractTransexpressTracking($responseData) {
    $trackingNumbers = [];
    if (!empty($responseData['orders']) && is_array($responseData['orders'])) {
        foreach ($responseData['orders'] as $orderResult) {
            $orderNo = (string)$orderResult['order_no'];
            $waybill = $orderResult['waybill_id'] ?? null;
            if ($waybill) {
                $trackingNumbers[$orderNo] = $waybill;
            }
        }
    }
    return $trackingNumbers;
}

try {
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) throw new Exception('Authentication required');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Only POST method allowed');
    if (!isset($_POST['order_ids']) || !isset($_POST['carrier_id'])) throw new Exception('Missing required parameters');

    $orderIds = json_decode($_POST['order_ids'], true);
    $carrierId = (int)$_POST['carrier_id'];
    $dispatchNotes = $_POST['dispatch_notes'] ?? '';
    $userId = $_SESSION['user_id'] ?? 0;

    if (!is_array($orderIds) || empty($orderIds)) throw new Exception('Invalid order IDs');

    $stmt = $conn->prepare("SELECT courier_name, api_key FROM couriers WHERE courier_id = ? AND status='active' AND has_api_new=1");
    $stmt->bind_param("i", $carrierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc();
    if (!$courier || empty($courier['api_key'])) throw new Exception('Invalid courier or missing API credentials');

    // Get pending orders with district information
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT oh.order_id, oh.total_amount, oh.pay_status, c.name as customer_name, c.phone as customer_phone, 
               c.address_line1, c.address_line2, ct.city_id, dt.district_id
        FROM order_header oh
        LEFT JOIN customers c ON oh.customer_id = c.customer_id
        LEFT JOIN city_table ct ON c.city_id = ct.city_id
        LEFT JOIN district_table dt ON ct.district_id = dt.district_id
        WHERE oh.order_id IN ($placeholders) AND oh.status='pending'
    ");
    $stmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($orders)) throw new Exception('No valid pending orders found');

    // Prepare API payload with correct field names
    $payload = [];
    foreach ($orders as $order) {
        $apiAmount = ($order['pay_status'] === 'paid') ? 0 : $order['total_amount'];
        $cleanPhone = preg_replace('/[^0-9]/', '', $order['customer_phone']);
        
        // Ensure phone number is properly formatted
        if (strlen($cleanPhone) === 9) {
            $cleanPhone = '0' . $cleanPhone; // Add leading 0 if missing
        }
        
        $payload[] = [
            'order_id' => (string)$order['order_id'], // Convert to string as per API example
            'customer_name' => $order['customer_name'] ?? 'Customer',
            'address' => trim(($order['address_line1'] ?? '') . ' ' . ($order['address_line2'] ?? '')),
            'order_description' => 'Order #' . $order['order_id'],
            'customer_phone' => $cleanPhone,
            'customer_phone2' => '', // Optional field
            'cod_amount' => (float)$apiAmount,
            'district' => (int)($order['district_id'] ?? 1), // Add district field
            'city' => (int)($order['city_id'] ?? 1),
            'remarks' => $dispatchNotes // Changed from 'remark' to 'remarks'
        ];
    }

    // Log the payload for debugging
    error_log("Transexpress API Payload: " . json_encode($payload));

    $apiResult = callTransexpressBulkApi($payload, $courier['api_key']);
    if (!$apiResult['success']) {
        error_log("Transexpress API Error: " . $apiResult['message']);
        if (isset($apiResult['raw_response'])) {
            error_log("Raw Response: " . $apiResult['raw_response']);
        }
        throw new Exception($apiResult['message']);
    }

    $trackingNumbers = extractTransexpressTracking($apiResult['data']);

    $conn->autocommit(false);
    $successCount = 0;
    $failedOrders = [];

    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        if (!isset($trackingNumbers[(string)$orderId])) {
            // Fail order if no waybill returned
            $failedOrders[] = ['order_id' => $orderId, 'error' => 'No waybill returned from Transexpress'];
            logAction($conn, $userId, 'transexpress_bulk_new_dispatch_failed', $orderId, "No waybill returned from API");
            continue;
        }

        $tracking = $trackingNumbers[(string)$orderId];

        try {
            $stmtUpdate = $conn->prepare("UPDATE order_header SET status='dispatch', courier_id=?, tracking_number=?, dispatch_note=?, updated_at=NOW() WHERE order_id=?");
            $stmtUpdate->bind_param("issi", $carrierId, $tracking, $dispatchNotes, $orderId);
            $stmtUpdate->execute();
            $stmtUpdate->close();

            $stmtUpdateItems = $conn->prepare("UPDATE order_items SET status='dispatch' WHERE order_id=?");
            $stmtUpdateItems->bind_param("i", $orderId);
            $stmtUpdateItems->execute();
            $stmtUpdateItems->close();

            logAction($conn, $userId, 'transexpress_bulk_new_dispatch', $orderId, "Order dispatched - Tracking: $tracking");
            $successCount++;
        } catch (Exception $e) {
            $failedOrders[] = ['order_id' => $orderId, 'error' => $e->getMessage()];
            logAction($conn, $userId, 'transexpress_bulk_new_dispatch_failed', $orderId, "Error: " . $e->getMessage());
        }
    }

    if ($successCount > 0) $conn->commit(); else $conn->rollback();
    $conn->autocommit(true);

    $response = [
        'success' => $successCount > 0,
        'processed_count' => $successCount,
        'total_count' => count($orderIds),
        'failed_count' => count($failedOrders),
        'failed_orders' => $failedOrders,
        'message' => "$successCount orders dispatched successfully, " . count($failedOrders) . " failed"
    ];

    ob_clean();
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    ob_clean();
    error_log("Transexpress Bulk API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->autocommit(true);
    ob_end_flush();
}
?>