<?php
// Start session at the very beginning
session_start();

// Include the database connection file FIRST
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Get user's role from database
$user_id = $_SESSION['user_id'];
$role_check_sql = "SELECT u.role_id, r.name as role_name 
                   FROM users u 
                   LEFT JOIN roles r ON u.role_id = r.id 
                   WHERE u.id = ? AND u.status = 'active'";
$role_stmt = $conn->prepare($role_check_sql);
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();

if ($role_result->num_rows === 0) {
    // User not found or inactive
    session_destroy();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

$user_role = $role_result->fetch_assoc();

// Check if user is admin (role_id = 1)
if ($user_role['role_id'] != 1) {
    // User is not admin, redirect to dashboard
    header("Location: /OMS/dist/dashboard/index.php");
    exit();
}

// Function to generate CSRF token
// function generateCSRFToken() {
//     if (!isset($_SESSION['csrf_token'])) {
//         $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
//     }
//     return $_SESSION['csrf_token'];
// }

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - Add New User</title>

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
    border-left: 4px solid; /* Add this line */
}

/* Enhanced Bootstrap alert colors with gradients and left border */
.alert-success {
    color: #0f5132;
    background: linear-gradient(135deg, #f8f9fa 0%, #d1e7dd 100%);
    /* border-color: #badbcc; */
    border-left-color: #28a745;
}

.alert-danger {
    color: #842029;
    background: linear-gradient(135deg, #f8f9fa 0%, #f8d7da 100%);
    /* border-color: #f5c2c7; */
    border-left-color: #dc3545;
}

.alert-warning {
    color: #664d03;
    background: linear-gradient(135deg, #f8f9fa 0%, #fff3cd 100%);
    /* border-color: #ffecb5; */
    border-left-color: #ffc107;
}

/* Add info style if you need it */
.alert-info {
    color: #0c5460;
    background: linear-gradient(135deg, #f8f9fa 0%, #d1ecf1 100%);
    /* border-color: #bee5eb; */
    border-left-color: #17a2b8;
}

/* Ensure close button is properly styled */
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
            <p>Please wait while we add the user</p>
        </div>
    </div>

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Add New User</h5>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="main-container">
                <!-- Add User Form -->
                <form method="POST" id="addUserForm" class="customer-form" novalidate>
                    <!-- CSRF Token -->
                    <!-- <input type="hidden" name="csrf_token" value="<?php //echo generateCSRFToken(); ?>">
                     -->
                    <!-- User Details Section -->
                    <div class="form-section">
                        <div class="section-content">
                            <!-- First Row: Full Name and Email -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="full_name" class="form-label">
                                        <i class="fas fa-user"></i> Full Name<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="full_name" name="full_name"
                                        placeholder="Enter user's full name" required>
                                    <div class="error-feedback" id="full_name-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email Address<span class="required">*</span>
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email"
                                        placeholder="user@example.com" required>
                                    <div class="error-feedback" id="email-error"></div>
                                    <div class="email-suggestions" id="email-suggestions"></div>
                                </div>
                            </div>

                            <!-- Second Row: Password and Mobile -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock"></i> Password<span class="required">*</span>
                                    </label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="password" name="password"
                                            placeholder="Enter secure password" required>
                                        <button type="button" class="password-toggle" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="error-feedback" id="password-error"></div>
                                    <div class="password-strength" id="password-strength"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="mobile" class="form-label">
                                        <i class="fas fa-mobile-alt"></i> Mobile Number<span class="required">*</span>
                                    </label>
                                    <input type="tel" class="form-control" id="mobile" name="mobile"
                                        placeholder="0771234567" required>
                                    <div class="error-feedback" id="mobile-error"></div>
                                    <div class="phone-hint">Enter 10-digit Sri Lankan mobile number</div>
                                </div>
                            </div>

                            <!-- Third Row: NIC and Status -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="nic" class="form-label">
                                        <i class="fas fa-id-card"></i> NIC Number<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="nic" name="nic"
                                        placeholder="123456789V or 123456789012" required>
                                    <div class="error-feedback" id="nic-error"></div>
                                    <div class="nic-hint">Enter Sri Lankan NIC (9 digits + V or 12 digits)</div>
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

                            <!-- Fourth Row: Address and Role -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="address" class="form-label">
                                        <i class="fas fa-map-marker-alt"></i> Address<span class="required">*</span>
                                    </label>
                                    <textarea class="form-control" id="address" name="address" rows="3"
                                        placeholder="Enter complete address" required></textarea>
                                    <div class="error-feedback" id="address-error"></div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="role" class="form-label">
                                        <i class="fas fa-user-tag"></i> Role<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="role" name="role" required>
                                        <option value="">Select Role...</option>
                                        <option value="admin">Admin</option>
                                        <option value="moderator">Moderator</option>
                                        <option value="user">User</option>
                                    </select>
                                    <div class="error-feedback" id="role-error"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-user-plus"></i> Add User
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
            $('#addUserForm').on('submit', function(e) {
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
            $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Adding User...');
            
            // Prepare form data
            const formData = new FormData($('#addUserForm')[0]);
            
            // AJAX request
            $.ajax({
                url: 'save_user.php', // Create this new file for AJAX handling
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
                        showSuccessNotification(response.message || 'User added successfully!');
                        showSuccessModal(response.message || 'User has been successfully added to the system.');
                        
                        // Optional: Reset form after success
                        // resetForm();
                    } else {
                        if (response.errors) {
                            // Show field-specific errors
                            showFieldErrors(response.errors);
                        }
                        
                        showErrorNotification(response.message || 'Failed to add user. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    $submitBtn.prop('disabled', false).html(originalText);
                    
                    let errorMessage = 'An error occurred while adding the user.';
                    
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
            const iconClass = type === 'success' ? 'fas fa-check-circle' : 
                            type === 'danger' ? 'fas fa-exclamation-circle' : 
                            'fas fa-exclamation-triangle';
            
            const notification = `
                <div class="alert alert-${type} alert-dismissible ajax-notification" id="${notificationId}" role="alert">
                    <i class="${iconClass} me-2"></i>
                    ${message}
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
            $('#addUserForm')[0].reset();
            clearAllValidations();
            $('#password-strength').html('');
            $('#email-suggestions').html('');
            $('#full_name').focus();
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
            $('#full_name').focus();
            
            // Auto-format mobile number
            $('#mobile').on('input', function() {
                let value = this.value.replace(/\D/g, '');
                if (value.length > 10) {
                    value = value.substring(0, 10);
                }
                this.value = value;
            });
            
            // Auto-format NIC
            $('#nic').on('input', function() {
                let value = this.value.toUpperCase().replace(/[^0-9VX]/g, '');
                
                if (value.includes('V') || value.includes('X')) {
                    if (value.length > 10) {
                        value = value.substring(0, 10);
                    }
                } else {
                    if (value.length > 12) {
                        value = value.substring(0, 12);
                    }
                }
                
                this.value = value;
            });
            
            // Email formatting
            $('#email').on('input', function() {
                this.value = this.value.toLowerCase().trim();
                $('#email-suggestions').html('');
            });
            
            // Auto-resize textarea
            $('#address').on('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
            
            // Password toggle
            $('#togglePassword').on('click', function() {
                const $passwordInput = $('#password');
                const $icon = $(this).find('i');
                
                if ($passwordInput.attr('type') === 'password') {
                    $passwordInput.attr('type', 'text');
                    $icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    $passwordInput.attr('type', 'password');
                    $icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
        }
        
        // Setup real-time validation
        function setupRealTimeValidation() {
            $('#full_name').on('blur', function() {
                const validation = validateFullName($(this).val());
                if (!validation.valid) {
                    showError('full_name', validation.message);
                } else {
                    showSuccess('full_name');
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
            
            $('#password').on('input', function() {
                checkPasswordStrength($(this).val());
            }).on('blur', function() {
                const validation = validatePassword($(this).val());
                if (!validation.valid) {
                    showError('password', validation.message);
                } else {
                    showSuccess('password');
                }
            });
            
            $('#mobile').on('blur', function() {
                const validation = validateMobile($(this).val());
                if (!validation.valid) {
                    showError('mobile', validation.message);
                } else {
                    showSuccess('mobile');
                }
            });
            
            $('#nic').on('blur', function() {
                const validation = validateNIC($(this).val());
                if (!validation.valid) {
                    showError('nic', validation.message);
                } else {
                    showSuccess('nic');
                }
            });
            
            $('#address').on('blur', function() {
                const validation = validateAddress($(this).val());
                if (!validation.valid) {
                    showError('address', validation.message);
                } else {
                    showSuccess('address');
                }
            });
            
            $('#role').on('change', function() {
                const validation = validateRole($(this).val());
                if (!validation.valid) {
                    showError('role', validation.message);
                } else {
                    showSuccess('role');
                }
            });
        }
        
        // Setup other event listeners
        function setupEventListeners() {
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
        }

        // Validation functions (same as before)
        function validateFullName(name) {
            if (name.trim() === '') {
                return { valid: false, message: 'Full name is required' };
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

        function validatePassword(password) {
            if (password.trim() === '') {
                return { valid: false, message: 'Password is required' };
            }
            
            return { valid: true, message: '' };
        }

        function validateMobile(mobile) {
            if (mobile.trim() === '') {
                return { valid: false, message: 'Mobile number is required' };
            }
            const cleanMobile = mobile.replace(/\s+/g, '');
            const sriLankanMobileRegex = /^(0|94|\+94)?[1-9][0-9]{8}$/;
            if (!sriLankanMobileRegex.test(cleanMobile)) {
                return { valid: false, message: 'Please enter a valid Sri Lankan mobile number (e.g., 0771234567)' };
            }
            return { valid: true, message: '' };
        }

        function validateNIC(nic) {
            if (nic.trim() === '') {
                return { valid: false, message: 'NIC number is required' };
            }
            const cleanNIC = nic.trim().toUpperCase();
            const oldNICRegex = /^\d{9}[VX]$/;
            const newNICRegex = /^\d{12}$/;
            
            if (!oldNICRegex.test(cleanNIC) && !newNICRegex.test(cleanNIC)) {
                return { valid: false, message: 'Please enter a valid Sri Lankan NIC (e.g., 123456789V or 123456789012)' };
            }
            return { valid: true, message: '' };
        }

        function validateAddress(address) {
            if (address.trim() === '') {
                return { valid: false, message: 'Address is required' };
            }
            if (address.trim().length < 5) {
                return { valid: false, message: 'Address must be at least 5 characters long' };
            }
            if (address.length > 500) {
                return { valid: false, message: 'Address is too long (maximum 500 characters)' };
            }
            return { valid: true, message: '' };
        }

        function validateRole(role) {
            if (role.trim() === '') {
                return { valid: false, message: 'Role selection is required' };
            }
            const validRoles = ['admin', 'moderator', 'user'];
            if (!validRoles.includes(role)) {
                return { valid: false, message: 'Please select a valid role' };
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
            const fullName = $('#full_name').val();
            const email = $('#email').val();
            const password = $('#password').val();
            const mobile = $('#mobile').val();
            const nic = $('#nic').val();
            const address = $('#address').val();
            const role = $('#role').val();
            
            // Validate required fields
            const validations = [
                { field: 'full_name', validator: validateFullName, value: fullName },
                { field: 'email', validator: validateEmail, value: email },
                { field: 'password', validator: validatePassword, value: password },
                { field: 'mobile', validator: validateMobile, value: mobile },
                { field: 'nic', validator: validateNIC, value: nic },
                { field: 'address', validator: validateAddress, value: address },
                { field: 'role', validator: validateRole, value: role }
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

        function addAnotherUser() {
            hideSuccessModal();
            resetForm();
        }

        function viewAllUsers() {
            hideSuccessModal();
            window.location.href = 'users.php';
        }

        function showNotification(message, type) {
    const notificationId = 'notification_' + Date.now();
    // Map your types to Bootstrap alert classes
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
    </script>

</body>
</html>