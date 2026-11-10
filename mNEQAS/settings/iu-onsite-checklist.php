<?php	
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php");

    $action = $conn -> real_escape_string($_POST['action']);    
    $subcategory = $conn -> real_escape_string($_POST['subcategory']);
	$status = $conn -> real_escape_string($_POST['status']);
	$rank = $conn -> real_escape_string($_POST['rank']);
	$indicator = $conn -> real_escape_string($_POST['indicator']);

	$lnk = "onsite-checklist.php";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");
    
    if($action == 1){ 
        $rid = ($conn->query("SELECT MAX(checklist_id)+1 FROM ms_onsite_checklists"));
		$rid = $rid->fetch_row();
		$stmt = $conn->prepare("INSERT INTO ms_onsite_checklists(subcategory_id, checklist, checklist_order, checklist_status)  VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isii", $subcategory, $indicator, $rank, $status);
        if($stmt->execute()){   
			$stmt->close(); $check = true; 
		} else $check = false;
    }
    else{    
		$rid = $conn -> real_escape_string($_POST['id']);
		$uptqry = "UPDATE ms_onsite_checklists SET subcategory_id='$subcategory', checklist='$indicator', checklist_status='$status', checklist_order='$rank' WHERE checklist_id='$rid'";
        if($conn->query($uptqry)){
			$check = true;  
		}   else $check = false;
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
	
