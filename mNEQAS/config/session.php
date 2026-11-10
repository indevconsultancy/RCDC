<?php
	if (session_status() === PHP_SESSION_NONE) {
		session_start();
	}
	if(!isset($_SESSION["userID"])) {
		// header("Location : /MNEQAS/login/signout.php");
		echo '<script>window.location = "/MNEQAS/login/signout.php";</script>';
		exit;
	}
	// Authorization identity: re-read this user's system_id=12 role and facility
	// from commondb on every request (see mn_bootstrap_session in config/auth.php).
	// The PHP session cookie is shared with other applications on this server, so
	// roleID/facilityID stored in the session are never trusted on their own.
	require_once __DIR__ . '/commondb-config.php';
	require_once __DIR__ . '/auth.php';
	mn_bootstrap_session($commondb);
	// Default-deny folder policy (Settings/Slide Banking = RCDC; modules = roles 1-4).
	mn_enforce_area_policy();
	// Buffer page output so a later authorization denial (mn_deny*) can still send a
	// clean HTTP 403 even when the endpoint has already echoed its HTML/asset includes.
	ob_start();
?>
