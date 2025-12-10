<?php
// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Check if required parameters are provided
if (!isset($_GET['courier_id']) || !isset($_GET['count'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required parameters'
    ]);
    exit;
}

$courier_id = intval($_GET['courier_id']);
$count = intval($_GET['count']);

// Validate parameters
if ($courier_id <= 0 || $count <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid parameters'
    ]);
    exit;
}

try {
    // Get total available unused tracking numbers for this courier
    $count_query = "SELECT COUNT(*) as total FROM tracking 
                    WHERE courier_id = ? AND status = 'unused'";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param("i", $courier_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_available = $count_result->fetch_assoc()['total'];
    
    // Get unused tracking numbers for the specified courier (limit to requested count)
    $tracking_query = "SELECT tracking_id FROM tracking 
        WHERE courier_id = ? AND status = 'unused' 
        ORDER BY tracking_id ASC 
        LIMIT ?";
    
    $stmt = $conn->prepare($tracking_query);
    $stmt->bind_param("ii", $courier_id, $count);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tracking_numbers = [];
    while ($row = $result->fetch_assoc()) {
        $tracking_numbers[] = $row['tracking_id'];
    }
    
    // Return response
    echo json_encode([
        'status' => 'success',
        'tracking_numbers' => $tracking_numbers,
        'available_count' => $total_available,
        'requested_count' => $count,
        'sufficient' => count($tracking_numbers) >= $count
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} finally {
    // Close statements
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($count_stmt)) {
        $count_stmt->close();
    }
}
?>