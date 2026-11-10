<?php
/*
 * MPQMS final-outcome email notification (requirements 4 & 6).
 * After a test result is verified & approved (final result saved), notify the
 * BFDA MPD focal person of the outcome (Complies / Non-Complies), flagging
 * Non-Complies for prompt regulatory action, and record a delivery
 * acknowledgement in the outbox log as the official communication trail.
 */

if(!function_exists('mpqms_mail_outbox_dir')){
	function mpqms_mail_outbox_dir(){
		$base = dirname(__DIR__).'/email/outbox';
		if(!is_dir($base)){ @mkdir($base, 0775, true); }
		return $base;
	}
}

if(!function_exists('mpqms_notify_final_result')){
	function mpqms_notify_final_result($regid, $result, $connection, $commondb){
		$regid = intval($regid);
		$cfg = @include(dirname(__DIR__).'/config/mail_config.php');
		if(!is_array($cfg)){ $cfg = array('enabled'=>false,'method'=>'log'); }

		// Gather sample context for the message.
		$row = mysqli_fetch_row(mysqli_query($connection, "SELECT sampleID,batchNo,pharmaClaim,pharmaOther,labelClaim FROM registrationtable WHERE registrationID='$regid'"));
		$sid   = $row[0] ?? '';
		$batch = $row[1] ?? '';
		$pcl   = ($row[2] ?? '') === 'Others' ? ($row[3] ?? '') : ($row[2] ?? '');
		$label = $row[4] ?? '';
		list($frmks) = mysqli_fetch_row(mysqli_query($connection, "SELECT remarks FROM finalresulttable WHERE registrationID='$regid'"));

		$isNonComply = (strcasecmp(trim($result), 'Does not comply') === 0);
		$outcome = $isNonComply ? 'NON-COMPLIES' : (strcasecmp(trim($result),'Complies')===0 ? 'COMPLIES' : strtoupper($result));

		$subject = 'MPQMS Test Outcome ['.$outcome.'] - Sample '.$sid.($isNonComply ? ' - REGULATORY ACTION REQUIRED' : '');
		$body =
			"This is an official communication from the National Medical Product Testing Laboratory (NMPTL) (MPQMS).\n\n".
			"A laboratory test result has been verified and approved with the following final outcome:\n\n".
			"  Final Outcome   : ".$outcome."\n".
			"  Sample ID       : ".$sid."\n".
			"  Batch Number    : ".$batch."\n".
			"  Pharma Claim    : ".$pcl."\n".
			"  Label Claim Amt : ".$label."\n".
			"  Remarks         : ".($frmks ?? '')."\n\n".
			($isNonComply
				? "This sample DOES NOT COMPLY. Prompt regulatory action by the BFDA MPD focal person is requested.\n\n"
				: "This sample COMPLIES with the applicable standards.\n\n").
			"The official analytical report is retained in the MPQMS report archive.\n";

		// Deliver via the shared, config-driven SMTP engine (real send + retries + queue).
		require_once(dirname(__DIR__).'/includes/mailer.php');

		// -------- Duplicate prevention (idempotency) --------
		// One official approved outcome -> one notification. A page refresh, double-click, repeated
		// request or re-save of the SAME approved outcome must NOT re-send. (A changed outcome or a
		// prior FAILED attempt is still allowed to notify — see mpqms_notification_already_sent().)
		require_once(__DIR__.'/notification_log.php');
		if(mpqms_notification_already_sent($connection, $regid, $outcome)){
			@file_put_contents(mpqms_mail_outbox_dir().'/outbox.log', "[".date('Y-m-d H:i:s')."] reg $regid outcome=$outcome SKIPPED — already notified (duplicate prevented)\n", FILE_APPEND);
			mpqms_log_notification($connection, $regid, $sid, '(duplicate)', $outcome, 'FINAL_OUTCOME', 'SKIPPED_DUPLICATE', null, 'Already notified for this approved outcome', 0);
			return array('status'=>'skipped_duplicate', 'outcome'=>$outcome, 'sent'=>false, 'recipients'=>array());
		}

		// Build the recipient list. A NON-COMPLIANT (verified & approved) result is a real-time
		// regulatory alert dispatched to BOTH the MPD and BFDA focal officers, in addition to the
		// standing focal address. Complies/other outcomes keep the existing single-focal
		// notification unchanged (backward compatible). Each address is emailed individually
		// (the SMTP engine takes one recipient per call) and de-duplicated case-insensitively.
		// The BFDA/DRA user(s) linked to THIS sample come first (registering user / collecting
		// centre / dzongkhag, from the common user tables — includes/bfda_recipient_lib.php).
		// The configured focal addresses below are only the fallback when no such user exists.
		require_once(dirname(__DIR__).'/includes/bfda_recipient_lib.php');
		$mpDyn = mpqms_bfda_recipients($connection, $regid);
		$recipients = $mpDyn['recipients'];
		if(!empty($recipients)){
			@file_put_contents(mpqms_mail_outbox_dir().'/outbox.log', "[".date('Y-m-d H:i:s')."] reg $regid BFDA/DRA recipient(s) resolved by ".$mpDyn['basis']." -> ".implode(', ', $recipients)."\n", FILE_APPEND);
		} else {
			@file_put_contents(mpqms_mail_outbox_dir().'/outbox.log', "[".date('Y-m-d H:i:s')."] reg $regid ".$mpDyn['reason']." - falling back to the configured focal address(es)\n", FILE_APPEND);
			$focal = trim($cfg['focal_email'] ?? '');
			if($focal !== ''){ $recipients[] = $focal; }
			if($isNonComply){
				foreach(array('mpd_focal_email','bfda_focal_email') as $k){
					$addr = trim($cfg[$k] ?? '');
					if($addr !== ''){ $recipients[] = $addr; }
				}
			}
		}
		$seen = array();
		$recipients = array_values(array_filter($recipients, function($a) use (&$seen){ $l = strtolower($a); if($l === '' || isset($seen[$l])){ return false; } $seen[$l] = 1; return true; }));

		$stamp = date('Y-m-d H:i:s');
		$dir   = mpqms_mail_outbox_dir();

		// Fail-safe fallback (never throws): a Non-Compliant outcome with no configured focal
		// officer is logged as a warning so the missing config is visible without breaking approval.
		if($isNonComply && empty($recipients)){
			@file_put_contents($dir.'/outbox.log', "[$stamp] WARNING reg $regid NON-COMPLIANT but no MPD/BFDA/focal email is configured — regulatory alert NOT dispatched\n", FILE_APPEND);
		}

		// Dispatch to each officer.
		// ONE controlled send for the whole recipient set — one message, one SMTP transaction.
		// Previously this looped and sent a separate copy to every address.
		$statusList = array();
		$anySent = false;
		$failedR  = array();
		if(!empty($recipients)){
			$res = mpqms_send_mail($recipients, $subject, $body, array(
				'high_priority'   => $isNonComply,   // Non-Complies -> instant high-priority regulatory alert
				'request_receipt' => true,
				'context'         => 'outcome reg '.$regid.' ('.$outcome.')'
			));
			$anySent = !empty($res['delivered']);
			$accepted = isset($res['accepted']) ? $res['accepted'] : ($anySent ? $recipients : array());
			$refused  = isset($res['rejected']) ? $res['rejected'] : array();
			$isLog    = (($res['transport'] ?? '') === 'log');
			foreach($recipients as $addr){
				if(!$isLog && !in_array($addr, $accepted, true)){
					$failedR[$addr] = isset($refused[$addr]) ? (string)$refused[$addr] : (string)($res['error'] ?? 'not accepted by the mail server');
				}
			}
			$statusList[implode(', ', $recipients)] = $res['status'].' via '.$res['transport'].' after '.$res['attempts'].' attempt(s)'
				.($res['error'] ? ' - '.$res['error'] : '');
			@file_put_contents(mpqms_mail_outbox_dir().'/mail_transaction.log',
				'['.date('Y-m-d H:i:s').'] '.mpqms_mail_transaction_summary(array(
					'record'    => 'reg '.$regid.($sid !== '' ? ' / '.$sid : ''),
					'type'      => 'Test Outcome Notice ['.$outcome.']',
					'attempted' => $recipients,
					'accepted'  => $accepted,
					'failed'    => $failedR,
					'result'    => $res,
				))."\n", FILE_APPEND);
			// Acknowledgement/delivery record (Phase 11). One row per recipient, all derived from the
			// SINGLE send above — delivery_status stays NULL (never falsely claim "Delivered").
			foreach($recipients as $addr){
				$normStatus = $isLog ? strtoupper((string)$res['status'])
				            : (isset($failedR[$addr]) ? 'FAILED' : 'SENT');
				$rowErr = isset($failedR[$addr]) ? $failedR[$addr] : ($res['error'] ?: null);
				mpqms_log_notification($connection, $regid, $sid, $addr, $outcome, 'FINAL_OUTCOME', $normStatus, null, $rowErr, (int)$res['attempts']);
			}
		}

		// Delivery acknowledgement / audit trail (the official-communication record).
		$toLine    = empty($recipients) ? '(not configured)' : implode(', ', $recipients);
		$ackStatus = empty($statusList) ? 'not dispatched (no recipient)'
		           : implode(' | ', array_map(function($a, $s){ return $a.': '.$s; }, array_keys($statusList), array_values($statusList)));
		$ack =
			"=== MPQMS OUTCOME NOTIFICATION ===\n".
			"Timestamp      : ".$stamp."\n".
			"Registration   : ".$regid."\n".
			"Outcome        : ".$outcome.($isNonComply ? '  (REGULATORY ALERT -> MPD + BFDA)' : '')."\n".
			"To             : ".$toLine."\n".
			"Subject        : ".$subject."\n".
			"Delivery status: ".$ackStatus."\n".
			"----- MESSAGE -----\n".$body."\n";

		@file_put_contents($dir.'/report_'.$regid.'_'.date('Ymd_His').'.txt', $ack);
		@file_put_contents($dir.'/outbox.log', "[$stamp] reg $regid outcome=$outcome to=".$toLine." status=".$ackStatus."\n", FILE_APPEND);

		return array('status'=>$ackStatus, 'outcome'=>$outcome, 'sent'=>$anySent, 'recipients'=>$recipients);
	}
}
?>
