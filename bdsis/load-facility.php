<?php
	require_once("config/session.php");
	require_once("config/commondb-config.php");
	require_once("includes/rbac.php");
	bdsis_require_module();
	if(isset($_GET["dzongkhag"]) AND $_GET["dzongkhag"]<>""){
		$dzongkhag=$commondb->real_escape_string($_GET["dzongkhag"]);
		/* Restricted to the centres this caller may see, whatever dzongkhag is
		   asked for -- this list feeds a filter that must not offer another
		   facility. The restriction came from a FACILITY-only test, so a
		   district-scoped caller could ask for any dzongkhag and be handed its
		   facilities; bdsis_scope_clause() covers every scope from one rule. */
		$sql = "SELECT center_id, center_name FROM ms_health_center_table WHERE dzongkhag_code='".$dzongkhag."' AND dsis=1"
		     . bdsis_scope_clause($commondb, 'center_id');
		$result = $commondb->query($sql);
		
		echo("<select name='facility' id='facility' class='form-control facility'><option value=''>Select facility</option>");
		while($row = $result->fetch_row()) {
			echo("<option value='$row[0]'>$row[1]</option>");
		}
		echo("</select>");
	}else{
		echo("<select name='facility' id='facility' class='form-control'><option value=''>Select facility</option>");
	}
?>

<script>
    $(function () {
		$(document).ready(function(){
			$("#facility").change(function(){
				var facility = $('#facility').val();
				$("#sampleDiv").load("load-shipment-sample.php?facility="+facility);
			});
		});  
	})
</script>             