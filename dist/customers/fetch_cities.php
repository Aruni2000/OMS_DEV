<?php
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Accept GET parameter 'term' (used by the frontend). Return JSON list of cities.
$term = '';
if (isset($_GET['term'])) {
    $term = trim($_GET['term']);
}

if ($term === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

$query = "SELECT city_id, city_name FROM city_table WHERE is_active = 1 AND city_name LIKE ? ORDER BY city_name ASC LIMIT 20";
if ($stmt = $conn->prepare($query)) {
    $param = "%{$term}%";
    $stmt->bind_param("s", $param);
    $stmt->execute();
    $result = $stmt->get_result();

    $cities = [];
    while ($row = $result->fetch_assoc()) {
        $cities[] = [
            'city_id' => $row['city_id'],
            'city_name' => $row['city_name']
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($cities);
    $stmt->close();
} else {
    header('Content-Type: application/json; charset=utf-8', true, 500);
    echo json_encode([]);
}
$conn->close();

?>