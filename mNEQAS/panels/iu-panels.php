<?php	
	require_once("../config/session.php");
	// RBAC backend enforcement: only Create/Update roles (Administrator, RCDC Lab,
	// Health Worker) may write here; read-only "Others" are blocked for direct URL,
	// form submit, and AJAX alike. (Delete is gated separately in delete.php.)
	require_once("../config/auth.php"); mn_require_write();
	require_once("../config/db-config.php");
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	// Int-cast panel_id at intake — receive_date and per-slide status/remarks
	// are then written via prepared statements, so no user-controlled string
	// ever enters the SQL text directly.
	$id  = isset($_POST['id'])  ? (int)$_POST['id']   : 0;
	$dor = isset($_POST['dor']) ? (string)$_POST['dor'] : '';
	$slide   = isset($_POST['slide'])   && is_array($_POST['slide'])   ? $_POST['slide']   : [];
	$status  = isset($_POST['status'])  && is_array($_POST['status'])  ? $_POST['status']  : [];
	$remarks = isset($_POST['remarks']) && is_array($_POST['remarks']) ? $_POST['remarks'] : [];

	/* Authorization before any SQL: own facility's panel; every slide must belong to it.
	   Facility users: not after the round has CLOSED and not once lab results exist
	   (same gates as the receipt form on panels.php). RCDC keeps corrective access. */
	mn_require_record($conn, 'panel', $id);
	foreach ($slide as $authSlide) { mn_require_child($conn, 'panel_slide', (int)$authSlide, $id); }
	if (!mn_is_rcdc()) {
		$authState = mn_panel_state($conn, 'micro', $id);
		if (!$authState || $authState['closed']) { mn_deny('The panel round is closed; receipt can no longer be changed.', $id); }
		$lr = $conn->prepare("SELECT COUNT(*) FROM panel_lab_result_informations WHERE panel_id = ?");
		$lr->bind_param('i', $id);
		$lr->execute();
		$lrCount = (int)$lr->get_result()->fetch_row()[0];
		$lr->close();
		if ($lrCount > 0) { mn_deny('Receipt cannot be changed after lab results have been entered.', $id); }
	}

	$lnk = "panels.php?id=$id";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

	$updHdr =$conn->prepare("UPDATE panel_informations SET receive_date=? WHERE panel_id=?");
	$updHdr->bind_param("si", $dor, $id);
	if($updHdr->execute()) {
		$updHdr->close();
		$updSlide = $conn->prepare("UPDATE panel_slide_informations SET slide_status=?, slide_remarks=? WHERE slide_id=?");
		for($i = 0; $i < count($slide); $i++){
			$slideId = (int)$slide[$i];
			$st = (string)($status[$i]  ?? '');
			$rm = (string)($remarks[$i] ?? '');
			$updSlide->bind_param("ssi", $st, $rm, $slideId);
			$updSlide->execute();
		}
		$updSlide->close();
		$check = true;
	}
	else { $updHdr->close(); $check = false; }

	/* Receipt recorded by the facility -> RCDC is informed that the panel arrived
	   and that results are now being entered. Post-write, best-effort. */
	if ($check && $id > 0) {
		require_once("../notifications/mn-notify.php");
		mn_notify($conn, 'MICRO_PANEL_RECEIVED', $id);
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
	
