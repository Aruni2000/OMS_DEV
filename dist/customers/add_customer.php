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

// Fetch available cities dynamically from city_table
if (isset($_GET['query'])) {
    $query = $conn->real_escape_string($_GET['query']);
    $sql = "SELECT city_id, city_name FROM city_table 
            WHERE is_active = 1 AND city_name LIKE '%$query%' 
            ORDER BY city_name ASC LIMIT 10";
    $result = $conn->query($sql);

    $cities = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $cities[] = $row;
        }
    }

    header('Content-Type: application/json');
    echo json_encode($cities);
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - Add New Customer</title>

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

        .suggestions-list {
    position: absolute;
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 4px;
    max-height: 200px;
    overflow-y: auto;
    width: 100%;
    z-index: 1000;
    display: none;
}

.suggestion-item {
    padding: 8px 12px;
    cursor: pointer;
}

.suggestion-item:hover {
    background-color: #f1f1f1;
}

.suggestion-item.disabled {
    color: #999;
    cursor: default;
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
            <p>Please wait while we add the customer</p>
        </div>
    </div>

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Add New Customer</h5>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="main-container">
                <!-- Add Customer Form -->
                <form method="POST" id="addCustomerForm" class="customer-form" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
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
                                        placeholder="Enter customer's full name" required>
                                    <div class="error-feedback" id="name-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email Address<span class="required">*</span>
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email"
                                        placeholder="customer@example.com" required>
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
                                        placeholder="0771234567" required>
                                    <div class="error-feedback" id="phone-error"></div>
                                    <div class="phone-hint">Enter 10-digit Sri Lankan mobile number</div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on"></i> Status<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="Active" selected>Active</option>
                                        <option value="Inactive">Inactive</option>
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
                                        placeholder="House number, street name" required>
                                    <div class="error-feedback" id="address_line1-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="address_line2" class="form-label">
                                        <i class="fas fa-building"></i> Address Line 2
                                    </label>
                                    <input type="text" class="form-control" id="address_line2" name="address_line2"
                                        placeholder="Apartment, suite, building (optional)">
                                    <div class="error-feedback" id="address_line2-error"></div>
                                </div>
                            </div>

                            <!-- Second Row: City -->
                           <div class="customer-form-group" style="position: relative;">
    <label for="city_input" class="form-label">
        <i class="fas fa-city"></i> City<span class="required">*</span>
    </label>
    <input type="text" class="form-control" id="city_input" placeholder="Type city name" autocomplete="off" required>
    <input type="hidden" id="city_id" name="city_id">
    <div id="city-suggestions" class="suggestions-list"></div>
    <div class="error-feedback" id="city_id-error"></div>
</div>


                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-user-plus"></i> Add Customer
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" id="resetBtn">
                            <i class="fas fa-undo"></i> Reset Form
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
        $(document).ready(function() {
            // Initialize form
            initializeForm();
            
            // AJAX Form submission
            $('#addCustomerForm').on('submit', function(e) {
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

        // AJAX Form Submission Function
        function submitFormAjax() {
            // Show loading overlay
            showLoading();
            
            // Disable submit button
            const $submitBtn = $('#submitBtn');
            const originalText = $submitBtn.html();
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Adding Customer...');
            
            // Prepare form data
            const formData = new FormData($('#addCustomerForm')[0]);
            
            // AJAX request
            $.ajax({
                url: 'save_customer.php', // Create this new file for AJAX handling
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
                        showSuccessNotification(response.message || 'Customer added successfully!');
                        showSuccessModal(response.message || 'Customer has been successfully added to the system.');
                    } else {
                        if (response.errors) {
                            // Show field-specific errors
                            showFieldErrors(response.errors);
                        }
                        showErrorNotification(response.message || 'Failed to add customer. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while adding the customer.';
                    
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
            $('#addCustomerForm')[0].reset();
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

        // Validation functions
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
                return { valid: false, message: 'Email address is required' };
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

        // Success modal functions
        function showSuccessModal(message) {
            const $modal = $('#successModal');
            const $messageElement = $('#successMessage');
            
            if (message) {
                $messageElement.text(message);
            }
            
            $modal.show();
            $('body').css('overflow', 'hidden');
        }

        function hideSuccessModal() {
            const $modal = $('#successModal');
            $modal.hide();
            $('body').css('overflow', 'auto');
        }

        function addAnotherCustomer() {
            hideSuccessModal();
            resetForm();
        }

        function viewAllCustomers() {
            hideSuccessModal();
            window.location.href = 'customer_list.php';
        }

        // Close modal when clicking outside or pressing Escape
        $(window).on('click', function(event) {
            if (event.target === $('#successModal')[0]) {
                hideSuccessModal();
            }
        });

        $(document).on('keydown', function(event) {
            if (event.key === 'Escape') {
                hideSuccessModal();
            }
        });

        $(document).ready(function() {
    $('#city_input').on('input', function() {
        const query = $(this).val().trim();
        if (query.length < 1) {
            $('#city-suggestions').hide();
            $('#city_id').val('');
            return;
        }

        $.ajax({
            url: 'fetch_cities.php',
            type: 'GET',
            data: { query: query },
            dataType: 'json',
            success: function(data) {
                let html = '';
                if (data.length > 0) {
                    data.forEach(city => {
                        html += `<div class="suggestion-item" data-id="${city.city_id}" data-name="${city.city_name}">${city.city_name}</div>`;
                    });
                } else {
                    html = '<div class="suggestion-item disabled">No cities found</div>';
                }
                $('#city-suggestions').html(html).show();
            }
        });
    });

    $(document).on('click', '.suggestion-item', function() {
        if (!$(this).hasClass('disabled')) {
            const name = $(this).data('name');
            const id = $(this).data('id');
            $('#city_input').val(name);
            $('#city_id').val(id);
            $('#city-suggestions').hide();
        }
    });

    $(document).click(function(e) {
        if (!$(e.target).closest('#city_input, #city-suggestions').length) {
            $('#city-suggestions').hide();
        }
    });
});

    </script>
</body>
</html>