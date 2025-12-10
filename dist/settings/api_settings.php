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

// Initialize error message variable
$errorMsg = '';
$successMsg = '';

// Handle form submission with proper validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMsg = 'Invalid request. Please try again.';
    } else {
        $courier_id = isset($_POST['courier_id']) ? (int)$_POST['courier_id'] : 0;
        $client_id = isset($_POST['client_id']) ? trim($_POST['client_id']) : '';
        $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';
        $status = isset($_POST['status']) ? $_POST['status'] : '';
        
        // Validation errors array
        $validationErrors = [];
        
        // Validate courier selection
        if ($courier_id <= 0) {
            $validationErrors[] = 'Please select a courier';
        }
        
        // Validate Client ID (REQUIRED)
        if (empty($client_id)) {
            $validationErrors[] = 'Client ID is required';
        } elseif (strlen($client_id) > 255) {
            $validationErrors[] = 'Client ID is too long (maximum 255 characters)';
        }
        
        // Validate API Key (REQUIRED)
        if (empty($api_key)) {
            $validationErrors[] = 'API Key is required';
        } elseif (strlen($api_key) > 500) {
            $validationErrors[] = 'API Key is too long (maximum 500 characters)';
        }
        
        // Validate Status (REQUIRED)
        if (empty($status)) {
            $validationErrors[] = 'Status selection is required';
        } elseif (!in_array($status, ['active', 'inactive'])) {
            $validationErrors[] = 'Please select a valid status';
        }
        
        // If there are validation errors, show them
        if (!empty($validationErrors)) {
            $errorMsg = implode('<br>', $validationErrors);
        } else {
            // All validations passed, proceed with update
            $updateSql = "UPDATE couriers SET 
                          client_id = ?, 
                          api_key = ?, 
                          status = ?, 
                          updated_at = CURRENT_TIMESTAMP 
                          WHERE courier_id = ?";
            
            $stmt = $conn->prepare($updateSql);
            $stmt->bind_param("sssi", $client_id, $api_key, $status, $courier_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "API settings updated successfully!";
                header("Location: api_settings.php?courier_id=" . $courier_id);
                exit();
            } else {
                $errorMsg = "Error updating API settings: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Check for success/error messages from session
if (isset($_SESSION['success_message'])) {
    $successMsg = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $errorMsg = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Fetch all couriers for the dropdown
$couriersSql = "SELECT courier_id, courier_name, client_id, api_key, status, is_default FROM couriers ORDER BY is_default DESC, courier_name ASC";
$couriersResult = $conn->query($couriersSql);

// Get selected courier details if courier_id is provided
$selectedCourier = null;
$selected_courier_id = isset($_GET['courier_id']) ? (int)$_GET['courier_id'] : 0;
if ($selected_courier_id > 0) {
    $selectedSql = "SELECT courier_id, courier_name, client_id, api_key, status, is_default FROM couriers WHERE courier_id = ?";
    $stmt = $conn->prepare($selectedSql);
    $stmt->bind_param("i", $selected_courier_id);
    $stmt->execute();
    $selectedResult = $stmt->get_result();
    if ($selectedResult->num_rows > 0) {
        $selectedCourier = $selectedResult->fetch_assoc();
    }
    $stmt->close();
}

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <!-- TITLE -->
    <title>Order Management Admin Portal - API Settings</title>

    <?php
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php');
    ?>
    
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
     <link rel="stylesheet" href="../assets/css/api.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
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
                        <h5 class="mb-0 font-medium">API Settings</h5>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->

            <!-- [ Main Content ] start -->
            <div class="main-container">
                <!-- Success Alert -->
                <?php if (!empty($successMsg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($successMsg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Error Alert -->
                <?php if (!empty($errorMsg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $errorMsg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- API Settings Form -->
                <form method="POST" action="api_settings.php" id="apiSettingsForm" class="customer-form" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- API Configuration Section -->
                    <div class="form-section">
                        <div class="section-content">
                            <!-- First Row: Courier Selection -->
                            <div class="form-row">
                                <div class="customer-form-group full-width">
                                    <label for="courier_id" class="form-label">
                                        <i class="fas fa-shipping-fast"></i> Select Courier<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="courier_id" name="courier_id" required>
                                        <option value="">Choose a courier...</option>
                                        <?php if ($couriersResult && $couriersResult->num_rows > 0): ?>
                                            <?php 
                                            $couriersResult->data_seek(0); // Reset pointer
                                            while ($courier = $couriersResult->fetch_assoc()): ?>
                                                <option value="<?php echo $courier['courier_id']; ?>" 
                                                        <?php echo ($selectedCourier && $selectedCourier['courier_id'] == $courier['courier_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($courier['courier_name']); ?>
                                                    <?php if ($courier['is_default']): ?>
                                                        (Default)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endwhile; ?>
                                        <?php endif; ?>
                                    </select>
                                    <div class="error-feedback" id="courier_id-error"></div>
                                    <div class="courier-hint">Select the courier service to configure API settings</div>
                                </div>
                            </div>

                            <!-- Second Row: Client ID and API Key -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="client_id" class="form-label">
                                        <i class="fas fa-id-badge"></i> Client ID<span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="client_id" name="client_id"
                                        value="<?php echo $selectedCourier ? htmlspecialchars($selectedCourier['client_id']) : ''; ?>"
                                        placeholder="Enter Client ID" required>
                                    <div class="error-feedback" id="client_id-error"></div>
                                    <div class="client-hint">Unique identifier provided by the courier service</div>
                                </div>

                                <div class="customer-form-group">
                                    <label for="api_key" class="form-label">
                                        <i class="fas fa-key"></i> API Key<span class="required">*</span>
                                    </label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="api_key" name="api_key"
                                            value="<?php echo $selectedCourier ? htmlspecialchars($selectedCourier['api_key']) : ''; ?>"
                                            placeholder="Enter API Key" required>
                                        <button type="button" class="password-toggle" id="toggleApiKey">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="error-feedback" id="api_key-error"></div>
                                    <div class="api-hint">Secret key for API authentication</div>
                                </div>
                            </div>

                            <!-- Third Row: Status -->
                            <div class="form-row">
                                <div class="customer-form-group">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on"></i> Status<span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="">Choose status...</option>
                                        <option value="active" <?php echo ($selectedCourier && $selectedCourier['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ($selectedCourier && $selectedCourier['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                    <div class="error-feedback" id="status-error"></div>
                                    <div class="status-hint">Enable or disable API integration</div>
                                </div>

                                <div class="customer-form-group">
                                    <!-- Empty space for layout balance -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Current Settings Display -->
                    <?php if ($selectedCourier): ?>
                    <div class="form-section">
                        <div class="section-header">
                            <h6><i class="fas fa-info-circle"></i> Current Settings for <?php echo htmlspecialchars($selectedCourier['courier_name']); ?></h6>
                        </div>
                        <div class="section-content">
                            <div class="settings-display">
                                <div class="setting-row">
                                    <div class="setting-item">
                                        <label>Client ID:</label>
                                        <span><?php echo !empty($selectedCourier['client_id']) ? htmlspecialchars($selectedCourier['client_id']) : '<em>Not set</em>'; ?></span>
                                    </div>
                                    <div class="setting-item">
                                        <label>API Key:</label>
                                        <span><?php echo !empty($selectedCourier['api_key']) ? '••••••••••••••••' : '<em>Not set</em>'; ?></span>
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <div class="setting-item">
                                        <label>Status:</label>
                                        <span class="status-badge status-<?php echo $selectedCourier['status']; ?>">
                                            <?php echo ucfirst($selectedCourier['status']); ?>
                                        </span>
                                    </div>
                                    <div class="setting-item">
                                        <label>Default Courier:</label>
                                        <span class="<?php echo $selectedCourier['is_default'] ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo $selectedCourier['is_default'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Submit Buttons -->
                    <div class="submit-container">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Update API Settings
                        </button>
                        <a href="../orders/couriers.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Couriers
                        </a>
                    </div>
                </form>
            </div>
            <!-- [ Main Content ] end -->
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="success-modal">
        <div class="success-modal-content">
            <div class="success-modal-header">
                <i class="fas fa-check-circle"></i>
                <h4>Success!</h4>
            </div>
            <div class="success-modal-body">
                <p id="successMessage">API settings have been updated successfully.</p>
            </div>
            <div class="success-modal-footer">
                <button type="button" class="btn-success" onclick="continueEditing()">
                    <i class="fas fa-edit"></i> Continue Editing
                </button>
                <button type="button" class="btn-secondary" onclick="viewAllCouriers()">
                    <i class="fas fa-list"></i> View All Couriers
                </button>
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
        // Show success modal if there's a success message
        <?php if (!empty($successMsg)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showSuccessModal("<?php echo addslashes($successMsg); ?>");
            });
        <?php endif; ?>

        // Success modal functions
        function showSuccessModal(message) {
            const modal = document.getElementById('successModal');
            const messageElement = document.getElementById('successMessage');
            
            if (message) {
                messageElement.textContent = message;
            }
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function hideSuccessModal() {
            const modal = document.getElementById('successModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function continueEditing() {
            hideSuccessModal();
            document.getElementById('client_id').focus();
        }

        function viewAllCouriers() {
            hideSuccessModal();
            window.location.href = '../orders/couriers.php';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('successModal');
            if (event.target === modal) {
                hideSuccessModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                hideSuccessModal();
            }
        });

        // API Key toggle functionality
        document.getElementById('toggleApiKey').addEventListener('click', function() {
            const apiKeyInput = document.getElementById('api_key');
            const icon = this.querySelector('i');
            
            if (apiKeyInput.type === 'password') {
                apiKeyInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                apiKeyInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Courier selection handler
        document.getElementById('courier_id').addEventListener('change', function() {
            const courierId = this.value;
            if (courierId) {
                window.location.href = `api_settings.php?courier_id=${courierId}`;
            } else {
                // Clear form if no courier selected
                document.getElementById('client_id').value = '';
                document.getElementById('api_key').value = '';
                document.getElementById('status').value = '';
                
                // Clear validations
                clearAllValidations();
            }
        });

        // Updated validation functions with proper required field checks
        function validateCourier(courierId) {
            if (!courierId || courierId.trim() === '') {
                return { valid: false, message: 'Please select a courier' };
            }
            return { valid: true, message: '' };
        }

        function validateClientId(clientId) {
            // Make Client ID required
            if (!clientId || clientId.trim() === '') {
                return { valid: false, message: 'Client ID is required' };
            }
            if (clientId.length > 255) {
                return { valid: false, message: 'Client ID is too long (maximum 255 characters)' };
            }
            return { valid: true, message: '' };
        }

        function validateApiKey(apiKey) {
            // Make API Key required
            if (!apiKey || apiKey.trim() === '') {
                return { valid: false, message: 'API Key is required' };
            }
            if (apiKey.length > 500) {
                return { valid: false, message: 'API Key is too long (maximum 500 characters)' };
            }
            return { valid: true, message: '' };
        }

        function validateStatus(status) {
            if (!status || status.trim() === '') {
                return { valid: false, message: 'Status selection is required' };
            }
            const validStatuses = ['active', 'inactive'];
            if (!validStatuses.includes(status)) {
                return { valid: false, message: 'Please select a valid status' };
            }
            return { valid: true, message: '' };
        }

        // Show/hide error functions
        function showError(fieldId, message) {
            const field = document.getElementById(fieldId);
            const errorDiv = document.getElementById(fieldId + '-error');
            
            if (field && errorDiv) {
                field.classList.add('is-invalid');
                field.classList.remove('is-valid');
                errorDiv.textContent = message;
                errorDiv.style.display = 'block';
            }
        }

        function showSuccess(fieldId) {
            const field = document.getElementById(fieldId);
            const errorDiv = document.getElementById(fieldId + '-error');
            
            if (field && errorDiv) {
                field.classList.add('is-valid');
                field.classList.remove('is-invalid');
                errorDiv.style.display = 'none';
            }
        }

        function clearValidation(fieldId) {
            const field = document.getElementById(fieldId);
            const errorDiv = document.getElementById(fieldId + '-error');
            
            if (field && errorDiv) {
                field.classList.remove('is-valid', 'is-invalid');
                errorDiv.style.display = 'none';
            }
        }

        function clearAllValidations() {
            const fields = ['courier_id', 'client_id', 'api_key', 'status'];
            fields.forEach(fieldId => {
                clearValidation(fieldId);
            });
        }

        // Form validation - ALL FIELDS REQUIRED
        function validateForm() {
            let isValid = true;
            
            // Get all field values
            const courierId = document.getElementById('courier_id').value;
            const clientId = document.getElementById('client_id').value;
            const apiKey = document.getElementById('api_key').value;
            const status = document.getElementById('status').value;
            
            // Validate ALL required fields
            const validations = [
                { field: 'courier_id', validator: validateCourier, value: courierId },
                { field: 'client_id', validator: validateClientId, value: clientId },
                { field: 'api_key', validator: validateApiKey, value: apiKey },
                { field: 'status', validator: validateStatus, value: status }
            ];
            
            validations.forEach(validation => {
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

        // Event listeners for real-time validation
        document.addEventListener('DOMContentLoaded', function() {
            // Courier selection validation
            document.getElementById('courier_id').addEventListener('blur', function() {
                const validation = validateCourier(this.value);
                if (!validation.valid) {
                    showError('courier_id', validation.message);
                } else {
                    showSuccess('courier_id');
                }
            });

            // Client ID validation (REQUIRED)
            document.getElementById('client_id').addEventListener('blur', function() {
                const validation = validateClientId(this.value);
                if (!validation.valid) {
                    showError('client_id', validation.message);
                } else {
                    showSuccess('client_id');
                }
            });

            // API Key validation (REQUIRED)
            document.getElementById('api_key').addEventListener('blur', function() {
                const validation = validateApiKey(this.value);
                if (!validation.valid) {
                    showError('api_key', validation.message);
                } else {
                    showSuccess('api_key');
                }
            });

            // Status validation (REQUIRED)
            document.getElementById('status').addEventListener('change', function() {
                const validation = validateStatus(this.value);
                if (!validation.valid) {
                    showError('status', validation.message);
                } else {
                    showSuccess('status');
                }
            });

            // Form submission handler
            document.getElementById('apiSettingsForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Clear all previous validations
                clearAllValidations();
                
                // Validate form - ALL FIELDS MUST BE FILLED
                if (validateForm()) {
                    // Show loading state
                    const submitBtn = document.getElementById('submitBtn');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating Settings...';
                    submitBtn.disabled = true;
                    
                    // Submit the form
                    this.submit();
                } else {
                    // Scroll to first error and focus
                    const firstError = document.querySelector('.is-invalid');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        firstError.focus();
                    }
                    
                    // Show alert for required fields
                    alert('Please fill in all required fields: Courier, Client ID, API Key, and Status');
                }
            });

            // Focus on courier selection if none selected
            const courierId = document.getElementById('courier_id').value;
            if (!courierId) {
                document.getElementById('courier_id').focus();
            } else {
                document.getElementById('client_id').focus();
            }
        });

        // Prevent form submission on Enter key in input fields
        document.querySelectorAll('input:not([type="submit"])').forEach(input => {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    // Move to next input field
                    const inputs = Array.from(document.querySelectorAll('input, select'));
                    const currentIndex = inputs.indexOf(this);
                    if (currentIndex < inputs.length - 1) {
                        inputs[currentIndex + 1].focus();
                    }
                }
            });
        });
    </script>

</body>
</html>