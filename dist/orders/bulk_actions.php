<?php
/**
 * Bulk Actions Handler
 * Handles bulk operations for pending orders
 * File: bulk_actions.php
 */

// Start session management
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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

try {
    $conn->autocommit(false); // Start transaction
    
    switch ($action) {
        case 'bulk_mark_paid':
            $result = handleBulkMarkPaid($conn, $user_id);
            break;
            
        case 'bulk_dispatch':
            $result = handleBulkDispatch($conn, $user_id);
            break;
            
        case 'bulk_answer_status':
            $result = handleBulkAnswerStatus($conn, $user_id);
            break;
            
        case 'bulk_cancel':
            $result = handleBulkCancel($conn, $user_id);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    $conn->commit();
    echo json_encode($result);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function handleBulkMarkPaid($conn, $user_id) {
    $order_ids = json_decode($_POST['order_ids'], true);
    
    if (empty($order_ids)) {
        throw new Exception('No orders selected');
    }
    
    // Handle file upload
    if (!isset($_FILES['payment_slip']) || $_FILES['payment_slip']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Payment slip upload failed');
    }
    
    $file = $_FILES['payment_slip'];
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
    
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG, PNG, PDF allowed');
    }
    
    if ($file['size'] > 2 * 1024 * 1024) { // 2MB limit
        throw new Exception('File size too large. Maximum 2MB allowed');
    }
    
    // Create upload directory if it doesn't exist
    $upload_dir = 'uploads/payment_slips/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'bulk_payment_' . time() . '_' . uniqid() . '.' . $file_extension;
    $upload_path = $upload_dir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        throw new Exception('Failed to save payment slip');
    }
    
    $success_count = 0;
    $current_date = date('Y-m-d');
    
    foreach ($order_ids as $order_id) {
        // Validate order exists and is not already paid
        $check_query = "SELECT order_id, total_amount, pay_status FROM order_header WHERE order_id = ? AND pay_status != 'paid'";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $order_id);
        $check_stmt->execute();
        $order_result = $check_stmt->get_result();
        
        if ($order_result->num_rows === 0) {
            continue; // Skip if order not found or already paid
        }
        
        $order = $order_result->fetch_assoc();
        
        // Update order_header
        $update_order_query = "UPDATE order_header SET 
            pay_status = 'paid', 
            pay_date = ?, 
            slip = ?, 
            pay_by = 'bulk_upload',
            updated_at = CURRENT_TIMESTAMP 
            WHERE order_id = ?";
        $update_order_stmt = $conn->prepare($update_order_query);
        $update_order_stmt->bind_param("ssi", $current_date, $filename, $order_id);
        $update_order_stmt->execute();
        
        // Update order_items
        $update_items_query = "UPDATE order_items SET 
            pay_status = 'paid', 
            updated_at = CURRENT_TIMESTAMP 
            WHERE order_id = ?";
        $update_items_stmt = $conn->prepare($update_items_query);
        $update_items_stmt->bind_param("i", $order_id);
        $update_items_stmt->execute();
        
        // Insert into payments table
        $payment_query = "INSERT INTO payments (order_id, amount_paid, payment_method, payment_date, pay_by) 
                         VALUES (?, ?, 'bulk_upload', CURRENT_TIMESTAMP, ?)";
        $payment_stmt = $conn->prepare($payment_query);
        $payment_stmt->bind_param("idi", $order_id, $order['total_amount'], $user_id);
        $payment_stmt->execute();
        
        // Log the action
        $log_query = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                     VALUES (?, 'bulk_mark_paid', ?, ?, CURRENT_TIMESTAMP)";
        $log_stmt = $conn->prepare($log_query);
        $log_details = "Bulk marked as paid - Amount: " . $order['total_amount'] . ", Slip: " . $filename;
        $log_stmt->bind_param("iis", $user_id, $order_id, $log_details);
        $log_stmt->execute();
        
        $success_count++;
    }
    
    return [
        'success' => true, 
        'message' => "Successfully marked {$success_count} orders as paid",
        'processed_count' => $success_count
    ];
}

function handleBulkDispatch($conn, $user_id) {
    $order_ids = json_decode($_POST['order_ids'], true);
    $courier_id = $_POST['carrier'] ?? '';
    $dispatch_notes = $_POST['dispatch_notes'] ?? '';
    
    if (empty($order_ids)) {
        throw new Exception('No orders selected');
    }
    
    if (empty($courier_id)) {
        throw new Exception('Please select a courier service');
    }
    
    // Get available tracking numbers for the courier
    $tracking_query = "SELECT tracking_number FROM courier_tracking 
                      WHERE courier_id = ? AND status = 'available' 
                      ORDER BY created_at ASC LIMIT ?";
    $tracking_stmt = $conn->prepare($tracking_query);
    $order_count = count($order_ids);
    $tracking_stmt->bind_param("ii", $courier_id, $order_count);
    $tracking_stmt->execute();
    $tracking_result = $tracking_stmt->get_result();
    
    $available_tracking = [];
    while ($row = $tracking_result->fetch_assoc()) {
        $available_tracking[] = $row['tracking_number'];
    }
    
    // Validation: Check if enough tracking numbers are available
    if (count($available_tracking) < count($order_ids)) {
        $available_count = count($available_tracking);
        $needed_count = count($order_ids);
        throw new Exception("Insufficient tracking numbers. Available: {$available_count}, Needed: {$needed_count}. " . 
                          ($needed_count - $available_count) . " orders cannot be dispatched.");
    }
    
    $success_count = 0;
    $current_date = date('Y-m-d');
    
    foreach ($order_ids as $index => $order_id) {
        // Validate order exists and can be dispatched
        $check_query = "SELECT order_id, status FROM order_header 
                       WHERE order_id = ? AND status IN ('pending', 'done') AND pay_status = 'paid'";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $order_id);
        $check_stmt->execute();
        $order_result = $check_stmt->get_result();
        
        if ($order_result->num_rows === 0) {
            continue; // Skip if order not found or cannot be dispatched
        }
        
        $tracking_number = $available_tracking[$index];
        
        // Update order_header
        $update_order_query = "UPDATE order_header SET 
            status = 'dispatch',
            courier_id = ?,
            tracking_number = ?,
            dispatch_note = ?,
            updated_at = CURRENT_TIMESTAMP 
            WHERE order_id = ?";
        $update_order_stmt = $conn->prepare($update_order_query);
        $update_order_stmt->bind_param("issi", $courier_id, $tracking_number, $dispatch_notes, $order_id);
        $update_order_stmt->execute();
        
        // Update order_items
        $update_items_query = "UPDATE order_items SET 
            status = 'dispatch',
            updated_at = CURRENT_TIMESTAMP 
            WHERE order_id = ?";
        $update_items_stmt = $conn->prepare($update_items_query);
        $update_items_stmt->bind_param("i", $order_id);
        $update_items_stmt->execute();
        
        // Mark tracking number as used
        $update_tracking_query = "UPDATE courier_tracking SET 
            status = 'used',
            order_id = ?,
            used_date = CURRENT_TIMESTAMP 
            WHERE tracking_number = ? AND courier_id = ?";
        $update_tracking_stmt = $conn->prepare($update_tracking_query);
        $update_tracking_stmt->bind_param("isi", $order_id, $tracking_number, $courier_id);
        $update_tracking_stmt->execute();
        
        // Log the action
        $log_query = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                     VALUES (?, 'bulk_dispatch', ?, ?, CURRENT_TIMESTAMP)";
        $log_stmt = $conn->prepare($log_query);
        $log_details = "Bulk dispatched - Courier ID: {$courier_id}, Tracking: {$tracking_number}, Notes: {$dispatch_notes}";
        $log_stmt->bind_param("iis", $user_id, $order_id, $log_details);
        $log_stmt->execute();
        
        $success_count++;
    }
    
    return [
        'success' => true, 
        'message' => "Successfully dispatched {$success_count} orders",
        'processed_count' => $success_count
    ];
}

function handleBulkAnswerStatus($conn, $user_id) {
    $order_ids = json_decode($_POST['order_ids'], true);
    $new_call_log = $_POST['new_call_log'] ?? '';
    $answer_reason = $_POST['answer_reason'] ?? '';
    
    if (empty($order_ids)) {
        throw new Exception('No orders selected');
    }
    
    if (empty($answer_reason)) {
        throw new Exception('Please provide call notes');
    }
    
    if (!in_array($new_call_log, ['0', '1'])) {
        throw new Exception('Invalid call status');
    }
    
    $success_count = 0;
    $status_text = $new_call_log == '1' ? 'answered' : 'no_answer';
    $reason_field = $new_call_log == '1' ? 'answer_reason' : 'no_answer_reason';
    
    foreach ($order_ids as $order_id) {
        // Validate order exists
        $check_query = "SELECT order_id FROM order_header WHERE order_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $order_id);
        $check_stmt->execute();
        $order_result = $check_stmt->get_result();
        
        if ($order_result->num_rows === 0) {
            continue; // Skip if order not found
        }
        
        // Update order_header
        $update_query = "UPDATE order_header SET 
            call_log = ?,
            {$reason_field} = ?,
            updated_at = CURRENT_TIMESTAMP 
            WHERE order_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("isi", $new_call_log, $answer_reason, $order_id);
        $update_stmt->execute();
        
        // Log the action
        $log_query = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                     VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)";
        $log_stmt = $conn->prepare($log_query);
        $action_type = "bulk_mark_" . $status_text;
        $log_details = "Bulk marked as {$status_text} - Reason: {$answer_reason}";
        $log_stmt->bind_param("isis", $user_id, $action_type, $order_id, $log_details);
        $log_stmt->execute();
        
        $success_count++;
    }
    
    return [
        'success' => true, 
        'message' => "Successfully updated call status for {$success_count} orders",
        'processed_count' => $success_count
    ];
}

function handleBulkCancel($conn, $user_id) {
    $order_ids = json_decode($_POST['order_ids'], true);
    $reason = $_POST['reason'] ?? '';
    
    if (empty($order_ids)) {
        throw new Exception('No orders selected');
    }
    
    if (empty($reason) || strlen($reason) < 10) {
        throw new Exception('Please provide a detailed cancellation reason (minimum 10 characters)');
    }
    
    $success_count = 0;
    
    foreach ($order_ids as $order_id) {
        // Validate order exists and can be cancelled
        $check_query = "SELECT order_id, status FROM order_header 
                       WHERE order_id = ? AND status NOT IN ('cancel', 'dispatch')";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $order_id);
        $check_stmt->execute();
        $order_result = $check_stmt->get_result();
        
        if ($order_result->num_rows === 0) {
            continue; // Skip if order not found or cannot be cancelled
        }
        
        // Update order_header
        $update_order_query = "UPDATE order_header SET 
            status = 'cancel',
            cancellation_reason = ?,
            updated_at = CURRENT_TIMESTAMP 
            WHERE order_id = ?";
        $update_order_stmt = $conn->prepare($update_order_query);
        $update_order_stmt->bind_param("si", $reason, $order_id);
        $update_order_stmt->execute();
        
        // Update order_items
        $update_items_query = "UPDATE order_items SET 
            status = 'canceled',
            updated_at = CURRENT_TIMESTAMP 
            WHERE order_id = ?";
        $update_items_stmt = $conn->prepare($update_items_query);
        $update_items_stmt->bind_param("i", $order_id);
        $update_items_stmt->execute();
        
        // Log the action
        $log_query = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                     VALUES (?, 'bulk_cancel', ?, ?, CURRENT_TIMESTAMP)";
        $log_stmt = $conn->prepare($log_query);
        $log_details = "Bulk cancelled - Reason: {$reason}";
        $log_stmt->bind_param("iis", $user_id, $order_id, $log_details);
        $log_stmt->execute();
        
        $success_count++;
    }
    
    return [
        'success' => true, 
        'message' => "Successfully cancelled {$success_count} orders",
        'processed_count' => $success_count
    ];
}
?>