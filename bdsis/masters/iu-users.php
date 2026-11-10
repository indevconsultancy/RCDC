<?php
/**
 * Save endpoint for masters/users.php. POST only, admin-only.
 *
 * SCOPE
 * -----
 * BDSIS is now the user-management surface for its own admins. The actions
 * below write to the shared commondb directory so that a new BDSIS user is a
 * real portal user who can also be granted access to sibling systems later
 * from the shared COMMONNEW admin portal. BDSIS still OWNS access only for
 * system_id=6 -- `revoke` never removes the shared profile row.
 *
 *   add     INSERT INTO login_table (username, password=md5(?))
 *           INSERT INTO user_profile_table (username, fullname, <emailcol>, ...)
 *           INSERT INTO user_system_access_table
 *                     (username, system_id=6, access_level, user_category)
 *           -- All three run inside one transaction; any failure rolls back.
 *
 *   update  UPDATE user_profile_table SET fullname=?, <emailcol>=?
 *           UPDATE login_table SET password=md5(?)   -- only if password given
 *           UPDATE user_system_access_table
 *               SET access_level=?, user_category=?
 *             WHERE username=? AND system_id=6
 *
 *   revoke  DELETE FROM user_system_access_table
 *            WHERE username=? AND system_id=6
 *           -- The shared login + profile rows are intentionally retained so
 *           -- the user keeps every other system they already have.
 *
 * Password hashing matches /BDSIS/login/authentications.php exactly:
 *   $sql = "... AND t1.password=md5('$password')"
 * i.e. unsalted MD5. We can only use what the existing auth stack validates;
 * strengthening the scheme is a cross-system change beyond this module.
 */
require_once __DIR__ . '/_inc.php';
bdsis_csrf_require();

/* Admin tier only. */
bdsis_require_admin('manage BDSIS users');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mdFlash('danger', 'This action must be submitted from the form.');
    mdRedirect('users.php');
}

$do       = isset($_POST['do'])       ? (string)$_POST['do']               : '';
$username = isset($_POST['username']) ? trim((string)$_POST['username'])   : '';
$fullname = isset($_POST['fullname']) ? trim((string)$_POST['fullname'])   : '';
$email    = isset($_POST['email'])    ? trim((string)$_POST['email'])      : '';
$password = isset($_POST['password']) ? (string)$_POST['password']         : '';
$centerId = isset($_POST['center_id']) ? trim((string)$_POST['center_id']) : '';
$desigId  = isset($_POST['designation']) ? trim((string)$_POST['designation']) : '';
$rawRole  = isset($_POST['role'])     ? trim((string)$_POST['role'])        : '';
$roleDef  = bdsis_resolve_role($rawRole);
$roleKey  = $roleDef ? $roleDef['key'] : '';

if ($username === '') {
    mdFlash('danger', 'Username is required.');
    mdRedirect('users.php');
}
if (!preg_match('~^[A-Za-z0-9_.\-]{1,64}$~', $username)) {
    mdFlash('danger', 'Username has an unexpected format.');
    mdRedirect('users.php');
}

/* Self-protection: an admin cannot revoke, nor change their own role via
   this page -- a one-wrong-click lockout is easy to make and expensive to
   recover from. They can still change their own name / email / password. */
$myUn = isset($_SESSION['users']) ? (string)$_SESSION['users'] : '';
if (($do === 'revoke' || $do === 'delete') && $myUn !== '' && strcasecmp($myUn, $username) === 0) {
    mdFlash('danger', 'You cannot revoke your own BDSIS Access from this page.');
    mdRedirect('users.php');
}

/* Role validation (add / update only). */
$roleAcl = $roleUc = null;
if ($do === 'add' || $do === 'update') {
    if (!$roleDef) {
        mdFlash('danger', 'That role is not recognised. Please select one of the 5 BDSIS roles.');
        mdRedirect('users.php');
    }
    $roleAcl = $roleDef['acl'];
    $roleUc  = $roleDef['uc'];
    /* Self-role-change: refuse an admin demoting themselves. */
    if ($do === 'update' && $myUn !== '' && strcasecmp($myUn, $username) === 0 && $roleKey !== 'administrator') {
        mdFlash('danger', 'You cannot change your own role from this page.');
        mdRedirect('users.php');
    }
}

/* Profile field validation for the write paths. */
if ($do === 'add' || $do === 'update') {
    if ($fullname === '') {
        mdFlash('danger', 'Full name is required.');
        mdRedirect('users.php');
    }
    if (strlen($fullname) > 128) {
        mdFlash('danger', 'Full name is too long (128 characters max).');
        mdRedirect('users.php');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mdFlash('danger', 'Email is not a valid address.');
        mdRedirect('users.php');
    }
}
if ($do === 'add') {
    if ($password === '') {
        mdFlash('danger', 'Password is required for a new user.');
        mdRedirect('users.php');
    }
    if (strlen($password) < 6) {
        mdFlash('danger', 'Password must be at least 6 characters.');
        mdRedirect('users.php');
    }
}
if ($do === 'update' && $password !== '' && strlen($password) < 6) {
    mdFlash('danger', 'New password must be at least 6 characters (leave blank to keep current).');
    mdRedirect('users.php');
}

/* Validate center_id or select safe default to satisfy FK on user_profile_table */
if ($centerId !== '') {
    $cChk = $commondb->prepare("SELECT 1 FROM ms_health_center_table WHERE center_id=?");
    if ($cChk) {
        $cChk->bind_param("s", $centerId);
        $cChk->execute();
        $cChk->store_result();
        if ($cChk->num_rows === 0) { $centerId = ''; }
        $cChk->close();
    }
}
if ($centerId === '') {
    if ($roleKey === 'bfda_bafra') {
        $centerId = '905'; // BAFRA Thimphu
    } else {
        $centerId = '997'; // Royal Centre For Disease Control (RCDC)
    }
}

/* Validate designation or select safe default to satisfy FK on user_profile_table */
if ($desigId !== '') {
    $dChk = $commondb->prepare("SELECT 1 FROM ms_designation_table WHERE id=?");
    if ($dChk) {
        $dIdInt = (int)$desigId;
        $dChk->bind_param("i", $dIdInt);
        $dChk->execute();
        $dChk->store_result();
        if ($dChk->num_rows === 0) { $desigId = ''; }
        $dChk->close();
    }
}
if ($desigId === '') {
    switch ($roleKey) {
        case 'administrator':
            $desigId = 1;  // System Administrators
            break;
        case 'bfda_bafra':
            $desigId = 20; // BFDA
            break;
        case 'district_health':
            $desigId = 17; // DHO
            break;
        case 'rcdc_labs':
            $desigId = 10; // Laboratory Technician
            break;
        case 'health_worker':
        default:
            $desigId = 10; // Laboratory Technician
            break;
    }
}
$desigId = (int)$desigId;

/* ---- Discover the email column name on user_profile_table -------------- */
$emailCol = '';
if ($rChk = $commondb->query("SHOW COLUMNS FROM user_profile_table LIKE '%mail%'")) {
    if ($rowChk = $rChk->fetch_assoc()) {
        $cand = (string)$rowChk['Field'];
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $cand)) { $emailCol = $cand; }
    }
    $rChk->free();
}

/* ---- Build the full column map for an INSERT into user_profile_table --- */
function bdsis_profile_insert_columns($commondb, $username, $fullname, $email, $emailCol, $centerId, $desigId) {
    $data = array(
        'username'    => $username,
        'fullname'    => $fullname,
        'center_id'   => $centerId,
        'designation' => $desigId,
    );
    if ($emailCol !== '') { $data[$emailCol] = $email; }
    if ($res = $commondb->query("SHOW COLUMNS FROM user_profile_table")) {
        while ($row = $res->fetch_assoc()) {
            $field = (string)$row['Field'];
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $field)) { continue; }
            if (array_key_exists($field, $data))                { continue; }
            $null  = (string)$row['Null'];                    // 'YES' / 'NO'
            $extra = (string)$row['Extra'];                   // auto_increment / ...
            if (strpos($extra, 'auto_increment') !== false)   { continue; }
            if ($null === 'NO' && $row['Default'] === null) {
                $type = strtolower((string)$row['Type']);
                $data[$field] = (strpos($type, 'int') !== false) ? 0 : '';
            }
        }
        $res->free();
    }
    return $data;
}

/* ------------------------------------------------------------------------- */

switch ($do) {

    case 'add':
        /* 1) Check if the user already has BDSIS access */
        $hasBdsis = false;
        if ($chk = $commondb->prepare("SELECT 1 FROM user_system_access_table WHERE username=? AND system_id=6")) {
            $chk->bind_param("s", $username); $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) { $hasBdsis = true; }
            $chk->close();
        }
        if ($hasBdsis) {
            mdFlash('danger', "A user named '$username' already has BDSIS Access. Use Edit to change their role or profile.");
            mdRedirect('users.php');
        }

        /* 2) Check if user exists in shared commondb */
        $inLogin = false;
        if ($chk = $commondb->prepare("SELECT 1 FROM login_table WHERE username=?")) {
            $chk->bind_param("s", $username); $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) { $inLogin = true; }
            $chk->close();
        }
        $inProfile = false;
        if ($chk = $commondb->prepare("SELECT 1 FROM user_profile_table WHERE username=?")) {
            $chk->bind_param("s", $username); $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) { $inProfile = true; }
            $chk->close();
        }

        $pwHash = md5($password);
        $sysId  = 6;
        $ok     = false;
        $errSrc = '';

        try {
            $commondb->begin_transaction();

            /* A) login_table: create login record if not already present */
            if (!$inLogin) {
                $ins1 = $commondb->prepare("INSERT INTO login_table (username, password, account_status) VALUES (?, ?, 0)");
                if (!$ins1) { throw new Exception('prepare login_table failed: ' . $commondb->error); }
                $ins1->bind_param("ss", $username, $pwHash);
                if (!$ins1->execute()) { $errSrc = 'login_table'; throw new Exception($ins1->error ?: 'execute failed'); }
                $ins1->close();
            }

            /* B) user_profile_table: create profile record with valid center_id and designation */
            if (!$inProfile) {
                $profileCols = bdsis_profile_insert_columns($commondb, $username, $fullname, $email, $emailCol, $centerId, $desigId);
                $colNames = array_keys($profileCols);
                $colVals  = array_values($profileCols);
                $colSqlQ  = array_map(function($c) { return '`' . $c . '`'; }, $colNames);
                $placeholders = rtrim(str_repeat('?,', count($colVals)), ',');
                $profileSql   = "INSERT INTO user_profile_table (" . implode(',', $colSqlQ) . ") VALUES ($placeholders)";

                $ins2 = $commondb->prepare($profileSql);
                if (!$ins2) { $errSrc = 'user_profile_table (prepare)'; throw new Exception($commondb->error); }
                $types = str_repeat('s', count($colVals));
                $ins2->bind_param($types, ...$colVals);
                if (!$ins2->execute()) { $errSrc = 'user_profile_table'; throw new Exception($ins2->error ?: 'execute failed'); }
                $ins2->close();
            } else {
                // If user already had a profile from another system, sync their center_id if passed
                if ($centerId !== '') {
                    $updProf = $commondb->prepare("UPDATE user_profile_table SET center_id=? WHERE username=?");
                    if ($updProf) {
                        $updProf->bind_param("ss", $centerId, $username);
                        $updProf->execute();
                        $updProf->close();
                    }
                }
            }

            /* C) user_system_access_table: grant BDSIS access (system_id=6) */
            $ins3 = $commondb->prepare("INSERT INTO user_system_access_table
                                            (username, system_id, access_level, user_category, unit_access)
                                        VALUES (?, ?, ?, ?, 1)");
            if (!$ins3) { $errSrc = 'user_system_access_table (prepare)'; throw new Exception($commondb->error); }
            $ins3->bind_param("siss", $username, $sysId, $roleAcl, $roleUc);
            if (!$ins3->execute()) { $errSrc = 'user_system_access_table'; throw new Exception($ins3->error ?: 'execute failed'); }
            $ins3->close();

            $commondb->commit();
            $ok = true;
        }
        catch (Throwable $e) {
            try { $commondb->rollback(); } catch (Throwable $e2) {}
            error_log('BDSIS add user failed (' . $errSrc . '): ' . $e->getMessage());
        }

        if ($ok) {
            $msg = ($inLogin && $inProfile)
                ? "Existing user '$username' granted BDSIS Access."
                : "User '$username' created and granted BDSIS Access.";
            mdFlash('success', $msg);
        } else {
            mdFlash('danger', "Could not create user '$username'" . ($errSrc !== '' ? " ($errSrc rolled back)" : '') . ".");
        }
        mdRedirect('users.php');
        break;

    case 'update':
        /* The user must already have a BDSIS access row -- Edit is not a
           grant path. (We rely on the UI only exposing the pencil on existing
           BDSIS users, but check server-side too.) */
        $hasRow = false;
        if ($chk = $commondb->prepare("SELECT 1 FROM user_system_access_table WHERE username=? AND system_id=6")) {
            $chk->bind_param("s", $username); $chk->execute(); $chk->store_result();
            $hasRow = ($chk->num_rows > 0); $chk->close();
        }
        if (!$hasRow) {
            mdFlash('danger', "'$username' has no BDSIS Access to update. Use Add User to grant access.");
            mdRedirect('users.php');
        }

        $ok     = false;
        $errSrc = '';

        try {
            $commondb->begin_transaction();

            /* 1) user_profile_table -- fullname + email + center_id + designation */
            $updFields = array("fullname=?");
            $updTypes  = "s";
            $updParams = array($fullname);

            if ($emailCol !== '') {
                $updFields[] = "`" . $emailCol . "`=?";
                $updTypes  .= "s";
                $updParams[] = $email;
            }
            if ($centerId !== '') {
                $updFields[] = "`center_id`=?";
                $updTypes  .= "s";
                $updParams[] = $centerId;
            }
            if ($desigId > 0) {
                $updFields[] = "`designation`=?";
                $updTypes  .= "i";
                $updParams[] = $desigId;
            }

            $upd1Sql = "UPDATE user_profile_table SET " . implode(', ', $updFields) . " WHERE username=?";
            $updTypes .= "s";
            $updParams[] = $username;

            $upd1 = $commondb->prepare($upd1Sql);
            if (!$upd1) { $errSrc = 'user_profile_table (prepare)'; throw new Exception($commondb->error); }
            $upd1->bind_param($updTypes, ...$updParams);
            if (!$upd1->execute()) { $errSrc = 'user_profile_table'; throw new Exception($upd1->error ?: 'execute failed'); }
            $upd1->close();

            /* 2) login_table -- only if a new password was provided. Empty
                  password means "keep current" -- this is the expected admin
                  behavior and avoids accidental password resets. */
            if ($password !== '') {
                $pwHash = md5($password);
                $upd2 = $commondb->prepare("UPDATE login_table SET password=? WHERE username=?");
                if (!$upd2) { $errSrc = 'login_table (prepare)'; throw new Exception($commondb->error); }
                $upd2->bind_param("ss", $pwHash, $username);
                if (!$upd2->execute()) { $errSrc = 'login_table'; throw new Exception($upd2->error ?: 'execute failed'); }
                $upd2->close();
            }

            /* 3) user_system_access_table -- role */
            $currAcl = null; $currUc = null;
            if ($selUsa = $commondb->prepare("SELECT access_level, user_category FROM user_system_access_table WHERE username=? AND system_id=6")) {
                $selUsa->bind_param("s", $username);
                $selUsa->execute();
                $selUsa->bind_result($currAcl, $currUc);
                $selUsa->fetch();
                $selUsa->close();
            }
            $targetUc = $roleUc;
            if ((string)$currAcl === (string)$roleAcl && $currUc !== null && (string)$currUc !== '') {
                $targetUc = (string)$currUc;
            }
            $upd3 = $commondb->prepare("UPDATE user_system_access_table
                                           SET access_level=?, user_category=?
                                         WHERE username=? AND system_id=6");
            if (!$upd3) { $errSrc = 'user_system_access_table (prepare)'; throw new Exception($commondb->error); }
            $upd3->bind_param("sss", $roleAcl, $targetUc, $username);
            if (!$upd3->execute()) { $errSrc = 'user_system_access_table'; throw new Exception($upd3->error ?: 'execute failed'); }
            $upd3->close();

            $commondb->commit();
            $ok = true;
        }
        catch (Throwable $e) {
            try { $commondb->rollback(); } catch (Throwable $e2) {}
            error_log('BDSIS update user failed (' . $errSrc . '): ' . $e->getMessage());
        }

        if ($ok) { mdFlash('success', "User '$username' updated."); }
        else     { mdFlash('danger',  "Could not update '$username'" . ($errSrc !== '' ? " ($errSrc)" : '') . "."); }
        mdRedirect('users.php');
        break;

    case 'revoke':
    case 'delete':
        /* Unchanged destructive scope: BDSIS access only. The shared commondb
           login + profile rows survive so the user keeps every other system
           they already have. Full account deletion lives in COMMONNEW. */
        $del = $commondb->prepare("DELETE FROM user_system_access_table
                                    WHERE username=? AND system_id=6");
        if (!$del) { mdFlash('danger', 'Database error preparing revoke.'); mdRedirect('users.php'); }
        $del->bind_param("s", $username);
        $ok  = $del->execute();
        $aff = $del->affected_rows;
        $del->close();
        if ($ok && $aff > 0) { mdFlash('success', "BDSIS Access revoked for $username. Login and other-system access are unchanged."); }
        elseif ($ok)         { mdFlash('info',    "$username did not have BDSIS Access."); }
        else                 { mdFlash('danger',  "Could not revoke BDSIS Access for $username."); }
        mdRedirect('users.php');
        break;

    default:
        mdFlash('danger', 'Unrecognised action.');
        mdRedirect('users.php');
}
