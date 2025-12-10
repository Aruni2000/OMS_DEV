<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo '<div style="text-align: center; padding: 40px; color: #dc3545;">Access denied</div>';
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Get customer ID from request
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customer_id <= 0) {
    echo '<div style="text-align: center; padding: 40px; color: #dc3545;">Invalid customer ID</div>';
    exit();
}

// Fetch customer details
$sql = "SELECT customer_id, name, email, phone, address_line1, address_line2, city_id, postal_code, status, created_at, updated_at 
        FROM customers 
        WHERE customer_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div style="text-align: center; padding: 40px; color: #dc3545;">Customer not found</div>';
    exit();
}

$customer = $result->fetch_assoc();

// Format the address
$full_address = $customer['address_line1'];
if (!empty($customer['address_line2'])) {
    $full_address .= ', ' . $customer['address_line2'];
}
if (!empty($customer['postal_code'])) {
    $full_address .= ', ' . $customer['postal_code'];
}

// Format dates
$created_date = date('F j, Y \a\t h:i A', strtotime($customer['created_at']));
$updated_date = date('F j, Y \a\t h:i A', strtotime($customer['updated_at']));
?>

<div class="customer-details">
    <div class="detail-row">
        <div class="detail-label">Customer ID:</div>
        <div class="detail-value"><?php echo htmlspecialchars($customer['customer_id']); ?></div>
    </div>
    
    <div class="detail-row">
        <div class="detail-label">Full Name:</div>
        <div class="detail-value"><?php echo htmlspecialchars($customer['name']); ?></div>
    </div>
    
    <div class="detail-row">
        <div class="detail-label">Email:</div>
        <div class="detail-value"><?php echo htmlspecialchars($customer['email']); ?></div>
    </div>
    
    <div class="detail-row">
        <div class="detail-label">Mobile:</div>
        <div class="detail-value"><?php echo htmlspecialchars($customer['phone']); ?></div>
    </div>
    
    <div class="detail-row">
        <div class="detail-label">Address:</div>
        <div class="detail-value"><?php echo htmlspecialchars($full_address); ?></div>
    </div>
    
    <?php if (!empty($customer['city_id'])): ?>
    <div class="detail-row">
        <div class="detail-label">City ID:</div>
        <div class="detail-value"><?php echo htmlspecialchars($customer['city_id']); ?></div>
    </div>
    <?php endif; ?>
    
    <div class="detail-row">
        <div class="detail-label">Status:</div>
        <div class="detail-value">
            <span class="status-badge-modal <?php echo $customer['status'] === 'Active' ? 'status-active' : 'status-inactive'; ?>">
                <?php echo htmlspecialchars($customer['status']); ?>
            </span>
        </div>
    </div>
    
    <div class="detail-row">
        <div class="detail-label">Created:</div>
        <div class="detail-value"><?php echo $created_date; ?></div>
    </div>
    
    <div class="detail-row">
        <div class="detail-label">Last Updated:</div>
        <div class="detail-value"><?php echo $updated_date; ?></div>
    </div>
</div>