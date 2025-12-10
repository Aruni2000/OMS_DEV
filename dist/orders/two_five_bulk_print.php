<?php
/**
 * Nine Nine Bulk Print (10cm × 13.9cm Labels)
 * Prints multiple orders based on filters from label print page
 * Each order is printed as a compact label with all essential information
 * Updated to use external print.css stylesheet
 * FIXED: Now prints 4 labels per page
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
 */
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$time_from = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$time_to = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * BUILD QUERY TO FETCH ORDERS
 * Fixed the column references to match the actual database schema
 */
$sql = "SELECT o.order_id, o.customer_id, o.full_name, o.mobile, o.address_line1, o.address_line2,
               o.status, o.updated_at, o.interface, o.tracking_number, o.total_amount, o.currency,
               o.delivery_fee, o.discount, o.issue_date,
               c.name as customer_name, c.phone as customer_phone, 
               CONCAT_WS(', ', c.address_line1, c.address_line2) as customer_address,
               c.email as customer_email,
               cr.courier_name as delivery_service,
               COALESCE(o.full_name, c.name) as display_name,
               COALESCE(o.mobile, c.phone) as display_mobile,
               COALESCE(CONCAT_WS(', ', o.address_line1, o.address_line2), CONCAT_WS(', ', c.address_line1, c.address_line2)) as display_address
        FROM order_header o 
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        LEFT JOIN couriers cr ON o.courier_id = cr.courier_id
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
 */
$orders = [];
$order_ids = [];

while ($order = $result->fetch_assoc()) {
    $orders[] = $order;
    $order_ids[] = $order['order_id'];
}

// Get all items for all orders at once
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
 */
function getCurrencySymbol($currency) {
    return (strtolower($currency) == 'usd') ? '$' : 'Rs.';
}

function getBarcodeUrl($data) {
    return "https://barcodeapi.org/api/auto/{$data}";
}

function getQRCodeUrl($data) {
    return "https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=" . urlencode($data);
}

function calculateSubtotal($total, $delivery, $discount) {
    return floatval($total) - floatval($delivery) + floatval($discount);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Print - Receipt Labels (<?php echo count($orders); ?> orders)</title>
    
    <!-- Link to external CSS file -->
    <link rel="stylesheet" href="../assets/css/print.css">
    
   
</head>
<body>
    <!-- Print Instructions (hidden when printing) -->
    <div class="print-instructions">
        <h3>Bulk Print Instructions</h3>
        <p><strong>Orders Found:</strong> <?php echo count($orders); ?> orders</p>
        <p><strong>Label Size:</strong> 10cm × 13.9cm receipt format</p>
        <p><strong>Labels Per Page:</strong> 4 labels (2x2 grid)</p>
        <p><strong>Filters Applied:</strong></p>
        <ul>
            <?php if ($date): ?><li>Date: <?php echo htmlspecialchars($date); ?></li><?php endif; ?>
            <?php if ($time_from): ?><li>Time From: <?php echo htmlspecialchars($time_from); ?></li><?php endif; ?>
            <?php if ($time_to): ?><li>Time To: <?php echo htmlspecialchars($time_to); ?></li><?php endif; ?>
            <?php if ($status_filter !== 'all'): ?><li>Status: <?php echo htmlspecialchars($status_filter); ?></li><?php endif; ?>
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
                $barcode_data = str_pad($order_id, 10, '0', STR_PAD_LEFT);
                $barcode_url = getBarcodeUrl($barcode_data);
                $qr_url = getQRCodeUrl("Order: " . $order_id . " | Tracking: " . ($order['tracking_number'] ?? 'N/A'));
                $tracking_number = !empty($order['tracking_number']) ? $order['tracking_number'] : '-';
                
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
                                    <div style="font-weight: bold; margin-bottom: 2mm;">Order ID: <?php echo str_pad($order_id, 5, '0', STR_PAD_LEFT); ?></div>
                                    <div class="barcode-section">
                                        <img src="<?php echo $barcode_url; ?>" alt="Order Barcode" class="barcode-image" onerror="this.style.display='none'">
                                        <div class="barcode-text"><?php echo $barcode_data; ?></div>
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
                                    <strong>Tracking:</strong> <?php echo htmlspecialchars($tracking_number); ?><br>
                                    <strong>Date:</strong> <?php echo !empty($order['issue_date']) ? date('Y-m-d', strtotime($order['issue_date'])) : date('Y-m-d'); ?>
                                </td>
                            </tr>

                            <!-- Products Header -->
                            <tr>
                                <td class="product-header" colspan="2">Product Name</td>
                                <td class="product-header">Qty</td>
                            </tr>

                            <!-- Products List (limit to prevent overflow) -->
                            <?php 
                            $display_items = !empty($order_items) ? array_slice($order_items, 0, 3) : [];
                            $remaining_items = count($order_items) - count($display_items);
                            ?>
                            
                            <?php if (!empty($display_items)): ?>
                                <?php foreach ($display_items as $item): ?>
                                    <tr>
                                        <td colspan="2" style="padding: 1mm; font-size: 10px;">
                                            <?php echo htmlspecialchars(substr($item['product_name'], 0, 30)) . (strlen($item['product_name']) > 30 ? '...' : ''); ?>
                                        </td>
                                        <td style="text-align: center; padding: 1mm; font-size: 10px;">
                                            <?php echo intval($item['total_quantity']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php if ($remaining_items > 0): ?>
                                    <tr>
                                        <td colspan="2" style="padding: 1mm; font-size: 10px; font-style: italic;">
                                            +<?php echo $remaining_items; ?> more item(s)
                                        </td>
                                        <td style="text-align: center; padding: 1mm; font-size: 10px;">-</td>
                                    </tr>
                                <?php endif; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2" style="padding: 1mm; font-size: 10px;">No items found</td>
                                    <td style="text-align: center; padding: 1mm; font-size: 10px;">0</td>
                                </tr>
                            <?php endif; ?>

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
                                    <strong>Address:</strong> <?php echo htmlspecialchars(substr($order['display_address'], 0, 40)) . (strlen($order['display_address']) > 40 ? '...' : ''); ?>
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
        console.log('Bulk print loaded: <?php echo count($orders); ?> orders');
    </script>
</body>
</html>

<?php
$conn->close();
?>