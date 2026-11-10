<?php  
	if (session_status() === PHP_SESSION_NONE) session_start();
	session_destroy();
	session_unset();
	header("Location: login.php");
	// header("Location: http://localhost/login.php");
	exit;
?>