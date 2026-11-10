<?php
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	$mpAjax = !empty($_REQUEST['ajax']);
	if(!$mpAjax){ include_once("../includes/css-jquery.php"); }   // AJAX responses must be pure JSON
	include_once("../config/mpq_validate.php");
	mpq_csrf_check($mpAjax);

	$type=mysqli_real_escape_string($connection,$_REQUEST["type"]);

	// Effective-role RBAC (config/mpq_validate.php). Each operation code maps to the module and
	// action it performs, and the SAME matrix drives the buttons on the page, so a hidden control,
	// a hand-typed URL and a forged POST all reach one decision. Deny by default: an operation
	// code that is not listed here is refused outright.
	//   Formulation / Parameter / Category -> settings_core : Administrator + NDTL
	//   EDL                                -> edl           : Administrator only
	//   Manufacturer / MAH                 -> manufacturer / mah : Administrator + NDTL + NMRL + BFDA
	// Delete is NOT handled here at all; it lives in delete_master.php and stays Administrator-only.
	$mpOpMap = array(
		'NF'=>array('settings_core','add'),  'UF'=>array('settings_core','edit'),
		'NP'=>array('settings_core','add'),  'UP'=>array('settings_core','edit'),
		'NC'=>array('settings_core','add'),  'UC'=>array('settings_core','edit'),
		'NE'=>array('edl','add'),            'UE'=>array('edl','edit'),
		'NM'=>array('manufacturer','add'),   'UM'=>array('manufacturer','edit'),
		'NH'=>array('mah','add'),            'UH'=>array('mah','edit'),
	);
	if(!isset($mpOpMap[$type])){
		mpq_fail('Unknown settings operation.', $mpAjax);
	}
	mpq_require($mpOpMap[$type][0], $mpOpMap[$type][1], $mpAjax,
		'You are not authorised to '.($mpOpMap[$type][1] === 'add' ? 'add' : 'edit').' this record.');

	// --- Server-side required-field validation (mirrors the client `required` rules) ---
	include_once("../config/mpq_validate.php");
	$mpReqMap = array(
		'NF'=>array('for'=>'Formulation','sts'=>'Status'),                 'UF'=>array('for'=>'Formulation','sts'=>'Status','id'=>'Record'),
		'NP'=>array('par'=>'Parameter','rnk'=>'Ranking','sts'=>'Status'),  'UP'=>array('par'=>'Parameter','rnk'=>'Ranking','sts'=>'Status','id'=>'Record'),
		'NE'=>array('edl'=>'EDL','sts'=>'Status'),                         'UE'=>array('edl'=>'EDL','sts'=>'Status','id'=>'Record'),
		'NC'=>array('cat'=>'Category','sts'=>'Status'),                    'UC'=>array('cat'=>'Category','sts'=>'Status','id'=>'Record'),
		'NM'=>array('mfg'=>'Manufacturer','sts'=>'Status'),               'UM'=>array('mfg'=>'Manufacturer','sts'=>'Status','id'=>'Record'),
		'NH'=>array('mah'=>'MAH','sts'=>'Status'),                        'UH'=>array('mah'=>'MAH','sts'=>'Status','id'=>'Record'),
	);
	if(isset($mpReqMap[$type])){ mpq_guard($mpReqMap[$type], $mpAjax); }
	if(($type=='NP'||$type=='UP') && isset($_REQUEST['rnk']) && trim($_REQUEST['rnk'])!=='' && !is_numeric($_REQUEST['rnk'])){
		mpq_fail('Ranking must be a number.', $mpAjax);
	}

	// Serialize concurrent "new record" inserts so two admins can't read the same
	// MAX(id)+1 and collide. Advisory lock auto-releases when this connection closes.
	if(isset($type[0]) && $type[0]==='N'){ @mysqli_query($connection, "SELECT GET_LOCK('mpq_settings_newid',5)"); }

	if($type == "NF" || $type == "UF"){
		$for=mysqli_real_escape_string($connection,$_REQUEST["for"]);
		$sts=mysqli_real_escape_string($connection,$_REQUEST["sts"]);
		$lnk = "formulation.php";
		if($type == "NF"){
			list($id) = mysqli_fetch_row(mysqli_query($connection,"SELECT COALESCE(MAX(id),0)+1 FROM msformulationtable WHERE id!=99"));
			$qry = "INSERT INTO msformulationtable VALUES('$id','$for','$sts')";	
		}
		else{
			$id=mysqli_real_escape_string($connection,$_REQUEST["id"]);
			$qry = "UPDATE msformulationtable SET formulation='$for', status='$sts' WHERE id='$id'";
		}
	}	
	
	if($type == "NP" || $type == "UP"){	
		$par=mysqli_real_escape_string($connection,$_REQUEST["par"]);
		$unt=mysqli_real_escape_string($connection,$_REQUEST["unt"]);
		$rnk=mysqli_real_escape_string($connection,$_REQUEST["rnk"]);
		$sts=mysqli_real_escape_string($connection,$_REQUEST["sts"]);
		$lnk = "parameter.php";
		if($type == "NP"){
			list($id) = mysqli_fetch_row(mysqli_query($connection,"SELECT COALESCE(MAX(id),0)+1 FROM mstestparametertable WHERE id!=99"));
			$qry = "INSERT INTO mstestparametertable VALUES('$id','$par','$unt','$rnk','$sts')";	
		}
		else{
			$id=mysqli_real_escape_string($connection,$_REQUEST["id"]);
			$qry = "UPDATE mstestparametertable SET parameter='$par',unit='$unt',ranking='$rnk',status='$sts' WHERE id='$id'";
		}
	}	

	if($type == "NE" || $type == "UE"){
		$edl=mysqli_real_escape_string($connection,$_REQUEST["edl"]);
		$sts=mysqli_real_escape_string($connection,$_REQUEST["sts"]);
		$lnk = "edl.php";
		list($cntr) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM msdrugcategorytable WHERE status=1"));
		if($type == "NE"){
			list($id) = mysqli_fetch_row(mysqli_query($connection,"SELECT COALESCE(MAX(id),0)+1 FROM msedltable WHERE id!=999"));
			$qry = "INSERT INTO msedltable VALUES('$id','$edl','$sts');";	
			for($i=0;$i<$cntr;$i++){
				$name = "cat".$i;
				if(isset($_POST[$name])){
					$cat=$_POST[$name];
					$qry .= "INSERT INTO msedlcategorymaptable VALUES(NULL,'$id','$cat');";	
				}	
			}
		}
		else{
			$id=mysqli_real_escape_string($connection,$_REQUEST["id"]);
			$qry = "UPDATE msedltable SET edl='$edl', status='$sts' WHERE id='$id';";

			$qry .= "DELETE FROM msedlcategorymaptable WHERE edlID='$id';";	
			for($i=0;$i<$cntr;$i++){
				$name = "cat".$i;
				if(isset($_POST[$name])){
					$cat=$_POST[$name];
					$qry .= "INSERT INTO msedlcategorymaptable VALUES(NULL,'$id','$cat');";	
				}	
			}
		}
	}	

	if($type == "NC" || $type == "UC"){
		$cat=mysqli_real_escape_string($connection,$_REQUEST["cat"]);
		$sts=mysqli_real_escape_string($connection,$_REQUEST["sts"]);
		$lnk = "category.php";
		if($type == "NC"){
			list($id) = mysqli_fetch_row(mysqli_query($connection,"SELECT COALESCE(MAX(id),0)+1 FROM msdrugcategorytable WHERE id!=99"));
			$qry = "INSERT INTO msdrugcategorytable VALUES('$id','$cat','$sts')";	
		}
		else{
			$id=mysqli_real_escape_string($connection,$_REQUEST["id"]);
			$qry = "UPDATE msdrugcategorytable SET category='$cat', status='$sts' WHERE id='$id'";
		}
	}
		
	if($type == "NM" || $type == "UM"){
		$mfg=mysqli_real_escape_string($connection,$_REQUEST["mfg"]);
		$sts=mysqli_real_escape_string($connection,$_REQUEST["sts"]);
		$lnk = "manufacturer.php";
		if($type == "NM"){
			list($id) = mysqli_fetch_row(mysqli_query($connection,"SELECT COALESCE(MAX(id),0)+1 FROM msmanufacturertable WHERE id!=999"));
			$qry = "INSERT INTO msmanufacturertable VALUES('$id','$mfg','$sts')";	
		}
		else{
			$id=mysqli_real_escape_string($connection,$_REQUEST["id"]);
			$qry = "UPDATE msmanufacturertable SET name='$mfg', status='$sts' WHERE id='$id'";
		}
	}	
	
	if($type == "NH" || $type == "UH"){
		$mah=mysqli_real_escape_string($connection,$_REQUEST["mah"]);
		$sts=mysqli_real_escape_string($connection,$_REQUEST["sts"]);
		$lnk = "mah.php";
		if($type == "NH"){
			list($id) = mysqli_fetch_row(mysqli_query($connection,"SELECT COALESCE(MAX(id),0)+1 FROM msmahtable WHERE id!=99"));
			$qry = "INSERT INTO msmahtable VALUES('$id','$mah','$sts')";	
		}
		else{
			$id=mysqli_real_escape_string($connection,$_REQUEST["id"]);
			$qry = "UPDATE msmahtable SET name='$mah', status='$sts' WHERE id='$id'";
		}
	}	

	// --- Master-data change audit (who / what / when), appended to the SAME atomic batch ---
	$mpAuditEntity = array('F'=>'formulation','P'=>'parameter','C'=>'category','E'=>'edl','M'=>'manufacturer','H'=>'mah');
	if(isset($type[1]) && isset($mpAuditEntity[$type[1]]) && isset($id)){
		switch($type[1]){
			case 'F': $aName = $for ?? ''; break;
			case 'P': $aName = $par ?? ''; break;
			case 'C': $aName = $cat ?? ''; break;
			case 'E': $aName = $edl ?? ''; break;
			case 'M': $aName = $mfg ?? ''; break;
			case 'H': $aName = $mah ?? ''; break;
			default:  $aName = '';
		}
		$aEntity  = $mpAuditEntity[$type[1]];
		$aAction  = ($type[0] === 'N') ? 'INSERT' : 'UPDATE';
		$aDetails = mysqli_real_escape_string($connection, json_encode(array('name'=>stripslashes((string)$aName), 'status'=>(isset($sts)?$sts:''))));
		$aUser    = mysqli_real_escape_string($connection, $_SESSION['users'] ?? '');
		$aId      = (int)$id;
		// The single-statement modules (F/P/C/M/H) build their INSERT/UPDATE without a trailing
		// ';', so terminate the batch before the audit row is appended — otherwise the two
		// statements run together as one invalid statement and the whole save is rolled back.
		$qry = rtrim($qry); if($qry !== '' && substr($qry, -1) !== ';'){ $qry .= ';'; }
		$qry .= "INSERT INTO masterdata_audit(entity,record_id,action,details,changed_by,changed_at) VALUES('$aEntity','$aId','$aAction','$aDetails','$aUser','".date('Y-m-d H:i:s')."');";
	}

	// Atomicity: wrap the whole multi-statement batch in a transaction so a mid-batch
	// failure rolls back cleanly (and, under PHP 8.2 mysqli exceptions, degrades to a
	// graceful error instead of a fatal). Success behaviour is unchanged.
	$mpOk = false; $mpErr = '';
	mysqli_begin_transaction($connection);
	try {
		mysqli_multi_query($connection,$qry);
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}
		mysqli_commit($connection);
		$mpOk = true;
	} catch(\Throwable $e){ mysqli_rollback($connection); $mpErr = $e->getMessage(); }
	if($mpOk){
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){;}
		mysqli_close($connection);

		// Professional AJAX response: return only the affected row so the client can
		// update the table in place (no full page reload).
		if($mpAjax){
			$isNew = ($type[0] === 'N');
			$statusSpan = ($sts == 1)
				? "<span class='text-success font-weight-bold'>Active</span>"
				: "<span class='text-muted'>Inactive</span>";
			$edit = "<a href='".$lnk."?id=".$id."' title='Edit'><i class='far fa-edit'></i></a>";
			// Same Action cell the list page renders: Administrators also get the Delete icon,
			// so a record just added/edited can be deleted without reloading the page.
			if((int)($_SESSION['acl'] ?? 0) === 1){
				$edit .= " &nbsp;<a href='javascript:void(0)' class='mp-del-master' data-id='".(int)$id."' data-type='D".$type[1]."' data-name=\"".htmlspecialchars(stripslashes((string)($aName ?? '')), ENT_QUOTES)."\" title='Delete'><i class='fas fa-trash-alt text-danger'></i></a>";
			}
			switch($type[1]){
				case 'C': $name = stripslashes($cat); $row = array((string)$id, $name, $statusSpan, $edit); break;
				case 'F': $name = stripslashes($for); $row = array((string)$id, $name, $statusSpan, $edit); break;
				case 'E': $name = stripslashes($edl); $row = array((string)$id, $name, $statusSpan, $edit); break;
				case 'M': $name = stripslashes($mfg); $row = array((string)$id, $name, $statusSpan, $edit); break;
				case 'H': $name = stripslashes($mah); $row = array((string)$id, $name, $statusSpan, $edit); break;
				case 'P': $row = array((string)$id, stripslashes($par), stripslashes($unt), stripslashes($rnk), $statusSpan, $edit); break;
				default:  $row = array((string)$id, '', $statusSpan, $edit);
			}
			header('Content-Type: application/json');
			echo json_encode(array(
				'status'  => 'success',
				'message' => $isNew ? 'Saved successfully!' : 'Updated successfully!',
				'mode'    => $isNew ? 'new' : 'update',
				'id'      => (string)$id,
				'row'     => $row
			));
			exit;
		}
		echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");
?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Updated Successfully!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
<?php }	else{
		if($mpAjax){
			header('Content-Type: application/json');
			echo json_encode(array('status'=>'error','message'=>'Failed to save. Nothing was changed. '.($mpErr !== '' ? '[Details: '.$mpErr.']' : mysqli_error($connection))));
			exit;
		}
		echo htmlspecialchars($mpErr !== '' ? $mpErr : mysqli_error($connection)); ?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Failed to Update!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>