<?php
// Start session management
session_start();

// Authentication check - redirect if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

// Check if required parameters are provided
if (!isset($_POST['action']) || $_POST['action'] !== 'bulk_dispatch_orders') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action'
    ]);
    exit();
}

// Get and validate parameters
$order_ids = isset($_POST['order_ids']) ? json_decode($_POST['order_ids'], true) : [];
$courier_id = isset($_POST['carrier']) ? intval($_POST['carrier']) : 0;
$dispatch_notes = isset($_POST['dispatch_notes']) ? trim($_POST['dispatch_notes']) : '';
$user_id = $_SESSION['user_id'] ?? 0;

// Validate parameters
if (empty($order_ids) || !is_array($order_ids)) {
    echo json_encode([
        'success' => false,
        'message' => 'No orders selected'
    ]);
    exit();
}

if ($courier_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid courier selected'
    ]);
    exit();
}

if ($user_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid user session'
    ]);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // First, verify all orders exist and are in pending status
    $order_ids_str = implode(',', array_map('intval', $order_ids));
    $verify_query = "SELECT order_id, status FROM order_header WHERE order_id IN ($order_ids_str)";
    $verify_result = $conn->query($verify_query);
    
    if (!$verify_result || $verify_result->num_rows !== count($order_ids)) {
        throw new Exception('Some orders not found or invalid');
    }
    
    // Check if all orders are in pending status
    $invalid_orders = [];
    while ($order = $verify_result->fetch_assoc()) {
        if ($order['status'] !== 'pending') {
            $invalid_orders[] = $order['order_id'];
        }
    }
    
    if (!empty($invalid_orders)) {
        throw new Exception('Orders must be in pending status: ' . implode(', ', $invalid_orders));
    }
    
    // Get unused tracking numbers for the selected courier - ORDER BY tracking_id ASC for numerical order
    $tracking_query = "SELECT tracking_id FROM tracking 
                       WHERE courier_id = ? AND status = 'unused' 
                       ORDER BY CAST(tracking_id AS UNSIGNED) ASC 
                       LIMIT ?";
    
    $tracking_stmt = $conn->prepare($tracking_query);
    $tracking_count = count($order_ids);
    $tracking_stmt->bind_param("ii", $courier_id, $tracking_count);
    $tracking_stmt->execute();
    $tracking_result = $tracking_stmt->get_result();
    
    $tracking_numbers = [];
    while ($row = $tracking_result->fetch_assoc()) {
        $tracking_numbers[] = $row['tracking_id'];
    }
    
    // Check if we have enough tracking numbers
    if (count($tracking_numbers) < count($order_ids)) {
        throw new Exception('Not enough tracking numbers available for selected courier');
    }
    
    // Update orders with dispatch information
    $update_order_query = "UPDATE order_header SET 
                           status = 'dispatch', 
                           courier_id = ?, 
                           tracking_number = ?, 
                           dispatch_note = ?,
                           updated_at = NOW()
                           WHERE order_id = ?";
    
    $update_order_stmt = $conn->prepare($update_order_query);
    
    // Update order items status to dispatch
    $update_items_query = "UPDATE order_items SET 
                           status = 'dispatch',
                           updated_at = NOW()
                           WHERE order_id = ?";
    
    $update_items_stmt = $conn->prepare($update_items_query);
    
    // Mark tracking numbers as used
    $update_tracking_query = "UPDATE tracking SET 
                              status = 'used', 
                              updated_at = NOW() 
                              WHERE tracking_id = ?";
    
    $update_tracking_stmt = $conn->prepare($update_tracking_query);
    
    // Process each order
    $dispatched_orders = [];
    $assigned_tracking = [];
    
    foreach ($order_ids as $index => $order_id) {
        $tracking_number = $tracking_numbers[$index];
        
        // Update order header
        $update_order_stmt->bind_param("issi", $courier_id, $tracking_number, $dispatch_notes, $order_id);
        if (!$update_order_stmt->execute()) {
            throw new Exception('Failed to update order: ' . $order_id);
        }
        
        // Update order items status
        $update_items_stmt->bind_param("i", $order_id);
        if (!$update_items_stmt->execute()) {
            throw new Exception('Failed to update order items for order: ' . $order_id);
        }
        
        // Mark tracking number as used
        $update_tracking_stmt->bind_param("s", $tracking_number);
        if (!$update_tracking_stmt->execute()) {
            throw new Exception('Failed to update tracking number: ' . $tracking_number);
        }
        
        $dispatched_orders[] = $order_id;
        $assigned_tracking[] = $tracking_number;
        
        // Log user action
        $log_query = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                      VALUES (?, 'bulk_dispatch', ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_query);
        $log_details = "Bulk dispatched order with tracking: $tracking_number, courier ID: $courier_id";
        $log_stmt->bind_param("iis", $user_id, $order_id, $log_details);
        $log_stmt->execute();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Orders dispatched successfully',
        'dispatched_count' => count($dispatched_orders),
        'dispatched_orders' => $dispatched_orders,
        'tracking_numbers' => $assigned_tracking,
        'courier_id' => $courier_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    
} finally {
    // Close prepared statements
    if (isset($tracking_stmt)) {
        $tracking_stmt->close();
    }
    if (isset($update_order_stmt)) {
        $update_order_stmt->close();
    }
    if (isset($update_items_stmt)) {
        $update_items_stmt->close();
    }
    if (isset($update_tracking_stmt)) {
        $update_tracking_stmt->close();
    }
    if (isset($log_stmt)) {
        $log_stmt->close();
    }
}
?>