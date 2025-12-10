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

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - Add Product</title>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/products.css" id="main-style-link" />
 
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

        /* Enhanced Bootstrap alert colors with gradients and left border */
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

        .alert-info {
            color: #0c5460;
            background: linear-gradient(135deg, #f8f9fa 0%, #d1ecf1 100%);
            border-left-color: #17a2b8;
        }

        .alert .btn-close {
            padding: 0.5rem 0.5rem;
            position: absolute;
            top: 0;
            right: 0;
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
            <p>Please wait while we add the product</p>
        </div>
    </div>

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Add New Product</h5>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="main-container">
                <form method="POST" id="addProductForm" class="product-form" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- Product Details Section -->
                    <div class="form-section">
                        <div class="section-content">
                            <!-- First Row: Name and Status -->
                            <div class="form-row">
                                <div class="product-form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-box"></i> Product Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name"
                                        placeholder="Enter product name" required maxlength="255">
                                    <div class="error-feedback" id="name-error"></div>
                                </div>

                                <div class="product-form-group">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on"></i> Status<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                    <div class="error-feedback" id="status-error"></div>
                                </div>
                            </div>

                            <!-- Second Row: Price and Product Code -->
                            <div class="form-row">
                                <div class="product-form-group">
                                    <label for="lkr_price" class="form-label">
                                        <i class="fas fa-rupee-sign"></i> Price (LKR)<span class="required">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="lkr_price" name="lkr_price"
                                        placeholder="0.00" required min="0" max="99999999.99" step="0.01">
                                    <div class="error-feedback" id="lkr_price-error"></div>
                                    <div class="price-hint">Enter price in Sri Lankan Rupees (e.g., 1500.00)</div>
                                </div>

                                <div class="product-form-group">
                                    <label for="product_code" class="form-label">
                                        <i class="fas fa-barcode"></i> Product Code<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="product_code" name="product_code"
                                        placeholder="Enter product code" required maxlength="50">
                                    <div class="error-feedback" id="product_code-error"></div>
                                    <div class="code-hint">Unique identifier for the product</div>
                                </div>
                            </div>

                            <!-- Third Row: Description -->
                           <div class="form-row">
                            <div class="product-form-group full-width">
                                <label for="description" class="form-label">
                                    <i class="fas fa-align-left"></i> Description <span class="required">*</span>
                                </label>
                                <textarea class="form-control" id="description" name="description" rows="4"
                                    placeholder="Enter product description" required></textarea>
                                <div class="error-feedback" id="description-error"></div>
                                <div class="char-counter">
                                    <span id="desc-char-count">0</span> characters
                                </div>
                            </div>
                        </div>

                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-plus"></i> Add Product
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

    <!-- jQuery (make sure this is loaded before your custom script) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize form
            initializeForm();
            
            // AJAX Form submission
            $('#addProductForm').on('submit', function(e) {
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
            
            // Other event listeners
            setupEventListeners();
        });

        // AJAX Form Submission Function
        function submitFormAjax() {
            // Show loading overlay
            showLoading();
            
            // Disable submit button
            const $submitBtn = $('#submitBtn');
            const originalText = $submitBtn.html();
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Adding Product...');
            
            // Prepare form data
            const formData = new FormData($('#addProductForm')[0]);
            
            // AJAX request
            $.ajax({
                url: 'save_product.php', // Your existing save_product.php file
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
                        showSuccessNotification(response.message || 'Product added successfully!');
                        
                        // Reset form after success
                        resetForm();
                    } else {
                        if (response.errors) {
                            // Show field-specific errors
                            showFieldErrors(response.errors);
                        }
                        
                        showErrorNotification(response.message || 'Failed to add product. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while adding the product.';
                    
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
                    console.error('AJAX Error:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error
                    });
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
        
        function showWarningNotification(message) {
            showNotification(message, 'warning');
        }
        
        function showNotification(message, type) {
            const notificationId = 'notification_' + Date.now();
            const alertClasses = {
                'success': 'alert-success',
                'danger': 'alert-danger',
                'warning': 'alert-warning'
            };
            
            const iconClass = type === 'success' ? 'fas fa-check-circle' : 
                            type === 'danger' ? 'fas fa-exclamation-circle' : 
                            'fas fa-exclamation-triangle';
            
            const notification = `
                <div class="alert ${alertClasses[type]} alert-dismissible fade show ajax-notification" id="${notificationId}" role="alert">
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
            $('#addProductForm')[0].reset();
            clearAllValidations();
            updateCharCount();
            $('#name').focus();
        }
        
        // Clear all validations
        function clearAllValidations() {
            $('.form-control, .form-select').removeClass('is-valid is-invalid field-error field-success');
            $('.error-feedback').hide().text('');
        }
        
        // Scroll to first error
        function scrollToFirstError() {
            const $firstError = $('.is-invalid, .field-error').first();
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
            updateCharCount();
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
            
            $('#lkr_price').on('blur', function() {
                const validation = validatePrice($(this).val());
                if (!validation.valid) {
                    showError('lkr_price', validation.message);
                } else {
                    showSuccess('lkr_price');
                }
            });
            
            $('#product_code').on('blur', function() {
                const validation = validateProductCode($(this).val());
                if (!validation.valid) {
                    showError('product_code', validation.message);
                } else {
                    showSuccess('product_code');
                }
            });
            
            $('#description').on('blur', function() {
                const validation = validateDescription($(this).val());
                if (!validation.valid) {
                    showError('description', validation.message);
                } else if ($(this).val().trim() !== '') {
                    showSuccess('description');
                } else {
                    clearValidation('description');
                }
            });
        }
        
        // Setup other event listeners
        function setupEventListeners() {
            // Character counter for description
            $('#description').on('input', function() {
                updateCharCount();
                if ($(this).hasClass('is-invalid')) {
                    clearValidation('description');
                }
            });

            // Prevent form submission on Enter key in input fields
            $('input:not([type="submit"])').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const $inputs = $('input, select, textarea');
                    const currentIndex = $inputs.index(this);
                    if (currentIndex < $inputs.length - 1) {
                        $inputs.eq(currentIndex + 1).focus();
                    }
                }
            });
        }

        // Character counter for description
        function updateCharCount() {
            const textarea = $('#description');
            const counter = $('#desc-char-count');
            if (textarea.length && counter.length) {
                counter.text(textarea.val().length);
            }
        }

        // Validation functions
        function validateName(name) {
            if (name.trim() === '') {
                return { valid: false, message: 'Product name is required' };
            }
            if (name.trim().length < 2) {
                return { valid: false, message: 'Product name must be at least 2 characters long' };
            }
            if (name.length > 255) {
                return { valid: false, message: 'Product name is too long (maximum 255 characters)' };
            }
            return { valid: true, message: '' };
        }

        function validatePrice(price) {
            if (price.trim() === '' || isNaN(price)) {
                return { valid: false, message: 'Price is required and must be a valid number' };
            }
            
            const numPrice = parseFloat(price);
            
            if (numPrice < 0) {
                return { valid: false, message: 'Price cannot be negative' };
            }
            
            if (numPrice > 99999999.99) {
                return { valid: false, message: 'Price is too high (maximum 99,999,999.99)' };
            }
            
            // Check for too many decimal places
            if (price.includes('.') && price.split('.')[1].length > 2) {
                return { valid: false, message: 'Price can have maximum 2 decimal places' };
            }
            
            return { valid: true, message: '' };
        }

        function validateProductCode(code) {
            if (code.trim() === '') {
                return { valid: false, message: 'Product code is required' };
            }
            
            if (code.trim().length < 2) {
                return { valid: false, message: 'Product code must be at least 2 characters long' };
            }
            
            if (code.length > 50) {
                return { valid: false, message: 'Product code is too long (maximum 50 characters)' };
            }
            
            // Allow alphanumeric, hyphens, underscores
            if (!/^[a-zA-Z0-9\-_]+$/.test(code.trim())) {
                return { valid: false, message: 'Product code can only contain letters, numbers, hyphens, and underscores' };
            }
            
            return { valid: true, message: '' };
        }

       function validateDescription(description) {
            if (description.trim() === '') {
                return { valid: false, message: 'Description is required' };
            }

            if (description.length < 5) {
                return { valid: false, message: 'Description must be at least 5 characters long' };
            }

            if (description.length > 65535) {
                return { valid: false, message: 'Description is too long (maximum 65,535 characters)' };
            }

            return { valid: true, message: '' };
        }

        // Show/hide error functions
        function showError(fieldId, message) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.addClass('is-invalid field-error').removeClass('is-valid field-success');
                $errorDiv.text(message).show();
            }
        }

        function showSuccess(fieldId) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.addClass('is-valid field-success').removeClass('is-invalid field-error');
                $errorDiv.hide();
            }
        }

        function clearValidation(fieldId) {
            const $field = $('#' + fieldId);
            const $errorDiv = $('#' + fieldId + '-error');
            
            if ($field.length && $errorDiv.length) {
                $field.removeClass('is-valid is-invalid field-error field-success');
                $errorDiv.hide();
            }
        }

        // Form validation
        function validateForm() {
            let isValid = true;
            
            // Get all field values
            const name = $('#name').val();
            const price = $('#lkr_price').val();
            const productCode = $('#product_code').val();
            const description = $('#description').val();
            
            // Validate required fields
            const validations = [
                { field: 'name', validator: validateName, value: name },
                { field: 'lkr_price', validator: validatePrice, value: price },
                { field: 'product_code', validator: validateProductCode, value: productCode },
                { field: 'description', validator: validateDescription, value: description }
            ];
            
            validations.forEach(function(validation) {
                const result = validation.validator(validation.value);
                if (!result.valid) {
                    showError(validation.field, result.message);
                    isValid = false;
                } else if (validation.field === 'description' && validation.value.trim() !== '') {
                    showSuccess(validation.field);
                } else if (validation.field !== 'description') {
                    showSuccess(validation.field);
                }
            });
            
            return isValid;
        }
    </script>
</body>
</html>