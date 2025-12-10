<?php
// File: templates/lead_upload.php
// Start output buffering to prevent header issues
ob_start();

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ob_end_clean();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include the database connection file early
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Process CSV upload if form is submitted
if ($_POST && isset($_FILES['csv_file']) && isset($_POST['users'])) {
    try {
        // Validate file upload
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed with error code: " . $_FILES['csv_file']['error']);
        }
        
        // Validate file type
        $fileInfo = pathinfo($_FILES['csv_file']['name']);
        if (strtolower($fileInfo['extension']) !== 'csv') {
            throw new Exception("Only CSV files are allowed.");
        }
        
        // Validate file size (10MB limit)
        if ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
            throw new Exception("File size must be less than 10MB.");
        }
        
        // Get selected users
        $selectedUsers = $_POST['users'];
        if (empty($selectedUsers)) {
            throw new Exception("Please select at least one user.");
        }
        
        // Get the logged-in user ID who is performing the import
        $loggedInUserId = $_SESSION['user_id']; // Assuming user_id is stored in session
        
        if (!$loggedInUserId) {
            throw new Exception("Unable to determine logged-in user.");
        }
        
        // Validate selected users exist and are active
        $userPlaceholders = str_repeat('?,', count($selectedUsers) - 1) . '?';
        $userValidationSql = "SELECT id FROM users WHERE id IN ($userPlaceholders) AND status = 'active'";
        $userValidationStmt = $conn->prepare($userValidationSql);
        if (!$userValidationStmt) {
            throw new Exception("Failed to prepare user validation query: " . $conn->error);
        }
        $userValidationStmt->bind_param(str_repeat('i', count($selectedUsers)), ...$selectedUsers);
        $userValidationStmt->execute();
        $validUsersResult = $userValidationStmt->get_result();
        
        if ($validUsersResult->num_rows !== count($selectedUsers)) {
            throw new Exception("One or more selected users are invalid or inactive.");
        }
        $userValidationStmt->close();
        
        // Process CSV file
        $csvFile = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($csvFile, 'r');
        
        if (!$handle) {
            throw new Exception("Could not open CSV file.");
        }
        
        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        // Read header row
        $headers = fgetcsv($handle);
        if (!$headers) {
            throw new Exception("CSV file is empty or invalid.");
        }
        
        // Expected headers (case-insensitive)
        $expectedHeaders = [
            'Full Name', 'Phone Number', 'City', 'Email', 'Address Line 1', 
            'Address Line 2', 'Product Code', 'Total Amount', 'Other'
        ];
        
        // Normalize headers for comparison
        $normalizedHeaders = array_map('strtolower', array_map('trim', $headers));
        $normalizedExpected = array_map('strtolower', $expectedHeaders);
        
        // Check if headers match
        if ($normalizedHeaders !== $normalizedExpected) {
            throw new Exception("CSV headers do not match the expected format. Please use the template.");
        }
        
        // Initialize counters
        $successCount = 0;
        $errorCount = 0;
        $errorMessages = [];
        $rowNumber = 1; // Start from 1 (header is row 0)
        
        // Begin transaction
        $conn->begin_transaction();
        
        // Process each row
        while (($row = fgetcsv($handle)) !== FALSE) {
            $rowNumber++;
            
            try {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // Map CSV columns to variables
                $fullName = trim($row[0]);
                $phoneNumber = trim($row[1]);
                $city = trim($row[2]);
                $email = trim($row[3]);
                $addressLine1 = trim($row[4]);
                $addressLine2 = trim($row[5]);
                $productCode = trim($row[6]);
                $totalAmount = trim($row[7]);
                $other = trim($row[8]);
                
                // Handle email - normalize empty values
                // Check for truly empty email values (empty string, null, whitespace, or common empty indicators)
                if (empty($email) || $email === '' || $email === 'NULL' || $email === 'null' || $email === 'N/A' || $email === 'n/a' || $email === '-') {
                    $email = '';
                    $emailForDb = '-'; // Use dash for database storage
                } else {
                    $emailForDb = $email;
                }
                
                // Debug: Add some error context for troubleshooting
                $emailDebugInfo = "Email value: '" . $email . "' (length: " . strlen($email) . ")";
                
                // Validate required fields
                if (empty($fullName)) {
                    throw new Exception("Full Name is required");
                }
                if (empty($phoneNumber)) {
                    throw new Exception("Phone Number is required");
                }
                if (empty($city)) {
                    throw new Exception("City is required");
                }
                if (empty($productCode)) {
                    throw new Exception("Product Code is required");
                }
                if (empty($totalAmount)) {
                    throw new Exception("Total Amount is required");
                }
                
                // Validate email format ONLY if email is actually provided and not empty
                if (!empty($email) && $email !== '' && $email !== '-') {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid email format: '$email' - " . $emailDebugInfo);
                    }
                }
                
                // Validate phone number format (allow numbers, spaces, +, -, ())
                if (!preg_match('/^[0-9\s\+\-\(\)]+$/', $phoneNumber)) {
                    throw new Exception("Invalid phone number format");
                }
                
                // Validate total amount is numeric and positive (MODIFIED - removed price matching validation)
                if (!is_numeric($totalAmount) || $totalAmount <= 0) {
                    throw new Exception("Total Amount must be a positive number");
                }
                
                // Convert total amount to decimal
                $totalAmountDecimal = (float)$totalAmount;
                
                // Check if product exists and is active, and get its price
                $productSql = "SELECT id, lkr_price FROM products WHERE product_code = ? AND status = 'active'";
                $productStmt = $conn->prepare($productSql);
                if (!$productStmt) {
                    throw new Exception("Failed to prepare product query: " . $conn->error);
                }
                $productStmt->bind_param("s", $productCode);
                $productStmt->execute();
                $productResult = $productStmt->get_result();
                
                if ($productResult->num_rows === 0) {
                    throw new Exception("Product code '$productCode' not found or inactive");
                }
                
                $product = $productResult->fetch_assoc();
                $productId = $product['id'];
                $unitPrice = (float)$product['lkr_price'];
                $productStmt->close();
                
                // REMOVED: Price matching validation - now allows any positive amount
                // The original validation that checked if total amount matches product price has been removed
                
                // Look up city_id - REQUIRED field
                $cityId = null;
                $citySql = "SELECT city_id FROM city_table WHERE city_name = ? AND is_active = 1 LIMIT 1";
                $cityStmt = $conn->prepare($citySql);
                if (!$cityStmt) {
                    throw new Exception("Failed to prepare city query: " . $conn->error);
                }
                $cityStmt->bind_param("s", $city);
                $cityStmt->execute();
                $cityResult = $cityStmt->get_result();
                if ($cityResult->num_rows > 0) {
                    $cityId = $cityResult->fetch_assoc()['city_id'];
                } else {
                    throw new Exception("City '$city' not found or inactive");
                }
                $cityStmt->close();
                
                // Check if customer exists - handle empty/dash email properly
                if (!empty($email) && $email !== '' && $email !== '-') {
                    // If email is provided and valid, check by phone OR email
                    $customerSql = "SELECT customer_id, name, email, phone, address_line1, address_line2, city_id 
                                   FROM customers WHERE phone = ? OR email = ?";
                    $customerStmt = $conn->prepare($customerSql);
                    if (!$customerStmt) {
                        throw new Exception("Failed to prepare customer query: " . $conn->error);
                    }
                    $customerStmt->bind_param("ss", $phoneNumber, $email);
                } else {
                    // If email is empty, check by phone only
                    $customerSql = "SELECT customer_id, name, email, phone, address_line1, address_line2, city_id 
                                   FROM customers WHERE phone = ?";
                    $customerStmt = $conn->prepare($customerSql);
                    if (!$customerStmt) {
                        throw new Exception("Failed to prepare customer query: " . $conn->error);
                    }
                    $customerStmt->bind_param("s", $phoneNumber);
                }
                $customerStmt->execute();
                $customerResult = $customerStmt->get_result();
                
                $customerId = null;
                
                if ($customerResult->num_rows > 0) {
                    // Customer exists - validate data matches
                    $existingCustomer = $customerResult->fetch_assoc();
                    
                    // Check if all data matches exactly
                    // For email comparison, handle empty/dash values properly
                    $emailMatches = false;
                    if ($emailForDb === '-') {
                        // If new email is dash (empty), accept existing dash, empty, or null
                        $emailMatches = ($existingCustomer['email'] === '-' || empty($existingCustomer['email']) || $existingCustomer['email'] === null);
                    } else {
                        // If new email is not dash, must match exactly
                        $emailMatches = ($existingCustomer['email'] === $emailForDb);
                    }
                    
                    if (
                        $existingCustomer['name'] !== $fullName ||
                        $existingCustomer['phone'] !== $phoneNumber ||
                        $existingCustomer['address_line1'] !== $addressLine1 ||
                        $existingCustomer['address_line2'] !== $addressLine2 ||
                        $existingCustomer['city_id'] != $cityId ||
                        !$emailMatches
                    ) {
                        throw new Exception("Customer data mismatch with existing record");
                    }
                    
                    $customerId = $existingCustomer['customer_id'];
                } else {
                    // Create new customer - use $emailForDb (dash for empty emails)
                    $customerInsertSql = "INSERT INTO customers (name, email, phone, address_line1, address_line2, city_id) 
                                         VALUES (?, ?, ?, ?, ?, ?)";
                    $customerInsertStmt = $conn->prepare($customerInsertSql);
                    if (!$customerInsertStmt) {
                        throw new Exception("Failed to prepare customer insert query: " . $conn->error);
                    }
                    $customerInsertStmt->bind_param("sssssi", $fullName, $emailForDb, $phoneNumber, $addressLine1, $addressLine2, $cityId);
                    
                    if (!$customerInsertStmt->execute()) {
                        throw new Exception("Failed to create customer: " . $customerInsertStmt->error);
                    }
                    
                    $customerId = $conn->insert_id;
                    $customerInsertStmt->close();
                }
                $customerStmt->close();
                
                // Randomly assign to one of the selected users (this is the lead assignee)
                $assignedUserId = $selectedUsers[array_rand($selectedUsers)];
                
                // Create order header
                // user_id = assigned user (who will handle the lead)
                // created_by = logged-in user (who is importing the leads)
                $orderSql = "INSERT INTO order_header (
                    customer_id, user_id, issue_date, due_date, subtotal, discount, notes, 
                    pay_status, pay_by, total_amount, currency, status, product_code, interface, 
                    mobile, city_id, address_line1, address_line2, full_name, call_log, created_by
                ) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), ?, 0.00, ?, 
                         'unpaid', 'NULL', ?, 'lkr', 'pending', ?, 'leads', ?, ?, ?, ?, ?, 0, ?)";
                
                $orderStmt = $conn->prepare($orderSql);
                if (!$orderStmt) {
                    throw new Exception("Failed to prepare order query: " . $conn->error);
                }
                $notes = !empty($other) ? $other : 'Imported from CSV';
                
                // Using the total amount from CSV (no longer needs to match product price)
                $orderStmt->bind_param("iidsdssisssi", 
                    $customerId, $assignedUserId, $totalAmountDecimal, $notes, $totalAmountDecimal, 
                    $productCode, $phoneNumber, $cityId, $addressLine1, $addressLine2, 
                    $fullName, $loggedInUserId
                );
                
                if (!$orderStmt->execute()) {
                    throw new Exception("Failed to create order: " . $orderStmt->error);
                }
                
                $orderId = $conn->insert_id;
                $orderStmt->close();
                
                // Create order item
                $quantity = 1; // Default quantity
                $itemSql = "INSERT INTO order_items (
                    order_id, product_id, quantity, unit_price, discount, total_amount, 
                    pay_status, status, description
                ) VALUES (?, ?, ?, ?, 0.00, ?, 'unpaid', 'pending', ?)";
                
                $itemStmt = $conn->prepare($itemSql);
                if (!$itemStmt) {
                    throw new Exception("Failed to prepare order item query: " . $conn->error);
                }
                $description = "Product: $productCode";
                
                // MODIFIED: Use the CSV total amount instead of unit price for calculations
                $itemStmt->bind_param("iiidds", 
                    $orderId, $productId, $quantity, $totalAmountDecimal, $totalAmountDecimal, $description
                );
                
                if (!$itemStmt->execute()) {
                    throw new Exception("Failed to create order item: " . $itemStmt->error);
                }
                
                $itemStmt->close();
                
                $successCount++;
                
            } catch (Exception $e) {
                $errorCount++;
                $errorMessages[] = "Row $rowNumber: " . $e->getMessage();
                
                // Continue processing other rows
                continue;
            }
        }
        
        fclose($handle);
        
        // Commit transaction
        $conn->commit();
        
        // Store results in session
        $_SESSION['import_result'] = [
            'success' => $successCount,
            'errors' => $errorCount,
            'messages' => $errorMessages
        ];
        
        // Redirect to avoid resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }
        
        $_SESSION['import_error'] = $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch only active users from the database using prepared statement
$usersSql = "SELECT id, name FROM users WHERE status = 'active' ORDER BY name ASC";
$usersStmt = $conn->prepare($usersSql);
if (!$usersStmt) {
    die("Failed to prepare users query: " . $conn->error);
}
$usersStmt->execute();
$usersResult = $usersStmt->get_result();
$users = [];
if ($usersResult && $usersResult->num_rows > 0) {
    while ($user = $usersResult->fetch_assoc()) {
        $users[] = $user;
    }
}
$usersStmt->close();

// Include UI files after processing POST request to avoid header issues
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Lead Upload</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/leads.css" id="main-style-link" />
</head>
<style>
/* Add this simple CSS to your leads.css file */

.alert-info {
    background-color: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 5px;
}

.alert-info h4 {
    margin-bottom: 0.5rem;
    color: #0c5460;
}

.alert-info ul {
    margin-bottom: 0;
    padding-left: 1.5rem;
}

.alert-info li {
    margin-bottom: 0.3rem;
}
</style>
<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Lead Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                <!-- Display import results/errors -->
                <?php if (isset($_SESSION['import_result'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['import_result']['errors'] > 0 ? 'warning' : 'success'; ?>">
                        <h4>Import Results</h4>
                        <p><strong>Successfully imported:</strong> <?php echo $_SESSION['import_result']['success']; ?> records</p>
                        <?php if ($_SESSION['import_result']['errors'] > 0): ?>
                            <p><strong>Failed imports:</strong> <?php echo $_SESSION['import_result']['errors']; ?> records</p>
                            <?php if (!empty($_SESSION['import_result']['messages'])): ?>
                                <details>
                                    <summary>View Error Details</summary>
                                    <ul class="mt-2">
                                        <?php foreach ($_SESSION['import_result']['messages'] as $message): ?>
                                            <li><?php echo htmlspecialchars($message); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php unset($_SESSION['import_result']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['import_error'])): ?>
                    <div class="alert alert-danger">
                        <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['import_error']); ?>
                    </div>
                    <?php unset($_SESSION['import_error']); ?>
                <?php endif; ?>

                <div class="lead-upload-container">
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <!-- Download CSV Template Section -->
                        <div class="file-upload-section">
                            <a href="/OMS/dist/templates/generate_template.php" class="choose-file-btn">
                                Download CSV Template
                            </a>

                            <div class="file-upload-box">
                                <p><strong>Select CSV File</strong></p>
                                <p id="file-name">No file selected</p>
                                <input type="file" id="csv_file" name="csv_file" accept=".csv" style="display: none;" required>
                                <button type="button" class="choose-file-btn" onclick="document.getElementById('csv_file').click()">
                                     Choose File
                                </button>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <h4>📋 Upload Guidelines</h4>
                            <ul>
                                <li><strong>Download the template first</strong> - Use the provided CSV template</li>
                                <li><strong>Required fields:</strong> Full Name, Phone, City, Product Code, Total Amount</li>
                                <li><strong>Total Amount must be a positive number</strong> - Can be different from product price</li>
                                <li><strong>Email is optional</strong> - Leave blank if not available</li>
                                <li><strong>City names must match system database</strong></li>
                                <li><strong>File limit:</strong> 10MB maximum, CSV format only</li>
                                <li><strong>Select users</strong> to randomly assign leads to</li>
                            </ul>
                        </div>
                        <hr>
                        
                        <!-- Select Users Section -->
                        <div class="users-section">
                            <h2 class="section-title">Select Users</h2>
                            <p class="text-muted">Choose which users will receive the imported leads</p>
                            
                            <ul class="users-list" id="usersList">
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $user): ?>
                                        <li>
                                            <input type="checkbox" id="user_<?php echo $user['id']; ?>" name="users[]" value="<?php echo $user['id']; ?>">
                                            <label for="user_<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></label>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="no-users">No active users found</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        
                        
                        <!-- Select All Button -->
                        <?php if (!empty($users)): ?>
                            <button type="button" class="select-all-btn" id="toggleSelectAll">Select All</button>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="button" class="action-btn reset-btn" id="resetBtn">Reset</button>
                            <button type="submit" class="action-btn import-btn" id="importBtn">
                                 Import Leads
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    
    <script>
        // Form validation
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('csv_file');
            const userCheckboxes = document.querySelectorAll('#usersList input[type="checkbox"]:checked');
            
            if (!fileInput.files.length) {
                alert('Please select a CSV file to upload.');
                e.preventDefault();
                return false;
            }
            
            if (userCheckboxes.length === 0) {
                alert('Please select at least one user to assign the leads to.');
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            const importBtn = document.getElementById('importBtn');
            importBtn.disabled = true;
            importBtn.innerHTML = '⏳ Importing...';
            
            return true;
        });
        
        // Toggle select all/deselect all functionality
        const toggleBtn = document.getElementById('toggleSelectAll');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const checkboxes = document.querySelectorAll('#usersList input[type="checkbox"]');
                const allChecked = Array.from(checkboxes).every(checkbox => checkbox.checked);
                
                checkboxes.forEach(checkbox => {
                    checkbox.checked = !allChecked;
                });
                
                this.textContent = allChecked ? 'Select All' : 'Deselect All';
            });
        }
        
        // Reset button functionality
        document.getElementById('resetBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to reset the form?')) {
                // Uncheck all checkboxes
                document.querySelectorAll('#usersList input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
                
                // Reset file input
                document.getElementById('csv_file').value = '';
                document.getElementById('file-name').textContent = 'No file selected';
                
                // Reset the select all button text
                if (toggleBtn) {
                    toggleBtn.textContent = 'Select All';
                }
                
                // Reset import button
                const importBtn = document.getElementById('importBtn');
                importBtn.disabled = false;
                importBtn.innerHTML = '📤 Import Leads';
            }
        });
        
        // Show selected file name and validate file type
        document.getElementById('csv_file').addEventListener('change', function() {
            const file = this.files[0];
            const fileNameEl = document.getElementById('file-name');
            
            if (file) {
                // Check file extension
                const validExtensions = ['.csv'];
                const fileName = file.name.toLowerCase();
                const isValidExtension = validExtensions.some(ext => fileName.endsWith(ext));
                
                if (!isValidExtension) {
                    alert('Please select a valid CSV file.');
                    this.value = '';
                    fileNameEl.textContent = 'No file selected';
                    return;
                }
                
                // Check file size (10MB limit)
                const maxSize = 10 * 1024 * 1024; // 10MB in bytes
                if (file.size > maxSize) {
                    alert('File size must be less than 10MB.');
                    this.value = '';
                    fileNameEl.textContent = 'No file selected';
                    return;
                }
                
                fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            } else {
                fileNameEl.textContent = 'No file selected';
            }
        });
    </script>
</body>
</html>