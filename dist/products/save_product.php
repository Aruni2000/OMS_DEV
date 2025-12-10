<?php
// Start session at the very beginning
session_start();

// Set content type for JSON response
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please log in again.'
    ]);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode([
        'success' => false,
        'message' => 'Security token mismatch. Please refresh the page and try again.'
    ]);
    exit();
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'errors' => []
];

// Function to sanitize input
function sanitizeInput($input) {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

try {
    // Get and sanitize form data
    $name = sanitizeInput($_POST['name'] ?? '');
    $productshortname = sanitizeInput($_POST['product_short_name'] ?? '');
    $status = 'active';  //active
    $lkr_price = sanitizeInput($_POST['lkr_price'] ?? '');
    $product_code = sanitizeInput($_POST['product_code'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    
    // Server-side validation for required fields
    $validationErrors = [];
    
    // Validate name
    if (empty($name)) {
        $validationErrors['name'] = 'Product name is required';
    } elseif (strlen($name) < 2) {
        $validationErrors['name'] = 'Product name must be at least 2 characters long';
    } elseif (strlen($name) > 255) {
        $validationErrors['name'] = 'Product name is too long (maximum 255 characters)';
    }

    // Validate short name
    if (empty($productshortname)) {
        $validationErrors['product_shor_tname'] = 'Product short name is required';
    } elseif (strlen($productshortname) < 2) {
        $validationErrors['product_short_name'] = 'Product short name must be at least 2 characters long';
    } elseif (strlen($productshortname) > 255) {
        $validationErrors['product_short_name'] = 'Product short name is too long (maximum 255 characters)';
    }
    
    // Validate status
    /*if (empty($status)) {
        $validationErrors['status'] = 'Status is required';
    } elseif (!in_array($status, ['active', 'inactive'])) {
        $validationErrors['status'] = 'Invalid status value';
    }*/
    
    // Validate price
    if (empty($lkr_price)) {
        $validationErrors['lkr_price'] = 'Price is required';
    } elseif (!is_numeric($lkr_price)) {
        $validationErrors['lkr_price'] = 'Price must be a valid number';
    } elseif ($lkr_price < 0) {
        $validationErrors['lkr_price'] = 'Price cannot be negative';
    } elseif ($lkr_price > 99999999.99) {
        $validationErrors['lkr_price'] = 'Price is too high (maximum 99,999,999.99)';
    }
    
    // Validate product code
    if (empty($product_code)) {
        $validationErrors['product_code'] = 'Product code is required';
    } elseif (strlen($product_code) < 2) {
        $validationErrors['product_code'] = 'Product code must be at least 2 characters long';
    } elseif (strlen($product_code) > 50) {
        $validationErrors['product_code'] = 'Product code is too long (maximum 50 characters)';
    } elseif (!preg_match('/^[a-zA-Z0-9\-_]+$/', $product_code)) {
        $validationErrors['product_code'] = 'Product code can only contain letters, numbers, hyphens, and underscores';
    }
    
    // Validate description (NOW REQUIRED)
    if (empty($description)) {
        $validationErrors['description'] = 'Description is required';
    } elseif (strlen($description) < 10) {
        $validationErrors['description'] = 'Description must be at least 10 characters long';
    } elseif (strlen($description) > 65535) {
        $validationErrors['description'] = 'Description is too long (maximum 65,535 characters)';
    }
    
    // If there are validation errors, return them
    if (!empty($validationErrors)) {
        $response['errors'] = $validationErrors;
        $response['message'] = 'Please correct the errors below';
        echo json_encode($response);
        exit();
    }
    
    // Check for duplicate product code
    if (!empty($product_code)) {
        $checkCodeQuery = "SELECT id FROM products WHERE product_code = ? LIMIT 1";
        $checkCodeStmt = $conn->prepare($checkCodeQuery);
        $checkCodeStmt->bind_param("s", $product_code);
        $checkCodeStmt->execute();
        $codeResult = $checkCodeStmt->get_result();
        
        if ($codeResult->num_rows > 0) {
            $response['errors']['product_code'] = 'A product with this code already exists';
            $response['message'] = 'Please correct the errors below';
            echo json_encode($response);
            exit();
        }
        $checkCodeStmt->close();
    }
    
    // Prepare insert query
    $insertQuery = "INSERT INTO products (name, description, lkr_price, status, product_code, product_short_name) VALUES (?, ?, ?, ?, ?, ?)"; ///
    $insertStmt = $conn->prepare($insertQuery);
    
    if (!$insertStmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    // Bind parameters (description is no longer nullable, product_code can be null but we already validated it's not empty)
    $insertStmt->bind_param("ssdsss", $name, $description, $lkr_price, $status, $product_code, $productshortname);
    
    // Execute the query
    if ($insertStmt->execute()) {
        $product_id = $conn->insert_id;
        
        // Log the action in user_logs table
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $action_type = 'product_create';
            $details = "New product created - Name: {$name}, Code: {$product_code}, Price: LKR {$lkr_price}, Status: {$status}, short name: {$productshortname}";
            
            $logQuery = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
            $logStmt = $conn->prepare($logQuery);
            
            if ($logStmt) {
                $logStmt->bind_param("isis", $user_id, $action_type, $product_id, $details);
                $logStmt->execute();
                $logStmt->close();
            }
        }
        
        // Close prepared statements
        $insertStmt->close();
        
        // Success response
        $response['success'] = true;
        $response['message'] = "Product '{$name}' has been successfully added to the system!";
        $response['product_id'] = $product_id;
        
    } else {
        throw new Exception("Database execution error: " . $insertStmt->error);
    }
    
} catch (Exception $e) {
    // Log the error (you might want to log to a file instead)
    error_log("Product creation error: " . $e->getMessage());
    
    // Generic error message for security
    $response['success'] = false;
    $response['message'] = 'An error occurred while adding the product. Please try again.';
    
    // For debugging (remove in production)
    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $response['debug_message'] = $e->getMessage();
    }
    
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