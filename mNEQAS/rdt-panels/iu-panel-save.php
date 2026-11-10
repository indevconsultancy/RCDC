<?php
	/**
	 * Unified RDT Panel save controller.
	 *
	 * Replaces the three separate submits (iu-panels.php, iu-results.php R/L) with a
	 * SINGLE transactional request. The merged #panelForm posts a per-section flag
	 * (rcdc_submit / info_submit / lab_submit) exactly mirroring the gates that used
	 * to control each section's individual Submit button, so only the sections that
	 * were actually editable for this role/state are written. Every SQL statement,
	 * calculation and redirect is identical to the original controllers - only the
	 * wrapping changes (one BEGIN ... COMMIT/ROLLBACK).
	 */
	require_once("../config/session.php");
	// RBAC backend enforcement: only Create/Update roles (Administrator, RCDC Lab,
	// Health Worker) may write here; read-only "Others" are blocked for direct URL,
	// form submit, and AJAX alike. (Delete is gated separately in delete.php.)
	require_once("../config/auth.php"); mn_require_write();
	require_once("../config/db-config.php");
	require_once("../includes/css-jquery.php");
	require_once("rdt-panel-functions.php");
	date_default_timezone_set('Asia/Thimphu');

	$null = NULL;
	// Cast panel_id to int at intake — protects every interpolated query below.
	$id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
	$lnk  = "panels.php?id=$id";

	// F1/F2: facility ownership guard — non-RCDC users can only write to panels
	// belonging to their own facility. Runs before any DB write.
	mn_require_rdt_panel_ownership($conn, $id, 'page');

	// Snapshot round.closing_date + panel.receive_date + lab findings for the
	// server-side workflow embargoes below. Cast closing_date/receive_date via
	// DATE() so DATETIME columns and stray time-of-day values still compare
	// cleanly as pure YYYY-MM-DD, and use CURDATE() so the "today" side of the
	// comparison comes from the same server clock that stored the row (avoids
	// PHP-vs-MySQL timezone drift at midnight).
	$stateStmt = $conn->prepare("
		SELECT DATE(r.closing_date) AS closing_ymd,
		       DATE(p.receive_date) AS receive_ymd,
		       COALESCE((SELECT findings FROM rdt_panel_lab_result_informations WHERE panel_id = p.panel_id LIMIT 1), '') AS findings,
		       CURDATE() AS today_ymd
		FROM rdt_panel_informations p
		INNER JOIN rdt_panel_round_informations r ON r.round_id = p.round_id
		WHERE p.panel_id = ? LIMIT 1
	");
	$stateStmt->bind_param('i', $id);
	$stateStmt->execute();
	$state = $stateStmt->get_result()->fetch_row();
	$stateStmt->close();
	$closing_ymd  = $state[0] ?? '';
	$receive_ymd  = $state[1] ?? '';
	$has_findings = (isset($state[2]) && $state[2] !== '');
	$today_ymd    = $state[3] ?? date('Y-m-d');
	// String compare of two normalised YYYY-MM-DD values is safe and total.
	$round_is_closed = ($closing_ymd !== '' && $closing_ymd !== null && $today_ymd > $closing_ymd);
	$has_receipt     = ($receive_ymd !== '' && $receive_ymd !== null);
	// RCDC (Administrator + RCDC Lab, roleID 1|2) may override every workflow
	// embargo below to correct genuine data-entry mistakes after the fact.
	// Participants (roleID 3|4|…) remain strictly gated.
	$can_override    = mn_is_rcdc();

	/* ---- Section authorization before any SQL write ---------------------------
	   Every slide id must belong to this panel. RCDC answers = RCDC. Receipt by a
	   facility user: not after CLOSED and not once lab results exist. Lab results by
	   a facility user: receipt recorded and RCDC answers present; timeliness is
	   computed server-side (ONTIME/LATE) instead of trusting the hidden form value. */
	if (isset($_POST['rcdc_submit'])) {
		if (!mn_is_rcdc()) { mn_deny('You are not authorised to submit RCDC answers.'); }
		mn_require_child($conn, 'rdt_panel_slide', isset($_POST['rcdc_rid']) ? (int)$_POST['rcdc_rid'] : 0, $id);
	}
	foreach (array('info_slide', 'lab_slide') as $authKey) {
		if (isset($_POST[$authKey]) && is_array($_POST[$authKey])) {
			foreach ($_POST[$authKey] as $authK => $authSlide) {
				mn_require_child($conn, 'rdt_panel_slide', (int)$authSlide, $id);
				$_POST[$authKey][$authK] = (string)(int)$authSlide;   // normalised integer id
			}
		}
	}
	if (!$can_override) {
		if (isset($_POST['info_submit'])) {
			if ($round_is_closed) { mn_deny('The round is closed; receipt can no longer be changed.', $id); }
			$lr = $conn->prepare("SELECT COUNT(*) FROM rdt_panel_lab_result_informations WHERE panel_id = ?");
			$lr->bind_param('i', $id);
			$lr->execute();
			$lrCount = (int)$lr->get_result()->fetch_row()[0];
			$lr->close();
			if ($lrCount > 0) { mn_deny('Receipt cannot be changed after lab results have been entered.', $id); }
		}
		if (isset($_POST['lab_submit'])) {
			// A receipt posted in this same request counts as recorded.
			if (!$has_receipt && !(isset($_POST['info_submit']) && trim((string)($_POST['dor'] ?? '')) !== '')) {
				mn_deny('Record the panel receipt before entering lab results.', $id);
			}
			$ra = $conn->prepare("SELECT COUNT(*) FROM rdt_panel_rcdc_results r INNER JOIN rdt_panel_slide_informations s ON s.slide_id = r.slide_id WHERE s.panel_id = ?");
			$ra->bind_param('i', $id);
			$ra->execute();
			$raCount = (int)$ra->get_result()->fetch_row()[0];
			$ra->close();
			if ($raCount === 0) { mn_deny('Lab results can be entered once RCDC has set up the panel answers.', $id); }
			$authState = mn_panel_state($conn, 'rdt', $id);
			if ($authState && $authState['flag'] !== 'OPEN') { $_POST['timeliness'] = $authState['flag']; }
		}
	}

	$conn->begin_transaction();
	$check = false;
	$denyMsg = '';
	try {
	$ok = true;

	/* ---------------- 1. RCDC Result (was iu-results.php type=R) ---------------- */
if ($ok && isset($_POST['rcdc_submit'])) {
    // RCDC "true answers" are RCDC/Admin only — same rule as iu-results.php
    if (!mn_is_rcdc()) {
        mn_deny('You are not authorised to submit RCDC answers.');
    }
    // F5: RCDC reference answers lock once the facility has recorded receipt —
    // otherwise the reference key could be changed under a facility that has
    // already been scored against it. Admin/RCDC override applies here because
    // only RCDC/Admin reach this branch anyway (the outer mn_is_rcdc guard),
    // so this is effectively an audit note: Administrator retains write access.
    if ($has_receipt && !$can_override) {
        throw new Exception('RCDC reference answers are locked once the facility has recorded receipt.');
    }

    $rid     = (int)$_POST['rcdc_rid'];
    $species = $conn -> real_escape_string($_POST['rcdc_species']);

    $chkResult = ($conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results WHERE slide_id='$rid'"));
    if(!$chkResult){ $ok = false; }
    else{
        $chkResult = $chkResult -> fetch_row();
        if($chkResult[0] == 0){
            $stmt = $conn->prepare("INSERT INTO rdt_panel_rcdc_results VALUES (?, ?)");
            if(!$stmt){ $ok = false; }
            else{
                $stmt->bind_param("is", $rid, $species);
                if(!$stmt->execute()) $ok = false;
                $stmt->close();
            }
        }
        else{
            if(!$conn->query("UPDATE rdt_panel_rcdc_results SET rcdc_species='$species' WHERE slide_id='$rid'")) $ok = false;
        }
    }
}

	/* ---------------- 2. Panel Information receipt (was iu-panels.php) ---------------- */
	if ($ok && isset($_POST['info_submit'])) {
		$dor     = $conn -> real_escape_string($_POST['dor'] ?? '');
		$slide   = (isset($_POST['info_slide']) && is_array($_POST['info_slide'])) ? $_POST['info_slide'] : array();
		$status  = (isset($_POST['status'])     && is_array($_POST['status']))     ? $_POST['status']     : array();
		$remarks = (isset($_POST['remarks'])    && is_array($_POST['remarks']))    ? $_POST['remarks']    : array();

		if(!$conn->query("UPDATE rdt_panel_informations SET receive_date='$dor' WHERE panel_id='$id'")) $ok = false;
		if($ok){
			for($i = 0; $i < count($slide); $i++){
				$s  = $conn -> real_escape_string($slide[$i]);
				$st = $conn -> real_escape_string($status[$i]);
				$rm = $conn -> real_escape_string($remarks[$i]);
				if(!$conn->query("UPDATE rdt_panel_slide_informations SET slide_status='$st', slide_remarks='$rm' WHERE slide_id='$s'")){ $ok = false; break; }
			}
		}
	}

	/* ---------------- 3. Lab Result + scoring (was iu-results.php type=L) ---------------- */
	if ($ok && isset($_POST['lab_submit'])) {
		// F3: block lab writes for CLOSED rounds — participants only. RCDC
		// (Administrator + RCDC Lab) retain administrative override to correct
		// mistakes after the round has closed.
		if ($round_is_closed && !$can_override) {
			throw new Exception('The round is closed. Lab result submissions are no longer accepted.');
		}
		// F6: block lab edits once RCDC findings have been entered — otherwise
		// the scored outcome could be silently rewritten after RCDC judgment.
		// Same RCDC override applies for corrective edits.
		if ($has_findings && !$can_override) {
			throw new Exception('Lab results are locked once the RCDC Findings have been recorded.');
		}
		$timeliness  = $conn -> real_escape_string($_POST['timeliness']);
		$action      = $conn -> real_escape_string($_POST['lab_action']);
		$examiner    = $conn -> real_escape_string($_POST['examiner']);
		$category    = $conn -> real_escape_string($_POST['category']);
		$designation = $conn -> real_escape_string($_POST['designation']);
		$doe         = $conn -> real_escape_string($_POST['doe']);
		$kitname     = $conn -> real_escape_string($_POST['kitname']);
		$batchlot    = $conn -> real_escape_string($_POST['batchlot']);
		$mfg         = $conn -> real_escape_string($_POST['mfg']);
		$doex        = $conn -> real_escape_string($_POST['doex']);
		if($doex == "") $doex = NULL;

		$slide   = (isset($_POST['lab_slide']) && is_array($_POST['lab_slide'])) ? $_POST['lab_slide'] : array();
		$species = (isset($_POST['species'])   && is_array($_POST['species']))   ? $_POST['species']   : array();

		if($action == 1){
			$stmt1 = $conn->prepare("INSERT INTO rdt_panel_lab_result_informations VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			if(!$stmt1){ $ok = false; }
			else{
				$stmt1->bind_param("issssssssiiiiiisssss", $id, $category, $examiner, $designation, $doe, $kitname, $batchlot, $mfg, $doex, $null, $null, $null, $null, $null, $null, $null, $null, $null, $null, $timeliness);
				if($stmt1->execute()) {
					$stmt1->close();
					$stmt2 = $conn->prepare("INSERT INTO rdt_panel_lab_results VALUES (?, ?, ?, ?)");
					for($i = 0;$i < count($slide); $i++){
						$flag_array=generateFlag($conn, $slide[$i], $species[$i]);
						$stmt2->bind_param("issi", $slide[$i], $species[$i], $flag_array[0], $flag_array[1]);
						if(!$stmt2->execute()){ $ok = false; break; }
					}
					$stmt2->close();
				}
				else{ $ok = false; $stmt1->close(); }
			}
		}
		else{
			$uptqry = "UPDATE rdt_panel_lab_result_informations SET examiner='$examiner', category='$category', designation='$designation', examination_date='$doe', timeliness='$timeliness', kitname='$kitname', batchlot='$batchlot', manufacturer='$mfg', expiry_date='$doex' WHERE panel_id='$id'";
			if($conn->query($uptqry)){
				for($i = 0;$i < count($slide); $i++){
					$flag_array=generateFlag($conn, $slide[$i], $species[$i]);
					$upSpecies = $conn->real_escape_string((string)$species[$i]);
					$upSlide   = (int)$slide[$i];
					if(!$conn->query("UPDATE rdt_panel_lab_results SET lab_species='$upSpecies', positivity_flag='$flag_array[0]', species_flag='$flag_array[1]' WHERE slide_id='$upSlide'")){ $ok = false; break; }
				}
			}
			else $ok = false;
		}

		if($ok){
			//call function to calculate score
			$score_array = calculateScore($conn, $id);
			//update scores
			if(!$conn->query("UPDATE rdt_panel_lab_result_informations SET sensitivity='$score_array[0]', specificity='$score_array[1]', species='$score_array[2]', avg_score1='$score_array[3]', avg_score2='$score_array[4]', final_score='$score_array[5]', achievement='$score_array[6]' WHERE panel_id='$id'")) $ok = false;
		}
	}

		if($ok){ $conn->commit(); $check = true; }
		else   { $conn->rollback(); $check = false; }

		/* Workflow notifications for whichever section of the unified form was
		   submitted — raised AFTER the single commit, one per section, never one
		   per slide row. Failures here can never undo the transaction. */
		if ($check && (int)$id > 0) {
			require_once(__DIR__ . "/../notifications/mn-notify.php");
			if (isset($_POST['info_submit']))       { mn_notify($conn, 'RDT_PANEL_RECEIVED', (int)$id); }
			if (isset($_POST['lab_submit']))        { mn_notify($conn, 'RDT_PANEL_RESULTS_SUBMITTED', (int)$id); }
		}
	} catch (\Throwable $e) {
		// Under PHP 8.1+ mysqli throws on any query error; undo the whole
		// transaction so no partial data is ever left behind. Also captures
		// the workflow-embargo Exceptions thrown above so they surface as a
		// professional SweetAlert instead of a raw fatal.
		$conn->rollback();
		$check = false;
		$denyMsg = $e->getMessage();
	}

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
	<?php }	else { ?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: <?php echo json_encode($denyMsg !== '' ? $denyMsg : 'Submission Failed!!'); ?>,
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>
