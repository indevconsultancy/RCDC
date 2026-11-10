<?php
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	$mpAjax = !empty($_REQUEST['ajax']);
	if(!$mpAjax){ include_once("../includes/css-jquery.php"); }   // AJAX responses must be pure JSON

	include_once("../config/mpq_validate.php");
	// RBAC: only Administrator + Lab may write; BFDA/DRA (acl 7) is read-only
	// Effective-role RBAC: Administrator + NDTL may create/update a sample registration.
	// NMRL (access level 2, category 19) and BFDA are denied here as well as in the UI, so the
	// previous access-level-only check can no longer be used to bypass the page restriction.
	mpq_require('registration', (isset($_REQUEST["regid"]) && $_REQUEST["regid"] != "") ? 'edit' : 'add', $mpAjax);
	mpq_csrf_check($mpAjax);

	// Determine mode from the form itself (never from the stale session flag)
	$par = (isset($_REQUEST["regid"]) && $_REQUEST["regid"] != "") ? 2 : 1;
	$_SESSION['par'] = $par;
	$uid = $_SESSION['users'];

	// --- Server-side required-field validation ---
	// `sample_type` (Oct 2026) is enforced here on NEW records only (not on edit) so
	// historic registrations can still be updated without the new field. On INSERT the
	// client dropdown offers Surveillance | Routine Compliance; the server also allowlists
	// those two values further down before writing.
	// Oct 2026 — Test Category ('cat') is no longer part of the Add/Edit UI;
	//            it is therefore not expected in POST and not required-guarded.
	//            The DB column testCategory is retained for historic records.
	$mpReg = array(
		'lab'=>'Lab','dor'=>'Registration Date','site'=>'Sampling Site',
		'sid'=>'Sample ID','sam'=>'EDL / Sample','pcl'=>'Pharma Claim','for'=>'Formulation',
		'qty'=>'Quantity','batch'=>'Batch No','mfg'=>'Manufacturer','mah'=>'MAH',
		'cby'=>'Collected By','comp'=>'Composition','decp'=>'Description'
	);
	if((int)(isset($_REQUEST["regid"]) && $_REQUEST["regid"] != "" ? 2 : 1) === 1){
		$mpReg['sample_type'] = 'Sample Type';
	}
	if($par==2){ $mpReg['regid']='Record'; }
	mpq_guard($mpReg, $mpAjax);
	// Completed reports are read-only (server-side lock; see report_lock_lib.php).
	if($par==2){
		require_once(__DIR__."/report_lock_lib.php");
		mpqms_require_unlocked($connection, (int)$_REQUEST["regid"], $mpAjax);
	}

	// Dependent “Others” fields
	$mpOth = array();
	if(($_REQUEST['site'] ?? '')==999){ $mpOth['siteoth']='Other Site'; }
	if(($_REQUEST['sam']  ?? '')==999){ $mpOth['samoth']='Other Sample'; }
	if(($_REQUEST['pcl']  ?? '')=='Others'){ $mpOth['pcloth']='Other Pharma Claim'; }
	if(($_REQUEST['for']  ?? '')==99){ $mpOth['foroth']='Other Formulation'; }
	if(($_REQUEST['cby']  ?? '')==999){ $mpOth['cbyoth']='Other Collected By'; }
	if(!empty($mpOth)){ mpq_guard($mpOth, $mpAjax); }
	if(isset($_REQUEST['qty']) && trim($_REQUEST['qty'])!=='' && !is_numeric($_REQUEST['qty'])){
		mpq_fail('Quantity must be a number.', $mpAjax);
	}
	// Dates must be real calendar dates in yyyy-mm-dd. Without this the non-strict database
	// silently stores an invalid date as 0000-00-00 and the user is told the save succeeded.
	// Registration Date is mandatory; Mfg./Expiry/Sampling are optional but must be valid when given.
	$mpDates = array('dor'=>'Registration Date', 'dom'=>'Mfg. Date', 'doe'=>'Expiry Date', 'sampling_date'=>'Sampling Date');
	foreach($mpDates as $mpF => $mpL){
		$mpV = trim((string)($_REQUEST[$mpF] ?? ''));
		if($mpV === ''){ continue; }
		$mpD = preg_match('/^\d{4}-\d{2}-\d{2}$/', $mpV) ? DateTime::createFromFormat('!Y-m-d', $mpV) : false;
		if(!$mpD || $mpD->format('Y-m-d') !== $mpV){
			mpq_fail($mpL.' "'.$mpV.'" is not a valid date. Please use the format yyyy-mm-dd. The record was NOT saved.', $mpAjax);
		}
	}

	/*
	 * Translate a database failure into a message the user can act on, while keeping the
	 * technical detail for support. The database session runs in STRICT mode (set below),
	 * so oversized or malformed values are rejected instead of being silently truncated.
	 */
	if(!function_exists('mpq_reg_friendly_error')){
		function mpq_reg_friendly_error($errno, $detail){
			$labels = array(
				'sampleID'=>'Sample ID','batchNo'=>'Batch No','labelClaim'=>'Label Claim Amount',
				'registrationDate'=>'Registration Date','mfgDate'=>'Mfg. Date','expDate'=>'Expiry Date',
				'siteOther'=>'Sampling Site (Others)','edlOther'=>'Sample (Others)','pharmaOther'=>'Pharma. Claim (Others)',
				'formulationOther'=>'Formulation (Others)','manufacturerOther'=>'Manufacturer (Others)',
				'mahOther'=>'MAH (Others)','collectOther'=>'Collected By (Others)','composition'=>'Composition',
				'description'=>'Description','remarks'=>'Remarks','sampleQty'=>'Quantity','pharmaClaim'=>'Pharma. Claim',
				'testCategory'=>'Test Category','siteID'=>'Sampling Site','collectID'=>'Collected By',
				'edlID'=>'Sample','formulationID'=>'Formulation','manufacturer'=>'Manufacturer','mahID'=>'MAH','labID'=>'Laboratory'
			);
			$field = '';
			// MariaDB writes either column 'name' or column `schema`.`table`.`name` — take the last identifier.
			if(preg_match("/column (?:'([A-Za-z_]+)'|(?:`[A-Za-z_]+`\\.)*`([A-Za-z_]+)`)/", (string)$detail, $m)){
				$col = (isset($m[1]) && $m[1] !== '') ? $m[1] : $m[2];
				$field = isset($labels[$col]) ? $labels[$col] : $col;
			}
			switch((int)$errno){
				case 1062: $msg = 'A record with the same key already exists (another registration may have been saved at the same moment). Please refresh the page and try again.'; break;
				case 1406: $msg = 'The value entered for '.($field !== '' ? $field : 'a field').' is too long for the database.'; break;
				case 1264: $msg = 'The value entered for '.($field !== '' ? $field : 'a field').' is out of range.'; break;
				case 1292: case 1366:
				           $msg = 'The value entered for '.($field !== '' ? $field : 'a field').' is not valid.'; break;
				case 1451: case 1452:
				           $msg = 'The record refers to master data that does not exist or is in use.'; break;
				default:   $msg = 'The database could not save the record.'; break;
			}
			return $msg.' The record was NOT saved.'.($detail !== '' ? ' [Details: '.$detail.']' : '');
		}
	}

	// --- Sanitize ---
	// Oct 2026 — Lab POST shape depends on the In/Ex-country widget:
	//   In-country -> numeric id selected from the master dropdown (unchanged).
	//   Ex-country -> free text (overseas lab name); resolve to a numeric id by
	//                 SELECTing (or auto-INSERTing) the matching master row with
	//                 inexcountry='Ex-country'. The stored registrationtable.labID
	//                 therefore stays a valid FK regardless of input shape.
	$labRaw   = trim((string)($_REQUEST["lab"] ?? ""));
	$inexRaw  = trim((string)($_REQUEST["inex"] ?? ""));
	if($labRaw !== '' && !ctype_digit($labRaw)){
		// Non-numeric lab -> treat as free-text name (Ex-country path).
		$inexForLab = ($inexRaw === 'In-country' || $inexRaw === 'Ex-country') ? $inexRaw : 'Ex-country';
		$labName    = substr($labRaw, 0, 150);
		$labNameEsc = mysqli_real_escape_string($connection, $labName);
		$inexEsc    = mysqli_real_escape_string($connection, $inexForLab);
		$found = @mysqli_query($connection,
			"SELECT id FROM msappellatelabtable WHERE name='$labNameEsc' AND inexcountry='$inexEsc' LIMIT 1");
		$labResolved = 0;
		if($found && ($r = mysqli_fetch_row($found))){ $labResolved = (int)$r[0]; }
		if($labResolved === 0){
			if(@mysqli_query($connection,
				"INSERT INTO msappellatelabtable (name, inexcountry, status) VALUES ('$labNameEsc','$inexEsc',1)")){
				$labResolved = (int)mysqli_insert_id($connection);
			}
		}
		$labRaw = ($labResolved > 0) ? (string)$labResolved : '';
	}
	$lab      = mysqli_real_escape_string($connection, $labRaw);
	$cat      = mysqli_real_escape_string($connection, (string)($_REQUEST["cat"] ?? ""));
	$dor      = mysqli_real_escape_string($connection, $_REQUEST["dor"]);
	$site     = mysqli_real_escape_string($connection, $_REQUEST["site"]);
	$siteoth  = ($site == 999) ? mysqli_real_escape_string($connection, $_REQUEST["siteoth"]) : "";
	$sid      = ucwords(mysqli_real_escape_string($connection, $_REQUEST["sid"]));
	$sam      = mysqli_real_escape_string($connection, $_REQUEST["sam"]);
	$samoth   = ($sam == 999) ? mysqli_real_escape_string($connection, $_REQUEST["samoth"]) : "";
	$pcl      = mysqli_real_escape_string($connection, $_REQUEST["pcl"]);
	$pcloth   = ($pcl == "Others") ? mysqli_real_escape_string($connection, $_REQUEST["pcloth"]) : "";
	$for      = mysqli_real_escape_string($connection, $_REQUEST["for"]);
	$foroth   = ($for == 99) ? mysqli_real_escape_string($connection, $_REQUEST["foroth"]) : "";
	$qty      = mysqli_real_escape_string($connection, $_REQUEST["qty"]);
	$batch    = mysqli_real_escape_string($connection, $_REQUEST["batch"]);
	$dom      = mysqli_real_escape_string($connection, $_REQUEST["dom"]); if($dom == "") $dom = "1900-01-01";
	$doe      = mysqli_real_escape_string($connection, $_REQUEST["doe"]); if($doe == "") $doe = "1900-01-01";
	$mfg      = ucwords(mysqli_real_escape_string($connection, $_REQUEST["mfg"]));
	$mfgoth   = ucwords(mysqli_real_escape_string($connection, $_REQUEST["mfgoth"]));
	$mah      = ucwords(mysqli_real_escape_string($connection, $_REQUEST["mah"]));
	$mahoth   = ucwords(mysqli_real_escape_string($connection, $_REQUEST["mahoth"]));
	$cby      = ucwords(mysqli_real_escape_string($connection, $_REQUEST["cby"]));
	$cbyoth   = ($cby == 999) ? mysqli_real_escape_string($connection, $_REQUEST["cbyoth"]) : "";
	$comp     = mysqli_real_escape_string($connection, $_REQUEST["comp"]);
	$decp     = mysqli_real_escape_string($connection, $_REQUEST["decp"]);
	$rmk      = mysqli_real_escape_string($connection, $_REQUEST["rmk"]);
	$labelclaim = mysqli_real_escape_string($connection, $_REQUEST["labelclaim"] ?? "");

	// Oct 2026 — Sample Type. Dropdown is DB-driven from mssampletypetable; the
	// server mirrors the master as the authoritative allowlist so a crafted POST
	// can never store an arbitrary string. Falls back to the Test-Category trio
	// if the master table isn't present yet (migration not run); the legacy
	// "Routine Compliance" value stays accepted so historic records can be
	// re-saved without silent data loss. Blank passes through as NULL.
	$sample_type_posted = (string)($_REQUEST["sample_type"] ?? "");
	$sample_type_allowed = array();
	try {
		$rs = @mysqli_query($connection,
			"SELECT name FROM mssampletypetable WHERE status=1 ORDER BY sort_order ASC, name ASC");
		if($rs){ while($r = mysqli_fetch_row($rs)){ $sample_type_allowed[] = (string)$r[0]; } }
	} catch(\Throwable $e){ /* table missing — fall back below */ }
	if(empty($sample_type_allowed)){
		$sample_type_allowed = array('Complain','Surveillance','Registration','Routine Compliance');
	}
	$sample_type = in_array($sample_type_posted, $sample_type_allowed, true)
		? mysqli_real_escape_string($connection, $sample_type_posted)
		: "";

	// Oct 2026 — Chain-of-custody fields (all optional; stored as NULL when blank so historic
	// rows can be edited without them). sampling_date defaults to the 1900-01-01 sentinel so a
	// positional VALUES clause always has something to send; a blank user input is preserved as
	// NULL via the inline NULLIF trick in the SQL below.
	$sampled_by          = mysqli_real_escape_string($connection, $_REQUEST["sampled_by"] ?? "");
	$sampling_org_posted = (string)($_REQUEST["sampling_organization"] ?? "");
	$sampling_org        = mysqli_real_escape_string($connection, $sampling_org_posted);
	$sampling_org_other  = ($sampling_org_posted === 'Other')
		? mysqli_real_escape_string($connection, $_REQUEST["sampling_organization_other"] ?? "")
		: "";
	$sampling_location   = mysqli_real_escape_string($connection, $_REQUEST["sampling_location"] ?? "");
	$sampling_date       = mysqli_real_escape_string($connection, $_REQUEST["sampling_date"] ?? "");
	$sent_by             = mysqli_real_escape_string($connection, $_REQUEST["sent_by"] ?? "");

	$now = date('Y-m-d H:i:s');
	$qry = "";

	if($par == 1){   // ===== NEW RECORD =====

		// FIX 1: never let MAX() return NULL
		list($regid) = mysqli_fetch_row(mysqli_query($connection,
			"SELECT COALESCE(MAX(registrationID),0)+1 FROM registrationtable"));

		// Explicit column list – safe against future column additions
		// NULLIF('',$x) stores blank strings as NULL so the new chain-of-custody columns do not
		// turn into empty strings in the database (keeps "no data" distinguishable from "''").
		$qry = "INSERT INTO registrationtable
			(registrationID, sampleID, labID, testCategory, sample_type, registrationDate,
			 siteID, siteOther, edlID, edlOther, pharmaClaim, pharmaOther,
			 formulationID, formulationOther, sampleQty, batchNo, mfgDate, expDate,
			 manufacturer, manufacturerOther, mahID, mahOther, collectID, collectOther,
			 composition, description, remarks, entryUser, entrytimestamp, labelClaim,
			 sampled_by, sampling_organization, sampling_organization_other,
			 sampling_location, sampling_date, sent_by)
			VALUES
			($regid, '$sid', '$lab', '$cat', NULLIF('$sample_type',''), '$dor',
			 '$site', '$siteoth', '$sam', '$samoth', '$pcl', '$pcloth',
			 '$for', '$foroth', $qty, '$batch', '$dom', '$doe',
			 '$mfg', '$mfgoth', '$mah', '$mahoth', '$cby', '$cbyoth',
			 '$comp', '$decp', '$rmk', '$uid', '$now', '$labelclaim',
			 NULLIF('$sampled_by',''), NULLIF('$sampling_org',''), NULLIF('$sampling_org_other',''),
			 NULLIF('$sampling_location',''), NULLIF('$sampling_date',''), NULLIF('$sent_by',''));";

		// ---------------------------------------------------------------------------------
		// Ex-country shortcut, now gated on the CREATOR'S ROLE as well as the laboratory.
		//
		// A sample sent to an ex-country laboratory is not received, accepted or approved
		// inside MPQMS, so creation used to mark it Accepted and every result row Approved.
		// That was decided by the Laboratory field alone, which let any user who may create a
		// registration hand themselves an acceptance and an approval they are not entitled to
		// perform — a Laboratory (NDTL) user creating an ex-country sample got ACP=Accepted and
		// APV=Approved without an Administrator ever approving anything.
		//
		// The shortcut is now applied only as far as the logged-in role is actually authorised,
		// using the same permission matrix the rest of the application uses:
		//     results.approve    -> Administrator only
		//     acceptance.submit  -> Administrator + NDTL laboratory
		// Anything the creator may not do is left for the normal workflow to do explicitly.
		// The in-country path (labID 1) is unchanged for every role.
		// ---------------------------------------------------------------------------------
		$mpExCountry  = ($lab != 1);
		$mpMayApprove = function_exists('mpq_can_do') ? mpq_can_do('results', 'approve')   : false;
		$mpMayAccept  = function_exists('mpq_can_do') ? mpq_can_do('acceptance', 'submit') : false;

		$mpAutoApprove = ($mpExCountry && $mpMayApprove);
		$mpAutoAccept  = ($mpExCountry && $mpMayAccept);

		$pending = $mpAutoApprove ? "Approved"   : "Pending";
		$appby   = $mpAutoApprove ? "excountry"  : "";

		// FIX 2: named columns + leave signature columns at their DEFAULT (NULL)
		if(!empty($_POST['parid'])){
			foreach($_POST['parid'] as $edlid){
				$edlid = mysqli_real_escape_string($connection, $edlid);
				$qry .= "INSERT INTO labresulttable
					(parameterID, registrationID, repetition, testDate, testedBy,
					 observation, standard, result, remarks, approval, approvedBy,
					 entryUser, entrytimestamp)
					VALUES
					('$edlid', '$regid', 1, '1900-01-01', 'default',
					 '', '', '', '', '$pending', '$appby',
					 '$uid', '$now');";
			}
		}

		if($mpAutoAccept){
			$qry .= "INSERT INTO samplestatustable
				(registrationID, status, receiveDate, remarks, entryUser, entrytimestamp)
				VALUES
				('$regid', 'Accepted', '$dor', '', '$uid', '$now');";
		}

	} else {   // ===== UPDATE EXISTING RECORD =====

		$regid = mysqli_real_escape_string($connection, $_REQUEST["regid"]);
		$qry = "UPDATE registrationtable SET
			sampleID='$sid', labID='$lab', testCategory='$cat', sample_type=NULLIF('$sample_type',''), registrationDate='$dor',
			siteID='$site', siteOther='$siteoth', edlID='$sam', edlOther='$samoth',
			pharmaClaim='$pcl', pharmaOther='$pcloth', formulationID='$for', formulationOther='$foroth',
			sampleQty=$qty, batchNo='$batch', mfgDate='$dom', expDate='$doe',
			manufacturer='$mfg', manufacturerOther='$mfgoth', mahID='$mah', mahOther='$mahoth',
			collectID='$cby', collectOther='$cbyoth', composition='$comp', description='$decp',
			remarks='$rmk', labelClaim='$labelclaim',
			sampled_by=NULLIF('$sampled_by',''),
			sampling_organization=NULLIF('$sampling_org',''),
			sampling_organization_other=NULLIF('$sampling_org_other',''),
			sampling_location=NULLIF('$sampling_location',''),
			sampling_date=NULLIF('$sampling_date',''),
			sent_by=NULLIF('$sent_by',''),
			entryUser='$uid', entrytimestamp='$now'
			WHERE registrationID='$regid';";
	}

	// Atomic multi-statement execution
	$mpOk  = false;
	$mpErr = '';
	$mpErrNo = 0;
	// STRICT mode for this connection only: an oversized or malformed value must FAIL the
	// save (and be reported) rather than be silently truncated/zeroed and reported as saved.
	try { mysqli_query($connection, "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_TRANS_TABLES,STRICT_ALL_TABLES')"); } catch(\Throwable $e){ /* best effort */ }
	mysqli_begin_transaction($connection);
	try {
		if(!mysqli_multi_query($connection, $qry)){
			throw new Exception(mysqli_error($connection), mysqli_errno($connection));
		}
		// drain all result sets
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){ /* empty */ }
		mysqli_commit($connection);
		$mpOk = true;
	} catch(Throwable $e){
		mysqli_rollback($connection);
		$mpErr   = $e->getMessage();
		$mpErrNo = (int)$e->getCode();
		if($mpErr === ''){ $mpErr = mysqli_error($connection); $mpErrNo = mysqli_errno($connection); }
		$mpUserErr = mpq_reg_friendly_error($mpErrNo, $mpErr);
	}

	if($mpOk){
		// final drain (safety)
		while(mysqli_more_results($connection) && mysqli_next_result($connection)){ /* empty */ }

		// Oct 2026 — Sample lifecycle audit (immutable trail). Only on first-ever INSERT
		// ($par==1); edits never emit new transition rows (UPDATE does not change the
		// COLLECTED/SUBMITTED/REGISTERED trio — those are fixed events). Idempotent.
		if($par == 1){
			try {
				require_once(dirname(__DIR__)."/includes/sample_lifecycle_lib.php");
				mpqms_log_registration_transitions($connection, (int)$regid, (string)$uid,
					$_REQUEST['sampled_by']    ?? '',
					$_REQUEST['sampling_date'] ?? '',
					$_REQUEST['sent_by']       ?? '');
			} catch(\Throwable $e){ /* audit must never break the save */ }
		}

		mysqli_close($commondb);
		mysqli_close($connection);

		if($mpAjax){
			header('Content-Type: application/json');
			echo json_encode(array(
				'status'  => 'success',
				'message' => 'Report Saved Successfully!!',
				'isnew'   => ($par==1),
				'regid'   => $regid
			));
			exit;
		}

		echo "<input type='hidden' name='lnk1' id='lnk1' value='manager.php'/>";
		if($par == 1){
			echo "<input type='hidden' name='lnk2' id='lnk2' value='registration.php?par=$par'/>";
		} else {
			echo "<input type='hidden' name='lnk2' id='lnk2' value='registration.php?par=$par&rid=$regid'/>";
		}
?>
	<script>
		$(function() {
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Report Saved Successfully!!',
				showCloseButton: true
			}).then(function(){ window.location = $('#lnk1').val(); });
		});
	</script>
<?php
	} else {
		if($mpAjax){
			header('Content-Type: application/json');
			echo json_encode(array(
				'status'  => 'error',
				'message' => 'Failed to Save Report. ' . $mpUserErr
			));
			exit;
		}
		// non-AJAX fallback: show the same reason the AJAX path reports.
		echo "<input type='hidden' id='mpErrMsg' value='".htmlspecialchars('Failed to Save Report. '.$mpUserErr, ENT_QUOTES, 'UTF-8')."'/>";
?>
	<script>
		$(function() {
			Swal.fire({
				icon: 'error',
				title: 'Not saved',
				confirmButtonText: 'Close',
				text: $('#mpErrMsg').val(),
				showCloseButton: true
			}).then(function(){ window.location = 'manager.php'; });
		});
	</script>
<?php } ?>