<?php
/*
 * Duplicate batch-number lookup for the Registration form.
 * Read-only: reports whether the batch number already exists in ANOTHER registration.
 *
 * Edit mode: the current record's id is passed so it is EXCLUDED from the search
 * (a record must never flag its own unchanged batch number as a duplicate). The
 * client sends it as `rid`; `regid` is also accepted for backward compatibility.
 */
include_once("../config/auth_ndtlis.php");
include_once("../config/db_config.php");

header('Content-Type: application/json; charset=UTF-8');

$batch = isset($_GET['batch']) ? trim($_GET['batch']) : '';
// Current record id to exclude while editing (0 = new registration, exclude nothing).
$rid = isset($_GET['rid']) ? (int)$_GET['rid'] : (isset($_GET['regid']) ? (int)$_GET['regid'] : 0);

if ($batch === '') {
    echo json_encode(array('exists' => false, 'count' => 0));
    exit;
}

// Count other registrations sharing this batch number. Prepared statement (SQL-injection safe).
// The AND registrationID <> ? clause excludes the record being edited in Edit mode.
if ($rid > 0) {
    $stmt = mysqli_prepare($connection, "SELECT COUNT(*) FROM registrationtable WHERE batchNo = ? AND registrationID <> ?");
    mysqli_stmt_bind_param($stmt, 'si', $batch, $rid);
} else {
    $stmt = mysqli_prepare($connection, "SELECT COUNT(*) FROM registrationtable WHERE batchNo = ?");
    mysqli_stmt_bind_param($stmt, 's', $batch);
}

$cnt = 0;
if ($stmt) {
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $cnt);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
}
$cnt = (int)$cnt;

echo json_encode(array('exists' => $cnt > 0, 'count' => $cnt));
?>
