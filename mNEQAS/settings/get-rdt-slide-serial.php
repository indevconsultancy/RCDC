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
$count     = 10;

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
    jsonResponse(['ok' => false, 'msg' => 'Facility prepare failed'], 500);
}
$facilityStmt->bind_param("ss", $facility, $dzongkhag);
$facilityStmt->execute();
$facilityRow = $facilityStmt->get_result()->fetch_assoc();
$facilityStmt->close();

if (!$facilityRow) {
    jsonResponse(['ok' => false, 'msg' => 'Invalid health facility.'], 400);
}

/* Validate round and fetch configured total_panels */
$roundStmt = $conn->prepare("
    SELECT round_id, panel_round, total_panels
    FROM rdt_panel_round_informations
    WHERE round_id = ?
    LIMIT 1
");
if (!$roundStmt) {
    jsonResponse(['ok' => false, 'msg' => 'Round prepare failed'], 500);
}
$roundStmt->bind_param("s", $round);
$roundStmt->execute();
$roundRow = $roundStmt->get_result()->fetch_assoc();
$roundStmt->close();

if (!$roundRow) {
    jsonResponse(['ok' => false, 'msg' => 'Invalid panel round.'], 400);
}

// Derive panel count dynamically from round's configured total_panels
$roundTotalPanels = isset($roundRow['total_panels']) ? (int)$roundRow['total_panels'] : 0;
if ($roundTotalPanels <= 0) {
    $roundTotalPanels = 10;
}

$requestedCount = isset($_GET['count']) ? (int)$_GET['count'] : 0;
if ($requestedCount > 0) {
    $count = $requestedCount;
} else {
    $count = $roundTotalPanels;
}

/* YY-ROUND-SL-SERIAL (panel-number-functions.php, same scheme as Microscopy's
 * YY-ROUND-MM-SERIAL). No Dzongkhag / facility code appears in the number.
 * Older slide numbers (e.g. 261SL14014-001) are left exactly as stored.
 */
$prefix = mnPanelNumberPrefix($roundRow['panel_round'], 'SL');

/* The block of numbers a facility gets is decided by its POSITION in the round,
 * not by the highest serial issued so far:
 *
 *     1st facility of the round -> 1..N
 *     2nd facility of the round -> (N+1)..2N
 *     3rd facility of the round -> (2N+1)..3N
 *
 * Counting the distinct facilities already assigned to this round_id gives that
 * position directly, so a round with no assignments yet starts at 1. The old
 * rule read the largest serial already present under the prefix, which meant a
 * brand-new round inherited whatever numbering an earlier round had reached.
 *
 * The panel being edited is excluded from the count so that re-saving it does
 * not push its own block further along.
 */
$facilitySql    = "SELECT COUNT(DISTINCT facility_id) FROM rdt_panel_informations WHERE round_id = ?";
$facilityTypes  = 'i';
$facilityParams = array((int)$round);
if ($panelId !== '' && ctype_digit((string)$panelId)) {
    $facilitySql   .= " AND panel_id <> ?";
    $facilityTypes .= 'i';
    $facilityParams[] = (int)$panelId;
}

$facilityCount = 0;
$countStmt = $conn->prepare($facilitySql);
if (!$countStmt) {
    jsonResponse(['ok' => false, 'msg' => 'Could not generate slide numbers.'], 500);
}
$countStmt->bind_param($facilityTypes, ...$facilityParams);
$countStmt->execute();
$countStmt->bind_result($facilityCount);
$countStmt->fetch();
$countStmt->close();
$facilityCount = (int)$facilityCount;

$startSlide = ($facilityCount * $count) + 1;

$slides = [];
for ($i = 0; $i < $count; $i++) {
    $slides[] = mnPanelNumberFormat($prefix, $startSlide + $i);
}

jsonResponse([
    'ok'            => true,
    'slides'        => $slides,
    'prefix'        => $prefix,
    'start'         => $startSlide,
    'facilityCount' => $facilityCount,
    'count'         => $count,
    'total_panels'  => $roundTotalPanels
]);
