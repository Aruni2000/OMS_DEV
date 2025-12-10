<?php
/**
 * Get next available tracking number for selected courier
 * Returns JSON response with tracking number availability
 */

// Start session and check authentication
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get courier_id from GET parameter
    $courier_id = isset($_GET['courier_id']) ? (int)$_GET['courier_id'] : 0;
    
    if ($courier_id <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid courier ID provided'
        ]);
        exit();
    }
    
    // Verify courier exists and is active
    $courier_check_sql = "SELECT courier_id, courier_name FROM couriers WHERE courier_id = ? AND status = 'active'";
    $courier_stmt = $conn->prepare($courier_check_sql);
    $courier_stmt->bind_param("i", $courier_id);
    $courier_stmt->execute();
    $courier_result = $courier_stmt->get_result();
    
    if ($courier_result->num_rows === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Courier not found or inactive'
        ]);
        exit();
    }
    
    $courier_data = $courier_result->fetch_assoc();
    
    // Get count of unused tracking numbers for this courier
    $count_sql = "SELECT COUNT(*) as available_count FROM tracking WHERE courier_id = ? AND status = 'unused'";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("i", $courier_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_data = $count_result->fetch_assoc();
    $available_count = $count_data['available_count'];
    
    if ($available_count <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No unused tracking numbers available for ' . $courier_data['courier_name'],
            'courier_name' => $courier_data['courier_name'],
            'available_count' => 0
        ]);
        exit();
    }
    
    // Get the next available tracking number (oldest unused one)
    $tracking_sql = "SELECT tracking_id FROM tracking 
                     WHERE courier_id = ? AND status = 'unused' 
                     ORDER BY created_at ASC 
                     LIMIT 1";
    $tracking_stmt = $conn->prepare($tracking_sql);
    $tracking_stmt->bind_param("i", $courier_id);
    $tracking_stmt->execute();
    $tracking_result = $tracking_stmt->get_result();
    
    if ($tracking_result->num_rows === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No tracking numbers found despite count query showing availability',
            'available_count' => $available_count
        ]);
        exit();
    }
    
    $tracking_data = $tracking_result->fetch_assoc();
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'tracking_number' => $tracking_data['tracking_id'],
        'courier_name' => $courier_data['courier_name'],
        'courier_id' => $courier_id,
        'available_count' => $available_count
    ]);
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Error in get_tracking_number.php: " . $e->getMessage());
    
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred while fetching tracking number. Please try again.',
        'debug' => $e->getMessage() // Remove this in production
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>