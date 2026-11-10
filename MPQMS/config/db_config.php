<?php
	// Server (rcdc-dev.indevconsultancy.in) MPQMS database configuration.
	// Databases carry the account prefix rcdc_. The MPQMS code resolves cross-database
	// references through includes/db_names.php, so the same PHP works on the local XAMPP
	// setup and on this server unchanged.

	$host="localhost";
	$database="rcdc_commondb";
	$port = 3307;
	$user="root";
	$password="";

	$commondb=mysqli_connect($host, $user, $password, $database,$port);
	if($commondb === false){die("ERROR: Could not connect. " . mysqli_connect_error());}

	$db_host="localhost";
	$db_user="root";
	$db_password="";
	$port = 3307;
	$db_name="rcdc_ndtlisdb";

	$connection=mysqli_connect($db_host, $db_user, $db_password,$db_name,$port);
	if($connection === false){die("ERROR: Could not connect. " . mysqli_connect_error());}

?>
