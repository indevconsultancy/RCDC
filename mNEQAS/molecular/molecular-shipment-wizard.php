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
            <h1>Molecular Shipment</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="molecular-shipment-list.php">Molecular Shipment List</a></li>
              <li class="breadcrumb-item active"> Molecular Shipment | Wizard</li>
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
        mn_require_record($conn, 'dbs_shipment', $id);   // record-level authorization (IDOR)
        $canceltr = "molecular-shipment-wizard.php?action=2&id=$id";

        // 0 shipment_id,1 facility_id,2 total_dbs_shipped,3 shipped_by,4 shipment_date,
        // 5 total_dbs_received,6 received_by,7 receive_date
        $dbsqry = $conn->query("SELECT shipment_id, facility_id, total_dbs_shipped, shipped_by, shipment_date, total_dbs_received, received_by, receive_date FROM dbs_shipment_details WHERE shipment_id=".$id);
        $trqry = $dbsqry->fetch_row();

        $facility  = $commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$trqry[1]'")->fetch_row();
        $dzongkhag = $commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$trqry[1]')")->fetch_row();

        if ($trqry[5] != '') {
            $deletepcr = "<a href='delete.php?type=rcdcship&dbsid=$id' class='btn btn-outline-danger' onclick=\"return confirm('Undo receipt?');\">Undo Receipt</a>";
        } else {
            /* A shipment is un-batched by RCDC at any time, or by the owning
               facility until RCDC has received it (see delete.php). This branch
               is already the not-yet-received case and the page only opens a
               record the user owns, so both of those hold here and no extra
               role test is needed. */
            $deletelab = "<a href='delete.php?type=labship&dbsid=$id' class='btn btn-outline-danger' onclick=\"return confirm('Delete this shipment?');\"><i class='fa fa-trash'></i> Delete Record</a>";
        }
    } else {
        $canceltr = "molecular-shipment-wizard.php?action=1";
        $trqry = array('', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '');
        $facility  = array('Select Facility');
        $dzongkhag = array('', 'Select Dzongkhag');
    }

    $received    = ($isEdit && $trqry[5] != '');       // receipt already recorded
    $showReceipt = ($isRCDC && $isEdit);               // Receipt step only for RCDC in edit mode
    $headerLock  = $received;                          // header (shipped_by / date) locked once received

    // Wizard model: 3 steps (Location, Select Samples, Shipping Details) + optional Receipt (RCDC edit)
    $totalSteps = $showReceipt ? 4 : 3;
    $enabled    = array(1, 2, 3);
    if ($showReceipt) { $enabled[] = 4; }
    $curStep    = isset($_GET["step"]) ? (int)$_GET["step"] : 1;
    if (!in_array($curStep, $enabled)) { $curStep = 1; }
    $stepLabels = array(1 => 'Location', 2 => 'Select Samples', 3 => 'Shipping Details');
    if ($showReceipt) { $stepLabels[4] = 'Receipt'; }

    // Fixed facility for district users (drives pre-load of samples on the new-shipment step)
    $fixedFacility = (!$isRCDC && !$isEdit) ? $facilityId : ($isEdit ? $trqry[1] : '');
?>

    <!-- ============================ SHIPMENT WIZARD ============================ -->
    <section class="content">
      <div class="container-fluid">
        <div class="card card-primary card-outline">
          <div class="card-header">
            <h3 class="card-title"><i class="fa fa-truck"></i>&nbsp; DBS Shipment &mdash; <?= $isEdit ? 'Edit Shipment' : 'New Shipment'; ?></h3>
            <div class="card-tools"><?=$deletelab;?></div>
          </div>
          <div class="card-body">

            <?php if ($received) { ?>
              <div class="alert alert-info"><i class="fa fa-lock"></i> This shipment has been received by RCDC &mdash; shipment details are locked.</div>
            <?php } elseif (!$isEdit) { ?>
              <div class="alert alert-info"><i class="fa fa-info-circle"></i> Pick a facility, select the un-shipped samples to batch, then enter the shipping details and submit.</div>
            <?php } ?>

            <!-- Stepper -->
            <ul class="wiz-nav" id="wizNav">
              <?php foreach ($stepLabels as $sn => $lbl) { ?>
                <li class="wiz-step" data-step="<?=$sn;?>"><span class="wiz-num"><?=$sn;?></span><span class="wiz-label"><?=$lbl;?></span></li>
              <?php } ?>
            </ul>
            <div class="progress mb-4" style="height:4px;"><div id="wizProgress" class="progress-bar bg-primary" style="width:0%;"></div></div>

            <!-- ============ #labShipment FORM (Steps 1-3, ONE final submit) ============ -->
            <form class="form-horizontal" id="labShipment" autocomplete="off" method="Post" action="iu-molecular-shipment.php">
              <input type='hidden' name='action' id='action' value='<?=$action;?>'>
              <input type='hidden' name='submit' id='submit' value='lab'>
              <input type="hidden" name="sid" id="sid" value="<?=$trqry[0];?>">

              <!-- STEP 1: LOCATION -->
              <div class="wiz-pane" data-step="1">
                <h5 class="text-primary mb-3">Shipping Facility</h5>
                <div class="row">
                <?php if ($isRCDC) { ?>
                  <div class="form-group col-md-6">
                    <label class="col-form-label-sm">Dzongkhag:</label>
                    <select class="form-control select2" name="dzongkhag" id="dzongkhag" style="width:100%;" <?=$headerLock?'disabled':'';?>>
                      <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                      <?php $rs = $commondb->query($dzsql); while ($row = $rs->fetch_row()) { echo "<option value='$row[0]'>$row[1]</option>"; } ?>
                    </select>
                  </div>
                  <div class="form-group col-md-6">
                    <label class="col-form-label-sm">Health Facility:</label>
                    <div id="facilityDiv">
                      <select class="form-control" name="facility" id="facility" <?=$headerLock?'disabled':'';?> required>
                        <option value="<?=$trqry[1];?>"><?=$facility[0];?></option>
                      </select>
                    </div>
                  </div>
                <?php } else { ?>
                  <div class="form-group col-md-6">
                    <label class="col-form-label-sm">Dzongkhag:</label>
                    <select class="form-control select2" name="dzongkhag" id="dzongk" style="width:100%;" <?=$headerLock?'disabled':'';?>>
                      <option value='<?=$dzongkhag_code;?>'><?=$dzongkhag_name;?></option>
                    </select>
                  </div>
                  <div class="form-group col-md-6">
                    <label class="col-form-label-sm">Health Facility:</label>
                    <div id="facilityDiv">
                      <select class="form-control" name="facility" id="facility" required <?=$headerLock?'disabled':'';?>>
                        <option value="<?=($isEdit ? $trqry[1] : $facilityId);?>"><?=($isEdit ? $facility[0] : $center_name);?></option>
                      </select>
                    </div>
                  </div>
                <?php } ?>
                </div>
              </div>

              <!-- STEP 2: SELECT SAMPLES -->
              <div class="wiz-pane" data-step="2">
                <h5 class="text-primary mb-3">Samples in this Shipment</h5>
                <?php if ($isEdit) {
                    // Read-only listing of the samples already batched into this shipment
                    $samples = $conn->query("SELECT dbs_id, patient_name, age, bse_no, collection_date FROM dbs_results WHERE dbs_shipment_id=$trqry[0] AND facility_id=$trqry[1]");
                    if ($samples && $samples->num_rows >= 1) { ?>
                      <table class="table table-bordered table-hover">
                        <thead>
                          <tr><th scope="col">Sample #</th><th>Patient Name</th><th>Age</th><th>BSE No.</th><th>Collection Date</th></tr>
                        </thead>
                        <tbody>
                          <?php while ($row = $samples->fetch_row()) { ?>
                          <tr>
                            <td scope="row"><?=$row[0];?></td>
                            <td><?=htmlspecialchars($row[1], ENT_QUOTES);?></td>
                            <td><?=$row[2];?></td>
                            <td><?=htmlspecialchars($row[3], ENT_QUOTES);?></td>
                            <td><?=$row[4];?></td>
                          </tr>
                          <?php } ?>
                        </tbody>
                      </table>
                      <p class="text-muted"><small>Batched samples cannot be changed after the shipment is created.</small></p>
                <?php } else { ?>
                      <div class="alert alert-secondary">No samples are linked to this shipment.</div>
                <?php }
                  } else { ?>
                  <p class="text-muted"><small>All un-shipped samples for the selected facility are pre-checked. Uncheck any you do not want to include.</small></p>
                  <div id="sampleDiv">
                    <div class="form-group required col-sm-12">
                      <label>Select the facility first to load its un-shipped samples.</label>
                    </div>
                  </div>
                <?php } ?>
              </div>

              <!-- STEP 3: SHIPPING DETAILS -->
              <div class="wiz-pane" data-step="3">
                <h5 class="text-primary mb-3">Shipping Details</h5>
                <div class="row">
                  <div class="form-group col-lg-4 col-md-6">
                    <label class="col-form-label-sm">No. DBS Shipped:</label>
                    <input type="number" name="totaldbs" id="totaldbs" class="form-control" value="<?=$trqry[2];?>" readonly>
                  </div>
                  <div class="form-group col-lg-4 col-md-6">
                    <label class="col-form-label-sm">Shipped By:</label>
                    <input type="text" name="shippedby" id="shippedby" class="form-control" value="<?=htmlspecialchars($trqry[3], ENT_QUOTES);?>" placeholder="Shipped By" required <?=$headerLock?'readonly':'';?>>
                  </div>
                  <div class="form-group col-lg-4 col-md-6">
                    <label class="col-form-label-sm">Shipment Date:</label>
                    <div class="input-group date" id="dos" data-target-input="nearest">
                      <input type="text" class="form-control datetimepicker-input text-center" data-target="#dos" name="dos" placeholder="YYYY-MM-DD" value="<?=$trqry[4];?>" <?=$headerLock?'readonly':'';?> />
                      <div class="input-group-append" data-target="#dos" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                  </div>
                </div>
                <?php if (!$headerLock) { ?>
                <div class="text-right">
                  <button type="submit" class="btn bg-gradient-success" id="wizSubmit"><i class="fa fa-check"></i> <?= $isEdit ? 'Save Shipment' : 'Submit Shipment'; ?></button>
                </div>
                <?php } ?>
              </div>
            </form>
            <!-- /#labShipment form -->

<?php if ($showReceipt) { ?>
            <!-- ============ STEP 4: RCDC RECEIPT (separate form) ============ -->
            <div class="wiz-pane" data-step="4">
              <h5 class="text-primary mb-3">Shipment Receipt</h5>
              <form class="form-horizontal" id="shipmentReceipt" autocomplete="off" method="Post" action="iu-molecular-shipment.php">
                <input type='hidden' name='action' value='<?=$action;?>'>
                <input type='hidden' name='submit' value='shipmentReceipt'>
                <input type="hidden" name="sid" value="<?=$trqry[0];?>">
                <div class="row">
                  <div class="form-group col-lg-4 col-md-6">
                    <label class="col-form-label-sm">No. DBS Received:</label>
                    <input type="number" name="numberdbs" id="numberdbs" class="form-control" value="<?=$trqry[5];?>" required>
                  </div>
                  <div class="form-group col-lg-4 col-md-6">
                    <label class="col-form-label-sm">Received By:</label>
                    <input type="text" name="receivedby" id="receivedby" class="form-control" value="<?=htmlspecialchars($trqry[6], ENT_QUOTES);?>" required>
                  </div>
                  <div class="form-group col-lg-4 col-md-6">
                    <label class="col-form-label-sm">Receipt Date:</label>
                    <div class="input-group date" id="dor" data-target-input="nearest">
                      <input type="text" class="form-control datetimepicker-input text-center" data-target="#dor" name="dor" placeholder="YYYY-MM-DD" value="<?=$trqry[7];?>" />
                      <div class="input-group-append" data-target="#dor" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="text-right">
                  <?=$deletepcr;?>
                  <button type="submit" class="btn bg-gradient-success"><i class="fa fa-save"></i> Save Receipt</button>
                </div>
              </form>
            </div>
<?php } ?>

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
                  'module'    => 'molecular_shipment',
                  'record_id' => (int)($id ?? 0),
                  'can_write' => true,
                  'steps'     => array(
                      1 => array('name' => 'Shipping Facility',           'rows' => 'record', 'saved' => $isEdit),
                      2 => array('name' => 'Samples in this Shipment',    'rows' => 'many',   'saved' => $isEdit),
                      3 => array('name' => 'Shipping Details',            'rows' => 'record', 'saved' => $isEdit),
                      4 => array('name' => 'Shipment Receipt',            'rows' => 'record', 'saved' => $isEdit),
                  ),
              ));
              ?>
            </div>
            <div><button type="button" class="btn bg-gradient-primary" id="wizNext">Next <i class="fa fa-arrow-right"></i></button></div>
          </div>
        </div>
      </div>
    </section>

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
  </style>

  <script>
    $(function () {
        // Select2 + datetimepicker (same as current code)
        $('.select2').select2({ theme: 'bootstrap4' });
        $('#dos').datetimepicker({ format: 'YYYY-MM-DD' });
        $('#dor').datetimepicker({ format: 'YYYY-MM-DD' });

        var enabled     = <?=json_encode(array_values($enabled));?>;
        var wizTotal    = <?=$totalSteps;?>, wizCurrent = <?=$curStep;?>;
        var isNew       = <?=$isEdit ? 'false' : 'true';?>;
        var fixedFac    = "<?=$fixedFacility;?>";

        // ---- live count of checked samples -> No. DBS Shipped ----
        function updateCount(){
            var n = $('#sampleDiv input[name="sample_id[]"]:checked').length;
            $('#totaldbs').val(n);
        }
        function loadSamples(fac){
            if(!fac){ return; }
            $("#sampleDiv").load("load-shipment-sample.php?facility="+fac, function(){ updateCount(); });
        }
        $('#sampleDiv').on('change', 'input[name="sample_id[]"]', updateCount);

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
        }
        $('#wizNext').on('click', function(){ if(wizCurrent<wizTotal) wizShow(wizCurrent+1); });
        $('#wizPrev').on('click', function(){ if(wizCurrent>1) wizShow(wizCurrent-1); });
        $('.wiz-step').on('click', function(){ wizShow(parseInt($(this).data('step'))); });
        wizShow(wizCurrent);

        // ---- validation before final submit (#labShipment) ----
        $('#labShipment').validate({
            ignore: [],
            rules: {
                dzongkhag: "required",
                facility:  "required",
                shippedby: "required",
                dos:       "required"
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
            submitHandler: function (form) {
                // On a NEW shipment there must be at least one selected sample
                if (isNew && $('#sampleDiv input[name="sample_id[]"]:checked').length === 0) {
                    wizShow(2);
                    Swal.fire({ icon:'warning', text:'Select at least one sample to include in this shipment.', confirmButtonText:'OK' });
                    return false;
                }
                form.submit();
            }
        });

        // ---- validation (#shipmentReceipt) ----
        $('#shipmentReceipt').validate({
            rules: { numberdbs:"required", receivedby:"required", dor:"required" },
            errorElement: 'span',
            errorPlacement: function (error, element) { error.addClass('invalid-feedback'); element.closest('.form-group').append(error); },
            highlight:   function (element) { $(element).addClass('is-invalid'); },
            unhighlight: function (element) { $(element).removeClass('is-invalid'); }
        });

        // ---- pre-load samples for a fixed facility (district new shipment, or RCDC after facility already chosen) ----
        if (isNew && fixedFac !== "") { loadSamples(fixedFac); }
    });

    $(document).ready(function(){
        // RCDC dzongkhag -> facility cascade (unchanged AJAX endpoint)
        $(document).on('change', '#dzongkhag', function(){
            var dzongkhag = $(this).val() || '';
            $("#facilityDiv").load("../load-facility-all.php?dzongkhag=" + encodeURIComponent(dzongkhag));
        });
        // Facility -> load its un-shipped samples (new shipment only). Delegated: #facility is replaced by AJAX.
        $(document).on('change', '#facility', function(){
            var fac = $(this).val();
            if (fac) { $("#sampleDiv").load("load-shipment-sample.php?facility="+fac, function(){
                var n = $('#sampleDiv input[name="sample_id[]"]:checked').length; $('#totaldbs').val(n);
            }); }
        });
    });
  </script>
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
