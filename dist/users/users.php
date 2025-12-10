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

// Check if user has admin role (role_id = 1)
if (!isset($_SESSION['user_id'])) {
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

// If we reach here, user is admin - continue with the original functionality
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

// Handle search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$user_name_filter = isset($_GET['user_name_filter']) ? trim($_GET['user_name_filter']) : '';
$email_filter = isset($_GET['email_filter']) ? trim($_GET['email_filter']) : '';
$phone_filter = isset($_GET['phone_filter']) ? trim($_GET['phone_filter']) : '';
$nic_filter = isset($_GET['nic_filter']) ? trim($_GET['nic_filter']) : '';
$role_filter = isset($_GET['role_filter']) ? trim($_GET['role_filter']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM users";

// Main query - updated to match your actual database schema
$sql = "SELECT u.id as user_id, u.name as username, u.name as full_name, u.email, u.mobile as phone, 
               u.nic, r.name as role, u.status, u.commission_per_parcel, u.percentage_drawdown, 
               u.commission_type, u.created_at, u.updated_at 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id";

// Build search conditions
$searchConditions = [];

// General search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        u.name LIKE '%$searchTerm%' OR 
                        u.email LIKE '%$searchTerm%' OR 
                        u.mobile LIKE '%$searchTerm%' OR 
                        u.nic LIKE '%$searchTerm%' OR
                        r.name LIKE '%$searchTerm%')";
}

// Specific User Name filter
if (!empty($user_name_filter)) {
    $userNameTerm = $conn->real_escape_string($user_name_filter);
    $searchConditions[] = "u.name LIKE '%$userNameTerm%'";
}

// Specific Email filter
if (!empty($email_filter)) {
    $emailTerm = $conn->real_escape_string($email_filter);
    $searchConditions[] = "u.email LIKE '%$emailTerm%'";
}

// Specific Phone filter
if (!empty($phone_filter)) {
    $phoneTerm = $conn->real_escape_string($phone_filter);
    $searchConditions[] = "u.mobile LIKE '%$phoneTerm%'";
}

// Specific NIC filter
if (!empty($nic_filter)) {
    $nicTerm = $conn->real_escape_string($nic_filter);
    $searchConditions[] = "u.nic LIKE '%$nicTerm%'";
}

// Role filter
if (!empty($role_filter)) {
    $roleTerm = $conn->real_escape_string($role_filter);
    $searchConditions[] = "r.name = '$roleTerm'";
}

// Status filter
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "u.status = '$statusTerm'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(u.created_at) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(u.created_at) <= '$dateToTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql = "SELECT COUNT(*) as total FROM users u LEFT JOIN roles r ON u.role_id = r.id" . $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY u.created_at DESC LIMIT $limit OFFSET $offset";

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

// Get unique roles for filter dropdown
$role_sql = "SELECT DISTINCT r.name as role FROM roles r WHERE r.name IS NOT NULL AND r.name != '' ORDER BY r.name";
$role_result = $conn->query($role_sql);
$roles = [];
if ($role_result && $role_result->num_rows > 0) {
    $roles = $role_result->fetch_all(MYSQLI_ASSOC);
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - User Management</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
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
                        <h5 class="mb-0 font-medium">User Management</h5>
                        <small class="text-muted">Administrator Access</small>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- User Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="user_name_filter">User Name</label>
                            <input type="text" id="user_name_filter" name="user_name_filter" 
                                   placeholder="Enter username" 
                                   value="<?php echo htmlspecialchars($user_name_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="email_filter">Email</label>
                            <input type="text" id="email_filter" name="email_filter" 
                                   placeholder="Enter email" 
                                   value="<?php echo htmlspecialchars($email_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="phone_filter">Phone</label>
                            <input type="text" id="phone_filter" name="phone_filter" 
                                   placeholder="Enter phone number" 
                                   value="<?php echo htmlspecialchars($phone_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="nic_filter">NIC Number</label>
                            <input type="text" id="nic_filter" name="nic_filter" 
                                   placeholder="Enter NIC number" 
                                   value="<?php echo htmlspecialchars($nic_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="role_filter">Role</label>
                            <select id="role_filter" name="role_filter">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role['role']); ?>" 
                                            <?php echo $role_filter == $role['role'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($status_filter == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <div class="button-group">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i>
                                    Search
                                </button>
                                <button type="button" class="search-btn" onclick="clearFilters()" style="background: #6c757d;">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- User Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Users</div>
                </div>

                <!-- Users Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>User Info</th>
                                <th>Contact & NIC</th>
                                <th>Role & Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- User Info -->
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($row['username']); ?></h6>
                                                <small style="color: #6c757d; font-size: 12px;">ID: <?php echo htmlspecialchars($row['user_id']); ?></small>
                                            </div>
                                        </td>
                                        
                                        <!-- Contact Info & NIC -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <div style="font-weight: 500; margin-bottom: 2px;"><?php echo htmlspecialchars($row['phone'] ?: 'N/A'); ?></div>
                                                <div style="font-size: 12px; color: #6c757d; margin-bottom: 2px;"><?php echo htmlspecialchars($row['email']); ?></div>
                                                <?php if (!empty($row['nic'])): ?>
                                                    <div style="font-size: 11px; color: #007bff; font-weight: 500;">NIC: <?php echo htmlspecialchars($row['nic']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Role & Status -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <div style="font-weight: 500; margin-bottom: 4px; color: #495057;">
                                                    <?php echo htmlspecialchars($row['role'] ?: 'User'); ?>
                                                </div>
                                                <?php if ($row['status'] === 'active'): ?>
                                                    <span class="status-badge pay-status-paid">Active</span>
                                                <?php else: ?>
                                                    <span class="status-badge pay-status-unpaid">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Created -->
                                        <td>
                                            <div style="font-size: 12px; line-height: 1.4;">
                                                <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                                                <div style="color: #6c757d;"><?php echo date('h:i A', strtotime($row['created_at'])); ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-user-btn"
                                                        data-user-id="<?= $row['user_id'] ?>"
                                                        data-username="<?= htmlspecialchars($row['username']) ?>"
                                                        data-full-name="<?= htmlspecialchars($row['username']) ?>"
                                                        data-user-email="<?= htmlspecialchars($row['email']) ?>"
                                                        data-user-phone="<?= htmlspecialchars($row['phone']) ?>"
                                                        data-user-nic="<?= htmlspecialchars($row['nic']) ?>"
                                                        data-user-role="<?= htmlspecialchars($row['role']) ?>"
                                                        data-user-status="<?= htmlspecialchars($row['status']) ?>"
                                                        data-user-commission-type="<?= htmlspecialchars($row['commission_type']) ?>"
                                                        data-user-commission-per-parcel="<?= htmlspecialchars($row['commission_per_parcel']) ?>"
                                                        data-user-percentage-drawdown="<?= htmlspecialchars($row['percentage_drawdown']) ?>"
                                                        data-user-created="<?= htmlspecialchars($row['created_at']) ?>"
                                                        data-user-updated="<?= htmlspecialchars($row['updated_at']) ?>"
                                                        title="View User Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button class="action-btn dispatch-btn" title="Edit User" 
                                                        onclick="editUser(<?php echo $row['user_id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                          
                                                <!-- Status Toggle Button -->
                                                <button type="button" class="action-btn <?= $row['status'] == 'active' ? 'deactivate-btn' : 'activate-btn' ?> toggle-status-btn"
                                                        data-user-id="<?= $row['user_id'] ?>"
                                                        data-current-status="<?= $row['status'] ?>"
                                                        data-user-name="<?= htmlspecialchars($row['username']) ?>"
                                                        title="<?= $row['status'] == 'active' ? 'Deactivate User' : 'Activate User' ?>"
                                                        data-action="<?= $row['status'] == 'active' ? 'deactivate' : 'activate' ?>">
                                                    <i class="fas <?= $row['status'] == 'active' ? 'fa-user-times' : 'fa-user-check' ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No users found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&nic_filter=<?php echo urlencode($nic_filter); ?>&role_filter=<?php echo urlencode($role_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&nic_filter=<?php echo urlencode($nic_filter); ?>&role_filter=<?php echo urlencode($role_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&nic_filter=<?php echo urlencode($nic_filter); ?>&role_filter=<?php echo urlencode($role_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Details Modal -->
    <div id="userDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>User Details</h4>
                <span class="close" onclick="closeUserModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">User ID:</span>
                    <span class="detail-value" id="modal-user-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value" id="modal-username"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value" id="modal-user-email"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value" id="modal-user-phone"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">NIC Number:</span>
                    <span class="detail-value" id="modal-user-nic"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Role:</span>
                    <span class="detail-value" id="modal-user-role"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span id="modal-user-status" class="status-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value" id="modal-user-created"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Last Updated:</span>
                    <span class="detail-value" id="modal-user-updated"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Confirmation Modal -->
    <div id="statusConfirmationModal" class="modal confirmation-modal">
        <div class="modal-content confirmation-modal-content">
            <div class="modal-header">
                <h4>Are you sure?</h4>
                <span class="close" onclick="closeConfirmationModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">
                    <i class="ti ti-alert-triangle"></i>
                </div>
                <div class="confirmation-text">
                    You are about to <span class="action-highlight" id="action-text"></span> user:
                </div>
                <div class="confirmation-text">
                    <span class="user-name-highlight" id="confirm-user-name"></span>
                </div>
                <div class="modal-buttons">
                    <button class="btn-confirm" id="confirmActionBtn">
                        <span id="confirm-button-text">Yes, deactivate user!</span>
                    </button>
                    <button class="btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
// Complete JavaScript code for user management page

function clearFilters() {
    window.location.href = 'users.php';
}

// User Details Modal Functions
function openUserModal(button) {
    const modal = document.getElementById('userDetailsModal');
    
    // Extract data from button attributes
    const userId = button.getAttribute('data-user-id');
    const username = button.getAttribute('data-username');
    const userEmail = button.getAttribute('data-user-email');
    const userPhone = button.getAttribute('data-user-phone');
    const userNic = button.getAttribute('data-user-nic');
    const userRole = button.getAttribute('data-user-role');
    const userStatus = button.getAttribute('data-user-status');
    const userCreated = button.getAttribute('data-user-created');
    const userUpdated = button.getAttribute('data-user-updated');

    // Populate modal fields
    document.getElementById('modal-user-id').textContent = userId;
    document.getElementById('modal-username').textContent = username || 'N/A';
    document.getElementById('modal-user-email').textContent = userEmail;
    document.getElementById('modal-user-phone').textContent = userPhone || 'N/A';
    document.getElementById('modal-user-nic').textContent = userNic || 'N/A';
    document.getElementById('modal-user-role').textContent = userRole || 'User';
    
    // Set status badge
    const statusElement = document.getElementById('modal-user-status');
    statusElement.textContent = userStatus === 'active' ? 'Active' : 'Inactive';
    if (userStatus === 'active') {
        statusElement.className = 'status-badge pay-status-paid';
    } else {
        statusElement.className = 'status-badge pay-status-unpaid';
    }
    
    // Format dates
    document.getElementById('modal-user-created').textContent = formatDateTime(userCreated);
    document.getElementById('modal-user-updated').textContent = formatDateTime(userUpdated);

    // Show modal
    modal.style.display = 'block';
}

function closeUserModal() {
    document.getElementById('userDetailsModal').style.display = 'none';
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleString();
    } catch (e) {
        return dateString;
    }
}

// Status Toggle Functions - Updated to match customer page style
function closeConfirmationModal() {
    document.getElementById('statusConfirmationModal').style.display = 'none';
}

function openConfirmationModal(button) {
    const userId = button.getAttribute('data-user-id');
    const userName = button.getAttribute('data-user-name');
    const currentStatus = button.getAttribute('data-current-status');
    
    // Determine action based on current status
    const isActive = currentStatus.toLowerCase() === 'active';
    const actionText = isActive ? 'deactivate' : 'activate';
    const buttonText = isActive ? 'Yes, deactivate user!' : 'Yes, activate user!';
    
    // Update modal content
    document.getElementById('action-text').textContent = actionText;
    document.getElementById('confirm-user-name').textContent = userName;
    document.getElementById('confirm-button-text').textContent = buttonText;
    
    // Store data for confirmation
    const confirmBtn = document.getElementById('confirmActionBtn');
    confirmBtn.setAttribute('data-user-id', userId);
    confirmBtn.setAttribute('data-new-status', isActive ? 'inactive' : 'active');
    
    // Add click handler to confirm button
    confirmBtn.onclick = function() {
        toggleUserStatus(userId, isActive ? 'inactive' : 'active');
    };
    
    // Show modal
    document.getElementById('statusConfirmationModal').style.display = 'block';
}

function toggleUserStatus(userId, newStatus) {
    fetch('toggle_user_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: userId,
            new_status: newStatus
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close confirmation modal
            closeConfirmationModal();
            
            // Show success message
            alert('User status updated successfully!');
            
            // Reload page to reflect changes
            location.reload();
        } else {
            alert('Error updating user status: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating the user status.');
    });
}

// Edit User Function
function editUser(userId) {
    window.location.href = `edit_user.php?id=${userId}`;
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // View user button event listeners
    const viewButtons = document.querySelectorAll('.view-user-btn');
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            openUserModal(this);
        });
    });
    
    // Status toggle button event listeners
    const statusToggleButtons = document.querySelectorAll('.toggle-status-btn');
    statusToggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            openConfirmationModal(this);
        });
    });
    
    // Close modals when clicking outside
    window.onclick = function(event) {
        const userModal = document.getElementById('userDetailsModal');
        const statusModal = document.getElementById('statusConfirmationModal');
        
        if (event.target === userModal) {
            closeUserModal();
        }
        if (event.target === statusModal) {
            closeConfirmationModal();
        }
    };
    
    // Escape key to close modals
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeUserModal();
            closeConfirmationModal();
        }
    });
});

// Search functionality (if needed)
function performSearch() {
    const searchForm = document.querySelector('.tracking-form');
    if (searchForm) {
        searchForm.submit();
    }
}

// Auto-submit search on Enter key
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = document.querySelectorAll('#user_name_filter, #email_filter, #phone_filter, #nic_filter');
    searchInputs.forEach(input => {
        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                performSearch();
            }
        });
    });
});
</script>

</body>
</html>