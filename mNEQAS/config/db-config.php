<?php
$host = getenv('DB_HOST') ?: "localhost";
$user = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$database = getenv('DB_NAME') ?: "mneqas";
$configuredPort = getenv('DB_PORT') ?: 3307;
$port = (int)$configuredPort;

// Suppress uncaught mysqli exception during connection test
$driver = new mysqli_driver();
$origReport = $driver->report_mode;
$driver->report_mode = MYSQLI_REPORT_OFF;

$conn = @new mysqli($host, $user, $password, $database, $port);

// If port 3307 failed and user didn't explicitly enforce it via env, fallback to standard MySQL port 3306
if ($conn->connect_errno && empty(getenv('DB_PORT')) && $port !== 3306) {
    $fallback = @new mysqli($host, $user, $password, $database, 3306);
    if (!$fallback->connect_errno) {
        $conn = $fallback;
    }
}

// Restore report mode
$driver->report_mode = $origReport;

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
