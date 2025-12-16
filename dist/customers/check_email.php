<?php
// check_email.php
// Returns JSON { exists: true|false } or { error: 'invalid_format' }
header('Content-Type: application/json');

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
if ($email === '') {
    echo json_encode(['exists' => false]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'invalid_format']);
    exit;
}

// Prepared statement to avoid injection
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM customers WHERE email = ? AND status = 'Active' LIMIT 1");
if (!$stmt) {
    echo json_encode(['exists' => false]);
    exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$exists = isset($row['cnt']) && intval($row['cnt']) > 0;

echo json_encode(['exists' => $exists]);

?>