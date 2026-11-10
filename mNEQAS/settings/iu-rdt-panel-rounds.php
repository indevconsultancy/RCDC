<?php	
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
    $action = $conn -> real_escape_string($_POST['action']);
    $id = $conn -> real_escape_string($_POST['id']);
    $round = $conn -> real_escape_string($_POST['round']);
    $panels = $conn -> real_escape_string($_POST['panels']);
	$doc = $conn -> real_escape_string($_POST['doc']);
	$dos = $conn -> real_escape_string($_POST['dos']);

	$lnk = "rdt-panel-rounds.php";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");
    
    if($action == 1){ 
		if($dos == "") $dos = NULL;
        $id = ($conn->query("SELECT MAX(round_id)+1 FROM rdt_panel_round_informations"));
		$id = $id->fetch_row();
		if($id[0] == "") $id[0] = 1;
		$stmt = $conn->prepare("INSERT INTO rdt_panel_round_informations  VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $id[0], $round, $panels, $doc, $dos);
        if($stmt->execute()){   
			$stmt->close(); $check = true; 
		} else $check = false;
    }
    else{   
		if($dos == "") $dos = "1900-01-01"; 
		$id = $conn -> real_escape_string($_POST['id']);
		$uptqry = "UPDATE rdt_panel_round_informations SET panel_round='$round', total_panels='$panels', closing_date='$doc', shipment_date='$dos' WHERE round_id='$id'";
        if($conn->query($uptqry)) $check = true;  
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
	
