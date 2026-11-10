<?php
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	require_once(dirname(__DIR__)."/includes/db_names.php");   // real database names (local or prefixed host)
	if(isset($_GET["dzongkhag"]) AND $_GET["dzongkhag"]<>""){
		$dzongkhag=$_GET["dzongkhag"];
		$sql = "SELECT DISTINCT t1.center_id,t1.center_name FROM ms_health_center_table t1, `".mpq_db($commondb, 'ndtlisdb')."`.registrationtable t2 WHERE t1.center_id=t2.collectID AND t1.dzongkhag_code='$dzongkhag' ORDER BY t1.center_name ASC"; 
		$result = $commondb->query($sql);
		echo("<select name='facility' id='facility' class='form-control'><option value=''>Select facility</option>");
		while($row = $result->fetch_row()) {
			echo("<option value='$row[0]'>$row[1]</option>");
		}
		echo("</select>");
	}else{
		echo("<select name='facility' id='facility' class='form-control'><option value=''>Select facility</option>");
	}
?>  