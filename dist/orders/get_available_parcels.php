<?php
/**
 * Get Available Parcels API Endpoint
 * Returns unused tracking numbers for a specific courier
 * Used by API dispatch modal functionality
 */

// Set JSON response header
header('Content-Type: application/json');

// Start session for authentication
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit();
}

try {
    // Include database connection
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');
    
    // Check if connection exists
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception('Database connection failed');
    }
    
    // Get courier_id from query parameter
    $courier_id = isset($_GET['courier_id']) ? (int)$_GET['courier_id'] : 0;
    
    if ($courier_id === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing courier ID'
        ]);
        exit;
    }
    
    // Verify courier exists, is active, and has API integration
    $courierCheckSql = "SELECT courier_id, courier_name, has_api, status 
                        FROM couriers 
                        WHERE courier_id = ? AND status = 'active' AND has_api = 1";
    
    $courierStmt = $conn->prepare($courierCheckSql);
    if (!$courierStmt) {
        throw new Exception('Failed to prepare courier check query: ' . $conn->error);
    }
    
    $courierStmt->bind_param("i", $courier_id);
    $courierStmt->execute();
    $courierResult = $courierStmt->get_result();
    
    if ($courierResult->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Courier not found, inactive, or does not have API integration'
        ]);
        exit;
    }
    
    $courier = $courierResult->fetch_assoc();
    $courierStmt->close();
    
    // Get available parcels (unused tracking numbers) for the specified courier
    $parcelsSql = "SELECT 
                        t.tracking_id,
                        t.courier_id,
                        t.status,
                        t.created_at,
                        t.updated_at,
                        c.courier_name
                   FROM tracking t
                   INNER JOIN couriers c ON t.courier_id = c.courier_id
                   WHERE t.courier_id = ? AND t.status = 'unused'
                   ORDER BY t.created_at ASC
                   LIMIT 50";
    
    $parcelsStmt = $conn->prepare($parcelsSql);
    if (!$parcelsStmt) {
        throw new Exception('Failed to prepare parcels query: ' . $conn->error);
    }
    
    $parcelsStmt->bind_param("i", $courier_id);
    $parcelsStmt->execute();
    $parcelsResult = $parcelsStmt->get_result();
    
    // Format the data for the JavaScript frontend
    $formattedParcels = [];
    
    while ($parcel = $parcelsResult->fetch_assoc()) {
        $formattedParcels[] = [
            'id' => $parcel['tracking_id'], // Using tracking_id as the unique identifier
            'tracking_number' => $parcel['tracking_id'],
            'tracking' => $parcel['tracking_id'], // Alternative field name for compatibility
            'courier_name' => $parcel['courier_name'],
            'provider' => $parcel['courier_name'], // Alternative field name for compatibility
            'status' => ucfirst($parcel['status']), // Capitalize first letter (Unused)
            'created_date' => date('M d, Y H:i', strtotime($parcel['created_at'])),
            'created' => date('Y-m-d H:i:s', strtotime($parcel['created_at'])), // Alternative field name
            'updated_at' => $parcel['updated_at'],
            'raw_created_at' => $parcel['created_at'] // Keep original timestamp
        ];
    }
    
    $parcelsStmt->close();
    
    // Get total count of unused parcels for this courier
    $countSql = "SELECT COUNT(*) as total_unused FROM tracking WHERE courier_id = ? AND status = 'unused'";
    $countStmt = $conn->prepare($countSql);
    
    if ($countStmt) {
        $countStmt->bind_param("i", $courier_id);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalUnused = $countResult->fetch_assoc()['total_unused'];
        $countStmt->close();
    } else {
        $totalUnused = count($formattedParcels);
    }
    
    // Success response
    echo json_encode([
        'success' => true,
        'parcels' => $formattedParcels,
        'count' => count($formattedParcels),
        'total_unused' => (int)$totalUnused,
        'showing_limit' => min(50, count($formattedParcels)),
        'courier_info' => [
            'courier_id' => (int)$courier['courier_id'],
            'courier_name' => $courier['courier_name'],
            'has_api' => (int)$courier['has_api'],
            'status' => $courier['status']
        ],
        'message' => count($formattedParcels) > 0 ? 
                    'Found ' . count($formattedParcels) . ' available parcels' : 
                    'No unused parcels available for this courier'
    ]);
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Error in get_available_parcels.php: " . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'parcels' => [],
        'count' => 0
    ]);
    
} finally {
    // Close database connection
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>