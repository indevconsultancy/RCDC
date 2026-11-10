<?php
	require_once("../config/session.php"); require_once("../config/auth.php");
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$cdt = date('Y-m-d h:i:s', time());
	$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
	$biid = isset($_GET["biid"]) ? (int)$_GET["biid"] : 0;
	$type = isset($_GET["type"]) ? (string)$_GET["type"] : '';

	/* Authorization before any SQL: write role, record ownership, then stage owner.
	   ac/recom/rcpt/c1 = RCDC stages; c2 (Controller 2 + scores) and bi = Administrator;
	   rslt (district slide row) = RCDC, or the owning Health Worker until the shipment is
	   received / Controller 1 starts. Child ids must belong to the record. */
	mn_require_write();
	if (!in_array($type, array('ac', 'recom', 'c2', 'c1', 'rcpt', 'rslt', 'bi', 'reopen'), true)) { mn_deny('Unknown delete type.'); }
	mn_require_record($conn, 'blinded', $biid);
	if ($type === 'reopen') {
		/* Reopening un-issues a report that has already been released, so it stays
		   with the Administrator. It clears no data — only the finalized flag. */
		mn_require_admin('Only an Administrator can reopen a finalized report.');
	} elseif ($type === 'bi') {
		// Deleting a whole record stays with the Administrator.
		mn_require_admin('Only an Administrator can delete an entire record.');
	} elseif ($type === 'c2') {
		/* Clearing Controller 2 belongs to the role that enters it. Controller 2 is
		   an RCDC stage in iu-blinded.php, so this now matches. The finalized-record
		   rule below is unchanged and still blocks a cleared score on an issued report. */
		mn_require_rcdc('This Blinded Rechecking stage is restricted to RCDC users.');
	} elseif ($type === 'rslt') {
		mn_require_child($conn, 'blinded_result', $id, $biid);
		if (!mn_is_rcdc()) {
			$lk = $conn->prepare("SELECT (receive_date IS NOT NULL AND receive_date <> ''),
			                             (SELECT COUNT(*) FROM blinded_results WHERE blinded_id = ? AND slide_status = 'Intact' AND c1_microscopy IS NOT NULL AND c1_microscopy <> '')
			                        FROM blinded_informations WHERE blinded_id = ?");
			$lk->bind_param('ii', $biid, $biid);
			$lk->execute();
			$lkRow = $lk->get_result()->fetch_row();
			$lk->close();
			if ($lkRow && ((int)$lkRow[0] === 1 || (int)$lkRow[1] > 0)) {
				mn_deny('District data is locked once RCDC has received the shipment or started Controller 1.', $biid);
			}
		}
	} else {
		mn_require_rcdc('This Blinded Rechecking stage is restricted to RCDC users.');
		if ($type === 'c1') { mn_require_child($conn, 'blinded_result', $id, $biid); }
	}

	/* Downstream dependency rules (BRD: a stage can be undone only when no downstream
	   data exists). Enforced server-side for every role, including RCDC, so an undo can
	   never silently strip a later stage's data. */
	$blScalar = function($sql, $param) use ($conn) {
		$st = $conn->prepare($sql);
		$st->bind_param('i', $param);
		$st->execute();
		$row = $st->get_result()->fetch_row();
		$st->close();
		return $row ? $row[0] : null;
	};
	if ($type === 'rcpt') {
		$c1Count = (int)$blScalar("SELECT COUNT(*) FROM blinded_results WHERE blinded_id = ? AND c1_microscopy IS NOT NULL AND c1_microscopy <> ''", $biid);
		if ($c1Count > 0) { mn_deny('Shipment receipt cannot be undone while Controller 1 results exist. Clear Controller 1 first.', $biid); }
	}
	if ($type === 'c1') {
		$c2OnRow = (string)$blScalar("SELECT COALESCE(c2_microscopy, '') FROM blinded_results WHERE result_id = ?", $id);
		if ($c2OnRow !== '') { mn_deny('Controller 1 cannot be cleared while Controller 2 data exists for this slide. Clear Controller 2 first.', $biid); }
	}
	if ($type === 'c2') {
		$fin = (int)$blScalar("SELECT COALESCE(finalized, 0) FROM blinded_feedback WHERE blinded_id = ?", $biid);
		if ($fin === 1) { mn_deny('Controller 2 and the scores cannot be cleared on a finalized record.', $biid); }
	}
	if ($type === 'reopen') {
		$fin = (int)$blScalar("SELECT COALESCE(finalized, 0) FROM blinded_feedback WHERE blinded_id = ?", $biid);
		if ($fin !== 1) { mn_deny('This report is not finalized, so there is nothing to reopen.', $biid); }
	}
	if ($type === 'rslt') {
		$rowStages = $blScalar("SELECT CONCAT(COALESCE(c1_microscopy,''), COALESCE(c2_microscopy,'')) FROM blinded_results WHERE result_id = ?", $id);
		if ((string)$rowStages !== '') { mn_deny('This slide cannot be deleted because Controller 1 / Controller 2 results exist for it.', $biid); }
	}
	if ($type === 'recom') {
		$act = (string)$blScalar("SELECT COALESCE(blinded_action, '') FROM blinded_feedback WHERE blinded_id = ?", $biid);
		if ($act !== '') { mn_deny('Findings and recommendation cannot be cleared while an Action Taken is recorded. Clear the Action first.', $biid); }
	}

	$okMsg = 'Deleted Successfully!!';   // overridden by the non-delete actions below

	if($type == 'reopen'){
		// Clears the finalized flag ONLY. No row is deleted and no score, finding,
		// recommendation or action is touched — the record becomes a draft again.
		$lnk = "blinded.php?action=2&id=$biid";
		$uptqry = "UPDATE blinded_feedback SET finalized = 0, finalized_at = NULL WHERE blinded_id = '$biid'";
		if($conn->query($uptqry)) {
			$check = true;
			$okMsg = 'Report reopened. It is a draft again and can be edited.';
		}  else $check = false;
	}
	else if($type == 'ac'){
		$lnk = "blinded.php?action=2&id=$biid";
		$uptqry = "UPDATE blinded_feedback SET blinded_action=NULL WHERE blinded_id='$biid'";
		if($conn->query($uptqry)) {
			$check = true;  
		}  else $check = false;
	}
	else if($type == 'recom'){
		$lnk = "blinded.php?action=2&id=$biid";
		$uptqry = "UPDATE blinded_feedback SET blinded_finding=NULL, blinded_recommendation=NULL WHERE blinded_id='$biid'";
		if($conn->query($uptqry)) {
			$check = true;  
		}  else $check = false;
	}
    else if($type == 'c2'){
		$lnk = "blinded.php?action=2&id=$biid";
        $delqry ="DELETE FROM blinded_feedback WHERE blinded_id = $biid";
		$uptqry = "UPDATE blinded_results SET c2_microscopy = NULL, c2_stage  = NULL, c2_parasite_count = NULL, c2_wbc_count = NULL, c2_density = NULL, min_range = NULL, max_range = NULL, positivity_flag = '', species_flag = 0, stages_flag = 0, density_flag = 0 WHERE blinded_id='$biid'";
		if($conn->query($delqry) && $conn->query($uptqry)) {
			$check = true;  
		}  else $check = false;
	}
    else if($type == 'c1'){
		$lnk = "blinded.php?action=2&id=$biid";
		$uptqry = "UPDATE blinded_results SET c1_microscopy = NULL, c1_stage  = NULL, c1_parasite_count = NULL, c1_wbc_count = NULL, c1_density = NULL,  size_thick_smear = NULL, size_thin_smear = NULL, shape_thin_smear = NULL, smear_spacing = NULL, eveness_thin_smear = NULL, blood_film = NULL, smear_intact = NULL, slide_labeling = NULL, background = NULL, stain_blood_element = NULL, cleanliness = NULL, stain_deposit = NULL WHERE result_id='$id'";
		if($conn->query($uptqry)) {
			$check = true;  
		}  else $check = false;
	}
    else if($type == 'rcpt'){
		$lnk = "blinded.php?action=2&id=$biid";
		$uptqry = "UPDATE blinded_results SET slide_status = NULL, remarks = NULL WHERE blinded_id='$biid'";
        $uptqry2 = "UPDATE blinded_informations SET receive_date = NULL WHERE blinded_id='$biid'";
		if($conn->query($uptqry) && $conn->query($uptqry2)) {
			$check = true;  
		}  else $check = false;
	}
    else if($type == 'rslt'){
		$lnk = "blinded.php?action=2&id=$biid";
		$delqry = "DELETE FROM blinded_results WHERE result_id = $id";
		if($conn->query($delqry)) {
			$check = true;  
		}  else $check = false;
	}
    else if($type == 'bi'){
        mn_require_admin('Only an Administrator can delete an entire record.'); // whole-record delete
		$lnk = "blinded-list.php";
		// Whole-record delete: remove the child rows in one transaction as well, so slides,
		// feedback/scores and evidence rows are not left orphaned (no FK cascade exists).
		try {
			$conn->begin_transaction();
			foreach (array(
				"DELETE FROM blinded_results WHERE blinded_id = ?",
				"DELETE FROM blinded_feedback WHERE blinded_id = ?",
				"DELETE FROM blinded_action_images WHERE blinded_id = ?",
				"DELETE FROM blinded_informations WHERE blinded_id = ?"
			) as $sqlDel) {
				$stDel = $conn->prepare($sqlDel);
				$stDel->bind_param('i', $biid);
				$stDel->execute();
				$stDel->close();
			}
			$conn->commit();
			$check = true;
		} catch (\Throwable $e) {
			$conn->rollback();
			$check = false;
		}
	}
    else{	$check = false;	}
	
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

	if($check){
    	$conn->close();		
?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: <?= json_encode($okMsg) ?>,
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
	<?php }	else{ echo $conn -> error;?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Deletion Failed!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>