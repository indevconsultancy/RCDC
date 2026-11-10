<?php
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$action = $conn -> real_escape_string($_POST['action']);
	$cid = $conn -> real_escape_string($_POST['cid']);
	$rid = $conn -> real_escape_string($_POST['rid']);
	$facility = $conn -> real_escape_string($_POST['facility']);
	$doc = $conn -> real_escape_string($_POST['doc']);
	$donar = $conn -> real_escape_string($_POST['donar']);
	$species = $conn -> real_escape_string($_POST['species']);
	$volume = $conn -> real_escape_string($_POST['volume']);
	$specimen = $conn -> real_escape_string($_POST['specimen']);
	$parasite = $conn -> real_escape_string($_POST['parasite']);
	$wbc = $conn -> real_escape_string($_POST['wbc']);
	// Numeric compare/divide: in PHP 8 '' != 0 is true and '' / '' throws a TypeError.
	if((float)$wbc != 0) $density = ((float)$parasite/(float)$wbc)*8000;
	else $density = 0;
	$remarks = $conn -> real_escape_string($_POST['remarks']);
	$status= $conn -> real_escape_string($_POST['status']);
	$sstart= $conn -> real_escape_string($_POST['sstart']);
	$send= $conn -> real_escape_string($_POST['send']);
	$null = NULL;
	$check = false;

	$lnk = "mappings.php?id=$cid&rid=$rid&act=rinfo";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

	if($action == 1){
		$stmt = $conn->prepare("INSERT INTO banking_rack_specimen_informations  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
		$stmt->bind_param("iissssisiiissii", $null, $rid, $facility, $doc, $donar, $specimen, $volume, $species, $parasite, $wbc, $density, $remarks, $status, $sstart, $send);

		if($stmt->execute()){
		    	$stmt->close();
		    	$check = true;		
		} 
	} else {   
		$iid = $conn -> real_escape_string($_POST['iid']);
		$uptqry = "UPDATE banking_rack_specimen_informations SET collection_facility_id='$facility', collection_date='$doc', donar_number='$donar', specimen_type='$specimen', blood_volume='$volume', malaria_species='$species', parasite_count='$parasite', wbc_count='$wbc', density='$density', remarks='$remarks', status='$status', slide_start='$sstart', slide_end='$send' WHERE information_id='$iid'";
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
	
