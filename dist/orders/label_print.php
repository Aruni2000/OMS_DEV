<?php
/**
 * Label Print Page
 * Displays orders for label printing with date filters
 * Includes three print format options: 9x9, 2x5, and regular print
 */

// Start session management
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

/**
 * SEARCH AND FILTER PARAMETERS
 */
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d'); // Default to today
$time_from = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$time_to = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * DATABASE QUERIES
 * Fetch orders for label printing based on filters
 */
// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM order_header o 
             LEFT JOIN customers c ON o.customer_id = c.customer_id
             WHERE o.interface IN ('individual', 'leads')";
// Main query - Fixed column references
$sql = "SELECT o.order_id, o.customer_id, o.full_name, o.mobile, o.address_line1, o.address_line2,
               o.status, o.updated_at, o.interface, o.tracking_number, o.total_amount, o.currency,
               c.name as customer_name, c.phone as customer_phone, 
               CONCAT_WS(', ', c.address_line1, c.address_line2) as customer_address,
               COALESCE(o.full_name, c.name) as display_name,
               COALESCE(o.mobile, c.phone) as display_mobile,
               COALESCE(CONCAT_WS(', ', o.address_line1, o.address_line2), 
                       CONCAT_WS(', ', c.address_line1, c.address_line2)) as display_address
        FROM order_header o 
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        WHERE o.interface IN ('individual', 'leads')";

// Build search conditions
$searchConditions = [];
// Date filter (single date)
if (!empty($date)) {
    $dateTerm = $conn->real_escape_string($date);
    $searchConditions[] = "DATE(o.updated_at) = '$dateTerm'";
}

// Time range filter
if (!empty($time_from)) {
    $timeFromTerm = $conn->real_escape_string($time_from);
    $searchConditions[] = "TIME(o.updated_at) >= '$timeFromTerm'";
}

if (!empty($time_to)) {
    $timeToTerm = $conn->real_escape_string($time_to);
    $searchConditions[] = "TIME(o.updated_at) <= '$timeToTerm'";
}

// Status filter
if (!empty($status_filter) && $status_filter !== 'all') {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "o.status = '$statusTerm'";
}

// Apply search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " AND " . implode(' AND ', $searchConditions);
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY o.updated_at DESC, o.order_id DESC LIMIT $limit OFFSET $offset";

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
    <title>Label Print - Order Management</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/label_print.css" id="main-style-link" />

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
                        <h5 class="mb-0 font-medium">Label Print</h5>
                    </div>
                </div>
            </div>
            <div class="main-content-wrapper">

                <!-- Filters Container -->
                <div class="filters-container">
                    <form method="GET" action="" id="filterForm">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label for="date">Date</label>
                                <input type="date" id="date" name="date" 
                                       value="<?php echo htmlspecialchars($date); ?>">
                            </div>
                            <div class="filter-group">
                                <label for="time_from">Time From</label>
                                <input type="time" id="time_from" name="time_from" 
                                       value="<?php echo htmlspecialchars($time_from); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="time_to">Time To</label>
                                <input type="time" id="time_to" name="time_to" 
                                       value="<?php echo htmlspecialchars($time_to); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="status_filter">Status</label>
                                <select id="status_filter" name="status_filter">
                                   
                                    <option value="dispatch" <?php echo ($status_filter == 'dispatch') ? 'selected' : ''; ?>>Dispatch</option>
                    
                                </select>
                            </div>

                            <div class="filter-actions">
                                <button type="button" class="clear-btn" onclick="clearFilters()">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Results Info -->
                <!-- <div class="results-info">
                    Total orders found: <?php echo $totalRows; ?>
                </div> -->

                <!-- Print Buttons -->
                <div class="print-buttons">
                    <button class="print-btn" onclick="printLabels('9x9')">
                        <i class="fas fa-print"></i>
                        Print 9×9 Labels
                    </button>
                    <button class="print-btn" onclick="printLabels('4x13')">
                        <i class="fas fa-print"></i>
                        Print 4×13 Labels
                    </button>
                    <button class="print-btn" onclick="printLabels('regular')">
                        <i class="fas fa-print"></i>
                       Print 10×14 Labels
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Print labels function
        function printLabels(format) {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            params.set('format', format);
            params.delete('page'); // Remove pagination for print

            // Open print page in new window
            const printUrl = 'bulk_print.php?' + params.toString();
            const printWindow = window.open(printUrl, '_blank');
            if (!printWindow) {
                alert('Please allow popups for this site to open the print window.');
            }
        }

        // Clear filters function
        function clearFilters() {
            // Reset all form fields to their default values
            document.getElementById('date').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('time_from').value = '';
            document.getElementById('time_to').value = '';
            document.getElementById('status_filter').value = 'all';
            
            // Submit the form to apply the cleared filters
            document.getElementById('filterForm').submit();
        }
        
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Label print page loaded');
            console.log('Total orders found: <?php echo $totalRows; ?>');
        });
    </script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

</body>
</html>