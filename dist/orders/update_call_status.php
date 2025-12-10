<?php
/**
 * UPDATE CALL STATUS HANDLER
 * Handles AJAX requests to update call_log status and related fields
 * File: update_call_status.php
 */

// Start session and check authentication
session_start();

// Set content type for JSON response
header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

// Check if action is correct
if (!isset($_POST['action']) || $_POST['action'] !== 'update_call_status') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action'
    ]);
    exit();
}

try {
    // Get and validate input parameters
    $order_id = isset($_POST['order_id']) ? trim($_POST['order_id']) : '';
    $call_log = isset($_POST['call_log']) ? intval($_POST['call_log']) : null;
    $answer_reason = isset($_POST['answer_reason']) ? trim($_POST['answer_reason']) : '';
    
    // Input validation
    if (empty($order_id)) {
        throw new Exception('Order ID is required');
    }
    
    if ($call_log === null || ($call_log !== 0 && $call_log !== 1)) {
        throw new Exception('Invalid call log status');
    }
    
    if (empty($answer_reason)) {
        throw new Exception('Call notes/reason is required');
    }
    
    if (strlen($answer_reason) < 5) {
        throw new Exception('Call notes must be at least 5 characters long');
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
        // Check if order exists and get current status
        $checkSql = "SELECT order_id, call_log, status FROM order_header WHERE order_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        
        if (!$checkStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $checkStmt->bind_param("s", $order_id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Order not found');
        }
        
        $orderData = $result->fetch_assoc();
        $checkStmt->close();
        
        // Determine which field to update based on call_log value
        $updateSql = "";
        $params = [];
        $types = "";
        
        if ($call_log == 1) {
            // Marking as ANSWERED - update answer_reason field
            $updateSql = "UPDATE order_header SET 
                          call_log = ?, 
                          answer_reason = ?, 
                          no_answer_reason = NULL,
                          updated_at = CURRENT_TIMESTAMP 
                          WHERE order_id = ?";
            $params = [$call_log, $answer_reason, $order_id];
            $types = "iss";
            $action_type = "call_answered";
        } else {
            // Marking as NO ANSWER - update no_answer_reason field
            $updateSql = "UPDATE order_header SET 
                          call_log = ?, 
                          no_answer_reason = ?, 
                          answer_reason = NULL,
                          updated_at = CURRENT_TIMESTAMP 
                          WHERE order_id = ?";
            $params = [$call_log, $answer_reason, $order_id];
            $types = "iss";
            $action_type = "call_no_answer";
        }
        
        // Prepare and execute update statement
        $updateStmt = $conn->prepare($updateSql);
        
        if (!$updateStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        // Bind parameters dynamically
        $updateStmt->bind_param($types, ...$params);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update order: ' . $updateStmt->error);
        }
        
        // Check if any rows were affected
        if ($updateStmt->affected_rows === 0) {
            throw new Exception('No changes made to the order');
        }
        
        $updateStmt->close();
        
        // Get current user ID from session
        $currentUserId = null;
        if (isset($_SESSION['user_id'])) {
            $currentUserId = $_SESSION['user_id'];
        } elseif (isset($_SESSION['id'])) {
            $currentUserId = $_SESSION['id'];
        } else {
            $currentUserId = 1; // Default fallback
        }
        
        // Simple log message format with actual notes
        if ($call_log == 1) {
            $log_message = "Call answered order({$order_id}) - {$answer_reason}";
        } else {
            $log_message = "Call no answer order({$order_id}) - {$answer_reason}";
        }
        
        // Insert user log entry with simple format
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                   VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            throw new Exception('Failed to prepare log statement: ' . $conn->error);
        }
        
        $logStmt->bind_param("isss", $currentUserId, $action_type, $order_id, $log_message);
        
        if (!$logStmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $logStmt->error);
        }
        
        $logId = $conn->insert_id;
        $logStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Call status updated successfully',
            'data' => [
                'order_id' => $order_id,
                'call_log' => $call_log,
                'action_type' => $action_type,
                'reason' => $answer_reason,
                'log_id' => $logId
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Return error response
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    // Close database connection
    if (isset($conn)) {
        $conn->close();
    }
}
?>