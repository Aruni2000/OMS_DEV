<?php
// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Check database connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

$searchTerm = isset($_GET['term']) ? $_GET['term'] : '';
$cities = [];

if (!empty($searchTerm)) {
    // Sanitize the search term to prevent SQL injection
    $searchTerm = $conn->real_escape_string($searchTerm);
    
    // Query to fetch cities matching the search term
    $cityQuery = "SELECT city_id, city_name FROM city_table WHERE is_active = 1 AND city_name LIKE '%" . $searchTerm . "%' ORDER BY city_name ASC LIMIT 10";
    $cityResult = $conn->query($cityQuery);

    if ($cityResult) {
        while ($cityRow = $cityResult->fetch_assoc()) {
            $cities[] = [
                'id' => $cityRow['city_id'],
                'name' => $cityRow['city_name']
            ];
        }
    } else {
        error_log("City query failed: " . $conn->error);
    }
}

// Return cities as JSON
header('Content-Type: application/json');
echo json_encode($cities);

$conn->close();
?>