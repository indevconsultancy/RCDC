<?php
/*
 * MPQMS shared mail delivery engine.
 * ---------------------------------------------------------------------------
 * Production-ready, dependency-free SMTP sender (TLS/SSL/AUTH LOGIN) with
 * connection timeout, automatic retries, an on-disk retry queue, and error
 * logging.  All settings come from config/mail_config.php (which itself reads
 * environment variables), so no credentials are hardcoded.
 *
 * Public API:
 *   mpqms_mail_cfg()                                  -> resolved config array
 *   mpqms_send_mail($to, $subject, $body, $opts=[])   -> ['delivered'=>bool,'status'=>..,'error'=>..,'attempts'=>n,
 *                                                          'recipients'=>[], 'accepted'=>[], 'rejected'=>[addr=>reason]]
 *     $to may be a single address OR an array of addresses. An array is delivered as ONE message
 *     in ONE SMTP transaction (one MAIL FROM, several RCPT TO), never as one message per address.
 *     A recipient the server rejects is recorded in 'rejected' and the message is still delivered
 *     to the rest, so a bad address cannot suppress or duplicate the notification.
 *   mpqms_mail_flush_queue($max = 25)                 -> retries queued messages (cron / opportunistic)
 *
 * $opts keys: cc, high_priority(bool), request_receipt(bool), reply_to,
 *             context(string, for logs), no_queue(bool)
 */

if(!function_exists('mpqms_mail_cfg')){
	function mpqms_mail_cfg(){
		$cfg = @include(dirname(__DIR__).'/config/mail_config.php');
		if(!is_array($cfg)){ $cfg = array(); }
		return $cfg;
	}
}

if(!function_exists('mpqms_mail_dir')){
	function mpqms_mail_dir($sub){
		$d = dirname(__DIR__).'/email/'.$sub;
		if(!is_dir($d)){ @mkdir($d, 0775, true); }
		return $d;
	}
}

if(!function_exists('mpqms_mail_log')){
	function mpqms_mail_log($line){
		@file_put_contents(mpqms_mail_dir('').'/mail_error.log', '['.date('Y-m-d H:i:s').'] '.$line."\n", FILE_APPEND);
	}
}

/* -------------------------------------------------------------------------
 * ONE aggregated summary line per email transaction.
 *
 * Every notifier finishes a transaction by writing exactly one of these, listing the
 * record, the notification type, who was attempted, who failed and why. It replaces the
 * old per-recipient log lines, so a notification to fourteen people that three of them
 * reject produces ONE failure record, not three. This only writes to the log file — it
 * never sends mail, so a failure can never cause another email.
 * ---------------------------------------------------------------------- */
if(!function_exists('mpqms_mail_transaction_summary')){
	/**
	 * @param array $t record, type, attempted[], accepted[], failed[addr=>reason], result[], extra
	 * @return string one-line summary, safe for the log (never contains credentials)
	 */
	function mpqms_mail_transaction_summary($t){
		$attempted = isset($t['attempted']) ? (array)$t['attempted'] : array();
		$accepted  = isset($t['accepted'])  ? (array)$t['accepted']  : array();
		$failed    = isset($t['failed'])    ? (array)$t['failed']    : array();
		$res       = isset($t['result'])    ? (array)$t['result']    : array();

		if(($res['transport'] ?? '') === 'log'){ $final = 'LOGGED_ONLY'; }
		else if(empty($accepted))             { $final = 'FAILED'; }
		else if(!empty($failed))              { $final = 'PARTIAL_FAILURE'; }
		else                                  { $final = 'SUCCESS'; }

		$line = 'TRANSACTION '.$final
		      . ' | record='.(string)($t['record'] ?? '?')
		      . ' | type='.(string)($t['type'] ?? '?')
		      . ' | attempted='.count($attempted).' ['.implode(', ', $attempted).']'
		      . ' | delivered='.count($accepted)
		      . ' | failed='.count($failed);
		if(!empty($failed)){
			$parts = array();
			foreach($failed as $addr => $why){ $parts[] = $addr.' -> '.preg_replace('/\s+/', ' ', trim((string)$why)); }
			$line .= ' ['.implode('; ', $parts).']';
		}
		$line .= ' | transport='.(string)($res['transport'] ?? '?')
		       . ' | attempts='.(int)($res['attempts'] ?? 0)
		       . ' | at='.date('Y-m-d H:i:s');
		if(!empty($t['extra'])){ $line .= ' | '.$t['extra']; }
		return $line;
	}
}

if(!function_exists('mpqms_mime_header')){
	function mpqms_mime_header($s){
		return preg_match('/[\x80-\xff]/', $s) ? '=?UTF-8?B?'.base64_encode($s).'?=' : $s;
	}
}

/* -------------------------------------------------------------------------
 * Low-level SMTP conversation. Returns true on a 250 for the final DATA.
 * ---------------------------------------------------------------------- */
if(!function_exists('mpqms_smtp_deliver')){
	/**
	 * One SMTP conversation for one message.
	 * @param string|array $to        primary recipient(s)
	 * @param array        $ccList    copy recipient(s)
	 * @param array|null   $rejected  OUT: address => rejection reason, for recipients the server refused
	 * @return bool true when the message was accepted for at least one recipient
	 */
	function mpqms_smtp_deliver($smtp, $from, $fromName, $to, $ccList, $subject, $body, $extraHeaders, &$err, $attachments = array(), &$rejected = null){
		$host    = $smtp['host'] ?? '';
		$port    = (int)($smtp['port'] ?? 587);
		$enc     = strtolower($smtp['encryption'] ?? 'tls');   // tls | ssl | none
		$timeout = (int)($smtp['timeout'] ?? 15);
		$err     = '';

		if($host === ''){ $err = 'no SMTP host configured'; return false; }

		// Connect over IPv4. The mail host also publishes an IPv6 address; when the OS orders
		// that first and the IPv6 route is unreliable, PHP spends the whole connect timeout on
		// it and then gives up (intermittent "10060" timeouts), even though IPv4 answers at
		// once. The hostname is kept for TLS/SNI; if no A record exists the hostname is used.
		$connectHost = $host;
		if(filter_var($host, FILTER_VALIDATE_IP) === false){
			$v4 = @gethostbyname($host);
			if($v4 !== $host && filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)){ $connectHost = $v4; }
		}
		$remote = ($enc === 'ssl' ? 'ssl://' : '').$connectHost.':'.$port;
		$ctx = stream_context_create(array('ssl'=>array('verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true,'peer_name'=>$host,'SNI_enabled'=>true)));
		$fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
		if(!$fp){ $err = "connect failed to $remote ($host): $errstr ($errno)"; return false; }
		stream_set_timeout($fp, $timeout);

		$read = function() use ($fp, &$err){
			$data = '';
			while(($line = fgets($fp, 515)) !== false){
				$data .= $line;
				$meta = stream_get_meta_data($fp);
				if(!empty($meta['timed_out'])){ $err = 'read timeout'; return $data; }
				// final line of a (possibly multi-line) reply has a space at index 3
				if(strlen($line) < 4 || $line[3] === ' '){ break; }
			}
			return $data;
		};
		$cmd = function($c) use ($fp, $read){ if($c !== null){ fwrite($fp, $c."\r\n"); } return $read(); };
		$code = function($r){ return substr(ltrim($r), 0, 3); };

		$r = $read();
		if($code($r) !== '220'){ $err = 'greeting: '.trim($r); fclose($fp); return false; }

		$ehloName = $_SERVER['SERVER_NAME'] ?? (gethostname() ?: 'localhost');
		$r = $cmd("EHLO ".$ehloName);
		if($code($r) !== '250'){
			$r = $cmd("HELO ".$ehloName);
			if($code($r) !== '250'){ $err = 'EHLO/HELO: '.trim($r); fclose($fp); return false; }
		}

		if($enc === 'tls'){
			$r = $cmd("STARTTLS");
			if($code($r) !== '220'){ $err = 'STARTTLS: '.trim($r); fclose($fp); return false; }
			$ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
				| (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT : 0)
				| (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0));
			if($ok !== true){ $err = 'TLS negotiation failed'; fclose($fp); return false; }
			$r = $cmd("EHLO ".$ehloName);
			if($code($r) !== '250'){ $err = 'EHLO after TLS: '.trim($r); fclose($fp); return false; }
		}

		if(!empty($smtp['auth']) && ($smtp['username'] ?? '') !== ''){
			$r = $cmd("AUTH LOGIN");
			if($code($r) !== '334'){ $err = 'AUTH LOGIN: '.trim($r); fclose($fp); return false; }
			$r = $cmd(base64_encode($smtp['username']));
			if($code($r) !== '334'){ $err = 'AUTH user: '.trim($r); fclose($fp); return false; }
			$r = $cmd(base64_encode($smtp['password'] ?? ''));
			if($code($r) !== '235'){ $err = 'auth failed: '.trim($r); fclose($fp); return false; }
		}

		$r = $cmd("MAIL FROM:<$from>");
		if($code($r) !== '250'){ $err = 'MAIL FROM: '.trim($r); fclose($fp); return false; }

		// Offer every recipient in this one transaction. A refusal of one address is recorded and
		// the conversation continues, so the remaining recipients still receive the message; only
		// when EVERY address is refused does the send fail.
		$rejected = is_array($rejected) ? $rejected : array();
		$toList = is_array($to) ? $to : array($to);
		$ccL    = is_array($ccList) ? $ccList : array();
		$acceptedTo = array(); $acceptedCc = array(); $seenRcpt = array();
		foreach(array(array('to', $toList), array('cc', $ccL)) as $grp){
			foreach($grp[1] as $rc){
				$rc = trim((string)$rc); if($rc === ''){ continue; }
				$k = strtolower($rc); if(isset($seenRcpt[$k])){ continue; } $seenRcpt[$k] = true;
				$r = $cmd("RCPT TO:<$rc>");
				if(in_array($code($r), array('250','251'), true)){
					if($grp[0] === 'to'){ $acceptedTo[] = $rc; } else { $acceptedCc[] = $rc; }
				} else {
					$rejected[$rc] = trim($r);
				}
			}
		}
		if(empty($acceptedTo) && empty($acceptedCc)){
			$err = 'all recipients refused by the server'.($rejected ? ': '.implode('; ', array_map(function($a,$m){ return $a.' -> '.$m; }, array_keys($rejected), array_values($rejected))) : '');
			$cmd("RSET"); fclose($fp); return false;
		}
		// The header addresses must match what the server actually accepted.
		$to = $acceptedTo ? $acceptedTo[0] : ($acceptedCc ? $acceptedCc[0] : '');
		$hdrTo = $acceptedTo ? $acceptedTo : array($to);
		$ccList = $acceptedCc;

		$r = $cmd("DATA");
		if($code($r) !== '354'){ $err = 'DATA: '.trim($r); fclose($fp); return false; }

		$headers  = "Date: ".date('r')."\r\n";
		$headers .= "Message-ID: <".date('YmdHis').".".bin2hex(random_bytes(6))."@".$ehloName.">\r\n";
		$headers .= "From: ".mpqms_mime_header($fromName)." <$from>\r\n";
		$headers .= "To: ".implode(', ', array_map(function($a){ return '<'.trim($a).'>'; }, $hdrTo))."\r\n";
		if(!empty($ccList)){
			$headers .= "Cc: ".implode(', ', array_map(function($c){ return '<'.trim($c).'>'; }, $ccList))."\r\n";
		}
		$headers .= "Subject: ".mpqms_mime_header($subject)."\r\n";
		$headers .= "MIME-Version: 1.0\r\n";

		// Normalise to CRLF and dot-stuff (RFC 5321).
		$normalise = function($t){
			$t = str_replace(array("\r\n", "\r", "\n"), "\n", $t);
			$t = str_replace("\n", "\r\n", $t);
			return preg_replace('/(^|\r\n)\./', '$1..', $t);
		};

		// Optional file attachments. Unreadable/oversized entries are skipped silently
		// so a missing file can never stop the message itself from going out.
		$files = array();
		foreach((array)$attachments as $a){
			$path = is_array($a) ? ($a['path'] ?? '') : $a;
			if($path === '' || !is_file($path) || !is_readable($path) || filesize($path) > 10485760){ continue; }
			$data = @file_get_contents($path);
			if($data === false || $data === ''){ continue; }
			$files[] = array(
				'name' => preg_replace('/[^A-Za-z0-9._-]+/', '_', (is_array($a) && !empty($a['name'])) ? $a['name'] : basename($path)),
				'mime' => (is_array($a) && !empty($a['mime'])) ? $a['mime'] : 'application/octet-stream',
				'data' => $data
			);
		}

		if($files){
			$bnd = '=_MPQMS_'.bin2hex(random_bytes(12));
			$headers .= "Content-Type: multipart/mixed; boundary=\"$bnd\"\r\n";
			$headers .= $extraHeaders;
			$msgBody  = "This is a multi-part message in MIME format.\r\n\r\n";
			$msgBody .= "--$bnd\r\nContent-Type: text/plain; charset=UTF-8\r\n"
			          . "Content-Transfer-Encoding: 8bit\r\n\r\n".$normalise($body)."\r\n";
			foreach($files as $f){
				$msgBody .= "--$bnd\r\nContent-Type: ".$f['mime']."; name=\"".$f['name']."\"\r\n"
				          . "Content-Transfer-Encoding: base64\r\n"
				          . "Content-Disposition: attachment; filename=\"".$f['name']."\"\r\n\r\n"
				          . chunk_split(base64_encode($f['data']), 76, "\r\n");
			}
			$msgBody .= "--$bnd--\r\n";
		} else {
			$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
			$headers .= "Content-Transfer-Encoding: 8bit\r\n";
			$headers .= $extraHeaders;
			$msgBody  = $normalise($body);
		}

		fwrite($fp, $headers."\r\n".$msgBody."\r\n.\r\n");
		$r = $read();
		if($code($r) !== '250'){ $err = 'message rejected: '.trim($r); fclose($fp); return false; }

		@fwrite($fp, "QUIT\r\n"); @fclose($fp);
		return true;
	}
}

/* -------------------------------------------------------------------------
 * High-level send: applies method/retries/queue and returns a status array.
 * ---------------------------------------------------------------------- */
if(!function_exists('mpqms_send_mail')){
	function mpqms_send_mail($to, $subject, $body, $opts = array()){
		$cfg = mpqms_mail_cfg();

		// Normalise the recipient set. $to may be one address or many; the whole set travels in
		// ONE message. Syntactically invalid addresses are rejected up-front (never offered to the
		// server) and reported alongside the addresses the server itself refuses.
		$rawTo    = is_array($to) ? $to : array($to);
		$valid    = array(); $rejected = array(); $seen = array();
		foreach($rawTo as $a){
			$a = trim((string)$a);
			if($a === ''){ continue; }
			$k = strtolower($a);
			if(isset($seen[$k])){ continue; }
			$seen[$k] = true;
			if(filter_var($a, FILTER_VALIDATE_EMAIL)){ $valid[] = $a; }
			else { $rejected[$a] = 'invalid address format'; }
		}
		$to = $valid ? $valid[0] : '';            // kept for logs and the legacy return shape

		$ccList = array();
		if(!empty($opts['cc']))            { $ccList[] = $opts['cc']; }
		if(!empty($cfg['cc_email']))       { $ccList[] = $cfg['cc_email']; }

		$from     = $cfg['from_email'] ?? 'noreply@localhost';
		$fromName = $cfg['from_name'] ?? 'MPQMS';
		$method   = strtolower($cfg['method'] ?? 'smtp');
		$smtp     = $cfg['smtp'] ?? array();
		$hostSet  = trim($smtp['host'] ?? '') !== '';

		// Extra headers (priority / read-receipt / reply-to)
		$extra = '';
		if(!empty($opts['high_priority'])){
			$extra .= "X-Priority: 1 (Highest)\r\nImportance: High\r\nX-MSMail-Priority: High\r\n";
		}
		if(!empty($opts['request_receipt']) || !empty($cfg['request_receipt'])){
			$extra .= "Disposition-Notification-To: <$from>\r\nReturn-Receipt-To: <$from>\r\n";
		}
		if(!empty($opts['reply_to'])){ $extra .= "Reply-To: <".trim($opts['reply_to']).">\r\n"; }

		$masterOn = !isset($cfg['enabled']) ? true : (bool)$cfg['enabled'];

		// Decide the active transport.
		$transport = 'log';
		if($masterOn && !empty($valid)){
			if($method === 'smtp' && $hostSet)      { $transport = 'smtp'; }
			else if($method === 'mail')             { $transport = 'mail'; }
		}

		// Attachment paths (optional). Kept as paths so a queued retry can re-read them.
		$atts = isset($opts['attachments']) ? (array)$opts['attachments'] : array();

		$delivered = false; $error = ''; $attempts = 0;

		if($transport === 'smtp'){
			// Callers on a user-facing request path (e.g. the non-compliance alert fired
			// during an approval save) may cap the immediate attempts and the connect
			// timeout so a dead mail server cannot stall the page. Anything not delivered
			// still lands in the retry queue and is drained by mail_queue.php.
			$maxRetries = max(1, (int)($opts['max_retries'] ?? ($cfg['max_retries'] ?? 3)));
			if(isset($opts['timeout'])){ $smtp['timeout'] = max(1, (int)$opts['timeout']); }
			// CONTROLLED RETRY POLICY. A retry re-sends the whole message, so it is only safe while
			// NOTHING has been accepted yet (a connection/greeting/auth failure). As soon as the
			// server has accepted the message for at least one recipient the attempt loop stops,
			// even if other recipients were refused — retrying would deliver a duplicate.
			for($attempts = 1; $attempts <= $maxRetries; $attempts++){
				$e = ''; $srvRejected = array();
				$delivered = mpqms_smtp_deliver($smtp, $from, $fromName, $valid, $ccList, $subject, $body, $extra, $e, $atts, $srvRejected);
				if($delivered){ break; }
				$error = $e;
				// Every address refused is a permanent verdict, not a transport fault: do not retry.
				if(!empty($srvRejected) && count($srvRejected) >= count($valid)){ break; }
				mpqms_mail_log("attempt $attempts/$maxRetries to ".implode(', ', $valid)." failed: $e".(!empty($opts['context'])?" [".$opts['context']."]":''));
				if($attempts < $maxRetries){ usleep(400000 * $attempts); }   // linear backoff
			}
			foreach($srvRejected as $addr => $why){ $rejected[$addr] = $why; }
			$accepted = array();
			foreach($valid as $a){ if(!isset($rejected[$a])){ $accepted[] = $a; } }
			// Queue for a later attempt ONLY when nothing was accepted and the failure was a
			// transport fault. A message already accepted for someone is never re-queued, and a
			// permanently refused address is never retried.
			if(!$delivered && empty($srvRejected) && empty($opts['no_queue']) && !empty($cfg['retry_queue'])){
				mpqms_mail_enqueue(array(
					'to'=>$valid, 'cc'=>$ccList, 'subject'=>$subject, 'body'=>$body,
					'extra'=>$extra, 'from'=>$from, 'fromName'=>$fromName,
					'context'=>$opts['context'] ?? '', 'attachments'=>$atts, 'queued_at'=>date('Y-m-d H:i:s'), 'tries'=>$attempts
				));
			}
		}
		else if($transport === 'mail'){
			$attempts = 1;
			$hdr = "From: ".mpqms_mime_header($fromName)." <$from>\r\n";
			if(!empty($ccList)){ $hdr .= "Cc: ".implode(', ', $ccList)."\r\n"; }
			$hdr .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n".$extra;
			$delivered = @mail($to, $subject, $body, $hdr);
			if(!$delivered){ $error = 'mail() returned false (no MTA?)'; }
		}
		else {
			$attempts = 0; $error = empty($valid) ? 'no valid recipient' : 'email disabled / not configured';
		}
		if(!isset($accepted)){ $accepted = $delivered ? $valid : array(); }

		// When a genuine send succeeds, opportunistically drain any backlog.
		if($delivered && $transport === 'smtp'){ @mpqms_mail_flush_queue(10); }

		// One outcome for one transaction: DELIVERED (everyone accepted), PARTIAL (some accepted,
		// some refused), or FAILED (nobody accepted).
		if($transport === 'log'){ $status = 'LOGGED_ONLY'; }
		else if($delivered){ $status = empty($rejected) ? 'DELIVERED' : 'PARTIAL'; }
		else { $status = 'FAILED'.(!empty($cfg['retry_queue']) && $transport === 'smtp' && empty($rejected) ? ' (queued for retry)' : ''); }
		if($status === 'PARTIAL' && $error === ''){
			$error = 'refused for '.count($rejected).' of '.count($valid).' recipient(s)';
		}

		return array('delivered'=>$delivered, 'status'=>$status, 'error'=>$error, 'attempts'=>$attempts,
		             'transport'=>$transport, 'to'=>implode(', ', $valid),
		             'recipients'=>$valid, 'accepted'=>$accepted, 'rejected'=>$rejected);
	}
}

if(!function_exists('mpqms_mail_enqueue')){
	function mpqms_mail_enqueue($item){
		$dir = mpqms_mail_dir('queue');
		@file_put_contents($dir.'/'.date('YmdHis').'_'.bin2hex(random_bytes(4)).'.json', json_encode($item));
	}
}

if(!function_exists('mpqms_mail_flush_queue')){
	// Retry queued messages. Successful ones are removed; permanently failing
	// ones (after ~20 total tries) are moved to email/failed/ so the queue drains.
	function mpqms_mail_flush_queue($max = 25){
		$cfg = mpqms_mail_cfg();
		$smtp = $cfg['smtp'] ?? array();
		if(trim($smtp['host'] ?? '') === '' || !((bool)($cfg['enabled'] ?? true))){ return array('processed'=>0,'delivered'=>0); }

		$dir = mpqms_mail_dir('queue');
		$files = glob($dir.'/*.json');
		if(!$files){ return array('processed'=>0,'delivered'=>0); }
		sort($files);
		$processed = 0; $delivered = 0;
		$from = $cfg['from_email'] ?? 'noreply@localhost';
		$fromName = $cfg['from_name'] ?? 'MPQMS';

		foreach($files as $f){
			if($processed >= $max){ break; }
			$processed++;
			$item = json_decode(@file_get_contents($f), true);
			if(!is_array($item)){ @unlink($f); continue; }
			$e = '';
			// A queued item's recipient may be a single address (older files) or the whole set.
			$qRejected = array();
			$qTo = is_array($item['to']) ? implode(', ', $item['to']) : (string)$item['to'];
			$ok = mpqms_smtp_deliver($smtp, $item['from'] ?? $from, $item['fromName'] ?? $fromName,
				$item['to'], $item['cc'] ?? array(), $item['subject'], $item['body'], $item['extra'] ?? '', $e, $item['attachments'] ?? array(), $qRejected);
			if(!$ok && !empty($qRejected)){ $e = trim($e.' [refused: '.implode('; ', array_keys($qRejected)).']'); }
			if($ok){
				$delivered++;
				@unlink($f);
				mpqms_mail_log("queue: delivered to ".$qTo.(!empty($item['context'])?" [".$item['context']."]":''));
			} else {
				$item['tries'] = (int)($item['tries'] ?? 0) + 1;
				if($item['tries'] >= 20){
					@rename($f, mpqms_mail_dir('failed').'/'.basename($f));
					mpqms_mail_log("queue: giving up on ".$qTo." after ".$item['tries']." tries: $e");
				} else {
					@file_put_contents($f, json_encode($item));
					mpqms_mail_log("queue: retry to ".$qTo." failed (try ".$item['tries']."): $e");
				}
			}
		}
		return array('processed'=>$processed, 'delivered'=>$delivered);
	}
}
?>
