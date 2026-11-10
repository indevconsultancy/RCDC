<?php
	// Full filtered export for the Registration & Result Manager (Excel or PDF).
	// Applies the SAME role visibility + filters + search as the list, but returns
	// ALL matching rows (no pagination) so "export all" still works under server-side paging.
	// mPDF (PDF path) and a wide HTML result set (Excel path) both legitimately need more
	// than the server's default 128 MB. Raised per-request only.
	ini_set('memory_limit', '512M');
	ob_start();
	ini_set('display_errors', '0');
	// If we die before the download body starts, don't ship the fatal text with a fake
	// .xls / .pdf filename — flip the response back to plain HTML.
	register_shutdown_function(function(){
		$e = error_get_last();
		if($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)){
			if(!headers_sent()){
				while(ob_get_level() > 0){ ob_end_clean(); }
				header_remove('Content-Disposition');
				header('Content-Type: text/html; charset=utf-8');
				echo '<!doctype html><meta charset="utf-8"><title>Export</title><style>body{font-family:"Segoe UI",Arial,sans-serif;background:#f1f5f9;color:#334155;margin:0}.box{max-width:520px;margin:60px auto;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(2,6,23,.12);padding:26px 30px}.box h2{color:#dc3545;margin:0 0 12px;font-size:1.12rem}a{display:inline-block;background:#007bff;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600}</style>';
				echo '<div class="box"><h2>&#9888; The export could not be generated.</h2><p>Please narrow the filters (fewer months, a smaller search) and try again.</p><a href="javascript:history.back()">&#8592; Back</a></div>';
			}
		}
	});
	include_once("../config/auth_ndtlis.php");
	// Effective-role RBAC: the export carries the same rows as the list, so it carries the
	// same permission. Previously this endpoint had no role check at all.
	include_once("../config/mpq_validate.php");
	mpq_require_page('manager', 'export', 'manager.php');
	include_once("../config/db_config.php");
	require_once(__DIR__."/manager_lib.php");

	$type = (isset($_GET['type']) && $_GET['type'] === 'pdf') ? 'pdf' : 'excel';

	$req = array(
		'year'      => $_GET['year']      ?? '',
		'month'     => $_GET['month']     ?? '',
		'dzongkhag' => $_GET['dzongkhag'] ?? '',
		'facility'  => $_GET['facility']  ?? '',
		'search'    => $_GET['search']    ?? '',
		'order_col' => 1, 'order_dir' => 'desc',
	);
	$session = array(
		'acl'     => $_SESSION['acl']     ?? 0,
		'usercat' => $_SESSION['usercat'] ?? 0,
		'users'   => $_SESSION['users']   ?? '',
	);

	list($from, $baseWhere, $searchWhere) = mpqms_manager_from_where($connection, $commondb, $session, $req);
	$fullWhere = $baseWhere . ($searchWhere !== '' ? " AND $searchWhere" : "");
	$order = mpqms_manager_order($req);

	$maps = mpqms_manager_load_maps($connection, $commondb);   // batch maps (many rows)
	$res  = mysqli_query($connection, mpqms_manager_select()." $from WHERE $fullWhere $order");

	$headers = array('Pharma Claim','DoR','SampleID','Cat','Site','CollectedBy','Sample','Batch','Acp','Sts','Apv','Result');
	$rows = array();
	if($res){
		while($row = mysqli_fetch_row($res)){
			$c = mpqms_manager_compute($connection, $commondb, $row, $maps);
			$rows[] = array(
				$c['pclaim'], $c['dor'], $c['sid'], $c['cat'], $c['site'], $c['cby'],
				$c['edl'], $c['batch'], $c['acp'], $c['sts'], $c['apv'], ($c['frslt'] === null ? '' : $c['frslt'])
			);
		}
	}

	$fname = 'Registration_List_'.date('Y-m-d');

	if($type === 'excel'){
		// Only commit the download headers now that the rows are in memory — any earlier
		// failure would otherwise be saved by the browser under the .xls filename with the
		// PHP error text inside.
		if(ob_get_length()) ob_end_clean();
		header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
		header('Content-Disposition: attachment; filename="'.$fname.'.xls"');
		echo "<table border='1'><thead><tr>";
		foreach($headers as $h){ echo "<th>".htmlspecialchars($h)."</th>"; }
		echo "</tr></thead><tbody>";
		foreach($rows as $r){
			echo "<tr>";
			foreach($r as $cell){ echo "<td>".htmlspecialchars((string)$cell)."</td>"; }
			echo "</tr>";
		}
		echo "</tbody></table>";
		exit;
	}

	// PDF (A3 landscape) via the bundled mpdf — matches the previous export orientation.
	$docroot = $_SERVER["DOCUMENT_ROOT"] ?? '';
	if($docroot === '' || !is_dir($docroot.'/MPQMS')){ $docroot = dirname(dirname(__DIR__)); }   // this file's real location
	require_once($docroot."/MPQMS/mpdf/vendor/autoload.php");

	// A large result set as a single WriteHTML() string overruns PHP's pcre.backtrack_limit
	// (mPDF's AdjustHTML runs regexes over the whole string). Fix: raise the PCRE limits as a
	// safety net AND feed the table to mPDF in small row-batches. mPDF keeps the table open
	// across WriteHTML() calls and still buffers every row before rendering, so column widths
	// and the final layout are identical to a single call.
	ini_set('pcre.backtrack_limit', '50000000');
	ini_set('pcre.recursion_limit', '50000000');

	if(ob_get_length()) ob_end_clean();
	$mpdf = new \Mpdf\Mpdf(array('mode'=>'utf-8','format'=>'A3-L','margin_left'=>10,'margin_right'=>10,'margin_top'=>12,'margin_bottom'=>10));
	$mpdf->SetTitle('Registration List');

	$head  = '<style>table{border-collapse:collapse;width:100%;} th,td{border:.4px solid #333;padding:3px;font-size:8px;} th{background:#f1f5f9;text-align:center;}</style>';
	$head .= '<div style="text-align:center;font-size:13px;"><b>Registration &amp; Result List</b></div>';
	$head .= '<div style="text-align:right;font-size:9px;">Generated: '.date('Y-m-d H:i').'</div><br>';
	$head .= '<table><thead><tr>';
	foreach($headers as $h){ $head .= '<th>'.htmlspecialchars($h).'</th>'; }
	$head .= '</tr></thead><tbody>';
	$mpdf->WriteHTML($head);

	$buf = '';
	$n = 0;
	foreach($rows as $r){
		$buf .= '<tr>';
		foreach($r as $cell){ $buf .= '<td>'.htmlspecialchars((string)$cell).'</td>'; }
		$buf .= '</tr>';
		if(++$n % 200 === 0){ $mpdf->WriteHTML($buf); $buf = ''; }   // flush every 200 rows
	}
	if($buf !== ''){ $mpdf->WriteHTML($buf); }
	$mpdf->WriteHTML('</tbody></table>');

	$mpdf->Output($fname.'.pdf', 'D');
	exit;
?>
