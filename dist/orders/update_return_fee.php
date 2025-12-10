<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// validate inputs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courier_id = isset($_POST['courier_id']) ? intval($_POST['courier_id']) : 0;
    $return_fee_value = isset($_POST['return_fee_value']) ? floatval($_POST['return_fee_value']) : 0.00;

    if ($courier_id <= 0) {
        http_response_code(400);
        echo "Invalid courier ID.";
        exit;
    }

    // update query
    $sql = "UPDATE couriers 
            SET return_fee_value = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE courier_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        http_response_code(500);
        echo "Prepare failed: " . $conn->error;
        exit;
    }

    $stmt->bind_param("di", $return_fee_value, $courier_id);

    if ($stmt->execute()) {
        echo "Return fee updated successfully!";
    } else {
        http_response_code(500);
        echo "Error updating return fee: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
} else {
    http_response_code(405);
    echo "Invalid request method.";
}
