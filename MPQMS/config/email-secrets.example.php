<?php
/*
 * MPQMS — email secrets TEMPLATE.
 * ---------------------------------------------------------------------------
 * Copy this file to  config/email-secrets.php  and fill in the real values.
 * email-secrets.php is git-ignored, so credentials never enter version control
 * and moving from the test mailbox to the production one needs NO code change.
 *
 *     cp config/email-secrets.example.php config/email-secrets.php
 *
 * Everything here is OPTIONAL and layered on top of config/mail_config.php:
 * only non-empty values override, so anything left blank keeps the value from
 * mail_config.php (which itself still reads environment variables). Environment
 * variables remain the recommended option on a managed server; this file exists
 * for deployments (like XAMPP) where setting env vars is impractical.
 */

return array(

	// Master switch for all MPQMS mail. Leave true; delivery still needs a host.
	'enabled' => true,

	// 'smtp' = real delivery, 'mail' = PHP mail()/MTA, 'log' = never send.
	'method'  => 'smtp',

	'smtp' => array(
		// --- Gmail example -------------------------------------------------
		// host: smtp.gmail.com   port: 587   encryption: tls
		// username: the full Gmail address
		// password: a 16-character Google APP PASSWORD (NOT the account password).
		//           Google account -> Security -> 2-Step Verification -> App passwords
		// -------------------------------------------------------------------
		'host'       => 'smtp.gmail.com',
		'port'       => 587,
		'encryption' => 'tls',            // tls | ssl | none
		'auth'       => true,
		'username'   => '',               // e.g. mpqms.alerts@gmail.com
		'password'   => '',               // e.g. abcd efgh ijkl mnop  (app password)
		'timeout'    => 15,
	),

	// Sender identity. With Gmail the from address must match the SMTP account.
	'from_email' => '',                   // e.g. mpqms.alerts@gmail.com
	'from_name'  => 'MPQMS - National Medical Product Testing Laboratory (NMPTL)',

	/* -----------------------------------------------------------------------
	 * NON-COMPLIANCE REGULATORY ALERT
	 * The MPD / BFDA focal person(s) who must be alerted the moment a result is
	 * verified & approved as "Does not comply". Accepts either a comma-separated
	 * string or an array. Leave empty to disable the alert (it is then recorded
	 * as SKIPPED rather than failing anything).
	 * -------------------------------------------------------------------- */
	'noncompliance_recipients' => '',     // 'mpd.focal@bfda.gov.bt, bfda.focal@bfda.gov.bt'

	/* -----------------------------------------------------------------------
	 * OFFICIAL FINAL OUTCOME REPORT
	 * The BFDA MPD focal person who receives the official outcome (Complies /
	 * Does not comply / Inconclusive) once the Final Result is saved. Leave
	 * blank to use the existing focal_email / mpd_focal_email from
	 * mail_config.php, which is the normal setup.
	 * -------------------------------------------------------------------- */
	'final_outcome_recipients' => '',     // optional override; blank = focal_email / mpd_focal_email

	// Base URL used to build the "view this sample" link inside the alert.
	// No trailing slash. Must be reachable by the recipient.
	'app_base_url' => 'http://localhost/MPQMS',

	/* Session-independent delivery (optional). After a Final Result save the notifications are
	 * sent by a detached PHP CLI worker (regtest/notify_worker.php) so they do not depend on the
	 * user's web session. Set 'notify_background' => false to send inline instead, or 'php_cli'
	 * to the PHP CLI binary if it cannot be located automatically. 'queue_token' lets an
	 * external scheduler drain the retry queue over HTTP without a login:
	 *   .../regtest/mail_queue.php?token=<queue_token>   (callers on the server itself need none)
	 */
	'notify_background' => true,
	'php_cli'           => '',
	'queue_token'       => '',

	// Attach the official Analytical Report PDF when one has already been
	// archived for the sample (analytical_report_archive). Never blocks the send.
	'attach_official_report' => true,
);
?>
