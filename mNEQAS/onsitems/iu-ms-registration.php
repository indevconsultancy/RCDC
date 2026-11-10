<?php	
	require_once("../config/session.php");
	// Legacy per-section processor (superseded by iu-ms-registration-save.php; no
	// page posts here any more). It has no record/audit-type checks, so it is kept
	// available only to RCDC writers instead of being callable by facility users.
	mn_require_rcdc('This endpoint is restricted to RCDC users.');
	mn_require_write();
	require_once("../config/db-config.php");
	require_once("../includes/css-jquery.php");

    $submit = $conn -> real_escape_string($_POST['submit']);
    $action = $conn -> real_escape_string($_POST['action']);
    if ($submit=='general') {
        $facility = $conn -> real_escape_string($_POST['facility']);
        $dov = $conn -> real_escape_string($_POST['dov']);        
        $evaluator = $conn -> real_escape_string($_POST['evaluator']);
        // $labincharge = $conn -> real_escape_string($_POST['labincharge']);
        $designation = $conn -> real_escape_string($_POST['designation']);
        $audit = $conn -> real_escape_string($_POST['audit']); 

        if($action == 2){
            $gid = $conn -> real_escape_string($_POST['gid']);
            $lnk = "ms-registration.php?action=$action&id=$gid&step=2";
            $uptqry = "UPDATE onsite_general_informations SET facility_id = '$facility', date_visit = '$dov', evaluator = '$evaluator', incharge_designation = '$designation', audit_category = '$audit' WHERE gi_id='$gid'";
            if($conn->query($uptqry)){
                $check = true;  
            }   else $check = false;
        }
        else{
            $gid = ($conn->query("SELECT MAX(gi_id)+1 FROM onsite_general_informations"));
            $gid = $gid->fetch_row();
            if ($gid[0]=='') {$gid[0]=1;}
            $lnk = "ms-registration.php?action=2&id=$gid[0]&step=2";
            $stmt = $conn->prepare("INSERT INTO onsite_general_informations(gi_id, facility_id, date_visit, evaluator, incharge_designation, audit_category) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $gid[0], $facility, $dov, $evaluator, $designation, $audit);
            if($stmt->execute()){   
                $stmt->close(); $check = true; 
            } else $check = false;            
        }
    }

    if ($submit=='staff') {      
        $gid = $conn -> real_escape_string($_POST['gid']);
        $action = $conn -> real_escape_string($_POST['action']);        
        $sname = $conn -> real_escape_string($_POST['sname']);
        $sdesignation = $conn -> real_escape_string($_POST['sdesignation']);
        $tfrequency = $conn -> real_escape_string($_POST['tfrequency']);
        $tdays = $conn -> real_escape_string($_POST['tdays']);
        $ldt = $conn -> real_escape_string($_POST['ldt']);
        $ttopic = $conn -> real_escape_string($_POST['ttopic']);

        $lnk = "ms-registration.php?action=2&id=$gid&step=2";
        if ($action=='sf') {
            $sid = $conn -> real_escape_string($_POST['sid']);
            $uptqry = "UPDATE onsite_staff_informations SET general_info_id = '$gid', staff_name = '$sname', staff_designation = '$sdesignation', training_frequency = '$tfrequency', training_days = '$tdays', last_training_date = '$ldt', training_topic= '$ttopic' WHERE staff_id='$sid'";
            if($conn->query($uptqry)){
                $check = true;  
            }   else $check = false; 
        }else {
            $stmt = $conn->prepare("INSERT INTO onsite_staff_informations(general_info_id, staff_name, staff_designation, training_frequency, training_days, last_training_date, training_topic) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issiiss", $gid, $sname, $sdesignation, $tfrequency, $tdays, $ldt, $ttopic);
            if($stmt->execute()){   
                $stmt->close(); $check = true; 
            } else $check = false; 
        }

    }

    if ($submit=='checklist') {
        $gid = $conn -> real_escape_string($_POST['gid']);
        $action = $conn -> real_escape_string($_POST['action']); 
        $count = $conn -> real_escape_string($_POST['count']);
        $lnk = "ms-registration.php?action=2&id=$gid&step=4";

        for ($i=0; $i < $count; $i++) {
            $cid = $conn -> real_escape_string($_POST['cid'][$i]);
            $rating = $conn -> real_escape_string($_POST['rating'][$i]);
            $remarks = $conn -> real_escape_string($_POST['remarks'][$i]);
            $chklist = ($conn->query("SELECT rating_id FROM onsite_checklists_rating WHERE general_info_id = '$gid' AND checklists_id = '$cid'"));
            $chklist = $chklist->num_rows;
            if ($chklist>0) {
                $uptqry = "UPDATE onsite_checklists_rating SET rating_value = '$rating', remarks = '$remarks' WHERE general_info_id = '$gid' AND checklists_id = '$cid'";
                if($conn->query($uptqry)){
                    $check = true;  
                }   else $check = false;
            }else{
                $stmt = $conn->prepare("INSERT INTO onsite_checklists_rating(general_info_id, checklists_id, rating_value, remarks) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiis", $gid, $cid, $rating, $remarks);
                if($stmt->execute()){   
                    $stmt->close(); $check = true; 
                } else $check = false;
            }
        }

        //Delete from Table Score
        $conn->query("DELETE FROM onsite_checklists_rating_score WHERE general_info_id='$gid'");

        //Query total 0 and 1 from table rating for each category
        $totqry = $conn->query("SELECT oc.category_id, cr.general_info_id, COUNT(rating_id) FROM onsite_checklists_rating cr, ms_onsite_checklists cl, ms_onsite_subcategories os, ms_onsite_categories oc WHERE rating_value IN (0,1) AND cr.checklists_id=cl.checklist_id AND cl.subcategory_id = os.subcategory_id AND os.category_id = oc.category_id AND cr.general_info_id=$gid GROUP BY oc.category_id");

        while ($total = $totqry->fetch_row()) {
            //count 1 for each category
            $yesCount = ($conn->query("SELECT COUNT(rating_id) FROM onsite_checklists_rating cr, ms_onsite_checklists cl, ms_onsite_subcategories os, ms_onsite_categories oc WHERE rating_value IN (1) AND cr.checklists_id=cl.checklist_id AND cl.subcategory_id = os.subcategory_id AND os.category_id = oc.category_id AND cr.general_info_id=$gid AND oc.category_id=$total[0]"));
            $yesCount = $yesCount->fetch_row();

            //calculate total 1 / total 0 and 1 * 100
            $score = $yesCount[0]/$total[2]*100;

            //Save the Score to table Score.
            $savescore = $conn->prepare("INSERT INTO onsite_checklists_rating_score(general_info_id, category_id, score_value) VALUES (?, ?, ?)");
            $savescore->bind_param("iii", $total[1], $total[0], $score);
            if($savescore->execute()){   
                $savescore->close(); $check = true; 
            } else $check = false;
        }       
        
    }

    if ($submit=='recommendation') {      
        $gid = $conn -> real_escape_string($_POST['gid']);
        $action = $conn -> real_escape_string($_POST['action']);        
        $recommendation = $conn -> real_escape_string($_POST['recommendation']);
        $findings = isset($_POST['findings']) ? $conn->real_escape_string($_POST['findings']) : '';

        $lnk = "ms-registration.php?action=2&id=$gid&step=5";
        if ($action=='update') {
            $ora_id = $conn -> real_escape_string($_POST['ora_id']);
            $uptqry = "UPDATE onsite_recommendation_action SET recommendation = '$recommendation', findings = '$findings' WHERE general_info_id='$gid' AND ora_id = '$ora_id'";
            if($conn->query($uptqry)){
                $check = true;  
            }   else $check = false; 
        }else {
            $stmt = $conn->prepare("INSERT INTO onsite_recommendation_action(general_info_id, recommendation, findings) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $gid, $recommendation, $findings);
            if($stmt->execute()){   
                $stmt->close(); $check = true; 
            } else $check = false; 
        }

    }

    

    if ($submit=='actions') {

    $gid = $conn->real_escape_string($_POST['gid']);
    $action = $conn->real_escape_string($_POST['action']);
    $actions = $conn->real_escape_string($_POST['actions']);

    $lnk = "ms-registration.php?action=2&id=$gid&step=6";

    /*
     * Save/update Actions text
     */
    if ($action=='update') {

        $ora_id = $conn->real_escape_string($_POST['ora_id']);

        $uptqry = "
            UPDATE onsite_recommendation_action
            SET actions = '$actions'
            WHERE general_info_id='$gid'
            AND ora_id='$ora_id'
        ";

        if ($conn->query($uptqry)) {
            $check = true;
        } else {
            $check = false;
        }

    } else {

        $stmt = $conn->prepare("
            INSERT INTO onsite_recommendation_action
            (
                general_info_id,
                actions
            )
            VALUES (?, ?)
        ");

        $stmt->bind_param(
            "is",
            $gid,
            $actions
        );

        if ($stmt->execute()) {

            $stmt->close();
            $check = true;

        } else {

            $check = false;
        }
    }

    /*
     * Upload multiple Action images
     *
     * Only Admin (roleID = 1) is allowed.
     */
    if ($check && isset($_SESSION['roleID']) &&
        $_SESSION['roleID'] == 1) {

        if (!uploadActionImages($conn, $gid)) {

            /*
             * Do not report complete success if image
             * upload failed.
             */
            $check = false;
        }
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
	
