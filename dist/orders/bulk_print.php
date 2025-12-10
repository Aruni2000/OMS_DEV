<?php
/**
 * Label Print Router
 * Routes print requests to appropriate print format pages
 * Handles 9x9, 2x5, and regular print formats
 */

// Start session management
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Get the format parameter
$format = isset($_GET['format']) ? trim($_GET['format']) : 'regular';

// Remove the format parameter from the query string to pass clean parameters to print pages
$queryParams = $_GET;
unset($queryParams['format']);
$queryString = http_build_query($queryParams);

// Determine which print page to redirect to based on format
switch ($format) {
    case '9x9':
        $printPage = 'nine_nine_bulk_print.php';
        break;
    
    case '4x13':
        $printPage = 'four_thirteen_bulk_print.php';
        break;
    
    case 'regular':
    default:
        $printPage = 'ten_fourteen_bulk_print.php';
        break;
}

// Build the redirect URL with parameters
$redirectUrl = $printPage;
if (!empty($queryString)) {
    $redirectUrl .= '?' . $queryString;
}

// Redirect to the appropriate print page
header("Location: " . $redirectUrl);
exit();
?>