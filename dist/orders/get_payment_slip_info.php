<?php
/**
 * get_payment_slip_info.php
 * API endpoint to get payment slip information for an order
 * Place this file in the same directory as your order_list.php
 */

// Start session and check authentication
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if order_id is provided
if (!isset($_GET['order_id']) || empty($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit();
}

$order_id = $_GET['order_id'];

try {
    // Query to get payment slip information
    $query = "SELECT slip as payment_slip, pay_status 
              FROM order_header 
              WHERE order_id = ?";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }
    
    $order = $result->fetch_assoc();
    
    $payment_slip = $order['payment_slip'];
    $pay_status = $order['pay_status'];
    
    // Check if payment slip file exists on server
    $file_exists = false;
    if (!empty($payment_slip)) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/uploads/payment_slips/' . $payment_slip;
        $file_exists = file_exists($file_path);
    }
    
    // Return response
    echo json_encode([
        'success' => true,
        'payment_slip' => $payment_slip,
        'pay_status' => $pay_status,
        'file_exists' => $file_exists,
        'show_button' => !empty($payment_slip) && 
                        $file_exists && 
                        ($pay_status === 'paid' || $pay_status === 'partial')
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>