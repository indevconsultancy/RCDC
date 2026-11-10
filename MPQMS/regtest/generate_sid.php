<?php
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	require_once(dirname(__DIR__)."/includes/db_names.php");   // real database names (local or prefixed host)
	if(isset($_GET["par1"]) AND isset($_GET["par2"])){
		$did=$_GET["par1"];
		$dor=$_GET["par2"];
		$inex=$_GET["par3"];
		$yr=substr($dor,0,4);
		list($code)=mysqli_fetch_row(mysqli_query($commondb,"SELECT dzongkhag_name_short FROM ms_dzongkhag_table WHERE dzongkhag_code='$did'"));
		
		if($inex=="In-country") $sid ="NMPTL/$code/$yr/";
		else $sid = "EX/$code/$yr/"; 
		
		list($maxsid)=mysqli_fetch_row(mysqli_query($connection,"SELECT MAX(CAST(SUBSTRING_INDEX(sampleID, '/', -1) AS UNSIGNED)) FROM `".mpq_db($connection, 'ndtlisdb')."`.registrationtable WHERE sampleID like '%$sid%'"));
		
		if($maxsid=="")$num=1;
		else $num=$maxsid+1;
		
		if($inex=="In-country") $sid ="NMPTL/$code/$yr/$num";
		else $sid = "EX/$code/$yr/$num"; 	
			
		
		echo("<input type='text' name='sid' id='sid' value='$sid' class='form-control' required>");
	}
?>