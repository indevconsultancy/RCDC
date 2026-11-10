<?php
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
    $cid = $conn -> real_escape_string($_POST['cid']);
    $comp = $conn -> real_escape_string($_POST['comp']);
	$rack = $conn -> real_escape_string($_POST['rack']);
	$slot = $conn -> real_escape_string($_POST['slot']);

	$lnk = "mappings.php?id=$cid";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

	$stmt1 = $conn->prepare("INSERT INTO banking_rack_informations  VALUES (?, ?, ?, ?)");
	$stmt2 = $conn->prepare("INSERT INTO banking_slot_informations  VALUES (?, ?, ?, ?, ?)");

	$check = false;
	$null = NULL;
	$status = "Empty";
	$r =1;
    for($m=1; $m<=$rack; $m++){
	    for($n=1; $n<=$comp; $n++){
	    	$compname = "Compartment $n";
	    	$rackname = "Rack $r";
	    	
	    	$rid = ($conn->query("SELECT MAX(rack_id) FROM banking_rack_informations"));
            $rid = $rid->fetch_row();
            if($rid[0] == "") $newrid = 1;
            else $newrid = $rid[0] + 1; 

	    	$stmt1->bind_param("isis", $newrid, $rackname, $cid, $compname);
	    	
	    	if($stmt1->execute()){
	    		for($q=1; $q<=$slot; $q++){
	    			$stmt2->bind_param("iiiis", $null, $newrid, $null, $null, $status);
	    			$stmt2->execute();
	    		}	
	    	} 
	    }
	    $r++;
	}

	$check = true;
    
   if($check){
        $conn->close();	
?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Mapped Successfully!!',
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
				text: 'Mapping Failed!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?> 
	
