<?php
/*
 * MPQMS — Digital Signature for the FINAL RESULT (shared implementation).
 *
 * The signature modal (registration.php) only STAGES a signature into hidden fields of the
 * Final Result form; it is persisted here, by save_update_details.php (type=frslt), inside
 * the same transaction as the Final Result itself. So a signature can never exist without
 * the final result it authorises, and it is never written by any other action.
 *
 *   mpqms_signature_from_post($conn, $mpAjax, $now) -> array|null   validated staged signature,
 *                                                       or null when none was staged; an invalid
 *                                                       signature fails the request (mpq_fail)
 *                                                       BEFORE anything is written.
 *   mpqms_signature_store($conn, $regid, $sig)       -> void         prepared UPDATE; throws on
 *                                                       failure so the caller's transaction rolls back.
 *   mpqms_signature_current($conn, $regid)           -> array|null   latest stored signature (preview / PDF).
 *
 * Storage is unchanged: one signature per registration in labresulttable
 * (digital_signature, signature_type, signature_date, signed_by), the columns the Analytical
 * Report already reads — no database change was required for the relocation.
 */

if(!function_exists('mpqms_signature_from_post')){
	function mpqms_signature_from_post($conn, $mpAjax, $now){
		$data = trim((string)($_POST['sig_pending_data'] ?? ''));
		if($data === ''){ return null; }
		if(!function_exists('mpq_fail')){ include_once(dirname(__DIR__).'/config/mpq_validate.php'); }
		$method = trim((string)($_POST['sig_pending_method'] ?? ''));
		$signer = trim((string)($_POST['sig_pending_signer'] ?? ''));
		$date   = trim((string)($_POST['sig_pending_date']   ?? ''));
		if(!in_array($method, array('draw','upload'), true)){ mpq_fail('Digital signature: invalid signature method. Nothing was saved.', $mpAjax); }
		if($signer === ''){ mpq_fail("Digital signature: the signer's name is required. Nothing was saved.", $mpAjax); }
		if($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $date)){ $date = $now; }
		if(!preg_match('#^data:image/(png|jpeg|jpg|gif);base64,([A-Za-z0-9+/=\r\n]+)$#', $data, $m)){
			mpq_fail('Digital signature: the signature image is not valid (PNG, JPG or GIF expected). Nothing was saved.', $mpAjax);
		}
		$bin = base64_decode($m[2], true);
		if($bin === false || strlen($bin) > 2 * 1024 * 1024){ mpq_fail('Digital signature: the image is invalid or larger than 2 MB. Nothing was saved.', $mpAjax); }
		$img = @getimagesizefromstring($bin);
		if(!$img || !in_array($img[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF), true)){ mpq_fail('Digital signature: the file is not a valid PNG, JPG or GIF image. Nothing was saved.', $mpAjax); }
		// The whole data URI travels in one statement; keep it under the server's packet limit.
		try { $packet = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT @@max_allowed_packet"))[0]; } catch(\Throwable $e){ $packet = 1048576; }
		if(strlen($data) > $packet - 8192){ mpq_fail('Digital signature: the image is too large to store (about '.floor(($packet - 8192)/1024).' KB maximum). Please use a smaller image. Nothing was saved.', $mpAjax); }
		return array('data'=>$data, 'type'=>$method, 'date'=>$date, 'by'=>$signer);
	}
}

if(!function_exists('mpqms_signature_store')){
	function mpqms_signature_store($conn, $regid, $sig){
		$regid = (int)$regid;
		$st = mysqli_prepare($conn, "UPDATE labresulttable SET digital_signature=?, signature_type=?, signature_date=?, signed_by=? WHERE registrationID=?");
		mysqli_stmt_bind_param($st, 'ssssi', $sig['data'], $sig['type'], $sig['date'], $sig['by'], $regid);
		mysqli_stmt_execute($st);
		$n = mysqli_stmt_affected_rows($st);
		mysqli_stmt_close($st);
		// A signature must have a result record to authorise; with no test results there is nothing to sign.
		if($n < 1){
			$chk = mysqli_prepare($conn, "SELECT COUNT(*) FROM labresulttable WHERE registrationID=?");
			mysqli_stmt_bind_param($chk, 'i', $regid); mysqli_stmt_execute($chk); mysqli_stmt_bind_result($chk, $cnt); mysqli_stmt_fetch($chk); mysqli_stmt_close($chk);
			if((int)$cnt < 1){ throw new \Exception('the digital signature cannot be stored because this sample has no test results'); }
		}
	}
}

if(!function_exists('mpqms_signature_current')){
	function mpqms_signature_current($conn, $regid){
		$regid = (int)$regid;
		try {
			$st = mysqli_prepare($conn, "SELECT digital_signature, signed_by, signature_date, signature_type FROM labresulttable
				WHERE registrationID=? AND digital_signature IS NOT NULL AND digital_signature<>'' ORDER BY signature_date DESC LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $regid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $data, $by, $date, $type);
			$has = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			return $has ? array('data'=>$data, 'by'=>$by, 'date'=>$date, 'type'=>$type) : null;
		} catch(\Throwable $e){ return null; }
	}
}
?>
