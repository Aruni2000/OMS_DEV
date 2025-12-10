<?php
$fe_servername = "localhost";   // Database server
$fe_username   = "root";        // Database username (default for XAMPP is root)
$fe_password   = "";            // Database password (empty by default in XAMPP)
$fe_dbname     = "fe_it_db";    // Replace with your actual FE IT DB name

$fe_conn = new mysqli($fe_servername, $fe_username, $fe_password, $fe_dbname);

// Check connection
if ($fe_conn->connect_error) {
    die("FE IT DB Connection failed: " . $fe_conn->connect_error);
}
?>
