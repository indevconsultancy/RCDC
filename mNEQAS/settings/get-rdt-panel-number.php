<?php
require_once("../config/session.php");
require_once("../config/auth.php");
mn_require_rcdc();

require_once("../config/db-config.php");
require_once("../config/commondb-config.php");
require_once(__DIR__ . "/panel-number-functions.php");

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Thimphu');

function jsonResponse(array $data, int $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$facility  = isset($_GET['facility'])  ? trim($_GET['facility'])  : '';
$round     = isset($_GET['round'])     ? trim($_GET['round'])     : '';
$dzongkhag = isset($_GET['dzongkhag']) ? trim($_GET['dzongkhag']) : '';
$panelId   = isset($_GET['id'])        ? trim($_GET['id'])        : '';

if ($round === '') {
    jsonResponse(['ok' => false, 'msg' => 'Panel round is required.'], 400);
}
if ($dzongkhag === '') {
    jsonResponse(['ok' => false, 'msg' => 'Dzongkhag is required.'], 400);
}
if ($facility === '') {
    jsonResponse(['ok' => false, 'msg' => 'Health facility is required.'], 400);
}

/* Validate facility */
$facilityStmt = $commondb->prepare("
    SELECT center_id, center_name, dzongkhag_code
    FROM ms_health_center_table
    WHERE center_id = ? AND dzongkhag_code = ?
    LIMIT 1
");
if (!$facilityStmt) {
    jsonResponse(['ok' => false, 'msg' => 'Could not prepare facility validation.'], 500);
}
$facilityStmt->bind_param("ss", $facility, $dzongkhag);
$facilityStmt->execute();
$facilityRow = $facilityStmt->get_result()->fetch_assoc();
$facilityStmt->close();

if (!$facilityRow) {
    jsonResponse(['ok' => false, 'msg' => 'Selected health facility does not belong to the selected Dzongkhag.'], 400);
}

/* Validate round */
$roundStmt = $conn->prepare("
    SELECT round_id, panel_round
    FROM rdt_panel_round_informations
    WHERE round_id = ?
    LIMIT 1
");
if (!$roundStmt) {
    jsonResponse(['ok' => false, 'msg' => 'Could not prepare panel round query.'], 500);
}
$roundStmt->bind_param("s", $round);
$roundStmt->execute();
$roundRow = $roundStmt->get_result()->fetch_assoc();
$roundStmt->close();

if (!$roundRow) {
    jsonResponse(['ok' => false, 'msg' => 'Invalid panel round.'], 400);
}

/* YY-ROUND-PN-SERIAL (panel-number-functions.php). One number per panel,
 * sequential across all facilities within the same year + round; no Dzongkhag
 * or facility code. Older values (e.g. 261PN14014-001) stay as stored.
 */
$prefix = mnPanelNumberPrefix($roundRow['panel_round'], 'PN');
try {
    $maxSerial = mnPanelMaxSerial($conn, 'rdt_panel', $prefix, $panelId);
} catch (\Throwable $e) {
    jsonResponse(['ok' => false, 'msg' => 'Could not generate panel number.'], 500);
}

$nextSerial  = $maxSerial + 1;
$panelNumber = mnPanelNumberFormat($prefix, $nextSerial);

jsonResponse([
    'ok'           => true,
    'panel_number' => $panelNumber,
    'prefix'       => $prefix,
    'serial'       => $nextSerial,
    'max_serial'   => $maxSerial,
    'dzongkhag'    => $dzongkhag,
    'facility'     => $facility,
    'round'        => $round,
    'panel_id'     => $panelId
]);