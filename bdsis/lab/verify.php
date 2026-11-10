<?php
/**
 * Verify a laboratory record, or return it for correction.
 *
 * POST only. Both actions are supervisory: they belong to the district health
 * office, which reviews what the facilities in its dzongkhag entered. Neither is
 * available to a facility role -- 'verify' is not in the facility matrix.
 *
 * Every value that decides anything is taken from the session or the database.
 * The post carries only which record, which action, and (for a return) why.
 */

ob_start();   /* so a refusal can discard the layout and answer with a real 403 */

require_once("../config/session.php");
require_once("../config/db-config.php");
require_once("../includes/rbac.php");
require_once("../includes/csrf.php");
require_once("../includes/lab-workflow.php");
require_once("../includes/css-jquery.php");

$user = $_SESSION["users"];

bdsis_require_module();
bdsis_csrf_require();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bdsis_deny('This action must be submitted from the laboratory record.', 'verify');
}

$sid = isset($_POST["sid"]) ? trim((string)$_POST["sid"]) : '';
if ($sid === '') { bdsis_deny('No laboratory record was specified.', 'verify'); }

/* Ownership first: the record must belong to a facility this user supervises.
   Checked against the SUBJECT's centre, never against anything posted. */
bdsis_require_subject($bdsis, $sid);

/* The capability. Verify is a bacteriology-module action in the matrix; a role
   without it never reaches the branches below. */
bdsis_require_can('verify', 'Your role is not permitted to verify laboratory records.', 'bacteriology');

$act = isset($_POST["do"]) ? (string)$_POST["do"] : '';
$lnk = "lab.php?action=1&sid=" . urlencode($sid);

if ($act === 'verify') {

    /* Approving is a separate, higher action that no scoped role holds; a record
       already approved is closed. bdsis_lab_can_verify() also refuses a record
       whose required sections are not finished. */
    $why = '';
    if (!bdsis_lab_can_verify($bdsis, $sid, $why)) {
        bdsis_deny($why !== '' ? $why : 'This record cannot be verified yet.', 'verify', 'bacteriology');
    }
    $ok  = bdsis_lab_verify($bdsis, $sid, $user);
    $msg = $ok ? 'Record verified.' : 'The record could not be verified.';

} elseif ($act === 'return') {

    $status = bdsis_lab_status($bdsis, $sid);
    if ($status === BDSIS_LAB_APPROVED) {
        bdsis_deny('An approved record cannot be reopened.', 'return', 'bacteriology');
    }
    $reason = isset($_POST["reason"]) ? trim((string)$_POST["reason"]) : '';
    if ($reason === '') {
        bdsis_deny('Say what needs correcting before returning the record.', 'return', 'bacteriology');
    }
    $ok  = bdsis_lab_return($bdsis, $sid, $user, $reason);
    $msg = $ok ? 'Record returned for correction.' : 'The record could not be returned.';

} else {
    bdsis_deny('Unrecognised action.', 'verify');
}

/* AJAX submit: discard the buffered popup page and return the same message and
   target as JSON, so verifying/returning happens in place without this page or a
   Close click. Logic and message text unchanged; a non-AJAX request still gets
   the SweetAlert + redirect page below. */
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => (bool)$ok, 'msg' => $msg, 'lnk' => $lnk));
    exit;
}

?>
<input type="hidden" id="lnk" value="<?=htmlspecialchars($lnk, ENT_QUOTES);?>"/>
<script>
    $(function () {
        Swal.fire({
            icon: <?=$ok ? "'success'" : "'error'";?>,
            text: <?=json_encode($msg);?>,
            confirmButtonText: 'Close',
            showCloseButton: true
        }).then(function () { window.location = $('#lnk').val(); });
    });
</script>
