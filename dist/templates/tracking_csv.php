<?php
// tracking_csv.php - CSV template download for tracking numbers

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="tracking_numbers_template.csv"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

// Create CSV content
$csvContent = "Tracking Number\n";
$csvContent .= "TRK001234567\n";
$csvContent .= "TRK001234568\n";
$csvContent .= "TRK001234569\n";

// Output CSV content
echo $csvContent;
exit();
?>