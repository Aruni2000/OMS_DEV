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

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if order ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Order ID is required");
}

$order_id = $_GET['id'];

// Query to get order information with courier details
$order_query = "SELECT o.*, c.name as customer_name, c.phone as customer_phone, 
                c.email as customer_email, c.city_id,
                CONCAT_WS(', ', c.address_line1, c.address_line2) as customer_address,
                o.delivery_fee, o.discount, o.total_amount, o.issue_date, o.tracking_number,
                cr.courier_name as delivery_service,
                ct.city_name,
                
                -- Display name with proper fallback
                COALESCE(NULLIF(o.full_name, ''), c.name, 'Unknown Customer') as display_name,
                
                -- Display mobile with proper fallback
                COALESCE(NULLIF(o.mobile, ''), c.phone, 'No phone') as display_mobile,
                
                -- Display address with proper priority and fallback + city
                COALESCE(
                    NULLIF(CONCAT_WS(', ', NULLIF(o.address_line1, ''), NULLIF(o.address_line2, '')), ''),
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
                WHERE o.order_id = ?";

$stmt = $conn->prepare($order_query);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Order not found");
}

$order = $result->fetch_assoc();

// Get order items with proper quantity grouping for same products
$items_query = "SELECT oi.product_id, p.name as product_name, 
                SUM(oi.quantity) as total_quantity
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
                GROUP BY oi.product_id, p.name
                ORDER BY p.name";

$stmt = $conn->prepare($items_query);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $order_id);
$stmt->execute();
$items_result = $stmt->get_result();

$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Get currency
$currency = isset($order['currency']) ? strtolower($order['currency']) : 'lkr';
$currencySymbol = ($currency == 'usd') ? '$' : 'Rs.';

// Company information
$company = [
    'name' => 'FE IT Solutions pvt (Ltd)',
    'address' => 'No: 04, Wijayamangalarama Road, Kohuwala',
    'email' => 'info@feitsolutions.com',
    'phone' => '011-2824524'
];

// Calculate totals
$subtotal = floatval($order['total_amount']) - floatval($order['delivery_fee']) + floatval($order['discount']);
$delivery_fee = floatval($order['delivery_fee']);
$discount = floatval($order['discount']);
$total_payable = floatval($order['total_amount']);

// Handle tracking number - only use if exists, don't generate
$tracking_number = !empty($order['tracking_number']) ? $order['tracking_number'] : '';
$has_tracking = !empty($tracking_number);

// Generate barcode data only if tracking number exists
$barcode_data = $has_tracking ? $tracking_number : '';

function getBarcodeUrl($data) {
    // Using Code128 format which is widely supported by barcode scanners
    return "https://barcodeapi.org/api/code128/{$data}";
}

function getQRCodeUrl($data) {
    return "https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=" . urlencode($data);
}

$barcode_url = $has_tracking ? getBarcodeUrl($barcode_data) : '';
$qr_url = $has_tracking ? getQRCodeUrl("Tracking: " . $tracking_number . " | Order: " . $order_id) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Print - <?php echo $order_id; ?></title>
    <link rel="stylesheet" href="../assets/css/print.css" id="main-style-link" />
</head>
<body>
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
                    
                    <?php if ($has_tracking): ?>
                        <div style="font-weight: bold; margin-bottom: 2mm; color: #2563eb;"></div>
                        <div class="barcode-section">
                            <img src="<?php echo $barcode_url; ?>" alt="Tracking Barcode" class="barcode-image" onerror="this.style.display='none'">
                            <div style="font-size: 7px; margin-top: 1mm; color: #666;"></div>
                        </div>
                    <?php else: ?>
                        <div style="font-weight: bold; margin-bottom: 2mm; color: #dc2626;">No Tracking Assigned</div>
                        <div class="no-tracking-section">
                            <div style="border: 2px dashed #dc2626; padding: 8px; text-align: center; font-size: 10px; color: #dc2626;">
                                NO BARCODE<br>
                                <span style="font-size: 8px;">Tracking not available</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <!-- Delivery Service Row -->
            <tr>
                <td class="delivery-service-cell">
                    <strong>Delivery Service:</strong><br>
                    <?php echo !empty($order['delivery_service']) ? htmlspecialchars($order['delivery_service']) : ''; ?>
                </td>
                <td class="tracking-cell" colspan="2">
                    <strong>Tracking:</strong> 
                    <?php if ($has_tracking): ?>
                        <?php echo htmlspecialchars($tracking_number); ?>
                    <?php else: ?>
                        <span style="color: #dc2626;">No Tracking Assigned</span>
                    <?php endif; ?>
                    <br>
                    <strong>Date:</strong> <?php echo !empty($order['issue_date']) ? date('Y-m-d', strtotime($order['issue_date'])) : date('Y-m-d'); ?>
                </td>
            </tr>

            <!-- Products Section -->
            <tr>
                <td class="product-header" colspan="3">
                    <strong>Products (<?php echo count($items); ?>):</strong>
                    <div style="margin-top: 1mm; font-size: 9px; line-height: 1.2;">
                        <?php if (!empty($items)): ?>
                            <?php 
                            $product_list = [];
                            foreach ($items as $item) {
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
                    <?php echo $currencySymbol . ' ' . number_format($subtotal, 2); ?><br>
                    <?php echo $currencySymbol . ' ' . number_format($delivery_fee, 2); ?><br>
                    <?php echo $currencySymbol . ' ' . number_format($discount, 2); ?>
                </td>
            </tr>

            <!-- Total Payable -->
            <tr>
                <td class="total-payable" colspan="2">TOTAL PAYABLE</td>
                <td class="total-payable amount"><?php echo $currencySymbol . ' ' . number_format($total_payable, 2); ?></td>
            </tr>
        </table>
    </div>

    <script>
        // Auto print when page loads
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>