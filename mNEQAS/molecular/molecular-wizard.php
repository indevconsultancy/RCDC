<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<?php require_once("../includes/wizard-step-delete.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php
    require_once("../config/commondb-config.php");
    require_once("../config/db-config.php");
?>
<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <!-- Top Navbar -->
  <?php require_once("../includes/top-navbar.php"); ?>
  <!-- Main Sidebar Container -->
  <?php require_once("../includes/sidebar-menu.php"); ?>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>Molecular Test</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="molecular-list.php">Molecular Patient List</a></li>
              <li class="breadcrumb-item active"> Molecular Test | Registration Wizard</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <!-- Data and page settings -->
<?php
    // ------------------------------------------------------------------ state
    $dzsql  = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
    $isRCDC = ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2);   // RCDC / admin

    if (!$isRCDC) {
        $facilityId = $_SESSION["facilityID"];
        list($dzongkhag_code, $dzongkhag_name) = $commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$facilityId')")->fetch_row();
        list($center_name) = $commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$facilityId'")->fetch_row();
    }

    $deletepcr = $deletelab = "";
    $action = isset($_GET["action"]) ? $_GET["action"] : 1;
    $isEdit = ($action == 2);

    if ($isEdit) {
        $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
        mn_require_record($conn, 'dbs', $id);   // record-level authorization (IDOR)
        $canceltr = "molecular-wizard.php?action=2&id=$id";

        // Column order: 0 dbs_id,1 facility_id,2 sample_type,3 patient_name,4 age,5 gender,
        // 6 nationality,7 bse_no,8 collection_date,9 collected_by,10 microscopy,11 rdt,
        // 12 pcr,13 pcr_date,14 pcr_remarks,15 dbs_shipment_id,16 collection_site,17 ref_code
        $dbsqry = $conn->query("SELECT dbs_id, facility_id, sample_type, patient_name, age, gender, nationality, bse_no, collection_date, collected_by, microscopy, rdt, pcr, pcr_date, pcr_remarks, dbs_shipment_id, collection_site, ref_code FROM dbs_results WHERE dbs_id=".$id);
        $trqry = $dbsqry->fetch_row();

        $facility  = $commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$trqry[1]'")->fetch_row();
        $dzongkhag = $commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$trqry[1]')")->fetch_row();

        $caseType    = "<option value='$trqry[2]'>$trqry[2]</option>";
        $gender      = "<option value='$trqry[5]'>$trqry[5]</option>";
        $nationality = "<option value='$trqry[6]'>$trqry[6]</option>";
        $microscopy  = "<option value='$trqry[10]'>$trqry[10]</option>";
        $rdt         = "<option value='$trqry[11]'>$trqry[11]</option>";
        $pcr         = "<option value='$trqry[12]'>$trqry[12]</option>";
        if ($trqry[12] != '') {
            $deletepcr = "<a href='delete.php?type=pcr&dbsid=$id' class='btn btn-outline-danger' onclick=\"return confirm('Delete PCR result?');\">Delete PCR</a>";
        } else {
            $deletelab = "<a href='delete.php?type=gi&dbsid=$id' class='btn btn-outline-danger' onclick=\"return confirm('Delete this sample?');\"><i class='fa fa-trash'></i> Delete Record</a>";
        }
    } else {
        $canceltr = "molecular-wizard.php?action=1";
        $trqry = array('', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '');
        $facility    = array('Select Facility');
        $dzongkhag   = array('', 'Select Dzongkhag');
        $caseType    = "<option value=''>Select Type</option>";
        $gender      = "<option value=''>Select</option>";
        $nationality = "<option value=''>Select</option>";
        $microscopy  = "<option value=''>Select</option>";
        $rdt         = "<option value=''>Select</option>";
        $pcr         = "<option value=''>Select</option>";
    }

    // Wizard model (Patient / Sample registration = 3 steps)
    $totalSteps = 3;
    $enabled    = array(1, 2, 3);
    $curStep    = isset($_GET["step"]) ? (int)$_GET["step"] : 1;
    if (!in_array($curStep, $enabled)) { $curStep = 1; }
    $stepLabels = array(1 => 'Location', 2 => 'Patient Details', 3 => 'Sample &amp; Tests');
?>

    <!-- ============================ PATIENT / SAMPLE WIZARD ============================ -->
    <section class="content">
      <div class="container-fluid">
        <div class="card card-primary card-outline">
          <div class="card-header">
            <h3 class="card-title"><i class="fa fa-vials"></i>&nbsp; DBS Registration &mdash; <?= $isEdit ? 'Edit Sample' : 'New Sample'; ?></h3>
            <div class="card-tools"><?=$deletelab;?></div>
          </div>
          <div class="card-body">

            <?php if (!$isRCDC) { ?>
              <div class="alert alert-info"><i class="fa fa-info-circle"></i> You are registering a DBS sample for your own facility. The PCR result is entered later by RCDC.</div>
            <?php } ?>

            <!-- Stepper -->
            <ul class="wiz-nav" id="wizNav">
              <?php foreach ($stepLabels as $sn => $lbl) { ?>
                <li class="wiz-step" data-step="<?=$sn;?>"><span class="wiz-num"><?=$sn;?></span><span class="wiz-label"><?=$lbl;?></span></li>
              <?php } ?>
            </ul>
            <div class="progress mb-4" style="height:4px;"><div id="wizProgress" class="progress-bar bg-primary" style="width:0%;"></div></div>

            <!-- ============ #dbs FORM (Steps 1-3, ONE final submit) ============ -->
            <form class="form-horizontal" id="dbs" autocomplete="off" method="Post" action="iu-molecular.php">
              <input type='hidden' name='action' id='action' value='<?=$action;?>'>
              <input type='hidden' name='submit' id='submit' value='dbs'>
              <input type="hidden" name="dbsid" id="dbsid" value="<?=$trqry[0];?>">

              <!-- STEP 1: LOCATION -->
              <div class="wiz-pane" data-step="1">
                <h5 class="text-primary mb-3">Location &amp; Case</h5>
                <div class="row">
                <?php if ($isRCDC) { ?>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Dzongkhag:</label>
                    <select class="form-control select2" name="dzongkhag" id="dzongkhag" style="width:100%;">
                      <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                      <?php $rs = $commondb->query($dzsql); while ($row = $rs->fetch_row()) { echo "<option value='$row[0]'>$row[1]</option>"; } ?>
                    </select>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Health Facility:</label>
                    <div id="facilityDiv">
                      <select class="form-control" name="facility" id="facility">
                        <option value="<?=$trqry[1];?>"><?=$facility[0];?></option>
                      </select>
                    </div>
                  </div>
                <?php } else { ?>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Dzongkhag:</label>
                    <select class="form-control select2" name="dzongkhag" id="dzongk" style="width:100%;">
                      <option value='<?=$dzongkhag_code;?>'><?=$dzongkhag_name;?></option>
                    </select>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Health Facility:</label>
                    <div id="facilityDiv">
                      <select class="form-control" name="facility" id="facility" readonly>
                        <option value="<?=($isEdit ? $trqry[1] : $facilityId);?>"><?=($isEdit ? $facility[0] : $center_name);?></option>
                      </select>
                    </div>
                  </div>
                <?php } ?>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Collection Site:</label>
                    <input type="text" name="csite" id="csite" class="form-control" value="<?=htmlspecialchars($trqry[16], ENT_QUOTES);?>" placeholder="Collection Site">
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Case Type:</label>
                    <select class="form-control" name="case_type" id="case_type" required>
                      <?=$caseType;?>
                      <option value="PCD">PCD</option>
                      <option value="RACD">RACD</option>
                      <option value="PACD">PACD</option>
                      <option value="ACD">ACD</option>
                    </select>
                  </div>
                </div>
              </div>

              <!-- STEP 2: PATIENT DETAILS -->
              <div class="wiz-pane" data-step="2">
                <h5 class="text-primary mb-3">Patient Information</h5>
                <div class="row">
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Patient Name:</label>
                    <input type="text" name="pname" id="pname" class="form-control" value="<?=htmlspecialchars($trqry[3], ENT_QUOTES);?>" placeholder="Name of Patient" required>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Age:</label>
                    <input type="number" name="age" id="age" class="form-control" value="<?=htmlspecialchars($trqry[4], ENT_QUOTES);?>" placeholder="Patient Age" required>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Gender</label>
                    <select class="form-control" name="gender" id="gender" required>
                      <?=$gender;?>
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                      <option value="Others">Others</option>
                    </select>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Nationality</label>
                    <select class="form-control" name="nationality" id="nationality" required>
                      <?=$nationality;?>
                      <option value="Bhutanese">Bhutanese</option>
                      <option value="NonBhutanese">NonBhutanese</option>
                    </select>
                  </div>
                </div>
              </div>

              <!-- STEP 3: SAMPLE & TESTS -->
              <div class="wiz-pane" data-step="3">
                <h5 class="text-primary mb-3">Sample &amp; On-site Tests</h5>
                <div class="row">
                  <div class="form-group col-sm-2">
                    <label class="col-form-label-sm">Patient BSE No.:</label>
                    <input type="text" name="bseno" id="bseno" class="form-control" value="<?=htmlspecialchars($trqry[7], ENT_QUOTES);?>" placeholder="Patient BSE No." required>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Collection Date:</label>
                    <div class="input-group date" id="doc" data-target-input="nearest">
                      <input type="text" class="form-control datetimepicker-input text-center" data-target="#doc" name="doc" placeholder="YYYY-MM-DD" value="<?=$trqry[8];?>" />
                      <div class="input-group-append" data-target="#doc" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                  </div>
                  <div class="form-group col-sm-3">
                    <label class="col-form-label-sm">Collected By:</label>
                    <input type="text" name="collectedby" id="collectedby" class="form-control" value="<?=htmlspecialchars($trqry[9], ENT_QUOTES);?>" placeholder="Sample Collected by" required>
                  </div>
                  <div class="form-group col-sm-2">
                    <label class="col-form-label-sm">Microscopy Result</label>
                    <select class="form-control" name="microscopy" id="microscopy" required>
                      <?=$microscopy;?>
                      <option value='Nmps'>Nmps</option>
                      <option value='Pf'>Pf</option>
                      <option value='Pv'>Pv</option>
                      <option value='F+V'>F+V</option>
                      <option value='Po'>Po</option>
                      <option value='Pm'>Pm</option>
                      <option value='Pk'>Pk</option>
                      <option value='Not Done'>Not Done</option>
                    </select>
                  </div>
                  <div class="form-group col-sm-2">
                    <label class="col-form-label-sm">RDT Result</label>
                    <select class="form-control" name="rdt" id="rdt" required>
                      <?=$rdt;?>
                      <option value='Neg'>Neg</option>
                      <option value='Pf'>Pf</option>
                      <option value='Pv'>Pv</option>
                      <option value='F+V'>F+V</option>
                      <option value='Po'>Po</option>
                      <option value='Pm'>Pm</option>
                      <option value='Pk'>Pk</option>
                      <option value='Not Done'>Not Done</option>
                    </select>
                  </div>
                </div>

                <!-- Review summary (populated by JS) -->
                <div class="card card-outline card-secondary mt-2">
                  <div class="card-header"><h3 class="card-title">Review</h3></div>
                  <div class="card-body p-2" id="reviewArea"></div>
                </div>

                <div class="text-right">
                  <button type="submit" class="btn bg-gradient-success" id="wizSubmit"><i class="fa fa-check"></i> Submit</button>
                </div>
              </div>
            </form>
            <!-- /#dbs form -->

          </div>
          <!-- /.card-body -->
          <div class="card-footer d-flex justify-content-between">
            <div>
              <button type="button" class="btn bg-gradient-secondary" id="wizPrev"><i class="fa fa-arrow-left"></i> Previous</button>
              <a href="<?=$canceltr;?>" class="btn btn-outline-secondary">Cancel</a>
              <?php
              /* Clears the step on screen. $id is only assigned on the edit path in
                 this wizard, so it is read null-safe here. */
              render_wizard_step_delete(array(
                  'module'    => 'molecular',
                  'record_id' => (int)($id ?? 0),
                  'can_write' => true,
                  'steps'     => array(
                      1 => array('name' => 'Location & Case',          'rows' => 'record', 'saved' => $isEdit),
                      2 => array('name' => 'Patient Information',      'rows' => 'record', 'saved' => $isEdit),
                      3 => array('name' => 'Sample & On-site Tests',   'rows' => 'many',   'saved' => $isEdit),
                  ),
              ));
              ?>
            </div>
            <div><button type="button" class="btn bg-gradient-primary" id="wizNext">Next <i class="fa fa-arrow-right"></i></button></div>
          </div>
        </div>
      </div>
    </section>

<?php if ($isEdit && $isRCDC) { ?>
    <!-- ============================ PCR RESULT (RCDC only) ============================ -->
    <section class="content">
      <div class="container-fluid">
        <div class="card card-primary card-outline">
          <div class="card-header"><h3 class="card-title"><i class="fa fa-dna"></i>&nbsp; PCR Result</h3></div>
          <div class="card-body">
            <form class="form-horizontal" id="pcrData" autocomplete="off" method="Post" action="iu-molecular.php">
              <input type='hidden' name='action' value='<?=$action;?>'>
              <input type='hidden' name='submit' value='pcrData'>
              <input type="hidden" name="dbsid" value="<?=$trqry[0];?>">
              <div class="row">
                <div class="form-group col-sm-2">
                  <label class="col-form-label-sm">Patient BSE No.:</label>
                  <input type="text" name="bseno" class="form-control" value="<?=htmlspecialchars($trqry[7], ENT_QUOTES);?>" readonly>
                </div>
                <div class="form-group col-sm-2">
                  <label class="col-form-label-sm">Sample Ref. Code.:</label>
                  <input type="text" name="ref_code" id="ref_code" class="form-control" value="<?=htmlspecialchars($trqry[17], ENT_QUOTES);?>">
                </div>
                <div class="form-group col-sm-2">
                  <label class="col-form-label-sm">PCR Result</label>
                  <select class="form-control" name="pcr" id="pcr" required>
                    <?=$pcr;?>
                    <option value='Negative'>Negative</option>
                    <option value='Pf'>Pf</option>
                    <option value='Pv'>Pv</option>
                    <option value='F+V'>F+V</option>
                    <option value='Po'>Po</option>
                    <option value='Pm'>Pm</option>
                    <option value='Pk'>Pk</option>
                    <option value='NA'>NA</option>
                  </select>
                </div>
                <div class="form-group col-sm-3">
                  <label class="col-form-label-sm">PCR Date:</label>
                  <div class="input-group date" id="pcr_date" data-target-input="nearest">
                    <input type="text" class="form-control datetimepicker-input text-center" data-target="#pcr_date" name="pcr_date" placeholder="YYYY-MM-DD" value="<?=$trqry[13];?>" />
                    <div class="input-group-append" data-target="#pcr_date" data-toggle="datetimepicker">
                      <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                    </div>
                  </div>
                </div>
                <div class="form-group col-sm-3">
                  <label class="col-form-label-sm">Remarks</label>
                  <input type="text" name="remarks" id="remarks" class="form-control" value="<?=htmlspecialchars($trqry[14], ENT_QUOTES);?>">
                </div>
              </div>
              <div class="text-right">
                <?=$deletepcr;?>
                <button type="submit" class="btn bg-gradient-success"><i class="fa fa-save"></i> Save PCR Result</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>
<?php } ?>

  </div>
  <!-- /.content-wrapper -->

  <style>
    .wiz-nav{list-style:none;display:flex;padding:0;margin:0 0 6px;}
    .wiz-nav .wiz-step{flex:1;text-align:center;position:relative;cursor:pointer;padding:4px;color:#6c757d;font-size:12px;}
    .wiz-nav .wiz-step .wiz-num{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:#e9ecef;color:#6c757d;font-weight:600;margin-bottom:4px;border:2px solid #e9ecef;transition:.2s;}
    .wiz-nav .wiz-step::before{content:"";position:absolute;top:22px;left:-50%;width:100%;height:2px;background:#e9ecef;z-index:0;}
    .wiz-nav .wiz-step:first-child::before{display:none;}
    .wiz-nav .wiz-step .wiz-num,.wiz-nav .wiz-step .wiz-label{position:relative;z-index:1;display:block;margin:0 auto;}
    .wiz-nav .wiz-step.active{color:#007bff;font-weight:600;} .wiz-nav .wiz-step.active .wiz-num{background:#007bff;border-color:#007bff;color:#fff;}
    .wiz-nav .wiz-step.done .wiz-num{background:#28a745;border-color:#28a745;color:#fff;}
    .wiz-nav .wiz-step.done::before,.wiz-nav .wiz-step.active::before{background:#007bff;}
    .wiz-nav .wiz-step.disabled{cursor:not-allowed;opacity:.5;}
    .wiz-pane{display:none;} .wiz-pane.active{display:block;}
    #reviewArea .rk{color:#6c757d;font-size:12px;display:block;}
  </style>

  <script>
    $(function () {
        // Select2 + datetimepicker (same as current code)
        $('.select2').select2({ theme: 'bootstrap4' });
        $('#doc').datetimepicker({ format: 'YYYY-MM-DD' });
        $('#pcr_date').datetimepicker({ format: 'YYYY-MM-DD' });

        var enabled  = <?=json_encode(array_values($enabled));?>;
        var wizTotal = <?=$totalSteps;?>, wizCurrent = <?=$curStep;?>;

        // ---- review summary ----
        function esc(s){ return $('<div>').text(s==null?'':s).html(); }
        function buildReview(){
            var h = '<div class="row">';
            h += '<div class="col-sm-3 mb-2"><span class="rk">Facility</span><strong>'+esc($('select[name="facility"] option:selected').text().trim())+'</strong></div>';
            h += '<div class="col-sm-3 mb-2"><span class="rk">Case Type</span><strong>'+esc($('#case_type').val())+'</strong></div>';
            h += '<div class="col-sm-3 mb-2"><span class="rk">Patient</span><strong>'+esc($('#pname').val())+'</strong></div>';
            h += '<div class="col-sm-3 mb-2"><span class="rk">Age / Gender</span><strong>'+esc($('#age').val())+' / '+esc($('#gender').val())+'</strong></div>';
            h += '<div class="col-sm-3 mb-2"><span class="rk">Nationality</span><strong>'+esc($('#nationality').val())+'</strong></div>';
            h += '<div class="col-sm-3 mb-2"><span class="rk">BSE No.</span><strong>'+esc($('#bseno').val())+'</strong></div>';
            h += '<div class="col-sm-3 mb-2"><span class="rk">Collection Date</span><strong>'+esc($('input[name="doc"]').val())+'</strong></div>';
            h += '<div class="col-sm-3 mb-2"><span class="rk">Microscopy / RDT</span><strong>'+esc($('#microscopy').val())+' / '+esc($('#rdt').val())+'</strong></div>';
            h += '</div>';
            $('#reviewArea').html(h);
        }

        // ---- wizard nav ----
        function wizShow(n){
            if(enabled.indexOf(n)===-1){ return; }
            wizCurrent = n;
            $('.wiz-pane').removeClass('active'); $('.wiz-pane[data-step="'+n+'"]').addClass('active');
            $('.wiz-step').removeClass('active done');
            $('.wiz-step').each(function(){ var s=parseInt($(this).data('step')); if(s<n){ $(this).addClass('done'); } });
            $('.wiz-step[data-step="'+n+'"]').addClass('active');
            $('#wizProgress').css('width', ((n-1)/(wizTotal-1)*100)+'%');
            $('#wizPrev').prop('disabled', n<=1); $('#wizNext').toggle(n<wizTotal);
            if(n===wizTotal){ buildReview(); }
        }
        $('#wizNext').on('click', function(){ if(wizCurrent<wizTotal) wizShow(wizCurrent+1); });
        $('#wizPrev').on('click', function(){ if(wizCurrent>1) wizShow(wizCurrent-1); });
        $('.wiz-step').on('click', function(){ wizShow(parseInt($(this).data('step'))); });
        wizShow(wizCurrent);

        // ---- validation before final submit (validates every pane) ----
        $('#dbs').validate({
            ignore: [],
            rules: {
                dzongkhag:   "required",
                facility:    "required",
                case_type:   "required",
                pname:       "required",
                age:         "required",
                gender:      "required",
                nationality: "required",
                bseno:       "required",
                doc:         "required",
                collectedby: "required",
                microscopy:  "required",
                rdt:         "required"
            },
            errorElement: 'span',
            errorPlacement: function (error, element) { error.addClass('invalid-feedback'); element.closest('.form-group').append(error); },
            highlight:   function (element) { $(element).addClass('is-invalid'); },
            unhighlight: function (element) { $(element).removeClass('is-invalid'); },
            invalidHandler: function (ev, v) {
                if (v.errorList.length) {
                    var st = $(v.errorList[0].element).closest('.wiz-pane').data('step');
                    if (st) { wizShow(parseInt(st)); }
                    Swal.fire({ icon:'warning', text:'Please complete the required fields before submitting.', confirmButtonText:'OK' });
                }
            },
            submitHandler: function (form) { form.submit(); }
        });

        // ---- PCR form validation (RCDC card) ----
        $('#pcrData').validate({
            rules: { bseno:"required", pcr:"required", pcr_date:"required" },
            errorElement: 'span',
            errorPlacement: function (error, element) { error.addClass('invalid-feedback'); element.closest('.form-group').append(error); },
            highlight:   function (element) { $(element).addClass('is-invalid'); },
            unhighlight: function (element) { $(element).removeClass('is-invalid'); }
        });
    });

    $(document).ready(function(){
        // RCDC dzongkhag -> facility cascade (unchanged AJAX endpoint)
        $(document).on('change', '#dzongkhag', function(){
            var dzongkhag = $(this).val() || '';
            $("#facilityDiv").load("../load-facility-all.php?dzongkhag=" + encodeURIComponent(dzongkhag));
        });
    });
  </script>
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
