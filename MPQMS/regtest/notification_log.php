<?php
/*
 * MPQMS — Official notification log + duplicate-prevention (idempotency).
 *
 * A SEPARATE audit table (mpqms_notification_log) that records every BFDA MPD focal
 * notification for an approved final outcome: recipient, outcome, send status, delivery
 * status, error, attempt count and timestamp. It is also the idempotency store: one
 * official approved outcome -> one notification. Re-saving / refreshing / double-clicking
 * the SAME approved outcome does not re-send; a genuinely changed outcome (Complies <->
 * Non-complies) is a new official result and is allowed to notify; a previously FAILED
 * send may retry. Non-breaking: does not touch any existing table.
 */

if(!function_exists('mpqms_ensure_notification_log_table')){
	function mpqms_ensure_notification_log_table($conn){
		@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mpqms_notification_log (
			id INT AUTO_INCREMENT PRIMARY KEY,
			registration_id INT NOT NULL,
			sample_id VARCHAR(100) NULL,
			recipient VARCHAR(255) NULL,
			final_outcome VARCHAR(50) NULL,
			notification_type VARCHAR(50) NOT NULL DEFAULT 'FINAL_OUTCOME',
			notification_status VARCHAR(30) NOT NULL,      -- SENT | LOGGED_ONLY | QUEUED | FAILED | SKIPPED_DUPLICATE
			delivery_status VARCHAR(30) NULL,               -- highest-confidence delivery status (NULL when only 'Sent' is known)
			error_reason TEXT NULL,
			attempt_count INT NOT NULL DEFAULT 1,
			sent_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			INDEX idx_reg_outcome (registration_id, final_outcome),
			INDEX idx_reg (registration_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}
}

if(!function_exists('mpqms_notification_already_sent')){
	/**
	 * Idempotency check: has this registration's THIS approved outcome already been notified
	 * (a real send attempt that was not a hard failure)? Prevents duplicate emails on
	 * refresh / double-click / re-save while allowing a changed outcome or a retry-after-failure.
	 */
	function mpqms_notification_already_sent($conn, $regid, $outcome){
		mpqms_ensure_notification_log_table($conn);
		$regid = (int)$regid;
		$o = mysqli_real_escape_string($conn, (string)$outcome);
		$res = mysqli_query($conn, "SELECT COUNT(*) FROM mpqms_notification_log
			WHERE registration_id='$regid' AND final_outcome='$o'
			  AND notification_status IN ('SENT','LOGGED_ONLY','DELIVERED','QUEUED')");
		return $res ? ((int)mysqli_fetch_row($res)[0] > 0) : false;
	}
}

if(!function_exists('mpqms_log_notification')){
	/** Record one notification attempt (per recipient) for the audit trail / idempotency store. */
	function mpqms_log_notification($conn, $regid, $sample, $recipient, $outcome, $type, $status, $delivery, $error, $attempts){
		mpqms_ensure_notification_log_table($conn);
		$now = date('Y-m-d H:i:s');
		$e = function($v) use($conn){ return mysqli_real_escape_string($conn, (string)$v); };
		$sentAt = in_array(strtoupper((string)$status), array('SENT','LOGGED_ONLY','DELIVERED','QUEUED'), true) ? "'$now'" : "NULL";
		$deliverySql = ($delivery === null || $delivery === '') ? "NULL" : "'".$e($delivery)."'";
		$errorSql    = ($error === null || $error === '')       ? "NULL" : "'".$e($error)."'";
		return @mysqli_query($conn, "INSERT INTO mpqms_notification_log
			(registration_id, sample_id, recipient, final_outcome, notification_type, notification_status,
			 delivery_status, error_reason, attempt_count, sent_at, created_at)
			 VALUES ('".(int)$regid."', '".$e($sample)."', '".$e($recipient)."', '".$e($outcome)."', '".$e($type)."',
			         '".$e($status)."', $deliverySql, $errorSql, '".(int)$attempts."', $sentAt, '$now')");
	}
}
?>
