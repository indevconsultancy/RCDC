<?php
	$host="localhost";
	$database="rcdc_commondb";
	$user="rcdc_dev";
	$port=3307;
	$password="W3wBrbHxmZG37NJG";
	
	// Create connection
	$commondb = new mysqli($host, $user, $password, $database,$port);
	// Create connection with fallback for local development environments
	$commondb = @new mysqli($host, $user, $password, $database, $port);
	if ($commondb->connect_error) {
		$fallbackDbs = ['rcdc_commondb', 'commondb'];
		foreach ($fallbackDbs as $fdb) {
			$commondb = @new mysqli('127.0.0.1', 'root', '', $fdb, $port);
			if (!$commondb->connect_error) {
				$database = $fdb;
				break;
			}
		}
	}
	// Check connection
	if ($commondb->connect_error) {	die("Connection failed: " . $commondb->connect_error);	} 
	/* Same staging alignment as config/db-config.php: the baseline sql_mode so
	   STRICT does not turn a local warning into an error, and Bhutan time so
	   NOW() does not record six hours behind. */
	$commondb->query("SET SESSION sql_mode='NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
	$commondb->query("SET SESSION time_zone='+06:00'");
	date_default_timezone_set('Asia/Thimphu');

	/* This database is called rcdc_commondb here and plain commondb in the
	   environment BDSIS was developed against. Statements that reach it across the
	   $bdsis connection have to name it, and several had "commondb." written into
	   them -- that schema does not exist on this server, so those queries returned
	   false and the unguarded ->fetch_row() after them was a fatal (the 500 on
	   reports/report-export-excel.php). The real name is published once here
	   rather than repeated in each statement, so neither environment is wired in. */
	if (!defined('BDSIS_COMMONDB')) { define('BDSIS_COMMONDB', $database); }
?>
