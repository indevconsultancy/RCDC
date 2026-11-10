<?php
// LEGACY handler. Superseded by iu-panel-save.php since the panels.php wiring
// migration. Kept on disk as a rollback safety net; not linked from any active
// UI page. mn_require_write() still runs so a direct POST from a read-only role
// cannot bypass the RBAC layer while this file remains checked in.
require_once("../config/session.php");
require_once("../config/auth.php"); mn_require_write();
// It has no facility / deadline / slide-ownership checks, so it is RCDC-only while it remains.
mn_require_rcdc('This legacy endpoint is restricted to RCDC users.');
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");
require_once("../includes/css-jquery.php");

date_default_timezone_set('Asia/Thimphu');

/* ============================================================
   HELPER FUNCTIONS
   ============================================================ */

function generateFlag($conn, $slide, $species)
{
    $pos_array = ['Pf', 'Pv', 'F+V'];

    $rcdc = $conn->query("SELECT rcdc_species FROM rdt_panel_rcdc_results WHERE slide_id='$slide'");
    $rcdc = $rcdc ? $rcdc->fetch_row() : null;
    $rcdc_species = $rcdc[0] ?? '';

    if ($species === '') {
        $pos_flag = 'MI';
    } else {
        if (in_array($rcdc_species, $pos_array) && in_array($species, $pos_array)) {
            $pos_flag = 'TP';
        } elseif ($rcdc_species === 'Negative' && in_array($species, $pos_array)) {
            $pos_flag = 'FP';
        } elseif (in_array($rcdc_species, $pos_array) && $species === 'Negative') {
            $pos_flag = 'FN';
        } elseif ($rcdc_species === 'Negative' && $species === 'Negative') {
            $pos_flag = 'TN';
        } else {
            $pos_flag = 'MI';
        }
    }

    $species_flag = 0;
    if (in_array($rcdc_species, $pos_array) && $rcdc_species === $species) {
        $species_flag = 1;
    }

    return [$pos_flag, $species_flag];
}

function calculateScore($conn, $id)
{
    $rcdc_pos = $conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results t1
                              INNER JOIN rdt_panel_slide_informations t2 ON t1.slide_id = t2.slide_id
                              WHERE t2.panel_id = '$id' AND t1.rcdc_species IN ('Pf','Pv','F+V')");
    $rcdc_pos = $rcdc_pos ? $rcdc_pos->fetch_row() : [0];

    $rcdc_neg = $conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results t1
                              INNER JOIN rdt_panel_slide_informations t2 ON t1.slide_id = t2.slide_id
                              WHERE t2.panel_id = '$id' AND t1.rcdc_species = 'Negative'");
    $rcdc_neg = $rcdc_neg ? $rcdc_neg->fetch_row() : [0];

    $panel_received = $conn->query("SELECT COUNT(*) FROM rdt_panel_slide_informations
                                    WHERE panel_id = '$id' AND slide_status = 'Intact'");
    $panel_received = $panel_received ? $panel_received->fetch_row() : [0];

    $tp = $conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results t1
                        INNER JOIN rdt_panel_slide_informations t2 ON t1.slide_id = t2.slide_id
                        WHERE t2.panel_id = '$id' AND positivity_flag = 'TP'");
    $tp = $tp ? $tp->fetch_row() : [0];

    $tn = $conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results t1
                        INNER JOIN rdt_panel_slide_informations t2 ON t1.slide_id = t2.slide_id
                        WHERE t2.panel_id = '$id' AND positivity_flag = 'TN'");
    $tn = $tn ? $tn->fetch_row() : [0];

    $species_flag_total = $conn->query("SELECT SUM(species_flag) FROM rdt_panel_lab_results t1
                                        INNER JOIN rdt_panel_slide_informations t2 ON t1.slide_id = t2.slide_id
                                        WHERE t2.panel_id = '$id'");
    $species_flag_total = $species_flag_total ? $species_flag_total->fetch_row() : [0];

    if (($rcdc_pos[0] ?? 0) != 0) {
        $sen_score = ($tp[0] / $rcdc_pos[0]) * 100;
        $spe_score = (($species_flag_total[0] ?? 0) / $rcdc_pos[0]) * 100;
    } else {
        $sen_score = $spe_score = 0;
    }

    if (($rcdc_neg[0] ?? 0) != 0) {
        $spc_score = ($tn[0] / $rcdc_neg[0]) * 100;
    } else {
        $spc_score = 0;
    }

    $avg_score1 = round(($sen_score + $spc_score + $spe_score) / 3, 2) * 0.8;

    $tinfo = $conn->query("SELECT examiner, kitname, batchlot, manufacturer, expiry_date
                           FROM rdt_panel_lab_result_informations WHERE panel_id = '$id'");
    $tinfo = $tinfo ? $tinfo->fetch_row() : ['', '', '', '', ''];

    $score2 = 0;
    if (($tinfo[0] ?? '') !== '') $score2++;
    if (($tinfo[1] ?? '') !== '') $score2++;
    if (($tinfo[2] ?? '') !== '') $score2++;
    if (($tinfo[3] ?? '') !== '') $score2++;
    if (($tinfo[4] ?? '') !== '') $score2++;
    $avg_score2 = ($score2 / 5) * 20;

    $fnl_score = $avg_score1 + $avg_score2;

    if ($fnl_score == 100) {
        $achievement = 'Excellent';
    } elseif ($fnl_score >= 95) {
        $achievement = 'Very Good';
    } elseif ($fnl_score >= 90) {
        $achievement = 'Good';
    } elseif ($fnl_score >= 80) {
        $achievement = 'Satisfactory';
    } else {
        $achievement = 'Need Improvement';
    }

    return [
        $sen_score,
        $spc_score,
        $spe_score,
        $avg_score1,
        $avg_score2,
        $fnl_score,
        $achievement
    ];
}

/* ============================================================
   MAIN LOGIC
   ============================================================ */

$null  = null;
$id    = $conn->real_escape_string($_POST['id'] ?? '');
$type  = $conn->real_escape_string($_POST['type'] ?? '');
$check = false;

if ($type === 'R') {
    // ---------- RCDC Result ----------
    require_once("../config/auth.php");
    if (!mn_is_rcdc()) {
        mn_deny('You are not authorised to submit RCDC answers.');
    }

    $rid     = $conn->real_escape_string($_POST['rid'] ?? '');
    $species = $conn->real_escape_string($_POST['species'] ?? '');

    $chkResult = $conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results WHERE slide_id = '$rid'");
    $chkResult = $chkResult ? $chkResult->fetch_row() : [0];

    if (($chkResult[0] ?? 0) == 0) {
        $stmt = $conn->prepare("INSERT INTO rdt_panel_rcdc_results VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param('is', $rid, $species);
            $check = $stmt->execute();
            $stmt->close();
        }
    } else {
        $check = (bool)$conn->query("UPDATE rdt_panel_rcdc_results SET rcdc_species = '$species' WHERE slide_id = '$rid'");
    }
} else {
    // ---------- Lab Result ----------
    $timeliness  = $conn->real_escape_string($_POST['timeliness'] ?? '');
    $action      = $conn->real_escape_string($_POST['action'] ?? '');
    $examiner    = $conn->real_escape_string($_POST['examiner'] ?? '');
    $category    = $conn->real_escape_string($_POST['category'] ?? '');
    $designation = $conn->real_escape_string($_POST['designation'] ?? '');
    $doe         = $conn->real_escape_string($_POST['doe'] ?? '');
    $kitname     = $conn->real_escape_string($_POST['kitname'] ?? '');
    $batchlot    = $conn->real_escape_string($_POST['batchlot'] ?? '');
    $mfg         = $conn->real_escape_string($_POST['mfg'] ?? '');
    $doex        = $conn->real_escape_string($_POST['doex'] ?? '');
    if ($doex === '') $doex = null;

    // Safe array access – fixes the original “Undefined array key” + count(null)
    $slide   = (isset($_POST['slide'])   && is_array($_POST['slide']))   ? $_POST['slide']   : [];
    $species = (isset($_POST['species']) && is_array($_POST['species'])) ? $_POST['species'] : [];

    if ($action == 1) {
        // INSERT
        $stmt1 = $conn->prepare("INSERT INTO rdt_panel_lab_result_informations
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt1) {
            $stmt1->bind_param(
                'issssssssiiiiiisssss',
                $id, $category, $examiner, $designation, $doe,
                $kitname, $batchlot, $mfg, $doex,
                $null, $null, $null, $null, $null, $null, $null, $null, $null, $null,
                $timeliness
            );

            if ($stmt1->execute()) {
                $stmt1->close();
                $stmt2 = $conn->prepare("INSERT INTO rdt_panel_lab_results VALUES (?, ?, ?, ?)");
                if ($stmt2) {
                    for ($i = 0; $i < count($slide); $i++) {
                        $flag = generateFlag($conn, $slide[$i], $species[$i] ?? '');
                        $stmt2->bind_param('issi', $slide[$i], $species[$i], $flag[0], $flag[1]);
                        $stmt2->execute();
                    }
                    $stmt2->close();
                    $check = true;
                }
            } else {
                $stmt1->close();
            }
        }
    } else {
        // UPDATE
        $uptqry = "UPDATE rdt_panel_lab_result_informations SET
                      examiner = '$examiner',
                      category = '$category',
                      designation = '$designation',
                      examination_date = '$doe',
                      timeliness = '$timeliness',
                      kitname = '$kitname',
                      batchlot = '$batchlot',
                      manufacturer = '$mfg',
                      expiry_date = " . ($doex === null ? 'NULL' : "'$doex'") . "
                   WHERE panel_id = '$id'";

        if ($conn->query($uptqry)) {
            for ($i = 0; $i < count($slide); $i++) {
                $flag = generateFlag($conn, $slide[$i], $species[$i] ?? '');
                $conn->query("UPDATE rdt_panel_lab_results SET
                                 lab_species = '{$species[$i]}',
                                 positivity_flag = '{$flag[0]}',
                                 species_flag = '{$flag[1]}'
                              WHERE slide_id = '{$slide[$i]}'");
            }
            $check = true;
        }
    }

    // Always recalculate scores after a successful lab save
    if ($check) {
        $score_array = calculateScore($conn, $id);   // now returns a real array

        $conn->query("UPDATE rdt_panel_lab_result_informations SET
                         sensitivity = '{$score_array[0]}',
                         specificity = '{$score_array[1]}',
                         species = '{$score_array[2]}',
                         avg_score1 = '{$score_array[3]}',
                         avg_score2 = '{$score_array[4]}',
                         final_score = '{$score_array[5]}',
                         achievement = '{$score_array[6]}'
                      WHERE panel_id = '$id'");
    }
}

/* ============================================================
   RESPONSE
   ============================================================ */

/* ONE notification for the whole results submission, not one per slide row. */
if ($check && (int)$id > 0) {
    require_once("../notifications/mn-notify.php");
    mn_notify($conn, 'RDT_PANEL_RESULTS_SUBMITTED', (int)$id);
}

$lnk = "panels.php?id=$id";
echo "<input type='hidden' name='lnk' id='lnk' value='$lnk'/>";

if ($check) {
    $conn->close();
?>
<script>
$(function () {
    var lnk = $('#lnk').val();
    Swal.fire({
        icon: 'success',
        confirmButtonText: 'Close',
        text: 'Submitted Successfully!!',
        showCloseButton: true
    }).then(function () {
        window.location = lnk;
    });
});
</script>
<?php
} else {
    echo $conn->error;
?>
<script>
$(function () {
    var lnk = $('#lnk').val();
    Swal.fire({
        icon: 'error',
        confirmButtonText: 'Close',
        text: 'Submission Failed!!',
        showCloseButton: true
    }).then(function () {
        window.location = lnk;
    });
});
</script>
<?php
}