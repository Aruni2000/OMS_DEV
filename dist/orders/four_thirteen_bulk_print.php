<?php
/**
 * Four Thirteen Bulk Print (Simple Labels)
 * Prints 8 simple labels per A4 page (2 columns × 4 rows layout) - LANDSCAPE
 * Each label: 3.75" × 2" (95mm × 51mm)
 * Each label contains: From, To, Products, Barcode, Total Amount, Order ID, and Order Date
 * UPDATED: Barcode now displays tracking number instead of order ID
 * Based on filters from label print page
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
 */
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$time_from = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$time_to = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';

// NEW: Tracking filter parameters
$tracking_filter = isset($_GET['tracking_filter']) ? trim($_GET['tracking_filter']) : 'all';
$tracking_number = isset($_GET['tracking_number']) ? trim($_GET['tracking_number']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * BUILD QUERY TO FETCH ORDERS
 */
$sql = "SELECT o.order_id, o.customer_id, o.full_name, o.mobile, o.address_line1, o.address_line2,
               o.status, o.updated_at, o.interface, o.tracking_number, o.total_amount, o.currency,
               o.delivery_fee, o.discount, o.issue_date,
               c.name as customer_name, c.phone as customer_phone, 
               c.email as customer_email, c.city_id,
               cr.courier_name as delivery_service,
               
               -- City information from city_table
               ct.city_name,
               
               -- Display name with proper fallback
               COALESCE(NULLIF(o.full_name, ''), c.name, 'Unknown Customer') as display_name,
               
               -- Display mobile with proper fallback
               COALESCE(NULLIF(o.mobile, ''), c.phone, 'No phone') as display_mobile,
               
               -- ADDED: Customer address lines separately for better control
               c.address_line1 as customer_address_line1,
               c.address_line2 as customer_address_line2,
               
               -- Customer address with city (for fallback)
               CONCAT_WS(', ', 
                   NULLIF(c.address_line1, ''), 
                   NULLIF(c.address_line2, ''), 
                   ct.city_name
               ) as customer_address
               
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

// Fetch orders
$orders = [];
while ($order = $result->fetch_assoc()) {
    $orders[] = $order;
}

// NEW: Function to get products for an order
function getOrderProducts($conn, $order_id) {
    $sql = "SELECT oi.product_id, oi.quantity, p.name as product_name, p.id as product_id
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    return $products;
}

// Company information (From address)
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

// NEW: Function to check if tracking number exists
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
    <title>Simple Bulk Print Labels (<?php echo count($orders); ?> orders) - A4 Landscape 8 Labels</title>
    
 
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }

        /* Print Instructions (hidden when printing) */
        .print-instructions {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }

        .print-button {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 16px;
            margin: 5px;
            border-radius: 4px;
            cursor: pointer;
        }

        .print-button:hover {
            background: #0056b3;
        }

        /* Main container for labels */
        .labels-container {
            width: 297mm; /* A4 landscape width */
            margin: 0 auto;
        }

        /* Page wrapper - A4 landscape for 8 labels (2 columns × 4 rows) */
        .page-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr; /* 2 columns */
            grid-template-rows: repeat(4, 1fr); /* 4 rows */
            gap: 5mm;
            width: 297mm; /* A4 landscape width */
            height: 210mm; /* A4 landscape height */
            padding: 10mm;
        }

        /* Individual label styling - 3.75" × 2" (95mm × 51mm) */
        .simple-label {
            border: 2px dashed #333;
            padding: 3mm;
            display: flex;
            flex-direction: row; /* Horizontal layout */
            justify-content: space-between;
            align-items: stretch;
            width: 135mm; /* Adjusted for 2 columns with gap */
            height: 45mm; /* Adjusted for 4 rows with gap */
            background: white;
            position: relative;
        }

        /* Left section - From and To info */
        .left-section {
            display: flex;
            flex-direction: column;
            flex: 1;
            margin-right: 5mm;
            justify-content: space-between;
        }

        /* From section */
        .from-section {
            border-bottom: 1px solid #ccc;
            padding-bottom: 1mm;
            margin-bottom: 2mm;
        }

        .from-label {
            font-weight: bold;
            font-size: 9px;
            margin-bottom: 1mm;
            color: #333;
        }

        .from-details {
            font-size: 8px;
            line-height: 1.2;
        }

        /* To section */
        .to-section {
            flex-grow: 1;
        }

        .to-label {
            font-weight: bold;
            font-size: 9px;
            margin-bottom: 1mm;
            color: #333;
        }

        .to-details {
            font-size: 8px;
            line-height: 1.2;
        }

        /* NEW: Products section styling */
        .products-section {
            margin-top: 1mm;
            border-top: 1px dotted #ccc;
            padding-top: 1mm;
        }

        .products-label {
            font-weight: bold;
            font-size: 7px;
            color: #666;
            margin-bottom: 0.5mm;
        }

        .product-item {
            font-size: 7px;
            color: #333;
            line-height: 1.1;
        }

        /* Right section - Order info, Barcode and Total */
        .right-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            width: 40mm;
            text-align: center;
            border-left: 1px solid #ccc;
            padding-left: 3mm;
        }

        /* NEW: Order info section at the top right */
        .order-info-section {
            text-align: center;
            width: 100%;
            border-bottom: 1px solid #eee;
            padding-bottom: 2mm;
            margin-bottom: 2mm;
        }

        .order-id {
            font-weight: bold;
            font-size: 8px;
            color: #000;
            margin-bottom: 0.5mm;
        }

        .order-date {
            font-size: 7px;
            color: #666;
        }

        .barcode-section {
            text-align: center;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .barcode-image {
            height: 12mm; /* Slightly reduced to accommodate order info */
            max-width: 35mm;
            object-fit: contain;
        }

        .barcode-text {
            font-size: 7px;
            margin-top: 1mm;
            font-weight: bold;
        }

        .total-section {
            text-align: center;
            margin-top: 2mm;
        }

        .total-label {
            font-size: 7px;
            color: #666;
        }

        .total-amount {
            font-weight: bold;
            font-size: 10px;
            margin-top: 0.5mm;
            color: #000;
        }

        /* Page break */
        .page-break {
            page-break-before: always;
        }

        /* Print styles */
        @media print {
            .print-instructions {
                display: none !important;
            }
            
            body {
                margin: 0;
                padding: 0;
            }
            
            .labels-container {
                margin: 0;
            }
            
            .page-wrapper {
                margin: 0;
                padding: 10mm;
                width: 297mm !important; /* A4 landscape width */
                height: 210mm !important; /* A4 landscape height */
            }
            
            /* Ensure exact label dimensions when printing */
            .simple-label {
                width: 135mm !important;
                height: 45mm !important;
            }

            /* Set landscape orientation */
            @page {
                size: A4 landscape;
                margin: 0;
            }
        }

        .no-orders {
            text-align: center;
            padding: 50px;
            color: #666;
        }
    </style>


</head>
<body>
    <!-- Print Instructions (hidden when printing) -->
    <div class="print-instructions">
        <h3>Simple Bulk Print Instructions - A4 Landscape 8 Labels</h3>
        <p><strong>Orders Found:</strong> <?php echo count($orders); ?> orders</p>
        <p><strong>Labels Per Page:</strong> 8 labels (2 columns × 4 rows)</p>
        <p><strong>Format:</strong> From, To, Products, Order ID, Order Date, Tracking Barcode, Total Amount</p>
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
            $labels_per_page = 8; // 8 labels per A4 landscape page (2×4 grid)
            $total_orders = count($orders);
            $current_page_labels = 0;
            ?>
            
            <?php foreach ($orders as $index => $order): ?>
                <?php
                // Start new page wrapper every 8 labels
                if ($current_page_labels == 0): ?>
                    <div class="page-wrapper">
                <?php endif; ?>
                
                <?php
                // Prepare order data 
                $order_id = $order['order_id'];
                $currency_symbol = getCurrencySymbol($order['currency'] ?? 'lkr');
                
                // UPDATED: Use tracking number for barcode instead of order ID
                $tracking_number_val = !empty($order['tracking_number']) ? trim($order['tracking_number']) : '';
                $has_tracking = hasTracking($tracking_number_val);
                
                // Barcode data and URLs - only if tracking exists
                if ($has_tracking) {
                    $barcode_data = $tracking_number_val;
                    $barcode_url = getBarcodeUrl($barcode_data);
                } else {
                    // Fallback to order ID if no tracking
                    $barcode_data = str_pad($order_id, 10, '0', STR_PAD_LEFT);
                    $barcode_url = getBarcodeUrl($barcode_data);
                }
                
                // Total amount
                $total_amount = floatval($order['total_amount']);
                
                // Format order date
                $order_date = '';
                if (!empty($order['issue_date'])) {
                    $order_date = date('d/m/Y', strtotime($order['issue_date']));
                } elseif (!empty($order['updated_at'])) {
                    $order_date = date('d/m/Y', strtotime($order['updated_at']));
                } else {
                    $order_date = date('d/m/Y');
                }

                // Get products for this order
                $products = getOrderProducts($conn, $order_id);
                ?>
                
                <div class="simple-label">
                    <!-- Left Section: From and To -->
                    <div class="left-section">
                        <!-- From Section -->
                        <div class="from-section">
                            <div class="from-label">From:</div>
                            <div class="from-details">
                                <strong><?php echo htmlspecialchars($company['name']); ?></strong><br>
                                <?php echo htmlspecialchars($company['address']); ?><br>
                                <?php echo htmlspecialchars($company['phone']); ?>
                            </div>
                        </div>

                        <!-- To Section -->
                        <div class="to-section">
                            <div class="to-label">To:</div>
                            <div class="to-details">
                                <strong><?php echo htmlspecialchars(substr($order['display_name'], 0, 25)); ?></strong><br>
                                <?php echo htmlspecialchars($order['display_mobile']); ?><br>
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
                                
                                echo htmlspecialchars(substr($address_only, 0, 40)) . (strlen($address_only) > 40 ? '...' : '');
                                ?><br>
                                <?php echo !empty($order['city_name']) ? htmlspecialchars($order['city_name']) : 'City not specified'; ?>
                            </div>

                            <!-- NEW: Products Section -->
                            <?php if (!empty($products)): ?>
                            <div class="products-section">
                                <?php 
                                // Group products by ID and sum quantities
                                $grouped_products = [];
                                foreach ($products as $product) {
                                    $product_id = $product['product_id'];
                                    if (isset($grouped_products[$product_id])) {
                                        $grouped_products[$product_id]['quantity'] += $product['quantity'];
                                    } else {
                                        $grouped_products[$product_id] = [
                                            'product_id' => $product_id,
                                            'product_name' => $product['product_name'],
                                            'quantity' => $product['quantity']
                                        ];
                                    }
                                }
                                
                                $total_unique_products = count($grouped_products);
                                ?>
                                <div class="products-label">Products (<?php echo $total_unique_products; ?>):</div>
                                <div class="product-item">
                                    <?php 
                                    $product_list = [];
                                    foreach ($grouped_products as $product) {
                                        $product_name = htmlspecialchars(substr($product['product_name'], 0, 15));
                                        if (strlen($product['product_name']) > 15) $product_name .= '...';
                                        $product_list[] = $product['product_id'] . " - " . $product_name . " (" . $product['quantity'] . ")";
                                    }
                                    echo implode(', ', $product_list);
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Section: Order Info, Barcode and Total -->
                    <div class="right-section">
                        <!-- Order Info Section -->
                        <div class="order-info-section">
                            <div class="order-id">Order #<?php echo $order_id; ?></div>
                            <div class="order-date"><?php echo $order_date; ?></div>
                            
                            <!-- NEW: Tracking status indicator -->
                            <div class="tracking-status <?php echo $has_tracking ? 'has-tracking' : 'no-tracking'; ?>">
                                <?php if ($has_tracking): ?>
                                  
                                <?php else: ?>
                                    ⚠ No Track
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- UPDATED: Barcode Section - Show tracking number or fallback to order ID -->
                        <div class="barcode-section">
                            <?php if ($has_tracking): ?>
                                <img src="<?php echo $barcode_url; ?>" alt="Tracking Barcode" class="barcode-image" onerror="this.style.display='none'">
                                <div class="barcode-text"><?php echo htmlspecialchars($tracking_number_val); ?></div>
                            <?php else: ?>
                                <div class="no-tracking-barcode">
                                    NO TRACKING<br>
                                    Order: <?php echo $order_id; ?>
                                </div>
                                <img src="<?php echo $barcode_url; ?>" alt="Order Barcode" class="barcode-image" onerror="this.style.display='none'">
                                <div class="barcode-text"><?php echo $barcode_data; ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="total-section">
                            <div class="total-label">Total:</div>
                            <div class="total-amount"><?php echo $currency_symbol . ' ' . number_format($total_amount, 2); ?></div>
                        </div>
                    </div>
                </div>

                <?php 
                $current_page_labels++;
                
                // Close page wrapper and reset counter every 8 labels
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
        console.log('Simple bulk print loaded: <?php echo count($orders); ?> orders');
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