<?php
/*
 * MPQMS — Official Analytical Report archive (write-once, read-only).
 *
 * When a sample becomes FULLY COMPLETED (Sample Accepted + every lab result Approved +
 * Final Result saved) the official Analytical Report PDF is rendered once, stored in the
 * protected /storage/reports/YYYY/MM/ tree and indexed in `analytical_report_archive`
 * with its SHA-256 hash, for long-term traceability and future client reference.
 *
 * Immutability guarantees:
 *   - `analytical_report_archive.registrationID` is UNIQUE, so a second archive attempt
 *     for the same registration can never insert.
 *   - The archiver returns early when a record already exists — the stored PDF is never
 *     regenerated, overwritten or deleted.
 *   - The stored file is chmod'ed read-only after indexing.
 *
 * This module is completely ADDITIVE. It does not modify, replace or interfere with the
 * on-the-fly download (pdf_report.php) or the pre-existing reports_archive/ snapshots —
 * both keep working exactly as before. All queries use prepared statements, and every
 * database call is wrapped so a missing table or a failed archive can never break a
 * caller (mysqli runs in exception mode on this stack).
 */

require_once(__DIR__."/report_lib.php");   // mpqms_docroot() + mpqms_render_report()

if(!function_exists('mpqms_official_base_dir')){
	/* Absolute path of the protected archive root: <MPQMS>/storage/reports */
	function mpqms_official_base_dir(){
		return mpqms_docroot().'/MPQMS/storage/reports';
	}
}

if(!function_exists('mpqms_official_protect_dir')){
	/* Drop a deny-all .htaccess so the PDFs are never served directly over HTTP. */
	function mpqms_official_protect_dir($dir){
		$ht = rtrim($dir, '/').'/.htaccess';
		if(!is_file($ht)){
			@file_put_contents($ht,
				"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n".
				"<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n");
		}
	}
}

if(!function_exists('mpqms_official_ensure_table')){
	/* Create the archive table on first use. Called from the write path only. */
	function mpqms_official_ensure_table($conn){
		try {
			mysqli_query($conn, "CREATE TABLE IF NOT EXISTS analytical_report_archive (
				id INT AUTO_INCREMENT PRIMARY KEY,
				registrationID INT NOT NULL,
				sampleID VARCHAR(50) NULL,
				file_path VARCHAR(500) NOT NULL,
				file_hash CHAR(64) NOT NULL,
				generated_at DATETIME NOT NULL,
				generated_by VARCHAR(100) NULL,
				is_official TINYINT(1) NOT NULL DEFAULT 1,
				UNIQUE KEY uq_registration (registrationID),
				KEY idx_sample (sampleID)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			return true;
		} catch(\Throwable $e){ return false; }
	}
}

if(!function_exists('mpqms_sample_fully_completed')){
	/**
	 * A sample is "fully completed" when ALL THREE hold:
	 *   1. samplestatustable.status = 'Accepted'
	 *   2. it has at least one lab result and EVERY one of them is approval='Approved'
	 *   3. a non-empty Final Result has been saved in finalresulttable
	 * Mirrors the status logic already used by the manager list (manager_lib.php).
	 * Read-only; returns false on any error.
	 */
	function mpqms_sample_fully_completed($conn, $rid){
		$rid = (int)$rid;
		if($rid <= 0){ return false; }
		try {
			// 1. Sample Accepted
			$st = mysqli_prepare($conn, "SELECT status FROM samplestatustable WHERE registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $rid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $status);
			$has = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			if(!$has || trim((string)$status) !== 'Accepted'){ return false; }

			// 2. Every recorded lab result approved (and at least one exists)
			$st = mysqli_prepare($conn, "SELECT COUNT(*), SUM(approval='Approved') FROM labresulttable WHERE registrationID=?");
			mysqli_stmt_bind_param($st, 'i', $rid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $total, $approved);
			mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			if((int)$total < 1 || (int)$total !== (int)$approved){ return false; }

			// 3. Final Result saved
			$st = mysqli_prepare($conn, "SELECT result FROM finalresulttable WHERE registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $rid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $final);
			$has = mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			if(!$has || trim((string)$final) === ''){ return false; }

			return true;
		} catch(\Throwable $e){ return false; }
	}
}

if(!function_exists('mpqms_official_report_record')){
	/**
	 * The archive record for one registration, or null when none exists / table absent.
	 * @return array|null {id, registrationID, sampleID, file_path, file_hash, generated_at, generated_by, is_official}
	 */
	function mpqms_official_report_record($conn, $rid){
		$rid = (int)$rid;
		if($rid <= 0){ return null; }
		try {
			$st = mysqli_prepare($conn, "SELECT id, registrationID, sampleID, file_path, file_hash, generated_at, generated_by, is_official
				FROM analytical_report_archive WHERE registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $rid);
			mysqli_stmt_execute($st);
			$res = mysqli_stmt_get_result($st);
			$row = $res ? mysqli_fetch_assoc($res) : null;
			mysqli_stmt_close($st);
			return $row ? $row : null;
		} catch(\Throwable $e){ return null; }   // table not created yet -> behave as "no official report"
	}
}

if(!function_exists('mpqms_official_report_map')){
	/**
	 * Batched existence lookup for a page of registrations (avoids N+1 in the manager list).
	 * @param  array $rids registration IDs
	 * @return array       [registrationID => true] for those that have an official report
	 */
	function mpqms_official_report_map($conn, $rids){
		$map = array();
		$ids = array();
		foreach((array)$rids as $r){ $r = (int)$r; if($r > 0){ $ids[$r] = $r; } }
		if(empty($ids)){ return $map; }
		try {
			$ph   = implode(',', array_fill(0, count($ids), '?'));
			$st   = mysqli_prepare($conn, "SELECT registrationID FROM analytical_report_archive WHERE registrationID IN ($ph)");
			$vals = array_values($ids);
			mysqli_stmt_bind_param($st, str_repeat('i', count($vals)), ...$vals);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $got);
			while(mysqli_stmt_fetch($st)){ $map[(int)$got] = true; }
			mysqli_stmt_close($st);
		} catch(\Throwable $e){ return array(); }   // table not created yet -> no official reports
		return $map;
	}
}

if(!function_exists('mpqms_official_abs_path')){
	/**
	 * Resolve a stored relative file_path to an absolute path, refusing anything that
	 * escapes the archive root (defence in depth for the download endpoint).
	 * @return string|null
	 */
	function mpqms_official_abs_path($relPath){
		$rel  = str_replace('\\', '/', (string)$relPath);
		$base = mpqms_official_base_dir();
		$abs  = (strpos($rel, 'storage/reports/') === 0)
			? mpqms_docroot().'/MPQMS/'.$rel
			: rtrim($base, '/').'/'.ltrim($rel, '/');
		$realAbs  = realpath($abs);
		$realBase = realpath($base);
		if($realAbs === false || $realBase === false){ return null; }
		$realAbs  = str_replace('\\', '/', $realAbs);
		$realBase = rtrim(str_replace('\\', '/', $realBase), '/');
		if(strpos($realAbs, $realBase.'/') !== 0){ return null; }   // outside the archive root
		return $realAbs;
	}
}

if(!function_exists('mpqms_official_archive')){
	/**
	 * Generate + store + hash + index the official report for a fully completed sample.
	 * Write-once: if a record already exists for $rid, nothing at all happens.
	 *
	 * @param  mysqli $conn     ndtlisdb connection
	 * @param  mysqli $commondb commondb connection (needed by the report renderer)
	 * @param  int    $rid      registrationID
	 * @param  string $user     username performing the save
	 * @return array  {status: created|exists|not_completed|error, ...}
	 */
	function mpqms_official_archive($conn, $commondb, $rid, $user){
		$rid = (int)$rid;
		if($rid <= 0){ return array('status'=>'error', 'message'=>'invalid registrationID'); }

		if(!mpqms_official_ensure_table($conn)){
			return array('status'=>'error', 'message'=>'archive table unavailable');
		}
		// Only fully completed samples get an official report.
		if(!mpqms_sample_fully_completed($conn, $rid)){
			return array('status'=>'not_completed');
		}
		// Never overwrite: an existing record ends the operation immediately.
		if(mpqms_official_report_record($conn, $rid) !== null){
			return array('status'=>'exists');
		}

		$file = null;
		try {
			// Sample ID for the filename (read-only lookup).
			$st = mysqli_prepare($conn, "SELECT sampleID FROM registrationtable WHERE registrationID=? LIMIT 1");
			mysqli_stmt_bind_param($st, 'i', $rid);
			mysqli_stmt_execute($st);
			mysqli_stmt_bind_result($st, $sampleID);
			mysqli_stmt_fetch($st);
			mysqli_stmt_close($st);
			$sampleID = (string)$sampleID;

			$ts   = time();
			$base = mpqms_official_base_dir();
			$dir  = $base.'/'.date('Y', $ts).'/'.date('m', $ts);
			if(!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)){
				throw new \Exception('cannot create storage directory: '.$dir);
			}
			mpqms_official_protect_dir($base);

			// /storage/reports/YYYY/MM/{SampleID}_{registrationID}_{timestamp}.pdf
			$safeSid = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sampleID);
			if($safeSid === '' || $safeSid === null){ $safeSid = 'SAMPLE'; }
			$safeSid = substr($safeSid, 0, 60);
			$name = $safeSid.'_'.$rid.'_'.date('YmdHis', $ts).'.pdf';
			$file = $dir.'/'.$name;
			// Paranoia: an already-present file is never touched.
			if(file_exists($file)){
				$name = $safeSid.'_'.$rid.'_'.date('YmdHis', $ts).'-'.substr(md5(uniqid('', true)), 0, 6).'.pdf';
				$file = $dir.'/'.$name;
			}

			// Same renderer, same layout as pdf_report.php — written to disk instead of the browser.
			mpqms_render_report($rid, $conn, $commondb, 'F', $file);
			if(!is_file($file) || filesize($file) <= 0){
				throw new \Exception('generated PDF missing or empty: '.$file);
			}

			$hash = hash_file('sha256', $file);
			$rel  = 'storage/reports/'.date('Y', $ts).'/'.date('m', $ts).'/'.$name;
			$now  = date('Y-m-d H:i:s', $ts);
			$by   = (string)$user;

			$st = mysqli_prepare($conn, "INSERT INTO analytical_report_archive
				(registrationID, sampleID, file_path, file_hash, generated_at, generated_by, is_official)
				VALUES (?, ?, ?, ?, ?, ?, 1)");
			mysqli_stmt_bind_param($st, 'isssss', $rid, $sampleID, $rel, $hash, $now, $by);
			mysqli_stmt_execute($st);
			mysqli_stmt_close($st);

			@chmod($file, 0444);   // read-only (enforced on *nix; advisory on Windows)
			return array('status'=>'created', 'file'=>$rel, 'hash'=>$hash);

		} catch(\Throwable $e){
			// A duplicate key means another request archived it first — keep THAT file, drop ours.
			$dup = ($file !== null && mpqms_official_report_record($conn, $rid) !== null);
			if($file !== null && is_file($file)){ @chmod($file, 0666); @unlink($file); }
			if($dup){ return array('status'=>'exists'); }
			return array('status'=>'error', 'message'=>$e->getMessage());
		}
	}
}
?>
