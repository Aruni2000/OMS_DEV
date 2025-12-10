<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get form data
    $courier_id = isset($_POST['courier_id']) ? intval($_POST['courier_id']) : 0;
    $client_id = isset($_POST['client_id']) ? trim($_POST['client_id']) : '';
    $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';
    $origin_city_name = isset($_POST['origin_city_name']) ? trim($_POST['origin_city_name']) : null;
    $origin_state_name = isset($_POST['origin_state_name']) ? trim($_POST['origin_state_name']) : null;

    // Validate input
    if ($courier_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid courier ID']);
        exit();
    }

    if (empty($api_key)) {
        echo json_encode(['success' => false, 'message' => 'API Key is required']);
        exit();
    }

    // Check if courier exists
    $checkSql = "SELECT * FROM couriers WHERE courier_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $courier_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Courier not found']);
        exit();
    }

    $courier = $checkResult->fetch_assoc();

    // Track changes
    $changes = [];

    if ($courier['client_id'] !== $client_id) {
        $changes[] = "Client ID changed from '" . ($courier['client_id'] ?: 'empty') . "' to '" . $client_id . "'";
    }

    if ($courier['api_key'] !== $api_key) {
        $changes[] = "API Key changed from '" . ($courier['api_key'] ?: 'empty') . "' to '" . $api_key . "'";
    }

    // Only update origin fields for courier_id = 13
    if ($courier_id === 13 || $courier_id == '13') {
        if ($courier['origin_city_name'] !== $origin_city_name) {
            $changes[] = "Origin City changed from '" . ($courier['origin_city_name'] ?: 'empty') . "' to '" . $origin_city_name . "'";
        }
        if ($courier['origin_state_name'] !== $origin_state_name) {
            $changes[] = "Origin State changed from '" . ($courier['origin_state_name'] ?: 'empty') . "' to '" . $origin_state_name . "'";
        }
    } else {
        // Ensure origin fields are null for other couriers
        $origin_city_name = null;
        $origin_state_name = null;
    }

    if (empty($changes)) {
        echo json_encode(['success' => false, 'message' => 'No changes were made to the API settings']);
        exit();
    }

    // Update courier settings
    $updateSql = "UPDATE couriers SET client_id = ?, api_key = ?, origin_city_name = ?, origin_state_name = ?, updated_at = CURRENT_TIMESTAMP WHERE courier_id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("ssssi", $client_id, $api_key, $origin_city_name, $origin_state_name, $courier_id);

    if ($updateStmt->execute()) {
        // Logging
        $log_details = "API settings updated: " . $courier['courier_name'] . " (ID: " . $courier_id . "). Changes: " . implode(' | ', $changes);
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
        $action_type = 'API_UPDATE';

        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
        $logStmt = $conn->prepare($logSql);
        $logStmt->bind_param("isis", $user_id, $action_type, $courier_id, $log_details);
        $logStmt->execute();
        $logStmt->close();

        echo json_encode(['success' => true, 'message' => 'API settings updated successfully for ' . $courier['courier_name'], 'changes' => $changes]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update API settings: ' . $conn->error]);
    }

    $updateStmt->close();
    $checkStmt->close();

} catch (Exception $e) {
    error_log('API Update Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred while updating API settings']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
