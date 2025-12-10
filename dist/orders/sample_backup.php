<?php
/**
 * Return Complete Scanner System
 * This page allows scanning tracking numbers to update return_handover status
 * Includes batch processing and status tracking functionality
 * Updated to handle both order_header and order_items tables
 * Added dispatched orders table for reference
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
    $scan_mode = isset($_POST['scan_mode']) ? trim($_POST['scan_mode']) : 'return_complete';
    
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
            if ($order['status'] !== 'return_complete') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Order status must be "return_complete" to update to "return_handover". Current status: ' . $order['status'],
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
                    'previous_status' => 'return_complete',
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

/**
 * SEARCH AND PAGINATION PARAMETERS FOR ORDERS TABLE
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$tracking_id = isset($_GET['tracking_id']) ? trim($_GET['tracking_id']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * DATABASE QUERIES FOR ORDERS TABLE
 * Main query to fetch orders with customer and payment information
 * Filtered for return_handover status and both interface types
 */

// Base SQL for counting total records - RETURN_HANDOVER STATUS
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.created_by = u2.id
             WHERE i.status = 'return_handover'
             AND (i.interface = 'individual' OR i.interface = 'leads')";

// Main query with all required joins - RETURN_HANDOVER STATUS
$sql = "SELECT i.*, c.name as customer_name, 
               p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
               u1.name as paid_by_name,
               u2.name as creator_name
        FROM order_header i 
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.created_by = u2.id
        WHERE i.status = 'return_handover'
        AND (i.interface = 'individual' OR i.interface = 'leads')";

// Build search conditions
$searchConditions = [];

// General search condition (existing functionality)
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        i.order_id LIKE '%$searchTerm%' OR 
                        c.name LIKE '%$searchTerm%' OR 
                        i.issue_date LIKE '%$searchTerm%' OR 
                        i.due_date LIKE '%$searchTerm%' OR 
                        i.total_amount LIKE '%$searchTerm%' OR
                        i.tracking_number LIKE '%$searchTerm%' OR
                        i.pay_status LIKE '%$searchTerm%' OR
                        u2.name LIKE '%$searchTerm%')";
}

// Specific Order ID filter
if (!empty($order_id_filter)) {
    $orderIdTerm = $conn->real_escape_string($order_id_filter);
    $searchConditions[] = "i.order_id LIKE '%$orderIdTerm%'";
}

// Specific Customer Name filter
if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "c.name LIKE '%$customerNameTerm%'";
}

// Tracking ID filter
if (!empty($tracking_id)) {
    $trackingTerm = $conn->real_escape_string($tracking_id);
    $searchConditions[] = "i.tracking_number LIKE '%$trackingTerm%'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(i.issue_date) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(i.issue_date) <= '$dateToTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " AND (" . implode(' AND ', $searchConditions) . ")";
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY i.order_id DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
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
                                🔍 Process Tracking Numbers
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

                <!-- Orders Table Section -->
                <div class="orders-section" style="margin-top: 40px;">
                    <h6 class="section-title" style="margin-bottom: 20px; color: #333; font-weight: 600;">Return Handover Orders</h6>
                    
                    <!-- Order Tracking and Filter Section -->
                    <div class="tracking-container">
                        <form class="tracking-form" method="GET" action="">
                            <div class="form-group">
                                <label for="order_id_filter">Order ID</label>
                                <input type="text" id="order_id_filter" name="order_id_filter" 
                                       placeholder="Enter order ID" 
                                       value="<?php echo htmlspecialchars($order_id_filter); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="customer_name_filter">Customer Name</label>
                                <input type="text" id="customer_name_filter" name="customer_name_filter" 
                                       placeholder="Enter customer name" 
                                       value="<?php echo htmlspecialchars($customer_name_filter); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="tracking_id">Tracking ID</label>
                                <input type="text" id="tracking_id" name="tracking_id" 
                                       placeholder="Enter tracking ID" 
                                       value="<?php echo htmlspecialchars($tracking_id); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="date_from">Date From</label>
                                <input type="date" id="date_from" name="date_from" 
                                       value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="date_to">Date To</label>
                                <input type="date" id="date_to" name="date_to" 
                                       value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            
                            <div class="form-group">
                                <div class="button-group">
                                    <button type="submit" class="search-btn">
                                        <i class="fas fa-search"></i>
                                        Search
                                    </button>
                                    <button type="button" class="search-btn" onclick="clearFilters()" style="background: #6c757d;">
                                        <i class="fas fa-times"></i>
                                        Clear
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Order Count Display -->
                    <div class="order-count-container">
                        <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                        <div class="order-count-dash">-</div>
                        <div class="order-count-subtitle">Total Return Handover Orders</div>
                    </div>

                    <!-- Orders Table -->
                    <div class="table-wrapper">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer Name</th>
                                    <th>Total Amount</th>
                                    <th>Pay Status</th>
                                    <th>Tracking Number</th>
                                    <th>Processed By</th>
                                    <th>Created By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="ordersTableBody">
                                <?php if ($result && $result->num_rows > 0): ?>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <!-- Order ID -->
                                            <td class="order-id">
                                                <?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>
                                            </td>
                                            
                                            <!-- Customer Name with ID -->
                                            <td class="customer-name">
                                                <?php
                                                $customerName = isset($row['customer_name']) ? htmlspecialchars($row['customer_name']) : 'N/A';
                                                $customerId = isset($row['customer_id']) ? htmlspecialchars($row['customer_id']) : '';
                                                echo $customerName . ($customerId ? " ($customerId)" : "");
                                                ?>
                                            </td>
                                           
                                            <!-- Total Amount with Currency -->
                                            <td class="amount">
                                                <?php
                                                $amount = isset($row['total_amount']) ? (float)$row['total_amount'] : 0;
                                                $currency = isset($row['currency']) ? $row['currency'] : 'lkr';
                                                $currencySymbol = ($currency == 'usd') ? '$' : 'Rs';
                                                echo $currencySymbol . number_format($amount, 2);
                                                ?>
                                            </td>
                                              
                                            <!-- Payment Status Badge -->
                                            <td>
                                                <?php
                                                $payStatus = isset($row['pay_status']) ? $row['pay_status'] : 'unpaid';
                                                if ($payStatus == 'paid'): ?>
                                                    <span class="status-badge pay-status-paid">Paid</span>
                                                <?php else: ?>
                                                    <span class="status-badge pay-status-unpaid">Unpaid</span>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <!-- Tracking Number -->
                                            <td class="tracking-number">
                                                <?php
                                                if (isset($row['tracking_number']) && !empty($row['tracking_number'])) {
                                                    echo '<span style="cursor: pointer; color: #007bff; text-decoration: underline;" onclick="copyTrackingNumber(\'' . htmlspecialchars($row['tracking_number']) . '\')" title="Click to copy tracking number">' . htmlspecialchars($row['tracking_number']) . '</span>';
                                                } else {
                                                    echo '<span style="color: #999; font-style: italic;">Not assigned</span>';
                                                }
                                                ?>
                                            </td>
                                            
                                            <!-- Processed By User -->
                                            <td>
                                                <?php
                                                echo isset($row['paid_by_name']) ? htmlspecialchars($row['paid_by_name']) : 'N/A';
                                                ?>
                                            </td>
                                            
                                            <!-- Created By User -->
                                            <td>
                                                <?php
                                                echo isset($row['creator_name']) ? htmlspecialchars($row['creator_name']) : 'N/A';
                                                ?>
                                            </td>
                                            
                                            <!-- Action Buttons -->
                                            <td class="actions">
                                                <?php
                                                $orderId = isset($row['order_id']) ? htmlspecialchars($row['order_id']) : '';
                                                ?>
                                                <button class="action-btn view-btn" title="View Order Details" 
                                                        onclick="openOrderModal('<?php echo $orderId; ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                            No return handover orders found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                        onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                    <?php echo $i; ?>
                                </button>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <!-- Order View Modal -->
    <div id="orderModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Order Details</h3>
                <button class="modal-close" onclick="closeOrderModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalContent">
                <div class="modal-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    Loading order details...
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" onclick="closeOrderModal()">Close</button>
                <button class="modal-btn modal-btn-primary" onclick="downloadOrder()" id="downloadBtn" style="display:none;">
                    <i class="fas fa-download"></i>
                    Download
                </button>
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
                            resultDiv.className = response.success ? 'result-success' : 'result-error';
                            resultDiv.innerHTML = `<strong>${trackingNumber}:</strong> ${response.message}`;
                            document.getElementById('results').appendChild(resultDiv);

                            // Update counters
                            document.getElementById('successCount').textContent = successCount;
                            document.getElementById('errorCount').textContent = errorCount;
                            document.getElementById('totalCount').textContent = total;

                            // Show stats when complete
                            if (index === trackingNumbers.length - 1) {
                                document.getElementById('statsContainer').style.display = 'flex';
                                document.getElementById('processingStatus').style.display = 'none';
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                        }
                    }
                };
                xhr.send(`action=process_tracking&tracking_number=${encodeURIComponent(trackingNumber)}`);
            });
        }

        function copyTrackingNumber(trackingNumber) {
            navigator.clipboard.writeText(trackingNumber).then(() => {
                alert('Tracking number copied to clipboard: ' + trackingNumber);
            }).catch(err => {
                console.error('Failed to copy tracking number: ', err);
            });
        }

        function clearFilters() {
            window.location.href = window.location.pathname;
        }

        function openOrderModal(orderId) {
            const modal = document.getElementById('orderModal');
            const modalContent = document.getElementById('modalContent');
            
            // Show loading state
            modalContent.innerHTML = '<div class="modal-loading"><i class="fas fa-spinner fa-spin"></i> Loading order details...</div>';
            modal.style.display = 'flex';
            
            // Load order details via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `/OMS/dist/ajax/order_details.php?order_id=${orderId}`, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    modalContent.innerHTML = xhr.responseText;
                    document.getElementById('downloadBtn').style.display = 'inline-block';
                    document.getElementById('downloadBtn').setAttribute('data-order-id', orderId);
                } else {
                    modalContent.innerHTML = '<div class="modal-error">Error loading order details</div>';
                }
            };
            xhr.send();
        }

        function closeOrderModal() {
            document.getElementById('orderModal').style.display = 'none';
        }

        function downloadOrder() {
            const orderId = document.getElementById('downloadBtn').getAttribute('data-order-id');
            window.open(`/OMS/dist/ajax/download_order.php?order_id=${orderId}`, '_blank');
        }
    </script>
</body>
</html>