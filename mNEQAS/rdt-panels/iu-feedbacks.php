<?php
require_once("../config/session.php");
// RBAC backend enforcement: only Create/Update roles (Administrator, RCDC Lab,
// Health Worker) may write here; read-only "Others" are blocked for direct URL,
// form submit, and AJAX alike. (Delete is gated separately in delete.php.)
require_once("../config/auth.php"); mn_require_write();
require_once("../config/db-config.php");
require_once(__DIR__ . "/action-image-store.php");
require_once("../includes/css-jquery.php");
date_default_timezone_set('Asia/Thimphu');

$submit = $_POST['submit'] ?? '';
$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;

$check   = false;
$imgErr  = '';              // set when an Action image was sent but rejected
$errMsg  = 'Submission Failed!!';

if ($id <= 0) {
    $errMsg = 'Invalid panel id.';
} else {
    // Ownership guard for participants — same rule as pdf-feedbacks.php.
    if (!mn_is_rcdc()) {
        $own = $conn->prepare("SELECT facility_id FROM rdt_panel_informations WHERE panel_id=? LIMIT 1");
        $own->bind_param('i', $id);
        $own->execute();
        $ownRow = $own->get_result()->fetch_row();
        $own->close();
        if (!$ownRow || (int)$ownRow[0] !== (int)($_SESSION['facilityID'] ?? 0)) {
            mn_deny('You are not authorised to update this panel.');
        }
    }

    // Snapshot current findings/recommendation/action to enforce ordering server-side.
    $state = $conn->prepare("SELECT COALESCE(findings,''), COALESCE(recommendation,''), COALESCE(action,'') FROM rdt_panel_lab_result_informations WHERE panel_id=? LIMIT 1");
    $state->bind_param('i', $id);
    $state->execute();
    $stateRow = $state->get_result()->fetch_row();
    $state->close();
    if (!$stateRow) {
        $errMsg = 'No lab result exists for this panel yet.';
    } else {
        $hasRecommendation = $stateRow[1] !== '';
        $hasAction         = $stateRow[2] !== '';

        if ($submit === 'finding') {
            // Findings + Recommendation are RCDC-only, and locked once an Action has been recorded.
            if (!mn_is_rcdc()) {
                mn_deny('Only RCDC/Admin can enter Findings and Recommendations.');
            } elseif ($hasAction) {
                $errMsg = 'Findings/Recommendations are locked once the Action has been recorded.';
            } else {
                $finding        = $_POST['finding']        ?? '';
                $recommendation = $_POST['recommendation'] ?? '';
                $stmt = $conn->prepare("UPDATE rdt_panel_lab_result_informations SET findings=?, recommendation=? WHERE panel_id=?");
                $stmt->bind_param('ssi', $finding, $recommendation, $id);
                $check = $stmt->execute();
                $stmt->close();
                /* Replace this feedback's finding-library links. Every id is checked
                   against the module's own library, so a stale or foreign id is ignored. */
                if ($check) {
                    /* An absent field means "no instruction", not "none selected". The picker
                       was removed from the feedback screen, so a save no longer posts
                       pfm_finding_ids at all, and clearing on its absence would wipe the stored
                       selections - and with them the findings library's "used by saved feedback"
                       protection. When the field IS posted the set is replaced exactly as before.
                       The loop below then iterates an empty array, which is a no-op. */
                    $pfmPosted = isset($_POST['pfm_finding_ids']);
                    $pfmIds    = $pfmPosted ? (array)$_POST['pfm_finding_ids'] : array();
                    if ($pfmPosted) { $conn->query("DELETE FROM rdt_panel_feedback_findings WHERE panel_id = ".(int)$id); }
                    $pfmSeen = array(); $pfmPos = 0;
                    foreach ($pfmIds as $pfmOne) {
                        $pfmOne = (int)$pfmOne;
                        if ($pfmOne <= 0 || in_array($pfmOne, $pfmSeen, true)) { continue; }
                        $pfmOk = $conn->query("SELECT finding_id FROM panel_finding_master WHERE finding_id = $pfmOne AND module = 'rdt'");
                        if (!$pfmOk || !$pfmOk->num_rows) { continue; }
                        $pfmSeen[] = $pfmOne;
                        $conn->query("INSERT IGNORE INTO rdt_panel_feedback_findings (panel_id, finding_id, sort_order)
                                      VALUES (".(int)$id.", $pfmOne, ".($pfmPos++).")");
                    }
                }
                if (!$check) $errMsg = $conn->error;
            }
        } elseif ($submit === 'action') {
            // Action can only be entered AFTER a Recommendation exists — enforced server-side,
            // not just in the UI, so a crafted POST cannot skip the stage.
            $authState = mn_panel_state($conn, 'rdt', $id);
            if (!$hasRecommendation) {
                $errMsg = 'Action cannot be recorded before a Recommendation is entered.';
            } elseif (!mn_is_rcdc() && (!$authState || !$authState['closed'])) {
                // Facility users record action taken only once feedback is released (round CLOSED).
                mn_deny('Action taken can be recorded after the panel round closes.', $id);
            } else {
                $actions = $_POST['actions'] ?? '';
                $stmt = $conn->prepare("UPDATE rdt_panel_lab_result_informations SET action=? WHERE panel_id=?");
                $stmt->bind_param('si', $actions, $id);
                $check = $stmt->execute();
                $stmt->close();
                if (!$check) $errMsg = $conn->error;

                /* Evidence chosen in the same form is stored with the Action.
                   It used to be discarded unless the separate Upload button had
                   been pressed first, which is why the preview vanished on the
                   way back. A rejected file does not undo the text just saved:
                   the Action stands and the reason is reported. */
                if ($check && isset($_FILES['action_image'])
                    && (int)$_FILES['action_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $imgRes = rdt_action_image_store($conn, $id, $_FILES['action_image']);
                    if (!$imgRes['ok']) { $imgErr = $imgRes['msg']; }
                }
            }
        } else {
            $errMsg = 'Unknown submit type.';
        }
    }
}

/* Findings -> the facility must act; Action taken -> RCDC is informed.
   Raised only after the update actually succeeded. */
if ($check && (int)$id > 0) {
    require_once("../notifications/mn-notify.php");
    if ($submit === 'finding') {
        mn_notify($conn, 'RDT_PANEL_FEEDBACK_COMPLETED', (int)$id);
    } else if ($submit === 'action') {
        mn_notify($conn, 'RDT_PANEL_ACTION_COMPLETED', (int)$id);
    }
}

$lnk = "feedbacks.php?id=$id";
echo "<input type='hidden' name='lnk' id='lnk' value='$lnk'/>";

if ($check) {
    $conn->close();
?>
    <script>
        $(function() {
            var lnk = $('#lnk').val();
            Swal.fire({
                icon: <?php echo $imgErr === '' ? "'success'" : "'warning'"; ?>,
                confirmButtonText: 'Close',
                text: <?php echo json_encode($imgErr === ''
                        ? 'Submitted Successfully!!'
                        : 'Saved, but the image was not stored: ' . $imgErr); ?>,
                showCloseButton: true
            }).then(function () { window.location = lnk; });
        });
    </script>
<?php } else { ?>
    <script>
        $(function() {
            var lnk = $('#lnk').val();
            Swal.fire({
                icon: 'error',
                confirmButtonText: 'Close',
                text: <?php echo json_encode($errMsg); ?>,
                showCloseButton: true
            }).then(function () { window.location = lnk; });
        });
    </script>
<?php } ?>
