<?php
/**
 * Nine Nine Bulk Print (8.5cm × 8.5cm Labels) 
 * Prints multiple orders based on filters from label print page
 * Each order is printed as a compact label with all essential information
 * Updated to use external print.css stylesheet
 * FIXED: Now prints 6 labels per page
 * FIXED: Customer address display issue resolved with city information
 * FIXED: Barcode positioning and font size issues
 * FIXED: Better space management and readable font sizes
 * MODIFIED: Company header layout, simplified customer section, simplified totals
 * FIXED: Better styling for "more items" display
 * UPDATED: Separated address and city display
 * UPDATED: Products now display comma-separated with grouped quantities
 * UPDATED: Barcode now displays tracking number instead of order ID
 * NEW: Added tracking number filter functionality
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
 * DEFAULT: Nine Nine Bulk Print shows only orders WITH tracking numbers
 */
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$time_from = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$time_to = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';

// NEW: Tracking filter parameters - DEFAULT to 'with_tracking' for Nine Nine Bulk Print
$tracking_filter = isset($_GET['tracking_filter']) ? trim($_GET['tracking_filter']) : 'with_tracking';
$tracking_number = isset($_GET['tracking_number']) ? trim($_GET['tracking_number']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * BUILD QUERY TO FETCH ORDERS
 * UPDATED: Added separate customer address lines for better display control
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
               
               -- Customer address with city (for fallback)
               CONCAT_WS(', ', 
                   NULLIF(c.address_line1, ''), 
                   NULLIF(c.address_line2, ''), 
                   ct.city_name
               ) as customer_address,
               
               -- Display name with proper fallback
               COALESCE(NULLIF(o.full_name, ''), c.name, 'Unknown Customer') as display_name,
               
               -- Display mobile with proper fallback
               COALESCE(NULLIF(o.mobile, ''), c.phone, 'No phone') as display_mobile
               
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
    <title>Nine Nine Bulk Print - Receipt Labels (<?php echo count($orders); ?> orders)</title>
    
   <!-- Link to external CSS file -->
<link rel="stylesheet" type="text/css" href="../assets/css/bulk_print.css">
    
</head>
<body>
    <!-- Print Instructions (hidden when printing) -->
    <div class="print-instructions">
        <h3>Nine Nine Bulk Print Instructions </h3>
        <p><strong>Orders Found:</strong> <?php echo count($orders); ?> orders</p>
        <p><strong>Label Size:</strong> 8.5cm × 8.5cm compact format</p>
        <p><strong>Labels Per Page:</strong> 6 labels (2×3 grid)</p>
        
      
        <p><strong>Filters Applied:</strong></p>
        <ul>
            <?php if ($date): ?><li>Date: <?php echo htmlspecialchars($date); ?></li><?php endif; ?>
            <?php if ($time_from): ?><li>Time From: <?php echo htmlspecialchars($time_from); ?></li><?php endif; ?>
            <?php if ($time_to): ?><li>Time To: <?php echo htmlspecialchars($time_to); ?></li><?php endif; ?>
            <?php if ($status_filter !== 'all'): ?><li>Status: <?php echo htmlspecialchars($status_filter); ?></li><?php endif; ?>
            
            <!-- NEW: Tracking filter display -->
            <?php if ($tracking_filter !== 'all'): ?>
                <li><strong>Tracking Filter:</strong> <?php echo htmlspecialchars(getTrackingFilterText($tracking_filter, $tracking_number)); ?></li>
            <?php endif; ?>
        </ul>
        <button class="print-button" onclick="window.print()">🖨️ Print Labels</button>
        <button class="print-button" onclick="window.close()" style="background: #6c757d;">❌ Close</button>
    </div>

    <!-- Labels Container -->
    <div class="labels-container">
        <?php if (empty($orders)): ?>
            <div class="no-orders">
                <h3>No Orders Found</h3>
                <p>No orders match the selected filters.</p>
                <?php if ($tracking_filter !== 'all'): ?>
                    <p><em>Try adjusting your tracking filter: <?php echo htmlspecialchars(getTrackingFilterText($tracking_filter, $tracking_number)); ?></em></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php 
            $labels_per_page = 6; // 6 labels per page
            $total_orders = count($orders);
            $current_page_labels = 0;
            ?>
            
            <?php foreach ($orders as $index => $order): ?>
                <?php
                // Start new page wrapper every 6 labels
                if ($current_page_labels == 0): ?>
                    <div class="page-wrapper">
                <?php endif; ?>
                
                <?php
                // Prepare order data 
                $order_id = $order['order_id'];
                $currency_symbol = getCurrencySymbol($order['currency'] ?? 'lkr');
                
                // UPDATED: Use tracking number for barcode instead of order ID
                $tracking_number = !empty($order['tracking_number']) ? trim($order['tracking_number']) : '';
                $has_tracking = hasTracking($tracking_number);
                
                // Barcode data and URLs - only if tracking exists
                if ($has_tracking) {
                    $barcode_data = $tracking_number;
                    $barcode_url = getBarcodeUrl($barcode_data);
                    $qr_url = getQRCodeUrl("Tracking: " . $tracking_number . " | Order: " . $order_id);
                } else {
                    $barcode_data = '';
                    $barcode_url = '';
                    $qr_url = '';
                }
                
                // Calculate totals
                $total_amount = floatval($order['total_amount']);
                $delivery_fee = floatval($order['delivery_fee']);
                $discount = floatval($order['discount']);
                $subtotal = calculateSubtotal($total_amount, $delivery_fee, $discount);
                
                // Get items for this order
                $order_items = isset($items_by_order[$order_id]) ? $items_by_order[$order_id] : [];
                
                // Determine if we need compact mode based on content
                $compact_mode = (count($order_items) > 3 || strlen($order['display_name']) > 50);
                ?>
                
                <div class="label-wrapper <?php echo $compact_mode ? 'compact' : ''; ?>">
                    <div class="receipt-container">
                        <!-- MODIFIED: Header Section with order info on right -->
                        <div class="header-section">
                            <div class="company-left">
                                <div class="company-logo">
                                    <img src="../assets/images/OMS.png" alt="Company Logo">
                                </div>
                                <div class="company-name"><?php echo htmlspecialchars($company['name']); ?></div>
                            </div>
                            <div class="order-right">
                                <div class="order-id">ORDER: <?php echo str_pad($order_id, 5, '0', STR_PAD_LEFT); ?></div>
                                <div class="order-date"><?php echo !empty($order['issue_date']) ? date('Y-m-d', strtotime($order['issue_date'])) : date('Y-m-d'); ?></div>
                                
                                <!-- NEW: Tracking status indicator -->
                                <?php if ($has_tracking): ?>
                                    <div style="color: #28a745; font-size: 6px; font-weight: bold;"></div>
                                <?php else: ?>
                                    <div style="color: #dc3545; font-size: 6px; font-weight: bold;">⚠ NO TRACK</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- UPDATED: Customer Section with separated address and city display -->
                        <div class="customer-section">
                            <div class="customer-info">
                                <div><strong>Customer Name:</strong> <?php echo htmlspecialchars(substr($order['display_name'], 0, 35)) . (strlen($order['display_name']) > 35 ? '...' : ''); ?></div>
                                <div><strong>Phone:</strong> <?php echo htmlspecialchars($order['display_mobile']); ?></div>
                                <div><strong>Address:</strong> 
                                    <?php 
                                    // Build address with only address lines (no city)
                                    $address_parts = [];
                                    
                                    // Priority 1: Use order address lines if available
                                    if (!empty($order['address_line1'])) {
                                        $address_parts[] = trim($order['address_line1']);
                                    }
                                    if (!empty($order['address_line2'])) {
                                        $address_parts[] = trim($order['address_line2']);
                                    }
                                    
                                    // Priority 2: If no order address, use customer address lines
                                    if (empty($address_parts)) {
                                        if (!empty($order['customer_address_line1'])) {
                                            $address_parts[] = trim($order['customer_address_line1']);
                                        }
                                        if (!empty($order['customer_address_line2'])) {
                                            $address_parts[] = trim($order['customer_address_line2']);
                                        }
                                    }
                                    
                                    $address_only = implode(', ', array_filter($address_parts));
                                    if (empty($address_only)) {
                                        $address_only = 'Address not available';
                                    }
                                    
                                    echo htmlspecialchars(substr($address_only, 0, 55)) . (strlen($address_only) > 55 ? '...' : '');
                                    ?>
                                </div>
                                <div><strong>City:</strong> <?php echo !empty($order['city_name']) ? htmlspecialchars($order['city_name']) : 'Not specified'; ?></div>
                            </div>
                        </div>

                        <!-- UPDATED: Products Section - Now comma-separated -->
                        <div class="products-section">
                            <div class="products-header">
                                <strong>Products (<?php echo count($order_items); ?>):</strong>
                            </div>
                            <div class="products-list">
                                <?php if (!empty($order_items)): ?>
                                    <?php 
                                    $product_list = [];
                                    foreach ($order_items as $item) {
                                        $product_name = htmlspecialchars(substr($item['product_name'], 0, 20));
                                        if (strlen($item['product_name']) > 20) $product_name .= '...';
                                        $product_list[] = $item['product_id'] . " - " . $product_name . " (" . $item['total_quantity'] . ")";
                                    }
                                    echo implode(', ', $product_list);
                                    ?>
                                <?php else: ?>
                                    No items found
                                <?php endif; ?>
                            </div>

                            <!-- Delivery info with tracking status -->
                            <div class="delivery-info" style="margin-top: 2mm;">
                                <strong>Service:</strong> <?php echo !empty($order['delivery_service']) ? htmlspecialchars(substr($order['delivery_service'], 0, 15)) : 'none'; ?> | 
                                <strong>Track:</strong> 
                                <?php if ($has_tracking): ?>
                                    <span style="color: #2563eb;"><?php echo htmlspecialchars(substr($tracking_number, 0, 12)); ?></span>
                                <?php else: ?>
                                    <span style="color: #dc2626;">No Tracking</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- MODIFIED: Simplified Totals Section - only show total -->
                        <div class="totals-section">
                            <div class="total-only">
                                TOTAL: <?php echo $currency_symbol . ' ' . number_format($total_amount, 2); ?>
                            </div>
                        </div>

                        <!-- UPDATED: Barcode Section - Show tracking number or "No Tracking" -->
                        <div class="barcode-section">
                            <?php if ($has_tracking): ?>
                                <img src="<?php echo $barcode_url; ?>" alt="Tracking Barcode" class="barcode-image" onerror="this.style.display='none'">
                                <div class="barcode-text"><?php echo htmlspecialchars($tracking_number); ?></div>
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
                    </div>
                </div>

                <?php 
                $current_page_labels++;
                
                // Close page wrapper and reset counter every 6 labels
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

        console.log('Nine Nine bulk print loaded: <?php echo count($orders); ?> orders');
        console.log('UPDATED: Barcode now displays tracking number instead of order ID');
        console.log('NEW: Tracking filter implemented');
        console.log('Orders with tracking: <?php echo $tracking_stats['with_tracking']; ?>');
        console.log('Orders without tracking: <?php echo $tracking_stats['without_tracking']; ?>');
        console.log('Tracking filter: <?php echo $tracking_filter; ?>');
        <?php if ($tracking_filter === 'specific_tracking' && !empty($tracking_number)): ?>
        console.log('Tracking search term: <?php echo addslashes($tracking_number); ?>');
        <?php endif; ?>
    </script>
</body>
</html>

<?php
$conn->close();
?>