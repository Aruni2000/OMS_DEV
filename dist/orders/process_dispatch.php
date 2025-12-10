<?php
/**
 * Process order dispatch
 * Updates order status to 'dispatch', assigns tracking number, marks tracking as used,
 * updates order items status, and logs user action
 */

// Start session and check authentication
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    
    // Get POST parameters
    $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
    $carrier_id = isset($_POST['carrier']) ? (int)$_POST['carrier'] : 0;
    $dispatch_notes = isset($_POST['dispatch_notes']) ? trim($_POST['dispatch_notes']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    
    // Validate required parameters
    if ($action !== 'dispatch_order') {
        echo json_encode(['success' => false, 'message' => 'Invalid action specified']);
        exit();
    }
    
    if ($order_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID provided']);
        exit();
    }
    
    if ($carrier_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid courier service']);
        exit();
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
        // Verify order exists and is pending or done (both can be dispatched)
        $order_check_sql = "SELECT order_id, status, customer_id, total_amount FROM order_header 
                           WHERE order_id = ? AND status IN ('pending', 'done')";
        $order_stmt = $conn->prepare($order_check_sql);
        $order_stmt->bind_param("i", $order_id);
        $order_stmt->execute();
        $order_result = $order_stmt->get_result();
        
        if ($order_result->num_rows === 0) {
            throw new Exception('Order not found or not available for dispatch (must be pending or done status)');
        }
        
        $order_data = $order_result->fetch_assoc();
        $customer_id = $order_data['customer_id'];
        $current_status = $order_data['status'];
        
        // Verify courier exists and is active
        $courier_check_sql = "SELECT courier_id, courier_name FROM couriers 
                             WHERE courier_id = ? AND status = 'active'";
        $courier_stmt = $conn->prepare($courier_check_sql);
        $courier_stmt->bind_param("i", $carrier_id);
        $courier_stmt->execute();
        $courier_result = $courier_stmt->get_result();
        
        if ($courier_result->num_rows === 0) {
            throw new Exception('Courier not found or inactive');
        }
        
        $courier_data = $courier_result->fetch_assoc();
        
        // Get next available tracking number for this courier
        $tracking_sql = "SELECT tracking_id FROM tracking 
                        WHERE courier_id = ? AND status = 'unused' 
                        ORDER BY created_at ASC 
                        LIMIT 1 FOR UPDATE";
        $tracking_stmt = $conn->prepare($tracking_sql);
        $tracking_stmt->bind_param("i", $carrier_id);
        $tracking_stmt->execute();
        $tracking_result = $tracking_stmt->get_result();
        
        if ($tracking_result->num_rows === 0) {
            throw new Exception('No unused tracking numbers available for ' . $courier_data['courier_name']);
        }
        
        $tracking_data = $tracking_result->fetch_assoc();
        $tracking_number = $tracking_data['tracking_id'];
        
        // Update tracking number status to 'used'
        $update_tracking_sql = "UPDATE tracking SET status = 'used', updated_at = CURRENT_TIMESTAMP 
                               WHERE tracking_id = ? AND courier_id = ? AND status = 'unused'";
        $update_tracking_stmt = $conn->prepare($update_tracking_sql);
        $update_tracking_stmt->bind_param("si", $tracking_number, $carrier_id);
        
        if (!$update_tracking_stmt->execute()) {
            throw new Exception('Failed to update tracking number status');
        }
        
        if ($update_tracking_stmt->affected_rows === 0) {
            throw new Exception('Tracking number was already used by another process');
        }
        
        // Update order status to 'dispatch' and set tracking information
        // Allow dispatch from both 'pending' and 'done' status
        $update_order_sql = "UPDATE order_header SET 
                            status = 'dispatch',
                            courier_id = ?,
                            tracking_number = ?,
                            dispatch_note = ?,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ? AND status IN ('pending', 'done')";
        $update_order_stmt = $conn->prepare($update_order_sql);
        $update_order_stmt->bind_param("issi", $carrier_id, $tracking_number, $dispatch_notes, $order_id);
        
        if (!$update_order_stmt->execute()) {
            throw new Exception('Failed to update order status');
        }
        
        if ($update_order_stmt->affected_rows === 0) {
            throw new Exception('Order was already processed by another user or status changed');
        }
        
        // Update order_items status to 'dispatch'
        // Allow updating items from both 'pending' and 'done' status
        $update_items_sql = "UPDATE order_items SET 
                            status = 'dispatch',
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ? AND status IN ('pending', 'done')";
        $update_items_stmt = $conn->prepare($update_items_sql);
        $update_items_stmt->bind_param("i", $order_id);
        
        if (!$update_items_stmt->execute()) {
            throw new Exception('Failed to update order items status');
        }
        
        // Get the count of updated items for logging
        $items_updated = $update_items_stmt->affected_rows;
        
        // Get user ID for logging
        $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
        
        // Log message format indicating tracking is system-assigned only
        $log_message = "Add a dispatch unpaid order({$order_id}) with system tracking({$tracking_number})";
        
        // Minimal log details to avoid redundancy
        $log_details = $log_message;
        
        $user_log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                        VALUES (?, 'order_dispatch', ?, ?, NOW())";
        $user_log_stmt = $conn->prepare($user_log_sql);
        $user_log_stmt->bind_param("iis", $user_id, $order_id, $log_details);
        
        if (!$user_log_stmt->execute()) {
            throw new Exception('Failed to log user action');
        }
        
        // Commit transaction
        $conn->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Order dispatched successfully',
            'order_id' => $order_id,
            'previous_status' => $current_status,
            'tracking_number' => $tracking_number,
            'courier_name' => $courier_data['courier_name'],
            'dispatch_notes' => $dispatch_notes,
            'items_updated' => $items_updated
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e; // Re-throw to be caught by outer try-catch
    }
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Error in process_dispatch.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $e->getMessage() // Remove this in production
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>