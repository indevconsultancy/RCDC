<?php
	//error_reporting(0);	
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	$mpAjax = !empty($_REQUEST['ajax']);
	if(!$mpAjax){ include_once("../includes/css-jquery.php"); }
	// Backend permission enforcement for status/final-result updates (mirrors the UI gate).
	include_once("../config/mpq_validate.php");
	// RBAC matrix: Administrator + RCDC/NDTL labs may update; BFDA/DRA (acl 7) is read-only.
	// Effective-role RBAC, per action: sample acceptance and the Final Result are both
	// Administrator + NDTL. NMRL and BFDA are denied. The Administrator-verification gate for
	// the Final Result (below) and the completed-report lock still apply on top of this.
	$mpType = (string)($_REQUEST["type"] ?? '');
	if($mpType === 'ssts')      { mpq_require('acceptance', 'submit', $mpAjax); }
	else if($mpType === 'frslt'){ mpq_require('final_result', 'finalize', $mpAjax); }
	else                        { mpq_fail('Unknown update type.', $mpAjax); }
	mpq_csrf_check($mpAjax);
	// Status / final-result updates only happen from the edit page; don't trust the stale session flag.
	$par = 2;
	$_SESSION['par'] = $par;
	$uid=$_SESSION['users'];
	$qry = "";

	$rid=mysqli_real_escape_string($connection,$_REQUEST["rid"]);
	$type=mysqli_real_escape_string($connection,$_REQUEST["type"]);

	// --- Server-side required-field validation (mirrors form2/form4 `required` fields) ---
	include_once("../config/mpq_validate.php");
	mpq_guard(array('rid'=>'Record','type'=>'Type'), $mpAjax);
	if($type == "ssts"){ mpq_guard(array('sts'=>'Acceptance status','recDate'=>'Received Date'), $mpAjax); }
	if($type == "frslt"){ mpq_guard(array('rslt'=>'Final Result'), $mpAjax); }
	// Completed reports are read-only (server-side lock; see report_lock_lib.php).
	require_once(__DIR__."/report_lock_lib.php");
	mpqms_require_unlocked($connection, (int)$rid, $mpAjax);
	// SRS workflow: RCDC/NDTL lab users record the Final Result only AFTER the Administrator
	// has verified every test result. The Administrator (acl 1) performs that verification
	// and is not gated. Server-side twin of the Final Result card gate in registration.php
	// (shared rule: report_lock_lib.php :: mpqms_results_verification). Read-only check.
	if($type == "frslt" && (int)($_SESSION['acl'] ?? 0) !== 1){
		$mpVer = mpqms_results_verification($connection, (int)$rid);
		if(empty($mpVer['verified'])){ mpq_fail(mpqms_final_result_pending_message($mpVer), $mpAjax); }
	}

	if($type == "ssts"){
		$sts=mysqli_real_escape_string($connection,$_REQUEST["sts"]);
		$recDate=mysqli_real_escape_string($connection,$_REQUEST["recDate"]);
		$rmks=mysqli_real_escape_string($connection,$_REQUEST["rmks"]);
		// Oct 2026 — chain-of-custody at receipt. Both optional; stored as NULL when blank.
		$received_by      = mysqli_real_escape_string($connection, $_REQUEST["received_by"] ?? "");
		$sample_condition = mysqli_real_escape_string($connection, $_REQUEST["sample_condition"] ?? "");
		$chk = mysqli_num_rows(mysqli_query($connection,"SELECT * FROM samplestatustable WHERE registrationID='$rid'"));
		if($chk == 0){
			$qry .= "INSERT INTO samplestatustable
				(registrationID, status, receiveDate, remarks, entryUser, entryTimestamp,
				 received_by, sample_condition)
				VALUES
				('$rid','$sts','$recDate','$rmks','$uid','".date('Y-m-d H:i:s')."',
				 NULLIF('$received_by',''), NULLIF('$sample_condition',''));";
		} else {
			$qry .= "UPDATE samplestatustable SET
				status='$sts', receiveDate='$recDate', remarks='$rmks',
				received_by=NULLIF('$received_by',''),
				sample_condition=NULLIF('$sample_condition',''),
				entryUser='$uid', entryTimestamp='".date('Y-m-d H:i:s')."'
				WHERE registrationID='$rid';";
		}
	}

	// Optional DIGITAL SIGNATURE for the FINAL RESULT (relocated from the Test Result). The
	// signature modal stages it into hidden fields of the Final Result form; it is validated
	// here BEFORE any write (an invalid signature fails the request and nothing is saved) and
	// persisted inside the same transaction as the Final Result — shared implementation in
	// signature_lib.php. Only the frslt action may carry a signature; no other action stores one.
	$mpSig = null;
	if($type == "frslt"){
		require_once(__DIR__."/signature_lib.php");
		$mpSig = mpqms_signature_from_post($connection, $mpAjax, date('Y-m-d H:i:s'));
	}

	if($type == "frslt"){
		$rslt=mysqli_real_escape_string($connection,$_REQUEST["rslt"]);
		$rmks=mysqli_real_escape_string($connection,$_REQUEST["rmks"]);
		$chk = mysqli_num_rows(mysqli_query($connection,"SELECT * FROM finalresulttable WHERE registrationID='$rid'"));
		if($chk == 0){	$qry .= "INSERT INTO finalresulttable VALUES('$rid','$rslt','$rmks','$uid','".date('Y-m-d H:i:s')."')";	}
		else {	$qry .= "UPDATE finalresulttable SET result='$rslt', remarks='$rmks',entryUser='$uid',entryTimestamp='".date('Y-m-d H:i:s')."' WHERE registrationID='$rid'";	}
	}

	// Atomicity: wrap the whole multi-statement batch in a transaction so a mid-batch
	// failure rolls back cleanly (and, under PHP 8.2 mysqli exceptions, degrades to a
	// graceful error instead of a fatal). Success behaviour is unchanged. A staged Final
	// Result signature is written in the SAME transaction: both are saved or neither is.
	$mpOk = false; $mpErr = '';
	mysqli_begin_transaction($connection);
	try {
		mysqli_multi_query($connection,$qry);
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}
		if($mpSig !== null){ mpqms_signature_store($connection, (int)$rid, $mpSig); }
		mysqli_commit($connection);
		$mpOk = true;
	} catch(\Throwable $e){ mysqli_rollback($connection); $mpErr = $e->getMessage(); }
	if($mpOk){
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}

		// Oct 2026 — Sample lifecycle audit (immutable trail). ssts = RECEIVED_AT_NMPTL
		// (sample acceptance recorded); frslt = REPORTED (Final Result committed). Both
		// calls are idempotent, so a re-save of the same stage is a no-op.
		try {
			require_once(dirname(__DIR__)."/includes/sample_lifecycle_lib.php");
			if($type === 'ssts'){
				mpqms_log_transition($connection, (int)$rid, 'RECEIVED_AT_NMPTL', (string)$uid,
					'acceptance='.(string)($_REQUEST['sts'] ?? ''));
			} elseif($type === 'frslt'){
				mpqms_log_transition($connection, (int)$rid, 'REPORTED', (string)$uid,
					'final='.(string)($_REQUEST['rslt'] ?? ''));
			}
		} catch(\Throwable $e){ /* audit must never break the save */ }

		// On final-result approval: archive the read-only report PDF (req 3) and email the
		// BFDA MPD focal person the final outcome + acknowledgement (reqs 4 & 6).
		// Wrapped/buffered so a PDF or mail failure can never break the save.
		if($type == "frslt"){
			ob_start();
			require_once(__DIR__."/report_lib.php");
			$adir = mpqms_docroot()."/MPQMS/reports_archive";
			try {
				if(!is_dir($adir)){ @mkdir($adir, 0775, true); }
				// Write-once / versioned naming: never overwrite an already-archived report
				// (immutability). A re-approval writes report_<rid>_v2.pdf, _v3.pdf, ...
				$base = $adir."/report_".$rid;
				$file = $base.".pdf";
				if(file_exists($file)){ $v = 2; while(file_exists($base."_v".$v.".pdf")){ $v++; } $file = $base."_v".$v.".pdf"; }
				mpqms_render_report($rid, $connection, $commondb, 'F', $file);
				if(file_exists($file) && filesize($file) > 0){
					// Integrity checksum sidecar + best-effort read-only (effective on *nix).
					$sha = hash_file('sha256', $file);
					@file_put_contents($file.".sha256", $sha."  ".basename($file)."\n");
					@chmod($file, 0444);
					// Long-term traceability: index the immutable report (client/test/batch/timestamp/
					// SHA-256/URI/lock) in the DB so clients can retrieve it and auditors can verify it.
					require_once(__DIR__."/report_archive_index.php");
					mpqms_index_archived_report($connection, $rid, $file, $sha, $rslt, $uid);
				} else {
					throw new \Exception("archived PDF missing or empty: ".$file);
				}
			} catch(\Throwable $e) {
				// Archiving must never break approval, but the failure must be recorded.
				@file_put_contents($adir."/archive_errors.log", date('Y-m-d H:i:s')." rid=".$rid." ERROR: ".$e->getMessage()."\n", FILE_APPEND);
			}
			// Official Analytical Report (write-once). Only fires when the sample is FULLY
			// completed — Sample Accepted + every result Approved + Final Result saved — and
			// only when no record exists yet, so a stored official PDF is never overwritten.
			// Additive and fully isolated: a failure here cannot affect the save above.
			try {
				require_once(__DIR__."/official_report_lib.php");
				$offRes = mpqms_official_archive($connection, $commondb, $rid, $uid);
				if(is_array($offRes) && $offRes['status'] === 'error'){
					@file_put_contents($adir."/archive_errors.log", date('Y-m-d H:i:s')." rid=".$rid." OFFICIAL ERROR: ".$offRes['message']."\n", FILE_APPEND);
				}
			} catch(\Throwable $e) {
				@file_put_contents($adir."/archive_errors.log", date('Y-m-d H:i:s')." rid=".$rid." OFFICIAL ERROR: ".$e->getMessage()."\n", FILE_APPEND);
			}
			// The three regulator notifications (outcome notice, non-compliance alert, official
			// final outcome) are dispatched INDEPENDENTLY of this request and of the user's login
			// session: a detached background worker runs them (inline fallback that ignores a
			// client disconnect / execution-time limit). Same notifiers, same order, same
			// arguments — see includes/notify_dispatch.php. Never breaks the save.
			try {
				require_once(dirname(__DIR__)."/includes/notify_dispatch.php");
				mpqms_notify_final_result_dispatch($connection, $commondb, $rid, $rslt, $uid);
			} catch(\Throwable $e) { /* notification must never break the save */ }
			ob_end_clean();
		}

		mysqli_close($commondb);
		mysqli_close($connection);

		if($mpAjax){ header('Content-Type: application/json'); echo json_encode(array('status'=>'success','message'=>($mpSig !== null ? 'Final result and digital signature updated successfully!!' : 'Updated Successfully!!'), 'signature_saved'=>($mpSig !== null))); exit; }

		$lnk = "registration.php?par=$par&rid=$rid";

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
	// The whole batch (and any staged Final Result signature) was rolled back — nothing was saved.
	if($mpAjax){ header('Content-Type: application/json'); echo json_encode(array('status'=>'error','message'=>'Failed to Update!! Nothing was saved. '.($mpErr !== '' ? '[Details: '.$mpErr.']' : mysqli_error($connection)))); exit; }
	echo mysqli_error($connection); ?>
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
	
