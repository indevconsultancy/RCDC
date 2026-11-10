<?php
/*
 * MPQMS mail retry-queue processor.
 * Retries any notification/reset emails that failed to send (e.g. SMTP was
 * briefly unreachable).  Run it on a schedule for automatic retries:
 *
 *   Windows Task Scheduler (every 5 min):
 *     C:\xampp\php\php.exe C:\xampp\htdocs\MPQMS\regtest\mail_queue.php
 *   cron (every 5 min): use "5-minute interval" cron expression running:
 *     /usr/bin/php /var/www/MPQMS/regtest/mail_queue.php
 *
 * It also runs opportunistically after any successful send, so a working
 * mailbox drains the backlog on its own.
 */
require_once(dirname(__DIR__).'/includes/mailer.php');

// CLI (cron / the background worker) is unrestricted. Over HTTP the queue can be drained
// WITHOUT a login session by a caller on the server itself (a scheduler using curl/wget) or by
// a caller presenting the configured 'queue_token' (config/email-secrets.php); any other
// browser hit still requires an authenticated Administrator session, as before. Delivery of
// queued e-mails therefore no longer depends on somebody being logged in.
if(PHP_SAPI !== 'cli'){
	$mpqCfg   = mpqms_mail_cfg();
	$mpqTok   = trim((string)($mpqCfg['queue_token'] ?? ''));
	$mpqIp    = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	$mpqLocal = in_array($mpqIp, array('127.0.0.1', '::1'), true);
	$mpqTokOk = ($mpqTok !== '' && hash_equals($mpqTok, (string)($_REQUEST['token'] ?? '')));
	if(!$mpqLocal && !$mpqTokOk){
		if(session_status() === PHP_SESSION_NONE){ session_start(); }
		if(!isset($_SESSION['users']) || (int)($_SESSION['acl'] ?? 0) !== 1){
			http_response_code(403);
			exit('Forbidden');
		}
		@session_write_close();
	}
	header('Content-Type: text/plain');
}

$r = mpqms_mail_flush_queue(200);
echo "[".date('Y-m-d H:i:s')."] mail queue: processed {$r['processed']}, delivered {$r['delivered']}\n";
?>
