<?php
/*
 * MPQMS — background notification worker (CLI only).
 *
 * Started detached by includes/notify_dispatch.php right after a Final Result has been
 * committed:   php regtest/notify_worker.php <registrationID> <finalResult> <username> <token>
 *
 * It has no web session and does not need one: it opens its own database connections
 * (config/db_config.php), runs the same three notifiers the web request used to run inline
 * (outcome notice, non-compliance alert, official final outcome — recipients resolved from
 * the sample and the common user tables), then drains the mail retry queue. Its first action
 * is to write a start marker so the dispatcher knows the hand-over succeeded.
 */
if(PHP_SAPI !== 'cli'){ http_response_code(403); exit('Forbidden'); }

$rid   = (int)($argv[1] ?? 0);
$rslt  = (string)($argv[2] ?? '');
$uid   = (string)($argv[3] ?? '');
$token = preg_replace('/[^a-f0-9]/', '', (string)($argv[4] ?? ''));

require_once(dirname(__DIR__).'/includes/mailer.php');
$marker = ($token !== '') ? mpqms_mail_dir('dispatch').'/'.$token.'.started' : null;
if($marker){ @file_put_contents($marker, date('Y-m-d H:i:s').' pid='.getmypid().' reg='.$rid); }

set_time_limit(0);
ignore_user_abort(true);
ini_set('display_errors', '0');

try {
	if($rid <= 0){ throw new \RuntimeException('invalid registrationID'); }
	include_once(dirname(__DIR__).'/config/db_config.php');       // $connection (ndtlisdb) + $commondb
	require_once(dirname(__DIR__).'/includes/notify_dispatch.php');
	mpqms_notify_log('reg '.$rid.' background worker started (pid '.getmypid().')');
	mpqms_notify_final_result_inline($connection, $commondb, $rid, $rslt, $uid);
	$q = @mpqms_mail_flush_queue(200);
	mpqms_notify_log('reg '.$rid.' background worker finished'.(is_array($q) && $q['processed'] ? ' (queue: processed '.$q['processed'].', delivered '.$q['delivered'].')' : ''));
} catch(\Throwable $e){
	mpqms_notify_log('reg '.$rid.' background worker error: '.$e->getMessage());
}
if($marker){ @unlink($marker); }
