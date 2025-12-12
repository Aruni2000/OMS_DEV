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

        /* Styles for city dropdown */
        .city-suggestions-dropdown {
            position: absolute;
            z-index: 1000;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: .25rem;
            max-height: 200px;
            overflow-y: auto;
            width: calc(100% - 30px); /* Adjust based on form-group padding */
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
                                        <i class="fas fa-envelope"></i> Email Address <span class="text-muted small fw-normal"> (Optional)</span>
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email"
                                        placeholder="customer@example.com">
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
                                    <label for="phone2" class="form-label">
                                        <i class="fas fa-phone"></i> Secondary Phone Number <span class="text-muted small fw-normal"> (Optional)</span>
                                    </label>
                                    <input type="tel" class="form-control" id="phone2" name="phone2"
                                        placeholder="0711234567">
                                    <div class="error-feedback" id="phone2-error"></div>
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
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="city_name_input" class="form-label">
                                        <i class="fas fa-city"></i> City<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="city_name_input" name="city_name_input"
                                        placeholder="Type to select city" required autocomplete="off">
                                    <input type="hidden" id="city_id" name="city_id" value="">
                                    <div class="city-suggestions-dropdown" id="city-suggestions-dropdown"></div>
                                    <div class="error-feedback" id="city_id-error"></div>
                                </div>
                            </div>
                        </div>
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

            // City autocomplete functionality
            let debounceTimer;
            $('#city_name_input').on('input', function() {
                const $this = $(this);
                const query = $this.val();
                
                // Clear previously selected city_id if user types again
                $('#city_id').val('');
                clearValidation('city_name_input'); // Clear error for city name input
                
                clearTimeout(debounceTimer);
                if (query.length < 2) {
                    $('#city-suggestions-dropdown').empty().hide();
                    return;
                }

                debounceTimer = setTimeout(() => {
                    $.ajax({
                        url: 'get_cities.php',
                        type: 'GET',
                        data: { term: query },
                        dataType: 'json',
                        success: function(cities) {
                            const $dropdown = $('#city-suggestions-dropdown');
                            $dropdown.empty();
                            if (cities.length > 0) {
                                cities.forEach(city => {
                                    $dropdown.append(
                                        `<div class="city-suggestion-item" data-city-id="${city.id}">${city.name}</div>`
                                    );
                                });
                                $dropdown.show();
                            } else {
                                $dropdown.hide();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error fetching cities:', error);
                            $('#city-suggestions-dropdown').empty().hide();
                        }
                    });
                }, 300); // 300ms debounce
            });

            // Handle click on suggestion item
            $(document).on('click', '.city-suggestion-item', function() {
                const $this = $(this);
                const cityId = $this.data('city-id');
                const cityName = $this.text();

                $('#city_name_input').val(cityName);
                $('#city_id').val(cityId);
                $('#city-suggestions-dropdown').empty().hide();
                showSuccess('city_name_input'); // Show success for the input field
                showSuccess('city_id'); // Show success for the hidden ID field for validation
            });

            // Hide suggestions when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.customer-form-group').is($('#city_name_input').parent())) {
                    $('#city-suggestions-dropdown').empty().hide();
                }
            });

            // Clear selected city if input is cleared manually
            $('#city_name_input').on('blur', function() {
                if ($(this).val().trim() === '' && $('#city_id').val() !== '') {
                    $('#city_id').val('');
                    showError('city_id', 'City selection is required');
                } else if ($(this).val().trim() !== '' && $('#city_id').val() === '') {
                    // If user typed something but didn't select, show error
                    showError('city_id', 'Please select a city from the suggestions');
                }
            });
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
                // Map server error fields to client-side IDs
                if (field === 'city_id') {
                    showError('city_name_input', message); // Show error on the visible input field
                } else {
                    showError(field, message);
                }
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
            $('#city_id').val(''); // Clear hidden city ID
            $('#city_name_input').val(''); // Clear visible city name input
            $('#city-suggestions-dropdown').empty().hide(); // Hide suggestions
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

            // Auto-format secondary phone number
            $('#phone2').on('input', function() {
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

            $('#phone2').on('blur', function() {
                if ($(this).val().trim() !== '') {
                    const validation = validatePhone2($(this).val());
                    if (!validation.valid) {
                        showError('phone2', validation.message);
                    } else {
                        showSuccess('phone2');
                    }
                } else {
                    clearValidation('phone2');
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
            
            $('#city_name_input').on('blur', function() {
                const validation = validateCitySelection($('#city_id').val());
                if (!validation.valid) {
                    showError('city_name_input', validation.message);
                } else {
                    showSuccess('city_name_input');
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

        function validatePhone2(phone2) {
            if (phone2.trim() === '') {
                return { valid: true, message: '' }; // Optional, so empty is valid
            }
            if (phone2.length > 20) {
                return { valid: false, message: 'Secondary phone number is too long (maximum 20 characters)' };
            }
            const cleanPhone = phone2.replace(/\s+/g, '');
            const digitsOnly = cleanPhone.replace(/[^0-9]/g, '');
            
            if (digitsOnly.length !== 10) {
                return { valid: false, message: 'Secondary phone number must be exactly 10 digits' };
            }
            
            const localPattern = /^0[1-9][0-9]{8}$/;
            const internationalPattern = /^(\+94|94)[1-9][0-9]{8}$/;
            
            if (!localPattern.test(cleanPhone) && !internationalPattern.test(cleanPhone)) {
                return { valid: false, message: 'Please enter a valid Sri Lankan phone number (e.g., 0711234567)' };
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

        function validateCitySelection(cityId) {
            if (cityId === '' || cityId === null) {
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
            const phone2 = $('#phone2').val();
            const addressLine1 = $('#address_line1').val();
            const cityId = $('#city_id').val(); // Get value from hidden input
            const cityNameInput = $('#city_name_input').val(); // Get value from visible input
            
            // Validate required fields
            const validations = [
                { field: 'name', validator: validateName, value: name },
                { field: 'email', validator: validateEmail, value: email },
                { field: 'phone', validator: validatePhone, value: phone },
                { field: 'address_line1', validator: validateAddressLine1, value: addressLine1 },
                // Use city_id for actual validation, but show error on city_name_input
                { field: 'city_id', validator: validateCitySelection, value: cityId, errorField: 'city_name_input' }
            ];
            
            validations.forEach(function(validation) {
                const result = validation.validator(validation.value);
                if (!result.valid) {
                    showError(validation.errorField || validation.field, result.message);
                    isValid = false;
                } else {
                    showSuccess(validation.errorField || validation.field);
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

            // Validate secondary phone if not empty
            if (phone2.trim() !== '') {
                const phone2Validation = validatePhone2(phone2);
                if (!phone2Validation.valid) {
                    showError('phone2', phone2Validation.message);
                    isValid = false;
                } else {
                    showSuccess('phone2');
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
    </script>
</body>
</html>