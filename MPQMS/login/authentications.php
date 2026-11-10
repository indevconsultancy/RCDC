<?php
    session_start();
	session_regenerate_id();
    require_once("../config/db_config.php"); 
    date_default_timezone_set('Asia/Thimphu');
	
    $cdt = date('Y-m-d h:i:s', time());
	$null = NULL;
    $username = $commondb -> real_escape_string($_POST['username']);
    $password = $commondb -> real_escape_string($_POST['password']);
    $sql = "SELECT t1.username, t2.center_id, t3.access_level, t3.user_category, t3.unit_access FROM login_table t1, user_profile_table t2, user_system_access_table t3 WHERE t1.username=t2.username AND t1.username=t3.username AND t3.system_id IN (9) AND t1.username='$username' AND t1.password=md5('$password')";
    $result = $commondb->query($sql);

    if ($result->num_rows > 0) {
        $data = $result->fetch_row();
        $_SESSION['users'] = $data[0]; 
        $acl = $data[2];
        $ucat = $data[3];
        $uacc = $data[4];
        $par = base64_encode($acl.":".$ucat.":".$uacc); 
        header("location: ../dashboard/dashboard.php?par=$par");
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