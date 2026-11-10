<?php
/*
 * Shared helpers for the MPQMS password-reset flow.
 * Sends the reset link to the user's registered email using the same config-driven
 * mechanism as the other notifications (config/mail_config.php); until SMTP is
 * configured it writes an audit copy to the access-guarded email/outbox/ folder.
 */

if(!function_exists('mpqms_base_url')){
	function mpqms_base_url(){
		$scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
		$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
		return $scheme.'://'.$host;
	}
}

if(!function_exists('mpqms_send_reset_email')){
	function mpqms_send_reset_email($toEmail, $username, $link){
		$cfg = @include(dirname(__DIR__).'/config/mail_config.php');
		if(!is_array($cfg)){ $cfg = array('enabled'=>false, 'method'=>'log'); }

		$subject = 'MPQMS Password Reset Request';
		$body =
			"A password reset was requested for your MPQMS account (username: $username).\n\n".
			"To set a new password, open the link below. It expires in 30 minutes and can be used only once:\n\n".
			$link."\n\n".
			"If you did not request this, no action is needed — your password will not change.\n\n".
			"— MPQMS, National Medical Product Testing Laboratory (NMPTL)\n";

		// Deliver via the shared SMTP engine (same config/retries/queue as the outcome emails).
		require_once(dirname(__DIR__).'/includes/mailer.php');
		$res = mpqms_send_mail($toEmail, $subject, $body, array(
			'high_priority' => true,
			'context'       => 'password reset for '.$username
		));
		$sent    = $res['delivered'];
		$emailed = ($res['transport'] !== 'log');   // was a real send attempted?

		// Audit / dev log. Only write the raw link when NOT actually emailed (dev), so
		// production logs never hold a live reset token on disk.
		$dir = dirname(__DIR__).'/email/outbox';
		if(!is_dir($dir)){ @mkdir($dir, 0775, true); }
		$stamp  = date('Y-m-d H:i:s');
		$status = $res['status'].' via '.$res['transport'].' after '.$res['attempts'].' attempt(s)';
		$linkLine = $emailed ? '(reset link emailed to user; token not logged)' : $link;
		$safeUser = preg_replace('/[^A-Za-z0-9_]/', '_', $username);
		@file_put_contents(
			$dir.'/reset_'.$safeUser.'_'.date('Ymd_His').'.txt',
			"MPQMS PASSWORD RESET\nTime  : $stamp\nUser  : $username\nTo    : $toEmail\nStatus: $status\nLink  : $linkLine\n"
		);
		return $sent;
	}
}
?>
