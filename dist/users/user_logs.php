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
$user_name_filter = isset($_GET['user_name_filter']) ? trim($_GET['user_name_filter']) : '';
$action_type_filter = isset($_GET['action_type_filter']) ? trim($_GET['action_type_filter']) : '';
$inquiry_id_filter = isset($_GET['inquiry_id_filter']) ? trim($_GET['inquiry_id_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM user_logs ul 
             LEFT JOIN users u ON ul.user_id = u.id";

// Main query - joining user_logs with users table to get user names
$sql = "SELECT ul.id as log_id, ul.user_id, ul.action_type, ul.inquiry_id, 
               ul.details, ul.created_at,
               u.name as username, u.email as user_email
        FROM user_logs ul 
        LEFT JOIN users u ON ul.user_id = u.id";

// Build search conditions
$searchConditions = [];

// General search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        u.name LIKE '%$searchTerm%' OR 
                        ul.action_type LIKE '%$searchTerm%' OR 
                        ul.details LIKE '%$searchTerm%' OR
                        ul.inquiry_id LIKE '%$searchTerm%')";
}

// Specific User Name filter
if (!empty($user_name_filter)) {
    $userNameTerm = $conn->real_escape_string($user_name_filter);
    $searchConditions[] = "u.name LIKE '%$userNameTerm%'";
}

// Action Type filter
if (!empty($action_type_filter)) {
    $actionTypeTerm = $conn->real_escape_string($action_type_filter);
    $searchConditions[] = "ul.action_type = '$actionTypeTerm'";
}

// Inquiry ID filter
if (!empty($inquiry_id_filter)) {
    $inquiryIdTerm = $conn->real_escape_string($inquiry_id_filter);
    $searchConditions[] = "ul.inquiry_id = '$inquiryIdTerm'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(ul.created_at) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(ul.created_at) <= '$dateToTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY ul.created_at DESC LIMIT $limit OFFSET $offset";

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

// Get unique action types for filter dropdown
$action_types_sql = "SELECT DISTINCT action_type FROM user_logs WHERE action_type IS NOT NULL AND action_type != '' ORDER BY action_type";
$action_types_result = $conn->query($action_types_sql);
$action_types = [];
if ($action_types_result && $action_types_result->num_rows > 0) {
    $action_types = $action_types_result->fetch_all(MYSQLI_ASSOC);
}

// Get unique users for filter dropdown
$users_sql = "SELECT DISTINCT u.id, u.name FROM users u 
              INNER JOIN user_logs ul ON u.id = ul.user_id 
              WHERE u.name IS NOT NULL AND u.name != '' 
              ORDER BY u.name";
$users_result = $conn->query($users_sql);
$users_list = [];
if ($users_result && $users_result->num_rows > 0) {
    $users_list = $users_result->fetch_all(MYSQLI_ASSOC);
}

// Function to format details JSON
function formatLogDetails($details) {
    if (empty($details)) {
        return 'No details available';
    }
    
    // Try to decode JSON
    $decoded = json_decode($details, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $formatted = [];
        
        // Exclude unwanted fields
        $excludeFields = ['ip_address', 'user_agent'];
        
        foreach ($decoded as $key => $value) {
            if (!in_array($key, $excludeFields)) {
                $formattedKey = ucwords(str_replace('_', ' ', $key));
                if (is_array($value) || is_object($value)) {
                    $formattedValue = json_encode($value);
                } else {
                    $formattedValue = $value;
                }
                $formatted[] = "<strong>{$formattedKey}:</strong> {$formattedValue}";
            }
        }
        
        return implode('<br>', $formatted);
    }
    
    // If not JSON, return as is
    return htmlspecialchars($details);
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - User Activity Logs</title>
    
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
                        <h5 class="mb-0 font-medium">User Activity Logs</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- User Logs Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="user_name_filter">User Name</label>
                            <select id="user_name_filter" name="user_name_filter">
                                <option value="">All Users</option>
                                <?php foreach ($users_list as $user): ?>
                                    <option value="<?php echo htmlspecialchars($user['name']); ?>" 
                                            <?php echo $user_name_filter == $user['name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="action_type_filter">Action Type</label>
                            <select id="action_type_filter" name="action_type_filter">
                                <option value="">All Actions</option>
                                <?php foreach ($action_types as $action_type): ?>
                                    <option value="<?php echo htmlspecialchars($action_type['action_type']); ?>" 
                                            <?php echo $action_type_filter == $action_type['action_type'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($action_type['action_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="inquiry_id_filter">Inquiry ID</label>
                            <input type="number" id="inquiry_id_filter" name="inquiry_id_filter" 
                                   placeholder="Enter inquiry ID" 
                                   value="<?php echo htmlspecialchars($inquiry_id_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_from">Date From</label>
                            <input type="date" id="date_from" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to">Date To</label>
                            <input type="date" id="date_to" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
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

                <!-- Logs Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Activity Logs</div>
                </div>

                <!-- User Logs Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Log ID</th>
                                <th>User Info & Action</th>
                                <th>Inquiry ID</th>
                                <th>Details</th>
                                <th>Date & Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userLogsTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- Log ID -->
                                        <td>
                                            <div style="font-weight: 600; color: #007bff;">
                                                #<?php echo htmlspecialchars($row['log_id']); ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Combined User Info & Action -->
                                        <td class="user-action-combined">
                                            <div class="user-info-section">
                                                <h6 style="margin: 0 0 5px 0; font-size: 14px; font-weight: 600; color: #333;">
                                                    <?php echo htmlspecialchars($row['username'] ?: 'Unknown User'); ?>
                                                </h6>
                                                <small style="color: #6c757d; font-size: 12px; display: block;">
                                                    ID: <?php echo htmlspecialchars($row['user_id']); ?>
                                                </small>
                                                <?php if (!empty($row['user_email'])): ?>
                                                    <small style="color: #6c757d; font-size: 11px; display: block;">
                                                        <?php echo htmlspecialchars($row['user_email']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="action-section" style="margin-top: 8px;">
                                                <span class="status-badge <?php 
                                                    $action = strtolower($row['action_type']);
                                                    if (strpos($action, 'create') !== false || strpos($action, 'add') !== false) {
                                                        echo 'pay-status-paid'; // Green for create/add actions
                                                    } elseif (strpos($action, 'delete') !== false || strpos($action, 'remove') !== false) {
                                                        echo 'pay-status-unpaid'; // Red for delete/remove actions
                                                    } elseif (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) {
                                                        echo 'status-badge-warning'; // Orange for update/edit actions
                                                    } else {
                                                        echo 'status-badge-info'; // Blue for other actions
                                                    }
                                                ?>">
                                                    <?php echo htmlspecialchars($row['action_type']); ?>
                                                </span>
                                            </div>
                                        </td>
                                        
                                        <!-- Inquiry ID -->
                                        <td>
                                            <?php if (!empty($row['inquiry_id'])): ?>
                                                <div style="font-weight: 500; color: #495057;">
                                                    #<?php echo htmlspecialchars($row['inquiry_id']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #6c757d; font-style: italic;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Details -->
                                        <td>
                                            <div class="details-container" style="max-width: 250px;">
                                                <?php 
                                                $formattedDetails = formatLogDetails($row['details']);
                                                if (strlen($formattedDetails) > 150) {
                                                    echo '<div class="details-short">' . substr(strip_tags($formattedDetails), 0, 150) . '...</div>';
                                                    echo '<div class="details-full" style="display: none;">' . $formattedDetails . '</div>';
                                                    echo '<a href="#" class="toggle-details" style="color: #007bff; font-size: 12px;">Show More</a>';
                                                } else {
                                                    echo '<div>' . $formattedDetails . '</div>';
                                                }
                                                ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Date & Time -->
                                        <td>
                                            <div style="font-size: 12px; line-height: 1.4;">
                                                <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                                                <div style="color: #6c757d;"><?php echo date('h:i:s A', strtotime($row['created_at'])); ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-log-btn"
                                                        data-log-id="<?= $row['log_id'] ?>"
                                                        data-user-id="<?= $row['user_id'] ?>"
                                                        data-username="<?= htmlspecialchars($row['username'] ?: 'Unknown User') ?>"
                                                        data-user-email="<?= htmlspecialchars($row['user_email'] ?: '') ?>"
                                                        data-action-type="<?= htmlspecialchars($row['action_type']) ?>"
                                                        data-inquiry-id="<?= htmlspecialchars($row['inquiry_id'] ?: '') ?>"
                                                        data-details="<?= htmlspecialchars($row['details'] ?: '') ?>"
                                                        data-created-at="<?= htmlspecialchars($row['created_at']) ?>"
                                                        title="View Log Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                             
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No activity logs found
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&action_type_filter=<?php echo urlencode($action_type_filter); ?>&inquiry_id_filter=<?php echo urlencode($inquiry_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&action_type_filter=<?php echo urlencode($action_type_filter); ?>&inquiry_id_filter=<?php echo urlencode($inquiry_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&action_type_filter=<?php echo urlencode($action_type_filter); ?>&inquiry_id_filter=<?php echo urlencode($inquiry_id_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Details Modal -->
    <div id="logDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Activity Log Details</h4>
                <span class="close" onclick="closeLogModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">Log ID:</span>
                    <span class="detail-value" id="modal-log-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">User:</span>
                    <span class="detail-value" id="modal-username"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">User ID:</span>
                    <span class="detail-value" id="modal-user-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">User Email:</span>
                    <span class="detail-value" id="modal-user-email"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Action Type:</span>
                    <span class="detail-value">
                        <span id="modal-action-type" class="status-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Inquiry ID:</span>
                    <span class="detail-value" id="modal-inquiry-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Details:</span>
                    <span class="detail-value" id="modal-details"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Date & Time:</span>
                    <span class="detail-value" id="modal-created-at"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <script>
// Complete JavaScript code for user logs page

function clearFilters() {
    window.location.href = 'user_logs.php';
}

// Log Details Modal Functions
function openLogModal(button) {
    const modal = document.getElementById('logDetailsModal');
    
    // Extract data from button attributes
    const logId = button.getAttribute('data-log-id');
    const userId = button.getAttribute('data-user-id');
    const username = button.getAttribute('data-username');
    const userEmail = button.getAttribute('data-user-email');
    const actionType = button.getAttribute('data-action-type');
    const inquiryId = button.getAttribute('data-inquiry-id');
    const details = button.getAttribute('data-details');
    const createdAt = button.getAttribute('data-created-at');

    // Populate modal fields
    document.getElementById('modal-log-id').textContent = '#' + logId;
    document.getElementById('modal-username').textContent = username || 'Unknown User';
    document.getElementById('modal-user-id').textContent = userId;
    document.getElementById('modal-user-email').textContent = userEmail || 'N/A';
    document.getElementById('modal-inquiry-id').textContent = inquiryId ? '#' + inquiryId : 'N/A';
    
    // Format details for modal
    document.getElementById('modal-details').innerHTML = formatDetailsForModal(details);
    document.getElementById('modal-created-at').textContent = formatDateTime(createdAt);
    
    // Set action type badge
    const actionTypeElement = document.getElementById('modal-action-type');
    actionTypeElement.textContent = actionType;
    
    // Set appropriate badge class based on action type
    const action = actionType.toLowerCase();
    if (action.includes('create') || action.includes('add')) {
        actionTypeElement.className = 'status-badge pay-status-paid';
    } else if (action.includes('delete') || action.includes('remove')) {
        actionTypeElement.className = 'status-badge pay-status-unpaid';
    } else if (action.includes('update') || action.includes('edit')) {
        actionTypeElement.className = 'status-badge status-badge-warning';
    } else {
        actionTypeElement.className = 'status-badge status-badge-info';
    }

    // Show modal
    modal.style.display = 'block';
}

function formatDetailsForModal(details) {
    if (!details) return 'No details available';
    
    try {
        const decoded = JSON.parse(details);
        if (typeof decoded === 'object' && decoded !== null) {
            const excludeFields = ['ip_address', 'user_agent'];
            const formatted = [];
            
            for (const [key, value] of Object.entries(decoded)) {
                if (!excludeFields.includes(key)) {
                    const formattedKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    const formattedValue = typeof value === 'object' ? JSON.stringify(value) : value;
                    formatted.push(`<strong>${formattedKey}:</strong> ${formattedValue}`);
                }
            }
            
            return formatted.join('<br>');
        }
    } catch (e) {
        // Not JSON, return as is
    }
    
    return details;
}

function closeLogModal() {
    document.getElementById('logDetailsModal').style.display = 'none';
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

// View Related Inquiry Function
function viewInquiry(inquiryId) {
    // Redirect to inquiries page with specific inquiry ID
    window.location.href = `inquiries.php?inquiry_id=${inquiryId}`;
}

// Toggle Details Function
function toggleDetails(element) {
    const container = element.closest('.details-container');
    const shortText = container.querySelector('.details-short');
    const fullText = container.querySelector('.details-full');
    
    if (fullText.style.display === 'none') {
        shortText.style.display = 'none';
        fullText.style.display = 'block';
        element.textContent = 'Show Less';
    } else {
        shortText.style.display = 'block';
        fullText.style.display = 'none';
        element.textContent = 'Show More';
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // View log button event listeners
    const viewButtons = document.querySelectorAll('.view-log-btn');
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            openLogModal(this);
        });
    });
    
    // Toggle details event listeners
    const toggleDetailsButtons = document.querySelectorAll('.toggle-details');
    toggleDetailsButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            toggleDetails(this);
        });
    });
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const logModal = document.getElementById('logDetailsModal');
        
        if (event.target === logModal) {
            closeLogModal();
        }
    };
    
    // Escape key to close modal
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeLogModal();
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

// Auto-submit search on Enter key for text inputs
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = document.querySelectorAll('#inquiry_id_filter, #date_from, #date_to');
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