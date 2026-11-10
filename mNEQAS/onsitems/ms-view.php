<?php
/**
 * ms-view.php — view dispatcher to ms-registration.php in view mode (action=2)
 */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (isset($_GET['pdf']) || (isset($_GET['export']) && $_GET['export'] === 'pdf')) {
    require_once __DIR__ . '/pdf-ms-registration.php';
    exit;
}
if (!isset($_GET['action'])) {
    $_GET['action'] = 2;
}
require_once __DIR__ . '/ms-registration.php';
