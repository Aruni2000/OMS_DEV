<?php
/**
 * Return Complete Orders List
 * This page displays orders with return complete status
 * Includes filtering and pagination functionality
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
 * SEARCH AND PAGINATION PARAMETERS FOR ORDERS TABLE
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
 * DATABASE QUERIES FOR ORDERS TABLE
 * Main query to fetch orders with customer and payment information
 * Filtered for return complete status and both interface types
 */

// Base SQL for counting total records - RETURN COMPLETE STATUS - Updated to use user_id
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.user_id = u2.id
             WHERE i.status = 'return complete'
             AND (i.interface = 'individual' OR i.interface = 'leads')$roleBasedCondition";

// Main query with all required joins - RETURN COMPLETE STATUS - Updated to use user_id
$sql = "SELECT i.*, c.name as customer_name, 
               p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
               u1.name as paid_by_name,
               u2.name as user_name
        FROM order_header i 
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.user_id = u2.id
        WHERE i.status = 'return complete'
        AND (i.interface = 'individual' OR i.interface = 'leads')$roleBasedCondition";

// Build search conditions
$searchConditions = [];

// General search condition - Updated to search user_name instead of creator_name
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

// NEW: Fetch all users for the User ID dropdown
$usersQuery = "SELECT id, name FROM users ORDER BY name ASC";
$usersResult = $conn->query($usersQuery);

// Include navigation components
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Return Complete Orders - OMS Admin Portal</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />

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
                        <h5 class="mb-0 font-medium">Return Complete Orders</h5>
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
                                <label for="tracking_id">Tracking ID</label>
                                <input type="text" id="tracking_id" name="tracking_id" 
                                       placeholder="Enter tracking ID" 
                                       value="<?php echo htmlspecialchars($tracking_id); ?>">
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
                        <div class="order-count-subtitle">Total Orders</div>
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
                                                $orderId = isset($row['order_id']) ? htmlspecialchars($row['order_id']) : '';
                                                $interface = isset($row['interface']) ? htmlspecialchars($row['interface']) : '';
                                                ?>
                                                <button class="action-btn view-btn" title="View Order Details" 
                                                        onclick="openOrderModal('<?php echo $orderId; ?>', '<?php echo $interface; ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                               
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                            No return complete orders found
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
                                <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                        onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                    <?php echo $i; ?>
                                </button>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
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
      <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/order_view_modal.php'); ?>
   

    <!-- Include JavaScript files -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    
    <script>
        let currentOrderId = null;
        let currentInterface = null;
        let currentPaymentSlip = null; // Store payment slip filename
        let currentPayStatus = null; // Store payment status

         // NEW: Current user role from PHP
        const currentUserRole = <?php echo $current_user_role; ?>;
        const currentUserId = <?php echo $current_user_id; ?>;


        // Clear all filter inputs - UPDATED: Added user_id_filter
        function clearFilters() {
            document.getElementById('order_id_filter').value = '';
            document.getElementById('customer_name_filter').value = '';
            document.getElementById('tracking_id').value = '';
            document.getElementById('user_id_filter').value = '';
            document.getElementById('date_from').value = '';
            document.getElementById('date_to').value = '';
            
      // Only clear user_id_filter for admin users (if it exists)
            const userIdFilter = document.getElementById('user_id_filter');
            if (userIdFilter && currentUserRole == 1) {
                userIdFilter.value = '';
            }
            window.location.href = window.location.pathname;
        }

        function copyTrackingNumber(trackingNumber) {
            navigator.clipboard.writeText(trackingNumber).then(() => {
                alert('Tracking number copied to clipboard: ' + trackingNumber);
            }).catch(err => {
                console.error('Failed to copy tracking number: ', err);
            });
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

    </script>
</body>
</html>