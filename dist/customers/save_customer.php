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
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone2 = trim($_POST['phone2'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $address_line1 = trim($_POST['address_line1'] ?? '');
    $address_line2 = trim($_POST['address_line2'] ?? '');
    $city_id = intval($_POST['city_id'] ?? 0);

    // Essential server-side validation (security checks only)
    $errors = [];

    // Basic required field checks

    if (empty($name)) {
        $errors['name'] = 'Customer name is required';
    }
    if ($email === '') {
        $email = null;
    }
    if (empty($phone)) {
        $errors['phone'] = 'Phone number is required';
    } else {
        // Validate primary phone format
        if (!preg_match('/^(0|94|\+94)[0-9]{9}$/', $phone)) {
            $errors['phone'] = 'Invalid primary phone number format. Please use 10 digits starting with 0, 94 or +94.';
        }
    }

    // Validate secondary phone format if provided
    if (!empty($phone2)) {
        if (!preg_match('/^(0|94|\+94)[0-9]{9}$/', $phone2)) {
            $errors['phone2'] = 'Invalid secondary phone number format. Please use 10 digits starting with 0, 94 or +94.';
        }
    } else {
        $phone2 = null; // Set to null if empty for database insertion
    }

    if (empty($address_line1)) {
        $errors['address_line1'] = 'Address Line 1 is required';
    }
    if (empty($city_id) || $city_id <= 0) {
        $errors['city_id'] = 'City selection is required';
    }

    // Check for duplicate email
    if (!empty($email)) {
        $emailCheckStmt = $conn->prepare("SELECT customer_id FROM customers WHERE email = ?");
        $emailCheckStmt->bind_param("s", $email);
        $emailCheckStmt->execute();
        $emailCheckResult = $emailCheckStmt->get_result();
        
        if ($emailCheckResult->num_rows > 0) {
            $errors['email'] = 'Email address already exists. Please use a different email.';
        }
        $emailCheckStmt->close();
    }

    // Check for duplicate primary phone
    if (!empty($phone)) {
        $phoneCheckStmt = $conn->prepare("SELECT customer_id FROM customers WHERE phone = ?");
        $phoneCheckStmt->bind_param("s", $phone);
        $phoneCheckStmt->execute();
        $phoneCheckResult = $phoneCheckStmt->get_result();
        
        if ($phoneCheckResult->num_rows > 0) {
            $errors['phone'] = 'Primary phone number already exists. Please use a different phone number.';
        }
        $phoneCheckStmt->close();
    }

    // Check if primary phone number exists as another customer's secondary number
    if (!empty($phone)) { // Only check if primary phone is provided
        $phoneCheckStmt = $conn->prepare("SELECT customer_id FROM customers WHERE phone2 = ?");
        $phoneCheckStmt->bind_param("s", $phone);
        $phoneCheckStmt->execute();
        $phoneCheckResult = $phoneCheckStmt->get_result();
        
        if ($phoneCheckResult->num_rows > 0) {
            $errors['phone'] = 'Primary phone number already exists. Please use a different phone number.';
        }
        $phoneCheckStmt->close();
    }


    // Check for duplicate secondary phone if provided and valid
            if (!empty($phone2)) {
                $phone2CheckStmt = $conn->prepare("SELECT customer_id FROM customers WHERE phone2 = ?");
                $phone2CheckStmt->bind_param("s", $phone2);
                $phone2CheckStmt->execute();
                $phone2CheckResult = $phone2CheckStmt->get_result();
    
                if ($phone2CheckResult->num_rows > 0) {
                    $errors['phone2'] = 'Secondary phone number already exists. Please use a different phone number.';
                }
                        $phone2CheckStmt->close();
                    }
                
                    // Check if the secondary phone number is already used as a primary phone number
                    if (!empty($phone2)) { // Only check if phone2 is provided
                        $phone2AsPrimaryCheckStmt = $conn->prepare("SELECT customer_id FROM customers WHERE phone = ?");
                        $phone2AsPrimaryCheckStmt->bind_param("s", $phone2);
                        $phone2AsPrimaryCheckStmt->execute();
                        $phone2AsPrimaryCheckResult = $phone2AsPrimaryCheckStmt->get_result();
                        
                        if ($phone2AsPrimaryCheckResult->num_rows > 0) {
                            $errors['phone2'] = 'Secondary phone number already exists. Please use a different phone number.';
                        }
                        $phone2AsPrimaryCheckStmt->close();
                    }
                
                    // NEW VALIDATION: Check if primary and secondary phone numbers are the same
                    if (!empty($phone) && !empty($phone2) && $phone === $phone2) {
                        $errors['phone2'] = 'Primary and secondary phone numbers cannot be the same.';
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

    // Start transaction
    $conn->begin_transaction();

    // Prepare and execute customer insert
    $insertStmt = $conn->prepare("
        INSERT INTO customers (name, email, phone, phone2, status, address_line1, address_line2, city_id, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $insertStmt->bind_param("sssssssi", $name, $email, $phone, $phone2, $status, $address_line1, $address_line2, $city_id);

    if ($insertStmt->execute()) {
        $customer_id = $conn->insert_id;
        
        // Log customer creation action
        $logDetails = "New customer added - Name: {$name}, Email: {$email}, Primary Phone: {$phone}, Secondary Phone: {$phone2}, Status: {$status}";
        $logResult = logUserAction($conn, $currentUserId, 'customer_create', $customer_id, $logDetails);
        
        if (!$logResult) {
            error_log("Failed to log customer creation action for customer ID: $customer_id");
        }
        
        // Commit transaction
        $conn->commit();
        
        // Success response
        $response['success'] = true;
        $response['message'] = 'Customer "' . htmlspecialchars($name) . '" has been successfully added to the system.';
        $response['customer_id'] = $customer_id;
        $response['data'] = [
            'id' => $customer_id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'phone2' => $phone2,
            'status' => $status
        ];
        
        // Log success
        error_log("Customer added successfully - ID: $customer_id, Name: $name, Email: $email, Primary Phone: {$phone}, Secondary Phone: {$phone2}, Added by User ID: $currentUserId");
        
    } else {
        // Rollback transaction
        $conn->rollback();
        
        // Database error
        error_log("Failed to insert customer: " . $insertStmt->error);
        $response['message'] = 'Failed to add customer. Please try again.';
    }

    $insertStmt->close();

} catch (Exception $e) {
    // Rollback transaction if it was started
    // if (isset($conn) && $conn->inTransaction ?? false) {
    //     $conn->rollback();
    // }
    if ($conn->inTransaction ?? false) {
        $conn->rollback();
    }    
    // Log error
    error_log("Error adding customer: " . $e->getMessage());
    
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