<?php
	// PDF output integrity: buffer all output while building the report so stray
	// PHP notices/warnings/whitespace cannot corrupt the binary PDF stream. mPDF
	// aborts with "Data has already been sent to output" if anything precedes it.
	ob_start();
	require_once("../config/session.php");
	require_once("../config/auth.php");
	require_once(__DIR__ . "/../includes/pdf.php");
	require_once("../config/commondb-config.php");
  	require_once("../config/db-config.php");

	// Facility-scope guard via shared helper.
	$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
	mn_require_rdt_panel_ownership($conn, $id, 'plain', 'You are not authorised to download this panel report.');
	if (!mn_is_rcdc()) {
		$authState = mn_panel_state($conn, 'rdt', $id);
		if (!$authState || !$authState['closed']) { mn_deny_plain('The report is released to facilities after the panel round closes.', $id); }
	}
	
	$html = '
	<style>
	table { border-collapse: collapse; }
	table.layout {
		border: .5px solid black;
		border-collapse: collapse;
	}
	td {
		padding: 3px;
		font-size:12px;
		border: .5px solid black;
		vertical-align: middle;
		text-align: left;
	}
	.text-center {
		text-align: center;
	}	
	</style>';

	$panel = ($conn->query("SELECT t2.panel_round, t3.center_name, t2.shipment_date, t1.receive_date FROM rdt_panel_informations t1, rdt_panel_round_informations t2, rcdc_commondb.ms_health_center_table t3 WHERE t1.facility_id=t3.center_id AND t1.round_id=t2.round_id AND t1.panel_id='$id'"));
	$panel = $panel -> fetch_row();
	 $lab1 = ($conn->query("SELECT examiner, category, designation, examination_date, kitname, batchlot, manufacturer, expiry_date, timeliness FROM rdt_panel_lab_result_informations WHERE panel_id='$id'"));
	$lab1 = $lab1 -> fetch_row() ?: array_fill(0, 9, '');   // no lab result yet
	
	$html .= "
		<div style='text-align:center; font-size:18px; font-weight: bold;'>National Malaria Reference Laboratory</div>
		<div style='text-align:center; font-size:16px; font-weight: bold;'>National External Quality Assessment Scheme in Malaria Diagnosis</div>
		<div style='text-align:center; font-size:16px; font-weight: bold;'>RDT Panel Testing Report</div><br>
		<table class='layout' width='100%' >
			<tr>
				<td><strong>Panel round</strong></td><td class='text-center'>$panel[0]</td>	
				<td><strong>Health facility</strong></td><td>$panel[1]</td>
			</tr>
			<tr>
				<td><strong>Shipment date</strong></td><td>$panel[2]</td>	
				<td><strong>Receive date</strong></td><td>$panel[3]</td>
			</tr>
			<tr>
				<td><strong>Examiner</strong></td><td>$lab1[0]</td>	
				<td><strong>Designation</strong></td><td>$lab1[1]</td>
			</tr>
			<tr>
				<td><strong>Category</strong></td><td>$lab1[2]</td>	
				<td><strong>Examination Date</strong></td><td>$lab1[3]</td>
			</tr>
			<tr>
				<td><strong>Test kit name</strong></td><td>$lab1[4]</td>	
				<td><strong>Batch/lot#</strong></td><td>$lab1[5]</td>
			</tr>
			<tr>
				<td><strong>Manufacturer</strong></td><td>$lab1[6]</td>	
				<td><strong>Expiry Date</strong></td><td>$lab1[7]</td>
			</tr>
			<tr>
				<td><strong>Reporting status</strong></td><td>$lab1[8]</td>	
				<td colspan=2></td>
			</tr>	
		</table><br>";
	
	$html .= "		
		<table class='layout' width='100%'>
			<tr>
				<td rowspan=2 class='text-center' width='15%'><b>Slide<br>#</b></td>
				<td rowspan=2 class='text-center' width='10%'><b>Receive<br>Status</b></td>
				<td colspan=3 class='text-center' width='15%'><b>Species</b></td>
			</tr>
			<tr class='text-center'>
				<td class='text-center'><b>RCDC</b></td><td class='text-center'><b>Lab</b></td><td class='text-center'><b>Check</b></td>
			</tr>	
		";
		$slide = $conn->query("SELECT slide_id, slide_number, slide_status FROM rdt_panel_slide_informations WHERE panel_id='$id'");
		while($row = $slide->fetch_row()) { 
			$rcdc = ($conn->query("SELECT rcdc_species FROM rdt_panel_rcdc_results WHERE slide_id='$row[0]'"));
			$rcdc = $rcdc -> fetch_row() ?: array('');
			$lab2qry = "SELECT lab_species, positivity_flag FROM rdt_panel_lab_results WHERE slide_id='$row[0]'";
			$chkLab = ($conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results WHERE slide_id='$row[0]'"));
			$chkLab = $chkLab -> fetch_row(); 
			if($conn->query($lab2qry) -> num_rows > 0) { $lab2 = ($conn->query($lab2qry)); $lab2 = $lab2 -> fetch_row(); }
			else  $lab2 = array('','','','','');
		
			$html.="<tr>
				<td>$row[1]</td>
				<td>$row[2]</td>
				<td>$rcdc[0]</td><td>$lab2[0]</td><td class='text-center'>$lab2[1]</td>
			</tr>";	
		}	
		$html.="</table><br>";

		$score = ($conn->query("SELECT sensitivity, specificity, species, avg_score1, avg_score2, final_score, achievement, findings, recommendation, action FROM rdt_panel_lab_result_informations WHERE panel_id='$id'"));
		$score = $score -> fetch_row() ?: array_fill(0, 10, '');

		$html.="<table class='layout' width='100%'>
		  <tr>
			<td><b>Parameters</b></td>
			<td class='text-center'><b>Score</b></td>
		  </tr>
		  <tr>
			<td>Sensitivity</td><td class='text-center'>$score[0]</td>
		  </tr>
		  <tr>  
			<td>Specificity</td><td class='text-center'>$score[1]</td>
		  </tr>
		  <tr>
			<td>% of Agreement(Parasite Species Identification)</td><td class='text-center'>$score[2]</td>
		  </tr>
		  <tr>
			<td><b>Average score 1</b></td><td class='text-center'><b>$score[3]</b></td>
		  </tr>
		   <tr>
			<td><b>Average score 2</b></td><td class='text-center'><b>$score[4]</b></td>
		  </tr>
		   <tr>
			<td><b>Final score</b></td><td class='text-center'><b>$score[5]</b></td>
		  </tr>
		  <tr>
			<td><b>Achievement</b></td><td class='text-center'><b>$score[6]</b></td>
		  </tr>
	  	</table><br>
		<table class='layout' width='100%'>
			<tr><td><h3>Findings : </h3>".mn_pdf_list_fragment($score[7])."</td></tr>
			<tr><td><h3>Recommendations : </h3>".mn_pdf_list_fragment($score[8])."</td></tr>
			<tr><td><h3>Actions : </h3>".mn_pdf_list_fragment($score[9])."</td></tr>
			<tr><td><b>Reported by : </b>Kesang Wangchuk, Sr. Laboratory Officer<br><b>Reported generated on : </b>".date('d-m-Y')."</td></tr>
		</table><br>
		";
		
	require_once(__DIR__ . "/../includes/pdf.php");
	$mpdf=mn_pdf_create([
		'mode' => 'utf-8',
		'format' => 'A4',
		'default_font_size' => 11,
		'default_font' => '',
		'margin_left' => 20,
		'margin_right' => 20,
		'margin_top' => 60,
		'margin_bottom' => 20,
		'margin_header' => 5,
		'margin_footer' => 5,
		'orientation' => 'P',
	]);
	$mpdf->mirrorMargins = 1;
	$header = array (
		'C' => array (
		  'content' => '',
		  'font-size' => 10,
		  'font-style' => 'B',
		  'font-family' => 'arial',
		  'color'=>'#333333'
		)
	);
	
	$footer = array (
		'C' => array (
		  'content' => '- {PAGENO} - | mNEQAS | EPABX: +975-2-3505-77/78 | www.rcdc.gov.bt',
		  'font-size' => 8,
		  'font-style' => '',
		  'font-family' => 'arial',
		  'color'=>'#333333'
		)
	);

	$mpdf->SetTitle('RDT Panel Testing Report');

	// ==================== ROBUST LOGO HEADER ====================
	// IMPORTANT: header.png is an RGBA (alpha-channel) PNG, which mPDF can render
	// ONLY when the GD extension is enabled. GD is currently OFF in php.ini, so
	// header.jpg (no alpha) is preferred — mPDF renders JPEGs without GD. The PNG
	// remains as a fallback for environments where GD is enabled.
	$headerHtml = '';
	$possibleLogoPaths = [
	    $_SERVER["DOCUMENT_ROOT"] . "/MNEQAS/dist/img/header.jpg",   // Best - no alpha
	    $_SERVER["DOCUMENT_ROOT"] . "/MNEQAS/dist/img/header.png",
	    $_SERVER["DOCUMENT_ROOT"] . "/MNEQAS/dist/img/Logo.png",
	    $_SERVER["DOCUMENT_ROOT"] . "/dist/img/header.jpg",
	    $_SERVER["DOCUMENT_ROOT"] . "/images/header.jpg"
	];
	$logoData = null;
	$mime = 'image/png';
	foreach ($possibleLogoPaths as $path) {
	    if (is_file($path) && is_readable($path)) {
	        $imgData = @file_get_contents($path);
	        if ($imgData !== false) {
	            $logoData = $imgData;
	            $info = @getimagesize($path);
	            if ($info && !empty($info['mime'])) $mime = $info['mime'];
	            break;
	        }
	    }
	}
	if ($logoData !== null) {
	    $headerHtml = '<div style="text-align:center; margin-bottom:10px;">'
	                . '<img src="data:' . $mime . ';base64,' . base64_encode($logoData) . '" style="max-width:100%; max-height:55mm; height:auto;" alt="RCDC Logo" />'
	                . '</div>';
	} else {
	    $headerHtml = '<div style="text-align:center; border-bottom:2px solid #00539C; padding:8px 0; margin-bottom:15px;">'
	                . '<span style="font-family:arial; font-size:18px; font-weight:bold; color:#00539C;">Royal Center for Disease Control (RCDC)</span><br>'
	                . '<span style="font-family:arial; font-size:13px; color:#333;">RDT Panel Testing Report</span>'
	                . '</div>';
	}
	$mpdf->SetHTMLHeader($headerHtml, 'O');
	$mpdf->SetHTMLHeader($headerHtml, 'E');
	$mpdf->SetFooter($footer,'O');
	$mpdf->SetFooter($footer,'E');
	$mpdf->SetDisplayMode('fullpage');
	$mpdf->WriteHTML($html);
	mn_pdf_download($mpdf, "RDT_Panel_Report.pdf");
	exit;
?>