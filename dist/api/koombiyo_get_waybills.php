<?php  
// Start session to get user information
session_start();

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Get input values
$courier_id = isset($_POST['courier_id']) ? (int)$_POST['courier_id'] : 12;
$limit = isset($_POST['waybills_count']) ? (int)$_POST['waybills_count']: 10;

// Function to return JSON error response
function returnError($message, $code = 400) {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode(['error' => true, 'message' => $message]);
    exit();
}

// Function to log user actions
function logUserAction($conn, $userId, $actionType, $orderId, $details) {
    if (!$userId) return false;
    
    $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
    $logStmt = $conn->prepare($logSql);
    
    if (!$logStmt) {
        error_log("Failed to prepare user log statement: " . $conn->error);
        return false;
    }
    
    $logStmt->bind_param("isis", $userId, $actionType, $orderId, $details);
    $result = $logStmt->execute();
    
    if (!$result) {
        error_log("Failed to log user action: " . $logStmt->error);
    }
    
    $logStmt->close();
    return $result;
}

// Function to get courier name by ID
function getCourierName($conn, $courierId) {
    $sql = "SELECT courier_name FROM couriers WHERE courier_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return "Unknown Courier";
    }
    
    $stmt->bind_param("i", $courierId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['courier_name'];
    } else {
        $stmt->close();
        return "Unknown Courier";
    }
}

// Get current user ID from session
$currentUserId = $_SESSION['user_id'] ?? null;
if (!$currentUserId) {
    returnError("User session not found. Please login again.");
}

// Validate limit range
if ($limit < 1 || $limit > 100) {
    returnError('Limit must be between 1 and 100');
}

// Get API key from database
$api_key = null;
$apiKeySql = "SELECT api_key FROM couriers WHERE courier_id = ? AND api_key IS NOT NULL";
$stmt = $conn->prepare($apiKeySql);
$stmt->bind_param("i", $courier_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $api_key = $row['api_key'];
} else {
    returnError("No API key found for courier ID: " . $courier_id);
}
$stmt->close();

// Function to generate CSV from waybills data
function generateWaybillCSV($data, $filename = 'waybills.csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    // Create file pointer connected to output stream
    $output = fopen('php://output', 'w');
    
    // Handle different data structures
    $waybills = array();
    
    // Case 1: Data has 'waybills' key (current API response structure)
    if (isset($data['waybills']) && is_array($data['waybills'])) {
        $waybills = $data['waybills'];
    }
    // Case 2: Data has 'data' key (alternative API response)
    elseif (isset($data['data']) && is_array($data['data'])) {
        $waybills = $data['data'];
    }
    // Case 3: Direct array of waybills
    elseif (is_array($data)) {
        // Check if first element looks like a waybill
        $firstElement = reset($data);
        if (is_array($firstElement) && isset($firstElement['waybill_id'])) {
            $waybills = $data;
        }
    }
    
    // Write header - just "waybill" as requested
    fputcsv($output, array('tracking'));
    
    // Write waybill IDs
    foreach ($waybills as $waybill) {
        if (is_array($waybill) && isset($waybill['waybill_id'])) {
            fputcsv($output, array($waybill['waybill_id']));
        }
    }
    
    fclose($output);
    
    // Return the count of waybills for logging
    return count($waybills);
}

// API call
if (empty($api_key)) {
    returnError("API key is empty for courier ID: " . $courier_id);
}

$ch = curl_init();  
curl_setopt($ch, CURLOPT_URL, "https://application.koombiyodelivery.lk/api/Waybils/users");    
curl_setopt($ch, CURLOPT_POST, 1);    
curl_setopt($ch, CURLOPT_POSTFIELDS, "apikey=" . $api_key . "&limit=" . $limit);  
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));  
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  

$server_output = curl_exec($ch);  
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);  

if ($http_code !== 200) {
    returnError("API request failed with HTTP code: " . $http_code . ". Response: " . substr($server_output, 0, 100));
}

$response = json_decode($server_output, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    returnError("Invalid JSON response from API: " . json_last_error_msg());
}

if (empty($response)) {
    returnError("Empty response from API");
}

// Check if we have waybills data
$hasData = false;
if (isset($response['waybills']) && is_array($response['waybills']) && !empty($response['waybills'])) {
    $hasData = true;
} elseif (isset($response['data']) && is_array($response['data']) && !empty($response['data'])) {
    $hasData = true;
} elseif (is_array($response) && !empty($response)) {
    $firstElement = reset($response);
    if (is_array($firstElement) && isset($firstElement['waybill_id'])) {
        $hasData = true;
    }
}

if (!$hasData) {
    returnError("No waybills found in the API response");
}

// Generate CSV if we reach here
$filename = 'waybills_courier_' . $courier_id . '_' . date('Y-m-d_H-i-s') . '.csv';

// Get courier name for logging
$courierName = getCourierName($conn, $courier_id);

// Generate CSV and get count
$waybillCount = generateWaybillCSV($response, $filename);

// Log the waybill download activity
$logDetails = "Waybill CSV download: {$waybillCount} tracking numbers downloaded for courier ID: {$courier_id} ({$courierName})";
$logResult = logUserAction($conn, $currentUserId, 'waybill_download', 0, $logDetails);

if (!$logResult) {
    // Log the error but don't stop the download process
    error_log("Failed to log waybill download action for user ID: " . $currentUserId);
}

// The CSV generation function will handle the exit, so this won't be reached
// But we keep it here for completeness
exit();
?>