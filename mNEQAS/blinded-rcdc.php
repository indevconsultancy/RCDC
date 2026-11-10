<?php
/**
 * Blinded Rechecking RCDC Wizard Helper & Controller.
 * Manages stepper status, stage gating, and validation for RCDC rechecking.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseDir = __DIR__;
if (file_exists($baseDir . '/config/session.php')) {
    require_once $baseDir . '/config/session.php';
    require_once $baseDir . '/config/db-config.php';
    require_once $baseDir . '/blinded/blinded-functions.php';
} else {
    require_once $baseDir . '/../config/session.php';
    require_once $baseDir . '/../config/db-config.php';
    require_once $baseDir . '/blinded-functions.php';
}

// Record ID from GET or POST
$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : (isset($_POST['biid']) ? (int)$_POST['biid'] : 0));

// Fetch intact slides and rechecking counts
$intactSlides = array();
$c1Cnt = 0;
$c2Cnt = 0;

if ($id > 0 && isset($conn)) {
    $stmt = $conn->prepare("SELECT result_id, slide_no, c1_microscopy, c2_microscopy FROM blinded_results WHERE slide_status = 'Intact' AND blinded_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $intactSlides[] = $r;
            if ($r['c1_microscopy'] !== null && trim((string)$r['c1_microscopy']) !== '') {
                $c1Cnt++;
            }
            if ($r['c2_microscopy'] !== null && trim((string)$r['c2_microscopy']) !== '') {
                $c2Cnt++;
            }
        }
        $stmt->close();
    }
}

/* =========================================================================
   Wizard Stepper Status Badge Derivation Logic (Lines 45–80)
   ========================================================================= */

// Derive the counts strictly based on intact eligible slides:
$intactCnt = count($intactSlides); // total intact slides eligible for rechecking
$c1Done    = ($intactCnt > 0 && $c1Cnt === $intactCnt); // ALL intact slides have C1 filled
$c2Done    = ($c1Done && $intactCnt > 0 && $c2Cnt === $intactCnt); // ALL intact slides have C2 filled

// Update Step 5 (Controller 2) Stepper Badge State:
if ($c2Done) {
    // IF $c2Done is TRUE: Badge = Green Checkmark, Status = "Completed"
    $c2BadgeClass = 'done';
    $c2BadgeIcon  = 'fa-check';
    $c2Status     = 'Completed';
} elseif ($c1Done && $c2Cnt > 0) {
    // ELSE IF $c1Done is TRUE and $c2Cnt > 0: Badge = Active/Blue, Status = "In Progress"
    $c2BadgeClass = 'active';
    $c2BadgeIcon  = 'fa-hourglass-half';
    $c2Status     = 'In Progress';
} else {
    // ELSE: Badge = Grey Number (5), Status = "Pending" (NOT "Completed")
    $c2BadgeClass = 'pending';
    $c2BadgeIcon  = '5';
    $c2Status     = 'Pending';
}

// Controller 1 (Step 4) Stepper Badge State:
if ($c1Done) {
    $c1BadgeClass = 'done';
    $c1BadgeIcon  = 'fa-check';
    $c1Status     = 'Completed';
} elseif ($c1Cnt > 0) {
    $c1BadgeClass = 'active';
    $c1BadgeIcon  = 'fa-hourglass-half';
    $c1Status     = 'In Progress';
} else {
    $c1BadgeClass = 'pending';
    $c1BadgeIcon  = '4';
    $c1Status     = 'Pending';
}

/* Controller 1 save helper */
if (!function_exists('saveC1')) {
    function saveC1() {
        return true;
    }
}
