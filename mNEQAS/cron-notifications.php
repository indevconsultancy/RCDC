<?php
	/* Notification mailer. Drains notification_logs rows that are not SENT yet and
	   retries the ones a previous attempt could not deliver, up to MAIL_MAX_RETRY.

	   Scheduling (Windows Task Scheduler / cron), e.g. every 5 minutes:
	       C:\xampp\php\php.exe C:\xampp\htdocs\mNEQAS\cron-notifications.php

	   The workflow handlers already try to deliver inline, so this job only picks up
	   what the mail server was unable to accept at the time (and everything queued
	   while MAIL_SEND_INLINE was off). Same guard as cron-dashboard.php: the CLI path
	   is always allowed, an HTTP request needs an authenticated Administrator. */
	if (PHP_SAPI !== 'cli') {
		require_once("config/session.php");
		mn_require_admin('The notification mailer can only be run by an Administrator.');
	}
	require_once("config/db-config.php");
	require_once("notifications/mn-notify.php");
	date_default_timezone_set('Asia/Thimphu');

	$cfg = mn_notify_config();
	if (empty($cfg['MAIL_ENABLED'])) {
		echo "Mail delivery is disabled (MAIL_ENABLED = false). Nothing was sent.\n";
		exit;
	}

	$batch = 50;                                  // messages per run
	$res   = mn_notify_flush($conn, null, null, $batch);

	$pending = 0;
	$q = $conn->query("SELECT COUNT(*) FROM notification_logs WHERE status <> 'SENT' AND retry_count < " . (int)(isset($cfg['MAIL_MAX_RETRY']) ? $cfg['MAIL_MAX_RETRY'] : 3));
	if ($q) { $pending = (int)$q->fetch_row()[0]; }

	echo 'Notification mailer: ' . (int)$res['sent'] . " sent, " . (int)$res['failed'] . " failed, "
	   . $pending . " still queued.\n";
