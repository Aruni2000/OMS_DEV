<?php
// Start session
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Get current user's role information (replicating logic from order_list.php)
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;

if ($current_user_id == 0 || $current_user_role == 0) {
    $session_identifier = isset($_SESSION['username']) ? $_SESSION['username'] : 
                         (isset($_SESSION['email']) ? $_SESSION['email'] : '');
    
    if ($session_identifier) {
        $userQuery = "SELECT u.id, u.role_id FROM users u WHERE u.email = ? OR u.name = ? LIMIT 1";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param("ss", $session_identifier, $session_identifier);
        $stmt->execute();
        $userResult = $stmt->get_result();
        
        if ($userResult && $userResult->num_rows > 0) {
            $userData = $userResult->fetch_assoc();
            $current_user_id = (int)$userData['id'];
            $current_user_role = (int)$userData['role_id'];
            $_SESSION['user_id'] = $current_user_id;
            $_SESSION['role_id'] = $current_user_role;
        }
        $stmt->close();
    }
}

// Role-based access control
$roleBasedCondition = "";
if ($current_user_role != 1) {
    $roleBasedCondition = " AND i.user_id = $current_user_id";
}

// Get Filter Parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$user_id_filter = isset($_GET['user_id_filter']) ? trim($_GET['user_id_filter']) : '';
$tracking_id = isset($_GET['tracking_id']) ? trim($_GET['tracking_id']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$pay_status_filter = isset($_GET['pay_status_filter']) ? trim($_GET['pay_status_filter']) : '';

// Build Query
// We need to join order_items and products to get product details
$sql = "SELECT 
            i.order_id,
            i.tracking_number,
            i.issue_date,
            i.created_at,
            i.full_name as customer_name,
            i.mobile,
            i.address_line1,
            p.product_code,
            p.name as product_name,
            (ii.total_amount + COALESCE(ii.discount, 0)) as product_price,
            COALESCE(ii.discount, 0) as item_discount,
            ii.total_amount as subtotal
        FROM order_header i
        LEFT JOIN order_items ii ON i.order_id = ii.order_id
        LEFT JOIN products p ON ii.product_id = p.id
        LEFT JOIN users u2 ON i.user_id = u2.id
        WHERE i.interface IN ('individual', 'leads') 
          AND i.status NOT IN ('pending', 'cancel')
          $roleBasedCondition";

// Search Conditions (Replicated from order_list.php)
$searchConditions = [];

if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
        i.order_id LIKE '%$searchTerm%' OR 
        i.full_name LIKE '%$searchTerm%' OR 
        i.issue_date LIKE '%$searchTerm%' OR 
        i.due_date LIKE '%$searchTerm%' OR 
        i.total_amount LIKE '%$searchTerm%' OR
        i.status LIKE '%$searchTerm%' OR 
        i.tracking_number LIKE '%$searchTerm%' OR
        i.pay_status LIKE '%$searchTerm%' OR
        u2.name LIKE '%$searchTerm%'
    )";
}

if (!empty($order_id_filter)) {
    $orderIdTerm = $conn->real_escape_string($order_id_filter);
    $searchConditions[] = "i.order_id LIKE '%$orderIdTerm%'";
}

if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "(i.full_name LIKE '%$customerNameTerm%')";
}

if (!empty($user_id_filter)) {
    $userIdTerm = $conn->real_escape_string($user_id_filter);
    if ($current_user_role == 1) {
        $searchConditions[] = "i.user_id = '$userIdTerm'";
    } else {
        if ($userIdTerm == $current_user_id) {
            $searchConditions[] = "i.user_id = '$userIdTerm'";
        }
    }
}

if (!empty($tracking_id)) {
    $trackingTerm = $conn->real_escape_string($tracking_id);
    $searchConditions[] = "i.tracking_number LIKE '%$trackingTerm%'";
}

if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(i.issue_date) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(i.issue_date) <= '$dateToTerm'";
}

if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "i.status = '$statusTerm'";
}

if (!empty($pay_status_filter)) {
    $payStatusTerm = $conn->real_escape_string($pay_status_filter);
    $searchConditions[] = "i.pay_status = '$payStatusTerm'";
}

if (!empty($searchConditions)) {
    $sql .= " AND (" . implode(' AND ', $searchConditions) . ")";
}

$sql .= " ORDER BY i.order_id DESC";

// Execute Query
$result = $conn->query($sql);

if (!$result) {
    die("Error exporting data: " . $conn->error);
}

// Check if result is empty
if ($result->num_rows === 0) {
    echo "<script>alert('No Data Found'); window.history.back();</script>";
    exit();
}

// Set Headers for CSV Download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="order_list_export_' . date('Y-m-d_H-i-s') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Open Output Stream
$fp = fopen('php://output', 'w');

// Write CSV Header
$headers = [
    'Order ID',
    'Tracking Number',
    'Created Date',
    'Created Time',
    'Customer Name',
    'Phone Number 1',
    'Address Line 1',
    'Product Code',
    'Product Name',
    'Product Price',
    'Item Discount',
    'Subtotal'
];
fputcsv($fp, $headers);

// Write Data Rows
while ($row = $result->fetch_assoc()) {
    $issueDate = !empty($row['issue_date']) ? date('Y-m-d', strtotime($row['issue_date'])) : '';
    $createdTime = !empty($row['created_at']) ? date('H:i:s', strtotime($row['created_at'])) : '';
    
    $csvRow = [
        $row['order_id'],
        $row['tracking_number'],
        $issueDate,
        $createdTime,
        $row['customer_name'],
        $row['mobile'],
        $row['address_line1'],
        $row['product_code'],
        $row['product_name'],
        number_format($row['product_price'], 2, '.', ''), // Format price
        number_format($row['item_discount'], 2, '.', ''),
        number_format($row['subtotal'], 2, '.', '')
    ];
    fputcsv($fp, $csvRow);
}

fclose($fp);
$conn->close();
exit();
?>
