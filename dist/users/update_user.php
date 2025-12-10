<?php
// update_user.php - Fixed version that only logs when actual changes are made
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

// Function to detect actual changes in user data
function detectUserChanges($existingData, $newData, $passwordChanged = false) {
    $changes = [];
    
    // Check each field for changes
    if ($existingData['name'] !== $newData['name']) {
        $changes[] = "Name changed from '{$existingData['name']}' to '{$newData['name']}'";
    }
    
    if ($existingData['email'] !== $newData['email']) {
        $changes[] = "Email changed from '{$existingData['email']}' to '{$newData['email']}'";
    }
    
    if ($existingData['mobile'] !== $newData['mobile']) {
        $changes[] = "Mobile changed from '{$existingData['mobile']}' to '{$newData['mobile']}'";
    }
    
    if ($existingData['nic'] !== $newData['nic']) {
        $changes[] = "NIC changed from '{$existingData['nic']}' to '{$newData['nic']}'";
    }
    
    if ($passwordChanged) {
        $changes[] = "Password updated";
    }
    
    return $changes;
}

// Main processing
try {
    // Get current user ID for logging
    $currentUserId = $_SESSION['user_id'] ?? null;
    if (!$currentUserId) {
        jsonResponse(false, 'User session not found. Please login again.');
    }

    // Validate CSRF Token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        jsonResponse(false, 'Security token validation failed. Please refresh the page and try again.');
    }

    // Get user ID to update
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        jsonResponse(false, 'Invalid user ID provided.');
    }

    // Check if user exists and get current data
    $checkStmt = $conn->prepare("SELECT id, name, email, nic, mobile, address, status, role_id FROM users WHERE id = ?");
    if (!$checkStmt) {
        jsonResponse(false, 'Database error occurred. Please try again.');
    }
    
    $checkStmt->bind_param("i", $userId);
    $checkStmt->execute();
    $existingUser = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if (!$existingUser) {
        jsonResponse(false, 'User not found.');
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
    if (empty($mobile)) $fieldErrors['mobile'] = "Mobile number is required.";
    if (empty($nic)) $fieldErrors['nic'] = "NIC number is required.";
    if (empty($address)) $fieldErrors['address'] = "Address is required.";
    if (!isset($role_mapping[$role])) $fieldErrors['role'] = "Please select a valid role.";
    
    // Email format validation (security critical)
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = "Invalid email format.";
    }
    
    // Check for duplicate email (only if email has changed)
    if (!empty($email) && $email !== $existingUser['email']) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        if ($stmt) {
            $stmt->bind_param("si", $email, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $fieldErrors['email'] = "Email address is already in use by another user.";
            }
            $stmt->close();
        }
    }
    
    // Check for duplicate mobile/phone number (only if mobile has changed)
    if (!empty($mobile) && $mobile !== $existingUser['mobile']) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ? AND id != ?");
        if ($stmt) {
            $stmt->bind_param("si", $mobile, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $fieldErrors['mobile'] = "Mobile number is already registered with another user.";
            }
            $stmt->close();
        }
    }
    
    // Check for duplicate NIC (only if NIC has changed)
    if (!empty($nic) && $nic !== $existingUser['nic']) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE nic = ? AND id != ?");
        if ($stmt) {
            $stmt->bind_param("si", $nic, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $fieldErrors['nic'] = "NIC number is already registered with another user.";
            }
            $stmt->close();
        }
    }

    // If validation errors exist, return them
    if (!empty($fieldErrors)) {
        jsonResponse(false, 'Please correct the errors and try again.', $fieldErrors);
    }

    // NEW: Check if any actual changes were made before proceeding
    $role_id = $role_mapping[$role]['id'];
    $passwordChanged = !empty($password);
    
    $newData = [
        'name' => $name,
        'email' => $email,
        'mobile' => $mobile,
        'nic' => $nic,
        'address' => $address,
        'status' => $status,
        'role_id' => $role_id
    ];
    
    // Check if any field has actually changed (excluding password for now)
    $hasChanges = false;
    foreach ($newData as $field => $newValue) {
        if ($existingUser[$field] !== $newValue) {
            $hasChanges = true;
            break;
        }
    }
    
    // Also check if password was provided (indicating password change)
    if ($passwordChanged) {
        $hasChanges = true;
    }
    
    // If no changes detected, return early without logging
    if (!$hasChanges) {
        jsonResponse(false, 'No changes detected. Please modify at least one field to update the user.');
    }

    // Begin database transaction
    $conn->begin_transaction();
    
    $role_name = $role_mapping[$role]['name'];
    
    // Prepare update query - with or without password
    if (!empty($password)) {
        // Update with new password
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ?, mobile = ?, nic = ?, 
                               address = ?, status = ?, role_id = ?, updated_at = NOW() 
                               WHERE id = ?");
        
        if ($stmt === false) {
            $conn->rollback();
            jsonResponse(false, 'Database error occurred. Please try again.');
        }
        
        $stmt->bind_param("ssssssiii", 
            $name, $email, $hashed_password, $mobile, $nic, $address, 
            $status, $role_id, $userId
        );
    } else {
        // Update without changing password
        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, mobile = ?, nic = ?, 
                               address = ?, status = ?, role_id = ?, updated_at = NOW() 
                               WHERE id = ?");
        
        if ($stmt === false) {
            $conn->rollback();
            jsonResponse(false, 'Database error occurred. Please try again.');
        }
        
        $stmt->bind_param("ssssssii", 
            $name, $email, $mobile, $nic, $address, 
            $status, $role_id, $userId
        );
    }

    // Execute update
    if ($stmt->execute()) {
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        if ($affectedRows > 0) {
            // FIXED: Only log when actual changes were made and affected rows > 0
            // Detect specific changes for detailed logging
            $changes = detectUserChanges($existingUser, $newData, $passwordChanged);
            
            if (!empty($changes)) {
                $logDetails = "User account updated: " . implode(', ', $changes);
                logUserAction($conn, $currentUserId, 'user_update', $userId, $logDetails);
            }
            
            $conn->commit();
            
            // Return success response
            $userData = [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => $role_name,
                'status' => ucfirst($status)
            ];
            
            jsonResponse(true, "User '{$name}' has been successfully updated.", null, $userData);
        } else {
            $conn->rollback();
            jsonResponse(false, 'No changes were made to the user.');
        }
        
    } else {
        // Rollback transaction on failure
        $conn->rollback();
        $stmt->close();
        
        // Check for specific database errors
        $error = $conn->error;
        if (strpos($error, 'Duplicate entry') !== false) {
            if (strpos($error, 'email') !== false) {
                jsonResponse(false, 'Email address is already in use.', ['email' => 'This email address is already registered with another user.']);
            } elseif (strpos($error, 'nic') !== false) {
                jsonResponse(false, 'NIC number is already in use.', ['nic' => 'This NIC number is already registered with another user.']);
            }
        }
        
        jsonResponse(false, 'Failed to update user. Please try again.');
    }

} catch (Exception $e) {
    // Rollback transaction if active
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    
    // Log the error for debugging
    error_log("Error in update_user.php: " . $e->getMessage());
    
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