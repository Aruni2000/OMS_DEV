<?php
// Start session and check authentication
session_start();

// Check if user is logged in
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

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['user_id']) || !isset($input['new_status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$target_user_id = (int)$input['user_id'];
$new_status = trim($input['new_status']);
$current_user_id = $_SESSION['user_id'];

// Validate user ID
if ($target_user_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

// Validate status value
if (!in_array($new_status, ['active', 'inactive'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

try {
    // Check if user exists
    $check_sql = "SELECT id, name, status FROM users WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $check_stmt->bind_param("i", $target_user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    $user = $result->fetch_assoc();
    $check_stmt->close();
    
    // Check if status is actually changing
    if ($user['status'] === $new_status) {
        echo json_encode([
            'success' => true, 
            'message' => 'User status is already ' . $new_status,
            'current_status' => $new_status
        ]);
        exit();
    }
    
    // Begin transaction for both user update and logging
    $conn->autocommit(FALSE);
    
    try {
        // Update user status
        $update_sql = "UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        
        if (!$update_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $update_stmt->bind_param("si", $new_status, $target_user_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Execute failed: " . $update_stmt->error);
        }
        
        if ($update_stmt->affected_rows === 0) {
            throw new Exception("No changes made to user status");
        }
        
        $update_stmt->close();
        
        // Insert user log
        $action_type = $new_status === 'active' ? 'user_activated' : 'user_deactivated';
        $details = "User ID " . $target_user_id . " " . ($new_status === 'active' ? 'activated' : 'deactivated');
        
        $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
        $log_stmt = $conn->prepare($log_sql);
        
        if (!$log_stmt) {
            throw new Exception('Log prepare error: ' . $conn->error);
        }
        
        $log_stmt->bind_param("isis", $current_user_id, $action_type, $target_user_id, $details);
        
        if (!$log_stmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $log_stmt->error);
        }
        
        $log_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'User status updated successfully',
            'user_id' => $target_user_id,
            'user_name' => $user['name'],
            'old_status' => $user['status'],
            'new_status' => $new_status
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Error in toggle_user_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while updating user status'
    ]);
} finally {
    // Reset autocommit and close database connection
    if (isset($conn)) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>