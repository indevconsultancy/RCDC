<?php
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	$mpAjax = !empty($_REQUEST['ajax']);
	if(!$mpAjax){ include_once("../includes/css-jquery.php"); }
	// Backend permission enforcement for result entry (mirrors the UI gate).
	include_once("../config/mpq_validate.php");
	// RBAC matrix: Administrator + RCDC/NDTL labs may enter results; BFDA/DRA (acl 7) is read-only.
	// Effective-role RBAC: Administrator + NDTL may enter results. NMRL and BFDA denied.
	// The approval columns remain Administrator-only and are omitted from the non-admin SQL below.
	mpq_require('results', 'edit', $mpAjax);
	mpq_csrf_check($mpAjax);

	// Result entry only happens from the edit page; don't trust the stale session flag.
	$par = 2;
	$_SESSION['par'] = $par;
	$uid=$_SESSION['users'];

	// --- Server-side guard: reject malformed/empty posts before the UPDATE loop. Only the
	// record identity is enforced here; individual result cells stay optional by design
	// (the workflow allows saving partially-entered result grids). ---
	include_once("../config/mpq_validate.php");
	mpq_guard(array('regid'=>'Record','rid'=>'Result rows'), $mpAjax);
	// Completed reports are read-only (server-side lock; see report_lock_lib.php).
	require_once(__DIR__."/report_lock_lib.php");
	mpqms_require_unlocked($connection, (int)$_REQUEST["regid"], $mpAjax);

	$regid=mysqli_real_escape_string($connection,$_REQUEST["regid"]);

	$rid   = $_POST['rid']   ?? array();
	$obs   = $_POST['obs']   ?? array();
	$std   = $_POST['std']   ?? array();
	$rslt  = $_POST['rslt']  ?? array();
	$appv  = $_POST['appv']  ?? array();
	$dot   = $_POST['dot']   ?? array();
	$tby   = $_POST['tby']   ?? array();   // Assigned Analyst dropdown (form field name retained for backward compat)
	$apvby = $_POST['apvby'] ?? array();
	$rmk   = $_POST['rmk']   ?? array();
	// Escape every posted value to prevent SQL injection via the result-grid arrays.
	$esc = function($arr, $i) use ($connection){ return mysqli_real_escape_string($connection, (string)(isset($arr[$i]) ? $arr[$i] : '')); };
	$uidE = mysqli_real_escape_string($connection, $uid);
	$now  = date('Y-m-d H:i:s');
	// RBAC matrix: result Approval is an Administrator-only action. Labs (NDTL/NMRL) may enter
	// observations/standards/results but must NOT set the approval verdict or approver — those
	// columns are left untouched for non-admins so a stored approval cannot be forged/overwritten.
	$mpIsAdmin = ((int)($_SESSION['acl'] ?? 0) === 1);

	// -----------------------------------------------------------------------
	// Oct-2026 — Test Assignment & Traceability (ISO 13485 / 21 CFR Part 11).
	//
	// For every row posted, read the CURRENT stored state before updating, diff it
	// against the posted values, and queue an audit row (`labresult_audit`) for every
	// field that actually changed. The audit insert and the main UPDATE run inside
	// the same transaction, so a rollback preserves "either both or neither".
	//
	// At the same time, maintain the new tracking columns:
	//   • assigned_analyst_id : mirror of the posted analyst username (tby)
	//   • assigned_by_user_id : current user, when the analyst changed
	//   • date_assigned       : NOW(), when the analyst changed
	//   • date_tested         : NOW(), the first time observation/standard/result goes non-empty
	//   • test_status         : derived — Pending|In Progress|Completed|Approved
	//
	// The legacy `testedBy` column is still written exactly as before — PDF report,
	// view page and the signature code read it and must continue to work unchanged.
	// -----------------------------------------------------------------------

	// Fetch current state for every result row referenced in the POST, keyed by resultID.
	// A single query is used so this is one round-trip regardless of grid size.
	$mpOld = array();
	if(!empty($rid)){
		$mpIds = array();
		foreach($rid as $r){ $r = (int)$r; if($r > 0){ $mpIds[$r] = $r; } }
		if(!empty($mpIds)){
			$inList = implode(',', array_map('intval', array_values($mpIds)));
			$resOld = mysqli_query($connection,
				"SELECT resultID, testDate, testedBy, observation, standard, result, approval, approvedBy, remarks,
				        assigned_analyst_id, date_assigned, date_tested, test_status
				 FROM labresulttable WHERE resultID IN ($inList)");
			if($resOld){
				while($row = mysqli_fetch_assoc($resOld)){ $mpOld[(int)$row['resultID']] = $row; }
			}
		}
	}

	$mpAuditQueue = array();   // array of literal INSERT value tuples
	$mpAudit = function($rid, $regid, $action, $field, $old, $new, $reason='') use($connection, $uidE, $now, &$mpAuditQueue){
		$e = function($v) use($connection){ return mysqli_real_escape_string($connection, (string)$v); };
		$oldSql = ($old === null) ? 'NULL' : "'".$e($old)."'";
		$newSql = ($new === null) ? 'NULL' : "'".$e($new)."'";
		$fldSql = ($field === null || $field === '') ? 'NULL' : "'".$e($field)."'";
		$rsnSql = ($reason === '' ) ? 'NULL' : "'".$e($reason)."'";
		$mpAuditQueue[] = "($rid, $regid, '".$e($action)."', $fldSql, $oldSql, $newSql, '".$uidE."', '".$now."', $rsnSql)";
	};

	// Helper: normalise a value for comparison (treat NULL and '' as the same thing,
	// trim whitespace — users should not get an audit row for just adding a space).
	$cmp = function($a, $b){
		$a = ($a === null) ? '' : trim((string)$a);
		$b = ($b === null) ? '' : trim((string)$b);
		return $a === $b;
	};

	$qry = "";
	for($i=0;$i<count($rid);$i++){
		$e_dot=$esc($dot,$i); $e_tby=$esc($tby,$i); $e_obs=$esc($obs,$i); $e_std=$esc($std,$i);
		// A blank Test Date means "not recorded yet". Keep the 1900-01-01 sentinel that
		// save_parameter.php writes for new rows instead of storing a zero date, so the
		// report's test commencement/completion dates keep behaving exactly as before.
		if($e_dot === ''){ $e_dot = '1900-01-01'; }
		$e_rslt=$esc($rslt,$i); $e_appv=$esc($appv,$i); $e_apvby=$esc($apvby,$i); $e_rmk=$esc($rmk,$i); $e_rid=$esc($rid,$i);

		// Previous row state (keyed by numeric resultID) — may be absent for a hand-crafted
		// POST referencing an unknown id, in which case we treat every field as "new".
		$ridInt = (int)$rid[$i];
		$prev   = $mpOld[$ridInt] ?? null;

		// --- Diff the fields and queue audit rows -------------------------------------
		$p_tby    = $prev ? ($prev['assigned_analyst_id'] !== null && $prev['assigned_analyst_id'] !== '' ? $prev['assigned_analyst_id'] : $prev['testedBy']) : null;
		$p_obs    = $prev['observation'] ?? null;
		$p_std    = $prev['standard']    ?? null;
		$p_rslt   = $prev['result']      ?? null;
		$p_appv   = $prev['approval']    ?? null;
		$p_apvby  = $prev['approvedBy']  ?? null;
		$p_rmk    = $prev['remarks']     ?? null;
		$p_dot    = $prev['testDate']    ?? null;
		$p_status = $prev['test_status'] ?? 'Pending';
		$p_dtAsg  = $prev['date_assigned']?? null;
		$p_dtTst  = $prev['date_tested']  ?? null;

		$posted_tby   = (string)($tby[$i]   ?? '');
		$posted_obs   = (string)($obs[$i]   ?? '');
		$posted_std   = (string)($std[$i]   ?? '');
		$posted_rslt  = (string)($rslt[$i]  ?? '');
		$posted_appv  = (string)($appv[$i]  ?? '');
		$posted_apvby = (string)($apvby[$i] ?? '');
		$posted_rmk   = (string)($rmk[$i]   ?? '');
		$posted_dot   = (string)($dot[$i]   ?? '');

		// Assignment change — the single most audit-sensitive event.
		if(!$cmp($p_tby, $posted_tby)){
			$action = ($p_tby === null || trim((string)$p_tby) === '') ? 'ASSIGN' : 'REASSIGN';
			$mpAudit($ridInt, (int)$regid, $action, 'assigned_analyst_id', $p_tby, $posted_tby);
		}

		// Result-field edits — one audit row per field that actually changed.
		if(!$cmp($p_obs,  $posted_obs))  { $mpAudit($ridInt, (int)$regid, 'UPDATE_RESULT', 'observation', $p_obs,  $posted_obs); }
		if(!$cmp($p_std,  $posted_std))  { $mpAudit($ridInt, (int)$regid, 'UPDATE_RESULT', 'standard',    $p_std,  $posted_std); }
		if(!$cmp($p_rslt, $posted_rslt)) { $mpAudit($ridInt, (int)$regid, 'UPDATE_RESULT', 'result',      $p_rslt, $posted_rslt); }
		if(!$cmp($p_rmk,  $posted_rmk))  { $mpAudit($ridInt, (int)$regid, 'UPDATE_RESULT', 'remarks',     $p_rmk,  $posted_rmk); }
		if(!$cmp($p_dot,  $posted_dot))  { $mpAudit($ridInt, (int)$regid, 'UPDATE_RESULT', 'testDate',    $p_dot,  $posted_dot); }

		// Approval change — only Admin may post this, but we still audit who did it.
		if($mpIsAdmin && !$cmp($p_appv, $posted_appv)){
			$a = strtoupper(trim($posted_appv));
			$action = ($a === 'APPROVED') ? 'APPROVE' : (($a === 'DISAPPROVED') ? 'DISAPPROVE' : 'APPROVAL_CHANGE');
			$mpAudit($ridInt, (int)$regid, $action, 'approval',   $p_appv,  $posted_appv);
			if(!$cmp($p_apvby, $posted_apvby)){
				$mpAudit($ridInt, (int)$regid, $action, 'approvedBy', $p_apvby, $posted_apvby);
			}
		}

		// --- Derive test_status from the row's new state ------------------------------
		// Precedence: Approved (admin verdict) > Completed (data present) > In Progress
		// (analyst assigned) > Pending. Non-admin posts cannot promote to Approved —
		// the approval column is left untouched for non-admins, so the computed status
		// uses the EXISTING approval verdict in that branch (via $p_appv).
		$effective_appv = $mpIsAdmin ? $posted_appv : (string)$p_appv;
		$has_data = (trim($posted_obs) !== '' || trim($posted_std) !== '' || trim($posted_rslt) !== '');
		$has_assg = (trim($posted_tby) !== '');
		if(strcasecmp(trim($effective_appv), 'Approved') === 0){
			$new_status = 'Approved';
		} elseif($has_data){
			$new_status = 'Completed';
		} elseif($has_assg){
			$new_status = 'In Progress';
		} else {
			$new_status = 'Pending';
		}
		if(!$cmp($p_status, $new_status)){
			$mpAudit($ridInt, (int)$regid, 'STATUS_CHANGE', 'test_status', $p_status, $new_status);
		}

		// --- Timestamp bookkeeping ---------------------------------------------------
		// date_assigned: stamp NOW() whenever the analyst changed (ASSIGN or REASSIGN).
		// date_tested  : stamp NOW() the FIRST time any observation/standard/result goes
		//                non-empty for this row (do NOT overwrite a prior value).
		$set_date_assigned = !$cmp($p_tby, $posted_tby);
		$set_date_tested   = $has_data && ($p_dtTst === null || $p_dtTst === '' || $p_dtTst === '0000-00-00 00:00:00');

		$e_status      = mysqli_real_escape_string($connection, $new_status);
		$e_analyst_id  = $e_tby;              // mirror of the posted analyst
		$e_assigned_by = $set_date_assigned ? $uidE : '';   // only recorded when assignment just changed

		// Build the UPDATE. Branches diverge on two axes:
		//   • admin vs non-admin (approval columns only touched by admin)
		//   • date_assigned / date_tested set on this UPDATE vs preserved
		$setCore = "testDate='$e_dot',testedBy='$e_tby',observation='$e_obs',standard='$e_std',result='$e_rslt',"
		         . "remarks='$e_rmk',entryUser='$uidE',entrytimestamp='$now',"
		         . "assigned_analyst_id=".($posted_tby === '' ? "NULL" : "'$e_analyst_id'").","
		         . "test_status='$e_status'";
		if($set_date_assigned){
			$setCore .= ",assigned_by_user_id='$uidE',date_assigned='$now'";
		}
		if($set_date_tested){
			$setCore .= ",date_tested='$now'";
		}
		if($mpIsAdmin){
			$setCore .= ",approval='$e_appv',approvedBy='$e_apvby'";
		}
		$qry .= "UPDATE labresulttable SET $setCore WHERE resultID='$e_rid';";
	}

	// The Digital Signature is no longer part of the Test Result save: it belongs to the Final
	// Result and is stored only by save_update_details.php (type=frslt) — see signature_lib.php.
	// Any staged signature fields posted here are ignored.

	// Atomicity: wrap the whole multi-statement batch in a transaction so a mid-batch
	// failure rolls back cleanly (and, under PHP 8.2 mysqli exceptions, degrades to a
	// graceful error instead of a fatal). Success behaviour is unchanged. The audit
	// INSERTs (collected above) run inside the SAME transaction, so a result save
	// that rolls back also rolls back its audit rows — the two can never diverge.
	$mpOk = false; $mpErr = '';
	mysqli_begin_transaction($connection);
	try {
		mysqli_multi_query($connection,$qry);
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}
		if(!empty($mpAuditQueue)){
			$auditSql = "INSERT INTO labresult_audit
			    (resultID, registrationID, action, field_name, old_value, new_value, changed_by, changed_at, reason)
			    VALUES ".implode(",\n", $mpAuditQueue);
			if(!mysqli_query($connection, $auditSql)){
				throw new \Exception('audit log write failed: '.mysqli_error($connection));
			}
		}
		mysqli_commit($connection);
		$mpOk = true;
	} catch(\Throwable $e){ mysqli_rollback($connection); $mpErr = $e->getMessage(); }
	if($mpOk){
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}

		// Oct 2026 — Sample lifecycle audit (immutable trail). The IN_TESTING transition
		// fires the first time ANY result row for this sample carries real data (an
		// observation, a standard, or a result). Idempotent via UNIQUE(sample_id,new_status)
		// so re-saves after the first entry never duplicate the row.
		try {
			require_once(dirname(__DIR__)."/includes/sample_lifecycle_lib.php");
			$mpHasAnyData = false;
			$obs2  = $_POST['obs']   ?? array();
			$std2  = $_POST['std']   ?? array();
			$rslt2 = $_POST['rslt']  ?? array();
			for($i=0; !$mpHasAnyData && $i<max(count($obs2),count($std2),count($rslt2)); $i++){
				if(trim((string)($obs2[$i]  ?? '')) !== '') { $mpHasAnyData = true; break; }
				if(trim((string)($std2[$i]  ?? '')) !== '') { $mpHasAnyData = true; break; }
				if(trim((string)($rslt2[$i] ?? '')) !== '') { $mpHasAnyData = true; break; }
			}
			if($mpHasAnyData){
				mpqms_log_transition($connection, (int)$regid, 'IN_TESTING', (string)$uid, 'first result entered');
			}
		} catch(\Throwable $e){ /* audit must never break the save */ }

		// BFDA / MPD focal notification is NOT sent from the Test Result. Saving or approving
		// test results only stores the data; every BFDA email (non-compliance alert, final
		// outcome, legacy report notice) is triggered solely by the Final Result save in
		// save_update_details.php (type=frslt). The shared notifier
		// (includes/notification_lib.php :: mpqms_notify_non_compliance) is unchanged and is
		// still called there with the FINAL_RESULT trigger.

		mysqli_close($commondb);
		mysqli_close($connection);

		if($mpAjax){ header('Content-Type: application/json'); echo json_encode(array('status'=>'success','message'=>'Updated Successfully!!')); exit; }

		$lnk = "registration.php?par=$par&rid=$regid";
		echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Updated Successfully!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php }	else{
	// The whole batch was rolled back — nothing was saved.
	if($mpAjax){ header('Content-Type: application/json'); echo json_encode(array('status'=>'error','message'=>'Failed to Update!! Nothing was saved. '.($mpErr !== '' ? '[Details: '.$mpErr.']' : mysqli_error($connection)))); exit; }
	echo htmlspecialchars($mpErr !== '' ? $mpErr : mysqli_error($connection)); ?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Failed to Update!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>
