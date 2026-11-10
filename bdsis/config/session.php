<?php
	/* Staging's clock is UTC, Bhutan is UTC+6. Set before anything reads a
	   date, so pages that never open a DB connection agree with those that do. */
	date_default_timezone_set('Asia/Thimphu');
	if (session_status() === PHP_SESSION_NONE) session_start();
	/* Read with a default: these run before the check below, so an expired
	   session used to warn on every page before it redirected. */
	$user=$_SESSION["users"] ?? '';
	$ctid=$_SESSION["ctid"] ?? '';
	$acl=$_SESSION["acl"] ?? '';
	$usercat=$_SESSION["usercat"] ?? '';
	$unitacc=$_SESSION["unitacc"] ?? '';
	
	if(!isset($_SESSION["users"])) {
		/* A person who has been logged out gets sent to the login page, which is
		   what the script redirect is for. A programmatic caller cannot follow it
		   -- it would read a 200 with a <script> tag as success -- so anything
		   that is not a plain page load is answered with a real 401 instead. */
		$isPost = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST";
		$isXhr  = strtolower($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "xmlhttprequest";
		if ($isPost || $isXhr) {
			if (!headers_sent()) { http_response_code(401); }
			exit('Unauthorized: your session has expired. Please sign in again.');
		}
		echo '<script>window.location = "/BDSIS/login/signout.php";</script>';
		exit;
	}
?>