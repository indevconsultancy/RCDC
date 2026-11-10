<?php
/*
 * MPQMS — Official Final Outcome notification.
 * ---------------------------------------------------------------------------
 * Once a sample's testing is complete and the Final Result has been saved by
 * the verifying authority, the BFDA MPD focal person receives ONE short email
 * carrying the official outcome only — Complies / Does not comply /
 * Inconclusive — plus a delivery acknowledgement reference. This is the
 * official real-time report of record.
 *
 * DELIBERATELY MINIMAL CONTENT: Sample ID, final outcome, batch/product for
 * identification, the official-record statement and the acknowledgement. No
 * observations, standards, parameter detail, remarks or attachments — the
 * detailed analytical report is retrieved from MPQMS itself.
 *
 * This is a SEPARATE notification from the non-compliance regulatory alert in
 * includes/notification_lib.php. That alert escalates a failing result for
 * prompt action; this one reports the official outcome for every sample. They
 * have independent tables, independent idempotency and independent recipients,
 * and neither can affect the other.
 *
 * Pipeline (same shape as the non-compliance alert):
 *   1. READINESS GATE      a valid final outcome is saved and the sample was
 *                          not rejected.
 *   2. RECIPIENT RESOLUTION focal_email / mpd_focal_email from mail_config.php
 *                          (override with 'final_outcome_recipients').
 *   3. IDEMPOTENT CLAIM    UNIQUE(registrationID, recipient_email) claimed
 *                          BEFORE sending — one official outcome email per
 *                          sample per recipient.
 *   4. COMPOSE             minimal official-outcome message.
 *   5. SEND                read/delivery receipt requested.
 *   6. ACKNOWLEDGE + LOG   SENT/FAILED/SKIPPED plus a written acknowledgement
 *                          receipt in email/outbox/.
 *
 * SAFETY CONTRACT: nothing here throws. It is called from the Final Result
 * save path, so every DB / mail / file operation is wrapped and reports a
 * status instead. It never writes to any pre-existing table.
 */

require_once(dirname(__FILE__).'/notification_lib.php');   // MpqmsEmailService + mailer

if(!function_exists('mpqms_fo_valid_outcomes')){
	function mpqms_fo_valid_outcomes(){
		return array('Complies', 'Does not comply', 'Inconclusive');
	}
}

if(!function_exists('mpqms_fo_log')){
	function mpqms_fo_log($line){
		try { mpqms_mail_log('[final-outcome] '.$line); } catch(\Throwable $e){}
	}
}

/* =========================================================================
 * Storage — audit trail + idempotency store.
 * ====================================================================== */
if(!function_exists('mpqms_fo_ensure_table')){
	function mpqms_fo_ensure_table($conn){
		try {
			mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mpqms_final_outcome_notification (
				id INT AUTO_INCREMENT PRIMARY KEY,
				registrationID INT NOT NULL,
				sampleID VARCHAR(50) NULL,
				final_outcome VARCHAR(50) NULL,
				recipient_email VARCHAR(255) NOT NULL,
				acknowledgement_ref VARCHAR(80) NULL,
				email_result VARCHAR(10) NOT NULL,
				email_sent_on DATETIME NULL,
				email_error TEXT NULL,
				created_at DATETIME NOT NULL,
				created_by VARCHAR(100) NULL,
				UNIQUE KEY uq_reg_recipient (registrationID, recipient_email),
				KEY idx_reg (registrationID),
				KEY idx_result (email_result)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			return true;
		} catch(\Throwable $e){ return false; }
	}
}

/* =========================================================================
 * 1. READINESS GATE
 * ====================================================================== */
if(!function_exists('mpqms_fo_readiness')){
	/**
	 * Reportable when a recognised final outcome has been saved AND the sample
	 * was not rejected (a rejected sample has no official outcome to report).
	 * Read-only; false-safe.
	 * @return array {ready, outcome, accepted}
	 */
	function mpqms_fo_readiness($conn, $regid){
		$regid = (int)$regid;
		$v = array('ready'=>false, 'outcome'=>'', 'accepted'=>false);
		if($regid <= 0){ return $v; }
		try {
			$st = mysqli_prepare($conn, "SELECT result FROM finalresulttable WHERE registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $regid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $res);
			$has = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			if(!$has){ return $v; }

			// Normalise to the canonical spelling so casing differences still match.
			$raw = trim((string)$res);
			foreach(mpqms_fo_valid_outcomes() as $valid){
				if(strcasecmp($raw, $valid) === 0){ $v['outcome'] = $valid; break; }
			}
			if($v['outcome'] === ''){ return $v; }

			$st = mysqli_prepare($conn, "SELECT status FROM samplestatustable WHERE registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $regid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $status);
			$hasSt = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			// Absent status is tolerated (legacy records); only an explicit Rejected blocks.
			$v['accepted'] = !($hasSt && strcasecmp(trim((string)$status), 'Rejected') === 0);

			$v['ready'] = $v['accepted'];
		} catch(\Throwable $e){ mpqms_fo_log('readiness failed for reg '.$regid.': '.$e->getMessage()); }
		return $v;
	}
}

/* =========================================================================
 * 2. RECIPIENT RESOLUTION
 * ====================================================================== */
if(!function_exists('mpqms_fo_recipients')){
	/**
	 * The BFDA MPD focal person(s) for the official outcome report.
	 * Uses the EXISTING mail configuration — focal_email and mpd_focal_email —
	 * and honours an optional 'final_outcome_recipients' override (string or
	 * array) for installations that report to a different address.
	 * @return array validated, de-duplicated addresses
	 */
	function mpqms_fo_recipients($cfg){
		$list = array();
		$override = $cfg['final_outcome_recipients'] ?? '';
		if(is_array($override)){ $list = $override; }
		else if(trim((string)$override) !== ''){ $list = preg_split('/[,;]+/', (string)$override); }

		if(empty($list)){
			foreach(array('focal_email', 'mpd_focal_email') as $k){
				if(!empty($cfg[$k])){ $list[] = $cfg[$k]; }
			}
		}

		$out = array(); $seen = array();
		foreach($list as $a){
			$a = trim((string)$a);
			if($a === '' || !filter_var($a, FILTER_VALIDATE_EMAIL)){ continue; }
			$k = strtolower($a);
			if(isset($seen[$k])){ continue; }
			$seen[$k] = true; $out[] = $a;
		}
		return $out;
	}
}

/* =========================================================================
 * 4. COMPOSE — official outcome only.
 * ====================================================================== */
if(!function_exists('mpqms_fo_identification')){
	/** Minimal identification: sample ID, batch, product. Nothing else. */
	function mpqms_fo_identification($conn, $regid){
		$regid = (int)$regid;
		try {
			$st = mysqli_prepare($conn, "SELECT r.sampleID, r.batchNo, r.edlID, r.edlOther, e.edl
				FROM registrationtable r LEFT JOIN msedltable e ON r.edlID = e.id
				WHERE r.registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $regid);
			mysqli_stmt_execute($st);
			$res = mysqli_stmt_get_result($st);
			$row = $res ? mysqli_fetch_assoc($res) : null;
			mysqli_stmt_close($st);
			if(!$row){ return null; }
			$row['product'] = ((int)$row['edlID'] === 999 || trim((string)$row['edl']) === '')
				? (string)$row['edlOther'] : (string)$row['edl'];
			return $row;
		} catch(\Throwable $e){
			mpqms_fo_log('identification failed for reg '.$regid.': '.$e->getMessage());
			return null;
		}
	}
}

if(!function_exists('mpqms_fo_ack_ref')){
	/** Stable-per-send acknowledgement reference quoted in the email and stored. */
	function mpqms_fo_ack_ref($regid, $when = null){
		return 'MPQMS-FO-'.(int)$regid.'-'.date('YmdHis', $when === null ? time() : $when);
	}
}

if(!function_exists('mpqms_fo_compose')){
	/**
	 * Build {subject, body}. Pure formatting, no I/O.
	 * Contains ONLY the official outcome and identification — by design there
	 * are no observations, standards, parameter results or remarks here.
	 */
	function mpqms_fo_compose($ident, $outcome, $ackRef){
		$sid     = trim((string)($ident['sampleID'] ?? ''));
		$batch   = trim((string)($ident['batchNo'] ?? ''));
		$product = trim((string)($ident['product'] ?? ''));

		$subject = 'MPQMS Official Final Outcome ['.$outcome.'] - Sample '.($sid !== '' ? $sid : 'record');

		$body =
			"OFFICIAL FINAL OUTCOME - MEDICINAL PRODUCT QUALITY MONITORING SYSTEM\n".
			"====================================================================\n\n".
			"Testing of the sample below is complete and the Final Result has been\n".
			"verified and approved by the verifying authority. This message is the\n".
			"official final outcome for this sample.\n\n".
			"  Sample ID      : ".$sid."\n".
			($product !== '' ? "  Product        : ".$product."\n" : '').
			($batch   !== '' ? "  Batch Number   : ".$batch."\n"   : '').
			"\n".
			"  FINAL OUTCOME  : ".strtoupper($outcome)."\n\n".
			"--------------------------------------------------------------------\n".
			"DELIVERY ACKNOWLEDGEMENT\n".
			"  Reference    : ".$ackRef."\n".
			"  Issued on    : ".date('Y-m-d H:i:s')."\n".
			"  This message has been dispatched to the BFDA MPD focal person and\n".
			"  recorded in the MPQMS official notification register as delivered.\n".
			"  A read receipt has been requested for confirmation of receipt.\n".
			"--------------------------------------------------------------------\n\n".
			"No detailed test results are included in this notification. The full\n".
			"analytical report remains available in MPQMS.\n\n".
			"This is an automated official notification. Please do not reply.\n";

		return array('subject'=>$subject, 'body'=>$body);
	}
}

/* =========================================================================
 * 3 + 6. CLAIM -> OUTCOME RECORD -> ACKNOWLEDGEMENT RECEIPT
 * ====================================================================== */
if(!function_exists('mpqms_fo_claim')){
	/**
	 * Idempotency gate. Claims (registrationID, recipient) before sending, so a
	 * re-save, refresh or double-click cannot produce a second official email.
	 * @return string claimed | sent | retry | error
	 */
	function mpqms_fo_claim($conn, $regid, $sampleID, $outcome, $recipient, $ackRef, $user){
		try {
			$now = date('Y-m-d H:i:s');
			$st = mysqli_prepare($conn, "INSERT IGNORE INTO mpqms_final_outcome_notification
				(registrationID, sampleID, final_outcome, recipient_email, acknowledgement_ref,
				 email_result, created_at, created_by)
				VALUES (?, ?, ?, ?, ?, 'SENDING', ?, ?)");
			mysqli_stmt_bind_param($st, 'issssss', $regid, $sampleID, $outcome, $recipient, $ackRef, $now, $user);
			mysqli_stmt_execute($st);
			$inserted = mysqli_stmt_affected_rows($st) > 0;
			mysqli_stmt_close($st);
			if($inserted){ return 'claimed'; }

			$st = mysqli_prepare($conn, "SELECT email_result FROM mpqms_final_outcome_notification
				WHERE registrationID=? AND recipient_email=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'is', $regid, $recipient);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $prev);
			$has = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			// A delivered official outcome is final. A previous failure may retry.
			$prev = strtoupper(trim((string)$prev));
			if($prev === 'SENT'){ return 'sent'; }
			// Claimed by another run that has not finished; only a stale claim may be taken over.
			if($prev === 'SENDING' && !mpqms_fo_claim_is_stale($conn, $regid, $recipient)){ return 'sending'; }
			return 'retry';
		} catch(\Throwable $e){
			mpqms_fo_log('claim failed for reg '.$regid.' / '.$recipient.': '.$e->getMessage());
			return 'error';
		}
	}
}

if(!function_exists('mpqms_fo_claim_is_stale')){
	/** An in-flight claim older than 10 minutes is treated as abandoned. */
	function mpqms_fo_claim_is_stale($conn, $regid, $recipient){
		try {
			$st = mysqli_prepare($conn, "SELECT created_at FROM mpqms_final_outcome_notification
				WHERE registrationID=? AND recipient_email=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'is', $regid, $recipient);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $created);
			$has = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			if(!$has || $created === null){ return true; }
			return (time() - strtotime((string)$created)) > 600;
		} catch(\Throwable $e){ return true; }
	}
}

if(!function_exists('mpqms_fo_record_outcome')){
	function mpqms_fo_record_outcome($conn, $regid, $recipient, $outcome, $result, $error, $ackRef){
		try {
			$sentOn = ($result === 'SENT') ? date('Y-m-d H:i:s') : null;
			$err    = ($error === '') ? null : $error;
			$st = mysqli_prepare($conn, "UPDATE mpqms_final_outcome_notification
				SET email_result=?, email_sent_on=?, email_error=?, final_outcome=?, acknowledgement_ref=?
				WHERE registrationID=? AND recipient_email=?");
			mysqli_stmt_bind_param($st, 'sssssis', $result, $sentOn, $err, $outcome, $ackRef, $regid, $recipient);
			mysqli_stmt_execute($st);
			mysqli_stmt_close($st);
			return true;
		} catch(\Throwable $e){
			mpqms_fo_log('outcome write failed for reg '.$regid.': '.$e->getMessage());
			return false;
		}
	}
}

if(!function_exists('mpqms_fo_write_acknowledgement')){
	/**
	 * Written acknowledgement receipt — the official evidence that the outcome
	 * was dispatched. Uses the existing email/outbox/ convention.
	 */
	function mpqms_fo_write_acknowledgement($regid, $sid, $outcome, $recipients, $ackRef, $res, $failed = array()){
		try {
			$dir = mpqms_mail_dir('outbox');
			$attempted = (array)$recipients;
			$failed    = (array)$failed;
			$deliveredTo = array();
			foreach($attempted as $a){ if(!isset($failed[$a])){ $deliveredTo[] = $a; } }
			$final = (($res['transport'] ?? '') === 'log') ? 'LOGGED_ONLY'
			       : (empty($deliveredTo) ? 'FAILED' : (empty($failed) ? 'SUCCESS' : 'PARTIAL_FAILURE'));
			$txt =
				"=== MPQMS OFFICIAL FINAL OUTCOME - DELIVERY ACKNOWLEDGEMENT ===\n".
				"Reference        : ".$ackRef."\n".
				"Timestamp        : ".date('Y-m-d H:i:s')."\n".
				"Registration     : ".(int)$regid."\n".
				"Sample ID        : ".$sid."\n".
				"Final Outcome    : ".$outcome."\n".
				"Attempted        : ".implode(', ', $attempted)."\n".
				"Delivered to     : ".(empty($deliveredTo) ? '(none)' : implode(', ', $deliveredTo))."\n".
				"Transport        : ".($res['transport'] ?? '-')."\n".
				"Delivery status  : ".($res['status'] ?? '-')."\n".
				"Attempts         : ".(int)($res['attempts'] ?? 0)."\n".
				"Read receipt     : requested\n";
			if(!empty($failed)){
				$txt .= "Failed recipients:\n";
				foreach($failed as $addr => $why){ $txt .= "  - ".$addr." - ".preg_replace('/\s+/', ' ', trim((string)$why))."\n"; }
			}
			$txt .= (!empty($res['error']) ? "Error            : ".$res['error']."\n" : '')
			      . "Final status     : ".$final."\n"
			      . "===============================================================\n";
			@file_put_contents($dir.'/final_outcome_'.(int)$regid.'_'.date('Ymd_His').'.txt', $txt);
			@file_put_contents($dir.'/outbox.log',
				'['.date('Y-m-d H:i:s').'] FINAL OUTCOME reg '.(int)$regid.' outcome='.$outcome
				.' attempted='.count($attempted).' delivered='.count($deliveredTo).' failed='.count($failed)
				.' status='.$final.' ref='.$ackRef."\n", FILE_APPEND);
			return true;
		} catch(\Throwable $e){ return false; }
	}
}

/* =========================================================================
 * PUBLIC ENTRY POINT
 * ====================================================================== */
if(!function_exists('mpqms_notify_final_outcome')){
	/**
	 * Report the official final outcome. Safe to call on every Final Result
	 * save: gated, idempotent and incapable of throwing.
	 *
	 * @param  mysqli $conn  ndtlisdb connection
	 * @param  int    $regid registrationID
	 * @param  string $user  username that saved the Final Result
	 * @return array  {status, outcome, sent, skipped, failed, recipients, ack_ref, reason}
	 */
	function mpqms_notify_final_outcome($conn, $regid, $user = ''){
		$out = array('status'=>'skipped', 'outcome'=>'', 'sent'=>0, 'skipped'=>0, 'failed'=>0,
		             'recipients'=>array(), 'ack_ref'=>'', 'reason'=>'');
		$regid = (int)$regid;
		if($regid <= 0){ $out['reason'] = 'invalid registrationID'; return $out; }

		try {
			// 1. Readiness gate.
			$ready = mpqms_fo_readiness($conn, $regid);
			$out['outcome'] = $ready['outcome'];
			if(!$ready['ready']){
				$out['reason'] = ($ready['outcome'] === '')
					? 'no recognised final outcome saved'
					: 'sample was rejected - no official outcome to report';
				return $out;
			}
			$outcome = $ready['outcome'];

			// 2. Recipient resolution — the BFDA/DRA user(s) linked to THIS sample (registering
			//    user / collecting centre / dzongkhag, from the common user tables — see
			//    includes/bfda_recipient_lib.php). The existing focal configuration is only the
			//    fallback when no such user exists, and that fallback is logged.
			$svc  = new MpqmsEmailService();
			$cfg  = $svc->config();
			require_once(__DIR__.'/bfda_recipient_lib.php');
			$dyn  = mpqms_bfda_recipients($conn, $regid);
			$rcpt = $dyn['recipients'];
			if(!empty($rcpt)){
				mpqms_fo_log('reg '.$regid.': BFDA/DRA recipient(s) resolved by '.$dyn['basis'].' -> '.implode(', ', $rcpt));
			} else {
				$rcpt = mpqms_fo_recipients($cfg);
				mpqms_fo_log('reg '.$regid.': '.$dyn['reason'].' - falling back to the configured focal address(es)'.(empty($rcpt) ? '' : ' -> '.implode(', ', $rcpt)));
			}
			if(empty($rcpt)){
				$out['reason'] = 'no BFDA MPD focal recipient configured (focal_email / mpd_focal_email)';
				mpqms_fo_log('reg '.$regid.': '.$out['reason']);
				return $out;
			}
			$out['recipients'] = $rcpt;

			if(!mpqms_fo_ensure_table($conn)){
				$out['reason'] = 'notification table unavailable';
				return $out;
			}

			// 4. Compose (identification only).
			$ident = mpqms_fo_identification($conn, $regid);
			if($ident === null){ $out['reason'] = 'sample not found'; return $out; }
			$sid    = (string)($ident['sampleID'] ?? '');
			$ackRef = mpqms_fo_ack_ref($regid);
			$out['ack_ref'] = $ackRef;
			$msg = mpqms_fo_compose($ident, $outcome, $ackRef);

			// 3. Idempotent claim for the whole recipient set, BEFORE anything is sent.
			$toSend = array();
			foreach($rcpt as $to){
				$claim = mpqms_fo_claim($conn, $regid, $sid, $outcome, $to, $ackRef, (string)$user);
				if($claim === 'sent' || $claim === 'sending'){
					$out['skipped']++;
					mpqms_fo_log('reg '.$regid.' -> '.$to.': official outcome '.($claim === 'sent' ? 'already reported' : 'already being sent by another run').', skipped');
					continue;
				}
				if($claim === 'error'){ $out['failed']++; continue; }
				$toSend[] = $to;
			}
			if(empty($toSend)){
				$out['status'] = ($out['failed'] > 0) ? 'failed' : 'skipped';
				$out['reason'] = 'official outcome already reported for every recipient';
				return $out;
			}

			// 4. ONE controlled send for the whole set, with a read/delivery receipt requested.
			$res = mpqms_send_mail($toSend, $msg['subject'], $msg['body'], array(
				'request_receipt' => true,          // acknowledgement of receipt
				// Runs in the background worker after the Final Result save: one short attempt,
				// then the mailer's retry queue takes over (drained by mail_queue.php).
				'max_retries'     => 1,
				'timeout'         => 5,
				'context'         => 'official final outcome'
			));

			// 5. One outcome per recipient from that single result, then ONE acknowledgement and
			//    ONE log line for the whole transaction.
			$accepted = isset($res['accepted']) ? $res['accepted'] : (!empty($res['delivered']) ? $toSend : array());
			$refused  = isset($res['rejected']) ? $res['rejected'] : array();
			$isLog    = (($res['transport'] ?? '') === 'log');
			$failedR  = array();
			foreach($toSend as $to){
				if($isLog){ $verdict = 'SKIPPED'; $err = (string)($res['error'] ?? ''); $out['skipped']++; }
				else if(in_array($to, $accepted, true)){ $verdict = 'SENT'; $err = ''; $out['sent']++; }
				else {
					$verdict = 'FAILED';
					$err = isset($refused[$to]) ? (string)$refused[$to] : (string)($res['error'] ?? 'not accepted by the mail server');
					$failedR[$to] = $err; $out['failed']++;
				}
				mpqms_fo_record_outcome($conn, $regid, $to, $outcome, $verdict, $err, $ackRef);
			}
			$out['failed_recipients'] = $failedR;
			mpqms_fo_write_acknowledgement($regid, $sid, $outcome, $toSend, $ackRef, $res, $failedR);
			mpqms_fo_log(mpqms_mail_transaction_summary(array(
				'record'    => 'reg '.$regid.($sid !== '' ? ' / '.$sid : ''),
				'type'      => 'Official Final Outcome ['.$outcome.']',
				'attempted' => $toSend,
				'accepted'  => $accepted,
				'failed'    => $failedR,
				'result'    => $res,
				'extra'     => 'ref='.$ackRef,
			)));

			$out['status'] = ($out['sent'] > 0) ? (($out['failed'] > 0) ? 'partial' : 'sent') : (($out['failed'] > 0) ? 'failed' : 'skipped');
			return $out;

		} catch(\Throwable $e){
			mpqms_fo_log('pipeline error for reg '.$regid.': '.$e->getMessage());
			$out['status'] = 'failed'; $out['reason'] = $e->getMessage();
			return $out;
		}
	}
}
?>
