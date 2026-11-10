<?php
    // Not used by mNEQAS: nothing links here, and the code below belongs to another
    // system (pb_users table / "Pride Bhutan" mail) that does not exist in this database.
    // Password resets go through the shared RCDC login (/COMMONNEW/password_reset/).
    http_response_code(404);
    exit;
    require_once("../config/db-config.php");
    require_once("../includes/css-jquery.php"); 
	
    $email = $conn -> real_escape_string($_POST['email'] ?? '');
    
    function generateRandomString($length = 25) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
    
    $chkRecord = $conn->query("SELECT * FROM pb_users WHERE email='$email'");
    if ($chkRecord->num_rows > 0) {
        $uid = ($conn->query("SELECT id FROM pb_users WHERE email='$email'"))->fetch_row();
        $key = generateRandomString(5);
        $randomString = generateRandomString(5);
        $qry = "UPDATE pb_users SET password=SHA1(CONCAT('$randomString','$key')), key_string='$key' WHERE id='$uid[0]'";
        if($conn->query($qry)){
            $check = True; 
            $subject="New Password for Pride Bhutan Information Management System";		
            $content="Your new password for  Pride Bhutan Information Management System is ".$randomString;
            $header  = 'From : ims@pridebhutan.com';
            mail($email,$subject,$content,$header);
        }    
        else $check = False;
    } 
    else $check = False;  

    $lnk = "login.php";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

    if($check) { 
    	$conn->close();		
    ?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Password reset successful. New Password has been sent to registered Email ID!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
	<?php }	else{ ?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Not a registered Email ID. Password reset unsuccessful !!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
    <?php } ?>