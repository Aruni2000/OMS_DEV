<?php
/**
 * Pending Orders Management System
 * This page displays orders with status 'pending' for individual interface
 * Includes search, pagination, and modal view functionality
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


// NEW: Get current user's role information
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;

// If user_id or role_id is not in session, fetch from database
if ($current_user_id == 0 || $current_user_role == 0) {
    // Try to get user info from session username or email
    $session_identifier = isset($_SESSION['username']) ? $_SESSION['username'] : 
                         (isset($_SESSION['email']) ? $_SESSION['email'] : '');
    
    if ($session_identifier) {
        $userQuery = "SELECT u.id, u.role_id FROM users u WHERE u.email = ? OR u.name = ? LIMIT 1";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param("ss", $session_identifier, $session_identifier);
        $stmt->execute();
        $userResult = $stmt->get_result();
        
        if ($userResult && $userResult->num_rows > 0) {
            $userData = $userResult->fetch_assoc();
            $current_user_id = (int)$userData['id'];
            $current_user_role = (int)$userData['role_id'];
            
            // Update session with missing data
            $_SESSION['user_id'] = $current_user_id;
            $_SESSION['role_id'] = $current_user_role;
        }
        $stmt->close();
    }
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$pay_status_filter = isset($_GET['pay_status_filter']) ? trim($_GET['pay_status_filter']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// NEW: Role-based access control condition
$roleBasedCondition = "";
if ($current_user_role != 1) {
    // Non-admin users can only see their own orders
    $roleBasedCondition = " AND i.user_id = $current_user_id";
}

/**
 * DATABASE QUERIES
 * Main query to fetch orders with customer and payment information
 * Filtered for individual interface and pending status only
 */

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.created_by = u2.id
             WHERE i.interface IN ('individual', 'leads') AND i.status = 'pending'$roleBasedCondition";

// Main query with all required joins
$sql = "SELECT i.*, c.name as customer_name, 
               p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
               u1.name as paid_by_name,
               u2.name as creator_name
        FROM order_header i 
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.created_by = u2.id
        WHERE i.interface IN ('individual', 'leads') AND i.status = 'pending'$roleBasedCondition";

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

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(i.issue_date) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(i.issue_date) <= '$dateToTerm'";
}

// Payment Status filter
if (!empty($pay_status_filter)) {
    $payStatusTerm = $conn->real_escape_string($pay_status_filter);
    $searchConditions[] = "i.pay_status = '$payStatusTerm'";
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
    <title>Order Management Admin Portal - Pending Orders</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <style>
.print-btn {
    background-color: #28a745;
    color: white;
    border: none;
    padding: 8px 10px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    margin-left: 5px;
    transition: background-color 0.3s;
}

.print-btn:hover {
    background-color: #218838;
}

.print-btn:active {
    transform: scale(0.95);
}

.actions {
    white-space: nowrap;
}
</style>
</head>
<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Pending Orders</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
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
                            <label for="pay_status_filter">Payment Status</label>
                            <select id="pay_status_filter" name="pay_status_filter">
                                <option value="">All Payment Status</option>
                                <option value="paid" <?php echo ($pay_status_filter == 'paid') ? 'selected' : ''; ?>>Paid</option>
                                <option value="unpaid" <?php echo ($pay_status_filter == 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                                <!-- <option value="partial" <?php echo ($pay_status_filter == 'partial') ? 'selected' : ''; ?>>Partial</option> -->
                            </select>
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
                    <div class="order-count-subtitle">Total Orders</div>
                </div>

           <!-- Orders Table with Bulk Selection -->
<div class="table-wrapper">
    <!-- Bulk Actions Bar -->
    <div class="bulk-actions-bar" id="bulkActionsBar" style="display: none;">
    <div class="bulk-actions-left">
        <span id="selectedCount">0</span> orders selected
    </div>
    <div class="bulk-actions-right">
        <button class="bulk-btn bulk-dispatch-btn" onclick="bulkMarkAsDispatched()">
            <i class="fas fa-truck"></i> Mark as Dispatched
        </button>
        <!-- NEW: API Dispatch Button -->
        <button class="bulk-btn bulk-api-dispatch-btn" onclick="openApiDispatchModal()">
            <i class="fas fa-cloud"></i> API Dispatch
        </button>
        <button class="bulk-btn bulk-clear-btn" onclick="clearBulkSelection()">
            <i class="fas fa-times"></i> Clear Selection
        </button>
    </div>
</div>
    

    <table class="orders-table">
        <thead>
            <tr>
                <th>
                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                </th>
                <th>Order ID</th>
                <th>Customer Name</th>
                <th>Issue Date - Due Date</th>
                <th>Total Amount</th>
                <th>Pay Status</th>
                <th>Created By</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="ordersTableBody">
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <!-- Bulk Selection Checkbox -->
                        <td>
                            <input type="checkbox" class="order-checkbox" 
                                   value="<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>"
                                   onchange="updateBulkSelection()">
                        </td>
                        
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
                        <!-- Issue Date - Due Date -->
                        <td class="date-range">
                            <?php
                            $issueDate = isset($row['issue_date']) ? date('Y-m-d', strtotime($row['issue_date'])) : 'N/A';
                            $dueDate = isset($row['due_date']) ? date('Y-m-d', strtotime($row['due_date'])) : 'N/A';
                            echo "<div class='date-container'>";
                            echo "<span class='issue-date'>" . $issueDate . "</span>";
                            echo "<span class='date-separator'> - </span>";
                            echo "<span class='due-date'>" . $dueDate . "</span>";
                            echo "</div>";
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
                            <?php elseif ($payStatus == 'partial'): ?>
                                <span class="status-badge pay-status-partial">Partial</span>
                            <?php else: ?>
                                <span class="status-badge pay-status-unpaid">Unpaid</span>
                            <?php endif; ?>
                        </td>
                        
                       <!-- Created By User -->
                              <td>
                                <?php
    $creatorName = isset($row['creator_name']) ? htmlspecialchars($row['creator_name']) : 'N/A';
    $interface = isset($row['interface']) ? $row['interface'] : '';
    
    echo $creatorName;
    
    // Display (leads) if interface is 'leads'
    if ($interface == 'leads') {
        echo "<br><span style='color: #666; font-size: 0.9em;'>(leads)</span>";
    }
    ?>
</td>
                 <!-- Action Buttons - Updated to pass interface parameter -->
<td class="actions">
    <div class="action-buttons-group">
        <button class="action-btn view-btn" title="View Order Details" 
                onclick="openOrderModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', '<?php echo isset($row['interface']) ? htmlspecialchars($row['interface']) : ''; ?>')">
            <i class="fas fa-eye"></i>
        </button>
        
        <button class="action-btn paid-btn" title="Mark as Paid" 
                onclick="markAsPaid('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
            <i class="fas fa-dollar-sign"></i>
        </button>
        
        <button class="action-btn dispatch-btn" title="Mark as Dispatched" 
                onclick="openDispatchModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
            <i class="fas fa-truck"></i>
        </button>
        
        <button class="action-btn <?php echo ($row['call_log'] == 0) ? 'answer-btn' : 'no-answer-btn'; ?>" 
                title="<?php echo ($row['call_log'] == 0) ? 'Mark as Answered' : 'Mark as No Answer'; ?>" 
                onclick="openAnswerModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', <?php echo $row['call_log']; ?>)">
            <i class="fas <?php echo ($row['call_log'] == 0) ? 'fas fa-phone-slash' : 'fas fa-phone'; ?>"></i>
        </button>
        
        <button class="action-btn cancel-btn" title="Cancel Order" 
                onclick="cancelOrder('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
            <i class="fas fa-times-circle"></i>
        </button>
           <button class="action-btn print-btn" title="Print Order" 
            onclick="printOrder('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
        <i class="fas fa-print"></i>
    </button>
    </div>
</td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                        No pending orders found
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Include MODAL for View Order -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/order_view_modal.php'); ?>

<!-- Modal for Marking Order as Paid -->
   <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/paid_mark_modal.php'); ?>

<!-- DISPATCH MODAL HTML -->
   <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/dispatch_modal.php'); ?>

<!-- BULK DISPATCH MODAL HTML  -->
   <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/bulk_dispatch_modal.php'); ?>

<!-- ANSWER STATUS MODAL -->
 <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/answer_status_modal.php'); ?>


<!-- Cancel Order Modal  -->
 <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/cancel_order_modal.php'); ?>

<!--  ADD THE API DISPATCH MODAL HTML -->
 <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/api_dispatch.php'); ?>

<script>
        /**
         * JavaScript functionality for pending order management
         */
        
        let currentOrderId = null;
        let currentInterface = null;
        let currentPaymentSlip = null; // Store payment slip filename
        let currentPayStatus = null; // Store payment status

        // Clear all filter inputs
        function clearFilters() {
            document.getElementById('order_id_filter').value = '';
            document.getElementById('customer_name_filter').value = '';
            document.getElementById('date_from').value = '';
            document.getElementById('date_to').value = '';
            document.getElementById('pay_status_filter').value = '';
            
            // Submit the form to clear filters
            window.location.href = window.location.pathname;
        }

    
// MODIFIED: Enhanced openOrderModal function
function openOrderModal(orderId, interface = null) {
    if (!orderId || orderId.trim() === '') {
        alert('Order ID is required to view order details.');
        return;
    }
    
    console.log('Opening modal for Order ID:', orderId, 'Interface:', interface);
    
    currentOrderId = orderId.trim();
    currentInterface = interface;
    
    const modal = document.getElementById('orderModal');
    const modalContent = document.getElementById('modalContent');
    const downloadBtn = document.getElementById('downloadBtn');
    const viewPaymentSlipBtn = document.getElementById('viewPaymentSlipBtn');
    
    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Show loading state
    modalContent.innerHTML = `
        <div class="modal-loading">
            <i class="fas fa-spinner fa-spin"></i>
            Loading ${interface === 'leads' ? 'lead' : 'order'} details for Order ID: ${currentOrderId}...
        </div>
    `;
    downloadBtn.style.display = 'none';
    viewPaymentSlipBtn.style.display = 'none';
    
    // Determine which PHP file to use based on interface
    const phpFile = (interface === 'leads') ? '../leads/leads_download.php' : 'download_order.php';
    const fetchUrl = phpFile + '?id=' + encodeURIComponent(currentOrderId);
    
    console.log('Fetching from:', fetchUrl);
    
    fetch(fetchUrl, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        }
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(data => {
        console.log('Data received:', data.length, 'characters');
        if (data.trim() === '') {
            throw new Error('No data received from server');
        }
        modalContent.innerHTML = data;
        downloadBtn.style.display = 'inline-flex';
        
        // MODIFIED: Check for payment slip and update button visibility
        checkPaymentSlipAvailability();
    })
    .catch(error => {
        console.error('Error loading order details:', error);
        const itemType = (interface === 'leads') ? 'lead' : 'order';
        modalContent.innerHTML = `
            <div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
                <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                <h4>Error Loading ${itemType.charAt(0).toUpperCase() + itemType.slice(1)} Details</h4>
                <p>Order ID: ${currentOrderId}</p>
                <p>Error: ${error.message}</p>
                <p>Please check if the ${phpFile} file exists and is accessible.</p>
                <button onclick="retryLoadOrder()" class="btn btn-primary" style="margin-top: 10px;">
                    <i class="fas fa-redo"></i> Retry
                </button>
            </div>
        `;
    });
}

// MODIFIED: Function to check payment slip availability - always show button for paid orders
function checkPaymentSlipAvailability() {
    if (!currentOrderId) return;
    
    // Fetch payment slip information from server
    fetch('get_payment_slip_info.php?order_id=' + encodeURIComponent(currentOrderId), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentPaymentSlip = data.payment_slip;
            currentPayStatus = data.pay_status;
            
            const viewPaymentSlipBtn = document.getElementById('viewPaymentSlipBtn');
            
            // MODIFIED: Show button for all paid orders, regardless of slip availability
            if (currentPayStatus === 'paid') {
                viewPaymentSlipBtn.style.display = 'inline-flex';
            } else {
                viewPaymentSlipBtn.style.display = 'none';
            }
        } else {
            console.log('No payment slip information available');
        }
    })
    .catch(error => {
        console.error('Error checking payment slip:', error);
    });
}

// MODIFIED: Function to view payment slip with no-slip message
function viewPaymentSlip() {
    // Check if payment slip exists
    if (!currentPaymentSlip || currentPaymentSlip.trim() === '') {
        alert('This order has no payment slip.');
        return;
    }
    
    // Construct the payment slip URL
    const slipUrl = '/OMS/dist/uploads/payment_slips/' + encodeURIComponent(currentPaymentSlip);
    
    // Open payment slip in new tab
    window.open(slipUrl, '_blank');
}

// Retry loading order 
function retryLoadOrder() {
    if (currentOrderId) {
        openOrderModal(currentOrderId, currentInterface);
    }
}

// Close order modal 
function closeOrderModal() {
    const modal = document.getElementById('orderModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    currentOrderId = null;
    currentInterface = null;
    currentPaymentSlip = null;
    currentPayStatus = null;
}

// Download order 
function downloadOrder() {
    if (!currentOrderId) {
        alert('No order selected for download.');
        return;
    }
    
    const phpFile = (currentInterface === 'leads') ? '../leads/leads_download.php' : 'download_order.php';
    const downloadUrl = phpFile + '?id=' + encodeURIComponent(currentOrderId) + '&download=1';
    
    console.log('Downloading from:', downloadUrl);
    window.open(downloadUrl, '_blank');
}

// Close modal when clicking outside 
document.getElementById('orderModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeOrderModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeOrderModal();
    }
});

// Initialize page functionality when DOM is loaded 
document.addEventListener('DOMContentLoaded', function() {
    console.log('Orders page loaded, initializing...');
    
    const tableRows = document.querySelectorAll('.orders-table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(2px)';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.transform = 'translateX(0)';
        });
    });
    
    const modal = document.getElementById('orderModal');
    const modalContent = document.getElementById('modalContent');
    if (!modal || !modalContent) {
        console.error('Modal elements not found! Check HTML structure.');
    }
});

        // Mark as Paid Modal Functionality
function markAsPaid(orderId) {
    if (!orderId || orderId.trim() === '') {
        alert('Order ID is required to mark as paid.');
        return;
    }
    
    console.log('Opening mark as paid modal for Order ID:', orderId);
    
    // Set the order ID in the hidden input
    document.getElementById('modal_order_id').value = orderId.trim();
    
    // Reset the form
    document.getElementById('markPaidForm').reset();
    document.getElementById('fileInfo').style.display = 'none';
    
    // Show the modal
    const modal = document.getElementById('markPaidModal');
    modal.classList.add('show');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Close the Mark as Paid modal
function closePaidModal() {
    const modal = document.getElementById('markPaidModal');
    modal.classList.remove('show');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reset form
    document.getElementById('markPaidForm').reset();
    document.getElementById('fileInfo').style.display = 'none';
}

// Handle file selection
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('payment_slip');
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            // Validate file size (2MB limit)
            if (file.size > 2 * 1024 * 1024) {
                alert('File size must be less than 2MB');
                fileInput.value = '';
                fileInfo.style.display = 'none';
                return;
            }
            
            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
            if (!allowedTypes.includes(file.type)) {
                alert('Please select a valid file format (JPG, JPEG, PNG, PDF)');
                fileInput.value = '';
                fileInfo.style.display = 'none';
                return;
            }
            
            // Show file info
            fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
            fileInfo.style.display = 'block';
        } else {
            fileInfo.style.display = 'none';
        }
    });
});

// Remove selected file
function removeFile() {
    document.getElementById('payment_slip').value = '';
    document.getElementById('fileInfo').style.display = 'none';
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Handle form submission
document.getElementById('markPaidForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const orderId = document.getElementById('modal_order_id').value;
    const fileInput = document.getElementById('payment_slip');
    const submitBtn = document.getElementById('submitPaidBtn');
    
    if (!fileInput.files[0]) {
        alert('Please select a payment slip file');
        return;
    }
    
    // Show loading state
    submitBtn.innerHTML = '<span class="loading-spinner"></span> Processing...';
    submitBtn.disabled = true;
    
    // Create FormData object
    const formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('payment_slip', fileInput.files[0]);
    formData.append('action', 'mark_paid');
    
    // Send the request
    fetch('mark_paid.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Order marked as paid successfully!');
            closePaidModal();
            // Reload the page to reflect changes
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to mark order as paid'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while processing the payment. Please try again.');
    })
    .finally(() => {
        // Reset button state
        submitBtn.innerHTML = '<i class="fas fa-check me-1"></i>Mark as Paid';
        submitBtn.disabled = false;
    });
});

// Close modal when clicking outside
document.getElementById('markPaidModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePaidModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('markPaidModal');
        if (modal.classList.contains('show')) {
            closePaidModal();
        }
    }
});


// Open Dispatch Modal
function openDispatchModal(orderId) {
    if (!orderId || orderId.trim() === '') {
        alert('Order ID is required to dispatch order.');
        return;
    }
    
    console.log('Opening dispatch modal for Order ID:', orderId);
    
    // Set the order ID in the hidden input
    document.getElementById('dispatch_order_id').value = orderId.trim();
    
    // Reset the form
    document.getElementById('dispatch-order-form').reset();
    document.getElementById('dispatch_order_id').value = orderId.trim(); // Reset it again after form reset
    
    // Reset tracking number display
    document.getElementById('tracking_number_display').innerHTML = 
        '<span class="text-muted">Select a courier to see available tracking number</span>';
    
    // Disable submit button initially
    document.getElementById('dispatch-submit-btn').disabled = true;
    
    // Show the modal
    const modal = document.getElementById('dispatchOrderModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Close Dispatch Modal
function closeDispatchModal() {
    const modal = document.getElementById('dispatchOrderModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reset form
    document.getElementById('dispatch-order-form').reset();
    document.getElementById('tracking_number_display').innerHTML = 
        '<span class="text-muted">Select a courier to see available tracking number</span>';
    document.getElementById('dispatch-submit-btn').disabled = true;
}

// Fetch tracking number for selected courier
function fetchTrackingNumber(courierId) {
    const trackingDisplay = document.getElementById('tracking_number_display');
    const submitBtn = document.getElementById('dispatch-submit-btn');
    
    if (!courierId) {
        trackingDisplay.innerHTML = '<span class="text-muted">Select a courier to see available tracking number</span>';
        submitBtn.disabled = true;
        return;
    }
    
    // Show loading state
    trackingDisplay.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Loading tracking number...</span>';
    submitBtn.disabled = true;
    
    // Fetch tracking number from PHP endpoint
    fetch(`get_tracking_number.php?courier_id=${courierId}`, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.status === 'success') {
            trackingDisplay.innerHTML = 
                `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Next tracking number: <strong>${data.tracking_number}</strong></span>
                <small class="d-block text-muted mt-1">${data.available_count} tracking numbers available</small>`;
            submitBtn.disabled = false;
        } else {
            trackingDisplay.innerHTML = 
                `<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>${data.message}</span>`;
            submitBtn.disabled = true;
        }
    })
    .catch(error => {
        console.error('Error fetching tracking number:', error);
        trackingDisplay.innerHTML = 
            '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Error loading tracking number. Please try again.</span>';
        submitBtn.disabled = true;
    });
}

// Initialize Dispatch Functionality
document.addEventListener('DOMContentLoaded', function() {
    const carrierSelect = document.getElementById('carrier');
    const submitBtn = document.getElementById('dispatch-submit-btn');
    const dispatchForm = document.getElementById('dispatch-order-form');
    const modal = document.getElementById('dispatchOrderModal');
    
    // Handle courier selection change
    if (carrierSelect) {
        carrierSelect.addEventListener('change', function() {
            const selectedCourierId = this.value;
            
            if (selectedCourierId) {
                // Fetch tracking number for selected courier
                fetchTrackingNumber(selectedCourierId);
            } else {
                // Reset display when no courier is selected
                document.getElementById('tracking_number_display').innerHTML = 
                    '<span class="text-muted">Select a courier to see available tracking number</span>';
                submitBtn.disabled = true;
            }
        });
    }
    
    // Handle dispatch form submission
    if (dispatchForm) {
        dispatchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const orderId = document.getElementById('dispatch_order_id').value;
            const carrier = document.getElementById('carrier').value;
            const dispatchNotes = document.getElementById('dispatch_notes').value;
            
            if (!orderId || !carrier) {
                alert('Please select a courier service before dispatching');
                return;
            }
            
            // Confirm dispatch
            if (!confirm('Are you sure you want to dispatch this order? This action cannot be undone.')) {
                return;
            }
            
            // Show loading state
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Dispatching...';
            submitBtn.disabled = true;
            
            // Create FormData object
            const formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('carrier', carrier);
            formData.append('dispatch_notes', dispatchNotes);
            formData.append('action', 'dispatch_order');
            
            // Send the request
            fetch('process_dispatch.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert('Order dispatched successfully!' + 
                          (data.tracking_number ? ' Tracking number: ' + data.tracking_number : ''));
                    closeDispatchModal();
                    // Reload the page to reflect changes
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to dispatch order'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while dispatching the order. Please try again.');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = '<i class="fas fa-truck me-1"></i>Confirm Dispatch';
                submitBtn.disabled = !carrier; // Enable only if carrier is selected
            });
        });
    }
    
    // Close modal when clicking outside
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeDispatchModal();
            }
        });
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('dispatchOrderModal');
            if (modal && modal.style.display === 'flex') {
                closeDispatchModal();
            }
        }
    });
});

// Additional helper functions
function markAsDispatched(orderId) {
    // This is an alternative function name that calls openDispatchModal
    // Keep this for backward compatibility
    openDispatchModal(orderId);
}

// Add this debug script to help identify the modal footer issue
// Place this in your existing JavaScript section or in the console

function debugModalFooter() {
    console.log('=== Modal Footer Debug ===');
    
    const modal = document.getElementById('dispatchOrderModal');
    const modalFooter = modal?.querySelector('.modal-footer');
    const cancelBtn = modalFooter?.querySelector('.modal-btn-secondary');
    const confirmBtn = modalFooter?.querySelector('.modal-btn-primary');
    
    console.log('Modal element:', modal);
    console.log('Modal footer element:', modalFooter);
    console.log('Cancel button:', cancelBtn);
    console.log('Confirm button:', confirmBtn);
    
    if (modalFooter) {
        const footerStyles = window.getComputedStyle(modalFooter);
        console.log('Footer display:', footerStyles.display);
        console.log('Footer visibility:', footerStyles.visibility);
        console.log('Footer height:', footerStyles.height);
        console.log('Footer padding:', footerStyles.padding);
        console.log('Footer background:', footerStyles.backgroundColor);
    }
    
    if (cancelBtn) {
        const cancelStyles = window.getComputedStyle(cancelBtn);
        console.log('Cancel button display:', cancelStyles.display);
        console.log('Cancel button visibility:', cancelStyles.visibility);
        console.log('Cancel button background:', cancelStyles.backgroundColor);
        console.log('Cancel button color:', cancelStyles.color);
    }
    
    if (confirmBtn) {
        const confirmStyles = window.getComputedStyle(confirmBtn);
        console.log('Confirm button display:', confirmStyles.display);
        console.log('Confirm button visibility:', confirmStyles.visibility);
        console.log('Confirm button background:', confirmStyles.backgroundColor);
        console.log('Confirm button color:', confirmStyles.color);
        console.log('Confirm button disabled:', confirmBtn.disabled);
    }
}

// Call this function after opening the modal to debug
// Add this line to your openDispatchModal function temporarily:
// setTimeout(() => debugModalFooter(), 100);



/**
 * JAVASCRIPT FUNCTIONS - Add these functions to your existing script section
 * Handles the Answer/No Answer modal functionality
 */

// Global variable to store current modal state
let currentAnswerOrderId = null;
let currentCallLog = null;

/**
 * Open Answer Status Modal
 * @param {string} orderId - The order ID to update
 * @param {number} callLogStatus - Current call_log status (0 or 1)
 */
function openAnswerModal(orderId, callLogStatus) {
    if (!orderId || orderId.trim() === '') {
        alert('Order ID is required to update call status.');
        return;
    }
    
    console.log('Opening answer modal for Order ID:', orderId, 'Current call_log:', callLogStatus);
    
    // Store current values
    currentAnswerOrderId = orderId.trim();
    currentCallLog = parseInt(callLogStatus);
    
    // Set form values
    document.getElementById('answer_order_id').value = currentAnswerOrderId;
    document.getElementById('current_call_log').value = currentCallLog;
    document.getElementById('displayOrderId').textContent = currentAnswerOrderId;
    
    // Determine new status (toggle: 0->1, 1->0)
    const newCallLog = currentCallLog === 0 ? 1 : 0;
    document.getElementById('new_call_log').value = newCallLog;
    
    // Update modal content based on action
    updateModalContent(currentCallLog, newCallLog);
    
    // Reset form
    document.getElementById('answer-status-form').reset();
    // Re-set the hidden fields after reset
    document.getElementById('answer_order_id').value = currentAnswerOrderId;
    document.getElementById('current_call_log').value = currentCallLog;
    document.getElementById('new_call_log').value = newCallLog;
    document.getElementById('displayOrderId').textContent = currentAnswerOrderId;
    
    // Show the modal
    const modal = document.getElementById('answerStatusModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Focus on textarea
    setTimeout(() => {
        document.getElementById('answer_reason').focus();
    }, 100);
}

/**
 * Update Modal Content Based on Action
 * @param {number} currentStatus - Current call_log value
 * @param {number} newStatus - New call_log value to set
 */
function updateModalContent(currentStatus, newStatus) {
    const modalTitle = document.getElementById('answerModalTitle');
    const alertMessage = document.getElementById('answerAlertMessage');
    const alertText = document.getElementById('alertText');
    const reasonLabel = document.getElementById('reasonLabel');
    const reasonHelp = document.getElementById('reasonHelp');
    const submitButtonText = document.getElementById('submitButtonText');
    const submitBtn = document.getElementById('answer-submit-btn');
    
    if (newStatus === 1) {
        // Marking as ANSWERED (call_log = 1)
        modalTitle.innerHTML = '<i class="fas fa-check-circle me-2"></i>Mark as Answered';
        alertMessage.className = 'alert alert-success mb-3';
        alertText.textContent = 'Mark this order as answered and provide call notes';
        reasonLabel.innerHTML = 'Answer Notes <span class="text-danger">*</span>';
        reasonHelp.textContent = 'Please provide details about the customer conversation';
        submitButtonText.textContent = 'Mark as Answered';
        submitBtn.style.background = '#28a745 !important';
        document.getElementById('answer_reason').placeholder = 'Enter details about customer conversation...';
    } else {
        // Marking as NO ANSWER (call_log = 0)
        modalTitle.innerHTML = '<i class="fas fa-times-circle me-2"></i>Mark as No Answer';
        alertMessage.className = 'alert alert-warning mb-3';
        alertText.textContent = 'Mark this order as no answer and provide reason';
        reasonLabel.innerHTML = 'No Answer Reason <span class="text-danger">*</span>';
        reasonHelp.textContent = 'Please specify why the customer did not answer';
        submitButtonText.textContent = 'Mark as No Answer';
        submitBtn.style.background = '#dc3545 !important';
        document.getElementById('answer_reason').placeholder = 'Enter reason for no answer (busy, unreachable, etc.)...';
    }
}

/**
 * Close Answer Status Modal
 */
function closeAnswerModal() {
    const modal = document.getElementById('answerStatusModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reset form and variables
    document.getElementById('answer-status-form').reset();
    currentAnswerOrderId = null;
    currentCallLog = null;
}

/**
 * Initialize Answer Status Functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    const answerForm = document.getElementById('answer-status-form');
    const modal = document.getElementById('answerStatusModal');
    
    // Handle answer form submission
    if (answerForm) {
        answerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const orderId = document.getElementById('answer_order_id').value;
            const newCallLog = document.getElementById('new_call_log').value;
            const answerReason = document.getElementById('answer_reason').value.trim();
            const submitBtn = document.getElementById('answer-submit-btn');
            
            // Validation
            if (!orderId || !answerReason) {
                alert('Please fill in all required fields');
                return;
            }
            
            if (answerReason.length < 5) {
                alert('Please provide more detailed notes (minimum 5 characters)');
                return;
            }
            
            // Confirm action
            const actionText = (newCallLog == 1) ? 'mark as answered' : 'mark as no answer';
            if (!confirm(`Are you sure you want to ${actionText} for Order ID: ${orderId}?`)) {
                return;
            }
            
            // Show loading state
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
            submitBtn.disabled = true;
            
            // Create FormData object
            const formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('call_log', newCallLog);
            formData.append('answer_reason', answerReason);
            formData.append('action', 'update_call_status');
            
            // Send the request
            fetch('update_call_status.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const statusText = (newCallLog == 1) ? 'answered' : 'no answer';
                    alert(`Order marked as ${statusText} successfully!`);
                    closeAnswerModal();
                    // Reload the page to reflect changes
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update call status'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating call status. Please try again.');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    }
    
    // Close modal when clicking outside
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAnswerModal();
            }
        });
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('answerStatusModal');
            if (modal && modal.style.display === 'flex') {
                closeAnswerModal();
            }
        }
    });
});

/**
 * Backward compatibility function
 * Keep this if you have existing calls to markAsAnswered()
 */
function markAsAnswered(orderId) {
    // Default to call_log = 0 (no answer) for backward compatibility
    openAnswerModal(orderId, 0);
}

/**
 * CANCEL ORDER MODAL FUNCTIONALITY
 * Add these functions to your existing JavaScript code
 */

// Global variable to store current order being cancelled
let currentCancelOrderId = null;

/**
 * Open Cancel Order Modal
 * @param {string} orderId - The order ID to cancel
 */
function openCancelModal(orderId) {
    if (!orderId || orderId.trim() === '') {
        alert('Order ID is required to cancel order.');
        return;
    }
    
    console.log('Opening cancel modal for Order ID:', orderId);
    
    // Store current order ID
    currentCancelOrderId = orderId.trim();
    
    // Reset the cancellation reason textarea
    document.getElementById('cancellationReason').value = '';
    
    // Show the modal
    const modal = document.getElementById('cancelModal');
    modal.style.display = 'block';
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Focus on textarea
    setTimeout(() => {
        document.getElementById('cancellationReason').focus();
    }, 100);
}

/**
 * Close Cancel Order Modal
 */
function closeCancelModal() {
    const modal = document.getElementById('cancelModal');
    modal.style.display = 'none';
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
    
    // Reset form and variables
    document.getElementById('cancellationReason').value = '';
    currentCancelOrderId = null;
}

/**
 * Handle Cancel Order Confirmation
 */
function confirmCancelOrder() {
    const cancellationReason = document.getElementById('cancellationReason').value.trim();
    const confirmBtn = document.getElementById('confirmCancelBtn');
    
    // Validation
    if (!currentCancelOrderId) {
        alert('No order selected for cancellation.');
        return;
    }
    
    if (!cancellationReason) {
        alert('Please provide a reason for cancellation.');
        document.getElementById('cancellationReason').focus();
        return;
    }
    
    if (cancellationReason.length < 10) {
        alert('Please provide a more detailed reason (minimum 10 characters).');
        document.getElementById('cancellationReason').focus();
        return;
    }
    
    // Final confirmation
    if (!confirm(`Are you sure you want to cancel Order ID: ${currentCancelOrderId}? This action cannot be undone.`)) {
        return;
    }
    
    // Show loading state
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Cancelling...';
    confirmBtn.disabled = true;
    
    // Create FormData object
    const formData = new FormData();
    formData.append('order_id', currentCancelOrderId);
    formData.append('cancellation_reason', cancellationReason);
    formData.append('action', 'cancel_order');
    
    // Send the request
    fetch('cancel_order.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('Order cancelled successfully!');
            closeCancelModal();
            // Reload the page to reflect changes
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to cancel order'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while cancelling the order. Please try again.');
    })
    .finally(() => {
        // Reset button state
        confirmBtn.innerHTML = originalText;
        confirmBtn.disabled = false;
    });
}

/**
 * Initialize Cancel Order Functionality
 * Add this to your existing DOMContentLoaded event listener
 */
// Add this inside your existing DOMContentLoaded function
document.addEventListener('DOMContentLoaded', function() {
    const cancelModal = document.getElementById('cancelModal');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const cancellationReason = document.getElementById('cancellationReason');
    
    // Handle confirm cancel button click
    if (confirmCancelBtn) {
        confirmCancelBtn.addEventListener('click', confirmCancelOrder);
    }
    
    // Handle close button clicks
    const closeButtons = cancelModal?.querySelectorAll('[data-dismiss="modal"], .close');
    if (closeButtons) {
        closeButtons.forEach(btn => {
            btn.addEventListener('click', closeCancelModal);
        });
    }
    
    // Close modal when clicking outside
    if (cancelModal) {
        cancelModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeCancelModal();
            }
        });
    }
    
    // Auto-resize textarea
    if (cancellationReason) {
        cancellationReason.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('cancelModal');
            if (modal && (modal.style.display === 'block' || modal.classList.contains('show'))) {
                closeCancelModal();
            }
        }
    });
});

/**
 * Backward compatibility function
 * Call this function from your cancel button: onclick="cancelOrder('ORDER_ID')"
 */
function cancelOrder(orderId) {
    openCancelModal(orderId);
}


// Bulk Selection JavaScript Functions
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const orderCheckboxes = document.querySelectorAll('.order-checkbox');
    
    orderCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    updateBulkSelection();
}

function updateBulkSelection() {
    const orderCheckboxes = document.querySelectorAll('.order-checkbox');
    const checkedBoxes = document.querySelectorAll('.order-checkbox:checked');
    const selectAllCheckbox = document.getElementById('selectAll');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    
    // Update select all checkbox state
    if (checkedBoxes.length === 0) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = false;
    } else if (checkedBoxes.length === orderCheckboxes.length) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = true;
    } else {
        selectAllCheckbox.indeterminate = true;
        selectAllCheckbox.checked = false;
    }
    
    // Show/hide bulk actions bar
    if (checkedBoxes.length > 0) {
        bulkActionsBar.style.display = 'flex';
        selectedCount.textContent = checkedBoxes.length;
    } else {
        bulkActionsBar.style.display = 'none';
    }
}

function getSelectedOrderIds() {
    const checkedBoxes = document.querySelectorAll('.order-checkbox:checked');
    return Array.from(checkedBoxes).map(checkbox => checkbox.value);
}

function clearBulkSelection() {
    const orderCheckboxes = document.querySelectorAll('.order-checkbox');
    const selectAllCheckbox = document.getElementById('selectAll');
    
    orderCheckboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    selectAllCheckbox.checked = false;
    selectAllCheckbox.indeterminate = false;
    
    updateBulkSelection();
}
/**
 * BULK DISPATCH FUNCTIONALITY
 * Add these functions to your existing JavaScript code
 */

// Global variables for bulk dispatch
let selectedOrdersForBulkDispatch = [];

/**
 * Toggle Select All Checkbox
 */
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const orderCheckboxes = document.querySelectorAll('.order-checkbox');
    
    orderCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    updateBulkSelection();
}

/**
 * Update Bulk Selection Display
 */
function updateBulkSelection() {
    const orderCheckboxes = document.querySelectorAll('.order-checkbox:checked');
    const selectedCount = orderCheckboxes.length;
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCountElement = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAll');
    
    // Update selected count
    if (selectedCountElement) {
        selectedCountElement.textContent = selectedCount;
    }
    
    // Show/hide bulk actions bar
    if (bulkActionsBar) {
        bulkActionsBar.style.display = selectedCount > 0 ? 'flex' : 'none';
    }
    
    // Update select all checkbox state
    const totalCheckboxes = document.querySelectorAll('.order-checkbox').length;
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = selectedCount === totalCheckboxes && totalCheckboxes > 0;
        selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
    }
    
    // Store selected orders for bulk dispatch
    selectedOrdersForBulkDispatch = Array.from(orderCheckboxes).map(checkbox => checkbox.value);
}

/**
 * Clear Bulk Selection
 */
function clearBulkSelection() {
    const orderCheckboxes = document.querySelectorAll('.order-checkbox');
    const selectAllCheckbox = document.getElementById('selectAll');
    
    orderCheckboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    }
    
    updateBulkSelection();
}

/**
 * Open Bulk Dispatch Modal
 */
function bulkMarkAsDispatched() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
    
    if (selectedOrders.length === 0) {
        alert('Please select at least one order to dispatch.');
        return;
    }
    
    console.log('Opening bulk dispatch modal for', selectedOrders.length, 'orders');
    
    // Update selected orders list in modal
    updateSelectedOrdersList();
    
    // Reset form
    document.getElementById('bulk-dispatch-form').reset();
    document.getElementById('bulk_tracking_numbers_display').innerHTML = 
        '<span class="text-muted">Select a courier to see available tracking numbers</span>';
    document.getElementById('bulk-dispatch-submit-btn').disabled = true;
    
    // Show modal
    const modal = document.getElementById('bulkDispatchModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

/**
 * Update Selected Orders List in Modal
 */
function updateSelectedOrdersList() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
    const selectedOrdersList = document.getElementById('selectedOrdersList');
    const bulkSelectedCount = document.getElementById('bulkSelectedCount');
    
    if (bulkSelectedCount) {
        bulkSelectedCount.textContent = selectedOrders.length;
    }
    
    if (selectedOrdersList) {
        let ordersHtml = '<div class="selected-orders-list">';
        
        selectedOrders.forEach((checkbox, index) => {
            const orderId = checkbox.value;
            const row = checkbox.closest('tr');
            const customerName = row.querySelector('.customer-name').textContent.trim();
            
            ordersHtml += `
                <div class="selected-order-item">
                    <span class="order-number">${index + 1}.</span>
                    <span class="order-id">${orderId}</span>
                    <span class="customer-name">${customerName}</span>
                </div>
            `;
        });
        
        ordersHtml += '</div>';
        selectedOrdersList.innerHTML = ordersHtml;
    }
}

/**
 * Close Bulk Dispatch Modal
 */
function closeBulkDispatchModal() {
    const modal = document.getElementById('bulkDispatchModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reset form
    document.getElementById('bulk-dispatch-form').reset();
    document.getElementById('bulk_tracking_numbers_display').innerHTML = 
        '<span class="text-muted">Select a courier to see available tracking numbers</span>';
    document.getElementById('bulk-dispatch-submit-btn').disabled = true;
}

/**
 * Fetch Tracking Numbers for Bulk Dispatch
 */
function fetchBulkTrackingNumbers(courierId) {
    const trackingDisplay = document.getElementById('bulk_tracking_numbers_display');
    const submitBtn = document.getElementById('bulk-dispatch-submit-btn');
    const selectedCount = selectedOrdersForBulkDispatch.length;
    
    if (!courierId) {
        trackingDisplay.innerHTML = '<span class="text-muted">Select a courier to see available tracking numbers</span>';
        submitBtn.disabled = true;
        return;
    }
    
    // Show loading state
    trackingDisplay.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Loading tracking numbers...</span>';
    submitBtn.disabled = true;
    
    // Fetch tracking numbers from PHP endpoint
    fetch(`get_bulk_tracking_numbers.php?courier_id=${courierId}&count=${selectedCount}`, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.status === 'success') {
            let trackingHtml = `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Available tracking numbers: <strong>${data.tracking_numbers.length}</strong></span>`;
            
            if (data.tracking_numbers.length >= selectedCount) {
                trackingHtml += `<div class="tracking-numbers-preview mt-2">`;
                data.tracking_numbers.slice(0, selectedCount).forEach((trackingNumber, index) => {
                    trackingHtml += `<div class="tracking-item">${index + 1}. ${trackingNumber}</div>`;
                });
                trackingHtml += `</div>`;
                trackingHtml += `<small class="d-block text-muted mt-1">${data.available_count} total tracking numbers available</small>`;
                submitBtn.disabled = false;
            } else {
                trackingHtml += `<div class="alert alert-warning mt-2">Only ${data.tracking_numbers.length} tracking numbers available, but ${selectedCount} orders selected.</div>`;
                submitBtn.disabled = true;
            }
            
            trackingDisplay.innerHTML = trackingHtml;
        } else {
            trackingDisplay.innerHTML = 
                `<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>${data.message}</span>`;
            submitBtn.disabled = true;
        }
    })
    .catch(error => {
        console.error('Error fetching tracking numbers:', error);
        trackingDisplay.innerHTML = 
            '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Error loading tracking numbers. Please try again.</span>';
        submitBtn.disabled = true;
    });
}

/**
 * Initialize Bulk Dispatch Functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    const bulkCarrierSelect = document.getElementById('bulk_carrier');
    const bulkDispatchForm = document.getElementById('bulk-dispatch-form');
    const bulkModal = document.getElementById('bulkDispatchModal');
    
    // Handle bulk courier selection change
    if (bulkCarrierSelect) {
        bulkCarrierSelect.addEventListener('change', function() {
            const selectedCourierId = this.value;
            
            if (selectedCourierId) {
                // Fetch tracking numbers for selected courier
                fetchBulkTrackingNumbers(selectedCourierId);
            } else {
                // Reset display when no courier is selected
                document.getElementById('bulk_tracking_numbers_display').innerHTML = 
                    '<span class="text-muted">Select a courier to see available tracking numbers</span>';
                document.getElementById('bulk-dispatch-submit-btn').disabled = true;
            }
        });
    }
    
    // Handle bulk dispatch form submission
    if (bulkDispatchForm) {
        bulkDispatchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const carrier = document.getElementById('bulk_carrier').value;
            const dispatchNotes = document.getElementById('bulk_dispatch_notes').value;
            const submitBtn = document.getElementById('bulk-dispatch-submit-btn');
            
            if (!carrier) {
                alert('Please select a courier service before dispatching');
                return;
            }
            
            if (selectedOrdersForBulkDispatch.length === 0) {
                alert('No orders selected for dispatch');
                return;
            }
            
            // Confirm bulk dispatch
            if (!confirm(`Are you sure you want to dispatch ${selectedOrdersForBulkDispatch.length} orders? This action cannot be undone.`)) {
                return;
            }
            
            // Show loading state
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Dispatching...';
            submitBtn.disabled = true;
            
            // Create FormData object
            const formData = new FormData();
            formData.append('order_ids', JSON.stringify(selectedOrdersForBulkDispatch));
            formData.append('carrier', carrier);
            formData.append('dispatch_notes', dispatchNotes);
            formData.append('action', 'bulk_dispatch_orders');
            
            // Send the request
            fetch('process_bulk_dispatch.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert(`Successfully dispatched ${data.dispatched_count} orders!` + 
                          (data.tracking_numbers ? ' Tracking numbers assigned.' : ''));
                    closeBulkDispatchModal();
                    clearBulkSelection();
                    // Reload the page to reflect changes
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to dispatch orders'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while dispatching orders. Please try again.');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = '<i class="fas fa-truck me-1"></i>Confirm Bulk Dispatch';
                submitBtn.disabled = !carrier;
            });
        });
    }
    
    // Close modal when clicking outside
    if (bulkModal) {
        bulkModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeBulkDispatchModal();
            }
        });
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('bulkDispatchModal');
            if (modal && modal.style.display === 'flex') {
                closeBulkDispatchModal();
            }
        }
    });
});

/**
 * Toggle Action Buttons Based on Bulk Selection State
 */
function toggleActionButtons() {
    const selectedOrdersCount = document.querySelectorAll('.order-checkbox:checked').length;
    const allActionButtons = document.querySelectorAll('.action-buttons-group');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    
    if (selectedOrdersCount > 0) {
        // Disable all action buttons when bulk selection is active
        allActionButtons.forEach(buttonGroup => {
            buttonGroup.style.opacity = '0.5';
            buttonGroup.style.pointerEvents = 'none';
            buttonGroup.style.cursor = 'not-allowed';
        });
        
        // Add a visual indicator
        allActionButtons.forEach(buttonGroup => {
            if (!buttonGroup.querySelector('.bulk-selection-overlay')) {
                const overlay = document.createElement('div');
                overlay.className = 'bulk-selection-overlay';
                overlay.style.cssText = `
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0,0,0,0.1);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 10px;
                    color: #666;
                    z-index: 1;
                `;
                overlay.innerHTML = '<i class=""></i>';
                
                // Make button group relative for overlay positioning
                buttonGroup.style.position = 'relative';
                buttonGroup.appendChild(overlay);
            }
        });
        
    } else {
        // Re-enable all action buttons when no bulk selection
        allActionButtons.forEach(buttonGroup => {
            buttonGroup.style.opacity = '1';
            buttonGroup.style.pointerEvents = 'auto';
            buttonGroup.style.cursor = 'default';
            
            // Remove overlay
            const overlay = buttonGroup.querySelector('.bulk-selection-overlay');
            if (overlay) {
                overlay.remove();
            }
        });
    }
}

// Update the existing updateBulkSelection function to include action button control
// Replace your existing updateBulkSelection function with this enhanced version:
function updateBulkSelection() {
    const orderCheckboxes = document.querySelectorAll('.order-checkbox:checked');
    const selectedCount = orderCheckboxes.length;
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCountElement = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAll');
    
    // Update selected count
    if (selectedCountElement) {
        selectedCountElement.textContent = selectedCount;
    }
    
    // Show/hide bulk actions bar
    if (bulkActionsBar) {
        bulkActionsBar.style.display = selectedCount > 0 ? 'flex' : 'none';
    }
    
    // Update select all checkbox state
    const totalCheckboxes = document.querySelectorAll('.order-checkbox').length;
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = selectedCount === totalCheckboxes && totalCheckboxes > 0;
        selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
    }
    
    // Store selected orders for bulk dispatch
    selectedOrdersForBulkDispatch = Array.from(orderCheckboxes).map(checkbox => checkbox.value);
    
    // NEW: Toggle action buttons based on selection state
    toggleActionButtons();
}

// Print order function
function printOrder(orderId) {
    if (!orderId || orderId.trim() === '') {
        alert('Order ID is required to print order.');
        return;
    }
    
    console.log('Printing Order ID:', orderId);
    
    // Construct the print URL
    const printUrl = 'download_order_print.php?id=' + encodeURIComponent(orderId.trim());
    
    // Open print page in new window
    const printWindow = window.open(printUrl, '_blank');
    
    // Optional: Auto-print when page loads (uncomment if needed)
    // printWindow.onload = function() {
    //     printWindow.print();
    // };
}

/**
 * Open Bulk Dispatch Modal
 */
function openApiDispatchModal() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
    
    if (selectedOrders.length === 0) {
        alert('Please select at least one order to dispatch.');
        return;
    }
    
    console.log('Opening bulk dispatch modal for', selectedOrders.length, 'orders');
    
    // Update selected orders list in modal
    updateSelectedOrdersList();
    
    // Reset form
    document.getElementById('bulk-dispatch-form').reset();
    document.getElementById('bulk_tracking_numbers_display').innerHTML = 
        '<span class="text-muted">Select a courier to see available tracking numbers</span>';
    document.getElementById('bulk-dispatch-submit-btn').disabled = true;
    
    // Show modal
    const modal = document.getElementById('bulkDispatchModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
/**
 * Open API Dispatch Modal - FIXED VERSION
 */
function openApiDispatchModal() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
    
    if (selectedOrders.length === 0) {
        alert('Please select at least one order to dispatch via API.');
        return;
    }
    
    console.log('Opening API dispatch modal for', selectedOrders.length, 'orders');
    
    // Update selected orders list in modal
    updateApiSelectedOrdersList();
    
    // Reset form
    document.getElementById('api-dispatch-form').reset();
    document.getElementById('api_tracking_numbers_display').innerHTML = 
        '<span class="text-muted">Select a courier to see available tracking numbers</span>';
    document.getElementById('api-dispatch-submit-btn').disabled = true;
    document.getElementById('existingTrackingSection').style.display = 'none';
    
    // Show modal
    const modal = document.getElementById('apiDispatchModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

/**
 * Close API Dispatch Modal
 */
function closeApiDispatchModal() {
    const modal = document.getElementById('apiDispatchModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reset form
    document.getElementById('api-dispatch-form').reset();
    document.getElementById('api_tracking_numbers_display').innerHTML = 
        '<span class="text-muted">Select a courier to see available tracking numbers</span>';
    document.getElementById('api-dispatch-submit-btn').disabled = true;
    document.getElementById('existingTrackingSection').style.display = 'none';
}

/**
 * Update Selected Orders List in API Modal
 */
function updateApiSelectedOrdersList() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
    const selectedOrdersList = document.getElementById('apiSelectedOrdersList');
    const apiSelectedCount = document.getElementById('apiSelectedCount');
    
    if (apiSelectedCount) {
        apiSelectedCount.textContent = selectedOrders.length;
    }
    
    if (selectedOrdersList) {
        let ordersHtml = '<div class="selected-orders-list">';
        
        selectedOrders.forEach((checkbox, index) => {
            const orderId = checkbox.value;
            const row = checkbox.closest('tr');
            const customerName = row.querySelector('.customer-name').textContent.trim();
            
            ordersHtml += `
                <div class="selected-order-item">
                    <span class="order-number">${index + 1}.</span>
                    <span class="order-id">${orderId}</span>
                    <span class="customer-name">${customerName}</span>
                </div>
            `;
        });
        
        ordersHtml += '</div>';
        selectedOrdersList.innerHTML = ordersHtml;
    }
}
/**
 * Fetch Tracking Numbers for API Dispatch (existing parcels)
 */
function fetchApiTrackingNumbers(courierId) {
    const trackingDisplay = document.getElementById('api_tracking_numbers_display');
    const submitBtn = document.getElementById('api-dispatch-submit-btn');
    const selectedCount = document.querySelectorAll('.order-checkbox:checked').length;
    
    console.log('Fetching tracking numbers for courier:', courierId, 'Count:', selectedCount);
    
    if (!courierId) {
        trackingDisplay.innerHTML = '<span class="text-muted">Select a courier to see available tracking numbers</span>';
        submitBtn.disabled = true;
        return;
    }
    
    if (selectedCount === 0) {
        trackingDisplay.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>No orders selected</span>';
        submitBtn.disabled = true;
        return;
    }
    
    // Show loading state
    trackingDisplay.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Loading tracking numbers...</span>';
    submitBtn.disabled = true;
    
    // Fetch tracking numbers from PHP endpoint
    fetch(`get_api_tracking_numbers.php?courier_id=${courierId}&count=${selectedCount}`, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('API Response:', data);
        
        if (data.status === 'success') {
            let trackingHtml = '';
            
            if (data.tracking_numbers.length >= selectedCount) {
                trackingHtml = `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Available tracking numbers: <strong>${data.tracking_numbers.length}</strong></span>`;
                
                trackingHtml += `<div class="tracking-numbers-preview mt-2" style="max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 4px;">`;
                data.tracking_numbers.slice(0, selectedCount).forEach((trackingNumber, index) => {
                    trackingHtml += `<div class="tracking-item" style="padding: 2px 0; font-family: monospace;">${index + 1}. <strong>${trackingNumber}</strong></div>`;
                });
                trackingHtml += `</div>`;
                
                if (data.available_count > selectedCount) {
                    trackingHtml += `<small class="d-block text-muted mt-1">${data.available_count} total tracking numbers available</small>`;
                }
                
                submitBtn.disabled = false;
            } else {
                trackingHtml = `<div class="alert alert-warning mt-2">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <strong>Insufficient tracking numbers!</strong><br>
                    Only <strong>${data.tracking_numbers.length}</strong> tracking numbers available, 
                    but <strong>${selectedCount}</strong> orders selected.
                </div>`;
                
                if (data.tracking_numbers.length > 0) {
                    trackingHtml += `<div class="tracking-numbers-preview mt-2" style="max-height: 150px; overflow-y: auto; background: #fff3cd; padding: 10px; border-radius: 4px;">`;
                    trackingHtml += `<small class="text-muted">Available tracking numbers:</small>`;
                    data.tracking_numbers.forEach((trackingNumber, index) => {
                        trackingHtml += `<div class="tracking-item" style="padding: 2px 0; font-family: monospace;">${index + 1}. <strong>${trackingNumber}</strong></div>`;
                    });
                    trackingHtml += `</div>`;
                }
                
                submitBtn.disabled = true;
            }
            
            trackingDisplay.innerHTML = trackingHtml;
        } else {
            trackingDisplay.innerHTML = 
                `<div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    <strong>Error:</strong> ${data.message}
                </div>`;
            submitBtn.disabled = true;
        }
    })
    .catch(error => {
        console.error('Error fetching tracking numbers:', error);
        trackingDisplay.innerHTML = 
            `<div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <strong>Network Error:</strong> Could not load tracking numbers. Please check your connection and try again.
            </div>`;
        submitBtn.disabled = true;
    });
}

// Enhanced API Dispatch functionality with dynamic dispatch type control
document.addEventListener('DOMContentLoaded', function() {
    const apiCarrierSelect = document.getElementById('api_carrier');
    const apiDispatchForm = document.getElementById('api-dispatch-form');
    const apiModal = document.getElementById('apiDispatchModal');
    const dispatchTypeRadios = document.querySelectorAll('input[name="api_dispatch_type"]');
    const newParcelOption = document.getElementById('newParcel').closest('.form-check');
    const existingParcelOption = document.getElementById('existingParcel').closest('.form-check');
    const dispatchTypeContainer = document.querySelector('.dispatch-type-options');
    
    // Store courier API capabilities - populated from PHP data
    const courierCapabilities = {
        <?php
        // Fetch all courier capabilities and create JavaScript object
        $capabilities_query = "SELECT courier_id, has_api_new, has_api_existing FROM couriers WHERE status = 'active'";
        $capabilities_result = $conn->query($capabilities_query);
        
        if ($capabilities_result && $capabilities_result->num_rows > 0) {
            $capabilities_array = [];
            while($cap = $capabilities_result->fetch_assoc()) {
                $capabilities_array[] = $cap['courier_id'] . ': {has_api_new: ' . intval($cap['has_api_new']) . ', has_api_existing: ' . intval($cap['has_api_existing']) . '}';
            }
            echo implode(',', $capabilities_array);
        }
        ?>
    };
    
    /**
     * Get courier capabilities from embedded data
     */
    function getCourierCapabilities(courierId) {
        if (!courierId) {
            // Hide dispatch type section if no courier selected
            dispatchTypeContainer.style.display = 'none';
            document.getElementById('api-dispatch-submit-btn').disabled = true;
            return;
        }
        
        const capabilities = courierCapabilities[courierId];
        if (capabilities) {
            updateDispatchTypeOptions(capabilities);
        } else {
            // Courier not found, hide dispatch type section
            dispatchTypeContainer.style.display = 'none';
            document.getElementById('api-dispatch-submit-btn').disabled = true;
        }
    }
    
    /**
     * Update dispatch type options based on courier capabilities
     */
    function updateDispatchTypeOptions(capabilities) {
        const hasNew = capabilities.has_api_new === 1;
        const hasExisting = capabilities.has_api_existing === 1;
        
        // Show/hide options based on capabilities
        newParcelOption.style.display = hasNew ? 'block' : 'none';
        existingParcelOption.style.display = hasExisting ? 'block' : 'none';
        
        // If neither option is available, hide the entire dispatch type section
        if (!hasNew && !hasExisting) {
            dispatchTypeContainer.style.display = 'none';
            document.getElementById('api-dispatch-submit-btn').disabled = true;
            return;
        }
        
        // Show the dispatch type section
        dispatchTypeContainer.style.display = 'block';
        
        // Auto-select the available option if only one is available
        if (hasNew && !hasExisting) {
            document.getElementById('newParcel').checked = true;
            document.getElementById('existingTrackingSection').style.display = 'none';
        } else if (!hasNew && hasExisting) {
            document.getElementById('existingParcel').checked = true;
            document.getElementById('existingTrackingSection').style.display = 'block';
            // Fetch tracking numbers for existing parcels
            fetchApiTrackingNumbers(apiCarrierSelect.value);
        } else if (hasNew && hasExisting) {
            // Both options available, default to 'new'
            document.getElementById('newParcel').checked = true;
            document.getElementById('existingTrackingSection').style.display = 'none';
        }
        
        // Enable submit button since we have valid options
        updateSubmitButtonState();
    }
    
    /**
     * Update submit button state based on selections
     */
    function updateSubmitButtonState() {
        const submitBtn = document.getElementById('api-dispatch-submit-btn');
        const selectedOrders = document.querySelectorAll('.order-checkbox:checked').length;
        const courierSelected = apiCarrierSelect.value;
        const dispatchTypeSelected = document.querySelector('input[name="api_dispatch_type"]:checked');
        
        // Enable button only if we have courier, orders, and valid dispatch type
        const canSubmit = courierSelected && selectedOrders > 0 && dispatchTypeSelected;
        submitBtn.disabled = !canSubmit;
    }
    
    // Handle courier selection change
    if (apiCarrierSelect) {
        apiCarrierSelect.addEventListener('change', function() {
            const selectedCourierId = this.value;
            
            if (selectedCourierId) {
                // Get courier capabilities and update UI
                getCourierCapabilities(selectedCourierId);
            } else {
                // No courier selected, hide dispatch options
                dispatchTypeContainer.style.display = 'none';
                document.getElementById('existingTrackingSection').style.display = 'none';
                document.getElementById('api-dispatch-submit-btn').disabled = true;
            }
        });
    }
    
    // Handle dispatch type change
    dispatchTypeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'existing') {
                document.getElementById('existingTrackingSection').style.display = 'block';
                // Fetch tracking numbers if courier is selected
                if (apiCarrierSelect.value) {
                    fetchApiTrackingNumbers(apiCarrierSelect.value);
                }
            } else {
                document.getElementById('existingTrackingSection').style.display = 'none';
            }
            updateSubmitButtonState();
        });
    });
    
    // Handle API dispatch form submission
    if (apiDispatchForm) {
        apiDispatchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const carrier = apiCarrierSelect.value;
            const dispatchTypeElement = document.querySelector('input[name="api_dispatch_type"]:checked');
            const dispatchType = dispatchTypeElement ? dispatchTypeElement.value : null;
            const dispatchNotes = document.getElementById('api_dispatch_notes').value;
            const submitBtn = document.getElementById('api-dispatch-submit-btn');
            const selectedOrders = Array.from(document.querySelectorAll('.order-checkbox:checked')).map(cb => cb.value);
            
            // Validation
            if (!carrier) {
                alert('Please select an API courier service');
                return;
            }
            
            if (!dispatchType) {
                alert('Please select a dispatch type');
                return;
            }
            
            if (selectedOrders.length === 0) {
                alert('No orders selected for dispatch');
                return;
            }
            
            // Additional validation for existing parcels
            if (dispatchType === 'existing') {
                const trackingDisplay = document.getElementById('api_tracking_numbers_display');
                if (trackingDisplay.textContent.includes('No tracking numbers available') || 
                    trackingDisplay.textContent.includes('Select a courier')) {
                    alert('Please ensure you have enough tracking numbers available');
                    return;
                }
            }
            
            // Confirm action
            const actionText = dispatchType === 'new' ? 'create new API parcels' : 'assign existing tracking numbers';
            if (!confirm(`Are you sure you want to ${actionText} for ${selectedOrders.length} orders?`)) {
                return;
            }
            
            // Show loading state
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
            submitBtn.disabled = true;
            
            // Create FormData object
            const formData = new FormData();
            formData.append('order_ids', JSON.stringify(selectedOrders));
            formData.append('carrier_id', carrier);
            formData.append('dispatch_type', dispatchType);
            formData.append('dispatch_notes', dispatchNotes);
            formData.append('action', 'api_dispatch_orders');
            
            // Determine which endpoint to use
            let endpoint;

            // Get the selected courier data from your capabilities object
            const courierData = courierCapabilities[carrier];

            // Check if this is Koombiyo courier (has_api_existing = 1, has_api_new = 0)
            if (courierData && courierData.has_api_existing === 1 && courierData.has_api_new === 0) {
                // Koombiyo only supports existing parcel API
                endpoint = 'koombiyo_bulk_existing_parcel_api.php';
            } else {
                // For other couriers, use normal logic
                endpoint = dispatchType === 'new' ? 'fde_bulk_new_parcel_api.php' : 'fde_bulk_existing_parcel_api.php';
            }
            
            console.log('Submitting to endpoint:', endpoint);
            console.log('Form data:', {
                order_ids: selectedOrders,
                carrier_id: carrier,
                dispatch_type: dispatchType,
                dispatch_notes: dispatchNotes,
                action: 'api_dispatch_orders'
            });
            
            // Send the request
            fetch(endpoint, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    alert(`Successfully processed ${data.processed_count || selectedOrders.length} orders via API!`);
                    closeApiDispatchModal();
                    clearBulkSelection();
                    // Reload the page to reflect changes
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to process orders via API'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while processing orders via API. Please try again.');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i>Confirm API Dispatch';
                submitBtn.disabled = false;
            });
        });
    }
    
    // Close modal when clicking outside
    if (apiModal) {
        apiModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeApiDispatchModal();
            }
        });
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('apiDispatchModal');
            if (modal && modal.style.display === 'flex') {
                closeApiDispatchModal();
            }
        }
    });
    
    // Listen for order selection changes to update submit button
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('order-checkbox')) {
            updateSubmitButtonState();
        }
    });
    
    // Initial state - hide dispatch type section until courier is selected
    dispatchTypeContainer.style.display = 'none';
});

 </script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

</body>
</html>
