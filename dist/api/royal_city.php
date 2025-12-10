<?php
// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/royal_db.php');

// API URL with noPagination to get all cities
$url = "https://v1.api.curfox.com/api/public/merchant/city?noPagination=true";

// Your token (from login response)
$token = "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiIxIiwianRpIjoiZWJiODY1NTA0NTJhODgxZTM2MzZjYTNiZTQ0YmNkMTc5YWMwYjcwODBkYTBkNzE0ZmJmOWQ4NmY0YTI4NzQ1NGYyZWQ2NmE5MTlmZThlZmMiLCJpYXQiOjE3NTkyOTI1MjAuMzYxOTMsIm5iZiI6MTc1OTI5MjUyMC4zNjE5MzIsImV4cCI6NDkxNDk2NjEyMC4zNDI5NDcsInN1YiI6IjkyNTMiLCJzY29wZXMiOltdfQ.U47uw953s2D3DO_8zfZETOwKf6yA_g_ojTOsV2A4dUi_mac1MuAUOGeHWQkPD4T_Wvuimf0yuiJtDvGoUaUphsdmidcox-FeD52wD0F8IAjQnBPp_2Hvg5eeotY5a4ljmwekdGHNvmnCvcAOC9UX6_sP1Bu0vbfGUr_ZPeGBU5UYmMFVlluY_CX5GT9EHqg0hidBDPENWDdPV2qt_irPzBB2FQuIZfH_fu12hygnBsktJZkghsqknzChZTWL-3kgloL_pHnENIKdBbUTKQLmS8IE1LfWeYcUp7hIfe4QuCsTBVGQwT9Pv5mWySU7aftG7G0j4dXE-Ec21ZmjiSOaoV1ezX-0pj1kUTYIUtwd-Fssgn4FW4fSdVKDiFual1Ec2lV4VTCU31O9NyY5dG2y3eAYM74V77KxLWjJkjBj9eFwVe4qc0mndLrP2obDW6VW9hWL2_duNLOXLHiWtZPHeNcBbLZ54UOAyg-vj0vQ7YFpwf7jDDYudL0BUiq_7xazx6s1Vz62eAkFv9BrhtzttvykaSg0hl1qQRGG3uAGPDsg69Rn5UPN4XjtUDa2oF1GTCnjm7ePAk_XHskzjngmHQND_VmsoHK6na-nIzt6vxzhE5sImcmeLJTaRT470LfsDLL1-XZztHdfaUeLQ0GaXK3ZUYzIgZVMN2CCkPXigpA"; // replace with your actual token

// Your tenant
$tenant = "royalexpress";


// Initialize cURL
$ch = curl_init($url);

// Set headers
$headers = [
    "Accept: application/json",
    "Authorization: Bearer " . $token,
    "Content-Type: application/json",
    "X-tenant: " . $tenant
];

curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true
]);

// Execute cURL
$response = curl_exec($ch);

// Handle cURL errors
if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch);
    exit;
}

curl_close($ch);

// Decode JSON
$data = json_decode($response, true);

// Validate response
if (!isset($data['data']) || count($data['data']) === 0) {
    echo "No cities found.";
    exit;
}

// Display in a table
echo "<h2>City List</h2>";
echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse: collapse; font-family: Arial;'>
<tr style='background-color:#f2f2f2;'>
    <th>ID</th>
    <th>Ref No</th>
    <th>City Name</th>
    <th>Postal Code</th>
    <th>State</th>
    <th>Zone</th>
    <th>Country</th>
    <th>Default Warehouse</th>
    <th>Active</th>
</tr>";

foreach ($data['data'] as $city) {
    $id = htmlspecialchars($city['id']);
    $ref_no = htmlspecialchars($city['ref_no']);
    $name = htmlspecialchars($city['name']);
    $postal = htmlspecialchars($city['postal_code'] ?? '');
    $state = htmlspecialchars($city['state']['name'] ?? '');
    $zone = htmlspecialchars($city['zone']['name'] ?? '');
    $country = htmlspecialchars($city['country']['name'] ?? '');
    $warehouse = htmlspecialchars($city['default_warehouse']['name'] ?? '');
    $active = $city['is_active'] ? "✅" : "❌";

    echo "<tr>
        <td>{$id}</td>
        <td>{$ref_no}</td>
        <td>{$name}</td>
        <td>{$postal}</td>
        <td>{$state}</td>
        <td>{$zone}</td>
        <td>{$country}</td>
        <td>{$warehouse}</td>
        <td>{$active}</td>
    </tr>";
}

echo "</table>";
?>
