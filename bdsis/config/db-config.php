<?php
	$host="localhost";
	$database="rcdc_bdsis";
	$user="rcdc_dev";
	$port=3307;
	$password="W3wBrbHxmZG37NJG";
	
	// Create connection
	$bdsis = new mysqli($host, $user, $password, $database,$port);
	// Create connection with fallback for local development environments
	$bdsis = @new mysqli($host, $user, $password, $database, $port);
	if ($bdsis->connect_error) {
		$fallbackDbs = ['rcdc_bdsis', 'bdsis-up', 'bdsis'];
		foreach ($fallbackDbs as $fdb) {
			$bdsis = @new mysqli('127.0.0.1', 'root', '', $fdb, $port);
			if (!$bdsis->connect_error) {
				$database = $fdb;
				break;
			}
		}
	}
	// Check connection
	if ($bdsis->connect_error) {	die("Connection failed: " . $bdsis->connect_error);	} 

	/* Staging runs MariaDB 10.11 with sql_mode=STRICT_TRANS_TABLES; the
	   environment this application was built and tested against runs without
	   STRICT. Under STRICT, an empty form field written to one of the 15 NOT
	   NULL columns that carry no default -- sample_information_table.
	   collection_date and subject_information_table.recorded_on among them --
	   is a hard error rather than a warning, so a save that succeeds locally
	   fails here. Pinning the session to the baseline sql_mode makes both
	   environments behave identically.

	   The server clock is UTC and Bhutan is UTC+6, so NOW() and CURDATE() were
	   stamping rows up to six hours behind local time -- anything saved before
	   06:00 BTT landed on the previous day. Both the MySQL session and PHP are
	   pinned to Bhutan time. */
	$bdsis->query("SET SESSION sql_mode='NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
	$bdsis->query("SET SESSION time_zone='+06:00'");
	date_default_timezone_set('Asia/Thimphu');
?>