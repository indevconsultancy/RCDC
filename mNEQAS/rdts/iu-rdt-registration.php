<?php
	require_once("../config/session.php");
	// Legacy stage processor (superseded by iu-rdt-registration-save.php; no page posts here).
	// It has no stage/record checks, so it is kept available only to RCDC writers.
	mn_require_rcdc('This endpoint is restricted to RCDC users.');
	mn_require_write();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php");

    $submit = $conn -> real_escape_string($_POST['submit']);
    $action = $conn -> real_escape_string($_POST['action']);
    if ($submit=='request') {
        $facility = $conn -> real_escape_string($_POST['facility']);
        $dor = $conn -> real_escape_string($_POST['dor']);        
        $staffName = $conn -> real_escape_string($_POST['staffName']);
        $designation = $conn -> real_escape_string($_POST['designation']);
        $tol = $conn -> real_escape_string($_POST['tol']);

        if($action == 2){
            $trid = $conn -> real_escape_string($_POST['trid']);
            $lnk = "rdt-registration.php?action=$action&id=$trid";
            $uptqry = "UPDATE rdt_test_request SET facility_id = '$facility', date_request = '$dor', staff_name = '$staffName', staff_designation = '$designation', lot_type = '$tol' WHERE rdt_request_id='$trid'";
            if($conn->query($uptqry)){
                $check = true;  
            }   else $check = false;
        }
        else{
            $trid = ($conn->query("SELECT MAX(rdt_request_id)+1 FROM rdt_test_request"));
            $trid = $trid->fetch_row();
            if ($trid[0]=='') {$trid[0]=1;}
            $lnk = "rdt-registration.php?action=2&id=$trid[0]";
            $stmt = $conn->prepare("INSERT INTO rdt_test_request (rdt_request_id, facility_id, date_request, staff_name, staff_designation, lot_type) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $trid[0], $facility, $dor, $staffName, $designation, $tol);
            if($stmt->execute()){   
                $stmt->close(); $check = true; 
            } else $check = false;            
        }
    }

    if ($submit=='product') {
        $trid = $conn -> real_escape_string($_POST['trid']);
        $pname = $conn -> real_escape_string($_POST['pname']);        
        $manufacturer = $conn -> real_escape_string($_POST['manufacturer']);
        $lno = $conn -> real_escape_string($_POST['lno']);
        $mdt = $conn -> real_escape_string($_POST['mdt']);
        $edt = $conn -> real_escape_string($_POST['edt']);

        $lnk = "rdt-registration.php?action=2&id=$trid";

        if($action == 'update'){
            $pid = $conn -> real_escape_string($_POST['pid']);

            $uptqry = "UPDATE rdt_product_details SET product_name = '$pname', manufacturer = '$manufacturer', lot_no = '$lno', date_manufacture = '$mdt', date_expiry = '$edt' WHERE product_id='$pid' AND test_request_id = '$trid'";
            if($conn->query($uptqry)){
                $check = true;  
            }   else $check = false;
        }
        else{
            $pid = ($conn->query("SELECT MAX(product_id)+1 FROM rdt_product_details"));
            list($pid) = $pid->fetch_row();
            if ($pid=='') {$pid=1;}
            $stmt = $conn->prepare("INSERT INTO rdt_product_details (product_id, test_request_id, product_name, manufacturer, lot_no, date_manufacture, date_expiry) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssss", $pid, $trid, $pname, $manufacturer, $lno, $mdt, $edt);
            if($stmt->execute()){   
                $stmt->close(); $check = true; 
            } else $check = false;            
        }

        $qry = $conn->query("SELECT performance_id FROM rdt_performance_report WHERE manufacturer_id = $pid");
        if ($qry->num_rows<=0) {
            //insert into performance report table
            $stmt1 = $conn->prepare("INSERT INTO rdt_performance_report (manufacturer_id) VALUES (?)");
            $stmt1->bind_param("i", $pid);
            if($stmt1->execute()){   
                $stmt1->close(); $check = true; 
            } else $check = false;
        }
    }


    if ($submit=='labShipment') {
        $trid = $conn -> real_escape_string($_POST['trid']);
        $shdt = $conn -> real_escape_string($_POST['shdt']);
        $count = $conn -> real_escape_string($_POST['productCnt']);        

        $lnk = "rdt-registration.php?action=2&id=$trid";

        for ($i=0; $i < $count; $i++) {
            $pid = $conn -> real_escape_string($_POST['pid'][$i]);
            $snbox = $conn -> real_escape_string($_POST['snbox'][$i]);
            $stpbox = $conn -> real_escape_string($_POST['stpbox'][$i]);
            $chklist = ($conn->query("SELECT shipment_id FROM rdt_shipment_details WHERE product_details_id = $pid"));
            $chklist = $chklist->num_rows;
            if ($chklist>0) {
                $uptqry = "UPDATE rdt_shipment_details SET sent_no_box = '$snbox', sent_test_per_box = '$stpbox' WHERE product_details_id ='$pid'";
                if($conn->query($uptqry)){
                    $check = true;  
                }   else $check = false;
            }else{
                $stmt = $conn->prepare("INSERT INTO rdt_shipment_details (product_details_id, sent_no_box, sent_test_per_box) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $pid, $snbox, $stpbox);
                if($stmt->execute()){   
                    $stmt->close(); $check = true; 
                } else $check = false;
            }
        }
        $uptqry = "UPDATE rdt_test_request SET date_shipment = '$shdt' WHERE rdt_request_id='$trid'";
        if($conn->query($uptqry)){
            $check = true;  
        }  else $check = false;
    }

    if ($submit=='rcdcShipment') {
        $trid = $conn -> real_escape_string($_POST['trid']);
        $rdt = $conn -> real_escape_string($_POST['rdt']);
        $rname = $conn -> real_escape_string($_POST['rname']);
        $rdesignation = $conn -> real_escape_string($_POST['rdesignation']);
        $count = $conn -> real_escape_string($_POST['productCnt']);        

        $lnk = "rdt-registration.php?action=2&id=$trid";

        for ($i=0; $i < $count; $i++) {
            $pid = $conn -> real_escape_string($_POST['pid'][$i]);
            $lno = $conn -> real_escape_string($_POST['lno'][$i]);
            $rnbox = $conn -> real_escape_string($_POST['rnbox'][$i]);
            $rtpbox = $conn -> real_escape_string($_POST['rtpbox'][$i]);
            
           
            $uptshipqry = "UPDATE rdt_shipment_details SET received_no_box = '$rnbox', received_test_per_box = '$rtpbox' WHERE product_details_id ='$pid'";
            $uptprodqry = "UPDATE rdt_product_details SET lot_no = '$lno' WHERE product_id ='$pid'";
            if($conn->query($uptshipqry) && $conn->query($uptprodqry)){
                $check = true;  
            }   else $check = false;            
        }
        $uptqry = "UPDATE rdt_test_request SET date_receive = '$rdt', received_by = '$rname', designation = '$rdesignation' WHERE rdt_request_id='$trid'";
        if($conn->query($uptqry)){
            $check = true;  
        }  else $check = false;
    }

    if ($submit=='aassessment') {    
        $trid = $conn -> real_escape_string($_POST['trid']);
        $prid = $conn -> real_escape_string($_POST['prid']);
        
        $mfgid = $conn -> real_escape_string($_POST['mfgid']);
        $count = $conn -> real_escape_string($_POST['count']);
        $lnk = "rdt-registration.php?action=2&id=$trid&edit=pr&prid=$prid";

        for ($i=0; $i < $count; $i++) {
            $cid = $conn -> real_escape_string($_POST['cid'][$i]);
            $rating = $conn -> real_escape_string($_POST['rating'][$i]);
            $remarks = $conn -> real_escape_string($_POST['remarks'][$i]);
            $chklist = ($conn->query("SELECT rar_id FROM rdt_assessment_report WHERE manufacturer_id = '$mfgid' AND assesment_id = '$cid'"));
            $chklist = $chklist->num_rows;
            if ($chklist>0) {
                $uptqry = "UPDATE rdt_assessment_report SET result = '$rating', comments = '$remarks' WHERE manufacturer_id = '$mfgid' AND assesment_id = '$cid'";
                if($conn->query($uptqry)){
                    $check = true;  
                }   else $check = false;
            }else{
                $stmt = $conn->prepare("INSERT INTO rdt_assessment_report(assesment_id, manufacturer_id, result, comments) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiis", $cid, $mfgid, $rating, $remarks);
                if($stmt->execute()){   
                    $stmt->close(); $check = true; 
                } else $check = false;
            }
        }        
    }

    if ($submit=='performance') {
        $trid = $conn -> real_escape_string($_POST['trid']);
        $prid = $conn -> real_escape_string($_POST['prid']);
        // $rdt_brand = $conn -> real_escape_string($_POST['rdt_brand']);
        $pf_pd_200_temp = $conn -> real_escape_string($_POST['pf_pd_200_temp']);
        $pf_pds_200 = $conn -> real_escape_string($_POST['pf_pds_200']);
        $pf_pd_2000_temp = $conn -> real_escape_string($_POST['pf_pd_2000_temp']);
        $pf_pds_2000 = $conn -> real_escape_string($_POST['pf_pds_2000']);
        $pf_ivr = $conn -> real_escape_string($_POST['pf_ivr']);
        // $pf_fpr = $conn -> real_escape_string($_POST['pf_fpr']);
        $pv_pd_200_temp = $conn -> real_escape_string($_POST['pv_pd_200_temp']);
        $pv_pds_200 = $conn -> real_escape_string($_POST['pv_pds_200']);
        $pv_pd_2000_temp = $conn -> real_escape_string($_POST['pv_pd_2000_temp']);
        $pv_pds_2000 = $conn -> real_escape_string($_POST['pv_pds_2000']);
        $pv_ivr = $conn -> real_escape_string($_POST['pv_ivr']);
        // $pv_fpr = $conn -> real_escape_string($_POST['pv_fpr']);
        $test_by = $conn -> real_escape_string($_POST['test_by']);
        $designation = $conn -> real_escape_string($_POST['designation']);
        $test_date = $conn -> real_escape_string($_POST['test_date']);
        
        $ng_temperature = $conn -> real_escape_string($_POST['ng_temperature']); 
        $total_tests = $conn -> real_escape_string($_POST['total_tests']); 
        $true_negative = $conn -> real_escape_string($_POST['true_negative']); 
        $false_positive = $conn -> real_escape_string($_POST['false_positive']); 
        $tn_rate =  $true_negative/$total_tests*100;
        $fp_rate = $false_positive/$total_tests*100;

        if ($pf_pds_200<75 || $pf_pds_2000<75 || $pv_pds_200<75 || $pv_pds_2000<75 || $pv_ivr>=5 || $pf_ivr>=5 || $fp_rate>=10) {
            $result = "Fail";
        }else {
            $result = "Pass";
        }

        $lnk = "rdt-registration.php?action=2&id=$trid";

        if($action == 'update'){
            $uptqry = "UPDATE rdt_performance_report SET pf_pd_200_temp = '$pf_pd_200_temp', pf_pds_200 = '$pf_pds_200', pf_pd_2000_temp = '$pf_pd_2000_temp', pf_pds_2000 = '$pf_pds_2000', pf_ivr = '$pf_ivr', pv_pd_200_temp = '$pv_pd_200_temp', pv_pds_200 = '$pv_pds_200', pv_pd_2000_temp = '$pv_pd_2000_temp', pv_pds_2000 = '$pv_pds_2000', pv_ivr = '$pv_ivr', ng_temperature = '$ng_temperature', total_tests = '$total_tests', true_negative = '$true_negative', false_positive = '$false_positive', tn_rate = '$tn_rate', fp_rate = '$fp_rate', test_by = '$test_by', designation = '$designation', test_date = '$test_date', performance_result='$result' WHERE performance_id='$prid'";
            if($conn->query($uptqry)){
                $check = true;  
            }   else $check = false;
        }
        else{
            $stmt = $conn->prepare("INSERT INTO rdt_performance_report (manufacturer_id, pf_pd_200_temp, pf_pds_200, pf_pd_2000_temp, pf_pds_2000, pf_ivr, pv_pd_200_temp, pv_pds_200, pv_pd_2000_temp, pv_pds_2000, pv_ivr, ng_temperature, total_tests, true_negative, false_positive, tn_rate, fp_rate, test_by, designation, test_date, performance_result) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isiiiiiiiiiiiiiiissss",  $manufacturer_id, $pf_pd_200_temp, $pf_pds_200, $pf_pd_2000_temp, $pf_pds_2000, $pf_ivr, $pv_pd_200_temp, $pv_pds_200, $pv_pd_2000_temp, $pv_pds_2000, $pv_ivr, $ng_temperature, $total_tests, $true_negative, $false_positive, $tn_rate, $fp_rate, $test_by, $designation, $test_date, $result);
            if($stmt->execute()){   
                $stmt->close(); $check = true; 
            } else $check = false;            
        }
    }
	
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");    
    
    if($check){
        $conn->close();	
?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Submitted Successfully!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
	<?php }	else { echo $conn->error;?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Submission Failed!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>