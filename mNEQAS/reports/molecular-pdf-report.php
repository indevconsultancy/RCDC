<?php
  // PDF output integrity: buffer all output while building the report so stray
  // PHP notices/warnings/whitespace cannot corrupt the binary PDF stream. mPDF
  // aborts with "Data has already been sent to output" if anything precedes it.
  ob_start();
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php");
  require_once("../config/session.php");
  // Independent authorization for the PDF endpoint (patient data): every requested
  // sample id must exist and belong to the caller's facility (RCDC: any facility).
  $authIds = (isset($_POST['check_list']) && is_array($_POST['check_list'])) ? $_POST['check_list'] : array();
  foreach ($authIds as $authK => $authSel) {
    if (!ctype_digit((string)$authSel)) { mn_deny_plain('Invalid sample reference.'); }
    mn_require_record($conn, 'dbs', (int)$authSel, 'plain', 'You are not authorised to print one or more of the selected samples.');
    $_POST['check_list'][$authK] = (string)(int)$authSel;
  }
?>
<?php
$html = '
	<style>
	table { border-collapse: collapse; }
	table.layout {
		border: 1px solid grey;
		border-collapse: collapse;
	}
	td {
		padding: 3px;
		font-size:11px;
		border: 1px solid grey;
		vertical-align: middle;
		text-align: center;
	}
	th {
		padding: 3px;
		font-size:11px;
		/*
		background-color: green;
		background-color:#0A9C0A;
		*/
		background-color:#a6ff4c;
		text-align: center;
		border: 1px solid grey;
		vertical-align: middle;
	}
	</style>';

	$html.='
		<h1  style="text-align:center; font-size:13px; color:#333333; ">Test Center: National Malaria Reference Laboratory</h1>
		<!--<h4  style="text-align:left; font-size:13px; color:#333333; ">Test Centre:<i> NMRL</i></h4>-->';
    
        $html.='<table class="layout" width="100%" >
			<tr>
                <th>Sl. No</th>
                <th>BSE No</th>
                <th>Health Center</th>
                <th>Patient Name</th>
                <th>Age</th>
                <th>Sex</th>
                <th>Collection Date</th>
                <th>PCR Result</th>
                <th>PCR Date</th>
                <th>Remarks</th>
			</tr>';
            if(!empty($_POST['check_list'])){
                $sl=0;
                foreach($_POST['check_list'] as $selected){
                    $sl+=1;
                    $msqry = "SELECT t1.dbs_id, t1.bse_no, t2.center_name, t1.patient_name, t1.age, t1.gender, t1.collection_date, t1.pcr, t1.pcr_date, t1.pcr_remarks, t3.dzongkhag_name FROM dbs_results t1, rcdc_commondb.ms_health_center_table t2, rcdc_commondb.ms_dzongkhag_table t3 WHERE t1.dbs_id='$selected' AND t1.facility_id=t2.center_id AND t2.dzongkhag_code=t3.dzongkhag_code ORDER BY t1.pcr_date DESC, t2.center_name DESC";
                    $result =$conn->query($msqry);
                    $row = $result->fetch_row();
                    $html.= "<tr class='text-center'>
                          <td>$sl</td>
                          <td>$row[1]</td>
                          <td>$row[2]</td>
                          <td>$row[3]</td>
                          <td>$row[4]</td>
                          <td>$row[5]</td>
                          <td>$row[6]</td>
                          <td>$row[7]</td>
                          <td>$row[8]</td>
                          <td>$row[9]</td>
                        </tr>";
                }
            }
            $html.='</table> <br/>';
            $today = date("d-m-Y");
            $html.="<b>Test Performed and Analysed By:</b><br/>
            Kesang Wangchuk <br/>
            Sr. Laboratory Officer, <br/>
            National Malaria Reference Laboratory, RCDC <br/>
            <b>Date:$today";


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

		$mpdf->SetTitle('Molecular Report');

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
		                . '<span style="font-family:arial; font-size:13px; color:#333;">Molecular (DBS) Report</span>'
		                . '</div>';
		}
		$mpdf->SetHTMLHeader($headerHtml, 'O');
		$mpdf->SetHTMLHeader($headerHtml, 'E');
		$mpdf->SetFooter($footer,'O');
		$mpdf->SetFooter($footer,'E');
		$mpdf->SetDisplayMode('fullpage');
		$mpdf->WriteHTML($html);
		mn_pdf_download($mpdf, "MolecularReport.pdf");
		exit;
    

?>