<?php
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
    $action = $conn -> real_escape_string($_POST['action']);
    $cid = $conn -> real_escape_string($_POST['cid']);
    $cname = $conn -> real_escape_string($_POST['cname']);
    $compartment = $conn -> real_escape_string($_POST['compartment']);
	$rack = $conn -> real_escape_string($_POST['rack']);
	$capacity = $conn -> real_escape_string($_POST['capacity']);
	$column = $conn -> real_escape_string($_POST['column']);
	$row = $conn -> real_escape_string($_POST['row']);
	$location = $conn -> real_escape_string($_POST['location']);
	$remarks = $conn -> real_escape_string($_POST['remarks'] ?? '');
	$datetime = date("Y-m-d h:i:s");

	//$targetdir = getcwd()."/images/";
	$targetdir = "images/";
    $filename = $_FILES['image']['name'] ?? '';
    $targetfile = $targetdir.basename($filename);

	$lnk = "cabinets.php";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

    if($action == 1){ 
		$stmt = $conn->prepare("INSERT INTO banking_cabinet_informations  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isiiiiissss", $cid, $cname, $compartment, $rack, $capacity, $column, $row, $location, $targetfile, $remarks, $datetime);
        if($stmt->execute()){
        	move_uploaded_file($_FILES["image"]["tmp_name"] ?? '', $targetfile);
			$stmt->close(); $check = true; 
		} else $check = false;
    }
    else{   
		$uptqry = "UPDATE banking_cabinet_informations SET cabinet_name='$cname', compartments_per_cabinet='$compartment', racks_per_compartment='$rack', slide_capacity='$capacity', columns_per_rack='$column', rows_per_rack='$row', location='$location', remarks='$remarks' WHERE cabinet_id='$cid'";
        if($conn->query($uptqry)){ 
        	$check = true;  
        	if($filename != ""){
        		$old_image = ($conn->query("SELECT image_path FROM banking_cabinet_informations WHERE cabinet_id='$cid'"));
                $old_image = $old_image->fetch_row();
                if ($old_image && $old_image[0] !== null && is_file($old_image[0])) unlink($old_image[0]);
        		$conn->query("UPDATE banking_cabinet_informations SET image_path='$targetfile' WHERE cabinet_id='$cid'");
        		move_uploaded_file($_FILES["image"]["tmp_name"], $targetfile);
        	}
        }	
		else $check = false;
    }  
    
    if($check){
        $conn->close();	
?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Submitted Successfully!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
	<?php }	else { echo $conn->error;?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Submission Failed!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>
	
