<?php
	require_once("../config/session.php");
	require_once("../config/db-config.php");
	require_once("../includes/rbac.php");
	bdsis_require_module();   // existence oracle: gate it even though the form no longer calls it
	$sid = isset($_POST['sid']) ? (string)$_POST['sid'] : '';
	if(strlen($sid)==10){
		/* Answers only within the caller's own centres. Unscoped, this reported
		   whether an arbitrary subject id exists anywhere in the country, and ids
		   are predictable (<centre>-<yy>-<seq>) -- so it let a facility user
		   enumerate another hospital's caseload one guess at a time without ever
		   opening a record. A subject the caller may not see now reads as free,
		   which is also the truthful answer for the duplicate check this serves. */
		$result = $bdsis->query('SELECT subject_id FROM subject_information_table WHERE subject_id = "'
			. $bdsis->real_escape_string($sid) . '"' . bdsis_scope_clause($bdsis, 'center_id'));
		if($result && ($result->num_rows)>0){echo 0;}
		else{echo 1;}
	}
	else{echo 2;}
	   
?>