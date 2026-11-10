<?php
	include_once("config/auth_ndtlis.php");
	include_once("config/db_config.php");
	if(isset($_GET["par1"]) AND $_GET["par1"]<>""){
		$par1=$_GET["par1"];
		$par2=$_GET["par2"];
		if($par1 == "All"){	$qry=mysqli_query($commondb,"SELECT center_id,center_name FROM ms_health_center_table WHERE ndtlis=1 ORDER BY center_name ASC");	}
		else{	$qry=mysqli_query($commondb,"SELECT center_id,center_name FROM ms_health_center_table WHERE dzongkhag_code='$par1' AND ndtlis=1 ORDER BY center_name ASC");	}
		if($par2 == "S"){	echo("<select name='site' id='site' class='form-control select2bs4' required>"); echo("<option value=''>Select Sampling Site</option>");	}
		else if($par2 == "C") {	echo("<select name='cby' id='cby' class='form-control select2bs4' required>"); echo("<option value=''>Select Collected By</option>");	}
		else{  echo("<select class='site' multiple='multiple' name='site[]' id='site' required>"); }

		// Oct-13 2026 — Collected By listing: ensure National Medical Product Testing
		// Laboratory appears even if the migration row (center_id=998) hasn't been
		// inserted yet, and only inject it once even if a future seed also carries it.
		$mpSawNmptl = false;
		while(list($id,$val)=mysqli_fetch_row($qry)){
			if((int)$id === 998){ $mpSawNmptl = true; }
			echo("<option value='$id'>$val</option>");
		}
		if($par2 === 'C' && !$mpSawNmptl){
			echo("<option value='998'>National Medical Product Testing Laboratory</option>");
		}
		echo("<option value='999'>Others</option>");
		echo("</select>");
	}
?>
<script type="text/javascript" >
	$(function () {
		$('.site').bootstrapDualListbox();
	  })
</script>
