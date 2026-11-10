<?php
/*
 * MPQMS — persist role / permission edits made in settings/roles.php.
 *
 * POST body shape (as serialised by the roles.php form):
 *     csrf = <session token>
 *     roles_posted[] = ADMIN | MPD | NDTL | NMRL | BFDA      (one or more; the roles whose
 *                                                            checkbox grid is being saved)
 *     p[<role>][<module>][<col>] = 1                         (only ticked boxes appear;
 *                                                            unticked ones are absent → 0)
 * Where <col> is one of: can_view, can_enter, can_edit, can_review, can_approve, can_manage.
 *
 * Behaviour:
 *   • Admin-only. CSRF-guarded.
 *   • For every role in roles_posted[], one UPSERT per (role_id, module) row with the
 *     ticked / absent values. Unticked boxes write 0 explicitly.
 *   • Each UPSERT runs inside a single transaction; a mid-save failure rolls everything
 *     back and nothing is partially applied.
 *   • Writes an audit row to masterdata_audit for traceability (entity='role_permission').
 *
 * Response: application/json {status, message}.
 */

ob_start();
ini_set('display_errors', '0');

function sr_out($status, $msg){
	if(ob_get_length()) ob_clean();
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('status'=>$status, 'message'=>$msg));
	exit;
}

include_once("../config/auth_ndtlis.php");
include_once("../config/db_config.php");
include_once("../config/mpq_validate.php");

// Same gate as the Users page — Admin only. mpq_require handles the role check +
// returns a JSON error for the AJAX path.
mpq_require('users', 'view', true);
mpq_csrf_check(true);

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){
	sr_out('error', 'Invalid request method.');
}

// Only accept writes for roles that actually exist in mpqms_roles — this is the
// defensive sanity check that stops a crafted POST from spawning phantom rows.
$allowedRoles = array();
$rr = @mysqli_query($connection, "SELECT id, role_name FROM mpqms_roles");
if($rr){
	while($row = mysqli_fetch_assoc($rr)){
		$allowedRoles[(string)$row['role_name']] = (int)$row['id'];
	}
}
if(empty($allowedRoles)){
	sr_out('error', 'The role table is empty — run the RBAC migration first.');
}

$rolesPosted = (array)($_POST['roles_posted'] ?? array());
$grid        = (array)($_POST['p'] ?? array());
if(empty($rolesPosted)){
	sr_out('error', 'No roles were submitted. Save was cancelled; nothing changed.');
}

// Allowed modules — same list the editor renders, mirrored here so a crafted POST
// cannot create rows for modules the editor doesn't know about.
$allowedModules = array(
	'dashboard','manager','registration','acceptance','parameters','results',
	'final_result','pdf','official_report','reports','settings_core','edl',
	'manufacturer','mah','users'
);
$allowedCols = array('can_view','can_enter','can_edit','can_review','can_approve','can_manage');

$me  = mysqli_real_escape_string($connection, (string)($_SESSION['users'] ?? ''));
$now = date('Y-m-d H:i:s');

mysqli_begin_transaction($connection);
$ok = false; $errMsg = '';
$changes = 0;
try {
	// Build an audit snapshot before the write for traceability.
	$snapshotJson = @json_encode(array('posted_roles'=>$rolesPosted, 'ts'=>$now));

	foreach($rolesPosted as $roleName){
		$roleName = (string)$roleName;
		if(!isset($allowedRoles[$roleName])){ continue; }
		$roleId = (int)$allowedRoles[$roleName];
		$roleMap = (array)($grid[$roleName] ?? array());

		foreach($allowedModules as $mod){
			$flags = (array)($roleMap[$mod] ?? array());
			$vals = array();
			foreach($allowedCols as $col){
				$vals[$col] = !empty($flags[$col]) ? 1 : 0;
			}
			// UPSERT one row per (role_id, module). ON DUPLICATE KEY UPDATE every column.
			$st = mysqli_prepare($connection,
				"INSERT INTO mpqms_role_permissions
					(role_id, module_name, can_view, can_enter, can_edit, can_review, can_approve, can_manage, updated_by, updated_at)
				 VALUES (?,?,?,?,?,?,?,?,?,?)
				 ON DUPLICATE KEY UPDATE
					can_view=VALUES(can_view),
					can_enter=VALUES(can_enter),
					can_edit=VALUES(can_edit),
					can_review=VALUES(can_review),
					can_approve=VALUES(can_approve),
					can_manage=VALUES(can_manage),
					updated_by=VALUES(updated_by),
					updated_at=VALUES(updated_at)");
			if(!$st){ throw new \Exception('prepare failed: '.mysqli_error($connection)); }
			mysqli_stmt_bind_param($st, 'isiiiiiiss',
				$roleId, $mod,
				$vals['can_view'], $vals['can_enter'], $vals['can_edit'],
				$vals['can_review'], $vals['can_approve'], $vals['can_manage'],
				$me, $now);
			if(!mysqli_stmt_execute($st)){
				$e = mysqli_stmt_error($st);
				mysqli_stmt_close($st);
				throw new \Exception('upsert failed for '.$roleName.'/'.$mod.': '.$e);
			}
			mysqli_stmt_close($st);
			$changes++;
		}
	}

	// Audit row (best-effort; a missing audit table does NOT roll the save back).
	try {
		@mysqli_query($connection,
			"INSERT INTO masterdata_audit (entity, record_id, action, details, changed_by, changed_at)
			 VALUES ('role_permission', 0, 'UPDATE', '".mysqli_real_escape_string($connection, (string)$snapshotJson)."', '".$me."', '".$now."')");
	} catch(\Throwable $e){ /* audit optional */ }

	mysqli_commit($connection);
	$ok = true;
} catch(\Throwable $e){
	mysqli_rollback($connection);
	$errMsg = $e->getMessage();
}

if($ok){
	// Clean, user-facing success text. The row count + "next page load" phrasing was
	// developer-oriented; the admin UI reloads the page on OK so the fresh matrix is
	// visible immediately. (The upserted-row count is still available in the audit
	// row in masterdata_audit for anyone who needs the exact figure.)
	sr_out('success', 'Role permissions saved successfully.');
}
// Generic, user-friendly failure text. The technical detail ($errMsg) is dropped from
// the response so a staff user is not shown a SQL error; it is still logged by the
// web server (and can be surfaced via error_log if needed for debugging).
sr_out('error', 'Failed to update role permissions. Please try again.');
