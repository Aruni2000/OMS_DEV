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

    // Get product ID
    $product_id = intval($_POST['product_id'] ?? 0);

    if ($product_id <= 0) {
        $response['message'] = 'Invalid product ID.';
        echo json_encode($response);
        exit();
    }

    // Check if product exists
    $checkQuery = "SELECT * FROM products WHERE id = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("i", $product_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        $response['message'] = 'Product not found.';
        echo json_encode($response);
        exit();
    }

    $originalProduct = $checkResult->fetch_assoc();
    $checkStmt->close();

    // Get and sanitize form data
    $name = sanitizeInput($_POST['name'] ?? '');
    $status = sanitizeInput($_POST['status'] ?? '');
    $lkr_price = sanitizeInput($_POST['lkr_price'] ?? '');
    $product_code = sanitizeInput($_POST['product_code'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');

    // Server-side validation
    $errors = [];

    // Validate name
    if (empty($name)) {
        $errors['name'] = 'Product name is required';
    } elseif (strlen($name) < 2) {
        $errors['name'] = 'Product name must be at least 2 characters long';
    } elseif (strlen($name) > 255) {
        $errors['name'] = 'Product name is too long (maximum 255 characters)';
    }

    // Validate status
    if (empty($status) || !in_array($status, ['active', 'inactive'])) {
        $errors['status'] = 'Please select a valid status';
    }

    // Validate price
    if (empty($lkr_price) || !is_numeric($lkr_price)) {
        $errors['lkr_price'] = 'Price is required and must be a valid number';
    } else {
        $numPrice = floatval($lkr_price);
        if ($numPrice < 0) {
            $errors['lkr_price'] = 'Price cannot be negative';
        } elseif ($numPrice > 99999999.99) {
            $errors['lkr_price'] = 'Price is too high (maximum 99,999,999.99)';
        }
    }

    // Validate product code
    if (empty($product_code)) {
        $errors['product_code'] = 'Product code is required';
    } elseif (strlen($product_code) < 2) {
        $errors['product_code'] = 'Product code must be at least 2 characters long';
    } elseif (strlen($product_code) > 50) {
        $errors['product_code'] = 'Product code is too long (maximum 50 characters)';
    } elseif (!preg_match('/^[a-zA-Z0-9\-_]+$/', $product_code)) {
        $errors['product_code'] = 'Product code can only contain letters, numbers, hyphens, and underscores';
    }

    // ⭐ MAKE DESCRIPTION **REQUIRED**
    if (empty($description)) {
        $errors['description'] = 'Description is required';
    } elseif (strlen($description) > 65535) {
        $errors['description'] = 'Description is too long (maximum 65,535 characters)';
    }

    // Check for duplicate product code (excluding current product)
    if (empty($errors['product_code'])) {
        $checkCodeQuery = "SELECT id FROM products WHERE product_code = ? AND id != ? LIMIT 1";
        $checkCodeStmt = $conn->prepare($checkCodeQuery);
        $checkCodeStmt->bind_param("si", $product_code, $product_id);
        $checkCodeStmt->execute();
        $codeResult = $checkCodeStmt->get_result();

        if ($codeResult->num_rows > 0) {
            $errors['product_code'] = 'A product with this code already exists';
        }
        $checkCodeStmt->close();
    }

    // If errors exist → stop and return
    if (!empty($errors)) {
        $response['errors'] = $errors;
        $response['message'] = 'Please correct the errors below';
        echo json_encode($response);
        exit();
    }

    // Prepare update query
    $updateQuery = "UPDATE products 
                    SET name = ?, description = ?, lkr_price = ?, status = ?, product_code = ? 
                    WHERE id = ?";

    $updateStmt = $conn->prepare($updateQuery);

    if (!$updateStmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }

    // Bind parameters
    $updateStmt->bind_param("ssdssi", $name, $description, $lkr_price, $status, $product_code, $product_id);

    // Execute the update
    if ($updateStmt->execute()) {

        if ($updateStmt->affected_rows > 0) {

            // Write to logs
            if (isset($_SESSION['user_id'])) {

                $user_id = $_SESSION['user_id'];
                $action_type = 'product_update';
                $changes = [];

                if ($originalProduct['name'] !== $name) {
                    $changes[] = "Name: '{$originalProduct['name']}' → '{$name}'";
                }
                if ($originalProduct['status'] !== $status) {
                    $changes[] = "Status: '{$originalProduct['status']}' → '{$status}'";
                }
                if (floatval($originalProduct['lkr_price']) !== floatval($lkr_price)) {
                    $changes[] = "Price: LKR {$originalProduct['lkr_price']} → LKR {$lkr_price}";
                }
                if ($originalProduct['product_code'] !== $product_code) {
                    $changes[] = "Code: '{$originalProduct['product_code']}' → '{$product_code}'";
                }
                if (($originalProduct['description'] ?? '') !== ($description ?? '')) {
                    $changes[] = "Description updated";
                }

                $details = empty($changes)
                    ? "Product update attempted (no changes detected)"
                    : "Product updated - " . implode(', ', $changes);

                $logQuery = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details)
                             VALUES (?, ?, ?, ?)";

                $logStmt = $conn->prepare($logQuery);
                if ($logStmt) {
                    $logStmt->bind_param("isis", $user_id, $action_type, $product_id, $details);
                    $logStmt->execute();
                    $logStmt->close();
                }
            }

            $response['success'] = true;
            $response['message'] = "Product '{$name}' has been successfully updated!";

        } else {
            $response['success'] = true;
            $response['message'] = "No changes were made to the product.";
        }

        $updateStmt->close();

    } else {
        throw new Exception("Database execution error: " . $updateStmt->error);
    }

} catch (Exception $e) {

    error_log("Product update error: " . $e->getMessage());

    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        if (strpos($e->getMessage(), 'product_code') !== false) {
            $response['errors']['product_code'] = 'A product with this code already exists';
        }
        $response['message'] = 'Please correct the errors below';
    } else {
        $response['message'] = 'An error occurred while updating the product. Please try again.';
    }

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
