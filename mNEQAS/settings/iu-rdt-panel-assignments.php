<?php

require_once("../config/session.php");
require_once("../config/auth.php");
mn_require_rcdc();

require_once("../config/db-config.php");
require_once("../config/commondb-config.php");

date_default_timezone_set('Asia/Thimphu');

/*
|--------------------------------------------------------------------------
| Response mode — strict JSON only
|--------------------------------------------------------------------------
| This endpoint is exclusively an XHR API for the Add/Update form on
| rdt-panel-assignments.php, whose <form action="javascript:void(0);">
| guarantees the browser can never perform a native synchronous POST here.
| Every code path returns a single JSON envelope:
|     {status: 'success'|'error', message: '...'}
| No HTML/<script> fallback — hitting the URL directly in the browser will
| just render raw JSON, which is the desired API contract.
*/
$lnk = 'rdt-panel-assignments.php';

function respond($isXhr, $lnk, $ok, $msg) {
    global $conn;
    $status   = $ok ? 'success' : 'error';
    $response = ['status' => $status, 'message' => (string)$msg];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
    exit;
}

// Kept for call-site compatibility — the $isXhr flag threaded through every
// respond() call is now ignored. Setting it here so any legacy read still
// resolves to a defined value.
$isXhr = true;

/*
|--------------------------------------------------------------------------
| Input — accept the form's actual field names.
|--------------------------------------------------------------------------
| The Add/Update form posts `pround`, `dzongkhag2`, `facility`, `panel_number`,
| `slideno[]`, `slideid[]`. Historically some callers used `dzongkhag` — kept
| as a fallback so we don't silently break other pages.
*/
$action       = isset($_POST['action'])       ? (int)$_POST['action']         : 0;
$id           = isset($_POST['id'])           ? trim($_POST['id'])            : '';
$round        = isset($_POST['pround'])       ? trim($_POST['pround'])        : '';
$facility     = isset($_POST['facility'])     ? trim($_POST['facility'])      : '';
$dzongkhag    = isset($_POST['dzongkhag2'])   ? trim($_POST['dzongkhag2'])
             : (isset($_POST['dzongkhag'])    ? trim($_POST['dzongkhag'])     : '');
$panelNumber  = isset($_POST['panel_number']) ? trim($_POST['panel_number'])  : '';

$slideno = (isset($_POST['slideno']) && is_array($_POST['slideno'])) ? $_POST['slideno'] : [];
$slideid = (isset($_POST['slideid']) && is_array($_POST['slideid'])) ? $_POST['slideid'] : [];

/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/
if ($action !== 1 && $action !== 2)         respond($isXhr, $lnk, false, 'Invalid submission action.');
if ($round === '')                          respond($isXhr, $lnk, false, 'Panel round is required.');
if ($dzongkhag === '')                      respond($isXhr, $lnk, false, 'Dzongkhag is required.');
if ($facility === '')                       respond($isXhr, $lnk, false, 'Health facility is required.');
if ($panelNumber === '')                    respond($isXhr, $lnk, false, 'Panel number was not generated. Please re-select Round, Dzongkhag and Health Facility.');
if ($action === 1 && count($slideno) === 0) respond($isXhr, $lnk, false, 'Slide numbers were not generated. Please re-select Round, Dzongkhag and Health Facility.');

$roundInt = (int)$round;

// Fetch configured total_panels for this round
$roundTotalStmt = $conn->prepare("SELECT total_panels FROM rdt_panel_round_informations WHERE round_id = ? LIMIT 1");
if (!$roundTotalStmt) respond($isXhr, $lnk, false, $conn->error);
$roundTotalStmt->bind_param("i", $roundInt);
$roundTotalStmt->execute();
$roundInfo = $roundTotalStmt->get_result()->fetch_assoc();
$roundTotalStmt->close();

$expectedPanels = isset($roundInfo['total_panels']) ? (int)$roundInfo['total_panels'] : 0;
if ($expectedPanels <= 0) {
    $expectedPanels = 10;
}

// Clean slide numbers — drop blanks so a stray empty field cannot poison the
// duplicate check or produce empty rows in the slide table.
$cleanSlides = [];
foreach ($slideno as $slide) {
    $slide = trim($slide);
    if ($slide !== '') $cleanSlides[] = $slide;
}
if (count($cleanSlides) < $expectedPanels) {
    respond($isXhr, $lnk, false, "Expected {$expectedPanels} panel slide numbers, but received " . count($cleanSlides) . ". Please re-select to regenerate.");
}
$slideno = array_slice($cleanSlides, 0, $expectedPanels);

/*
|--------------------------------------------------------------------------
| Facility ↔ Dzongkhag ownership check
|--------------------------------------------------------------------------
| The facility_id posted here MUST belong to the submitted dzongkhag_code.
| Same check that get-rdt-panel-number.php / get-rdt-slide-serial.php run,
| so a crafted POST cannot stitch mismatched values that would corrupt the
| generated panel_number's embedded dzongkhag segment.
*/
$fchk = $commondb->prepare("SELECT center_id FROM ms_health_center_table WHERE center_id = ? AND dzongkhag_code = ? LIMIT 1");
if (!$fchk) respond($isXhr, $lnk, false, $commondb->error);
$fchk->bind_param("ss", $facility, $dzongkhag);
if (!$fchk->execute()) { $err = $fchk->error; $fchk->close(); respond($isXhr, $lnk, false, $err); }
$facilityOk = $fchk->get_result()->fetch_row();
$fchk->close();
if (!$facilityOk) respond($isXhr, $lnk, false, 'Selected Health Facility does not belong to the selected Dzongkhag.');

/*
|--------------------------------------------------------------------------
| Sequential panels per (Round, Dzongkhag, Facility)
|--------------------------------------------------------------------------
| A facility may receive MORE slides for the same round (e.g. 001-005 first,
| then 006-010), so several panels may share the same Round + Dzongkhag +
| Facility. The old "at most ONE panel per combination" rule is therefore gone.
|
| True duplicates are still blocked, by identity instead of by combination:
|   - assertSlideNumbersFree()  no slide number may repeat for the SAME facility
|   - assertPanelNumberFree()   no panel number may belong to another panel
| Both run inside the transaction, so a double-submitted form (which carries the
| slide numbers already generated for it) is still rejected instead of creating
| a second, identical panel.
*/

/*
|--------------------------------------------------------------------------
| Duplicate slide-number guard (race window)
|--------------------------------------------------------------------------
| Two RCDC users adding panels for the same facility at the same moment
| would both compute the same next serial in get-rdt-slide-serial.php. This
| check runs inside the transaction so the whole save aborts cleanly on a
| collision — no partial insert.
*/
function assertSlideNumbersFree($conn, array $slideno, $roundId, $facilityId, $excludePanelId = null) {
    if (count($slideno) === 0) return;
    $placeholders = implode(',', array_fill(0, count($slideno), '?'));
    /* Scoped to this round AND this facility. The displayed number
       (YY-ROUND-SL-01..10) intentionally carries no Dzongkhag or facility code,
       so the same visible number legitimately occurs for a different facility
       and for a different round. Checking it globally rejected those as
       duplicates — a false positive that blocked perfectly valid assignments.
       The real constraint is that one facility cannot hold the same slide
       number twice within one round, which is what is enforced here. */
    $sql = "SELECT s.slide_number
            FROM rdt_panel_slide_informations s
            INNER JOIN rdt_panel_informations p ON p.panel_id = s.panel_id
            WHERE s.slide_number IN ($placeholders)
              AND p.round_id    = ?
              AND p.facility_id = ?";
    $params = $slideno;
    $types  = str_repeat('s', count($slideno));
    $params[] = (int)$roundId;    $types .= 'i';
    $params[] = (string)$facilityId; $types .= 's';
    if ($excludePanelId !== null) {
        $sql   .= " AND p.panel_id != ?";
        $params[] = (int)$excludePanelId;
        $types .= 'i';
    }
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $hit = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($hit) {
        throw new Exception('This facility already has one of these slide numbers in the selected round (possible concurrent save). Please retry.');
    }
}

/*
| Panel No. (YY-ROUND-PN-SERIAL) is sequential across all facilities, so two
| concurrent saves could be issued the same number — refuse the second one.
*/
function assertPanelNumberFree($conn, $panelNumber, $excludePanelId = null) {
    $sql = "SELECT panel_id FROM rdt_panel_informations WHERE panel_number = ?";
    if ($excludePanelId !== null) $sql .= " AND panel_id != ?";
    $stmt = $conn->prepare($sql . " LIMIT 1");
    if (!$stmt) throw new Exception($conn->error);
    if ($excludePanelId !== null) {
        $ex = (int)$excludePanelId;
        $stmt->bind_param("si", $panelNumber, $ex);
    } else {
        $stmt->bind_param("s", $panelNumber);
    }
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $hit = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($hit) {
        throw new Exception('The generated Panel No. is already used by another RDT panel (possible concurrent save). Please re-select the Health Facility to generate a new number and retry.');
    }
}

/*
|--------------------------------------------------------------------------
| INSERT (Add)
|--------------------------------------------------------------------------
*/
if ($action === 1) {

    $idResult = $conn->query("SELECT COALESCE(MAX(panel_id), 0) + 1 FROM rdt_panel_informations");
    if (!$idResult) respond($isXhr, $lnk, false, $conn->error);
    $panelId = (int)$idResult->fetch_row()[0];

    $conn->begin_transaction();
    try {
        assertSlideNumbersFree($conn, $slideno, $roundInt, $facility);
        assertPanelNumberFree($conn, $panelNumber);

        $stmt1 = $conn->prepare("INSERT INTO rdt_panel_informations (panel_id, panel_number, round_id, facility_id) VALUES (?, ?, ?, ?)");
        if (!$stmt1) throw new Exception($conn->error);
        $stmt1->bind_param("isis", $panelId, $panelNumber, $roundInt, $facility);
        if (!$stmt1->execute()) throw new Exception($stmt1->error);
        $stmt1->close();

        $stmt2 = $conn->prepare("INSERT INTO rdt_panel_slide_informations (panel_id, slide_number) VALUES (?, ?)");
        if (!$stmt2) throw new Exception($conn->error);
        foreach ($slideno as $slideNumber) {
            $stmt2->bind_param("is", $panelId, $slideNumber);
            if (!$stmt2->execute()) throw new Exception($stmt2->error);
        }
        $stmt2->close();

        $conn->commit();

        /* The facility now owns the next step (receive the panel and submit results).
           After COMMIT; mn_notify() never throws, so respond() below is unaffected. */
        require_once(__DIR__ . "/../notifications/mn-notify.php");
        mn_notify($conn, 'RDT_PANEL_ASSIGNED', $panelId);

        respond($isXhr, $lnk, true, 'RDT Panel Assignment created successfully.');
    } catch (Exception $e) {
        $conn->rollback();
        respond($isXhr, $lnk, false, $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| UPDATE
|--------------------------------------------------------------------------
| When Dzongkhag / Facility are unchanged the client keeps the existing
| slide numbers as-is (see the guard in rdt-panel-assignments.php's facility
| change handler); the UPDATE loop then writes the same values back — a
| row-level no-op that preserves them safely.
*/
if ($id === '')                    respond($isXhr, $lnk, false, 'Panel ID is required.');
if (count($slideno) === 0)         respond($isXhr, $lnk, false, 'Slide numbers were not submitted.');

$conn->begin_transaction();
try {
    $panelIdInt = (int)$id;

    assertSlideNumbersFree($conn, $slideno, $roundInt, $facility, $panelIdInt);
    assertPanelNumberFree($conn, $panelNumber, $panelIdInt);

    $stmt = $conn->prepare("UPDATE rdt_panel_informations SET round_id = ?, facility_id = ?, panel_number = ? WHERE panel_id = ?");
    if (!$stmt) throw new Exception($conn->error);

    $panelId  = $panelIdInt;
    $stmt->bind_param("issi", $roundInt, $facility, $panelNumber, $panelId);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // Query existing slide IDs for this panel ordered by slide_id ASC
    $existingSlidesStmt = $conn->prepare("SELECT slide_id FROM rdt_panel_slide_informations WHERE panel_id = ? ORDER BY slide_id ASC");
    if (!$existingSlidesStmt) throw new Exception($conn->error);
    $existingSlidesStmt->bind_param("i", $panelIdInt);
    $existingSlidesStmt->execute();
    $resExisting = $existingSlidesStmt->get_result();
    $existingIds = [];
    while ($row = $resExisting->fetch_assoc()) {
        $existingIds[] = (int)$row['slide_id'];
    }
    $existingSlidesStmt->close();

    $slideUpdate = $conn->prepare("UPDATE rdt_panel_slide_informations SET slide_number = ? WHERE slide_id = ?");
    if (!$slideUpdate) throw new Exception($conn->error);

    $slideInsert = $conn->prepare("INSERT INTO rdt_panel_slide_informations (panel_id, slide_number) VALUES (?, ?)");
    if (!$slideInsert) throw new Exception($conn->error);

    for ($i = 0; $i < $expectedPanels; $i++) {
        $currentSlideNo = trim($slideno[$i]);
        if ($i < count($existingIds)) {
            $currentSlideId = (int)$existingIds[$i];
            $slideUpdate->bind_param("si", $currentSlideNo, $currentSlideId);
            if (!$slideUpdate->execute()) throw new Exception($slideUpdate->error);
        } else {
            $slideInsert->bind_param("is", $panelIdInt, $currentSlideNo);
            if (!$slideInsert->execute()) throw new Exception($slideInsert->error);
        }
    }
    $slideUpdate->close();
    $slideInsert->close();

    // Delete any excess slide rows if the new round's panel capacity is lower than before
    if (count($existingIds) > $expectedPanels) {
        $toDelete = array_slice($existingIds, $expectedPanels);
        if (count($toDelete) > 0) {
            $delPlaceholders = implode(',', array_fill(0, count($toDelete), '?'));
            $types = str_repeat('i', count($toDelete));

            $delLab = $conn->prepare("DELETE FROM rdt_panel_lab_results WHERE slide_id IN ($delPlaceholders)");
            if ($delLab) {
                $delLab->bind_param($types, ...$toDelete);
                $delLab->execute();
                $delLab->close();
            }

            $delRcdc = $conn->prepare("DELETE FROM rdt_panel_rcdc_results WHERE slide_id IN ($delPlaceholders)");
            if ($delRcdc) {
                $delRcdc->bind_param($types, ...$toDelete);
                $delRcdc->execute();
                $delRcdc->close();
            }

            $delSlide = $conn->prepare("DELETE FROM rdt_panel_slide_informations WHERE slide_id IN ($delPlaceholders)");
            if ($delSlide) {
                $delSlide->bind_param($types, ...$toDelete);
                $delSlide->execute();
                $delSlide->close();
            }
        }
    }

    $conn->commit();
    respond($isXhr, $lnk, true, 'RDT Panel Assignment updated successfully.');
} catch (Exception $e) {
    $conn->rollback();
    respond($isXhr, $lnk, false, $e->getMessage());
}
