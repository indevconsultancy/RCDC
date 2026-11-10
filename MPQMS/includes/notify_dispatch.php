<?php
/*
 * MPQMS — session-independent dispatch of the Final Result notifications.
 *
 * WHY THIS EXISTS
 *   The three regulator e-mails that follow a Final Result save (outcome notice in
 *   regtest/notify_mail.php, non-compliance alert in includes/notification_lib.php and the
 *   official final outcome in includes/final_outcome_lib.php) used to run inside the user's
 *   web request, after the database commit. That tied their delivery to the life of that
 *   request and its login session: an expired / destroyed session, a logout or navigation
 *   while the page was still working, an AJAX timeout, or PHP's max_execution_time could end
 *   the request part-way through the sends, and whatever had not yet been handed to the SMTP
 *   engine was lost (a request killed mid-send never reaches the retry queue either).
 *
 * WHAT IT DOES
 *   mpqms_notify_final_result_dispatch() is called once, after the Final Result transaction
 *   has been committed and the report archive written. It
 *     1. releases the PHP session lock (nothing below needs $_SESSION — every notifier reads
 *        the sample, the recipients and the mail settings from the database / config);
 *     2. hands the work to a detached PHP CLI worker (regtest/notify_worker.php) that runs
 *        exactly the same three notifiers, in the same order, with the same arguments, and
 *        then drains the retry queue. The worker has no session and outlives the request;
 *     3. if the worker cannot be started (no CLI binary, spawning disabled) it runs the
 *        notifiers inline, but with ignore_user_abort() and no execution-time limit so a
 *        logout / disconnect / timeout can no longer cut the sends short.
 *   Every notifier is idempotent per recipient (one alert per sample per address), so even a
 *   double dispatch cannot produce a duplicate e-mail. Subjects, bodies, attachments,
 *   recipient rules, SMTP settings and the notification tables are untouched.
 *
 *   Optional mail-config keys (config/email-secrets.php):
 *     'notify_background' => false   run the notifiers inline instead of in the worker
 *     'php_cli'           => '...'   explicit path to the PHP CLI binary
 */

require_once(dirname(__DIR__).'/includes/mailer.php');   // mpqms_mail_cfg / mpqms_mail_dir / mpqms_mail_log

if(!function_exists('mpqms_notify_log')){
	function mpqms_notify_log($line){ try { mpqms_mail_log('[dispatch] '.$line); } catch(\Throwable $e){} }
}

if(!function_exists('mpqms_notify_final_result_inline')){
	/**
	 * The three notifiers, exactly as they were called from save_update_details.php.
	 * Each is isolated: a failure in one can never affect the others or the caller.
	 */
	function mpqms_notify_final_result_inline($connection, $commondb, $rid, $rslt, $uid){
		try {
			require_once(dirname(__DIR__)."/regtest/notify_mail.php");
			mpqms_notify_final_result($rid, $rslt, $connection, $commondb);
		} catch(\Throwable $e) { mpqms_notify_log('reg '.$rid.' outcome notice failed: '.$e->getMessage()); }
		// Non-compliance regulatory alert (one alert per sample per recipient).
		try {
			require_once(dirname(__DIR__)."/includes/notification_lib.php");
			mpqms_notify_non_compliance($connection, $rid, 'FINAL_RESULT', $uid);
		} catch(\Throwable $e) { mpqms_notify_log('reg '.$rid.' non-compliance alert failed: '.$e->getMessage()); }
		// Official Final Outcome report to the BFDA MPD focal person (one official email per sample).
		try {
			require_once(dirname(__DIR__)."/includes/final_outcome_lib.php");
			mpqms_notify_final_outcome($connection, $rid, $uid);
		} catch(\Throwable $e) { mpqms_notify_log('reg '.$rid.' official outcome failed: '.$e->getMessage()); }
	}
}

if(!function_exists('mpqms_notify_php_cli')){
	/** Locate a PHP CLI binary: configured path, then the directory of the loaded php.ini, then PHP_BINDIR. */
	function mpqms_notify_php_cli($cfg){
		$exe = (PHP_OS_FAMILY === 'Windows') ? 'php.exe' : 'php';
		$cands = array();
		if(!empty($cfg['php_cli'])){ $cands[] = (string)$cfg['php_cli']; }
		$ini = php_ini_loaded_file();
		if($ini){ $cands[] = dirname($ini).DIRECTORY_SEPARATOR.$exe; }
		$cands[] = rtrim(PHP_BINDIR, '/\\').DIRECTORY_SEPARATOR.$exe;
		if(defined('PHP_BINARY') && PHP_BINARY !== '' && stripos(basename(PHP_BINARY), 'php') === 0){ $cands[] = PHP_BINARY; }
		foreach($cands as $c){ if($c !== '' && is_file($c)){ return $c; } }
		return null;
	}
}

if(!function_exists('mpqms_notify_spawn_worker')){
	/**
	 * Start regtest/notify_worker.php detached from this request. Returns true only when the
	 * worker has confirmed it is running (it writes a start marker within a few seconds).
	 */
	function mpqms_notify_spawn_worker($cfg, $rid, $rslt, $uid, &$why){
		$php = mpqms_notify_php_cli($cfg);
		if($php === null){ $why = 'no PHP CLI binary found'; return false; }
		$worker = dirname(__DIR__).DIRECTORY_SEPARATOR.'regtest'.DIRECTORY_SEPARATOR.'notify_worker.php';
		if(!is_file($worker)){ $why = 'worker script missing'; return false; }
		if(!function_exists('popen')){ $why = 'popen() unavailable'; return false; }
		$token  = bin2hex(random_bytes(8));
		$dir    = mpqms_mail_dir('dispatch');
		$marker = $dir.'/'.$token.'.started';
		$args   = escapeshellarg((string)(int)$rid).' '.escapeshellarg((string)$rslt).' '.escapeshellarg((string)$uid).' '.escapeshellarg($token);
		if(PHP_OS_FAMILY === 'Windows'){
			$cmd = 'start /B "" "'.str_replace('/', '\\', $php).'" "'.str_replace('/', '\\', $worker).'" '.$args.' > NUL 2>&1';
		} else {
			$cmd = 'nohup '.escapeshellarg($php).' '.escapeshellarg($worker).' '.$args.' > /dev/null 2>&1 &';
		}
		$h = @popen($cmd, 'r');
		if(!$h){ $why = 'popen() failed'; return false; }
		@pclose($h);
		// Hand-shake: the worker writes the marker as its first action.
		for($i = 0; $i < 40; $i++){           // up to ~4 s
			if(is_file($marker)){ return true; }
			usleep(100000);
		}
		$why = 'worker did not start within 4 s';
		return false;
	}
}

if(!function_exists('mpqms_notify_final_result_dispatch')){
	/**
	 * Entry point used by save_update_details.php after the Final Result commit.
	 * Never throws. Returns 'background' or 'inline'.
	 */
	function mpqms_notify_final_result_dispatch($connection, $commondb, $rid, $rslt, $uid){
		$rid = (int)$rid;
		try {
			// The notifiers need no session data; release the lock so the user's next request
			// (or a logout) can never wait on, or be affected by, the sends below.
			if(function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE){ @session_write_close(); }
			@ignore_user_abort(true);

			$cfg = mpqms_mail_cfg();
			$background = !isset($cfg['notify_background']) ? true : (bool)$cfg['notify_background'];
			if($background){
				$why = '';
				if(mpqms_notify_spawn_worker($cfg, $rid, $rslt, $uid, $why)){
					mpqms_notify_log('reg '.$rid.' notifications handed to a background worker (independent of the user session)');
					return 'background';
				}
				mpqms_notify_log('reg '.$rid.' background worker unavailable ('.$why.') - sending inline');
			} else {
				mpqms_notify_log('reg '.$rid.' notify_background=false - sending inline');
			}
			@set_time_limit(0);
			mpqms_notify_final_result_inline($connection, $commondb, $rid, $rslt, $uid);
			return 'inline';
		} catch(\Throwable $e){
			mpqms_notify_log('reg '.$rid.' dispatch error: '.$e->getMessage());
			return 'inline';
		}
	}
}
