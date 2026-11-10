<?php
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php");  
	if(isset($_GET["cid"]) AND $_GET["cid"]<>""){
		$cid = $conn->real_escape_string($_GET["cid"]);
		
		$sql = $conn->query("SELECT DISTINCT compartment FROM banking_rack_informations WHERE cabinet_id='$cid' ORDER BY compartment ASC");
		echo("<select name='compartment' id='compartment' class='form-control'><option value=''>Select Compartment</option>");
		if ($sql) {
			while($row = $sql->fetch_assoc()) {
				$comp = htmlspecialchars($row['compartment'] ?? '');
				echo("<option value='$comp'>$comp</option>");
			}
		}
		echo("</select>");
	}
?>
