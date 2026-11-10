<?php


    if(session_status() === PHP_SESSION_NONE){
		session_start();
	}
	if(!isset($_SESSION['users']) || empty($_SESSION['users'])){
		$_SESSION=[];

		session_unset();
		session_destroy();
		header("Location: /MPQMS/login/login.php");
		exit();
	}
	// Per-session CSRF token for authenticated write requests (see mpq_csrf_check()).
	if(empty($_SESSION['csrf'])){
		$_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(32)) : bin2hex(openssl_random_pseudo_bytes(32));
	}
?>