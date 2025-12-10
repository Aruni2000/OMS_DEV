<?php
// Set headers to download as CSV file
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="delivery_template.csv"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, ['waybill_id']);

// Close the output stream
fclose($output);
exit();
?>