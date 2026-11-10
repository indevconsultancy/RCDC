<?php
/*
 * MPQMS email delivery configuration.
 * ---------------------------------------------------------------------------
 * Real SMTP delivery for the outcome notifications (reqs 7 & 9) and the
 * password-reset link.  Every value can be supplied via an ENVIRONMENT
 * VARIABLE (recommended for secrets like the SMTP password) and falls back to
 * the literal default below.
 *
 * TO GO LIVE, set at minimum (env vars OR the defaults here):
 *     MPQMS_SMTP_HOST      e.g. smtp.office365.com / smtp.gmail.com / mail.gov.bt
 *     MPQMS_SMTP_PORT      587 (STARTTLS) or 465 (SSL)
 *     MPQMS_SMTP_ENC       tls | ssl | none
 *     MPQMS_SMTP_USER      SMTP login
 *     MPQMS_SMTP_PASS      SMTP password / app password
 *     MPQMS_FOCAL_EMAIL    BFDA MPD focal person's address
 *     MPQMS_MAIL_FROM      sender address (should match the SMTP account/domain)
 * Delivery activates automatically once a host + recipient are present.
 * Set MPQMS_MAIL_ENABLED=0 to force log-only (e.g. staging) without clearing creds.
 */

$env = function($key, $default = '') {
	$v = getenv($key);
	return ($v === false || $v === '') ? $default : $v;
};
$bool = function($v, $default) use ($env) {
	$raw = $env($v, null);
	if ($raw === null) return $default;
	return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
};

$cfg = array(
	// Master kill-switch. Default ON — actual sending still requires a host + recipient.
	'enabled'      => $bool('MPQMS_MAIL_ENABLED', true),

	// 'smtp' = real SMTP delivery (recommended). 'mail' = PHP mail()/MTA. 'log' = never send.
	'method'       => $env('MPQMS_MAIL_METHOD', 'smtp'),

	// --- SMTP server (fill these in to activate delivery) ---
	'smtp' => array(
		'host'       => $env('MPQMS_SMTP_HOST', 'smtp.gmail.com'),
		'port'       => (int)$env('MPQMS_SMTP_PORT', '587'),
		'encryption' => $env('MPQMS_SMTP_ENC', 'tls'),          // tls | ssl | none
		'auth'       => $bool('MPQMS_SMTP_AUTH', true),
		'username'   => $env('MPQMS_SMTP_USER', 'anupampal1307@gmail.com'),
		'password'   => $env('MPQMS_SMTP_PASS', 'tkfl kbmp kdan jjav'),
		'timeout'    => (int)$env('MPQMS_SMTP_TIMEOUT', '15'),  // seconds (connect + read)
	),

	// Recipients / sender identity
	'focal_email'  => $env('MPQMS_FOCAL_EMAIL', 'anupampal1307@gmail.com'),
	'focal_name'   => $env('MPQMS_FOCAL_NAME', 'BFDA MPD Focal Person'),
	// Non-Compliance regulatory alert goes to BOTH focal officers. Each falls back to
	// MPQMS_FOCAL_EMAIL so a single-address setup keeps working; a missing address is logged,
	// never fatal (see notify_mail.php).
	'mpd_focal_email'  => $env('MPD_FOCAL_EMAIL',  $env('MPQMS_FOCAL_EMAIL', 'anupampal1307@gmail.com')),
	'bfda_focal_email' => $env('BFDA_FOCAL_EMAIL', $env('MPQMS_FOCAL_EMAIL', 'anupampal1307@gmail.com')),
	'cc_email'     => $env('MPQMS_MAIL_CC', ''),
	'from_email'   => $env('MPQMS_MAIL_FROM', 'anupampal1307@gmail.com'),
	'from_name'    => $env('MPQMS_MAIL_FROM_NAME', 'MPQMS - National Medical Product Testing Laboratory (NMPTL)'),

	// Ask the mail client for a read/delivery receipt (best-effort acknowledgement).
	'request_receipt' => $bool('MPQMS_MAIL_RECEIPT', true),

	// Reliability
	'max_retries'  => (int)$env('MPQMS_MAIL_RETRIES', '3'),
	'retry_queue'  => $bool('MPQMS_MAIL_QUEUE', true),
);

/* ---------------------------------------------------------------------------
 * Local secrets overlay (config/email-secrets.php — git-ignored; see
 * email-secrets.example.php). Only NON-EMPTY values override, so a template
 * left partly blank never wipes a value set above or via an environment
 * variable. When the file is absent this block does nothing at all, and the
 * configuration resolves exactly as it did before.
 * ------------------------------------------------------------------------ */
$secrets = @include(__DIR__.'/email-secrets.php');
if (is_array($secrets)) {
	$overlay = function($base, $over) use (&$overlay) {
		foreach ($over as $k => $v) {
			if (is_array($v) && isset($base[$k]) && is_array($base[$k])) { $base[$k] = $overlay($base[$k], $v); }
			else if (is_bool($v) || is_int($v))                          { $base[$k] = $v; }
			else if (is_array($v))                                       { if (!empty($v)) { $base[$k] = $v; } }
			else if (trim((string)$v) !== '')                            { $base[$k] = $v; }
		}
		return $base;
	};
	$cfg = $overlay($cfg, $secrets);
}

return $cfg;
?>