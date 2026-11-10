<?php
	// PDF output integrity: buffer all output while building the report so stray
	// PHP notices/warnings/whitespace cannot corrupt the binary PDF stream. mPDF
	// aborts with "Data has already been sent to output" if anything precedes it.
	ob_start();
	require_once("../config/session.php");
	require_once(__DIR__ . "/../includes/pdf.php");
	require_once("../config/commondb-config.php");
  	require_once("../config/db-config.php"); 
	
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

	$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
	$rid = isset($_GET["rid"]) ? (int)$_GET["rid"] : 0;
	// Independent authorization for the PDF endpoint: own facility's panel, lab result belongs
	// to it, and facility users only after the round has CLOSED (same rule as panels.php link).
	mn_require_record($conn, 'panel', $id, 'plain');
	mn_require_child($conn, 'panel_info', $rid, $id, 'plain');
	if (!mn_is_rcdc()) {
		$authState = mn_panel_state($conn, 'micro', $id);
		if (!$authState || !$authState['closed']) { mn_deny_plain('The report is released to facilities after the panel round closes.', $id); }
	}
	$panel =($conn->query("SELECT t2.panel_round, t3.center_name, t2.shipment_date, t1.receive_date FROM panel_informations t1, panel_round_informations t2, rcdc_commondb.ms_health_center_table t3 WHERE t1.facility_id=t3.center_id AND t1.round_id=t2.round_id AND t1.panel_id='$id'"));
	$panel = $panel -> fetch_row();
	$lab1 = ($conn->query("SELECT examiner, designation, category, examination_date, timeliness FROM panel_lab_result_informations WHERE information_id='$rid'"));
	$lab1 = $lab1 -> fetch_row() ?: array_fill(0, 5, '');
	
	$html .= "
		<div style='text-align:center; font-size:18px; font-weight: bold;'>National Malaria Reference Laboratory</div>
		<div style='text-align:center; font-size:16px; font-weight: bold;'>National External Quality Assessment Scheme in Malaria Diagnosis</div>
		<div style='text-align:center; font-size:16px; font-weight: bold;'>Malaria Panel Testing Report</div><br>
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
				<td><strong>Reporting status</strong></td><td>$lab1[4]</td>	
				<td colspan=2></td>
			</tr>	
		</table><br>";
	
	$html .= "		
		<table class='layout' width='100%'>
			<tr>
				<td rowspan=2 class='text-center' width='15%'><b>Slide<br>#</b></td>
				<td rowspan=2 class='text-center' width='10%'><b>Receive<br>Status</b></td>
				<td colspan=3 class='text-center' width='15%'><b>Species</b></td>
				<td colspan=2 class='text-center' width='10%'><b>Stages</b></td>
				<td colspan=2 class='text-center' width='10%'><b>Parasite</b></td>
				<td colspan=2 class='text-center' width='10%'><b>WBC</b></td>
				<td rowspan=2 class='text-center' width='15%'><b>Density<br>Ranges</b></td>
				<td colspan=2 class='text-center' width='15%'><b>Density</b></td>
			</tr>
			<tr class='text-center'>
				<td class='text-center'><b>RCDC</b></td><td class='text-center'><b>Lab</b></td><td class='text-center'><b>Chk</b></td>
				<td class='text-center'><b>RCDC</b></td><td class='text-center'><b>Lab</b></b></td>
				<td class='text-center'><b>RCDC</b></td><td class='text-center'><b>Lab</b></b></td>
				<td class='text-center'><b>RCDC</b></td><td class='text-center'><b>Lab</b></b></td>
				<td class='text-center'><b>RCDC</b></td><td class='text-center'><b>Lab</b></b></td>
			</tr>	
		";
		$slide = $conn->query("SELECT slide_id, slide_number, slide_status FROM panel_slide_informations WHERE panel_id='$id'");
		while($row = $slide->fetch_row()) { 
			$rcdc = ($conn->query("SELECT rcdc_species, rcdc_stages, rcdc_parasite_count, rcdc_wbc_count, rcdc_parasite_density, min_range, max_range FROM panel_rcdc_results WHERE slide_id='$row[0]'"));
			$rcdc = $rcdc -> fetch_row() ?: array_fill(0, 7, '');
			$lab2qry = "SELECT lab_species, lab_stages, lab_parasite_count, lab_wbc_count, lab_parasite_density, positivity_flag FROM panel_lab_results WHERE information_id='$rid' AND slide_id='$row[0]'";
			$chkLab = ($conn->query("SELECT COUNT(*) FROM panel_rcdc_results WHERE slide_id='$row[0]'"));
			$chkLab = $chkLab -> fetch_row(); 
			if($conn->query($lab2qry) -> num_rows > 0) { $lab2 = ($conn->query($lab2qry)); $lab2 = $lab2 -> fetch_row(); }
			else  $lab2 = array_fill(0, 6, '');
		
			$html.="<tr>
				<td>$row[1]</td>
				<td>$row[2]</td>
				<td>$rcdc[0]</td><td>$lab2[0]</td><td class='text-center'>$lab2[5]</td>
				<td>$rcdc[1]</td><td>$lab2[1]</td>
				<td class='text-center'>$rcdc[2]</td><td class='text-center'>$lab2[2]</td>
				<td class='text-center'>$rcdc[3]</td><td class='text-center'>$lab2[3]</td>
				<td class='text-center'>$rcdc[5] - $rcdc[6]</td>
				<td class='text-center'>$rcdc[4]</td><td class='text-center'>$lab2[4]</td>
			</tr>";	
		}	
		$html.="</table><br>";

		$score = ($conn->query("SELECT sensitivity, specificity, infection, species, stages, density, avg_score, achievement, findings, recommendation, action FROM panel_lab_result_informations WHERE information_id='$rid'"));
		$score = $score -> fetch_row() ?: array_fill(0, 11, '');
		$delete = "<a href='delete.php?id=$id&rid=$rid&type=fe' class='btn bg-gradient-danger'>Delete</a>";

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
			<td>% of Agreement(Malaria Infection Detection)</td><td class='text-center'>$score[2]</td>
		  </tr>
		  <tr>
			<td>% of Agreement(Parasite Species Identification)</td><td class='text-center'>$score[3]</td>
		  </tr>
		  <tr>  
			<td>% of Agreement(Parasite Stage Identification)</td><td class='text-center'>$score[4]</td>
		  </tr>
		  <tr>
			<td>% of Agreement(Parasite Density Calculation)</td><td class='text-center'>$score[5]</td>
		  </tr>
		  <tr>
			<td><b>Average score</b></td><td class='text-center'><b>$score[6]</b></td>
		  </tr>
		  <tr>
			<td><b>Achievement</b></td><td class='text-center'><b>$score[7]</b></td>
		  </tr>
	  	</table><br>
		<table class='layout' width='100%'>
			<tr><td><h3>Findings : </h3>".mn_pdf_list_fragment($score[8])."</td></tr>
			<tr><td><h3>Recommendations : </h3>".mn_pdf_list_fragment($score[9])."</td></tr>
			<tr><td><h3>Actions : </h3>".mn_pdf_list_fragment($score[10])."</td></tr>
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

	$mpdf->SetTitle('Panel Testing Report');

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
	                . '<span style="font-family:arial; font-size:13px; color:#333;">Micro Panel Testing Report</span>'
	                . '</div>';
	}
	$mpdf->SetHTMLHeader($headerHtml, 'O');
	$mpdf->SetHTMLHeader($headerHtml, 'E');
	$mpdf->SetFooter($footer,'O');
	$mpdf->SetFooter($footer,'E');
	$mpdf->SetDisplayMode('fullpage');
	$mpdf->WriteHTML($html);
	mn_pdf_download($mpdf, "Panel_Report.pdf");
	exit;
?>