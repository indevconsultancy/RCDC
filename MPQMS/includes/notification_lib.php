<?php
/*
 * MPQMS — Non-Compliance Regulatory Alert pipeline.
 * ---------------------------------------------------------------------------
 * Alerts the MPD / BFDA focal person the moment a test result is verified &
 * approved as "Does not comply", so regulatory action can start immediately.
 *
 * Architecture follows the FooDSIMS BFDA report notification pipeline, adapted
 * to the MPQMS data model:
 *
 *   1. READINESS GATE      mpqms_nc_readiness()      — is there an approved
 *                          non-compliance on this registration yet?
 *   2. RECIPIENT RESOLUTION MpqmsEmailService::recipients() — configured focal
 *                          address(es), never hard-coded.
 *   3. IDEMPOTENT RECORD   claim a row in mpqms_noncompliance_notification
 *                          (UNIQUE registrationID+recipient_email) BEFORE
 *                          sending, so one sample = one alert per recipient.
 *   4. COMPOSE + PDF       subject/body from the sample record, optionally
 *                          attaching the archived official Analytical Report.
 *   5. SEND                via MpqmsEmailService (wraps includes/mailer.php).
 *   6. OUTCOME LOGGING     row updated to SENT / FAILED / SKIPPED.
 *
 * SAFETY CONTRACT — this module is called from the approval and final-result
 * save paths, so it must never disturb them:
 *   - No function here ever throws; every DB/mail/file call is wrapped and a
 *     status array is returned instead.
 *   - A missing table, missing config, missing recipient or dead SMTP server
 *     degrades to a recorded SKIPPED/FAILED, never an exception or fatal.
 *   - Nothing here writes to labresulttable, finalresulttable or any existing
 *     table; the feature is entirely additive.
 * Prepared statements are used for every query that takes a value.
 */

if(!defined('MPQMS_NC_RESULT')){ define('MPQMS_NC_RESULT', 'Does not comply'); }

require_once(dirname(__DIR__).'/includes/mailer.php');

/* =========================================================================
 * EmailService — thin, testable wrapper around the shared mail engine.
 * Mirrors the FooDSIMS FsEmailService role: it owns configuration, recipient
 * resolution and delivery, and reports outcomes instead of throwing.
 * ====================================================================== */
if(!class_exists('MpqmsEmailService')){
	class MpqmsEmailService {

		private $cfg;

		public function __construct($cfg = null){
			$this->cfg = is_array($cfg) ? $cfg : mpqms_mail_cfg();
		}

		/** Resolved mail configuration (mail_config.php + email-secrets.php overlay). */
		public function config(){ return $this->cfg; }

		/**
		 * Focal person address(es) for the non-compliance alert.
		 * Accepts a comma/semicolon-separated string OR an array in
		 * 'noncompliance_recipients'; falls back to the existing focal settings
		 * so an installation already configured via env vars keeps working.
		 * @return array de-duplicated, syntactically valid addresses
		 */
		public function recipients(){
			$raw = $this->cfg['noncompliance_recipients'] ?? '';
			$list = array();
			if(is_array($raw)){ $list = $raw; }
			else if(trim((string)$raw) !== ''){ $list = preg_split('/[,;]+/', (string)$raw); }

			if(empty($list)){   // fall back to the addresses mail_config.php already knows
				foreach(array('mpd_focal_email','bfda_focal_email','focal_email') as $k){
					if(!empty($this->cfg[$k])){ $list[] = $this->cfg[$k]; }
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

		/** True when a transport is actually able to deliver (host + master switch). */
		public function isConfigured(){
			$on   = !isset($this->cfg['enabled']) ? true : (bool)$this->cfg['enabled'];
			$m    = strtolower($this->cfg['method'] ?? 'smtp');
			$host = trim($this->cfg['smtp']['host'] ?? '');
			return $on && ($m === 'mail' || ($m === 'smtp' && $host !== ''));
		}

		/** Base URL for deep links into MPQMS (no trailing slash). */
		public function appBaseUrl(){
			$u = trim((string)($this->cfg['app_base_url'] ?? ''));
			if($u === ''){
				$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
				$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
				$u = $scheme.'://'.$host.'/MPQMS';
			}
			return rtrim($u, '/');
		}

		/** Should the archived official report be attached when one exists? */
		public function attachOfficialReport(){
			return !isset($this->cfg['attach_official_report']) ? true : (bool)$this->cfg['attach_official_report'];
		}

		/**
		 * Deliver one message. Never throws.
		 * @return array {delivered, status, error, attempts, transport, to}
		 */
		/** @param string|array $to one address or the whole recipient set for ONE message. */
		public function send($to, $subject, $body, $attachments = array()){
			try {
				return mpqms_send_mail($to, $subject, $body, array(
					'high_priority'   => true,          // regulatory alert
					'request_receipt' => true,
					'attachments'     => $attachments,
					// This runs inside the approval / final-result save, so the request must
					// not stall on an unreachable mail server: one short attempt only. A
					// failure is queued by the mailer and drained later by mail_queue.php,
					// so the alert is delayed, never lost.
					'max_retries'     => 1,
					'timeout'         => 5,
					'context'         => 'non-compliance alert'
				));
			} catch(\Throwable $e){
				return array('delivered'=>false, 'status'=>'FAILED', 'error'=>$e->getMessage(),
				             'attempts'=>0, 'transport'=>'none', 'to'=>$to);
			}
		}
	}
}

/* =========================================================================
 * Storage — the audit / idempotency table.
 * ====================================================================== */
if(!function_exists('mpqms_nc_ensure_table')){
	function mpqms_nc_ensure_table($conn){
		try {
			mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mpqms_noncompliance_notification (
				id INT AUTO_INCREMENT PRIMARY KEY,
				registrationID INT NOT NULL,
				sampleID VARCHAR(50) NULL,
				recipient_email VARCHAR(255) NOT NULL,
				trigger_source VARCHAR(20) NOT NULL,
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

if(!function_exists('mpqms_nc_log')){
	/** Diagnostic trail; shares the mail log so all delivery evidence sits together. */
	function mpqms_nc_log($line){
		try { mpqms_mail_log('[non-compliance] '.$line); } catch(\Throwable $e){}
	}
}

/* =========================================================================
 * 1. READINESS GATE
 * ====================================================================== */
if(!function_exists('mpqms_nc_readiness')){
	/**
	 * Is this registration reportable as non-compliant yet?
	 * True when EITHER
	 *   (a) at least one labresulttable row is approval='Approved' AND
	 *       result='Does not comply'  (verified & approved by the authority), OR
	 *   (b) the saved Final Result is 'Does not comply'.
	 * Read-only. Returns a structured verdict; false-safe on any error.
	 */
	function mpqms_nc_readiness($conn, $regid){
		$regid = (int)$regid;
		$v = array('ready'=>false, 'approved_rows'=>0, 'final_noncompliant'=>false);
		if($regid <= 0){ return $v; }
		try {
			$st = mysqli_prepare($conn, "SELECT COUNT(*) FROM labresulttable
				WHERE registrationID=? AND approval='Approved' AND result=?");
			$nc = MPQMS_NC_RESULT;
			mysqli_stmt_bind_param($st, 'is', $regid, $nc);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $cnt);
			mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			$v['approved_rows'] = (int)$cnt;

			$st = mysqli_prepare($conn, "SELECT result FROM finalresulttable WHERE registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $regid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $fr);
			$has = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			$v['final_noncompliant'] = ($has && strcasecmp(trim((string)$fr), MPQMS_NC_RESULT) === 0);

			$v['ready'] = ($v['approved_rows'] > 0 || $v['final_noncompliant']);
		} catch(\Throwable $e){ mpqms_nc_log('readiness failed for reg '.$regid.': '.$e->getMessage()); }
		return $v;
	}
}

/* =========================================================================
 * 4. COMPOSE — sample context, message body, optional PDF.
 * ====================================================================== */
if(!function_exists('mpqms_nc_sample_context')){
	/** Read-only sample details used in the alert. Returns array|null. */
	function mpqms_nc_sample_context($conn, $regid){
		$regid = (int)$regid;
		try {
			$st = mysqli_prepare($conn, "SELECT r.sampleID, r.registrationDate, r.batchNo, r.pharmaClaim,
					r.pharmaOther, r.edlID, r.edlOther, r.labelClaim, e.edl
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
			$row['claim'] = (trim((string)$row['pharmaClaim']) === 'Others')
				? (string)$row['pharmaOther'] : (string)$row['pharmaClaim'];

			// The parameters that actually failed (the regulatory detail).
			$row['failed'] = array();
			$st = mysqli_prepare($conn, "SELECT pt.parameter, pt.unit, rt.observation, rt.standard, rt.approval
				FROM mstestparametertable pt, labresulttable rt
				WHERE pt.id = rt.parameterID AND rt.registrationID = ? AND rt.result = ?
				ORDER BY pt.ranking ASC");
			$nc = MPQMS_NC_RESULT;
			mysqli_stmt_bind_param($st, 'is', $regid, $nc);
			mysqli_stmt_execute($st);
			$res = mysqli_stmt_get_result($st);
			while($res && ($p = mysqli_fetch_assoc($res))){ $row['failed'][] = $p; }
			mysqli_stmt_close($st);

			// Final result / remarks, when saved.
			$st = mysqli_prepare($conn, "SELECT result, remarks FROM finalresulttable WHERE registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $regid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $fr, $frm);
			if(mysqli_stmt_fetch($st)){ $row['final_result'] = $fr; $row['final_remarks'] = $frm; }
			else { $row['final_result'] = ''; $row['final_remarks'] = ''; }
			mysqli_stmt_close($st);

			return $row;
		} catch(\Throwable $e){
			mpqms_nc_log('context failed for reg '.$regid.': '.$e->getMessage());
			return null;
		}
	}
}

if(!function_exists('mpqms_nc_compose')){
	/** Build {subject, body} for the alert. Pure formatting — no I/O. */
	function mpqms_nc_compose($regid, $ctx, $triggerSource, $baseUrl){
		$sid  = trim((string)($ctx['sampleID'] ?? ''));
		$link = $baseUrl.'/regtest/view.php?rid='.(int)$regid;

		$subject = 'MPQMS Alert: Non-Compliant Test Result - Sample '.($sid !== '' ? $sid : ('Registration '.(int)$regid));

		$failedLines = '';
		if(!empty($ctx['failed'])){
			foreach($ctx['failed'] as $p){
				$unit = trim((string)($p['unit'] ?? ''));
				$failedLines .=
					"    - ".trim((string)$p['parameter']).($unit !== '' ? ' ('.$unit.')' : '')."\n".
					"        Observation : ".trim((string)($p['observation'] ?? ''))."\n".
					"        Standard    : ".trim((string)($p['standard'] ?? ''))."\n".
					"        Approval    : ".trim((string)($p['approval'] ?? ''))."\n";
			}
		} else {
			$failedLines = "    (see the attached / online report for parameter detail)\n";
		}

		$trigger = ($triggerSource === 'FINAL_RESULT')
			? 'Final Result saved as "'.MPQMS_NC_RESULT.'"'
			: (($triggerSource === 'MANUAL') ? 'Manually re-issued by an administrator'
			                                 : 'Test parameter verified & approved as "'.MPQMS_NC_RESULT.'"');

		$body =
			"*** AUTOMATED REGULATORY ALERT - NON-COMPLIANT TEST RESULT ***\n\n".
			"A laboratory test result has been VERIFIED AND APPROVED as NON-COMPLIANT.\n".
			"Prompt regulatory action by the MPD / BFDA focal person is requested.\n\n".
			"--------------------------------------------------------------\n".
			"  Sample ID          : ".$sid."\n".
			"  Registration Date  : ".trim((string)($ctx['registrationDate'] ?? ''))."\n".
			"  Product / EDL      : ".trim((string)($ctx['product'] ?? ''))."\n".
			"  Pharmaceutical Claim: ".trim((string)($ctx['claim'] ?? ''))."\n".
			"  Label Claim Amount : ".trim((string)($ctx['labelClaim'] ?? ''))."\n".
			"  Batch Number       : ".trim((string)($ctx['batchNo'] ?? ''))."\n".
			"  Final Result       : ".trim((string)($ctx['final_result'] ?? '(not yet saved)'))."\n".
			"  Remarks            : ".trim((string)($ctx['final_remarks'] ?? ''))."\n".
			"--------------------------------------------------------------\n\n".
			"NON-COMPLIANT PARAMETER(S):\n".$failedLines."\n".
			"View this sample in MPQMS:\n  ".$link."\n\n".
			"Alert trigger: ".$trigger.".\n\n".
			"This is an automated alert generated by the Medicinal Product Quality\n".
			"Monitoring System (MPQMS) for regulatory action. Please do not reply to\n".
			"this message; use the MPQMS link above to review the full analytical report.\n";

		return array('subject'=>$subject, 'body'=>$body);
	}
}

if(!function_exists('mpqms_nc_report_attachment')){
	/**
	 * The archived official Analytical Report PDF, when one already exists.
	 * Purely opportunistic — this never generates a report and never fails.
	 * @return array attachment descriptors for mpqms_send_mail (possibly empty)
	 */
	function mpqms_nc_report_attachment($conn, $regid, $ctx){
		try {
			$lib = dirname(__DIR__).'/regtest/official_report_lib.php';
			if(!is_file($lib)){ return array(); }
			require_once($lib);
			if(!function_exists('mpqms_official_report_record')){ return array(); }
			$rec = mpqms_official_report_record($conn, $regid);
			if($rec === null){ return array(); }
			$abs = mpqms_official_abs_path($rec['file_path']);
			if($abs === null || !is_file($abs)){ return array(); }
			$sid = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($ctx['sampleID'] ?? ''));
			return array(array(
				'path' => $abs,
				'name' => 'Analytical_Report_'.($sid !== '' ? $sid : 'Sample').'_'.(int)$regid.'.pdf',
				'mime' => 'application/pdf'
			));
		} catch(\Throwable $e){
			mpqms_nc_log('attachment lookup failed for reg '.$regid.': '.$e->getMessage());
			return array();
		}
	}
}

/* =========================================================================
 * 3 + 5 + 6. CLAIM -> SEND -> RECORD OUTCOME
 * ====================================================================== */
if(!function_exists('mpqms_nc_claim')){
	/**
	 * Idempotency gate. Tries to claim (registrationID, recipient) by inserting a
	 * PENDING-style row. The UNIQUE key makes this safe under concurrency.
	 * @return string 'claimed'  -> caller should send
	 *                'sent'     -> already alerted, do nothing
	 *                'retry'    -> a previous attempt FAILED, sending again is allowed
	 *                'error'    -> storage unavailable
	 */
	function mpqms_nc_claim($conn, $regid, $sampleID, $recipient, $trigger, $user){
		try {
			$now = date('Y-m-d H:i:s');
			$st = mysqli_prepare($conn, "INSERT IGNORE INTO mpqms_noncompliance_notification
				(registrationID, sampleID, recipient_email, trigger_source, email_result, created_at, created_by)
				VALUES (?, ?, ?, ?, 'SENDING', ?, ?)");
			mysqli_stmt_bind_param($st, 'isssss', $regid, $sampleID, $recipient, $trigger, $now, $user);
			mysqli_stmt_execute($st);
			$inserted = mysqli_stmt_affected_rows($st) > 0;
			mysqli_stmt_close($st);
			if($inserted){ return 'claimed'; }

			// Row already exists — a successful alert is final, a failure may retry.
			$st = mysqli_prepare($conn, "SELECT email_result FROM mpqms_noncompliance_notification
				WHERE registrationID=? AND recipient_email=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'is', $regid, $recipient);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $prev);
			$has = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			$prev = strtoupper(trim((string)$prev));
			if($prev === 'SENT'){ return 'sent'; }
			// Another run claimed this recipient and has not finished yet. Treat it as in flight,
			// unless the claim is stale (a crashed worker), in which case this run may take it over.
			if($prev === 'SENDING' && !mpqms_nc_claim_is_stale($conn, $regid, $recipient)){ return 'sending'; }
			return 'retry';
		} catch(\Throwable $e){
			mpqms_nc_log('claim failed for reg '.$regid.' / '.$recipient.': '.$e->getMessage());
			return 'error';
		}
	}
}

if(!function_exists('mpqms_nc_claim_is_stale')){
	/** An in-flight claim older than 10 minutes is treated as abandoned. */
	function mpqms_nc_claim_is_stale($conn, $regid, $recipient){
		try {
			$st = mysqli_prepare($conn, "SELECT created_at FROM mpqms_noncompliance_notification
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

if(!function_exists('mpqms_nc_record_outcome')){
	/** Write the final SENT / FAILED / SKIPPED verdict for one recipient. */
	function mpqms_nc_record_outcome($conn, $regid, $recipient, $result, $error, $trigger){
		try {
			$sentOn = ($result === 'SENT') ? date('Y-m-d H:i:s') : null;
			$err    = ($error === '' ) ? null : $error;
			$st = mysqli_prepare($conn, "UPDATE mpqms_noncompliance_notification
				SET email_result=?, email_sent_on=?, email_error=?, trigger_source=?
				WHERE registrationID=? AND recipient_email=?");
			mysqli_stmt_bind_param($st, 'ssssis', $result, $sentOn, $err, $trigger, $regid, $recipient);
			mysqli_stmt_execute($st);
			mysqli_stmt_close($st);
			return true;
		} catch(\Throwable $e){
			mpqms_nc_log('outcome write failed for reg '.$regid.': '.$e->getMessage());
			return false;
		}
	}
}

/* =========================================================================
 * PUBLIC ENTRY POINT
 * ====================================================================== */
if(!function_exists('mpqms_notify_non_compliance')){
	/**
	 * Run the whole pipeline for one registration. Safe to call from any save
	 * path, as often as you like: it is gated, idempotent and never throws.
	 *
	 * @param  mysqli $conn    ndtlisdb connection
	 * @param  int    $regid   registrationID
	 * @param  string $trigger AUTO_APPROVAL | FINAL_RESULT | MANUAL
	 * @param  string $user    username that caused the trigger
	 * @return array  {status, sent, skipped, failed, recipients, reason}
	 */
	function mpqms_notify_non_compliance($conn, $regid, $trigger = 'AUTO_APPROVAL', $user = ''){
		$out = array('status'=>'skipped', 'sent'=>0, 'skipped'=>0, 'failed'=>0,
		             'recipients'=>array(), 'reason'=>'');
		$regid = (int)$regid;
		if($regid <= 0){ $out['reason'] = 'invalid registrationID'; return $out; }

		try {
			// 1. Readiness gate — nothing to report unless an approved non-compliance exists.
			$ready = mpqms_nc_readiness($conn, $regid);
			if(!$ready['ready']){ $out['reason'] = 'not a verified non-compliant result'; return $out; }

			// 2. Recipient resolution — the BFDA/DRA user(s) linked to THIS sample (registering
			//    user / collecting centre / dzongkhag, from the common user tables — see
			//    includes/bfda_recipient_lib.php). Only when no such user exists does the
			//    configured focal address apply, and that fallback is logged.
			$svc  = new MpqmsEmailService();
			require_once(__DIR__.'/bfda_recipient_lib.php');
			$dyn  = mpqms_bfda_recipients($conn, $regid);
			$rcpt = $dyn['recipients'];
			if(!empty($rcpt)){
				mpqms_nc_log('reg '.$regid.': BFDA/DRA recipient(s) resolved by '.$dyn['basis'].' -> '.implode(', ', $rcpt));
			} else {
				$rcpt = $svc->recipients();
				mpqms_nc_log('reg '.$regid.': '.$dyn['reason'].' - falling back to the configured focal address(es)'.(empty($rcpt) ? '' : ' -> '.implode(', ', $rcpt)));
			}
			if(empty($rcpt)){
				$out['reason'] = 'no focal recipient configured (config/email-secrets.php)';
				mpqms_nc_log('reg '.$regid.': '.$out['reason']);
				return $out;
			}
			$out['recipients'] = $rcpt;

			if(!mpqms_nc_ensure_table($conn)){
				$out['reason'] = 'notification table unavailable';
				return $out;
			}

			// 4. Compose once for all recipients.
			$ctx = mpqms_nc_sample_context($conn, $regid);
			if($ctx === null){ $out['reason'] = 'sample not found'; return $out; }
			$msg  = mpqms_nc_compose($regid, $ctx, $trigger, $svc->appBaseUrl());
			$atts = $svc->attachOfficialReport() ? mpqms_nc_report_attachment($conn, $regid, $ctx) : array();
			$sid  = (string)($ctx['sampleID'] ?? '');

			// 3. Idempotent claim, for the whole recipient set, BEFORE anything is sent. A recipient
			//    already alerted for this sample is skipped; one that is mid-flight in another run is
			//    left alone. What remains is the set this transaction owns.
			$toSend = array();
			foreach($rcpt as $to){
				$claim = mpqms_nc_claim($conn, $regid, $sid, $to, $trigger, (string)$user);
				if($claim === 'sent' || $claim === 'sending'){
					$out['skipped']++;
					mpqms_nc_log('reg '.$regid.' -> '.$to.': '.($claim === 'sent' ? 'already alerted' : 'already being sent by another run').', skipped');
					continue;
				}
				if($claim === 'error'){ $out['failed']++; continue; }
				$toSend[] = $to;
			}
			if(empty($toSend)){
				$out['status'] = ($out['failed'] > 0) ? 'failed' : 'skipped';
				$out['reason'] = $out['reason'] !== '' ? $out['reason'] : 'nothing left to alert for this sample';
				return $out;
			}

			// 4. ONE controlled send for the whole set — one message, one SMTP transaction, no
			//    per-recipient loop and no resend anywhere below.
			$res = $svc->send($toSend, $msg['subject'], $msg['body'], $atts);

			// 5. One outcome per recipient, all derived from that single result, plus ONE aggregated
			//    log line for the transaction. Nothing here sends another email.
			$accepted = isset($res['accepted']) ? $res['accepted'] : (!empty($res['delivered']) ? $toSend : array());
			$refused  = isset($res['rejected']) ? $res['rejected'] : array();
			$isLog    = (($res['transport'] ?? '') === 'log');
			foreach($toSend as $to){
				if($isLog){ $verdict = 'SKIPPED'; $err = (string)($res['error'] ?? ''); $out['skipped']++; }
				else if(in_array($to, $accepted, true)){ $verdict = 'SENT'; $err = ''; $out['sent']++; }
				else { $verdict = 'FAILED'; $err = isset($refused[$to]) ? (string)$refused[$to] : (string)($res['error'] ?? 'not accepted by the mail server'); $out['failed']++; }
				mpqms_nc_record_outcome($conn, $regid, $to, $verdict, $err, $trigger);
			}
			$out['failed_recipients'] = array();
			foreach($toSend as $to){ if(!$isLog && !in_array($to, $accepted, true)){ $out['failed_recipients'][$to] = isset($refused[$to]) ? (string)$refused[$to] : (string)($res['error'] ?? 'not accepted'); } }
			mpqms_nc_log(mpqms_mail_transaction_summary(array(
				'record'    => 'reg '.$regid.($sid !== '' ? ' / '.$sid : ''),
				'type'      => 'Non-Compliance Alert ('.$trigger.')',
				'attempted' => $toSend,
				'accepted'  => $accepted,
				'failed'    => $out['failed_recipients'],
				'result'    => $res,
				'extra'     => $atts ? 'official report attached' : '',
			)));

			$out['status'] = ($out['sent'] > 0) ? (($out['failed'] > 0) ? 'partial' : 'sent') : (($out['failed'] > 0) ? 'failed' : 'skipped');
			return $out;

		} catch(\Throwable $e){
			// Absolute backstop: the approval/final-result save must never be affected.
			mpqms_nc_log('pipeline error for reg '.$regid.': '.$e->getMessage());
			$out['status'] = 'failed'; $out['reason'] = $e->getMessage();
			return $out;
		}
	}
}
?>
