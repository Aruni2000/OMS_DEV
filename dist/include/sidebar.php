<!-- [ Sidebar Menu ] start -->
<nav class="pc-sidebar">
  <div class="navbar-wrapper">
    <!-- Header section with logo -->
    <div class="m-header flex items-center py-4 px-6 h-header-height">
      <a href="../dashboard/index.php" class="b-brand flex items-center gap-3">
        <!-- Dynamic logo from branding table with comprehensive error handling -->
        <?php
        // Initialize default values
        $logo_url = '../assets/images/Sortiq.png';
        $company_name = 'OMS';
        $debug_info = [];
        
        try {
            // Check if database connection exists
            if (!isset($conn) || !$conn) {
                throw new Exception("Database connection not available");
            }
            
            // Fetch branding data for logo display
            $branding_query = "SELECT logo_url, company_name FROM branding WHERE active = 1 LIMIT 1";
            $branding_result = mysqli_query($conn, $branding_query);
            
            if (!$branding_result) {
                throw new Exception("Database query failed: " . mysqli_error($conn));
            }
            
            $branding_data = mysqli_fetch_assoc($branding_result);
            $debug_info[] = "Query executed successfully";
            
            if ($branding_data) {
                $debug_info[] = "Branding data found in database";
                
                // Handle company name
                if (!empty($branding_data['company_name'])) {
                    $company_name = trim($branding_data['company_name']);
                    $debug_info[] = "Company name: " . $company_name;
                }
                
                // Handle logo URL with multiple validation checks
                if (!empty($branding_data['logo_url'])) {
                    $db_logo_url = trim($branding_data['logo_url']);
                    $debug_info[] = "Database logo URL: " . $db_logo_url;
                    
                    // Check if it's a relative or absolute path
                    if (filter_var($db_logo_url, FILTER_VALIDATE_URL)) {
                        // It's a full URL - validate it exists
                        $headers = @get_headers($db_logo_url);
                        if ($headers && strpos($headers[0], '200')) {
                            $logo_url = $db_logo_url;
                            $debug_info[] = "Using external URL: " . $logo_url;
                        } else {
                            $debug_info[] = "External URL not accessible, using default";
                        }
                    } else {
                        // It's a local file path
                        $local_paths_to_check = [
                            $db_logo_url,
                            '../' . $db_logo_url,
                            '../../' . $db_logo_url,
                            dirname(__FILE__) . '/' . $db_logo_url,
                            $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($db_logo_url, '/')
                        ];
                        
                        $file_found = false;
                        foreach ($local_paths_to_check as $path_to_check) {
                            if (file_exists($path_to_check) && is_readable($path_to_check)) {
                                $logo_url = $db_logo_url; // Use original path for HTML
                                $debug_info[] = "File found at: " . $path_to_check;
                                $file_found = true;
                                break;
                            }
                        }
                        
                        if (!$file_found) {
                            $debug_info[] = "Logo file not found in any expected location";
                            $debug_info[] = "Checked paths: " . implode(', ', $local_paths_to_check);
                        }
                    }
                } else {
                    $debug_info[] = "No logo URL in database";
                }
            } else {
                $debug_info[] = "No active branding data found in database";
            }
            
            // Clean up result
            mysqli_free_result($branding_result);
            
        } catch (Exception $e) {
            $debug_info[] = "Error: " . $e->getMessage();
            error_log("Sidebar Logo Error: " . $e->getMessage());
        }
        
        // Final validation of default fallback
        $fallback_paths = [
            '../assets/images/OMS.png',
            'assets/images/OMS.png',
            '../assets/images/default-logo.png',
            'assets/images/default-logo.png'
        ];
        
        $fallback_found = false;
        if ($logo_url === '../assets/images/OMS.png') {
            foreach ($fallback_paths as $fallback_path) {
                if (file_exists($fallback_path)) {
                    $logo_url = $fallback_path;
                    $fallback_found = true;
                    $debug_info[] = "Using fallback: " . $fallback_path;
                    break;
                }
            }
            
            if (!$fallback_found) {
                $debug_info[] = "Warning: Default fallback image not found";
                // Use a data URI as last resort
                $logo_url = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBmaWxsPSIjMDA3YmZmIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0IiBmaWxsPSJ3aGl0ZSIgdGV4dC1hbmNob3I9Im1pZGRsZSI+TE9HTzwvdGV4dD4KPC9zdmc+';
                $debug_info[] = "Using data URI as last resort";
            }
        }
        
        // Sanitize output
        $logo_url = htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8');
        $company_name = htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8');
        
        // Output debug info as HTML comments (remove in production)
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            echo "<!-- DEBUG INFO:\n" . implode("\n", $debug_info) . "\n-->";
        }
        ?>
        
        <img src="<?php echo $logo_url; ?>" 
             alt="<?php echo $company_name; ?>" 
             class="img-fluid logo logo-lg" 
             style="max-height: 40px;" 
             onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBmaWxsPSIjMDA3YmZmIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0IiBmaWxsPSJ3aGl0ZSIgdGV4dC1hbmNob3I9Im1pZGRsZSI+TE9HTzwvdGV4dD4KPHN2Zz4=';" 
             onload="console.log('Logo loaded successfully: <?php echo addslashes($logo_url); ?>');" />
      </a>
    </div>
    
    <!-- Main navigation content -->
    <div class="navbar-content h-[calc(100vh_-_74px)] py-2.5">
      <ul class="pc-navbar">
        
        <!-- Navigation Section Header -->
        <li class="pc-item pc-caption">
          <label>Navigation</label>
        </li>
        <!-- Dashboard Link -->
        <li class="pc-item">
          <a href="../dashboard/index.php" class="pc-link">
            <span class="pc-micon">
              <i data-feather="home"></i>
            </span>
            <span class="pc-mtext">Dashboard</span>
          </a>
        </li>
        
        <!-- Order Management Section -->
        <li class="pc-item pc-caption">
          <label>Order Management</label>
          <i data-feather="feather"></i>
        </li>
        
        <!-- Orders Dropdown Menu -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="edit"></i></span>
            <span class="pc-mtext">Orders Management</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../orders/create_order.php">Create Order</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/order_list.php"> Processed Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/pending_order_list.php">Pending Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/dispatch_order_list.php">Dispatch Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/couriers.php">Courier Management</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/cancel_order_list.php">Cancel Orders</a></li>
               <li class="pc-item"><a class="pc-link" href="../orders/complete_mark_upload.php">Completed Mark Upload</a></li>
            <!-- <li class="pc-item"><a class="pc-link" href="../orders/completed_orders_report.php">Completed Orders Report</a></li> -->
            <li class="pc-item"><a class="pc-link" href="../orders/payment_report.php"> Payment Report</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/return_csv_upload.php">Return CSV Upload</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/return_complete_order_list.php">Return Complete Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/return_handover_order_list.php">Return Handover Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/label_print.php">Label Print</a></li>
          </ul>
        </li>

        <!-- Tracking Management Dropdown Menu -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="users"></i></span>
            <span class="pc-mtext">Tracking Management</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../tracking/tracking_upload.php">Tracking Upload</a></li>
          </ul>
        </li>
        
        <!-- Users Management Dropdown - Only visible to admin users -->
        <?php 
        // Check if user has admin privileges (multiple possible scenarios)
        $is_admin = false;
        
        // Option 1: Check if role_id exists in session
        if (isset($_SESSION['user_role_id'])) {
            // Admin might be role_id 1, or check role name
            $is_admin = ($_SESSION['user_role_id'] == 1);
        }
        
        // Option 2: If no role in session, check database directly
        if (!$is_admin && isset($_SESSION['user_id']) && isset($conn) && $conn) {
            $user_id = mysqli_real_escape_string($conn, $_SESSION['user_id']);
            $role_check_query = "SELECT u.role_id, r.name as role_name 
                               FROM users u 
                               LEFT JOIN roles r ON u.role_id = r.id 
                               WHERE u.id = '$user_id'";
            $role_result = mysqli_query($conn, $role_check_query);
            
            if ($role_result && $role_data = mysqli_fetch_assoc($role_result)) {
                // Check if role is admin (by ID or name)
                $is_admin = ($role_data['role_id'] == 1 || 
                           strtolower($role_data['role_name']) == 'admin' || 
                           strtolower($role_data['role_name']) == 'administrator' ||
                           strtolower($role_data['role_name']) == 'super admin');
            }
        }
        
        if ($is_admin): ?>
        <!-- DEBUG: User has admin access -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="type"></i></span>
            <span class="pc-mtext">Users</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../users/add_user.php">Add New User</a></li>
            <li class="pc-item"><a class="pc-link" href="../users/users.php">All Users</a></li>
            <li class="pc-item"><a class="pc-link" href="../users/user_logs.php">User Activity Log</a></li>
          </ul>
        </li>
        <?php else: ?>
        <!-- DEBUG: User does not have admin access -->
        <?php endif; ?>
        
        <!-- Customers Management Dropdown -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="feather"></i></span>
            <span class="pc-mtext">Customers</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../customers/add_customer.php">Add New Customer</a></li>
            <li class="pc-item"><a class="pc-link" href="../customers/customer_list.php">All Customers</a></li>
          </ul>
        </li>
        
        <!-- Products Management Dropdown -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="package"></i></span>
            <span class="pc-mtext">Products</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../products/add_product.php">Add New Product</a></li>
            <li class="pc-item"><a class="pc-link" href="../products/product_list.php">All Products</a></li>
          </ul>
        </li>

        <!-- Leads Management Section -->
        <li class="pc-item pc-caption">
          <label>Lead Management</label>
          <i data-feather="feather"></i>
        </li>
        
        <!-- Leads Dropdown Menu -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="users"></i></span>
            <span class="pc-mtext">Leads</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../leads/lead_upload.php">Lead Upload</a></li>
            <li class="pc-item"><a class="pc-link" href="../leads/lead_list.php">Lead List</a></li>
             <li class="pc-item"><a class="pc-link" href="../leads/my_leads.php">My Leads </a></li>
            <li class="pc-item"><a class="pc-link" href="../leads/city_list.php">City List</a></li>
          </ul>
        </li>
        
        <!-- Branding Section Header -->
        <li class="pc-item pc-caption">
          <label>Branding</label>
          <i data-feather="monitor"></i>
        </li>
        
        <!-- Settings Dropdown -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="settings"></i></span>
            <span class="pc-mtext">Settings</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../settings/branding.php">Edit Branding</a></li>
          </ul>
        </li>
        
      </ul>
    </div>
  </div>
</nav>
<!-- [ Sidebar Menu ] end -->

<?php
// Optional: Add this function to your common functions file for reuse
if (!function_exists('get_logo_with_fallback')) {
    function get_logo_with_fallback($conn, $default_logo = '../assets/images/FEIT.png', $default_name = 'OMS') {
        $result = [
            'logo_url' => $default_logo,
            'company_name' => $default_name,
            'debug' => []
        ];
        
        try {
            if (!$conn) {
                throw new Exception("No database connection");
            }
            
            $query = "SELECT logo_url, company_name FROM branding WHERE active = 1 LIMIT 1";
            $db_result = mysqli_query($conn, $query);
            
            if (!$db_result) {
                throw new Exception("Query failed: " . mysqli_error($conn));
            }
            
            $data = mysqli_fetch_assoc($db_result);
            
            if ($data) {
                if (!empty($data['company_name'])) {
                    $result['company_name'] = trim($data['company_name']);
                }
                
                if (!empty($data['logo_url'])) {
                    $logo_path = trim($data['logo_url']);
                    
                    // Validate logo exists
                    if (filter_var($logo_path, FILTER_VALIDATE_URL)) {
                        // External URL
                        $headers = @get_headers($logo_path);
                        if ($headers && strpos($headers[0], '200')) {
                            $result['logo_url'] = $logo_path;
                        }
                    } else {
                        // Local file
                        if (file_exists($logo_path)) {
                            $result['logo_url'] = $logo_path;
                        }
                    }
                }
            }
            
            mysqli_free_result($db_result);
            
        } catch (Exception $e) {
            $result['debug'][] = $e->getMessage();
            error_log("Logo fetch error: " . $e->getMessage());
        }
        
        return $result;
    }
}
?>