<?php
/*
 * MPQMS — Immutable report archive INDEX + integrity utilities.
 *
 * Long-term traceability layer for the read-only archived report PDFs. This is a
 * SEPARATE audit table (report_archive_index) — it does not touch registrationtable
 * or finalresulttable, so it is fully backward-compatible. Each archived report is
 * indexed with its client/test/batch identifiers, completion timestamp, storage URI,
 * SHA-256 hash, version and lock flag, so clients can retrieve reports by Client ID /
 * Batch Number and auditors can verify a file has not been tampered with.
 */

if(!function_exists('mpqms_ensure_report_index_table')){
	function mpqms_ensure_report_index_table($conn){
		@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS report_archive_index (
			id INT AUTO_INCREMENT PRIMARY KEY,
			registration_id INT NOT NULL,
			sample_id VARCHAR(100) NULL,
			client_id VARCHAR(100) NULL,
			batch_serial_number VARCHAR(150) NULL,
			final_result VARCHAR(50) NULL,
			completed_at DATETIME NOT NULL,
			pdf_storage_uri VARCHAR(500) NOT NULL,
			file_sha256_hash CHAR(64) NOT NULL,
			file_version INT NOT NULL DEFAULT 1,
			is_locked TINYINT(1) NOT NULL DEFAULT 1,
			archived_by VARCHAR(100) NULL,
			archived_at DATETIME NOT NULL,
			INDEX idx_client (client_id),
			INDEX idx_batch (batch_serial_number),
			INDEX idx_reg (registration_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}
}

if(!function_exists('mpqms_docroot_guess')){
	function mpqms_docroot_guess(){
		$d = $_SERVER['DOCUMENT_ROOT'] ?? '';
		// No usable DOCUMENT_ROOT (e.g. the command-line email worker): use this file's real location.
		if($d === '' || !is_dir($d.'/MPQMS')){ $d = dirname(dirname(__DIR__)); }
		return rtrim(str_replace('\\','/',$d), '/');
	}
}

if(!function_exists('mpqms_index_archived_report')){
	/**
	 * Record one archived (immutable) report in the DB index.
	 * @param mysqli $conn      ndtlisdb connection
	 * @param int    $rid       registrationID (test_id)
	 * @param string $absFile   absolute path to the archived PDF
	 * @param string $sha       SHA-256 hex hash of the archived PDF (computed at archive time)
	 * @param string $result    final result (Complies / Does not comply / …)
	 * @param string $by        username that approved/archived
	 */
	function mpqms_index_archived_report($conn, $rid, $absFile, $sha, $result, $by){
		mpqms_ensure_report_index_table($conn);
		$rid = (int)$rid;
		// Traceability metadata straight from the source record (read-only lookup).
		$meta = mysqli_fetch_row(mysqli_query($conn, "SELECT sampleID, batchNo, siteID FROM registrationtable WHERE registrationID='$rid'"));
		$sample = $meta[0] ?? '';
		$batch  = $meta[1] ?? '';
		$client = (string)($meta[2] ?? '');            // collection centre / site = the submitting client
		$bn  = basename($absFile);
		$ver = preg_match('/_v(\d+)\.pdf$/', $bn, $m) ? (int)$m[1] : 1;
		$uri = 'reports_archive/'.$bn;                 // relative to /MPQMS/
		$now = date('Y-m-d H:i:s');
		$e = function($v) use($conn){ return mysqli_real_escape_string($conn, (string)$v); };
		return @mysqli_query($conn, "INSERT INTO report_archive_index
			(registration_id, sample_id, client_id, batch_serial_number, final_result, completed_at,
			 pdf_storage_uri, file_sha256_hash, file_version, is_locked, archived_by, archived_at)
			 VALUES ('$rid', '".$e($sample)."', '".$e($client)."', '".$e($batch)."', '".$e($result)."', '$now',
			         '".$e($uri)."', '".$e($sha)."', '$ver', 1, '".$e($by)."', '$now')");
	}
}

if(!function_exists('mpqms_verify_report_integrity')){
	/**
	 * Recalculate the SHA-256 of the stored PDF and compare it to the indexed hash.
	 * Proves the archived file has not been altered since creation (client-audit utility).
	 * @param array $row  a report_archive_index row (needs pdf_storage_uri + file_sha256_hash)
	 * @return array {ok, status: VERIFIED|TAMPERED|MISSING, stored_hash, live_hash}
	 */
	function mpqms_verify_report_integrity($row){
		$abs = mpqms_docroot_guess().'/MPQMS/'.$row['pdf_storage_uri'];
		if(!is_file($abs)){
			return array('ok'=>false, 'status'=>'MISSING', 'stored_hash'=>$row['file_sha256_hash'], 'live_hash'=>null, 'message'=>'Archived PDF not found at the recorded URI.');
		}
		$live  = hash_file('sha256', $abs);
		$match = is_string($live) && hash_equals((string)$row['file_sha256_hash'], $live);
		return array('ok'=>$match, 'status'=>$match?'VERIFIED':'TAMPERED', 'stored_hash'=>$row['file_sha256_hash'], 'live_hash'=>$live);
	}
}
?>
