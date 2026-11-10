<?php	
	require_once("../config/session.php");
	require_once("../config/db-config.php");

    /* ------------------------------------------------------------------
       Final Submit (finalize) — AJAX / JSON endpoint.
       Handled here, BEFORE css-jquery.php, so no HTML is emitted into the
       JSON response. Sets the finalized flag on blinded_feedback; the
       Print Feedback Report button is gated on this flag (a record cannot
       be printed until it has been finalised on the Review step).
       RCDC/admin only; a feedback row must already exist. Purely additive
       — no existing handler, query or business rule is touched. */
    if (isset($_POST['submit']) && $_POST['submit'] === 'finalize') {
        header('Content-Type: application/json');
        // Final approval is an RCDC stage (Administrator or RCDC Labs): the role that
        // runs the rechecking workflow also closes it. Still denied to everyone else.
        if (!mn_is_rcdc()) {
            mn_deny_json('Only RCDC users can give final approval (Final Submit).');
        }
        $bid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($bid <= 0) {
            echo json_encode(array('ok' => false, 'msg' => 'Invalid record reference.'));
            exit;
        }
        $chk = $conn->query("SELECT feedback_id FROM blinded_feedback WHERE blinded_id = $bid");
        if (!$chk || $chk->num_rows === 0) {
            echo json_encode(array('ok' => false, 'msg' => 'Please complete the Feedback & Actions step before Final Submit.'));
            exit;
        }
        if ($conn->query("UPDATE blinded_feedback SET finalized = 1, finalized_at = NOW() WHERE blinded_id = $bid")) {
            echo json_encode(array('ok' => true));
        } else {
            echo json_encode(array('ok' => false, 'msg' => 'Could not finalize the record. Please try again.'));
        }
        exit;
    }

	// RBAC backend enforcement for the non-finalize save stages: only Create/Update
	// roles (Administrator, RCDC Lab, Health Worker) may write; read-only "Others"
	// are blocked. The stricter per-stage RCDC checks further below still apply
	// (e.g. Shipment/Feedback/Action = roles 1/2, Controller 2 = role 1 only).
	require_once("../config/auth.php"); mn_require_write();

	/* ------------------------------------------------------------------
	   Stage denials for AJAX callers.

	   Every wizard step on blinded.php posts here with jQuery. mn_deny()
	   answers a browser NAVIGATION: HTTP 403 plus an HTML page carrying an
	   alert() and a redirect. An XHR cannot use that — jQuery only sees "not
	   2xx" and used to report "Network error while saving", hiding refusals
	   such as "Controller 2 results cannot be changed on a finalized record."

	   For an XHR the same refusal is returned as JSON (still HTTP 403, same
	   rule, same logging) so the page can show the real reason. Ordinary form
	   posts keep the page-mode behaviour exactly as before.
	   ------------------------------------------------------------------ */
	$blIsAjax = (
		isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
		strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
	);

	if (!function_exists('bl_deny')) {
		function bl_deny($msg, $recordId = null) {
			global $blIsAjax;
			if ($blIsAjax) { mn_deny_json($msg, $recordId); }   // 403 + JSON, exits
			mn_deny($msg, $recordId);                           // 403 + page, exits
		}
	}

	/* ---- Stage + record authorization (server-side, before any SQL) ----------
	   District Intake (blindedInfo, labresults) : Health Worker own facility, or RCDC
	   Shipment receive / Controller 1 / Feedback : RCDC (1, 2)
	   Controller 2 (and its final scoring)       : RCDC (1, 2)
	   The owning facility is always read from blinded_informations, never the POST. */
	$authSubmit = isset($_POST['submit']) ? (string)$_POST['submit'] : '';
	$authAction = isset($_POST['action']) ? (string)$_POST['action'] : '';
	$authBiid   = isset($_POST['biid']) ? (int)$_POST['biid'] : 0;
	if ($authSubmit === 'controller2' && !mn_is_rcdc()) {
		bl_deny('Controller 2 and final scoring are restricted to RCDC users.', $authBiid);
	}
	if ($authSubmit === 'controller2' && $authBiid > 0) {
		// A finalized record's scores are the issued report. Controller 2 drives every
		// score, so rewriting it after Final Submit would silently change a report that
		// has already been released — the same rule delete.php applies to clearing C2.
		$finSt = $conn->prepare("SELECT COALESCE(finalized, 0) FROM blinded_feedback WHERE blinded_id = ?");
		$finSt->bind_param('i', $authBiid);
		$finSt->execute();
		$finRowC2 = $finSt->get_result()->fetch_row();
		$finSt->close();
		if ($finRowC2 && (int)$finRowC2[0] === 1) {
			bl_deny('Controller 2 results cannot be changed on a finalized record. Reopen the report before editing Controller 2.', $authBiid);
		}
	}
	if (in_array($authSubmit, array('labresultsBulk', 'bsStatus', 'c1Resulrs', 'feedback', 'findings', 'actions'), true) && !mn_is_rcdc()) {
		bl_deny('This Blinded Rechecking stage is restricted to RCDC users.', $authBiid);
	}
	if (!in_array($authSubmit, array('blindedInfo', 'labresults', 'labresultsBulk', 'bsStatus', 'c1Resulrs', 'controller2', 'feedback', 'findings', 'actions'), true)) {
		bl_deny('Unknown submission stage.');
	}
	$authIsUpdateInfo = ($authSubmit === 'blindedInfo' && $authAction == 2);
	if ($authSubmit !== 'blindedInfo' || $authIsUpdateInfo) {
		// Every stage except a new intake acts on an existing record.
		if ($authBiid <= 0 && !mn_is_rcdc()) { bl_deny('Missing record reference.'); }
		mn_require_record($conn, 'blinded', $authBiid);
	}
	if (!mn_is_rcdc()) {
		// Facility users: the facility is their own, never the submitted value.
		if ($authSubmit === 'blindedInfo') { $_POST['facility'] = (string)mn_facility(); }
		// District data is editable only until RCDC receives the shipment / starts Controller 1
		// (same rule as $districtEditable on blinded.php).
		if ($authIsUpdateInfo || $authSubmit === 'labresults') {
			$lk = $conn->prepare("SELECT (receive_date IS NOT NULL AND receive_date <> ''),
			                             (SELECT COUNT(*) FROM blinded_results WHERE blinded_id = ? AND slide_status = 'Intact' AND c1_microscopy IS NOT NULL AND c1_microscopy <> '')
			                        FROM blinded_informations WHERE blinded_id = ?");
			$lk->bind_param('ii', $authBiid, $authBiid);
			$lk->execute();
			$lkRow = $lk->get_result()->fetch_row();
			$lk->close();
			if ($lkRow && ((int)$lkRow[0] === 1 || (int)$lkRow[1] > 0)) {
				bl_deny('District data is locked once RCDC has received the shipment or started Controller 1.', $authBiid);
			}
		}
		if ($authSubmit === 'labresults' && $authAction === 'update') {
			mn_require_child($conn, 'blinded_result', isset($_POST['rid']) ? (int)$_POST['rid'] : 0, $authBiid);
		}
	}
	if (mn_is_rcdc() && $authSubmit === 'labresults' && $authAction === 'update') {
		mn_require_child($conn, 'blinded_result', isset($_POST['rid']) ? (int)$_POST['rid'] : 0, $authBiid);
	}

	require_once("../includes/css-jquery.php");
    require_once("blinded-result-functions.php");
    require_once("blinded-functions.php");

    $submit = $conn -> real_escape_string($_POST['submit']);
    $action = $conn -> real_escape_string($_POST['action']);
    $errmsg = '';

    // sqlNum() and calcDensity() now live in blinded-functions.php (already
    // required above) so the Excel importer and this handler share one
    // implementation. The code is unchanged — only its location moved.
    if ($submit=='blindedInfo') {
        $facility = $conn -> real_escape_string($_POST['facility']);
        $month = $conn -> real_escape_string($_POST['month']);
        $year = $conn -> real_escape_string($_POST['year']);        
        $totalMicroscopy = $conn -> real_escape_string($_POST['totalMicroscopy']);
        $totalRDT = $conn -> real_escape_string($_POST['totalRDT']);
        $totalMalaria = $conn -> real_escape_string($_POST['totalMalaria']);
        $pslides = $conn -> real_escape_string($_POST['pslides']);
        $nslides = $conn -> real_escape_string($_POST['nslides']);
        $tslides = $conn -> real_escape_string($_POST['tslides']);
        $dateship = $conn -> real_escape_string($_POST['dateship']);

        if($action == 2){
            if ($totalMicroscopy == 0) {            
                $tslides = $pslides = $nslides = 0;
            }
            $biid = $conn -> real_escape_string($_POST['biid']);
            $lnk = "blinded.php?action=2&id=$biid";
            $uptqry = "UPDATE blinded_informations SET facility_id = '$facility', month = '$month', year = '$year', total_microscopy =$totalMicroscopy, total_rdt=$totalRDT, positive_slides = $pslides, negative_slides = $nslides, total_slides=$tslides, shipment_date = '$dateship' WHERE blinded_id='$biid'";
            if($conn->query($uptqry)){
                $check = true;  
            }   else $check = false;
        }
        else{
            if ($totalMicroscopy == 0) {            
                $tslides = $pslides = $nslides = NULL;
            }
            $biidqry = ($conn->query("SELECT MAX(blinded_id)+1 FROM blinded_informations"));
            list($biid) = $biidqry->fetch_row();
            if ($biid=='') {$biid=1;}
            $dor = $time = NULL;
            $lnk = "blinded.php?action=2&id=$biid";
            $stmt = $conn->prepare("INSERT INTO blinded_informations (blinded_id, facility_id, month, year, total_microscopy, total_rdt, total_malaria, positive_slides, negative_slides, total_slides, shipment_date, receive_date, timeliness) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isiiiiiiiisss", $biid, $facility, $month, $year, $totalMicroscopy, $totalRDT, $totalMalaria, $pslides, $nslides, $tslides, $dateship, $dor, $time);
            if($stmt->execute()){   
                $stmt->close(); $check = true; 
            } else $check = false;            
        }
    }

    if ($submit=='labresults') {
        $biid = $conn -> real_escape_string($_POST['biid']);
        $rid = $conn -> real_escape_string($_POST['rid']);
        $microscopy = $conn -> real_escape_string($_POST['microscopy']);
        $bsno = $conn -> real_escape_string($_POST['bsno']);        
        $doc = $conn -> real_escape_string($_POST['doc']);
        $pstage = $conn -> real_escape_string($_POST['pstage']);
        $pcount = $conn -> real_escape_string($_POST['pcount']);
        $wbcount = $conn -> real_escape_string($_POST['wbcount']);
        // $density = $conn -> real_escape_string($_POST['density']);
        if ($microscopy =='Nmps') {
            $pstage = $pcount = $wbcount = $density = null;
        }else {
            $wbf = (float)$wbcount;
            $density = ($wbf != 0) ? round((float)$pcount / $wbf * 8000) : 0;   // guarded division, whole-number density
        }
        
        /* if ($density=='' && $microscopy !='Nmps') {
            $density = $pcount/$wbcount*8000;
        } */
        $lnk = "blinded.php?action=2&id=$biid";

        if($action == 'update'){
            if ($microscopy =='Nmps') {            
                $pcount = $wbcount = $density = 'NULL';
            }
            $uptqry = "UPDATE blinded_results SET slide_no = '$bsno', collection_date = '$doc', lab_microscopy = '$microscopy', lab_stage = '$pstage', lab_parasite_count = $pcount, lab_wbc_count = $wbcount, lab_density = $density WHERE blinded_id=$biid AND result_id = $rid";
            
            if($conn->query($uptqry)){
                $check = true;  
            }   else $check = false;
        }
        else{
            $stmt = $conn->prepare("INSERT INTO blinded_results (blinded_id, slide_no, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssiii", $biid, $bsno, $doc, $microscopy, $pstage, $pcount, $wbcount, $density);
            if($stmt->execute()){   
                $stmt->close(); $check = true; 
            } else $check = false;            
        }
    }

    /* District Results — RCDC multi-slide save (roles 1/2 only).
       All rows are validated, then updated (result_id > 0) or inserted
       (result_id = 0) inside ONE transaction. Only district-owned columns are
       written, total_slides is read from the database (never the browser), and
       the record can never grow beyond total_slides. District users keep their
       existing per-row 'labresults' path. */
    if ($submit=='labresultsBulk') {
        $biid  = isset($_POST['biid']) ? (int)$_POST['biid'] : 0;
        $lnk   = "blinded.php?action=2&id=$biid";
        $check = false;
        $bulkRole = isset($_SESSION['roleID']) ? (int)$_SESSION['roleID'] : 0;
        $rows = json_decode(isset($_POST['slides']) ? (string)$_POST['slides'] : '', true);

        // Allowed values come from the existing option helpers (no second list).
        preg_match_all("/value\s*='([^']*)'/", microscopyResults(), $micM);
        preg_match_all("/value\s*='([^']*)'/", parasiteStages(), $stgM);
        $micAllowed = $micM[1];
        $stgAllowed = $stgM[1];

        if ($bulkRole !== 1 && $bulkRole !== 2) {
            $errmsg = 'You are not authorised to save District Results.';
        } else if ($biid <= 0 || !is_array($rows) || count($rows) === 0) {
            $errmsg = 'No slide data was submitted.';
        } else {
            $conn->begin_transaction();
            try {
                // Lock the parent row so concurrent / repeated saves are serialised.
                $st = $conn->prepare("SELECT total_slides, total_microscopy, receive_date FROM blinded_informations WHERE blinded_id = ? FOR UPDATE");
                $st->bind_param('i', $biid);
                $st->execute();
                $info = $st->get_result()->fetch_row();
                $st->close();
                if (!$info) { throw new RuntimeException('Record not found.'); }
                $total    = (int)$info[0];
                $received = ($info[2] !== null && $info[2] !== '');
                if ((int)$info[1] === 0 || $total <= 0) {
                    throw new RuntimeException('This record has no MP slides to enter.');
                }

                // Existing slide rows of this record.
                $ex = array();
                $st = $conn->prepare("SELECT result_id, slide_no, c1_microscopy FROM blinded_results WHERE blinded_id = ?");
                $st->bind_param('i', $biid);
                $st->execute();
                $res = $st->get_result();
                while ($e = $res->fetch_row()) { $ex[(int)$e[0]] = $e; }
                $st->close();

                $clean = array(); $seen = array(); $sentIds = array(); $newCount = 0;
                foreach ($rows as $n => $r) {
                    $lbl   = 'Slide ' . ($n + 1);
                    $rid   = isset($r['rid']) ? (int)$r['rid'] : 0;
                    $bsno  = trim((string)(isset($r['bsno']) ? $r['bsno'] : ''));
                    $doc   = trim((string)(isset($r['doc']) ? $r['doc'] : ''));
                    $mic   = trim((string)(isset($r['microscopy']) ? $r['microscopy'] : ''));
                    $stage = trim((string)(isset($r['pstage']) ? $r['pstage'] : ''));
                    $pc    = trim((string)(isset($r['pcount']) ? $r['pcount'] : ''));
                    $wb    = trim((string)(isset($r['wbcount']) ? $r['wbcount'] : ''));

                    if ($rid > 0) {
                        if (!isset($ex[$rid])) { throw new RuntimeException("$lbl does not belong to this record."); }
                        if ($ex[$rid][2] !== null && $ex[$rid][2] !== '') {
                            throw new RuntimeException("$lbl already has Controller 1 results and is locked.");
                        }
                        if (isset($sentIds[$rid])) { throw new RuntimeException("$lbl was submitted twice."); }
                        $sentIds[$rid] = true;
                    } else {
                        if ($received) { throw new RuntimeException('New slides cannot be added after the shipment has been received.'); }
                        $newCount++;
                    }
                    if ($bsno === '') { throw new RuntimeException("$lbl: B/S No. is required."); }
                    $key = strtolower($bsno);
                    if (isset($seen[$key])) { throw new RuntimeException("B/S No. $bsno is entered more than once."); }
                    $seen[$key] = true;
                    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $doc, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
                        throw new RuntimeException("$lbl: a valid Date of Collection (YYYY-MM-DD) is required.");
                    }
                    if (!in_array($mic, $micAllowed, true)) { throw new RuntimeException("$lbl: select a valid Microscopy Result."); }

                    if ($mic === 'Nmps') {
                        $stage = null; $pcV = null; $wbV = null; $den = null;
                    } else {
                        if (!in_array($stage, $stgAllowed, true)) { throw new RuntimeException("$lbl: Parasite Stage is required for a positive slide."); }
                        if ($pc === '' || !is_numeric($pc) || (float)$pc < 0) { throw new RuntimeException("$lbl: Parasite Count is required for a positive slide."); }
                        if ($wb === '' || !is_numeric($wb) || (float)$wb < 0) { throw new RuntimeException("$lbl: WBC Count is required for a positive slide."); }
                        $pcV = (int)$pc; $wbV = (int)$wb;
                        $wbf = (float)$wb;
                        $den = ($wbf != 0) ? round((float)$pc / $wbf * 8000) : 0;   // same guarded formula as 'labresults'
                    }
                    $clean[] = array($rid, $bsno, $doc, $mic, $stage, $pcV, $wbV, $den);
                }

                // B/S No. must not collide with another existing slide of this record.
                foreach ($ex as $eid => $e) {
                    if (!isset($sentIds[$eid]) && isset($seen[strtolower(trim((string)$e[1]))])) {
                        throw new RuntimeException('B/S No. ' . trim((string)$e[1]) . ' already exists for this record.');
                    }
                }
                // The record can never grow beyond the selected number of slides.
                if ($newCount > 0 && (count($ex) + $newCount) > $total) {
                    throw new RuntimeException("Only $total slide(s) were selected for this record.");
                }

                $up = $conn->prepare("UPDATE blinded_results SET slide_no = ?, collection_date = ?, lab_microscopy = ?, lab_stage = ?, lab_parasite_count = ?, lab_wbc_count = ?, lab_density = ? WHERE result_id = ? AND blinded_id = ?");
                $in = $conn->prepare("INSERT INTO blinded_results (blinded_id, slide_no, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($clean as $c) {
                    list($rid, $bsno, $doc, $mic, $stage, $pcV, $wbV, $den) = $c;
                    if ($rid > 0) {
                        $up->bind_param('ssssiidii', $bsno, $doc, $mic, $stage, $pcV, $wbV, $den, $rid, $biid);
                        $up->execute();
                    } else {
                        $in->bind_param('issssiid', $biid, $bsno, $doc, $mic, $stage, $pcV, $wbV, $den);
                        $in->execute();
                    }
                }
                $up->close();
                $in->close();

                $conn->commit();
                $check = true;
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $errmsg = 'A database error occurred. Nothing was saved.';
            } catch (Exception $e) {
                $conn->rollback();
                $errmsg = $e->getMessage();
            }
        }
        // The message is echoed inside a JS string by the shared renderer below.
        $errmsg = str_replace(array("'", '"', '<', '>', "\\"), '', $errmsg);
    }

    if ($submit=='bsStatus') {
        // Shipment Receive is an RCDC/Admin stage (roleID 1/2) — enforce server-side.
        if (!isset($_SESSION["roleID"]) || ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2)) {
            echo("<input type='hidden' name='lnk' id='lnk' value='blinded.php?action=2&id=".(isset($_POST['biid']) ? (int)$_POST['biid'] : 0)."'/>");
            ?>
            <script>
                $(function () {
                    Swal.fire({ icon:'error', confirmButtonText:'Close', showCloseButton:true,
                        text:'You are not authorised to record shipment receipt.' })
                    .then(function(){ window.location = $('#lnk').val(); });
                });
            </script>
            <?php
            exit;
        }
        $biid = $conn -> real_escape_string($_POST['biid']);
        $dor = $conn -> real_escape_string($_POST['dorec']);
        $myqry = ($conn->query("SELECT month, year, shipment_date FROM blinded_informations WHERE blinded_id=".$biid));
        list($months, $yrs, $shdate) = $myqry->fetch_row();

        if($shdate <= $dor){
            if ($dor<=fifteeenthOfNext($month="$months", $yr = "$yrs")){ $timeliness = "ONTIME";}
            else {$timeliness = "LATE";}

            $resultID =  $_POST["resultID"];
            $slideNo = $_POST["slideNo"];
            $docc = $_POST["docc"];
            $status = $_POST["status"];
            $remarks = $_POST["remarks"];

            $uptqry = "UPDATE blinded_informations SET receive_date = '$dor', timeliness = '$timeliness' WHERE blinded_id='$biid'";
            if($conn->query($uptqry)) {
                for($i = 0; $i < count($resultID); $i++){
                    $updateRes = "UPDATE blinded_results SET slide_no='$slideNo[$i]', slide_status='$status[$i]', collection_date='$docc[$i]', remarks='$remarks[$i]' WHERE result_id='$resultID[$i]' AND blinded_id='$biid'";

                    if($conn->query($updateRes)) {
                        $check = true;
                    }   else $check = false;
                }
                $check = true;  
            }
        }else {$check = false; $errmsg = "Date of Shipment is greater than date of receive";}

        $lnk = "blinded.php?action=2&id=$biid";
    }
    // size_thick_smear, size_thin_smear, shape_thin_smear, smear_spacing, eveness_thin_smear, blood_film, smear_intact, slide_labeling, background, stain_blood_element, cleanliness, stain_deposit
    /* Controller 1 manual save.
       Excel import remains the bulk entry path; this handler lets a controller
       review and correct the imported values for a single slide before final
       submission. Density and the Controller 2 seeding rule are identical to
       the importer (shared helpers in blinded-functions.php). */
    if ($submit=='c1Resulrs') {
        // Controller 1 is an RCDC/Admin stage. The pane is not rendered for
        // facility users, but the UI hiding it is not a control on its own —
        // reject the write server-side too.
        if (!isset($_SESSION["roleID"]) || ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2)) {
            echo("<input type='hidden' name='lnk' id='lnk' value='blinded.php?action=2&id=".(isset($_POST['biid']) ? (int)$_POST['biid'] : 0)."'/>");
            ?>
            <script>
                $(function () {
                    Swal.fire({ icon:'error', confirmButtonText:'Close', showCloseButton:true,
                        text:'You are not authorised to update Controller 1 results.' })
                    .then(function(){ window.location = $('#lnk').val(); });
                });
            </script>
            <?php
            exit;
        }
        // Controller 1 eligibility: the slide must belong to this record AND have been
        // received Intact. Checked here, not just in the UI, so a tampered rid for a
        // Broken / Missing / not-received slide is rejected before any write.
        $c1Bid = isset($_POST['biid']) ? (int)$_POST['biid'] : 0;
        $c1Rid = isset($_POST['rid']) ? (int)$_POST['rid'] : 0;
        $c1Chk = $conn->prepare("SELECT slide_status FROM blinded_results WHERE result_id = ? AND blinded_id = ? LIMIT 1");
        $c1Chk->bind_param('ii', $c1Rid, $c1Bid);
        $c1Chk->execute();
        $c1Row = $c1Chk->get_result()->fetch_row();
        $c1Chk->close();
        if (!$c1Row || $c1Row[0] !== 'Intact') {
            echo("<input type='hidden' name='lnk' id='lnk' value='blinded.php?action=2&id=".$c1Bid."'/>");
            ?>
            <script>
                $(function () {
                    Swal.fire({ icon:'error', confirmButtonText:'Close', showCloseButton:true,
                        text:'Submission Failed!! Only Intact slides can be processed in Controller 1.' })
                    .then(function(){ window.location = $('#lnk').val(); });
                });
            </script>
            <?php
            exit;
        }
        // Counts for a positive result must be non-negative numbers when present
        // (density is always recalculated below from these values, never trusted
        // from the browser).
        $c1Bad = '';
        if ((isset($_POST['microscopyc']) ? $_POST['microscopyc'] : '') !== 'Nmps') {
            foreach (array('pcountc' => 'Parasite Count', 'wbcountc' => 'WBC Count') as $c1f => $c1lbl) {
                $c1v = isset($_POST[$c1f]) ? trim((string)$_POST[$c1f]) : '';
                if ($c1v !== '' && (!is_numeric($c1v) || (float)$c1v < 0)) { $c1Bad = $c1lbl; break; }
            }
        }
        if ($c1Bad !== '') {
            echo("<input type='hidden' name='lnk' id='lnk' value='blinded.php?action=2&id=".$c1Bid."'/>");
            ?>
            <script>
                $(function () {
                    Swal.fire({ icon:'error', confirmButtonText:'Close', showCloseButton:true,
                        text:'Submission Failed!! <?=$c1Bad;?> must be a number of 0 or more.' })
                    .then(function(){ window.location = $('#lnk').val(); });
                });
            </script>
            <?php
            exit;
        }
        $biid = $conn -> real_escape_string($_POST['biid']);
        $rid = $conn -> real_escape_string($_POST['rid']);
        $drechk = $conn -> real_escape_string($_POST['drechk']);
        $microscopyc = $conn -> real_escape_string($_POST['microscopyc']);
        $rawStageC = isset($_POST['c1_parasite_stage']) && trim($_POST['c1_parasite_stage']) !== ''
                 ? $_POST['c1_parasite_stage']
                 : (isset($_POST['pstagec']) ? $_POST['pstagec'] : '');
        if (is_array($rawStageC)) { $rawStageC = reset($rawStageC); }
        $rawStageC = trim((string)$rawStageC);
        $pcountc = $conn -> real_escape_string($_POST['pcountc']);
        $wbcountc = $conn -> real_escape_string($_POST['wbcountc']);
        $densityc = $conn -> real_escape_string($_POST['densityc']);

        $size_thick_smear = $conn -> real_escape_string($_POST['size_thick_smear']);
        $size_thin_smear  = $conn -> real_escape_string($_POST['size_thin_smear']);
        $shape_thin_smear  = $conn -> real_escape_string($_POST['shape_thin_smear']);
        $smear_spacing = $conn -> real_escape_string($_POST['smear_spacing']);
        $eveness_thin_smear = $conn -> real_escape_string($_POST['eveness_thin_smear']);
        $blood_film = $conn -> real_escape_string($_POST['blood_film']); 
        $smear_intact = $conn -> real_escape_string($_POST['smear_intact']);
        $slide_labeling = $conn -> real_escape_string($_POST['slide_labeling']);
        $background = $conn -> real_escape_string($_POST['background']);
        $stain_blood_element = $conn -> real_escape_string($_POST['stain_blood_element']);
        $cleanliness = $conn -> real_escape_string($_POST['cleanliness']);
        $stain_deposit = $conn -> real_escape_string($_POST['stain_deposit']);
        // Slide Received Date was removed from the Controller 1 form. The column is
        // deliberately left out of the UPDATE below so historical values are preserved.

        if ($microscopyc == 'Nmps') {
            $pstagec = null;
            $pcountc = $wbcountc = $densityc = 'NULL';
        }else{
            $pstagec = ($rawStageC !== '') ? $conn->real_escape_string($rawStageC) : null;
            $densityc = calcDensity($pcountc, $wbcountc);
            $pcountc  = sqlNum($pcountc);
            $wbcountc = sqlNum($wbcountc);
        }

        $pstageSql = ($pstagec === null || $pstagec === '') ? "NULL" : "'$pstagec'";
        $lnk = "blinded.php?action=2&id=$biid";

        $uptqry = "UPDATE blinded_results SET c1_chkdate = '$drechk', c1_microscopy = '$microscopyc', c1_stage = $pstageSql, c1_parasite_count = $pcountc, c1_wbc_count = $wbcountc, c1_density = $densityc, size_thick_smear = '$size_thick_smear', size_thin_smear = '$size_thin_smear', shape_thin_smear = '$shape_thin_smear', smear_spacing = '$smear_spacing', eveness_thin_smear = '$eveness_thin_smear', blood_film = '$blood_film', smear_intact = '$smear_intact', slide_labeling = '$slide_labeling', background = '$background', stain_blood_element = '$stain_blood_element', cleanliness = '$cleanliness', stain_deposit = '$stain_deposit' WHERE blinded_id=$biid AND result_id = $rid";
        if($conn->query($uptqry)) {
            $check = true;  
        } else $check = false;

        //pull the result and update for controller 2
        $labqry = ($conn->query("SELECT lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density, c2_microscopy FROM blinded_results WHERE blinded_id=$biid AND result_id = $rid"));
        $labqry = $labqry->fetch_row();
        //Insert the value for C2 only if the result for C2 is not entered.
        if ($labqry[5]==null) {
            $c2microscpy = $c2pstage = NULL;
            $c2pcountc = $c2wbcountc = $c2densityc = 'NULL';

            if ($microscopyc == $labqry[0]) {$c2microscpy = $microscopyc;}
            if ($pstagec !== null && $pstagec !== '' && $pstagec == $labqry[1]) {$c2pstage = $pstagec;}
            if ($pcountc == $labqry[2]) {$c2pcountc = $pcountc;}
            if ($wbcountc == $labqry[3]) {$c2wbcountc = $wbcountc;}
            if ($densityc == $labqry[4]) {$c2densityc = $densityc;}

            $c2micSql = ($c2microscpy === null || $c2microscpy === '') ? "NULL" : "'" . $conn->real_escape_string($c2microscpy) . "'";
            $c2stageSql = ($c2pstage === null || $c2pstage === '') ? "NULL" : "'" . $conn->real_escape_string($c2pstage) . "'";

            $conn->query("UPDATE blinded_results SET c2_microscopy = $c2micSql, c2_stage = $c2stageSql, c2_parasite_count = $c2pcountc, c2_wbc_count = $c2wbcountc, c2_density = $c2densityc  WHERE blinded_id=$biid AND result_id = $rid");
        }
    }

    if ($submit=='controller2') {
        // Controller 2 is an RCDC/Admin stage (roleID 1/2), the same set that owns
        // Shipment Receive, Controller 1, Feedback and Actions. It was Administrator
        // only, which left RCDC Labs unable to finish the workflow they run.
        // UI hiding is not a control, so the write is rejected server-side too.
        if (!isset($_SESSION["roleID"]) || ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2)) {
            echo("<input type='hidden' name='lnk' id='lnk' value='blinded.php?action=2&id=".(isset($_POST['biid']) ? (int)$_POST['biid'] : 0)."'/>");
            ?>
            <script>
                $(function () {
                    Swal.fire({ icon:'error', confirmButtonText:'Close', showCloseButton:true,
                        text:'You are not authorised to submit Controller 2 results.' })
                    .then(function(){ window.location = $('#lnk').val(); });
                });
            </script>
            <?php
            exit;
        }
        $biid = $conn -> real_escape_string($_POST['biid']);

        $resultID =  $_POST["resultID"];
        $microscopy = $_POST["microscopy"];
        $stages = isset($_POST['c2_parasite_stage']) && is_array($_POST['c2_parasite_stage'])
                ? $_POST['c2_parasite_stage']
                : (isset($_POST['stages']) && is_array($_POST['stages']) ? $_POST['stages'] : []);
        $pcount = $_POST["pcount"];
        $wbcount = $_POST["wbcount"];
        $density = $_POST["density"];

        $lnk = "blinded.php?action=2&id=$biid";

        $discrepancyWarnings = array();   // slides where c1/c2 density variation > 20% (advisory only)
        for($i = 0; $i < count($resultID); $i++){
            $curStage = isset($stages[$i]) ? trim((string)$stages[$i]) : '';
            if ($microscopy[$i] == 'Nmps') {
                $curStage = null;
                $pcount[$i] = $wbcount[$i] = $density[$i] = 'NULL';
            }else {
                $density[$i] = calcDensity($pcount[$i], $wbcount[$i]);
                $pcount[$i]  = sqlNum($pcount[$i]);
                $wbcount[$i] = sqlNum($wbcount[$i]);
            }

            $stageSql = ($curStage === null || $curStage === '') ? "NULL" : "'" . $conn->real_escape_string($curStage) . "'";
            $micSql = $conn->real_escape_string($microscopy[$i]);

            $updatec2 = "UPDATE blinded_results SET c2_microscopy = '$micSql', c2_stage = $stageSql, c2_parasite_count = $pcount[$i], c2_wbc_count = $wbcount[$i], c2_density = $density[$i]  WHERE blinded_id=$biid AND result_id='".(int)$resultID[$i]."'";

            if($conn->query($updatec2)) {
                $check = true;
            }   else $check = false;

            //select c1_density from blinded_results and compare density range of c_within 20% variation
            // if c1_density not within range of c2_density, flag error. else find the mean density and add min and max density range.
            $chkdataqry = ($conn->query("SELECT result_id, slide_no, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density, c1_microscopy, c1_stage, c1_parasite_count, c1_wbc_count, c1_density, c2_microscopy, c2_stage, c2_parasite_count, c2_wbc_count, c2_density, min_range, max_range FROM blinded_results WHERE result_id = " . (int)$resultID[$i]));
            list($result_id, $slide_no, $lab_microscopy, $lab_stage, $lab_parasite_count, $lab_wbc_count, $lab_density, $c1_microscopy, $c1_stage, $c1_parasite_count, $c1_wbc_count, $c1_density, $c2_microscopy, $c2_stage, $c2_parasite_count, $c2_wbc_count, $c2_density, $min_range, $max_range) = $chkdataqry->fetch_row();

            $min = $max = 'NULL';
            if($c2_microscopy != "Nmps" || $c1_microscopy !=  "Nmps" ) {
                $mean_density = ($c1_density+$c2_density)/2;
                $diff_density = abs($c1_density - $c2_density);
                if ($diff_density!=0) {
                    $discrepency = ($diff_density/$mean_density)*100;
                }else {
                    $discrepency = 0;
                }

                if ($discrepency>20) {
                    $discrepancyWarnings[] = $slide_no;
                    $refDen = ($c2_density > 0) ? $c2_density : (($c1_density > 0) ? $c1_density : $mean_density);
                    if ($refDen > 0) {
                        $min = (int)round($refDen * 0.75);
                        $max = (int)round($refDen * 1.25);
                    }
                }else{
                    $min = (int)round($mean_density - ($mean_density/2));
                    $max = (int)round($mean_density + ($mean_density/2));
                }
            }

            $conn->query("UPDATE blinded_results SET min_range=$min, max_range=$max  WHERE blinded_id=$biid AND result_id='".(int)$resultID[$i]."'");

            //call function to generate flags
			$flag_array=generateFlag($conn, $result_id, $lab_microscopy,  $lab_stage, $lab_parasite_count, $lab_wbc_count, $lab_density);

            $conn->query("UPDATE blinded_results SET positivity_flag='$flag_array[1]', species_flag=$flag_array[2], stages_flag=$flag_array[3], density_flag=$flag_array[4]  WHERE blinded_id=$biid AND result_id='$resultID[$i]'");

        }

        //call function to calculate score
		$score_array = calculateScore($conn, $biid);
        // check if data present in the blinded_feedback table. if true->uodate, else insert
        $chkfeedback = ($conn->query("SELECT feedback_id FROM blinded_feedback WHERE blinded_id = $biid"));
        $chkfeedback = $chkfeedback->num_rows;
        if ($chkfeedback>0) {
            //Update
            $conn->query("UPDATE blinded_feedback SET sensitivity=$score_array[0], specificity=$score_array[1], malaria_infection=$score_array[2], parasite_species=$score_array[3], parasite_stage=$score_array[4], parasite_density=$score_array[5], film_quality=$score_array[6], stain_quality=$score_array[7], average=$score_array[8], achievement='$score_array[9]' WHERE blinded_id = $biid");
        }else {
            //Insert
            $stmt = $conn->prepare("INSERT INTO blinded_feedback(blinded_id, sensitivity, specificity, malaria_infection, parasite_species, parasite_stage, parasite_density, film_quality, stain_quality, average, achievement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iddddddddds", $biid, $score_array[0], $score_array[1], $score_array[2], $score_array[3], $score_array[4], $score_array[5], $score_array[6], $score_array[7], $score_array[8], $score_array[9]);
            if($stmt->execute()){
                $stmt->close(); $check = true;
            } else $check = false;
        }

        // Advisory (non-blocking): report any >20% density-variation slides on the
        // SUCCESS banner. The results were saved; only these slides' ranges are unset.
        if (!empty($discrepancyWarnings)) {
            $errmsg = "Note: density variation exceeded 20% for slide(s): ".implode(', ', $discrepancyWarnings)
                    . " — the density range was not set for these slides; all other results were saved.";
        }

    }

    /* Findings are multi-select. Read the posted ids and return them de-duplicated
       and in order. The form posts finding_master_id[]; finding_ids[] is accepted
       under the same rules so either name works, and a single scalar from an older
       client is still accepted. */
    if (!function_exists('blPostedFindingIds')) {
        function blPostedFindingIds(){
            $raw = array();
            foreach (array('finding_master_id', 'finding_ids') as $field) {
                if (!isset($_POST[$field])) { continue; }
                $one = $_POST[$field];
                if (!is_array($one)) { $one = ($one === '' || $one === null) ? array() : array($one); }
                $raw = array_merge($raw, $one);
            }
            $ids = array();
            foreach ($raw as $v) {
                $v = (int)$v;
                if ($v > 0 && !in_array($v, $ids, true)) { $ids[] = $v; }
            }
            return $ids;
        }
    }
    /* Replace this feedback's finding links. finding_master_id keeps the first
       selection so existing reports/queries that read that column still work. */
    if (!function_exists('blSaveFindingLinks')) {
        function blSaveFindingLinks($conn, $biid, array $ids){
            $fid = $conn->query("SELECT feedback_id FROM blinded_feedback WHERE blinded_id = ".(int)$biid);
            $fid = $fid ? $fid->fetch_row() : null;
            if (!$fid) { return; }
            $feedbackId = (int)$fid[0];
            $conn->query("DELETE FROM blinded_feedback_findings WHERE feedback_id = $feedbackId");
            if (!count($ids)) { return; }
            $st = $conn->prepare("INSERT IGNORE INTO blinded_feedback_findings (feedback_id, finding_id, sort_order) VALUES (?,?,?)");
            foreach ($ids as $i => $one) {
                // Ignore an id that is not a real, active finding.
                $ok = $conn->query("SELECT finding_id FROM blinded_finding_master WHERE finding_id = ".(int)$one);
                if (!$ok || !$ok->num_rows) { continue; }
                $st->bind_param('iii', $feedbackId, $one, $i);
                $st->execute();
            }
            $st->close();
        }
    }

    // Combined Feedback & Actions save: findings + recommendation + action from the
    // single unified form. The legacy 'findings' / 'actions' handlers below are kept
    // for backward compatibility.
    if ($submit=='feedback') {
        // Feedback & Actions is an RCDC/Admin stage (roleID 1/2) — enforce server-side.
        if (!isset($_SESSION["roleID"]) || ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2)) {
            echo("<input type='hidden' name='lnk' id='lnk' value='blinded.php?action=2&id=".(isset($_POST['biid']) ? (int)$_POST['biid'] : 0)."'/>");
            ?>
            <script>
                $(function () {
                    Swal.fire({ icon:'error', confirmButtonText:'Close', showCloseButton:true,
                        text:'You are not authorised to submit feedback.' })
                    .then(function(){ window.location = $('#lnk').val(); });
                });
            </script>
            <?php
            exit;
        }
        $biid = $conn -> real_escape_string($_POST['biid']);
        $finding = $conn -> real_escape_string($_POST['finding']);
        $recommendation = $conn -> real_escape_string($_POST['recommendation']);
        $actions = $conn -> real_escape_string($_POST['actions']);
        $findingIds = blPostedFindingIds();
        $findingMasterId = count($findingIds) ? $findingIds[0] : 'NULL';

        $lnk = "blinded.php?action=2&id=$biid";

        $updaterec = "UPDATE blinded_feedback SET blinded_finding='$finding', blinded_recommendation='$recommendation', blinded_action='$actions', finding_master_id=$findingMasterId WHERE blinded_id = $biid";
        if($conn->query($updaterec)){
            blSaveFindingLinks($conn, $biid, $findingIds);
            $check = true;

        }   else $check = false;
    }

    if ($submit=='findings') {
        $biid = $conn -> real_escape_string($_POST['biid']);
        $finding = $conn -> real_escape_string($_POST['finding']);
        $recommendation = $conn -> real_escape_string($_POST['recommendation']);
        $findingIds = blPostedFindingIds();
        $findingMasterId = count($findingIds) ? $findingIds[0] : 'NULL';

        $lnk = "blinded.php?action=2&id=$biid";

        $updaterec = "UPDATE blinded_feedback SET  blinded_finding='$finding', blinded_recommendation='$recommendation', finding_master_id=$findingMasterId WHERE blinded_id = $biid";
        if($conn->query($updaterec)){
            blSaveFindingLinks($conn, $biid, $findingIds);
            $check = true;  
        }   else $check = false;
    }

    if ($submit=='actions') {
        // Action stage is an RCDC/Admin stage (roleID 1/2) — enforce server-side.
        if (!isset($_SESSION["roleID"]) || ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2)) {
            echo("<input type='hidden' name='lnk' id='lnk' value='blinded.php?action=2&id=".(isset($_POST['biid']) ? (int)$_POST['biid'] : 0)."'/>");
            ?>
            <script>
                $(function () {
                    Swal.fire({ icon:'error', confirmButtonText:'Close', showCloseButton:true,
                        text:'You are not authorised to record actions.' })
                    .then(function(){ window.location = $('#lnk').val(); });
                });
            </script>
            <?php
            exit;
        }
        $biid = $conn -> real_escape_string($_POST['biid']);
        $actions = $conn -> real_escape_string($_POST['actions']);

        $lnk = "blinded.php?action=2&id=$biid";

        $updaterec = "UPDATE blinded_feedback SET  blinded_action='$actions' WHERE blinded_id = $biid";
        if($conn->query($updaterec)){
            $check = true;

        }   else $check = false;
    }


    /* ---- Workflow e-mail notification (after the write, never before) --------
       Runs only when the stage actually succeeded. mn_notify() writes the queue row
       and attempts delivery; it never throws, so nothing here can affect the save or
       the response above. Repeat submissions are absorbed by the unique key on
       (event, record_id, recipient). */
    if ($check && isset($biid) && (int)$biid > 0) {
        require_once("../notifications/mn-notify.php");
        $nId = (int)$biid;
        if ($submit == 'blindedInfo' && $action == 1) {
            mn_notify($conn, 'BLINDED_SUBMITTED', $nId);                  // district -> RCDC receipt
        } else if ($submit == 'bsStatus') {
            mn_notify($conn, 'BLINDED_RECEIVED', $nId);                   // receipt -> Controller 1
        } else if ($submit == 'c1Resulrs') {
            // Only when the LAST intact slide has been rechecked, so a 10-slide
            // record produces one notification and not ten.
            $nQ = $conn->query("SELECT COUNT(*), SUM(c1_microscopy IS NOT NULL AND c1_microscopy <> '')
                                  FROM blinded_results WHERE blinded_id = $nId AND slide_status = 'Intact'");
            $nR = $nQ ? $nQ->fetch_row() : null;
            if ($nR && (int)$nR[0] > 0 && (int)$nR[0] === (int)$nR[1]) {
                mn_notify($conn, 'BLINDED_C1_COMPLETED', $nId);           // -> Controller 2 (Administrator)
            }
        } else if ($submit == 'controller2') {
            mn_notify($conn, 'BLINDED_C2_COMPLETED', $nId);               // -> findings/recommendation
        } else if ($submit == 'findings' || $submit == 'feedback') {
            if (isset($finding) && trim(strip_tags($finding)) !== '') {
                mn_notify($conn, 'BLINDED_FEEDBACK_COMPLETED', $nId);     // -> action taken
            }
            if (isset($actions) && trim(strip_tags($actions)) !== '') {
                if (mn_notify($conn, 'BLINDED_ACTION_COMPLETED', $nId)) { // -> facility (report available)
                    $conn->query("UPDATE blinded_feedback SET action_mail_sent = 1 WHERE blinded_id = $nId");
                }
            }
        } else if ($submit == 'actions') {
            if (isset($actions) && trim(strip_tags($actions)) !== '') {
                if (mn_notify($conn, 'BLINDED_ACTION_COMPLETED', $nId)) {
                    $conn->query("UPDATE blinded_feedback SET action_mail_sent = 1 WHERE blinded_id = $nId");
                }
            }
        }
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
				text: 'Submitted Successfully!! <?=$errmsg;?>',
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
				text: 'Submission Failed!! <?=$errmsg;?>',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>
