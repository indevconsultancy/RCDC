<?php
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	$rid = $_GET["vid"];
	
	$qry = mysqli_query($connection,"SELECT parameter,repetition,result FROM mstestparametertable pt,labresulttable rt WHERE pt.id=rt.parameterID AND rt.registrationID='$rid' ORDER BY id ASC");
	
	echo("<table class='table table-bordered table-striped'>
		<thead>
			<tr class='text-center'>
				<th>Test</th>
				<th>Repetition</th>
				<th>Result</th>
			</tr>
		</thead>
		<tbody>");
	while(list($par,$rpt,$rslt)=mysqli_fetch_row($qry)){ echo("<tr><td>$par</td><td class='text-center'>$rpt</td><td>$rslt</td></tr>"); }
	echo("</tbody></table>");
?>