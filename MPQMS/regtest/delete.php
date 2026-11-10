<?php
	header('Content-type: application/json; charset=UTF-8');
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	include_once("../config/mpq_validate.php");
	mpq_csrf_check(true);   // delete always responds JSON
	$response = array();
	$rid = $_POST["rid"];
	$rtyp = $_POST["rtyp"];

	// RBAC matrix: Delete is an Administrator-only operation (centralized check).
	// RCDC/NDTL labs have Create/Read/Update but NOT Delete; BFDA/DRA is read-only.
	if(!mpq_can_delete()){
		$response['status']  = 'error';
		$response['message'] = 'You are not authorised to delete this record.';
		echo json_encode($response);
		exit;
	}

	// Completed reports are read-only: neither the report nor any of its parameters
	// may be deleted (server-side lock; see report_lock_lib.php).
	require_once(__DIR__."/report_lock_lib.php");
	$mpLockRid = ($rtyp == "R") ? (int)$rid : (int)explode('-', (string)$rid)[0];
	if(mpqms_report_locked($connection, $mpLockRid)){
		$response['status']  = 'error';
		$response['message'] = mpqms_locked_message();
		echo json_encode($response);
		exit;
	}

	if($rtyp == "R"){
		$qry = "DELETE FROM finalresulttable WHERE registrationID='$rid';";
		$qry .= "DELETE FROM samplestatustable WHERE registrationID='$rid';";
		$qry .= "DELETE FROM labresulttable WHERE registrationID='$rid';";
		$qry .= "DELETE FROM registrationtable WHERE registrationID='$rid';";
	}
	else{
		$delid = explode('-', $rid);
		$qry = "DELETE FROM labresulttable WHERE registrationID='$delid[0]' AND parameterID='$delid[1]';";
	}	
	
	if(mysqli_multi_query($connection,$qry)){
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}
		mysqli_close($commondb);
		mysqli_close($connection);
		$response['status']  = 'success';
		$response['message'] = 'Report Deleted Successfully!!';	
	}
	else{
		$response['status']  = 'error';
		$response['message'] = 'Unable to Delete Report!!';
	}
	echo json_encode($response);	
?>