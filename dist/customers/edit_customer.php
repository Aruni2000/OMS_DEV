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

// Check database connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Get customer ID from URL parameter
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($customer_id <= 0) {
    header("Location: customer_list.php");
    exit();
}

// Fetch customer data
$customer = null;
$customerStmt = $conn->prepare("
    SELECT c.*, ct.city_name 
    FROM customers c 
    LEFT JOIN city_table ct ON c.city_id = ct.city_id 
    WHERE c.customer_id = ?
");
$customerStmt->bind_param("i", $customer_id);
$customerStmt->execute();
$customerResult = $customerStmt->get_result();

if ($customerResult->num_rows === 0) {
    header("Location: customer_list.php");
    exit();
}

$customer = $customerResult->fetch_assoc();
$customerStmt->close();

// Fetch available cities dynamically from city_table
$cities = [];
$cityQuery = "SELECT city_id, city_name FROM city_table WHERE is_active = 1 ORDER BY city_name ASC";
$cityResult = $conn->query($cityQuery);

// Collect cities into an array
if ($cityResult && $cityResult->num_rows > 0) {
    while ($cityRow = $cityResult->fetch_assoc()) {
        $cities[] = $cityRow;
    }
} else {
    // Log error or handle the case where no cities are found
    error_log("No cities found in city_table or query failed: " . $conn->error);
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - Edit Customer</title>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />

    <!-- Custom CSS for AJAX notifications -->
    <style>
        .ajax-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
            margin-bottom: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            border-radius: 8px;
            animation: slideInRight 0.3s ease-out;
            border: 1px solid transparent;
            padding: 1rem 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            color: #0f5132;
            background: linear-gradient(135deg, #f8f9fa 0%, #d1e7dd 100%);
            border-left-color: #28a745;
        }

        .alert-danger {
            color: #842029;
            background: linear-gradient(135deg, #f8f9fa 0%, #f8d7da 100%);
            border-left-color: #dc3545;
        }

        .alert-warning {
            color: #664d03;
            background: linear-gradient(135deg, #f8f9fa 0%, #fff3cd 100%);
            border-left-color: #ffc107;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        }

        .loading-spinner {
            text-align: center;
            color: white;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-top: 5px solid #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .customer-info {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .customer-info h6 {
            color: #495057;
            margin-bottom: 0.5rem;
        }
    </style>
</head>

<body>
    <!-- LOADER -->
    <?php
        include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    ?>
    <!-- END LOADER -->

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <h5>Processing...</h5>
            <p>Please wait while we update the customer</p>
        </div>
    </div>

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
           <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Edit User</h5>
                    </div>
                    <div class="page-header-breadcrumb">
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item">
                                <a href="customer_list.php">Customer List</a>
                            </li>
                            <li class="breadcrumb-item active">Edit Customer</li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->
            <!-- [ Main Content ] start -->
            <div class="main-container">

                <!-- Edit Customer Form -->
                <form method="POST" id="editCustomerForm" class="customer-form" novalidate>
                    
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="customer_id" value="<?= $customer['customer_id'] ?>">
                    
                    <!-- Customer Details Section -->
                    <div class="form-section">
                        <div class="section-content">
                            <!-- First Row: Name and Email -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-user"></i> Full Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name"
                                        placeholder="Enter customer's full name" 
                                        value="<?= htmlspecialchars($customer['name']) ?>" required>
                                    <div class="error-feedback" id="name-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email Address <span class="text-muted small fw-normal">(Optional)</span>
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email"
                                        placeholder="customer@example.com" 
                                        value="<?= htmlspecialchars($customer['email']) ?>" required>
                                    <div class="error-feedback" id="email-error"></div>
                                    <div class="email-suggestions" id="email-suggestions"></div>
                                </div>
                            </div>

                            <!-- Second Row: Phone and Status -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="phone" class="form-label">
                                        <i class="fas fa-phone"></i> Phone Number<span class="required">*</span>
                                    </label>
                                    <input type="tel" class="form-control" id="phone" name="phone"
                                        placeholder="0771234567" 
                                        value="<?= htmlspecialchars($customer['phone']) ?>" required>
                                    <div class="error-feedback" id="phone-error"></div>
                                    <div class="phone-hint">Enter 10-digit Sri Lankan mobile number</div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on"></i> Status<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="Active" <?= $customer['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="Inactive" <?= $customer['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                    <div class="error-feedback" id="status-error"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Address Details Section -->
                    <div class="form-section address-section">
                        <div class="section-content">
                            <!-- First Row: Address Lines -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="address_line1" class="form-label">
                                        <i class="fas fa-home"></i> Address Line 1<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="address_line1" name="address_line1"
                                        placeholder="House number, street name" 
                                        value="<?= htmlspecialchars($customer['address_line1']) ?>" required>
                                    <div class="error-feedback" id="address_line1-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="address_line2" class="form-label">
                                        <i class="fas fa-building"></i> Address Line 2
                                    </label>
                                    <input type="text" class="form-control" id="address_line2" name="address_line2"
                                        placeholder="Apartment, suite, building (optional)"
                                        value="<?= htmlspecialchars($customer['address_line2'] ?? '') ?>">
                                    <div class="error-feedback" id="address_line2-error"></div>
                                </div>
                            </div>

                            <!-- Second Row: City -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="city_id" class="form-label">
                                        <i class="fas fa-city"></i> City<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="city_id" name="city_id" required>
                                        <option value="">Select City</option>
                                        <?php if (!empty($cities)): ?>
                                            <?php foreach ($cities as $city): ?>
                                                <option value="<?= htmlspecialchars($city['city_id']) ?>" 
                                                    <?= $customer['city_id'] == $city['city_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($city['city_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No cities available</option>
                                        <?php endif; ?>
                                    </select>
                                    <div class="error-feedback" id="city_id-error"></div>
                                    <?php if (empty($cities)): ?>
                                        <div class="no-cities-message">No cities found. Please contact administrator.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Update Customer
                        </button>
                         <button type="button" class="btn btn-secondary ms-2" id="cancelBtn" onclick="window.location.href='customer_list.php'">
                            <i class="fas fa-times"></i> Back to Customers
                        </button>
                    </div>
                </form>
            </div>
            <!-- [ Main Content ] end -->
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

    <!-- jQuery -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
        // Store original form data for reset functionality
        let originalFormData = {};

        $(document).ready(function() {
            // Store original form data
            storeOriginalData();
            
            // Initialize form
            initializeForm();
            
            // AJAX Form submission
            $('#editCustomerForm').on('submit', function(e) {
                e.preventDefault();
                
                // Clear previous validations
                clearAllValidations();
                
                // Validate form
                if (validateForm()) {
                    submitFormAjax();
                } else {
                    // Scroll to first error
                    scrollToFirstError();
                }
            });
            
            // Reset button
            $('#resetBtn').on('click', function() {
                resetForm();
            });
            
            // Real-time validation
            setupRealTimeValidation();
        });

        // Store original form data
        function storeOriginalData() {
            originalFormData = {
                name: $('#name').val(),
                email: $('#email').val(),
                phone: $('#phone').val(),
                status: $('#status').val(),
                address_line1: $('#address_line1').val(),
                address_line2: $('#address_line2').val(),
                city_id: $('#city_id').val()
            };
        }

        // AJAX Form Submission Function
        function submitFormAjax() {
            // Show loading overlay
            showLoading();
            
            // Disable submit button
            const $submitBtn = $('#submitBtn');
            const originalText = $submitBtn.html();
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Updating Customer...');
            
            // Prepare form data
            const formData = new FormData($('#editCustomerForm')[0]);
            
            // AJAX request
            $.ajax({
                url: 'update_customer.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                timeout: 30000, // 30 seconds timeout
                success: function(response) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    if (response.success) {
                        showSuccessNotification(response.message || 'Customer updated successfully!');
                        // Update stored original data
                        storeOriginalData();
                    } else {
                        if (response.errors) {
                            // Show field-specific errors
                            showFieldErrors(response.errors);
                        }
                        showErrorNotification(response.message || 'Failed to update customer. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while updating the customer.';
                    
                    if (status === 'timeout') {
                        errorMessage = 'Request timeout. Please try again.';
                    } else if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    } else if (xhr.status === 500) {
                        errorMessage = 'Server error. Please contact administrator.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'No internet connection. Please check your connection.';
                    }
                    
                    showErrorNotification(errorMessage);
                    console.error('AJAX Error:', error);
                }
            });
        }
        
        // Show field-specific errors from server
        function showFieldErrors(errors) {
            $.each(errors, function(field, message) {
                showError(field, message);
            });
        }
        
        // Loading functions
        function showLoading() {
            $('#loadingOverlay').css('display', 'flex');
            $('body').css('overflow', 'hidden');
        }
        
        function hideLoading() {
            $('#loadingOverlay').hide();
            $('body').css('overflow', 'auto');
        }
        
        // Notification functions
        function showSuccessNotification(message) {
            showNotification(message, 'success');
        }
        
        function showErrorNotification(message) {
            showNotification(message, 'danger');
        }
        
        function showNotification(message, type) {
            const notificationId = 'notification_' + Date.now();
            const iconClass = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            
            const notification = `
                <div class="alert alert-${type} alert-dismissible fade show ajax-notification" id="${notificationId}" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="${iconClass} me-2"></i>
                        <div>${message}</div>
                    </div>
                    <button type="button" class="btn-close" onclick="hideNotification('${notificationId}')" aria-label="Close"></button>
                </div>
            `;
            
            $('body').append(notification);
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                hideNotification(notificationId);
            }, 5000);
        }
        
        function hideNotification(notificationId) {
            const $notification = $('#' + notificationId);
            if ($notification.length) {
                $notification.addClass('hide');
                setTimeout(() => {
                    $notification.remove();
                }, 300);
            }
        }
        
        // Form reset function
        function resetForm() {
            // Reset to original values
            $('#name').val(originalFormData.name);
            $('#email').val(originalFormData.email);
            $('#phone').val(originalFormData.phone);
            $('#status').val(originalFormData.status);
            $('#address_line1').val(originalFormData.address_line1);
            $('#address_line2').val(originalFormData.address_line2);
            $('#city_id').val(originalFormData.city_id);
            
            clearAllValidations();
            $('#email-suggestions').html('');
            $('#name').focus();
        }
        
        // Clear all validations
        function clearAllValidations() {
            $('.form-control, .form-select').removeClass('is-valid is-invalid');
            $('.error-feedback').hide().text('');
        }
        
        // Scroll to first error
        function scrollToFirstError() {
            const $firstError = $('.is-invalid').first();
            if ($firstError.length) {
                $('html, body').animate({
                    scrollTop: $firstError.offset().top - 100
                }, 500);
                $firstError.focus();
            }
        }
        
        // Initialize form
        function initializeForm() {
            $('#name').focus();
            
            // Auto-format phone number
            $('#phone').on('input', function() {
                let value = this.value.replace(/\D/g, '');
                if (value.length > 10) {
                    value = value.substring(0, 10);
                }
                this.value = value;
            });
            
            // Email formatting
            $('#email').on('input', function() {
                this.value = this.value.toLowerCase().trim();
                $('#email-suggestions').html('');
            });
        }
        
        // Setup real-time validation
        function setupRealTimeValidation() {
            $('#name').on('blur', function() {
                const validation = validateName($(this).val());
                if (!validation.valid) {
                    showError('name', validation.message);
                } else {
                    showSuccess('name');
                }
            });
            
            $('#email').on('blur', function() {
                const validation = validateEmail($(this).val());
                if (!validation.valid) {
                    showError('email', validation.message);
                } else {
                    showSuccess('email');
                }
                
                // Show email suggestions
                const suggestion = suggestEmail($(this).val());
                if (suggestion && suggestion !== $(this).val().toLowerCase()) {
                    $('#email-suggestions').html(`Did you mean <a href="#" onclick="$('#email').val('${suggestion}'); $('#email-suggestions').html(''); $('#email').focus();">${suggestion}</a>?`);
                } else {
                    $('#email-suggestions').html('');
                }
            });
            
            $('#phone').on('blur', function() {
                const validation = validatePhone($(this).val());
                if (!validation.valid) {
                    showError('phone', validation.message);
                } else {
                    showSuccess('phone');
                }
            });
            
            $('#address_line1').on('blur', function() {
                const validation = validateAddressLine1($(this).val());
                if (!validation.valid) {
                    showError('address_line1', validation.message);
                } else {
                    showSuccess('address_line1');
                }
            });
            
            $('#address_line2').on('blur', function() {
                if ($(this).val().trim() !== '') {
                    const validation = validateAddressLine($(this).val(), 'Address Line 2');
                    if (!validation.valid) {
                        showError('address_line2', validation.message);
                    } else {
                        showSuccess('address_line2');
                    }
                } else {
                    clearValidation('address_line2');
                }
            });
            
            $('#city_id').on('change', function() {
                const validation = validateCity($(this).val());
                if (!validation.valid) {
                    showError('city_id', validation.message);
                } else {
                    showSuccess('city_id');
                }
            });
        }

        // Validation functions (same as add customer page)
        function validateName(name) {
            if (name.trim() === '') {
                return { valid: false, message: 'Customer name is required' };
            }
            if (name.trim().length < 2) {
                return { valid: false, message: 'Name must be at least 2 characters long' };
            }
            if (name.length > 255) {
                return { valid: false, message: 'Name is too long (maximum 255 characters)' };
            }
            if (!/^[a-zA-Z\s.\-']+$/.test(name)) {
                return { valid: false, message: 'Name can only contain letters, spaces, dots, hyphens, and apostrophes' };
            }
            return { valid: true, message: '' };
        }

        function validateEmail(email) {
            if (email.trim() === '') {
                return { valid: true, message: '' };
            }
            if (email.length > 100) {
                return { valid: false, message: 'Email address is too long (maximum 100 characters)' };
            }
            const emailRegex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
            if (!emailRegex.test(email)) {
                return { valid: false, message: 'Please enter a valid email address' };
            }
            return { valid: true, message: '' };
        }

        function validatePhone(phone) {
            if (phone.trim() === '') {
                return { valid: false, message: 'Phone number is required' };
            }
            if (phone.length > 20) {
                return { valid: false, message: 'Phone number is too long (maximum 20 characters)' };
            }
            const cleanPhone = phone.replace(/\s+/g, '');
            const digitsOnly = cleanPhone.replace(/[^0-9]/g, '');
            
            if (digitsOnly.length !== 10) {
                return { valid: false, message: 'Phone number must be exactly 10 digits' };
            }
            
            const localPattern = /^0[1-9][0-9]{8}$/;
            const internationalPattern = /^(\+94|94)[1-9][0-9]{8}$/;
            
            if (!localPattern.test(cleanPhone) && !internationalPattern.test(cleanPhone)) {
                return { valid: false, message: 'Please enter a valid Sri Lankan phone number (e.g., 0771234567)' };
            }
            return { valid: true, message: '' };
        }

        function validateAddressLine1(address) {
            if (address.trim() === '') {
                return { valid: false, message: 'Address Line 1 is required' };
            }
            if (address.trim().length < 3) {
                return { valid: false, message: 'Address Line 1 must be at least 3 characters long' };
            }
            if (address.length > 255) {
                return { valid: false, message: 'Address Line 1 is too long (maximum 255 characters)' };
            }
            return { valid: true, message: '' };
        }

        function validateCity(cityId) {
            if (cityId.trim() === '') {
                return { valid: false, message: 'City selection is required' };
            }
            return { valid: true, message: '' };
        }

        function validateAddressLine(address, fieldName, maxLength = 255) {
            if (address.length > maxLength) {
                return { valid: false, message: `${fieldName} is too long (maximum ${maxLength} characters)` };
            }
            return { valid: true, message: '' };
        }

        // Email suggestion function
        function suggestEmail(email) {
            if (!email || email.trim() === '' || !email.includes('@')) {
                return null;
            }
            
            const parts = email.split('@');
            const username = parts[0];
            const domain = parts[1].toLowerCase();
            
            const typos = {
                'gamil.com': 'gmail.com',
                'gmail.co': 'gmail.com',
                'gmail.cm': 'gmail.com',
                'gmal.com': 'gmail.com',
                'yahooo.com': 'yahoo.com',
                'yaho.com': 'yahoo.com',
                'yahoo.co': 'yahoo.com',
                'hotmai.com': 'hotmail.com',
                'hotmail.co': 'hotmail.com',
                'outlok.com': 'outlook.com',
                'outlook.co': 'outlook.com'
            };
            
            if (typos[domain]) {
                return username + '@' + typos[domain];
            }
            
            return null;
        }

        // Show/hide error functions
        function showError(fieldId, message) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.addClass('is-invalid').removeClass('is-valid');
                $errorDiv.text(message).show();
            }
        }

        function showSuccess(fieldId) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.addClass('is-valid').removeClass('is-invalid');
                $errorDiv.hide();
            }
        }

        function clearValidation(fieldId) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.removeClass('is-valid is-invalid');
                $errorDiv.hide();
            }
        }

        // Form validation
        function validateForm() {
            let isValid = true;
            
            // Get all field values
            const name = $('#name').val();
            const email = $('#email').val();
            const phone = $('#phone').val();
            const addressLine1 = $('#address_line1').val();
            const cityId = $('#city_id').val();
            
            // Validate required fields
            const validations = [
                { field: 'name', validator: validateName, value: name },
                { field: 'email', validator: validateEmail, value: email },
                { field: 'phone', validator: validatePhone, value: phone },
                { field: 'address_line1', validator: validateAddressLine1, value: addressLine1 },
                { field: 'city_id', validator: validateCity, value: cityId }
            ];
            
            validations.forEach(function(validation) {
                const result = validation.validator(validation.value);
                if (!result.valid) {
                    showError(validation.field, result.message);
                    isValid = false;
                } else {
                    showSuccess(validation.field);
                }
            });
            
            // Optional fields validation
            const addressLine2 = $('#address_line2').val();
            if (addressLine2.trim() !== '') {
                const address2Validation = validateAddressLine(addressLine2, 'Address Line 2');
                if (!address2Validation.valid) {
                    showError('address_line2', address2Validation.message);
                    isValid = false;
                } else {
                    showSuccess('address_line2');
                }
            }
            
            return isValid;
        }
    </script>
</body>
</html>