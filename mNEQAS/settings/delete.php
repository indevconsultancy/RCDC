<?php
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_admin('Only an Administrator can delete master data.');
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$cdt = date('Y-m-d h:i:s', time());
	$id = $_GET["id"];
	$type = $_GET["type"];
	
	if($type == 'oc'){
		$lnk = "onsite-checklist.php";
		$delqry = "DELETE FROM ms_onsite_checklists WHERE checklist_id='$id'";
		if($conn->query($delqry)) {
			$check = true;  
		}  else $check = false;
	}
	else if($type == 'pr'){
		$lnk = "panel-rounds.php";
		$delqry = "DELETE FROM  panel_round_informations WHERE round_id='$id'";
		if($conn->query($delqry)) {
		$check = true;  
		}  else $check = false;
	}
	
	else if($type == 'pa'){
		$lnk = "panel-assignments.php";
		$delqry1 = "DELETE FROM  panel_informations WHERE panel_id='$id'";
		$delqry2 = "DELETE FROM  panel_slide_informations WHERE panel_id='$id'";
		if($conn->query($delqry2) && $conn->query($delqry1)) {
			$check = true;  
		}  else $check = false;
	} 

	else if($type == 'rpr'){
		$lnk = "rdt-panel-rounds.php";
		$delqry = "DELETE FROM  rdt_panel_round_informations WHERE round_id='$id'";
		if($conn->query($delqry)) {
		$check = true;  
		}  else $check = false;
	}

	else if($type == 'rpa'){
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
				text: 'Deletion Failed!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>