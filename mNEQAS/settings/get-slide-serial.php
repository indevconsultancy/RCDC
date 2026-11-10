<?php
/**
 * get-slide-serial.php
 * ---------------------------------------------------------------------------
 * AJAX endpoint for the Microscopy Panel Assignments form.
 *
 * Given the selected Health Facility (and the panel round), it
 * returns a consistent slide-number prefix built from the Dzongkhag + Facility
 * codes, plus the next facility-specific serial numbers.
 *
 * Slide number format:  YY-ROUND-MM-SERIAL  (see panel-number-functions.php)
 *   e.g. round 1, year 2026  ->  26-1-MM-01, 26-1-MM-02, ... 26-1-MM-05
 *   the next facility assigned in the same round continues at 26-1-MM-06.
 *
 * Request (GET):
 *   facility (required)  center_id of the health facility
 *   round    (optional)  round_id from panel_round_informations
 *   id       (optional)  panel_id to EXCLUDE from the counter (Update mode, so
 *                        the record's own slides don't inflate the serial)
 *   count    (optional)  number of slide serials to generate (defaults to configured total_slides)
 *
 * Response (JSON): { ok, prefix, nextSerial, slides:[N], total_slides, msg? }
 * ---------------------------------------------------------------------------
 */
require_once("../config/session.php");
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");
require_once(__DIR__ . "/panel-number-functions.php");
date_default_timezone_set('Asia/Thimphu');

header('Content-Type: application/json');

function out($arr){ echo json_encode($arr); exit; }

$facility  = isset($_GET['facility']) ? trim($_GET['facility']) : '';
$roundId   = isset($_GET['round'])    ? trim($_GET['round'])    : '';
$excludeId = isset($_GET['id'])       ? trim($_GET['id'])       : '';

// Facility is mandatory and must be a plain code (also guards against injection)
if ($facility === '' || !ctype_alnum($facility)) {
    out(['ok' => false, 'msg' => 'Facility is required']);
}

try {
    // 1) Validate facility (still required before numbers are issued)
    $fq = $commondb->prepare("SELECT center_id FROM ms_health_center_table WHERE center_id = ? LIMIT 1");
    $fq->bind_param('s', $facility);
    $fq->execute();
    $frow = $fq->get_result()->fetch_row();
    $fq->close();
    if (!$frow) {
        out(['ok' => false, 'msg' => 'Invalid facility']);
    }

    // 2) Resolve the panel round NUMBER from the round_id (part of the prefix)
    if ($roundId === '' || !ctype_digit($roundId)) {
        out(['ok' => false, 'msg' => 'Panel round is required']);
    }
    $mrProbe = @$conn->query("SHOW COLUMNS FROM panel_round_informations LIKE 'total_slides'");
    $mrHasTotalSlides = ($mrProbe && $mrProbe->num_rows > 0);
    $col = $mrHasTotalSlides ? 'panel_round, total_slides' : 'panel_round, 5 AS total_slides';

    $rq = $conn->prepare("SELECT $col FROM panel_round_informations WHERE round_id = ? LIMIT 1");
    $rid = (int)$roundId;
    $rq->bind_param('i', $rid);
    $rq->execute();
    $rrow = $rq->get_result()->fetch_row();
    $rq->close();
    if (!$rrow || $rrow[0] === null) {
        out(['ok' => false, 'msg' => 'Invalid panel round']);
    }

    $roundNumber = $rrow[0];
    $totalSlides = (!empty($rrow[1]) && (int)$rrow[1] > 0) ? (int)$rrow[1] : 5;
    if (isset($_GET['count']) && (int)$_GET['count'] > 0) {
        $totalSlides = (int)$_GET['count'];
    }

    // 3) YY-ROUND-MM- prefix and the next N numbers in that year + round
    $prefix = mnPanelNumberPrefix($roundNumber, 'MM');
    $slides = mnPanelNextNumbers($conn, 'micro_slide', $prefix, $totalSlides, $excludeId);
    $next   = mnPanelMaxSerial($conn, 'micro_slide', $prefix, $excludeId) + 1;

    out([
        'ok'           => true,
        'prefix'       => $prefix,
        'nextSerial'   => $next,
        'slides'       => $slides,
        'total_slides' => $totalSlides
    ]);

} catch (\Throwable $e) {
    out(['ok' => false, 'msg' => 'Server error generating slide numbers']);
}
