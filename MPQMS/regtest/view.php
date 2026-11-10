<!-- Auth -->
<?php include_once("../config/auth_ndtlis.php");  ?>
<?php if(!($_SESSION['acl']==1 || ($_SESSION['acl']==2 && $_SESSION["usercat"]==18) || $_SESSION['acl']==7)){ header("Location: manager.php"); exit; } ?>
<?php
	include_once("../config/db_config.php");
	$regid=$_GET["rid"];
	// SELECT also pulls the Oct-2026 chain-of-custody columns + sample_type; blank for
	// pre-migration rows.
	list($sid,$labid,$cat,$dor,$siteid,$siteoth,$edlid,$edloth,$pcl,$pcloth,$forid,$foroth,$qty,$bno,$dom,$doe,$mfgid,$mfgoth,$mahid,$mahoth,$cbyid,$cbyoth,$comp,$desp,$rmk,$labelclaim,
		$sampled_by,$sampling_org,$sampling_org_other,$sampling_location,$sampling_date,$sent_by,$sample_type
	) = mysqli_fetch_row(mysqli_query($connection,"SELECT sampleID,labID,testCategory,registrationDate,siteID,siteOther,edlID,edlOther,pharmaClaim,pharmaOther,formulationID,formulationOther,sampleQty,batchNo,mfgDate,expDate,manufacturer,manufacturerOther,mahID,mahOther,collectID,collectOther,composition,description,remarks,labelClaim,sampled_by,sampling_organization,sampling_organization_other,sampling_location,sampling_date,sent_by,sample_type FROM registrationtable WHERE registrationID='".$regid."'"));
	if($sampling_date == "1900-01-01" || $sampling_date == "0000-00-00"){ $sampling_date = ""; }
	if($dom == "1900-01-01") {$dom = "";} 
	if($doe == "1900-01-01") {$doe = "";}
	list($site,$dname) = mysqli_fetch_row(mysqli_query($commondb,"SELECT ct.center_name,dt.dzongkhag_name FROM ms_dzongkhag_table dt,ms_health_center_table ct WHERE ct.dzongkhag_code=dt.dzongkhag_code AND ct.center_id='".$siteid."'"));
	if($siteid == 999){	$site = "$site($siteoth)";}
	// Oct-13 2026 — Collected By (District) column removed from the UI; look
	// up the centre name only. A missing master row (e.g. the new NMPTL
	// sentinel before the shared commondb migration lands) falls back to a
	// human-readable label so the field never renders blank.
	$cby = ''; $cdname = '';
	$st = @mysqli_prepare($commondb, "SELECT ct.center_name, dt.dzongkhag_name
	                                  FROM ms_health_center_table ct
	                                  LEFT JOIN ms_dzongkhag_table dt ON dt.dzongkhag_code = ct.dzongkhag_code
	                                  WHERE ct.center_id = ? LIMIT 1");
	if($st){
		mysqli_stmt_bind_param($st, 's', $cbyid);
		mysqli_stmt_execute($st);
		mysqli_stmt_bind_result($st, $cby, $cdname);
		mysqli_stmt_fetch($st);
		mysqli_stmt_close($st);
	}
	if((int)$cbyid === 998 && ($cby === null || $cby === '')){ $cby = 'National Medical Product Testing Laboratory'; }
	if($cbyid == 999){	$cby = "$cby($cbyoth)";}

	// Oct-13 2026 — BFDA (acl=7) must NOT see samples collected by NMPTL. Hard
	// stop here so direct URL access redirects back to the list.
	if((int)($_SESSION['acl'] ?? 0) === 7 && (int)$cbyid === 998){
		header('Location: manager.php'); exit;
	}
	list($edl) = mysqli_fetch_row(mysqli_query($connection,"SELECT edl FROM msedltable WHERE id='".$edlid."'"));
	list($for) = mysqli_fetch_row(mysqli_query($connection,"SELECT formulation FROM msformulationtable WHERE id='".$forid."'"));
	list($lab,$inex) = mysqli_fetch_row(mysqli_query($connection,"SELECT name,inexcountry FROM msappellatelabtable WHERE id='".$labid."'"));
	list($mfg) = mysqli_fetch_row(mysqli_query($connection,"SELECT name FROM msmanufacturertable WHERE id='".$mfgid."'"));
	list($mah) = mysqli_fetch_row(mysqli_query($connection,"SELECT name FROM msmahtable WHERE id='".$mahid."'"));

	list($sts,$recDate,$stsrmk,$received_by,$sample_condition) = mysqli_fetch_row(mysqli_query($connection,"SELECT status,receiveDate,remarks,received_by,sample_condition FROM samplestatustable WHERE registrationID='$regid'"));
	list($frslt,$frmks) = mysqli_fetch_row(mysqli_query($connection,"SELECT result, remarks FROM finalresulttable WHERE registrationID='$regid'"));

	// Sample retention (presentation only, nothing is stored): the sample is retained for
	// 1 year (12 months) or until its expiry date, whichever is nearer.
	//   Base Date    = Receive Date when a valid one exists, otherwise Registration Date
	//   Retain Until = MIN(Base Date + 12 months, Expiry Date); no valid Expiry -> Base + 12 months
	// Legacy sentinel dates (1900-01-01, 0000-00-00, 1899-12-31 ...) are treated as blank.
	if(!function_exists('mpv_date')){
		function mpv_date($v){
			$v = trim((string)($v ?? ''));
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)){ return null; }
			$d = DateTime::createFromFormat('!Y-m-d', $v);
			if(!$d || $d->format('Y-m-d') !== $v || (int)$d->format('Y') < 1950){ return null; }
			return $d;
		}
	}
	if(!function_exists('mpv_add_months')){
		// Calendar-safe month arithmetic: 2028-02-29 + 12 months = 2029-02-28, not 2029-03-01.
		function mpv_add_months(DateTime $d, $months){
			$y = (int)$d->format('Y'); $m = (int)$d->format('n') + (int)$months; $day = (int)$d->format('j');
			$y += intdiv($m - 1, 12); $m = (($m - 1) % 12) + 1;
			$r = new DateTime('today'); $r->setDate($y, $m, 1);
			$r->setDate($y, $m, min($day, (int)$r->format('t')));
			return $r;
		}
	}
	// Retention period: 1 year (12 months) - matches the retention note printed on the report (report_lib.php).
	if(!defined('MPQMS_SAMPLE_RETENTION_MONTHS')){ define('MPQMS_SAMPLE_RETENTION_MONTHS', 12); }
	$mpRetainUntil = null; $mpRetBasis = '';
	$mpRetBase = mpv_date($recDate); $mpRetBaseLbl = 'Receive Date';
	if($mpRetBase === null){ $mpRetBase = mpv_date($dor); $mpRetBaseLbl = 'Registration Date'; }
	if($mpRetBase !== null){
		$mpRetEnd = mpv_add_months($mpRetBase, MPQMS_SAMPLE_RETENTION_MONTHS);
		$mpRetExp = mpv_date($doe);
		if($mpRetExp !== null && $mpRetExp < $mpRetEnd){
			$mpRetainUntil = $mpRetExp;
			$mpRetBasis = 'Expiry Date '.$mpRetExp->format('Y-m-d').' is nearer than 1 year from '.$mpRetBaseLbl.' ('.$mpRetEnd->format('Y-m-d').')';
		} else {
			$mpRetainUntil = $mpRetEnd;
			$mpRetBasis = '1 year (12 months) from '.$mpRetBaseLbl.' '.$mpRetBase->format('Y-m-d')
				.($mpRetExp !== null ? ' (Expiry Date '.$mpRetExp->format('Y-m-d').' is later)' : ' (no valid Expiry Date on record)');
		}
		if($mpRetainUntil < new DateTime('today')){ $mpRetBasis .= ' - retention period has ended'; }
	}

	// UI helper (presentation only): a value is "present" when it is not NULL and not blank
	// after trimming. Optional fields are rendered only when present, so blank/NULL optional
	// fields are omitted and the grid reflows to remove empty cells. Mandatory fields (the
	// ones marked required on the registration form) are always shown. No data is altered.
	if(!function_exists('mpv_has')){ function mpv_has($v){ return trim((string)($v ?? '')) !== ''; } }

	// Official (immutable) Analytical Report, if one has been issued for this sample.
	// Null when the sample is not fully completed yet — the existing on-the-fly
	// "Download PDF" button is always available and is unaffected by this.
	require_once(__DIR__."/official_report_lib.php");
	$mpOfficialRec = mpqms_official_report_record($connection, (int)$regid);
?>
<?php include_once("../includes/header.php"); ?>
	<!-- CSS -->
	<?php include_once("../includes/css-jquery.php"); ?>
	<body class="hold-transition sidebar-mini layout-fixed">
		<div class="wrapper">
			<!-- Top Navbar -->
			<?php include_once("../includes/top_navigation.php"); ?>
			<!-- Main Sidebar Container -->
			<?php include_once("../includes/main_navigation.php"); ?>
			<div class="content-wrapper">
				<div class="content-header">
				  <div class="container-fluid">
					<div class="row mb-2">
					  <div class="col-sm-6">
						<h1 class="m-0">View Registration & Result</h1>
					  </div>
					  <div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
						  <li class="breadcrumb-item"><a href="../regtest/manager.php">Manager</a></li>
						  <li class="breadcrumb-item active">View</li>
						</ol>
					  </div>
					</div>
				  </div>
				</div>
				
				<section class="content">
					<div class="container-fluid">
						<div class="mp-reg-body">

							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-flask"></i> Sample Registration</div>
								<div class="row">
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">In / Ex-country</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($inex ?? ''));?>" readonly>
						</div>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Appellate Laboratory</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($lab ?? ''));?>" readonly>
						</div>
						<!-- Oct 2026 — Test Category field removed from View per Oct-12 directive. -->
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Registration Date</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($dor ?? ''));?>" readonly>
						</div>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sampling District</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($dname ?? ''));?>" readonly>
						</div>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sampling Site</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($site ?? ''));?>" readonly>
						</div>
						<?php if(mpv_has($siteoth)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sampling Site (Others)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($siteoth ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sample ID</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sid ?? ''));?>" readonly>
						</div>
								</div>
							</div>

							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-pills"></i> Product Details</div>
								<div class="row">
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Name of Sample</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($edl ?? ''));?>" readonly>
						</div>
						<?php if(mpv_has($edloth)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sample (Others)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($edloth ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Pharma. Claim</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($pcl ?? ''));?>" readonly>
						</div>
						<?php if(mpv_has($pcloth)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Pharma. Claim (Others)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($pcloth ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Formulation</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($for ?? ''));?>" readonly>
						</div>
						<?php if(mpv_has($foroth)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Formulation (Others)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($foroth ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sample Quantity</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($qty ?? ''));?>" readonly>
						</div>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Batch No.</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($bno ?? ''));?>" readonly>
						</div>
						<?php if(mpv_has($labelclaim)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Label Claim Amount</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($labelclaim ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
								</div>
							</div>

							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-industry"></i> Manufacturing &amp; Dates</div>
								<div class="row">
						<?php if(mpv_has($dom)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Mfg. Date</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($dom ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($doe)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Expiry Date</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($doe ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Manufacturer</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($mfg ?? ''));?>" readonly>
						</div>
						<?php if(mpv_has($mfgoth)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Manufacturer (Others)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($mfgoth ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">MAH</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($mah ?? ''));?>" readonly>
						</div>
						<?php if(mpv_has($mahoth)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">MAH (Others)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($mahoth ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
								</div>
							</div>

							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-truck"></i> Sample Collection &amp; Dispatch Details</div>
								<div class="row">
						<?php if(mpv_has($sampled_by)): ?>
						<div class="col-lg-6 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sampled By</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sampled_by ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($sampling_org)): ?>
						<div class="col-lg-6 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sampling Organization</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sampling_org ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($sampling_org_other)): ?>
						<div class="col-lg-6 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sampling Organization (Other)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sampling_org_other ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($sampling_location)): ?>
						<div class="col-lg-6 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sampling Location</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sampling_location ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($sampling_date)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sampling Date</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sampling_date ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($sent_by)): ?>
						<div class="col-lg-8 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sent / Submitted By</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sent_by ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<!-- Oct-13 2026 — Collected By (District) field removed. -->
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Collected By</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($cby ?? ''));?>" readonly>
						</div>
						<?php if(mpv_has($cbyoth)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Collected By (Others)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($cbyoth ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
								</div>
							</div>

							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-vial"></i> Composition &amp; Description</div>
								<div class="row">
						<div class="col-lg-6 col-12 mp-field">
							<label class="mp-lbl">Composition</label>
							<textarea class="form-control" rows="3" readonly><?=htmlspecialchars((string)($comp ?? ''));?></textarea>
						</div>
						<div class="col-lg-6 col-12 mp-field">
							<label class="mp-lbl">Description of Sample</label>
							<textarea class="form-control" rows="3" readonly><?=htmlspecialchars((string)($desp ?? ''));?></textarea>
						</div>
								</div>
							</div>

							<?php if(mpv_has($rmk)): ?>
							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-comment-dots"></i> Remarks</div>
								<div class="row">
						<div class="col-12 mp-field">
							<label class="mp-lbl">Remarks</label>
							<textarea class="form-control" rows="3" readonly><?=htmlspecialchars((string)($rmk ?? ''));?></textarea>
						</div>
								</div>
							</div>
							<?php endif; ?>

							<?php if(mpv_has($sts) || mpv_has($stsrmk) || mpv_has($received_by) || mpv_has($sample_condition)): ?>
							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-clipboard-check"></i> Sample Acceptance</div>
								<div class="row">
						<?php if(mpv_has($sts)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sample Acceptance</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sts ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($received_by)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Collected / Received By</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($received_by ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($sample_condition)): ?>
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Sample Condition</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars((string)($sample_condition ?? ''));?>" readonly>
						</div>
						<?php endif; ?>
						<?php if(mpv_has($stsrmk)): ?>
						<div class="col-lg-6 col-12 mp-field">
							<label class="mp-lbl">Acceptance Remarks</label>
							<textarea class="form-control" rows="3" readonly><?=htmlspecialchars((string)($stsrmk ?? ''));?></textarea>
						</div>
						<?php endif; ?>
								</div>
							</div>
							<?php endif; ?>

							<?php if($mpRetainUntil !== null): ?>
							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-box-open"></i> Sample Retention</div>
								<div class="row">
						<div class="col-lg-4 col-md-6 col-12 mp-field">
							<label class="mp-lbl">Retain Until</label>
							<input type="text" class="form-control font-weight-bold" value="<?=htmlspecialchars($mpRetainUntil->format('Y-m-d'));?>" readonly>
						</div>
						<div class="col-lg-8 col-12 mp-field">
							<label class="mp-lbl">Basis (1 year or Expiry Date, whichever is nearer)</label>
							<input type="text" class="form-control" value="<?=htmlspecialchars($mpRetBasis);?>" readonly>
						</div>
								</div>
							</div>
							<?php endif; ?>

							<div class="mp-reg-section">
								<div class="mp-reg-title"><i class="fas fa-vials"></i> Test Result</div>
								<div class="table-responsive">
									<table class="table table-bordered table-striped table-hover table-sm">
										<thead>
											<tr class="text-center">
											  <th style="width: 10%">Test</th>
											  <th style="width: 10%">Repetition</th>
											  <th style="width: 10%">Observation</th>
											  <th style="width: 10%">Standard</th>
											  <th style="width: 10%">Result</th>
											  <th style="width: 10%">Test Date</th>
											  <th style="width: 10%">Tested By</th>
											  <th style="width: 10%">Approval Status</th>
											  <th style="width: 10%">Approved By</th>
											  <th style="width: 10%">Remarks</th>
											</tr>
										</thead>
										<tbody>
										<?php
											$qry=mysqli_query($connection,"SELECT parameter,repetition,unit,testDate,testedBy,observation,standard,result,approval,approvedBy, remarks FROM mstestparametertable pt,labresulttable rt WHERE pt.id=rt.parameterID AND rt.registrationID='$regid' AND approval='Approved' ORDER BY ranking ASC");
											while(list($par,$rpt,$unt,$dot,$tby,$obs,$std,$rslt,$appv,$aby, $rmk)=mysqli_fetch_row($qry)){
												list($tbyname)=mysqli_fetch_row(mysqli_query($commondb,"SELECT fullname FROM user_profile_table WHERE username='$tby'"));
												list($abyname)=mysqli_fetch_row(mysqli_query($commondb,"SELECT fullname FROM user_profile_table WHERE username='$aby'"));
												if($unt != ""){$unt = "($unt)";}
										?>
											<tr>
												<td><small><?=$par;?><?=$unt;?></small></td>
												<td class="text-center"><small><?=$rpt;?></small></td>
												<td><small><?=$obs;?></small></td>
												<td><small><?=$std;?></small></td>
												<td><small><?=$rslt;?></small></td>
												<td class="text-center"><small><?=$dot;?></small></td>
												<td><small><?=$tbyname;?></small></td>
												<td><small><?=$appv;?></small></td>
												<td><small><?=$abyname;?></small></td>
												<td><small><?=$rmk;?></small></td>
											</tr>
										<?php } ?>
											<tr>
												<td><small>Final Result</small></td><td><small><b><?=$frslt;?></b></small></td><td><small>Remarks for Final Result</small></td><td colspan="7"><small><b><?=$frmks;?></b></small></td>
											</tr>
										</tbody>
									</table>
								</div>
							</div>

						</div>
						<div class="mp-reg-footer d-flex justify-content-between align-items-center flex-wrap">
							<a href="manager.php" class="btn bg-gradient-secondary mp-back-btn"><i class="fas fa-arrow-left"></i>&nbsp;Back to List</a>
							<div class="d-flex align-items-center flex-wrap">
								<?php if($mpOfficialRec !== null): ?>
								<a class="btn bg-gradient-success mr-2" href="<?php echo("download_official_report.php?rid=".(int)$regid);?>" target="_blank"
								   title="Official report issued <?=htmlspecialchars((string)$mpOfficialRec['generated_at']);?> — stored read-only, SHA-256 <?=htmlspecialchars(substr((string)$mpOfficialRec['file_hash'],0,16));?>…">
									<i class="fas fa-lock"></i>&nbsp;Download Official Report
								</a>
								<?php endif; ?>
								<?php if(mpqms_sample_fully_completed($connection, (int)$regid)): ?>
								<a class="btn bg-gradient-primary" href="<?php echo("pdf_report.php?rid=$regid");?>" target="_blank"><i class="fa fa-download"></i>&nbsp;Download PDF</a>
								<?php else: /* Final Report is downloadable only once testing is complete and finalized (pdf_report.php enforces the same gate) */ ?>
								<button type="button" class="btn btn-secondary" disabled title="Available once the sample is accepted, all test results are approved and the Final Result is recorded."><i class="fa fa-download"></i>&nbsp;Final Report not yet available</button>
								<?php endif; ?>
							</div>
						</div>
						<?php if($mpOfficialRec !== null): ?>
						<div class="text-right mt-2" style="font-size:11px;color:#6c757d;">
							<i class="fas fa-certificate text-success"></i>
							Official report issued on <?=htmlspecialchars((string)$mpOfficialRec['generated_at']);?>
							by <?=htmlspecialchars((string)$mpOfficialRec['generated_by']);?> · stored read-only ·
							SHA-256 <code style="font-size:10px;"><?=htmlspecialchars((string)$mpOfficialRec['file_hash']);?></code>
						</div>
						<?php endif; ?>
					</div>
				</section>
			</div>
		<?php include_once("../includes/footer.php"); ?>
		</div>
	</body>
</html>
