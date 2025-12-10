<?php
/**
 * Process Payment Upload
 * Handles payment slip upload and order status update
 * Updated to work with order_header and order_items table structures
 */

session_start();
header('Content-Type: application/json');

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('log_errors', 1);

try {
    // Authentication check
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        throw new Exception('Authentication required');
    }

    // Include database connection
    $db_path = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php';
    if (!file_exists($db_path)) {
        throw new Exception('Database connection file not found');
    }
    include($db_path);

    if (!isset($conn)) {
        throw new Exception('Database connection not established');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    if (!isset($_POST['action']) || $_POST['action'] !== 'mark_paid') {
        throw new Exception('Invalid action');
    }
    
    $orderId = isset($_POST['order_id']) ? trim($_POST['order_id']) : '';
    if (empty($orderId)) {
        throw new Exception('Order ID is required');
    }
    
    // First check if order exists and get its current status
    $checkOrderSql = "SELECT order_id, pay_status, status FROM order_header WHERE order_id = ?";
    $checkOrderStmt = $conn->prepare($checkOrderSql);
    if (!$checkOrderStmt) {
        throw new Exception('Failed to prepare order check statement: ' . $conn->error);
    }
    
    $checkOrderStmt->bind_param("s", $orderId);
    $checkOrderStmt->execute();
    $orderResult = $checkOrderStmt->get_result();
    
    if ($orderResult->num_rows === 0) {
        throw new Exception('Order not found. Please check the order ID and try again.');
    }
    
    $orderData = $orderResult->fetch_assoc();
    
    // Check if order is already paid
    if ($orderData['pay_status'] === 'paid') {
        throw new Exception('This order has already been marked as paid. Payment cannot be processed again.');
    }
    
    // Check if order is in valid status for payment
    if (!in_array($orderData['status'], ['pending', 'dispatch'])) {
        throw new Exception('Order is not in a valid status for payment processing. Current status: ' . $orderData['status']);
    }
    
    // Validate file upload
    if (!isset($_FILES['payment_slip']) || $_FILES['payment_slip']['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File too large (exceeds server limit)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds form limit)',
            UPLOAD_ERR_PARTIAL => 'File upload incomplete',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        ];
        
        $error_code = $_FILES['payment_slip']['error'];
        $error_message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : 'Unknown upload error';
        throw new Exception('Payment slip upload error: ' . $error_message);
    }
    
    $file = $_FILES['payment_slip'];
    
    // Validate file size (2MB limit)
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception('File size must be less than 2MB');
    }
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
    
    // Check file type using multiple methods
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
    
    if (!in_array($fileExtension, $allowedExtensions)) {
        throw new Exception('Invalid file type. Only JPG, JPEG, PNG, and PDF files are allowed');
    }
    
    // Additional MIME type check if fileinfo is available
    if (extension_loaded('fileinfo')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Invalid file MIME type: ' . $mimeType);
        }
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/uploads/payment_slips/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }
    
    // Check if directory is writable
    if (!is_writable($uploadDir)) {
        throw new Exception('Upload directory is not writable');
    }
    
    // Generate unique filename
    $fileName = 'payment_' . $orderId . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    // Start database transaction
    $conn->begin_transaction();
    
    try {
        // Check if order items exist for this order
        $checkItemsSql = "SELECT COUNT(*) as item_count FROM order_items WHERE order_id = ?";
        $checkItemsStmt = $conn->prepare($checkItemsSql);
        if (!$checkItemsStmt) {
            throw new Exception('Failed to prepare items check statement: ' . $conn->error);
        }
        
        $checkItemsStmt->bind_param("i", $orderId);
        $checkItemsStmt->execute();
        $itemsResult = $checkItemsStmt->get_result();
        $itemsRow = $itemsResult->fetch_assoc();
        
        if ($itemsRow['item_count'] == 0) {
            throw new Exception('No order items found for this order');
        }
        
        // Get current user ID from session
        $currentUserId = null;
        if (isset($_SESSION['user_id'])) {
            $currentUserId = $_SESSION['user_id'];
        } elseif (isset($_SESSION['id'])) {
            $currentUserId = $_SESSION['id'];
        } else {
            // If no user ID in session, you might want to get it from the users table
            // For now, we'll use a default or get it from the session login
            $currentUserId = 1; // Default fallback
        }
        
        $paymentDate = date('Y-m-d');
        $updatedAt = date('Y-m-d H:i:s');
        
        // Update order_header with payment information
        $updateHeaderSql = "UPDATE order_header SET 
                           pay_status = 'paid', 
                           pay_by = ?, 
                           pay_date = ?, 
                           slip = ?,
                           updated_at = ?
                           WHERE order_id = ? AND pay_status != 'paid'";
        
        $updateHeaderStmt = $conn->prepare($updateHeaderSql);
        if (!$updateHeaderStmt) {
            throw new Exception('Failed to prepare header update statement: ' . $conn->error);
        }
        
        // pay_by seems to be varchar(50) in your table, so we'll store the user ID as string
        $payByValue = "User_" . $currentUserId;
        $updateHeaderStmt->bind_param("sssss", $payByValue, $paymentDate, $fileName, $updatedAt, $orderId);
        
        if (!$updateHeaderStmt->execute()) {
            throw new Exception('Failed to update order header: ' . $updateHeaderStmt->error);
        }
        
        // Check if any rows were affected in order_header
        if ($updateHeaderStmt->affected_rows === 0) {
            throw new Exception('Order has already been marked as paid or does not exist.');
        }
        
        // Update order_items with payment status
        $updateItemsSql = "UPDATE order_items SET 
                          pay_status = 'paid',
                          updated_at = CURRENT_TIMESTAMP
                          WHERE order_id = ? AND pay_status = 'unpaid'";
        
        $updateItemsStmt = $conn->prepare($updateItemsSql);
        if (!$updateItemsStmt) {
            throw new Exception('Failed to prepare items update statement: ' . $conn->error);
        }
        
        $updateItemsStmt->bind_param("i", $orderId);
        
        if (!$updateItemsStmt->execute()) {
            throw new Exception('Failed to update order items: ' . $updateItemsStmt->error);
        }
        
        // Get the number of items updated
        $itemsUpdated = $updateItemsStmt->affected_rows;
        
        // Insert payment record into payments table and get the actual payment_id
        $insertPaymentSql = "INSERT INTO payments (order_id, amount_paid, payment_method, payment_date, pay_by) 
                            SELECT order_id, total_amount, 'bank_transfer', ?, ? FROM order_header WHERE order_id = ?";
        
        $insertPaymentStmt = $conn->prepare($insertPaymentSql);
        if (!$insertPaymentStmt) {
            throw new Exception('Failed to prepare payment insert statement: ' . $conn->error);
        }
        
        $insertPaymentStmt->bind_param("sis", $paymentDate, $currentUserId, $orderId);
        
        if (!$insertPaymentStmt->execute()) {
            throw new Exception('Failed to insert payment record: ' . $insertPaymentStmt->error);
        }
        
        // Get the actual payment_id that was just inserted
        $paymentId = $conn->insert_id;
        
        // Insert simplified user log entry
        // Format: "pending unpaid order(id) paid mark | payment(payment_id)"
        $logMessage = $orderData['status'] . " unpaid order(" . $orderId . ") paid mark | payment(" . $paymentId . ")";
        
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                   VALUES (?, 'payment_marked', ?, ?, CURRENT_TIMESTAMP)";
        
        $logStmt = $conn->prepare($logSql);
        if (!$logStmt) {
            throw new Exception('Failed to prepare log statement: ' . $conn->error);
        }
        
        $logStmt->bind_param("iis", $currentUserId, $orderId, $logMessage);
        
        if (!$logStmt->execute()) {
            throw new Exception('Failed to insert user log: ' . $logStmt->error);
        }
        
        $logId = $conn->insert_id;
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Order and items marked as paid successfully',
            'order_id' => $orderId,
            'file_name' => $fileName,
            'pay_date' => $paymentDate,
            'payment_id' => $paymentId,
            'items_updated' => $itemsUpdated,
            'log_id' => $logId,
            'log_message' => $logMessage
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        
        // Delete uploaded file if database operations failed
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Mark Paid Error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}
?>