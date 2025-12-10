<?php
/**
 * Role-Based Access Control Helper Class
 * This centralizes all role-based logic in one place
 */
class RoleBasedAccessControl {
    private $current_user_id;
    private $current_user_role;
    private $conn;
    
    public function __construct($conn, $current_user_id = 0, $current_user_role = 0) {
        $this->conn = $conn;
        $this->current_user_id = (int)$current_user_id;
        $this->current_user_role = (int)$current_user_role;
    }
    
    /**
     * Check if current user is admin
     */
    public function isAdmin() {
        return $this->current_user_role == 1;
    }
    
    /**
     * Get role-based SQL condition for filtering orders
     */
    public function getRoleBasedCondition($table_alias = 'i') {
        if ($this->isAdmin()) {
            return ""; // Admin sees all orders
        }
        return " AND {$table_alias}.user_id = {$this->current_user_id}";
    }
    
    /**
     * Get role-based user filter condition for search
     */
    public function getUserFilterCondition($user_id_filter, $table_alias = 'i') {
        if (empty($user_id_filter)) {
            return "";
        }
        
        $userIdTerm = $this->conn->real_escape_string($user_id_filter);
        
        if ($this->isAdmin()) {
            // Admin can filter by any user
            return "{$table_alias}.user_id = '$userIdTerm'";
        } else {
            // Non-admin can only filter by their own user ID
            if ($userIdTerm == $this->current_user_id) {
                return "{$table_alias}.user_id = '$userIdTerm'";
            }
        }
        return "";
    }
    
    /**
     * Get users query based on role
     */
    public function getUsersQuery() {
        if ($this->isAdmin()) {
            return "SELECT id, name FROM users ORDER BY name ASC";
        } else {
            return "SELECT id, name FROM users WHERE id = {$this->current_user_id} ORDER BY name ASC";
        }
    }
    
    /**
     * Get table colspan based on role (for empty state)
     */
    public function getTableColspan() {
        return $this->isAdmin() ? '7' : '6';
    }
    
    /**
     * Get appropriate "no records" message
     */
    public function getNoRecordsMessage($record_type = 'orders') {
        if ($this->isAdmin()) {
            return "No cancel {$record_type} found";
        } else {
            return "No cancel {$record_type} found for your account";
        }
    }
    
    /**
     * Get order count subtitle
     */
    public function getOrderCountSubtitle() {
        return $this->isAdmin() ? 'Total Cancel Orders' : ' Total Orders';
    }
    
    /**
     * Check if user column should be displayed
     */
    public function shouldShowUserColumn() {
        return $this->isAdmin();
    }
}

/**
 * Cancel Orders Management System - IMPROVED VERSION
 * This page displays orders with status 'cancel' for individual interface
 * Includes search, pagination, and modal view functionality
 * MODIFIED: Added centralized role-based access control
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

// Get current user's role information
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

// Initialize RBAC helper - CENTRALIZED CONTROL
$rbac = new RoleBasedAccessControl($conn, $current_user_id, $current_user_role);

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$tracking_id = isset($_GET['tracking_id']) ? trim($_GET['tracking_id']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$user_id_filter = isset($_GET['user_id_filter']) ? trim($_GET['user_id_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$pay_status_filter = isset($_GET['pay_status_filter']) ? trim($_GET['pay_status_filter']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * DATABASE QUERIES - SIMPLIFIED WITH RBAC
 * Main query to fetch orders with customer and payment information
 * Filtered for individual interface and cancel status only
 */

// Role-based access control condition - SINGLE CALL
$roleBasedCondition = $rbac->getRoleBasedCondition();

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.user_id = u2.id
             WHERE i.interface IN ('individual', 'leads') AND i.status = 'cancel'$roleBasedCondition";

// Main query with all required joins
$sql = "SELECT i.*, c.name as customer_name, 
               p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
               u1.name as paid_by_name,
               u2.name as user_name
        FROM order_header i 
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.user_id = u2.id
        WHERE i.interface IN ('individual', 'leads') AND i.status = 'cancel'$roleBasedCondition";

// Build search conditions
$searchConditions = [];

// General search condition (existing functionality)
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        i.order_id LIKE '%$searchTerm%' OR 
                        c.name LIKE '%$searchTerm%' OR 
                        i.tracking_number LIKE '%$searchTerm%' OR
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

// Tracking ID filter
if (!empty($tracking_id)) {
    $trackingTerm = $conn->real_escape_string($tracking_id);
    $searchConditions[] = "i.tracking_number LIKE '%$trackingTerm%'";
}

// Specific Customer Name filter
if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "c.name LIKE '%$customerNameTerm%'";
}

// Specific User ID filter - SIMPLIFIED WITH RBAC
$userFilterCondition = $rbac->getUserFilterCondition($user_id_filter);
if (!empty($userFilterCondition)) {
    $searchConditions[] = $userFilterCondition;
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

// Fetch users for the User ID dropdown - SIMPLIFIED WITH RBAC
$usersQuery = $rbac->getUsersQuery();
$usersResult = $conn->query($usersQuery);

// Include navigation components
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Cancel Orders</title>
    
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

/* Role indicator styles */
.role-indicator {
    position: absolute;
    top: 10px;
    right: 20px;
    background: #007bff;
    color: white;
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: bold;
}

.role-indicator.admin {
    background: #28a745;
}

.role-indicator.user {
    background: #6c757d;
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
                        <h5 class="mb-0 font-medium">Cancel Orders</h5>
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
                            <label for="tracking_id">Tracking ID</label>
                            <input type="text" id="tracking_id" name="tracking_id" 
                                   placeholder="Enter tracking ID" 
                                   value="<?php echo htmlspecialchars($tracking_id); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="customer_name_filter">Customer Name</label>
                            <input type="text" id="customer_name_filter" name="customer_name_filter" 
                                   placeholder="Enter customer name" 
                                   value="<?php echo htmlspecialchars($customer_name_filter); ?>">
                        </div>

                        <!-- User ID Filter - SIMPLIFIED CONDITION -->
                        <?php if ($rbac->shouldShowUserColumn()): ?>
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

                <!-- Order Count Display - SIMPLIFIED -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">
                        <?php echo $rbac->getOrderCountSubtitle(); ?>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer Name</th>
                                <th>Issue Date - Due Date</th>
                                <th>Tracking Number</th>
                                <th>Total Amount</th>
                                <th>Pay Status</th>
                                <?php if ($rbac->shouldShowUserColumn()): ?>
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

                                        <!-- Order ID -->
                                        <td class="order-id">
                                            <?php echo isset($row['tracking_number']) ? htmlspecialchars($row['tracking_number']) : ''; ?>
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
                                        
                                        <!-- User Column - SIMPLIFIED CONDITION -->
                                        <?php if ($rbac->shouldShowUserColumn()): ?>
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
                                            <div class="action-buttons-group">
                                                <button class="action-btn view-btn" title="View Order Details" 
                onclick="openOrderModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', '<?php echo isset($row['interface']) ? htmlspecialchars($row['interface']) : ''; ?>')">
            <i class="fas fa-eye"></i>
        </button>

         <!-- Print Button -->
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
                                    <td colspan="<?php echo $rbac->getTableColspan(); ?>" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <?php echo $rbac->getNoRecordsMessage(); ?>
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
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

    <script>
        /**
         * JavaScript functionality for cancel order management
         * SIMPLIFIED: Using centralized RBAC approach
         */
        
        let currentOrderId = null;
        let currentInterface = null;
        let currentPaymentSlip = null;
        let currentPayStatus = null;
        
        // Simplified role variables from PHP
        const currentUserRole = <?php echo $current_user_role; ?>;
        const currentUserId = <?php echo $current_user_id; ?>;
        const isAdmin = <?php echo $rbac->isAdmin() ? 'true' : 'false'; ?>;

        // Clear all filter inputs - SIMPLIFIED
        function clearFilters() {
            document.getElementById('order_id_filter').value = '';
            document.getElementById('customer_name_filter').value = '';
            document.getElementById('date_from').value = '';
            document.getElementById('date_to').value = '';
            document.getElementById('pay_status_filter').value = '';
            document.getElementById('tracking_id').value = '';
            
            // Only clear user_id_filter for admin users (if it exists) - SIMPLIFIED
            if (isAdmin) {
                const userIdFilter = document.getElementById('user_id_filter');
                if (userIdFilter) {
                    userIdFilter.value = '';
                }
            }
            
            // Submit the form to clear filters
            window.location.href = window.location.pathname;
        }

        // Enhanced openOrderModal function
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
                
                // Check for payment slip and update button visibility
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

        // Function to check payment slip availability
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
                    
                    // Show button for all paid orders, regardless of slip availability
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

        // Function to view payment slip with no-slip message
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
            console.log('Current User Role:', currentUserRole, 'User ID:', currentUserId, 'Is Admin:', isAdmin);
            
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
            
            // Simplified role-based access info
            if (!isAdmin) {
                console.log('Non-admin user: Can only view own orders');
            } else {
                console.log('Admin user: Can view all orders');
            }
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