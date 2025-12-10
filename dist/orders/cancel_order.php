<?php
/**
 * Cancel Order Processing Script
 * Updates order status, order items, and logs user action
 */

session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

try {
    // Check if POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // Get parameters
    $order_id = $_POST['order_id'] ?? '';
    $cancellation_reason = $_POST['cancellation_reason'] ?? '';
    
    // Basic validation
    if (empty($order_id)) {
        throw new Exception('Order ID is required');
    }
    
    if (empty($cancellation_reason)) {
        throw new Exception('Cancellation reason is required');
    }
    
    if (strlen($cancellation_reason) < 10) {
        throw new Exception('Cancellation reason must be at least 10 characters');
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
       // Check if order exists and can be cancelled
$check_sql = "SELECT order_id, status, customer_id, total_amount 
              FROM order_header 
              WHERE order_id = ? AND interface IN ('individual', 'leads')";

        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("s", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Order not found');
        }
        
        $order = $result->fetch_assoc();
        $customer_id = $order['customer_id'];
        $total_amount = $order['total_amount'];
        $previous_status = $order['status'];
        
        if ($order['status'] === 'cancel') {
            throw new Exception('Order is already cancelled');
        }
        
        if ($order['status'] === 'done') {
            throw new Exception('Cannot cancel completed orders');
        }
        
        $stmt->close();
        
        // Update order header to cancelled
        $update_order_sql = "UPDATE order_header SET 
                            status = 'cancel', 
                            cancellation_reason = ?,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ?";
        $order_stmt = $conn->prepare($update_order_sql);
        $order_stmt->bind_param("ss", $cancellation_reason, $order_id);
        
        if (!$order_stmt->execute()) {
            throw new Exception('Failed to update order: ' . $order_stmt->error);
        }
        
        if ($order_stmt->affected_rows === 0) {
            throw new Exception('No rows updated in order header');
        }
        
        $order_stmt->close();
        
        // Update order_items status to 'canceled'
        $update_items_sql = "UPDATE order_items SET 
                            status = 'canceled',
                            updated_at = CURRENT_TIMESTAMP
                            WHERE order_id = ? AND status != 'canceled'";
        $items_stmt = $conn->prepare($update_items_sql);
        $items_stmt->bind_param("s", $order_id);
        
        if (!$items_stmt->execute()) {
            throw new Exception('Failed to update order items: ' . $items_stmt->error);
        }
        
        // Get the count of updated items for logging
        $items_cancelled = $items_stmt->affected_rows;
        $items_stmt->close();
        
        // Get user ID for logging
        $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
        
        // Create simple log description
        $log_description = $previous_status . " order(" . $order_id . ") cancelled | reason: " . substr($cancellation_reason, 0, 30);
        if (strlen($cancellation_reason) > 30) {
            $log_description .= "...";
        }
        
        // Log user action in user_logs table
        $user_log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                        VALUES (?, 'order_cancel', ?, ?, NOW())";
        $log_stmt = $conn->prepare($user_log_sql);
        $log_stmt->bind_param("iis", $user_id, $order_id, $log_description);
        
        if (!$log_stmt->execute()) {
            throw new Exception('Failed to log user action: ' . $log_stmt->error);
        }
        
        $log_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'order_id' => $order_id,
            'items_cancelled' => $items_cancelled,
            'cancellation_reason' => $cancellation_reason
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e; // Re-throw to be caught by outer try-catch
    }
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Error in cancel_order.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>