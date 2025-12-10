<?php
// File: templates/generate_template.php

// Start session and check authentication if needed
session_start();
if (!isset($_SESSION['logged_in'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied");
}

// Set CSV headers
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="order_import_template_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Define CSV columns - Updated to match order_header table structure
$header = [
    'Full Name',
    'Phone Number', 
    'City',
    'Email',
    'Address Line 1',
    'Address Line 2',
    'Product Code',
    'Total Amount',
    'Other'
];

// Create a temporary memory file
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fwrite($output, "\xEF\xBB\xBF");

// Write header row (using standard fputcsv with default parameters)
fputcsv($output, $header);

// Close output stream
fclose($output);
exit;
?>