<!-- Check session --><?php require_once("../config/session.php"); ?>
<?php require_once("../includes/header.php"); ?>
<?php require_once("../config/commondb-config.php"); require_once("../config/db-config.php"); ?>
<?php require_once("../includes/css-jquery.php"); ?>
<?php require_once("../includes/wizard-step-delete.php"); ?>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <?php require_once("../includes/top-navbar.php"); ?>
  <?php require_once("../includes/sidebar-menu.php"); ?>

  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6"><h1>RDT Lot Test Registration</h1></div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="rdt-list.php">RDT List</a></li>
              <li class="breadcrumb-item active">Lot Test Registration</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

<?php
    // ------------------------------------------------------------------ state
    $dzsql  = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
    $isRCDC = ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2);   // FIXED role check

    // Receipt editability follows PERMISSION, not whether downstream report data
    // exists. Roles with Update permission — Administrator (1), RCDC Lab (2),
    // Health Worker (3) — may always edit the RCDC receipt; only read-only roles
    // ("Others") get locked fields. Single source of truth for both the PHP
    // rendering and the JS submit gate below.
    $canEditReceipt = ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2 || $_SESSION["roleID"] == 3);

    if (!$isRCDC) {
        $facilityId = $_SESSION["facilityID"];
        list($dzongkhag_code, $dzongkhag_name) = $commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$facilityId')")->fetch_row();
        list($center_name) = $commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$facilityId'")->fetch_row();
    }

    $action  = isset($_GET["action"]) ? $_GET["action"] : 1;
    $isEdit  = ($action == 2);
    $id      = $isEdit ? (isset($_GET["id"]) ? (int)$_GET["id"] : 0) : 0;
    // Record-level authorization (IDOR): facility users may open only their own facility's request.
    if ($isEdit) { mn_require_record($conn, 'rdt_request', $id); }
    $tridVal = $isEdit ? $id : '';

    /* Catalog No is optional schema, and the product table, its JS row template
       and the worksheet export all key off this one flag. Declared before the
       $isEdit branch so a new request sees it too. Where it is selected it is
       appended LAST, so every existing $p[...] index keeps its position (those
       rows are read with fetch_row()) and catalog_no lands at $p[12]. */
    $catChk     = @$conn->query("SHOW COLUMNS FROM rdt_product_details LIKE 'catalog_no'");
    $hasCatalog = ($catChk && $catChk->num_rows > 0);

    $products = array();
    if ($isEdit) {
        $trqry = $conn->query("SELECT rdt_request_id, facility_id, date_request, staff_name, staff_designation, lot_type, date_shipment, date_receive, received_by, designation, lot_type_detail FROM rdt_test_request WHERE rdt_request_id=".$id)->fetch_row();
        $facility  = $commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$trqry[1]'")->fetch_row();
        $dzongkhag = $commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$trqry[1]')")->fetch_row();
        $pq = $conn->query("SELECT p.product_id, p.product_name, p.manufacturer, p.lot_no, p.date_manufacture, p.date_expiry,
                                   s.sent_no_box, s.sent_test_per_box, s.received_no_box, s.received_test_per_box,
                                   (SELECT performance_result FROM rdt_performance_report WHERE manufacturer_id=p.product_id LIMIT 1) perf,
                                   (SELECT performance_id FROM rdt_performance_report WHERE manufacturer_id=p.product_id LIMIT 1) prid"
                            . ($hasCatalog ? ", p.catalog_no" : "") . "
                            FROM rdt_product_details p LEFT JOIN rdt_shipment_details s ON s.product_details_id=p.product_id
                            WHERE p.test_request_id=".$id." ORDER BY p.product_id");
        while ($r = $pq->fetch_row()) { $products[] = $r; }
    } else {
        $trqry = array('','','','','','','','','','');
        $facility = array('Select Facility'); $dzongkhag = array('','Select Dzongkhag');
    }

    $shipped   = ($isEdit && $trqry[6] != NULL);
    $received  = ($isEdit && $trqry[7] != NULL);
    $hasProd   = (count($products) > 0);
    // Facility data stays editable until RCDC records the receipt, and only for roles
    // that may write at all: read-only roles (e.g. District Health Office) see it
    // read-only, which is what the save handler already enforces.
    $facilityEditable = !$received && mn_can_write();
    $facLocked        = !$facilityEditable;
    // Workflow completion, derived from what the record holds (no status column):
    //  - facility stage SUBMITTED once the request, at least one product and the
    //    shipment date are saved (exactly what Review -> Submit produces);
    //  - RCDC stage COMPLETE once the shipment is received and every product has a
    //    performance result (what "Submit All & Finish" produces).
    $facilitySubmitted = ($isEdit && $hasProd && $shipped);
    $rcdcComplete = ($received && $hasProd);
    foreach ($products as $pc) { if ((string)$pc[10] === '') { $rcdcComplete = false; } }

    // Report progress (drives receipt locking, stepper "Completed" marks and step unlocking)
    $assesCnt = 0; $perfCnt = 0;
    if ($isEdit) {
        $assesCnt = $conn->query("SELECT rar_id FROM rdt_assessment_report, rdt_product_details WHERE manufacturer_id=product_id AND test_request_id=$id")->num_rows;
        $perfCnt  = $conn->query("SELECT performance_id FROM rdt_performance_report, rdt_product_details WHERE performance_result !='' AND manufacturer_id=product_id AND test_request_id=$id")->num_rows;
    }

    $totalSteps = $isRCDC ? 7 : 4;
    $reviewStep = $totalSteps;

    // Progressive enabling — strict stage chain:
    // Request/Products/Shipment (one form) -> Receipt -> Assessment -> Performance -> Review.
    // Each later stage unlocks only once the previous one is SAVED. The
    // ($perfCnt > 0) alternative on step 6 grandfathers legacy records that
    // hold performance data without a saved assessment, so their verdicts
    // stay reachable/editable.
    if ($isRCDC) {
        $enabled = array(1,2,3);
        if ($shipped)                                      { $enabled[] = 4; }
        if ($shipped && $received)                         { $enabled[] = 5; }
        if ($received && ($assesCnt > 0 || $perfCnt > 0))  { $enabled[] = 6; }
        if ($received && $perfCnt > 0)                     { $enabled[] = 7; }
    } else {
        $enabled = array(1,2,3,4);
    }
    sort($enabled);

    // Stages with saved data show as "Completed" in the stepper
    $doneSteps = array();
    if ($isEdit) {
        $doneSteps[] = 1;
        if ($hasProd)  { $doneSteps[] = 2; }
        if ($shipped)  { $doneSteps[] = 3; }
        if ($isRCDC) {
            if ($received)     { $doneSteps[] = 4; }
            if ($assesCnt > 0) { $doneSteps[] = 5; }
            if ($perfCnt > 0)  { $doneSteps[] = 6; }
            if ($rcdcComplete) { $doneSteps[] = 7; }                 // Review & Print
        } else if ($facilitySubmitted) {
            $doneSteps[] = 4;                                          // Review & Submit
        }
    }

    $curStep = isset($_GET["step"]) ? (int)$_GET["step"] : 1;
    if (!in_array($curStep, $enabled)) {
        // Land on the furthest unlocked step at or before the requested one
        // (never silently bounce back to step 1 after a save).
        $fallback = $enabled[0];
        foreach ($enabled as $e) { if ($e <= $curStep) { $fallback = $e; } }
        $curStep = $fallback;
    }

    $canceltr = "rdt-registration.php?action=".($isEdit ? "2&id=$id" : "1");
    $deletetr = ($isEdit && !$hasProd) ? "<a href='delete.php?type=tr&id=$id&trid=$id' class='btn btn-outline-danger btn-sm' onclick=\"return confirm('Delete this request?');\"><i class='fa fa-trash'></i> Delete Record</a>" : "";

    $stepLabels = $isRCDC
        ? array(1=>'Request',2=>'Products',3=>'Shipment',4=>'Shipment Received',5=>'Accessory Assessment',6=>'Performance Report',7=>'Review & Print')
        : array(1=>'Request',2=>'Products',3=>'Shipment',4=>'Review');
?>

    <section class="content">
      <div class="container-fluid">
        <div class="card card-primary card-outline">
          <div class="card-header">
            <h3 class="card-title"><i class="fa fa-vials"></i>&nbsp; RDT Lot Test &mdash; <?= $isRCDC ? 'RCDC Wizard' : 'Facility Stage'; ?></h3>
            <div class="card-tools"><?=$deletetr;?></div>
          </div>
          <div class="card-body">

            <!-- Stepper -->
            <ul class="wiz-nav" id="wizNav">
              <?php foreach ($stepLabels as $sn => $lbl) { ?>
                <li class="wiz-step" data-step="<?=$sn;?>"><span class="wiz-num"><?=$sn;?></span><span class="wiz-label"><?=$lbl;?></span></li>
              <?php } ?>
            </ul>
            <div class="progress mb-4" style="height:4px;"><div id="wizProgress" class="progress-bar bg-primary" style="width:0%;"></div></div>

            <!-- ============ FACILITY FORM (Steps 1-3, + Review for facility users) ============ -->
            <form id="rdtWizard" method="post" action="iu-rdt-registration-save.php" autocomplete="off">
            <input type="hidden" name="submit" value="facility">
            <input type="hidden" name="trid" value="<?=$tridVal;?>">
            <!-- Set by the RCDC Next button so the save lands on the following step -->
            <input type="hidden" name="nextstep" id="rdtNextStep" value="">
            <div id="deletedProducts"></div>

            <!-- STEP 1: REQUEST -->
            <div class="wiz-pane" data-step="1">
              <h5 class="text-primary mb-3">Requesting Details</h5>
              <div class="row">
              <?php if ($isRCDC) { ?>
                <div class="form-group col-sm-4">
                  <label class="col-form-label-sm">Dzongkhag:</label>
                  <select class="form-control" name="dzongkhag" id="dzongkhag" style="width:100%;" <?=$facLocked?'disabled':'';?>>
                    <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                    <?php $rs=$commondb->query($dzsql); while($row=$rs->fetch_row()){ echo "<option value='$row[0]'>$row[1]</option>"; } ?>
                  </select>
                </div>
                <div class="form-group col-sm-4">
                  <label class="col-form-label-sm">Health Facility:</label>
                  <div id="facilityDiv">
                    <select class="form-control" name="facility" id="facility" <?=$facLocked?'disabled':'';?>>
                      <option value="<?=$trqry[1];?>"><?=$facility[0];?></option>
                    </select>
                  </div>
                </div>
              <?php } else { ?>
                <div class="form-group col-sm-4">
                  <label class="col-form-label-sm">Dzongkhag:</label>
                  <select class="form-control" name="dzongkhag" id="dzongk" style="width:100%;" disabled>
                    <option value="<?=($isEdit?$dzongkhag[0]:$dzongkhag_code);?>" selected><?=($isEdit?$dzongkhag[1]:$dzongkhag_name);?></option>
                  </select>
                </div>
                <div class="form-group col-sm-4">
                  <label class="col-form-label-sm">Health Facility:</label>
                  <div id="facilityDiv">
                    <select class="form-control" name="facility" id="facility" readonly>
                      <option value="<?=($isEdit?$trqry[1]:$facilityId);?>"><?=($isEdit?$facility[0]:$center_name);?></option>
                    </select>
                  </div>
                </div>
              <?php } ?>
                <div class="form-group col-sm-4">
                  <label class="col-form-label-sm">Date of Request:</label>
                  <div class="input-group date" data-target-input="nearest">
                    <input type="text" name="dor" id="dor" class="form-control datetimepicker-input" data-target="#dor" value="<?=$trqry[2];?>" placeholder="YYYY-MM-DD" <?=$facLocked?'readonly':'';?>>
                    <div class="input-group-append" data-target="#dor" data-toggle="datetimepicker">
                      <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="row">
                <div class="form-group col-sm-4"><label class="col-form-label-sm">Name of the staff:</label>
                  <input type="text" name="staffName" id="staffName" class="form-control" value="<?=htmlspecialchars($trqry[3] ?? '',ENT_QUOTES);?>" placeholder="Name of Staff" <?=$facLocked?'readonly':'';?>></div>
                <div class="form-group col-sm-4"><label class="col-form-label-sm">Designation:</label>
                  <input type="text" name="designation" id="designation" class="form-control" value="<?=htmlspecialchars($trqry[4] ?? '',ENT_QUOTES);?>" placeholder="Designation" <?=$facLocked?'readonly':'';?>></div>
                <div class="form-group col-sm-4">
  <label class="col-form-label-sm">Type of Lot Test:</label>
  <div class="d-flex align-items-center" style="gap:8px;">
    <select class="form-control" name="tol_select" id="tol_select"
            style="flex:1; min-width:0;" <?=$facLocked?'disabled':'';?>>
      <option value="">-- Select --</option>
      <option value="Initial" <?= ($isEdit && $trqry[5]==='Initial') ? 'selected' : '' ?>>Initial</option>
      <option value="Long Term" <?= ($isEdit && $trqry[5]==='Long Term') ? 'selected' : '' ?>>Long Term</option>
      <option value="__manual__" <?= ($isEdit && $trqry[5]!=='' && $trqry[5]!=='Initial' && $trqry[5]!=='Long Term') ? 'selected' : '' ?>>Other (specify)</option>
    </select>
    <input type="text"
           name="tol"
           id="tol"
           class="form-control"
           value="<?= htmlspecialchars($trqry[5] ?? '', ENT_QUOTES) ?>"
           placeholder="Specify lot-test type"
           style="flex:1; min-width:0; <?= ($isEdit && $trqry[5]!=='' && $trqry[5]!=='Initial' && $trqry[5]!=='Long Term') ? '' : 'display:none;' ?>"
           <?=$facLocked?'readonly':'';?>>
    <input type="text"
           name="tol_long_term"
           id="tol_lt"
           class="form-control"
           value="<?= htmlspecialchars($trqry[10] ?? '', ENT_QUOTES) ?>"
           placeholder="Long term details"
           style="flex:1; min-width:0; <?= ($isEdit && ($trqry[5] ?? '')==='Long Term') ? '' : 'display:none;' ?>"
           <?=$facLocked?'readonly':'';?>>
  </div>
</div>
              </div>
            </div>

            <!-- STEP 2: PRODUCTS -->
            <div class="wiz-pane" data-step="2">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div><h5 class="text-primary mb-0">Product Details</h5><small class="text-muted">Add each RDT product/lot in this request.</small></div>
                <?php if (!$shipped && !$facLocked) { ?><button type="button" class="btn bg-gradient-success btn-sm" id="btnAddProduct"><i class="fa fa-plus"></i> Add New Entry</button><?php } ?>
              </div>
              <div class="table-responsive rdt-products-scroll"><table class="table table-bordered table-sm align-middle" id="wizProductTable">
                <thead class="thead-light text-center"><tr><th style="width:18%;">Product Name</th><th style="width 18%;;">Manufacturer</th><?php if ($hasCatalog) { ?><th style="width:14%;">Catalog No.</th><?php } ?><th style="width:16%;">Lot No.</th><th style="width:22%;">Manufacture Date</th><th style="width:22%;">Expiry Date</th><th style="width:4%;"></th></tr></thead>
                <tbody id="productTbody">
                  <?php foreach ($products as $i => $p) { $plocked = ($p[8] !== null || $p[10] !== null); ?>
                  <tr data-rid="<?=$i;?>">
                    <td><input type="hidden" name="product_pid[]" value="<?=$p[0];?>"><input type="text" name="pname[]" class="form-control form-control-sm p-name" value="<?=htmlspecialchars($p[1] ?? '',ENT_QUOTES);?>" <?=$facLocked?'readonly':'';?>></td>
                    <td><input type="text" name="manufacturer[]" class="form-control form-control-sm" value="<?=htmlspecialchars($p[2] ?? '',ENT_QUOTES);?>" <?=$facLocked?'readonly':'';?>></td>
                    <?php if ($hasCatalog) { ?>
                    <td><input type="text" name="catno[]" class="form-control form-control-sm" value="<?=htmlspecialchars($p[12] ?? '',ENT_QUOTES);?>" <?=$facLocked?'readonly':'';?>></td>
                    <?php } ?>
                    <td><input type="text" name="lno[]" class="form-control form-control-sm p-lot" value="<?=htmlspecialchars($p[3] ?? '',ENT_QUOTES);?>" <?=$facLocked?'readonly':'';?>></td>
                    <td>
                      <div class="input-group date p-mdt-wrap" id="mdtWrap_<?=$i;?>" data-target-input="nearest">
                        <input type="text" name="mdt[]" class="form-control form-control-sm datetimepicker-input" data-target="#mdtWrap_<?=$i;?>" value="<?=$p[4];?>" placeholder="YYYY-MM-DD" <?=$facLocked?'readonly':'';?>>
                        <div class="input-group-append" data-target="#mdtWrap_<?=$i;?>" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="input-group date p-edt-wrap" id="edtWrap_<?=$i;?>" data-target-input="nearest">
                        <input type="text" name="edt[]" class="form-control form-control-sm datetimepicker-input" data-target="#edtWrap_<?=$i;?>" value="<?=$p[5];?>" placeholder="YYYY-MM-DD" <?=$facLocked?'readonly':'';?>>
                        <div class="input-group-append" data-target="#edtWrap_<?=$i;?>" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                      </div>
                    </td>
                    <td class="text-center"><?php if (!$shipped && !$facLocked && !$plocked) { ?><button type="button" class="btn btn-outline-danger btn-sm prodDel"><i class="fa fa-trash"></i></button>
                    <?php }
                    /* Once the shipment is saved the staged control above stops
                       rendering, so a product added by mistake could no longer be
                       removed at all. delete.php?type=prod clears the product and
                       everything recorded against it in one transaction.

                       Deliberately narrow: RCDC only, because a facility user has
                       no delete here today and this is not the place to grant one;
                       and never when $plocked, which means the product already
                       carries received boxes or a performance result - that is lab
                       data, and the module's rule throughout is that clearing a
                       stage must not destroy the results that depend on it. */
                    else if ($isEdit && (int)$p[0] > 0 && !$plocked && mn_is_rcdc()) { ?>
                      <a href="delete.php?type=prod&id=<?=(int)$p[0];?>&trid=<?=(int)$id;?>" class="btn btn-outline-danger btn-sm" title="Delete this product" onclick="return confirm('Are you sure you want to delete this record?');"><i class="fa fa-trash"></i></a>
                    <?php } ?></td>
                  </tr>
                  <?php } ?>
                </tbody>
              </table></div>
              <p class="text-muted mb-0" id="prodEmptyHint" style="display:none;"><small>No products yet. Use <strong>Add New Entry</strong>.</small></p>
            </div>

            <!-- STEP 3: SHIPMENT -->
            <div class="wiz-pane" data-step="3">
              <h5 class="text-primary mb-3">Lab Shipment</h5>
              <div class="row mb-3"><div class="form-group col-sm-3"><label class="col-form-label-sm">Shipment Date:</label>
                <div class="input-group date" data-target-input="nearest">
                  <input type="text" name="shdt" id="shdt" class="form-control datetimepicker-input" data-target="#shdt" value="<?=$trqry[6];?>" placeholder="YYYY-MM-DD" <?=$facLocked?'readonly':'';?>>
                  <div class="input-group-append" data-target="#shdt" data-toggle="datetimepicker">
                    <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                  </div>
                </div></div></div>
              <div class="table-responsive"><table class="table table-bordered table-sm align-middle" id="wizShipTable">
                <thead class="text-center"><tr><th style="width:25%">Product</th><th style="width:25%;">Lot No.</th><th style="width:25%;">No. of Boxes</th><th style="width:25%;">Tests per Box</th></tr></thead>
                <tbody id="shipTbody">
                  <?php foreach ($products as $i => $p) { ?>
                    <tr data-rid="<?=$i;?>"><td class="s-name font-weight-medium"><?=htmlspecialchars($p[1] ?? '',ENT_QUOTES);?></td><td class="text-center s-lot"><?=htmlspecialchars($p[3] ?? '',ENT_QUOTES);?></td>
                    <td><input type="number" min="0" name="snbox[]" class="form-control form-control-sm text-center" value="<?=$p[6];?>" <?=$facLocked?'readonly':'';?>></td>
                    <td><input type="number" min="0" name="stpbox[]" class="form-control form-control-sm text-center" value="<?=$p[7];?>" <?=$facLocked?'readonly':'';?>></td></tr>
                  <?php } ?>
                </tbody>
              </table></div>
              <p class="text-muted"><small>Boxes/tests match the products above by row order.</small></p>
              <?php
              /* This step had no delete of its own. delete.php?type=ship removes
                 the shipment rows for every product of the request and clears the
                 shipment date, which puts the request back to "not yet shipped".

                 Offered only while the shipment has NOT been received: the box
                 counts RCDC recorded on receipt live in the same rows, so clearing
                 after receipt would destroy them. Undoing a receipt is a separate
                 action (type=uship) and already has its own button. The endpoint
                 enforces the same rule, plus RCDC-or-owning-facility. */
              if ($isEdit && $shipped && !$received && ($isRCDC || $facilityEditable)) { ?>
              <div class="text-right mb-2">
                <a href="delete.php?type=ship&id=<?=(int)$id;?>&trid=<?=(int)$id;?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to delete this record?');"><i class="fa fa-trash"></i> Clear Shipment</a>
              </div>
              <?php } ?>
              <?php if ($facilityEditable) { ?>
             <!-- <div class="text-right"><button type="submit" class="btn bg-gradient-success" id="facilitySave"><i class="fa fa-save"></i> <?= $isRCDC ? 'Save &amp; Continue' : 'Save'; ?></button></div> -->
              <?php } ?>
            </div>

            <?php if (!$isRCDC) { ?>
            <!-- STEP 4 (facility): REVIEW -->
            <div class="wiz-pane" data-step="4">
              <h5 class="text-primary mb-3">Review &amp; Submit</h5>
              <?php if ($facilitySubmitted) { ?>
              <div class="callout callout-success py-2 mb-3" id="rdtSubmittedNote">
                <i class="fa fa-check-circle text-success"></i>&nbsp;
                <strong>Submitted to RCDC</strong> &mdash; shipment dated <?=htmlspecialchars((string)$trqry[6], ENT_QUOTES);?>.
                <?php if ($received) { ?>
                  RCDC received it on <?=htmlspecialchars((string)$trqry[7], ENT_QUOTES);?>; the request can no longer be changed.
                <?php } else if ($facilityEditable) { ?>
                  You can still correct it until RCDC records the receipt.
                <?php } else { ?>
                  Awaiting receipt by RCDC.
                <?php } ?>
              </div>
              <?php } else { ?>
              <p class="text-muted">Nothing is saved until you click <strong>Submit</strong>.</p>
              <?php } ?>
              <div id="reviewArea"></div>
              <?php if ($facilityEditable) { ?><div class="text-right"><button type="submit" class="btn bg-gradient-success" id="wizSubmit"><i class="fa fa-check"></i> <?= $facilitySubmitted ? 'Update Submission' : 'Submit'; ?></button></div><?php } ?>
            </div>
            <?php } ?>
            </form>
            <!-- /facility form -->

<?php if ($isRCDC) { ?>
            <!-- ============ STEP 4: SHIPMENT RECEIVED (RCDC RECEIPT) ============ -->
            <div class="wiz-pane" data-step="4">
              <h5 class="text-primary mb-3">Shipment Received &mdash; RCDC Receipt Information</h5>
              <?php if (!$shipped) { ?>
                <div class="alert alert-warning">Save the Lab Shipment first to record receipt.</div>
              <?php } else {
                $recLock  = !$canEditReceipt;   // lock only for read-only roles; Update roles edit regardless of report data
              ?>
              <form id="receiptForm" method="post" action="iu-rdt-registration-save.php" autocomplete="off">
                <input type="hidden" name="submit" value="receipt">
                <input type="hidden" name="trid" value="<?=$id;?>">
                <input type="hidden" name="productCnt" value="<?=count($products);?>">
                <div class="table-responsive"><table class="table table-bordered table-sm">
                  <thead class="text-center"><tr><th style="width:25%;">Product</th><th style="width:25%;">Lot No. (correctable)</th><th style="width:25%;">Received Boxes</th><th style="width:25%;">Tests per Box</th></tr></thead>
                  <tbody>
                  <?php foreach ($products as $i => $p) { ?>
                    <tr>
                      <td><?=htmlspecialchars($p[1] ?? '',ENT_QUOTES);?><input type="hidden" name="pid[<?=$i;?>]" value="<?=$p[0];?>"></td>
                      <td><input type="text" name="lno[<?=$i;?>]" class="form-control form-control-sm" value="<?=htmlspecialchars($p[3] ?? '',ENT_QUOTES);?>" required <?=$recLock?'readonly':'';?>></td>
                      <td><input type="number" min="0" name="rnbox[<?=$i;?>]" class="form-control form-control-sm" value="<?=$p[8];?>" required <?=$recLock?'readonly':'';?>></td>
                      <td><input type="number" min="0" name="rtpbox[<?=$i;?>]" class="form-control form-control-sm" value="<?=$p[9];?>" required <?=$recLock?'readonly':'';?>></td>
                    </tr>
                  <?php } ?>
                  </tbody>
                </table></div>
                <div class="row">
                  <div class="form-group col-sm-4"><label class="col-form-label-sm">Date of receipt:</label>
                    <div class="input-group date" data-target-input="nearest">
                      <input type="text" name="rdt" id="rdt" class="form-control datetimepicker-input" data-target="#rdt" value="<?=$trqry[7];?>" placeholder="YYYY-MM-DD" required <?=$recLock?'readonly':'';?>>
                      <div class="input-group-append" data-target="#rdt" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                  </div>
                  <div class="form-group col-sm-4"><label class="col-form-label-sm">Received By:</label><input type="text" name="rname" class="form-control" value="<?=htmlspecialchars($trqry[8] ?? '',ENT_QUOTES);?>" required <?=$recLock?'readonly':'';?>></div>
                  <div class="form-group col-sm-4"><label class="col-form-label-sm">Designation:</label><input type="text" name="rdesignation" class="form-control" value="<?=htmlspecialchars($trqry[9] ?? '',ENT_QUOTES);?>" <?=$recLock?'readonly':'';?>></div>
                </div>
                <?php if (!$recLock) { ?>
                <div class="text-right">
                  <?php if ($received) { ?><a href="delete.php?type=uship&id=<?=$trqry[0];?>&trid=<?=$id;?>" class="btn btn-outline-danger" onclick="return confirm('Undo receipt?');">Undo Receipt</a><?php } ?>
                <!--  <button type="submit" class="btn bg-gradient-success"><i class="fa fa-save"></i> Save Receipt</button> -->
                </div>
                <?php } else { ?><div class="alert alert-secondary mt-2"><small>Receipt is locked because report data already exists.</small></div><?php } ?>
              </form>
              <?php } ?>
            </div>

            <!-- ============ STEP 5: ACCESSORY ASSESSMENT (per product) ============ -->
            <div class="wiz-pane" data-step="5">
              <h5 class="text-primary mb-3">Accessory Assessment (per product)</h5>
              <?php if (!$received) { ?>
                <div class="alert alert-warning">Complete the RCDC Receipt first.</div>
              <?php } else {
                $chkMaster = array();
                $cm = $conn->query("SELECT rac_id, rac_value FROM ms_rdt_assesment_checklist WHERE rac_status=1 ORDER BY rac_rank ASC");
                while ($c = $cm->fetch_row()) { $chkMaster[] = $c; }
              ?>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <p class="text-muted mb-0"><small>Fill the checklist for each product — panels stay open while you work; saves happen in place.</small></p>
              <!--  <button type="button" class="btn bg-gradient-success btn-sm" id="saveAllAssess"><i class="fa fa-save"></i> Save All Assessments</button> -->
              </div>
              <div class="accordion" id="assessAcc">
                <?php foreach ($products as $i => $p) { $mfg = $p[0];
                  $aDone = $conn->query("SELECT rar_id FROM rdt_assessment_report WHERE manufacturer_id='$mfg'")->num_rows > 0;
                ?>
                <div class="card">
                  <div class="card-header p-2 d-flex align-items-center" id="ah<?=$i;?>">
                    <button class="btn btn-link text-left flex-grow-1 p-0 pl-1" type="button" data-toggle="collapse" data-target="#ac<?=$i;?>"><i class="fa fa-chevron-down mr-1"></i><?=htmlspecialchars($p[1] ?? '',ENT_QUOTES);?> &mdash; Lot <?=htmlspecialchars($p[3] ?? '',ENT_QUOTES);?></button>
                    <span class="badge <?= $aDone ? 'badge-success' : 'badge-secondary';?> assess-status" data-mfg="<?=$mfg;?>"><?= $aDone ? 'Saved' : 'Pending';?></span>
                  </div>
                  <div id="ac<?=$i;?>" class="collapse<?= $i===0 ? ' show' : '';?>">
                    <div class="card-body">
                      <form method="post" action="iu-rdt-registration-save.php" autocomplete="off" class="assessForm" data-mfg="<?=$mfg;?>">
                        <input type="hidden" name="submit" value="assessment">
                        <input type="hidden" name="trid" value="<?=$id;?>">
                        <input type="hidden" name="mfgid" value="<?=$mfg;?>">
                        <?php $cc = 0; foreach ($chkMaster as $ck) {
                          $ex = $conn->query("SELECT result, comments FROM rdt_assessment_report WHERE assesment_id='$ck[0]' AND manufacturer_id='$mfg'");
                          $d = ($ex->num_rows>0) ? $ex->fetch_row() : array('','');
                          $c1=($d[0]==='1'||$d[0]===1)?'checked':''; $c0=($d[0]==='0'||$d[0]===0)?'checked':''; $c2=($d[0]==='2'||$d[0]===2)?'checked':'';
                        ?>
                        <div class="row">
                          <div class="form-group col-sm-5"><input type="hidden" name="cid[<?=$cc;?>]" value="<?=$ck[0];?>"><label class="col-form-label-sm"><?=$ck[1];?></label></div>
                          <div class="form-group col-sm-4"><div class="form-check-inline">
                            <input class="form-check-input" type="radio" name="rating[<?=$cc;?>]" value="1" <?=$c1;?> required> <label class="form-check-label">Yes</label> &nbsp
                            <input class="form-check-input" type="radio" name="rating[<?=$cc;?>]" value="0" <?=$c0;?>> <label class="form-check-label">No</label> &nbsp
                            <input class="form-check-input" type="radio" name="rating[<?=$cc;?>]" value="2" <?=$c2;?>> <label class="form-check-label">NA</label>
                          </div></div>
                          <div class="form-group col-sm-3"><input type="text" name="remarks[<?=$cc;?>]" class="form-control form-control-sm" value="<?=htmlspecialchars($d[1] ?? '',ENT_QUOTES);?>" placeholder="Comments"></div>
                        </div>
                        <?php $cc++; } echo '<input type="hidden" name="count" value="'.$cc.'">'; ?>
                        <div class="text-right">
                          <a href="delete.php?type=rac&id=<?=$mfg;?>&trid=<?=$id;?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this assessment?');">Delete</a>
                        <!--  <button type="submit" class="btn bg-gradient-success btn-sm"><i class="fa fa-save"></i> Save Assessment</button> -->
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <?php } ?>
              </div>
              <?php } ?>
            </div>

            <!-- ============ STEP 6: PERFORMANCE (per product, live TN/FP + Pass/Fail) ============ -->
            <div class="wiz-pane" data-step="6">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="text-primary mb-0">Performance Report (per product)</h5>
                <?php if ($isEdit && count($products)) { ?>
                <!-- Exports every product of THIS request, one worksheet each. The
                     endpoint re-checks record ownership, so the link cannot be used
                     to reach another facility's request. -->
                <a href="perf-export.php?id=<?=(int)$id;?>" class="btn btn-sm bg-gradient-success" id="perfExportBtn">
                  <i class="fa fa-file-excel"></i> Export Excel
                </a>
                <?php } ?>
              </div>
              <?php if (!$received) { ?>
                <div class="alert alert-warning">Complete the RCDC Receipt first.</div>
              <?php } else {
                $verdictClass = function($v){ return $v==='Pass'?'badge-success':($v==='Fail'?'badge-danger':'badge-secondary'); };
              ?>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <p class="text-muted mb-0"><small>Enter each product's panel scores — the verdict is computed live; saves happen in place.</small></p>
                <!--<button type="button" class="btn bg-gradient-success btn-sm" id="saveAllPerf"><i class="fa fa-save"></i> Save All Performance Reports</button> -->
              </div>
              <div class="accordion" id="perfAcc">
                <?php foreach ($products as $i => $p) {
                  $prid = $p[11]; $mfg = $p[0];
                  $pr = $conn->query("SELECT pf_pd_200_temp, pf_pds_200, pf_pd_2000_temp, pf_pds_2000, pf_ivr, pv_pd_200_temp, pv_pds_200, pv_pd_2000_temp, pv_pds_2000, pv_ivr, ng_temperature, total_tests, true_negative, false_positive, tn_rate, fp_rate, test_by, designation, test_date, performance_result FROM rdt_performance_report WHERE performance_id='$prid'");
                  $d = ($pr && $pr->num_rows>0) ? $pr->fetch_row() : array_fill(0,20,'');
                ?>
                <div class="card">
                  <div class="card-header p-2 d-flex align-items-center">
                    <button class="btn btn-link text-left flex-grow-1 p-0 pl-1" type="button" data-toggle="collapse" data-target="#pc<?=$i;?>"><i class="fa fa-chevron-down mr-1"></i><?=htmlspecialchars($p[1] ?? '',ENT_QUOTES);?> &mdash; Lot <?=htmlspecialchars($p[3] ?? '',ENT_QUOTES);?></button>
                    <span class="badge <?=$verdictClass($d[19]);?> perf-verdict" data-mfg="<?=$mfg;?>" style="display:none;"><?= $d[19]!=''?$d[19]:'Not scored';?></span>
                  </div>
                  <div id="pc<?=$i;?>" class="collapse<?= $i===0 ? ' show' : '';?>">
                    <div class="card-body">
                      <form method="post" action="iu-rdt-registration-save.php" autocomplete="off" class="perfForm" data-mfg="<?=$mfg;?>">
                        <input type="hidden" name="submit" value="performance">
                        <input type="hidden" name="trid" value="<?=$id;?>">
                        <input type="hidden" name="prid" value="<?=$prid;?>">
                        <input type="hidden" name="mfgid" value="<?=$mfg;?>">
                        <div class="form-group row"><label class="col-sm-12 text-primary col-form-label">Panel Detection Score for Pf</label></div>
                        <div class="row">
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Temp:</label><input type="number" name="pf_pd_200_temp" class="form-control" value="<?=$d[0];?>"></div>
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Score (200p/ul):</label><input type="number" name="pf_pds_200" required class="form-control js-score" value="<?=$d[1];?>"></div>
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Score (2000p/ul):</label><input type="number" name="pf_pds_2000" required class="form-control js-score" value="<?=$d[3];?>"></div>
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Invalid Rate:</label><input type="number" name="pf_ivr" required class="form-control js-ivr" value="<?=$d[4];?>"></div>
                        </div>
                        <div class="form-group row"><label class="col-sm-12 text-primary col-form-label">Panel Detection Score for Pv</label></div>
                        <div class="row">
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Temp:</label><input type="number" name="pv_pd_200_temp" class="form-control" value="<?=$d[5];?>"></div>
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Score (200p/ul):</label><input type="number" name="pv_pds_200" required class="form-control js-score" value="<?=$d[6];?>"></div>
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Score (2000p/ul):</label><input type="number" name="pv_pds_2000" required class="form-control js-score" value="<?=$d[8];?>"></div>
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Invalid Rate:</label><input type="number" name="pv_ivr" required class="form-control js-ivr" value="<?=$d[9];?>"></div>
                        </div>
                        <div class="form-group row"><label class="col-sm-12 text-primary col-form-label">Negative Control Score</label></div>
                        <div class="row">
                          <div class="form-group col-sm-2"><label class="col-form-label-sm">Temp (&#x2103;):</label><input type="number" name="ng_temperature" class="form-control" value="<?=$d[10];?>" required></div>
                          <div class="form-group col-sm-2"><label class="col-form-label-sm">Total Tests:</label><input type="number" name="total_tests" class="form-control js-total" value="<?=$d[11];?>" required></div>
                          <div class="form-group col-sm-2"><label class="col-form-label-sm">True Negative:</label><input type="number" name="true_negative" class="form-control js-tn" value="<?=$d[12];?>" required></div>
                          <div class="form-group col-sm-2"><label class="col-form-label-sm">False Positive:</label><input type="number" name="false_positive" class="form-control js-fp" value="<?=$d[13];?>" required></div>
                          <div class="form-group col-sm-2"><label class="col-form-label-sm">TN Rate:</label><input type="number" name="tn_rate" class="form-control js-tnr" value="<?=$d[14];?>" readonly></div>
                          <div class="form-group col-sm-2"><label class="col-form-label-sm">FP Rate:</label><input type="number" name="fp_rate" class="form-control js-fpr" value="<?=$d[15];?>" readonly></div>
                        </div>
                        <div class="row align-items-center">
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Tested by:</label><input type="text" name="test_by" class="form-control" value="<?=htmlspecialchars($d[16] ?? '',ENT_QUOTES);?>" required></div>
                          <div class="form-group col-sm-3"><label class="col-form-label-sm">Designation:</label><input type="text" name="designation" class="form-control" value="<?=htmlspecialchars($d[17] ?? '',ENT_QUOTES);?>"></div>
                          <div class="form-group col-sm-2"><label class="col-form-label-sm">Test Date:</label>
                            <div class="input-group date rdt-td-date-wrap test-date-picker" id="testDateWrap_<?=$i;?>" data-target-input="nearest">
                              <input type="text" name="test_date" class="form-control datetimepicker-input" data-target="#testDateWrap_<?=$i;?>" value="<?=$d[18];?>" placeholder="YYYY-MM-DD" required>
                              <div class="input-group-append" data-target="#testDateWrap_<?=$i;?>" data-toggle="datetimepicker">
                                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                              </div>
                            </div>
                          </div>
                          <div class="form-group col-sm-4" style="display:none;"><label class="col-form-label-sm d-block">Live Verdict:</label><span class="badge badge-lg js-verdict badge-secondary" style="font-size:14px;padding:6px 12px;">&mdash;</span></div>
                        </div>
                        <div class="text-right">
                          <a href="delete.php?type=rpr&id=<?=$prid;?>&trid=<?=$id;?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Clear this performance report?');">Delete</a>
                         <!-- <button type="submit" class="btn bg-gradient-success btn-sm"><i class="fa fa-save"></i> Save Performance</button> -->
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <?php } ?>
              </div>

              <?php } ?>
            </div>

            <!-- ============ STEP 7: REVIEW & PRINT ============ -->
            <div class="wiz-pane" data-step="7">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="text-primary mb-0">Review &amp; Print</h5>
                <?php if ($isEdit && count($products)) { ?>
                <!-- Same endpoint as the Performance step: the lot testing
                     worksheet for every product of THIS request. It re-checks
                     record ownership, so the link cannot reach another
                     facility's request. -->
                <a href="perf-export.php?id=<?=(int)$id;?>" class="btn btn-sm bg-gradient-success" id="perfExportBtnReview">
                  <i class="fa fa-file-excel"></i> Export Excel
                </a>
                <?php } ?>
              </div>
              <div class="card card-outline card-secondary"><div class="card-header"><h3 class="card-title">Request</h3></div><div class="card-body p-2"><div class="row">
                <div class="col-sm-3 mb-2"><small class="text-muted d-block">Facility</small><strong><?=$facility[0];?></strong></div>
                <div class="col-sm-3 mb-2"><small class="text-muted d-block">Date of Request</small><strong><?=$trqry[2]!=''?$trqry[2]:'&mdash;';?></strong></div>
                <div class="col-sm-3 mb-2"><small class="text-muted d-block">Lot Type</small><strong><?=$trqry[5]!=''?$trqry[5]:'&mdash;';?></strong></div>
                <div class="col-sm-3 mb-2"><small class="text-muted d-block">Received</small><strong><?=$received?$trqry[7]:'Not yet';?></strong></div>
              </div></div></div>
              <div class="card card-outline card-secondary"><div class="card-header"><h3 class="card-title">Products &amp; Results</h3></div><div class="card-body p-2 table-responsive">
                <table class="table table-sm table-bordered mb-0"><thead><tr class="text-center"><th>Product</th><th>Lot</th><th>Sent</th><th>Received</th><th>Report</th></tr></thead><tbody>
                <?php foreach ($products as $p) {
                  $verdict = $p[10]; $vc = $verdict==='Pass'?'badge-success':($verdict==='Fail'?'badge-danger':'badge-secondary');
                  // Full-report download (all sections/data), replacing the old
                  // per-product print link which produced only a partial report.
                  // This pane renders inside if($isRCDC) only, so it is admin-only.
                  $printP = $received ? "<a href='pdf-rdt.php?id=$id' target='_blank' class='btn bg-gradient-warning btn-sm' title='Download Full Report (PDF)'><i class='fas fa-file-pdf'></i></a>" : '&mdash;';
                  echo "<tr><td>".htmlspecialchars($p[1] ?? '',ENT_QUOTES)."</td><td>".htmlspecialchars($p[3] ?? '',ENT_QUOTES)."</td>
                        <td class='text-center'>".($p[6]!==null?($p[6].' x '.$p[7]):'&mdash;')."</td>
                        <td class='text-center'>".($p[8]!==null?($p[8].' x '.$p[9]):'&mdash;')."</td>
                        <td class='text-center rev-report' data-mfg='$p[0]'>$printP</td></tr>";
                } ?>
                </tbody></table>
              </div></div>
            </div>
<?php } // end RCDC panes ?>

          </div>
          <!-- /.card-body -->
          <div class="card-footer d-flex flex-wrap align-items-center">
            <a href="rdt-list.php" class="btn btn-outline-secondary">Cancel</a>
            <?php
            /* Clears the step on screen. Every data-bearing step here holds one row
               per product, so a stored delete is routed to the per-product control
               that already checks the stages depending on it.

               Step 7 is the exception. It is a read-only summary, so it owns no
               fields to clear and no row of its own; its Delete removes the whole
               request instead, through the same Administrator-only endpoint the
               listing uses. The URL is withheld from anyone who may not do it, so
               the button explains itself rather than posting to be refused. */
            render_wizard_step_delete(array(
                'module'    => 'rdt',
                'record_id' => (int)$id,
                'can_write' => $canEditReceipt,
                'steps'     => array(
                    1 => array('name' => 'Requesting Details',           'rows' => 'record', 'saved' => $isEdit),
                    2 => array('name' => 'Product Details',              'rows' => 'many',   'saved' => ($isEdit && count($products) > 0)),
                    3 => array('name' => 'Lab Shipment',                 'rows' => 'many',   'saved' => ($isEdit && $shipped)),
                    4 => array('name' => 'Review & Submit',              'rows' => 'none',   'saved' => false),
                    5 => array('name' => 'Accessory Assessment',         'rows' => 'many',   'saved' => ($isEdit && $assesCnt > 0)),
                    6 => array('name' => 'Performance Report',           'rows' => 'many',   'saved' => ($isEdit && $perfCnt > 0)),
                    7 => array('name' => 'Review & Print',               'rows' => 'none',   'saved' => false,
                               'action'  => 'record',
                               'url'     => ($isEdit && mn_is_admin())
                                              ? 'delete.php?type=tr&id=' . (int)$id . '&trid=' . (int)$id
                                              : '',
                               'confirm' => 'Are you sure you want to delete this RDT Registration record?'),
                ),
            ));
            ?>
            <div class="ml-auto text-right">
              <button type="button" class="btn bg-gradient-secondary" id="wizPrev"><i class="fa fa-arrow-left"></i> Previous</button>
              <button type="button" class="btn bg-gradient-primary ml-2" id="wizNext">Next <i class="fa fa-arrow-right"></i></button>
            </div>
          </div>
        </div>
      </div>
    </section>

  </div>
  <!-- /.content-wrapper -->

  <style>
    /* Modern horizontal progress stepper (blue completed + active, grey pending).
       Steps are rendered dynamically from $stepLabels, so this adapts to any count. */
    .wiz-nav{list-style:none;display:flex;padding:0;margin:0 0 1rem;}
    .wiz-nav .wiz-step{flex:1;min-width:0;text-align:center;position:relative;cursor:pointer;padding:2px 4px 0;color:#6B7280;font-size:12.5px;font-weight:600;line-height:1.25;}
    .wiz-nav .wiz-step .wiz-num{position:relative;z-index:1;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;background:#E5E7EB;color:#6B7280;font-weight:700;font-size:1rem;margin:0 auto 6px;border:3px solid #fff;transition:.2s;}
    .wiz-nav .wiz-step .wiz-label{position:relative;z-index:1;display:block;margin:0 auto;max-width:14ch;}
    /* connector line (drawn on the left of each step, centred behind the circles) */
    .wiz-nav .wiz-step::before{content:"";position:absolute;top:22px;left:-50%;width:100%;height:4px;background:#E5E7EB;z-index:0;}
    .wiz-nav .wiz-step:first-child::before{display:none;}
    /* status caption under each label */
    .wiz-nav .wiz-step::after{content:"Pending";display:block;margin-top:2px;font-size:11px;font-weight:600;color:#9CA3AF;}
    /* completed */
    .wiz-nav .wiz-step.done{color:#111827;font-weight:700;}
    .wiz-nav .wiz-step.done .wiz-num{background:#007bff;border-color:#007bff;color:#fff;font-size:0;}
    .wiz-nav .wiz-step.done .wiz-num::before{content:"\f00c";font-family:"Font Awesome 5 Free";font-weight:900;font-size:15px;}
    .wiz-nav .wiz-step.done::after{content:"Completed";color:#007bff;}
    /* current / in-progress */
    .wiz-nav .wiz-step.active{color:#111827;font-weight:700;}
    .wiz-nav .wiz-step.active .wiz-num{background:#007bff;border-color:#007bff;color:#fff;box-shadow:0 0 0 4px rgba(0,123,255,.18);}
    .wiz-nav .wiz-step.active::after{content:"In Progress";color:#007bff;}
    .wiz-nav .wiz-step.done::before,.wiz-nav .wiz-step.active::before{background:#007bff;}
    .wiz-nav .wiz-step.disabled{cursor:not-allowed;opacity:.5;}
    @media (max-width:575.98px){
      .wiz-nav .wiz-step{font-size:10.5px;}
      .wiz-nav .wiz-step .wiz-num{width:34px;height:34px;font-size:12px;}
      .wiz-nav .wiz-step::before{top:17px;}
      .wiz-nav .wiz-step.done .wiz-num::before{font-size:12px;}
      .wiz-nav .wiz-step::after{font-size:9.5px;}
    }
    .wiz-pane{display:none;} .wiz-pane.active{display:block;}
    .review-sec{border:1px solid #e9ecef;border-radius:.35rem;margin-bottom:1rem;} .review-sec .review-head{background:#f8f9fa;padding:.5rem .75rem;border-bottom:1px solid #e9ecef;display:flex;justify-content:space-between;align-items:center;} .review-sec .review-body{padding:.75rem;} .review-sec .rk{color:#6c757d;font-size:12px;display:block;}

    /* -----------------------------------------------------------------
       Step 2 (Products) date-picker layout + popover clipping fix.
       Everything is scoped under #wizProductTable / .rdt-products-scroll
       so global input styling, other wizard steps, and other tables
       remain untouched.

       Fixes three concrete issues:

       1. Row layout — .input-group.date inside a .table-sm td collapses
          the input's flex sizing so the text field disappears behind
          the calendar-icon append. Force flex:1 on the input and
          flex:0 0 auto on the append.

       2. Popup clipping — the popup is a positioned dropdown inside
          the input-group. Its parent chain includes .table-responsive
          which uses overflow-x:auto, which vertically clips the
          popup too. Override overflow on the Products' responsive
          wrapper AND on the table cells so the popup can extend
          outside the cell/row/table. Only #wizProductTable is
          affected — other .table-responsive containers keep their
          horizontal scroll behaviour.

       3. Stacking — .bootstrap-datetimepicker-widget default z-index
          is low; raise it to 1080 so it renders above wizard chrome,
          DataTables headers, and Bootstrap modals (1050).
    ----------------------------------------------------------------- */
    #wizProductTable .input-group.date{display:flex;align-items:center;flex-wrap:nowrap;}
    #wizProductTable .input-group.date > .datetimepicker-input{flex:1 1 auto;min-width:0;}
    #wizProductTable .input-group.date > .input-group-append{flex:0 0 auto;cursor:pointer;pointer-events:auto;}
    #wizProductTable .input-group.date > .input-group-append .input-group-text{cursor:pointer;user-select:none;}
    /* Escape the .table-responsive vertical clip on THIS table only. */
    .rdt-products-scroll{overflow:visible !important;}
    #wizProductTable, #wizProductTable > tbody, #wizProductTable > tbody > tr, #wizProductTable td{overflow:visible;}
    /* The widget lives inside the input-group, not <body>. Give it a high
       z-index and guarantee pointer-events so nothing in the wizard chrome
       intercepts clicks on the calendar cells. */
    .bootstrap-datetimepicker-widget{z-index:1080 !important;pointer-events:auto;}
    .bootstrap-datetimepicker-widget.dropdown-menu{position:absolute;}
  </style>

  <script>
    $(function () {
        var RDT_HAS_CATALOG = <?= $hasCatalog ? 'true' : 'false' ?>;
        $('.select2').select2({ theme:'bootstrap4' });
        if ($.fn.DataTable && $('#table').length) { $('#table').DataTable({ "paging":true,"searching":false,"info":true,"responsive":true }); }

        var enabled = <?=json_encode(array_values($enabled));?>;
        var doneSteps = <?=json_encode(array_values($doneSteps));?>;
        var wizTotal = <?=$totalSteps;?>, wizCurrent = <?=$curStep;?>, received = <?=$received?'true':'false';?>;
        var shipped = <?=$shipped?'true':'false';?>;
        var isRCDC = <?=$isRCDC?'true':'false';?>;
        var recLock = <?=(!$canEditReceipt)?'true':'false';?>;   // permission-based (mirrors PHP $recLock)
        // Why a locked step is locked — used to tell the user exactly what to save next.
        function lockReason(n){
            if(!isRCDC){ return 'Save the earlier stage to unlock this step.'; }
            if(n>=4 && !shipped){ return 'Save the Lab Shipment (including the Shipment Date) to unlock Shipment Received.'; }
            if(n>=5 && !received){ return 'Save the RCDC Receipt to unlock Accessory Assessment.'; }
            if(n>=6 && enabled.indexOf(6)===-1){ return 'Save at least one Accessory Assessment to unlock Performance Report.'; }
            if(n>=7 && enabled.indexOf(7)===-1){ return 'Complete the Performance Report step and click Next to unlock Review.'; }
            return 'Save the earlier stage to unlock this step.';
        }
        function unlockStep(s){
            if(enabled.indexOf(s)===-1){ enabled.push(s); enabled.sort(function(a,b){return a-b;}); }
            $('.wiz-step[data-step="'+s+'"]').removeClass('disabled');
        }

        // ---- product/shipment dynamic rows (facility form) ----
        var ridSeq = <?=count($products);?>;
        function productRow(rid){
            // Per-row unique wrapper IDs are required so Tempus Dominus can wire
            // the calendar-icon click (data-toggle="datetimepicker" + data-target)
            // to the specific row's picker instance. rid is 'nN' from ridSeq for
            // dynamically-added rows; static-render PHP rows use numeric $i.
            // These two ID spaces don't collide (n0 vs 0).
            var mid = 'mdtWrap_' + rid;
            var eid = 'edtWrap_' + rid;
            return '<tr data-rid="'+rid+'">'+
                '<td><input type="hidden" name="product_pid[]" value=""><input type="text" name="pname[]" class="form-control form-control-sm p-name"></td>'+
                '<td><input type="text" name="manufacturer[]" class="form-control form-control-sm"></td>'+
                /* Mirrors the server row: no Catalog No cell when the column is
                   absent, so the posted arrays stay the same length. */
                (RDT_HAS_CATALOG ? '<td><input type="text" name="catno[]" class="form-control form-control-sm"></td>' : '')+
                '<td><input type="text" name="lno[]" class="form-control form-control-sm p-lot"></td>'+
                '<td><div class="input-group date p-mdt-wrap" id="'+mid+'" data-target-input="nearest"><input type="text" name="mdt[]" class="form-control form-control-sm datetimepicker-input" data-target="#'+mid+'" placeholder="YYYY-MM-DD"><div class="input-group-append" data-target="#'+mid+'" data-toggle="datetimepicker"><div class="input-group-text"><i class="fa fa-calendar"></i></div></div></div></td>'+
                '<td><div class="input-group date p-edt-wrap" id="'+eid+'" data-target-input="nearest"><input type="text" name="edt[]" class="form-control form-control-sm datetimepicker-input" data-target="#'+eid+'" placeholder="YYYY-MM-DD"><div class="input-group-append" data-target="#'+eid+'" data-toggle="datetimepicker"><div class="input-group-text"><i class="fa fa-calendar"></i></div></div></div></td>'+
                '<td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm prodDel"><i class="fa fa-trash"></i></button></td>'+
                '</tr>';
        }
        function shipRow(rid){ return '<tr data-rid="'+rid+'"><td class="s-name"></td><td class="s-lot"></td><td><input type="number" min="0" name="snbox[]" class="form-control form-control-sm"></td><td><input type="number" min="0" name="stpbox[]" class="form-control form-control-sm"></td></tr>'; }
        function toggleProdHint(){ $('#prodEmptyHint').toggle($('#productTbody tr').length===0); }
        // ------------------------------------------------------------------
        // Tempus Dominus date-picker initialisation.
        //
        // Three flavours of picker exist on this page:
        //
        //   1. Fixed-ID single-instance inputs  (#dor, #shdt, #rdt) — plain
        //      YYYY-MM-DD, no cross-field constraint.
        //   2. Generic per-row `.rdt-td-date-wrap` — used by Test Date on the
        //      performance-report form; per-instance init, no constraint.
        //   3. Product-row Manufacture / Expiry pair (.p-mdt-wrap / .p-edt-wrap)
        //      — these are Step-2 (Products) and need:
        //         * click on INPUT opens the picker (allowInputToggle:true),
        //           in addition to the calendar-icon click
        //         * Manufacture Date cannot be in the future (maxDate = today)
        //         * Expiry Date must be strictly after Manufacture Date
        //           (edt.minDate follows mdt.date; mdt.maxDate follows edt.date
        //            but never past today)
        //         * per-row scoping — each row's edt only reacts to its own
        //           row's mdt, not to another row's
        // ------------------------------------------------------------------

        function initRdtDatePickers($scope){
            var $wraps = ($scope && $scope.length) ? $scope.find('.rdt-td-date-wrap') : $('.rdt-td-date-wrap');
            $wraps.each(function(){
                $(this).datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false, allowInputToggle: true });
            });
        }

        // Init the Manufacture + Expiry pair on a single Product row, and set
        // up the cross-linkage so changing one constrains the other.
        function initProductRowPickers($row){
            if (!$row || !$row.length) return;
            var $mdt = $row.find('.p-mdt-wrap');
            var $edt = $row.find('.p-edt-wrap');
            if (!$mdt.length || !$edt.length) return;

            // Guard against double-bind if this row was already inited.
            if ($mdt.data('rdtPickerInited')) return;
            $mdt.data('rdtPickerInited', true);
            $edt.data('rdtPickerInited', true);

            // widgetParent:'body' was removed — it detached the widget from
            // its trigger context and caused the popup to render at (0,0) with
            // no clickable calendar. The .p-mdt-wrap/.p-edt-wrap wrappers
            // now carry unique per-row IDs (mdtWrap_<rid> / edtWrap_<rid>),
            // and the icon append has matching data-target="#<id>" so the
            // canonical Tempus Dominus click-to-open wiring works.
            //
            // Overflow clipping from the surrounding .table-responsive is
            // handled by scoped CSS (.rdt-products-scroll { overflow: visible })
            // — see the <style> block above.
            $mdt.datetimepicker({
                format: 'YYYY-MM-DD',
                useCurrent: false,
                allowInputToggle: true,
                maxDate: moment().endOf('day')   // no future dates
            });
            $edt.datetimepicker({
                format: 'YYYY-MM-DD',
                useCurrent: false,
                allowInputToggle: true
                // minDate is applied dynamically once Manufacture Date is picked
            });

            // Seed constraints from any pre-populated (edit-mode) values so the
            // relationship is enforced on the very first render, not only after
            // a user interaction.
            var seededMdt = $mdt.datetimepicker('date');
            if (seededMdt) {
                $edt.datetimepicker('minDate', moment(seededMdt).add(1, 'day'));
            }
            var seededEdt = $edt.datetimepicker('date');
            if (seededEdt) {
                var mdtCap = moment.min(moment(seededEdt).subtract(1, 'day'), moment().endOf('day'));
                $mdt.datetimepicker('maxDate', mdtCap);
            }

            $mdt.on('change.datetimepicker', function(e){
                if (!e.date) {
                    // Manufacture cleared → drop edt's minDate constraint.
                    $edt.datetimepicker('minDate', false);
                    return;
                }
                $edt.datetimepicker('minDate', moment(e.date).add(1, 'day'));
                // If the currently-picked Expiry is now on or before the new
                // Manufacture Date, clear it so the user re-picks a valid one
                // rather than silently keeping an out-of-range value.
                var currentEdt = $edt.datetimepicker('date');
                if (currentEdt && moment(currentEdt).isSameOrBefore(moment(e.date))) {
                    $edt.datetimepicker('date', null);
                }
            });

            $edt.on('change.datetimepicker', function(e){
                if (!e.date) {
                    // Expiry cleared → Manufacture cap goes back to today only.
                    $mdt.datetimepicker('maxDate', moment().endOf('day'));
                    return;
                }
                // Manufacture must be strictly before Expiry AND not in future.
                var mdtCap = moment.min(moment(e.date).subtract(1, 'day'), moment().endOf('day'));
                $mdt.datetimepicker('maxDate', mdtCap);
            });
        }

        // ID'd (single-instance) inputs
        $('#dor').datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false, allowInputToggle: true });
        $('#shdt').datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false, allowInputToggle: true });
        $('#rdt').datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false, allowInputToggle: true });
        // Row-based generic inputs (e.g. Test Date on the performance form)
        initRdtDatePickers();
        // Product-row (Step 2) pairs — pre-existing rows on load.
        $('#productTbody tr[data-rid]').each(function(){
            initProductRowPickers($(this));
        });

        $('#btnAddProduct').on('click', function(){
            var rid='n'+(ridSeq++);
            $('#productTbody').append(productRow(rid));
            $('#shipTbody').append(shipRow(rid));
            var $newRow = $('#productTbody tr[data-rid="'+rid+'"]');
            initProductRowPickers($newRow);
            toggleProdHint();
        });
        $('#productTbody').on('click','.prodDel',function(){ var $tr=$(this).closest('tr'), rid=$tr.data('rid'), pid=$tr.find('input[name="product_pid[]"]').val(); if(pid){ $('#deletedProducts').append('<input type="hidden" name="deleted_pid[]" value="'+pid+'">'); } $('#shipTbody tr[data-rid="'+rid+'"]').remove(); $tr.remove(); toggleProdHint(); });
        $('#productTbody').on('input','.p-name',function(){ $('#shipTbody tr[data-rid="'+$(this).closest('tr').data('rid')+'"] .s-name').text($(this).val()); });
        $('#productTbody').on('input','.p-lot',function(){ $('#shipTbody tr[data-rid="'+$(this).closest('tr').data('rid')+'"] .s-lot').text($(this).val()); });
        toggleProdHint();
        
        // Type of lot test – dropdown chooses Initial / Long Term / Other.
        // The visible `#tol_select` is a UI control only; the submitted value
        // is always in the hidden/text `#tol` input (which is what the server
        // reads at iu-rdt-registration*.php). Contract:
        //   Initial       → #tol = 'Initial'   (text field hidden)
        //   Long Term     → #tol = 'Long Term' (text field hidden)
        //   __manual__    → #tol = user text   (text field shown, required)
        //   "" (Select …) → #tol = ''           (text field hidden, cleared)
        // The `isUserChange` flag distinguishes on-load initialisation (must
        // not clobber a valid custom edit-mode value) from user-driven changes
        // (which do reset stale values).
        function toggleTolManual(isUserChange) {
          var sel  = $('#tol_select').val();
          var $tol = $('#tol');
          // Long Term carries an extra free-text detail (stored separately in
          // lot_type_detail, so #tol still submits the plain 'Long Term' value).
          var $lt = $('#tol_lt');
          if (sel === 'Long Term') {
            $lt.show();
          } else {
            $lt.hide();
            if (isUserChange) { $lt.val(''); }   // on load, keep what PHP rendered
          }
          if (sel === '__manual__') {
            $tol.show().prop('required', true);
            // On a user-initiated switch INTO manual mode, clear any standard
            // value that was previously mirrored in. On the initial load,
            // preserve whatever was rendered by PHP (an edit-mode custom
            // value that happens to be 'Initial'/'Long Term' stays as-is).
            if (isUserChange && ($tol.val() === 'Initial' || $tol.val() === 'Long Term')) {
              $tol.val('');
            }
          } else if (sel === '') {
            // "-- Select --" chosen: hide the text field AND clear the hidden
            // value so an earlier custom entry cannot silently submit under a
            // blank dropdown selection. Validation (tol: required) then fires
            // as expected.
            $tol.hide().prop('required', false).val('');
          } else {
            // Standard pick (Initial or Long Term): mirror it into #tol.
            $tol.hide().prop('required', false).val(sel);
          }
        }
        $('#tol_select').on('change', function () { toggleTolManual(true); });
        toggleTolManual(false);   // run once on load (handles both Add & Edit)

        // ---- performance live TN/FP + Pass/Fail ----
        function calcPerf($f){
            var num=function(sel){ var v=parseFloat($f.find(sel).val()); return isNaN(v)?0:v; };
            var tot=num('.js-total'), tn=num('.js-tn'), fp=num('.js-fp');
            var tnr = tot>0? (tn/tot*100):0, fpr = tot>0? (fp/tot*100):0;
            $f.find('.js-tnr').val(Math.round(tnr*100)/100); $f.find('.js-fpr').val(Math.round(fpr*100)/100);
            var scores=[]; $f.find('.js-score').each(function(){ scores.push(parseFloat($(this).val())||0); });
            var ivrs=[]; $f.find('.js-ivr').each(function(){ ivrs.push(parseFloat($(this).val())||0); });
            var fail = scores.some(function(s){return s<75;}) || ivrs.some(function(v){return v>=5;}) || fpr>=10;
            var $v=$f.find('.js-verdict');
            $v.text(fail?'FAIL':'PASS').removeClass('badge-secondary badge-success badge-danger').addClass(fail?'badge-danger':'badge-success');
        }
        $(document).on('input','.perfForm input', function(){ calcPerf($(this).closest('.perfForm')); });
        $('.perfForm').each(function(){ calcPerf($(this)); });

        // ---- wizard nav ----
        function esc(s){ return $('<div>').text(s==null?'':s).html(); }
        function buildReview(){
            var h='<div class="review-sec"><div class="review-head"><strong>Request</strong><button type="button" class="btn btn-sm btn-outline-primary wizEdit" data-goto="1"><i class="fa fa-edit"></i> Edit</button></div><div class="review-body"><div class="row">';
            h+='<div class="col-sm-4 mb-2"><span class="rk">Facility</span><strong>'+esc($('select[name="facility"] option:selected').text().trim())+'</strong></div>';
            h+='<div class="col-sm-4 mb-2"><span class="rk">Date</span><strong>'+esc($('#dor').val())+'</strong></div>';
            h+='<div class="col-sm-4 mb-2"><span class="rk">Lot Type</span><strong>'+esc($('#tol').val())+'</strong></div>';
            h+='<div class="col-sm-4 mb-2"><span class="rk">Staff</span><strong>'+esc($('#staffName').val())+'</strong></div>';
            h+='<div class="col-sm-4 mb-2"><span class="rk">Designation</span><strong>'+esc($('#designation').val())+'</strong></div>';
            h+='<div class="col-sm-4 mb-2"><span class="rk">Shipment Date</span><strong>'+esc($('#shdt').val())+'</strong></div></div></div></div>';
            h+='<div class="review-sec"><div class="review-head"><strong>Products &amp; Shipment</strong><button type="button" class="btn btn-sm btn-outline-primary wizEdit" data-goto="2"><i class="fa fa-edit"></i> Edit</button></div><div class="review-body table-responsive">';
            var rows=$('#productTbody tr').filter(function(){ return $.trim($(this).find('input[name="pname[]"]').val())!==''; });
            if(rows.length===0){ h+='<span class="text-muted">No products.</span>'; } else {
                h+='<table class="table table-sm table-bordered mb-0"><thead><tr class="text-center"><th>Product</th><th>Manufacturer</th><th>Lot</th><th>Boxes</th><th>Tests/Box</th></tr></thead><tbody>';
                rows.each(function(){ var $p=$(this),rid=$p.data('rid'),$s=$('#shipTbody tr[data-rid="'+rid+'"]');
                    h+='<tr><td>'+esc($p.find('input[name="pname[]"]').val())+'</td><td>'+esc($p.find('input[name="manufacturer[]"]').val())+'</td><td>'+esc($p.find('input[name="lno[]"]').val())+'</td><td>'+esc($s.find('input[name="snbox[]"]').val())+'</td><td>'+esc($s.find('input[name="stpbox[]"]').val())+'</td></tr>'; });
                h+='</tbody></table>';
            }
            h+='</div></div>'; $('#reviewArea').html(h);
        }
        function wizShow(n){
            if(enabled.indexOf(n)===-1){ Swal.fire({icon:'info',text:lockReason(n),confirmButtonText:'OK'}); return; }
            wizCurrent=n;
            $('.wiz-pane').removeClass('active'); $('.wiz-pane[data-step="'+n+'"]').addClass('active');
            // Stepper state via the shared helper (dist/js/mneqas-stepper-state.js):
            // saved stages Completed, current step Active, unreached steps Pending/Locked.
            var rdtSteps = [], rdtDone = doneSteps.slice();
            $('.wiz-step').each(function(){ rdtSteps.push(parseInt($(this).data('step'))); });
            for (var ds = 1; ds < n; ds++){ if (rdtDone.indexOf(ds) === -1 && enabled.indexOf(ds) !== -1){ rdtDone.push(ds); } }
            var rdtComplete = (doneSteps.indexOf(wizTotal) !== -1);
            if (window.mnStepperState){
                var rdtState = mnStepperState({ steps: rdtSteps, current: n, completed: rdtComplete, done: rdtDone, reachable: enabled });
                $('.wiz-step').each(function(){
                    var s  = parseInt($(this).data('step'));
                    var st = rdtState[s] || '';
                    $(this).toggleClass('active', st.indexOf('active') !== -1)
                           .toggleClass('done',   st.indexOf('done')   !== -1);
                });
            } else {
                $('.wiz-step').each(function(){
                    var s = parseInt($(this).data('step'));
                    $(this).toggleClass('active', s===n);
                    $(this).toggleClass('done', s!==n && (s<n || doneSteps.indexOf(s)!==-1));
                });
            }
            $('#wizProgress').css('width', (rdtComplete ? 100 : ((n-1)/(wizTotal-1)*100))+'%');
            $('#wizPrev').prop('disabled', n<=1); $('#wizNext').toggle(n<wizTotal);
            if(n===<?=$reviewStep;?> && !<?=$isRCDC?'true':'false';?>){ buildReview(); }
        }
        function markStepDone(s){
            if(doneSteps.indexOf(s)===-1){ doneSteps.push(s); }
            $('.wiz-step[data-step="'+s+'"]').not('.active').addClass('done');
        }
        $('.wiz-step').each(function(){ if(enabled.indexOf(parseInt($(this).data('step')))===-1){ $(this).addClass('disabled'); } });
        // Next moves strictly to the following stage — never silently over a
        // locked one. For RCDC on steps 1-4 Next SAVES the current stage first
        // (full-page post, so the server re-renders the panes that depend on
        // shipped/received state), with `nextstep` telling the handler where to
        // land. When the stage is read-only (received/locked) Next just navigates.
        $('#wizNext').on('click',function(){
            if(wizCurrent>=wizTotal){ return; }
            if(isRCDC && wizCurrent<=3 && !received){
                $('#rdtNextStep').val(wizCurrent+1);
                if(!$('#rdtWizard').valid()){
                    // .valid() marks the fields but doesn't run invalidHandler —
                    // jump to the offending pane ourselves.
                    var st = parseInt($('#rdtWizard').find('.is-invalid').first().closest('.wiz-pane').data('step')) || wizCurrent;
                    wizShow(st);
                    Swal.fire({icon:'warning', text:'Please complete the required fields.', confirmButtonText:'OK'});
                    return;
                }
                silentStageSave($('#rdtWizard'));
                return;
            }
            if(isRCDC && wizCurrent===4 && !recLock){
                var rf = document.getElementById('receiptForm');
                if(rf){
                    if(rf.checkValidity()){ silentStageSave($(rf)); } else { rf.reportValidity(); }
                    return;
                }
            }
            if(isRCDC && wizCurrent===5 && $('.assessForm').length){
                // Silently save the filled assessments, then advance.
                var $af = $('.assessForm');
                if($af.filter(function(){ return assessReady(this); }).length){
                    saveAll($('#saveAllAssess'), $af, assessReady, assessSaved, 'Assessments',
                        function(ok){ if(ok && enabled.indexOf(6)!==-1){ wizShow(6); } }, true);
                    return;
                }
            }
            if(isRCDC && wizCurrent===6 && $('.perfForm').length){
                // Step 6 Next now performs the one-shot combined_rcdc submission
                // (receipt + all assessments + all performance reports in one
                // transaction) and lands on Step 7 on success. Replaces the
                // former standalone "Submit All & Finish" alert block.
                runSubmitAllAndFinish($('#wizNext'));
                return;
            }
            wizShow(wizCurrent+1);
        });
        // The explicit save buttons bypass Next — make sure a stale nextstep
        // from an aborted Next click can't hijack their redirect.
        $('#facilitySave, #wizSubmit').on('click', function(){ $('#rdtNextStep').val(''); });
        $('#wizPrev').on('click',function(){ var pv=wizCurrent-1; while(pv>=1 && enabled.indexOf(pv)===-1) pv--; if(pv>=1) wizShow(pv); });
        $('.wiz-step').on('click',function(){ wizShow(parseInt($(this).data('step'))); });
        $('#reviewArea').on('click','.wizEdit',function(){ wizShow(parseInt($(this).data('goto'))); });
        wizShow(wizCurrent);

        // ---- Assessment & Performance: inline AJAX saves (user stays on the step) ----
        // Posts the unchanged forms to the existing transactional handlers in
        // iu-rdt-registration-save.php and reads the same success marker.
        function ajaxSave($form){
            return $.post($form.attr('action'), $form.serialize()).then(
                function(resp){ return /Submitted Successfully/.test(resp); },
                function(){ return $.Deferred().resolve(false); }
            );
        }
        // Next-path save for the facility/receipt stages: posts the form via
        // AJAX and follows the handler's redirect link directly, so the user
        // never sees the intermediate success page/popup. Errors still popup.
        function silentStageSave($form){
            var $btn = $('#wizNext').prop('disabled', true);
            $.post($form.attr('action'), $form.serialize()).done(function(resp){
                if(/Submitted Successfully/.test(resp)){
                    var m = resp.match(/id='lnk' value='([^']+)'/);
                    if(m){ window.location = m[1]; } else { window.location.reload(); }
                } else {
                    $btn.prop('disabled', false);
                    Swal.fire({icon:'error', text:'Save failed. Nothing was saved — please try again.'});
                }
            }).fail(function(){
                $btn.prop('disabled', false);
                Swal.fire({icon:'error', text:'Save failed. Nothing was saved — please try again.'});
            });
        }
        // Shared "is this product's form complete enough to save" predicates
        function assessReady(f){ return $(f).find('input[type="radio"]:checked').length>0; }
        function perfReady(f){ var ready=true; $(f).find('[required]').each(function(){ if($.trim($(this).val()||'')===''){ ready=false; } }); return ready; }
        function assessSaved($f){
            $('.assess-status[data-mfg="'+$f.data('mfg')+'"]').removeClass('badge-secondary').addClass('badge-success').text('Saved');
            markStepDone(5);
            unlockStep(6);   // an assessment now exists -> Performance becomes reachable
        }
        function perfSaved($f){
            var mfg = $f.data('mfg');
            var verdict = $f.find('.js-verdict').text()==='FAIL' ? 'Fail' : 'Pass';
            var cls = verdict==='Pass' ? 'badge-success' : 'badge-danger';
            $('.perf-verdict[data-mfg="'+mfg+'"], .rev-verdict[data-mfg="'+mfg+'"]')
                .removeClass('badge-secondary badge-success badge-danger').addClass(cls).text(verdict);
            // Reveal the full-report download on the Review step once a verdict exists
            var $rep = $('.rev-report[data-mfg="'+mfg+'"]');
            if ($rep.length && $rep.find('a').length===0){
                $rep.html('<a href="pdf-rdt.php?id=<?=$id;?>" target="_blank" class="btn bg-gradient-warning btn-sm" title="Download Full Report (PDF)"><i class="fas fa-file-pdf"></i></a>');
            }
            markStepDone(6);
            unlockStep(7);   // a performance report now exists -> Review becomes reachable
        }
        $(document).on('submit', '.assessForm', function(e){
            e.preventDefault();
            if(!this.checkValidity()){ this.reportValidity(); return; }
            var $f=$(this), $btn=$f.find('button[type="submit"]').prop('disabled', true);
            ajaxSave($f).then(function(ok){
                $btn.prop('disabled', false);
                if(ok){ assessSaved($f); Swal.fire({icon:'success', text:'Assessment saved.', timer:1400, showConfirmButton:false}); }
                else { Swal.fire({icon:'error', text:'Save failed. Nothing was saved — please try again.'}); }
            });
        });
        $(document).on('submit', '.perfForm', function(e){
            e.preventDefault();
            if(!this.checkValidity()){ this.reportValidity(); return; }
            var $f=$(this), $btn=$f.find('button[type="submit"]').prop('disabled', true);
            ajaxSave($f).then(function(ok){
                $btn.prop('disabled', false);
                if(ok){ perfSaved($f); Swal.fire({icon:'success', text:'Performance report saved.', timer:1400, showConfirmButton:false}); }
                else { Swal.fire({icon:'error', text:'Save failed. Nothing was saved — please try again.'}); }
            });
        });

        // Unified save: submits every product's form in sequence through the same
        // handlers (one per transaction, so data integrity is unchanged) and shows
        // a single summary. Incomplete products are skipped, not blocked.
        function saveAll($btn, $forms, isReady, onSaved, label, after, silent){
            var list = $forms.filter(function(){ return isReady(this); }).toArray();
            var skipped = $forms.length - list.length;
            if(!list.length){ Swal.fire({icon:'info', text:'Nothing ready to save yet — fill in at least one product first.'}); return; }
            $btn.prop('disabled', true);
            var i=0, ok=0, fail=0;
            (function next(){
                if(i>=list.length){
                    $btn.prop('disabled', false);
                    // Silent mode (Next-path): advance straight away when all
                    // saves succeeded; only failures interrupt with a popup.
                    if(silent && fail===0){ if(after){ after(true); } return; }
                    var msg = label+': '+ok+' saved'+(fail? ', '+fail+' failed':'')+(skipped? ', '+skipped+' skipped (incomplete)':'')+'.';
                    Swal.fire({icon: fail?'warning':'success', text: msg, confirmButtonText:'Close'})
                        .then(function(){ if(after){ after(fail===0); } });
                    return;
                }
                var $f=$(list[i]);
                ajaxSave($f).then(function(r){ if(r){ ok++; onSaved($f); } else { fail++; } i++; next(); });
            })();
        }
        $('#saveAllAssess').on('click', function(){
            saveAll($(this), $('.assessForm'), assessReady, assessSaved, 'Assessments');
        });
        $('#saveAllPerf').on('click', function(){
            saveAll($(this), $('.perfForm'), perfReady, perfSaved, 'Performance reports');
        });

        // ---- Submit All & Finish: one-shot combined_rcdc submission ----
        // Called by the Step 6 Next button (see wizNext handler above). Was
        // originally wired to a standalone "Submit All & Finish" button in a
        // separate alert block below the performance forms; that UI block has
        // been removed and its behaviour folded into the standard wizard Next
        // navigation so Step 6 → Step 7 is a single seamless click.
        function buildCombinedPayload(){
            var data = $('#receiptForm').serializeArray().filter(function(f){ return f.name!=='submit' && f.name!=='nextstep'; });
            data.push({name:'submit', value:'combined_rcdc'});
            var cids = $('.assessForm').first().find('input[name^="cid["]');
            data.push({name:'assessCnt', value: cids.length});
            cids.each(function(){ data.push({name: this.name, value: this.value}); });
            $('.assessForm').each(function(i){
                $(this).find('input[type="radio"]:checked').each(function(){
                    var m = this.name.match(/^rating\[(\d+)\]$/);
                    if(m){ data.push({name:'rating['+i+']['+m[1]+']', value: this.value}); }
                });
                $(this).find('input[name^="remarks["]').each(function(){
                    var m = this.name.match(/^remarks\[(\d+)\]$/);
                    if(m){ data.push({name:'remarks['+i+']['+m[1]+']', value: this.value}); }
                });
            });
            $('.perfForm').each(function(i){
                var $f = $(this);
                data.push({name:'prid['+i+']', value: $f.find('input[name="prid"]').val()});
                ['pf_pd_200_temp','pf_pds_200','pf_pds_2000','pf_ivr',
                 'pv_pd_200_temp','pv_pds_200','pv_pds_2000','pv_ivr',
                 'ng_temperature','total_tests','true_negative','false_positive',
                 'test_by','designation','test_date'].forEach(function(k){
                    data.push({name: k+'['+i+']', value: $f.find('[name="'+k+'"]').val()});
                });
            });
            return data;
        }
        // Extracted from the (removed) #submitAllFinish click handler so the
        // Step 6 Next button can call the exact same all-or-nothing combined
        // submission. $triggerBtn is the button that fired — disabled during
        // the request, re-enabled on failure so the user can retry.
        function runSubmitAllAndFinish($triggerBtn){
            var bad = null;
            $('.assessForm, .perfForm').each(function(){ if(!this.checkValidity()){ bad = this; return false; } });
            if(bad){
                var $col = $(bad).closest('.collapse');
                var st = parseInt($(bad).closest('.wiz-pane').data('step'));
                Swal.fire({icon:'warning', text:'Please complete every product\'s Assessment and Performance Report before submitting all.', confirmButtonText:'OK'})
                .then(function(){
                    wizShow(st);
                    if($col.length && !$col.hasClass('show')){
                        $col.collapse('show');
                        $col.one('shown.bs.collapse', function(){ bad.reportValidity(); });
                    } else { bad.reportValidity(); }
                });
                return;
            }
            var $btn = ($triggerBtn && $triggerBtn.length) ? $triggerBtn.prop('disabled', true) : $();
            $.post('iu-rdt-registration-save.php', $.param(buildCombinedPayload())).done(function(resp){
                if(/Submitted Successfully/.test(resp)){
                    // The one intentional success popup: the final full submission.
                    Swal.fire({icon:'success', title:'Submitted Successfully!', text:'Receipt, assessments and performance reports were saved together.', timer:1800, showConfirmButton:false})
                    .then(function(){ window.location = 'rdt-registration.php?action=2&id=<?=$id;?>&step=7'; });
                } else {
                    $btn.prop('disabled', false);
                    Swal.fire({icon:'error', text:'Submission failed. Nothing was saved — please try again.'});
                }
            }).fail(function(){
                $btn.prop('disabled', false);
                Swal.fire({icon:'error', text:'Submission failed. Nothing was saved — please try again.'});
            });
        }

        // ---- validation (facility form) ----
        $('#rdtWizard').validate({ ignore:[], rules:{ dzongkhag:"required", facility:"required", dor:"required", tol:"required", staffName:"required", designation:"required",
            // Shipment Date is what unlocks the Receipt stage: once any box/test
            // quantity is entered the date must be provided too, otherwise the
            // record saves as "not shipped" and the workflow stalls at Shipment.
            shdt: { required: function(){ return $('#shipTbody').find('input[name="snbox[]"], input[name="stpbox[]"]').filter(function(){ return $.trim($(this).val()||'')!==''; }).length>0; } } },
            errorElement:'span', errorPlacement:function(e,el){ e.addClass('invalid-feedback'); el.closest('.form-group, td').append(e); },
            highlight:function(el){$(el).addClass('is-invalid');}, unhighlight:function(el){$(el).removeClass('is-invalid');},
            invalidHandler:function(ev,v){ if(v.errorList.length){ var st=$(v.errorList[0].element).closest('.wiz-pane').data('step'); if(st){ wizShow(parseInt(st)); } Swal.fire({icon:'warning',text:'Please complete the required fields.',confirmButtonText:'OK'}); } },
            // form.submit() is shadowed by the hidden input named "submit" — call the prototype.
            submitHandler:function(form){ HTMLFormElement.prototype.submit.call(form); } });
    });

    $(document).ready(function(){
        $(document).on('change', '#dzongkhag', function(){
            var dzongkhag = $(this).val() || '';
            $("#facilityDiv").load("../load-facility-all.php?dzongkhag=" + encodeURIComponent(dzongkhag));
        });
    });
  </script>
<?php require_once("../includes/footer.php"); ?>
