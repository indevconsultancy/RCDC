<?php
	// PDF output integrity: buffer all output while building the report so stray
	// PHP notices/warnings/whitespace cannot corrupt the binary PDF stream. mPDF
	// aborts with "Data has already been sent to output" if anything precedes it.
	ob_start();
	// IMPORTANT: Keep this file in sync with blinded/pdf-blinded-feedbacks.php
	require_once("../config/session.php");
	require_once("../config/commondb-config.php");
  	require_once("../config/db-config.php"); 
	require_once("blinded-functions.php");
	require_once(__DIR__ . "/../includes/pdf.php");

	/**
	 * Drop inline font sizing/face from saved rich text so the cell keeps the
	 * report's own table font.
	 *
	 * The Summernote toolbar used on the Feedback step offers a font-size button,
	 * and pasted text brings font-family with it, so the stored HTML can carry
	 * declarations that mPDF then honours - printing that one cell in a different
	 * letter font and size from the Findings cell and every table around it.
	 * Only the font declarations go: bold, italic, underline, colour, lists and
	 * paragraph structure are preserved exactly as the evaluator saved them.
	 */
	function bl_pdf_table_font($html)
	{
		$html = (string)$html;
		if ($html === '') { return $html; }

		/* style="..." : remove only the font declarations, keep the rest. */
		$out = preg_replace_callback(
			'/\sstyle\s*=\s*(["\'])(.*?)\1/is',
			function ($m) {
				$keep = array();
				foreach (explode(';', $m[2]) as $decl) {
					if (trim($decl) === '') { continue; }
					$parts = explode(':', $decl, 2);
					$prop  = strtolower(trim($parts[0]));
					/* "font" is the shorthand, which also sets size and family. */
					if ($prop === 'font-family' || $prop === 'font-size' || $prop === 'font') { continue; }
					$keep[] = trim($decl);
				}
				return $keep ? ' style="' . implode('; ', $keep) . '"' : '';
			},
			$html
		);
		if ($out !== null) { $html = $out; }

		/* Legacy <font face="..." size="..."> - colour is left alone. */
		$out = preg_replace('/(<font\b[^>]*?)\sface\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/is', '$1', $html);
		if ($out !== null) { $html = $out; }
		$out = preg_replace('/(<font\b[^>]*?)\ssize\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/is', '$1', $html);
		if ($out !== null) { $html = $out; }

		return $html;
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
	/* th, td {
		border-style: dotted;
	  } */
	.text-center {
		text-align: center;
	}	
	</style>';

	$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
	// Independent authorization for the PDF endpoint: facility users only for their own facility.
	mn_require_record($conn, 'blinded', $id, 'plain');

	// Print gate: the feedback report is available only after Final Submit
	// (blinded_feedback.finalized = 1). The button in blinded.php is hidden until
	// finalized; this enforces the same rule server-side so a draft/incomplete
	// record cannot be printed via a direct URL. ($fid is a local int cast used
	// only for this guard; the existing queries below are left unchanged.)
	$fid = (int)$id;
	$finChk = $conn->query("SELECT finalized FROM blinded_feedback WHERE blinded_id = $fid");
	$finRow = $finChk ? $finChk->fetch_row() : null;
	if (!$finRow || (int)$finRow[0] !== 1) {
		ob_end_clean();
		echo "<!doctype html><meta charset='utf-8'><div style=\"font-family:Segoe UI,Arial,sans-serif;max-width:540px;margin:80px auto;text-align:center;color:#334155\">"
		   . "<h2 style=\"color:#b45309\">Report not available yet</h2>"
		   . "<p>This feedback report has not been finalized. Please complete <strong>Final Submit</strong> on the Review step before printing.</p>"
		   . "<p><a href=\"blinded.php?action=2&id=".$fid."\" style=\"color:#007bff;text-decoration:none\">&larr; Back to record</a></p></div>";
		exit;
	}

	$binfoqry=($conn->query("SELECT blinded_id, facility_id, month, year, total_microscopy, positive_slides, negative_slides, shipment_date, receive_date FROM blinded_informations WHERE blinded_id=".$id));
    $binfo = $binfoqry->fetch_row();
    $facility = ($commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$binfo[1]'"));
	$facility = $facility->fetch_row();
	$biMonth = showMonth($binfo[2]);
	
	$html .= "
		<div style='text-align:center; font-size:18px; font-weight: bold;'>National Malaria Reference Laboratory</div>
		<!--<div style='text-align:center; font-size:18px; font-weight: bold;'>Royal Centre for Disease Control</div>-->
		<div style='text-align:center; font-size:16px; font-weight: bold;'>National External Quality Assessment Scheme in Malaria Diagnosis</div>
		<div style='text-align:center; font-size:16px; font-weight: bold;'>Monthly MP slide cross-checking feedback</div><br>
		<table class='layout' width='100%' border='0'>
			<tr>					
				<td><strong>Name of Health Center: </strong></td><td>$facility[0]</td>
				<td><strong>Month: </strong></td><td class='text-center'>$biMonth, $binfo[3]</td>
			</tr>
			<tr>
				<td><strong>Shipment date: </strong></td><td>$binfo[7]</td>	
				<td><strong>Receive date: </strong></td><td>$binfo[8]</td>
			</tr>
			<tr>
				<td colspan='4'><strong>No. of Microscopy done: </strong>$binfo[4]</td>	
				
			</tr>
			<tr>
				<td><strong>Positive Slides: </strong></td><td>$binfo[5]</td>
				<td><strong>Negative Slides: </strong></td><td>$binfo[6]</td>
			</tr>	
		</table><br>";
	
	$html .= "		
		<table class='layout' width='100%'>
			<tr>
				<td class='text-center' rowspan=2>B/S<br>#</td>
				<td class='text-center' rowspan=2>Collection<br>Date</td>
				<td class='text-center' rowspan=2>Receive<br>Status</td>
				<td class='text-center' colspan=3>Species</td>
				<td class='text-center' colspan=2>Stages</td>
				<td class='text-center' colspan=2>Parasite</td>
				<td class='text-center' colspan=2>WBC</td>
				<td class='text-center' rowspan=2>Density<br>Ranges</td>
				<td class='text-center' colspan=2>Density</td>
			</tr>
			<tr>
				<td class='text-center'>RCDC</td><td class='text-center'>Lab</td><td>Chk</td>
				<td class='text-center'>RCDC</td><td class='text-center'>Lab</td>
				<td class='text-center'>RCDC</td><td class='text-center'>Lab</td>
				<td class='text-center'>RCDC</td><td class='text-center'>Lab</td>
				<td class='text-center'>RCDC</td><td class='text-center'>Lab</td>
			</tr>
		";
			$feedback = $conn->query("SELECT result_id, slide_no, slide_status, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density, c2_microscopy, c2_stage, c2_parasite_count, c2_wbc_count, c2_density, min_range, max_range, positivity_flag FROM blinded_results WHERE blinded_id='$id'");
			while($row = $feedback->fetch_row()) { 
				$denSrc = ($row[13] !== null && $row[13] !== '' && (int)$row[13] > 0)
					? (int)$row[13]
					: (($row[8] !== null && $row[8] !== '' && (int)$row[8] > 0) ? (int)$row[8] : null);
				$pCountSrc = ($row[11] !== null && $row[11] !== '') ? $row[11] : $row[6];
				$wCountSrc = ($row[12] !== null && $row[12] !== '') ? $row[12] : $row[7];
				$dr = blCalculateDensityAndRange($pCountSrc, $wCountSrc, $denSrc, $row[14], $row[15]);
				$html.="<tr>
							<td>$row[1]</td>
							<td>$row[3]</td>
							<td>$row[2]</td>
							<td>$row[9]</td><td>$row[4]</td><td>$row[16]</td>
							<td>$row[10]</td><td>$row[5]</td>
							<td>$row[11]</td><td>$row[6]</td>
							<td>$row[12]</td><td>$row[7]</td>
							<td class='text-center'>{$dr['range_txt']}</td>
							<td class='text-center'>{$dr['density_txt']}</td><td class='text-center'>$row[8]</td>
						</tr>";
		}	
		$html.="</table><br>";

		$score = ($conn->query("SELECT feedback_id, sensitivity, specificity, malaria_infection, parasite_species, parasite_stage, parasite_density, average, achievement, blinded_finding, blinded_recommendation, blinded_action, film_quality, stain_quality FROM blinded_feedback WHERE blinded_id='$id'"));
		$score = $score -> fetch_row();

		$html.="<table class='layout' width='100%'>
			<tr>
				<td><b>Parameters</b></td>
				<td class='text-center'><b>Score</b></td>
			</tr>
			<tr>
				<td>Sensitivity</td><td class='text-center'>$score[1]</td>
			</tr>
			<tr>  
				<td>Specificity</td><td class='text-center'>$score[2]</td>
			</tr>
			<tr>
				<td>% of Agreement(Malaria Infection Detection)</td><td class='text-center'>$score[3]</td>
			</tr>
			<tr>
				<td>% of Agreement(Parasite Species Identification)</td><td class='text-center'>$score[4]</td>
			</tr>
			<tr>  
				<td>% of Agreement(Parasite Stage Identification)</td><td class='text-center'>$score[5]</td>
			</tr>
			<tr>
				<td>% of Agreement(Parasite Density Calculation)</td><td class='text-center'>$score[6]</td>
			</tr>
			<tr>
				<td>% of Agreement(film quality)</td><td class='text-center'>$score[12]</td>
			</tr>
			<tr>
				<td>% of Agreement(stain quality)</td><td class='text-center'>$score[13]</td>
			</tr>
			<tr>
				<td><strong>Average score</strong></td><td class='text-center'><strong>$score[7]</strong></td>
			</tr>
			<tr>
				<td><b>Achievement</b></td><td class='text-center'><b>$score[8]</b></td>
			</tr>
	  	</table><br>";

	// Supporting/reference images for Action Taken
	$actImgQry = $conn->query("SELECT file_name FROM blinded_action_images WHERE blinded_id='$id' ORDER BY image_id ASC");
	$actImgHtml = '';
	if ($actImgQry && $actImgQry->num_rows > 0) {
		$actImgHtml .= '<div style="margin-top:8px;">';
		while ($ai = $actImgQry->fetch_assoc()) {
			$aiPath = __DIR__ . '/uploads/action-images/' . $ai['file_name'];
			if (file_exists($aiPath)) {
				$actImgHtml .= '<img src="' . $aiPath . '" style="max-height:75px; max-width:130px; margin-right:8px; margin-bottom:4px; border:1px solid #ccc; vertical-align:top;" /> ';
			}
		}
		$actImgHtml .= '</div>';
	}

	$html .= "<table class='layout' width='100%'>
			<tr><td style='background-color:#eef3f8; font-weight:bold; width:22%; vertical-align:top;'>Findings</td>
				<td style='text-align:justify;'>".($score[9]!='' ? mn_pdf_list_fragment($score[9]) : '&mdash;')."</td></tr>
			<tr><td style='background-color:#eef3f8; font-weight:bold; vertical-align:top;'>Recommendations</td>
				<td style='text-align:justify;'>".($score[10]!='' ? mn_pdf_list_fragment(bl_pdf_table_font($score[10])) : '&mdash;')."</td></tr>
			<tr><td style='background-color:#eef3f8; font-weight:bold; vertical-align:top;'>Action</td>
				<td style='text-align:justify;'>".($score[11]!='' ? mn_pdf_list_fragment($score[11]) : '&mdash;').$actImgHtml."</td></tr>
		</table><br>
		<table class='layout' width='100%'>
			<tr>
				<td width='50%'><b>Blinded Rechecked by:</b><br/>Jigme Lhendub<br/>Controller 1 (MLT)<br/>NMRL, RCDC</td>
				<td width='50%'><b>Report Validated and Prepared by:</b><br/>Kesang Wangchuk<br/>Controller 2 (Lab. Officer)<br/>NMRL, RCDC</td>
			</tr>
			<tr><td colspan=2><b>Report generated on: </b>".date('d-m-Y')."</td></tr>
		</table><br>
		<table class='layout' width='100%'>
			<tr><td colspan=4>Note:</td></tr>
			<tr>
				<td>>=90% (Excellent)</td><td>80<89% (Very Good)</td><td>70<79% (Good)</td><td><70% (Poor)</td>
			</tr>
		</table>
		";

	// ==================== ROBUST LOGO HEADER ====================
	$headerHtml = '';

	// Try multiple logo paths.
	// IMPORTANT: header.png is an RGBA (alpha-channel) PNG, which mPDF can render
	// ONLY when the GD extension is enabled. GD is currently OFF in php.ini, so
	// header.jpg (no alpha) is listed first — mPDF renders JPEGs without GD. The
	// PNGs remain as fallbacks for environments where GD is enabled.
	$possibleLogoPaths = [
	    $_SERVER["DOCUMENT_ROOT"] . "/MNEQAS/dist/img/header.jpg",   // Preferred - no alpha
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
	    $headerHtml = '<div style="text-align:center; margin-bottom:10px;">
	        <img src="data:' . $mime . ';base64,' . base64_encode($logoData) . '"
	             style="max-width:100%; max-height:55mm; height:auto;" alt="RCDC Logo" />
	    </div>';
	} else {
	    $headerHtml = '<div style="text-align:center; border-bottom:2px solid #00539C; padding:8px 0; margin-bottom:15px;">
	        <span style="font-family:arial; font-size:18px; font-weight:bold; color:#00539C;">Royal Center for Disease Control (RCDC)</span><br>
	        <span style="font-family:arial; font-size:13px; color:#333;">National Malaria Reference Laboratory — mNEQAS Blinded Rechecking</span>
	    </div>';
	}

	// ==================== mPDF SETUP ====================
	require_once(__DIR__ . "/../includes/pdf.php");

	$mpdf = mn_pdf_create([
	    'mode' => 'utf-8',
	    'format' => 'A4',
	    'default_font_size' => 11,
	    'margin_left' => 15,
	    'margin_right' => 15,
	    'margin_top' => 65,
	    'margin_bottom' => 20,
	    'margin_header' => 5,
	    'margin_footer' => 5,
	    'orientation' => 'P',
	]);

	$mpdf->mirrorMargins = true;
	$mpdf->SetTitle('Blinded Rechecking Report - ' . $id);

	$mpdf->SetHTMLHeader($headerHtml, 'O');
	$mpdf->SetHTMLHeader($headerHtml, 'E');

	$footer = ['C' => ['content' => '- {PAGENO} - | mNEQAS | EPABX: +975-2-3505-77/78 | www.rcdc.gov.bt', 'font-size' => 8, 'font-family' => 'arial', 'color' => '#333333']];
	$mpdf->SetFooter($footer, 'O');
	$mpdf->SetFooter($footer, 'E');

	$mpdf->SetDisplayMode('fullpage');
	$mpdf->WriteHTML($html);
	mn_pdf_download($mpdf, "Blinded_Report_$id.pdf");
	exit;
?>