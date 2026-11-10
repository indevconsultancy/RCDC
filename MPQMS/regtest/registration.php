<!-- Auth -->
<?php include_once("../config/auth_ndtlis.php"); ?>
<?php include_once("../config/mpq_validate.php"); mpq_require_page('registration', 'view'); /* effective-role RBAC: who may open this page */ ?>
<?php
	include_once("../config/db_config.php");

	// Display helper for "no date recorded yet". save_parameter.php seeds new result rows
	// with the 1900-01-01 sentinel (and legacy rows may hold a zero date); showing that raw
	// makes the Test Date picker open in the year 1900. Rendering it blank lets the picker
	// default to today. The sentinel itself is preserved on save (see update_result.php),
	// so stored data and the report's test commencement/completion dates are unchanged.
	if(!function_exists('mpq_date_display')){
		function mpq_date_display($d){
			$d = trim((string)$d);
			return ($d === '1900-01-01' || $d === '0000-00-00') ? '' : $d;
		}
	}

	$_SESSION['par'] = $_GET["par"];
	$mpLocked = false;   // completed-report lock; resolved below in edit mode

	if($_SESSION['par'] == 1){
		$phead = "New Report";
		$action = "save_update_registration.php";
		$btnlbl ="Save Registration";
		$sid=$inex=$labid=$lab=$cat=$dor=$siteid=$siteoth=$edlid=$edloth=$pcl=$pcloth=$forid=$for=$foroth=$qty=$bno=$dom=$doe=$mfgid=$mfg=$mfgoth=$mahid=$mah=$mahoth=$cby=$comp=$desp=$rmk=$site=$did=$dname=$edl=$for=$cdid=$cdname=$cbyoth=$frslt=$sts=$cbyid=$labelclaim="";
		// Oct 2026 — Chain-of-custody fields (empty in New mode; loaded from the row in Edit mode).
		$sampled_by=$sampling_org=$sampling_org_other=$sampling_location=$sampling_date=$sent_by="";
		$received_by=$sample_condition="";
		$sample_type="";
	}
	else{
		$regid=$_GET["rid"];
		$action = "save_update_registration.php";
		$phead = "Edit Report";
		$btnlbl ="Update Registration";
		
		// SELECT now also pulls the Oct-2026 chain-of-custody columns + sample_type
		// (nullable; empty for historic rows that pre-date the migration — rendered as
		// blank inputs in that case).
		list($sid,$labid,$cat,$dor,$siteid,$siteoth,$edlid,$edloth,$pcl,$pcloth,$forid,$foroth,$qty,$bno,$dom,$doe,$mfgid,$mfgoth,$mahid,$mahoth,$cbyid,$cbyoth,$comp,$desp,$rmk,$labelclaim,
			$sampled_by,$sampling_org,$sampling_org_other,$sampling_location,$sampling_date,$sent_by,$sample_type
		) = mysqli_fetch_row(mysqli_query($connection,"SELECT sampleID,labID,testCategory,registrationDate,siteID,siteOther,edlID,edlOther,pharmaClaim,pharmaOther,formulationID,formulationOther,sampleQty,batchNo,mfgDate,expDate,manufacturer,manufacturerOther,mahID,mahOther,collectID,collectOther,composition,description,remarks,labelClaim,sampled_by,sampling_organization,sampling_organization_other,sampling_location,sampling_date,sent_by,sample_type FROM registrationtable WHERE registrationID='".$regid."'"));
		if($sampling_date == "1900-01-01" || $sampling_date == "0000-00-00"){ $sampling_date = ""; }
		if($dom == "1900-01-01") {$dom = "";} 
		if($doe == "1900-01-01") {$doe = "";}
		list($site,$did,$dname) = mysqli_fetch_row(mysqli_query($commondb,"SELECT ct.center_name,dt.dzongkhag_code,dt.dzongkhag_name FROM ms_dzongkhag_table dt,ms_health_center_table ct WHERE ct.dzongkhag_code=dt.dzongkhag_code AND ct.center_id='".$siteid."'"));
		list($edl) = mysqli_fetch_row(mysqli_query($connection,"SELECT edl FROM msedltable WHERE id='".$edlid."'"));
		list($for) = mysqli_fetch_row(mysqli_query($connection,"SELECT formulation FROM msformulationtable WHERE id='".$forid."'"));
		list($lab,$inex) = mysqli_fetch_row(mysqli_query($connection,"SELECT name,inexcountry FROM msappellatelabtable WHERE id='".$labid."'"));
		list($mfg) = mysqli_fetch_row(mysqli_query($connection,"SELECT name FROM msmanufacturertable WHERE id='".$mfgid."'"));
		list($mah) = mysqli_fetch_row(mysqli_query($connection,"SELECT name FROM msmahtable WHERE id='".$mahid."'"));
		list($cby,$cdid,$cdname) = mysqli_fetch_row(mysqli_query($commondb,"SELECT ct.center_name,dt.dzongkhag_code,dt.dzongkhag_name FROM ms_dzongkhag_table dt,ms_health_center_table ct WHERE ct.dzongkhag_code=dt.dzongkhag_code AND ct.center_id='".$cbyid."'"));

		list($sts,$recDate,$stsrmks,$received_by,$sample_condition) = mysqli_fetch_row(mysqli_query($connection,"SELECT status,receiveDate,remarks,received_by,sample_condition FROM samplestatustable WHERE registrationID='$regid'"));

		// `testedBy` is kept for the PDF report + view-page readers; `assigned_analyst_id`,
		// `assigned_by_user_id`, `date_assigned`, `date_tested` and `test_status` are the
		// Oct-2026 assignment & traceability columns (see docs/sql/labresult_assignment_tracking.sql).
		$rsltqry = "SELECT resultID,parameter,repetition,unit,testDate,testedBy,observation,standard,result,approval,approvedBy,remarks,
                   digital_signature,signature_type,signature_date,signed_by,
                   assigned_analyst_id,assigned_by_user_id,date_assigned,date_tested,test_status
            FROM mstestparametertable pt,labresulttable rt
            WHERE pt.id=rt.parameterID AND rt.registrationID='$regid'
            ORDER BY ranking ASC";

		$rsltcnt = mysqli_num_rows(mysqli_query($connection,$rsltqry)); 
		list($apvcnt) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM labresulttable WHERE approval IN('Approved','Disapproved') AND registrationID='$regid'"));
		
		list($frslt,$frmk) = mysqli_fetch_row(mysqli_query($connection,"SELECT result,remarks FROM finalresulttable WHERE registrationID='$regid'"));

		// Completed report (Accepted + all results Approved + Final Result saved) => read-only.
		// Mutation controls below are hidden; every write endpoint enforces the same lock.
		require_once(__DIR__."/report_lock_lib.php");
		$mpLocked = mpqms_report_locked($connection, (int)$regid);
	}
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
						<h1 class="m-0"><?=$phead;?></h1>
						<?php if(!empty($mpLocked)){ ?>
						<div class="text-muted small mt-1"><i class="fas fa-lock"></i>&nbsp;<b>Completed report &mdash; read-only.</b> <?=htmlspecialchars(mpqms_locked_message());?></div>
						<?php } ?>
					  </div>
					  <div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
						  <li class="breadcrumb-item"><a href="../regtest/manager.php">Manager</a></li>
						  <li class="breadcrumb-item active">Registration</li>
						</ol>
					  </div>
					</div>
				  </div>
				</div>
				
				<section class="content">
					<div class="container-fluid">
						<div class="row">
							<div class="col-12">
								<div class="card card-primary">
									<div class="card-header">
						            	<h3 class="card-title"><i class="fas fa-file-medical text-primary"></i> &nbsp;<b>Registration Details</b> — sample &amp; product information</h3>
						           	</div>
									<form id="form0" class="form-horizontal" method="Post" autocomplete="off" action="<?=$action;?>">
										<div class="card-body">
<div class="mp-reg-body">

  <!--
    Sample Validity Check — captured at the very start of registration so the lab
    can verify Batch/Lot No. and Expiry Date before anything else is filled in.
    These two inputs keep their original name/id/validation/bindings
    (#batch -> chkBatch, #doe -> chkExpiry, datetimepicker on #doe) — only the
    position in the DOM moved. Nothing is duplicated, so save_update_registration.php
    reads exactly the same $_POST['batch'] / $_POST['doe'] it always did, and the
    existing duplicate-batch and short-expiry warnings continue to fire from here.
    No blocking of expired samples is introduced (requirement explicitly: do not
    reject expired samples unless an existing business rule already does so).
  -->
  <div class="mp-reg-section" style="background:#fff8e1;border-left:4px solid #f59e0b;">
    <div class="mp-reg-title"><i class="fas fa-shield-alt" style="color:#b45309;"></i> Sample Validity Check <span class="text-muted" style="font-weight:normal;font-size:.85em;">&mdash; verify batch, expiry &amp; sample type before registering</span></div>
    <div class="row">
      <!-- Oct 2026 — sample_type is the top-level lifecycle classifier for the
           6-stage chain-of-custody. Options are populated dynamically from
           mssampletypetable via load_sample_type.php so admins can add/retire
           entries without touching PHP. The server still allowlists the posted
           value before INSERT; blank stays NULL on historic rows. The inner
           <select> is swapped in by load_sample_type.php; the pre-render below
           preserves current/legacy values so Edit mode never loses them even
           if the AJAX call fails. -->
      <div class="col-lg-12 col-12 mp-field">
        <label class="mp-lbl">Sample Type <span class="mp-req">*</span></label>
        <div id="stdiv">
          <select name="sample_type" id="sample_type" class="form-control" required>
            <option value="<?= htmlspecialchars((string)($sample_type ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <?= (((string)($sample_type ?? '')) === '') ? 'Select Sample Type' : htmlspecialchars((string)$sample_type, ENT_QUOTES, 'UTF-8'); ?>
            </option>
          </select>
        </div>
      </div>
      <div class="col-lg-6 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Batch / Lot No. <span class="mp-req">*</span></label>
        <input type="text" name="batch" id="batch" class="form-control" value="<?=$bno;?>" placeholder="Enter Batch / Lot No" required>
      </div>
      <div class="col-lg-6 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Expiry Date</label>
        <div class="input-group date" data-target-input="nearest">
          <input type="text" class="form-control datetimepicker-input text-center" id="doe" name="doe" value="<?=$doe;?>" placeholder="yyyy-mm-dd"/>
          <div class="input-group-append" data-target="#doe" data-toggle="datetimepicker">
            <div class="input-group-text"><i class="fa fa-calendar"></i></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mp-reg-section">
    <div class="mp-reg-title"><i class="fas fa-flask"></i> Sample Registration</div>
    <div class="row">
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">In / Ex-country <span class="mp-req">*</span></label>
        <select name="inex" id="inex" class="form-control" required>
          <option value="<?=$inex;?>"><?= $inex=="" ? "Select Source (In/Ex-country)" : $inex; ?></option>
          <option value="In-country">In-country</option>
          <option value="Ex-country">Ex-country</option>
        </select>
      </div>
      <!-- Oct 2026 — Appellate Laboratory field reacts to In/Ex-country:
             In-country  -> AJAX dropdown of lab master rows; defaults to NMPTL.
             Ex-country  -> free-text input so the user can type the overseas lab.
           Both controls carry the same name/id (lab / #lab) and the server resolves
           either shape to a numeric msappellatelabtable.id, auto-registering a new
           Ex-country lab row when a brand-new name is typed. -->
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Appellate Laboratory <span class="mp-req">*</span></label>
        <div id="labdiv">
          <?php if($inex === 'Ex-country'){ ?>
            <input type="text" name="lab" id="lab" class="form-control" value="<?= htmlspecialchars((string)$lab, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter overseas laboratory name" required>
          <?php } else { ?>
            <select name="lab" id="lab" class="form-control" required>
              <option value="<?=$labid;?>"><?= $lab=="" ? "Select Laboratory" : $lab; ?></option>
            </select>
          <?php } ?>
        </div>
      </div>
      <!-- Oct 2026 — Test Category field removed from Add / Edit UI per the
           Oct-12 directive. The DB column testCategory is retained so historic
           records keep their stored value; save_update_registration.php writes
           '' on new rows so no new UI binding exists. -->
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Registration Date <span class="mp-req">*</span></label>
        <div class="input-group date" data-target-input="nearest">
          <input type="text" class="form-control datetimepicker-input text-center" id="dor" name="dor" value="<?=$dor;?>" placeholder="yyyy-mm-dd" required />
          <div class="input-group-append" data-target="#dor" data-toggle="datetimepicker">
            <div class="input-group-text"><i class="fa fa-calendar"></i></div>
          </div>
        </div>
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Sampling District <span class="mp-req">*</span></label>
        <?php
          $dqry = "SELECT dzongkhag_code,dzongkhag_name FROM ms_dzongkhag_table";
          $rundqry = mysqli_query($commondb,$dqry);
        ?>
        <select name="dzo" id="dzo" class="form-control select2bs4" style="width:100%;" required>
          <option value="<?= $did; ?>"><?= empty($dname) ? "Select Sampling District" : $dname; ?></option>
        <?php
          while(list($id,$val)=mysqli_fetch_row($rundqry)){
            echo("<option value='$id'>$val</option>");
          }
        ?>
        </select>
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Sampling Site <span class="mp-req">*</span></label>
        <div id="sitediv">
          <select name="site" id="site" class="form-control select2bs4" style="width:100%;" required>
            <option value="<?=$siteid;?>"><?= $site=="" ? "Select Sampling Site" : $site; ?></option>
          </select>
        </div>
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Sampling Site (Others)</label>
        <input type="text" name="siteoth" id="siteoth" class="form-control" value="<?=$siteoth;?>" placeholder="Other Sites">
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Sample ID <span class="mp-req">*</span></label>
        <div id="siddiv">
          <input type="text" name="sid" id="sid" class="form-control" value="<?=$sid;?>" placeholder="Enter Sample ID" required>
        </div>
      </div>
    </div>
  </div>

  <div class="mp-reg-section">
    <div class="mp-reg-title"><i class="fas fa-pills"></i> Product Details</div>
    <div class="row">
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Name of Sample <span class="mp-req">*</span></label>
        <?php $qry=mysqli_query($connection,"SELECT id,edl FROM msedltable WHERE status=1");?>
        <select name="sam" id="sam" class="form-control select2bs4" style="width:100%;" required>
          <option value="<?=$edlid;?>"><?= $edl=="" ? "Select Product (EDL)" : $edl; ?></option>
        <?php while(list($id,$val)=mysqli_fetch_row($qry)){ echo("<option value='$id'>$val</option>"); } ?>
        </select>
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Sample (Others)</label>
        <input type="text" name="samoth" id="samoth" class="form-control" value="<?=$edloth;?>" placeholder="Specify other product">
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Pharma. Claim <span class="mp-req">*</span></label>
        <select name="pcl" id="pcl" class="form-control select2bs4" style="width:100%;" required>
          <option value="<?=$pcl;?>"><?= $pcl=="" ? "Select Pharma Claim" : $pcl; ?></option>
          <option value="BP">BP</option>
          <option value="INN">INN</option>
          <option value="IP">IP</option>
          <option value="USP">USP</option>
          <option value="Others">Others</option>
        </select>
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Pharma. Claim (Others)</label>
        <input type="text" name="pcloth" id="pcloth" class="form-control" value="<?=$pcloth;?>" placeholder="Specify other claim">
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Formulation <span class="mp-req">*</span></label>
        <?php $qry=mysqli_query($connection,"SELECT id,formulation FROM msformulationtable WHERE status=1");?>
        <select name="for" id="for" class="form-control select2bs4" style="width:100%;" required>
          <option value="<?=$forid;?>"><?= $for=="" ? "Select Formulation" : $for; ?></option>
        <?php while(list($id,$for)=mysqli_fetch_row($qry)){ echo("<option value='$id'>$for</option>"); } ?>
        </select>
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Formulation (Others)</label>
        <input type="text" name="foroth" id="foroth" class="form-control" value="<?=$foroth;?>" placeholder="Specify other formulation">
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Sample Quantity <span class="mp-req">*</span></label>
        <input type="number" name="qty" id="qty" class="form-control text-center" value="<?=$qty;?>" placeholder="Enter Quantity" required>
      </div>
      <!-- Batch No. was here; moved to the "Sample Validity Check" section at the top so
           it is captured during the initial registration stage. Save/update logic unchanged. -->
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Label Claim Amount</label>
        <input type="text" name="labelclaim" id="labelclaim" class="form-control" value="<?=htmlspecialchars($labelclaim ?? '');?>" placeholder="e.g. 500 mg / 5 ml">
      </div>
    </div>
  </div>

  <div class="mp-reg-section">
    <div class="mp-reg-title"><i class="fas fa-industry"></i> Manufacturing &amp; Dates</div>
    <div class="row">
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Mfg. Date</label>
        <div class="input-group date" data-target-input="nearest">
          <input type="text" class="form-control datetimepicker-input text-center" id="dom" name="dom" value="<?=$dom;?>" placeholder="yyyy-mm-dd"/>
          <div class="input-group-append" data-target="#dom" data-toggle="datetimepicker">
            <div class="input-group-text"><i class="fa fa-calendar"></i></div>
          </div>
        </div>
      </div>
      <!-- Expiry Date was here; moved to the "Sample Validity Check" section at the top so
           it is captured during the initial registration stage. Save/update, chkExpiry and
           datetimepicker binding ($('#dor,#recDate,#dom,#doe')) are all unchanged. -->
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Manufacturer <span class="mp-req">*</span></label>
        <?php $qry=mysqli_query($connection,"SELECT id,name FROM msmanufacturertable WHERE status=1");?>
        <select name="mfg" id="mfg" class="form-control select2bs4" style="width:100%;" required>
          <option value="<?=$mfgid;?>"><?= $mfg=="" ? "Select Manufacturer" : $mfg; ?></option>
        <?php while(list($id,$val)=mysqli_fetch_row($qry)){ echo("<option value='$id'>$val</option>"); } ?>
        </select>
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">Manufacturer (Others)</label>
        <input type="text" name="mfgoth" id="mfgoth" class="form-control" value="<?=$mfgoth;?>" placeholder="Specify other manufacturer">
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">MAH <span class="mp-req">*</span></label>
        <?php $qry=mysqli_query($connection,"SELECT id,name FROM msmahtable WHERE status=1");?>
        <select name="mah" id="mah" class="form-control select2bs4" style="width:100%;" required>
          <option value="<?=$mahid;?>"><?= $mah=="" ? "Select MAH" : $mah; ?></option>
        <?php while(list($id,$val)=mysqli_fetch_row($qry)){ echo("<option value='$id'>$val</option>"); } ?>
        </select>
      </div>
      <div class="col-lg-4 col-md-6 col-12 mp-field">
        <label class="mp-lbl">MAH (Others)</label>
        <input type="text" name="mahoth" id="mahoth" class="form-control" value="<?=$mahoth;?>" placeholder="Specify other MAH">
      </div>
    </div>
  </div>

  <!--
    Sample Collection & Dispatch Details (Oct 2026, strict 2-column grid).

    Chain of custody from original sampling through NMPTL submission. All eight
    main fields pair up in a 4×2 Bootstrap grid; the Oct-2026 conditional
    "Sampling Organization (Other)" is a full-width insert that only appears
    when the dropdown is set to "Other" — this keeps row alignment stable
    whether the condition is on or off.

    The existing Collected By (District / Centre / Others) inputs are kept
    unchanged because they feed BFDA email recipient resolution
    (includes/bfda_recipient_lib.php) and the archive index client_id lookup
    (regtest/report_archive_index.php). New fields are optional on the form;
    save handlers store them as NULL when blank.
  -->
  <style>
    /* Uniform height, label weight, placeholder weight and spacing — scoped to
       this section only so no other form on the page shifts. 38px matches the
       default Bootstrap-4 form-control line-height so inputs, selects and
       date-picker icons line up on the same baseline. */
    .mpcd-grid .form-control { height: 38px; font-size: 14px; }
    .mpcd-grid textarea.form-control { height: auto; }
    .mpcd-grid .mp-lbl { font-weight: 500; font-size: 14px; margin-bottom: .25rem; display:block; }
    .mpcd-grid .form-control::-webkit-input-placeholder { font-weight: 400; }
    .mpcd-grid .form-control::placeholder { font-weight: 400; }
    .mpcd-grid .input-group.date { height: 38px; }
    .mpcd-grid .input-group.date .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .mpcd-grid .input-group-text { height: 38px; display: flex; align-items: center; }
    /* Select2 (used by cdzo / cby) — match the 38px standard. */
    .mpcd-grid .select2-container--bootstrap4 .select2-selection--single,
    .mpcd-grid .select2-container .select2-selection--single { height: 38px; }
    .mpcd-grid .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered { line-height: 36px; }
    .mpcd-grid .select2-container--bootstrap4 .select2-selection--single .select2-selection__arrow { height: 36px; }
    /* Each column is the row vertical margin — mb-3 on the column wrappers. */
  </style>
  <div class="mp-reg-section">
    <div class="mp-reg-title"><i class="fas fa-truck"></i> Sample Collection &amp; Dispatch Details</div>
    <div class="row mpcd-grid">

      <!-- Row 1 : Sampled By | Sampling Organization -->
      <div class="col-md-6 col-12 mp-field mb-3">
        <label class="mp-lbl mb-1">Sampled By</label>
        <input type="text" name="sampled_by" id="sampled_by" class="form-control" value="<?=htmlspecialchars((string)($sampled_by ?? ''));?>" placeholder="Name &amp; designation of sampler">
      </div>
      <div class="col-md-6 col-12 mp-field mb-3">
        <label class="mp-lbl mb-1">Sampling Organization</label>
        <?php
          $mpSampOrgOptions = array('Hospital','Private Pharmacy','BFDA','MPD','Public Pharmacy','Clinic','Health Centre','Other');
        ?>
        <select name="sampling_organization" id="sampling_organization" class="form-control">
          <option value="">Select Sampling Organization</option>
          <?php foreach($mpSampOrgOptions as $opt){
              $sel = ($sampling_org === $opt) ? ' selected' : '';
              echo '<option value="'.htmlspecialchars($opt).'"'.$sel.'>'.htmlspecialchars($opt).'</option>';
          } ?>
        </select>
      </div>

      <!-- Conditional row — full width when visible, zero height when hidden,
           so neighbouring rows stay in exactly the same column slots. -->
      <div class="col-12 mp-field mb-3" id="sampling_org_other_div" style="<?=($sampling_org === 'Other') ? '' : 'display:none;';?>">
        <label class="mp-lbl mb-1">Sampling Organization (Other)</label>
        <input type="text" name="sampling_organization_other" id="sampling_organization_other" class="form-control" value="<?=htmlspecialchars((string)($sampling_org_other ?? ''));?>" placeholder="Specify other organization">
      </div>

      <!-- Row 2 : Sampling Location | Sampling Date -->
      <div class="col-md-6 col-12 mp-field mb-3">
        <label class="mp-lbl mb-1">Sampling Location</label>
        <input type="text" name="sampling_location" id="sampling_location" class="form-control" value="<?=htmlspecialchars((string)($sampling_location ?? ''));?>" placeholder="Facility / location where the sample was taken">
      </div>
      <div class="col-md-6 col-12 mp-field mb-3">
        <label class="mp-lbl mb-1">Sampling Date</label>
        <div class="input-group date" data-target-input="nearest">
          <input type="text" class="form-control datetimepicker-input text-center" id="sampling_date" name="sampling_date" value="<?=htmlspecialchars((string)($sampling_date ?? ''));?>" placeholder="yyyy-mm-dd"/>
          <div class="input-group-append" data-target="#sampling_date" data-toggle="datetimepicker">
            <div class="input-group-text"><i class="fa fa-calendar"></i></div>
          </div>
        </div>
      </div>

      <!-- Row 3 : Sent / Submitted By -->
      <div class="col-md-6 col-12 mp-field mb-3">
        <label class="mp-lbl mb-1">Sent / Submitted By</label>
        <input type="text" name="sent_by" id="sent_by" class="form-control" value="<?=htmlspecialchars((string)($sent_by ?? ''));?>" placeholder="Person or organisation who submitted the sample to NMPTL">
      </div>
      <!-- Oct-13 2026 — "Collected By (District)" field removed. The Collected By
           dropdown now loads EVERY ndtlis health centre at once (plus the new
           NMPTL option), via load_site.php?par1=All, so no district pre-filter
           is needed. See the mpInitReg() init block for the auto-load. -->

      <!-- Row 4 : Collected By | Collected By (Others) -->
      <div class="col-md-6 col-12 mp-field mb-3">
        <label class="mp-lbl mb-1">Collected By <span class="mp-req">*</span></label>
        <div id="cbydiv">
          <select name="cby" id="cby" class="form-control select2bs4" style="width:100%;" required>
            <option value="<?=$cbyid;?>"><?= $cby=="" ? "Select Collected By" : $cby; ?></option>
          </select>
        </div>
      </div>
      <div class="col-md-6 col-12 mp-field mb-3">
        <label class="mp-lbl mb-1">Collected By (Others)</label>
        <input type="text" name="cbyoth" id="cbyoth" class="form-control" value="<?=$cbyoth;?>" placeholder="Others">
      </div>

    </div>
  </div>

  <div class="mp-reg-section">
    <div class="mp-reg-title"><i class="fas fa-vial"></i> Composition &amp; Description</div>
    <div class="row">
      <div class="col-lg-6 col-12 mp-field">
        <label class="mp-lbl">Composition <span class="mp-req">*</span></label>
        <textarea name="comp" id="comp" rows=3 class="form-control" placeholder="Enter composition..." required><?=$comp;?></textarea>
      </div>
      <div class="col-lg-6 col-12 mp-field">
        <label class="mp-lbl">Description of Sample <span class="mp-req">*</span></label>
        <textarea name="decp" id="decp" rows=3 class="form-control" placeholder="Enter description..." required><?=$desp;?></textarea>
      </div>
    </div>
  </div>

  <?php if($_SESSION['par'] == 1) { ?>
  <div class="mp-reg-section">
    <div class="mp-reg-title"><i class="fas fa-vials"></i> Test Parameters</div>
    <div class="row">
      <div class="col-12 mp-field">
        <select class="par" multiple="multiple" name="parid[]" id="parid" required>
        <?php
          $qry=mysqli_query($connection,"SELECT id,parameter FROM mstestparametertable WHERE status=1 ORDER BY ranking ASC");
          while(list($id,$val)=mysqli_fetch_row($qry)){
            echo("<option value='$id'>$val</option>");
          }
        ?>
        </select>
      </div>
    </div>
  </div>
  <?php } ?>

  <div class="mp-reg-section">
    <div class="mp-reg-title"><i class="fas fa-comment-dots"></i> Remarks</div>
    <div class="row">
      <div class="col-12 mp-field">
        <label class="mp-lbl">Remarks</label>
        <textarea name="rmk" id="rmk" rows=3 class="form-control" placeholder="Enter remarks..."><?=$rmk;?></textarea>
      </div>
    </div>
  </div>

</div>

											<?php if($_SESSION['par'] == 2){	echo("<input type='hidden' name='regid' value='$regid'/>");	} ?>
											<div class="mp-reg-footer d-flex justify-content-between align-items-center flex-wrap">
												<a href="manager.php" class="btn bg-gradient-secondary mp-back-btn"><i class="fas fa-arrow-left"></i>&nbsp;Back to List</a>
												<?php if(mpq_can_do('registration', ($_SESSION['par'] == 2) ? 'edit' : 'add') && $frslt == "") { /* effective-role RBAC */ ?>
												<button type="submit" class="btn bg-gradient-success mp-reg-save"><i class="fas fa-save"></i>&nbsp;<?=$btnlbl?></button>
												<?php } ?>
											</div>
										</div>
									</form>
								</div>
							</div>	
						</div>	
					</div>
				</section>
			
			<?php 
				if($_SESSION['par'] == 2) { 
				if($frslt == "") { 	
			?>	
				<!-- Remove and Add Parameter -->
				<section class="content">
					<div class="container-fluid">
				        <div class="row">
				          <div class="col-md-6 col-12">
				            <div class="card card-outline card-info collapsed-card">
					            <div class="card-header">
					            	<h3 class="card-title"><i class="fas fa-tasks text-info"></i> &nbsp;<b>Parameters Selected</b></h3>
					                <div class="card-tools">
					                  <button type="button" class="btn btn-tool" data-card-widget="maximize">
					                    <i class="fas fa-expand"></i>
					                  </button>
					                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
					                    <i class="fas fa-plus"></i>
					                  </button>
					                </div>
					              </div>

					              <div class="card-body">
					              	<table class="table table-bordered table-striped">
					              		<tbody>
					              			<?php 
					              				$chk = 0;
												if(mpq_can_do('parameters','delete')){
													$chk++;
												}
												/* RBAC: BFDA/DRA (acl 7) is read-only — no parameter add/remove. */
												if(!empty($mpLocked)){ $chk = 0; }   /* completed report: no parameter add/remove for anyone */

					              				$pqry = mysqli_query($connection,"SELECT id,parameter FROM mstestparametertable pt,labresulttable lt WHERE pt.id=lt.parameterID AND registrationID='$regid' ORDER BY ranking ASC");

					              				while(list($id,$par)=mysqli_fetch_row($pqry)){
													if($chk > 0){
														$str = "<a id='delete-report' data-id='$regid-$id' href='javascript:void(0)' title='Remove parameter'><i class='fas fa-trash-alt text-danger'></i></a>";
													}
													else $str = "";

													echo("<tr><td>$par</td><td class='text-center'>$str</td></tr>");
												}
					              			?>
					              		</tbody>
					              	</table>	
					              </div>
				            </div>
				          </div>
				       
				        <?php if($chk > 0){ ?>  
				          <div class="col-md-6 col-12">
				            <div class="card card-outline card-info collapsed-card">
					            <div class="card-header">
					            	<h3 class="card-title"><i class="fas fa-plus-circle text-info"></i> &nbsp;<b>Add Parameter</b></h3>
					                <div class="card-tools">
					                  <button type="button" class="btn btn-tool" data-card-widget="maximize">
					                    <i class="fas fa-expand"></i>
					                  </button>
					                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
					                    <i class="fas fa-plus"></i>
					                  </button>
					                </div>
					             </div>
					            	
								<div class="card-body">
									<form id="form1" class="form-horizontal" autocomplete="off" method="Post" action="save_parameter.php">	
										<input type="hidden" name='rid' value='<?=$regid;?>' required>
										<div class="row">
										  <div class="col-12">
											<div class="form-group">
											  <select class="par" multiple="multiple" name="newparid[]" id="newparid" required>
											<?php
												$qry=mysqli_query($connection,"SELECT id,parameter FROM mstestparametertable ORDER BY ranking ASC");
												while(list($id,$val)=mysqli_fetch_row($qry)){
													echo("<option value='$id'>$val</option>");
												}		
											?>	
											  </select>
											</div>
											<div class="form-group">
											  <input type="submit" value="Save Parameter" class="btn bg-gradient-success float-right">
											</div>
										  </div>
										</div>
									</form>	
								</div>
				            </div>
				          </div>
				        <?php } ?>  
				        </div>
					</div>		
				</section>	
			<?php 
				} 
				if(mpq_can_do('acceptance','submit') || mpq_can_do('parameters','add')){ /* effective-role RBAC */
			?>	
				
				<!-- Sample Accept/Reject -->
				<section class="content">
					<div class="container-fluid">
				        <div class="row">
				          <div class="col-12">
				            <div class="card card-outline card-warning collapsed-card">
					            <div class="card-header">
					            	<h3 class="card-title"><i class="fas fa-clipboard-check text-warning"></i> &nbsp;<b>Sample Acceptance</b></h3>
					                <div class="card-tools">
					                  <button type="button" class="btn btn-tool" data-card-widget="maximize">
					                    <i class="fas fa-expand"></i>
					                  </button>
					                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
					                    <i class="fas fa-plus"></i>
					                  </button>
					                </div>
					              </div>

					              <div class="card-body">
						            <form id="form2" class="form-horizontal" method="Post" autocomplete="off" action="save_update_details.php">  	
						              	<input type='hidden' name='rid' value="<?=$regid?>" required>
						              	<input type='hidden' name='type' value="ssts" required>
						            <?php 
						            	if($sts == "Accepted"){ $chk1 = "Checked"; $chk2 = ""; }
						            	else if($sts == "Rejected"){ $chk1 = ""; $chk2 = "Checked"; }
						            	else {	$chk1 = $chk2 = "";}
						             ?>  	
						              	<div class="form-group row">
											<label  class="col-sm-3 col-form-label-sm">Acceptance</label>
											<div class="col-sm-3">
												<div class="form-check-inline">
													<input class="form-check-input" type='radio' name='sts' value="Accepted" <?=$chk1;?> required>
													<label class="form-check-label">Accepted</label>
												</div>
												<div class="form-check-inline">
													<input class="form-check-input" type='radio' name='sts' value="Rejected" <?=$chk2;?> required>
													<label class="form-check-label">Rejected</label>
												</div>
											</div>
											<label class="col-sm-3 col-form-label-sm">Receive Date</label>
											<div class="col-sm-3 input-group date" data-target-input="nearest">
												<input type="text" class="form-control datetimepicker-input text-center" id="recDate" name="recDate" value="<?=$recDate;?>" required />
												<div class="input-group-append" data-target="#recDate" data-toggle="datetimepicker">
													<div class="input-group-text"><i class="fa fa-calendar"></i></div>
												</div>
											</div>
										</div>
										<!-- Oct 2026 — Receipt chain-of-custody. Both fields are optional on the
										     form so existing samples can be updated without them; save_update_details.php
										     stores them as NULL when blank. -->
										<div class="form-group row">
											<label class="col-sm-3 col-form-label-sm">Collected / Received By</label>
											<input type="text" name="received_by" id="received_by" class="col-sm-3 form-control" value="<?=htmlspecialchars((string)($received_by ?? ''));?>" placeholder="NMPTL staff taking custody">
											<label class="col-sm-3 col-form-label-sm">Sample Condition</label>
											<select name="sample_condition" id="sample_condition" class="col-sm-3 form-control">
												<option value="">Select Condition</option>
												<?php
													$mpCondOptions = array('Intact','Damaged','Temperature Compromised','Leaking','Seals Intact');
													foreach($mpCondOptions as $opt){
														$sel = ((string)($sample_condition ?? '') === $opt) ? ' selected' : '';
														echo '<option value="'.htmlspecialchars($opt).'"'.$sel.'>'.htmlspecialchars($opt).'</option>';
													}
												?>
											</select>
										</div>
										<div class="form-group row">
											<label  class="col-sm-3 col-form-label-sm">Remarks</label>
											<textarea name="rmks" id="rmks" class="col-sm-6 form-control" placeholder="Enter remarks..."><?=$stsrmks;?></textarea>
										</div>
									<?php if($rsltcnt != $apvcnt){ ?>
										<input type="submit" value="Update Status" class="btn bg-gradient-success float-right">
									<?php } ?>
									</form>
						          </div>
				            </div>
				          </div>
				        </div>
					</div>		
				</section>	
			
			<?php 
				if($sts == "Accepted"){ 
			?>	
				<!-- Test Result -->
				<section class="content">
					<div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card card-outline card-success collapsed-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-vials text-success"></i> &nbsp;<b>Test Result</b></h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="maximize"><i class="fas fa-expand"></i></button>
                            <button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>

                    <div class="card-body">
                        <form id="form3" class="form-horizontal" method="Post" autocomplete="off" action="update_result.php">
                            <input type="hidden" name="regid" value="<?=$regid;?>">
                            <!--
                                Oct 2026 — Test Result grid layout fix.
                                The outer .table-responsive gives Bootstrap's overflow-x:auto for the
                                horizontal scrollbar; the .mptr-grid class below pins realistic MIN-WIDTHS
                                per column so inputs render at a comfortable size and never squeeze.
                                `white-space: nowrap` on headers only — body cells still wrap textareas.
                                No change to field names, IDs, bindings or save flow.
                            -->
                            <style>
                                .mptr-grid { min-width: 1890px; }
                                .mptr-grid thead th { white-space: nowrap; vertical-align: middle; }
                                /* Per-column minimum widths so individual controls stay readable. */
                                .mptr-grid thead th:nth-child(1)  { min-width: 180px; }   /* Test */
                                .mptr-grid thead th:nth-child(2)  { min-width:  80px; }   /* Repetition */
                                .mptr-grid thead th:nth-child(3)  { min-width: 220px; }   /* Observation */
                                .mptr-grid thead th:nth-child(4)  { min-width: 180px; }   /* Standard */
                                .mptr-grid thead th:nth-child(5)  { min-width: 150px; }   /* Result */
                                .mptr-grid thead th:nth-child(6)  { min-width: 170px; }   /* Test Date */
                                .mptr-grid thead th:nth-child(7)  { min-width: 220px; }   /* Assigned Analyst */
                                .mptr-grid thead th:nth-child(8)  { min-width: 150px; }   /* Date Assigned */
                                .mptr-grid thead th:nth-child(9)  { min-width: 160px; }   /* Approval Status */
                                .mptr-grid thead th:nth-child(10) { min-width: 200px; }   /* Approved By */
                                .mptr-grid thead th:nth-child(11) { min-width: 180px; }   /* Remarks */
                                /* Test Date cell — the input-group must fill the cell without clipping
                                   the calendar icon append. */
                                .mptr-grid tbody td .input-group.date { flex-wrap: nowrap; width: 100%; }
                                .mptr-grid tbody td .input-group.date .form-control { min-width: 0; }
                                .mptr-grid tbody td .input-group.date .input-group-append { display: flex; }
                                .mptr-grid tbody td .input-group.date .input-group-text { height: 100%; display: flex; align-items: center; }
                                /* Form controls inside the grid line up on a single baseline. */
                                .mptr-grid tbody td > .form-control,
                                .mptr-grid tbody td > select.form-control { min-width: 100%; }
                            </style>
                            <div class="table-responsive">
                                <table class="table table-bordered mptr-grid">
                                    <thead>
                                        <tr class="text-center">
                                            <th>Test</th>
                                            <th>Repetition</th>
                                            <th>Observation</th>
                                            <th>Standard</th>
                                            <th>Result</th>
                                            <th>Test Date</th>
                                            <th title="Analyst assigned to perform this test (supervisor selects; was &quot;Tested By&quot;)">Assigned Analyst</th>
                                            <th title="Server timestamp recorded when the analyst was assigned">Date Assigned</th>
                                            <th>Approval Status</th>
                                            <th>Approved By</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $runqry = mysqli_query($connection, $rsltqry);

                                    while (list($rid, $par, $rpt, $unt, $dot, $tby, $obs, $std, $rslt, $appv, $aby, $rmk,
                                               $digital_signature, $signature_type, $signature_date, $signed_by,
                                               $assigned_analyst, $assigned_by, $date_assigned, $date_tested, $test_status) = mysqli_fetch_row($runqry)) {

                                        list($tbyname) = mysqli_fetch_row(mysqli_query($commondb, "SELECT fullname FROM user_profile_table WHERE username='$tby'"));
                                        list($abyname) = mysqli_fetch_row(mysqli_query($commondb, "SELECT fullname FROM user_profile_table WHERE username='$aby'"));
                                        if ($unt != "") $unt = "($unt)";

                                    ?>
                                        <tr>
                                            <td><small><input type="hidden" name="rid[]" value="<?=$rid;?>"/><?=$par;?><?=$unt;?></small></td>
                                            <td>
                                                <select name="rpt[]" class="form-control" aria-label="Repetition" title="Repetition">
                                                    <option value="<?=$rpt;?>"><?=$rpt;?></option>
                                                    <option value="1">1</option>
                                                    <option value="2">2</option>
                                                    <option value="3">3</option>
                                                </select>
                                            </td>
                                            <td><textarea name="obs[]" class="form-control" rows="2" placeholder="Observation"><?=$obs;?></textarea></td>
                                            <td><textarea name="std[]" class="form-control" rows="2" placeholder="Standard"><?=$std;?></textarea></td>
                                            <td>
                                                <select name="rslt[]" class="form-control" aria-label="Result" title="Result">
                                                    <option value="<?=$rslt;?>"><?=$rslt;?></option>
                                                    <option value="Complies">Complies</option>
                                                    <option value="Does not comply">Does not comply</option>
                                                    <option value="Inconclusive">Inconclusive</option>
                                                </select>
                                            </td>
                                            <td>
                                                <div class="input-group date" data-target-input="nearest">
                                                    <input type="text" name="dot[]" id="testDate<?=$rid;?>"
                                                           class="form-control datetimepicker-input text-center"
                                                           data-target="#testDate<?=$rid;?>"
                                                           value="<?=mpq_date_display($dot);?>" placeholder="yyyy-mm-dd"/>
                                                    <div class="input-group-append" data-target="#testDate<?=$rid;?>" data-toggle="datetimepicker">
                                                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
// Oct 2026 — Assigned Analyst dropdown is now driven by the admin-managed
// analyst_master table instead of a hard-coded WHERE IN(...). Each master
// name is resolved to a user_profile_table.username when one exists, so the
// stored value in testedBy / assigned_analyst_id keeps its original
// username semantics; rows without a matching user still render, carrying
// the master name as their value (readers display it verbatim).
$mp_analyst_opts = array();
$mAnalyst = @mysqli_query($connection, "SELECT name FROM analyst_master WHERE status=1 ORDER BY name ASC");
if($mAnalyst){
    while(list($anm) = mysqli_fetch_row($mAnalyst)){
        $u = @mysqli_query($commondb, "SELECT username FROM user_profile_table WHERE fullname='".mysqli_real_escape_string($commondb, (string)$anm)."' LIMIT 1");
        $uname = ($u && ($r = mysqli_fetch_row($u))) ? (string)$r[0] : '';
        $mp_analyst_opts[] = array(($uname !== '' ? $uname : (string)$anm), (string)$anm);
    }
}
?>

<!--
    Form field name kept as "tby[]" so update_result.php processing stays stable;
    the Oct-2026 assignment flow writes the posted value into BOTH the legacy
    `testedBy` column (so the PDF report + view page continue to render it) AND
    the new `assigned_analyst_id` column (so audit queries + traceability work).
    When the supervisor changes this dropdown, update_result.php stamps
    `date_assigned = NOW()` and records an ASSIGN / REASSIGN audit row.
-->
<select name="tby[]" class="form-control" aria-label="Assigned Analyst" title="Assigned Analyst (supervisor selects)">
    <option value="">Select Analyst</option>

    <?php
    // Prefer the already-stored assignment when present; fall back to the legacy
    // `testedBy` for rows written before the Oct-2026 migration backfilled them.
    $mp_sel_analyst = ($assigned_analyst !== null && trim((string)$assigned_analyst) !== '')
                      ? $assigned_analyst : $tby;
    foreach ($mp_analyst_opts as $opt) {
        list($uid, $usr) = $opt;
        $selected = ($uid == $mp_sel_analyst) ? "selected" : "";
        echo "<option value='" . htmlspecialchars($uid) . "' $selected>"
           . htmlspecialchars($usr)
           . "</option>";
    }
    ?>
</select>
                                            </td>
                                            <td>
                                                <?php
                                                // Date Assigned — read-only timestamp. Set automatically by
                                                // update_result.php the first time an analyst is chosen for
                                                // this row, and updated on every reassignment. Empty for rows
                                                // that have no analyst yet.
                                                $mp_date_asg_disp = ($date_assigned !== null && trim((string)$date_assigned) !== '' && $date_assigned !== '0000-00-00 00:00:00')
                                                    ? date('Y-m-d H:i', strtotime($date_assigned)) : '';
                                                ?>
                                                <input type="text" class="form-control text-center" value="<?=htmlspecialchars($mp_date_asg_disp);?>" placeholder="&mdash;" readonly aria-label="Date Assigned" title="Server timestamp recorded when the analyst was assigned (read-only)">
                                            </td>
                                            <td>
                                                <select name="appv[]" class="form-control" aria-label="Approval Status" title="<?=mpq_can_do('results','approve') ? 'Approval Status' : 'Approval Status (Administrator only)';?>"<?php if(!mpq_can_do('results','approve')){ echo ' disabled'; } ?>>
                                                    <option value="<?=$appv;?>"><?=$appv;?></option>
                                                    <?php if (mpq_can_do('results','approve')) { ?>
                                                        <option value="Approved">Approved</option>
                                                        <option value="Disapproved">Disapproved</option>
                                                        <option value="Pending">Pending</option>
                                                    <?php } ?>
                                                </select>
                                            </td>
                                            <td>
                                                <?php
                                                // Oct 2026 — Approved By dropdown is driven by the admin-managed
                                                // approver_master table instead of a static access_level=1 query.
                                                // Each name is resolved to a user_profile_table.username when one
                                                // exists so the stored value in approvedBy keeps its original
                                                // username semantics; rows without a matching user still render.
                                                $mp_approver_opts = array();
                                                $mApv = @mysqli_query($connection, "SELECT name FROM approver_master WHERE status=1 ORDER BY name ASC");
                                                if($mApv){
                                                    while(list($anm) = mysqli_fetch_row($mApv)){
                                                        $u = @mysqli_query($commondb, "SELECT username FROM user_profile_table WHERE fullname='".mysqli_real_escape_string($commondb, (string)$anm)."' LIMIT 1");
                                                        $uname = ($u && ($r = mysqli_fetch_row($u))) ? (string)$r[0] : '';
                                                        $mp_approver_opts[] = array(($uname !== '' ? $uname : (string)$anm), (string)$anm);
                                                    }
                                                }
                                                ?>
                                                <select name="apvby[]" class="form-control" aria-label="Approved By" title="<?=mpq_can_do('results','approve') ? 'Approved By' : 'Approved By (Administrator only)';?>"<?php if(!mpq_can_do('results','approve')){ echo ' disabled'; } ?>>
                                                    <option value="<?=$aby;?>"><?=$abyname;?></option>
                                                    <?php foreach ($mp_approver_opts as $opt) {
                                                        list($uid, $usr) = $opt;
                                                        echo "<option value='".htmlspecialchars($uid)."'>".htmlspecialchars($usr)."</option>";
                                                    } ?>
                                                </select>
                                            </td>
                                            <td><textarea name="rmk[]" class="form-control" rows="2" placeholder="Remarks"><?=$rmk;?></textarea></td>
                                        </tr>
                                    <?php } // end while – NO signature code inside the loop any more ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- The Digital Signature was relocated to the Final Result card (form4). -->
                            <?php if (mpq_can_do('results','edit') && empty($mpLocked)) { /* effective-role RBAC + completed-report lock */ ?>
                                <input type="submit" value="Update Results" class="btn bg-gradient-success float-right mt-3">
                            <?php } ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
					<!-- Signature Modal -->
<div class="modal fade" id="signatureModal" tabindex="-1" role="dialog" aria-labelledby="signatureModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title" id="signatureModalLabel">
                    <i class="fas fa-pen-fancy"></i> Digital Signature
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="signatureForm" enctype="multipart/form-data">
                    <input type="hidden" name="result_id" id="sig_result_id">
                    <input type="hidden" name="regid" value="<?=$regid;?>">
                    
                    <!-- Signature Type Selection: both options are always visible -->
                    <div class="form-group">
                        <label>Signature Method <span class="text-danger">*</span></label>
                        <div class="btn-group btn-group-toggle w-100" data-toggle="buttons" id="sigMethodGroup">
                            <label class="btn btn-outline-primary active" id="drawBtn">
                                <input type="radio" name="sig_method" value="draw" checked autocomplete="off">
                                <i class="fas fa-pencil-alt"></i> Draw Signature
                            </label>
                            <label class="btn btn-outline-success" id="uploadBtn">
                                <input type="radio" name="sig_method" value="upload" autocomplete="off">
                                <i class="fas fa-upload"></i> Upload Image
                            </label>
                        </div>
                    </div>

                    <!-- Draw Signature Area -->
                    <div id="drawArea">
                        <div class="form-group">
                            <label>Sign Below <span class="text-danger">*</span></label>
                            <div class="signature-wrapper" style="border:2px solid #ccc; border-radius:5px; position:relative; background:#fff;">
                                <canvas id="signatureCanvas" width="700" height="200" style="display:block; width:100%; height:200px; cursor:crosshair; touch-action:none; user-select:none;"></canvas>
                            </div>
                            <div class="signature-controls mt-2">
                                <button type="button" class="btn btn-sm btn-danger" id="clearSignature">
                                    <i class="fas fa-eraser"></i> Clear
                                </button>
                                <button type="button" class="btn btn-sm btn-info" id="undoSignature">
                                    <i class="fas fa-undo"></i> Undo
                                </button>
                            </div>
                            <small class="form-text text-muted">Use your mouse, pen or touch to draw your signature</small>
                        </div>
                    </div>

                    <!-- Upload Signature Area -->
                    <div id="uploadArea" style="display:none;">
                        <div class="form-group">
                            <label>Upload Signature Image <span class="text-danger">*</span></label>
                            <div class="custom-file" id="sigFilePicker">
                                <input type="file" class="custom-file-input" id="sigImageInput" name="sig_image" accept="image/png,image/jpeg,image/gif">
                                <label class="custom-file-label" for="sigImageInput" id="sigImageLabel">Choose signature image...</label>
                            </div>
                            <small class="form-text text-muted">Supported formats: PNG, JPG, JPEG, GIF (max 2MB)</small>
                            <div id="imagePreview" class="mt-2" style="display:none;">
                                <img id="sigPreview" src="#" alt="Signature Preview" style="max-height:150px; max-width:100%; border:1px solid #ddd; border-radius:5px; padding:5px; background:#fff;">
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="removeSigImage"><i class="fas fa-times"></i> Remove image</button>
                                    <small class="text-muted ml-2">Remove to upload a different image or switch to Draw Signature.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Signer Name -->
                    <div class="form-group">
                        <label>Signer's Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="signer_name" id="signerName" 
                               placeholder="Enter full name" required>
                    </div>

                    <!-- Signature Date -->
                    <div class="form-group">
                        <label>Signature Date</label>
                        <input type="text" class="form-control datetimepicker-input text-center" 
                               id="sigDate" name="sig_date" value="<?=date('Y-m-d H:i:s');?>">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveSignature">
                    <i class="fas fa-save"></i> Save Signature
                </button>
            </div>
        </div>
    </div>
</div>		
				</section>
			<?php 
				//if($rsltcnt == $apvcnt){
				// SRS workflow gate (1.5 step 4->5, 4.2, 6.6): RCDC/NDTL lab users may view/prepare the
				// Final Result only AFTER the Administrator has verified (approved/disapproved) every
				// test result. The Administrator (acl 1) performs that verification and is not gated.
				// Server-side twin: save_update_details.php (type=frslt). Shared rule: report_lock_lib.php.
				$mpVer = mpqms_results_verification($connection, (int)$regid);
				$mpFinalOpen = mpq_can_do('final_result','finalize')
					&& (mpq_effective_role() === 'ADMIN' || !empty($mpVer['verified']));
			 ?>	
				<!-- Final -->
				<section class="content">
					<div class="container-fluid">
				        <div class="row">
				          <div class="col-12">
				            <div class="card card-outline card-primary collapsed-card">
					            <div class="card-header">
					            	<h3 class="card-title"><i class="fas fa-check-circle text-primary"></i> &nbsp;<b>Final Result</b></h3>
					                <div class="card-tools">
					                  <button type="button" class="btn btn-tool" data-card-widget="maximize">
					                    <i class="fas fa-expand"></i>
					                  </button>
					                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
					                    <i class="fas fa-plus"></i>
					                  </button>
					                </div>
					              </div>

					              <div class="card-body">
						            <?php if($mpFinalOpen){ ?><form id="form4" class="form-horizontal" method="Post" autocomplete="off" action="save_update_details.php">  	
						              	<input type='hidden' name='rid' value="<?=$regid?>" required>
						              	<input type='hidden' name='type' value="frslt" required>
						            <?php 
						            	if($frslt == "Complies"){ $chk1 = "Checked"; $chk2 = ""; $chk3 = "";}
						            	else if($frslt == "Does not comply"){ $chk1 = ""; $chk2 = "Checked"; $chk3 = "";}
						            	else if($frslt == "Inconclusive"){ $chk1 = ""; $chk2 = ""; $chk3 = "Checked";}
						            	else {	$chk1 = $chk2 = $chk3 = "";}
						             ?>  
						              	<div class="form-group row">
											<label  class="col-sm-2 col-form-label-sm">Result</label>
											<div class="col-sm-3">
												<div class="form-check-inline">
													<input class="form-check-input" type='radio' name='rslt' value="Complies" <?=$chk1;?> required>
													<label class="form-check-label">Complies</label>
												</div>
												<div class="form-check-inline">
													<input class="form-check-input" type='radio' name='rslt' value="Does not comply" <?=$chk2;?> required>
													<label class="form-check-label">Does not comply</label>
												</div>
												<div class="form-check-inline">
													<input class="form-check-input" type='radio' name='rslt' value="Inconclusive" <?=$chk3;?> required>
													<label class="form-check-label">Inconclusive</label>
												</div>
											</div>
											
											<label  class="col-sm-2 col-form-label-sm">Remarks</label>
											<textarea name="rmks" id="rmks" class="col-sm-4 form-control" placeholder="Enter remarks..."><?=$frmk;?></textarea>
										</div>
<!-- ========== DIGITAL SIGNATURE (Final Result) ========== -->
<?php
	// The signature authorises the FINAL RESULT. It is staged here by the signature modal and
	// persisted by save_update_details.php (type=frslt) in the same transaction as the Final
	// Result — never by any other action. Preview = the signature currently stored for this
	// registration (signature_lib.php :: mpqms_signature_current).
	require_once(__DIR__."/signature_lib.php");
	$mpSigCur = mpqms_signature_current($connection, (int)$regid);
?>
<div class="row mt-3">
    <div class="col-lg-6 col-md-8 col-12">
        <label class="col-form-label-sm font-weight-bold">Digital Signature (Final Result)</label>
        <input type="hidden" name="sig_pending_method" id="sigPendingMethod" value="">
        <input type="hidden" name="sig_pending_signer" id="sigPendingSigner" value="">
        <input type="hidden" name="sig_pending_date"   id="sigPendingDate"   value="">
        <input type="hidden" name="sig_pending_data"   id="sigPendingData"   value="">
        <div class="p-3 border rounded bg-light text-left mp-signature-box">

            <div class="mb-2">
            </div>

            <?php if ($mpSigCur !== null): ?>

                <div class="mp-signature-preview">

                    <img src="<?= htmlspecialchars($mpSigCur['data']) ?>"
                         class="mp-signature-image"
                         title="Signed by <?= htmlspecialchars((string)$mpSigCur['by']) ?> on <?= htmlspecialchars((string)$mpSigCur['date']) ?>">

                </div>

                <div class="mt-2">
                    <small class="text-success d-block">
                        Signed by
                        <strong><?= htmlspecialchars((string)$mpSigCur['by']) ?></strong>
                        on
                        <?= htmlspecialchars((string)$mpSigCur['date']) ?>
                    </small>
                </div>

                <?php if(empty($mpLocked) && mpq_can_do('final_result','finalize')): /* completed report: signature is final; only a role that may finalise can submit the form it travels with */ ?>
                <button type="button"
                        class="btn btn-sm btn-outline-secondary mt-2 add-signature-btn"
                        data-toggle="modal"
                        data-target="#signatureModal">
                    <i class="fas fa-edit"></i>
                    Change Signature
                </button>
                <?php endif; ?>

            <?php else: ?>

                <?php if(empty($mpLocked) && mpq_can_do('final_result','finalize')): /* completed report: no new signature; only a role that may finalise can submit the form it travels with */ ?>
                <button type="button"
                        class="btn btn-primary add-signature-btn"
                        data-toggle="modal"
                        data-target="#signatureModal">
                    <i class="fas fa-pen-fancy"></i>
                    Add Digital Signature
                </button>
                <?php endif; ?>

            <?php endif; ?>

        </div>
        <small class="form-text text-muted">The signature is saved together with the Final Result when you click <b>Update Final Result</b>.</small>
    </div>
</div>
<!-- ========== END DIGITAL SIGNATURE (Final Result) ========== -->
										<?php if(mpq_can_do('final_result','finalize') && empty($mpLocked)){ /* effective-role RBAC + completed-report lock */ ?>
										<input type="submit" value="Update Final Result" class="btn bg-gradient-success float-right">
										<?php } ?>
									</form><?php } else { /* lab user: Final Result held until Administrator verification completes */ ?>
									<div class="callout callout-warning mb-0">
										<h5 class="mb-1"><i class="fas fa-user-clock"></i>&nbsp;Awaiting Administrator approval</h5>
										<p class="mb-0"><?=htmlspecialchars(mpqms_final_result_pending_message($mpVer));?></p>
									</div>
									<?php } ?>

						          </div>
				            </div>
				          </div>
				        </div>
					</div>		
				</section>
			<?php  } } } ?>
			</div>
		<?php include_once("../includes/footer.php"); ?>
		</div>

		<!-- modern collapsed multi-select for the Test Parameters section (presentation only) -->
		<script src="../dist/js/mpqms-select2multi.js?v=20261012d"></script>
		<script>
	var mpRegId = <?= (isset($regid) && $_SESSION['par']==2) ? (int)$regid : 0 ?>;

	function mpToast(icon, text){ Swal.fire({ icon: icon, text: text, timer: 1600, showConfirmButton: false }); }

	// Reload ONLY the content area (no full page reload), then re-init widgets/handlers.
	function mpReloadContent(){
		var y = $(window).scrollTop();
		$('.content-wrapper').load(window.location.href + ' .content-wrapper > *', function(){
			mpInitReg();
			$(window).scrollTop(y);
		});
	}

	// A failed submission must be unmistakable: a persistent dialog (no auto-dismiss) that
	// states the record was NOT saved and gives the reason. Success keeps the brief toast.
	function mpSubmitError(reason){
		Swal.fire({ icon: 'error', title: 'Not saved', text: reason, confirmButtonText: 'Close', showCloseButton: true, allowOutsideClick: false });
	}
	// Explain a transport-level failure (no usable JSON came back) in the user's terms.
	function mpExplainFailure(xhr){
		var txt = (xhr && xhr.responseText) ? String(xhr.responseText) : '';
		// A PHP notice printed ahead of the JSON breaks parsing; salvage the JSON if present.
		var i = txt.lastIndexOf('{"status"');
		if(i >= 0){ try { var j = JSON.parse(txt.slice(i)); if(j && j.message){ return j.message; } } catch(e){} }
		if(/login\.php|name="password"|Sign in/i.test(txt) || (xhr && xhr.status === 0)){
			return 'Your session has expired or you are no longer signed in. The record was NOT saved. Please sign in again and resubmit.';
		}
		if(xhr && xhr.status >= 500){ return 'The server reported an error (HTTP ' + xhr.status + '). The record was NOT saved. Please try again; if it persists, contact the administrator.'; }
		if(xhr && xhr.status === 403){ return 'You are not permitted to perform this action. The record was NOT saved.'; }
		if(xhr && xhr.status === 404){ return 'The save service could not be found (HTTP 404). The record was NOT saved.'; }
		return 'The server returned an unexpected response' + (xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '') + '. The record was NOT saved. Please try again.';
	}
	// Duplicate-submission guard: the moment a form is submitted its submit control(s) are
	// disabled and a busy flag is set, so repeated clicks or Enter presses cannot fire a second
	// request while the first is in flight. The control is restored when the save FAILS (so the
	// user can correct and retry). On success the page redirects (new record) or reloads its
	// content, so the control is released only where the form stays on screen.
	function mpSubmit($form, afterSuccess){
		if($form.data('mpBusy')){ return; }
		var data  = $form.serialize() + '&ajax=1';          // capture before touching any control
		var $btns = $form.find('button[type="submit"], input[type="submit"]');
		var saved = $btns.map(function(){ return $(this).is('button') ? $(this).html() : $(this).val(); }).get();
		$form.data('mpBusy', true);
		$btns.prop('disabled', true).each(function(){
			if($(this).is('button')){ $(this).html('<i class="fas fa-spinner fa-spin"></i>&nbsp;Saving...'); } else { $(this).val('Saving...'); }
		});
		var release = function(){
			$form.data('mpBusy', false);
			$btns.prop('disabled', false).each(function(i){ if($(this).is('button')){ $(this).html(saved[i]); } else { $(this).val(saved[i]); } });
		};
		$.post($form.attr('action'), data, function(res){
			if(res && res.status === 'success'){
				mpToast('success', res.message || 'Saved successfully!');
				afterSuccess(res);
				if(!res.isnew){ release(); }              // a new record redirects away; keep it locked until then
			}
			else { release(); mpSubmitError((res && res.message) || 'The record could not be saved. Please check the form and try again.'); }
		}, 'json').fail(function(xhr){ release(); mpSubmitError(mpExplainFailure(xhr)); });
	}

	var mpValidateCfg = {
		errorElement: 'span',
		errorPlacement: function (error, element) { error.addClass('invalid-feedback'); element.closest('.form-group').append(error); },
		highlight: function (el) { $(el).addClass('is-invalid'); },
		unhighlight: function (el) { $(el).removeClass('is-invalid'); }
	};
	function mpBindForm(sel, afterSuccess){
		var $f = $(sel); if(!$f.length) return;
		$f.validate($.extend({}, mpValidateCfg, { submitHandler: function(form){ mpSubmit($(form), afterSuccess); return false; } }));
	}

	// Param delete — delegated so it survives content reloads (bound once).
	$(document).on('click', '#delete-report', function(e){ e.preventDefault(); mpDeleteParam($(this).data('id')); });
	function mpDeleteParam(rid){
		Swal.fire({ title:'Do you really want to delete this parameter along with result?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', cancelButtonColor:'#3085d6', confirmButtonText:'Yes, delete it!', allowOutsideClick:false })
		.then(function(result){
			if(result.isConfirmed){
				$.ajax({ url:'delete.php', type:'POST', data:{ rid:rid, rtyp:'P' }, dataType:'json' })
				.done(function(response){ mpToast((response&&response.status)||'success', (response&&response.message)||'Deleted.'); mpReloadContent(); })
				.fail(function(){ mpToast('error','Unable to delete. Please try again.'); });
			}
		});
	}

	function mpInitWarnings(){
		// Batch-duplicate and short-expiry warnings are surfaced as professional fixed TOP TOAST
		// notifications (toastr), not an inline banner. The detection logic is unchanged (AJAX
		// duplicate check, calendar-based 6-month calc, stale-response sequence guard, error
		// handling); only the rendering target changed. Each condition toasts once per distinct
		// value so repeated checks (blur / debounced keyup / change) do not spam the user.
		if(window.toastr){ toastr.options = { closeButton:true, progressBar:true, positionClass:'toast-top-right', preventDuplicates:true, newestOnTop:true, timeOut:7000, extendedTimeOut:2500 }; }
		var lastBatchWarn = null, lastExpiryWarn = null;
		function chkBatch(){
			var b = $.trim($('#batch').val());
			if(b === ''){ lastBatchWarn = null; return; }
			var seq = ++batchSeq;   // sequence token — only the latest check is allowed to act.
			$.getJSON('check_batch.php', { batch:b, rid:mpRegId })
			.done(function(res){
				if(seq !== batchSeq){ return; }   // a newer batch check superseded this one -> discard the stale response
				if(res && res.exists){
					if(lastBatchWarn !== b && window.toastr){   // only notify when the duplicate condition is newly entered
						lastBatchWarn = b;
						toastr.warning('Batch No. "'+b+'" is already registered in '+res.count+' earlier record'+(res.count>1?'s':'')+'. Please verify the batch number before continuing.', 'Duplicate Batch Number');
					}
				} else { lastBatchWarn = null; }
			})
			.fail(function(){
				if(seq !== batchSeq){ return; }   // stale failure -> ignore
				// Never silently assume the batch is free when the check itself failed — surface a safe system message.
				lastBatchWarn = null;             // allow a retry to re-notify
				if(window.toastr){ toastr.error('Unable to verify the batch number right now. Please check your connection and re-enter the batch number to retry.', 'Batch Check Failed'); }
			});
		}
		function chkExpiry(){
			var v = $.trim($('#doe').val());
			if(v === '' || v === '1900-01-01'){ lastExpiryWarn = null; return; }
			var exp = moment(v, ['YYYY-MM-DD','DD-MM-YYYY','MM/DD/YYYY'], true); if(!exp.isValid()){ exp = moment(v); }
			if(!exp.isValid()){ lastExpiryWarn = null; return; }
			var today = moment().startOf('day'), six = moment(today).add(6,'months');
			var key = exp.format('YYYY-MM-DD');
			if(exp.isBefore(today)){
				if(lastExpiryWarn !== 'exp:'+key && window.toastr){ lastExpiryWarn = 'exp:'+key; toastr.error('This product has already expired (Expiry: '+exp.format('DD-MM-YYYY')+'). Please verify the expiry date before continuing.', 'Expired Product'); }
			} else if(exp.isBefore(six)){
				if(lastExpiryWarn !== 'short:'+key && window.toastr){ lastExpiryWarn = 'short:'+key; toastr.warning('Expiry Date is less than 6 months away (Expiry: '+exp.format('DD-MM-YYYY')+'). Please verify the expiry date before continuing.', 'Short Expiry (< 6 Months)'); }
			} else { lastExpiryWarn = null; }
		}
		var t, batchSeq = 0;
		$('#batch').off('.mpw').on('blur.mpw change.mpw', chkBatch).on('keyup.mpw', function(){ clearTimeout(t); t=setTimeout(chkBatch,400); });
		$('#doe').off('.mpw').on('change.mpw blur.mpw change.datetimepicker', chkExpiry);
		// Do NOT run the duplicate-batch check on load — in Edit mode the batch field is pre-filled
		// with the record's own (unchanged) value, so it must only run when the user actually edits
		// the Batch No. (blur / change / keyup above). Expiry still evaluates on load.
		chkExpiry();
	}

	// (Re)initialise all in-content widgets, cascade handlers and AJAX form submitters.
	function mpInitReg(){
		try {
			$("#dzo").off('change.mp').on('change.mp', function(){
				var inex=$('#inex').val(), did=$('#dzo').val(), dor=$('#dor').val();
				$("#sitediv").load("../load_site.php?par1="+did+"&par2=S", function(){ $('#site').select2({ theme:'bootstrap4' }); });
				$("#siddiv").load("generate_sid.php?par1="+did+"&par2="+dor+"&par3="+inex);
			});
			// Oct 2026 — Appellate Laboratory shape depends on In/Ex-country.
			//   In-country -> AJAX dropdown (defaults to NMPTL inside load_lab.php).
			//   Ex-country -> free-text input so an overseas lab name can be typed.
			$("#inex").off('change.mp').on('change.mp', function(){
				var v = $('#inex').val();
				if(v === 'Ex-country'){
					$("#labdiv").html('<input type="text" name="lab" id="lab" class="form-control" value="" placeholder="Enter overseas laboratory name" required>');
				} else if(v === 'In-country'){
					$("#labdiv").load("load_lab.php?par1="+encodeURIComponent(v)+"&t="+Date.now());
				} else {
					$("#labdiv").html('<select name="lab" id="lab" class="form-control" required><option value="">Select Laboratory</option></select>');
				}
			});
			// Oct 2026 — Sample Type is DB-driven (mssampletypetable). Load options on
			// first render; after swap, selecting a type re-fires the existing
			// batch-duplicate / short-expiry checks on #batch and #doe so warnings
			// surface against the newly chosen type (chkBatch / chkExpiry live inside
			// mpInitWarnings' closure and listen to .mpw-namespaced events).
			(function(){
				var curSt = $('#sample_type').val() || '';
				$('#stdiv').load("load_sample_type.php?sel="+encodeURIComponent(curSt)+"&t="+Date.now(), function(){
					$('#sample_type').off('change.mp').on('change.mp', function(){
						$('#batch').trigger('change.mpw');
						$('#doe').trigger('change.mpw');
					});
				});
			})();
			// Oct-13 2026 — Collected By now lists every ndtlis facility (plus the
			// new NMPTL option). We AJAX-load it once on page init with par1=All,
			// then re-select whatever value was stored on this registration so edit
			// mode never loses the current choice. The old "Collected By (District)"
			// cascade handler is intentionally gone — there is no cdzo element.
			(function(){
				var curCby = $('#cby').val() || '';
				$('#cbydiv').load('../load_site.php?par1=All&par2=C&t=' + Date.now(), function(){
					if(curCby !== ''){ $('#cby').val(curCby); }
					$('#cby').select2({ theme: 'bootstrap4' });
				});
			})();
			$("#dor").off('change.mp').on('change.mp', function(){ var inex=$('#inex').val(), did=$('#dzo').val(), dor=$('#dor').val(); $("#siddiv").load("generate_sid.php?par1="+did+"&par2="+dor+"&par3="+inex); });
			// Oct 2026 — reveal "Sampling Organization (Other)" input only when the user picks "Other".
			$("#sampling_organization").off('change.mp').on('change.mp', function(){
				var isOther = ($(this).val() === 'Other');
				$("#sampling_org_other_div").toggle(isOther);
				if(!isOther){ $("#sampling_organization_other").val(''); }
			});
		} catch(e){}
		try { if(window.mpqmsMultiselectDropdown){ mpqmsMultiselectDropdown('#parid','Test Parameters'); mpqmsMultiselectDropdown('#newparid','Test Parameters'); } else { $('.par').bootstrapDualListbox(); } } catch(e){}
		try { $('.select2bs4').select2({ theme:'bootstrap4' }); } catch(e){}
		// =====================================================================
		// Oct-13 2026 — Datetimepicker — FINAL: native library positioning.
		//
		// Every custom placer (body-reparent, strip, setProperty-important,
		// pid tagging, show/shown/scroll/resize re-pin) has been REMOVED.
		// They introduced races that could run against a stale
		// getBoundingClientRect and park the popup over the sidebar.
		//
		// We now trust Tempus Dominus v4's native positioning: the popup is
		// rendered inside its own .input-group.date, Bootstrap's
		// `.input-group { position: relative }` is the containing block, and
		// the popup is position:absolute at top:100%; left:0 of that box =
		// directly under the input. The ONLY extras we still need:
		//
		//   • destroy + fresh init on every mpInitReg() call so AJAX reloads
		//     don't orphan instances.
		//   • a document-delegated click trigger so clicking the input OR the
		//     calendar-icon append always opens the picker.
		//   • add `.mp-dtp-open` to the surrounding .table-responsive while
		//     the picker is open so the result grid's overflow-x:auto doesn't
		//     clip the Test Date popup. CSS block below carries the override.
		//
		// Format and useCurrent:false are preserved (empty-by-default input,
		// YYYY-MM-DD server contract). No business logic touched.
		// =====================================================================
		// Registration-form pickers (#dor, #doe, #dom, #sampling_date, #recDate)
		// use native inline positioning — the popup lives inside the field's
		// own `.input-group.date`, which is NOT in a scroll container, so the
		// vendor positions it correctly on its own.
		var MPDTP_OPTS = { format: 'YYYY-MM-DD', useCurrent: false };

		// Test Date pickers inside the Test Result grid use the SAME native
		// positioning (no widgetParent, no manual placement). The result card
		// sits well below the fold, so the vendor's auto-flip would place the
		// popup below the input and force page scrolling. widgetPositioning
		// 'top' prefers opening ABOVE the input, keeping the compact calendar
		// fully inside the current viewport. horizontal 'auto' flips sides
		// near the viewport edge. `.mp-dtp-open` on the surrounding
		// .table-responsive handles the overflow clip.
		var MPDTP_TEST_OPTS = {
			format: 'YYYY-MM-DD',
			useCurrent: false,
			widgetPositioning: { vertical: 'top', horizontal: 'auto' }
		};

		function mpDtpInit($input, opts){
			if(!$input || !$input.length){ return; }
			try {
				var api = $input.data('DateTimePicker');
				if(api){ try { api.destroy(); } catch(e){} }
				$input.datetimepicker(opts || MPDTP_OPTS);
			} catch(e){}
		}
		$('#dor,#recDate,#dom,#doe,#sampling_date').each(function(){ mpDtpInit($(this), MPDTP_OPTS); });
		$('input[name="dot[]"]').each(function(){ mpDtpInit($(this), MPDTP_TEST_OPTS); });

		// Click delegation — the input OR the calendar-icon append both open
		// the picker. Delegated at document so dynamically-added rows work too.
		$(document).off('click.mpdtptrig')
			.on('click.mpdtptrig', '.input-group.date .input-group-append, .input-group.date > input.datetimepicker-input', function(){
				var api = $(this).closest('.input-group.date').find('input.datetimepicker-input').first().data('DateTimePicker');
				if(api){ try { api.toggle(); } catch(e){} }
			});

		// Overflow escape (every picker) + explicit positioning for Test Date.
		// CSS has `.table-responsive.mp-dtp-open { overflow: visible }`.
		//
		// Form-level pickers (#dor, #doe, #dom, #sampling_date, #recDate) use
		// NATIVE library positioning — the popup lives inside its own
		// .input-group.date and the vendor positions it there correctly.
		//
		// Test Date pickers use widgetParent:'body' (so .table-responsive can
		// no longer clip the popup OR add a scrollbar). Because the vendor's
		// own positioning math is unreliable once the popup is appended to
		// body (it was computed against the input-group's offsetParent chain),
		// we take over positioning JUST for the body-attached popups via
		// getBoundingClientRect() + page scroll offsets. This is identical to
		// the Sampling-Date / Expiry-Date visual, as asked.
		// Oct-14 2026 — minimal event wiring. Form pickers and Test Date
		// pickers both use native library positioning now; the ONLY extra
		// here is the `.mp-dtp-open` toggle so the result grid's
		// .table-responsive stops clipping while the popup is open. Vendor
		// positions the popup inside the field's own .input-group.date;
		// widgetPositioning on Test Date prefers opening above.
		$(document).off('show.datetimepicker.mpdtp hide.datetimepicker.mpdtp')
			.on('show.datetimepicker.mpdtp', 'input.datetimepicker-input', function(){ $(this).closest('.table-responsive').addClass('mp-dtp-open'); })
			.on('hide.datetimepicker.mpdtp', 'input.datetimepicker-input', function(){ $(this).closest('.table-responsive').removeClass('mp-dtp-open'); });
		try {
			mpBindForm('#form0', function(res){ if(res && res.isnew){ window.location='manager.php'; } else { mpReloadContent(); } });
			mpBindForm('#form1', function(){ mpReloadContent(); });
			mpBindForm('#form2', function(){ mpReloadContent(); });
			mpBindForm('#form3', function(){ mpReloadContent(); });
			mpBindForm('#form4', function(){ mpReloadContent(); });
		} catch(e){}
		try { mpInitWarnings(); } catch(e){}
	}

	$(function(){ mpInitReg(); });
	/* ========== Digital Signature Logic ========== */
/* Re-render the signature box in place after a save. Mirrors the PHP markup of the
   "signed" state so the result grid is left completely untouched. */
function mpRenderSignature(src, signer, when){
	var $box = $('.mp-signature-box'); if(!$box.length || !src){ return; }
	var esc = function(v){ return $('<div>').text(v == null ? '' : v).html(); };
	$box.html(
		'<div class="mb-2"></div>'
		+ '<div class="mp-signature-preview"><img src="' + esc(src) + '" class="mp-signature-image" '
		+ 'title="Signed by ' + esc(signer) + ' on ' + esc(when) + '"></div>'
		+ '<div class="mt-2"><small class="text-success d-block">Signed by <strong>'
		+ esc(signer) + '</strong> on ' + esc(when) + '</small></div>'
		+ '<button type="button" class="btn btn-sm btn-outline-secondary mt-2 add-signature-btn" '
		+ 'data-toggle="modal" data-target="#signatureModal"><i class="fas fa-edit"></i> Change Signature</button>'
	);
}
/* A STAGED (not yet saved) signature: shown in the box with a clear "pending" note. It is
   persisted only when the Test Result form is submitted, in the same transaction as the
   result rows, so the two can never get out of step. "Discard" un-stages it. */
var mpSigBoxBeforeStage = null;
function mpStageSignature(src, signer, when, method){
	var $box = $('.mp-signature-box'); if(!$box.length || !src){ return; }
	var esc = function(v){ return $('<div>').text(v == null ? '' : v).html(); };
	$('#sigPendingMethod').val(method); $('#sigPendingSigner').val(signer);
	$('#sigPendingDate').val(when);     $('#sigPendingData').val(src);
	if(mpSigBoxBeforeStage === null){ mpSigBoxBeforeStage = $box.html(); }
	$box.html(
		'<div class="mb-2"></div>'
		+ '<div class="mp-signature-preview"><img src="' + esc(src) + '" class="mp-signature-image" '
		+ 'title="Pending: signed by ' + esc(signer) + ' on ' + esc(when) + '"></div>'
		+ '<div class="mt-2"><small class="text-warning d-block"><i class="fas fa-clock"></i> '
		+ '<strong>Signature attached &mdash; not saved yet.</strong> Signed by <strong>' + esc(signer) + '</strong> on ' + esc(when)
		+ '. It will be saved together with the Final Result when you click <strong>Update Final Result</strong>.</small></div>'
		+ '<button type="button" class="btn btn-sm btn-outline-secondary mt-2 add-signature-btn" '
		+ 'data-toggle="modal" data-target="#signatureModal"><i class="fas fa-edit"></i> Change Signature</button> '
		+ '<button type="button" class="btn btn-sm btn-outline-danger mt-2" id="mpDiscardSignature"><i class="fas fa-times"></i> Discard</button>'
	);
}
$(document).on('click', '#mpDiscardSignature', function(){
	$('#sigPendingMethod,#sigPendingSigner,#sigPendingDate,#sigPendingData').val('');
	if(mpSigBoxBeforeStage !== null){ $('.mp-signature-box').html(mpSigBoxBeforeStage); mpSigBoxBeforeStage = null; }
});
/* ========== Digital Signature Logic (ONE signature per registration) ========== */
/*
 * Signature component (Draw + Upload).
 *  - The modal lives inside .content-wrapper, which mpReloadContent() replaces after every
 *    save, so EVERYTHING here is delegated through document and the canvas is looked up and
 *    (re)bound fresh on every modal open / mode switch. Nothing keeps a stale element.
 *  - The drawing pad owns its listeners (pointer events: mouse, pen, touch) and removes them
 *    before binding again, so there are never duplicate handlers.
 *  - The canvas is sized while VISIBLE, then styled (sizing resets the 2D context), and every
 *    pointer position is scaled from CSS pixels to bitmap pixels, so strokes land where the
 *    user draws. Strokes are kept in normalised coordinates and redrawn on resize.
 *  - Switching modes clears the other mode's data; Remove clears the upload; opening the
 *    modal resets everything. Save/staging behaviour is unchanged.
 */
(function () {
    /* ---------------- drawing pad ---------------- */
    var pad = {
        canvas: null, ctx: null, drawing: false, strokes: [], current: null, handlers: null, pointerId: null,
        destroy: function () {
            if (this.canvas && this.handlers) {
                var c = this.canvas, h = this.handlers;
                c.removeEventListener('pointerdown', h.down);
                c.removeEventListener('pointermove', h.move);
                c.removeEventListener('pointerup', h.up);
                c.removeEventListener('pointercancel', h.up);
                c.removeEventListener('pointerleave', h.up);
                window.removeEventListener('resize', h.resize);
            }
            this.canvas = null; this.ctx = null; this.handlers = null; this.drawing = false; this.current = null; this.pointerId = null;
        },
        bind: function () {
            this.destroy();
            var c = document.getElementById('signatureCanvas');
            if (!c) { return false; }
            this.canvas = c;
            var self = this;
            this.handlers = {
                down: function (e) { self.onDown(e); },
                move: function (e) { self.onMove(e); },
                up:   function (e) { self.onUp(e); },
                resize: function () { self.size(); }
            };
            c.addEventListener('pointerdown', this.handlers.down);
            c.addEventListener('pointermove', this.handlers.move);
            c.addEventListener('pointerup', this.handlers.up);
            c.addEventListener('pointercancel', this.handlers.up);
            c.addEventListener('pointerleave', this.handlers.up);
            window.addEventListener('resize', this.handlers.resize);
            this.size();
            return true;
        },
        size: function () {
            if (!this.canvas) { return; }
            var w = Math.max(1, Math.round(this.canvas.getBoundingClientRect().width || this.canvas.parentElement.clientWidth || 700));
            this.canvas.width = w;            // resets the context -> style afterwards
            this.canvas.height = 200;
            this.ctx = this.canvas.getContext('2d');
            this.ctx.strokeStyle = '#000'; this.ctx.lineWidth = 2.5; this.ctx.lineCap = 'round'; this.ctx.lineJoin = 'round';
            this.redraw();
        },
        point: function (e) {          // CSS pixels -> bitmap pixels, then normalised 0..1
            var r = this.canvas.getBoundingClientRect();
            // If the element has no layout size yet (e.g. mid-transition), map 1:1 instead of scaling by infinity.
            var sx = r.width  > 0 ? this.canvas.width  / r.width  : 1;
            var sy = r.height > 0 ? this.canvas.height / r.height : 1;
            var x = (e.clientX - r.left) * sx, y = (e.clientY - r.top) * sy;
            return { x: x / this.canvas.width, y: y / this.canvas.height };
        },
        onDown: function (e) {
            if (!this.ctx) { this.size(); }
            e.preventDefault();
            this.drawing = true; this.pointerId = e.pointerId;
            try { this.canvas.setPointerCapture(e.pointerId); } catch (err) {}
            this.current = [this.point(e)];
        },
        onMove: function (e) {
            if (!this.drawing || e.pointerId !== this.pointerId) { return; }
            e.preventDefault();
            var p = this.point(e), q = this.current[this.current.length - 1], W = this.canvas.width, H = this.canvas.height;
            this.ctx.beginPath(); this.ctx.moveTo(q.x * W, q.y * H); this.ctx.lineTo(p.x * W, p.y * H); this.ctx.stroke();
            this.current.push(p);
        },
        onUp: function (e) {
            if (!this.drawing) { return; }
            if (e && e.pointerId !== undefined && e.pointerId !== this.pointerId) { return; }
            this.drawing = false;
            try { this.canvas.releasePointerCapture(this.pointerId); } catch (err) {}
            if (this.current && this.current.length > 0) {
                if (this.current.length === 1) { this.current.push({ x: this.current[0].x + 0.0005, y: this.current[0].y }); }   // a dot
                this.strokes.push(this.current);
            }
            this.current = null; this.pointerId = null;
            this.redraw();
        },
        redraw: function () {
            if (!this.ctx) { return; }
            var W = this.canvas.width, H = this.canvas.height, ctx = this.ctx;
            ctx.clearRect(0, 0, W, H);
            this.strokes.forEach(function (st) {
                ctx.beginPath(); ctx.moveTo(st[0].x * W, st[0].y * H);
                for (var i = 1; i < st.length; i++) { ctx.lineTo(st[i].x * W, st[i].y * H); }
                ctx.stroke();
            });
        },
        clear: function () { this.strokes = []; this.current = null; this.drawing = false; if (this.ctx) { this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height); } },
        undo: function () { this.strokes.pop(); this.redraw(); },
        isEmpty: function () { return this.strokes.length === 0; },
        blankDataURL: function () { var b = document.createElement('canvas'); b.width = this.canvas.width; b.height = this.canvas.height; return b.toDataURL('image/png'); },
        toDataURL: function () { this.redraw(); return this.canvas.toDataURL('image/png'); }
    };

    /* ---------------- upload state ---------------- */
    var upload = { dataUrl: '', name: '' };
    function clearUpload() {
        upload.dataUrl = ''; upload.name = '';
        var inp = document.getElementById('sigImageInput');
        if (inp) { inp.value = ''; }
        $('#sigImageLabel').text('Choose signature image...');
        $('#sigPreview').attr('src', '#');
        $('#imagePreview').hide();
        $('#sigFilePicker').show();
    }

    /* ---------------- mode switching (always clears the other mode) ---------------- */
    function currentMode() { return $('input[name="sig_method"]:checked').val() === 'upload' ? 'upload' : 'draw'; }
    function setMode(mode) {
        var isDraw = (mode !== 'upload');
        $('input[name="sig_method"][value="draw"]').prop('checked', isDraw).closest('label').toggleClass('active', isDraw);
        $('input[name="sig_method"][value="upload"]').prop('checked', !isDraw).closest('label').toggleClass('active', !isDraw);
        if (isDraw) {
            clearUpload();                         // Upload -> Draw: drop the image
            $('#uploadArea').hide(); $('#drawArea').show();
            pad.bind(); pad.clear();               // fresh canvas, sized while visible, no listeners duplicated
        } else {
            pad.clear(); pad.destroy();            // Draw -> Upload: drop the strokes
            $('#drawArea').hide(); $('#uploadArea').show();
        }
    }
    $(document).on('change', 'input[name="sig_method"]', function () { setMode(this.value); });

    /* ---------------- draw controls ---------------- */
    $(document).on('click', '#clearSignature', function () { pad.clear(); });
    $(document).on('click', '#undoSignature',  function () { pad.undo(); });

    /* ---------------- upload controls ---------------- */
    $(document).on('change', '#sigImageInput', function () {
        var file = this.files && this.files[0];
        if (!file) { clearUpload(); return; }
        if (!/^image\/(png|jpeg|gif)$/.test(file.type)) { Swal.fire('Error', 'Only PNG / JPG / GIF images are allowed', 'error'); clearUpload(); return; }
        if (file.size > 2 * 1024 * 1024)                { Swal.fire('Error', 'Image must be smaller than 2 MB', 'error'); clearUpload(); return; }
        var reader = new FileReader();
        reader.onload = function (e) {
            upload.dataUrl = e.target.result; upload.name = file.name;
            $('#sigPreview').attr('src', upload.dataUrl);
            $('#sigImageLabel').text(file.name);
            $('#imagePreview').show();
        };
        reader.onerror = function () { Swal.fire('Error', 'Could not read the selected image', 'error'); clearUpload(); };
        reader.readAsDataURL(file);
    });
    $(document).on('click', '#removeSigImage', function () { clearUpload(); });   // re-upload or switch to Draw afterwards

    /* ---------------- open / show / hide: full reset every time ---------------- */
    $(document).on('click', '.add-signature-btn', function () {
        var f = document.getElementById('signatureForm'); if (f) { f.reset(); }
        $('#sig_result_id').val('');
        clearUpload();
        // Order-independent: Bootstrap's own click handler may already have shown the modal
        // (and bound the pad) before this reset runs. bind() is idempotent (removes then
        // re-adds listeners) so the pad is always live after the reset; shown.bs.modal
        // re-binds again with the final visible size.
        pad.bind(); pad.clear();
        $('#uploadArea').hide(); $('#drawArea').show();
        $('input[name="sig_method"][value="draw"]').prop('checked', true).closest('label').addClass('active');
        $('input[name="sig_method"][value="upload"]').prop('checked', false).closest('label').removeClass('active');
        $('#signerName').val('');
        $('#sigDate').val(new Date().toISOString().slice(0, 19).replace('T', ' '));
    });
    // Delegated so it survives content reloads; the canvas is visible here, so sizing is correct.
    $(document).on('shown.bs.modal', '#signatureModal', function () {
        if (currentMode() === 'draw') { pad.bind(); pad.clear(); }
    });
    $(document).on('hidden.bs.modal', '#signatureModal', function () { pad.destroy(); });

    /* ---------------- Save Signature (staging; unchanged behaviour) ---------------- */
    $(document).on('click', '#saveSignature', function () {
        const method = currentMode();
        const signer = $('#signerName').val().trim();
        const regid  = $('input[name="regid"]').val() || '<?= isset($regid) ? (int)$regid : 0 ?>';

        if (!signer) {
            Swal.fire('Error', 'Please enter signer name', 'error');
            return;
        }
        if (!regid || regid == 0) {
            Swal.fire('Error', 'Registration ID missing', 'error');
            return;
        }

        // DATA SAFETY: the signature is NOT sent to the server here. It is staged into the
        // Test Result form and persisted by update_result.php in the same transaction as the
        // result rows, so it can never be saved while the results are missing or unsaved.
        const sigDate = $('#sigDate').val() || new Date().toISOString().slice(0, 19).replace('T', ' ');
        const $btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Attaching...');
        const done = function(){ $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Signature'); };
        const stage = function(dataUri){
            mpStageSignature(dataUri, signer, sigDate, method);
            // Bootstrap silently ignores modal('hide') while the open transition is still
            // running (a fast Save click, or a slow device); defer it until the modal is shown.
            const $m = $('#signatureModal'), inst = $m.data('bs.modal');
            if (inst && inst._isTransitioning) { $m.one('shown.bs.modal', function(){ $m.modal('hide'); }); } else { $m.modal('hide'); }
            Swal.fire({ icon: 'info', title: 'Signature attached', text: 'It will be saved together with the Final Result when you click "Update Final Result".', confirmButtonText: 'OK' });
            done();
        };

        if (method === 'draw') {
            if (!pad.canvas) { pad.bind(); }
            var drawn = pad.isEmpty() ? '' : pad.toDataURL();
            // Also refuse a visually blank drawing (e.g. strokes that fell outside the canvas).
            if (drawn === '' || drawn === pad.blankDataURL()) {
                Swal.fire('Error', 'Please draw your signature first', 'error');
                done(); return;
            }
            stage(drawn);
        } else {
            if (!upload.dataUrl) {
                Swal.fire('Error', 'Please select an image', 'error');
                done(); return;
            }
            stage(upload.dataUrl);
        }
    });
})();
	</script>
	<style>

		/* While a Test Date picker is open, stop the scroll container from clipping the
		   popup (see the show/hide handlers above). Restored as soon as it closes, so
		   horizontal scrolling of the result grid is unaffected the rest of the time.
		   Oct-2026: widgetParent:'body' is the primary fix; this stays as a fallback. */
		.table-responsive.mp-dtp-open { overflow: visible; }

		/* ---------- Oct-13 2026 — Datetimepicker popup, compact floating card ----------
		   Hard compact 280 px card. position:absolute lets our JS anchor it to
		   the clicked input's getBoundingClientRect + page scroll offset — the
		   popup tracks the input through scrolling without drifting. z-index
		   9999 clears the sidebar (which sits at ~1038 in AdminLTE). */
		.input-group.date { position: relative; }
		body .bootstrap-datetimepicker-widget.dropdown-menu,
		.bootstrap-datetimepicker-widget.dropdown-menu,
		.mptr-grid .bootstrap-datetimepicker-widget.dropdown-menu {
			position: absolute !important;
			z-index: 9999 !important;
			width: 280px !important;
			min-width: 280px !important;
			max-width: 280px !important;
			max-height: none !important;
			overflow: visible !important;
			padding: 8px !important;
			margin: 0 !important;
			border-radius: 10px !important;
			border: 1px solid rgba(15, 23, 42, 0.08) !important;
			box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18), 0 2px 6px rgba(15, 23, 42, 0.08) !important;
			background: #ffffff !important;
			box-sizing: border-box !important;
		}
		/* The inner <table> must NOT inherit width:100% from any global table rule
		   (AdminLTE / Bootstrap). !important ensures the popup never stretches to
		   its outer container's width. Natural width = 7 × cell-size + nav. */
		body .bootstrap-datetimepicker-widget.dropdown-menu,
		.bootstrap-datetimepicker-widget.dropdown-menu { font-size: 13px !important; line-height: 1.3 !important; }
		.bootstrap-datetimepicker-widget.dropdown-menu table {
			width: auto !important;
			min-width: 0 !important;
			max-width: 100% !important;
			margin: 0 !important;
			border: 0 !important;
			border-collapse: separate !important;
			table-layout: fixed !important;    /* locks column widths against .table cascade */
		}
		.bootstrap-datetimepicker-widget.dropdown-menu table td,
		.bootstrap-datetimepicker-widget.dropdown-menu table th {
			width:     32px !important;
			min-width: 32px !important;
			max-width: 32px !important;
			height: 32px !important; line-height: 32px !important;
			font-size: 13px !important; padding: 0 !important;
			text-align: center; border-radius: 6px; vertical-align: middle;
			border: 0 !important;
			box-sizing: border-box !important;
		}
		/* Test Result grid override — the outer <table class="table table-bordered
		   mptr-grid"> cascades `.table th, .table td { padding:.75rem; border-top:
		   1px solid … }` and `.table-bordered td, th { border: 1px solid … }` into
		   the picker's nested table. Explicit zero-border + zero-padding reset,
		   scoped to the picker when it lives inside the result grid, keeps the
		   Test Date popup rendering IDENTICALLY to the registration-form pickers. */
		.mptr-grid .bootstrap-datetimepicker-widget.dropdown-menu table,
		.mptr-grid .bootstrap-datetimepicker-widget.dropdown-menu table td,
		.mptr-grid .bootstrap-datetimepicker-widget.dropdown-menu table th {
			border: 0 !important;
			padding: 0 !important;
			background: transparent;
		}
		.mptr-grid .bootstrap-datetimepicker-widget.dropdown-menu table td,
		.mptr-grid .bootstrap-datetimepicker-widget.dropdown-menu table th {
			width: 32px !important; height: 32px !important; line-height: 32px !important;
			vertical-align: middle !important;
		}
		/* Vendor sets a bigger 54 px <span> for year / month cells and the time
		   picker buttons. Shrink those to 42 px so the month / year view also
		   fits inside the 280 px compact card. */
		.bootstrap-datetimepicker-widget.dropdown-menu table td span {
			width: 42px !important; height: 42px !important; line-height: 42px !important;
			margin: 1px !important; font-size: 13px !important; box-sizing: border-box !important;
		}
		/* The picker-switch header spans colspan=5; the universal td/th width:32px
		   would otherwise crush its label. */
		.bootstrap-datetimepicker-widget.dropdown-menu .picker-switch,
		.bootstrap-datetimepicker-widget.dropdown-menu .prev,
		.bootstrap-datetimepicker-widget.dropdown-menu .next {
			cursor: pointer; border-radius: 6px;
		}
		.bootstrap-datetimepicker-widget.dropdown-menu .picker-switch { width: auto !important; min-width: 0 !important; }
		.bootstrap-datetimepicker-widget.dropdown-menu .picker-switch td { padding: 0 !important; height: auto !important; width: auto !important; line-height: inherit !important; }
		.bootstrap-datetimepicker-widget.dropdown-menu .picker-switch td span { line-height: 2 !important; height: 2em !important; width: 100% !important; }
		.bootstrap-datetimepicker-widget.dropdown-menu .picker-switch:hover,
		.bootstrap-datetimepicker-widget.dropdown-menu .prev:hover,
		.bootstrap-datetimepicker-widget.dropdown-menu .next:hover,
		.bootstrap-datetimepicker-widget.dropdown-menu td.day:hover,
		.bootstrap-datetimepicker-widget.dropdown-menu td.month:hover,
		.bootstrap-datetimepicker-widget.dropdown-menu td.year:hover {
			background: #e9f0fb;
		}
		.bootstrap-datetimepicker-widget.dropdown-menu td.day.active,
		.bootstrap-datetimepicker-widget.dropdown-menu td.month.active,
		.bootstrap-datetimepicker-widget.dropdown-menu td.year.active,
		.bootstrap-datetimepicker-widget.dropdown-menu td.today {
			background: #0d6efd; color: #ffffff; border-radius: 6px;
		}
		.bootstrap-datetimepicker-widget.dropdown-menu td.old,
		.bootstrap-datetimepicker-widget.dropdown-menu td.new { color: #a7adbb; }
		@media (max-width: 576px) {
			.bootstrap-datetimepicker-widget.dropdown-menu {
				min-width: 0 !important;
				max-width: calc(100vw - 16px) !important;
			}
			.bootstrap-datetimepicker-widget.dropdown-menu table td,
			.bootstrap-datetimepicker-widget.dropdown-menu table th {
				width: 36px !important; height: 36px; line-height: 36px; font-size: 14px;
			}
		}

		/* Blinking / notification style for duplicate batch & expiry warnings */
.mp-blink-warn {
    background: #fff3cd;
    border: 1px solid #ffc107;
    color: #856404;
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 8px;
    font-weight: 500;
    animation: mp-blink 1.2s ease-in-out infinite;
}

.mp-blink-warn.mp-blink-danger {
    background: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
}

@keyframes mp-blink {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.4); }
    50%       { opacity: 0.55; box-shadow: 0 0 0 6px rgba(255, 193, 7, 0); }
}

/* =========================================================
   PROFESSIONAL DIGITAL SIGNATURE PREVIEW
   ========================================================= */

/* Main signature container */
.mp-signature-box {
    width: 100%;
    max-width: 500px;
    padding: 12px 14px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
}

/* Signature heading */
.mp-signature-header {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #495057;
}

/* =========================================================
   FIXED SIGNATURE PREVIEW
   ========================================================= */

.mp-signature-preview {
    width: 220px;
    height: 80px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: #ffffff;
    border: 1px solid #dcdcdc;
    border-radius: 6px;

    overflow: hidden;
}

/* Actual signature image */
.mp-signature-image {
    display: block;

    width: 100%;
    height: 100%;

    max-width: 100%;
    max-height: 100%;

    object-fit: contain;
    object-position: center;

    padding: 5px;

    background: #ffffff;
}

/* Signature information */
.mp-signature-info {
    display: flex;
    align-items: center;
    gap: 6px;

    margin-top: 7px;

    font-size: 12px;
    line-height: 1.4;
    color: #198754;
}

.mp-signature-info i {
    font-size: 12px;
}

/* Change signature button */
.mp-change-signature {
    margin-top: 7px;
    padding: 4px 9px;
    font-size: 12px;
}

/* Add signature button */
.mp-add-signature {
    padding: 6px 12px;
    font-size: 13px;
}

/* Responsive preview */
@media (max-width: 576px) {

    .mp-signature-box {
        max-width: 100%;
    }

    .mp-signature-preview {
        width: 200px;
        height: 75px;
    }
}	

  	</style>
	</body>
</html>
