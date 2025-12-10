<?php
// Start session
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['courier_id']) || !isset($input['is_default'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$courier_id = (int)$input['courier_id'];
$is_default = (int)$input['is_default']; // Convert to integer (0, 1, 2, or 3)

// Validate is_default value - now accepts 0, 1, 2, and 3
if (!in_array($is_default, [0, 1, 2, 3])) {
    echo json_encode(['success' => false, 'message' => 'Invalid is_default value. Must be 0, 1, 2, or 3']);
    exit();
}

// Get user ID from session for logging
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Start transaction
$conn->begin_transaction();

try {
    // First, verify the courier exists and get all relevant data including API flags
    $checkCourierSql = "SELECT courier_id, courier_name, is_default, has_api_new, has_api_existing FROM couriers WHERE courier_id = ?";
    $checkStmt = $conn->prepare($checkCourierSql);
    $checkStmt->bind_param("i", $courier_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        throw new Exception('Courier not found');
    }
    
    $courierData = $checkResult->fetch_assoc();
    $current_default = $courierData['is_default'];
    $courier_name = $courierData['courier_name'];
    $has_api_new = (int)$courierData['has_api_new'];
    $has_api_existing = (int)$courierData['has_api_existing'];
    $checkStmt->close();
    
    // Check if courier already has the requested default status
    if ($current_default == $is_default) {
        throw new Exception('Courier already has the requested status');
    }
    
    // NEW VALIDATION: Check API access permissions for status 2 and 3
    if ($is_default === 2) {
        // For New API Courier (status 2), check has_api_new
        if ($has_api_new !== 1) {
            throw new Exception("This courier does not support New API.");
        }
    } elseif ($is_default === 3) {
        // For Existing API Courier (status 3), check has_api_existing
        if ($has_api_existing !== 1) {
            throw new Exception("This courier does not support Existing API.");
        }
    }
    
    // Check if trying to set an active status (1, 2, or 3) when another courier already has one
    if (in_array($is_default, [1, 2, 3])) {
        $checkActiveCourierSql = "SELECT courier_id, courier_name, is_default FROM couriers WHERE courier_id != ? AND is_default IN (1, 2, 3)";
        $checkActiveStmt = $conn->prepare($checkActiveCourierSql);
        $checkActiveStmt->bind_param("i", $courier_id);
        $checkActiveStmt->execute();
        $activeResult = $checkActiveStmt->get_result();
        
        if ($activeResult->num_rows > 0) {
            $activeCourier = $activeResult->fetch_assoc();
            $statusNames = [
                1 => 'Default Courier',
                2 => 'New API Courier',
                3 => 'Existing API Courier'
            ];
            
            $currentActiveStatus = $statusNames[$activeCourier['is_default']] ?? 'Active Courier';
            $requestedStatus = $statusNames[$is_default] ?? 'Active Courier';
            
            throw new Exception("Cannot set as {$requestedStatus}. Another courier ('{$activeCourier['courier_name']}') is already set as {$currentActiveStatus}. Please deactivate the other courier first.");
        }
        $checkActiveStmt->close();
        
        // If setting as New API (2) or Existing API (3), check for API credentials
        if ($is_default === 2 || $is_default === 3) {
            // Check if courier has API credentials - only checking api_key now
            $checkApiSql = "SELECT api_key FROM couriers WHERE courier_id = ?";
            $checkApiStmt = $conn->prepare($checkApiSql);
            $checkApiStmt->bind_param("i", $courier_id);
            $checkApiStmt->execute();
            $apiResult = $checkApiStmt->get_result();
            $apiData = $apiResult->fetch_assoc();
            $checkApiStmt->close();
            
            if (empty($apiData['api_key'])) {
                $courierType = ($is_default === 2) ? 'New API Courier' : 'Existing API Courier';
                throw new Exception("Cannot set as {$courierType}. API key is required. Please configure API settings first.");
            }
        }
        
        // Check for unused tracking numbers for Default (1) and Existing API (3) couriers
        if ($is_default === 1 || $is_default === 3) {
            $checkTrackingSql = "SELECT COUNT(*) as unused_count FROM tracking WHERE courier_id = ? AND status = 'unused'";
            $trackingStmt = $conn->prepare($checkTrackingSql);
            $trackingStmt->bind_param("i", $courier_id);
            $trackingStmt->execute();
            $trackingResult = $trackingStmt->get_result();
            $trackingData = $trackingResult->fetch_assoc();
            $trackingStmt->close();
            
            // If no unused tracking numbers available, throw error
            if ($trackingData['unused_count'] == 0) {
                $courierType = ($is_default === 1) ? 'default courier' : 'existing API courier';
                throw new Exception("This courier has no tracking numbers available (unused). Cannot set as {$courierType}.");
            }
        }
    }
    
    // Update the target courier's default status
    $updateCourierSql = "UPDATE couriers SET is_default = ?, updated_at = CURRENT_TIMESTAMP WHERE courier_id = ?";
    $updateStmt = $conn->prepare($updateCourierSql);
    $updateStmt->bind_param("ii", $is_default, $courier_id);
    
    if (!$updateStmt->execute()) {
        throw new Exception('Failed to update courier status: ' . $updateStmt->error);
    }
    
    $updateStmt->close();
    
    // Check if update was successful
    if ($conn->affected_rows === 0) {
        throw new Exception('No rows were updated');
    }
    
    // Prepare log message based on the status change
    $statusNames = [
        0 => 'None',
        1 => 'Default',
        2 => 'New API',
        3 => 'Existing API'
    ];
    
    $old_status = $statusNames[$current_default] ?? 'Unknown';
    $new_status = $statusNames[$is_default] ?? 'Unknown';
    
    $log_message = "{$courier_name}({$courier_id}) set value as ({$new_status})";
    
    // Insert into user_logs table
    if ($user_id) {
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)";
        $logStmt = $conn->prepare($logSql);
        $action_type = "courier_status_update";
        $logStmt->bind_param("isis", $user_id, $action_type, $courier_id, $log_message);
        
        if (!$logStmt->execute()) {
            // Log the error but don't fail the main operation
            error_log("Failed to insert user log: " . $logStmt->error);
        }
        $logStmt->close();
    }
    
    // Get updated courier data
    $getUpdatedSql = "SELECT courier_id, courier_name, is_default FROM couriers WHERE courier_id = ?";
    $getUpdatedStmt = $conn->prepare($getUpdatedSql);
    $getUpdatedStmt->bind_param("i", $courier_id);
    $getUpdatedStmt->execute();
    $updatedResult = $getUpdatedStmt->get_result();
    $updatedCourierData = $updatedResult->fetch_assoc();
    $getUpdatedStmt->close();
    
    // Get unused tracking count for response (only for statuses that use tracking numbers)
    $finalTrackingData = ['unused_count' => 0];
    if (in_array($is_default, [0, 1, 3])) {
        $finalTrackingSql = "SELECT COUNT(*) as unused_count FROM tracking WHERE courier_id = ? AND status = 'unused'";
        $finalTrackingStmt = $conn->prepare($finalTrackingSql);
        $finalTrackingStmt->bind_param("i", $courier_id);
        $finalTrackingStmt->execute();
        $finalTrackingResult = $finalTrackingStmt->get_result();
        $finalTrackingData = $finalTrackingResult->fetch_assoc();
        $finalTrackingStmt->close();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Prepare success response based on the status
    $message = '';
    $statusType = '';
    switch ($is_default) {
        case 0:
            $message = 'Default status removed successfully';
            $statusType = 'none';
            break;
        case 1:
            $message = 'Courier set as default successfully';
            $statusType = 'default';
            break;
        case 2:
            $message = 'Courier set as New API successfully';
            $statusType = 'new_api';
            break;
        case 3:
            $message = 'Courier set as Existing API successfully';
            $statusType = 'existing_api';
            break;
        default:
            $message = 'Courier status updated successfully';
            $statusType = 'unknown';
            break;
    }
    
    $response = [
        'success' => true,
        'message' => $message,
        'data' => [
            'courier_id' => $courier_id,
            'courier_name' => $updatedCourierData['courier_name'],
            'is_default' => (int)$updatedCourierData['is_default'],
            'is_default_bool' => (bool)$updatedCourierData['is_default'],
            'status_type' => $statusType,
            'log_message' => $log_message
        ]
    ];
    
    // Only include tracking count for statuses that use tracking numbers (exclude status 2 - new api)
    if ($is_default !== 2) {
        $response['data']['unused_tracking_count'] = (int)$finalTrackingData['unused_count'];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    // Close database connection
    $conn->close();
}
?>