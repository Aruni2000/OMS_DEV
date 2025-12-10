<?php
// save_user.php - Simplified version with minimal backend validation
// Enable strict error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors from output for AJAX

// Set content type to JSON
header('Content-Type: application/json');

// Start output buffering immediately
ob_start();

// Start session
session_start();

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Function to return JSON response and exit
function jsonResponse($success, $message, $errors = null, $data = null) {
    // Clean any existing output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($errors !== null) {
        $response['errors'] = $errors;
    }
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    jsonResponse(false, 'Authentication required. Please login again.');
}

// Function to log user actions - SIMPLIFIED
function logUserAction($conn, $userId, $actionType, $targetUserId, $details = '') {
    try {
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            error_log("Failed to prepare user log statement: " . $conn->error);
            return false;
        }
        
        $logStmt->bind_param("isis", $userId, $actionType, $targetUserId, $details);
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

// Main processing
try {
    // Get current user ID for logging
    $currentUserId = $_SESSION['user_id'] ?? null;
    if (!$currentUserId) {
        jsonResponse(false, 'User session not found. Please login again.');
    }

    // Get and sanitize inputs (frontend validation already handled most cases)
    $name = trim($_POST['full_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $mobile = trim($_POST['mobile'] ?? '');
    $nic = trim(strtoupper($_POST['nic'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $status = strtolower($_POST['status'] ?? 'active');
    $role = $_POST['role'] ?? '';
    
    // Convert role to role_id
    $role_mapping = [
        'admin' => ['id' => 1, 'name' => 'Admin'],
        'moderator' => ['id' => 2, 'name' => 'Moderator'],
        'user' => ['id' => 3, 'name' => 'User']
    ];
    
    // Essential server-side validation (security-critical only)
    $fieldErrors = [];
    
    // Required field checks (basic)
    if (empty($name)) $fieldErrors['full_name'] = "Full name is required.";
    if (empty($email)) $fieldErrors['email'] = "Email address is required.";
    if (empty($password)) $fieldErrors['password'] = "Password is required.";
    if (empty($mobile)) $fieldErrors['mobile'] = "Mobile number is required.";
    if (empty($nic)) $fieldErrors['nic'] = "NIC number is required.";
    if (empty($address)) $fieldErrors['address'] = "Address is required.";
    if (!isset($role_mapping[$role])) $fieldErrors['role'] = "Please select a valid role.";
    
    // Email format validation (security critical)
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = "Invalid email format.";
    }
    
    // Check for duplicate email and NIC (database constraints)
    if (!empty($email)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $fieldErrors['email'] = "Email address is already in use.";
            }
            $stmt->close();
        }
    }
    
    // Check for duplicate NIC
    if (!empty($nic)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE nic = ?");
        if ($stmt) {
            $stmt->bind_param("s", $nic);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $fieldErrors['nic'] = "NIC number is already registered.";
            }
            $stmt->close();
        }
    }

    // If validation errors exist, return them
    if (!empty($fieldErrors)) {
        jsonResponse(false, 'Please correct the errors and try again.', $fieldErrors);
    }

    // Begin database transaction
    $conn->begin_transaction();

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    
    // Get role info
    $role_id = $role_mapping[$role]['id'];
    $role_name = $role_mapping[$role]['name'];
    
    // Set commission defaults (if your table has these fields)
    $commission_type = 'none';
    $commission_per_parcel = 0.00;
    $percentage_drawdown = 0.00;

    // Prepare insert query
    $stmt = $conn->prepare("INSERT INTO users (name, email, password, mobile, nic, address, status, role_id, 
                            commission_type, commission_per_parcel, percentage_drawdown, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    if ($stmt === false) {
        $conn->rollback();
        jsonResponse(false, 'Database error occurred. Please try again.');
    }
    
    $stmt->bind_param("sssssssissd", 
        $name, $email, $hashed_password, $mobile, $nic, $address, 
        $status, $role_id, $commission_type, $commission_per_parcel, $percentage_drawdown
    );

    // Execute insert
    if ($stmt->execute()) {
        // Get the ID of the newly created user
        $newUserId = $conn->insert_id;
        $stmt->close();
        
        // Log user creation
        $logDetails = "New user account created - Name: {$name}, Email: {$email}, Role: {$role_name}";
        logUserAction($conn, $currentUserId, 'user_create', $newUserId, $logDetails);
        
        $conn->commit();
        
        // Return success response
        $userData = [
            'id' => $newUserId,
            'name' => $name,
            'email' => $email,
            'role' => $role_name,
            'status' => ucfirst($status)
        ];
        
        jsonResponse(true, "User '{$name}' has been successfully added to the system.", null, $userData);
        
    } else {
        // Rollback transaction on failure
        $conn->rollback();
        $stmt->close();
        
        // Check for specific database errors
        $error = $conn->error;
        if (strpos($error, 'Duplicate entry') !== false) {
            if (strpos($error, 'email') !== false) {
                jsonResponse(false, 'Email address is already in use.', ['email' => 'This email address is already registered.']);
            } elseif (strpos($error, 'nic') !== false) {
                jsonResponse(false, 'NIC number is already in use.', ['nic' => 'This NIC number is already registered.']);
            }
        }
        
        jsonResponse(false, 'Failed to add user. Please try again.');
    }

} catch (Exception $e) {
    // Rollback transaction if active
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    
    // Log the error for debugging
    error_log("Error in save_user.php: " . $e->getMessage());
    
    // Return generic error message to user
    jsonResponse(false, 'An unexpected error occurred. Please try again.');
    
} finally {
    // Ensure connection is closed
    if (isset($conn) && $conn->ping()) {
        $conn->close();
    }
    
    // Clean up output buffer
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
}
?>