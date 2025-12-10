<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Get current user info
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_role = $_SESSION['role_id'] ?? 0;

// Filters with proper sanitization
$search = trim($_GET['search'] ?? '');
$order_id_filter = trim($_GET['order_id_filter'] ?? '');
$customer_name_filter = trim($_GET['customer_name_filter'] ?? '');
$tracking_id = trim($_GET['tracking_id'] ?? '');
$courier_id_filter = trim($_GET['courier_id_filter'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$limit = intval($_GET['limit'] ?? 20);
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Role-based access
$roleCondition = ($current_user_role != 1) ? " AND i.user_id = $current_user_id" : "";

// Base SQL with UPDATED logic for return_handover - using percentage calculation
$sql = "SELECT i.order_id, i.customer_id, c.name AS customer_name, i.tracking_number,
               i.total_amount, 
               CASE 
                   WHEN i.status = 'return_handover' THEN (i.delivery_fee * COALESCE(co.return_fee_value, 0) / 100)
                   ELSE i.delivery_fee
               END AS delivery_fee,
               i.subtotal, i.status,
               co.courier_name, 
               COALESCE(co.return_fee_value, 0) AS return_fee_percentage,
               (i.delivery_fee * COALESCE(co.return_fee_value, 0) / 100) AS calculated_return_fee,
               CASE 
                   WHEN i.status = 'delivered' THEN i.subtotal
                   WHEN i.status = 'done' THEN i.subtotal
                   WHEN i.status = 'return_handover' THEN (0 - (i.delivery_fee * COALESCE(co.return_fee_value, 0) / 100))
                   ELSE 0
               END AS after_amount
        FROM order_header i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN couriers co ON i.courier_id = co.courier_id
        WHERE 1 $roleCondition";

// Search filters
$searchConditions = [];
// MAIN FIX: Only show data if courier OR status is selected, or if other filters are applied
$hasActiveFilters = !empty($courier_id_filter) || !empty($status_filter) || !empty($order_id_filter) || 
                   !empty($customer_name_filter) || !empty($tracking_id) || !empty($date_from) || 
                   !empty($date_to) || !empty($search);

if (!$hasActiveFilters) {
    // No filters selected - show no results
    $searchConditions[] = "1 = 0"; // This will return no results
} else {
    // Filters are active - apply normal logic
    if (empty($status_filter)) {
        $searchConditions[] = "(i.status IN ('delivered', 'return_handover', 'done'))";
    } else {
        $searchConditions[] = "i.status = '" . $conn->real_escape_string($status_filter) . "'";
    }
}
// Apply other filters
if (!empty($search)) {
    $escapedSearch = $conn->real_escape_string($search);
    $searchConditions[] = "(i.order_id LIKE '%$escapedSearch%' OR c.name LIKE '%$escapedSearch%' OR i.tracking_number LIKE '%$escapedSearch%')";
}
if (!empty($order_id_filter)) {
    $searchConditions[] = "i.order_id LIKE '%" . $conn->real_escape_string($order_id_filter) . "%'";
}
if (!empty($customer_name_filter)) {
    $searchConditions[] = "c.name LIKE '%" . $conn->real_escape_string($customer_name_filter) . "%'";
}
if (!empty($tracking_id)) {
    $searchConditions[] = "i.tracking_number LIKE '%" . $conn->real_escape_string($tracking_id) . "%'";
}
if (!empty($courier_id_filter)) {
    $searchConditions[] = "i.courier_id = '" . $conn->real_escape_string($courier_id_filter) . "'";
}
if (!empty($date_from)) {
    $searchConditions[] = "DATE(i.issue_date) >= '" . $conn->real_escape_string($date_from) . "'";
}
if (!empty($date_to)) {
    $searchConditions[] = "DATE(i.issue_date) <= '" . $conn->real_escape_string($date_to) . "'";
}
if ($searchConditions) {
    $sql .= " AND " . implode(' AND ', $searchConditions);
}

$sql .= " ORDER BY i.order_id DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

// Count total rows - fix the count query to match the main query conditions
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN couriers co ON i.courier_id = co.courier_id
             WHERE 1 $roleCondition";
if ($searchConditions) {
    $countSql .= " AND " . implode(' AND ', $searchConditions);
}
$totalRows = (int)$conn->query($countSql)->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Fetch couriers for filter
$couriersResult = $conn->query("SELECT courier_id, courier_name FROM couriers ORDER BY courier_name ASC");

// Function to build query string for pagination
function buildQueryString($params = []) {
    global $order_id_filter, $customer_name_filter, $status_filter, $tracking_id, $courier_id_filter, $date_from, $date_to, $limit;
    
    $defaults = [
        'order_id_filter' => $order_id_filter,
        'customer_name_filter' => $customer_name_filter,
        'status_filter' => $status_filter,
        'tracking_id' => $tracking_id,
        'courier_id_filter' => $courier_id_filter,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'limit' => $limit
    ];
    
    $queryParams = array_merge($defaults, $params);
    
    // Remove empty values
    $queryParams = array_filter($queryParams, function($value) {
        return $value !== '' && $value !== null;
    });
    
    return http_build_query($queryParams);
}

// Include navbar & sidebar
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Payment Report</title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <style>
        .total-row { font-weight:bold; background:#f9f9f9; }
        .tracking-container .form-group { margin-right: 15px; margin-bottom: 10px; }
        .return-fee-info { 
            font-size: 0.9em; 
            color: #666; 
            font-style: italic; 
        }
        .return-summary-container {
            animation: slideIn 0.3s ease-in-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
        .unpaid-mark {
            color: #dc3545;
            font-style: italic;
            font-weight: bold;
        }
        .return-fee-mark {
            color: #28a745;
            font-weight: bold;
        }
        .no-return-mark {
            color: #6c757d;
            font-style: italic;
        }
        .subtotal-mark {
            color: #007bff;
            font-weight: bold;
        }
        .return-deducted-mark {
            color: #dc3545;
            font-weight: bold;
        }
        /* Tooltip for better understanding */
        .tooltip-container {
            position: relative;
            display: inline-block;
        }
        .tooltip-container .tooltip-text {
            visibility: hidden;
            width: 250px;
            background-color: #333;
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -125px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
        }
        .tooltip-container:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        /* Auto-submit form styling */
        .auto-submit {
            background-color: #e8f5e8;
            border: 2px solid #28a745;
        }
        /* Button group styling */
        .button-group {
            display: flex;
            gap: 12px;
        }
    </style>
    <script>
        // Remove auto-submit functionality to prevent premature refreshing
        // Users must click Search button to submit form
    </script>
</head>

<body>
<div class="pc-container">
    <div class="pc-content">
        <div class="page-header">
            <div class="page-block">
                <div class="page-header-title">
                    <h5 class="mb-0 font-medium"> Payment Report</h5>
                </div>
            </div>
        </div>

        <div class="tracking-container">
            <form class="tracking-form" method="GET">
                <!-- Primary Filters - Most Important -->
                <div class="form-group">
                    <label><strong>Courier</strong> <span style="color: #dc3545;">*</span></label>
                    <select name="courier_id_filter" class="<?= !empty($courier_id_filter) ? 'auto-submit' : '' ?>">
                        <option value="">Select Courier</option>
                        <?php 
                        // Reset result pointer for couriers
                        if($couriersResult && $couriersResult->num_rows > 0): 
                            $couriersResult->data_seek(0); // Reset pointer
                            while($rowC = $couriersResult->fetch_assoc()): 
                        ?>
                            <option value="<?= $rowC['courier_id'] ?>" <?= ($courier_id_filter==$rowC['courier_id'])?'selected':'' ?>>
                                <?= htmlspecialchars($rowC['courier_name']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><strong>Status</strong></label>
                    <select name="status_filter" class="<?= !empty($status_filter) ? 'auto-submit' : '' ?>">
                        <option value="">All Statuses</option>
                        <option value="delivered" <?= ($status_filter=='delivered')?'selected':'' ?>>Delivered</option>
                        <option value="return_handover" <?= ($status_filter=='return_handover')?'selected':'' ?>>Return Handover</option>
                        <option value="done" <?= ($status_filter=='done')?'selected':'' ?>>Complete</option>
                    </select>
                </div>
                
                <!-- Date Range Filters -->
                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                
                <!-- Action Buttons -->
                <div class="form-group">
                    <div class="button-group">
                        <button type="submit" class="search-btn"><i class="fas fa-search"></i> SEARCH</button>
                        <button type="button" class="search-btn" style="background:#6c757d;" onclick="window.location.href='payment_report.php'">
                            <i class="fas fa-times"></i> CLEAR
                        </button>
                    </div>
                </div>
            </form>
            
            <?php if (!$hasActiveFilters): ?>
            <!-- <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 10px; margin-top: 10px; text-align: center;">
                <i class="fas fa-info-circle" style="color: #856404;"></i>
                <span style="color: #856404; margin-left: 5px;">Please select a courier to start viewing payment data</span>
            </div> -->
            <?php endif; ?>
        </div>

        <div class="order-count-container">
            <div class="order-count-number"><?= number_format($totalRows) ?></div>
            <div class="order-count-dash">-</div>
            <div class="order-count-subtitle">
                <?php if (!$hasActiveFilters): ?>
                    Please select filters to view data
                <?php else: ?>
                    Total Orders
                <?php endif; ?>
            </div>
        </div>

        <?php 
        // Calculate return summary totals for all filtered results (not just current page)
        // UPDATED summary calculation with percentage-based return fees
        $summarySql = "SELECT 
                       COUNT(*) as total_orders,
                       SUM(i.total_amount) as sum_total_amount,
                       SUM(i.subtotal) as sum_subtotal,
                       SUM(CASE 
                           WHEN i.status = 'return_handover' THEN (i.delivery_fee * COALESCE(co.return_fee_value, 0) / 100)
                           ELSE i.delivery_fee
                       END) as sum_delivery_fee,
                       SUM(CASE 
                           WHEN i.status = 'delivered' THEN i.subtotal
                           WHEN i.status = 'done' THEN i.subtotal
                           WHEN i.status = 'return_handover' THEN (0 - (i.delivery_fee * COALESCE(co.return_fee_value, 0) / 100))
                           ELSE 0
                       END) as sum_after_amount,
                       COUNT(CASE WHEN i.status = 'delivered' THEN 1 END) as delivered_orders,
                       COUNT(CASE WHEN i.status = 'return_handover' THEN 1 END) as return_orders,
                       COUNT(CASE WHEN i.status = 'done' THEN 1 END) as done_orders
                FROM order_header i
                LEFT JOIN customers c ON i.customer_id = c.customer_id
                LEFT JOIN couriers co ON i.courier_id = co.courier_id
                WHERE 1 $roleCondition";
        
        if ($searchConditions) $summarySql .= " AND " . implode(' AND ', $searchConditions);
        
        $summaryResult = $conn->query($summarySql);
        $summaryData = $summaryResult->fetch_assoc();
        ?>

        <div class="table-wrapper">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Total Amount</th>
                        <th>Subtotal</th>
                        <th>Delivery Fee</th>
                        <th>After Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sumTotalAmount = 0; 
                    $sumSubtotal = 0; 
                    $sumDelivery = 0; 
                    $sumAfterAmount = 0;

                    if ($result && $result->num_rows > 0):
                        while($row = $result->fetch_assoc()):
                            $sumTotalAmount += $row['total_amount'];
                            $sumSubtotal += $row['subtotal'];
                            $sumDelivery += $row['delivery_fee'];
                            $sumAfterAmount += $row['after_amount'];
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($row['order_id']) ?></td>
                            <td><?= htmlspecialchars($row['customer_name']) ?></td>
                            <td><?= ($row['status'] == 'done') ? 'Complete' : htmlspecialchars($row['status']) ?></td>
                            <td><?= number_format($row['total_amount'],2) ?></td>
                            <td><?= number_format($row['subtotal'],2) ?></td>
                            <td>
                                <?php if($row['status'] == 'return_handover'): ?>
                                    <div class="tooltip-container">
                                        <span class="return-fee-mark"><?= number_format($row['delivery_fee'],2) ?></span>
                                        <span class="tooltip-text">
                                            <?= $row['return_fee_percentage'] ?>% of original delivery fee 
                                            (Original: <?= number_format($row['calculated_return_fee'] * 100 / max($row['return_fee_percentage'], 1), 2) ?>)
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <?= number_format($row['delivery_fee'],2) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($row['status'] == 'delivered' || $row['status'] == 'done'): ?>
                                    <div class="tooltip-container">
                                        <span class="subtotal-mark"><?= number_format($row['after_amount'],2) ?></span>
                                        <span class="tooltip-text">Full subtotal - delivered successfully</span>
                                    </div>
                                <?php elseif($row['status'] == 'return_handover'): ?>
                                    <div class="tooltip-container">
                                        <span class="return-deducted-mark"><?= number_format($row['after_amount'],2) ?></span>
                                        <span class="tooltip-text">
                                            Return fee deduction: <?= $row['return_fee_percentage'] ?>% of delivery fee 
                                            (<?= number_format($row['calculated_return_fee'],2) ?>)
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="no-return-mark">0.00</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                        <tr class="total-row">
                            <td colspan="3" style="text-align:right;">Totals</td>
                            <td><?= number_format($sumTotalAmount,2) ?></td>
                            <td><?= number_format($sumSubtotal,2) ?></td>
                            <td><?= number_format($sumDelivery,2) ?></td>
                            <td>
                                <span class="return-fee-mark"><?= number_format($sumAfterAmount,2) ?></span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align:center; padding: 40px;">
                            <?php if (!$hasActiveFilters): ?>
                                <div style="color: #6c757d; font-style: italic;">
                                    <i class="fas fa-filter" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                    Please select a courier or status to view payment data
                                </div>
                            <?php else: ?>
                                No orders found matching your criteria
                            <?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
            </div>
            <div class="pagination-controls">
                <?php if ($page > 1): ?>
                    <button class="page-btn" onclick="window.location.href='?<?php echo buildQueryString(['page' => $page - 1]); ?>'">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                            onclick="window.location.href='?<?php echo buildQueryString(['page' => $i]); ?>'">
                        <?php echo $i; ?>
                    </button>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <button class="page-btn" onclick="window.location.href='?<?php echo buildQueryString(['page' => $page + 1]); ?>'">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
</body>
</html>