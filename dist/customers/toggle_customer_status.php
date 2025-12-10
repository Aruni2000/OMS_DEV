<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if user_id is available in session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User ID not found in session']);
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!isset($input['customer_id']) || !isset($input['new_status'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }
    
    $customer_id = (int)$input['customer_id'];
    $new_status = trim($input['new_status']);
    $current_user_id = $_SESSION['user_id'];
    
    // Validate customer ID
    if ($customer_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
        exit();
    }
    
    // Validate status
    if (!in_array($new_status, ['Active', 'Inactive'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status value']);
        exit();
    }
    
    // Check if customer exists
    $checkSql = "SELECT customer_id, name, status FROM customers WHERE customer_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $customer_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit();
    }
    
    $customer = $checkResult->fetch_assoc();
    $checkStmt->close();
    
    // Check if status is already the same
    if ($customer['status'] === $new_status) {
        echo json_encode(['success' => false, 'message' => 'Customer status is already ' . $new_status]);
        exit();
    }
    
    // Begin transaction for both customer update and logging
    $conn->autocommit(FALSE);
    
    try {
        // Update customer status
        $updateSql = "UPDATE customers SET status = ?, updated_at = NOW() WHERE customer_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        
        if (!$updateStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $updateStmt->bind_param("si", $new_status, $customer_id);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Execute failed: " . $updateStmt->error);
        }
        
        if ($updateStmt->affected_rows === 0) {
            throw new Exception("No changes were made");
        }
        
        $updateStmt->close();
        
        // Insert user log
        $action_type = $new_status === 'Active' ? 'customer_activated' : 'customer_deactivated';
        $details = "Customer ID " . $customer_id . " " . ($new_status === 'Active' ? 'activated' : 'deactivated');
        
        $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
        $log_stmt = $conn->prepare($log_sql);
        
        if (!$log_stmt) {
            throw new Exception('Log prepare error: ' . $conn->error);
        }
        
        $log_stmt->bind_param("isis", $current_user_id, $action_type, $customer_id, $details);
        
        if (!$log_stmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $log_stmt->error);
        }
        
        $log_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Customer status updated successfully',
            'customer_id' => $customer_id,
            'new_status' => $new_status,
            'customer_name' => $customer['name']
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Toggle Customer Status Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
} finally {
    // Reset autocommit and close database connection
    if (isset($conn)) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>