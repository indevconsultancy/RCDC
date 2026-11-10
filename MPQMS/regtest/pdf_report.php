<?php
	// mPDF's CSS cascade + full DejaVu font loading + the header image legitimately need
	// more than the server's default 128 MB. Raised per-request only (not server-wide) so
	// no other endpoint or user is affected. Same story for the PCRE limits — mPDF's
	// AdjustHTML runs regexes over the whole rendered HTML.
	ini_set('memory_limit', '512M');
	ini_set('pcre.backtrack_limit', '50000000');
	ini_set('pcre.recursion_limit', '50000000');
	ob_start();
	ini_set('display_errors', '0');
	// If we die past the point where mPDF has started emitting the PDF stream, the browser
	// would otherwise save the fatal-error text with the .pdf name. Convert any fatal to a
	// visible HTML page instead — no half-written PDFs.
	register_shutdown_function(function(){
		$e = error_get_last();
		if($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)){
			if(!headers_sent()){
				while(ob_get_level() > 0){ ob_end_clean(); }
				header_remove('Content-Disposition');
				header('Content-Type: text/html; charset=utf-8');
				echo '<!doctype html><meta charset="utf-8"><title>Report</title><style>body{font-family:"Segoe UI",Arial,sans-serif;background:#f1f5f9;color:#334155;margin:0}.box{max-width:560px;margin:60px auto;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(2,6,23,.12);padding:26px 30px}.box h2{color:#dc3545;margin:0 0 12px;font-size:1.12rem}a{display:inline-block;background:#007bff;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600}</style>';
				echo '<div class="box"><h2>&#9888; The PDF report could not be generated.</h2><p>Please try again from the sample page. If this keeps happening, contact your administrator.</p><a href="javascript:history.back()">&#8592; Back</a></div>';
			}
		}
	});
	include_once("../config/auth_ndtlis.php");
	if(!($_SESSION['acl']==1 || ($_SESSION['acl']==2 && $_SESSION["usercat"]==18) || $_SESSION['acl']==7)){ if(ob_get_length()) ob_end_clean(); header("Location: manager.php"); exit; }
	include_once("../config/db_config.php");
	require_once(__DIR__."/report_lib.php");

	$regid = $_GET['rid'];

	// The Final Report may be downloaded only once testing is complete and the result is
	// finalized: sample Accepted, every lab result Approved and the Final Result recorded —
	// the same definition that issues the official archived report. Anything short of that
	// is an incomplete report and is refused here (this is the server-side gate; view.php
	// hides the button as well). Nothing is generated or written for an incomplete sample.
	require_once(__DIR__."/official_report_lib.php");
	if(!mpqms_sample_fully_completed($connection, (int)$regid)){
		if(ob_get_length()) ob_end_clean();
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><meta charset="utf-8"><title>Final Report</title><style>body{font-family:"Segoe UI",Arial,sans-serif;background:#f1f5f9;color:#334155;margin:0}.box{max-width:560px;margin:60px auto;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(2,6,23,.12);padding:26px 30px}.box h2{color:#b45309;margin:0 0 12px;font-size:1.12rem}.box p{margin:0 0 18px;line-height:1.5}a{display:inline-block;background:#007bff;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600}</style>';
		echo '<div class="box"><h2>&#9888; Final Report not yet available</h2>'
		   . '<p>The Final Report for this sample cannot be downloaded until testing is complete and the result has been finalized '
		   . '(sample accepted, all test results approved and the Final Result recorded).</p>'
		   . '<a href="view.php?rid='.(int)$regid.'">&#8592; Back to the sample</a></div>';
		exit;
	}

	if(ob_get_length()) ob_end_clean();
	mpqms_render_report($regid, $connection, $commondb, 'D');
	exit;
?>
