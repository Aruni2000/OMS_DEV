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

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details = null) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
    return $stmt->execute();
}

// Function to check courier and tracking status
function checkCourierStatus($conn) {
    $status = [
        'has_courier' => false,
        'courier_type' => null,
        'courier_name' => '',
        'has_tracking' => false,
        'tracking_count' => 0,
        'warning_message' => '',
        'error_message' => '',
        'info_message' => ''
    ];
  
    // Get default courier - Updated to include status 3 (Existing API Parcel)
    $courierSql = "SELECT courier_id, courier_name, api_key, client_id, is_default, status 
                   FROM couriers 
                   WHERE is_default IN (1, 2, 3) AND status = 'active' 
                   ORDER BY is_default ASC 
                   LIMIT 1";
    $courierResult = $conn->query($courierSql);
    
    if ($courierResult && $courierResult->num_rows > 0) {
        $courier = $courierResult->fetch_assoc();
        $status['has_courier'] = true;
        $status['courier_type'] = $courier['is_default'];
        $status['courier_name'] = $courier['courier_name'];
        
        if ($courier['is_default'] == 1) {
            // Internal tracking system - check for unused tracking numbers
            $trackingSql = "SELECT COUNT(*) as unused_count 
                           FROM tracking 
                           WHERE courier_id = ? AND status = 'unused'";
            $trackingStmt = $conn->prepare($trackingSql);
            $trackingStmt->bind_param("i", $courier['courier_id']);
            $trackingStmt->execute();
            $trackingResult = $trackingStmt->get_result();
            
            if ($trackingResult) {
                $trackingData = $trackingResult->fetch_assoc();
                $status['tracking_count'] = $trackingData['unused_count'];
                
                if ($status['tracking_count'] > 0) {
                    $status['has_tracking'] = true;
                } else {
                    // $status['warning_message'] = "Warning: No unused tracking numbers available. Orders will be created as 'pending' status until tracking numbers are added.";
                }
            }
                    } else if ($courier['is_default'] == 2) {
                    // FDE API system
                    $status['has_tracking'] = true; // API generates tracking numbers
                    if (empty($courier['api_key'])) {
                        $status['warning_message'] = "Warning: {$courier['courier_name']} API key is missing. Orders may not get tracking numbers automatically.";
                    }
                } else if ($courier['is_default'] == 3) {
                    // Existing API Parcel system
                    $status['has_tracking'] = true; // API integration available
                    if (empty($courier['api_key'])) {
                        $status['warning_message'] = "Warning: {$courier['courier_name']} API key is missing. Existing API integration may not function properly.";
                    } else {
                        // $status['info_message'] = "Using existing API parcel integration for {$courier['courier_name']}.";
                    }
                }
    } else {
        $status['info_message'] = "No default courier selected. ";
    }
    
    return $status;
}

// Check courier status
$courierStatus = checkCourierStatus($conn);

// Fetch necessary data for the form
$sql = "SELECT id, name, description, lkr_price FROM products WHERE status = 'active' ORDER BY name ASC";
$result = $conn->query($sql);

// Updated customer query with proper JOIN to get city_name
$customerSql = "SELECT c.*, ct.city_name 
                FROM customers c 
                LEFT JOIN city_table ct ON c.city_id = ct.city_id 
                WHERE c.status = 'Active' 
                ORDER BY c.name ASC";
$customerResult = $conn->query($customerSql);

// Fetch cities for dropdown
$citySql = "SELECT city_id, city_name FROM city_table WHERE is_active = 1 ORDER BY city_name ASC";
$cityResult = $conn->query($citySql);

// Fetch delivery fee from branding table
$deliveryFeeSql = "SELECT delivery_fee FROM branding LIMIT 1";
$deliveryFeeResult = $conn->query($deliveryFeeSql);
$deliveryFee = 0.00;
if ($deliveryFeeResult && $deliveryFeeResult->num_rows > 0) {
    $row = $deliveryFeeResult->fetch_assoc();
    $deliveryFee = floatval($row['delivery_fee']);
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - Create Order</title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/styles.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/alert.css" id="main-style-link" />

    <!-- Custom CSS for city dropdown -->
    <style>
        .city-suggestions-container {
            position: relative;
        }

        .city-suggestions-dropdown {
            position: absolute;
            z-index: 1050; /* Ensure it's above other elements */
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: .25rem;
            max-height: 200px;
            overflow-y: auto;
            width: 100%;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            display: none; /* Hidden by default */
        }

        .city-suggestion-item {
            padding: 0.5rem 1rem;
            cursor: pointer;
            color: #212529;
        }

        .city-suggestion-item:hover,
        .city-suggestion-item.active {
            background-color: #e9ecef;
        }
    </style>

</head>

<body>
    <!-- LOADER -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>
    <!-- END LOADER -->

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
        <!-- [ breadcrumb ] start -->
<div class="page-header">
    <div class="page-block">
        <div class="page-header-title" style="display: flex; justify-content: space-between; align-items: flex-start;">
            <!-- Left side: Create Order title -->
            <h5 class="mb-0 font-medium">Create Order</h5>
            
            <!-- Right side: Alert messages and courier status -->
            <div style="max-width: 400px;">
                <div class="alert-container">
                    <?php
                    // Display success messages
                    if (isset($_SESSION['order_success'])) {
                        echo '<div class="alert alert-success" id="success-alert">
                                <div>
                                    <span class="alert-icon">✅</span>
                                    <span>' . htmlspecialchars($_SESSION['order_success']) . '</span>
                                </div>
                                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                              </div>';
                        unset($_SESSION['order_success']);
                    }

                    // Display error messages
                    if (isset($_SESSION['order_error'])) {
                        echo '<div class="alert alert-error" id="error-alert">
                                <div>
                                    <span class="alert-icon">❌</span>
                                    <span>' . htmlspecialchars($_SESSION['order_error']) . '</span>
                                </div>
                                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                              </div>';
                        unset($_SESSION['order_error']);
                    }

                    // Display warning messages
                    if (isset($_SESSION['order_warning'])) {
                        echo '<div class="alert alert-warning" id="warning-alert">
                                <div>
                                    <span class="alert-icon">⚠️</span>
                                    <span>' . htmlspecialchars($_SESSION['order_warning']) . '</span>
                                </div>
                                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                              </div>';
                        unset($_SESSION['order_warning']);
                    }

                    // Display info messages
                    if (isset($_SESSION['order_info'])) {
                        echo '<div class="alert alert-info" id="info-alert">
                                <div>
                                    <span class="alert-icon">ℹ️</span>
                                    <span>' . htmlspecialchars($_SESSION['order_info']) . '</span>
                                </div>
                                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                              </div>';
                        unset($_SESSION['order_info']);
                    }

                    // Display courier status messages
                    if (!empty($courierStatus['error_message'])) {
                        echo '<div class="alert alert-error">
                                <div>
                                    <span class="alert-icon">❌</span>
                                    <span>' . htmlspecialchars($courierStatus['error_message']) . '</span>
                                </div>
                                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                              </div>';
                    }

                    if (!empty($courierStatus['warning_message'])) {
                        echo '<div class="alert alert-warning">
                                <div>
                                    <span class="alert-icon">⚠️</span>
                                    <span>' . htmlspecialchars($courierStatus['warning_message']) . '</span>
                                </div>
                                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                              </div>';
                    }

                    // Display info messages for courier status
                    if (!empty($courierStatus['info_message'])) {
                        echo '<div class="alert alert-info">
                                <div>
                                    <span class="alert-icon">ℹ️</span>
                                    <span>' . htmlspecialchars($courierStatus['info_message']) . '</span>
                                </div>
                                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                              </div>';
                    }
                    ?>

                    <!-- Courier Status Information Card -->
                    <?php if ($courierStatus['has_courier']): ?>
                    <div class="courier-status-card">
                        <h6 style="margin-bottom: 10px; color: #495057;">
                            <!-- <i class="feather icon-truck"></i> Delivery Status -->
                        </h6>
                        <div style="font-size: 11px;">
                            <?php if ($courierStatus['courier_type'] == 1): ?>
                                <div>
                                    <span class="status-indicator <?php echo $courierStatus['has_tracking'] ? 'status-active' : 'status-warning'; ?>"></span>
                                    <strong>Courier:</strong> <?php echo htmlspecialchars($courierStatus['courier_name']); ?> (Internal Tracking)
                                </div>
                                <div style="margin-top: 5px;">
                                    <strong>Available Tracking Numbers:</strong> 
                                    <?php if ($courierStatus['has_tracking']): ?>
                                        <span style="color: #28a745;"><?php echo $courierStatus['tracking_count']; ?> unused numbers</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">0 unused numbers - Orders will be pending</span>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($courierStatus['courier_type'] == 2): ?>
                                <div>
                                    <span class="status-indicator status-active"></span>
                                    <strong>Courier:</strong> <?php echo htmlspecialchars($courierStatus['courier_name']); ?> (API Parcel Courier)
                                </div>
                                <div style="margin-top: 5px;">
                                    <strong>Info:</strong> <span style="color: #28a745;">Automatic tracking number generation</span>
                                </div>
                            <?php elseif ($courierStatus['courier_type'] == 3): ?>
                                <div>
                                    <span class="status-indicator status-api"></span>
                                    <strong>Courier:</strong> <?php echo htmlspecialchars($courierStatus['courier_name']); ?> (Existing API Parcel)
                                </div>
                                <div style="margin-top: 5px;">
                                    <strong>Info:</strong> <span style="color: #17a2b8;">Integrated with existing API parcel system</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="order-container">
                <form method="post" action="process_order.php" id="orderForm" target="_blank">
                    <!-- Order Details Section -->
                    <div class="order-details-section">
                        <div class="order-details-grid">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <div class="status-radio-group">
                                    <div class="radio-option">
                                        <input type="radio" name="order_status" value="Paid" id="status_paid">
                                        <label for="status_paid">Paid</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" name="order_status" value="Unpaid" id="status_unpaid" checked>
                                        <label for="status_unpaid">Unpaid</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Order Date</label>
                                <input type="date" class="form-control" name="order_date"
                                    value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date"
                                    value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                            </div>
                        </div>
                        <!-- Hidden currency field - always set to LKR -->
                        <input type="hidden" name="order_currency" id="order_currency" value="lkr">
                    </div>

                    <!-- Customer Information Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <h5 class="section-title">Customer Information</h5>
                                <button type="button" class="btn-outline-primary" id="select_existing_customer" style="float: right; margin-left: 542px;">
                                    <i class="feather icon-users"></i> Select Customer
                                </button>
                            </div>
                        </div>
                        <div class="section-body">
                            <div class="customer-info-grid">
                                <input type="hidden" name="customer_id" id="customer_id" value="">
                                <div class="form-group">
                                    <label class="form-label">Name <span style="color: #dc3545;">*</span></label>
                                    <input type="text" class="form-control" name="customer_name" id="customer_name" required placeholder="Enter Full Name">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Email <span style="font-size: 0.8em; color: #6c757d; font-weight: normal;">(Optional)</span></label>
                                    <input type="email" class="form-control" name="customer_email" id="customer_email" placeholder="example@email.com">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Phone</label>
                                    <input type="text" class="form-control" name="customer_phone" id="customer_phone" placeholder="(07) xxxx xxxx">
                                </div>
                                <div class="form-group">
                                    <label for="city_name_input" class="form-label">City</label>
                                    <div class="city-suggestions-container">
                                        <input type="text" class="form-control" id="city_name_input" name="city_name_input"
                                            placeholder="Type to select city" autocomplete="off">
                                        <input type="hidden" id="city_id" name="city_id" value="">
                                        <div class="city-suggestions-dropdown" id="city-suggestions-dropdown"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Address Line 1</label>
                                    <input type="text" class="form-control" name="address_line1" id="address_line1">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Address Line 2</label>
                                    <input type="text" class="form-control" name="address_line2" id="address_line2">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Products Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <h5 class="section-title">Products</h5>
                        </div>
                        <div class="section-body">
                            <div style="overflow-x: auto;">
                                <table class="products-table" id="order_table">
                                    <thead>
                                        <tr>
                                            <th class="action-col">Action</th>
                                            <th class="product-col">Product</th>
                                            <th class="description-col">Description</th>
                                            <th class="price-col">Price</th>
                                            <th class="discount-col">Discount</th>
                                            <th class="subtotal-col">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="action-col">
                                                <button type="button" class="btn-remove remove_product">×</button>
                                            </td>
                                            <td class="product-col">
                                                <select name="order_product[]" class="form-select product-select">
                                                    <option value="">-- Select Product --</option>
                                                    <?php
                                                    // Reset the pointer for $result
                                                    $result->data_seek(0);
                                                    while ($row = $result->fetch_assoc()): ?>
                                                        <option value="<?= $row['id'] ?>"
                                                            data-lkr-price="<?= $row['lkr_price'] ?>"
                                                            data-description="<?= htmlspecialchars($row['description']) ?>">
                                                            <?= htmlspecialchars($row['name']) ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                            </td>
                                            <td class="description-col">
                                                <input type="text" name="order_product_description[]" class="form-control product-description">
                                            </td>
                                            <td class="price-col">
                                                <div class="input-group">
                                                    <span class="input-group-text">Rs.</span>
                                                    <input type="number" name="order_product_price[]" class="form-control price" value="0.00" step="0.01">
                                                </div>
                                            </td>
                                            <td class="discount-col">
                                                <input type="number" name="order_product_discount[]" class="form-control discount" value="0" min="0" step="1">
                                            </td>
                                            <td class="subtotal-col">
                                                <div class="input-group">
                                                    <span class="input-group-text">Rs.</span>
                                                    <input type="text" name="order_product_sub[]" class="form-control subtotal" value="0.00" readonly>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 20px;">
                                <button type="button" id="add_product" class="btn-add-product">
                                    <span>+</span> Add Product
                                </button>

                                <div class="totals-section">
                                    <div class="totals-row">
                                        <span class="totals-label">Subtotal:</span>
                                        <span class="totals-value">
                                            Rs. <span id="subtotal_display">0.00</span>
                                            <input type="hidden" id="subtotal_amount" name="subtotal" value="0.00">
                                        </span>
                                    </div>
                                    <div class="totals-row">
                                        <span class="totals-label">Discount:</span>
                                        <span class="totals-value">
                                            Rs. <span id="discount_display">0.00</span>
                                            <input type="hidden" id="discount_amount" name="discount" value="0.00">
                                        </span>
                                    </div>
                                    <div class="totals-row delivery-fee-row" id="delivery_fee_row">
                                        <span class="totals-label">Delivery Fee:</span>
                                        <span class="totals-value">
                                            Rs. <span id="delivery_fee_display"><?php echo number_format($deliveryFee, 2); ?></span>
                                            <input type="hidden" id="delivery_fee" name="delivery_fee" value="<?php echo number_format($deliveryFee, 2); ?>">
                                        </span>
                                    </div>
                                    <div class="totals-row">
                                        <span class="totals-label">Total:</span>
                                        <span class="totals-value">
                                            Rs. <span id="total_display">0.00</span>
                                            <input type="hidden" id="total_amount" name="total_amount" value="0.00">
                                            <input type="hidden" id="lkr_total_amount" name="lkr_price" value="0.00">
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes & Submit Section -->
                    <div class="section-card">
                        <div class="section-body">
                            <div class="notes-section">
                                <label class="form-label">Additional Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Enter any additional notes for this order..."></textarea>
                            </div>
                            <div class="submit-section">
                                <button type="submit" class="btn-primary" id="submit_order" 
                                    <?php echo (!$courierStatus['has_courier']) ? 'title="Warning: No courier configured"' : ''; ?>>
                                    <i class="feather icon-save"></i> Create Order
                                    <?php if (!$courierStatus['has_courier']): ?>
                                        <small style="display: block; font-size: 11px; opacity: 0.8;"></small>
                                    <?php elseif ($courierStatus['courier_type'] == 1 && !$courierStatus['has_tracking']): ?>
                                        <small style="display: block; font-size: 11px; opacity: 0.8;"></small>
                                    <?php endif; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <script>
                // Auto-hide success and info alerts after 5 seconds
                setTimeout(function() {
                    const successAlert = document.getElementById('success-alert');
                    const infoAlert = document.getElementById('info-alert');
                    
                    if (successAlert) {
                        successAlert.style.opacity = '0';
                        setTimeout(() => successAlert.remove(), 300);
                    }
                    
                    if (infoAlert) {
                        infoAlert.style.opacity = '0';
                        setTimeout(() => infoAlert.remove(), 300);
                    }
                }, 5000);

                // Keep error and warning alerts visible longer (10 seconds)
                setTimeout(function() {
                    const errorAlert = document.getElementById('error-alert');
                    const warningAlert = document.getElementById('warning-alert');
                    
                    if (errorAlert) {
                        errorAlert.style.opacity = '0';
                        setTimeout(() => errorAlert.remove(), 300);
                    }
                    
                    if (warningAlert) {
                        warningAlert.style.opacity = '0';
                        setTimeout(() => warningAlert.remove(), 300);
                    }
                }, 10000);
            </script>

            <!-- [ Main Content ] end -->
        </div>
    </div>
    <!-- [ Main Content ] end -->
     
<!-- Customer Selection Modal -->
<div id="customerModal" class="customer-modal">
    <div class="customer-modal-content">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="feather icon-users"></i>
                Select Customer
            </h5>
            <button type="button" class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="input-group" style="margin-bottom: 20px;">
                <span class="input-group-text"><i class="feather icon-search"></i></span>
                <input type="text" id="customerSearch" class="form-control" placeholder="Search : Customer id | Customer Name | Email | Phone Number |city ">
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>CUSTOMER NAME</th>
                            <th>PHONE & EMAIL</th>
                            <th>ADDRESS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Reset the pointer for $customerResult
                        $customerResult->data_seek(0);
                        while ($customer = $customerResult->fetch_assoc()): ?>
                            <tr class="customer-row" 
                                data-customer-id="<?= $customer['customer_id'] ?? '' ?>"
                                data-name="<?= htmlspecialchars($customer['name'] ?? '') ?>"
                                data-email="<?= htmlspecialchars($customer['email'] ?? '') ?>"
                                data-phone="<?= htmlspecialchars($customer['phone'] ?? '') ?>"
                                data-address-line1="<?= htmlspecialchars($customer['address_line1'] ?? '') ?>"
                                data-address-line2="<?= htmlspecialchars($customer['address_line2'] ?? '') ?>"
                                data-city-name="<?= htmlspecialchars($customer['city_name'] ?? '') ?>"
                                data-city-id="<?= $customer['city_id'] ?? '' ?>">
                                
                                <td><?= $customer['customer_id'] ?? '' ?></td>
                                
                                <td>
                                    <div class="customer-name"><?= htmlspecialchars($customer['name'] ?? '') ?></div>
                                </td>
                                
                                <td>
                                    <div class="contact-info">
                                        <div class="phone-number"><?= htmlspecialchars($customer['phone'] ?? '') ?></div>
                                        <div class="email-address"><?= htmlspecialchars($customer['email'] ?? '') ?></div>
                                    </div>
                                </td>
                                
                                <td>
                                    <div class="address-info">
                                        <div class="address-line"><?= htmlspecialchars($customer['address_line1'] ?? '') ?></div>
                                        <div class="city-name"><?= htmlspecialchars($customer['city_name'] ?? '') ?></div>
                                    </div>
                                </td>
                                
                                <td>
                                    <button type="button" class="btn btn-primary select-customer-btn">Select</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Store the delivery fee value from PHP
    let deliveryFee = <?php echo $deliveryFee; ?>;
    let hasProducts = false; // Flag to track if any products have been selected
    let isExistingCustomer = false; // Flag to track if existing customer is selected

    // Email validation function
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Phone number validation function (10 digits)
    function isValidPhoneNumber(phone) {
        const phoneRegex = /^\d{10}$/;
        return phoneRegex.test(phone);
    }

    // Function to toggle field editability based on customer type
    function toggleCustomerFields(readonly = false) {
        const fields = [
            'customer_name', 'customer_email', 'customer_phone', 
            'city_id', 'address_line1', 'address_line2'
        ];
        fields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                if (readonly) {
                    field.setAttribute('readonly', 'readonly');
                    field.style.backgroundColor = '#f8f9fa';
                    field.style.cursor = 'not-allowed';
                    if (field.tagName === 'SELECT') {
                        field.setAttribute('disabled', 'disabled');
                    }
                } else {
                    field.removeAttribute('readonly');
                    field.removeAttribute('disabled');
                    field.style.backgroundColor = '';
                    field.style.cursor = '';
                }
            }
        });
    }

    // Function to clear customer fields
    function clearCustomerFields() {
        document.getElementById('customer_id').value = '';
        document.getElementById('customer_name').value = '';
        document.getElementById('customer_email').value = '';
        document.getElementById('customer_phone').value = '';
        document.getElementById('city_id').value = '';
        document.getElementById('address_line1').value = '';
        document.getElementById('address_line2').value = '';
        
        // Clear any validation errors
        document.querySelectorAll('.validation-error').forEach(el => el.remove());
        
        // Reset flags
        isExistingCustomer = false;
        toggleCustomerFields(false);
    }

    // Enhanced customer information validation
    function validateCustomerInfo() {
        const customerName = document.getElementById('customer_name').value.trim();
        const customerEmail = document.getElementById('customer_email').value.trim();
        const customerPhone = document.getElementById('customer_phone').value.trim();
        const cityId = document.getElementById('city_id').value;
        const addressLine1 = document.getElementById('address_line1').value.trim();

        // Clear previous error messages
        document.querySelectorAll('.validation-error').forEach(el => el.remove());

        let isValid = true;

        // For new customers, all fields are required
        if (!isExistingCustomer) {
            // Name validation (required)
            if (customerName === '') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Customer name is required';
                document.getElementById('customer_name').parentNode.appendChild(errorDiv);
                isValid = false;
            }

            // Email validation (required for new customers)
            if (customerEmail !== '' && !isValidEmail(customerEmail)) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Invalid email format';
                document.getElementById('customer_email').parentNode.appendChild(errorDiv);
                isValid = false;
            }

            // Phone validation (required for new customers)
            if (customerPhone === '') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Phone number is required';
                document.getElementById('customer_phone').parentNode.appendChild(errorDiv);
                isValid = false;
            } else if (!isValidPhoneNumber(customerPhone)) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Phone number must be 10 digits';
                document.getElementById('customer_phone').parentNode.appendChild(errorDiv);
                isValid = false;
            }

            // City validation (required for new customers)
            if (cityId === '') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'City is required';
                document.getElementById('city_id').parentNode.appendChild(errorDiv);
                isValid = false;
            }

            // Address validation (required for new customers)
            if (addressLine1 === '') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Address Line 1 is required';
                document.getElementById('address_line1').parentNode.appendChild(errorDiv);
                isValid = false;
            }
        } else {
            // For existing customers, only validate name (should always be present)
            if (customerName === '') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Customer name is required';
                document.getElementById('customer_name').parentNode.appendChild(errorDiv);
                isValid = false;
            }
        }

        return isValid;
    }

    // Function to check if any product is selected and show/hide delivery fee
    function checkForProducts() {
        let productSelected = false;

        document.querySelectorAll('#order_table tbody tr').forEach(function(row) {
            const productSelect = row.querySelector('.product-select');
            if (productSelect && productSelect.value !== "") {
                productSelected = true;
            }
        });

        hasProducts = productSelected;

        // Show/hide the delivery fee row based on whether products are selected
        const deliveryFeeRow = document.getElementById('delivery_fee_row');
        if (productSelected) {
            deliveryFeeRow.style.display = 'flex';
        } else {
            deliveryFeeRow.style.display = 'none';
        }

        // Always update totals after checking for products to ensure correct calculation
        updateTotals();

        return productSelected;
    }

    // Function to update product price based on currency
    function updateProductPrice(row) {
        const productSelect = row.querySelector('.product-select');
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        
        if (productSelect.value === "") return;

        const priceField = row.querySelector('.price');
        const descriptionField = row.querySelector('.product-description');
        const description = selectedOption.getAttribute('data-description') || '';
        const price = parseFloat(selectedOption.getAttribute('data-lkr-price') || 0);

        priceField.value = isNaN(price) ? '0.00' : price.toFixed(2);
        descriptionField.value = description;

        // First check if any products are selected (this will update the hasProducts flag)
        checkForProducts();
        // Then update the row total
        updateRowTotal(row);
    }

    // Updated Row Total Calculation Function
    function updateRowTotal(row) {
        let price = parseFloat(row.querySelector('.price').value) || 0;
        let discount = parseFloat(row.querySelector('.discount').value) || 0;

        // Ensure discount doesn't exceed price
        if (discount > price) {
            discount = price;
            row.querySelector('.discount').value = discount;
        }

        let subtotal = price - discount;
        row.querySelector('.subtotal').value = subtotal.toFixed(2);
        updateTotals();
    }

    // Updated Totals Calculation Function with fix for delivery fee
    function updateTotals() {
        let subtotal = 0;
        let totalDiscount = 0;

        document.querySelectorAll('#order_table tbody tr').forEach(function(row) {
            let rowPrice = parseFloat(row.querySelector('.price').value) || 0;
            let rowDiscount = parseFloat(row.querySelector('.discount').value) || 0;

            // Ensure discount doesn't exceed price
            if (rowDiscount > rowPrice) {
                rowDiscount = rowPrice;
                row.querySelector('.discount').value = rowDiscount;
            }

            let rowSubtotal = rowPrice - rowDiscount;
            row.querySelector('.subtotal').value = rowSubtotal.toFixed(2);

            subtotal += rowPrice;
            totalDiscount += rowDiscount;
        });

        document.getElementById('subtotal_display').textContent = subtotal.toFixed(2);
        document.getElementById('subtotal_amount').value = subtotal.toFixed(2);
        document.getElementById('discount_display').textContent = totalDiscount.toFixed(2);
        document.getElementById('discount_amount').value = totalDiscount.toFixed(2);

        // Always check for products before calculating total
        let hasAnyProducts = false;
        document.querySelectorAll('#order_table tbody tr').forEach(function(row) {
            const productSelect = row.querySelector('.product-select');
            if (productSelect && productSelect.value !== "") {
                hasAnyProducts = true;
            }
        });

        // Calculate total including delivery fee if products are selected
        let total = subtotal - totalDiscount;

        // Add delivery fee if any products are selected
        const deliveryFeeRow = document.getElementById('delivery_fee_row');
        if (hasAnyProducts) {
            total += deliveryFee;
            deliveryFeeRow.style.display = 'flex';
        } else {
            deliveryFeeRow.style.display = 'none';
        }

        document.getElementById('total_display').textContent = total.toFixed(2);
        document.getElementById('total_amount').value = total.toFixed(2);
        document.getElementById('lkr_total_amount').value = total.toFixed(2);
    }

    // Customer modal functionality
    const customerModal = document.getElementById("customerModal");
    const selectCustomerBtn = document.getElementById("select_existing_customer");
    const closeModal = document.querySelector(".close-modal");

    selectCustomerBtn.addEventListener('click', function() {
        customerModal.style.display = "block";
    });

    closeModal.addEventListener('click', function() {
        customerModal.style.display = "none";
    });

    window.addEventListener('click', function(event) {
        if (event.target == customerModal) {
            customerModal.style.display = "none";
        }
    });

    // Customer search functionality
    const customerSearch = document.getElementById("customerSearch");
    customerSearch.addEventListener('keyup', function() {
        const value = this.value.toLowerCase();
        document.querySelectorAll(".customer-row").forEach(function(row) {
            const text = row.textContent || row.innerText;
            row.style.display = text.toLowerCase().indexOf(value) > -1 ? "" : "none";
        });
    });

    // Updated: Select customer functionality with proper field mapping
    document.querySelectorAll(".select-customer-btn").forEach(function(btn) {
        btn.addEventListener('click', function() {
            const row = this.closest('tr');
            
            // Populate form fields with existing customer data
            document.getElementById('customer_id').value = row.getAttribute('data-customer-id');
            document.getElementById('customer_name').value = row.getAttribute('data-name');
            document.getElementById('customer_email').value = row.getAttribute('data-email');
            document.getElementById('customer_phone').value = row.getAttribute('data-phone');
            document.getElementById('address_line1').value = row.getAttribute('data-address-line1');
            document.getElementById('address_line2').value = row.getAttribute('data-address-line2');
            document.getElementById('city_name_input').value = row.getAttribute('data-city-name'); // Populate city name
            document.getElementById('city_id').value = row.getAttribute('data-city-id');
            
            // Set flag and make fields readonly
            isExistingCustomer = true;
            toggleCustomerFields(true);
            
            // Clear any existing validation errors
            document.querySelectorAll('.validation-error').forEach(el => el.remove());
            
            customerModal.style.display = "none";
            alert('Customer selected: ' + row.getAttribute('data-name'));
        });
    });

    // City autocomplete functionality
    let debounceTimer;
    const cityNameInput = document.getElementById('city_name_input');
    const cityIdInput = document.getElementById('city_id');
    const suggestionsDropdown = document.getElementById('city-suggestions-dropdown');

    cityNameInput.addEventListener('input', function() {
        const query = this.value;
        
        // Clear previously selected city_id if user types again
        cityIdInput.value = '';
        
        clearTimeout(debounceTimer);
        if (query.length < 2) {
            suggestionsDropdown.innerHTML = '';
            suggestionsDropdown.style.display = 'none';
            return;
        }

        debounceTimer = setTimeout(() => {
            // Assuming get_cities.php is in the customers directory
            fetch(`../customers/get_cities.php?term=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(cities => {
                    suggestionsDropdown.innerHTML = '';
                    if (cities.length > 0) {
                        cities.forEach(city => {
                            const item = document.createElement('div');
                            item.className = 'city-suggestion-item';
                            item.textContent = city.name;
                            item.setAttribute('data-city-id', city.id);
                            item.addEventListener('click', function() {
                                cityNameInput.value = this.textContent;
                                cityIdInput.value = this.getAttribute('data-city-id');
                                suggestionsDropdown.innerHTML = '';
                                suggestionsDropdown.style.display = 'none';
                            });
                            suggestionsDropdown.appendChild(item);
                        });
                        suggestionsDropdown.style.display = 'block';
                    } else {
                        suggestionsDropdown.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error fetching cities:', error);
                    suggestionsDropdown.style.display = 'none';
                });
        }, 300); // 300ms debounce
    });

    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.city-suggestions-container')) {
            suggestionsDropdown.style.display = 'none';
        }
    });

    // Add "Clear Selection" button functionality (you may want to add this button to your HTML)
    // This allows users to switch back to creating a new customer
    function addClearSelectionButton() {
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'btn btn-outline-secondary ml-2';
        clearBtn.innerHTML = '<i class="feather icon-x"></i> Clear Selection';
        clearBtn.style.marginLeft = '10px';
        clearBtn.addEventListener('click', clearCustomerFields);
        
        // Add the button next to the "Select Customer" button
        selectCustomerBtn.parentNode.appendChild(clearBtn);
    }

    // Call this function to add the clear button
    addClearSelectionButton();

    // Real-time validation for email (only for new customers)
    document.getElementById('customer_email').addEventListener('input', function() {
        if (!isExistingCustomer) {
            document.querySelectorAll('.validation-error').forEach(el => el.remove());
            const email = this.value.trim();
            if (email !== '' && !isValidEmail(email)) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Invalid email format';
                this.parentNode.appendChild(errorDiv);
            }
        }
    });

    // Real-time validation for phone (only for new customers)
    document.getElementById('customer_phone').addEventListener('input', function() {
        if (!isExistingCustomer) {
            document.querySelectorAll('.validation-error').forEach(el => el.remove());
            const phone = this.value.trim();
            if (phone !== '' && !isValidPhoneNumber(phone)) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Phone number must be 10 digits';
                this.parentNode.appendChild(errorDiv);
            }
        }
    });

    // Enhanced form submission validation
    document.getElementById('orderForm').addEventListener('submit', function(e) {
        // Validate customer information
        if (!validateCustomerInfo()) {
            e.preventDefault();
            return false;
        }

        // Validate dates
        if (!validateDates()) {
            e.preventDefault();
            return false;
        }

        // Validate at least one product is added
        if (document.querySelectorAll('#order_table tbody tr').length === 0) {
            alert('Please add at least one product to the order.');
            e.preventDefault();
            return false;
        }

        // Validate product selection
        let isProductValid = true;
        document.querySelectorAll('#order_table tbody tr').forEach(function(row) {
            let productSelect = row.querySelector('.product-select');
            if (productSelect.value === "") {
                alert('Please select a product for all order lines.');
                isProductValid = false;
            }
        });

        if (!isProductValid) {
            e.preventDefault();
            return false;
        }

        // If all validations pass, allow form submission
        return true;
    });

    // Product selection change
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('product-select')) {
            updateProductPrice(e.target.closest('tr'));
        }
    });

    // Add product row
    document.getElementById('add_product').addEventListener('click', function() {
        let newRow = document.querySelector('#order_table tbody tr').cloneNode(true);
        
        // Clear all input values in the new row
        newRow.querySelectorAll('input').forEach(input => {
            if (input.classList.contains('price')) {
                input.value = '0.00';
            } else if (input.classList.contains('discount')) {
                input.value = '0';
            } else if (input.classList.contains('subtotal')) {
                input.value = '0.00';
            } else {
                input.value = '';
            }
        });
        
        // Reset the select element
        newRow.querySelector('.product-select').value = '';
        
        document.querySelector('#order_table tbody').appendChild(newRow);
    });

    // Remove product row
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove_product')) {
            const tableBody = document.querySelector('#order_table tbody');
            if (tableBody.children.length > 1) {
                e.target.closest('tr').remove();
                // After removing a row, check if there are any products left
                checkForProducts();
            } else {
                // If it's the last row, just clear it instead of removing
                let row = e.target.closest('tr');
                row.querySelector('.product-select').value = '';
                row.querySelector('.product-description').value = '';
                row.querySelector('.price').value = '0.00';
                row.querySelector('.discount').value = '0';
                row.querySelector('.subtotal').value = '0.00';
                checkForProducts();
            }
        }
    });

    // Update on price or discount change
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('price') || e.target.classList.contains('discount')) {
            // Ensure discount is a whole number
            if (e.target.classList.contains('discount')) {
                let value = e.target.value;
                e.target.value = value.replace(/[^0-9]/g, '');
            }
            updateRowTotal(e.target.closest('tr'));
        }
    });

    // Initialize: hide delivery fee row until products are added
    document.getElementById('delivery_fee_row').style.display = 'none';

    // Initialize the form on page load
    updateTotals();
});

// Date validation functions
function isValidDate(dateString) {
    const date = new Date(dateString);
    return date instanceof Date && !isNaN(date) && dateString === date.toISOString().split('T')[0];
}

function validateDates() {
    const orderDate = document.querySelector('input[name="order_date"]').value;
    const dueDate = document.querySelector('input[name="due_date"]').value;
    const today = new Date().toISOString().split('T')[0];

    // Clear previous error messages
    document.querySelectorAll('.date-validation-error').forEach(el => el.remove());

    let isValid = true;

    // Order date validation
    if (!orderDate) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '0.875rem';
        errorDiv.style.marginTop = '0.25rem';
        errorDiv.textContent = 'Order date is required';
        document.querySelector('input[name="order_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    } else if (!isValidDate(orderDate)) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '0.875rem';
        errorDiv.style.marginTop = '0.25rem';
        errorDiv.textContent = 'Invalid order date format';
        document.querySelector('input[name="order_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    } else if (orderDate > today) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '0.875rem';
        errorDiv.style.marginTop = '0.25rem';
        errorDiv.textContent = 'Order date cannot be in the future';
        document.querySelector('input[name="order_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    }

    // Due date validation
    if (!dueDate) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '0.875rem';
        errorDiv.style.marginTop = '0.25rem';
        errorDiv.textContent = 'Due date is required';
        document.querySelector('input[name="due_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    } else if (!isValidDate(dueDate)) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '0.875rem';
        errorDiv.style.marginTop = '0.25rem';
        errorDiv.textContent = 'Invalid due date format';
        document.querySelector('input[name="due_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    } else if (orderDate && dueDate < orderDate) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '0.875rem';
        errorDiv.style.marginTop = '0.25rem';
        errorDiv.textContent = 'Due date cannot be earlier than order date';
        document.querySelector('input[name="due_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    }

    return isValid;
}

// Real-time validation event listeners for dates
document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('input[name="order_date"]').addEventListener('change', function() {
        // Clear previous date errors
        document.querySelectorAll('.date-validation-error').forEach(el => el.remove());
        
        const orderDate = this.value;
        const today = new Date().toISOString().split('T')[0];
        
        if (orderDate && orderDate > today) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'date-validation-error validation-error';
            errorDiv.style.color = '#dc3545';
            errorDiv.style.fontSize = '0.875rem';
            errorDiv.style.marginTop = '0.25rem';
            errorDiv.textContent = 'Order date cannot be in the future';
            this.parentNode.appendChild(errorDiv);
        }
        
        // Re-validate due date when order date changes
        const dueDate = document.querySelector('input[name="due_date"]').value;
        if (orderDate && dueDate && dueDate < orderDate) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'date-validation-error validation-error';
            errorDiv.style.color = '#dc3545';
            errorDiv.style.fontSize = '0.875rem';
            errorDiv.style.marginTop = '0.25rem';
            errorDiv.textContent = 'Due date cannot be earlier than order date';
            document.querySelector('input[name="due_date"]').parentNode.appendChild(errorDiv);
        }
    });

    document.querySelector('input[name="due_date"]').addEventListener('change', function() {
        // Clear previous date errors for due date
        this.parentNode.querySelectorAll('.date-validation-error').forEach(el => el.remove());
        
        const dueDate = this.value;
        const orderDate = document.querySelector('input[name="order_date"]').value;
        
        if (orderDate && dueDate && dueDate < orderDate) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'date-validation-error validation-error';
            errorDiv.style.color = '#dc3545';
            errorDiv.style.fontSize = '0.875rem';
            errorDiv.style.marginTop = '0.25rem';
            errorDiv.textContent = 'Due date cannot be earlier than order date';
            this.parentNode.appendChild(errorDiv);
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    // Store the delivery fee value from PHP
    let deliveryFee = <?php echo $deliveryFee; ?>;
    let hasProducts = false;
    let isExistingCustomer = false;

    // Function to validate all form fields and enable/disable submit button
    function validateFormAndToggleSubmit() {
        const submitButton = document.getElementById('submit_order');
        let isFormValid = true;
        
        // Clear previous validation errors
        document.querySelectorAll('.product-validation-error').forEach(el => el.remove());
        
        // 1. Validate customer information
        const customerValid = validateCustomerInfo();
        
        // 2. Validate dates
        const datesValid = validateDates();
        
        // 3. Validate products
        const productsValid = validateProducts();
        
        // 4. Check if at least one product row has all required fields filled
        const hasValidProducts = checkForValidProducts();
        
        isFormValid = customerValid && datesValid && productsValid && hasValidProducts;
        
        // Enable/disable submit button based on validation
        if (isFormValid) {
            submitButton.disabled = false;
            submitButton.style.opacity = '1';
            submitButton.style.cursor = 'pointer';
            submitButton.style.backgroundColor = '#007bff';
        } else {
            submitButton.disabled = true;
            submitButton.style.opacity = '0.6';
            submitButton.style.cursor = 'not-allowed';
            submitButton.style.backgroundColor = '#6c757d';
        }
        
        return isFormValid;
    }

    // Function to validate products section
    function validateProducts() {
        let isValid = true;
        const productRows = document.querySelectorAll('#order_table tbody tr');
        
        productRows.forEach(function(row, index) {
            const productSelect = row.querySelector('.product-select');
            const descriptionInput = row.querySelector('.product-description');
            const priceInput = row.querySelector('.price');
            
            // Only validate if product is selected
            if (productSelect.value !== '') {
                // Validate description
                if (!descriptionInput.value.trim()) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'product-validation-error';
                    errorDiv.style.color = '#dc3545';
                    errorDiv.style.fontSize = '0.75rem';
                    errorDiv.style.marginTop = '0.25rem';
                    errorDiv.textContent = 'Description required';
                    descriptionInput.parentNode.appendChild(errorDiv);
                    isValid = false;
                }
                
                // Validate price
                const price = parseFloat(priceInput.value) || 0;
                if (price <= 0) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'product-validation-error';
                    errorDiv.style.color = '#dc3545';
                    errorDiv.style.fontSize = '0.75rem';
                    errorDiv.style.marginTop = '0.25rem';
                    errorDiv.textContent = 'Price must be greater than 0';
                    priceInput.parentNode.appendChild(errorDiv);
                    isValid = false;
                }
            }
        });
        
        return isValid;
    }

    // Function to check if there's at least one valid product
    function checkForValidProducts() {
        const productRows = document.querySelectorAll('#order_table tbody tr');
        let hasValidProduct = false;
        
        productRows.forEach(function(row) {
            const productSelect = row.querySelector('.product-select');
            const descriptionInput = row.querySelector('.product-description');
            const priceInput = row.querySelector('.price');
            const price = parseFloat(priceInput.value) || 0;
            
            if (productSelect.value !== '' && 
                descriptionInput.value.trim() !== '' && 
                price > 0) {
                hasValidProduct = true;
            }
        });
        
        return hasValidProduct;
    }

    // Enhanced customer validation function
    function validateCustomerInfo() {
        const customerName = document.getElementById('customer_name').value.trim();
        const customerEmail = document.getElementById('customer_email').value.trim();
        const customerPhone = document.getElementById('customer_phone').value.trim();
        const cityId = document.getElementById('city_id').value;
        const addressLine1 = document.getElementById('address_line1').value.trim();

        // Clear previous customer validation errors
        document.querySelectorAll('.validation-error').forEach(el => el.remove());

        let isValid = true;

        // Name is always required
        if (customerName === '') {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error';
            errorDiv.style.color = '#dc3545';
            errorDiv.style.fontSize = '0.875rem';
            errorDiv.style.marginTop = '0.25rem';
            errorDiv.textContent = 'Customer name is required';
            document.getElementById('customer_name').parentNode.appendChild(errorDiv);
            isValid = false;
        }

        // For new customers, all fields are required
        if (!isExistingCustomer) {
            if (customerEmail !== '' && !isValidEmail(customerEmail)) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Invalid email format';
                document.getElementById('customer_email').parentNode.appendChild(errorDiv);
                isValid = false;
            }

            if (customerPhone === '') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Phone number is required';
                document.getElementById('customer_phone').parentNode.appendChild(errorDiv);
                isValid = false;
            } else if (!isValidPhoneNumber(customerPhone)) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Phone number must be 10 digits';
                document.getElementById('customer_phone').parentNode.appendChild(errorDiv);
                isValid = false;
            }

            if (cityId === '') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'City is required';
                document.getElementById('city_id').parentNode.appendChild(errorDiv);
                isValid = false;
            }

            if (addressLine1 === '') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                errorDiv.style.color = '#dc3545';
                errorDiv.style.fontSize = '0.875rem';
                errorDiv.style.marginTop = '0.25rem';
                errorDiv.textContent = 'Address Line 1 is required';
                document.getElementById('address_line1').parentNode.appendChild(errorDiv);
                isValid = false;
            }
        }

        return isValid;
    }

    // Utility functions
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    function isValidPhoneNumber(phone) {
        const phoneRegex = /^\d{10}$/;
        return phoneRegex.test(phone);
    }

    function isValidDate(dateString) {
        const date = new Date(dateString);
        return date instanceof Date && !isNaN(date) && dateString === date.toISOString().split('T')[0];
    }

    // Add visual indicators to required fields in products table
    function addRequiredIndicators() {
        const productHeader = document.querySelector('#order_table thead th.product-col');
        const descriptionHeader = document.querySelector('#order_table thead th.description-col');
        const priceHeader = document.querySelector('#order_table thead th.price-col');
        
        if (productHeader && !productHeader.querySelector('.required-indicator')) {
            productHeader.innerHTML += ' <span class="required-indicator" style="color: #dc3545;">*</span>';
        }
        if (descriptionHeader && !descriptionHeader.querySelector('.required-indicator')) {
            descriptionHeader.innerHTML += ' <span class="required-indicator" style="color: #dc3545;">*</span>';
        }
        if (priceHeader && !priceHeader.querySelector('.required-indicator')) {
            priceHeader.innerHTML += ' <span class="required-indicator" style="color: #dc3545;">*</span>';
        }
    }

    // Event listeners for real-time validation
    
    // Customer fields validation on input
    document.getElementById('customer_name').addEventListener('input', validateFormAndToggleSubmit);
    document.getElementById('customer_email').addEventListener('input', validateFormAndToggleSubmit);
    document.getElementById('customer_phone').addEventListener('input', validateFormAndToggleSubmit);
    document.getElementById('city_id').addEventListener('change', validateFormAndToggleSubmit);
    document.getElementById('address_line1').addEventListener('input', validateFormAndToggleSubmit);
    document.getElementById('address_line2').addEventListener('input', validateFormAndToggleSubmit);

    // Product fields validation with event delegation
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('product-select')) {
            updateProductPrice(e.target.closest('tr'));
            validateFormAndToggleSubmit();
        }
    });

    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('product-description') || 
            e.target.classList.contains('price') || 
            e.target.classList.contains('discount')) {
            
            if (e.target.classList.contains('price') || e.target.classList.contains('discount')) {
                updateRowTotal(e.target.closest('tr'));
            }
            validateFormAndToggleSubmit();
        }
    });

    // Enhanced form submission
    document.getElementById('orderForm').addEventListener('submit', function(e) {
        if (!validateFormAndToggleSubmit()) {
            e.preventDefault();
            // Show alert with specific issues
            let issues = [];
            if (!validateCustomerInfo()) issues.push('Customer information');
            if (!validateDates()) issues.push('Order dates');
            if (!validateProducts()) issues.push('Product information');
            if (!checkForValidProducts()) issues.push('At least one complete product');
            
            alert('Please fix the following issues before submitting:\n- ' + issues.join('\n- '));
            return false;
        }
        return true;
    });

    // Your existing functions (updateProductPrice, updateRowTotal, etc.) remain the same
    function updateProductPrice(row) {
        const productSelect = row.querySelector('.product-select');
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        
        if (productSelect.value === "") return;

        const priceField = row.querySelector('.price');
        const descriptionField = row.querySelector('.product-description');
        const description = selectedOption.getAttribute('data-description') || '';
        const price = parseFloat(selectedOption.getAttribute('data-lkr-price') || 0);

        priceField.value = isNaN(price) ? '0.00' : price.toFixed(2);
        descriptionField.value = description;

        checkForProducts();
        updateRowTotal(row);
    }

    function updateRowTotal(row) {
        let price = parseFloat(row.querySelector('.price').value) || 0;
        let discount = parseFloat(row.querySelector('.discount').value) || 0;

        if (discount > price) {
            discount = price;
            row.querySelector('.discount').value = discount;
        }

        let subtotal = price - discount;
        row.querySelector('.subtotal').value = subtotal.toFixed(2);
        updateTotals();
    }

    function checkForProducts() {
        let productSelected = false;

        document.querySelectorAll('#order_table tbody tr').forEach(function(row) {
            const productSelect = row.querySelector('.product-select');
            if (productSelect && productSelect.value !== "") {
                productSelected = true;
            }
        });

        hasProducts = productSelected;

        const deliveryFeeRow = document.getElementById('delivery_fee_row');
        if (productSelected) {
            deliveryFeeRow.style.display = 'flex';
        } else {
            deliveryFeeRow.style.display = 'none';
        }

        updateTotals();
        return productSelected;
    }

    function updateTotals() {
        let subtotal = 0;
        let totalDiscount = 0;

        document.querySelectorAll('#order_table tbody tr').forEach(function(row) {
            let rowPrice = parseFloat(row.querySelector('.price').value) || 0;
            let rowDiscount = parseFloat(row.querySelector('.discount').value) || 0;

            if (rowDiscount > rowPrice) {
                rowDiscount = rowPrice;
                row.querySelector('.discount').value = rowDiscount;
            }

            let rowSubtotal = rowPrice - rowDiscount;
            row.querySelector('.subtotal').value = rowSubtotal.toFixed(2);

            subtotal += rowPrice;
            totalDiscount += rowDiscount;
        });

        document.getElementById('subtotal_display').textContent = subtotal.toFixed(2);
        document.getElementById('subtotal_amount').value = subtotal.toFixed(2);
        document.getElementById('discount_display').textContent = totalDiscount.toFixed(2);
        document.getElementById('discount_amount').value = totalDiscount.toFixed(2);

        let hasAnyProducts = false;
        document.querySelectorAll('#order_table tbody tr').forEach(function(row) {
            const productSelect = row.querySelector('.product-select');
            if (productSelect && productSelect.value !== "") {
                hasAnyProducts = true;
            }
        });

        let total = subtotal - totalDiscount;

        const deliveryFeeRow = document.getElementById('delivery_fee_row');
        if (hasAnyProducts) {
            total += deliveryFee;
            deliveryFeeRow.style.display = 'flex';
        } else {
            deliveryFeeRow.style.display = 'none';
        }

        document.getElementById('total_display').textContent = total.toFixed(2);
        document.getElementById('total_amount').value = total.toFixed(2);
        document.getElementById('lkr_total_amount').value = total.toFixed(2);
    }

    // Add required indicators and initialize
    addRequiredIndicators();
    validateFormAndToggleSubmit(); // Initial validation on page load
    
    // Initialize: hide delivery fee row until products are added
    document.getElementById('delivery_fee_row').style.display = 'none';
    updateTotals();
});
</script>
</body>
</html>