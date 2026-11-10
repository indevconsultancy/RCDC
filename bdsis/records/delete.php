<?php
	require_once("../config/session.php");
	require_once("../config/db-config.php"); 
	require_once("../includes/rbac.php");
    include_once("../check-record.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$cdt = date('Y-m-d h:i:s', time());
	/* Escape first: these land in DELETE statements and used to be taken raw
	   from the query string. */
	$id   = $bdsis->real_escape_string(isset($_GET["id"])   ? $_GET["id"]   : '');
	$siid = $bdsis->real_escape_string(isset($_GET["siid"]) ? $_GET["siid"] : '');
	$type = $bdsis->real_escape_string(isset($_GET["type"]) ? $_GET["type"] : '');

	/* Authorise the record before deleting anything. The listing hides the delete
	   icon from roles that may not use it, but this endpoint is reachable directly
	   by URL -- which is how a facility user could delete another hospital's data. */
	bdsis_require_subject($bdsis, $id);
	bdsis_require_can('delete', 'Facility users may not delete Records data.', 'records');

	/* Assets load only once the caller is known to be entitled to this record,
	   so a refusal is a bare 403 rather than a denial drawn into a styled page.
	   Skipped for an AJAX caller: it needs clean JSON, not the SweetAlert page's
	   jQuery/CSS, which would otherwise be emitted before the JSON below. */
	if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
		require_once("../includes/css-jquery.php");
	}


    if($type == 'caseInfo'){
        if (chkMicroscopy($id, $bdsis)==0 AND chkShipment($id, $bdsis)==0 AND  chkBacteriology($id, $bdsis)==0 AND chkElisa($id, $bdsis)==0) {
            $lnk = "record-management.php";
            $delcqry ="DELETE FROM case_clinical_information_table WHERE subject_id = '$id'";
            $delsqry ="DELETE FROM subject_information_table WHERE subject_id = '$id'";
            if($bdsis->query($delcqry) && $bdsis->query($delsqry)) {
                $check = true;  
            }  else $check = false;
        }else{
            $lnk = "case.php?sid=$id&action=2";
            $check = false;
        }
	}
    else if($type == 'sampleInfo'){
		$lnk = "case.php?sid=$id&action=2";
		$delqry = "DELETE FROM sample_information_table WHERE subject_id = '$id'";
		if($bdsis->query($delqry)) {
			$check = true;  
		}  else $check = false;
	}
    else if($type == 'microscopy'){
		$lnk = "case.php?sid=$id&action=2";
		$delqry = "DELETE FROM microscopy_result_table WHERE subject_id = '$id'";
		if($bdsis->query($delqry)) {
			$check = true;  
		}  else $check = false;
	}
    else if($type == 'caseAll'){
		$lnk = "record-management.php";
        if (chkBacteriology($id, $bdsis)==0 AND chkElisa($id, $bdsis)==0) {
            $delmqry = "DELETE FROM microscopy_result_table WHERE subject_id = '$id'";
            $delqry = "DELETE FROM sample_information_table WHERE subject_id = '$id'";
            $delcqry ="DELETE FROM case_clinical_information_table WHERE subject_id = '$id'";
            $delsqry ="DELETE FROM subject_information_table WHERE subject_id = '$id'";
            if($bdsis->query($delmqry) && $bdsis->query($delqry) && $bdsis->query($delcqry) && $bdsis->query($delsqry)) {
                $check = true;  
            }  else $check = false;
        }else{
            $check = false;
        }
	}
    else{$check = false;}

	/* In-place delete: the listing's trash icon calls this over AJAX and stays on
	   the page, so answer with a small JSON result instead of the SweetAlert +
	   window.location page. Every guard, cascade and query above is unchanged, and
	   a direct (non-AJAX) request still gets the popup-and-redirect page below --
	   so the delete behaviour, its messages and its fallback are all preserved;
	   only the response format differs for the in-place caller. */
	if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => (bool)$check,
			'msg' => $check ? 'Deleted Successfully!!' : 'Deletion Failed!!'));
		exit;
	}

	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

	if($check){
    	$bdsis->close();		
?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Deleted Successfully!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
	<?php }	else{ echo $bdsis -> error;?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Deletion Failed!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>
