<?php
	// Do NOT send Content-Type: text/csv up front. If anything below fails (auth redirect,
	// missing filters, DB error, out-of-memory, etc.) with text/csv already on the response,
	// the browser downloads the failure body as a file named "csv_raw.php". The CSV headers
	// are set only just before we stream the actual CSV, once the data is ready.
	ob_start();
	ini_set('display_errors', '0');
	ini_set('memory_limit', '512M');
	include_once("../config/auth_ndtlis.php");
	if(!($_SESSION['acl']==1 || ($_SESSION['acl']==2 && $_SESSION["usercat"]==18))){ if(ob_get_length()) ob_end_clean(); header("Location: ../dashboard/dashboard.php"); exit; }
	include_once("../config/db_config.php");
	// If PHP dies with a fatal error later, emit a plain HTML page (never a fake .csv).
	register_shutdown_function(function(){
		$e = error_get_last();
		if($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)){
			if(!headers_sent()){
				while(ob_get_level() > 0){ ob_end_clean(); }
				header_remove('Content-Disposition');
				header('Content-Type: text/html; charset=utf-8');
				echo '<!doctype html><meta charset="utf-8"><title>Export</title><style>body{font-family:"Segoe UI",Arial,sans-serif;background:#f1f5f9;color:#334155;margin:0}.box{max-width:520px;margin:60px auto;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(2,6,23,.12);padding:26px 30px}.box h2{color:#dc3545;margin:0 0 12px;font-size:1.12rem}a{display:inline-block;background:#007bff;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600}</style>';
				echo '<div class="box"><h2>&#9888; The CSV export could not be generated.</h2><p>Please narrow the filters (fewer years/months, fewer sites) and try again.</p><a href="javascript:history.back()">&#8592; Back to Filters</a></div>';
			}
		}
	});

	// Validate mandatory filters BEFORE building the query. Without this, a missing "acp"
	// leaves $qry1 undefined and a broken query error gets streamed as a .csv download.
	// Nothing has been output yet, so we can override the csv header with an HTML message.
	$mpMissing = array();
	if(!isset($_POST['acp']) || $_POST['acp'] === ''){ $mpMissing[] = 'Sample Acceptance'; }
	if(empty($_POST['site'])){  $mpMissing[] = 'Sampling Sites'; }
	if(empty($_POST['dcat'])){  $mpMissing[] = 'Drug Categories'; }
	if(empty($_POST['year'])){  $mpMissing[] = 'Year'; }
	if(empty($_POST['month'])){ $mpMissing[] = 'Month'; }
	if(!empty($mpMissing)){
		if(ob_get_length()) ob_end_clean();
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><meta charset="utf-8"><title>Export</title><style>body{font-family:"Segoe UI",Arial,sans-serif;background:#f1f5f9;color:#334155;margin:0}.box{max-width:520px;margin:60px auto;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(2,6,23,.12);padding:26px 30px}.box h2{color:#dc3545;margin:0 0 12px;font-size:1.12rem}.box ul{margin:6px 0 20px 18px}a{display:inline-block;background:#007bff;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600}</style>';
		echo '<div class="box"><h2>&#9888; Please select the required filters before exporting.</h2><p>The following filter'.(count($mpMissing)>1?'s are':' is').' required:</p><ul>';
		foreach($mpMissing as $m){ echo '<li>'.htmlspecialchars($m).'</li>'; }
		echo '</ul><a href="javascript:history.back()">&#8592; Back to Filters</a></div>';
		exit;
	}

	if(isset($_POST["acp"])) {
		$acp = $_POST["acp"]; 
		// Oct 2026 — appended chain-of-custody columns (sampled_by, sampling_organization,
		// sampling_organization_other, sampling_location, sampling_date, sent_by, st.received_by,
		// st.sample_condition). Appending to the END keeps positional unpacking stable for the
		// pre-existing fields; the loop below pulls them off the row via explicit list() order.
		if($acp == "Accepted"){
			$qry1 = "SELECT DISTINCT rt.registrationID,sampleID,testCategory,registrationDate,siteID,siteOther,rt.edlID,edlOther,pharmaClaim,pharmaOther,formulationID,formulationOther,sampleQty,batchNo,mfgDate,expDate,mfg.name,rt.manufacturerOther,collectID,collectOther,st.status,ft.result,
				sampled_by,sampling_organization,sampling_organization_other,sampling_location,sampling_date,sent_by,st.received_by,st.sample_condition
				FROM registrationtable rt,samplestatustable st,labresulttable lt,finalresulttable ft,msedlcategorymaptable ect, msmanufacturertable mfg WHERE rt.registrationID=st.registrationID AND rt.registrationID=lt.registrationID AND rt.registrationID=ft.registrationID AND rt.edlID=ect.edlID AND rt.manufacturer=mfg.id AND st.status='Accepted'";
		}
		else{
			$qry1 = "SELECT DISTINCT rt.registrationID,sampleID,testCategory,registrationDate,siteID,siteOther,rt.edlID,edlOther,pharmaClaim,pharmaOther,formulationID,formulationOther,sampleQty,batchNo,mfgDate,expDate,mfg.name,rt.manufacturerOther,collectID,collectOther,
				sampled_by,sampling_organization,sampling_organization_other,sampling_location,sampling_date,sent_by,st.received_by,st.sample_condition
				FROM registrationtable rt, samplestatustable st, msedlcategorymaptable ect, msmanufacturertable mfg WHERE rt.registrationID=st.registrationID AND rt.edlID=ect.edlID AND rt.manufacturer=mfg.id AND st.status='Rejected'";
		}
	}

	if(isset($_POST["site"])) { 
		$site = $_POST["site"]; 
		$str = "";
		for($i=0;$i<count($site);$i++){ $str .= "'$site[$i]',"; }
		$str = substr($str,0,-1);
		$qry1 .= " AND siteID IN($str)";
	}

	if(isset($_POST["cat"])) { 
		$cat = $_POST["cat"]; 
		$str = "";
		for($i=0;$i<count($cat);$i++){ $str .= "'$cat[$i]',"; }
		$str = substr($str,0,-1);
		$qry1 .= " AND testCategory IN($str)";
	}
	
	if(isset($_POST["year"])) { 
		$year = $_POST["year"]; 
		$str = "";
		for($i=0;$i<count($year);$i++){ $str .= $year[$i].","; }
		$str = substr($str,0,-1);
		$qry1 .= " AND YEAR(registrationDate) IN($str)";

	}
	if(isset($_POST["month"])) { 
		$month = $_POST["month"]; 
		$str = "";
		for($i=0;$i<count($month);$i++){ $str .= $month[$i].","; }
		$str = substr($str,0,-1);
		$qry1 .= " AND MONTH(registrationDate) IN($str)";
	}

	if(isset($_POST["dcat"])) { 
		$dcat = $_POST["dcat"]; 
		$str = "";
		for($i=0;$i<count($dcat);$i++){ $str .= "'$dcat[$i]',"; }
		$str = substr($str,0,-1);
		$qry1 .= " AND  categoryID IN($str)";

	}
	
	$delimiter = ",";
    $filename = "raw_" . date('Y-m-d') . ".csv";
    // php://temp spills to disk once the buffer exceeds 2 MB, so large exports no longer
    // fail with "Allowed memory size exhausted" the way php://memory did.
    $file = fopen('php://temp/maxmemory:2097152', 'w+');

	if($acp == "Accepted"){
		if(isset($_POST["rslt"])) { 
			$rslt = $_POST["rslt"]; 
			$str = "";
			for($i=0;$i<count($rslt);$i++){ $str .= "'$rslt[$i]',"; }
			$str = substr($str,0,-1);
			$qry1 .= " AND lt.result IN($str)";
		}

		$pararr = array();
		if(isset($_POST["parid"])) { 
			$parid = $_POST["parid"]; 
			for($i=0;$i<count($parid);$i++){ 
				list($par) = mysqli_fetch_row(mysqli_query($connection,"SELECT parameter FROM mstestparametertable WHERE id='$parid[$i]'"));
				$pararr[] .= $par;
			}
		}
		 $fields = array('TestCategory', 'RegistrationDate', 'Dzongkhag', 'Site', 'SiteOther', 'SampleID','Sample','SampleOther','Pharma','PharmaOther','Formu', 'FormuOther', 'Qty', 'Batch', 'MgfDate', 'ExpDate', 'Mfg', 'MfgOth', 'Dzongkhag', 'Collect', 'CollectOther', 'Status', 'CommDate', 'CompDate', 'Result',
			'SampledBy','SamplingOrg','SamplingOrgOther','SamplingLocation','SamplingDate','SentBy','ReceivedBy','SampleCondition');
   		$colheader = array_merge($fields,$pararr);
	}
	else{
		$colheader = array('TestCategory', 'RegistrationDate', 'Dzongkhag', 'Site', 'SiteOther', 'SampleID','Sample','SampleOther','Pharma','PharmaOther','Formu', 'FormuOther', 'Qty', 'Batch', 'MgfDate', 'ExpDate', 'Mfg', 'MfgOth', 'Dzongkhag', 'Collect', 'CollectOther', 'Status',
			'SampledBy','SamplingOrg','SamplingOrgOther','SamplingLocation','SamplingDate','SentBy','ReceivedBy','SampleCondition');
	}
	
	$qry1 .= " ORDER BY registrationDate DESC;";
	$runqry1=mysqli_query($connection,$qry1);

   	fputcsv($file, $colheader, $delimiter);
   	if($acp == "Accepted"){
		while(list($rid,$sid,$cat,$dor,$siteid,$siteoth,$edlid,$edloth,$pcl,$pcloth,$forid,$foroth,$qty,$bno,$dom,$doe,$mfg,$mfgoth,$cbyid,$cbyoth,$sts,$fnlrslt,
			$sampled_by,$sampling_org,$sampling_org_other,$sampling_location,$sampling_date,$sent_by,$received_by,$sample_condition
		) = mysqli_fetch_row($runqry1)){

			list($site,$dz1) = mysqli_fetch_row(mysqli_query($commondb,"SELECT ct.center_name,dt.dzongkhag_name FROM ms_dzongkhag_table dt,ms_health_center_table ct WHERE ct.dzongkhag_code=dt.dzongkhag_code AND ct.center_id='".$siteid."'"));
			list($cby,$dz2) = mysqli_fetch_row(mysqli_query($commondb,"SELECT ct.center_name,dt.dzongkhag_name FROM ms_dzongkhag_table dt,ms_health_center_table ct WHERE ct.dzongkhag_code=dt.dzongkhag_code AND ct.center_id='".$cbyid."'"));
			list($edl) = mysqli_fetch_row(mysqli_query($connection,"SELECT edl FROM msedltable WHERE id='".$edlid."'"));
			list($for) = mysqli_fetch_row(mysqli_query($connection,"SELECT formulation FROM msformulationtable WHERE id='".$forid."'"));
			list($commdt,$compdt)=mysqli_fetch_row(mysqli_query($connection,"SELECT MIN(testDate),MAX(testDate) FROM labresulttable WHERE registrationID='$rid'"));

			$data = $rsltarr = $dataarr = array();

			for($i=0;$i<count($parid);$i++){
				list($rslt) = mysqli_fetch_row(mysqli_query($connection,"SELECT result FROM labresulttable WHERE registrationID='$rid' AND parameterID='$parid[$i]'"));
				$rsltarr[] = $rslt;
			}
			if($sampling_date === "1900-01-01" || $sampling_date === "0000-00-00"){ $sampling_date = ""; }
		   	$data = array($cat,$dor,$dz1,$site,$siteoth,$sid,$edl,$edloth,$pcl,$pcloth,$for,$foroth,$qty,$bno,$dom,$doe,$mfg,$mfgoth,$dz2,$cby,$cbyoth,$sts,$commdt,$compdt,$fnlrslt,
				$sampled_by,$sampling_org,$sampling_org_other,$sampling_location,$sampling_date,$sent_by,$received_by,$sample_condition);
		   	$dataarr = array_merge($data,$rsltarr);
			fputcsv($file, $dataarr, $delimiter);
		}
	}
	else{
		while(list($rid,$sid,$cat,$dor,$siteid,$siteoth,$edlid,$edloth,$pcl,$pcloth,$forid,$foroth,$qty,$bno,$dom,$doe,$mfg,$mfgoth,$cbyid,$cbyoth,$sts,
			$sampled_by,$sampling_org,$sampling_org_other,$sampling_location,$sampling_date,$sent_by,$received_by,$sample_condition
		) = mysqli_fetch_row($runqry1)){

			list($site,$dz1) = mysqli_fetch_row(mysqli_query($commondb,"SELECT ct.center_name,dt.dzongkhag_name FROM ms_dzongkhag_table dt,ms_health_center_table ct WHERE ct.dzongkhag_code=dt.dzongkhag_code AND ct.center_id='".$siteid."'"));
			list($cby,$dz2) = mysqli_fetch_row(mysqli_query($commondb,"SELECT ct.center_name,dt.dzongkhag_name FROM ms_dzongkhag_table dt,ms_health_center_table ct WHERE ct.dzongkhag_code=dt.dzongkhag_code AND ct.center_id='".$cbyid."'"));
			list($edl) = mysqli_fetch_row(mysqli_query($connection,"SELECT edl FROM msedltable WHERE id='".$edlid."'"));
			list($for) = mysqli_fetch_row(mysqli_query($connection,"SELECT formulation FROM msformulationtable WHERE id='".$forid."'"));
			if($sampling_date === "1900-01-01" || $sampling_date === "0000-00-00"){ $sampling_date = ""; }
		   	$data = array($cat,$dor,$dz1,$site,$siteoth,$sid,$edl,$edloth,$pcl,$pcloth,$for,$foroth,$qty,$bno,$dom,$doe,$mfg,$mfgoth,$dz2,$cby,$cbyoth,$sts,
				$sampled_by,$sampling_org,$sampling_org_other,$sampling_location,$sampling_date,$sent_by,$received_by,$sample_condition);
			fputcsv($file, $data, $delimiter);
		}
	}

    fseek($file, 0);
    // Only NOW commit the CSV headers — the data is ready, so a failure past this point
    // is impossible short of the client aborting. This is what stops the "downloaded a
    // .php file with garbage inside" symptom.
    if(ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    fpassthru($file);
	exit;
?>



