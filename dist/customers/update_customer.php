<?php
// Start session at the very beginning
session_start();

// Set JSON content type
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please login again.',
        'redirect' => '/OMS/dist/pages/login.php'
    ]);
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check database connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please try again later.'
    ]);
    exit();
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// CSRF token validation
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid security token. Please refresh the page and try again.'
    ]);
    exit();
}

// Function to log user actions
function logUserAction($conn, $userId, $actionType, $targetId, $details = '') {
    try {
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            error_log("Failed to prepare user log statement: " . $conn->error);
            return false;
        }
        
        $logStmt->bind_param("isis", $userId, $actionType, $targetId, $details);
        $result = $logStmt->execute();
        
        if (!$result) {
            error_log("Failed to log user action: " . $logStmt->error);
        }
        
        $logStmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Exception in logUserAction: " . $e->getMessage());
        return false;
    }
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'errors' => []
];

try {
    // Get current user ID for logging
    $currentUserId = $_SESSION['user_id'] ?? null;
    if (!$currentUserId) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'User session not found. Please login again.',
            'redirect' => '/OMS/dist/pages/login.php'
        ]);
        exit();
    }

    // Get and sanitize form data
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $address_line1 = trim($_POST['address_line1'] ?? '');
    $address_line2 = trim($_POST['address_line2'] ?? '');
    $city_id = intval($_POST['city_id'] ?? 0);

    // Validate customer ID
    if ($customer_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid customer ID.'
        ]);
        exit();
    }

    // Check if customer exists and get all current data for comparison
    $customerCheckStmt = $conn->prepare("SELECT customer_id, name, email, phone, status, address_line1, address_line2, city_id FROM customers WHERE customer_id = ?");
    $customerCheckStmt->bind_param("i", $customer_id);
    $customerCheckStmt->execute();
    $customerCheckResult = $customerCheckStmt->get_result();
    
    if ($customerCheckResult->num_rows === 0) {
        $customerCheckStmt->close();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found.'
        ]);
        exit();
    }
    
    $existingCustomer = $customerCheckResult->fetch_assoc();
    $customerCheckStmt->close();

    // Essential server-side validation
    $errors = [];

    // Basic required field checks
    // Verify other required fields
    if (empty($name)) {
        $errors['name'] = 'Customer name is required';
    }
    // Email is optional 
    // if (empty($email)) {
    //     $errors['email'] = 'Email address is required';
    // }
    
    if (empty($phone)) {
        $errors['phone'] = 'Phone number is required';
    }
    if (empty($address_line1)) {
        $errors['address_line1'] = 'Address Line 1 is required';
    }
    if (empty($city_id) || $city_id <= 0) {
        $errors['city_id'] = 'City selection is required';
    }

    // Check for duplicate email (excluding current customer)
    if (!empty($email) && $email !== $existingCustomer['email']) {
        $emailCheckStmt = $conn->prepare("SELECT customer_id FROM customers WHERE email = ? AND customer_id != ?");
        $emailCheckStmt->bind_param("si", $email, $customer_id);
        $emailCheckStmt->execute();
        $emailCheckResult = $emailCheckStmt->get_result();
        
        if ($emailCheckResult->num_rows > 0) {
            $errors['email'] = 'Email address already exists. Please use a different email.';
        }
        $emailCheckStmt->close();
    }

    // Check for duplicate phone (excluding current customer)
    if (!empty($phone) && $phone !== $existingCustomer['phone']) {
        $phoneCheckStmt = $conn->prepare("SELECT customer_id FROM customers WHERE phone = ? AND customer_id != ?");
        $phoneCheckStmt->bind_param("si", $phone, $customer_id);
        $phoneCheckStmt->execute();
        $phoneCheckResult = $phoneCheckStmt->get_result();
        
        if ($phoneCheckResult->num_rows > 0) {
            $errors['phone'] = 'Phone number already exists. Please use a different phone number.';
        }
        $phoneCheckStmt->close();
    }

    // Validate city exists and is active
    if ($city_id > 0) {
        $cityCheckStmt = $conn->prepare("SELECT city_id FROM city_table WHERE city_id = ? AND is_active = 1");
        $cityCheckStmt->bind_param("i", $city_id);
        $cityCheckStmt->execute();
        $cityCheckResult = $cityCheckStmt->get_result();
        
        if ($cityCheckResult->num_rows === 0) {
            $errors['city_id'] = 'Selected city is not valid';
        }
        $cityCheckStmt->close();
    }

    // Validate status (security check)
    if (!in_array($status, ['Active', 'Inactive'])) {
        $status = 'Active'; // Default to Active if invalid
    }

    // If there are validation errors, return them
    if (!empty($errors)) {
        $response['errors'] = $errors;
        $response['message'] = 'Please correct the errors and try again.';
        echo json_encode($response);
        exit();
    }

    // Check if any data has actually changed
    $hasChanges = false;
    $changes = [];

    if ($name !== $existingCustomer['name']) {
        $hasChanges = true;
        $changes[] = "Name: '{$existingCustomer['name']}' → '{$name}'";
    }
    if ($email !== $existingCustomer['email']) {
        $hasChanges = true;
        $changes[] = "Email: '{$existingCustomer['email']}' → '{$email}'";
    }
    if ($phone !== $existingCustomer['phone']) {
        $hasChanges = true;
        $changes[] = "Phone: '{$existingCustomer['phone']}' → '{$phone}'";
    }
    if ($status !== $existingCustomer['status']) {
        $hasChanges = true;
        $changes[] = "Status: '{$existingCustomer['status']}' → '{$status}'";
    }
    if ($address_line1 !== $existingCustomer['address_line1']) {
        $hasChanges = true;
        $changes[] = "Address Line 1: '{$existingCustomer['address_line1']}' → '{$address_line1}'";
    }
    if ($address_line2 !== $existingCustomer['address_line2']) {
        $hasChanges = true;
        $changes[] = "Address Line 2: '{$existingCustomer['address_line2']}' → '{$address_line2}'";
    }
    if ($city_id != $existingCustomer['city_id']) {
        $hasChanges = true;
        $changes[] = "City ID: '{$existingCustomer['city_id']}' → '{$city_id}'";
    }

    // If no changes detected, return early without logging
    if (!$hasChanges) {
        $response['success'] = true;
        $response['message'] = 'No changes were made to the customer.';
        $response['customer_id'] = $customer_id;
        $response['data'] = [
            'id' => $customer_id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'status' => $status
        ];
        echo json_encode($response);
        exit();
    }

    // Start transaction
    $conn->begin_transaction();

    // Prepare and execute customer update
    $updateStmt = $conn->prepare("
        UPDATE customers 
        SET name = ?, email = ?, phone = ?, status = ?, address_line1 = ?, address_line2 = ?, city_id = ?, updated_at = NOW()
        WHERE customer_id = ?
    ");

    // Convert empty email to NULL 
    $db_email = empty($email) ? null : $email;

    $updateStmt->bind_param("ssssssii", $name, $db_email, $phone, $status, $address_line1, $address_line2, $city_id, $customer_id);

    if ($updateStmt->execute()) {
        // Check if any rows were affected
        if ($updateStmt->affected_rows > 0) {
            // Log customer update action only if there were actual changes
            $logDetails = "Customer updated - " . implode(', ', $changes);
            
            $logResult = logUserAction($conn, $currentUserId, 'customer_update', $customer_id, $logDetails);
            
            if (!$logResult) {
                error_log("Failed to log customer update action for customer ID: $customer_id");
            }
            
            // Commit transaction
            $conn->commit();
            
            // Success response
            $response['success'] = true;
            $response['message'] = 'Customer "' . htmlspecialchars($name) . '" has been successfully updated.';
            $response['customer_id'] = $customer_id;
            $response['data'] = [
                'id' => $customer_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'status' => $status
            ];
            
            // Log success
            error_log("Customer updated successfully - ID: $customer_id, Name: $name, Email: $email, Updated by User ID: $currentUserId");
        } else {
            // No changes were made (this should not happen since we checked above, but keep as fallback)
            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'No changes were made to the customer.';
            $response['customer_id'] = $customer_id;
        }
        
    } else {
        // Rollback transaction
        $conn->rollback();
        
        // Database error
        error_log("Failed to update customer: " . $updateStmt->error);
        $response['message'] = 'Failed to update customer. Please try again.';
    }

    $updateStmt->close();

} catch (Exception $e) {
    // Rollback transaction if it was started
    if ($conn->inTransaction ?? false) {
        $conn->rollback();
    }
    
    // Log error
    error_log("Error updating customer: " . $e->getMessage());
    
    // Return error response
    $response['message'] = 'An unexpected error occurred. Please try again.';
    http_response_code(500);
    
} finally {
    // Close database connection
    if (isset($conn)) {
        $conn->close();
    }
}

// Return JSON response
echo json_encode($response);
exit();
?>