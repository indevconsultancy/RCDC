<?php
	// PDF output integrity: buffer all output so stray notices/whitespace cannot
	// corrupt the binary PDF stream (mPDF aborts if any output precedes it).
	ob_start();
	require_once("../config/session.php");
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
    $prid = isset($_GET["prid"]) ? (int)$_GET["prid"] : 0;
	// Independent authorization: the request must be the caller's facility and the product must belong to it.
	mn_require_record($conn, 'rdt_request', $id, 'plain');
	mn_require_child($conn, 'rdt_product', $prid, $id, 'plain');
    $rtrqry=($conn->query("SELECT rdt_request_id, facility_id, date_request, staff_name, staff_designation, lot_type, date_shipment, date_receive, received_by, designation FROM rdt_test_request WHERE rdt_request_id=".$id));
        $trqry = $rtrqry->fetch_row();

        $facility = ($commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$trqry[1]'"));
        $facility = $facility ->fetch_row();

        $dzongkhag = ($commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$trqry[1]' )"));
        $dzongkhag = $dzongkhag->fetch_row();

        $productqry=$conn->query("SELECT product_id, product_name, manufacturer, lot_no, date_manufacture, date_expiry FROM rdt_product_details WHERE product_id=".$prid);
	
	$html .= "
		<div style='text-align:center; font-size:18px; font-weight: bold;'>National Malaria Reference Laboratory</div>
        <div style='text-align:center; font-size:18px; font-weight: bold;'>Royal Centre for Disease Control</div>
		<div style='text-align:center; font-size:16px; font-weight: bold;'>National External Quality Assessment Scheme in Malaria Diagnosis</div>
		<div style='text-align:center; font-size:16px; font-weight: bold;'>RDT lot test Report</div><br>

        <div style='font-size:13px; font-weight: bold;'>Request detail:</div>
		<table class='layout' width='100%' >
			<tr>
				<td colspan=4><strong>Name of Health Centre:</strong> $facility[0]</td>
			</tr>
            <tr>
				<td><strong>Date of lot testing request</strong></td><td>$trqry[2]</td>
                <td><strong>Type of lot test</strong></td><td>$trqry[5]</td>
			</tr>
			<tr>
				<td><strong>Name of the staff</strong></td><td>$trqry[3]</td>	
				<td><strong>Designation</strong></td><td>$trqry[4]</td>
			</tr>
		</table><br>";
	
	$html .= "		
        <div style='font-size:13px; font-weight: bold;'>RDT Details:</div>
        <table class='layout' width='100%'>
            <tr>
                <td class='text-center'><b>Product Name</b></td>
                <td class='text-center'><b>Manufacturer</b></td>
                <td class='text-center'><b>Lot No.</b></td>
                <td class='text-center'><b>Manufacturer Date</b></td>
                <td class='text-center'><b>Expiry Date</b></td>
			</tr>
		";
		while($product=$productqry->fetch_row()){
            $html.="<tr>
                <td class='text-center'>$product[1]</td>
                <td class='text-center'>$product[2]</td>
                <td class='text-center'>$product[3]</td>
                <td class='text-center'>$product[4]</td>
                <td class='text-center'>$product[5]</td>
            </tr>";
        }
		$html.="</table><br>";

		$html.="<div style='font-size:13px; font-weight: bold;'>Shipment Details:</div>
            <table class='layout' width='100%'>
            <tr>
                <td class='text-center' rowspan=2><b>Lot No.</b></td>
                <td class='text-center' colspan=2><b>Lab Shipment Information</b></td>
                <td class='text-center' colspan=2><b>RCDC Receipt Information</b></td>
            </tr>
            <tr>                
                <td class='text-center'><b>No of boxes</b></td>
                <td class='text-center'><b>No of test per box</b></td>
                <td class='text-center'><b>No of boxes</b></td>
                <td class='text-center'><b>No of test per box</b></td>
            </tr>";

            $lotqry = "SELECT lot_no, product_id FROM rdt_product_details WHERE product_id=".$prid;
            $prodqry=$conn->query($lotqry);
            while($prod=$prodqry->fetch_row()){
                $shipmentqry=($conn->query("SELECT shipment_id, sent_no_box, sent_test_per_box, received_no_box, received_test_per_box FROM rdt_shipment_details, rdt_product_details WHERE product_details_id =".$prod[1]));
                $shipmentqry=$shipmentqry->fetch_row() ?: array_fill(0, 5, '');
            $html.=" <tr>
                <td class='text-center'>$prod[0]</td>               
                <td class='text-center'>$shipmentqry[1]</td>
                <td class='text-center'>$shipmentqry[2]</td>
                <td class='text-center'>$shipmentqry[3]</td>
                <td class='text-center'>$shipmentqry[4]</td>
            </tr>";                
            }
        $html.="</table><br>";

        $html.="
            <table class='layout' width='100%'>
            <tr>
                <td><b>Shipment Date:</b> $trqry[6]</td>               
                <td><b>Date of receipt:</b> $trqry[7]</td>
                <td><b>Received By:</b> $trqry[8]</td>
                <td><b>Designation:</b> $trqry[9]</td>
            </tr>
            </table><br>";

        $html.="<div style='font-size:14px; font-weight: bold;'>Accessory Assessment Report:</div>
            <table class='layout' width='100%'>
            <tr>
                <td><b>Parameters</b></td>               
                <td><b>Yes</b></td>
                <td><b>No</b></td>
                <td><b>Comments</b></td>
            </tr>";
            $pdqry="SELECT product_id, product_name, lot_no FROM rdt_product_details WHERE product_id=".$prid;
            $prodqry=($conn->query($pdqry));
            $cklqry=$conn->query("SELECT rac_id, rac_value FROM ms_rdt_assesment_checklist WHERE rac_status = 1 ORDER BY rac_rank ASC");
            while ($product =  $prodqry->fetch_row()) {
                $html.="<tr>
                        <td colspan=4><b>Product:</b> $product[1]. &nbsp; <b>Lot No.:</b> $product[2]</td>
                    </tr>";
            
                while ($cklist=$cklqry->fetch_row()) {
                    $datacnt = $conn->query("SELECT rar_id, result, comments FROM rdt_assessment_report WHERE assesment_id = '$cklist[0]' AND manufacturer_id = $product[0]");
                    if ($datacnt->num_rows>0) {
                        $data = $datacnt->fetch_row();
                        $rac_id = $data[0];
                        $statusChk1 = $statusChk2 = $statusChk3 = "";
                        if($data[1] == 1) {
                            $statusChk1 = "<span style='font-family: Arial Unicode MS, Lucida Grande'>&#10004;</span>";
                        }elseif($data[1] == 0) {
                            $statusChk2 = "<span style='font-family: Arial Unicode MS, Lucida Grande'>&#10004;</span>";
                        }else{
                            $statusChk3 = "<span style='font-family: Arial Unicode MS, Lucida Grande'>&#10004;</span>";
                        }
                    }else {
                        $data = array('', '', '');                                            
                        $statusChk1 = $statusChk2 = $statusChk3 = "";
                    }
                $html.="<tr>
                    <td>$cklist[1]</td>               
                    <td>$statusChk1</td>
                    <td>$statusChk2</td>
                    <td>$data[2]</td>
                </tr>";
                }
            }
        $html.="</table><br>";

        $html.="<div style='font-size:14px; font-weight: bold;'>RDT performance Report:</div>
            <table class='layout' width='100%'>
            <tr>
                <td class='text-center' rowspan=2><b>Product Name</b></td>
                <td class='text-center' colspan=2><b>Panel Detection Score for PF (200p/ul)</b></td>               
                <td class='text-center' colspan=2><b>Panel Detection Score for PF (2000 p/ul)</b></td>
                <td class='text-center' rowspan=2><b>Invalid rate</b></td>                
            </tr>
            
            <tr>
                <td class='text-center'><b>Temperature</b></td>
                <td class='text-center'><b>Score</b></td>               
                <td class='text-center'><b>Temperature</b></td>
                <td class='text-center'><b>Score</b></td>                
            </tr>";
        $pfprodqry=($conn->query($pdqry));
        while ($product =  $pfprodqry->fetch_row()) {
            $rsltqry = ($conn->query("SELECT pf_pd_200_temp, pf_pds_200, pf_pd_2000_temp, pf_pds_2000, pf_ivr FROM rdt_performance_report WHERE manufacturer_id = $product[0]"));
            list($pf_pd_200_temp, $pf_pds_200, $pf_pd_2000_temp,$pf_pds_2000, $pf_ivr) = $rsltqry->fetch_row();
            $html.="<tr>
                    <td>$product[1]</td>               
                    <td>$pf_pd_200_temp</td>
                    <td>$pf_pds_200</td>
                    <td>$pf_pd_2000_temp</td>
                    <td>$pf_pds_2000</td>
                    <td>$pf_ivr</td>
                </tr>";
        }
        $html.="</table><br>";

        $html.="
        <table class='layout' width='100%'>
            <tr>
                <td class='text-center' rowspan=2><b>Product Name</b></td>
                <td class='text-center' colspan=2><b>Panel Detection Score for Pv (200p/ul)</b></td>               
                <td class='text-center' colspan=2><b>Panel Detection Score for Pv (2000 p/ul)</b></td>
                <td class='text-center' rowspan=2><b>Invalid rate</b></td>                
            </tr>
            
            <tr>
                <td class='text-center'><b>Temperature</b></td>
                <td class='text-center'><b>Score</b></td>               
                <td class='text-center'><b>Temperature</b></td>
                <td class='text-center'><b>Score</b></td>                
            </tr>";
            $pvprodqry=($conn->query($pdqry));
            while ($product =  $pvprodqry->fetch_row()) {
                $pvrsltqry = ($conn->query("SELECT pv_pd_200_temp, pv_pds_200, pv_pd_2000_temp, pv_pds_2000, pv_ivr FROM rdt_performance_report WHERE manufacturer_id = $product[0]"));
                list($pv_pd_200_temp, $pv_pds_200, $pv_pd_2000_temp,$pv_pds_2000, $pv_ivr) = $pvrsltqry->fetch_row();
                $html.="<tr>
                        <td>$product[1]</td>               
                        <td>$pv_pd_200_temp</td>
                        <td>$pv_pds_200</td>
                        <td>$pv_pd_2000_temp</td>
                        <td>$pv_pds_2000</td>
                        <td>$pv_ivr</td>
                    </tr>";
            }
            $html.="</table><br>";

            $html.="
            <table class='layout' width='100%'>
            <tr>
                <td class='text-center' colspan=7><b>Negative Control</b></td> 
            </tr>
            
            <tr>
                <td class='text-center'><b>Product Name</b></td>
                <td class='text-center'><b>Temperature (℃)</b></td>
                <td class='text-center'><b>Total Tests</b></td>               
                <td class='text-center'><b>True Negative</b></td>
                <td class='text-center'><b>False Positive</b></td>
                <td class='text-center'><b>TN Rate</b></td>
                <td class='text-center'><b>FP Rate</b></td>
            </tr>";
            $ngprodqry=($conn->query($pdqry));
            while ($product =  $ngprodqry->fetch_row()) {
                $ngrsltqry = ($conn->query("SELECT ng_temperature, total_tests, true_negative, false_positive, tn_rate, fp_rate FROM rdt_performance_report WHERE manufacturer_id = $product[0]"));
                list($ng_temperature, $total_tests, $true_negative,$false_positive, $tn_rate, $fp_rate) = $ngrsltqry->fetch_row();
                $html.="<tr>
                        <td>$product[1]</td>               
                        <td>$ng_temperature</td>
                        <td>$total_tests</td>
                        <td>$true_negative</td>
                        <td>$false_positive</td>
                        <td>$tn_rate</td>
                        <td>$fp_rate</td>
                    </tr>";
            }
        $html.="</table><br>";

        $html.="<div style='font-size:14px; font-weight: bold;'>RDT Lot testing:</div>
            <table class='layout' width='100%'>            
            <tr>
                <td class='text-center'><b>Product Name</b></td>
                <td class='text-center'><b>Performance Result</b></td>
                <td class='text-center'><b>Tested by</b></td>
                <td class='text-center'><b>Designation</b></td>
                <td class='text-center'><b>Test Date</b></td>
            </tr>";
            $tdprodqry=($conn->query($pdqry));
            while ($product =  $tdprodqry->fetch_row()) {
                $perforqry = ($conn->query("SELECT performance_result, test_by, designation, test_date FROM rdt_performance_report WHERE manufacturer_id = $product[0]"));
                list($performance_result, $test_by, $designation, $test_date) = $perforqry->fetch_row();
                $html.="<tr>
                        <td>$product[1]</td>
                        <td class='text-center'></i><b>$performance_result</b></i></td>
                        <td>$test_by</td>
                        <td>$designation</td>
                        <td>$test_date</td>
                    </tr>";
            }
        $html.="</table><br>";

        $html.="<div style='font-size:12px; font-weight: bold;'><i>Note:</i></div>
            <table class='layout' width='100%'>            
            <tr>
                <td class='text-center'><b>Panel Detection Score</b></td>
                <td>should be ≥ 75%</td>
            </tr>
            <tr>   
                <td class='text-center'><b>false-positive rate</b></td>
                <td>should be < 10%</td>
            </tr>
            <tr>
                <td class='text-center'><b>Invalid rate</b></td>
                <td>should be < 5%</td>
            </tr>";
        $html.="</table><br>";


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

	$mpdf->SetTitle('RDT Product Report');
	$mpdf->SetHTMLHeader('<img src="'.$_SERVER["DOCUMENT_ROOT"].'/MNEQAS/dist/img/header.jpg"/>','E');
	$mpdf->SetHTMLHeader('<img src="'.$_SERVER["DOCUMENT_ROOT"].'/MNEQAS/dist/img/header.jpg"/>','O');
	$mpdf->SetFooter($footer,'O');
	$mpdf->SetFooter($footer,'E');
	$mpdf->SetDisplayMode('fullpage');
	$mpdf->WriteHTML($html);
	mn_pdf_download($mpdf, "RDTProduct.pdf");
	exit;
?>