<?php

/**
 * molecular-shipment.php
 *
 * Compatibility entry point.
 *
 * The shipment UI has been merged into molecular.php.
 *
 * Existing bookmarks/menus can continue using this URL,
 * but users are redirected to the unified Molecular Workflow.
 */

require_once("../config/session.php");

$action = isset($_GET['action'])
    ? (int)$_GET['action']
    : 1;

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

$step = isset($_GET['step'])
    ? (int)$_GET['step']
    : 2;

/*
|--------------------------------------------------------------------------
| New shipment entry
|--------------------------------------------------------------------------
|
| Without a sample, the unified workflow should begin from the
| sample step.
|
*/

if ($id <= 0) {

    header(
        "Location: molecular.php?action=1&step=1"
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Existing shipment URL
|--------------------------------------------------------------------------
|
| We need to identify the associated sample so that the unified
| molecular page can open it.
|
*/

require_once("../config/db-config.php");

// Record-level authorization (IDOR): facility users may open only their own facility's shipment.
mn_require_record($conn, 'dbs_shipment', $id);

$stmt = $conn->prepare("
    SELECT dbs_id
    FROM dbs_results
    WHERE dbs_shipment_id = ?
    ORDER BY dbs_id ASC
    LIMIT 1
");

if (!$stmt) {

    header(
        "Location: molecular-list.php"
    );

    exit;
}

$stmt->bind_param(
    "i",
    $id
);

$stmt->execute();

$result = $stmt->get_result();

if (
    !$result ||
    $result->num_rows === 0
) {

    $stmt->close();

    header(
        "Location: molecular-list.php"
    );

    exit;
}

$row = $result->fetch_assoc();

$stmt->close();

$dbsid = (int)$row['dbs_id'];

header(
    "Location: molecular.php?action=2&id=" .
    $dbsid .
    "&step=" .
    max(2, min(5, $step))
);

exit;