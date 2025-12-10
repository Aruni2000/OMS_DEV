<?php
// Implementation for get_payment_details.php
// This file will fetch payment details for an order ID

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include 'db_connection.php';

// Check if order ID is provided
if (!isset($_GET['order_id']) || empty($_GET['order_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit();
}

// Get order ID
$order_id = intval($_GET['order_id']);

// Get payment details from the database
$query = "SELECT p.*, u.name as processed_by_name, oh.slip 
          FROM payments p 
          LEFT JOIN users u ON p.pay_by = u.id
          LEFT JOIN order_header oh ON p.order_id = oh.order_id
          WHERE p.order_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    
    // Format the payment date
    $payment_date = isset($data['payment_date']) ? date('d/m/Y H:i', strtotime($data['payment_date'])) : 'N/A';
    
    // Format the amount
    $amount_paid = isset($data['amount_paid']) ? number_format((float)$data['amount_paid'], 2) : '0.00';
    $currency = 'Rs'; // Default currency symbol
    
    // Prepare response
    $response = [
        'success' => true,
        'payment_id' => $data['payment_id'] ?? '',
        'payment_method' => $data['payment_method'] ?? 'Cash',
        'amount_paid' => $amount_paid . ' (' . $currency . ')',
        'payment_date' => $payment_date,
        'processed_by' => $data['processed_by_name'] ?? 'System',
        'slip' => $data['slip'] ?? ''
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
} else {
    // No payment details found
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No payment details found for this order']);
}

// Close statement and connection
$stmt->close();
$conn->close();
?>