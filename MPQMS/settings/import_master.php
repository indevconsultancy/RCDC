<?php
/*
 * MPQMS Settings — Excel bulk-import endpoint (one endpoint for all six sub-modules).
 *
 *   GET  ?action=template&type=F|P|C|E|M|H   -> downloads that sub-module's .xlsx template
 *   GET  ?action=export&type=F|P|C|E|M|H     -> downloads the CURRENT records in that same
 *                                                column layout (EDL: the whole Drug Category
 *                                                selection in one comma-separated cell)
 *   POST action=validate, type, file          -> parses + validates, returns row-wise verdicts (nothing saved)
 *   POST action=import,   type, file          -> re-validates server-side, imports the valid rows in one
 *                                                transaction, returns row-wise results + table rows
 *
 * Gates are the same as the manual Add form (save_update.php): session, CSRF on writes, and the
 * per-module role rule (Formulation/Parameter/Category: Admin or NDTL lab; EDL: Admin only;
 * Manufacturer/MAH: Admin + labs). Nothing here touches the manual add/edit/delete paths.
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

function iout($arr){ if(ob_get_length()) ob_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }

try {
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	include_once("../config/mpq_validate.php");
	require_once(__DIR__."/import_lib.php");
	if(ob_get_length()) ob_clean();

	$action = strtolower(trim((string)($_REQUEST['action'] ?? '')));
	$mod    = mpq_import_module($_REQUEST['type'] ?? '');
	if($mod === null){ iout(array('status'=>'error', 'message'=>'Unknown settings module.')); }
	if(!mpq_import_allowed($mod)){ iout(array('status'=>'error', 'message'=>'You are not authorised to import '.$mod['label'].' records.')); }

	/* ---------------- template / export download (both read-only) ---------------- */
	if($action === 'template' || $action === 'export'){
		$isExport = ($action === 'export');
		$bin = $isExport ? mpq_import_export_xlsx($mod, $connection) : mpq_import_template_xlsx($mod, $connection);
		if($bin === false){ iout(array('status'=>'error', 'message'=>'The file could not be generated.')); }
		$fname = $isExport
			? 'MPQMS_'.$mod['label'].'_Export_'.date('Ymd').'.xlsx'
			: 'MPQMS_'.$mod['label'].'_Import_Template.xlsx';
		if(ob_get_length()) ob_clean();
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="'.$fname.'"');
		header('Content-Length: '.strlen($bin));
		header('Cache-Control: private, max-age=0, must-revalidate');
		echo $bin; exit;
	}

	/* ---------------- validate / import (writes need CSRF + role, like save_update.php) ---------------- */
	if($_SERVER['REQUEST_METHOD'] !== 'POST'){ iout(array('status'=>'error', 'message'=>'Invalid request method.')); }
	if(function_exists('mpq_csrf_check')){ mpq_csrf_check(true); }
	if(function_exists('mpq_require_role')){ mpq_require_role(array(1,2), true); }

	if(empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])){ iout(array('status'=>'error', 'message'=>'Please choose the completed Excel template to upload.')); }
	$f = $_FILES['file'];
	if($f['error'] !== UPLOAD_ERR_OK){ iout(array('status'=>'error', 'message'=>'The upload failed (code '.$f['error'].'). Please try again.')); }
	if($f['size'] > 8 * 1024 * 1024){ iout(array('status'=>'error', 'message'=>'The file is larger than 8 MB.')); }

	$read = mpq_import_read_file($f['tmp_name'], $f['name']);
	if(!empty($read['error'])){ iout(array('status'=>'error', 'message'=>$read['error'])); }

	if($action === 'validate'){
		$v = mpq_import_validate($mod, $connection, $read['rows']);
		if(isset($v['error'])){ iout(array('status'=>'error', 'message'=>$v['error'])); }
		$rows = array_map(function($r){ return array('row'=>$r['row'], 'values'=>$r['values'], 'valid'=>$r['valid'], 'messages'=>$r['messages']); }, $v['rows']);
		iout(array('status'=>'success', 'module'=>$mod['label'], 'fields'=>array_map(function($f){ return array('key'=>$f['key'], 'header'=>$f['header']); }, $mod['fields']), 'rows'=>$rows, 'summary'=>$v['summary']));
	}

	if($action === 'import'){
		$res = mpq_import_execute($mod, $connection, $read['rows'], $_SESSION['users'] ?? '');
		if(isset($res['rows'])){ $res['rows'] = array_map(function($r){ return array('row'=>$r['row'], 'values'=>$r['values'], 'valid'=>$r['valid'], 'messages'=>$r['messages']); }, $res['rows']); }
		$res['fields'] = array_map(function($f){ return array('key'=>$f['key'], 'header'=>$f['header']); }, $mod['fields']);
		iout($res);
	}

	iout(array('status'=>'error', 'message'=>'Unknown action.'));

} catch(Throwable $e){
	iout(array('status'=>'error', 'message'=>'A server error occurred while processing the import. Nothing was saved.'));
}
?>
