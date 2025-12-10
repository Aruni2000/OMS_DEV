<?php
/**
 * Ten Fourteen Bulk Print (10cm × 13.9cm Labels)
 * Prints multiple orders based on filters from label print page
 * Each order is printed as a compact label with all essential information
 * Updated to use external print.css stylesheet
 * FIXED: Now prints 4 labels per page
 * FIXED: Customer address display issue resolved with city information
 * UPDATED: Improved product display - now comma-separated like Nine Nine format
 * NEW: Added tracking number filter functionality - DEFAULT shows only tracked orders
 * NEW: Barcode now displays tracking number instead of order ID
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
 * GET FILTER PARAMETERS FROM URL
 * These come from the main label print page filters
 * UPDATED: Added tracking filter parameters
 * DEFAULT: Ten Fourteen Bulk Print shows only orders WITH tracking numbers
 */
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$time_from = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$time_to = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';

// NEW: Tracking filter parameters - DEFAULT to 'with_tracking' for Ten Fourteen Bulk Print
$tracking_filter = isset($_GET['tracking_filter']) ? trim($_GET['tracking_filter']) : 'with_tracking';
$tracking_number = isset($_GET['tracking_number']) ? trim($_GET['tracking_number']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * BUILD QUERY TO FETCH ORDERS
 * FIXED: Customer address display using NULLIF to handle empty strings + city information
 */
$sql = "SELECT o.order_id, o.customer_id, o.full_name, o.mobile, o.address_line1, o.address_line2,
               o.status, o.updated_at, o.interface, o.tracking_number, o.total_amount, o.currency,
               o.delivery_fee, o.discount, o.issue_date,
               c.name as customer_name, c.phone as customer_phone, 
               c.email as customer_email, c.city_id,
               
               -- ADDED: Customer address lines separately for better control
               c.address_line1 as customer_address_line1,
               c.address_line2 as customer_address_line2,
               
               cr.courier_name as delivery_service,
               
               -- City information from city_table
               ct.city_name,
               
               -- FIXED: Customer address with NULLIF to handle empty strings + city
               CONCAT_WS(', ', 
                   NULLIF(c.address_line1, ''), 
                   NULLIF(c.address_line2, ''), 
                   ct.city_name
               ) as customer_address,
               
               -- FIXED: Display name with proper fallback
               COALESCE(NULLIF(o.full_name, ''), c.name, 'Unknown Customer') as display_name,
               
               -- FIXED: Display mobile with proper fallback
               COALESCE(NULLIF(o.mobile, ''), c.phone, 'No phone') as display_mobile,
               
               -- FIXED: Display address with proper priority and fallback + city
               COALESCE(
                   -- Priority 1: Order address (no city for orders typically)
                   NULLIF(CONCAT_WS(', ', NULLIF(o.address_line1, ''), NULLIF(o.address_line2, '')), ''),
                   -- Priority 2: Customer address with city
                   NULLIF(CONCAT_WS(', ', 
                       NULLIF(c.address_line1, ''), 
                       NULLIF(c.address_line2, ''), 
                       ct.city_name
                   ), ''),
                   'Address not available'
               ) as display_address
               
        FROM order_header o 
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        LEFT JOIN couriers cr ON o.courier_id = cr.courier_id
        LEFT JOIN city_table ct ON c.city_id = ct.city_id AND ct.is_active = 1
        WHERE o.interface IN ('individual', 'leads')";

// Build search conditions (same as main page)
$searchConditions = [];

if (!empty($date)) {
    $dateTerm = $conn->real_escape_string($date);
    $searchConditions[] = "DATE(o.updated_at) = '$dateTerm'";
}

if (!empty($time_from)) {
    $timeFromTerm = $conn->real_escape_string($time_from);
    $searchConditions[] = "TIME(o.updated_at) >= '$timeFromTerm'";
}

if (!empty($time_to)) {
    $timeToTerm = $conn->real_escape_string($time_to);
    $searchConditions[] = "TIME(o.updated_at) <= '$timeToTerm'";
}

if (!empty($status_filter) && $status_filter !== 'all') {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "o.status = '$statusTerm'";
}

// NEW: Tracking filter conditions
if (!empty($tracking_filter) && $tracking_filter !== 'all') {
    switch ($tracking_filter) {
        case 'with_tracking':
            $searchConditions[] = "o.tracking_number IS NOT NULL AND o.tracking_number != '' AND TRIM(o.tracking_number) != ''";
            break;
        case 'without_tracking':
            $searchConditions[] = "(o.tracking_number IS NULL OR o.tracking_number = '' OR TRIM(o.tracking_number) = '')";
            break;
        case 'specific_tracking':
            if (!empty($tracking_number)) {
                $trackingTerm = $conn->real_escape_string($tracking_number);
                $searchConditions[] = "o.tracking_number LIKE '%$trackingTerm%'";
            }
            break;
    }
}

// Apply search conditions
if (!empty($searchConditions)) {
    $sql .= " AND " . implode(' AND ', $searchConditions);
}

$sql .= " ORDER BY o.updated_at DESC, o.order_id DESC LIMIT $limit OFFSET $offset";

// Execute query
$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}

/**
 * FETCH ORDER ITEMS FOR ALL ORDERS
 * Get products for each order in a single optimized query
 * UPDATED: Group by product_id and product_name to sum quantities properly
 */
$orders = [];
$order_ids = [];

while ($order = $result->fetch_assoc()) {
    $orders[] = $order;
    $order_ids[] = $order['order_id'];
}

// Get all items for all orders at once with proper grouping
$items_by_order = [];
if (!empty($order_ids)) {
    $order_ids_str = implode(',', array_map('intval', $order_ids));
    $items_query = "SELECT oi.order_id, oi.product_id, p.name as product_name, 
                    SUM(oi.quantity) as total_quantity
                    FROM order_items oi
                    JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id IN ($order_ids_str)
                    GROUP BY oi.order_id, oi.product_id, p.name
                    ORDER BY oi.order_id, p.name";
    
    $items_result = $conn->query($items_query);
    if ($items_result) {
        while ($item = $items_result->fetch_assoc()) {
            $items_by_order[$item['order_id']][] = $item;
        }
    }
}

// Company information
$company = [
    'name' => 'FE IT Solutions pvt (Ltd)',
    'address' => 'No: 04, Wijayamangalarama Road, Kohuwala',
    'email' => 'info@feitsolutions.com',
    'phone' => '011-2824524'
];

/**
 * HELPER FUNCTIONS
 * UPDATED: Barcode functions now handle tracking numbers
 */
function getCurrencySymbol($currency) {
    return (strtolower($currency) == 'usd') ? '$' : 'Rs.';
}

function getBarcodeUrl($data) {
    // Using Code128 format which is widely supported by barcode scanners
    return "https://barcodeapi.org/api/code128/{$data}";
}

function getQRCodeUrl($data) {
    return "https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=" . urlencode($data);
}

function calculateSubtotal($total, $delivery, $discount) {
    return floatval($total) - floatval($delivery) + floatval($discount);
}

// Function to check if tracking number exists
function hasTracking($tracking_number) {
    return !empty($tracking_number) && trim($tracking_number) !== '';
}

// NEW: Function to get tracking filter display text
function getTrackingFilterText($tracking_filter, $tracking_number = '') {
    switch ($tracking_filter) {
        case 'with_tracking':
            return 'Orders WITH tracking numbers';
        case 'without_tracking':
            return 'Orders WITHOUT tracking numbers';
        case 'specific_tracking':
            return !empty($tracking_number) ? "Tracking contains: '{$tracking_number}'" : 'Specific tracking (no number provided)';
        default:
            return 'All orders (no tracking filter)';
    }
}

// NEW: Count orders by tracking status for summary
$tracking_stats = [
    'with_tracking' => 0,
    'without_tracking' => 0,
    'total' => count($orders)
];

foreach ($orders as $order) {
    if (hasTracking($order['tracking_number'])) {
        $tracking_stats['with_tracking']++;
    } else {
        $tracking_stats['without_tracking']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ten Fourteen Bulk Print - Receipt Labels (<?php echo count($orders); ?> orders)</title>
    
    <!-- Link to external CSS file -->
    <link rel="stylesheet" href="../assets/css/print.css">
    
</head>
<body>
    <!-- Print Instructions (hidden when printing) -->
    <div class="print-instructions">
        <h3>Ten Fourteen Bulk Print Instructions</h3>
        <p><strong>Orders Found:</strong> <?php echo count($orders); ?> orders</p>
        <p><strong>Label Size:</strong> 10cm × 13.9cm receipt format</p>
        <p><strong>Labels Per Page:</strong> 4 labels (2x2 grid)</p>
        <p><strong>Format:</strong> Comma-separated products with grouped quantities</p>
    
        
        <p><strong>Filters Applied:</strong></p>
        <ul>
            <?php if ($date): ?><li>Date: <?php echo htmlspecialchars($date); ?></li><?php endif; ?>
            <?php if ($time_from): ?><li>Time From: <?php echo htmlspecialchars($time_from); ?></li><?php endif; ?>
            <?php if ($time_to): ?><li>Time To: <?php echo htmlspecialchars($time_to); ?></li><?php endif; ?>
            <?php if ($status_filter !== 'all'): ?><li>Status: <?php echo htmlspecialchars($status_filter); ?></li><?php endif; ?>
            
            <!-- NEW: Tracking filter display - Only show if manually overridden -->
            <?php if (isset($_GET['tracking_filter']) && $_GET['tracking_filter'] !== 'with_tracking'): ?>
                <li><strong>Tracking Filter:</strong> <?php echo htmlspecialchars(getTrackingFilterText($tracking_filter, $tracking_number)); ?></li>
            <?php elseif (!isset($_GET['tracking_filter'])): ?>
                <li><strong>Default Mode:</strong> <span style="color: #28a745;">Showing only orders WITH tracking</span></li>
            <?php endif; ?>
        </ul>
        <button class="print-button" onclick="window.print()">🖨️ Print Labels</button>
        <button class="print-button" onclick="window.close()" style="background: #6c757d;">❌ Close</button>
    </div>

    <!-- Labels Container -->
    <div class="labels-container">
        <?php if (empty($orders)): ?>
            <div class="no-orders">
                <h3>No Trackable Orders Found</h3>
                <p>No trackable orders found matching the selected filters.</p>
                <p><em>Ten Fourteen Bulk Print shows only orders with tracking numbers assigned.</em></p>
                <?php if (isset($_GET['tracking_filter']) && $_GET['tracking_filter'] !== 'with_tracking'): ?>
                    <p><em>Current filter: <?php echo htmlspecialchars(getTrackingFilterText($tracking_filter, $tracking_number)); ?></em></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php 
            $labels_per_page = 4; // CHANGED: Now 4 labels per page
            $total_orders = count($orders);
            $current_page_labels = 0;
            ?>
            
            <?php foreach ($orders as $index => $order): ?>
                <?php
                // Start new page wrapper every 4 labels
                if ($current_page_labels == 0): ?>
                    <div class="page-wrapper">
                <?php endif; ?>
                
                <?php
                // Prepare order data 
                $order_id = $order['order_id'];
                $currency_symbol = getCurrencySymbol($order['currency'] ?? 'lkr');
                
                // UPDATED: Use tracking number for barcode instead of order ID
                $tracking_number_raw = !empty($order['tracking_number']) ? trim($order['tracking_number']) : '';
                $has_tracking = hasTracking($tracking_number_raw);
                
                // Barcode data and URLs - only if tracking exists
                if ($has_tracking) {
                    $barcode_data = $tracking_number_raw;
                    $barcode_url = getBarcodeUrl($barcode_data);
                    $qr_url = getQRCodeUrl("Tracking: " . $tracking_number_raw . " | Order: " . $order_id);
                    $tracking_display = $tracking_number_raw;
                } else {
                    // This should not happen since we're filtering for tracking orders, but keeping as fallback
                    $barcode_data = str_pad($order_id, 10, '0', STR_PAD_LEFT);
                    $barcode_url = getBarcodeUrl($barcode_data);
                    $qr_url = getQRCodeUrl("Order: " . $order_id . " | No Tracking");
                    $tracking_display = 'No Tracking';
                }
                
                // Calculate totals
                $total_amount = floatval($order['total_amount']);
                $delivery_fee = floatval($order['delivery_fee']);
                $discount = floatval($order['discount']);
                $subtotal = calculateSubtotal($total_amount, $delivery_fee, $discount);
                
                // Get items for this order
                $order_items = isset($items_by_order[$order_id]) ? $items_by_order[$order_id] : [];
                ?>
                
                <div class="label-wrapper">
                    <div class="receipt-container">
                        <!-- Main Table Structure -->
                        <table class="main-table">
                            <!-- Header Section -->
                            <tr>
                                <td class="header-section" colspan="2">
                                    <div class="company-logo">
                                        <img src="../assets/images/OMS.png" alt="Company Logo">
                                    </div>
                                    <div class="company-name"><?php echo htmlspecialchars($company['name']); ?></div>
                                    <div class="company-info">Address: <?php echo htmlspecialchars($company['address']); ?></div>
                                    <div class="company-info">Phone: <?php echo htmlspecialchars($company['phone']); ?> | Email: <?php echo htmlspecialchars($company['email']); ?></div>
                                </td>
                                <td class="order-id-cell">
                                    <div style="font-weight: bold; margin-bottom: 2mm;">
                                        Order ID: <?php echo str_pad($order_id, 5, '0', STR_PAD_LEFT); ?>
                                        <!-- NEW: Tracking status indicator -->
                                        <?php if ($has_tracking): ?>
                                            <div style="color: #28a745; font-size: 8px; font-weight: bold;"></div>
                                        <?php else: ?>
                                            <div style="color: #dc3545; font-size: 8px; font-weight: bold;">⚠ NO TRACK</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="barcode-section">
                                        <!-- UPDATED: Barcode Section - Now shows tracking number -->
                                        <?php if ($has_tracking): ?>
                                            <img src="<?php echo $barcode_url; ?>" alt="Tracking Barcode" class="barcode-image" onerror="this.style.display='none'">
                                            <div class="barcode-text"><?php echo htmlspecialchars($tracking_number_raw); ?></div>
                                            <div style="font-size: 6px; margin-top: 0.5mm; color: #666;"></div>
                                        <?php else: ?>
                                            <div class="no-tracking-barcode">
                                                <div style="border: 1px dashed #dc2626; padding: 4px; text-align: center; font-size: 8px; color: #dc2626; background: #fef2f2;">
                                                    NO BARCODE<br>
                                                    <span style="font-size: 6px;">No tracking assigned</span>
                                                </div>
                                                <div class="barcode-text" style="color: #dc2626;">No Tracking</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Delivery Service Row -->
                            <tr>
                                <td class="delivery-service-cell">
                                    <strong>Delivery Service:</strong><br>
                                    <?php echo !empty($order['delivery_service']) ? htmlspecialchars($order['delivery_service']) : 'Standard Delivery'; ?>
                                </td>
                                <td class="tracking-cell" colspan="2">
                                    <strong>Tracking:</strong> 
                                    <?php if ($has_tracking): ?>
                                        <span style="color: #2563eb;"><?php echo htmlspecialchars(substr($tracking_display, 0, 15)); ?></span>
                                    <?php else: ?>
                                        <span style="color: #dc2626;">No Tracking</span>
                                    <?php endif; ?><br>
                                    <strong>Date:</strong> <?php echo !empty($order['issue_date']) ? date('Y-m-d', strtotime($order['issue_date'])) : date('Y-m-d'); ?>
                                </td>
                            </tr>

                            <!-- UPDATED: Products Section - Now comma-separated like Nine Nine -->
                            <tr>
                                <td class="product-header" colspan="3">
                                    <strong>Products (<?php echo count($order_items); ?>):</strong>
                                    <div style="margin-top: 1mm; font-size: 9px; line-height: 1.2;">
                                        <?php if (!empty($order_items)): ?>
                                            <?php 
                                            $product_list = [];
                                            foreach ($order_items as $item) {
                                                $product_name = htmlspecialchars(substr($item['product_name'], 0, 25));
                                                if (strlen($item['product_name']) > 25) $product_name .= '...';
                                                $product_list[] = $item['product_id'] . " - " . $product_name . " (" . $item['total_quantity'] . ")";
                                            }
                                            echo implode(', ', $product_list);
                                            ?>
                                        <?php else: ?>
                                            No items found
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Customer Details and Totals -->
                            <tr>
                                <td class="customer-header">Customer Details</td>
                                <td class="totals-header">Summary</td>
                                <td class="totals-header">Amount</td>
                            </tr>

                            <tr>
                                <td class="customer-info">
                                    <strong>Name:</strong> <?php echo htmlspecialchars(substr($order['display_name'], 0, 20)); ?><br>
                                    <strong>Phone:</strong> <?php echo htmlspecialchars($order['display_mobile']); ?><br>
                                    <strong>Address:</strong> <?php echo htmlspecialchars(substr($order['display_address'], 0, 60)) . (strlen($order['display_address']) > 60 ? '...' : ''); ?>
                                </td>
                                <td class="totals-cell">
                                    Subtotal:<br>
                                    Delivery:<br>
                                    Discount:
                                </td>
                                <td class="totals-cell amount">
                                    <?php echo $currency_symbol . ' ' . number_format($subtotal, 2); ?><br>
                                    <?php echo $currency_symbol . ' ' . number_format($delivery_fee, 2); ?><br>
                                    <?php echo $currency_symbol . ' ' . number_format($discount, 2); ?>
                                </td>
                            </tr>

                            <!-- Total Payable -->
                            <tr>
                                <td class="total-payable" colspan="2">TOTAL PAYABLE</td>
                                <td class="total-payable amount"><?php echo $currency_symbol . ' ' . number_format($total_amount, 2); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php 
                $current_page_labels++;
                
                // Close page wrapper and reset counter every 4 labels
                if ($current_page_labels == $labels_per_page || $index == $total_orders - 1): 
                    $current_page_labels = 0; ?>
                    </div> <!-- Close page-wrapper -->
                    
                    <?php if ($index < $total_orders - 1): ?>
                        <div class="page-break"></div>
                    <?php endif; ?>
                <?php endif; ?>
                
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        // Auto print when page loads (with small delay for images to load)
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        });

        // Handle print completion
        window.addEventListener('afterprint', function() {
            console.log('Print completed');
        });

        // Log loaded orders for debugging
        console.log('Ten Fourteen bulk print loaded: <?php echo count($orders); ?> orders');
        console.log('DEFAULT MODE: Only showing orders WITH tracking numbers');
        console.log('UPDATED: Barcode now displays tracking number instead of order ID');
        console.log('UPDATED: Products now display comma-separated with grouped quantities like Nine Nine format');
        console.log('Orders displayed (all have tracking): <?php echo count($orders); ?>');
        <?php if (!isset($_GET['tracking_filter'])): ?>
        console.log('AUTO-FILTER: Automatically filtering to show only trackable orders');
        <?php else: ?>
        console.log('MANUAL-FILTER: Custom tracking filter applied - <?php echo $tracking_filter; ?>');
        <?php endif; ?>
        <?php if ($tracking_filter === 'specific_tracking' && !empty($tracking_number)): ?>
        console.log('Tracking search term: <?php echo addslashes($tracking_number); ?>');
        <?php endif; ?>
    </script>
</body>
</html>

<?php
$conn->close();
?>