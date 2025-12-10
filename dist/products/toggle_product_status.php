<?php
// Start session and check if user is logged in
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

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!isset($input['product_id']) || !isset($input['new_status'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }
    
    $product_id = (int)$input['product_id'];
    $new_status = trim($input['new_status']);
    $user_id = $_SESSION['user_id'];
    
    // Validate product ID
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        exit();
    }
    
    // Validate status value
    if (!in_array($new_status, ['active', 'inactive'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status value']);
        exit();
    }
    
    // Check if product exists
    $checkSql = "SELECT id, name, status FROM products WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    
    if (!$checkStmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
        exit();
    }
    
    $checkStmt->bind_param("i", $product_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }
    
    $product = $result->fetch_assoc();
    $checkStmt->close();
    
    // Check if status is already the same
    if ($product['status'] === $new_status) {
        echo json_encode([
            'success' => true, 
            'message' => 'Product status is already ' . $new_status,
            'current_status' => $new_status
        ]);
        exit();
    }
    
    // Begin transaction for both product update and logging
    $conn->autocommit(FALSE);
    
    try {
        // Update product status
        $updateSql = "UPDATE products SET status = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        
        if (!$updateStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $updateStmt->bind_param("si", $new_status, $product_id);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update product status: ' . $updateStmt->error);
        }
        
        if ($updateStmt->affected_rows === 0) {
            throw new Exception('No changes were made to product status');
        }
        
        $updateStmt->close();
        
        // Insert user log
        $action_type = $new_status === 'active' ? 'product_activated' : 'product_deactivated';
        $details = "Product ID " . $product_id . " " . ($new_status === 'active' ? 'activated' : 'deactivated');
        
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            throw new Exception('Log prepare error: ' . $conn->error);
        }
        
        $logStmt->bind_param("isis", $user_id, $action_type, $product_id, $details);
        
        if (!$logStmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $logStmt->error);
        }
        
        $logStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Product status updated successfully',
            'product_id' => $product_id,
            'product_name' => $product['name'],
            'old_status' => $product['status'],
            'new_status' => $new_status
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log error (you can also log to a file)
    error_log("Toggle product status error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred'
    ]);
    
} finally {
    // Reset autocommit and close database connection
    if (isset($conn)) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>