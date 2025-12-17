<?php
// check_phone.php
// Returns JSON { exists: true|false } or { error: 'invalid_format' }
header('Content-Type: application/json');

include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
if ($phone === '') {
    echo json_encode(['exists' => false]);
    exit;
}

// Normalize: remove non-digits
$clean = preg_replace('/\D+/', '', $phone);

// Try to derive a local 10-digit phone starting with 0
$variants = [];
if (strlen($clean) === 10 && $clean[0] === '0') {
    $local = $clean;
    $variants[] = $local;
    $variants[] = '94' . substr($local, 1);
} elseif (strlen($clean) === 11 && substr($clean, 0, 2) === '94') {
    $local = '0' . substr($clean, 2);
    $variants[] = $local;
    $variants[] = $clean;
} elseif (strlen($clean) === 9) {
    // maybe missing leading zero
    $local = '0' . $clean;
    if (strlen($local) === 10) {
        $variants[] = $local;
        $variants[] = '94' . substr($local, 1);
    }
}

$variants = array_values(array_unique(array_filter($variants)));

if (count($variants) === 0) {
    echo json_encode(['error' => 'invalid_format']);
    exit;
}

// Build placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($variants), '?'));
$types = str_repeat('s', count($variants));

$placeholders_dup = $placeholders; // same placeholders for second IN

// We'll check both `phone` and `phone2` columns for duplicates
$sql = "SELECT COUNT(*) AS cnt FROM customers WHERE (phone IN ($placeholders) OR phone2 IN ($placeholders_dup)) AND status = 'Active' LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['exists' => false]);
    exit;
}

// Bind params: each variant list appears twice (for phone and phone2)
$bindValues = array_merge($variants, $variants);
$types = str_repeat('s', count($bindValues));

$refs = [];
foreach ($bindValues as $k => $v) {
    $refs[$k] = &$bindValues[$k];
}
array_unshift($refs, $types);
call_user_func_array([$stmt, 'bind_param'], $refs);

$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$exists = isset($row['cnt']) && intval($row['cnt']) > 0;

echo json_encode(['exists' => $exists]);

?>
