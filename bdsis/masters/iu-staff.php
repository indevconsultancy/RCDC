<?php
/**
 * Save endpoint for masters/staff.php. POST only.
 *
 * Cross-app master: user_profile_table lives in commondb and is the login
 * directory for every RCDC surveillance system. Every write here is gated
 * to ADMIN via bdsis_masters_require_write('staff'), and the ONLY column
 * this endpoint touches is `designation` -- the field that drives BDSIS
 * report signatures. Full-name / centre / username / access-level changes
 * are intentionally not offered here; they belong in the shared user
 * administration portal (COMMONNEW).
 *
 * The target username is validated against the same filter the staff
 * listing shows (system_id=6, access_level IN (2,3), user_category IN
 * (3,13)), so a hand-crafted POST cannot re-designate a user outside the
 * BDSIS staff pool.
 */
require_once __DIR__ . '/_inc.php';
bdsis_csrf_require();

/* Any write on Staff requires ADMIN (cross-app master). */
bdsis_masters_require_write('staff', 'write');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mdFlash('danger', 'This action must be submitted from the form.');
    mdRedirect('staff.php');
}

$do       = isset($_POST['do'])          ? (string)$_POST['do']              : '';
$username = isset($_POST['username'])    ? trim((string)$_POST['username'])  : '';
$desigId  = isset($_POST['designation']) ? trim((string)$_POST['designation']) : '';

if ($username === '') { mdFlash('danger', 'Username is required.'); mdRedirect('staff.php'); }
if (!preg_match('~^[A-Za-z0-9_.\-]{1,64}$~', $username)) {
    mdFlash('danger', 'Username has an unexpected format.');
    mdRedirect('staff.php');
}

switch ($do) {
    case 'update_designation':
        /* Whitelist the username against the same pool the listing uses.
           Prevents a bookmarked POST from re-designating a user outside
           the BDSIS staff filter (e.g. a facility clinician). */
        $chk = $commondb->prepare("SELECT 1 FROM user_profile_table up
                                   JOIN user_system_access_table usa ON usa.username = up.username
                                  WHERE up.username = ?
                                    AND usa.system_id = 6
                                    AND usa.access_level IN (2,3)
                                    AND usa.user_category IN (3,13)");
        if (!$chk) { mdFlash('danger', 'Database error.'); mdRedirect('staff.php'); }
        $chk->bind_param("s", $username); $chk->execute(); $chk->store_result();
        $pool = $chk->num_rows > 0; $chk->close();
        if (!$pool) {
            mdFlash('danger', "'$username' is not in the BDSIS staff picker pool.");
            mdRedirect('staff.php');
        }

        /* Designation id: 0 means "no designation set". A non-zero value
           must exist in ms_designation_table. */
        $desId = 0;
        if ($desigId !== '' && $desigId !== '0') {
            if (!ctype_digit($desigId)) {
                mdFlash('danger', 'Invalid designation.');
                mdRedirect('staff.php?edit=' . urlencode($username) . '#editForm');
            }
            $desId = (int)$desigId;
            $chk2 = $commondb->prepare("SELECT 1 FROM ms_designation_table WHERE id=?");
            if (!$chk2) { mdFlash('danger', 'Database error.'); mdRedirect('staff.php'); }
            $chk2->bind_param("i", $desId); $chk2->execute(); $chk2->store_result();
            $desOk = $chk2->num_rows > 0; $chk2->close();
            if (!$desOk) {
                mdFlash('danger', 'That designation is not recognised.');
                mdRedirect('staff.php?edit=' . urlencode($username) . '#editForm');
            }
        }

        /* Write. designation column is INT(11) NULL in commondb schema; 0 is
           stored as-is (meaning "no designation"). Nothing else is touched. */
        $upd = $commondb->prepare("UPDATE user_profile_table SET designation=? WHERE username=?");
        if (!$upd) { mdFlash('danger', 'Database error preparing update.'); mdRedirect('staff.php'); }
        $upd->bind_param("is", $desId, $username);
        $ok = $upd->execute(); $aff = $upd->affected_rows; $upd->close();
        if ($ok && $aff > 0) { mdFlash('success', "Designation updated for $username."); }
        elseif ($ok)         { mdFlash('info',    "No change for $username."); }
        else                 { mdFlash('danger',  'Update failed.'); }
        mdRedirect('staff.php');
        break;

    default:
        mdFlash('danger', 'Unrecognised action.');
        mdRedirect('staff.php');
}
