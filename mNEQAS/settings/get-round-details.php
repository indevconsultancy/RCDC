<?php
require_once("../config/session.php");
require_once("../config/auth.php");
mn_require_rcdc();

require_once("../config/db-config.php");

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Thimphu');

function jsonResponse(array $data, int $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$roundId = isset($_GET['round_id']) ? trim($_GET['round_id']) : (isset($_GET['round']) ? trim($_GET['round']) : '');

if ($roundId === '') {
    jsonResponse(['ok' => false, 'msg' => 'Round ID is required.'], 400);
}

$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';

if ($type === 'micro' || $type === 'microscopy') {
    $mrProbe = @$conn->query("SHOW COLUMNS FROM panel_round_informations LIKE 'total_slides'");
    $mrHasTotalSlides = ($mrProbe && $mrProbe->num_rows > 0);
    $col = $mrHasTotalSlides ? 'total_slides' : '5 AS total_slides';
    $stmt = $conn->prepare("
        SELECT round_id, panel_round, $col, closing_date, shipment_date
        FROM panel_round_informations
        WHERE round_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        jsonResponse(['ok' => false, 'msg' => 'Database prepare failed.'], 500);
    }
    $stmt->bind_param("s", $roundId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonResponse(['ok' => false, 'msg' => 'Microscopy panel round not found.'], 404);
    }

    $totalSlides = (isset($row['total_slides']) && (int)$row['total_slides'] > 0) ? (int)$row['total_slides'] : 5;

    jsonResponse([
        'ok'            => true,
        'round_id'      => (int)$row['round_id'],
        'panel_round'   => $row['panel_round'],
        'total_slides'  => $totalSlides,
        'total_panels'  => $totalSlides,
        'closing_date'  => $row['closing_date'] ?? null,
        'shipment_date' => $row['shipment_date'] ?? null
    ]);
}

// RDT query (default)
$stmt = $conn->prepare("
    SELECT round_id, panel_round, total_panels, closing_date, shipment_date
    FROM rdt_panel_round_informations
    WHERE round_id = ?
    LIMIT 1
");

if (!$stmt) {
    jsonResponse(['ok' => false, 'msg' => 'Database prepare failed.'], 500);
}

$stmt->bind_param("s", $roundId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fallback to panel_round_informations if not found in rdt
if (!$row) {
    $mrProbe = @$conn->query("SHOW COLUMNS FROM panel_round_informations LIKE 'total_slides'");
    $mrHasTotalSlides = ($mrProbe && $mrProbe->num_rows > 0);
    $col = $mrHasTotalSlides ? 'total_slides' : '5 AS total_slides';
    $stmtMicro = $conn->prepare("
        SELECT round_id, panel_round, $col, closing_date, shipment_date
        FROM panel_round_informations
        WHERE round_id = ?
        LIMIT 1
    ");
    if ($stmtMicro) {
        $stmtMicro->bind_param("s", $roundId);
        $stmtMicro->execute();
        $row = $stmtMicro->get_result()->fetch_assoc();
        $stmtMicro->close();
    }
}

if (!$row) {
    jsonResponse(['ok' => false, 'msg' => 'Panel round not found.'], 404);
}

$totalPanels = isset($row['total_panels']) ? (int)$row['total_panels'] : (isset($row['total_slides']) ? (int)$row['total_slides'] : 0);
if ($totalPanels <= 0) {
    $totalPanels = 10;
}

jsonResponse([
    'ok'            => true,
    'round_id'      => (int)$row['round_id'],
    'panel_round'   => $row['panel_round'],
    'total_panels'  => $totalPanels,
    'total_slides'  => $totalPanels,
    'closing_date'  => $row['closing_date'] ?? null,
    'shipment_date' => $row['shipment_date'] ?? null
]);
