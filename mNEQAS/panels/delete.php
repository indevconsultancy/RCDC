<?php
	require_once("../config/session.php");
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php");
	require_once("../config/auth.php");
	date_default_timezone_set('Asia/Thimphu');

	// Read-only "Others" cannot delete/undo here — matches the UI, which only shows
	// these controls to Create/Update roles (Administrator, RCDC Lab, Health Worker).
	// (Workflow undo/clear stays available to those roles per the approved RBAC.)
	mn_require_write('You have read-only access and cannot delete panel results.');

	// Server-side mirror of the UI "CLOSED" gate in panels.php: the reporting
	// deadline (panel round closing_date) has passed when today > closing_date.
	// Uses a prepared lookup and DATE()/CURDATE() so DATETIME columns and
	// time-of-day components compare as pure YYYY-MM-DD.
	if (!function_exists('mn_panel_closed')) {
		function mn_panel_closed($conn, $panel_id) {
			$pid = (int)$panel_id;
			$stmt = $conn->prepare("SELECT DATE(t2.closing_date), CURDATE() FROM panel_informations t1 INNER JOIN panel_round_informations t2 ON t1.round_id = t2.round_id WHERE t1.panel_id = ? LIMIT 1");
			if (!$stmt) { return false; }
			$stmt->bind_param('i', $pid);
			$stmt->execute();
			$r = $stmt->get_result()->fetch_row();
			$stmt->close();
			if (!$r || $r[0] === null || $r[0] === '') { return false; }
			return $r[1] > $r[0];
		}
	}

	$cdt  = date('Y-m-d h:i:s', time());
	// Int-cast at intake — every DELETE/UPDATE below binds via placeholders,
	// so no user-controlled string ever enters the SQL text directly.
	$id   = isset($_GET["id"])  ? (int)$_GET["id"]  : 0;
	$rid  = isset($_GET["rid"]) ? (int)$_GET["rid"] : 0;
	$type = isset($_GET["type"]) ? (string)$_GET["type"] : '';

	$check = false;
	$lnk   = "panels.php?id=$id";

	/* Authorization before any SQL: own facility's panel; secondary ids must belong to it.
	   rcdc (true answer) and fe (findings/recommendation) = RCDC. lab / action / rdate =
	   writer on own facility; facility users cannot delete lab results after the round has
	   CLOSED or once RCDC findings exist, nor undo receipt when closed or lab results exist. */
	if (!in_array($type, array('rcdc', 'lab', 'fe', 'action', 'rdate', 'pi'), true)) { mn_deny('Unknown delete type.'); }
	mn_require_record($conn, 'panel', $id);
	if ($type === 'rcdc' || $type === 'fe') { mn_require_rcdc('This action is restricted to RCDC users.'); }
	if ($type === 'rcdc') { mn_require_child($conn, 'panel_slide', $rid, $id); }
	if ($type === 'lab' || $type === 'fe' || $type === 'action') { mn_require_child($conn, 'panel_info', $rid, $id); }
	if (!mn_is_rcdc()) {
		$authState = mn_panel_state($conn, 'micro', $id);
		if (($type === 'lab' || $type === 'rdate') && (!$authState || $authState['closed'])) {
			mn_deny('The reporting deadline has passed; this can no longer be changed.', $id);
		}
		if ($type === 'lab') {
			$fd = $conn->prepare("SELECT COALESCE(findings, '') FROM panel_lab_result_informations WHERE information_id = ?");
			$fd->bind_param('i', $rid);
			$fd->execute();
			$fdRow = $fd->get_result()->fetch_row();
			$fd->close();
			if ($fdRow && $fdRow[0] !== '') { mn_deny('Lab results are locked once RCDC findings have been recorded.', $id); }
		}
		if ($type === 'rdate') {
			$lr = $conn->prepare("SELECT COUNT(*) FROM panel_lab_result_informations WHERE panel_id = ?");
			$lr->bind_param('i', $id);
			$lr->execute();
			$lrCount = (int)$lr->get_result()->fetch_row()[0];
			$lr->close();
			if ($lrCount > 0) { mn_deny('Receipt cannot be undone after lab results have been entered.', $id); }
		}
	}

	if($type == 'rcdc'){
		$lnk = "panels.php?id=$id";
		$stmt = $conn->prepare("DELETE FROM panel_rcdc_results WHERE slide_id = ?");
		$stmt->bind_param('i', $rid);
		$check = $stmt->execute();
		$stmt->close();
	}
	else if($type == 'lab'){
		// Participants blocked after reporting deadline; RCDC (Administrator +
		// RCDC Lab, roleID 1|2) retain administrative override to delete for
		// corrective purposes, mirroring the write-side override in iu-results.php.
		if (mn_panel_closed($conn, $id) && !mn_is_rcdc()) {
			mn_deny('The reporting deadline has passed; lab results can no longer be deleted.');
		}
		$lnk = "panels.php?id=$id";
		$stmt1 = $conn->prepare("DELETE FROM panel_lab_results WHERE information_id = ?");
		$stmt1->bind_param('i', $rid);
		$ok1 = $stmt1->execute();
		$stmt1->close();
		$stmt2 = $conn->prepare("DELETE FROM panel_lab_result_informations WHERE information_id = ?");
		$stmt2->bind_param('i', $rid);
		$ok2 = $stmt2->execute();
		$stmt2->close();
		$check = ($ok1 && $ok2);
	}
	else if($type == 'fe'){
		$lnk = "feedbacks.php?id=$id&rid=$rid";
		$stmt = $conn->prepare("UPDATE panel_lab_result_informations SET findings=NULL, recommendation=NULL, action=NULL WHERE information_id = ?");
		$stmt->bind_param('i', $rid);
		$check = $stmt->execute();
		$stmt->close();
	}
	else if($type == 'action'){
		$lnk = "feedbacks.php?id=$id&rid=$rid";
		$stmt = $conn->prepare("UPDATE panel_lab_result_informations SET action=NULL WHERE information_id = ?");
		$stmt->bind_param('i', $rid);
		$check = $stmt->execute();
		$stmt->close();
	}
	else if($type == 'rdate'){
		$lnk = "panels.php?id=$id";
		$stmt1 = $conn->prepare("UPDATE panel_informations SET receive_date=NULL WHERE panel_id = ?");
		$stmt1->bind_param('i', $id);
		$ok1 = $stmt1->execute();
		$stmt1->close();
		$stmt2 = $conn->prepare("UPDATE panel_slide_informations SET slide_status='', slide_remarks='' WHERE panel_id = ?");
		$stmt2->bind_param('i', $id);
		$ok2 = $stmt2->execute();
		$stmt2->close();
		$check = ($ok1 && $ok2);
	}
	/* ---- pi: delete the whole panel record, children first ----
	   Administrator only. Every child table is removed in dependency order
	   inside one transaction, so the record either goes completely or not at
	   all. Order matters: every foreign key here is RESTRICT, which means a
	   wrong order fails loudly instead of orphaning rows.

	   The guard below exists because of a schema fault:
	   rdt_panel_lab_result_informations.panel_id has a foreign key to
	   panel_informations (the MICROSCOPY table) rather than to
	   rdt_panel_informations. Panel ids are allocated independently in the two
	   modules, so an RDT lab-result row can collide with an unrelated micro
	   panel id and block its deletion with a raw SQL error. Rather than let
	   that surface, the collision is detected and explained. */
	else if($type == 'pi'){
		mn_require_admin('Only an Administrator can delete an entire panel record.');
		$lnk = "panel-list.php";

		$blk = $conn->prepare("SELECT COUNT(*) FROM rdt_panel_lab_result_informations WHERE panel_id = ?");
		$blk->bind_param('i', $id);
		$blk->execute();
		$blkRow = $blk->get_result()->fetch_row();
		$blk->close();
		if ($blkRow && (int)$blkRow[0] > 0) {
			mn_deny('This panel cannot be deleted: RDT Panel Testing lab results share its record id through a '
			      . 'mis-targeted foreign key (rdt_panel_lab_result_informations points at panel_informations). '
			      . 'That constraint needs correcting before this record can be removed.', $id);
		}

		$conn->begin_transaction();
		try {
			$st = $conn->prepare("DELETE FROM panel_feedback_findings
			                       WHERE information_id IN (SELECT information_id FROM panel_lab_result_informations WHERE panel_id = ?)");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM panel_lab_results
			                       WHERE information_id IN (SELECT information_id FROM panel_lab_result_informations WHERE panel_id = ?)");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM panel_lab_results
			                       WHERE slide_id IN (SELECT slide_id FROM panel_slide_informations WHERE panel_id = ?)");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM panel_rcdc_results
			                       WHERE slide_id IN (SELECT slide_id FROM panel_slide_informations WHERE panel_id = ?)");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM panel_lab_result_informations WHERE panel_id = ?");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM panel_slide_informations WHERE panel_id = ?");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$st = $conn->prepare("DELETE FROM panel_informations WHERE panel_id = ?");
			$st->bind_param('i', $id); $st->execute(); $st->close();

			$conn->commit();
			$check = true;
		} catch (\Throwable $e) {
			$conn->rollback();
			error_log('[mNEQAS] panel pi delete: ' . $e->getMessage());
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