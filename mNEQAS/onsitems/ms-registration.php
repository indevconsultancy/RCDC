<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<?php require_once("../includes/wizard-step-delete.php"); ?>
<?php
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
?>
<?php
    $roleID = $_SESSION["roleID"];
?>
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

  <!-- Content Wrapper -->
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6"><h1>M&S Checklist</h1></div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="ms-list.php">M&S List</a></li>
              <li class="breadcrumb-item active">Monitoring and Supervision | Indicators</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

<?php
    $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
    if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) {
        $facilityId = $_SESSION["facilityID"];
        $dzdqry = ($commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$facilityId' )"));
        list($dzongkhag_code, $dzongkhag_name) = $dzdqry->fetch_row();
        $centerqry = ($commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$facilityId'"));
        list($center_name) = $centerqry->fetch_row();
    }

    $action = isset($_GET["action"]) ? $_GET["action"] : 1;
    $isEdit = ($action == 2);
    $id     = $isEdit ? (isset($_GET["id"]) ? (int)$_GET["id"] : 0) : 0;     // 0 for a brand-new registration (no rows to pre-fill)
    // Record-level authorization (IDOR): facility users may open only their own facility's audit.
    if ($isEdit) { mn_require_record($conn, 'onsite', $id); }
    $gidVal = $isEdit ? $id : '';            // hidden gid posted to the transactional endpoint

    $staffRows = array();
    $oraData   = array('', '', '');   // [0]=recommendation, [1]=actions, [2]=findings
    $actionImages = array();

    if ($isEdit) {
        $generalqry = ($conn->query("SELECT gi_id, facility_id, date_visit, evaluator, incharge_designation, audit_category FROM onsite_general_informations WHERE gi_id=".$id));
        $generalqry = $generalqry->fetch_row();

        $facility  = ($commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$generalqry[1]'"))->fetch_row();
        $dzongkhag = ($commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$generalqry[1]' )"))->fetch_row();

        $sq = $conn->query("SELECT staff_id, staff_name, staff_designation, training_frequency, training_days, last_training_date, training_topic FROM onsite_staff_informations WHERE general_info_id=".$id);
        while($r=$sq->fetch_row()){ $staffRows[] = $r; }

        $oraRow = $conn->query("SELECT recommendation, actions, findings FROM onsite_recommendation_action WHERE general_info_id='$id'");
        if ($oraRow && $oraRow->num_rows>0) { $oraData = $oraRow->fetch_row(); }

        // Load previously uploaded Action images
        $imageQry = $conn->query("
            SELECT image_id, image_name, image_path
            FROM onsite_action_images
            WHERE general_info_id='$id'
            ORDER BY image_id ASC
        ");

        if ($imageQry) {
            while ($img = $imageQry->fetch_assoc()) {
            $actionImages[] = $img;
            }
        }

        $downloadReport = "<a href='pdf-ms-registration.php?id=$id' id='dlReportBtn' target='_blank' class='btn bg-gradient-warning btn-sm'><i class='fas fa-print'></i> Download Report</a>";
        $deleteReport   = "<a href='delete.php?type=gi&id=$id&gid=$id' class='btn btn-outline-danger' onclick=\"return confirm('Delete this entire report, including staff details, checklist ratings, scores and recommendations? This cannot be undone.');\"><i class='fa fa-trash'></i> Delete Report</a>";
    } else {
        $generalqry = array('', '', '', '', '', '');
        $facility   = array('Select Facility');
        $dzongkhag  = array('','Select Dzongkhag');
        $downloadReport = "";
        $deleteReport   = "";
    }

    // ---- Persisted workflow state (from the database only; ?step / session / reload are ignored) ----
    // The wizard writes a record only on the final Review -> Submit, and that save always creates
    // the onsite_recommendation_action row. A record with that row is therefore Completed/Submitted
    // (all 6 stages done, opens on Review with the report available). Older partial records saved
    // by the legacy per-section handler open on their first incomplete stage.
    $msCompleted = false;
    $msDoneSteps = array();
    $curStep     = 1;
    if ($isEdit && $id && is_array($generalqry) && $generalqry[0] !== '') {
        $msCompleted = (isset($oraRow) && $oraRow && $oraRow->num_rows > 0);
        if ($msCompleted) {
            $msDoneSteps = array(1, 2, 3, 4, 5, 6);
            $curStep = 6;
        } else {
            $rc = $conn->prepare("SELECT COUNT(*) FROM onsite_checklists_rating WHERE general_info_id = ?");
            $rc->bind_param('i', $id);
            $rc->execute();
            $ratedCount = (int)$rc->get_result()->fetch_row()[0];
            $rc->close();
            $stageOk = array(
                1 => (trim((string)$generalqry[1]) !== '' && trim((string)$generalqry[2]) !== '' && trim((string)$generalqry[3]) !== '' && trim((string)$generalqry[4]) !== '' && trim((string)$generalqry[5]) !== ''),
                2 => count($staffRows) > 0,
                3 => $ratedCount > 0,
            );
            foreach ($stageOk as $s => $ok) { if (!$ok) { break; } $msDoneSteps[] = $s; }
            $curStep = count($msDoneSteps) + 1;
        }
    }
?>

    <section class="content">
      <div class="container-fluid">
        <form id="wizardForm" method="post" action="iu-ms-registration-save.php" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="gid" id="gid" value="<?=$gidVal;?>">

        <div class="card card-primary card-outline">
          <div class="card-header">
            <h3 class="card-title"><i class="fa fa-clipboard-list"></i>&nbsp; Monitoring &amp; Supervision</h3>
            <div class="card-tools" id="msCardTools"><?=$downloadReport;?></div>
          </div>
          <div class="card-body">

            <!-- Stepper -->
            <ul class="wiz-nav" id="wizNav">
              <li class="wiz-step" data-step="1"><span class="wiz-num">1</span><span class="wiz-label">General Info</span></li>
              <li class="wiz-step" data-step="2"><span class="wiz-num">2</span><span class="wiz-label">Staff</span></li>
              <li class="wiz-step" data-step="3"><span class="wiz-num">3</span><span class="wiz-label">Checklist</span></li>
              <li class="wiz-step" data-step="4"><span class="wiz-num">4</span><span class="wiz-label">Recommendations</span></li>
              <li class="wiz-step" data-step="5"><span class="wiz-num">5</span><span class="wiz-label">Actions</span></li>
              <li class="wiz-step" data-step="6"><span class="wiz-num">6</span><span class="wiz-label">Review</span></li>
            </ul>
            <div class="progress mb-4" style="height:4px;">
              <div id="wizProgress" class="progress-bar bg-primary" role="progressbar" style="width:0%;"></div>
            </div>

            <!-- ============ STEP 1: GENERAL INFORMATION ============ -->
            <div class="wiz-pane" data-step="1">
              <h5 class="text-primary mb-3">General Information</h5>
              <!-- Row 1: max 4 fields (col-md-3 each) -->
              <div class="row">
              <?php if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) { ?>
                <div class="form-group col-md-4">
                    <label class="col-form-label-sm">Dzongkhag</label>
                    <select class="form-control select2" name="dzongkhag" id="dzongk" style="width:100%;">
                    <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                    <option value='<?=$dzongkhag_code;?>'><?=$dzongkhag_name;?></option>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label class="col-form-label-sm">Health Facility</label>
                    <div id="facilityDiv">
                        <select class="form-control" name="facility" id="facility">
                            <option value="<?=$generalqry[1];?>"><?=$facility[0];?></option>
                            <option value="<?=$facilityId;?>"><?=$center_name;?></option>
                        </select>
                    </div>
                </div>
              <?php }else{ ?>
                <div class="form-group col-md-4">
                    <label class="col-form-label-sm">Dzongkhag</label>
                    <select class="form-control select2" name="dzongkhag" id="dzongkhag" style="width:100%;">
                    <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                        <?php
                        $result = $commondb->query($dzsql);
                        while($row = $result->fetch_row()) { echo("<option value='$row[0]'>$row[1]</option>"); }
                        ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label class="col-form-label-sm">Health Facility</label>
                    <div id="facilityDiv">
                        <select class="form-control" name="facility" id="facility">
                            <option value="<?=$generalqry[1];?>"><?=$facility[0];?></option>
                        </select>
                    </div>
                </div>
              <?php } ?>

                <div class="form-group col-md-4">
                    <label class="col-form-label-sm">Date of Visit</label>
                    <div class="input-group date" id="dov" data-target-input="nearest">
                        <input type="text" class="form-control datetimepicker-input text-center" data-target="#dov" name="dov" placeholder="M&S Date" value="<?=$generalqry[2];?>" />
                        <div class="input-group-append" data-target="#dov" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                    </div>
                </div>
              </div>

              <!-- Row 2: Evaluator + Designation (col-md-6 each) -->
              <div class="row">
                <div class="form-group col-md-4">
                    <label class="col-form-label-sm">Audit Category</label>
                    <select class="form-control" name="audit" id="audit">
                        <?php
                        if ($isEdit) { echo '<option value="'.$generalqry[5].'">'.$generalqry[5].'</option>'; }
                        if ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) { echo '<option value="External">External</option>'; }
                        ?>
                        <option value="Internal">Internal</option>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label class="col-form-label-sm">Name of Evaluator</label>
                    <input type="text" name="evaluator" id="evaluator" class="form-control" value="<?=$generalqry[3];?>" placeholder="Name of Evaluator">
                </div>
                <div class="form-group col-md-4">
                    <label class="col-form-label-sm">Designation of Evaluator</label>
                    <input type="text" name="designation" id="designation" class="form-control" value="<?=$generalqry[4];?>" placeholder="Designation of Lab Incharge">
                </div>
              </div>
            </div>
            <!-- /step 1 -->

            <!-- ============ STEP 2: STAFF INFORMATION ============ -->
            <div class="wiz-pane" data-step="2">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                  <h5 class="text-primary mb-0">Staff Information</h5>
                  <small class="text-muted">Add multiple lab staff members who were trained on malaria.</small>
                </div>
                <button type="button" class="btn bg-gradient-success btn-sm" id="btnAddStaff"><i class="fa fa-plus"></i> Add New Entry</button>
              </div>
              <div class="table-responsive staff-table-responsive">
                <table class="table table-bordered table-sm align-middle" id="staffTable">
                  <thead class="text-center">
                    <tr>
                      <th style="min-width:150px;">Staff Name</th>
                      <th style="min-width:150px;">Designation</th>
                      <th style="width:110px;">Frequency</th>
                      <th style="width:100px;">Days</th>
                      <th style="width:160px;">Last Training</th>
                      <th style="min-width:150px;">Training Topic</th>
                      <th style="width:50px;"></th>
                    </tr>
                  </thead>
                  <tbody id="staffTbody">
                    <?php foreach($staffRows as $s){ ?>
                    <tr>
                      <td><input type="hidden" name="staff_id[]" value="<?=$s[0];?>"><input type="text" name="staff_name[]" class="form-control form-control-sm" value="<?=htmlspecialchars($s[1], ENT_QUOTES);?>"></td>
                      <td><input type="text" name="staff_designation[]" class="form-control form-control-sm" value="<?=htmlspecialchars($s[2], ENT_QUOTES);?>"></td>
                      <td><input type="number" name="staff_frequency[]" class="form-control form-control-sm" value="<?=$s[3];?>"></td>
                      <td><input type="number" name="staff_days[]" class="form-control form-control-sm" value="<?=$s[4];?>"></td>
                      <td>
  <?php $rowUid = 'staffLdt_' . $s[0]; // use staff_id for uniqueness ?>
  <div class="input-group date staff-ldt-wrap" id="<?=$rowUid;?>" data-target-input="nearest">
      <input type="text"
             name="staff_ldt[]"
             class="form-control form-control-sm datetimepicker-input"
             data-target="#<?=$rowUid;?>"
             value="<?=htmlspecialchars($s[5], ENT_QUOTES, 'UTF-8');?>"
             placeholder="YYYY-MM-DD">
      <div class="input-group-append"
           data-target="#<?=$rowUid;?>"
           data-toggle="datetimepicker">
          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
      </div>
  </div>
</td>
                      <td><input type="text" name="staff_topic[]" class="form-control form-control-sm" value="<?=htmlspecialchars($s[6], ENT_QUOTES);?>"></td>
                      <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm staffDel"><i class="fa fa-trash"></i></button></td>
                    </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
              <p class="text-muted mb-0" id="staffEmptyHint" style="display:none;"><small>No staff added yet. Use <strong>Add New Entry</strong> to add a row.</small></p>
              <div id="deletedStaff"></div>
            </div>
            <!-- /step 2 -->

            <!-- ============ STEP 3: M&S CHECKLIST ============ -->
<div class="wiz-pane" data-step="3">
  <h5 class="text-primary mb-3">M&S Checklists</h5>
  <?php if ($isEdit && $id && mn_is_rcdc()) { ?>
    <a href="delete.php?type=ck&id=<?=(int)$id;?>&gid=<?=(int)$id;?>"
       class="btn btn-outline-danger btn-sm float-right"
       onclick="return confirm('Clear every checklist rating and score for this report?');">
      <i class="fa fa-trash"></i> Clear this section</a>
  <?php } ?>
  <?php
    require_once __DIR__ . '/ms-findings-functions.php';
    $catqry = $conn->query("SELECT category_id, category_value FROM ms_onsite_categories WHERE category_status = 1 ORDER BY category_order ASC");
    $chkcount = 0;

    if ($catqry) {
        while ($cat = $catqry->fetch_row()) {
            $subcatqry = $conn->query("SELECT subcategory_id, subcategory_value FROM ms_onsite_subcategories WHERE subcategory_status = 1 AND category_id = '{$cat[0]}' ORDER BY subcategory_order ASC");
            echo '<div class="mt-2 col-sm-12 p-2 mb-2 bg-info text-white">' . htmlspecialchars($cat[1]) . '</div>';

            if ($subcatqry) {
                while ($subcat = $subcatqry->fetch_row()) {
                    // Prefer query with findings; fall back if column missing
                    $cklqry = $conn->query("
                        SELECT checklist_id, checklist, findings
                        FROM ms_onsite_checklists
                        WHERE checklist_status = 1
                          AND subcategory_id = '{$subcat[0]}'
                        ORDER BY checklist_order ASC
                    ");

                    if (!$cklqry) {
                        // Column may not exist yet – safe fallback
                        $cklqry = $conn->query("
                            SELECT checklist_id, checklist
                            FROM ms_onsite_checklists
                            WHERE checklist_status = 1
                              AND subcategory_id = '{$subcat[0]}'
                            ORDER BY checklist_order ASC
                        ");
                    }

                    echo '<div class="mt-2 col-sm-12 p-1 mb-2 bg-secondary text-dark">' . htmlspecialchars($subcat[1]) . '</div>';

                    if ($cklqry) {
                        while ($cklist = $cklqry->fetch_row()) {
                            $datacntQ = $conn->query("SELECT rating_value FROM onsite_checklists_rating WHERE checklists_id = '{$cklist[0]}' AND general_info_id = '$id'");
                            $datacnt  = $datacntQ ? $datacntQ->num_rows : 0;

                            if ($datacnt > 0) {
                                $data = $conn->query("SELECT rating_id, rating_value, remarks FROM onsite_checklists_rating WHERE checklists_id = '{$cklist[0]}' AND general_info_id = '$id'")->fetch_row();
                                $statusChk1 = $statusChk2 = $statusChk3 = "";
                                if ($data[1] == 1)      { $statusChk1 = "checked"; }
                                elseif ($data[1] == 0) { $statusChk2 = "checked"; }
                                else                   { $statusChk3 = "checked"; }
                            } else {
                                $data = array('', '', '');
                                $statusChk1 = $statusChk2 = $statusChk3 = "";
                            }

                            // Professional negative finding used when this item is rated "No"
                            // (shared generator; the checklist statement itself stays unchanged)
                            $findingRaw  = msNegativeFinding($cklist[1], $subcat[1], $cat[1], isset($cklist[2]) ? $cklist[2] : null);
                            $findingText = htmlspecialchars($findingRaw, ENT_QUOTES, 'UTF-8');
                            ?>
                            <div class="row">
                                <div class="form-group col-sm-6">
                                    <input type="hidden" name="cid[<?=$chkcount;?>]" value="<?=$cklist[0];?>">
                                    <label class="col-form-label-sm"><?=htmlspecialchars($cklist[1]);?></label>
                                </div>
                                <div class="form-group col-sm-1"><label class="col-form-label-sm">Rating</label></div>
                                <div class="form-group col-sm-3"
                                     data-cid="<?=(int)$cklist[0];?>"
                                     data-item="<?=htmlspecialchars($cklist[1], ENT_QUOTES, 'UTF-8');?>"
                                     data-finding="<?=$findingText;?>">
                                    <div class="form-check-inline">
                                        <input class="form-check-input rating-radio" type="radio"
                                               name="rating[<?=$chkcount;?>]" value="1" <?=$statusChk1;?> required />
                                        <label class="form-check-label">Yes</label> &nbsp;

                                        <input class="form-check-input rating-radio" type="radio"
                                               name="rating[<?=$chkcount;?>]" value="0" <?=$statusChk2;?> />
                                        <label class="form-check-label">No</label> &nbsp;

                                        <input class="form-check-input rating-radio" type="radio"
                                               name="rating[<?=$chkcount;?>]" value="2" <?=$statusChk3;?> />
                                        <label class="form-check-label">NA</label> &nbsp;
                                    </div>
                                </div>
                                <div class="form-group col-sm-2">
                                    <input type="text" name="remarks[<?=$chkcount;?>]" class="form-control"
                                           value="<?=htmlspecialchars($data[2] ?? '', ENT_QUOTES);?>" placeholder="Remarks">
                                </div>
                            </div>
                            <?php
                            $chkcount++;
                        }
                    }
                }
            }
        }
    }
    echo '<input type="hidden" name="count" id="count" value="'.$chkcount.'">';
  ?>
</div>
<!-- /step 3 -->

            <!-- ============ STEP 4: RECOMMENDATIONS ============ -->
            <div class="wiz-pane" data-step="4">
              <h5 class="text-primary mb-1">Findings &amp; Recommendations</h5>
              <?php if ($isEdit && $id && mn_is_rcdc()) { ?>
                <a href="delete.php?type=ora&id=<?=(int)$id;?>&gid=<?=(int)$id;?>"
                   class="btn btn-outline-danger btn-sm float-right"
                   onclick="return confirm('Clear the findings, recommendation and action taken for this report?');">
                  <i class="fa fa-trash"></i> Clear this section</a>
              <?php } ?>
                <small class="text-muted d-block mb-3">
                    Findings are generated automatically from checklist items rated <strong>No</strong>.
                    Write your recommendations on the right &mdash; use the bullet-list button for bullet points.
                </small>
              <div class="row">
                <!-- LEFT: findings generated from the checklist (read-only, saved to
                     onsite_recommendation_action.findings via #findingsInput). -->
                <div class="col-md-6">
                  <label class="col-form-label-sm mb-1">Findings from Checklist</label>
                  <div class="ms-find-panel" id="msFindingsPanel">
                    <ol class="ms-find-list" id="msFindingsList"></ol>
                    <div class="ms-reco-empty text-muted" id="msFindingsEmpty">No checklist items are rated <strong>No</strong>, so there are no findings.</div>
                  </div>
                  <input type="hidden" name="findings" id="findingsInput" value="">
                </div>
                <!-- RIGHT: manual recommendations. Empty by default; never auto-filled. -->
                <div class="col-md-6">
                  <label class="col-form-label-sm mb-1" for="recommendation">Recommendations</label>
                  <div class="form-group">
                    <textarea name="recommendation" id="recommendation" class="form-control"><?=$oraData[0];?></textarea>
                  </div>
                </div>
              </div>
            </div>
            <!-- /step 4 -->

            <!-- ============ STEP 5: ACTIONS ============ -->
<!-- =========================================================
     STEP 5: ACTIONS
     ========================================================= -->
<div class="wiz-pane" data-step="5">

  <!-- Section Header -->
  <div class="action-section-header mb-3">

    <div>
      <h5 class="text-primary mb-1">
        <i class="fas fa-tasks mr-1"></i>
        Actions
      </h5>

      <small class="text-muted">
        Record actions taken, corrective actions and supporting evidence.
      </small>
    </div>

    <?php if ($isEdit && $id && mn_is_rcdc()) { ?>
    <div>
      <a href="delete.php?type=act&id=<?=(int)$id;?>&gid=<?=(int)$id;?>"
         class="btn btn-outline-danger btn-sm"
         onclick="return confirm('Clear the Action Taken for this report? Findings and recommendations are kept.');">
        <i class="fa fa-trash"></i> Clear this section</a>
    </div>
    <?php } ?>

  </div>


  <!-- =======================================================
       ACTION DETAILS
       ======================================================= -->
  <div class="action-details-card">
    <div class="action-details-body">

      <label class="action-field-label">
        Action Details
      </label>

      <textarea
        name="actions"
        id="actions"
        class="form-control"
        placeholder="Enter actions taken / corrective actions..."
      ><?=$oraData[1];?></textarea>

    </div>

  </div>


  <!-- =======================================================
       ACTION EVIDENCE UPLOAD
       ADMIN ONLY
       ======================================================= -->
  <?php if ($roleID == 1) { ?>

    <div class="action-evidence-upload-card mt-4">

      <div class="action-evidence-header">
        <div id="actionSelectedCount"
             class="action-image-count"
             style="display:none;">
          0 Images
        </div>

      </div>


      <div class="action-evidence-body">

        <!-- Upload Area -->
        <div class="action-upload-area">

          <div class="action-upload-icon">
            <i class="fas fa-cloud-upload-alt"></i>
          </div>

          <div class="action-upload-content">

            <div class="action-upload-title">
              Select Action Evidence Images
            </div>

            <div class="action-upload-description">
              You can select multiple images at once
            </div>

            <div class="action-upload-rules">
              JPG, JPEG, PNG or WEBP &nbsp;•&nbsp; Maximum 5 MB per image
            </div>

          </div>

          <div class="action-upload-button">

            <label
              for="actionImages"
              class="btn btn-primary btn-sm mb-0"
            >
              <i class="fas fa-plus mr-1"></i>
              Choose Images
            </label>

            <input
              type="file"
              id="actionImages"
              name="action_images[]"
              accept="image/jpeg,image/png,image/jpg,image/webp"
              multiple
              style="display:none;"
            >

          </div>

        </div>


        <!-- =================================================
             NEW IMAGE PREVIEW
             ================================================= -->
        <div id="newActionEvidenceSection"
             class="mt-4"
             style="display:none;">

          <div class="action-preview-heading">

            <div>
              <strong>
                <i class="fas fa-eye mr-1"></i>
                Selected Evidence
              </strong>

              <small class="text-muted d-block">
                These images will be uploaded when you click Submit.
              </small>
            </div>

            <span id="actionPreviewCount"
                  class="badge badge-primary">
              0
            </span>

          </div>


          <div
            id="actionImagePreview"
            class="action-image-grid"
          ></div>

        </div>


        <!-- =================================================
             EXISTING / SAVED IMAGES
             ================================================= -->
        <?php if (!empty($actionImages)) { ?>

          <div class="saved-action-evidence mt-4">

            <div class="action-preview-heading">

              <div>
                <strong>
                  <i class="fas fa-check-circle text-success mr-1"></i>
                  Saved Evidence
                </strong>

                <small class="text-muted d-block">
                  These images are already saved with this M&amp;S record.
                </small>
              </div>

              <span class="badge badge-success">
                <?=count($actionImages);?>
                <?=count($actionImages) == 1 ? 'Image' : 'Images';?>
              </span>

            </div>


            <div class="action-image-grid">

              <?php foreach ($actionImages as $index => $img) { ?>

                <?php
                  $imageId = (int)$img['image_id'];

                  $storedImagePath = trim($img['image_path']);

/*
 * image_path must be a browser-accessible URL/path.
 * Convert Windows backslashes to URL slashes.
 */
$storedImagePath = str_replace('\\', '/', $storedImagePath);

/*
 * If the DB already contains a web path beginning with /,
 * use it directly.
 */
if (strpos($storedImagePath, '/') === 0) {
    $imagePath = $storedImagePath;
} else {
    /*
     * If DB stores a relative upload path such as:
     * uploads/ms_actions/image.jpg
     *
     * adjust this prefix according to your actual upload folder.
     */
    $imagePath = '../' . ltrim($storedImagePath, '/');
}

$imagePath = htmlspecialchars(
    $imagePath,
    ENT_QUOTES,
    'UTF-8'
);

                  $imageName = htmlspecialchars(
                      $img['image_name'],
                      ENT_QUOTES,
                      'UTF-8'
                  );
                ?>


                <div
                  class="action-image-item saved-image-item"
                  id="action-image-<?=$imageId;?>"
                >

                  <!-- Image -->
                  <div class="action-image-preview-wrapper">

                    <a
                      href="<?=$imagePath;?>"
                      target="_blank"
                      class="action-image-link"
                      title="View Evidence #<?=($index + 1);?>"
                    >

                      <img
                        src="<?=$imagePath;?>"
                        class="action-gallery-image"
                        alt="Action Evidence <?=($index + 1);?>"
                        loading="lazy"
                        onerror="this.style.display='none'; this.parentElement.classList.add('image-load-error');"
                        >

                      <div class="action-image-hover">

                        <span class="btn btn-light btn-sm">
                          <i class="fas fa-search-plus mr-1"></i>
                          View
                        </span>

                      </div>

                    </a>


                    <!-- Number -->
                    <span class="action-image-badge">
                      #<?=($index + 1);?>
                    </span>


                    <!-- Saved Status -->
                    <span class="action-image-status">
                      <i class="fas fa-check"></i>
                      Saved
                    </span>

                  </div>


                  <!-- Information -->
                  <div class="action-image-info">

                    <div
                      class="action-image-filename"
                      title="<?=$imageName;?>"
                    >
                      <i class="far fa-file-image mr-1 text-primary"></i>
                      <?=$imageName;?>
                    </div>


                    <div class="action-image-actions">

                      <a
                        href="<?=$imagePath;?>"
                        target="_blank"
                        class="btn btn-outline-primary btn-sm"
                      >
                        <i class="fas fa-eye mr-1"></i>
                        View
                      </a>


                      <?php if ($roleID == 1) { ?>

                        <button
                          type="button"
                          class="btn btn-outline-danger btn-sm delete-action-image"
                          data-image-id="<?=$imageId;?>"
                        >
                          <i class="fas fa-trash-alt mr-1"></i>
                          Delete
                        </button>

                      <?php } ?>

                    </div>

                  </div>

                </div>

              <?php } ?>

            </div>

          </div>

        <?php } ?>


      </div>

    </div>

  <?php } ?>


</div>
<!-- /step 5 -->


<!-- /step 5 -->

            <!-- ============ STEP 6: REVIEW & SUBMIT ============ -->
            <div class="wiz-pane" data-step="6">
              <h5 class="text-primary mb-3">Review &amp; Submit</h5>
              <div id="msDirtyBox" class="alert alert-warning d-none" role="status">
                <i class="fas fa-exclamation-triangle mr-1"></i> You have unsaved changes to this completed record. Click <strong>Submit</strong> to save them and update the report.
              </div>
              <p class="text-muted" id="msReviewHint">Please review all information below. Nothing is saved until you click <strong>Submit</strong>.</p>
              <div id="reviewArea"></div>
              <?php if ($isEdit && $id) { ?>
              <div class="d-flex justify-content-end mt-3">
                <?=$deleteReport;?>
              </div>
              <?php } ?>
            </div>
            <!-- /step 6 -->

          </div>
          <!-- /.card-body -->
          <div class="card-footer d-flex flex-wrap align-items-center">
            <div class="d-flex align-items-center">
              <a href="ms-list.php" class="btn btn-outline-secondary">Cancel</a>
              <?php
              /* Clears the step on screen. The per-stage deletes in onsitems/delete.php
                 are per row (gi / sf / ck / ora / act), so a saved record is routed to
                 the row control that already carries the stage rules. */
              render_wizard_step_delete(array(
                  'module'    => 'onsite_ms',
                  'record_id' => (int)$id,
                  'can_write' => true,
                  'steps'     => array(
                      1 => array('name' => 'General Information',        'rows' => 'record', 'saved' => $isEdit),
                      2 => array('name' => 'Staff Information',          'rows' => 'many',   'saved' => $isEdit),
                      3 => array('name' => 'M&S Checklists',             'rows' => 'many',   'saved' => $isEdit),
                      4 => array('name' => 'Findings & Recommendations', 'rows' => 'many',   'saved' => $isEdit),
                      5 => array('name' => 'Action Taken',               'rows' => 'many',   'saved' => $isEdit),
                      6 => array('name' => 'Review & Submit',            'rows' => 'none',   'saved' => false),
                  ),
              ));
              ?>
              <button type="button" class="btn bg-gradient-secondary ml-2" id="wizPrev"><i class="fa fa-arrow-left"></i> Previous</button>
            </div>
            <div class="ml-auto">
              <button type="button" class="btn bg-gradient-primary" id="wizNext">Next <i class="fa fa-arrow-right"></i></button>
              <button type="submit" class="btn bg-gradient-primary" id="wizSubmit" style="display:none;"><i class="fa fa-check"></i> <?= ($isEdit && $id) ? 'Update &amp; Save Changes' : 'Submit'; ?></button>
            </div>
          </div>
        </div>
        <!-- /.card -->
        </form>
      </div>
    </section>

  </div>
  <!-- /.content-wrapper -->

  <style>
    /* ---- M&S wizard: self-contained professional field, stepper & button styling ---- */
    /* Every input / select / date-picker: identical 42px height, 4px radius, blue focus */
    #wizardForm .form-control,
    #wizardForm select.form-control,
    #wizardForm .input-group > .form-control{
      height:42px; padding:.5rem .85rem; font-size:.875rem;
      border:1px solid #ced4da; border-radius:4px; box-shadow:none;
      transition:border-color .15s ease, box-shadow .15s ease;
    }
    #wizardForm textarea.form-control{ height:auto; min-height:110px; }
    #wizardForm .form-control:focus,
    #wizardForm select.form-control:focus{
      border-color:#007bff; box-shadow:0 0 0 .2rem rgba(0,123,255,.25); outline:none;
    }
    /* Date-picker calendar button matches the 42px field height seamlessly */
    #wizardForm .input-group.date .form-control{ border-right:0; }
    #wizardForm .input-group.date .input-group-text{
      height:42px; background:#f8f9fb; color:#6c757d;
      border:1px solid #ced4da; border-left:0; border-radius:0 4px 4px 0;
    }
    /* Select2 single-select matches the 42px field height */
    #wizardForm .select2-container--bootstrap4 .select2-selection,
    #wizardForm .select2-container--default .select2-selection--single{
      height:42px !important; min-height:42px !important;
      border:1px solid #ced4da !important; border-radius:4px !important;
      display:flex; align-items:center;
    }
    #wizardForm .select2-container--default .select2-selection--single .select2-selection__rendered{ line-height:40px; }

    /* Stepper: modern horizontal progress indicator (blue completed + active, grey pending).
       Scoped to #wizNav so it overrides only this module's stepper; labels/steps stay driven
       by the existing markup + JS (.active/.done), so the design adapts to any step count.  */
    #wizNav .wiz-step{ color:#6B7280; font-weight:600; }
    #wizNav .wiz-step .wiz-num{ display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; font-size:1rem; background:#E5E7EB; border:3px solid #fff; color:#6B7280; }
    #wizNav .wiz-step .wiz-label{ font-weight:600; }
    /* connector line (shared base draws it on the left via ::before) */
    #wizNav .wiz-step::before{ top:28px; height:4px; background:#E5E7EB; }
    /* status caption under each label */
    #wizNav .wiz-step::after{ content:"Pending"; display:block; margin-top:2px; font-size:11px; font-weight:600; color:#9CA3AF; }
    /* completed */
    #wizNav .wiz-step.done{ color:#111827; font-weight:700; }
    #wizNav .wiz-step.done .wiz-num{ background:#007bff; border-color:#007bff; color:#fff; font-size:0; }
    #wizNav .wiz-step.done .wiz-num::before{ content:"\f00c"; font-family:"Font Awesome 5 Free"; font-weight:900; font-size:15px; }
    #wizNav .wiz-step.done::after{ content:"Completed"; color:#007bff; }
    #wizNav .wiz-step.done::before, #wizNav .wiz-step.active::before{ background:#007bff; }
    /* current / in-progress */
    #wizNav .wiz-step.active{ color:#111827; font-weight:700; }
    #wizNav .wiz-step.active .wiz-num{ background:#007bff; border-color:#007bff; color:#fff; box-shadow:0 0 0 4px rgba(0,123,255,.18); }
    #wizNav .wiz-step.active::after{ content:"In Progress"; color:#007bff; }
    /* Step 4: read-only "Findings from Checklist" panel beside the Recommendations editor */
    .ms-find-panel{ border:1px solid #dee2e6; border-radius:6px; background:#fff; overflow:hidden; min-height:120px; }
    .ms-find-list{ margin:0; padding:10px 14px 10px 32px; font-size:.9rem; line-height:1.45; overflow-wrap:anywhere; }
    .ms-find-list li{ margin:0 0 5px; }
    .ms-find-list:empty{ display:none; }
    .ms-reco-empty{ padding:12px 14px; border-top:1px solid #eef1f4; font-size:.9rem; }
    /* submitted record: a completed stage stays labelled Completed while it is being viewed */
    #wizNav.ms-completed .wiz-step.done.active::after{ content:"Completed"; }
    @media (max-width:575.98px){
      #wizNav{ overflow-x:visible; }
      #wizNav .wiz-step{ min-width:0; font-size:10.5px; }
      #wizNav .wiz-step .wiz-num{ width:34px; height:34px; font-size:12px; }
      #wizNav .wiz-step::before{ top:23px; }
      #wizNav .wiz-step.done .wiz-num::before{ font-size:12px; }
      #wizNav .wiz-step::after{ font-size:9.5px; }
    }

    /* Action buttons: consistent size; left group (Cancel/Previous), primary action pinned right */
    #wizardForm .card-footer{ display:flex; flex-wrap:wrap; align-items:center; }
    #wizardForm .card-footer .btn{ min-width:108px; font-weight:600; }
    #wizardForm #wizNext, #wizardForm #wizSubmit{ background:#007bff; border-color:#007bff; color:#fff; }
    #wizardForm #wizNext:hover, #wizardForm #wizSubmit:hover{ background:#0062cc; border-color:#0062cc; }
    /* Step-wise validation: highlight an unrated checklist rating group */
    #wizardForm .is-invalid-group{ border:1px solid #dc3545; border-radius:4px; padding:2px 6px; }
    #wizardForm .wiz-inline-err, #wizardForm .chk-rating-err{ display:block; }
    
    
 /* =========================================================
   ACTION SECTION
   ========================================================= */

.action-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}


/* =========================================================
   ACTION DETAILS CARD
   ========================================================= */

.action-details-card {
    background: #ffffff;
    border: 1px solid #e2e6ea;
    border-radius: 10px;
    overflow: hidden;
}

.action-details-header {
    display: flex;
    align-items: center;
    padding: 14px 16px;
    background: #f8f9fb;
    border-bottom: 1px solid #e2e6ea;
}

.action-header-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #eaf2ff;
    color: #007bff;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
}

.action-header-title {
    font-weight: 700;
    color: #212529;
    font-size: 14px;
}

.action-header-subtitle {
    font-size: 12px;
    color: #6c757d;
    margin-top: 2px;
}

.action-details-body {
    padding: 16px;
}

.action-field-label {
    font-size: 13px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 7px;
}


/* Keep Summernote professional */

#actions + .note-editor {
    border-radius: 7px;
    border-color: #ced4da;
}

#actions + .note-editor .note-toolbar {
    background: #f8f9fb;
}


/* =========================================================
   ACTION EVIDENCE UPLOAD CARD
   ========================================================= */

.action-evidence-upload-card {
    border: 1px solid #dfe4ea;
    border-radius: 10px;
    background: #fff;
    overflow: hidden;
}

.action-evidence-header {
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #e5e7eb;
    background: #f8f9fb;
}

.action-evidence-icon {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: #eaf2ff;
    color: #007bff;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 11px;
}

.action-evidence-title {
    font-size: 14px;
    font-weight: 700;
    color: #212529;
}

.action-evidence-subtitle {
    font-size: 12px;
    color: #6c757d;
    margin-top: 2px;
}

.action-image-count {
    background: #007bff;
    color: #fff;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.action-evidence-body {
    padding: 16px;
}


/* =========================================================
   UPLOAD AREA
   ========================================================= */

.action-upload-area {
    display: flex;
    align-items: center;
    border: 1px dashed #b8c2cc;
    border-radius: 9px;
    background: #fbfcfe;
    padding: 15px;
    transition: all .2s ease;
}

.action-upload-area:hover {
    border-color: #007bff;
    background: #f8fbff;
}

.action-upload-icon {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    background: #eaf2ff;
    color: #007bff;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 18px;
}

.action-upload-content {
    flex: 1;
}

.action-upload-title {
    font-size: 13px;
    font-weight: 700;
    color: #343a40;
}

.action-upload-description {
    font-size: 12px;
    color: #6c757d;
    margin-top: 2px;
}

.action-upload-rules {
    font-size: 11px;
    color: #9aa0a6;
    margin-top: 4px;
}

.action-upload-button {
    margin-left: 15px;
}


/* =========================================================
   PREVIEW HEADING
   ========================================================= */

.action-preview-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 10px;
    margin-bottom: 12px;
    border-bottom: 1px solid #e9ecef;
}

.action-preview-heading strong {
    font-size: 13px;
    color: #343a40;
}

.action-preview-heading small {
    font-size: 11px;
}


/* =========================================================
   IMAGE GRID
   ========================================================= */

.action-image-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}


/* =========================================================
   IMAGE CARD
   ========================================================= */

.action-image-item {
    background: #fff;
    border: 1px solid #e1e5ea;
    border-radius: 9px;
    overflow: hidden;
    min-width: 0;
    transition: all .2s ease;
}

.action-image-item:hover {
    border-color: #b9c8d8;
    box-shadow: 0 5px 16px rgba(0, 0, 0, .08);
    transform: translateY(-2px);
}


/* =========================================================
   IMAGE PREVIEW
   ========================================================= */

.action-image-preview-wrapper {
    position: relative;
    height: 150px;
    background: #f2f4f7;
    overflow: hidden;
}

.action-gallery-image,
.action-preview-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}


/* =========================================================
   IMAGE HOVER
   ========================================================= */

.action-image-link {
    display: block;
    width: 100%;
    height: 100%;
    position: relative;
}

.action-image-hover {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, .42);
    opacity: 0;
    transition: opacity .2s ease;
}

.action-image-link:hover .action-image-hover {
    opacity: 1;
}


/* =========================================================
   IMAGE NUMBER
   ========================================================= */

.action-image-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    min-width: 27px;
    height: 27px;
    padding: 0 7px;
    border-radius: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, .72);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
}


/* =========================================================
   SAVED STATUS
   ========================================================= */

.action-image-status {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(40, 167, 69, .95);
    color: #fff;
    border-radius: 5px;
    padding: 4px 7px;
    font-size: 10px;
    font-weight: 600;
}


/* =========================================================
   IMAGE INFORMATION
   ========================================================= */

.action-image-info {
    padding: 10px;
}

.action-image-filename {
    font-size: 11px;
    font-weight: 600;
    color: #495057;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 9px;
}


/* =========================================================
   IMAGE BUTTONS
   ========================================================= */

.action-image-actions {
    display: flex;
    gap: 6px;
}

.action-image-actions .btn {
    flex: 1;
    font-size: 11px;
    border-radius: 5px;
}


/* =========================================================
   NEW IMAGE REMOVE BUTTON
   ========================================================= */

.action-remove-preview {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 27px;
    height: 27px;
    border: 0;
    border-radius: 5px;
    background: rgba(220, 53, 69, .95);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 5;
    transition: all .15s ease;
}

.action-remove-preview:hover {
    background: #c82333;
    transform: scale(1.05);
}


/* =========================================================
   FILE SIZE
   ========================================================= */

.action-image-size {
    font-size: 10px;
    color: #8a929a;
    margin-top: 2px;
}


/* =========================================================
   EMPTY STATE
   ========================================================= */

.action-no-images {
    text-align: center;
    padding: 25px 15px;
    border: 1px dashed #d7dce1;
    border-radius: 8px;
    background: #fafbfc;
    color: #6c757d;
}

.action-no-images i {
    font-size: 28px;
    margin-bottom: 8px;
    opacity: .65;
}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (max-width: 1199px) {

    .action-image-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

}

@media (max-width: 767px) {

    .action-image-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .action-upload-area {
        flex-wrap: wrap;
    }

    .action-upload-content {
        min-width: 0;
    }

    .action-upload-button {
        width: 100%;
        margin-left: 0;
        margin-top: 12px;
    }

}

@media (max-width: 480px) {

    .action-image-grid {
        grid-template-columns: 1fr;
    }

    .action-image-preview-wrapper {
        height: 190px;
    }

}


  </style>
  <style>
    /* =========================================================
   PROFESSIONAL STAFF DATE PICKER
   ========================================================= */
/* =========================================================
   STAFF DATE PICKER – FINAL FIX (no clipping, high z-index)
   ========================================================= */
/* Stronger fix for staff Last Training calendar */
#wizardForm .staff-table-responsive,
#wizardForm .table-responsive {
    overflow-x: auto !important;
    overflow-y: visible !important;
}

#wizardForm #staffTable,
#wizardForm #staffTable td,
#wizardForm .staff-ldt-wrap {
    overflow: visible !important;
    position: relative;
}

/* Make sure the widget is always on top and not affected by parent transforms */
.bootstrap-datetimepicker-widget {
    z-index: 99999 !important;
    position: absolute !important;
}

/* Optional: force the dropdown to appear cleanly */
.bootstrap-datetimepicker-widget.dropdown-menu {
    margin-top: 2px !important;
}
  </style>

  <script>
    $(function () {
        $('.select2').select2({ theme: 'bootstrap4' });
        $('#dov').datetimepicker({ format: 'YYYY-MM-DD' });

        /* Shared initialiser — see includes/css-jquery.php. Same toolbar as before. */
        mnSummernote('#recommendation, #actions', {
            toolbar: [['style',['bold','italic','underline','clear']],['fontsize',['fontsize']],['color',['color']],['para',['ul','ol','paragraph']]]
        });
/* =========================================================
   AUTO-POPULATE RECOMMENDATION FROM CHECKLIST "No" RATINGS
   Professional – no extra blank space
   ========================================================= */
(function () {
    var MARKER_START = '<!--MS_FINDINGS_START-->';
    var MARKER_END   = '<!--MS_FINDINGS_END-->';

    function collectNoFindings() {
        var list = [];
        $('input.rating-radio[value="0"]:checked').each(function () {
            var txt = ($(this).closest('[data-finding]').data('finding') || '').toString().trim();
            if (txt && list.indexOf(txt) === -1) list.push(txt);
        });
        return list;
    }

    function buildFindingsBlock(findings) {
        if (!findings.length) return '';

        var html = MARKER_START +
            '<hr style="margin:10px 0 8px;border:0;border-top:1px solid #dee2e6;">' +
            '<p style="margin:0 0 4px;"><strong>Findings (items rated No):</strong></p>' +
            '<ul style="margin:0 0 0 18px;padding:0;">';

        findings.forEach(function (f) {
            html += '<li style="margin:0 0 3px;">' + $('<div>').text(f).html() + '</li>';
        });

        html += '</ul>' + MARKER_END;
        return html;
    }

    /* Remove ALL empty paragraphs / br that Summernote leaves behind */
    function stripEmpty(html) {
        if (!html) return '';
        return html
            // empty <p> with only whitespace, &nbsp; or <br>
            .replace(/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '')
            // leftover <br> at end of other paragraphs
            .replace(/(<br\s*\/?>\s*)+$/gi, '')
            .trim();
    }

    /* Findings from Checklist: the generated statements for every item rated No.
       This is exactly the content that used to be produced under Recommendations. */
    function renderFindingsPanel() {
        var findings = collectNoFindings();
        var html = '';
        findings.forEach(function (f) {
            html += '<li>' + $('<div>').text(f).html() + '</li>';
        });
        $('#msFindingsList').html(html);
        $('#msFindingsEmpty').toggle(findings.length === 0);
    }

    // The generated block is shown as the two-column table instead of inside the editor. The
    // editor holds only the free-text recommendation; the saved value is rebuilt exactly as before:
    // free text (empty paragraphs stripped) + the generated MS_FINDINGS block.
    window.msAutoRecommendationBlock = '';
    /* Recommendations are now manual only. The generated findings are saved on their
       own (onsite_recommendation_action.findings) instead of being appended here. */
    window.msFullRecommendation = function () {
        return stripEmpty($('#recommendation').summernote('code') || '');
    };

    function updateRecommendationFindings(fromLoad) {
        if (!$('#recommendation').length || typeof $('#recommendation').summernote !== 'function') {
            return;
        }

        var previousBlock = window.msAutoRecommendationBlock;
        window.msAutoRecommendationBlock = buildFindingsBlock(collectNoFindings());
        renderFindingsPanel();
        $('#findingsInput').val(window.msAutoRecommendationBlock);   // posted and stored

        // Take any stored generated block (saved records) out of the editor view; it is
        // regenerated from the ratings above and re-attached on save.
        var current = $('#recommendation').summernote('code') || '';
        var start = current.indexOf(MARKER_START);
        var end   = current.indexOf(MARKER_END);
        if (start !== -1 && end !== -1 && end > start) {
            var free = stripEmpty(current.substring(0, start) + current.substring(end + MARKER_END.length));
            window.msSuppressDirty = true;   // not a user edit
            try { $('#recommendation').summernote('code', free); }
            finally { setTimeout(function(){ window.msSuppressDirty = false; }, 0); }
        }

        // A rating change that alters the findings is an unsaved edit (same as before).
        if (fromLoad !== true && previousBlock !== window.msAutoRecommendationBlock && typeof msDirty !== 'undefined') {
            msDirty = true;
            $('#dlReportBtn').hide();
            if (window.msRefreshCompletedUI) { msRefreshCompletedUI(); }
        }
    }

    // Live update
    $(document).on('change', 'input.rating-radio', function () {
        updateRecommendationFindings();
    });

    // On load (New and Edit): build the table and move the stored block out of the editor.
    updateRecommendationFindings(true);
    setTimeout(function(){ updateRecommendationFindings(true); }, 600);
})();

        // ---------- Staff inline rows ----------
        function staffRowHtml(){
    var rowId = 'new_' + Date.now();

    return '<tr>'+
        '<td>'+
            '<input type="hidden" name="staff_id[]" value="">'+
            '<input type="text" name="staff_name[]" class="form-control form-control-sm">'+
        '</td>'+
        '<td>'+
            '<input type="text" name="staff_designation[]" class="form-control form-control-sm">'+
        '</td>'+
        '<td>'+
            '<input type="number" name="staff_frequency[]" class="form-control form-control-sm">'+
        '</td>'+
        '<td>'+
            '<input type="number" name="staff_days[]" class="form-control form-control-sm">'+
        '</td>'+
        '<td>'+
            '<div class="input-group date staff-ldt-wrap" '+
                 'id="staffLdt_'+rowId+'" '+
                 'data-target-input="nearest">'+

                '<input type="text" '+
                       'name="staff_ldt[]" '+
                       'class="form-control form-control-sm datetimepicker-input" '+
                       'data-target="#staffLdt_'+rowId+'" '+
                       'placeholder="YYYY-MM-DD">'+

                '<div class="input-group-append" '+
                     'data-target="#staffLdt_'+rowId+'" '+
                     'data-toggle="datetimepicker">'+

                    '<div class="input-group-text">'+
                        '<i class="fa fa-calendar"></i>'+
                    '</div>'+
                '</div>'+
            '</div>'+
        '</td>'+
        '<td>'+
            '<input type="text" name="staff_topic[]" class="form-control form-control-sm">'+
        '</td>'+
        '<td class="text-center">'+
            '<button type="button" class="btn btn-outline-danger btn-sm staffDel">'+
                '<i class="fa fa-trash"></i>'+
            '</button>'+
        '</td>'+
    '</tr>';
}
        function toggleStaffHint(){
            $('#staffEmptyHint').toggle($('#staffTbody tr').length===0);
        }
        // Idempotent picker init for staff-training date rows. Safe to call
        // after every dynamic append — Tempus Dominus does not double-bind
        // when re-called on an already-initialised wrapper (the widget just
        // updates its options).
        function initStaffDatePickers($scope) {
    var $targets = ($scope && $scope.length)
        ? $scope.find('.staff-ldt-wrap')
        : $('.staff-ldt-wrap');

    $targets.each(function () {
        var $picker = $(this);

        // Destroy any previous instance cleanly
        if ($picker.data('DateTimePicker')) {
            try { $picker.data('DateTimePicker').destroy(); } catch (e) {}
        }
        $picker.removeData('DateTimePicker');

        $picker.datetimepicker({
            format: 'YYYY-MM-DD',
            useCurrent: false,
            allowInputToggle: true,            // keep this
            widgetPositioning: {
                horizontal: 'auto',
                vertical: 'auto'              // force it below the field
            },
            icons: {
                time: 'fa fa-clock',
                date: 'fa fa-calendar',
                up: 'fa fa-chevron-up',
                down: 'fa fa-chevron-down',
                previous: 'fa fa-chevron-left',
                next: 'fa fa-chevron-right',
                today: 'fa fa-calendar-check',
                clear: 'fa fa-trash',
                close: 'fa fa-times'
            }
        });
    });
}
        // Init existing rows on load.
        initStaffDatePickers();
        console.log('Staff datepickers initialized:', $('.staff-ldt-wrap').length);
            $('.staff-ldt-wrap').on('click', function(){
            console.log('Clicked staff datepicker wrapper');
        });
        /* The staff "Last Training" calendar is kept visible by the shared
           positioning helper (dist/js/mneqas-datepicker.js), which now does for
           every datepicker what this page used to do for the staff table only. */
        $('#btnAddStaff').on('click', function(){
            $('#staffTbody').append(staffRowHtml());
            var $newRow = $('#staffTbody tr:last');
            $newRow.find('input[name="staff_name[]"]').focus();
            initStaffDatePickers($newRow);
            toggleStaffHint();
        });
        $('#staffTbody').on('click', '.staffDel', function(){
            var $tr = $(this).closest('tr');
            var sid = $tr.find('input[name="staff_id[]"]').val();
            if(sid){ $('#deletedStaff').append('<input type="hidden" name="deleted_staff[]" value="'+sid+'">'); }
            $tr.remove();
            toggleStaffHint();
        });
        toggleStaffHint();

        // ---------- Wizard navigation (free; nothing saved between steps) ----------
        var wizCurrent = <?=$curStep;?>;
        var wizTotal   = 6;

        function esc(s){ return $('<div>').text(s==null?'':s).html(); }

        function buildReview(){
            var html = '';
            // General
            html += '<div class="review-sec"><div class="review-head"><strong>General Information</strong>'+
                    '<button type="button" class="btn btn-sm btn-outline-primary wizEdit" data-goto="1"><i class="fa fa-edit"></i> Edit</button></div><div class="review-body"><div class="row">';
            html += '<div class="col-sm-4 mb-2"><span class="rk">Dzongkhag</span><strong>'+esc($('select[name="dzongkhag"] option:selected').text().trim()||'—')+'</strong></div>';
            html += '<div class="col-sm-4 mb-2"><span class="rk">Health Facility</span><strong>'+esc($('select[name="facility"] option:selected').text().trim()||'—')+'</strong></div>';
            html += '<div class="col-sm-4 mb-2"><span class="rk">Date of Visit</span><strong>'+esc($('input[name="dov"]').val()||'—')+'</strong></div>';
            html += '<div class="col-sm-4 mb-2"><span class="rk">Audit Category</span><strong>'+esc($('select[name="audit"]').val()||'—')+'</strong></div>';
            html += '<div class="col-sm-4 mb-2"><span class="rk">Evaluator</span><strong>'+esc($('input[name="evaluator"]').val()||'—')+'</strong></div>';
            html += '<div class="col-sm-4 mb-2"><span class="rk">Designation</span><strong>'+esc($('input[name="designation"]').val()||'—')+'</strong></div>';
            html += '</div></div></div>';

            // Staff
            html += '<div class="review-sec"><div class="review-head"><strong>Staff Information</strong>'+
                    '<button type="button" class="btn btn-sm btn-outline-primary wizEdit" data-goto="2"><i class="fa fa-edit"></i> Edit</button></div><div class="review-body table-responsive">';
            var rows = $('#staffTbody tr').filter(function(){ return $.trim($(this).find('input[name="staff_name[]"]').val())!==''; });
            if(rows.length===0){ html += '<span class="text-muted">No staff added.</span>'; }
            else {
                html += '<table class="table table-sm table-bordered mb-0"><thead><tr class="text-center"><th>Staff Name</th><th>Designation</th><th>Frequency</th><th>Days</th><th>Last Training</th><th>Topic</th></tr></thead><tbody>';
                rows.each(function(){
                    var $t=$(this);
                    html += '<tr><td>'+esc($t.find('input[name="staff_name[]"]').val())+'</td>'+
                            '<td>'+esc($t.find('input[name="staff_designation[]"]').val())+'</td>'+
                            '<td>'+esc($t.find('input[name="staff_frequency[]"]').val())+'</td>'+
                            '<td>'+esc($t.find('input[name="staff_days[]"]').val())+'</td>'+
                            '<td>'+esc($t.find('input[name="staff_ldt[]"]').val())+'</td>'+
                            '<td>'+esc($t.find('input[name="staff_topic[]"]').val())+'</td></tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div></div>';

            // Checklist
            var total = $('input[name^="cid"]').length;
            var answered = 0;
            $('input[name^="cid"]').each(function(){
                var idx = $(this).attr('name').replace('cid[','').replace(']','');
                if($('input[name="rating['+idx+']"]:checked').length){ answered++; }
            });
            html += '<div class="review-sec"><div class="review-head"><strong>M&amp;S Checklist</strong>'+
                    '<button type="button" class="btn btn-sm btn-outline-primary wizEdit" data-goto="3"><i class="fa fa-edit"></i> Edit</button></div>'+
                    '<div class="review-body"><span class="badge '+(answered===total?'badge-success':'badge-warning')+'">'+answered+' of '+total+' items rated</span>'+
                    (answered<total?' <small class="text-danger">All items must be rated before submission.</small>':'')+' <small class="text-muted d-block mt-1">Category scores are calculated automatically on submit.</small></div></div>';

            // Findings from Checklist (generated) then Recommendations (manual)
            var fnd = $('#findingsInput').val() || '';
            html += '<div class="review-sec"><div class="review-head"><strong>Findings from Checklist</strong>'+
                    '<button type="button" class="btn btn-sm btn-outline-primary wizEdit" data-goto="4"><i class="fa fa-edit"></i> Edit</button></div>'+
                    '<div class="review-body">'+((fnd && fnd.replace(/<[^>]*>/g,'').trim()!=='')?fnd:'<span class="text-muted">No findings.</span>')+'</div></div>';

            var rec = window.msFullRecommendation ? msFullRecommendation() : $('#recommendation').summernote('code');
            html += '<div class="review-sec"><div class="review-head"><strong>Recommendations</strong>'+
                    '<button type="button" class="btn btn-sm btn-outline-primary wizEdit" data-goto="4"><i class="fa fa-edit"></i> Edit</button></div>'+
                    '<div class="review-body">'+((rec && rec.replace(/<[^>]*>/g,'').trim()!=='')?rec:'<span class="text-muted">Not provided.</span>')+'</div></div>';

            // Actions
            var act = $('#actions').summernote('code');
            html += '<div class="review-sec"><div class="review-head"><strong>Actions</strong>'+
                    '<button type="button" class="btn btn-sm btn-outline-primary wizEdit" data-goto="5"><i class="fa fa-edit"></i> Edit</button></div>'+
                    '<div class="review-body">'+((act && act.replace(/<[^>]*>/g,'').trim()!=='')?act:'<span class="text-muted">Not provided.</span>')+'</div></div>';

            $('#reviewArea').html(html);
        }


        /*
 * Multiple Action Image Preview
 */

        /* =========================================================
   ACTION EVIDENCE IMAGE PREVIEW
   ========================================================= */

(function () {

    var selectedActionFiles = [];

    var allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/jpg',
        'image/webp'
    ];

    var maxFileSize = 5 * 1024 * 1024;


    /* =====================================================
       1. FORMAT FILE SIZE
       Example:
       1024 KB → 1.0 MB
       ===================================================== */
    function formatFileSize(bytes) {

        if (bytes < 1024) {
            return bytes + ' B';
        }

        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }

        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }


    /* =====================================================
       2. ESCAPE FILE NAME
       Prevents unsafe HTML in filename
       ===================================================== */
    function escapeHtml(value) {

        return $('<div>')
            .text(value == null ? '' : value)
            .html();
    }


    /* =====================================================
       3. DISPLAY SELECTED IMAGES
       
       This is the MAIN PREVIEW FUNCTION.
       
       It represents:
       #1 Image
       #2 Image
       #3 Image
       etc.
       ===================================================== */
    function renderActionImagePreview() {

        var $preview = $('#actionImagePreview');
        var $section = $('#newActionEvidenceSection');

        $preview.empty();


        /* No images selected */
        if (selectedActionFiles.length === 0) {

            $section.hide();

            $('#actionSelectedCount').hide();

            $('.action-upload-area')
                .removeClass('border-primary');

            return;
        }


        /* Show preview section */
        $section.show();


        /* Show total image count */
        $('#actionSelectedCount')
            .text(
                selectedActionFiles.length +
                (
                    selectedActionFiles.length === 1
                        ? ' Image'
                        : ' Images'
                )
            )
            .show();


        $('#actionPreviewCount')
            .text(selectedActionFiles.length);


        /* Create preview for every selected image */
        selectedActionFiles.forEach(function (file, index) {

            var reader = new FileReader();


            reader.onload = function (e) {

                var $item = $(
                    '<div class="action-image-item">' +

                        '<div class="action-image-preview-wrapper">' +

                            '<img ' +
                                'src="' + e.target.result + '" ' +
                                'class="action-preview-image" ' +
                                'alt="Selected Evidence ' +
                                (index + 1) + '">' +

                            /* Image number */
                            '<span class="action-image-badge">' +
                                '#' + (index + 1) +
                            '</span>' +

                            /* Remove button */
                            '<button ' +
                                'type="button" ' +
                                'class="action-remove-preview" ' +
                                'data-file-index="' + index + '" ' +
                                'title="Remove this image">' +

                                '<i class="fas fa-times"></i>' +

                            '</button>' +

                        '</div>' +


                        '<div class="action-image-info">' +

                            /* Filename */
                            '<div ' +
                                'class="action-image-filename" ' +
                                'title="' + escapeHtml(file.name) + '">' +

                                '<i class="far fa-file-image ' +
                                'mr-1 text-primary"></i>' +

                                escapeHtml(file.name) +

                            '</div>' +


                            /* File size */
                            '<div class="action-image-size">' +

                                '<i class="fas fa-file mr-1"></i>' +

                                formatFileSize(file.size) +

                                ' &nbsp;•&nbsp; New Evidence' +

                            '</div>' +

                        '</div>' +

                    '</div>'
                );


                $preview.append($item);
            };


            reader.readAsDataURL(file);

        });

    }


    /* =====================================================
       4. USER SELECTS IMAGES
       
       This runs IMMEDIATELY after:
       Choose Images → Select → Open
       
       No Submit required.
       ===================================================== */
    $('#actionImages').on('change', function () {

        var input = this;

        var files = Array.from(
            input.files || []
        );


        if (!files.length) {
            return;
        }


        var invalidFiles = [];


        /* Validate every selected file */
        files.forEach(function (file) {


            /* File type validation */
            if (!allowedTypes.includes(file.type)) {

                invalidFiles.push(
                    file.name +
                    ' - unsupported format'
                );

                return;
            }


            /* 5 MB validation */
            if (file.size > maxFileSize) {

                invalidFiles.push(
                    file.name +
                    ' - exceeds 5 MB'
                );

                return;
            }


            /* Valid image */
            selectedActionFiles.push(file);

        });


        /* Show invalid files */
        if (invalidFiles.length > 0) {

            Swal.fire({
                icon: 'warning',

                title: 'Some images were not added',

                html:
                    '<div class="text-left small">' +

                    invalidFiles.map(function (item) {

                        return '<div>• ' +
                            escapeHtml(item) +
                            '</div>';

                    }).join('') +

                    '</div>',

                confirmButtonText: 'OK'
            });

        }


        /* Update actual input */
        syncActionFileInput();


        /* Immediately display preview */
        renderActionImagePreview();

    });


    /* =====================================================
       5. REMOVE SELECTED IMAGE
       
       Example:
       User selects 3 images
       
       #1
       #2
       #3
       
       Click X on #2
       
       Result:
       #1
       #3
       
       Then preview is regenerated.
       ===================================================== */
    $(document).on(
        'click',
        '.action-remove-preview',
        function () {

            var index = parseInt(
                $(this).attr('data-file-index'),
                10
            );


            if (isNaN(index)) {
                return;
            }


            /* Remove image from JavaScript array */
            selectedActionFiles.splice(
                index,
                1
            );


            /* Update actual input */
            syncActionFileInput();


            /* Refresh preview */
            renderActionImagePreview();

        }
    );


    /* =====================================================
       6. UPDATE REAL FILE INPUT
       
       Important:
       Removing image from preview must also remove it
       from the actual <input type="file">
       ===================================================== */
    function syncActionFileInput() {

        var input =
            document.getElementById('actionImages');


        if (!input) {
            return;
        }


        var dataTransfer =
            new DataTransfer();


        selectedActionFiles.forEach(function (file) {

            dataTransfer.items.add(file);

        });


        input.files =
            dataTransfer.files;


        if (selectedActionFiles.length === 0) {

            $('#actionImages').val('');

            $('.action-upload-area')
                .removeClass('border-primary');

        } else {

            $('.action-upload-area')
                .addClass('border-primary');

        }

    }


    /* =====================================================
       7. RESET AFTER SUCCESSFUL SUBMIT
       ===================================================== */
    window.resetActionImagePreview = function () {

        selectedActionFiles = [];

        $('#actionImages').val('');

        $('#actionImagePreview').empty();

        $('#newActionEvidenceSection').hide();

        $('#actionSelectedCount').hide();

        $('.action-upload-area')
            .removeClass('border-primary');

    };


})();

        function markDone(){
            // General complete?
            var gOk = $('select[name="dzongkhag"]').val() && $('select[name="facility"]').val() && $('input[name="dov"]').val() && $('select[name="audit"]').val() && $('input[name="evaluator"]').val() && $('input[name="designation"]').val();
            $('.wiz-step[data-step="1"]').toggleClass('done', !!gOk);
            $('.wiz-step[data-step="2"]').toggleClass('done', $('#staffTbody tr').length>0);
            var total = $('input[name^="cid"]').length, answered=0;
            $('input[name^="cid"]').each(function(){ var idx=$(this).attr('name').replace('cid[','').replace(']',''); if($('input[name="rating['+idx+']"]:checked').length){answered++;} });
            $('.wiz-step[data-step="3"]').toggleClass('done', total>0 && answered===total);
            $('.wiz-step[data-step="4"]').toggleClass('done', ((($('#findingsInput').val()||'') + ((window.msFullRecommendation ? msFullRecommendation() : $('#recommendation').summernote('code'))||''))).replace(/<[^>]*>/g,'').trim()!=='');
            $('.wiz-step[data-step="5"]').toggleClass('done', ($('#actions').summernote('code')||'').replace(/<[^>]*>/g,'').trim()!=='');
            // Final status via the shared helper (dist/js/mneqas-stepper-state.js): the live
            // checks above say which stages hold complete data, the persisted list says which
            // stages the SAVED record already has. Completed record => all stages Completed
            // (Review only while the form still matches what was submitted).
            var clean = (typeof msSaved !== 'undefined') && msSaved && !msDirty;
            if (window.mnStepperState){
                var msDone = [];
                $('.wiz-step').each(function(){
                    var s = parseInt($(this).data('step'), 10);
                    if ($(this).hasClass('done') || $.inArray(s, MS_PERSISTED_DONE) !== -1){ msDone.push(s); }
                });
                if (!clean){ msDone = $.grep(msDone, function(s){ return s !== 6; }); }
                var msState = mnStepperState({
                    steps:     [1, 2, 3, 4, 5, 6],
                    current:   wizCurrent,
                    completed: MS_COMPLETED && clean,
                    done:      msDone
                });
                mnApplyStepperState($('#wizNav .wiz-step'), msState, function($el){ return parseInt($el.data('step'), 10); });
            }
            $('#wizNav').toggleClass('ms-completed', MS_COMPLETED && clean);
        }

        // Review-stage final state: Completed banner + Download Report, or an unsaved-changes note.
        window.msRefreshCompletedUI = function(){
            var onReview = (wizCurrent === wizTotal);
            var clean = msSaved && !msDirty;
            $('#dlReportBtn').toggle(onReview && clean);
            $('#msDirtyBox').toggleClass('d-none', !(onReview && MS_COMPLETED && msDirty));
            $('#msReviewHint').toggle(!(MS_COMPLETED && clean));
            markDone();
        };

        // ---------- Step-wise validation ----------
        // Enforces the EXISTING mandatory fields per step (same rules used by the
        // final Submit), just earlier — before the user leaves the step. Fields that
        // are optional today (Recommendations / Actions, zero staff) stay optional so
        // New and Edit of older records are never blocked. Returns true/false and
        // renders inline messages. No business logic, DB op or field is changed.
        // Field-level messages for the step that failed last (used by showStepError).
        var stepErrors = [];
        function validateStep(n){
            var $pane = $('.wiz-pane[data-step="'+n+'"]');
            stepErrors = [];
            if (n === 1){
                var v = $('#wizardForm').validate();
                var ok = true;
                var labels = {dzongkhag:'Dzongkhag', facility:'Health Facility', dov:'Date of Visit (YYYY-MM-DD)', audit:'Audit Category', evaluator:'Evaluator', designation:'Designation'};
                $pane.find('[name="dzongkhag"],[name="facility"],[name="dov"],[name="audit"],[name="evaluator"],[name="designation"]').each(function(){
                    if (!v.element(this)) { ok = false; stepErrors.push(labels[this.name] || this.name); }
                });
                return ok;
            }
            if (n === 2){ return validateStaffStep($pane); }
            if (n === 3){ return validateChecklistStep($pane); }
            return true;   // steps 4 (Recommendations) & 5 (Actions) have no mandatory fields
        }

        // Staff (dynamic section): at least ONE valid entry (a row with a Staff Name)
        // is required before advancing. Any started row must also carry a name. Blank
        // rows remain ignored on save, so the DB operation / business logic is unchanged.
        function validateStaffStep($pane){
            var ok = true, validRows = 0;
            var $rows = $pane.find('#staffTbody tr');
            $rows.each(function(){
                var $tr   = $(this);
                var $name = $tr.find('input[name="staff_name[]"]');
                $name.removeClass('is-invalid');
                $tr.find('.wiz-inline-err').remove();
                var name = $.trim($name.val()), hasOther = false;
                $tr.find('input[name="staff_designation[]"],input[name="staff_frequency[]"],input[name="staff_days[]"],input[name="staff_ldt[]"],input[name="staff_topic[]"]').each(function(){
                    if ($.trim($(this).val()) !== '') hasOther = true;
                });
                var rowNo = $rows.index($tr) + 1;
                if (name === ''){
                    if (hasOther){   // a started row is missing its name
                        $name.addClass('is-invalid').after('<span class="wiz-inline-err invalid-feedback">Staff Name is required for this row.</span>');
                        ok = false;
                        stepErrors.push('Row ' + rowNo + ': Staff Name is required');
                    }
                } else {
                    validRows++;
                }
                // Optional values must still be valid when entered (same types the save expects).
                $tr.find('input[name="staff_frequency[]"],input[name="staff_days[]"]').each(function(){
                    var $f = $(this), val = $.trim($f.val());
                    $f.removeClass('is-invalid');
                    if (val !== '' && !/^\d+$/.test(val)){
                        $f.addClass('is-invalid').after('<span class="wiz-inline-err invalid-feedback">Enter a whole number (0 or more).</span>');
                        ok = false;
                        stepErrors.push('Row ' + rowNo + ': ' + ($f.attr('name') === 'staff_days[]' ? 'Days' : 'Frequency') + ' must be a whole number');
                    }
                });
                var $ldt = $tr.find('input[name="staff_ldt[]"]'), ldt = $.trim($ldt.val());
                $ldt.removeClass('is-invalid');
                if (ldt !== '' && !(/^\d{4}-\d{2}-\d{2}$/.test(ldt) && moment(ldt, 'YYYY-MM-DD', true).isValid())){
                    $ldt.addClass('is-invalid').closest('td').append('<span class="wiz-inline-err invalid-feedback d-block">Enter a valid date (YYYY-MM-DD).</span>');
                    ok = false;
                    stepErrors.push('Row ' + rowNo + ': Last Training must be a valid date (YYYY-MM-DD)');
                }
            });
            // Mandatory: at least one valid staff entry.
            if (validRows === 0){
                ok = false;
                stepErrors.push('At least one staff member with a Staff Name is required');
                var $firstName = $rows.first().find('input[name="staff_name[]"]');
                if ($firstName.length){
                    $firstName.addClass('is-invalid');
                    if ($firstName.next('.wiz-inline-err').length === 0){
                        $firstName.after('<span class="wiz-inline-err invalid-feedback">At least one staff member with a Staff Name is required.</span>');
                    }
                }
            }
            return ok;
        }

        // Checklist: every item must be rated (Yes / No / NA) — same rule the final
        // Submit already enforces via the required radios, applied per step here.
        function validateChecklistStep($pane){
            var ok = true, $first = null;
            $pane.find('.chk-rating-err').remove();
            $pane.find('.is-invalid-group').removeClass('is-invalid-group');
            $('input[name^="cid"]').each(function(){
                var idx = $(this).attr('name').replace('cid[','').replace(']','');
                if ($('input[name="rating['+idx+']"]:checked').length === 0){
                    var $group = $('input[name="rating['+idx+']"]').first().closest('.form-check-inline');
                    $group.addClass('is-invalid-group');
                    $group.closest('.form-group').append('<span class="chk-rating-err invalid-feedback">Please rate this item.</span>');
                    if ($first === null){ $first = $group; }
                    ok = false;
                    stepErrors.push($.trim($group.closest('.row').find('label.col-form-label-sm').first().text()) || ('Item ' + (parseInt(idx, 10) + 1)));
                }
            });
            if ($first){ try { $first[0].scrollIntoView({block:'center'}); } catch(e){} }
            return ok;
        }

        function showStepError(n){
            var names = ['General Info', 'Staff', 'Checklist', 'Recommendations', 'Actions', 'Review'];
            var msg = 'Please complete all required fields in this step before continuing.';
            if (n === 2){ msg = 'Please add at least one staff member (Add New Entry) with a Staff Name before continuing.'; }
            if (n === 3){ msg = 'Please rate every checklist item (Yes / No / NA) before continuing.'; }
            var list = '';
            if (stepErrors.length){
                var shown = stepErrors.slice(0, 8);
                list = '<ul class="text-left mt-2 mb-0">' + $.map(shown, function(e){ return '<li>' + $('<div>').text(e).html() + '</li>'; }).join('') +
                       (stepErrors.length > shown.length ? '<li>…and ' + (stepErrors.length - shown.length) + ' more</li>' : '') + '</ul>';
            }
            Swal.fire({icon:'warning', title: 'Step ' + n + ': ' + names[n-1], html: $('<div>').text(msg).html() + list, confirmButtonText:'OK'});
        }

        // Forward navigation is gated: every step on the way must be valid & complete.
        // Backward navigation is always allowed and never loses the entered data.
        function gotoStep(target){
            if (target <= wizCurrent){ wizShow(target); return; }
            for (var s = wizCurrent; s < target; s++){
                if (!validateStep(s)){
                    if (s !== wizCurrent){ wizShow(s); }   // land on the first incomplete step
                    showStepError(s);
                    return;
                }
            }
            wizShow(target);
        }

        var MS_READONLY = <?= (!mn_can_write() || ($isEdit && !mn_is_rcdc() && isset($generalqry[5]) && $generalqry[5] !== 'Internal')) ? 'true' : 'false' ?>;
        function wizShow(n){
            if(n<1||n>wizTotal){ return; }
            wizCurrent = n;
            $('.wiz-pane').removeClass('active');
            $('.wiz-pane[data-step="'+n+'"]').addClass('active');
            $('.wiz-step').removeClass('active');
            $('.wiz-step[data-step="'+n+'"]').addClass('active');
            $('#wizProgress').css('width', ((n-1)/(wizTotal-1)*100)+'%');
            $('#wizPrev').prop('disabled', n<=1);
            $('#wizNext').toggle(n<wizTotal);
            // Hidden for read-only roles and for facility users viewing an RCDC External audit
            // (iu-ms-registration-save.php rejects those saves server-side as well).
            $('#wizSubmit').toggle(n===wizTotal && !MS_READONLY);
            // Download Report appears only AFTER a successful submit: on the Review
            // step and only when the saved DB record matches the form (saved & clean).
            // Before submission it stays hidden -> Review -> Submit -> Success -> Download.
            msRefreshCompletedUI();   // also runs markDone()
            if(n===wizTotal){ buildReview(); }
        }

        $('#wizNext').on('click', function(){ gotoStep(Math.min(wizTotal, wizCurrent+1)); });
        $('#wizPrev').on('click', function(){ wizShow(Math.max(1, wizCurrent-1)); });          // backward: always allowed
        $('.wiz-step').on('click', function(){ gotoStep(parseInt($(this).data('step'))); });   // forward jumps are validated
        $('#reviewArea').on('click', '.wizEdit', function(){ wizShow(parseInt($(this).data('goto'))); });  // Edit = jump back

        // Clear inline validation as soon as the user corrects the field.
        $('#staffTbody').on('input', 'input[name="staff_name[]"]', function(){
            $(this).removeClass('is-invalid').closest('tr').find('.wiz-inline-err').remove();
        });
        $(document).on('change', 'input[name^="rating"]', function(){
            $(this).closest('.form-check-inline').removeClass('is-invalid-group')
                   .closest('.form-group').find('.chk-rating-err').remove();
        });

        wizShow(wizCurrent);


        // Make sure staff datepickers are alive when we land on step 2
if (wizCurrent === 2) {
    setTimeout(function(){ initStaffDatePickers(); }, 100);
}

// Also re-init every time the user navigates to the Staff step
var originalWizShow = wizShow;
wizShow = function(n) {
    originalWizShow(n);
    if (n === 2) {
        setTimeout(function(){ initStaffDatePickers(); }, 80);
    }
};

        // ---------- Validation (single form, all steps) ----------
        $('#wizardForm').validate({
            ignore: [],   // validate fields on inactive (hidden) steps too
            rules: {
                dzongkhag: "required",
                facility:  "required",
                dov:       { required: true, dateISO: true },
                audit:     "required",
                evaluator: "required",
                designation:"required"
            },
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group, .form-check-inline, td').append(error);
            },
            highlight: function (element) { $(element).addClass('is-invalid'); },
            unhighlight: function (element) { $(element).removeClass('is-invalid'); },
            invalidHandler: function(event, validator){
                if(validator.errorList.length){
                    var el = validator.errorList[0].element;
                    var step = $(el).closest('.wiz-pane').data('step');
                    if(step){ wizShow(parseInt(step)); }
                    Swal.fire({icon:'warning', text:'Please complete all required fields (including every checklist rating) before submitting.', confirmButtonText:'OK'});
                }
            },
            // AJAX submit: save in place and stay on this page — no redirect.
            submitHandler: function(form){

    var $btn = $('#wizSubmit');

    if ($btn.prop('disabled')) {
        return false;
    }

    // Final gate: every step's required information is re-validated before saving,
    // so nothing can be skipped by jumping between steps. Data on screen is kept.
    for (var vs = 1; vs < wizTotal; vs++) {
        if (!validateStep(vs)) {
            wizShow(vs);
            showStepError(vs);
            return false;
        }
    }

    var orig = $btn.html();

    $btn.prop('disabled', true)
        .html('<i class="fas fa-spinner fa-spin"></i> Saving...');

    // Sync Summernote content
    try {
        // Manual recommendation text only; findings post separately in #findingsInput.
        $('#recommendation').val(
            window.msFullRecommendation ? msFullRecommendation() : $('#recommendation').summernote('code')
        );

        $('#actions').val(
            $('#actions').summernote('code')
        );
    } catch(e) {}

    /*
     * IMPORTANT:
     * FormData is required because the form contains file uploads.
     */
    var formData = new FormData(form);
    formData.append('ajax', '1');

    $.ajax({
        url: form.action,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,

        success: function(resp){

            $btn.prop('disabled', false)
                .html(orig);

            var isSuccess = false;
            var savedGid = null;
            var errorMsg = '';

            if (typeof resp === 'object' && resp !== null) {
                isSuccess = (resp.success === true || resp.ok === true || resp.status === 'success');
                savedGid = resp.id || resp.gid;
                if (!isSuccess) {
                    errorMsg = resp.message || resp.error || '';
                }
            } else if (typeof resp === 'string') {
                try {
                    var parsed = JSON.parse(resp);
                    isSuccess = (parsed.status === 'success' || parsed.success === true || parsed.ok === true);
                    savedGid = parsed.id || parsed.gid;
                    if (!isSuccess) {
                        errorMsg = parsed.message || parsed.error || '';
                    }
                } catch(e) {
                    isSuccess = /Submitted Successfully/i.test(resp);
                    var m = resp.match(/id='lnk'\s+value='([^']+)/);
                    if (m) {
                        var idm = m[1].match(/id=(\d+)/);
                        if (idm) savedGid = idm[1];
                    }
                    if (!isSuccess) {
                        var em = resp.match(/id='saveErr'\s+value='([^']*)'/);
                        errorMsg = em ? $('<textarea>').html(em[1]).text() : '';
                    }
                }
            }

            if (isSuccess) {

                /*
                 * First save of a new record:
                 * adopt generated ID.
                 */
                if (savedGid && $('#gid').val() === '') {
                    $('#gid').val(savedGid);

                    try {
                        history.replaceState(
                            null,
                            '',
                            'ms-registration.php?action=2&id=' + encodeURIComponent(savedGid)
                        );
                    } catch(e) {}
                }

                msSaved = true;
                msDirty = false;
                // The record is now permanently Completed (the submit created/updated its
                // recommendation/action row); keep the user on Review in the final state.
                MS_COMPLETED = true;
                MS_PERSISTED_DONE = [1, 2, 3, 4, 5, 6];

                var gidNow = $('#gid').val() || savedGid;
                if (gidNow) {

                    var pdfUrl =
                        'pdf-ms-registration.php?id=' +
                        encodeURIComponent(gidNow);

                    if ($('#dlReportBtn').length === 0) {

                        $('#msCardTools').prepend(
                            '<a href="' + pdfUrl + '" ' +
                            'id="dlReportBtn" ' +
                            'target="_blank" ' +
                            'class="btn bg-gradient-warning btn-sm">' +
                            '<i class="fas fa-print"></i> ' +
                            'Download Report</a>'
                        );

                    } else {

                        $('#dlReportBtn').attr(
                            'href',
                            pdfUrl
                        );
                    }

                    $('#dlReportBtn').show();
                }

                /*
                 * Clear file input after successful save.
                 */
                $('#actionImages').val('');
                $('.custom-file-label').text(
                    'Choose multiple images'
                );

                // Stay on Review (step 6) in the Completed state — never fall back to Actions.
                wizShow(wizTotal);

                Swal.fire({
                    icon: 'success',
                    title: 'Submitted Successfully',
                    text: 'The M&S record is now saved successfully.',
                    confirmButtonText: 'OK',
                    showCloseButton: true
                }).then(function(){
                    if (gidNow) {
                        location.href = 'ms-registration.php?action=2&id=' + encodeURIComponent(gidNow);
                    } else {
                        location.href = 'ms-list.php';
                    }
                });

            } else {

                // Show the server's validation message when it rejected the data (nothing saved).
                Swal.fire({
                    icon: 'error',
                    confirmButtonText: 'Close',
                    text: 'Submission Failed!! Nothing was saved.' + (errorMsg ? ' ' + errorMsg : ''),
                    showCloseButton: true
                });
            }
        },

        error: function(xhr, textStatus, errorThrown){

            $btn.prop('disabled', false)
                .html(orig);

            console.error("M&S Registration save failed:", textStatus, errorThrown, xhr.status, xhr.responseText);

            var serverMsg = '';
            if (xhr.responseJSON) {
                serverMsg = xhr.responseJSON.message || xhr.responseJSON.error || xhr.responseJSON.msg || '';
            } else if (xhr.responseText) {
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    serverMsg = parsed.message || parsed.error || parsed.msg || '';
                } catch(e) {
                    var m = xhr.responseText.match(/<p[^>]*>([^<]+)<\/p>/i) || xhr.responseText.match(/<title>([^<]+)<\/title>/i);
                    if (m && m[1]) {
                        serverMsg = m[1].replace(/^\d+\s*[—–-]\s*/, '').trim();
                    } else if (xhr.responseText.length > 0 && xhr.responseText.length < 300) {
                        serverMsg = xhr.responseText.trim();
                    }
                }
            }

            var alertText = serverMsg ? serverMsg : 'Network error while saving. Please check your connection and try again.';

            Swal.fire({
                icon: 'error',
                title: 'Save Failed',
                text: alertText,
                confirmButtonText: 'OK',
                showCloseButton: true
            });
        }
    });

    return false;
}
        });
    });

    $(document).ready(function(){
        $(document).on('change', '#dzongkhag', function(){
            var dzongkhag = $(this).val() || '';
            $("#facilityDiv").load("../load-facility.php?dzongkhag=" + encodeURIComponent(dzongkhag));
        });
    });

    /* ---------------------------------------------------------------
       Download Report guard.
       The PDF is built from the SAVED database record. If the wizard
       holds unsaved edits (or nothing has been saved yet), the PDF
       would show stale / missing data. Only allow the download when
       the on-screen form matches what is stored.
    --------------------------------------------------------------- */
    // Saved on load only if we opened an already-persisted record (action=2).
    var msSaved = <?= ($isEdit && $id) ? 'true' : 'false' ?>;
    var msDirty = false;
    // Persisted workflow state computed server-side from the saved record (see $msCompleted).
    var MS_COMPLETED = <?= $msCompleted ? 'true' : 'false' ?>;
    var MS_PERSISTED_DONE = <?= json_encode(array_values($msDoneSteps)) ?>;

    $(function(){
        // Any edit to the wizard marks the form dirty (needs a re-save
        // before the PDF is accurate). Summernote fires 'summernote.change'.
        // Any edit invalidates the saved snapshot: mark dirty AND hide Download
        // Report until the user re-submits (Review -> Submit -> Success -> Download).
        $('#wizardForm').on('input change', ':input', function(){ msDirty = true; $('#dlReportBtn').hide(); if (window.msRefreshCompletedUI) { msRefreshCompletedUI(); } });
        $('#recommendation, #actions').on('summernote.change', function(){ if (window.msSuppressDirty) { return; } msDirty = true; $('#dlReportBtn').hide(); if (window.msRefreshCompletedUI) { msRefreshCompletedUI(); } });

        // Intercept the download unless the saved record is current.
        $(document).on('click', '#dlReportBtn', function(e){
            if (!msSaved || msDirty){
                e.preventDefault();
                Swal.fire({
                    icon: 'info',
                    title: 'Save before downloading',
                    text: 'Please click Submit first so the report reflects your latest changes.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            // The PDF is served as an attachment into a target=_blank tab that
            // closes itself immediately, so nothing visibly changes on screen
            // (in Incognito even the download shelf is easy to miss). Confirm
            // the download so the click never looks like it did nothing.
            Swal.fire({
                icon: 'success',
                toast: true,
                position: 'top-end',
                title: 'Report download started',
                text: "Check your browser's Downloads for Monitoring_Supervisiory_" + $('#gid').val() + ".pdf",
                showConfirmButton: false,
                timer: 4000,
                timerProgressBar: true
            });
        });
        /* =========================================================
   DELETE SAVED ACTION EVIDENCE IMAGE (Admin only)
   ========================================================= */
$(document).on('click', '.delete-action-image', function () {
    var $btn     = $(this);
    var imageId  = parseInt($btn.data('image-id'), 10);
    var $card    = $btn.closest('.action-image-item');
    var gid      = $('#gid').val();

    if (!imageId || isNaN(imageId)) {
        Swal.fire({ icon: 'error', text: 'Invalid image ID.' });
        return;
    }

    Swal.fire({
        icon: 'warning',
        title: 'Delete this evidence?',
        text: 'This will permanently remove the image. This cannot be undone.',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel'
    }).then(function (result) {
        if (!result.isConfirmed) return;

        $btn.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: 'delete-action-image.php',   // new endpoint (see below)
            type: 'POST',
            dataType: 'json',
            data: {
                image_id: imageId,
                gid: gid
            },
            success: function (resp) {
                if (resp && resp.success) {
                    $card.fadeOut(300, function () {
                        $(this).remove();

                        // Update the badge count
                        var $badge = $('.saved-action-evidence .badge-success');
                        var remaining = $('.saved-action-evidence .action-image-item').length;
                        if (remaining === 0) {
                            $('.saved-action-evidence').fadeOut(200, function () {
                                $(this).remove();
                            });
                        } else {
                            $badge.text(remaining + (remaining === 1 ? ' Image' : ' Images'));
                        }
                    });

                    Swal.fire({
                        icon: 'success',
                        toast: true,
                        position: 'top-end',
                        title: 'Image deleted',
                        showConfirmButton: false,
                        timer: 2500
                    });
                } else {
                    $btn.prop('disabled', false)
                        .html('<i class="fas fa-trash-alt mr-1"></i> Delete');
                    Swal.fire({
                        icon: 'error',
                        text: (resp && resp.message) ? resp.message : 'Delete failed.'
                    });
                }
            },
            error: function () {
                $btn.prop('disabled', false)
                    .html('<i class="fas fa-trash-alt mr-1"></i> Delete');
                Swal.fire({
                    icon: 'error',
                    text: 'Network error while deleting the image.'
                });
            }
        });
    });
});


    });
  </script>
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
