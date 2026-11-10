<?php
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	$mpAjax = !empty($_REQUEST['ajax']);
	if(!$mpAjax){ include_once("../includes/css-jquery.php"); }
	// Backend permission enforcement (mirrors the UI gate on the Add-Parameter form).
	include_once("../config/mpq_validate.php");
	// RBAC matrix: Administrator + RCDC/NDTL labs may update; BFDA/DRA (acl 7) is read-only.
	// Effective-role RBAC: Administrator + NDTL may attach test parameters. NMRL and BFDA denied.
	mpq_require('parameters', 'add', $mpAjax);
	mpq_csrf_check($mpAjax);
	// Parameters can only be added from the edit page; don't trust the stale session flag.
	$par = 2;
	$_SESSION['par'] = $par;
	$uid=$_SESSION['users'];

	// --- Server-side required-field validation (mirrors form1's `required` fields) ---
	include_once("../config/mpq_validate.php");
	mpq_guard(array('rid'=>'Record','newparid'=>'Parameter(s)'), $mpAjax);

	$rid=mysqli_real_escape_string($connection,$_REQUEST["rid"]);
	// Completed reports are read-only (server-side lock; see report_lock_lib.php).
	require_once(__DIR__."/report_lock_lib.php");
	mpqms_require_unlocked($connection, (int)$rid, $mpAjax);
	$paridarr= $_POST["newparid"];
	$qry = "";
	
	list($labid) = mysqli_fetch_row(mysqli_query($connection,"SELECT labID FROM registrationtable WHERE registrationID='".$rid."'"));
	if($labid==1){ $pending = "Pending";}	else $pending = "Approved";
	
	$now = date('Y-m-d H:i:s');
	for($i=0;$i<count($paridarr);$i++){	
		$pid = mysqli_real_escape_string($connection, $paridarr[$i]);
		list($rpt) = mysqli_fetch_row(mysqli_query($connection,"SELECT repetition FROM labresulttable WHERE parameterID='$pid' AND registrationID='$rid' ORDER BY repetition DESC LIMIT 1"));
		if($rpt == "") $rpt =1;	else $rpt +=1;
		$qry .= "INSERT INTO labresulttable 
			(parameterID, registrationID, repetition, testDate, testedBy, 
			 observation, standard, result, remarks, approval, approvedBy, 
			 entryUser, entrytimestamp) 
			VALUES 
			('$pid', '$rid', '$rpt', '1900-01-01', 'default', 
			 '', '', '', '', '$pending', '', 
			 '$uid', '$now');";
	}		
	// Atomicity: wrap the whole multi-statement batch in a transaction so a mid-batch
	// failure rolls back cleanly (and, under PHP 8.2 mysqli exceptions, degrades to a
	// graceful error instead of a fatal). Success behaviour is unchanged.
	$mpOk = false;
	$mpErr = '';
	mysqli_begin_transaction($connection);
	try {
		mysqli_multi_query($connection,$qry);
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}
		mysqli_commit($connection);
		$mpOk = true;
	} catch(\Throwable $e){
		$mpErr = $e->getMessage();
		mysqli_rollback($connection);
	}
	if($mpOk){
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}
		mysqli_close($commondb);
		mysqli_close($connection);

		if($mpAjax){ header('Content-Type: application/json'); echo json_encode(array('status'=>'success','message'=>'Parameter Saved Successfully!!')); exit; }

		$lnk = "registration.php?par=$par&rid=$rid";

		echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");
?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Parameter Saved Successfully!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
<?php }	else{
	$dbErr = mysqli_error($connection);
	if(!$dbErr && !empty($mpErr)){ $dbErr = $mpErr; }
	error_log("MPQMS save_parameter failed: rid=$rid params=".json_encode($paridarr)." user=$uid error=".($dbErr ?: 'unknown'));
	if($mpAjax){ header('Content-Type: application/json'); echo json_encode(array('status'=>'error','message'=>'Failed to Save Parameter!! '.($dbErr ? '('.$dbErr.')' : ''))); exit; }
	echo $dbErr; ?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Failed to Save Parameter!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>
	
