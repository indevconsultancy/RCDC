<?php
	/* Dashboard rollup job. It rewrites the dashboard_* aggregate tables, so it must not be
	   runnable by an anonymous web request. Scheduled execution is unaffected: the CLI path
	   (php cron-dashboard.php / Task Scheduler) is always allowed. Over HTTP an authenticated
	   Administrator session is required. No query, calculation or table below is changed. */
	if (PHP_SAPI !== 'cli') {
		require_once("config/session.php");
		mn_require_admin('The dashboard rollup job can only be run by an Administrator.');
	}
	require_once("config/commondb-config.php");
	require_once("config/db-config.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$cdt = date('Y-m-d h:i:s', time());
	$cur_year = date("Y");
	$cur_month = date("m");
	$null = NULL;


	//Update dashboard_stats
	$panel = ($conn->query("SELECT COUNT(*) FROM panel_lab_results")); $panel = $panel -> fetch_row();
	$blinded = ($conn->query("SELECT COUNT(*) FROM blinded_results")); $blinded = $blinded -> fetch_row();
	$rdt = ($conn->query("SELECT COUNT(*) FROM rdt_assessment_report")); $rdt = $rdt -> fetch_row();
	$molecular = ($conn->query("SELECT COUNT(*) FROM dbs_results")); $molecular = $molecular -> fetch_row();
	$ms = ($conn->query("SELECT COUNT(*) FROM onsite_general_informations")); $ms = $ms -> fetch_row();
	$prdt = ($conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results")); $prdt = $prdt -> fetch_row();
	
	$conn->query("UPDATE dashboard_stats SET panel = '$panel[0]', blinded = '$blinded[0]', rdt = '$rdt[0]', molecular = '$molecular[0]', ms = '$ms[0]', rdt_panel = '$prdt[0]', updated_at='$cdt' WHERE id = '1'");


	//Update dashboard_panels table
    $panel_qry = ($conn->query("SELECT round_id, panel_round, closing_date, shipment_date FROM panel_round_informations WHERE YEAR(shipment_date) = '$cur_year'"));
	$panelChk = $panel_qry -> num_rows;
	$panel = $panel_qry -> fetch_row();
	if($panelChk > 0){
		//Panel Status
		$status = "";
		$today = new DateTime(date('Y-m-d'));
		$closing = new DateTime($panel[2]);

		if($today > $closing){  $status = "CLOSED"; }
		else{	if($panel[2] != "") $status = "OPEN"; }  

		$labs = ($conn->query("SELECT COUNT(DISTINCT facility_id) FROM panel_informations WHERE round_id = '$panel[0]'"));
		$labs = $labs -> fetch_row();
		$reports = ($conn->query("SELECT COUNT(DISTINCT t3.facility_id) FROM panel_lab_results t1, panel_slide_informations t2, panel_informations t3 WHERE t1.slide_id=t2.slide_id AND t2.panel_id=t3.panel_id AND t3.round_id = '$panel[0]'"));
		$reports = $reports -> fetch_row();

		$conn->query("DELETE FROM dashboard_panels WHERE round = '$panel[1]'");
		$stmt = $conn->prepare("INSERT INTO dashboard_panels VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
		$stmt->bind_param("iisssiis", $panel[1], $cur_year, $status, $panel[2], $panel[3], $labs, $reports, $cdt);
		$stmt->execute();
		$stmt->close(); 
	}
	
	//Update dashboard_blinded table
	$conn->query("DELETE FROM dashboard_blinded WHERE year = '$cur_year'");
	$stmt = $conn->prepare("INSERT INTO dashboard_blinded VALUES (?, ?, ?, ?, ?, ?)");
	for($i=1; $i <=12; $i++){
		$blinded_labs = ($conn->query("SELECT COUNT(DISTINCT facility_id) FROM blinded_informations WHERE year = '$cur_year' AND month = '$i'"));
		$blinded_labs = $blinded_labs -> fetch_row();
		$blinded_slides = ($conn->query("SELECT COUNT(*) FROM blinded_informations t1, blinded_results t2 WHERE t1.blinded_id = t2.blinded_id AND t1.year = '$cur_year' AND t1.month = '$i'"));
		$blinded_slides = $blinded_slides -> fetch_row();
		if($blinded_labs[0] == "") $blinded_labs[0] = 0;
		if($blinded_slides[0] == "") $blinded_slides[0] = 0;
		$stmt->bind_param("iiiiis", $null, $i, $cur_year, $blinded_labs[0], $blinded_slides[0], $cdt);
		$stmt->execute();
	}
	$stmt->close(); 
	
	//Update dashboard_rdt table
	$conn->query("DELETE FROM dashboard_rdt WHERE year = '$cur_year'");
	$stmt = $conn->prepare("INSERT INTO dashboard_rdt VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
	for($i=1; $i <=12; $i++){
		$rdt_labs = ($conn->query("SELECT COUNT(DISTINCT facility_id) FROM rdt_test_request WHERE YEAR(date_request) = '$cur_year' AND MONTH(date_request) = '$i'"));
		$rdt_labs = $rdt_labs -> fetch_row();
		$rdt_ini = ($conn->query("SELECT COUNT(*) FROM rdt_test_request WHERE YEAR(date_request) = '$cur_year' AND MONTH(date_request) = '$i' AND lot_type='Initial'"));
		$rdt_ini = $rdt_ini -> fetch_row();
		$rdt_lt3 = ($conn->query("SELECT COUNT(*) FROM rdt_test_request WHERE YEAR(date_request) = '$cur_year' AND MONTH(date_request) = '$i' AND lot_type='Long-Term(3 Months)'"));
		$rdt_lt3 = $rdt_lt3 -> fetch_row();
		$rdt_lt6 = ($conn->query("SELECT COUNT(*) FROM rdt_test_request WHERE YEAR(date_request) = '$cur_year' AND MONTH(date_request) = '$i' AND lot_type='Long-Term(6 Months)'"));
		$rdt_lt6 = $rdt_lt6 -> fetch_row();
		$rdt_lt9 = ($conn->query("SELECT COUNT(*) FROM rdt_test_request WHERE YEAR(date_request) = '$cur_year' AND MONTH(date_request) = '$i' AND lot_type='Long-Term(9 Months)'"));
		$rdt_lt9 = $rdt_lt9 -> fetch_row();
		$rdt_lt12 = ($conn->query("SELECT COUNT(*) FROM rdt_test_request WHERE YEAR(date_request) = '$cur_year' AND MONTH(date_request) = '$i' AND lot_type='Long-Term(12 Months)'"));
		$rdt_lt12 = $rdt_lt12 -> fetch_row();
		if($rdt_labs[0] == "") $rdt_labs[0] = 0;
		if($rdt_ini[0] == "") $rdt_ini[0] = 0;
		if($rdt_lt3[0] == "") $rdt_lt3[0] = 0;
		if($rdt_lt6[0] == "") $rdt_lt6[0] = 0;
		if($rdt_lt9[0] == "") $rdt_lt9[0] = 0;
		if($rdt_lt12[0] == "") $rdt_lt12[0] = 0;
		$stmt->bind_param("iiiiiiiiis", $null, $i, $cur_year, $rdt_ini[0], $rdt_labs[0], $rdt_lt3[0], $rdt_lt6[0], $rdt_lt9[0], $rdt_lt12[0], $cdt);
		$stmt->execute();
	}       
	$stmt->close(); 

	//Update dashboard_molecular table
	$conn->query("DELETE FROM dashboard_molecular WHERE year = '$cur_year'");
	$stmt = $conn->prepare("INSERT INTO dashboard_molecular VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
	for($i=1; $i <=12; $i++){
		$molecular_labs = ($conn->query("SELECT COUNT(DISTINCT facility_id) FROM dbs_results WHERE YEAR(collection_date) = '$cur_year' AND MONTH(collection_date) = '$i'"));
		$molecular_labs = $molecular_labs -> fetch_row();
		$pcd = ($conn->query("SELECT COUNT(*) FROM dbs_results WHERE YEAR(collection_date) = '$cur_year' AND MONTH(collection_date) = '$i' AND sample_type = 'PCD'"));
		$pcd = $pcd -> fetch_row();
		$racd = ($conn->query("SELECT COUNT(*) FROM dbs_results WHERE YEAR(collection_date) = '$cur_year' AND MONTH(collection_date) = '$i' AND sample_type = 'RACD'"));
		$racd = $racd -> fetch_row();
		$pacd = ($conn->query("SELECT COUNT(*) FROM dbs_results WHERE YEAR(collection_date) = '$cur_year' AND MONTH(collection_date) = '$i' AND sample_type = 'PACD'"));
		$pacd = $pacd -> fetch_row();
		$acd = ($conn->query("SELECT COUNT(*) FROM dbs_results WHERE YEAR(collection_date) = '$cur_year' AND MONTH(collection_date) = '$i' AND sample_type = 'ACD'"));
		$acd = $acd -> fetch_row();
		$neg = ($conn->query("SELECT COUNT(*) FROM dbs_results WHERE YEAR(collection_date) = '$cur_year' AND MONTH(collection_date) = '$i' AND pcr = 'Negative'"));
		$neg = $neg -> fetch_row(); 
		$pos = ($conn->query("SELECT COUNT(*) FROM dbs_results WHERE YEAR(collection_date) = '$cur_year' AND MONTH(collection_date) = '$i' AND pcr != 'Negative' AND pcr IS NOT NULL"));
		$pos = $pos -> fetch_row();
		
		if($molecular_labs[0] == "") $molecular_labs[0] = 0;
		if($pcd[0] == "") $pcd[0] = 0;
		if($racd[0] == "") $racd[0] = 0;
		if($pacd[0] == "") $pacd[0] = 0;
		if($acd[0] == "") $acd[0] = 0;
		if($neg[0] == "") $neg[0] = 0;
		if($pos[0] == "") $pos[0] = 0;
		$stmt->bind_param("iiiiiiiiiis", $null, $i, $cur_year, $molecular_labs[0], $pcd[0], $racd[0], $pacd[0], $acd[0], $neg[0], $pos[0], $cdt);
		$stmt->execute();
	}       
	$stmt->close(); 
?>           