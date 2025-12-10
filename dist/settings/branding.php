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
//  Check if user is admin (ID = 1)
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] != 1) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    // Redirect to access denied page or dashboard
    header("Location: /OMS/dist/pages/access_denied.php");
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

// Initialize message variables
$success_message = '';
$error_message = '';

// Check for success/error messages from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid request. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Get form data with proper sanitization
    $company_name = mysqli_real_escape_string($conn, trim($_POST['company_name']));
    $web_name = mysqli_real_escape_string($conn, trim($_POST['web_name']));
    $address = mysqli_real_escape_string($conn, trim($_POST['address']));
    $hotline = mysqli_real_escape_string($conn, trim($_POST['hotline']));
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $delivery_fee = mysqli_real_escape_string($conn, trim($_POST['delivery_fee']));
    
    // Validate required fields
    if (empty($company_name)) {
        $_SESSION['error_message'] = "Company name is required.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error_message'] = "Please enter a valid email address.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    if (!empty($delivery_fee) && (!is_numeric($delivery_fee) || $delivery_fee < 0)) {
        $_SESSION['error_message'] = "Please enter a valid delivery fee.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Logo upload handling
    $logo_url = '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $allowed = array('jpg', 'jpeg', 'png', 'gif');
        $filename = $_FILES['logo']['name'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (in_array(strtolower($ext), $allowed)) {
            // Create uploads directory if it doesn't exist
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $new_name = 'logo_' . time() . '.' . $ext;
            $destination = $upload_dir . $new_name;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $destination)) {
                $logo_url = '/OMS/dist/uploads/' . $new_name;
            } else {
                $_SESSION['error_message'] = "Error uploading logo file.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } else {
            $_SESSION['error_message'] = "Invalid logo file type. Please upload JPG, JPEG, PNG, or GIF files only.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    // Favicon upload handling
    $fav_icon_url = '';
    if (isset($_FILES['fav_icon']) && $_FILES['fav_icon']['error'] == 0) {
        $allowed = array('jpg', 'jpeg', 'png', 'ico');
        $filename = $_FILES['fav_icon']['name'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (in_array(strtolower($ext), $allowed)) {
            // Create uploads directory if it doesn't exist
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $new_name = 'favicon_' . time() . '.' . $ext;
            $destination = $upload_dir . $new_name;
            
            if (move_uploaded_file($_FILES['fav_icon']['tmp_name'], $destination)) {
                $fav_icon_url = '/OMS/dist/uploads/' . $new_name;
            } else {
                $_SESSION['error_message'] = "Error uploading favicon file.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } else {
            $_SESSION['error_message'] = "Invalid favicon file type. Please upload JPG, JPEG, PNG, or ICO files only.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    // Check if we need to update or insert
    $check_query = "SELECT * FROM branding LIMIT 1";
    $check_result = $conn->query($check_query);
    
    if ($check_result && $check_result->num_rows > 0) {
        // Update existing record
        $row = $check_result->fetch_assoc();
        $branding_id = $row['branding_id'];
        
        $update_sql = "UPDATE branding SET 
                      company_name = ?, 
                      web_name = ?, 
                      address = ?, 
                      hotline = ?, 
                      email = ?, 
                      delivery_fee = ?";
        
        $params = [$company_name, $web_name, $address, $hotline, $email, $delivery_fee];
        $types = "ssssss";
        
        // Only update logo if a new one was uploaded
        if (!empty($logo_url)) {
            $update_sql .= ", logo_url = ?";
            $params[] = $logo_url;
            $types .= "s";
        }
        
        // Only update favicon if a new one was uploaded
        if (!empty($fav_icon_url)) {
            $update_sql .= ", fav_icon_url = ?";
            $params[] = $fav_icon_url;
            $types .= "s";
        }
        
        $update_sql .= " WHERE branding_id = ?";
        $params[] = $branding_id;
        $types .= "i";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Branding settings updated successfully!";
        } else {
            $_SESSION['error_message'] = "Error updating branding settings: " . $conn->error;
        }
        $stmt->close();
    } else {
        // Insert new record
        $insert_sql = "INSERT INTO branding (company_name, web_name, address, hotline, email, logo_url, fav_icon_url, delivery_fee, active) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
        
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("ssssssss", $company_name, $web_name, $address, $hotline, $email, $logo_url, $fav_icon_url, $delivery_fee);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Branding settings saved successfully!";
        } else {
            $_SESSION['error_message'] = "Error saving branding settings: " . $conn->error;
        }
        $stmt->close();
    }
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch current branding settings
$branding = array(
    'company_name' => '',
    'web_name' => '',
    'address' => '',
    'hotline' => '',
    'email' => '',
    'logo_url' => '',
    'fav_icon_url' => '',
    'delivery_fee' => '0.00'
);

$query = "SELECT * FROM branding LIMIT 1";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $branding = $result->fetch_assoc();
}

// Include navbar and sidebar
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Branding Settings - Order Management Admin Portal</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="icon" href="<?php echo !empty($branding['fav_icon_url']) ? $branding['fav_icon_url'] : '/OMS/dist/assets/images/favicon.ico'; ?>" type="image/x-icon">
    
    <style>
        .form-section {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        
        .form-section h3 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-section h3 i {
            color: #007bff;
            font-size: 1.2em;
        }
        
        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-label i {
            color: #6c757d;
            width: 16px;
        }
        
        .form-label .required {
            color: #dc3545;
            font-weight: bold;
        }
        
        .form-control, .form-select {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px 16px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }
        
        .logo-preview, .favicon-preview {
            max-width: 150px;
            max-height: 150px;
            margin: 15px 0;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 10px;
            background: #f8f9fa;
        }
        
        .favicon-preview {
            max-width: 64px;
            max-height: 64px;
        }
        
        .navbar-logo-preview {
            max-height: 40px;
            margin: 15px 0;
            border: 2px solid #343a40;
            border-radius: 6px;
            padding: 8px;
            background: #343a40;
        }
        
        .preview-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 15px;
        }
        
        .preview-section h5 {
            color: #6c757d;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .submit-container {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .btn-primary {
            background: #007bff;
            border: none;
            padding: 12px 30px;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
            padding: 12px 30px;
            font-weight: 500;
            border-radius: 6px;
            margin-right: 15px;
        }
        
        .alert {
            border: none;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .alert-info {
            background: #cce7ff;
            color: #004085;
            border-left: 4px solid #007bff;
        }
        
        .file-upload-hint {
            font-size: 13px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .input-group-text {
            background: #e9ecef;
            border: 1px solid #dee2e6;
            color: #495057;
            font-weight: 500;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .form-section {
                padding: 20px;
            }
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
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Branding Settings</h5>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="main-container">
                <!-- Success Alert -->
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Error Alert -->
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Branding Settings Form -->
                <form method="POST" enctype="multipart/form-data" id="brandingForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- Company Settings Section -->
                    <div class="form-section">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="company_name" class="form-label">
                                    <i class="fas fa-building"></i>
                                    Company Name<span class="required">*</span>
                                </label>
                                <input type="text" class="form-control" id="company_name" name="company_name" 
                                       value="<?php echo htmlspecialchars($branding['company_name']); ?>" 
                                       placeholder="Enter company name" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="web_name" class="form-label">
                                    <i class="fas fa-globe"></i>
                                    Website Name
                                </label>
                                <input type="text" class="form-control" id="web_name" name="web_name" 
                                       value="<?php echo htmlspecialchars($branding['web_name']); ?>" 
                                       placeholder="Enter website name">
                            </div>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label for="address" class="form-label">
                                <i class="fas fa-map-marker-alt"></i>
                                Company Address
                            </label>
                            <textarea class="form-control" id="address" name="address" rows="3" 
                                      placeholder="Enter complete company address"><?php echo htmlspecialchars($branding['address']); ?></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="hotline" class="form-label">
                                    <i class="fas fa-phone"></i>
                                    Hotline Number
                                </label>
                                <input type="text" class="form-control" id="hotline" name="hotline" 
                                       value="<?php echo htmlspecialchars($branding['hotline']); ?>" 
                                       placeholder="Enter hotline number">
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="form-label">
                                    <i class="fas fa-envelope"></i>
                                    Email Address
                                </label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($branding['email']); ?>" 
                                       placeholder="Enter email address">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="delivery_fee" class="form-label">
                                <i class="fas fa-truck"></i>
                                Delivery Fee
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">Rs</span>
                                <input type="number" class="form-control" id="delivery_fee" name="delivery_fee" 
                                       step="0.01" min="0" value="<?php echo htmlspecialchars($branding['delivery_fee']); ?>" 
                                       placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Logo Settings Section -->
                    <div class="form-section">

                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="logo" class="form-label">
                                    <i class="fas fa-image"></i>
                                    Main Logo (Header)
                                </label>
                                <input type="file" class="form-control" id="logo" name="logo" accept=".jpg,.jpeg,.png,.gif">
                                <div class="file-upload-hint">
                                    <i class="fas fa-info-circle"></i>
                                    Recommended size: 150x36 pixels (JPG, PNG, GIF)
                                </div>
                                
                                <div class="preview-section">
                                    <h5>Current Logo:</h5>
                                    <?php if (!empty($branding['logo_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($branding['logo_url']); ?>" 
                                             alt="Company Logo" class="logo-preview">
                                        <div class="mt-3">
                                            <h5>Navbar Preview:</h5>
                                            <div class="navbar-logo-preview-container">
                                                <img src="<?php echo htmlspecialchars($branding['logo_url']); ?>" 
                                                     alt="Company Logo" class="navbar-logo-preview">
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <img src="/OMS/dist/assets/images/default-logo.png" 
                                             alt="Default Logo" class="logo-preview">
                                        <div class="mt-3">
                                            <h5>Navbar Preview:</h5>
                                            <div class="navbar-logo-preview-container">
                                                <img src="/OMS/dist/assets/images/default-logo.png" 
                                                     alt="Default Logo" class="navbar-logo-preview">
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="fav_icon" class="form-label">
                                    <i class="fas fa-star"></i>
                                    Favicon
                                </label>
                                <input type="file" class="form-control" id="fav_icon" name="fav_icon" accept=".jpg,.jpeg,.png,.ico">
                                <div class="file-upload-hint">
                                    <i class="fas fa-info-circle"></i>
                                    Recommended size: 32x32 pixels (ICO, PNG, JPG)
                                </div>
                                
                                <div class="preview-section">
                                    <h5>Current Favicon:</h5>
                                    <?php if (!empty($branding['fav_icon_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($branding['fav_icon_url']); ?>" 
                                             alt="Favicon" class="favicon-preview">
                                    <?php else: ?>
                                        <img src="/OMS/dist/assets/images/favicon.ico" 
                                             alt="Default Favicon" class="favicon-preview">
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            After updating the logo or favicon, you may need to refresh your browser or clear your cache to see the changes on all pages.
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="button" class="btn btn-secondary" onclick="resetForm()">
                            <i class="fas fa-undo"></i> Reset Form
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Save Branding Settings
                        </button>
                    </div>
                </form>
            </div>
            <!-- [ Main Content ] end -->
        </div>
    </div>

    <!-- FOOTER -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <!-- END FOOTER -->

    <!-- SCRIPTS -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    <!-- END SCRIPTS -->

    <script>
        // Form validation
        function validateForm() {
            let isValid = true;
            const companyName = document.getElementById('company_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const deliveryFee = document.getElementById('delivery_fee').value.trim();
            
            // Clear previous validation styles
            document.querySelectorAll('.form-control').forEach(el => {
                el.classList.remove('is-invalid', 'is-valid');
            });
            
            // Validate company name
            if (companyName === '') {
                showError('company_name', 'Company name is required');
                isValid = false;
            } else {
                showSuccess('company_name');
            }
            
            // Validate email if provided
            if (email !== '' && !isValidEmail(email)) {
                showError('email', 'Please enter a valid email address');
                isValid = false;
            } else if (email !== '') {
                showSuccess('email');
            }
            
            // Validate delivery fee if provided
            if (deliveryFee !== '' && (isNaN(deliveryFee) || parseFloat(deliveryFee) < 0)) {
                showError('delivery_fee', 'Please enter a valid delivery fee');
                isValid = false;
            } else if (deliveryFee !== '') {
                showSuccess('delivery_fee');
            }
            
            return isValid;
        }
        
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }
        
        function showError(fieldId, message) {
            const field = document.getElementById(fieldId);
            field.classList.add('is-invalid');
            field.classList.remove('is-valid');
            
            // Remove existing error message
            const existingError = field.parentNode.querySelector('.invalid-feedback');
            if (existingError) {
                existingError.remove();
            }
            
            // Add new error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'invalid-feedback';
            errorDiv.textContent = message;
            field.parentNode.appendChild(errorDiv);
        }
        
        function showSuccess(fieldId) {
            const field = document.getElementById(fieldId);
            field.classList.add('is-valid');
            field.classList.remove('is-invalid');
            
            // Remove error message
            const existingError = field.parentNode.querySelector('.invalid-feedback');
            if (existingError) {
                existingError.remove();
            }
        }
        
        // Reset form function
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All unsaved changes will be lost.')) {
                document.getElementById('brandingForm').reset();
                document.querySelectorAll('.form-control').forEach(el => {
                    el.classList.remove('is-invalid', 'is-valid');
                });
                document.querySelectorAll('.invalid-feedback').forEach(el => {
                    el.remove();
                });
            }
        }
        
        // Form submission handler
        document.getElementById('brandingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (validateForm()) {
                // Show loading state
                const submitBtn = document.getElementById('submitBtn');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                submitBtn.disabled = true;
                
                // Submit the form
                this.submit();
            } else {
                // Scroll to first error
                const firstError = document.querySelector('.is-invalid');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }
            }
        });
        
        // Real-time validation
        document.getElementById('company_name').addEventListener('blur', function() {
            if (this.value.trim() === '') {
                showError('company_name', 'Company name is required');
            } else {
                showSuccess('company_name');
            }
        });
        
        document.getElementById('email').addEventListener('blur', function() {
            if (this.value.trim() !== '' && !isValidEmail(this.value.trim())) {
                showError('email', 'Please enter a valid email address');
            } else if (this.value.trim() !== '') {
                showSuccess('email');
            }
        });
        
        document.getElementById('delivery_fee').addEventListener('blur', function() {
            const value = this.value.trim();
            if (value !== '' && (isNaN(value) || parseFloat(value) < 0)) {
                showError('delivery_fee', 'Please enter a valid delivery fee');
            } else if (value !== '') {
                showSuccess('delivery_fee');
            }
        });
        
        // File upload preview functionality
        document.getElementById('logo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const logoPreview = document.querySelector('.logo-preview');
                    const navbarLogoPreview = document.querySelector('.navbar-logo-preview');
                    
                    logoPreview.src = e.target.result;
                    logoPreview.alt = 'New Logo Preview';
                    
                    navbarLogoPreview.src = e.target.result;
                    navbarLogoPreview.alt = 'New Logo Preview';
                };
                reader.readAsDataURL(file);
            }
        });
        
        document.getElementById('fav_icon').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const faviconPreview = document.querySelector('.favicon-preview');
                    faviconPreview.src = e.target.result;
                    faviconPreview.alt = 'New Favicon Preview';
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    if (alert.classList.contains('show')) {
                        alert.classList.remove('show');
                        setTimeout(function() {
                            alert.remove();
                        }, 150);
                    }
                }, 5000);
            });
        });
        
        // Prevent form submission on Enter key in input fields (except textarea)
        document.querySelectorAll('input[type="text"], input[type="email"], input[type="number"]').forEach(function(input) {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });
        });
        
        // Format delivery fee input
        document.getElementById('delivery_fee').addEventListener('input', function() {
            let value = this.value;
            
            // Remove any non-digit characters except decimal point
            value = value.replace(/[^0-9.]/g, '');
            
            // Ensure only one decimal point
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
            
            // Limit to 2 decimal places
            if (parts[1] && parts[1].length > 2) {
                value = parts[0] + '.' + parts[1].substring(0, 2);
            }
            
            this.value = value;
        });
        
        // Phone number formatting (optional)
        document.getElementById('hotline').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, ''); // Remove non-digits
            
            // Format as needed (example: Sri Lankan format)
            if (value.length > 0) {
                if (value.length <= 3) {
                    value = value;
                } else if (value.length <= 6) {
                    value = value.slice(0, 3) + '-' + value.slice(3);
                } else {
                    value = value.slice(0, 3) + '-' + value.slice(3, 6) + '-' + value.slice(6, 10);
                }
            }
            
            this.value = value;
        });
        
        // Character counter for textarea
        const addressTextarea = document.getElementById('address');
        if (addressTextarea) {
            const maxLength = 500;
            
            // Create character counter
            const counterDiv = document.createElement('div');
            counterDiv.className = 'character-counter';
            counterDiv.style.fontSize = '12px';
            counterDiv.style.color = '#6c757d';
            counterDiv.style.textAlign = 'right';
            counterDiv.style.marginTop = '5px';
            
            addressTextarea.parentNode.appendChild(counterDiv);
            
            function updateCounter() {
                const remaining = maxLength - addressTextarea.value.length;
                counterDiv.textContent = `${addressTextarea.value.length}/${maxLength} characters`;
                
                if (remaining < 50) {
                    counterDiv.style.color = '#dc3545';
                } else if (remaining < 100) {
                    counterDiv.style.color = '#ffc107';
                } else {
                    counterDiv.style.color = '#6c757d';
                }
            }
            
            addressTextarea.addEventListener('input', updateCounter);
            updateCounter(); // Initialize counter
        }
        
        // Smooth scrolling for form sections
        function scrollToSection(sectionId) {
            const section = document.getElementById(sectionId);
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        
        // Add tooltips to form labels (if Bootstrap tooltips are available)
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips if Bootstrap is available
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function(tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }
        });
        
        // Confirmation dialog for form reset
        function confirmReset() {
            return confirm('Are you sure you want to reset all form data? This action cannot be undone.');
        }
        
        // File size validation
        function validateFileSize(input, maxSizeMB = 2) {
            const file = input.files[0];
            if (file) {
                const fileSizeMB = file.size / (1024 * 1024);
                if (fileSizeMB > maxSizeMB) {
                    alert(`File size must be less than ${maxSizeMB}MB. Current file size: ${fileSizeMB.toFixed(2)}MB`);
                    input.value = '';
                    return false;
                }
            }
            return true;
        }
        
        // Add file size validation to file inputs
        document.getElementById('logo').addEventListener('change', function() {
            validateFileSize(this, 2);
        });
        
        document.getElementById('fav_icon').addEventListener('change', function() {
            validateFileSize(this, 1);
        });
        
        // Add loading overlay functionality
        function showLoadingOverlay() {
            const overlay = document.createElement('div');
            overlay.id = 'loadingOverlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            `;
            
            const spinner = document.createElement('div');
            spinner.innerHTML = `
                <div class="text-center text-white">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2">Saving branding settings...</div>
                </div>
            `;
            
            overlay.appendChild(spinner);
            document.body.appendChild(overlay);
        }
        
        function hideLoadingOverlay() {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.remove();
            }
        }
        
        // Enhanced form submission with loading overlay
        document.getElementById('brandingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (validateForm()) {
                showLoadingOverlay();
                
                // Submit the form after a short delay to show the overlay
                setTimeout(() => {
                    this.submit();
                }, 100);
            } else {
                // Scroll to first error
                const firstError = document.querySelector('.is-invalid');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('brandingForm').dispatchEvent(new Event('submit'));
            }
            
            // Ctrl + R to reset (with confirmation)
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                if (confirmReset()) {
                    resetForm();
                }
            }
        });
        
        // Add unsaved changes warning
        let formChanged = false;
        
        document.querySelectorAll('#brandingForm input, #brandingForm textarea').forEach(function(input) {
            input.addEventListener('input', function() {
                formChanged = true;
            });
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
        
        // Reset form changed flag on successful submission
        document.getElementById('brandingForm').addEventListener('submit', function() {
            formChanged = false;
        });
    </script>
</body>
</html>