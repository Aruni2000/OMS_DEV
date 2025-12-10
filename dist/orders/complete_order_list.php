<?php
/**
 * Complete Orders Management System
 * This page displays orders with status 'complete' for individual interface
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

/**
 * DATABASE QUERIES
 * Main query to fetch orders with customer and payment information
 * Filtered for individual interface and complete status only
 */

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.created_by = u2.id
             WHERE i.interface = 'individual' AND i.status = 'done'";

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
        WHERE i.interface = 'individual' AND i.status = 'done'";

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
// if (!empty($pay_status_filter)) {
//     $payStatusTerm = $conn->real_escape_string($pay_status_filter);
//     $searchConditions[] = "i.pay_status = '$payStatusTerm'";
// }

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
    <title>Order Management Admin Portal - Complete Orders</title>
    
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
                        <h5 class="mb-0 font-medium">Complete Orders</h5>
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
                        
                        <!-- <div class="form-group">
                            <label for="pay_status_filter">Payment Status</label>
                            <select id="pay_status_filter" name="pay_status_filter">
                                <option value="">All Payment Status</option>
                                <option value="paid" <?php echo ($pay_status_filter == 'paid') ? 'selected' : ''; ?>>Paid</option>
                                <option value="unpaid" <?php echo ($pay_status_filter == 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                             
                            </select>
                        </div> -->
                        
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
                    <div class="order-count-subtitle">Total Complete Orders</div>
                </div>

                <!-- Orders Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
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
                                            echo isset($row['creator_name']) ? htmlspecialchars($row['creator_name']) : 'N/A';
                                            ?>
                                        </td>
                                        
                                       <!-- Action Buttons -->
<td class="actions">
    <div class="action-buttons-group">
        <button class="action-btn view-btn" title="View Order Details" 
                onclick="openOrderModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
            <i class="fas fa-eye"></i>
        </button>
 <button class="action-btn dispatch-btn" title="Mark as Dispatched" 
        onclick="openDispatchModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
    <i class="fas fa-truck"></i>
</button>
    
    </div>
</td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        No complete orders found
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
   
<!-- DISPATCH MODAL HTML (Complete modal structure) -->
<div class="modal-overlay" id="dispatchOrderModal" style="display: none;">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-truck me-2"></i>Dispatch Order
            </h3>
            <button class="modal-close" onclick="closeDispatchModal()" type="button">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="dispatch-order-form">
            <input type="hidden" name="order_id" id="dispatch_order_id">
            
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    Dispatching this order will assign a tracking number and update the order status.
                </div>
                
                <div class="form-group mb-3">
                    <label for="carrier" class="form-label">Courier Service <span class="text-danger">*</span></label>
                    <select class="form-control" id="carrier" name="carrier" required>
                        <option value="" selected disabled>Select courier service</option>
                        <?php
                        // Fetch active couriers from the database
                        $courier_query = "SELECT courier_id, courier_name FROM couriers WHERE status = 'active' ORDER BY courier_name";
                        $courier_result = $conn->query($courier_query);
                        
                        if ($courier_result && $courier_result->num_rows > 0) {
                            while($courier = $courier_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $courier['courier_id']; ?>"><?php echo htmlspecialchars($courier['courier_name']); ?></option>
                        <?php 
                            endwhile;
                        } else {
                            echo '<option value="" disabled>No couriers available</option>';
                        }
                        ?>
                    </select>
                    <small class="form-text text-muted">Select the courier service that will deliver this order</small>
                </div>
                
                <div class="form-group mb-3">
                    <label class="form-label">Tracking Number</label>
                    <div class="tracking-preview" id="tracking_number_display">
                        <span class="text-muted">Will be generated when you confirm dispatch</span>
                    </div>
                    <small class="form-text text-muted">An available tracking number will be assigned from the selected courier</small>
                </div>
                
                <div class="form-group mb-3">
                    <label for="dispatch_notes" class="form-label">Dispatch Notes</label>
                    <textarea class="form-control" id="dispatch_notes" name="dispatch_notes" rows="3" 
                              placeholder="Enter additional notes about this dispatch (optional)"></textarea>
                </div>
            </div>
            
        <div class="modal-footer" style="display: flex !important; justify-content: flex-end; padding: 15px; background: #f8f9fa; border-top: 1px solid #ddd;">
    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeDispatchModal()" 
            style="display: inline-flex !important; padding: 8px 16px; background: #6c757d !important; color: white !important; border: none; border-radius: 4px; margin-right: 10px;">
        <i class="fas fa-times me-1"></i>Cancel
    </button>
    <button type="submit" class="modal-btn modal-btn-primary" id="dispatch-submit-btn" disabled
            style="display: inline-flex !important; padding: 8px 16px; background: #007bff !important; color: white !important; border: none; border-radius: 4px;">
        <i class="fas fa-truck me-1"></i>Confirm Dispatch
    </button>
</div>
        </form>
    </div>
</div>


    <script>
        /**
         * JavaScript functionality for complete order management
         */
        
        let currentOrderId = null;

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

        // Open order modal and load details
        function openOrderModal(orderId) {
            // Enhanced validation
            if (!orderId || orderId.trim() === '') {
                alert('Order ID is required to view order details.');
                return;
            }
            
            console.log('Opening modal for Order ID:', orderId);
            
            currentOrderId = orderId.trim();
            const modal = document.getElementById('orderModal');
            const modalContent = document.getElementById('modalContent');
            const downloadBtn = document.getElementById('downloadBtn');
            
            // Show modal
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Show loading state
            modalContent.innerHTML = `
                <div class="modal-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    Loading order details for Order ID: ${currentOrderId}...
                </div>
            `;
            downloadBtn.style.display = 'none';
            
            // Fetch order details
            const fetchUrl = 'download_order.php?id=' + encodeURIComponent(currentOrderId);
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
            })
            .catch(error => {
                console.error('Error loading order details:', error);
                modalContent.innerHTML = `
                    <div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                        <h4>Error Loading Order Details</h4>
                        <p>Order ID: ${currentOrderId}</p>
                        <p>Error: ${error.message}</p>
                        <p>Please check if the download_order.php file exists and is accessible.</p>
                        <button onclick="retryLoadOrder()" class="btn btn-primary" style="margin-top: 10px;">
                            <i class="fas fa-redo"></i> Retry
                        </button>
                    </div>
                `;
            });
        }

        // Retry loading order
        function retryLoadOrder() {
            if (currentOrderId) {
                openOrderModal(currentOrderId);
            }
        }

        // Close order modal
        function closeOrderModal() {
            const modal = document.getElementById('orderModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            currentOrderId = null;
        }

        // Download order
        function downloadOrder() {
            if (!currentOrderId) {
                alert('No order selected for download.');
                return;
            }
            
            const downloadUrl = 'download_order.php?id=' + encodeURIComponent(currentOrderId) + '&download=1';
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
            console.log('Complete Orders page loaded, initializing...');
            
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

    </script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

</body>
</html>