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
$show_payment_details = isset($_GET['show_payment']) && $_GET['show_payment'] === 'true';

// Query specifically for leads orders - updated to match leads_list.php structure
$order_query = "SELECT i.*, i.pay_status AS order_pay_status, c.name as customer_name, 
                CONCAT_WS(', ', c.address_line1, c.address_line2) as customer_address, 
                c.email as customer_email, c.phone as customer_phone,
                p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
                r.name as paid_by_name, u.name as user_name,
                i.delivery_fee, u2.name as creator_name
                FROM order_header i 
                LEFT JOIN customers c ON i.customer_id = c.customer_id
                LEFT JOIN payments p ON i.order_id = p.order_id
                LEFT JOIN roles r ON p.pay_by = r.id
                LEFT JOIN users u ON i.user_id = u.id
                LEFT JOIN users u2 ON i.created_by = u2.id
                WHERE i.order_id = ? AND i.interface = 'leads'
                AND i.status IN ('done', 'pending', 'cancel', 'dispatch', 'no_answer')";

$stmt = $conn->prepare($order_query);

// Add error checking for prepare statement
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Leads order not found or access denied");
}

$order = $result->fetch_assoc();

// Get currency from order
$currency = isset($order['currency']) ? strtolower($order['currency']) : 'lkr';
$currencySymbol = ($currency == 'usd') ? '$' : 'Rs.';

// Ensure delivery fee is properly set
$delivery_fee = isset($order['delivery_fee']) && !is_null($order['delivery_fee']) ? floatval($order['delivery_fee']) : 0.00;

// Get order items with product information
$itemSql = "SELECT ii.*, ii.pay_status, p.name as product_name,
            p.description as product_description,
            (ii.total_amount + ii.discount) as original_price, 
            ii.total_amount as item_price,
            COALESCE(ii.discount, 0) as item_discount
            FROM order_items ii
            JOIN products p ON ii.product_id = p.id
            WHERE ii.order_id = ?";

$stmt = $conn->prepare($itemSql);

// Add error checking for prepare statement
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $order_id);
$stmt->execute();
$itemsResult = $stmt->get_result();
$items = [];

while ($item = $itemsResult->fetch_assoc()) {
    $items[] = $item;
}

// Determine overall order payment status
if (isset($order['order_pay_status']) && !empty($order['order_pay_status'])) {
    $orderPayStatus = strtolower($order['order_pay_status']);
} else {
    $allItemsPaid = true;
    $anyItemPaid = false;

    foreach ($items as $item) {
        if (strtolower($item['pay_status']) == 'paid') {
            $anyItemPaid = true;
        } else {
            $allItemsPaid = false;
        }
    }

    if ($allItemsPaid && count($items) > 0) {
        $orderPayStatus = 'paid';
    } elseif ($anyItemPaid) {
        $orderPayStatus = 'partial';
    } else {
        $orderPayStatus = 'unpaid';
    }
}

// Company information
$company = [
    'name' => 'FE IT Solutions pvt (Ltd)',
    'address' => 'No: 04, Wijayamangalarama Road, Kohuwala',
    'email' => 'info@feitsolutions.com',
    'phone' => '011-2824524'
];

// Function to get the color for payment status
function getPaymentStatusColor($status)
{
    $status = strtolower($status ?? 'unpaid');

    switch ($status) {
        case 'paid':
            return "color: #28a745;"; // Green for paid
        case 'partial':
            return "color: #fd7e14;"; // Orange for partial payment
        case 'unpaid':
        default:
            return "color: #dc3545;"; // Red for unpaid
    }
}

// Function to get badge class for payment status
function getPaymentStatusBadge($status)
{
    $status = strtolower($status ?? 'unpaid');

    switch ($status) {
        case 'paid':
            return "bg-success"; // Green for paid
        case 'partial':
            return "bg-warning"; // Orange for partial payment
        case 'unpaid':
        default:
            return "bg-danger"; // Red for unpaid
    }
}

// Set autoPrint for normal view
$autoPrint = !$show_payment_details;

// Calculate total item-level discounts
$total_item_discounts = 0;
foreach ($items as $item) {
    $total_item_discounts += floatval($item['item_discount']);
}

// Calculate subtotal before discounts (using original prices)
$subtotal_before_discounts = 0;
foreach ($items as $item) {
    $subtotal_before_discounts += floatval($item['original_price']);
}

// Check if there are any discounts at all (order level or item level)
$has_any_discount = $total_item_discounts > 0 || floatval($order['discount']) > 0;

// Count how many columns we need to display in the table (removed product code column)
$column_count = $has_any_discount ? 5 : 4;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Leads Order #<?php echo $order_id; ?></title>
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <style>
        .leads-badge {
            background-color: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .product-code {
            font-weight: bold;
            color: #007bff;
        }
        
        .creator-info {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .creator-info strong {
            color: #495057;
        }
        
        .modal-specific {
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .order-container {
            max-width: 100%;
            margin: 0;
            padding: 20px;
        }
        
        .product-name {
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="order-container modal-specific">
        <div class="order-header">
            <div class="company-logo">
                <img src="../assets/images/logo-white.svg" alt="Company Logo">
            </div>
            <div class="order-info">
                <div class="order-title">
                    LEADS ORDER : # <?php echo $order_id; ?>
                    <span class="leads-badge">LEADS</span>
                </div>
                <div class="order-date">Date Issued: <?php echo date('Y-m-d', strtotime($order['issue_date'])); ?></div>
                <div>Due Date: <?php echo date('Y-m-d', strtotime($order['due_date'])); ?></div>
                <div>Created Time: <?php echo date('H:i:s', strtotime($order['created_at'])); ?></div>
                <div>Status: <span class="status-badge status-<?php echo strtolower($order['status']); ?>"><?php echo ucfirst($order['status']); ?></span></div>
                <div class="pay-status">
                    Pay Status:
                    <span class="payment-badge <?php echo getPaymentStatusBadge($orderPayStatus); ?>">
                        <?php echo ucfirst($orderPayStatus); ?>
                    </span>
                </div>
                <?php if (!empty($order['creator_name'])): ?>
                    <div class="creator-info">
                        <strong>Created By:</strong> <?php echo htmlspecialchars($order['creator_name']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="billing-details">
            <div class="billing-block">
                <div class="billing-title">Billing From :</div>
                <div class="billing-info">
                    <div><?php echo htmlspecialchars($company['name']); ?></div>
                    <div>No: 04</div>
                    <div>Wijayamangalarama Road, Kohuwala</div>
                    <div><?php echo htmlspecialchars($company['email']); ?></div>
                    <div><?php echo htmlspecialchars($company['phone']); ?></div>
                </div>
            </div>
            <div class="billing-block">
                <div class="billing-title">Billing To :</div>
                <div class="billing-info">
                    <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong>
                    <?php if (!empty($order['customer_id'])): ?>
                        <span style="color: #666; font-size: 0.9em;">(ID: <?php echo htmlspecialchars($order['customer_id']); ?>)</span>
                    <?php endif; ?>
                    <br>
                    <?php if (!empty($order['customer_address'])): ?>
                        <?php echo nl2br(htmlspecialchars($order['customer_address'])); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_email'])): ?>
                        Email: <?php echo htmlspecialchars($order['customer_email']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_phone'])): ?>
                        Phone: <?php echo htmlspecialchars($order['customer_phone']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <table class="product-table">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="<?php echo $has_any_discount ? '35%' : '40%'; ?>">PRODUCT</th>
                    <th width="<?php echo $has_any_discount ? '35%' : '40%'; ?>">DESCRIPTION</th>
                    <?php if ($has_any_discount): ?>
                        <th width="10%" style="text-align: right;">DISCOUNT</th>
                    <?php endif; ?>
                    <th width="15%" style="text-align: right;">PRICE</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1;
                if (count($items) > 0):
                    foreach ($items as $item):
                        $original_price = $item['original_price'] ?? 0;
                        $item_price = $item['item_price'] ?? 0;
                        $item_discount = $item['item_discount'] ?? 0;
                ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td class="product-name"><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['product_description']); ?></td>
                            <?php if ($has_any_discount): ?>
                                <td style="text-align: right;">
                                    <?php echo $currencySymbol . ' ' . number_format($item_discount, 2); ?>
                                </td>
                            <?php endif; ?>
                            <td style="text-align: right;">
                                <?php 
                                // Show original price with discount info if applicable
                                if ($item_discount > 0) {
                                    echo $currencySymbol . ' ' . number_format($original_price, 2);
                                    echo '<br><span class="item-discount">(After discount: ' . $currencySymbol . ' ' . number_format($item_price, 2) . ')</span>';
                                } else {
                                    echo $currencySymbol . ' ' . number_format($item_price, 2);
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr>
                        <td colspan="<?php echo $column_count; ?>" style="text-align: center;">No items found for this leads order</td>
                    </tr>
                <?php endif; ?>

                <tr class="total-row">
                    <td colspan="<?php echo $column_count - 1; ?>" style="text-align: right; border-right: none;">Sub Total :</td>
                    <td class="total-value">
                        <?php echo $currencySymbol . ' ' . number_format($subtotal_before_discounts, 2); ?>
                    </td>
                </tr>

                <?php if ($has_any_discount): ?>
                    <tr class="total-row">
                        <td colspan="<?php echo $column_count - 1; ?>" style="text-align: right; border-right: none;">Item Discounts :</td>
                        <td class="total-value">
                            <?php echo $currencySymbol . ' ' . number_format($total_item_discounts, 2); ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php if ($delivery_fee > 0): ?>
                    <tr class="total-row delivery-fee-row">
                        <td colspan="<?php echo $column_count - 1; ?>" style="text-align: right; border-right: none;">Delivery Fee :</td>
                        <td class="total-value">
                            <?php echo $currencySymbol . ' ' . number_format($delivery_fee, 2); ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <tr class="total-row">
                    <td colspan="<?php echo $column_count - 1; ?>" style="text-align: right; border-right: none;">Total :</td>
                    <td class="total-value">
                        <?php 
                        // Calculate final total ensuring delivery fee is included
                        $final_total = $subtotal_before_discounts - $total_item_discounts + $delivery_fee;
                        echo $currencySymbol . ' ' . number_format($final_total, 2); 
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="notes">
            <div class="note-title">Note:</div>
            <p><?php echo !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : 'This is a leads order. Please process according to leads workflow procedures.'; ?>
            </p>
        </div>

        <?php if ($orderPayStatus == 'paid' || $orderPayStatus == 'partial'): ?>
            <div class="payment-info">
                <div class="payment-details">
                    <h3>Payment Information</h3>
                    <div>Payment Method: <?php echo htmlspecialchars($order['payment_method'] ?? 'N/A'); ?></div>
                    <div>Amount Paid:
                        <?php echo $currencySymbol . ' ' . number_format((float) ($order['amount_paid'] ?? 0), 2); ?>
                    </div>
                    <div>Payment Date:
                        <?php echo ($order['payment_date']) ? date('d/m/Y', strtotime($order['payment_date'])) : 'N/A'; ?>
                    </div>
                    <div>Processed By: <?php echo htmlspecialchars($order['paid_by_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="signature">
                    <div class="signature-line">
                        Authorized Signature
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="payment-info">
                <div class="payment-methods">
                    <h5>Payment Methods</h5>
                    <p>
                        Account Name: F E IT SOLUTIONS PVT (LTD)<br>
                        Account Number: 100810008655<br>
                        Account Type: LKR Current Account<br>
                        Bank Name: Nations Trust Bank PLC
                    </p>
                </div>
                <div class="signature">
                    <div class="signature-line">
                        Authorized Signature
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
<?php
$conn->close();
?>