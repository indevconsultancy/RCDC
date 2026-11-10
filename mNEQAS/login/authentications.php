<?php
    session_start();
	session_regenerate_id();
    require_once("../config/commondb-config.php"); 
    date_default_timezone_set('Asia/Thimphu');
	
    $cdt = date('Y-m-d h:i:s', time());
	$null = NULL;
    $username = $commondb -> real_escape_string($_POST['username'] ?? '');
    $password = $commondb -> real_escape_string($_POST['password'] ?? '');
    $sql = "SELECT t1.username, t2.center_id, t3.access_level FROM login_table t1, user_profile_table t2, user_system_access_table t3 WHERE t1.username=t2.username AND t1.username=t3.username AND t3.system_id=12 AND t1.username='$username' AND t1.password=md5('$password')";
    $result = $commondb->query($sql);

    if ($result->num_rows > 0) {
        $data = $result->fetch_row();
        //for COMMONDB
        $_SESSION['users'] = $data[0];
		$_SESSION['ctid'] = $data[1];
        //For mNEQAS
        $_SESSION['userID'] = $data[0]; 
        $_SESSION['facilityID'] = $data[1]; 
        $_SESSION['roleID'] = $data[2]; 
        header('location: ../dashboard/dashboard.php');
        exit;
    } 
    else{
        $msgLogin = "INVALID Username or Password!!";
        session_destroy();
	    session_unset();
       header("location: login.php?msgLogin= $msgLogin");
       exit;
    } 
?>