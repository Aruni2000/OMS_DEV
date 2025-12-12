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
$customer_id_filter = isset($_GET['customer_id_filter']) ? trim($_GET['customer_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$email_filter = isset($_GET['email_filter']) ? trim($_GET['email_filter']) : '';
$phone_filter = isset($_GET['phone_filter']) ? trim($_GET['phone_filter']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$city_filter = isset($_GET['city_filter']) ? trim($_GET['city_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records with city join
$countSql = "SELECT COUNT(*) as total FROM customers c LEFT JOIN city_table ct ON c.city_id = ct.city_id";

// Main query with city join
$sql = "SELECT c.customer_id, c.name, c.email, c.phone, c.phone2, c.address_line1, c.address_line2, 
        c.city_id, ct.city_name, c.status, c.created_at, c.updated_at 
        FROM customers c
        LEFT JOIN city_table ct ON c.city_id = ct.city_id";

// Build search conditions
$searchConditions = [];

// General search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        c.customer_id LIKE '%$searchTerm%' OR
                        c.name LIKE '%$searchTerm%' OR 
                        c.email LIKE '%$searchTerm%' OR 
                        c.phone LIKE '%$searchTerm%' OR 
                        c.address_line1 LIKE '%$searchTerm%' OR
                        c.address_line2 LIKE '%$searchTerm%' OR
                        ct.city_name LIKE '%$searchTerm%')";
}

// Specific Customer ID filter
if (!empty($customer_id_filter)) {
    $customerIdTerm = $conn->real_escape_string($customer_id_filter);
    $searchConditions[] = "c.customer_id = '$customerIdTerm'";
}

// Specific Customer Name filter
if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "c.name LIKE '%$customerNameTerm%'";
}

// Specific Email filter
if (!empty($email_filter)) {
    $emailTerm = $conn->real_escape_string($email_filter);
    $searchConditions[] = "c.email LIKE '%$emailTerm%'";
}

// Specific Phone filter
if (!empty($phone_filter)) {
    $phoneTerm = $conn->real_escape_string($phone_filter);
    $searchConditions[] = "c.phone LIKE '%$phoneTerm%'";
}

// Status filter
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "c.status = '$statusTerm'";
}

// City filter
if (!empty($city_filter)) {
    $cityTerm = $conn->real_escape_string($city_filter);
    $searchConditions[] = "c.city_id = '$cityTerm'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(c.created_at) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(c.created_at) <= '$dateToTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

// Get unique cities for filter dropdown
$city_sql = "SELECT DISTINCT c.city_id, ct.city_name 
             FROM customers c 
             LEFT JOIN city_table ct ON c.city_id = ct.city_id 
             WHERE c.city_id IS NOT NULL AND c.city_id != '' 
             ORDER BY ct.city_name";
$city_result = $conn->query($city_sql);
$cities = $city_result->fetch_all(MYSQLI_ASSOC);
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Customer Management</title>
    
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
                        <h5 class="mb-0 font-medium">Customer Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Customer Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="customer_id_filter">Customer ID</label>
                            <input type="number" id="customer_id_filter" name="customer_id_filter" 
                                   placeholder="Enter customer ID" 
                                   value="<?php echo htmlspecialchars($customer_id_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="customer_name_filter">Customer Name</label>
                            <input type="text" id="customer_name_filter" name="customer_name_filter" 
                                   placeholder="Enter customer name" 
                                   value="<?php echo htmlspecialchars($customer_name_filter); ?>">
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
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="Active" <?php echo ($status_filter == 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo ($status_filter == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="city_filter">City</label>
                            <select id="city_filter" name="city_filter">
                                <option value="">All Cities</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo htmlspecialchars($city['city_id']); ?>" 
                                            <?php echo $city_filter == $city['city_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($city['city_name'] ? $city['city_name'] : 'City ' . $city['city_id']); ?>
                                    </option>
                                <?php endforeach; ?>
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
                <!-- Customer Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Customers</div>
                </div>

                <!-- Customers Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer Name</th>
                                <th>Phone & Email</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="customersTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- Customer ID -->
                                        <td><?php echo htmlspecialchars($row['customer_id']); ?></td>
                                        
                                        <!-- Customer Name -->
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px;"><?php echo htmlspecialchars($row['name']); ?></h6>
                                            </div>
                                        </td>
                                        
                                        <!-- Phone & Email (Combined Column) -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <div style="font-weight: 500; margin-bottom: 2px;"><?php echo htmlspecialchars($row['phone']); ?></div>
                                                <div style="font-size: 12px; color: #6c757d;"><?php echo htmlspecialchars($row['email']); ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Address -->
                                        <td>
                                            <div class="address-truncate" title="<?php echo htmlspecialchars($row['address_line1'] . ($row['address_line2'] ? ', ' . $row['address_line2'] : '') . ($row['city_name'] ? ', ' . $row['city_name'] : '')); ?>">
                                                <?php echo htmlspecialchars($row['address_line1']); ?>
                                                <?php if (!empty($row['address_line2'])): ?>
                                                    <br><small style="color: #6c757d;"><?php echo htmlspecialchars($row['address_line2']); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($row['city_name'])): ?>
                                                    <br><small style="color: #007bff; font-weight: 500;"><?php echo htmlspecialchars($row['city_name']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Status Badge -->
                                        <td>
                                            <?php if ($row['status'] === 'Active'): ?>
                                                <span class="status-badge pay-status-paid">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge pay-status-unpaid">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-customer-btn"
                                                        data-customer-id="<?= $row['customer_id'] ?>"
                                                        data-customer-name="<?= htmlspecialchars($row['name']) ?>"
                                                        data-customer-email="<?= htmlspecialchars($row['email']) ?>"
                                                        data-customer-phone="<?= htmlspecialchars($row['phone']) ?>"
                                        data-customer-secondary-phone="<?= htmlspecialchars($row['phone2'] ?? '') ?>"
                                                        data-customer-address1="<?= htmlspecialchars($row['address_line1']) ?>"
                                                        data-customer-address2="<?= htmlspecialchars($row['address_line2'] ?? '') ?>"
                                                        data-customer-city="<?= htmlspecialchars($row['city_id']) ?>"
                                                        data-customer-city-name="<?= htmlspecialchars($row['city_name'] ?? '') ?>"
                                                        data-customer-status="<?= htmlspecialchars($row['status']) ?>"
                                                        data-customer-created="<?= htmlspecialchars($row['created_at']) ?>"
                                                        data-customer-updated="<?= htmlspecialchars($row['updated_at']) ?>"
                                                        title="View Customer Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button class="action-btn dispatch-btn" title="Edit Customer" 
                                                        onclick="editCustomer(<?php echo $row['customer_id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                          
                                            <!-- Fixed Button with Correct Logic -->
                                             <button type="button" class="action-btn <?= $row['status'] == 'Active' ? 'deactivate-btn' : 'activate-btn' ?> toggle-status-btn"
                                              data-customer-id="<?= $row['customer_id'] ?>"
                                              data-current-status="<?= $row['status'] ?>"
                                              data-customer-name="<?= htmlspecialchars($row['name']) ?>"
                                               title="<?= $row['status'] == 'Active' ? 'Deactivate Customer' : 'Activate Customer' ?>"
                                               data-action="<?= $row['status'] == 'Active' ? 'deactivate' : 'activate' ?>">
                                                   <i class="fas <?= $row['status'] == 'Active' ? 'fa-user-times' : 'fa-user-check' ?>"></i>
                                            </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No customers found
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&city_filter=<?php echo urlencode($city_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&city_filter=<?php echo urlencode($city_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&city_filter=<?php echo urlencode($city_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Details Modal -->
    <div id="customerDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Customer Details</h4>
                <span class="close" onclick="closeCustomerModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">Customer ID:</span>
                    <span class="detail-value" id="modal-customer-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Full Name:</span>
                    <span class="detail-value" id="modal-customer-name"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value" id="modal-customer-email"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value" id="modal-customer-phone"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Secondary Phone:</span>
                    <span class="detail-value" id="modal-customer-secondary-phone"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Address:</span>
                    <span class="detail-value">
                        <div class="address-display" id="modal-customer-address"></div>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">City:</span>
                    <span class="detail-value" id="modal-customer-city"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Postal Code:</span>
                    <span class="detail-value" id="modal-customer-postal"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span id="modal-customer-status" class="status-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value" id="modal-customer-created"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Last Updated:</span>
                    <span class="detail-value" id="modal-customer-updated"></span>
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
        function clearFilters() {
            window.location.href = 'customer_list.php';
        }

        // Customer Details Modal Functions
        function openCustomerModal(button) {
            const modal = document.getElementById('customerDetailsModal');
            
            // Extract data from button attributes
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name');
            const customerEmail = button.getAttribute('data-customer-email');
            const customerPhone = button.getAttribute('data-customer-phone');
            const customerSecondaryPhone = button.getAttribute('data-customer-secondary-phone'); 
            const customerAddress1 = button.getAttribute('data-customer-address1');
            const customerAddress2 = button.getAttribute('data-customer-address2');
            const customerCity = button.getAttribute('data-customer-city');
            const customerCityName = button.getAttribute('data-customer-city-name');
            const customerPostal = button.getAttribute('data-customer-postal');
            const customerStatus = button.getAttribute('data-customer-status');
            const customerCreated = button.getAttribute('data-customer-created');
            const customerUpdated = button.getAttribute('data-customer-updated');

            // Populate modal fields
            document.getElementById('modal-customer-id').textContent = customerId;
            document.getElementById('modal-customer-name').textContent = customerName;
            document.getElementById('modal-customer-email').textContent = customerEmail;
            document.getElementById('modal-customer-phone').textContent = customerPhone;
            document.getElementById('modal-customer-secondary-phone').textContent = customerSecondaryPhone ? customerSecondaryPhone : 'N/A'; 
            
            // Build address display
            let addressDisplay = customerAddress1;
            if (customerAddress2 && customerAddress2.trim() !== '') {
                addressDisplay += `<span class="address-line">${customerAddress2}</span>`;
            }
            document.getElementById('modal-customer-address').innerHTML = addressDisplay;
            
            document.getElementById('modal-customer-city').textContent = customerCityName || customerCity || 'N/A';
            document.getElementById('modal-customer-postal').textContent = customerPostal || 'N/A';
            
            // Set status badge
            const statusElement = document.getElementById('modal-customer-status');
            statusElement.textContent = customerStatus;
            statusElement.className = 'status-badge ' + (customerStatus === 'Active' ? 'status-active' : 'status-inactive');
            
            // Format dates
            document.getElementById('modal-customer-created').textContent = formatDateTime(customerCreated);
            document.getElementById('modal-customer-updated').textContent = formatDateTime(customerUpdated);

            // Show modal
            modal.style.display = 'block';
        }

        function closeCustomerModal() {
            document.getElementById('customerDetailsModal').style.display = 'none';
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

        // Event listeners for view buttons
        document.addEventListener('DOMContentLoaded', function() {
            const viewButtons = document.querySelectorAll('.view-customer-btn');
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    openCustomerModal(this);
                });
            });

            // Close modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('customerDetailsModal');
                if (event.target === modal) {
                    closeCustomerModal();
                }
            }
        });

        function editCustomer(customerId) {
            window.location.href = 'edit_customer.php?id=' + customerId;
        }

        function deleteCustomer(customerId, customerName) {
            if (confirm('Are you sure you want to delete customer "' + customerName + '"? This action cannot be undone.')) {
                fetch('delete_customer.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        customer_id: customerId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Customer deleted successfully!');
                        location.reload();
                    } else {
                        alert('Error deleting customer: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while deleting the customer.');
                });
            }
        }

        function closeConfirmationModal() {
            document.getElementById('statusConfirmationModal').style.display = 'none';
        }

        // Toggle Customer Status Functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listeners for toggle status buttons
            const toggleButtons = document.querySelectorAll('.toggle-status-btn');
            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    openStatusConfirmationModal(this);
                });
            });
            
            // Close modal when clicking outside
            window.onclick = function(event) {
                const customerModal = document.getElementById('customerDetailsModal');
                const statusModal = document.getElementById('statusConfirmationModal');
                
                if (event.target === customerModal) {
                    closeCustomerModal();
                }
                if (event.target === statusModal) {
                    closeConfirmationModal();
                }
            }
        });

        function openStatusConfirmationModal(button) {
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name');
            const currentStatus = button.getAttribute('data-current-status');
            
            // Determine action based on current status
            const isActive = currentStatus.toLowerCase() === 'active';
            const actionText = isActive ? 'deactivate' : 'activate';
            const buttonText = isActive ? 'Yes, deactivate user!' : 'Yes, activate user!';
            
            // Update modal content
            document.getElementById('action-text').textContent = actionText;
            document.getElementById('confirm-user-name').textContent = customerName;
            document.getElementById('confirm-button-text').textContent = buttonText;
            
            // Store data for confirmation
            const confirmBtn = document.getElementById('confirmActionBtn');
            confirmBtn.setAttribute('data-customer-id', customerId);
            confirmBtn.setAttribute('data-new-status', isActive ? 'Inactive' : 'Active');
            
            // Add click handler to confirm button
            confirmBtn.onclick = function() {
                toggleCustomerStatus(customerId, isActive ? 'Inactive' : 'Active');};
            
            // Show modal
            document.getElementById('statusConfirmationModal').style.display = 'block';
        }

        function toggleCustomerStatus(customerId, newStatus) {
            fetch('toggle_customer_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    customer_id: customerId,
                    new_status: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close confirmation modal
                    closeConfirmationModal();
                    
                    // Show success message
                    alert('Customer status updated successfully!');
                    
                    // Reload page to reflect changes
                    location.reload();
                } else {
                    alert('Error updating customer status: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the customer status.');
            });
        }

        // Date range filter validation
        document.addEventListener('DOMContentLoaded', function() {
            const dateFromInput = document.getElementById('date_from');
            const dateToInput = document.getElementById('date_to');
            
            if (dateFromInput && dateToInput) {
                dateFromInput.addEventListener('change', function() {
                    if (this.value && dateToInput.value && new Date(this.value) > new Date(dateToInput.value)) {
                        alert('From date cannot be later than To date');
                        this.value = '';
                    }
                });
                
                dateToInput.addEventListener('change', function() {
                    if (this.value && dateFromInput.value && new Date(this.value) < new Date(dateFromInput.value)) {
                        alert('To date cannot be earlier than From date');
                        this.value = '';
                    }
                });
            }
        });

        // Add loading states for buttons
        function addLoadingState(button) {
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            button.disabled = true;
            
            return function() {
                button.innerHTML = originalText;
                button.disabled = false;
            };
        }

        // Enhanced search with debouncing
        let searchTimeout;
        function debounceSearch(func, delay) {
            return function(...args) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => func.apply(this, args), delay);
            };
        }

        // Auto-submit search form with debouncing
        document.addEventListener('DOMContentLoaded', function() {
            const searchInputs = document.querySelectorAll('#customer_name_filter, #email_filter, #phone_filter');
            const debouncedSubmit = debounceSearch(function() {
                document.querySelector('.tracking-form').submit();
            }, 500);
            
            searchInputs.forEach(input => {
                input.addEventListener('input', debouncedSubmit);
            });
        });
    </script>


</body>
</html>