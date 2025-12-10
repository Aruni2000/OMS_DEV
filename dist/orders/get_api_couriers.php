<?php
/**
 * Get API Couriers
 * Fetches all couriers that have API integration enabled (has_api = 1)
 * Returns JSON response for AJAX calls
 */

// Set content type to JSON
header('Content-Type: application/json');

// Start session and check authentication
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

try {
    // Query to fetch couriers with API integration
    $sql = "SELECT 
                courier_id,
                courier_name,
                phone_number,
                email,
                status,
                has_api,
                api_key,
                client_id,
                notes
            FROM couriers 
            WHERE has_api = 1 
            AND status = 'active'
            ORDER BY courier_name ASC";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Database query failed: " . $conn->error);
    }
    
    $couriers = [];
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $couriers[] = [
                'courier_id' => $row['courier_id'],
                'courier_name' => htmlspecialchars($row['courier_name']),
                'phone_number' => htmlspecialchars($row['phone_number'] ?? ''),
                'email' => htmlspecialchars($row['email'] ?? ''),
                'status' => $row['status'],
                'has_api' => (int)$row['has_api'],
                'has_api_key' => !empty($row['api_key']),
                'has_client_id' => !empty($row['client_id']),
                'notes' => htmlspecialchars($row['notes'] ?? '')
            ];
        }
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'couriers' => $couriers,
        'count' => count($couriers),
        'message' => count($couriers) > 0 ? 'API couriers loaded successfully' : 'No API couriers found'
    ]);
    
} catch (Exception $e) {
    // Log error (you might want to log this to a file)
    error_log("Error in get_api_couriers.php: " . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error loading API couriers',
        'error' => $e->getMessage()
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>