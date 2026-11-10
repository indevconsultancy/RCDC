<?php
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$id = $_GET["id"];
	$rid = $_GET["rid"];
	$iid = $_GET["iid"];
	$sts = $_GET["sts"];
	
	if($sts == 'Current' OR $sts == 'In-use'){
		$lnk = "mappings.php?id=$id&rid=$rid&iid=$iid";
		$uptqry = "UPDATE banking_slide_informations SET status='$sts' WHERE slide_no != '' AND slot_id IN (SELECT slot_id FROM banking_slot_informations WHERE rack_id='$rid')";
		if($conn->query($uptqry)) {
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
				text: 'Status Updated Successfully!!',
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
				text: 'Updation Failed!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>