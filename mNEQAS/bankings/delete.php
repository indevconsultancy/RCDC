<?php
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$cdt = date('Y-m-d h:i:s', time());
	$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
	$type = isset($_GET["type"]) ? (string)$_GET["type"] : '';
	$failMsg = 'Deletion Failed!!';   // per-type override below where a reason is known
	
	if($type == 'cabinet'){
		$lnk = "cabinets.php";

		/* banking_slide_informations.slot_id is ON DELETE RESTRICT, so the first
		   statement below cannot remove a slot that still holds a slide: the
		   query fails, the && chain stops before anything is deleted, and the
		   user used to get a bare "Deletion Failed!!" with no reason. Checked up
		   front so the message can say what to do. The list page gates its
		   Delete icon on the same count; this is the server-side half. */
		$bs = $conn->prepare("SELECT COUNT(*)
							  FROM banking_slide_informations sl
							  INNER JOIN banking_slot_informations s ON s.slot_id = sl.slot_id
							  INNER JOIN banking_rack_informations r ON r.rack_id = s.rack_id
							 WHERE r.cabinet_id = ?");
		$bs->bind_param('i', $id);
		$bs->execute();
		$bsRow = $bs->get_result()->fetch_row();
		$bs->close();
		$banked = $bsRow ? (int)$bsRow[0] : 0;
		$image = ($conn->query("SELECT image_path FROM banking_cabinet_informations WHERE cabinet_id='$id'"));
		$image = $image->fetch_row();
		$delqry1 = "DELETE FROM banking_slot_informations WHERE rack_id IN(SELECT rack_id FROM banking_rack_informations WHERE cabinet_id='$id')";
		$delqry2 = "DELETE FROM banking_rack_specimen_informations WHERE rack_id IN(SELECT rack_id FROM banking_rack_informations WHERE cabinet_id='$id')";
		$delqry3 = "DELETE FROM banking_rack_informations WHERE cabinet_id='$id'";
		$delqry4 = "DELETE FROM banking_cabinet_informations WHERE cabinet_id='$id'";
		if($banked > 0){
			$check = false;
			$failMsg = 'This cabinet holds ' . $banked . ' banked slide(s). Remove them from its racks before deleting the cabinet.';
		}
		else if($conn->query($delqry1) && $conn->query($delqry2) && $conn->query($delqry3) && $conn->query($delqry4)) {
            if ($image && $image[0] !== null && is_file($image[0])) unlink($image[0]);   // cabinets without an image store only "images/"
			$check = true;  
		}  else $check = false;
	}
	else if($type == 'map'){
		$lnk = "mappings.php";
		$delqry1 = "DELETE FROM banking_slot_informations WHERE rack_id IN (SELECT rack_id FROM banking_rack_informations WHERE cabinet_id='$id')";
		$delqry2 = "DELETE FROM banking_rack_informations WHERE cabinet_id='$id'";
		if($conn->query($delqry1) && $conn->query($delqry2)) {
		$check = true;  
		}  else $check = false;
	}
	
	else if($type == 'rinfo'){
		$iid = isset($_GET["iid"]) ? (int)$_GET["iid"] : 0;
		$lnk = "mappings.php?id=$id";
		$delqry = "DELETE FROM banking_rack_specimen_informations WHERE information_id='$iid'";
		if($conn->query($delqry)) {
		$check = true;  
		}  else $check = false;
	} 

	else if($type == 'sinfo'){
		$rid = isset($_GET["rid"]) ? (int)$_GET["rid"] : 0;
		$lnk = "mappings.php?id=$id&rid=$rid";
		$delqry ="DELETE FROM banking_slide_informations WHERE slot_id IN (SELECT slot_id FROM banking_slot_informations WHERE rack_id='$rid')";
		$uptqry = "UPDATE banking_slot_informations SET column_no=NULL, row_no=NULL, status='Empty' WHERE rack_id='$rid'";
		if($conn->query($delqry) && $conn->query($uptqry)) {
		$check = true;  
		}  else $check = false;
	} 

	else if($type == 'unassign'){
		$rid = isset($_GET["rid"]) ? (int)$_GET["rid"] : 0;
		$lnk = "mappings.php?id=$id";
		$uptqry1 = "UPDATE banking_slide_informations SET status='Historical' WHERE slot_id IN (SELECT slot_id FROM banking_slot_informations WHERE rack_id='$rid')";
		$uptqry2 = "UPDATE banking_slot_informations SET column_no=NULL, row_no=NULL, status='Empty' WHERE rack_id='$rid'";
		$uptqry3 = "UPDATE banking_rack_specimen_informations SET status='Historical' WHERE rack_id='$rid'";
		if($conn->query($uptqry1) && $conn->query($uptqry2) && $conn->query($uptqry3)) {
		$check = true;  
		}  else $check = false;
	} 

	else if($type == 'rpa'){
		// Stray RDT panel-assignment delete: same rule as settings/delete.php (Administrator only).
		mn_require_admin('Only an Administrator can delete a panel assignment.');
		$lnk = "rdt-panel-assignments.php";
		$delqry1 = "DELETE FROM  rdt_panel_informations WHERE panel_id='$id'";
		$delqry2 = "DELETE FROM  rdt_panel_slide_informations WHERE panel_id='$id'";
		if($conn->query($delqry2) && $conn->query($delqry1)) {
			$check = true;  
		}  else $check = false;
	} 

	else{	$check = false;	}
	
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
				text: '<?=htmlspecialchars($failMsg, ENT_QUOTES);?>',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>