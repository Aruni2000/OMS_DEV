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
    $status = sanitizeInput($_POST['status'] ?? '');
    $lkr_price = sanitizeInput($_POST['lkr_price'] ?? '');
    $product_code = sanitizeInput($_POST['product_code'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');

    // -------------------------------------------------------------------------
    // REQUIRED FIELDS VALIDATION (updated to include description)
    // -------------------------------------------------------------------------
    if (empty($name) || empty($status) || empty($lkr_price) || empty($product_code) || empty($description)) {
        $response['message'] = 'Required fields are missing';

        if (empty($description)) {
            $response['errors']['description'] = 'Description is required';
        }

        echo json_encode($response);
        exit();
    }

    // Validate description minimum length (server-side match to JS)
    if (strlen($description) < 5) {
        $response['errors']['description'] = 'Description must be at least 5 characters long';
        $response['message'] = 'Please correct the errors below.';
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
    $insertQuery = "INSERT INTO products (name, description, lkr_price, status, product_code) 
                    VALUES (?, ?, ?, ?, ?)";
    $insertStmt = $conn->prepare($insertQuery);

    if (!$insertStmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }

    // Bind parameters
    $insertStmt->bind_param("ssdss", $name, $description, $lkr_price, $status, $product_code);

    // Execute the query
    if ($insertStmt->execute()) {
        $product_id = $conn->insert_id;

        // Log the action in user_logs table
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $action_type = 'product_create';
            $details = "New product created - Name: {$name}, Code: {$product_code}, Price: LKR {$lkr_price}, Status: {$status}";

            $logQuery = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) 
                         VALUES (?, ?, ?, ?)";
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
    error_log("Product creation error: " . $e->getMessage());

    $response['success'] = false;
    $response['message'] = 'An error occurred while adding the product. Please try again.';

    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $response['debug_message'] = $e->getMessage();
    }

} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

echo json_encode($response);
exit();
?>
