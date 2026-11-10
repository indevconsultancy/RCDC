<?php
	require_once("../config/session.php"); require_once("../config/auth.php");
	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php");

	$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
	$type = isset($_GET["type"]) ? (string)$_GET["type"] : '';
    $trid = isset($_GET["trid"]) ? (int)$_GET["trid"] : 0;
	/* For a whole-record delete the request id may arrive as either
	   parameter: the wizard sends both, the listing sends `id` alone.
	   Resolved here, ahead of the ownership guard, so the guard checks the
	   record actually targeted. Never applied to the child types, where
	   `id` is a child row and must not be read as a request. */
	if ($type === 'tr' && $trid <= 0) { $trid = $id; }

	/* Authorization before any SQL: write role + record ownership + stage owner.
	   tr = Administrator; uship (undo receipt) / rac (assessment) / rpr (performance) = RCDC;
	   prod / ship = RCDC, or the owning Health Worker before RCDC receipt. Child ids must
	   belong to the request. */
	mn_require_write();
	if (!in_array($type, array('tr', 'prod', 'ship', 'uship', 'rac', 'rpr'), true)) { mn_deny('Unknown delete type.'); }
	mn_require_record($conn, 'rdt_request', $trid);
	if (in_array($type, array('uship', 'rac', 'rpr'), true)) {
		mn_require_rcdc('This RDT QA stage is restricted to RCDC users.');
	}
	if ($type === 'prod' || $type === 'rac') { mn_require_child($conn, 'rdt_product', $id, $trid); }
	if ($type === 'rpr') { mn_require_child($conn, 'rdt_performance', $id, $trid); }
	if (($type === 'prod' || $type === 'ship') && !mn_is_rcdc()) {
		$rc = $conn->prepare("SELECT date_receive FROM rdt_test_request WHERE rdt_request_id = ?");
		$rc->bind_param('i', $trid);
		$rc->execute();
		$rcRow = $rc->get_result()->fetch_row();
		$rc->close();
		if ($rcRow && $rcRow[0] !== null) { mn_deny('The request is locked once RCDC has received the shipment.', $trid); }
	}

	if($type == 'tr'){
		mn_require_admin('Only an Administrator can delete an entire record.'); // whole-record delete
		$lnk = "rdt-list.php";

		/* Purge the request and everything hanging off it. Every foreign key in
		   this family is ON DELETE RESTRICT, so the children have to go first and
		   in order, inside one transaction - a failure part-way then leaves the
		   record whole rather than half-deleted.

		   What this replaces: the old branch refused outright as soon as the
		   request had a product or a shipment, which is every request anyone has
		   worked on, and on the paths where it did run it reported success even
		   when the id matched no row at all. */
		$exists = $conn->prepare("SELECT 1 FROM rdt_test_request WHERE rdt_request_id = ?");
		$exists->bind_param('i', $trid);
		$exists->execute();
		$found = (bool)$exists->get_result()->fetch_row();
		$exists->close();

		if (!$found) {
			$check = false;          // nothing there: report failure, never success
		} else {
			$conn->begin_transaction();
			try {
				/* deepest first; each is keyed on the request through its products */
				$cascade = array(
					"DELETE r FROM rdt_performance_report r INNER JOIN rdt_product_details p ON p.product_id = r.manufacturer_id WHERE p.test_request_id = ?",
					"DELETE r FROM rdt_assessment_report  r INNER JOIN rdt_product_details p ON p.product_id = r.manufacturer_id WHERE p.test_request_id = ?",
					"DELETE s FROM rdt_shipment_details   s INNER JOIN rdt_product_details p ON p.product_id = s.product_details_id WHERE p.test_request_id = ?",
					"DELETE FROM rdt_product_details WHERE test_request_id = ?",
					"DELETE FROM rdt_test_request    WHERE rdt_request_id = ?",
				);
				foreach ($cascade as $sql) {
					$st = $conn->prepare($sql);
					$st->bind_param('i', $trid);
					$st->execute();
					$st->close();
				}
				$conn->commit();
				$check = true;
			} catch (Throwable $e) {   // mysqli throws by default on PHP 8.1+; an Error if that is ever turned off
				$conn->rollback();
				$check = false;
			}
		}
	}
	
	else if($type == 'prod'){
		/* One product and everything recorded against it. The product is the
		   parent of three tables and all three foreign keys are ON DELETE
		   RESTRICT, so the children have to go first.

		   What this replaces: the old branch deleted only the performance report
		   and then the product, so any product that had an accessory assessment
		   or a shipment row - every product past step 3 - failed with FK error
		   1451. Worse, the two statements ran unguarded, so the performance
		   report was already gone by the time the product delete failed, leaving
		   the row behind with its results destroyed. One transaction now, so it
		   either all goes or nothing does.

		   The stage guards above still apply: a non-RCDC user cannot get here
		   once RCDC has received the shipment, and mn_require_child has already
		   confirmed the product belongs to this request. */
		$lnk = "rdt-registration.php?action=2&id=$trid";
		$conn->begin_transaction();
		try {
			$cascade = array(
				"DELETE FROM rdt_assessment_report  WHERE manufacturer_id = ?",
				"DELETE FROM rdt_shipment_details   WHERE product_details_id = ?",
				"DELETE FROM rdt_performance_report WHERE manufacturer_id = ?",
				"DELETE FROM rdt_product_details    WHERE product_id = ?",
			);
			foreach ($cascade as $sql) {
				$st = $conn->prepare($sql);
				$st->bind_param('i', $id);
				$st->execute();
				$st->close();
			}
			$conn->commit();
			$check = true;
		} catch (Throwable $e) {
			$conn->rollback();
			$check = false;
		}
	}

    else if($type == 'ship'){
        //disable if the product is shipped or product report entered.
		//delete records for all product id for the trID and update null on tr ship date
		$productqry = $conn->query("SELECT product_id FROM rdt_product_details WHERE test_request_id=".$trid);
		while (list($pid) = $productqry->fetch_row()) {
			$delqry = "DELETE FROM rdt_shipment_details WHERE product_details_id='$pid'";
			if($conn->query($delqry)) {
			$check = true;  
			}  else $check = false;
		}
		$updateqry = "UPDATE rdt_test_request SET date_shipment = NULL WHERE rdt_request_id = $trid";
		if($conn->query($updateqry)) {
			$check = true;  
			}  else $check = false;
		$lnk = "rdt-registration.php?action=2&id=$trid";
		
	}

    else if($type == 'uship'){
        //disable if the product is shipped or product report entered.
		$lnk = "rdt-registration.php?action=2&id=$trid";
		$productqry = $conn->query("SELECT product_id FROM rdt_product_details WHERE test_request_id=".$trid);
		while (list($pid) = $productqry->fetch_row()) {
			$delqry = "UPDATE rdt_shipment_details SET received_no_box = NULL, received_test_per_box = NULL WHERE product_details_id='$pid'";
			if($conn->query($delqry)) {
			$check = true;  
			}  else $check = false;
		}
		$updateqry = "UPDATE rdt_test_request SET date_receive = NULL, received_by = NULL, designation = NULL  WHERE rdt_request_id = $trid";
		if($conn->query($updateqry)) {
		$check = true;  
		}  else $check = false;
	}

    else if($type == 'rac'){
        //disable if the product is shipped or product report entered.
		$lnk = "rdt-registration.php?action=2&id=$trid";
		$delqry = "DELETE FROM rdt_assessment_report WHERE manufacturer_id='$id'";
		if($conn->query($delqry)) {
		$check = true;  
		}  else $check = false;
	}

    else if($type == 'rpr'){
        //disable if the product is shipped or product report entered.
		$lnk = "rdt-registration.php?action=2&id=$trid";
		$delqry = "UPDATE rdt_performance_report SET pf_pd_200_temp = NULL, pf_pds_200 = NULL, pf_pd_2000_temp = NULL, pf_pds_2000 = NULL, pf_ivr = NULL, pv_pd_200_temp = NULL, pv_pds_200 = NULL, pv_pd_2000_temp = NULL, pv_pds_2000 = NULL, pv_ivr = NULL, ng_temperature = NULL, total_tests = NULL, true_negative = NULL, false_positive = NULL, tn_rate = NULL, fp_rate = NULL, test_by = NULL, designation = NULL, test_date = NULL, performance_result = NULL WHERE performance_id='$id'";
		if($conn->query($delqry)) {
		$check = true;  
		}  else $check = false;
	}

    else{
        $check = false;
    }
	
	$okMsg = ($type === 'tr') ? 'Record deleted successfully' : 'Deleted Successfully!!';

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