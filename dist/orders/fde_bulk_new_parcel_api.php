<?php
/**
 * FDE Bulk New Parcel API Handler - FIXED VERSION
 * @version 2.3
 * @date 2025
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

// API submission function
function callFdeApi($apiData) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://www.fdedomestic.com/api/parcel/new_api_v1.php",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $apiData,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['success' => false, 'message' => "Connection error: $error"];
    if ($httpCode !== 200) return ['success' => false, 'message' => "Server error: $httpCode"];
    
    $data = json_decode($response, true);
    if (!$data) return ['success' => false, 'message' => 'Invalid response from API'];
    
    $messages = [
        200 => 'Successfully insert the parcel', 201 => 'Incorrect waybill type. Only allow CRE or CCP',
        202 => 'The waybill is used', 203 => 'The waybill is not yet assigned', 204 => 'Inactive Client',
        205 => 'Invalid order id', 206 => 'Invalid weight', 207 => 'Empty or invalid parcel description',
        208 => 'Empty or invalid name', 209 => 'Invalid contact number 1', 210 => 'Invalid contact number 2',
        211 => 'Empty or invalid address', 212 => 'Empty or invalid amount', 213 => 'Invalid city',
        214 => 'Parcel insert unsuccessfully', 215 => 'Invalid or inactive client', 216 => 'Invalid API key',
        217 => 'Invalid exchange value', 218 => 'System maintain mode is activated'
    ];
    
    $status = $data['status'] ?? 999;
    return [
        'success' => $status == 200,
        'message' => $messages[$status] ?? "Unknown error (Code: $status)",
        'status_code' => $status,
        'data' => $data,
        'raw_response' => $response  // Add raw response for debugging
    ];
}

// Get parcel description and weight
function getParcelData($orderId, $conn) {
    $stmt = $conn->prepare("SELECT GROUP_CONCAT(description SEPARATOR ', ') as description_text, SUM(quantity) as total_qty FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    $desc = $result['description_text'] ?? 'General Items';
    $desc = strlen($desc) > 100 ? substr($desc, 0, 97) . '...' : $desc;
    $weight = max(0.5, min(10, ($result['total_qty'] ?? 1) * 0.5));
    
    return ['description' => $desc, 'weight' => number_format($weight, 1)];
}

// Extract tracking number from API response
function extractTrackingNumber($apiResponse) {
    $data = $apiResponse['data'] ?? [];
    
    // Try different possible field names for tracking number
    $possibleFields = [
        'tracking_number',
        'waybill',
        'waybill_no',
        'tracking_no',
        'consignment_no',
        'reference_no',
        'parcel_no',
        'order_reference'
    ];
    
    foreach ($possibleFields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            return trim($data[$field]);
        }
    }
    
    // If no tracking number found in expected fields, check if it's in the message or other places
    $message = $apiResponse['data']['message'] ?? '';
    if (preg_match('/(?:tracking|waybill|reference)[\s#:]*([A-Z0-9]+)/i', $message, $matches)) {
        return trim($matches[1]);
    }
    
    return null;
}

try {
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');
    
    // Validations
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) throw new Exception('Authentication required');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Only POST method allowed');
    if (!isset($_POST['order_ids']) || !isset($_POST['carrier_id'])) throw new Exception('Missing required parameters');
    
    $orderIds = json_decode($_POST['order_ids'], true);
    $carrierId = (int)$_POST['carrier_id'];
    $dispatchNotes = $_POST['dispatch_notes'] ?? '';
    $userId = $_SESSION['user_id'] ?? 0;
    
    if (!is_array($orderIds) || empty($orderIds)) throw new Exception('Invalid order IDs');
    
    // Get courier details
    $stmt = $conn->prepare("SELECT courier_name, api_key, client_id FROM couriers WHERE courier_id = ? AND status = 'active' AND has_api_new = 1");
    $stmt->bind_param("i", $carrierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc();
    
    if (!$courier || empty($courier['api_key']) || empty($courier['client_id'])) {
        throw new Exception('Invalid courier or missing API credentials');
    }
    
    // Get orders
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT oh.*, c.name as customer_name, c.phone as customer_phone, c.address_line1 as customer_address1, c.address_line2 as customer_address2, ct.city_name
        FROM order_header oh 
        LEFT JOIN customers c ON oh.customer_id = c.customer_id 
        LEFT JOIN city_table ct ON c.city_id = ct.city_id
        WHERE oh.order_id IN ($placeholders) AND oh.status = 'pending'
    ");
    $stmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (empty($orders)) throw new Exception('No valid pending orders found');
    
    // Process orders
    $conn->autocommit(false);
    $successCount = 0;
    $failedOrders = [];
    $processedOrders = [];
    
    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        
        try {
            $parcelData = getParcelData($orderId, $conn);
            
            // Determine amount based on pay_status
            $apiAmount = ($order['pay_status'] === 'paid') ? 0 : $order['total_amount'];
            
            $apiData = [
                'api_key' => $courier['api_key'],
                'client_id' => $courier['client_id'],
                'order_id' => $orderId,
                'parcel_weight' => $parcelData['weight'],
                'parcel_description' => $parcelData['description'],
                'recipient_name' => $order['full_name'] ?: $order['customer_name'],
                'recipient_contact_1' => $order['mobile'] ?: $order['customer_phone'],
                'recipient_contact_2' => '',
                'recipient_address' => trim(($order['address_line1'] ?? $order['customer_address1'] ?? '') . ' ' . ($order['address_line2'] ?? $order['customer_address2'] ?? '')),
                'recipient_city' => $order['city_name'] ?: '',
                'amount' => $apiAmount,
                'exchange' => '0'
            ];
            
            $result = callFdeApi($apiData);
            
            if ($result['success']) {
                // Extract tracking number using improved function
                $trackingNumber = extractTrackingNumber($result);
                
                // Log the API response for debugging if tracking number is empty
                if (empty($trackingNumber)) {
                    logAction($conn, $userId, 'api_debug', $orderId, 
                        "API Response Debug - Raw: " . substr($result['raw_response'], 0, 500));
                }
                
                // Update database - even if tracking number is empty, we still mark as dispatched
                $trackingNumberToStore = $trackingNumber ?: "FDE-" . $orderId . "-" . date('Ymd');
                
                $stmt = $conn->prepare("UPDATE order_header SET status='dispatch', courier_id=?, tracking_number=?, dispatch_note=?, updated_at=NOW() WHERE order_id=?");
                $stmt->bind_param("issi", $carrierId, $trackingNumberToStore, $dispatchNotes, $orderId);
                
                if (!$stmt->execute()) {
                    throw new Exception("Database update failed: " . $stmt->error);
                }
                
                $stmt = $conn->prepare("UPDATE order_items SET status='dispatch' WHERE order_id=?");
                $stmt->bind_param("i", $orderId);
                
                if (!$stmt->execute()) {
                    throw new Exception("Order items update failed: " . $stmt->error);
                }
                
                logAction($conn, $userId, 'api_new_dispatch', $orderId, 
                    "Order $orderId dispatched - Tracking: $trackingNumberToStore, Status: {$result['message']}");
                
                $successCount++;
                $processedOrders[] = [
                    'order_id' => $orderId, 
                    'tracking_number' => $trackingNumberToStore,
                    'api_tracking' => $trackingNumber,
                    'generated_tracking' => empty($trackingNumber)
                ];
                
            } else {
                $failedOrders[] = [
                    'order_id' => $orderId,
                    'tracking_number' => '',
                    'error' => $result['message'],
                    'status_code' => $result['status_code'] ?? null,
                    'raw_response' => substr($result['raw_response'], 0, 200)  // First 200 chars for debugging
                ];
                
                logAction($conn, $userId, 'api_new_dispatch_failed', $orderId,
                    "Order $orderId failed - Error: {$result['message']}, Code: {$result['status_code']}");
            }
            
        } catch (Exception $e) {
            $failedOrders[] = [
                'order_id' => $orderId,
                'tracking_number' => '',
                'error' => $e->getMessage()
            ];
            
            logAction($conn, $userId, 'api_new_dispatch_failed', $orderId,
                "Order $orderId exception - Error: {$e->getMessage()}");
        }
    }
    
    // Commit or rollback
    if ($successCount > 0) {
        $conn->commit();
        $trackingList = implode(', ', array_column($processedOrders, 'tracking_number'));
        $details = "Bulk dispatch: $successCount/" . count($orderIds) . " orders dispatched, Tracking: $trackingList";
        
        if (!empty($failedOrders)) {
            $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
            $details .= ". Failed: " . implode('; ', $errorList);
        }
        
        logAction($conn, $userId, 'bulk_api_new_dispatch', 0, $details);
    } else {
        $conn->rollback();
        $errorList = array_map(fn($f) => "Order {$f['order_id']}: {$f['error']}", $failedOrders);
        logAction($conn, $userId, 'bulk_api_new_dispatch_failed', 0, 
            "Bulk dispatch failed: All " . count($orderIds) . " orders failed. Errors: " . implode('; ', $errorList));
    }
    
    // Response
    $response = [
        'success' => $successCount > 0,
        'processed_count' => $successCount,
        'total_count' => count($orderIds),
        'failed_count' => count($failedOrders),
        'processed_orders' => $processedOrders
    ];
    
    if (!empty($failedOrders)) {
        $response['failed_orders'] = $failedOrders;
        $response['message'] = "Processed $successCount orders successfully, " . count($failedOrders) . " failed";
    } else {
        $response['message'] = "All $successCount orders processed successfully";
    }
    
    ob_clean();
    echo json_encode($response);
    
} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) $conn->autocommit(true);
    ob_end_flush();
}
?>