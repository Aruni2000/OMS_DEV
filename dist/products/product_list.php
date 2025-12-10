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
$product_id_filter = isset($_GET['product_id_filter']) ? trim($_GET['product_id_filter']) : '';
$product_name_filter = isset($_GET['product_name_filter']) ? trim($_GET['product_name_filter']) : '';
$product_code_filter = isset($_GET['product_code_filter']) ? trim($_GET['product_code_filter']) : '';
$description_filter = isset($_GET['description_filter']) ? trim($_GET['description_filter']) : '';
$price_from = isset($_GET['price_from']) ? trim($_GET['price_from']) : '';
$price_to = isset($_GET['price_to']) ? trim($_GET['price_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM products";

// Main query - Updated to include product_code
$sql = "SELECT id, name, product_code, description, lkr_price, created_at, status FROM products";

// Build search conditions
$searchConditions = [];

// General search condition - Updated to include product_code and id
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        id LIKE '%$searchTerm%' OR
                        name LIKE '%$searchTerm%' OR 
                        product_code LIKE '%$searchTerm%' OR 
                        description LIKE '%$searchTerm%' OR 
                        lkr_price LIKE '%$searchTerm%')";
}

// Specific Product ID filter
if (!empty($product_id_filter)) {
    $productIdTerm = $conn->real_escape_string($product_id_filter);
    $searchConditions[] = "id = '$productIdTerm'";
}

// Specific Product Name filter
if (!empty($product_name_filter)) {
    $productNameTerm = $conn->real_escape_string($product_name_filter);
    $searchConditions[] = "name LIKE '%$productNameTerm%'";
}

// Specific Product Code filter
if (!empty($product_code_filter)) {
    $productCodeTerm = $conn->real_escape_string($product_code_filter);
    $searchConditions[] = "product_code LIKE '%$productCodeTerm%'";
}

// Price range filter
if (!empty($price_from)) {
    $priceFromTerm = $conn->real_escape_string($price_from);
    $searchConditions[] = "lkr_price >= '$priceFromTerm'";
}

if (!empty($price_to)) {
    $priceToTerm = $conn->real_escape_string($price_to);
    $searchConditions[] = "lkr_price <= '$priceToTerm'";
}

// Status filter
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "status = '$statusTerm'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(created_at) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(created_at) <= '$dateToTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Product Management</title>
    
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
                        <h5 class="mb-0 font-medium">Product Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Product Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="product_id_filter">Product ID</label>
                            <input type="number" id="product_id_filter" name="product_id_filter" 
                                   placeholder="Enter product ID" min="1"
                                   value="<?php echo htmlspecialchars($product_id_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="product_name_filter">Product Name</label>
                            <input type="text" id="product_name_filter" name="product_name_filter" 
                                   placeholder="Enter product name" 
                                   value="<?php echo htmlspecialchars($product_name_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="product_code_filter">Product Code</label>
                            <input type="text" id="product_code_filter" name="product_code_filter" 
                                   placeholder="Enter product code" 
                                   value="<?php echo htmlspecialchars($product_code_filter); ?>">
                        </div>
                        
                        <!-- <div class="form-group">
                            <label for="description_filter">Description</label>
                            <input type="text" id="description_filter" name="description_filter" 
                                   placeholder="Enter description" 
                                   value="<?php echo htmlspecialchars($description_filter); ?>">
                        </div> -->
                        
                        <!-- <div class="form-group">
                            <label for="price_from">Price From (LKR)</label>
                            <input type="number" id="price_from" name="price_from" 
                                   placeholder="Min price" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($price_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="price_to">Price To (LKR)</label>
                            <input type="number" id="price_to" name="price_to" 
                                   placeholder="Max price" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($price_to); ?>">
                        </div> -->
                        
                        <div class="form-group">
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($status_filter == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
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

                <!-- Product Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Products</div>
                </div>

                <!-- Products Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Product Name</th>
                                <th>Product Code</th>
                                <th>Description</th>
                                <th>Price (LKR)</th>
                                <th>Created Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- Product ID -->
                                        <td><?php echo htmlspecialchars($row['id']); ?></td>
                                        
                                        <!-- Product Name -->
                                        <td class="product-name">
                                            <div class="product-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($row['name']); ?></h6>
                                            </div>
                                        </td>
                                        
                                        <!-- Product Code -->
                                        <td>
                                            <div class="product-code" style="font-family: monospace; font-size: 13px; color: #495057; background: #f8f9fa; padding: 4px 8px; border-radius: 4px; display: inline-block;">
                                                <?php echo htmlspecialchars($row['product_code'] ?? 'N/A'); ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Description -->
                                        <td>
                                            <div class="description-truncate" title="<?php echo htmlspecialchars($row['description'] ?? ''); ?>">
                                                <?php 
                                                $description = $row['description'] ?? '';
                                                echo htmlspecialchars(strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description); 
                                                ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Price -->
                                        <td>
                                            <div class="price-display">
                                                <span style="font-weight: 600; color: #28a745;">
                                                    LKR <?php echo number_format($row['lkr_price'], 2); ?>
                                                </span>
                                            </div>
                                        </td>
                                        
                                        <!-- Created Date -->
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php echo date('Y-m-d', strtotime($row['created_at'])); ?>
                                                <br>
                                                <small style="color: #6c757d;"><?php echo date('H:i:s', strtotime($row['created_at'])); ?></small>
                                            </div>
                                        </td>
                                        
                                        <!-- Status Badge -->
                                        <td>
                                            <?php if ($row['status'] === 'active'): ?>
                                                <span class="status-badge pay-status-paid">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge pay-status-unpaid">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-product-btn"
                                                        data-product-id="<?= $row['id'] ?>"
                                                        data-product-name="<?= htmlspecialchars($row['name']) ?>"
                                                        data-product-code="<?= htmlspecialchars($row['product_code'] ?? '') ?>"
                                                        data-product-description="<?= htmlspecialchars($row['description'] ?? '') ?>"
                                                        data-product-price="<?= htmlspecialchars($row['lkr_price']) ?>"
                                                        data-product-status="<?= htmlspecialchars($row['status']) ?>"
                                                        data-product-created="<?= htmlspecialchars($row['created_at']) ?>"
                                                        title="View Product Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button class="action-btn dispatch-btn" title="Edit Product" 
                                                        onclick="editProduct(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                          
                                                <!-- Status Toggle Button -->
                                                <button type="button" class="action-btn <?= $row['status'] == 'active' ? 'deactivate-btn' : 'activate-btn' ?> toggle-status-btn"
                                                  data-product-id="<?= $row['id'] ?>"
                                                  data-current-status="<?= $row['status'] ?>"
                                                  data-product-name="<?= htmlspecialchars($row['name']) ?>"
                                                   title="<?= $row['status'] == 'active' ? 'Deactivate Product' : 'Activate Product' ?>"
                                                   data-action="<?= $row['status'] == 'active' ? 'deactivate' : 'activate' ?>">
                                                       <i class="fas <?= $row['status'] == 'active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-box" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No products found
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&product_name_filter=<?php echo urlencode($product_name_filter); ?>&product_code_filter=<?php echo urlencode($product_code_filter); ?>&description_filter=<?php echo urlencode($description_filter); ?>&price_from=<?php echo urlencode($price_from); ?>&price_to=<?php echo urlencode($price_to); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&product_name_filter=<?php echo urlencode($product_name_filter); ?>&product_code_filter=<?php echo urlencode($product_code_filter); ?>&description_filter=<?php echo urlencode($description_filter); ?>&price_from=<?php echo urlencode($price_from); ?>&price_to=<?php echo urlencode($price_to); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&product_name_filter=<?php echo urlencode($product_name_filter); ?>&product_code_filter=<?php echo urlencode($product_code_filter); ?>&description_filter=<?php echo urlencode($description_filter); ?>&price_from=<?php echo urlencode($price_from); ?>&price_to=<?php echo urlencode($price_to); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Details Modal -->
    <div id="productDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Product Details</h4>
                <span class="close" onclick="closeProductModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">Product ID:</span>
                    <span class="detail-value" id="modal-product-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Product Name:</span>
                    <span class="detail-value" id="modal-product-name"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Product Code:</span>
                    <span class="detail-value" id="modal-product-code" style="font-family: monospace; background: #f8f9fa; padding: 4px 8px; border-radius: 4px; display: inline-block;"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Description:</span>
                    <span class="detail-value" id="modal-product-description"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Price (LKR):</span>
                    <span class="detail-value" id="modal-product-price"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span id="modal-product-status" class="status-badge"></span>
                    </span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Created:</span>
                    <span class="detail-value" id="modal-product-created"></span>
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
                    You are about to <span class="action-highlight" id="action-text"></span> product:
                </div>
                <div class="confirmation-text">
                    <span class="user-name-highlight" id="confirm-product-name"></span>
                </div>
                <div class="modal-buttons">
                    <button class="btn-confirm" id="confirmActionBtn">
                        <span id="confirm-button-text">Yes, deactivate product!</span>
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
            window.location.href = 'product_list.php';
        }

        // Product Details Modal Functions
        function openProductModal(button) {
            const modal = document.getElementById('productDetailsModal');
            
            // Extract data from button attributes
            const productId = button.getAttribute('data-product-id');
            const productName = button.getAttribute('data-product-name');
            const productCode = button.getAttribute('data-product-code');
            const productDescription = button.getAttribute('data-product-description');
            const productPrice = button.getAttribute('data-product-price');
            const productStatus = button.getAttribute('data-product-status');
            const productCreated = button.getAttribute('data-product-created');

            // Populate modal fields
            document.getElementById('modal-product-id').textContent = productId;
            document.getElementById('modal-product-name').textContent = productName;
            document.getElementById('modal-product-code').textContent = productCode || 'N/A';
            document.getElementById('modal-product-description').textContent = productDescription || 'N/A';
            document.getElementById('modal-product-price').textContent = 'LKR ' + parseFloat(productPrice).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            // Set status badge
            const statusElement = document.getElementById('modal-product-status');
            statusElement.textContent = productStatus.charAt(0).toUpperCase() + productStatus.slice(1);
            statusElement.className = 'status-badge ' + (productStatus === 'active' ? 'status-active' : 'status-inactive');
            
            // Format dates
            document.getElementById('modal-product-created').textContent = formatDateTime(productCreated);

            // Show modal
            modal.style.display = 'block';
        }

        function closeProductModal() {
            document.getElementById('productDetailsModal').style.display = 'none';
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
            const viewButtons = document.querySelectorAll('.view-product-btn');
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    openProductModal(this);
                });
            });

            // Close modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('productDetailsModal');
                if (event.target === modal) {
                    closeProductModal();
                }
            }
        });

        function editProduct(productId) {
            window.location.href = 'edit_product.php?id=' + productId;
        }

        function closeConfirmationModal() {
            document.getElementById('statusConfirmationModal').style.display = 'none';
        }

        // Toggle Product Status Functionality
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
                const productModal = document.getElementById('productDetailsModal');
                const statusModal = document.getElementById('statusConfirmationModal');
                
                if (event.target === productModal) {
                    closeProductModal();
                }
                if (event.target === statusModal) {
                    closeConfirmationModal();
                }
            }
        });

        function openStatusConfirmationModal(button) {
            const productId = button.getAttribute('data-product-id');
            const productName = button.getAttribute('data-product-name');
            const currentStatus = button.getAttribute('data-current-status');
            
            // Determine action based on current status
            const isActive = currentStatus.toLowerCase() === 'active';
            const actionText = isActive ? 'deactivate' : 'activate';
            const buttonText = isActive ? 'Yes, deactivate product!' : 'Yes, activate product!';
            
            // Update modal content
            document.getElementById('action-text').textContent = actionText;
            document.getElementById('confirm-product-name').textContent = productName;
            document.getElementById('confirm-button-text').textContent = buttonText;
            
            // Store data for confirmation
            const confirmBtn = document.getElementById('confirmActionBtn');
            confirmBtn.setAttribute('data-product-id', productId);
            confirmBtn.setAttribute('data-new-status', isActive ? 'inactive' : 'active');
            
            // Add click handler to confirm button
            confirmBtn.onclick = function() {
                toggleProductStatus(productId, isActive ? 'inactive' : 'active');
            };
            
            // Show modal
            document.getElementById('statusConfirmationModal').style.display = 'block';
        }

        function toggleProductStatus(productId, newStatus) {
            fetch('toggle_product_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_id: productId,
                    new_status: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close confirmation modal
                    closeConfirmationModal();
                    
                    // Show success message
                    alert('Product status updated successfully!');
                    
                    // Reload page to reflect changes
                    location.reload();
                } else {
                    alert('Error updating product status: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the product status.');
            });
        }

        // Price range filter validation
        document.addEventListener('DOMContentLoaded', function() {
            const priceFromInput = document.getElementById('price_from');
            const priceToInput = document.getElementById('price_to');
            
            if (priceFromInput && priceToInput) {
                priceFromInput.addEventListener('change', function() {
                    if (this.value && priceToInput.value && parseFloat(this.value) > parseFloat(priceToInput.value)) {
                        alert('From price cannot be greater than To price');
                        this.value = '';
                    }
                });
                
                priceToInput.addEventListener('change', function() {
                    if (this.value && priceFromInput.value && parseFloat(this.value) < parseFloat(priceFromInput.value)) {
                        alert('To price cannot be less than From price');
                        this.value = '';
                    }
                });
            }
        });

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
            const searchInputs = document.querySelectorAll('#product_name_filter, #description_filter');
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