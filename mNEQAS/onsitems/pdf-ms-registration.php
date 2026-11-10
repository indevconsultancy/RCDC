<?php
	// PDF output integrity: buffer all output while building the report so stray
	// PHP notices/warnings/whitespace cannot corrupt the binary PDF stream. mPDF
	// aborts with "Data has already been sent to output" if anything precedes it.
	ob_start();
	require_once("../config/session.php");
	require_once("../config/commondb-config.php");
  	require_once("../config/db-config.php");
	require_once(__DIR__ . "/ms-findings-functions.php");

	/* MS_ACTION_IMAGES_START
	   Build the "Uploaded Image / Evidence" block from onsite_action_images for one
	   record. Read-only: it only reads files the existing uploader already stored.
	   - Only files inside $baseDir are used (basename + realpath containment), so a
	     stored path can never point elsewhere on the server.
	   - Missing, unreadable, corrupt or unsupported files are skipped silently, so a
	     bad image never breaks the report.
	   - Images are embedded as data URIs (same as the header logo) and scaled
	     proportionally to fit the 170 mm A4 content width, never upscaled.
	   Returns '' when there is nothing to show. */
	function msActionImagesHtml($conn, $id, $baseDir){
		try {
			$base = realpath($baseDir);
			if ($base === false || (int)$id <= 0) { return ''; }
			$gid = (int)$id;
			$st  = $conn->prepare("SELECT image_path FROM onsite_action_images WHERE general_info_id = ? ORDER BY image_id ASC");
			$st->bind_param('i', $gid);
			$st->execute();
			$res = $st->get_result();
			$paths = array();
			while ($r = $res->fetch_row()) { $paths[] = (string)$r[0]; }
			$st->close();

			$maxW = 170;   // mm: A4 width 210 - left/right margins 20+20
			$maxH = 110;   // mm: leaves room for the label on a 217 mm content page
			$blocks = ''; $n = 0;
			foreach ($paths as $p) {
				$file = basename(str_replace('\\', '/', $p));
				if ($file === '' || $file === '.' || $file === '..') { continue; }
				$full = realpath($base . DIRECTORY_SEPARATOR . $file);
				if ($full === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full) || !is_readable($full)) { continue; }
				$info = @getimagesize($full);
				if (!$info || $info[0] <= 0 || $info[1] <= 0) { continue; }
				$type = $info[2];
				$supported = ($type === IMAGETYPE_JPEG) || ($type === IMAGETYPE_PNG)
				          || ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp'));
				if (!$supported) { continue; }
				$data = @file_get_contents($full);
				if ($data === false || $data === '') { continue; }

				$wMm = $info[0] * 25.4 / 96;
				$hMm = $info[1] * 25.4 / 96;
				$scale = min(1, $maxW / $wMm, $maxH / $hMm);
				$w = round($wMm * $scale, 2);
				$h = round($hMm * $scale, 2);
				$n++;
				// Table + page-break-inside:avoid keeps each image with its caption and
				// moves it whole to the next page when it does not fit.
				$blocks .= "<table width='100%' style='page-break-inside:avoid; border:none; margin:4px 0 10px 0;'><tr>"
				        .  "<td style='border:none; text-align:center;'>"
				        .  "<img src='data:" . $info['mime'] . ";base64," . base64_encode($data) . "' style='width:{$w}mm; height:{$h}mm;' />"
				        .  "<div style='font-size:10px; color:#555;'>Image $n</div>"
				        .  "</td></tr></table>";
			}
			if ($n === 0) { return ''; }
			return "<div style='font-size:14px; font-weight: bold; margin-top:4px;'>Uploaded Image / Evidence</div>" . $blocks;
		} catch (\Throwable $e) {
			return '';   // images are supplementary; never fail the whole report
		}
	}
	/* MS_ACTION_IMAGES_END */

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

	// Validate the record id: a missing/non-numeric id used to reach the SQL
	// as-is, throw an uncaught mysqli exception (PHP 8 throws by default) and
	// leave the user with a blank tab instead of a PDF.
	$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
	// Independent authorization for the PDF endpoint: facility users only for their own facility.
	mn_require_record($conn, 'onsite', $id, 'plain');
    $generalqry = ($id > 0) ? $conn->query("SELECT gi_id, facility_id, date_visit, evaluator, lab_incharge, incharge_designation, audit_category FROM onsite_general_informations WHERE gi_id=".$id) : false;
    $generalqry = $generalqry ? $generalqry->fetch_row() : null;
    if (!$generalqry) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(404);
        exit('Report not found: there is no saved M&S record for this id. Please submit the checklist first, then use Download Report.');
    }

    $facility = ($commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$generalqry[1]'"));
    $facility = $facility->fetch_row();
    if (!$facility) { $facility = array(''); }

    $staffqry=$conn->query("SELECT staff_id, staff_name, staff_designation, training_frequency, training_days, last_training_date, training_topic FROM onsite_staff_informations WHERE general_info_id=".$id);
	
	$html .= "
		<div style='text-align:center; font-size:18px; font-weight: bold;'>National Malaria Reference Laboratory</div>
        <!--<div style='text-align:center; font-size:18px; font-weight: bold;'>Royal Centre for Disease Control</div>-->
		<div style='text-align:center; font-size:16px; font-weight: bold;'>National External Quality Assessment Scheme in Malaria Diagnosis</div>
		<div style='text-align:center; font-size:16px; font-weight: bold;'>Checklist for monitoring and supervisiory</div><br>
		<table class='layout' width='100%' >
			<tr>
				<td colspan=2><strong>Name of Health Centre</strong></td><td  colspan=2 class='text-center'>$facility[0]</td>
			</tr>
            <tr>
				<td><strong>Audit Category</strong></td><td>$generalqry[6]</td>
                <td><strong>Date of Visit</strong></td><td>$generalqry[2]</td>
			</tr>
			<tr>
				<td><strong>Name of Evaluator</strong></td><td>$generalqry[3]</td>	
				<td><strong>Designation of Evaluator</strong></td><td>$generalqry[5]</td>
			</tr>
		</table><br>";
	
	$html .= "		
		<table class='layout' width='100%'>
			<tr>
                <td colspan=6 class='text-center' width='15%'><b>Staff Details</b></td>
            </tr>
            <tr>
				<td rowspan=2 class='text-center' width='15%'><b>Name of staff</b></td>
				<td rowspan=2 class='text-center' width='10%'><b>Designation</b></td>
				<td colspan=4 class='text-center' width='15%'><b>Training related to malaria</b></td>
			</tr>
			<tr class='text-center'>
				<td class='text-center'><b>Frequency</b></td>
				<td class='text-center'><b>Days</b></td>
				<td class='text-center'><b>Last Date</b></td>
				<td class='text-center'><b>Topic</b></td>
			</tr>	
		";
		while($staff=$staffqry->fetch_row()){
            $html.="<tr>
                <td>$staff[1]</td>
                <td>$staff[2]</td>
                <td class='text-center'>$staff[3]</td>
                <td class='text-center'>$staff[4]</td>
                <td class='text-center'>$staff[5]</td>
                <td>$staff[6]</td>
            </tr>";
        }	
		$html.="</table><br>";

		$html.="<table class='layout' width='100%'>
            <tr>
                <td rowspan=2><b>Sl.No</b></td>
                <td rowspan=2 class='text-center'><b>Checklist Items with Categories</b></td>
                <td colspan=3 class='text-center'><b>Rating</b></td>
            </tr>
            <tr>
                <td class='text-center'>Yes</td>
                <td class='text-center'>No</td>
                <td class='text-center'>Remarks</td>
            </tr>";
            $catqry=$conn->query("SELECT category_id, category_value FROM ms_onsite_categories WHERE category_status = 1 ORDER BY category_order ASC");
            $catCount=0;
            $msNoFindings = array();   // professional findings for items currently rated "No"
            while ($cat=$catqry->fetch_row()) {
                $subcatqry=$conn->query("SELECT subcategory_id, subcategory_value FROM ms_onsite_subcategories WHERE subcategory_status = 1 AND category_id = '$cat[0]' ORDER BY subcategory_order ASC");

                $scoreqry = ($conn->query("SELECT score_value FROM onsite_checklists_rating_score WHERE general_info_id = '$id' AND category_id = '$cat[0]'"));
                if (($scoreqry->num_rows) > 0) {
                    $score = $scoreqry->fetch_row();
                    $scoreValue = $score[0];
                    $displayScore = '<label class="col-sm-12 text-success">'.$cat[1].' score: '.$scoreValue.'%</label>';
                }else {
                    $displayScore = '';
                }
                $catCount++;
                $html.="<tr><td colspan=5 class='text-center'><b>$catCount. $cat[1]</b></td></tr>";

                $subcatCount=0;
                while ($subcat=$subcatqry->fetch_row()) {
                    // Same source as the form: optional per-item findings column, else the checklist only
                    try {
                        $cklqry=$conn->query("SELECT checklist_id, checklist, findings FROM ms_onsite_checklists WHERE checklist_status = 1 AND subcategory_id = '$subcat[0]' ORDER BY checklist_order ASC");
                    } catch (\Throwable $e) { $cklqry = false; }
                    if (!$cklqry) {
                        $cklqry=$conn->query("SELECT checklist_id, checklist FROM ms_onsite_checklists WHERE checklist_status = 1 AND subcategory_id = '$subcat[0]' ORDER BY checklist_order ASC");
                    }
                    $subcatCount++;
                    $html.="<tr><td colspan=5 class='text-center'><i><b>$catCount.$subcatCount. $subcat[1]</b></i></td></tr>";

                    $chkcount = 0;
                    while ($cklist=$cklqry->fetch_row()) {
                        $datacnt = ($conn->query("SELECT rating_value FROM onsite_checklists_rating WHERE checklists_id = '$cklist[0]' AND general_info_id = '$id'"));
                        $datacnt = $datacnt->num_rows;
                        if ($datacnt>0) {
                            $data = ($conn->query("SELECT rating_id, rating_value, remarks FROM onsite_checklists_rating WHERE checklists_id = '$cklist[0]' AND general_info_id = '$id'"));
                            $data = $data->fetch_row();
                            $rid = $data[0];
                            $statusChk1 = $statusChk2 = $statusChk3 = "";
                            if($data[1] == 1) {
                                $statusChk1 = "<span style='font-family: Arial Unicode MS, Lucida Grande'>&#10004;</span>";
                            }elseif($data[1] == 0) {
                                $statusChk2 = "<span style='font-family: Arial Unicode MS, Lucida Grande'>&#10004;</span>";
                                if ((string)$data[1] === '0') {
                                    $msNoFindings[] = msNegativeFinding($cklist[1], $subcat[1], $cat[1], isset($cklist[2]) ? $cklist[2] : null);
                                }
                            }else{
                                $statusChk3 = "NA";
                            }
                        }else {
                            $data = array('', '', '');                                            
                            $statusChk1 = $statusChk2 = $statusChk3 = "";
                        }
                        $chkcount++;

                        $html.="<tr>
                            <td class='text-center'>$catCount.$subcatCount.$chkcount</td>
                            <td>$cklist[1]</td>
                            <td class='text-center'>$statusChk1</td>
                            <td class='text-center'>$statusChk2</td>
                            <td>$data[2]</td>
                        </tr>";
                    }
                }
                $html.="<tr><td colspan=5 class='text-center'><i> $displayScore </i></td></tr>";
            }
            $scoreavg = ($conn->query("SELECT AVG(score_value) FROM onsite_checklists_rating_score WHERE general_info_id = '$id'"));
            $average = $scoreavg->fetch_row();
            // AVG() always returns one row; the value is NULL when no scores exist yet.
            if ($average && $average[0] !== null) {
                $avg = round($average[0],2);
                $html.="<tr><td colspan=5 class='text-center'><b><i>Average Score: $avg%</i></b></td></tr>";
            }

        $html.="</table><br>";

        $ora = ($conn->query("SELECT ora_id, recommendation, actions, findings FROM onsite_recommendation_action WHERE general_info_id = '$id'"));
        $ora = $ora->fetch_row();
        if (!$ora) { $ora = array('', '', '', ''); }   // legacy records saved without a recommendation row
        /* Findings: the stored column when the record has one, otherwise rebuilt from the
           current "No" ratings so records saved before the split still print correctly. */
        $msFindingsHtml = (isset($ora[3]) && trim((string)$ora[3]) !== '')
                        ? (string)$ora[3]
                        : msFindingsBlockHtml($msNoFindings);
        /* Recommendation holds manual text only; drop any legacy embedded findings block. */
        $ora[1] = msStripFindingsBlock((string)$ora[1]);

        $html.="<br><div style='font-size:16px; font-weight: bold;'>Findings from Checklist</div>
        <div style='text-align: justify; text-justify: inter-word;'>$msFindingsHtml</div><br>
        <br><div style='font-size:16px; font-weight: bold;'>Recommendation</div>
        <div style='text-align: justify; text-justify: inter-word;'>$ora[1]</div><br>
        <br><div style='font-size:16px; font-weight: bold;'>Actions</div>
        <div style='text-align: justify; text-justify: inter-word;'>$ora[2]</div><br>";

        // Evidence images already uploaded against this record's Actions section.
        $html .= msActionImagesHtml($conn, $id, __DIR__ . '/../uploads/ms_actions');
		


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

	$mpdf->SetTitle('Onsite Supervision & Monitoring Report');

	// ==================== ROBUST LOGO HEADER ====================
	// IMPORTANT: header.png is an RGBA (alpha-channel) PNG, which mPDF can render
	// ONLY when the GD extension is enabled. GD is currently OFF in php.ini, so
	// header.jpg (no alpha) is preferred — mPDF renders JPEGs without GD. The PNG
	// remains as a fallback for environments where GD is enabled.
	$headerHtml = '';
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
	    $headerHtml = '<div style="text-align:center; margin-bottom:10px;">'
	                . '<img src="data:' . $mime . ';base64,' . base64_encode($logoData) . '" style="max-width:100%; max-height:55mm; height:auto;" alt="RCDC Logo" />'
	                . '</div>';
	} else {
	    $headerHtml = '<div style="text-align:center; border-bottom:2px solid #00539C; padding:8px 0; margin-bottom:15px;">'
	                . '<span style="font-family:arial; font-size:18px; font-weight:bold; color:#00539C;">Royal Center for Disease Control (RCDC)</span><br>'
	                . '<span style="font-family:arial; font-size:13px; color:#333;">Onsite Monitoring &amp; Supervision Report</span>'
	                . '</div>';
	}
	$mpdf->SetHTMLHeader($headerHtml, 'O');
	$mpdf->SetHTMLHeader($headerHtml, 'E');
	$mpdf->SetFooter($footer,'O');
	$mpdf->SetFooter($footer,'E');
	$mpdf->SetDisplayMode('fullpage');
	$mpdf->WriteHTML($html);
	mn_pdf_download($mpdf, "Monitoring_Supervisiory_$id.pdf");
	exit;
?>