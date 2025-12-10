<?php
// Start session and check authentication
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Fetch all active cities
    $sql = "SELECT city_id, city_name, postal_code 
            FROM city_table 
            WHERE is_active = 1 
            ORDER BY city_name ASC";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Database query failed: " . $conn->error);
    }
    
    $cities = [];
    while ($row = $result->fetch_assoc()) {
        $cities[] = [
            'city_id' => $row['city_id'],
            'city_name' => $row['city_name'],
            'postal_code' => $row['postal_code']
        ];
    }
    
    echo json_encode($cities);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>