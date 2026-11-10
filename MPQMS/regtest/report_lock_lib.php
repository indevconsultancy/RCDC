<?php
/*
 * MPQMS — Completed-report lock (read-only guard).
 *
 * A report is COMPLETED when the sample is Accepted, every result row is Approved and a
 * Final Result has been recorded — exactly the definition already used to issue the
 * official Analytical Report (official_report_lib.php :: mpqms_sample_fully_completed),
 * so there is a single source of truth for "complete". From that moment the report can
 * still be viewed, downloaded and exported, but no update, edit or delete may touch it.
 *
 * Two entry points, both additive:
 *   mpqms_report_locked($conn, $regid)              -> bool   (UI: hide mutation controls)
 *   mpqms_require_unlocked($conn, $regid, $mpAjax)  -> exits with the standard mpq_fail
 *                                                     response when the report is locked
 *                                                     (endpoints: refuse direct POSTs too)
 * The guard sits AFTER each endpoint's existing RBAC + CSRF checks and never replaces
 * them. Nothing here writes to the database; incomplete reports are unaffected.
 */

require_once(__DIR__.'/official_report_lib.php');

if(!function_exists('mpqms_report_locked')){
	function mpqms_report_locked($conn, $regid){
		$regid = (int)$regid;
		if($regid <= 0){ return false; }
		try { return (bool)mpqms_sample_fully_completed($conn, $regid); }
		catch(\Throwable $e){ return false; }
	}
}

if(!function_exists('mpqms_locked_message')){
	function mpqms_locked_message(){
		return 'This report is completed (sample accepted, all results approved and the final result recorded) '
		     . 'and is locked as read-only. It can be viewed and downloaded but can no longer be modified.';
	}
}

/*
 * Administrator-verification gate for the FINAL RESULT (SRS 1.5 steps 4->5, 4.2, 6.5->6.6).
 *
 * The SRS workflow for RCDC/NDTL lab users is: enter results -> wait for Administrator
 * approval -> record final result. "Verified" means every result row of the sample has
 * an Administrator decision (Approved or Disapproved) and at least one row exists; rows
 * still Pending (or blank) block the final verdict. This is the same rule the Sample
 * Acceptance card already uses to decide when the sample is fully processed.
 *
 *   mpqms_results_verification($conn, $regid) -> array(total, decided, pending, verified)
 *   mpqms_results_verified($conn, $regid)     -> bool
 *   mpqms_final_result_pending_message($info) -> user-facing explanation
 *
 * Read-only helpers; they never write. The Administrator (acl 1) is not subject to this
 * gate (the Administrator performs the verification), so callers apply it to lab roles.
 */
if(!function_exists('mpqms_results_verification')){
	function mpqms_results_verification($conn, $regid){
		$regid = (int)$regid;
		$info = array('total'=>0, 'decided'=>0, 'pending'=>0, 'verified'=>false);
		if($regid <= 0){ return $info; }
		try {
			$st = mysqli_prepare($conn, "SELECT COUNT(*), SUM(approval IN ('Approved','Disapproved')) FROM labresulttable WHERE registrationID=?");
			mysqli_stmt_bind_param($st, 'i', $regid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $total, $decided);
			mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			$info['total']    = (int)$total;
			$info['decided']  = (int)$decided;
			$info['pending']  = (int)$total - (int)$decided;
			$info['verified'] = ((int)$total > 0 && (int)$total === (int)$decided);
		} catch(\Throwable $e){ /* fail closed: not verified */ }
		return $info;
	}
}

if(!function_exists('mpqms_results_verified')){
	function mpqms_results_verified($conn, $regid){
		$info = mpqms_results_verification($conn, $regid);
		return !empty($info['verified']);
	}
}

if(!function_exists('mpqms_final_result_pending_message')){
	function mpqms_final_result_pending_message($info){
		$total = (int)($info['total'] ?? 0); $pending = (int)($info['pending'] ?? 0);
		if($total < 1){
			return 'The Final Result cannot be recorded yet: no test results exist for this sample.';
		}
		return 'The Final Result can be recorded only after the Administrator has verified (approved or disapproved) every test result. '
		     . $pending.' of '.$total.' result'.($total === 1 ? '' : 's').' still awaiting Administrator approval.';
	}
}

if(!function_exists('mpqms_require_unlocked')){
	function mpqms_require_unlocked($conn, $regid, $mpAjax){
		if(mpqms_report_locked($conn, $regid)){
			if(!function_exists('mpq_fail')){ include_once(dirname(__DIR__).'/config/mpq_validate.php'); }
			mpq_fail(mpqms_locked_message(), $mpAjax);   // JSON for AJAX, SweetAlert-back otherwise; exits
		}
	}
}
?>
