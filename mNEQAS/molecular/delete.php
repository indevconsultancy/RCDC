<?php
	require_once("../config/session.php"); require_once("../config/auth.php");
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php");

	$type = isset($_GET["type"]) ? (string)$_GET["type"] : '';
	/* The record id arrives as `dbsid` from the wizard and as `id` from
	   molecular-list.php. Only `dbsid` used to be read, so the listing's Delete
	   icon addressed record 0 and could never work - it reported success having
	   deleted nothing until the affected-row check was added, and a plain
	   failure after that. Both spellings are accepted now, `dbsid` first. */
	$dbsid = isset($_GET["dbsid"]) ? (int)$_GET["dbsid"] : 0;
	if ($dbsid <= 0 && isset($_GET["id"])) { $dbsid = (int)$_GET["id"]; }

	/* Optional return target, so a delete fired from inside a workflow step
	   lands back on that step instead of the page the scope defaults to.
	   Whitelisted by exact filename and re-assembled here from an integer id:
	   nothing from the query string reaches the redirect verbatim, so this
	   cannot be turned into an open redirect. */
	$backMap = array(
		'molecular'          => 'molecular.php?action=2&id=',
		'molecular-shipment' => 'molecular-shipment.php?action=2&id=',
	);
	$backName = isset($_GET['back']) ? (string)$_GET['back'] : '';
	$backId   = isset($_GET['backid']) ? (int)$_GET['backid'] : 0;
	$backUrl  = ($backId > 0 && isset($backMap[$backName]))
			? $backMap[$backName] . $backId
			: '';

	/* Authorization before any SQL. gi (sample) = Administrator; pcr (PCR result) and
	   rcdcship (undo receipt) = RCDC; labship (un-batch a shipment) = RCDC, or the owning
	   Health Worker while the shipment has not been received. */
	mn_require_write();
	if (!in_array($type, array('gi', 'pcr', 'labship', 'rcdcship'), true)) { mn_deny('Unknown delete type.'); }
	if ($type === 'gi' || $type === 'pcr') {
		mn_require_record($conn, 'dbs', $dbsid);
	} else {
		mn_require_record($conn, 'dbs_shipment', $dbsid);
	}
	if ($type === 'pcr' || $type === 'rcdcship') {
		mn_require_rcdc('This Molecular stage is restricted to RCDC users.');
	}
	/* A shipment is un-batched by RCDC at any time, or by the owning facility
	   until RCDC has received it. Ownership is already established above by
	   mn_require_record(..., 'dbs_shipment', ...); the check below is the
	   "until received" half. The controls in molecular-shipment-list.php and
	   molecular-shipment-wizard.php are gated to match, so nobody is shown a
	   button that would 403. */
	if ($type === 'labship' && !mn_is_rcdc()) {
		$rc = $conn->prepare("SELECT receive_date FROM dbs_shipment_details WHERE shipment_id = ?");
		$rc->bind_param('i', $dbsid);
		$rc->execute();
		$rcRow = $rc->get_result()->fetch_row();
		$rc->close();
		if ($rcRow && $rcRow[0] !== null) { mn_deny('A received shipment can only be changed by RCDC.', $dbsid); }
	}

	if($type == 'gi'){
		mn_require_admin('Only an Administrator can delete an entire record.'); // whole-record delete
		$lnk = "molecular-list.php";

		/* Administrator-only, and for an Administrator this is unconditional: the
		   whole record goes, whatever stage it had reached, exactly as type=tr does
		   in Malaria RDT QA. Nothing here refuses on account of a later stage,
		   because the cascade below cleans up everything that depended on the
		   sample instead of leaving it dangling.

		   WHAT DEPENDS ON A SAMPLE
		   The PCR result is three columns ON dbs_results (pcr, pcr_date,
		   pcr_remarks), so it leaves with the row. The sample's link to its batch
		   is dbs_results.dbs_shipment_id, likewise. What does NOT look after
		   itself is the batch on the other end of that link:

		     - dbs_shipment_details.total_dbs_shipped is a STORED count, set to
		       count($sampleIds) by iu-molecular.php when the shipment is created,
		       so it has to be put right or the batch claims samples it no longer
		       holds. It is recomputed from the rows that survive rather than
		       decremented, which also corrects a count that had already drifted.

		     - A batch with no samples left is a shipment log for nothing, so it is
		       removed too. Only when THIS delete emptied it; a batch that still
		       holds other samples is left alone with its count corrected.

		   All of it in one transaction, so a failure part-way leaves the record
		   whole rather than half-deleted. */
		$sh = $conn->prepare("SELECT dbs_shipment_id FROM dbs_results WHERE dbs_id = ?");
		$sh->bind_param('i', $dbsid);
		$sh->execute();
		$shRow = $sh->get_result()->fetch_row();
		$sh->close();
		$shipId = ($shRow && $shRow[0] !== null) ? (int)$shRow[0] : 0;

		$conn->begin_transaction();
		try {
			$st = $conn->prepare("DELETE FROM dbs_results WHERE dbs_id = ?");
			$st->bind_param('i', $dbsid);
			$st->execute();
			$gone = $st->affected_rows;
			$st->close();

			if ($gone < 1) { throw new Exception('No such sample.'); }

			if ($shipId > 0) {
				/* how many samples the batch still holds */
				$cnt = $conn->prepare("SELECT COUNT(*) FROM dbs_results WHERE dbs_shipment_id = ?");
				$cnt->bind_param('i', $shipId);
				$cnt->execute();
				$cntRow = $cnt->get_result()->fetch_row();
				$cnt->close();
				$left = $cntRow ? (int)$cntRow[0] : 0;

				if ($left === 0) {
					/* this delete emptied the batch, so the shipment log goes with it */
					$st = $conn->prepare("DELETE FROM dbs_shipment_details WHERE shipment_id = ?");
					$st->bind_param('i', $shipId);
					$st->execute();
					$st->close();
				} else {
					/* still in use: just make the stored count tell the truth */
					$st = $conn->prepare("UPDATE dbs_shipment_details SET total_dbs_shipped = ?
												 WHERE shipment_id = ?");
					$st->bind_param('ii', $left, $shipId);
					$st->execute();
					$st->close();
				}
			}

			$conn->commit();
			$check = true;
		} catch (Throwable $e) {
			$conn->rollback();
			$check = false;
		}
	}
	else if($type == 'pcr'){
		$lnk = ($backUrl !== '') ? $backUrl : "molecular.php?action=2&id=$dbsid";
		$delqry = "UPDATE dbs_results SET pcr = NULL, pcr_date = NULL, pcr_remarks = NULL WHERE dbs_id='$dbsid'";
		if($conn->query($delqry)) {
		$check = true;  
		}  else $check = false;
	}
	else if($type == 'labship'){
		$lnk = ($backUrl !== '') ? $backUrl : "molecular-shipment-list.php";
		$delqry = "UPDATE dbs_results SET dbs_shipment_id = NULL WHERE dbs_shipment_id=$dbsid";
		$delqry2 = "DELETE FROM dbs_shipment_details WHERE shipment_id=$dbsid";
		if($conn->query($delqry) && $conn->query($delqry2)) {
		$check = true;  
		}  else $check = false;
	}
	else if($type == 'rcdcship'){
		/* Undo the receipt for a shipment. PCR comes after receipt, so if any
		   sample in the batch already carries a PCR result, undoing the receipt
		   would leave those results sitting on a batch that was never received.
		   Refused, naming the step to clear first - the same dependency rule the
		   other modules use. */
		$pc = $conn->prepare("SELECT COUNT(*) FROM dbs_results
							 WHERE dbs_shipment_id = ?
							   AND pcr IS NOT NULL AND pcr <> ''");
		$pc->bind_param('i', $dbsid);
		$pc->execute();
		$pcRow = $pc->get_result()->fetch_row();
		$pc->close();
		if ($pcRow && (int)$pcRow[0] > 0) {
			mn_deny('The receipt cannot be undone while PCR results exist for this shipment. '
				. 'Clear the PCR results first.', $dbsid);
		}

		$lnk = ($backUrl !== '') ? $backUrl : "molecular-shipment.php?action=2&id=$dbsid";
		$st = $conn->prepare("UPDATE dbs_shipment_details
							   SET total_dbs_received = NULL, received_by = NULL, receive_date = NULL
							 WHERE shipment_id = ?");
		$st->bind_param('i', $dbsid);
		$check = $st->execute();
		$st->close();
	}
    else{
        $check = false;
    }
	
	$okMsgs = array(
		'gi'       => 'Record deleted successfully',
		'pcr'      => 'PCR result deleted successfully',
		'rcdcship' => 'Receipt deleted successfully',
		'labship'  => 'Shipment deleted successfully',
	);
	$okMsg = isset($okMsgs[$type]) ? $okMsgs[$type] : 'Deleted Successfully!!';

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
				text: '<?=$okMsg;?>',
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