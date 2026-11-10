<?php	
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
    $action = $conn -> real_escape_string($_POST['action']);
    $id = $conn -> real_escape_string($_POST['id']);
    $round = $conn -> real_escape_string($_POST['round']);
    $slides = isset($_POST['slides']) && trim($_POST['slides']) !== '' ? (int)$_POST['slides'] : 5;
    if ($slides <= 0) { $slides = 5; }

    $mrProbe = @$conn->query("SHOW COLUMNS FROM panel_round_informations LIKE 'total_slides'");
    $mrHasTotalSlides = ($mrProbe && $mrProbe->num_rows > 0);
    if (!$mrHasTotalSlides) {
        @$conn->query("ALTER TABLE panel_round_informations ADD COLUMN total_slides INT(11) NULL AFTER panel_round");
        $mrProbe = @$conn->query("SHOW COLUMNS FROM panel_round_informations LIKE 'total_slides'");
        $mrHasTotalSlides = ($mrProbe && $mrProbe->num_rows > 0);
    }
	$doc = $conn -> real_escape_string($_POST['doc']);
	$dos = $conn -> real_escape_string($_POST['dos']);

	$lnk = "panel-rounds.php";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");
    
    if($action == 1){ 
		if($dos == "") $dos = NULL;
        $id = ($conn->query("SELECT MAX(round_id)+1 FROM panel_round_informations"));
		$id = $id->fetch_row();
		if($id[0] == "") $id[0] = 1;
		if ($mrHasTotalSlides) {
			$stmt = $conn->prepare("INSERT INTO panel_round_informations (round_id, panel_round, total_slides, closing_date, shipment_date) VALUES (?, ?, ?, ?, ?)");
			$stmt->bind_param("iiiss", $id[0], $round, $slides, $doc, $dos);
		} else {
			$stmt = $conn->prepare("INSERT INTO panel_round_informations (round_id, panel_round, closing_date, shipment_date) VALUES (?, ?, ?, ?)");
			$stmt->bind_param("iiss", $id[0], $round, $doc, $dos);
		}
        if($stmt->execute()){   
			$stmt->close(); $check = true; 
		} else $check = false;
    }
    else{   
		if($dos == "") $dos = "1900-01-01"; 
		$id = $conn -> real_escape_string($_POST['id']);
		$mrSet = $mrHasTotalSlides
		       ? "total_slides=" . (int)$slides . ", "
		       : "";
		$uptqry = "UPDATE panel_round_informations SET panel_round='$round', {$mrSet}closing_date='$doc', shipment_date='$dos' WHERE round_id='$id'";
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
