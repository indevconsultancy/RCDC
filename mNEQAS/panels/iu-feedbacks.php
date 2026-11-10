<?php	
	require_once("../config/session.php");
	// RBAC backend enforcement: only Create/Update roles (Administrator, RCDC Lab,
	// Health Worker) may write here; read-only "Others" are blocked for direct URL,
	// form submit, and AJAX alike. (Delete is gated separately in delete.php.)
	require_once("../config/auth.php"); mn_require_write();
	require_once("../config/db-config.php");
	require_once("../includes/css-jquery.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	/* Authorization before any SQL: own facility's panel and the lab result must belong to it.
	   finding (findings + recommendation) = RCDC. action (action taken) = writer on their own
	   facility's result once a recommendation exists; facility users only after the round CLOSED. */
	$authSubmit = isset($_POST['submit']) ? (string)$_POST['submit'] : '';
	$authId  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
	$authRid = isset($_POST['rid']) ? (int)$_POST['rid'] : 0;
	if ($authSubmit !== 'finding' && $authSubmit !== 'action') { mn_deny('Unknown submission.'); }
	mn_require_record($conn, 'panel', $authId);
	mn_require_child($conn, 'panel_info', $authRid, $authId);
	if ($authSubmit === 'finding') {
		mn_require_rcdc('Only RCDC can enter Findings and Recommendations.');
	} else {
		$rc = $conn->prepare("SELECT COALESCE(recommendation, '') FROM panel_lab_result_informations WHERE information_id = ?");
		$rc->bind_param('i', $authRid);
		$rc->execute();
		$rcRow = $rc->get_result()->fetch_row();
		$rc->close();
		if (!$rcRow || $rcRow[0] === '') { mn_deny('Action cannot be recorded before a Recommendation is entered.', $authRid); }
		if (!mn_is_rcdc()) {
			$authState = mn_panel_state($conn, 'micro', $authId);
			if (!$authState || !$authState['closed']) { mn_deny('Action taken can be recorded after the panel round closes.', $authId); }
		}
	}

	$null = NULL;
	$submit = $conn -> real_escape_string($_POST['submit']);
	$id = $conn -> real_escape_string($_POST['id']);
	$rid = $conn -> real_escape_string($_POST['rid']);
	if ($submit == "finding") {
		$finding = $conn -> real_escape_string($_POST['finding']);
		$recommendation = $conn -> real_escape_string($_POST['recommendation']);
		/* // $actions = $conn -> real_escape_string($_POST['actions']); */

		$uptqry = "UPDATE panel_lab_result_informations SET findings='$finding', recommendation='$recommendation' WHERE information_id='$rid'";

		if($conn->query($uptqry)) $check = true;  
		else $check = false;

		/* Replace this feedback's finding-library links. Every id is checked against
		   the module's own library, so a stale or foreign id is simply ignored. */
		if ($check) {
			/* An absent field means "no instruction", not "none selected". The picker
			   was removed from the feedback screen, so a save no longer posts
			   pfm_finding_ids at all, and clearing on its absence would wipe the stored
			   selections - and with them the findings library's "used by saved feedback"
			   protection. When the field IS posted the set is replaced exactly as before.
			   The loop below then iterates an empty array, which is a no-op. */
			$pfmPosted = isset($_POST['pfm_finding_ids']);
			$pfmIds    = $pfmPosted ? (array)$_POST['pfm_finding_ids'] : array();
			if ($pfmPosted) { $conn->query("DELETE FROM panel_feedback_findings WHERE information_id = ".(int)$rid); }
			$pfmSeen = array(); $pfmPos = 0;
			foreach ($pfmIds as $pfmOne) {
				$pfmOne = (int)$pfmOne;
				if ($pfmOne <= 0 || in_array($pfmOne, $pfmSeen, true)) { continue; }
				$pfmOk = $conn->query("SELECT finding_id FROM panel_finding_master WHERE finding_id = $pfmOne AND module = 'micro'");
				if (!$pfmOk || !$pfmOk->num_rows) { continue; }
				$pfmSeen[] = $pfmOne;
				$conn->query("INSERT IGNORE INTO panel_feedback_findings (information_id, finding_id, sort_order)
				              VALUES (".(int)$rid.", $pfmOne, ".($pfmPos++).")");
			}
		}
	}

	if ($submit == "action") {
		/* // $finding = $conn -> real_escape_string($_POST['finding']);
		// $recommendation = $conn -> real_escape_string($_POST['recommendation']); */
		$actions = $conn -> real_escape_string($_POST['actions']);
		
		$uptqry = "UPDATE panel_lab_result_informations SET action='$actions' WHERE information_id='$rid'";

		if($conn->query($uptqry)) $check = true;  
		else $check = false;
	}

	/* Findings -> the facility must act; Action taken -> RCDC is informed.
	   Raised only after the update succeeded. */
	if ($check && (int)$id > 0) {
		require_once("../notifications/mn-notify.php");
		if ($submit == "finding" && trim(strip_tags($finding)) !== '') {
			mn_notify($conn, 'MICRO_PANEL_FEEDBACK_COMPLETED', (int)$id);
		} else if ($submit == "action" && trim(strip_tags($actions)) !== '') {
			mn_notify($conn, 'MICRO_PANEL_ACTION_COMPLETED', (int)$id);
		}
	}

	$lnk = "feedbacks.php?id=$id&rid=$rid";
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
	
