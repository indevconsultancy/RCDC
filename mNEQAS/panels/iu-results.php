<?php	
	require_once("../config/session.php");
	require_once("../config/commondb-config.php");
  	require_once("../config/db-config.php"); 
	require_once("../includes/css-jquery.php");

	// Server-side mirror of the UI "CLOSED" gate in panels.php: the panel round's
	// reporting deadline (closing_date) has passed when today > closing_date. Used
	// to block lab-result writes/deletes after the deadline even via a crafted or
	// replayed request (the UI hides the controls, but that alone is bypassable).
	if (!function_exists('mn_panel_closed')) {
		function mn_panel_closed($conn, $panel_id) {
			$pid = $conn->real_escape_string($panel_id);
			$q = $conn->query("SELECT t2.closing_date FROM panel_informations t1, panel_round_informations t2 WHERE t1.round_id=t2.round_id AND t1.panel_id='$pid'");
			$r = ($q && $q->num_rows) ? $q->fetch_row() : null;
			if (!$r || $r[0] === null || $r[0] === '') { return false; }
			try { return (new DateTime(date('Y-m-d'))) > (new DateTime($r[0])); }
			catch (\Throwable $e) { return false; }
		}
	}

	//function to calculate density and flags
	function generateFlag($conn, $slide, $species, $stages, $parasite, $wbc){
		$pos_array = array('Pf','Pv','Po','Pm','Pk','F+V');
		
		//calculate density for positives only
		if ($species == "Nmps" || $wbc <= 0 || $wbc == "" || $parasite == "") {
		    $density = 0;
		} else {
		    $density = ($parasite / $wbc) * 8000;
		}

		//get RCDC species, stages, density ranges
		$rcdc = ($conn->query("SELECT rcdc_species, rcdc_stages, min_range, max_range FROM panel_rcdc_results WHERE slide_id='$slide'"));
		$rcdc = ($rcdc && $rcdc->num_rows > 0) ? $rcdc->fetch_row() : null;
		//no RCDC reference row for this slide: use neutral defaults so the
		//comparisons below evaluate exactly as before without null offsets
		if (!is_array($rcdc)) { $rcdc = array('', '', 0, 0); }

		//flag TP,FP,FN,TN by comparing Lab species with RCDC species
		//default keeps positivity_flag non-null when no branch matches (e.g. missing RCDC data)
		$pos_flag = "MI";
		if($species == "")	$pos_flag = "MI";
		else{
			if (in_array($rcdc[0], $pos_array) && in_array($species, $pos_array)) $pos_flag = "TP";
			if ($rcdc[0] == "Nmps" && in_array($species, $pos_array)) $pos_flag = "FP";
			if (in_array($rcdc[0], $pos_array) && $species == "Nmps") $pos_flag = "FN";
			if ($rcdc[0]=="Nmps" && $species == "Nmps") $pos_flag = "TN";
		}	

		//flags for only Lab positive species	
		$species_flag = $stages_flag = $density_flag = 0;

		if(in_array($rcdc[0], $pos_array)){
			//flag species identification
			if($rcdc[0] == $species){	$species_flag = 1;

				//flag stage identification
				//rcdc and lab stage is exact match
				if($rcdc[1] == $stages)	$stages_flag = 1;
				else{
					$rcdc_cnt = strlen($rcdc[1]);
					//$lab_cnt = strlen($stages);
					$rcdc_arr = str_split($rcdc[1]);
					$lab_arr = str_split($stages);
					$common = count(array_intersect($rcdc_arr, $lab_arr)); 
					//all rcdc stages is in lab stages
					if($common == $rcdc_cnt) $stages_flag = 1;
					//partial lab stages in rcdc stages
					else $stages_flag = $common/$rcdc_cnt;
				}
			}
			//flag parasite density
			if($density >= $rcdc[2] && $density <= $rcdc[3]) $density_flag = 1;
		}
		
		$flag_array = array($density, $pos_flag, $species_flag, $stages_flag, $density_flag);
		return $flag_array;
	}

	//calculate scores and update
	function calculateScore($conn, $id, $rid){

		$rcdc_pos = ($conn->query("SELECT COUNT(*) FROM panel_rcdc_results t1, panel_slide_informations t2 WHERE t1.slide_id=t2.slide_id AND t2.panel_id='$id' AND t1.rcdc_species IN('Pf','Pv','Po','Pm','Pk','F+V')"));
		$rcdc_pos = $rcdc_pos->fetch_row();

		$rcdc_neg = ($conn->query("SELECT COUNT(*) FROM panel_rcdc_results t1, panel_slide_informations t2 WHERE t1.slide_id=t2.slide_id AND t2.panel_id='$id' AND t1.rcdc_species='Nmps'"));
		$rcdc_neg = $rcdc_neg->fetch_row();

		$panel_received = ($conn->query("SELECT COUNT(*) FROM panel_slide_informations WHERE panel_id='$id' AND slide_status='Intact'"));
		$panel_received = $panel_received->fetch_row();

		$tp = ($conn->query("SELECT COUNT(*) FROM panel_lab_results WHERE information_id='$rid' AND positivity_flag='TP'"));
		$tp = $tp->fetch_row();

		$tn = ($conn->query("SELECT COUNT(*) FROM panel_lab_results WHERE information_id='$rid' AND positivity_flag='TN'"));
		$tn = $tn->fetch_row();
		
		$species_flag_total = ($conn->query("SELECT SUM(species_flag) FROM panel_lab_results WHERE information_id='$rid'"));
		$species_flag_total = $species_flag_total->fetch_row();

		$stages_flag_total = ($conn->query("SELECT SUM(stages_flag) FROM panel_lab_results WHERE information_id='$rid'"));
		$stages_flag_total = $stages_flag_total->fetch_row();

		$density_flag_total = ($conn->query("SELECT SUM(density_flag) FROM panel_lab_results WHERE information_id='$rid'"));
		$density_flag_total = $density_flag_total->fetch_row();

		//guard against zero denominators (e.g. a panel with no positive/negative
		//reference slides or none received) — a metric with no applicable slides
		//scores 0, matching the pre-PHP 8 runtime behaviour without a fatal error
		$sen_score = ($rcdc_pos[0] > 0) ? ($tp[0]/$rcdc_pos[0])*100 : 0;
		$spc_score = ($rcdc_neg[0] > 0) ? ($tn[0]/$rcdc_neg[0])*100 : 0;
		$inf_score = ($panel_received[0] > 0) ? (($tp[0]+$tn[0])/$panel_received[0])*100 : 0;
		$spe_score = ($rcdc_pos[0] > 0) ? ($species_flag_total[0]/$rcdc_pos[0])*100 : 0;
		$sta_score = ($rcdc_pos[0] > 0) ? ($stages_flag_total[0]/$rcdc_pos[0])*100 : 0;
		$den_score = ($rcdc_pos[0] > 0) ? ($density_flag_total[0]/$rcdc_pos[0])*100 : 0;
		$avg_score =round(($sen_score + $spc_score + $inf_score + $spe_score + $sta_score + $den_score)/6,2);
		if($avg_score >= 90) $achievement = "Excellent";
		else if($avg_score >= 80 && $avg_score < 90) $achievement = "Very Good";
		else if($avg_score >= 70 && $avg_score < 80) $achievement = "Good";
		else $achievement = "Poor";

		$score_array = array($sen_score, $spc_score, $inf_score, $spe_score, $sta_score, $den_score, $avg_score, $achievement);
		return $score_array;
	}

	// Auth helpers loaded once here so both branches (R + L) share the same guard set.
	require_once("../config/auth.php");

	$null =  NULL;
	// Int-cast panel_id at intake — makes every interpolated query that uses $id
	// injection-safe (panels/informations/results are PK-typed columns).
	$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
	$type = isset($_POST['type']) ? $_POST['type'] : '';

	/* ---- Authorization before any SQL ------------------------------------------
	   R (true answers) = RCDC; the slide must belong to the panel.
	   L (lab results)  = writer on their own facility's panel; slide / result ids must
	   belong to the panel. Facility users additionally: round not CLOSED, receipt
	   recorded, RCDC answers present, no RCDC findings yet; timeliness is computed
	   server-side (ONTIME/LATE) instead of trusting the hidden form value. */
	mn_require_record($conn, 'panel', $id);
	if ($type == "R") {
		if (!mn_is_rcdc()) { mn_deny('You are not authorised to submit RCDC answers.'); }
		mn_require_child($conn, 'panel_slide', isset($_POST['rid']) ? (int)$_POST['rid'] : 0, $id);
	} else {
		mn_require_write('You have read-only access and cannot submit lab results.');
		$authSlides = isset($_POST['slide']) && is_array($_POST['slide']) ? $_POST['slide'] : array();
		foreach ($authSlides as $authSlide) { mn_require_child($conn, 'panel_slide', (int)$authSlide, $id); }
		$authIsInsert = (isset($_POST['action']) && (int)$_POST['action'] === 1);
		$authRid = isset($_POST['rid']) ? (int)$_POST['rid'] : 0;
		if (!$authIsInsert) {
			mn_require_child($conn, 'panel_info', $authRid, $id);
			$authRslt = isset($_POST['rsltid']) && is_array($_POST['rsltid']) ? $_POST['rsltid'] : array();
			foreach ($authRslt as $authR) { mn_require_child($conn, 'panel_result', (int)$authR, $authRid); }
		}
		if (!mn_is_rcdc()) {
			$authState = mn_panel_state($conn, 'micro', $id);
			if (!$authState || $authState['closed']) { mn_deny('The reporting deadline for this panel has passed; lab results can no longer be updated.', $id); }
			if (!$authState['received']) { mn_deny('Record the panel receipt before entering lab results.', $id); }
			$ra = $conn->prepare("SELECT COUNT(*) FROM panel_rcdc_results r INNER JOIN panel_slide_informations s ON s.slide_id = r.slide_id WHERE s.panel_id = ?");
			$ra->bind_param('i', $id);
			$ra->execute();
			$raCount = (int)$ra->get_result()->fetch_row()[0];
			$ra->close();
			if ($raCount === 0) { mn_deny('Lab results can be entered once RCDC has set up the panel answers.', $id); }
			if (!$authIsInsert) {
				$fd = $conn->prepare("SELECT COALESCE(findings, '') FROM panel_lab_result_informations WHERE information_id = ?");
				$fd->bind_param('i', $authRid);
				$fd->execute();
				$fdRow = $fd->get_result()->fetch_row();
				$fd->close();
				if ($fdRow && $fdRow[0] !== '') { mn_deny('Lab results are locked once RCDC findings have been recorded.', $id); }
			}
			$_POST['timeliness'] = $authState['flag'];
		}
	}

	if($type == "R"){
		// RCDC "true answers" are RCDC/Admin only — enforce server-side, not just UI.
		if (!mn_is_rcdc()) { mn_deny('You are not authorised to submit RCDC answers.'); }
		$rid = isset($_POST['rid']) ? (int)$_POST['rid'] : 0;
		$species = $_POST['species'] ?? '';
		$stages  = $_POST['stages']  ?? '';
		$parasite = $_POST['parasite'] ?? '';
		$wbc      = $_POST['wbc']      ?? '';

		if($species == "Nmps" || $wbc <= 0 || $wbc == "" || $parasite == "") $density = $min = $max = 0;
		else {
			$density = ($parasite/$wbc) * 8000;
			$min = $density - ($density/2);
			$max = $density + ($density/2);
		}

		// Parameterized existence check + prepared UPDATE. No raw string
		// interpolation of $rid / $species / stages / counts.
		$chkStmt = $conn->prepare("SELECT COUNT(*) FROM panel_rcdc_results WHERE slide_id = ?");
		$chkStmt->bind_param("i", $rid);
		$chkStmt->execute();
		$chkResult = $chkStmt->get_result()->fetch_row();
		$chkStmt->close();

		if($chkResult[0] == 0){
			$stmt = $conn->prepare("INSERT INTO panel_rcdc_results VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
			$stmt->bind_param("issiiiii", $rid, $species, $stages, $parasite, $wbc, $density, $min, $max);
			$check = $stmt->execute();
			$stmt->close();
		}
		else{
			$stmt = $conn->prepare("UPDATE panel_rcdc_results SET rcdc_species=?, rcdc_stages=?, rcdc_parasite_count=?, rcdc_wbc_count=?, rcdc_parasite_density=?, min_range=?, max_range=? WHERE slide_id=?");
			$stmt->bind_param("ssiiiiii", $species, $stages, $parasite, $wbc, $density, $min, $max, $rid);
			$check = $stmt->execute();
			$stmt->close();
		}
	}
	else{
		// Server-side enforcement of the Lab Result gate (previously UI-only in
		// panels.php): only Create/Update roles may write. Read-only "Others"
		// are rejected outright. Post-deadline edits are blocked for
		// participants only — RCDC (Administrator + RCDC Lab, roleID 1|2)
		// retain administrative override to correct genuine mistakes after the
		// reporting deadline, matching the RDT-side embargo behaviour.
		if (!mn_can_write()) { mn_deny('You have read-only access and cannot submit lab results.'); }
		if (mn_panel_closed($conn, $id) && !mn_is_rcdc()) {
			mn_deny('The reporting deadline for this panel has passed; lab results can no longer be updated.');
		}

		$timeliness  = $_POST['timeliness']  ?? '';
		$action      = isset($_POST['action']) ? (int)$_POST['action'] : 0;
		$examiner    = $_POST['examiner']    ?? '';
		$category    = $_POST['category']    ?? '';
		$designation = $_POST['designation'] ?? '';
		$doe         = $_POST['doe']         ?? '';
		$slide    = isset($_POST['slide'])    && is_array($_POST['slide'])    ? $_POST['slide']    : [];
		$species  = isset($_POST['species'])  && is_array($_POST['species'])  ? $_POST['species']  : [];
		$stages   = isset($_POST['stages'])   && is_array($_POST['stages'])   ? $_POST['stages']   : [];
		$parasite = isset($_POST['parasite']) && is_array($_POST['parasite']) ? $_POST['parasite'] : [];
		$wbc      = isset($_POST['wbc'])      && is_array($_POST['wbc'])      ? $_POST['wbc']      : [];

		if($action == 1){
			$maxStmt = $conn->query("SELECT COALESCE(MAX(information_id), 0) + 1 FROM panel_lab_result_informations");
			$rid = (int)$maxStmt->fetch_row()[0];
			$stmt1 = $conn->prepare("INSERT INTO panel_lab_result_informations VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$stmt1->bind_param("iissssiiiiiiisssss", $rid, $id, $category, $examiner, $designation, $doe, $null, $null, $null, $null, $null, $null, $null, $null, $null, $null, $null, $timeliness);

			if($stmt1->execute()) {
				$stmt1->close();
				$stmt2 = $conn->prepare("INSERT INTO panel_lab_results VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
				for($i = 0;$i < count($slide); $i++){
					// negatives: blank the stage, zero the counts (density is forced to 0 in generateFlag)
					if(($species[$i] ?? '') == "Nmps") {
						$stages[$i] = ""; $parasite[$i] = $wbc[$i] = 0;
					}
					else if(($wbc[$i] ?? 0) <= 0 || ($wbc[$i] ?? '') == "" || ($parasite[$i] ?? '') == "") $parasite[$i] = $wbc[$i] = 0;

					$slideId = (int)$slide[$i];
					$flag_array = generateFlag($conn, $slideId, $species[$i], $stages[$i], $parasite[$i], $wbc[$i]);
					$stmt2->bind_param("iiissiiisidi", $null, $rid, $slideId, $species[$i], $stages[$i], $parasite[$i], $wbc[$i], $flag_array[0], $flag_array[1], $flag_array[2], $flag_array[3], $flag_array[4]);
					$stmt2->execute();
				}
				$stmt2->close();
				$check = true;
			}
			else $check = false;
		}
		else{
			$rid = isset($_POST['rid']) ? (int)$_POST['rid'] : 0;
			$rsltid = isset($_POST['rsltid']) && is_array($_POST['rsltid']) ? $_POST['rsltid'] : [];

			$updHdr = $conn->prepare("UPDATE panel_lab_result_informations SET examiner=?, category=?, designation=?, examination_date=?, timeliness=? WHERE information_id=?");
			$updHdr->bind_param("sssssi", $examiner, $category, $designation, $doe, $timeliness, $rid);
			$hdrOk = $updHdr->execute();
			$updHdr->close();

			if($hdrOk){
				$updRow = $conn->prepare("UPDATE panel_lab_results SET lab_species=?, lab_stages=?, lab_parasite_count=?, lab_wbc_count=?, lab_parasite_density=?, positivity_flag=?, species_flag=?, stages_flag=?, density_flag=? WHERE result_id=?");
				for($i = 0;$i < count($rsltid); $i++){
					if(($species[$i] ?? '') == "Nmps") {
						$stages[$i] = ""; $parasite[$i] = $wbc[$i] = 0;
					}
					else if(($wbc[$i] ?? 0) <= 0 || ($wbc[$i] ?? '') == "" || ($parasite[$i] ?? '') == "") $parasite[$i] = $wbc[$i] = 0;

					$slideId  = (int)$slide[$i];
					$resultId = (int)$rsltid[$i];
					$flag_array = generateFlag($conn, $slideId, $species[$i], $stages[$i], $parasite[$i], $wbc[$i]);

					$updRow->bind_param("ssiiisdddi",
						$species[$i], $stages[$i], $parasite[$i], $wbc[$i],
						$flag_array[0], $flag_array[1], $flag_array[2], $flag_array[3], $flag_array[4],
						$resultId
					);
					$updRow->execute();
				}
				$updRow->close();
				$check = true;
			}
			else $check = false;
		}

		// Recompute and persist the 6-metric scoring (Sensitivity / Specificity /
		// Infection / Species / Stages / Density) exactly as before — logic is
		// unchanged; only the persistence write is now parameterized.
		$score_array = calculateScore($conn, $id, $rid);
		$updScore = $conn->prepare("UPDATE panel_lab_result_informations SET sensitivity=?, specificity=?, infection=?, species=?, stages=?, density=?, avg_score=?, achievement=? WHERE information_id=?");
		$updScore->bind_param("ddddddssi",
			$score_array[0], $score_array[1], $score_array[2], $score_array[3],
			$score_array[4], $score_array[5], $score_array[6], $score_array[7],
			$rid
		);
		$updScore->execute();
		$updScore->close();
	}

	/* ONE notification for the whole results submission, not one per slide row:
	   the event is raised here, after every row of the batch has been written. */
	if ($check && (int)$id > 0) {
		require_once("../notifications/mn-notify.php");
		mn_notify($conn, 'MICRO_PANEL_RESULTS_SUBMITTED', (int)$id);
	}

	$lnk = "panels.php?id=$id";
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
	
