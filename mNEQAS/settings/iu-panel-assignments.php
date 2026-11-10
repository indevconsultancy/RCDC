<?php
/**
 * Micro Panel Assignment — save (Add / Update)
 * ---------------------------------------------------------------------------
 * Hardened to match the safeguards already proven in RDT Panel Assignment:
 * validate every submitted field, reject duplicate slide numbers, and
 * perform the whole save inside one transaction so a failure can never leave
 * a panel with partial slides.
 *
 * Supports dynamic array of slides: $_POST['slide_no'] and $_POST['slide_id'],
 * with fallback support for $_POST['slide1']..
 */
	require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc();
	require_once("../config/commondb-config.php");
	require_once("../config/db-config.php");
	require_once("../includes/css-jquery.php");
	date_default_timezone_set('Asia/Thimphu');

	$lnk = "panel-assignments.php";
	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

	$check   = false;
	$failMsg = 'Submission Failed!!';

	/* ---------------------------------------------------------------- input */
	$action    = isset($_POST['action'])     ? trim($_POST['action'])     : '';
	$id        = isset($_POST['id'])         ? trim($_POST['id'])         : '';
	$round     = isset($_POST['round'])      ? trim($_POST['round'])      : '';
	$facility  = isset($_POST['facility'])   ? trim($_POST['facility'])   : '';
	$dzongkhag = isset($_POST['dzongkhag2']) ? trim($_POST['dzongkhag2'])
	           : (isset($_POST['dzongkhag']) ? trim($_POST['dzongkhag'])  : '');

	/* Read slide numbers and slide ids dynamically */
	$slideNos = array();
	$slideIds = array();

	if (isset($_POST['slide_no']) && is_array($_POST['slide_no'])) {
		$rawNos = array_values($_POST['slide_no']);
		$rawIds = (isset($_POST['slide_id']) && is_array($_POST['slide_id'])) ? array_values($_POST['slide_id']) : array();
		for ($idx = 0; $idx < count($rawNos); $idx++) {
			$i = $idx + 1;
			$slideNos[$i] = trim((string)$rawNos[$idx]);
			$slideIds[$i] = isset($rawIds[$idx]) ? trim((string)$rawIds[$idx]) : '';
		}
	} else {
		// Fallback for indexed field names (slide1..slideN, slideid1..slideidN)
		for ($i = 1; $i <= 100; $i++) {
			if (!isset($_POST['slide'.$i])) {
				if ($i > 5) break;
				continue;
			}
			$slideNos[$i] = trim((string)$_POST['slide'.$i]);
			$slideIds[$i] = isset($_POST['slideid'.$i]) ? trim((string)$_POST['slideid'.$i]) : '';
		}
	}
	$totalSlidesCount = count($slideNos);

	/* ----------------------------------------------------------- validation */
	$err = '';
	if ($action !== '1' && $action !== '2')          { $err = 'Invalid submission action.'; }
	elseif ($round === '' || !ctype_digit($round))   { $err = 'Panel round is required.'; }
	elseif ($dzongkhag === '')                       { $err = 'Dzongkhag is required.'; }
	elseif ($facility === '')                        { $err = 'Health facility is required.'; }
	elseif ($action === '2' && !ctype_digit($id))    { $err = 'Panel reference is missing.'; }
	elseif ($totalSlidesCount === 0)                 { $err = 'At least one slide number is required.'; }

	/* All slide fields are required */
	if ($err === '') {
		for ($i = 1; $i <= $totalSlidesCount; $i++) {
			if (!isset($slideNos[$i]) || $slideNos[$i] === '') { $err = 'Slide No. '.$i.' is required.'; break; }
			if (mb_strlen($slideNos[$i]) > 50) { $err = 'Slide No. '.$i.' is too long (max 50 characters).'; break; }
		}
	}

	/* Check uniqueness among submitted fields */
	if ($err === '') {
		$seen = array();
		for ($i = 1; $i <= $totalSlidesCount; $i++) {
			$key = mb_strtolower($slideNos[$i]);
			if (isset($seen[$key])) { $err = 'Slide number '.$slideNos[$i].' is entered more than once. Each slide number must be unique.'; break; }
			$seen[$key] = $i;
		}
	}

	/* The facility must belong to the submitted dzongkhag */
	if ($err === '') {
		$fchk = $commondb->prepare("SELECT center_id FROM ms_health_center_table WHERE center_id = ? AND dzongkhag_code = ? LIMIT 1");
		if ($fchk) {
			$fchk->bind_param('ss', $facility, $dzongkhag);
			$fchk->execute();
			$fOk = $fchk->get_result()->fetch_row();
			$fchk->close();
			if (!$fOk) { $err = 'Selected Health Facility does not belong to the selected Dzongkhag.'; }
		}
	}

	/**
	 * No slide number may already belong to a DIFFERENT panel.
	 */
	function mnAssertSlidesFree($conn, array $slideNos, $excludePanelId = null) {
		$values = array_values($slideNos);
		if (empty($values)) { return; }
		$place  = implode(',', array_fill(0, count($values), '?'));
		$sql    = "SELECT slide_number FROM panel_slide_informations WHERE slide_number IN ($place)";
		$types  = str_repeat('s', count($values));
		$params = $values;
		if ($excludePanelId !== null) {
			$sql     .= " AND panel_id <> ?";
			$types   .= 'i';
			$params[] = (int)$excludePanelId;
		}
		$stmt = $conn->prepare($sql . " LIMIT 1");
		if (!$stmt) { throw new Exception('Could not verify slide numbers.'); }
		$stmt->bind_param($types, ...$params);
		if (!$stmt->execute()) { throw new Exception('Could not verify slide numbers.'); }
		$hit = $stmt->get_result()->fetch_row();
		$stmt->close();
		if ($hit) {
			throw new Exception('Slide number '.$hit[0].' is already assigned to another panel. Please change it, or re-select the round or facility to generate new numbers.');
		}
	}

	if ($err !== '') {
		$failMsg = $err;
	}
	elseif ($action === '1') {
		/* ------------------------------------------------------------ Add */
		$conn->begin_transaction();
		try {
			mnAssertSlidesFree($conn, $slideNos);

			$stmt1 = $conn->prepare("INSERT INTO panel_informations (round_id, facility_id) VALUES (?, ?)");
			if (!$stmt1) { throw new Exception('Could not save the panel.'); }
			$roundInt = (int)$round;
			$stmt1->bind_param('is', $roundInt, $facility);
			if (!$stmt1->execute()) { throw new Exception('Could not save the panel.'); }
			$stmt1->close();
			$panelId = (int)$conn->insert_id;
			if ($panelId <= 0) { throw new Exception('Could not save the panel.'); }

			$stmt2 = $conn->prepare("INSERT INTO panel_slide_informations (panel_id, slide_number) VALUES (?, ?)");
			if (!$stmt2) { throw new Exception('Could not save the slide numbers.'); }
			for ($i = 1; $i <= $totalSlidesCount; $i++) {
				$stmt2->bind_param('is', $panelId, $slideNos[$i]);
				if (!$stmt2->execute()) { throw new Exception('Could not save the slide numbers.'); }
			}
			$stmt2->close();

			$conn->commit();
			$check  = true;
			$newId  = $panelId;
		} catch (Exception $e) {
			$conn->rollback();
			$failMsg = $e->getMessage();
		}
	}
	else {
		/* --------------------------------------------------------- Update */
		$conn->begin_transaction();
		try {
			$panelId = (int)$id;
			mnAssertSlidesFree($conn, $slideNos, $panelId);

			$up = $conn->prepare("UPDATE panel_informations SET round_id = ?, facility_id = ? WHERE panel_id = ?");
			if (!$up) { throw new Exception('Could not update the panel.'); }
			$roundInt = (int)$round;
			$up->bind_param('isi', $roundInt, $facility, $panelId);
			if (!$up->execute()) { throw new Exception('Could not update the panel.'); }
			$up->close();

			$ownRes = $conn->prepare("SELECT slide_id FROM panel_slide_informations WHERE panel_id = ?");
			if (!$ownRes) { throw new Exception('Could not verify the slide rows.'); }
			$ownRes->bind_param('i', $panelId);
			if (!$ownRes->execute()) { throw new Exception('Could not verify the slide rows.'); }
			$ownIds = array();
			$ownQ = $ownRes->get_result();
			while ($r = $ownQ->fetch_row()) { $ownIds[(int)$r[0]] = true; }
			$ownRes->close();

			$sUp = $conn->prepare("UPDATE panel_slide_informations SET slide_number = ? WHERE slide_id = ? AND panel_id = ?");
			if (!$sUp) { throw new Exception('Could not update the slide numbers.'); }

			$sIn = $conn->prepare("INSERT INTO panel_slide_informations (panel_id, slide_number) VALUES (?, ?)");
			if (!$sIn) { throw new Exception('Could not insert slide number.'); }

			$usedIds = array();
			for ($i = 1; $i <= $totalSlidesCount; $i++) {
				$currId = $slideIds[$i];
				if ($currId !== '' && ctype_digit((string)$currId) && isset($ownIds[(int)$currId])) {
					$slideIdInt = (int)$currId;
					$usedIds[] = $slideIdInt;
					$sUp->bind_param('sii', $slideNos[$i], $slideIdInt, $panelId);
					if (!$sUp->execute()) { throw new Exception('Could not update slide numbers.'); }
				} else {
					$sIn->bind_param('is', $panelId, $slideNos[$i]);
					if (!$sIn->execute()) { throw new Exception('Could not insert slide number.'); }
					$newSlideId = (int)$conn->insert_id;
					if ($newSlideId > 0) {
						$usedIds[] = $newSlideId;
					}
				}
			}
			$sUp->close();
			$sIn->close();

			// If round capacity was reduced, clean up orphaned slide records from this panel
			if (count($usedIds) > 0) {
				$placeholders = implode(',', array_fill(0, count($usedIds), '?'));
				$delTypes = 'i' . str_repeat('i', count($usedIds));
				$delParams = array_merge([$panelId], $usedIds);
				$delStmt = $conn->prepare("DELETE FROM panel_slide_informations WHERE panel_id = ? AND slide_id NOT IN ($placeholders)");
				if ($delStmt) {
					$delStmt->bind_param($delTypes, ...$delParams);
					$delStmt->execute();
					$delStmt->close();
				}
			}

			$conn->commit();
			$check = true;
		} catch (Exception $e) {
			$conn->rollback();
			$failMsg = $e->getMessage();
		}
	}

	/* Notification after assignment */
	if ($check && $action === '1' && isset($newId) && $newId > 0) {
		require_once("../notifications/mn-notify.php");
		mn_notify($conn, 'MICRO_PANEL_ASSIGNED', (int)$newId);
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
	<?php }	else { ?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: <?=json_encode($failMsg);?>,
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>
