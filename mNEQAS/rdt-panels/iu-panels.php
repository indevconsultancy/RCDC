<?php	
	require_once("../config/session.php");
	// RBAC backend enforcement: only Create/Update roles (Administrator, RCDC Lab,
	// Health Worker) may write here; read-only "Others" are blocked for direct URL,
	// form submit, and AJAX alike. (Delete is gated separately in delete.php.)
	require_once("../config/auth.php"); mn_require_write();
	// LEGACY (superseded by iu-panel-save.php; no page posts here). It has no facility /
	// deadline / slide-ownership checks, so it is RCDC-only while it remains on disk.
	mn_require_rcdc('This legacy endpoint is restricted to RCDC users.');
	require_once("../config/db-config.php");
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$null = NULL;
    $id = $conn -> real_escape_string($_POST['id']);
    $dor = $conn -> real_escape_string($_POST['dor']);
	$slide = $_POST["slide"];
	$status = $_POST["status"];
	$remarks = $_POST["remarks"];

	$lnk = "panels.php?id=$id";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

	$uptqry = "UPDATE rdt_panel_informations SET receive_date='$dor' WHERE panel_id='$id'";
	if($conn->query($uptqry)) {
		for($i = 0; $i < count($slide); $i++){
			$conn->query("UPDATE rdt_panel_slide_informations SET slide_status='$status[$i]', slide_remarks='$remarks[$i]' WHERE slide_id='$slide[$i]'");
		}
		$check = true;  
	}	
	else $check = false;  

	/* Receipt recorded by the facility -> RCDC is informed. Post-write, best-effort. */
	if ($check && (int)$id > 0) {
		require_once("../notifications/mn-notify.php");
		mn_notify($conn, 'RDT_PANEL_RECEIVED', (int)$id);
	}
    
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
	
