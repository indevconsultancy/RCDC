<?php
	require_once("../config/session.php");
	require_once("../config/db-config.php");
	require_once("../includes/css-jquery.php");
	require_once("../config/auth.php");
	// RBAC backend enforcement: read-only "Others" cannot delete/undo here — matches
	// the UI, which shows these controls only to Create/Update roles (Administrator,
	// RCDC Lab, Health Worker). Workflow undo/clear stays available to those roles.
	mn_require_write('You have read-only access and cannot delete RDT panel results.');
	date_default_timezone_set('Asia/Thimphu');

	// Int-cast at intake — every interpolated query below is now injection-safe.
	$cdt  = date('Y-m-d h:i:s', time());
	$id   = isset($_GET["id"])  ? (int)$_GET["id"]  : 0;
	$type = isset($_GET["type"]) ? (string)$_GET["type"] : '';
	$rid  = isset($_GET["rid"]) ? (int)$_GET["rid"] : 0;

	// F4: facility-ownership guard — non-RCDC users can only mutate their own
	// facility's panels. RCDC/Admin bypass; runs before any DELETE/UPDATE.
	mn_require_rdt_panel_ownership($conn, $id, 'page');

	/* Stage ownership: rcdc (true answer) and fe (findings/recommendation) = RCDC, and the
	   slide must belong to this panel. Facility users: no lab-result delete after CLOSED or
	   once RCDC findings exist; no receipt undo after CLOSED or once lab results exist. */
	if (!in_array($type, array('rcdc', 'lab', 'fe', 'action', 'rdate', 'pi'), true)) { mn_deny('Unknown delete type.'); }
	if ($type === 'rcdc' || $type === 'fe') { mn_require_rcdc('This action is restricted to RCDC users.'); }
	if ($type === 'rcdc') { mn_require_child($conn, 'rdt_panel_slide', $rid, $id); }
	if (!mn_is_rcdc() && ($type === 'lab' || $type === 'rdate')) {
		$authState = mn_panel_state($conn, 'rdt', $id);
		if (!$authState || $authState['closed']) { mn_deny('The round is closed; this can no longer be changed.', $id); }
		$lk = $conn->prepare("SELECT COUNT(*), COALESCE(MAX(findings), '') FROM rdt_panel_lab_result_informations WHERE panel_id = ?");
		$lk->bind_param('i', $id);
		$lk->execute();
		$lkRow = $lk->get_result()->fetch_row();
		$lk->close();
		if ($type === 'lab' && $lkRow[1] !== '') { mn_deny('Lab results are locked once RCDC findings have been recorded.', $id); }
		if ($type === 'rdate' && (int)$lkRow[0] > 0) { mn_deny('Receipt cannot be undone after lab results have been entered.', $id); }
	}

	if($type == 'rcdc'){
		$lnk = "panels.php?id=$id";
		// Prepared statement — slide_id is user-supplied via GET.
		$stmt = $conn->prepare("DELETE FROM rdt_panel_rcdc_results WHERE slide_id = ?");
		$stmt->bind_param('i', $rid);
		$check = $stmt->execute();
		$stmt->close();
	}
	else if($type == 'lab'){
		$lnk = "panels.php?id=$id";
		$stmt1 = $conn->prepare("DELETE FROM rdt_panel_lab_results WHERE slide_id IN (SELECT slide_id FROM rdt_panel_slide_informations WHERE panel_id = ?)");
		$stmt1->bind_param('i', $id);
		$ok1 = $stmt1->execute();
		$stmt1->close();
		$stmt2 = $conn->prepare("DELETE FROM rdt_panel_lab_result_informations WHERE panel_id = ?");
		$stmt2->bind_param('i', $id);
		$ok2 = $stmt2->execute();
		$stmt2->close();
		$check = ($ok1 && $ok2);
	}
	else if($type == 'fe'){
		$lnk = "feedbacks.php?id=$id";
		$stmt = $conn->prepare("UPDATE rdt_panel_lab_result_informations SET findings=NULL, recommendation=NULL, action=NULL WHERE panel_id = ?");
		$stmt->bind_param('i', $id);
		$check = $stmt->execute();
		$stmt->close();
	}
	else if($type == 'action'){
		$lnk = "feedbacks.php?id=$id";
		$stmt = $conn->prepare("UPDATE rdt_panel_lab_result_informations SET action=NULL WHERE panel_id = ?");
		$stmt->bind_param('i', $id);
		$check = $stmt->execute();
		$stmt->close();
	}
	else if($type == 'rdate'){
		$lnk = "panels.php?id=$id";
		$stmt1 = $conn->prepare("UPDATE rdt_panel_informations SET receive_date=NULL WHERE panel_id = ?");
		$stmt1->bind_param('i', $id);
		$ok1 = $stmt1->execute();
		$stmt1->close();
		$stmt2 = $conn->prepare("UPDATE rdt_panel_slide_informations SET slide_status='', slide_remarks='' WHERE panel_id = ?");
		$stmt2->bind_param('i', $id);
		$ok2 = $stmt2->execute();
		$stmt2->close();
		$check = ($ok1 && $ok2);
	}
	/* ---- pi: delete the whole panel record, children first ----
	   Administrator only. Every child table is removed in dependency order
	   inside one transaction, so the record either goes completely or not at
	   all. Order matters: the foreign keys here are RESTRICT, so a wrong order
	   fails loudly instead of orphaning rows.

	   rdt_panel_lab_result_informations is removed explicitly rather than left
	   to a constraint: its foreign key points at panel_informations (the
	   MICROSCOPY table) instead of rdt_panel_informations, so it is not
	   protected by this record at all. */
	else if($type == 'pi'){
		mn_require_admin('Only an Administrator can delete an entire panel record.');
		$lnk = "panel-list.php";

		$conn->begin_transaction();
		try {
			$st = $conn->prepare("DELETE FROM rdt_panel_feedback_findings WHERE panel_id = ?");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM rdt_panel_lab_results
			                       WHERE slide_id IN (SELECT slide_id FROM rdt_panel_slide_informations WHERE panel_id = ?)");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM rdt_panel_rcdc_results
			                       WHERE slide_id IN (SELECT slide_id FROM rdt_panel_slide_informations WHERE panel_id = ?)");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM rdt_panel_lab_result_informations WHERE panel_id = ?");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM rdt_panel_slide_informations WHERE panel_id = ?");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM rdt_panel_informations WHERE panel_id = ?");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$conn->commit();
			$check = true;
		} catch (\Throwable $e) {
			$conn->rollback();
			error_log('[mNEQAS] rdt panel pi delete: ' . $e->getMessage());
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
				text: 'Deleted Successfully!!',
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