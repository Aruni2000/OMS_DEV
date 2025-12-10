<?php
session_start();
$csvData = [];
$convertedData = [];
$header = [];

/* -----------------------------------------
   HANDLE CSV UPLOAD
------------------------------------------ */
if (isset($_FILES['csv_file'])) {

    $file = $_FILES['csv_file']['tmp_name'];

    if (($handle = fopen($file, "r")) !== FALSE) {

        // Read original header
        $header = fgetcsv($handle);

        // Read all rows
        while (($row = fgetcsv($handle)) !== FALSE) {

            $csvData[] = $row;

            // Convert row into city_table format
            $convertedData[] = [
                $row[0],                    // city_id
                null,                       // state_id
                $row[1],                    // city_name
                $row[3],                    // district_id
                $row[4],                    // zone_id
                null,                       // postal_code
                1,                          // is_active
                date("Y-m-d H:i:s"),        // created_at
                date("Y-m-d H:i:s")         // updated_at
            ];
        }

        fclose($handle);

        // Save converted rows for download
        $_SESSION['convertedData'] = $convertedData;
    }
}

/* -----------------------------------------
   HANDLE CSV DOWNLOAD
------------------------------------------ */
if (isset($_GET['download']) && $_GET['download'] == 1) {

    if (!isset($_SESSION['convertedData'])) {
        die("No data to download!");
    }

    $convertedData = $_SESSION['convertedData'];
    $filename = "converted_city_table_" . date('Y-m-d') . ".csv";

    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=\"$filename\"");

    $out = fopen("php://output", "w");

    // CSV header row
    fputcsv($out, [
        'city_id','state_id','city_name','district_id','zone_id',
        'postal_code','is_active','created_at','updated_at'
    ]);

    // CSV data rows with NULL as text
    foreach ($convertedData as $row) {
        $rowForCsv = array_map(function($v) {
            return $v === null ? "NULL" : $v;
        }, $row);
        fputcsv($out, $rowForCsv);
    }

    fclose($out);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>CSV Reader + Converter</title>
    <style>
        table { border-collapse: collapse; width: 95%; margin-top: 20px; }
        th, td { border: 1px solid #444; padding: 6px 10px; }
        th { background: #ddd; }
        button { padding: 8px 12px; margin: 10px 0; cursor: pointer; }
    </style>
</head>
<body>

<h2>Upload CSV File</h2>

<form method="POST" enctype="multipart/form-data">
    <input type="file" name="csv_file" accept=".csv" required>
    <button type="submit">Read CSV</button>
</form>

<?php if (!empty($csvData)) { ?>

    <!-- ORIGINAL CSV DATA -->
    <h3>Original CSV Content</h3>
    <table>
        <thead>
            <tr>
                <?php foreach ($header as $h) echo "<th>$h</th>"; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($csvData as $row) { ?>
                <tr>
                    <?php foreach ($row as $cell) echo "<td>$cell</td>"; ?>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <!-- CONVERTED DATA -->
    <h3>Converted to <u>city_table</u> Structure</h3>

    <table>
        <thead>
            <tr>
                <th>city_id</th>
                <th>state_id</th>
                <th>city_name</th>
                <th>district_id</th>
                <th>zone_id</th>
                <th>postal_code</th>
                <th>is_active</th>
                <th>created_at</th>
                <th>updated_at</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($_SESSION['convertedData'] as $r) { ?>
                <tr>
                    <?php foreach ($r as $col) { ?>
                        <td><?= ($col === null ? "NULL" : $col) ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <br>

    <!-- DOWNLOAD CSV BUTTON -->
    <a href="?download=1">
        <button style="background:#007bff;color:white;">Download Converted CSV</button>
    </a>

<?php } ?>

</body>
</html>
