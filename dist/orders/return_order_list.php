<?php
/**
 * Return Complete Scanner System
 * This page allows scanning tracking numbers to update return_handover status
 * Includes batch processing and status tracking functionality
 * Updated to handle both order_header and order_items tables
 */

// Start session management
session_start();

// Authentication check - redirect if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear output buffers before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

/**
 * PROCESS TRACKING NUMBERS AJAX REQUEST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_tracking') {
    header('Content-Type: application/json');
    
    $tracking_number = isset($_POST['tracking_number']) ? trim($_POST['tracking_number']) : '';
    $scan_mode = isset($_POST['scan_mode']) ? trim($_POST['scan_mode']) : 'return complete';
    
    if (empty($tracking_number)) {
        echo json_encode(['success' => false, 'message' => 'Tracking number is required']);
        exit();
    }
    
    try {
        if ($scan_mode === 'test_mode') {
            // Test mode - simulate processing without database changes
            sleep(1); // Simulate processing time
            echo json_encode([
                'success' => true,
                'message' => 'Test mode - No database changes made',
                'order_info' => 'Order #' . rand(1000, 9999) . ' - Items: ' . rand(1, 5),
                'tracking_number' => $tracking_number
            ]);
        } else {
            // Live mode - update database
            
            // First, check if tracking number exists in orders
            $checkSql = "SELECT order_id, status, tracking_number FROM order_header WHERE tracking_number = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("s", $tracking_number);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Tracking number not found in system',
                    'tracking_number' => $tracking_number
                ]);
                exit();
            }
            
            $order = $result->fetch_assoc();
            
            // Check if order is eligible for return_handover status
            if ($order['status'] !== 'return complete') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Order status must be "return complete" to update to "return_handover". Current status: ' . $order['status'],
                    'tracking_number' => $tracking_number
                ]);
                exit();
            }
            
            // Start transaction for data integrity
            $conn->begin_transaction();
            
            try {
                // Update order_header status to return_handover
                $updateHeaderSql = "UPDATE order_header SET status = 'return_handover', updated_at = NOW() WHERE tracking_number = ?";
                $updateHeaderStmt = $conn->prepare($updateHeaderSql);
                $updateHeaderStmt->bind_param("s", $tracking_number);
                
                if (!$updateHeaderStmt->execute()) {
                    throw new Exception("Failed to update order_header: " . $conn->error);
                }
                
                // Update all order_items for this order to return_handover status
                $updateItemsSql = "UPDATE order_items SET status = 'return_handover', updated_at = NOW() WHERE order_id = ?";
                $updateItemsStmt = $conn->prepare($updateItemsSql);
                $updateItemsStmt->bind_param("i", $order['order_id']);
                
                if (!$updateItemsStmt->execute()) {
                    throw new Exception("Failed to update order_items: " . $conn->error);
                }
                
                // Get updated counts
                $itemsUpdated = $updateItemsStmt->affected_rows;
                
                // Log user action
                $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
                $action_type = 'return_handover_scan';
                $inquiry_id = $order['order_id']; // Using order_id as inquiry_id
                $details = json_encode([
                    'tracking_number' => $tracking_number,
                    'previous_status' => 'return complete',
                    'new_status' => 'return_handover',
                    'items_updated' => $itemsUpdated,
                    'scan_method' => 'bulk_scanner',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);
                
                $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
                $logStmt = $conn->prepare($logSql);
                $logStmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
                
                if (!$logStmt->execute()) {
                    throw new Exception("Failed to insert user log: " . $conn->error);
                }
                
                // Commit transaction
                $conn->commit();
                
                // Get order details for response
                $orderDetailsSql = "SELECT o.order_id, o.total_amount, c.name as customer_name,
                                           COUNT(oi.item_id) as total_items
                                   FROM order_header o 
                                   LEFT JOIN customers c ON o.customer_id = c.customer_id 
                                   LEFT JOIN order_items oi ON o.order_id = oi.order_id
                                   WHERE o.tracking_number = ?
                                   GROUP BY o.order_id";
                $detailsStmt = $conn->prepare($orderDetailsSql);
                $detailsStmt->bind_param("s", $tracking_number);
                $detailsStmt->execute();
                $orderDetails = $detailsStmt->get_result()->fetch_assoc();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Status updated to return_handover successfully',
                    'order_info' => sprintf(
                        'Order #%d - Customer: %s - Amount: Rs%s - Items Updated: %d - Action Logged',
                        $orderDetails['order_id'],
                        $orderDetails['customer_name'] ?: 'N/A',
                        number_format($orderDetails['total_amount'], 2),
                        $itemsUpdated
                    ),
                    'tracking_number' => $tracking_number,
                    'items_updated' => $itemsUpdated,
                    'action_logged' => true
                ]);
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                throw $e;
            }
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'System error: ' . $e->getMessage(),
            'tracking_number' => $tracking_number
        ]);
    }
    
    exit();
}

// Include navigation components
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Return Scanner - OMS Admin Portal</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
</head>

<style>
    /* Scanner-specific styling */
    .scanner-container {
        background: white;
        border-radius: 15px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        overflow: hidden;
        margin-bottom: 30px;
    }

    .scanner-content {
        padding: 40px;
    }

    .input-group {
        margin-bottom: 20px;
    }

    .input-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #333;
    }

    .input-group textarea, .input-group select {
        width: 100%;
        padding: 15px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 16px;
        transition: border-color 0.3s ease;
        height: 60px;
    }

    .input-group textarea:focus, .input-group select:focus {
        outline: none;
        border-color: #4facfe;
        box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
    }

    .scan-btn {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        color: white;
        border: none;
        padding: 10px 21px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 25%;
        margin-bottom: 20px;
    }

    .scan-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
    }

    .scan-btn:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
    }

    /* Progress bar styling */
    .progress-bar {
        width: 100%;
        height: 8px;
        background-color: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 20px;
        display: none;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        border-radius: 4px;
        transition: width 0.3s ease;
        width: 0%;
    }

    /* Results styling */
    .results {
        margin-top: 20px;
    }

    .result-item {
        padding: 15px;
        margin: 10px 0;
        border-radius: 8px;
        border-left: 4px solid;
        background: #f8f9fa;
    }

    .result-success {
        border-color: #28a745;
        background: #d4edda;
        color: #155724;
    }

    .result-error {
        border-color: #dc3545;
        background: #f8d7da;
        color: #721c24;
    }

    .result-info {
        border-color: #17a2b8;
        background: #d1ecf1;
        color: #0c5460;
    }

    .tracking-number {
        font-weight: bold;
        font-family: monospace;
    }

    .order-info {
        font-size: 0.9em;
        margin-top: 5px;
        opacity: 0.8;
    }

    .processing-status {
        text-align: center;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        margin: 20px 0;
        display: none;
    }

    .stats-container {
        display: flex;
        justify-content: space-around;
        margin: 20px 0;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
    }

    .stat-item {
        text-align: center;
    }

    .stat-number {
        font-size: 2em;
        font-weight: bold;
        color: #667eea;
    }

    .stat-label {
        font-size: 0.9em;
        color: #666;
    }
</style>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Return Scanner</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Scanner Container -->
                <div class="scanner-container">
                    <div class="scanner-content">
                        
                        <div class="scanner-section">
                            <div class="input-group">
                                <label for="trackingInput">Enter Tracking Numbers </label>
                                <textarea id="trackingInput" rows="5" placeholder="Enter tracking numbers here..." style="resize: vertical; min-height: 120px;"></textarea>
                            </div>
                            
                            <button class="scan-btn" id="processBtn" onclick="processTracking()">
                            Process Tracking Numbers
                            </button>

                            <div class="progress-bar" id="progressBar">
                                <div class="progress-fill" id="progressFill"></div>
                            </div>

                            <div class="processing-status" id="processingStatus">
                                <div>Processing tracking numbers...</div>
                                <div id="currentTracking"></div>
                            </div>

                            <div class="stats-container" id="statsContainer" style="display: none;">
                                <div class="stat-item">
                                    <div class="stat-number" id="successCount">0</div>
                                    <div class="stat-label">Successful</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number" id="errorCount">0</div>
                                    <div class="stat-label">Errors</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number" id="totalCount">0</div>
                                    <div class="stat-label">Total</div>
                                </div>
                            </div>
                        </div>
                        <div class="results" id="results"></div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <!-- Include JavaScript files -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    
    <script>
        // JavaScript functions for scanner functionality
        function processTracking() {
            const trackingInput = document.getElementById('trackingInput').value.trim();
            if (!trackingInput) {
                alert('Please enter at least one tracking number');
                return;
            }

            const trackingNumbers = trackingInput.split('\n').filter(num => num.trim() !== '');
            const total = trackingNumbers.length;
            let successCount = 0;
            let errorCount = 0;

            // Show progress bar and status
            document.getElementById('progressBar').style.display = 'block';
            document.getElementById('processingStatus').style.display = 'block';
            document.getElementById('statsContainer').style.display = 'none';

            // Clear previous results
            document.getElementById('results').innerHTML = '';

            // Process each tracking number
            trackingNumbers.forEach((trackingNumber, index) => {
                const currentTrackingElement = document.getElementById('currentTracking');
                currentTrackingElement.textContent = `Processing ${index + 1} of ${total}: ${trackingNumber}`;

                // Update progress bar
                const progress = ((index + 1) / total) * 100;
                document.getElementById('progressFill').style.width = `${progress}%`;

                // Make AJAX request
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                successCount++;
                            } else {
                                errorCount++;
                            }

                            // Update results
                            const resultDiv = document.createElement('div');
                            resultDiv.className = `result-item ${response.success ? 'result-success' : 'result-error'}`;
                            resultDiv.innerHTML = `
                                <div class="tracking-number">${trackingNumber}</div>
                                <div>${response.message}</div>
                                ${response.order_info ? `<div class="order-info">${response.order_info}</div>` : ''}
                            `;
                            document.getElementById('results').appendChild(resultDiv);

                            // Update counters
                            document.getElementById('successCount').textContent = successCount;
                            document.getElementById('errorCount').textContent = errorCount;
                            document.getElementById('totalCount').textContent = total;

                            // Show stats when complete
                            if (successCount + errorCount === total) {
                                document.getElementById('statsContainer').style.display = 'flex';
                                document.getElementById('processingStatus').style.display = 'none';
                                document.getElementById('progressBar').style.display = 'none';
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            errorCount++;
                            
                            // Update error result
                            const resultDiv = document.createElement('div');
                            resultDiv.className = 'result-item result-error';
                            resultDiv.innerHTML = `
                                <div class="tracking-number">${trackingNumber}</div>
                                <div>Error processing request</div>
                            `;
                            document.getElementById('results').appendChild(resultDiv);
                            
                            // Update counters
                            document.getElementById('errorCount').textContent = errorCount;
                            document.getElementById('totalCount').textContent = total;
                            
                            // Show stats when complete
                            if (successCount + errorCount === total) {
                                document.getElementById('statsContainer').style.display = 'flex';
                                document.getElementById('processingStatus').style.display = 'none';
                                document.getElementById('progressBar').style.display = 'none';
                            }
                        }
                    }
                };
                xhr.send(`action=process_tracking&tracking_number=${encodeURIComponent(trackingNumber.trim())}`);
            });
        }
    </script>
</body>
</html>