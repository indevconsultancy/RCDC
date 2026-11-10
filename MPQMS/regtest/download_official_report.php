<?php
/*
 * MPQMS — Official Analytical Report download (READ-ONLY).
 *
 * Streams the immutable PDF that was archived when the sample became fully completed.
 * Oct 2026 hardening — the endpoint now self-heals two prior 404 cases that the UI icon
 * could expose to end users, without ever modifying report contents or audit history:
 *
 *   1. Archive row exists but the archived PDF file is missing on disk (e.g. the DB was
 *      restored but /storage/reports/ was not): the report is regenerated on the fly with
 *      the SAME renderer (mpqms_render_report) and streamed. We log the miss via header.
 *   2. No archive row exists but the sample IS fully completed (Accepted + every lab
 *      result Approved + Final Result saved): we trigger mpqms_official_archive() which
 *      is write-once and idempotent, then stream the just-archived file.
 *
 * Access control is identical to the existing report pages (view.php / pdf_report.php):
 * Administrator (acl 1), RCDC/NDTL lab reviewers (acl 2 + usercat 18) and BFDA/DRA (acl 7).
 * Read-only guarantee: GET/HEAD only; no POST path exists.
 */
	// Oct 2026 — mPDF writes PDF bytes directly; a stray PHP notice/warning (e.g.
	// vendor code unserialising a stale fontmetrics cache) would prepend text to
	// the stream and trigger "Data has already been sent to output" at Output().
	// Mute runtime warnings on this endpoint ONLY — fatals still bubble up, and
	// every other page keeps its normal error-reporting behaviour.
	@ini_set('display_errors', '0');
	@ini_set('html_errors', '0');
	error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

	include_once("../config/auth_ndtlis.php");
	if(!($_SESSION['acl']==1 || ($_SESSION['acl']==2 && $_SESSION["usercat"]==18) || $_SESSION['acl']==7)){ header("Location: manager.php"); exit; }
	include_once("../config/db_config.php");
	require_once(__DIR__."/official_report_lib.php");

	// Read-only guarantee at the transport layer.
	$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	if(!in_array($method, array('GET','HEAD'), true)){
		http_response_code(405);
		header('Allow: GET, HEAD');
		echo 'Read-only endpoint. The official report cannot be modified or deleted.';
		exit;
	}

	$rid = isset($_GET['rid']) ? (int)$_GET['rid'] : 0;
	if($rid <= 0){
		http_response_code(400);
		echo 'Invalid report reference.';
		exit;
	}

	// Pull the sampleID early for the filename regardless of which branch we take.
	$sampleID = '';
	$st = @mysqli_prepare($connection, "SELECT sampleID FROM registrationtable WHERE registrationID=? LIMIT 1");
	if($st){
		mysqli_stmt_bind_param($st, 'i', $rid);
		mysqli_stmt_execute($st);
		mysqli_stmt_bind_result($st, $sidVal);
		if(mysqli_stmt_fetch($st)){ $sampleID = (string)$sidVal; }
		mysqli_stmt_close($st);
	}

	$rec = mpqms_official_report_record($connection, $rid);

	// Branch B — no archive row yet. Archive now if the sample is fully completed;
	//            otherwise fall through to a clear "not available yet" 404.
	if($rec === null){
		if(mpqms_sample_fully_completed($connection, $rid)){
			$user = isset($_SESSION['users']) ? (string)$_SESSION['users'] : '';
			$res  = mpqms_official_archive($connection, $commondb, $rid, $user);
			if(in_array(($res['status'] ?? ''), array('created','exists'), true)){
				$rec = mpqms_official_report_record($connection, $rid);
			}
		}
		if($rec === null){
			http_response_code(404);
			echo 'No official report has been issued for this sample yet.';
			exit;
		}
	}

	$abs    = mpqms_official_abs_path($rec['file_path']);
	$exists = ($abs !== null && is_file($abs) && is_readable($abs));

	$download = 'Official_Analytical_Report_'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$rec['sampleID']).'_'.$rid.'.pdf';

	// Branch A — archive row exists but the archived file is missing on disk.
	// Regenerate on the fly using the identical renderer and stream it. The archive
	// row (file_hash + generated_at) is NOT mutated: the stored, immutable record of
	// the original issuance stays intact; we only ensure the user receives their PDF.
	if(!$exists){
		if(function_exists('ob_get_level')){ while(ob_get_level() > 0){ ob_end_clean(); } }
		header('X-Report-Regenerated: 1');
		header('X-Report-SHA256: '.$rec['file_hash']);
		header('X-Report-Generated-At: '.$rec['generated_at']);
		if($method === 'HEAD'){
			header('Content-Type: application/pdf');
			header('Content-Disposition: attachment; filename="'.$download.'"');
			exit;
		}
		// mpqms_render_report writes to stdout for $dest='D' with Content-Disposition
		// set by mPDF itself; we don't send a Content-Length because the stream is
		// generated on the fly.
		mpqms_render_report($rid, $connection, $commondb, 'D', null);
		exit;
	}

	// Default branch — the archived immutable file is present. Stream it byte-for-byte.
	if(function_exists('ob_get_level')){ while(ob_get_level() > 0){ ob_end_clean(); } }
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.$download.'"');
	header('Content-Length: '.filesize($abs));
	header('Content-Transfer-Encoding: binary');
	header('Cache-Control: private, max-age=0, must-revalidate');
	header('Pragma: public');
	// Traceability: the checksum recorded when the report was issued.
	header('X-Report-SHA256: '.$rec['file_hash']);
	header('X-Report-Generated-At: '.$rec['generated_at']);

	if($method === 'HEAD'){ exit; }
	readfile($abs);
	exit;
?>
