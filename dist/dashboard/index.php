<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

/**
 * Role-Based Access Control Helper Class for Dashboard
 * This centralizes all role-based logic in one place
 */
class DashboardRBAC {
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
    public function getRoleBasedCondition($table_alias = '') {
        if ($this->isAdmin()) {
            return ""; // Admin sees all orders
        }
        
        $table_prefix = $table_alias ? $table_alias . '.' : '';
        return " AND {$table_prefix}user_id = {$this->current_user_id}";
    }
}

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

// Initialize RBAC helper
$rbac = new DashboardRBAC($conn, $current_user_id, $current_user_role);

// Set default to today's date if no date parameters are provided
$today = date('Y-m-d');
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : $today;
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : $today;

// Initialize statistics with default values
$stats = [
    'total_users' => 0,
    'total_customers' => 0,
    'total_products' => 0,
    'total_orders' => 0,
    'complete_orders' => 0,
    'pending_orders' => 0,
    'cancel_orders' => 0,
    'dispatch_orders' => 0,
    'return_complete_orders' => 0,
    'return_handover_orders' => 0
];

// Helper function to safely query the database
function safeQuery($conn, $query)
{
    try {
        $result = $conn->query($query);
        if ($result) {
            $row = $result->fetch_assoc();
            return isset($row['count']) ? $row['count'] : 0;
        }
    } catch (Exception $e) {
        return 0;
    }
    return 0;
}

// Prepare date conditions for SQL query - always apply date filter
$date_condition = " AND (issue_date BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59')";

// Get role-based condition for orders
$role_based_condition = $rbac->getRoleBasedCondition();

// Fetch statistics with role-based access control

// Total Users - Only admin can see this
if ($rbac->isAdmin()) {
    $stats['total_users'] = safeQuery($conn, "SELECT COUNT(*) as count FROM users");
}

// Total Customers - Only admin can see all customers, regular users see only their customers
if ($rbac->isAdmin()) {
    // Check if customers table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'customers'");
    if ($tableExists && $tableExists->num_rows > 0) {
        $stats['total_customers'] = safeQuery($conn, "SELECT COUNT(*) as count FROM customers");
    }
} else {
    // For regular users, count customers from their orders only
    $tableExists = $conn->query("SHOW TABLES LIKE 'customers'");
    if ($tableExists && $tableExists->num_rows > 0) {
        $stats['total_customers'] = safeQuery($conn, 
            "SELECT COUNT(DISTINCT c.customer_id) as count 
             FROM customers c 
             INNER JOIN order_header oh ON c.customer_id = oh.customer_id 
             WHERE oh.user_id = $current_user_id"
        );
    }
}

// Total Products - Only admin can see all products
if ($rbac->isAdmin()) {
    // Check if products table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'products'");
    if ($tableExists && $tableExists->num_rows > 0) {
        $stats['total_products'] = safeQuery($conn, "SELECT COUNT(*) as count FROM products");
    }
}

// Check if orders table exists
$tableExists = $conn->query("SHOW TABLES LIKE 'order_header'");
if ($tableExists && $tableExists->num_rows > 0) {
    // Base query for total orders with role-based filtering
    $total_orders_query = "SELECT COUNT(*) as count FROM order_header WHERE 1=1";
    
    // Apply role-based condition and date filter
    $total_orders_query .= $role_based_condition . $date_condition;
    
    $stats['total_orders'] = safeQuery($conn, $total_orders_query);
    
    // Count for complete orders
    $complete_orders_query = "SELECT COUNT(*) as count FROM order_header WHERE status = 'done'";
    $complete_orders_query .= $role_based_condition . $date_condition;
    $stats['complete_orders'] = safeQuery($conn, $complete_orders_query);
    
    // Count for pending orders
    $pending_orders_query = "SELECT COUNT(*) as count FROM order_header WHERE status = 'pending'";
    $pending_orders_query .= $role_based_condition . $date_condition;
    $stats['pending_orders'] = safeQuery($conn, $pending_orders_query);
    
    // Count for cancel orders
    $cancel_orders_query = "SELECT COUNT(*) as count FROM order_header WHERE status = 'cancel'";
    $cancel_orders_query .= $role_based_condition . $date_condition;
    $stats['cancel_orders'] = safeQuery($conn, $cancel_orders_query);
    
    // Count for dispatch orders
    $dispatch_orders_query = "SELECT COUNT(*) as count FROM order_header WHERE status = 'dispatch'";
    $dispatch_orders_query .= $role_based_condition . $date_condition;
    $stats['dispatch_orders'] = safeQuery($conn, $dispatch_orders_query);
    
    // Count for return complete orders
    $return_complete_orders_query = "SELECT COUNT(*) as count FROM order_header WHERE status = 'return_complete'";
    $return_complete_orders_query .= $role_based_condition . $date_condition;
    $stats['return_complete_orders'] = safeQuery($conn, $return_complete_orders_query);
    
    // Count for return handover orders
    $return_handover_orders_query = "SELECT COUNT(*) as count FROM order_header WHERE status = 'return_handover'";
    $return_handover_orders_query .= $role_based_condition . $date_condition;
    $stats['return_handover_orders'] = safeQuery($conn, $return_handover_orders_query);
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - Dashboard</title>
      <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
        <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">

    
    <style>
        .filter-bar {
            background-color: #fff;
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }
        
        .form-group {
            flex: 1;
            min-width: 160px;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #6b7280;
            margin-bottom: 0.25rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            transition: border-color 0.15s ease-in-out;
        }
        
        .form-control:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }
        
        .btn-group {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .btn-primary {
            background-color: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2563eb;
        }
        
        .btn-secondary {
            background-color: #f3f4f6;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            background-color: #e5e7eb;
        }
        
        .date-info {
            font-size: 0.9rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
            background-color: #f9fafb;
            padding: 0.5rem;
            border-radius: 0.375rem;
            border: 1px solid #e5e7eb;
            display: inline-block;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .card-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .card-link:hover {
            text-decoration: none;
            color: inherit;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .status-indicator {
            display: inline-block;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            margin-right: 0.375rem;
        }
        
        .status-complete { background-color: #10b981; }
        .status-pending { background-color: #f59e0b; }
        .status-cancel { background-color: #ef4444; }
        .status-dispatch { background-color: #3b82f6; }
        .status-return-complete { background-color: #8b5cf6; }
        .status-return-handover { background-color: #f97316; }

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

        /* Hide elements for non-admin users */
        .admin-only {
            display: <?php echo $rbac->isAdmin() ? 'block' : 'none'; ?>;
        }

        .user-specific-label {
            color: #666;
            font-size: 0.9em;
            margin-left: 0.5rem;
        }
    </style>
</head>

<!-- [Body] Start -->
<body>
  <!-- LOADER -->
    <?php
        include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    ?>
    <!-- END LOADER -->

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">
                            Dashboard
                            <?php if (!$rbac->isAdmin()): ?>
                                <span class="user-specific-label"></span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="javascript: void(0)">Dashboard</a></li>
                    </ul>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- Date Info -->
            <div class="date-info">
                <?php 
                if ($date_from == $date_to && $date_from == date('Y-m-d')) {
                    echo '<i class="fas fa-calendar-day"></i> Showing orders for today (' . date('F d, Y') . ')';
                } else if ($date_from == $date_to) {
                    echo '<i class="fas fa-calendar-day"></i> Showing orders for ' . date('F d, Y', strtotime($date_from));
                } else {
                    echo '<i class="fas fa-calendar-week"></i> Showing orders from ' . date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to));
                }
                
                if (!$rbac->isAdmin()) {
                    echo ' <span class="user-specific-label"></span>';
                }
                ?>
            </div>
            
            <!-- Filter Bar -->
            <div class="filter-bar no-print">
                <form method="GET" action="" id="orderFilterForm" class="filter-form">
                    <div class="form-group">
                        <label for="date_from" class="form-label">Date From</label>
                        <input type="text" name="date_from" id="date_from" class="form-control" 
                               value="<?= htmlspecialchars($date_from) ?>" placeholder="Select date">
                    </div>
                    
                    <div class="form-group">
                        <label for="date_to" class="form-label">Date To</label>
                        <input type="text" name="date_to" id="date_to" class="form-control" 
                               value="<?= htmlspecialchars($date_to) ?>" placeholder="Select date">
                    </div>
                    
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <button type="button" class="btn btn-secondary" id="resetButton">
                            <i class="fas fa-redo"></i> Reset to Today
                        </button>
                    </div>
                </form>
            </div>

            <!-- [ Main Content ] start -->
            <div class="grid grid-cols-12 gap-x-6">
                
                <!-- Order Management Section -->
                <div class="col-span-12">
                    <h2 class="section-title">Order Management</h2>
                </div>
                
  <!-- Total Orders -->
<div class="col-span-12 xl:col-span-4 md:col-span-6">
    <a href="../orders/all_orders.php<?= !empty($date_from) || !empty($date_to) ? '?date_from='.urlencode($date_from).'&date_to='.urlencode($date_to) : '' ?>" class="card-link">
        <div class="card">
            <div class="card-header !pb-0 !border-b-0">
                <h5>Total Orders</h5>
                <i class="fas fa-file-invoice text-blue-500 text-xl"></i>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-light flex items-center mb-0">
                        <span class="status-indicator status-total"></span>
                        <?= $stats['total_orders'] ?>
                    </h3>
                    <p class="mb-0 text-sm text-blue-600">View</p>
                </div>
                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                    <div class="bg-blue-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                        style="width: 100%"></div>
                </div>
            </div>
        </div>
    </a>
</div>


<!-- Pending Orders -->
<div class="col-span-12 xl:col-span-4 md:col-span-6">
    <a href="../orders/pending_order_list.php<?= !empty($date_from) || !empty($date_to) ? '?date_from='.urlencode($date_from).'&date_to='.urlencode($date_to) : '' ?>" class="card-link">
        <div class="card">
            <div class="card-header !pb-0 !border-b-0">
                <h5>Pending Orders</h5>
                <i class="fas fa-clock text-yellow-500 text-xl"></i>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-light flex items-center mb-0">
                        <span class="status-indicator status-pending"></span>
                        <?= $stats['pending_orders'] ?>
                    </h3>
                    <p class="mb-0 text-sm text-yellow-600">View</p>
                </div>
                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                    <div class="bg-yellow-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                        style="width: <?= $stats['total_orders'] > 0 ? ($stats['pending_orders'] / $stats['total_orders']) * 100 : 0 ?>%"></div>
                </div>
            </div>
        </div>
    </a>
</div>

<!-- Dispatch Orders -->
<div class="col-span-12 xl:col-span-4 md:col-span-6">
    <a href="../orders/dispatch_order_list.php<?= !empty($date_from) || !empty($date_to) ? '?date_from='.urlencode($date_from).'&date_to='.urlencode($date_to) : '' ?>" class="card-link">
        <div class="card">
            <div class="card-header !pb-0 !border-b-0">
                <h5>Dispatch Orders</h5>
                <i class="fas fa-truck text-blue-500 text-xl"></i>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-light flex items-center mb-0">
                        <span class="status-indicator status-dispatch"></span>
                        <?= $stats['dispatch_orders'] ?>
                    </h3>
                    <p class="mb-0 text-sm text-blue-600">View</p>
                </div>
                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                    <div class="bg-blue-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                        style="width: <?= $stats['total_orders'] > 0 ? ($stats['dispatch_orders'] / $stats['total_orders']) * 100 : 0 ?>%"></div>
                </div>
            </div>
        </div>
    </a>
</div>

<!-- Canceled Orders -->
<div class="col-span-12 xl:col-span-4 md:col-span-6">
   <a href="../orders/cancel_order_list.php<?= !empty($date_from) || !empty($date_to) ? '?date_from='.urlencode($date_from).'&date_to='.urlencode($date_to) : '' ?>" class="card-link">
        <div class="card">
            <div class="card-header !pb-0 !border-b-0">
                <h5>Canceled Orders</h5>
                <i class="fas fa-ban text-red-500 text-xl"></i>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-light flex items-center mb-0">
                        <span class="status-indicator status-cancel"></span>
                        <?= $stats['cancel_orders'] ?>
                    </h3>
                    <p class="mb-0 text-sm text-red-600">View</p>
                </div>
                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                    <div class="bg-red-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                        style="width: <?= $stats['total_orders'] > 0 ? ($stats['cancel_orders'] / $stats['total_orders']) * 100 : 0 ?>%"></div>
                </div>
            </div>
        </div>
    </a>
</div>

<!-- Return Complete Orders -->
<div class="col-span-12 xl:col-span-4 md:col-span-6">
    <a href="../orders/return_complete_order_list.php<?= !empty($date_from) || !empty($date_to) ? '?date_from='.urlencode($date_from).'&date_to='.urlencode($date_to) : '' ?>" class="card-link">
        <div class="card">
            <div class="card-header !pb-0 !border-b-0">
                <h5>Return Complete Orders</h5>
                <i class="fas fa-undo text-purple-500 text-xl"></i>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-light flex items-center mb-0">
                        <span class="status-indicator status-return-complete"></span>
                        <?= $stats['return_complete_orders'] ?>
                    </h3>
                    <p class="mb-0 text-sm text-purple-600">View</p>
                </div>
                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                    <div class="bg-purple-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                        style="width: <?= $stats['total_orders'] > 0 ? ($stats['return_complete_orders'] / $stats['total_orders']) * 100 : 0 ?>%"></div>
                </div>
            </div>
        </div>
    </a>
</div>

<!-- Return Handover Orders -->
<div class="col-span-12 xl:col-span-4 md:col-span-6">
    <a href="../orders/return_handover_order_list.php<?= !empty($date_from) || !empty($date_to) ? '?date_from='.urlencode($date_from).'&date_to='.urlencode($date_to) : '' ?>" class="card-link">
        <div class="card">
            <div class="card-header !pb-0 !border-b-0">
                <h5>Return Handover Orders</h5>
                <i class="fas fa-handshake text-orange-500 text-xl"></i>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-light flex items-center mb-0">
                        <span class="status-indicator status-return-handover"></span>
                        <?= $stats['return_handover_orders'] ?>
                    </h3>
                    <p class="mb-0 text-sm text-orange-600">View</p>
                </div>
                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                    <div class="bg-orange-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                        style="width: <?= $stats['total_orders'] > 0 ? ($stats['return_handover_orders'] / $stats['total_orders']) * 100 : 0 ?>%"></div>
                </div>
            </div>
        </div>
    </a>
</div>

                <!-- Inventory & User Management Section - Admin Only -->
                <div class="col-span-12 mt-6 admin-only">
                    <h2 class="section-title">Inventory & User Management</h2>
                </div>

                <!-- Total Users - Admin Only -->
                <div class="col-span-12 xl:col-span-4 md:col-span-6 admin-only">
                    <a href="/OMS/dist/users/users.php" class="card-link">
                        <div class="card">
                            <div class="card-header !pb-0 !border-b-0">
                                <h5>Total Users</h5>
                                <i class="fas fa-users text-purple-500 text-xl"></i>
                            </div>
                            <div class="card-body">
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <h3 class="font-light flex items-center mb-0">
                                        <i class="feather icon-arrow-up text-success-500 text-[30px] mr-1.5"></i>
                                        <?= $stats['total_users'] ?>
                                    </h3>
                                    <p class="mb-0 text-sm text-purple-600">View</p>
                                </div>
                                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                    <div class="bg-purple-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                                        style="width: 85%"></div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Total Customers -->
                <div class="col-span-12 xl:col-span-4 md:col-span-6">
                    <a href="/OMS/dist/customers/customer_list.php" class="card-link">
                        <div class="card">
                            <div class="card-header !pb-0 !border-b-0">
                                <h5>Total Customers
                                    <?php if (!$rbac->isAdmin()): ?>
                                        <span class="user-specific-label"></span>
                                    <?php endif; ?>
                                </h5>
                                <i class="fas fa-user-tie text-indigo-500 text-xl"></i>
                            </div>
                            <div class="card-body">
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <h3 class="font-light flex items-center mb-0">
                                        <i class="feather icon-arrow-up text-success-500 text-[30px] mr-1.5"></i>
                                        <?= $stats['total_customers'] ?>
                                    </h3>
                                    <p class="mb-0 text-sm text-indigo-600">View</p>
                                </div>
                                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                    <div class="bg-indigo-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                                        style="width: 95%"></div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Total Products - All users can view products -->
                <div class="col-span-12 xl:col-span-4 md:col-span-6">
                    <a href="/OMS/dist/products/product_list.php" class="card-link">
                        <div class="card">
                            <div class="card-header !pb-0 !border-b-0">
                                <h5>Total Products</h5>
                                <i class="fas fa-box text-teal-500 text-xl"></i>
                            </div>
                            <div class="card-body">
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <h3 class="font-light flex items-center mb-0">
                                        <i class="feather icon-arrow-up text-success-500 text-[30px] mr-1.5"></i>
                                        <?= $stats['total_products'] ?>
                                    </h3>
                                    <p class="mb-0 text-sm text-teal-600">View</p>
                                </div>
                                <div class="w-full bg-theme-bodybg rounded-lg h-1.5 mt-6 dark:bg-themedark-bodybg">
                                    <div class="bg-teal-500 h-full rounded-lg shadow-[0_10px_20px_0_rgba(0,0,0,0.3)]" role="progressbar"
                                        style="width: 60%"></div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

            </div>
            <!-- [ Main Content ] end -->
        </div>
    </div>
    <!-- [ Main Content ] end -->

   <!-- FOOTER -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php');
    ?>
    <!-- END FOOTER -->

    <!-- SCRIPTS -->
    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php');
    ?>
    <!-- END SCRIPTS -->
    
    <!-- Additional Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("#date_from", {
            dateFormat: "Y-m-d",
            allowInput: true,
            static: true
        });
        
        flatpickr("#date_to", {
            dateFormat: "Y-m-d",
            allowInput: true,
            static: true
        });
        
        // Reset button functionality
        document.getElementById('resetButton').addEventListener('click', function() {
            // Get today's date in YYYY-MM-DD format
            const today = new Date().toISOString().split('T')[0];
            
            // Set the input fields to today's date
            document.getElementById('date_from').value = today;
            document.getElementById('date_to').value = today;
            
            // Submit the form to refresh the page with today's date as the filter
            document.getElementById('orderFilterForm').submit();
        });

        // Initialize page with role-based functionality
        document.addEventListener('DOMContentLoaded', function() {
            const isAdmin = <?php echo $rbac->isAdmin() ? 'true' : 'false'; ?>;
            const currentUserId = <?php echo $current_user_id; ?>;
            const currentUserRole = <?php echo $current_user_role; ?>;
            
            console.log('Dashboard loaded with role-based access control');
            console.log('User ID:', currentUserId, 'Role:', currentUserRole, 'Is Admin:', isAdmin);
            
            // Add hover effects to cards
            const cards = document.querySelectorAll('.card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px)';
                    this.style.transition = 'all 0.3s ease';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
            
            // Display appropriate message based on user role
            if (!isAdmin) {
                console.log('Non-admin user: Displaying user-specific data only');
            } else {
                console.log('Admin user: Displaying all system data');
            }
        });
    </script>

</body>
<!-- [Body] end -->
</html>

<?php $conn->close(); ?>