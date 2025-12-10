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
    // Debug: First check what courier_id we're looking for
    error_log("Looking for tracking numbers with courier_id: " . $courier_id);
    
    // Get total available unused tracking numbers for this courier
    $count_query = "SELECT COUNT(*) as total FROM tracking 
                    WHERE courier_id = ? AND status = 'unused'";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param("i", $courier_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_available = $count_result->fetch_assoc()['total'];
    
    // Debug: Log how many unused tracking numbers we found
    error_log("Total unused tracking numbers found: " . $total_available);

    // Check if we have any unused tracking numbers
    if ($total_available > 0) {
        
        // Check if we have enough tracking numbers for the request
        if ($count <= $total_available) {
            
            // Get unused tracking numbers for the specified courier (limit to requested count)
            $tracking_query = "SELECT tracking_id FROM tracking 
                WHERE courier_id = ? AND status = 'unused' 
                ORDER BY id ASC 
                LIMIT ?";

            $stmt = $conn->prepare($tracking_query);
            $stmt->bind_param("ii", $courier_id, $count);
            $stmt->execute();
            $result = $stmt->get_result();

            $tracking_numbers = [];
            while ($row = $result->fetch_assoc()) {
                $tracking_numbers[] = $row['tracking_id'];
            }

            // Return success response
            echo json_encode([
                'status' => 'success',
                'tracking_numbers' => $tracking_numbers,
                'available_count' => $total_available,
                'requested_count' => $count,
                'sufficient' => count($tracking_numbers) >= $count
            ]);
            
        } else {
            // Not enough unused tracking numbers available
            
            // Get whatever tracking numbers are available
            $tracking_query = "SELECT tracking_id FROM tracking 
                WHERE courier_id = ? AND status = 'unused' 
                ORDER BY id ASC";

            $stmt = $conn->prepare($tracking_query);
            $stmt->bind_param("i", $courier_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $tracking_numbers = [];
            while ($row = $result->fetch_assoc()) {
                $tracking_numbers[] = $row['tracking_id'];
            }
            
            echo json_encode([
                'status' => 'warning',
                'message' => "Insufficient tracking numbers. Only {$total_available} available, but {$count} requested.",
                'tracking_numbers' => $tracking_numbers,
                'available_count' => $total_available,
                'requested_count' => $count,
                'sufficient' => false
            ]);
        }
        
    } else {
        // No unused tracking numbers available
        
        // Check if there are any tracking numbers at all for this courier
        $all_count_query = "SELECT COUNT(*) as total FROM tracking WHERE courier_id = ?";
        $all_stmt = $conn->prepare($all_count_query);
        $all_stmt->bind_param("i", $courier_id);
        $all_stmt->execute();
        $all_result = $all_stmt->get_result();
        $total_all = $all_result->fetch_assoc()['total'];
        
        if ($total_all == 0) {
            $message = "No tracking numbers found for this courier";
        } else {
            $message = "All tracking numbers for this courier are already used";
        }
        
        echo json_encode([
            'status' => 'warning',
            'message' => $message,
            'tracking_numbers' => [],
            'available_count' => 0,
            'requested_count' => $count,
            'sufficient' => false,
            'total_tracking_numbers' => $total_all
        ]);
    }

} catch (Exception $e) {
    error_log("Database error in get_api_tracking_numbers.php: " . $e->getMessage());
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
    if (isset($all_stmt)) {
        $all_stmt->close();
    }
}
?>