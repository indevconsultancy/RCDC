<?php
$host = getenv('COMMONDB_HOST') ?: getenv('DB_HOST') ?: "localhost";
$user = getenv('COMMONDB_USER') ?: getenv('DB_USER') ?: "root";
$password = getenv('COMMONDB_PASS') !== false ? getenv('COMMONDB_PASS') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : "");
$database = getenv('COMMONDB_NAME') ?: "rcdc_commondb";
$configuredPort = getenv('COMMONDB_PORT') ?: getenv('DB_PORT') ?: 3307;
$port = (int)$configuredPort;

// Suppress uncaught mysqli exception during connection test
$driver = new mysqli_driver();
$origReport = $driver->report_mode;
$driver->report_mode = MYSQLI_REPORT_OFF;

$commondb = @new mysqli($host, $user, $password, $database, $port);

// If port 3307 failed and user didn't explicitly enforce it via env, fallback to standard MySQL port 3306
if ($commondb->connect_errno && empty(getenv('COMMONDB_PORT')) && empty(getenv('DB_PORT')) && $port !== 3306) {
    $fallback = @new mysqli($host, $user, $password, $database, 3306);
    if (!$fallback->connect_errno) {
        $commondb = $fallback;
    }
}

// Restore report mode
$driver->report_mode = $origReport;

if ($commondb->connect_error) {
    die("Connection failed: " . $commondb->connect_error);
}
?>
