<?php
/**
 * Dispatched Orders Management System
 * This page displays orders with status 'dispatch' only for individual and leads interface
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

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');


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

// If still no user data, redirect to login
if ($current_user_id == 0) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$tracking_id = isset($_GET['tracking_id']) ? trim($_GET['tracking_id']) : '';
$user_id_filter = isset($_GET['user_id_filter']) ? trim($_GET['user_id_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

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
 * Filtered for individual and leads interface and dispatch status only
 */

// Base SQL for counting total records - ONLY DISPATCH STATUS
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.user_id = u2.id
             WHERE  i.interface IN ('individual', 'leads') AND i.status = 'dispatch'$roleBasedCondition";

// Main query with all required joins - ONLY DISPATCH STATUS - FIXED: Changed creator_name to user_name
$sql = "SELECT i.*, c.name as customer_name, 
               p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
               u1.name as paid_by_name,
               u2.name as user_name
        FROM order_header i 
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.user_id = u2.id
        WHERE  i.interface IN ('individual', 'leads') AND i.status = 'dispatch'$roleBasedCondition";

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

//Specific User ID filter - MODIFIED: Apply role-based restrictions
if (!empty($user_id_filter)) {
    $userIdTerm = $conn->real_escape_string($user_id_filter);
    if ($current_user_role == 1) {
        // Admin can filter by any user
        $searchConditions[] = "i.user_id = '$userIdTerm'";
    } else {
        // Non-admin can only filter by their own user ID
        if ($userIdTerm == $current_user_id) {
            $searchConditions[] = "i.user_id = '$userIdTerm'";
        }
    }
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

// FIXED: Fetch all users for the User ID dropdown
$usersQuery = "SELECT id, name FROM users ORDER BY name ASC";
$usersResult = $conn->query($usersQuery);

// Include navigation components
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Dispatched Orders</title>
    
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
                        <h5 class="mb-0 font-medium">Dispatched Orders</h5>
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

                        <!-- User ID Filter - Only show for admin users -->
                        <?php if ($current_user_role == 1): ?>
                            <div class="form-group">
                                <label for="user_id_filter">User</label>
                                <select id="user_id_filter" name="user_id_filter">
                                    <option value="">All Users</option>
                                    <?php if ($usersResult && $usersResult->num_rows > 0): ?>
                                        <?php while ($userRow = $usersResult->fetch_assoc()): ?>
                                            <option value="<?php echo htmlspecialchars($userRow['id']); ?>" 
                                                    <?php echo ($user_id_filter == $userRow['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($userRow['name']) . ' (ID: ' . $userRow['id'] . ')'; ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        
                        
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
                    <div class="order-count-subtitle">
                        <?php echo ($current_user_role == 1) ? 'Total Orders' : ' Total Orders'; ?>
                    </div>
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
                                 <?php if ($current_user_role == 1): ?>
                                    <th>User</th>
                                <?php endif; ?>
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
                                                echo htmlspecialchars($row['tracking_number']);
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
                                          <!-- User Column - CONDITIONAL: Only show for admin users -->
                                        <?php if ($current_user_role == 1): ?>
                                            <td>
                                                <?php
                                                $userName = isset($row['user_name']) ? htmlspecialchars($row['user_name']) : 'N/A';
                                                $interface = isset($row['interface']) ? $row['interface'] : '';
                                                $userId = isset($row['user_id']) ? htmlspecialchars($row['user_id']) : '';
                                                
                                                echo $userName;
                                                
                                                // Display user ID in small text
                                                if ($userId) {
                                                    echo "<br><span style='color: #666; font-size: 0.8em;'>ID: $userId</span>";
                                                }
                                                
                                                // Display (leads) if interface is 'leads'
                                                if ($interface == 'leads') {
                                                    echo "<br><span style='color: #666; font-size: 0.9em;'>(leads)</span>";
                                                }
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <?php
                                            $payStatus = isset($row['pay_status']) ? $row['pay_status'] : 'unpaid';
                                            $orderId = isset($row['order_id']) ? htmlspecialchars($row['order_id']) : '';
                                            ?>
                                            
                                            <!-- VIEW button - always show -->
                                            <button class="action-btn view-btn" title="View Order Details" 
                                                    onclick="openOrderModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', '<?php echo isset($row['interface']) ? htmlspecialchars($row['interface']) : ''; ?>')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <!-- PRINT BUTTON -->
                                            <button class="action-btn print-btn" title="Print Order" 
                                                    onclick="printOrder('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                                <i class="fas fa-print"></i>
                                            </button>
                                            
                                            <!-- CANCEL button - always show for dispatch status -->
                                            <button class="action-btn cancel-btn" title="Cancel Order" 
                                                    onclick="cancelOrder('<?php echo $orderId; ?>')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            
                                            <?php if ($payStatus == 'unpaid'): ?>
                                                <!-- MARK AS PAID button - only show for unpaid orders -->
                                                <button class="action-btn paid-btn" title="Mark as Paid" 
                                                        onclick="markAsPaid('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                                    <i class="fas fa-dollar-sign"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        No dispatched orders found
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Marking Order as Paid -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/paid_mark_modal.php'); ?>
    
    <!-- Cancel Order Modal  -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/cancel_order_modal.php'); ?>

    <!-- Include MODAL for View Order -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/order_view_modal.php'); ?>

<script>
    /**
 * JavaScript functionality for dispatched order management
 * Enhanced with Mark as Paid and Cancel Order functionality
 */

let currentOrderId = null;
let currentInterface = null;
let currentPaymentSlip = null; // Store payment slip filename
let currentPayStatus = null; // Store payment status

 // NEW: Current user role from PHP
        const currentUserRole = <?php echo $current_user_role; ?>;
        const currentUserId = <?php echo $current_user_id; ?>;

// Clear all filter inputs
function clearFilters() {
    document.getElementById('order_id_filter').value = '';
    document.getElementById('customer_name_filter').value = '';
    document.getElementById('user_id_filter').value = '';
    document.getElementById('tracking_id').value = '';
    document.getElementById('date_from').value = '';
    document.getElementById('date_to').value = '';

     // Only clear user_id_filter for admin users (if it exists)
            const userIdFilter = document.getElementById('user_id_filter');
            if (userIdFilter && currentUserRole == 1) {
                userIdFilter.value = '';
            }
    
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
    const phpFile = (interface === 'leads') ? '../leads/leads_download.php' : 'download_order_page.php';
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

/**
 * MARK AS PAID MODAL FUNCTIONALITY
 */

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

/**
 * CANCEL ORDER MODAL FUNCTIONALITY
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
 * Backward compatibility function for cancel order
 * Call this function from your cancel button: onclick="cancelOrder('ORDER_ID')"
 */
function cancelOrder(orderId) {
    openCancelModal(orderId);
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
    console.log('Dispatched Orders page loaded, initializing...'); // Debug log
    
    // Add hover effects to table rows
    const tableRows = document.querySelectorAll('.orders-table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(2px)';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.transform = 'translateX(0)';
        });
    });
    
    // Check if modal elements exist
    const modal = document.getElementById('orderModal');
    const modalContent = document.getElementById('modalContent');
    if (!modal || !modalContent) {
        console.error('Modal elements not found! Check HTML structure.');
    }

    /**
     * MARK AS PAID FUNCTIONALITY INITIALIZATION
     */
    const fileInput = document.getElementById('payment_slip');
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const markPaidForm = document.getElementById('markPaidForm');
    const markPaidModal = document.getElementById('markPaidModal');
    
    // Handle file selection
    if (fileInput && fileInfo && fileName) {
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
    }

    // Handle mark paid form submission
    if (markPaidForm) {
        markPaidForm.addEventListener('submit', function(e) {
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
    }

    // Close mark paid modal when clicking outside
    if (markPaidModal) {
        markPaidModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closePaidModal();
            }
        });
    }

    /**
     * CANCEL ORDER FUNCTIONALITY INITIALIZATION
     */
    const cancelModal = document.getElementById('cancelModal');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const cancellationReason = document.getElementById('cancellationReason');
    
    // Handle confirm cancel button click
    if (confirmCancelBtn) {
        confirmCancelBtn.addEventListener('click', confirmCancelOrder);
    }
    
    // Handle close button clicks for cancel modal
    const cancelCloseButtons = cancelModal?.querySelectorAll('[data-dismiss="modal"], .close');
    if (cancelCloseButtons) {
        cancelCloseButtons.forEach(btn => {
            btn.addEventListener('click', closeCancelModal);
        });
    }
    
    // Close cancel modal when clicking outside
    if (cancelModal) {
        cancelModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeCancelModal();
            }
        });
    }
    
    // Auto-resize textarea for cancellation reason
    if (cancellationReason) {
        cancellationReason.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }
    
    // Enhanced Escape key handling for all modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // Check which modal is open and close it
            const orderModal = document.getElementById('orderModal');
            const markPaidModal = document.getElementById('markPaidModal');
            const cancelModal = document.getElementById('cancelModal');
            
            if (orderModal && orderModal.style.display === 'flex') {
                closeOrderModal();
            } else if (markPaidModal && markPaidModal.classList.contains('show')) {
                closePaidModal();
            } else if (cancelModal && (cancelModal.style.display === 'block' || cancelModal.classList.contains('show'))) {
                closeCancelModal();
            }
        }
    });
});

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

    </script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

</body>
</html>