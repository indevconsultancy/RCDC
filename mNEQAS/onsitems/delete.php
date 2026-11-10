<?php
	require_once("../config/session.php"); require_once("../config/auth.php");
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php");

	$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
	$type = isset($_GET["type"]) ? (string)$_GET["type"] : '';
    $gid = isset($_GET["gid"]) ? (int)$_GET["gid"] : 0;

	/* Authorization before any SQL: write role + record ownership. gi = Administrator;
	   sf/ck/ora/act = RCDC, or the owning Health Worker on an Internal audit only. */
	mn_require_write();
	if (!in_array($type, array('gi', 'sf', 'ck', 'ora', 'act'), true)) { mn_deny('Unknown delete type.'); }
	mn_require_record($conn, 'onsite', $gid);
	if ($type !== 'gi' && !mn_is_rcdc()) {
		$ac = $conn->prepare("SELECT audit_category FROM onsite_general_informations WHERE gi_id = ?");
		$ac->bind_param('i', $gid);
		$ac->execute();
		$acRow = $ac->get_result()->fetch_row();
		$ac->close();
		if (!$acRow || $acRow[0] !== 'Internal') { mn_deny('External audits can only be modified by RCDC.', $gid); }
	}
	if ($type === 'sf') { mn_require_child($conn, 'onsite_staff', $id, $gid); }

	if($type == 'gi'){
		mn_require_admin('Only an Administrator can delete an entire record.'); // whole-record delete
		$lnk = "ms-list.php";
		// Delete the entire report: child rows first, then the parent record,
		// atomically — a failure part-way through must not leave a half-deleted
		// report. There are no FK constraints on these tables, so each child
		// table has to be cleared explicitly.
		$gid = (int)$gid;
		try {
			$conn->begin_transaction();
			$conn->query("DELETE FROM onsite_staff_informations WHERE general_info_id=$gid");
			$conn->query("DELETE FROM onsite_checklists_rating WHERE general_info_id=$gid");
			$conn->query("DELETE FROM onsite_checklists_rating_score WHERE general_info_id=$gid");
			$conn->query("DELETE FROM onsite_recommendation_action WHERE general_info_id=$gid");
			$conn->query("DELETE FROM onsite_general_informations WHERE gi_id=$gid");
			$conn->commit();
			$check = ($gid > 0);
		} catch (Exception $e) {
			$conn->rollback();
			$check = false;
		}
	}
	else if($type == 'sf'){
		$lnk = "ms-registration.php?action=2&id=$gid";
		$delqry = "DELETE FROM  onsite_staff_informations WHERE staff_id='$id'";
		if($conn->query($delqry)) {
		$check = true;  
		}  else $check = false;
	}
	
	else if($type == 'ck'){
		$lnk = "ms-registration.php?action=2&id=$gid";
		$delqry1 = "DELETE FROM onsite_checklists_rating WHERE general_info_id='$gid'";
		$delqry2 = "DELETE FROM onsite_checklists_rating_score WHERE general_info_id='$gid'";
		if($conn->query($delqry2) && $conn->query($delqry1)) {
			$check = true;  
		}  else $check = false;
	}

	else if($type == 'ora'){
		$lnk = "ms-registration.php?action=2&id=$gid";
		$delqry1 = "DELETE FROM onsite_recommendation_action WHERE general_info_id='$gid'";
		if($conn->query($delqry1)) {
			$check = true;  
		}  else $check = false;
	}
	else if($type == 'act'){
		$lnk = "ms-registration.php?action=2&id=$gid";
		$delqry1 = "UPDATE onsite_recommendation_action SET actions = NULL WHERE general_info_id='$gid'";
		if($conn->query($delqry1)) {
			$check = true;  
		}  else $check = false;
	}

    else{
        $check = false;
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
				text: 'Deleted Successfully!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
	<?php }	else{ echo $conn -> error;?>
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