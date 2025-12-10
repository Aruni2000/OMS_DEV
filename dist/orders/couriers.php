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
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

// Handle search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$courier_name_filter = isset($_GET['courier_name_filter']) ? trim($_GET['courier_name_filter']) : '';
$phone_filter = isset($_GET['phone_filter']) ? trim($_GET['phone_filter']) : '';
$email_filter = isset($_GET['email_filter']) ? trim($_GET['email_filter']) : '';
$address_filter = isset($_GET['address_filter']) ? trim($_GET['address_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM couriers";

// Main query - Updated to include is_default and has_api_new and has_api_existing fields
$sql = "SELECT courier_id, courier_name, phone_number, email, 
               CONCAT(address_line1, 
                     CASE WHEN address_line2 IS NOT NULL AND address_line2 != '' 
                          THEN CONCAT(', ', address_line2) 
                          ELSE '' 
                     END,
                     ', ', city) as full_address,
               address_line1, address_line2, city, is_default, has_api_new, has_api_existing, date_joined, 
               notes, created_at, updated_at, return_fee_value 
        FROM couriers";

// Build search conditions
$searchConditions = [];

// General search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        courier_name LIKE '%$searchTerm%' OR 
                        phone_number LIKE '%$searchTerm%' OR 
                        email LIKE '%$searchTerm%' OR 
                        address_line1 LIKE '%$searchTerm%' OR
                        city LIKE '%$searchTerm%')";
}

// Specific Courier Name filter
if (!empty($courier_name_filter)) {
    $courierNameTerm = $conn->real_escape_string($courier_name_filter);
    $searchConditions[] = "courier_name LIKE '%$courierNameTerm%'";
}

// Specific Phone filter
if (!empty($phone_filter)) {
    $phoneTerm = $conn->real_escape_string($phone_filter);
    $searchConditions[] = "phone_number LIKE '%$phoneTerm%'";
}

// Specific Email filter
if (!empty($email_filter)) {
    $emailTerm = $conn->real_escape_string($email_filter);
    $searchConditions[] = "email LIKE '%$emailTerm%'";
}

// Specific Address filter
if (!empty($address_filter)) {
    $addressTerm = $conn->real_escape_string($address_filter);
    $searchConditions[] = "(address_line1 LIKE '%$addressTerm%' OR city LIKE '%$addressTerm%')";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(date_joined) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(date_joined) <= '$dateToTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql = "SELECT COUNT(*) as total FROM couriers" . $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering - prioritize default courier first, then by creation date
$sql .= " ORDER BY is_default DESC, created_at DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

// Debug: Check if query failed
if (!$result) {
    die("Query failed: " . $conn->error);
}

// Function to get status label and class
function getStatusInfo($is_default) {
    switch($is_default) {
        case 0:
            return ['label' => 'None', 'class' => 'status-none', 'icon' => 'fas fa-circle'];
        case 1:
            return ['label' => 'Default Courier', 'class' => 'status-default', 'icon' => 'fas fa-star'];
        case 2:
            return ['label' => 'API Parcel Courier', 'class' => 'status-api', 'icon' => 'fas fa-code'];
        case 3:
            return ['label' => 'Existing API Parcel', 'class' => 'status-existing-api', 'icon' => 'fas fa-boxes'];
        default:
            return ['label' => 'Unknown', 'class' => 'status-unknown', 'icon' => 'fas fa-question'];
    }
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Courier Management</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/message.css" id="main-style-link" />
    <style>
        
        </style>
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Courier Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
            
                <!-- Courier Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Couriers</div>
                </div>

                <!-- Couriers Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>User Info</th>
                                <th>Contact number & email</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Return Value</th>
                                <th>Actions</th>
                                
                            </tr>
                        </thead>
                        <tbody id="couriersTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <?php $statusInfo = getStatusInfo($row['is_default']); ?>
                                    <tr class="courier-row-<?php echo $row['is_default']; ?>">
                                        <!-- User Info (courier name and id) -->
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;">
                                                    <?php echo htmlspecialchars($row['courier_name']); ?>
                                                </h6>
                                                <small style="color: #6c757d; font-size: 12px;">ID: <?php echo htmlspecialchars($row['courier_id']); ?></small>
                                            </div>
                                        </td>
                                        
                                        <!-- Contact number & email -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <div style="font-weight: 500; margin-bottom: 2px;">
                                                    <i class="fas fa-phone" style="color: #28a745; margin-right: 5px;"></i>
                                                    <?php echo htmlspecialchars($row['phone_number']); ?>
                                                </div>
                                                <?php if (!empty($row['email'])): ?>
                                                    <div style="font-size: 12px; color: #6c757d;">
                                                        <i class="fas fa-envelope" style="margin-right: 5px;"></i>
                                                        <?php echo htmlspecialchars($row['email']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Address -->
                                        <td>
                                            <div style="line-height: 1.3; font-size: 12px;">
                                                <div style="font-weight: 500; color: #495057;">
                                                    <?php echo htmlspecialchars($row['address_line1']); ?>
                                                </div>
                                                <?php if (!empty($row['address_line2'])): ?>
                                                    <div style="color: #6c757d;">
                                                        <?php echo htmlspecialchars($row['address_line2']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div style="color: #007bff; font-weight: 500;">
                                                    <?php echo htmlspecialchars($row['city']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- Status -->
                                        <td>
                                            <span class="status-badge <?php echo $statusInfo['class']; ?>">
                                                <i class="<?php echo $statusInfo['icon']; ?>"></i>
                                                <?php echo $statusInfo['label']; ?>
                                            </span>
                                        </td>

                                          <td>
                                                       <button class="btn btn-sm return-fee-btn"
                                        onclick="openReturnFeeModal(
                                            <?= $row['courier_id'] ?>,
                                            '<?= htmlspecialchars($row['courier_name']) ?>',
                                            <?= $row['return_fee_value'] ?? 0 ?>
                                        ); return false;"
                                        title="Set Return Fee"
                                        style="background-color: #f88a47ff; color: white; border: 1px solid #f88a47ff; margin-left:5px; 
                                            padding: 2px 6px; font-size: 11px; line-height: 1.2;">
                                        <i class="fas fa-dollar-sign"></i>  Fee
                                </button>
                                                    </td>
                                        
                                        <!-- Actions -->
                                        <td class="actions">
                                            <div class="action-dropdown-container">
                                                <select class="courier-status-dropdown" 
                                                        data-courier-id="<?= $row['courier_id'] ?>"
                                                        data-courier-name="<?= htmlspecialchars($row['courier_name']) ?>"
                                                        data-current-status="<?= $row['is_default'] ?>">
                                                    <option value="0" <?= $row['is_default'] == 0 ? 'selected' : '' ?>>None</option>
                                                    <option value="1" <?= $row['is_default'] == 1 ? 'selected' : '' ?>>Default Courier</option>
                                                    <option value="2" <?= $row['is_default'] == 2 ? 'selected' : '' ?>>API Parcel Courier</option>
                                                    <option value="3" <?= $row['is_default'] == 3 ? 'selected' : '' ?>>Existing API Parcel</option>
                                                </select>
                                              <?php if ($row['has_api_new'] == 1 || $row['has_api_existing'] == 1): ?>
                                                            <button class="add-api-btn" 
                                                                    onclick="handleApiButtonClick(<?= $row['courier_id'] ?>, '<?= htmlspecialchars($row['courier_name']) ?>', <?= $row['has_api_new'] ?>, <?= $row['has_api_existing'] ?>); return false;"
                                                                    title="Configure API Settings">
                                                                    <i class="fas fa-cog"></i>
                                                                    <span>API</span>
                                                            </button>
                                                            <?php endif; ?>
                                              <?php if ($row['courier_id'] == 12 && $row['has_api_existing'] == 1): ?>
                                                <button class="btn btn-sm download-waybills-btn" 
                                                    onclick="openWaybillsModal(<?= $row['courier_id'] ?>); return false;"
                                                    title="Download Koombiyo Waybills"
                                                    style="background-color: #1565C0; color: white; border: 1px solid #1565C0;"
                                                    onmouseover="this.style.backgroundColor='#1565C0'"
                                                    onmouseout="this.style.backgroundColor='#1565C0'">
                                                    <i class="fas fa-download"></i> Waybills
                                                </button>
                                                <?php endif; ?>
                                                
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-truck" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No couriers found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                           <!-- API Configuration Modal -->
                            <div id="apiModal" class="api-modal-overlay">
                                <div class="api-modal">
                                    
                                    <!-- Modal Header -->
                                    <div class="api-modal-header">
                                        <h4>
                                            <i class="fas fa-cog text-white"></i>
                                            <span id="modalTitle" class="text-white">Configure API Settings</span>
                                        </h4>
                                        <button type="button" class="close-btn" onclick="closeApiModal()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>

                                    <!-- Form Start -->
                                    <form id="apiSettingsForm" method="POST">
                                        <div class="api-modal-body">

                                            <!-- Hidden Fields -->
                                            <input type="hidden" id="courier_id" name="courier_id" value="">
                                            <input type="hidden" name="csrf_token" value="demo_token">

                                            <!-- Flex container for API credentials -->
                                            <div style="display: flex; gap: 20px;">

                                                <!-- Client ID -->
                                                <div class="form-group" style="flex: 1;">
                                                    <label for="client_id" class="form-label">
                                                        <i class="fas fa-id-badge"></i>
                                                        Client ID
                                                    </label>
                                                    <input type="text" 
                                                        class="form-control" 
                                                        id="client_id" 
                                                        name="client_id"
                                                        placeholder="Enter your Client ID">
                                                    <div class="error-feedback" id="client_id-error"></div>
                                                    <div class="form-hint">
                                                        <i class="fas fa-info-circle"></i>
                                                        Unique identifier provided by the courier service
                                                    </div>
                                                </div>

                                                <!-- API Key -->
                                                <div class="form-group" style="flex: 1;">
                                                    <label for="api_key" class="form-label">
                                                        <i class="fas fa-key"></i>
                                                        API Key<span class="required">*</span>
                                                    </label>
                                                    <div class="password-input-group">
                                                        <input type="password" 
                                                            class="form-control" 
                                                            id="api_key" 
                                                            name="api_key"
                                                            placeholder="Enter your API Key"
                                                            required>
                                                        <button type="button" class="password-toggle" id="toggleApiKey">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                    <div class="error-feedback" id="api_key-error"></div>
                                                    <div class="form-hint">
                                                        <i class="fas fa-shield-alt"></i>
                                                        Secret key for API authentication - keep this secure
                                                    </div>
                                                </div>
                                            </div> <!-- End of Client ID + API Key Flex -->

                                            <!-- Origin City and State (visible only for courier_id = 13) -->
                                            <div id="originFields" style="display: none; margin-top: 20px;">
                                                <div style="display: flex; gap: 20px;">
                                                    
                                                    <!-- Origin City -->
                                                    <div class="form-group" style="flex: 1;">
                                                        <label for="origin_city_name" class="form-label">
                                                            <i class="fas fa-city"></i>
                                                            Origin City
                                                        </label>
                                                        <input type="text" 
                                                            class="form-control" 
                                                            id="origin_city_name" 
                                                            name="origin_city_name"
                                                            placeholder="Enter origin city">
                                                        <div class="error-feedback" id="origin_city_error"></div>
                                                        <div class="form-hint">
                                                            <i class="fas fa-info-circle"></i>
                                                            Enter the courier’s main city of origin or pickup location
                                                        </div>
                                                    </div>

                                                    <!-- Origin State -->
                                                    <div class="form-group" style="flex: 1;">
                                                        <label for="origin_state_name" class="form-label">
                                                            <i class="fas fa-map-marked-alt"></i>
                                                            Origin State
                                                        </label>
                                                        <input type="text" 
                                                            class="form-control" 
                                                            id="origin_state_name" 
                                                            name="origin_state_name"
                                                            placeholder="Enter origin state">
                                                        <div class="error-feedback" id="origin_state_error"></div>
                                                        <div class="form-hint">
                                                            <i class="fas fa-info-circle"></i>
                                                            Enter the courier’s origin state or region name
                                                        </div>
                                                    </div>

                                                </div>
                                            </div> <!-- End of Origin Fields -->

                                        </div> <!-- End of Modal Body -->

                                        <!-- Modal Footer -->
                                        <div class="api-modal-footer">
                                            <button type="button" class="btn btn-secondary" onclick="closeApiModal()">
                                                <i class="fas fa-times"></i>
                                                Cancel
                                            </button>
                                            <button type="submit" class="btn btn-primary" id="saveApiBtn">
                                                <i class="fas fa-save"></i>
                                                Save API Settings
                                            </button>
                                        </div>

                                    </form> <!-- End of Form -->
                                </div> <!-- End of Modal -->
                            </div> <!-- End of Overlay -->

                        <!-- Waybills Download Modal -->
                        <div id="waybillsModal" class="api-modal-overlay">
                        <div class="api-modal">
                            <div class="api-modal-header">
                                <h4>
                                    <i class="fas fa-download  text-white"></i>
                                    <span id="waybillsModalTitle" class="text-white">Download Waybills - Koombiyo</span>
                                </h4>
                                <button type="button" class="close-btn" onclick="closeWaybillsModal()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <form id="waybillsDownloadForm" method="POST" action="/OMS/dist/api/koombiyo_get_waybills.php">
                                <div class="api-modal-body">
                                    <!-- Hidden courier ID -->
                                    <input type="hidden" id="waybills_courier_id" name="courier_id" value="">
                                    <input type="hidden" name="csrf_token" value="demo_token">

                                    <!-- Waybills Count Input -->
                                    <div class="form-group">
                                        <label for="waybills_count" class="form-label">
                                            <i class="fas fa-sort-numeric-up"></i>
                                            Number of Waybills
                                        </label>
                                        <input type="number" 
                                                class="form-control" 
                                                id="waybills_count" 
                                                name="waybills_count"
                                                placeholder="Enter number of waybills"
                                                min="1" 
                                                max="100" 
                                                step="1"
                                                required>
                                        <div class="error-feedback" id="waybills_count-error"></div>
                                        <div class="form-hint">
                                            <i class="fas fa-info-circle"></i>
                                            Maximum waybills count: 100 (Enter value between 1-100)
                                        </div>
                                    </div>
                                </div>

                                <div class="api-modal-footer">
                                    <button type="button" class="btn btn-secondary" onclick="closeWaybillsModal()">
                                        <i class="fas fa-times"></i>
                                        Cancel
                                    </button>
                                    <button type="submit" class="btn btn-primary" id="downloadWaybillsBtn">
                                        <i class="fas fa-download"></i>
                                        Download Waybills
                                    </button>
                                </div>
                            </form>
                        </div>
                        </div>

                         <!-- Return Fee Modal -->
                                            <div id="returnFeeModal" class="api-modal-overlay" style="display:none;">
                                                <div class="api-modal">
                                                    <div class="api-modal-header">
                                                        <h4>
                                                            <i class="fas fa-dollar-sign text-white"></i>
                                                            <span id="returnFeeModalTitle" class="text-white">Set Return Fee</span>
                                                        </h4>
                                                        <button type="button" class="close-btn" onclick="closeReturnFeeModal()">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                    <form id="returnFeeForm" method="POST" action="update_return_fee.php">
                                                        <div class="api-modal-body">
                                                            <!-- Hidden courier ID -->
                                                            <input type="hidden" id="returnFeeCourierId" name="courier_id" value="">
                                                            <input type="hidden" name="csrf_token" value="demo_token">

                                                            <!-- Return Fee Input -->
                                                            <div class="form-group">
                                                                <label for="returnFeeValue" class="form-label">
                                                                    <i class="fas fa-percentage"></i>
                                                                    Return Fee Value
                                                                </label>
                                                                <input type="number"
                                                                    class="form-control"
                                                                    id="returnFeeValue"
                                                                    name="return_fee_value"
                                                                    placeholder="Enter return fee (0 = no fee)"
                                                                    step="0.01"
                                                                    min="0"
                                                                    required>
                                                                <div class="error-feedback" id="return_fee_value-error"></div>
                                                                <div class="form-hint">
                                                                    <i class="fas fa-info-circle"></i>
                                                                   Enter a percentage. Enter 0 for no fee.

                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="api-modal-footer">
                                                            <button type="button" class="btn btn-secondary" onclick="closeReturnFeeModal()">
                                                                <i class="fas fa-times"></i> Cancel
                                                            </button>
                                                            <button type="submit" class="btn btn-primary" id="saveReturnFeeBtn">
                                                                <i class="fas fa-save"></i> Save Fee
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>


                <!-- API Access Denied Modal -->
                <div id="apiAccessDeniedModal" class="modal confirmation-modal">
                    <div class="modal-content confirmation-modal-content">
                        <div class="modal-header">
                            <h4><i class="fas fa-ban" style="color: #dc3545; margin-right: 8px;"></i>API Access Denied</h4>
                            <span class="close" onclick="closeModal('apiAccessDeniedModal')">&times;</span>
                        </div>
                        <div class="modal-body">
                            <div class="confirmation-icon">
                                <i class="fas fa-exclamation-triangle" style="color: #ffc107; font-size: 3rem;"></i>
                            </div>
                            <div class="confirmation-text">
                                <strong>Access Denied!</strong>
                            </div>
                            <div class="confirmation-text">
                                This courier (<span id="denied-courier-name" class="user-name-highlight"></span>) does not have API integration enabled.
                            </div>
                            <div class="confirmation-text" style="color: #6c757d; font-size: 14px; margin-top: 15px;">
                                Please contact your system administrator to enable API integration for this courier before configuring API settings.
                            </div>
                            <div class="modal-buttons">
                                <button class="btn-cancel" onclick="closeModal('apiAccessDeniedModal')" style="background: #6c757d;">
                                    <i class="fas fa-check"></i>
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination Controls -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&courier_name_filter=<?php echo urlencode($courier_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&address_filter=<?php echo urlencode($address_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&courier_name_filter=<?php echo urlencode($courier_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&address_filter=<?php echo urlencode($address_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&courier_name_filter=<?php echo urlencode($courier_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&address_filter=<?php echo urlencode($address_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Change Confirmation Modal -->
    <div id="statusChangeModal" class="modal confirmation-modal">
        <div class="modal-content confirmation-modal-content">
            <div class="modal-header">
                <h4>Change Courier Status</h4>
                <span class="close" onclick="closeModal('statusChangeModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">
                    <i class="fas fa-exchange-alt" style="color: #007bff;"></i>
                </div>
                <div class="confirmation-text">
                    Are you sure you want to change the status for:
                </div>
                <div class="confirmation-text">
                    <span class="user-name-highlight" id="change-status-courier-name"></span>
                </div>
                <div class="confirmation-text">
                    From: <span id="current-status-text" class="status-highlight"></span><br>
                    To: <span id="new-status-text" class="status-highlight"></span>
                </div>
                <div class="modal-buttons">
                    <button class="btn-confirm" id="confirmStatusChangeBtn">
                        <span>Yes, change status!</span>
                    </button>
                    <button class="btn-cancel" onclick="closeModal('statusChangeModal')">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toast Message System
        class ToastManager {
            constructor() {
                this.createContainer();
            }

            createContainer() {
                if (!document.getElementById('toast-container')) {
                    const container = document.createElement('div');
                    container.id = 'toast-container';
                    container.className = 'toast-container';
                    document.body.appendChild(container);
                }
            }

            show(message, type = 'info', duration = 5000) {
                const container = document.getElementById('toast-container');
                const toast = this.createToast(message, type);
                
                container.appendChild(toast);
                
                // Trigger animation
                setTimeout(() => {
                    toast.classList.add('show');
                }, 100);
                
                // Auto remove
                if (duration > 0) {
                    setTimeout(() => {
                        this.remove(toast);
                    }, duration);
                }
                
                return toast;
            }

            createToast(message, type) {
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                
                const icons = {
                    success: 'fas fa-check-circle',
                    error: 'fas fa-exclamation-circle',
                    warning: 'fas fa-exclamation-triangle',
                    info: 'fas fa-info-circle'
                };
                
                const titles = {
                    success: 'Success',
                    error: 'Error',
                    warning: 'Warning',
                    info: 'Information'
                };
                
                toast.innerHTML = `
                    <div class="toast-header">
                        <i class="toast-icon ${icons[type] || icons.info}"></i>
                        <span>${titles[type] || titles.info}</span>
                        <button class="toast-close" onclick="toastManager.remove(this.closest('.toast'))">&times;</button>
                    </div>
                    <div class="toast-body">${message}</div>
                `;
                
                return toast;
            }

            remove(toast) {
                if (toast && toast.parentNode) {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.parentNode.removeChild(toast);
                        }
                    }, 300);
                }
            }

            success(message, duration = 5000) {
                return this.show(message, 'success', duration);
            }

            error(message, duration = 8000) {
                return this.show(message, 'error', duration);
            }

            warning(message, duration = 6000) {
                return this.show(message, 'warning', duration);
            }

            info(message, duration = 5000) {
                return this.show(message, 'info', duration);
            }
        }

        // Initialize toast manager
        const toastManager = new ToastManager();

        // Loading overlay functions
        function showLoading(message = 'Processing...') {
            let overlay = document.getElementById('loading-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'loading-overlay';
                overlay.className = 'loading-overlay';
                overlay.innerHTML = `
                    <div class="loading-spinner">
                        <div class="spinner"></div>
                        <span id="loading-message">${message}</span>
                    </div>
                `;
                document.body.appendChild(overlay);
            }
            
            document.getElementById('loading-message').textContent = message;
            overlay.style.display = 'flex';
            
            // Disable all dropdowns
            const dropdowns = document.querySelectorAll('.courier-status-dropdown');
            dropdowns.forEach(dropdown => {
                dropdown.disabled = true;
            });
        }

        function hideLoading() {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
            
            // Re-enable all dropdowns
            const dropdowns = document.querySelectorAll('.courier-status-dropdown');
            dropdowns.forEach(dropdown => {
                dropdown.disabled = false;
            });
        }

        // Clear filters function
        function clearFilters() {
            window.location.href = 'couriers.php';
        }

        // Modal Functions
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Get status text and class
        function getStatusInfo(statusValue) {
            const statusMap = {
                '0': { label: 'None', class: 'status-none' },
                '1': { label: 'Default Courier', class: 'status-default' },
                '2': { label: 'API Parcel Courier', class: 'status-api' },
                '3': { label: 'Existing API Parcel', class: 'status-existing-api' }
            };
            return statusMap[statusValue] || { label: 'Unknown', class: 'status-unknown' };
        }

        // Open status change confirmation modal
        function openStatusChangeModal(courierId, courierName, currentStatus, newStatus) {
            const currentStatusInfo = getStatusInfo(currentStatus);
            const newStatusInfo = getStatusInfo(newStatus);
            
            // Update modal content
            document.getElementById('change-status-courier-name').textContent = courierName;
            document.getElementById('current-status-text').textContent = currentStatusInfo.label;
            document.getElementById('new-status-text').textContent = newStatusInfo.label;
            
            // Store data for confirmation
            const confirmBtn = document.getElementById('confirmStatusChangeBtn');
            confirmBtn.setAttribute('data-courier-id', courierId);
            confirmBtn.setAttribute('data-new-status', newStatus);
            
            // Add click handler to confirm button
            confirmBtn.onclick = function() {
                changeCourierStatus(courierId, newStatus);
            };
            
            // Show modal
            document.getElementById('statusChangeModal').style.display = 'block';
        }

        // Enhanced API Function to change courier status with proper error handling
        function changeCourierStatus(courierId, newStatus) {
            showLoading('Updating courier status...');
            
            fetch('toggle_courier_default.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    courier_id: courierId,
                    is_default: parseInt(newStatus)
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                hideLoading();
                closeModal('statusChangeModal');
                
                if (data.success) {
                    // Show success message with details
                    let successMessage = data.message;
                    if (data.data && data.data.unused_tracking_count !== undefined) {
                        successMessage += ` (${data.data.unused_tracking_count} tracking numbers available)`;
                    }
                    toastManager.success(successMessage);
                    
                    // Update the dropdown to reflect current status
                    const dropdown = document.querySelector(`[data-courier-id="${courierId}"]`);
                    if (dropdown) {
                        dropdown.setAttribute('data-current-status', newStatus);
                        dropdown.value = newStatus;
                    }
                    
                    // Reload page after a short delay to reflect all changes
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    // Show error message
                    toastManager.error(data.message || 'Failed to update courier status');
                    
                    // Reset dropdown to original value
                    const dropdown = document.querySelector(`[data-courier-id="${courierId}"]`);
                    if (dropdown) {
                        const originalStatus = dropdown.getAttribute('data-current-status');
                        dropdown.value = originalStatus;
                    }
                }
            })
            .catch(error => {
                hideLoading();
                closeModal('statusChangeModal');
                
                console.error('Error:', error);
                
                // Show generic error message
                toastManager.error('An unexpected error occurred while updating the courier status. Please try again.');
                
                // Reset dropdown to original value
                const dropdown = document.querySelector(`[data-courier-id="${courierId}"]`);
                if (dropdown) {
                    const originalStatus = dropdown.getAttribute('data-current-status');
                    dropdown.value = originalStatus;
                }
            });
        }

    // Handle API button click - Check has_api_new OR has_api_existing status first
function handleApiButtonClick(courierId, courierName, hasApiNew, hasApiExisting) {
    // Allow access if either has_api_new OR has_api_existing is set to 1
    if (hasApiNew == 1 || hasApiExisting == 1) {
        // Proceed with opening API modal
        openApiModal(courierId, courierName);
    } else {
        // Show access denied modal
        document.getElementById('denied-courier-name').textContent = courierName;
        document.getElementById('apiAccessDeniedModal').style.display = 'block';
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Status dropdown change event listeners
    const statusDropdowns = document.querySelectorAll('.courier-status-dropdown');
    statusDropdowns.forEach(dropdown => {
        dropdown.addEventListener('change', function() {
            const courierId = this.getAttribute('data-courier-id');
            const courierName = this.getAttribute('data-courier-name');
            const currentStatus = this.getAttribute('data-current-status');
            const newStatus = this.value;
            
            // Only show modal if status actually changed
            if (currentStatus !== newStatus) {
                openStatusChangeModal(courierId, courierName, currentStatus, newStatus);
            }
        });
    });
    
    // Close modals when clicking outside
    window.onclick = function(event) {
        const statusModal = document.getElementById('statusChangeModal');
        const accessDeniedModal = document.getElementById('apiAccessDeniedModal');
        
        if (event.target === statusModal) {
            closeModal('statusChangeModal');
        }
        if (event.target === accessDeniedModal) {
            closeModal('apiAccessDeniedModal');
        }
    };
    
    // Escape key to close modals
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal('statusChangeModal');
            closeModal('apiAccessDeniedModal');
        }
    });
});

// Search functionality
function performSearch() {
    const searchForm = document.querySelector('.tracking-form');
    if (searchForm) {
        searchForm.submit();
    }
}

// Auto-submit search on Enter key
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = document.querySelectorAll('#courier_name_filter, #phone_filter, #email_filter, #address_filter');
    searchInputs.forEach(input => {
        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                performSearch();
            }
        });
    });
});

function openApiModal(courierId, courierName) {
    const modal = document.getElementById('apiModal');
    const modalTitle = document.getElementById('modalTitle');
    const form = document.getElementById('apiSettingsForm');

    // Fields
    const courierIdInput = document.getElementById('courier_id');
    const clientIdInput = document.getElementById('client_id');
    const apiKeyInput = document.getElementById('api_key');
    const originFields = document.getElementById('originFields');
    const originCityInput = document.getElementById('origin_city_name');
    const originStateInput = document.getElementById('origin_state_name');
    const saveBtn = document.getElementById('saveApiBtn');

    // Set courier info
    courierIdInput.value = courierId;
    modalTitle.textContent = `Configure API Settings - ${courierName}`;

    // Show modal
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';

    // Reset form
    form.reset();

    // Show/hide origin fields for courier 13
    originFields.style.display = (courierId == 13) ? 'flex' : 'none';

    // Disable inputs while loading
    [clientIdInput, apiKeyInput, originCityInput, originStateInput, saveBtn].forEach(el => el.disabled = true);

    // Fetch existing API data
    fetch('get_courier_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ courier_id: courierId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            console.log("hhhhhhhi: "+data.data.client_id);
            clientIdInput.value = data.data.client_id || '';
            apiKeyInput.value = data.data.api_key || '';

            if (courierId == 13) {
                originCityInput.value = data.data.origin_city_name || '';
                originStateInput.value = data.data.origin_state_name || '';
            }
        } else {
            console.warn('No API data:', data.message);
        }
    })
    .catch(err => {
        console.error('Error loading API data:', err);
    })
    .finally(() => {
        // Enable inputs after loading
        [clientIdInput, apiKeyInput, originCityInput, originStateInput, saveBtn].forEach(el => el.disabled = false);
    });
}


// Close API Modal
function closeApiModal() {
    const modal = document.getElementById('apiModal');
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

// API Key toggle visibility
function toggleApiKey() {
    const apiKeyInput = document.getElementById('api_key');
    const icon = document.querySelector('#toggleApiKey i');
    
    if (apiKeyInput.type === 'password') {
        apiKeyInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        apiKeyInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Handle form submission
function handleApiFormSubmit(event) {
    event.preventDefault();
    
    const form = document.getElementById('apiSettingsForm');
    const submitBtn = document.getElementById('saveApiBtn');
    const formData = new FormData(form);
    
    // Show loading state
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    
    // Submit to update_api.php
    fetch('update_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            toastManager.success(data.message || 'API settings updated successfully!');
            closeApiModal();
            // Optionally reload page to reflect changes
            setTimeout(() => location.reload(), 1500);
        } else {
            toastManager.error(data.message || 'Failed to update API settings');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        toastManager.error('An error occurred while updating API settings');
    })
    .finally(() => {
        // Reset button
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Save API Settings';
        submitBtn.disabled = false;
    });
}

// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    // API key toggle
    const toggleBtn = document.getElementById('toggleApiKey');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleApiKey);
    }
    
    // Form submission
    const apiForm = document.getElementById('apiSettingsForm');
    if (apiForm) {
        apiForm.addEventListener('submit', handleApiFormSubmit);
    }
    
    // Close modal on overlay click
    const apiModal = document.getElementById('apiModal');
    if (apiModal) {
        apiModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeApiModal();
            }
        });
    }
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeApiModal();
        }
    });
});


// Modal controls
function openWaybillsModal(courierId) {
   document.getElementById('waybills_courier_id').value = courierId;
   document.getElementById('waybillsModal').style.display = 'flex';
   document.body.style.overflow = 'hidden';
}

function closeWaybillsModal() {
   document.getElementById('waybillsModal').style.display = 'none';
   document.body.style.overflow = 'auto';
   document.getElementById('waybillsDownloadForm').reset();
   hideError();
}

// Error handling
function showError(message) {
    let alert = document.getElementById('waybills-error-alert');
    if (!alert) {
        alert = document.createElement('div');
        alert.id = 'waybills-error-alert';
        alert.className = 'alert alert-danger';
        alert.style.marginBottom = '20px';
        document.querySelector('#waybillsModal .api-modal-body').prepend(alert);
    }
    alert.innerHTML = `<i class="fas fa-exclamation-triangle"></i> <strong>Error:</strong> ${message}`;
    alert.style.display = 'block';
}

function hideError() {
    const alert = document.getElementById('waybills-error-alert');
    if (alert) alert.style.display = 'none';
}

// Form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('waybillsDownloadForm');
    const btn = document.getElementById('downloadWaybillsBtn');
    
    // Close on outside click
    document.getElementById('waybillsModal').onclick = (e) => {
        if (e.target.id === 'waybillsModal') closeWaybillsModal();
    };
    
    form?.addEventListener('submit', async function(e) {
        e.preventDefault();
        hideError();
        
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Downloading...';
        btn.disabled = true;
        
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form)
            });
            
            const contentType = response.headers.get('content-type');
            
            if (contentType?.includes('application/json')) {
                const data = await response.json();
                throw new Error(data.message || 'Unknown error');
            }
            
            if (contentType?.includes('text/csv')) {
                const blob = await response.blob();
                const filename = response.headers.get('Content-Disposition')?.match(/filename="?([^"]+)"?/)?.[1] || 'waybills.csv';
                
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.click();
                URL.revokeObjectURL(url);
                
                closeWaybillsModal();
                if (typeof showSuccessToast === 'function') {
                    showSuccessToast('Waybills downloaded successfully!');
                }
            } else {
                throw new Error('Unexpected response format');
            }
        } catch (error) {
            showError(error.message);
        } finally {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    });
});

// Open the modal and populate fields
function openReturnFeeModal(courierId, courierName, currentValue) {
    document.getElementById("returnFeeCourierId").value = courierId;
    document.getElementById("returnFeeValue").value = currentValue ?? 0;
    document.getElementById("returnFeeModalTitle").innerText = "Set Return Fee - " + courierName;
    document.getElementById("returnFeeModal").style.display = "flex";
    
    // Clear any previous error messages
    clearValidationError();
}

// Close the modal
function closeReturnFeeModal() {
    document.getElementById("returnFeeModal").style.display = "none";
    clearValidationError();
}

// Clear validation error messages
function clearValidationError() {
    const errorDiv = document.getElementById("return_fee_value-error");
    const inputField = document.getElementById("returnFeeValue");
    
    if (errorDiv) {
        errorDiv.textContent = "";
        errorDiv.style.display = "none";
    }
    
    if (inputField) {
        inputField.classList.remove("is-invalid");
    }
}

// Show validation error
function showValidationError(message) {
    const errorDiv = document.getElementById("return_fee_value-error");
    const inputField = document.getElementById("returnFeeValue");
    
    if (errorDiv) {
        errorDiv.textContent = message;
        errorDiv.style.display = "block";
    }
    
    if (inputField) {
        inputField.classList.add("is-invalid");
    }
}

// Validate return fee value
function validateReturnFee(value) {
    const numValue = parseFloat(value);
    
    // Check if it's a valid number
    if (isNaN(numValue)) {
        return "Please enter a valid percentage value.";
    }
    
    // Check if it's within the valid range (0 to 100)
    if (numValue < 0 || numValue > 100) {
        return "Please enter a valid percentage between 0% and 100%.";
    }
    
    return null; // No error
}

// Add real-time validation on input
document.getElementById("returnFeeValue").addEventListener("input", function() {
    const value = this.value.trim();
    
    if (value === "") {
        clearValidationError();
        return;
    }
    
    const errorMessage = validateReturnFee(value);
    
    if (errorMessage) {
        showValidationError(errorMessage);
    } else {
        clearValidationError();
    }
});

// Add validation on blur (when user leaves the field)
document.getElementById("returnFeeValue").addEventListener("blur", function() {
    const value = this.value.trim();
    
    if (value !== "") {
        const errorMessage = validateReturnFee(value);
        if (errorMessage) {
            showValidationError(errorMessage);
        }
    }
});

// Handle form submission via AJAX
document.getElementById("returnFeeForm").addEventListener("submit", function(e) {
    e.preventDefault(); // prevent default form submission

    const feeValue = document.getElementById("returnFeeValue").value.trim();
    
    // Validate before submission
    if (feeValue === "") {
        showValidationError("Please enter a return fee value.");
        return;
    }
    
    const errorMessage = validateReturnFee(feeValue);
    if (errorMessage) {
        showValidationError(errorMessage);
        return;
    }
    
    // Clear any validation errors
    clearValidationError();
    
    // Disable submit button to prevent double submission
    const submitBtn = document.getElementById("saveReturnFeeBtn");
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const formData = new FormData(this);

    fetch(this.action, {
        method: "POST",
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        alert(data); // display success/error message from PHP
        closeReturnFeeModal();

        // Update the table value dynamically (optional, no reload)
        const courierId = document.getElementById("returnFeeCourierId").value;
        const feeValue = document.getElementById("returnFeeValue").value;

        const row = document.querySelector(`button[onclick*='${courierId}']`).closest("tr");
        if (row) {
           const feeCell = row.querySelector("td:nth-child(5)");
            if (feeCell) {
                feeCell.innerText = feeValue + "%";
            }
        }
    })
    .catch(err => {
        alert("Error: " + err);
        console.error("Error updating return fee:", err);
    })
    .finally(() => {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

</script>

</script>
</body>
</html>