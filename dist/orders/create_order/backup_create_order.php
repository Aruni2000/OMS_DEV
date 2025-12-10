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

// Fetch necessary data for the form - only need to do this once
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
    <title>Order Management Admin Portal - Create Order </title>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/styles.css" id="main-style-link" />
</head>

<body>
  
   
    <!-- LOADER -->
    <?php
        include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    ?>
    <!-- END LOADER -->

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Create Order</h5>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="alert-container"></div>
            
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
            <button type="button" class="btn-outline-primary" id="select_existing_customer">
                <i class="feather icon-users"></i> Select Customer
            </button>
        </div>
    </div>
    <div class="section-body">
        <div class="customer-info-grid">
            <input type="hidden" name="customer_id" id="customer_id" value="">
            <div class="form-group">
                <label class="form-label">Name <span style="color: #dc3545;">*</span></label>
                <input type="text" class="form-control" name="customer_name" id="customer_name" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="customer_email" id="customer_email">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" name="customer_phone" id="customer_phone">
            </div>
            <div class="form-group">
                <label class="form-label">City</label>
                <select class="form-control" name="city_id" id="city_id">
                    <option value="">-- Select City --</option>
                    <?php
                    if ($cityResult && $cityResult->num_rows > 0) {
                        while ($city = $cityResult->fetch_assoc()) {
                            echo '<option value="' . $city['city_id'] . '">' . htmlspecialchars($city['city_name']) . '</option>';
                        }
                    }
                    ?>
                </select>
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
                                <button type="submit" class="btn-primary" id="submit_order">
                                    <i class="feather icon-save"></i> Create Order
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
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

    // Validate customer information
    function validateCustomerInfo() {
        const customerName = document.getElementById('customer_name').value.trim();
        const customerEmail = document.getElementById('customer_email').value.trim();
        const customerPhone = document.getElementById('customer_phone').value.trim();

        // Clear previous error messages
        document.querySelectorAll('.validation-error').forEach(el => el.remove());

        let isValid = true;

        // Name validation (required)
        if (customerName === '') {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error';
            errorDiv.textContent = 'Customer name is required';
            document.getElementById('customer_name').parentNode.appendChild(errorDiv);
            isValid = false;
        }

        // Email validation (optional, but if provided must be valid)
        if (customerEmail !== '' && !isValidEmail(customerEmail)) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error';
            errorDiv.textContent = 'Invalid email format';
            document.getElementById('customer_email').parentNode.appendChild(errorDiv);
            isValid = false;
        }

        // Phone validation (optional, but if provided must be 10 digits)
        if (customerPhone !== '' && !isValidPhoneNumber(customerPhone)) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error';
            errorDiv.textContent = 'Phone number must be 10 digits';
            document.getElementById('customer_phone').parentNode.appendChild(errorDiv);
            isValid = false;
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
                

                // Your existing form field population code
              
                document.getElementById('customer_id').value = row.getAttribute('data-customer-id');
                document.getElementById('customer_name').value = row.getAttribute('data-name');
                document.getElementById('customer_email').value = row.getAttribute('data-email');
                document.getElementById('customer_phone').value = row.getAttribute('data-phone');
                document.getElementById('address_line1').value = row.getAttribute('data-address-line1');
                document.getElementById('address_line2').value = row.getAttribute('data-address-line2');
                document.getElementById('city_id').value = row.getAttribute('data-city-id');
             
                
                customerModal.style.display = "none";
                alert('Customer selected: ' + row.getAttribute('data-name'));
            });
        });

    // Real-time validation for email
    document.getElementById('customer_email').addEventListener('input', function() {
        document.querySelectorAll('.validation-error').forEach(el => el.remove());
        const email = this.value.trim();
        if (email !== '' && !isValidEmail(email)) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error';
            errorDiv.textContent = 'Invalid email format';
            this.parentNode.appendChild(errorDiv);
        }
    });

    // Real-time validation for phone
    document.getElementById('customer_phone').addEventListener('input', function() {
        document.querySelectorAll('.validation-error').forEach(el => el.remove());
        const phone = this.value.trim();
        if (phone !== '' && !isValidPhoneNumber(phone)) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error';
            errorDiv.textContent = 'Phone number must be 10 digits';
            this.parentNode.appendChild(errorDiv);
        }
    });

    // Form submission validation
    document.getElementById('orderForm').addEventListener('submit', function(e) {
        // Validate customer information
        if (!validateCustomerInfo()) {
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
        errorDiv.textContent = 'Order date is required';
        document.querySelector('input[name="order_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    } else if (!isValidDate(orderDate)) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.textContent = 'Invalid order date format';
        document.querySelector('input[name="order_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    } else if (orderDate > today) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.textContent = 'Order date cannot be in the future';
        document.querySelector('input[name="order_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    }

    // Due date validation
    if (!dueDate) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.textContent = 'Due date is required';
        document.querySelector('input[name="due_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    } else if (!isValidDate(dueDate)) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.textContent = 'Invalid due date format';
        document.querySelector('input[name="due_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    } else if (orderDate && dueDate < orderDate) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.textContent = 'Due date cannot be earlier than order date';
        document.querySelector('input[name="due_date"]').parentNode.appendChild(errorDiv);
        isValid = false;
    }

    return isValid;
}

// Real-time validation event listeners for dates
document.querySelector('input[name="order_date"]').addEventListener('change', function() {
    // Clear previous date errors
    document.querySelectorAll('.date-validation-error').forEach(el => el.remove());
    
    const orderDate = this.value;
    const today = new Date().toISOString().split('T')[0];
    
    if (orderDate && orderDate > today) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
        errorDiv.textContent = 'Order date cannot be in the future';
        this.parentNode.appendChild(errorDiv);
    }
    
    // Re-validate due date when order date changes
    const dueDate = document.querySelector('input[name="due_date"]').value;
    if (orderDate && dueDate && dueDate < orderDate) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'date-validation-error validation-error';
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
        errorDiv.textContent = 'Due date cannot be earlier than order date';
        this.parentNode.appendChild(errorDiv);
    }
});
</script>
</body>
</html>
<?php