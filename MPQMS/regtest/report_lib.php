<?php
/*
 * Shared MPQMS Analytical Report generator.
 * Used by pdf_report.php (download) and by the approval flow (archive a read-only PDF snapshot).
 * Business content is unchanged from the original report; this only centralises generation and
 * adds the Label Claim Amount, the one-year retention note, and per-user e-signatures.
 */

require_once(dirname(__DIR__)."/includes/db_names.php");   // real database names (local or prefixed host)

if(!function_exists('mpqms_docroot')){
	function mpqms_docroot(){
		$d = $_SERVER["DOCUMENT_ROOT"] ?? '';
		// No usable DOCUMENT_ROOT (e.g. the command-line email worker): use this file's real location,
		// two levels up (regtest -> MPQMS -> web root). Correct on Windows and Linux alike.
		if($d === '' || !is_dir($d.'/MPQMS')){ $d = dirname(dirname(__DIR__)); }
		return rtrim(str_replace('\\','/',$d), '/');
	}
}

if(!function_exists('mpqms_signature_block')){
    /*
     * Returns user name(s) for a particular report role.
     * Example:
     *     Analyzed By
     *     Verified & Approved By
     */
    function mpqms_signature_block($commondb, $regid, $roleCol){

        $regid = intval($regid);

        $allowedRoles = array('testedBy', 'approvedBy');

        if(!in_array($roleCol, $allowedRoles, true)){
            return '&nbsp;';
        }

        $q = mysqli_query(
            $commondb,
            "SELECT DISTINCT ut.username, ut.fullname
             FROM `".mpq_db($commondb, 'ndtlisdb')."`.labresulttable rt
             INNER JOIN user_profile_table ut
                ON rt.$roleCol = ut.username
             WHERE rt.registrationID='$regid'
             AND rt.$roleCol <> ''
             ORDER BY ut.fullname ASC"
        );

        $cells = array();

        while(list($uname, $fname) = mysqli_fetch_row($q)){

            $cells[] =
                '<span style="display:inline-block; margin-right:18px; font-size:11px;">'
                .htmlspecialchars($fname, ENT_QUOTES, 'UTF-8')
                .'</span>';
        }

        return $cells ? implode('', $cells) : '&nbsp;';
    }
}
if(!function_exists('mpqms_digital_signature_block')){

    function mpqms_digital_signature_block($commondb, $connection, $regid){

        $regid = intval($regid);

        // 1. Prefer signature stored in labresulttable (newest feature)
        $q = mysqli_query($connection,
            "SELECT digital_signature, signed_by, signature_date 
             FROM labresulttable 
             WHERE registrationID = '$regid' 
               AND digital_signature IS NOT NULL 
               AND digital_signature <> ''
             ORDER BY signature_date DESC 
             LIMIT 1"
        );

        if ($q && mysqli_num_rows($q) > 0) {
            list($sigData, $signer, $sigDate) = mysqli_fetch_row($q);

            // data:image/...;base64,... can be used directly by mPDF
            return
                '<div style="text-align:left;">'
                .'<img src="'.htmlspecialchars($sigData, ENT_QUOTES, 'UTF-8').'" '
                .'style="height:50px; max-width:200px; object-fit:contain;">'
                .'<br><span style="font-size:10px;">'.htmlspecialchars($signer).'</span>'
                .'<br><span style="font-size:9px;color:#666;">'.htmlspecialchars($sigDate).'</span>'
                .'</div>';
        }

        // 2. Fallback to old file-based signature (username.png)
        $sigDir = mpqms_docroot().'/MPQMS/dist/signatures/';

        $q2 = mysqli_query($commondb,
            "SELECT DISTINCT ut.username, ut.fullname
             FROM `".mpq_db($commondb, 'ndtlisdb')."`.labresulttable rt
             INNER JOIN user_profile_table ut ON rt.approvedBy = ut.username
             WHERE rt.registrationID='$regid' AND rt.approvedBy <> ''
             ORDER BY ut.fullname ASC LIMIT 1"
        );

        if (!$q2 || mysqli_num_rows($q2) == 0) {
            return '<span style="font-size:10px;color:#666;">Not Available</span>';
        }

        list($uname, $fname) = mysqli_fetch_row($q2);

        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $path = $sigDir . $uname . '.' . $ext;
            if (is_file($path)) {
                return
                    '<div style="text-align:left;">'
                    .'<img src="'.htmlspecialchars($path, ENT_QUOTES, 'UTF-8').'" '
                    .'style="height:45px; max-width:180px; object-fit:contain;">'
                    .'</div>';
            }
        }

        return '<span style="font-size:10px;color:#666;">Digital signature not available</span>';
    }
}

if(!function_exists('mpqms_render_report')){
	// $dest: 'D' = download to browser, 'F' = write to $filepath, 'I' = inline to browser.
	function mpqms_render_report($regid, $connection, $commondb, $dest='D', $filepath=null){
		// mPDF (CssManager + font loading + header image) needs more than the server's
		// default 128 MB. Raise per-request only, so no other endpoint or user is affected.
		// PCRE limits stop AdjustHTML's whole-string regexes from tripping on long reports.
		@ini_set('memory_limit', '512M');
		@ini_set('pcre.backtrack_limit', '50000000');
		@ini_set('pcre.recursion_limit', '50000000');
		$regid = intval($regid);
		$html = '
	<style>
	table { border-collapse: collapse; }
	table.layout { border: .5px solid black; border-collapse: collapse; }
	td { padding: 3px; font-size:11px; border: .5px solid black; vertical-align: middle; text-align: left; }
	</style>';

		list($sid,$edlid,$edloth,$pcl,$pcloth,$qty,$bno,$mfgid,$mfgoth,$dom,$doe,$cbyid,$cbyoth,$comp,$desp,$labelclaim) = mysqli_fetch_row(mysqli_query($connection,"SELECT sampleID,edlID,edlOther,pharmaClaim,pharmaOther,sampleQty,batchNo,manufacturer,manufacturerOther,mfgDate,expDate,collectID,collectOther,composition,description,labelClaim FROM registrationtable WHERE registrationID='$regid'"));
		list($edl) = mysqli_fetch_row(mysqli_query($connection,"SELECT edl FROM msedltable WHERE id='".$edlid."'"));
		if($edlid == 999){	$edl = $edloth;}
		if($pcl == "Others"){	$pcl = $pcloth;}
		list($cby) = mysqli_fetch_row(mysqli_query($commondb,"SELECT center_name FROM ms_health_center_table WHERE center_id='".$cbyid."'"));
		if($cby == 999){	$cby = $cbyoth;}
		list($mfg) = mysqli_fetch_row(mysqli_query($connection,"SELECT name FROM msmanufacturertable WHERE id='".$mfgid."'"));
		if($mfgid == 999){	$mfg = $mfgoth;}
		list($commdt,$compdt)=mysqli_fetch_row(mysqli_query($connection,"SELECT MIN(testDate),MAX(testDate) FROM labresulttable WHERE registrationID='$regid'"));

		$sampleStatusResult = mysqli_query($connection,"SELECT status, receiveDate, remarks FROM samplestatustable WHERE registrationID='$regid'");
		if(mysqli_num_rows($sampleStatusResult) > 0) {
			list($sampleStatus, $receiveDate, $statusRemarks) = mysqli_fetch_row($sampleStatusResult);
		} else {
			$sampleStatus = "Not Recorded"; $receiveDate = "-"; $statusRemarks = "";
		}

		$html .= '
		<div style="text-align:center; font-size:14px;"><b>ANALYTICAL REPORT</b></div><br>
		<div style="text-align:right; font-size:11px;">Report Date:'.date("Y-m-d").'</div><br>
		<table class="layout" width="100%" >
			<tr>
				<td><strong>Sample ID</strong></td><td>'.$sid.'</td>
				<td><strong>Name of Sample</strong></td><td>'.$edl.'</td>
			</tr>
			<tr>
				<td><strong>Pharma. Claim :</strong></td><td>'.$pcl.'</td>
				<td><strong>Batch Number :</strong></td><td>'.$bno.'</td>
			</tr>
			<tr>
				<td><strong>Label Claim Amount :</strong></td><td colspan=3>'.htmlspecialchars($labelclaim ?? "").'</td>
			</tr>
			<tr>
				<td><strong>Manufacturer</strong></td><td colspan=3>'.$mfg.'</td>
			</tr>
			<tr>
				<td><strong>Manufacturing Date</strong></td><td>'.$dom.'</td>
				<td><strong>Expiry Date</strong></td><td>'.$doe.'</td>
			</tr>
			<tr>
				<td><strong>Test Commencement Date</strong></td><td>'.$commdt.'</td>
				<td><strong>Test Completion Date</strong></td><td>'.$compdt.'</td>
			</tr>
			<tr>
                <td><strong>Sample Quantity</strong></td>
                <td>'.$qty.'</td>
                <td><strong>Sample Collected By</strong></td>
                <td>'.$cby.'</td>
            </tr>
			<tr>
				<td><strong>Sample Acceptance</strong></td><td>'.$sampleStatus.'</td>
				<td><strong>Receive Date</strong></td><td>'.$receiveDate.'</td>
			</tr>
			<tr>
				<td><strong>Sample Description</strong></td><td>'.$desp.'</td>
				<td><strong>Composition</strong></td><td>'.$comp.'</td>
			</tr>
		</table><br>
		<table class="layout" width="100%" >
			<tr>
				<td style="text-align:center;font-weight: bold;">Test</td><td style="text-align:center;font-weight: bold;">Observation</td><td style="text-align:center;font-weight: bold;">Standard</td><td style="text-align:center;font-weight: bold;">Result</td>
			</tr>';

		$qry=mysqli_query($connection,"SELECT parameter,unit, observation,standard,result FROM mstestparametertable pt,labresulttable rt WHERE pt.id=rt.parameterID AND rt.registrationID='$regid' AND approval='Approved' ORDER BY ranking ASC");
		while(list($par,$unt,$obs,$std,$rslt)=mysqli_fetch_row($qry)){
			$html .= '<tr><td>'.$par.'('.$unt.')</td><td>'.$obs.'</td><td>'.$std.'</td><td>'.$rslt.'</td></tr>';
		}

		list($frslt,$frmks) = mysqli_fetch_row(mysqli_query($connection,"SELECT result,remarks FROM finalresulttable WHERE registrationID='$regid'"));
		$sigTested   = mpqms_signature_block($commondb, $regid, 'testedBy');
        $sigApproved = mpqms_signature_block($commondb, $regid, 'approvedBy');
        $sigDigital  = mpqms_digital_signature_block($commondb, $connection, $regid);

		$html .= '
		</table><br>
		<table class="layout" width="100%" >
			<tr>
				<td><b>Final Result</b></td><td><i><b>'.$frslt.'</b></i></td>
			</tr>
			<tr>
				<td><b>Remarks</b></td><td>'.$frmks.'</td>
			</tr>
		</table><br><br>
		<div style="text-align:center;font-weight: bold;">*** END OF REPORT ***</div><br>

		<div style="font-family:arial;font-size:11px;">
		<table width="100%" border="0" cellpadding="3">

    <tr>
        <td width="30%" style="vertical-align:middle;">
            <b>Analyzed By</b>
        </td>
        <td width="70%" style="vertical-align:middle;">
            '.$sigTested.'
        </td>
    </tr>

    <tr>
        <td width="30%" style="vertical-align:middle;">
            <b>Verified &amp; Approved By</b>
        </td>
        <td width="70%" style="vertical-align:middle;">
            '.$sigApproved.'
        </td>
    </tr>

    <tr>
        <td width="30%" style="vertical-align:middle;">
            <b>Digital Signature (Final Result)</b>
        </td>
        <td width="70%" style="vertical-align:middle;">
            '.$sigDigital.'
        </td>
    </tr>

</table>
		</div>
		<br>
		<div style="text-align:left;font-family:arial;font-size:11px;">
			<i>Note:</i>
			<ol>
				<li><i>The results apply to the samples as received and the results listed refer only to the tested samples and applicable parameters.</i></li>
				<li><i>The report shall not be reproduced wholly or in parts without prior approval from the Laboratory.</i></li>
				<li><i>All samples which are being submitted for the laboratory testing shall be retained for a period of one year prior to their disposal.</i></li>
				<li><i>This electronically generated report shall not be used to reject any samples or in the court of law.</i></li>
			</ol>
		</div>
		';

		require_once(mpqms_docroot()."/MPQMS/mpdf/vendor/autoload.php");
		$mpdf=new \Mpdf\Mpdf(array(
			'mode' => 'utf-8', 'format' => 'A4', 'default_font_size' => 11, 'default_font' => 'arial',
			'margin_left' => 20, 'margin_right' => 20, 'margin_top' => 40, 'margin_bottom' => 20,
			'margin_header' => 5, 'margin_footer' => 5, 'orientation' => 'P',
		));
		$mpdf->mirrorMargins = 1;
		$footer = array('C' => array(
			'content' => '- {PAGENO} - | Medical Product Quality Monitoring System | EPABX: +975-2-3505-77/78 | www.rcdc.gov.bt',
			'font-size' => 8, 'font-style' => '', 'font-family' => 'arial', 'color'=>'#333333'));
		$mpdf->SetTitle('Laboratory Test Result');
		$mpdf->SetHTMLHeader('<img src="'.mpqms_docroot().'/MPQMS/dist/img/header.jpeg"/>','E');
		$mpdf->SetHTMLHeader('<img src="'.mpqms_docroot().'/MPQMS/dist/img/header.jpeg"/>','O');
		$mpdf->SetFooter($footer,'O');
		$mpdf->SetFooter($footer,'E');
		$mpdf->SetDisplayMode('fullpage');
		$mpdf->WriteHTML($html);

		// Oct 2026 — mPDF streams PDF bytes with its own headers; any stray output
		// (BOM, PHP notice, warning) in the buffer breaks the file and yields a
		// "Data has already been sent to output" fatal. Drain every open buffer
		// before Output(). 'F' writes to disk so no buffer concern, but we clean
		// anyway to keep the two paths symmetric.
		if(function_exists('ob_get_level')){ while(ob_get_level() > 0){ @ob_end_clean(); } }

		if($dest === 'F' && $filepath){ $mpdf->Output($filepath, 'F'); }
		else if($dest === 'I'){ $mpdf->Output('Report_'.$regid.'.pdf', 'I'); }
		else { $mpdf->Output('Reports.pdf', 'D'); }
	}
}
?>
