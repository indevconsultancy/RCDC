<!-- Check session -->
<?php
require_once("../config/session.php");
require_once("../config/auth.php");
?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php");
  require_once(__DIR__ . "/rdt-feedback-functions.php");
  require_once(__DIR__ . "/action-image-store.php");
?>

<!-- Data and page settings -->
<?php
  // Cast at intake — every SQL below then uses an int-safe $id.
  $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
  $roleID = $_SESSION["roleID"] ?? 0;
  // F1: facility-ownership guard.
  mn_require_rdt_panel_ownership($conn, $id, 'page');
  // Facility users see RCDC answers / findings only after the round has CLOSED
  // (same rule as the Feedback link on panels.php).
  if (!mn_is_rcdc()) {
    $authState = mn_panel_state($conn, 'rdt', $id);
    if (!$authState || !$authState['closed']) { mn_deny('Feedback is released to facilities after the panel round closes.', $id); }
  }
  $panel = ($conn->query("SELECT t2.panel_round, t3.center_name, t2.shipment_date, t1.receive_date FROM rdt_panel_informations t1, rdt_panel_round_informations t2, rcdc_commondb.ms_health_center_table t3 WHERE t1.facility_id=t3.center_id AND t1.round_id=t2.round_id AND t1.panel_id='$id'"));
  $panel = $panel -> fetch_row() ?? array('', '', '', '');
  $lab1 = ($conn->query("SELECT examiner, category, designation, examination_date, kitname, batchlot, manufacturer, expiry_date, sensitivity, specificity, species, avg_score1, avg_score2, final_score, achievement FROM rdt_panel_lab_result_informations WHERE panel_id='$id'"));
  $lab1 = $lab1 -> fetch_row();
  // no lab-result row exists until the Lab Result form is submitted on panels.php;
  // query selects 15 columns (indexes 0-14) - guarantee every index exists and NULLs render as blanks
  if(empty($lab1)) $lab1 = array();
  for($i = 0; $i < 15; $i++){ $lab1[$i] = $lab1[$i] ?? ''; }
  $cancel = "feedbacks.php?id=$id";
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
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>RDT Panel Testing Feedbacks</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="../rdt-panels/panel-list.php">Panel List</a></li>
              <li class="breadcrumb-item"><a href="../rdt-panels/panels.php?id=<?=$id;?>">Panels</a></li>
              <li class="breadcrumb-item active">Feedbacks</li>
            </ol>
          </div>
        </div> 
      </div>
    </section>

    <!-- Feedback -->
    <section class="content">
      <div class="card card-primary">
        <div class="card-header">
          <h3 class="card-title">Panel Information</h3>
        </div>
        <form class="form-horizontal" id="info" autocomplete="off" method="Post" action="iu-feedbacks.php">
          <input type="hidden" id="id" name="id" value="<?=$id;?>">
          <input type="hidden" id="submit" name="submit" value="finding">
          <div class="card-body">
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Panel round</label>
              <div class="col-sm-3">
                <input type="text" class="text-center form-control" value="<?=$panel[0];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Health facility</label>
              <div class="col-sm-3">
                <input type="text" class="form-control" value="<?=$panel[1];?>" readonly>
              </div> 
            </div>
            
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Shipment date</label>
              <div class="col-sm-3">
                <input type="text" class="text-center form-control" value="<?=$panel[2];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Receive date</label>
              <div class="col-sm-3">
                <input type="text" class="text-center form-control" value="<?=$panel[3];?>" readonly>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Examiner</label>
              <div class="col-sm-3">
                <input type="text" class="form-control" value="<?=$lab1[0];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Designation</label>
              <div class="col-sm-3">
                <input type="text" class="form-control" value="<?=$lab1[2];?>" readonly>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Category</label>
              <div class="col-sm-3">
                <input type="text" class="form-control" value="<?=$lab1[1];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Examination date</label>
              <div class="col-sm-3">
                <input type="text" class="text-center form-control" value="<?=$lab1[3];?>" readonly>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Test kit name</label>
              <div class="col-sm-3">
                <input type="text" class="form-control" value="<?=$lab1[4];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Batch/Lot</label>
              <div class="col-sm-3">
                <input type="text" class="form-control" value="<?=$lab1[5];?>" readonly>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Kit manufacturer</label>
              <div class="col-sm-3">
                <input type="text" class="form-control" value="<?=$lab1[6];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Kit expiry data</label>
              <div class="col-sm-3">
                <input type="text" class="form-control text-center" value="<?=$lab1[7];?>" readonly/>
              </div>
            </div>

            <table class="table table-bordered table-hover">
              <thead>
                <tr class="text-center">
                  <th rowspan=2>Panel</th>
                  <th rowspan=2>Receive<br>Status</th>
                  <th colspan=3>Species</th>
                </tr>
                <tr class="text-center">
                  <th>RCDC</th><th>Lab</th><th>Check</th>
                </tr>
              </thead>
              <tbody>
              <?php
                $slide = $conn->query("SELECT slide_id, slide_number, slide_status FROM rdt_panel_slide_informations WHERE panel_id='$id'");
                while($row = $slide->fetch_row()) { 
                  $rcdc = ($conn->query("SELECT rcdc_species FROM rdt_panel_rcdc_results WHERE slide_id='$row[0]'"));
                  $rcdc = $rcdc -> fetch_row() ?? array('');
                  $rcdc[0] = $rcdc[0] ?? '';
                  $lab2qry = "SELECT lab_species, positivity_flag FROM rdt_panel_lab_results WHERE slide_id='$row[0]'";
                  $chkLab = ($conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results WHERE slide_id='$row[0]'"));
                  $chkLab = $chkLab -> fetch_row() ?? array(0);
                  if($conn->query($lab2qry) -> num_rows > 0)  { $lab2 = ($conn->query($lab2qry)); $lab2 = $lab2 -> fetch_row(); }
                  else  $lab2 = array('','','','','');
                  $lab2[0] = $lab2[0] ?? '';
                  $lab2[1] = $lab2[1] ?? '';
              ?>
                <tr>
                  <td><?=$row[1];?></td>
                  <td><?=$row[2];?></td>
                  <td><?=$rcdc[0];?></td><td><?=$lab2[0];?></td><td class='text-center'><?=$lab2[1];?></td>
                </tr>
              <?php } ?>
              </tbody>
            </table><br>
            
            <?php
               $score = ($conn->query("SELECT sensitivity, specificity, species, avg_score1, avg_score2, final_score, achievement, findings, recommendation, action FROM rdt_panel_lab_result_informations WHERE panel_id='$id'"));
               $score = $score -> fetch_row();
               // query selects 10 columns (indexes 0-9); row is absent until lab results are
               // submitted and findings/recommendation/action may be NULL - default to blanks
               if(empty($score)) $score = array();
               for($i = 0; $i < 10; $i++){ $score[$i] = $score[$i] ?? ''; }
               $delete = "<a href='delete.php?id=$id&type=fe' class='btn bg-gradient-danger' onclick='return confirmDelete()'>Delete</a>";
               
               if($score[6] == "Excellent") $achievement = "<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>";
               else if($score[6] == "Very Good") $achievement = "<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>";
               else if($score[6] == "Good") $achievement = "<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>";
                else if($score[6] == "Satisfactory") $achievement = "<i class='fas fa-lg fa-award text-success'></i>";
               else $achievement = "<i class='fas fa-lg fa-thumbs-down text-danger'></i>";

               // Detect legacy placeholder findings that were saved into the DB before this
               // module was made dynamic. The old template always contains the tokens "XXX"/"xxx"
               // and "Nmps", which can never occur in generated findings (real panel numbers are
               // like 251MR01 and species are Pf/Pv/F+V/Negative). Such rows must be regenerated,
               // not displayed.
               $isLegacyFindings = (stripos($score[7], 'xxx') !== false || stripos($score[7], 'Nmps') !== false);

               /* Findings already saved by the PREVIOUS version of this generator are
                  stale too: they carry the standalone species paragraph and have no
                  Specificity section. They are regenerated so the change reaches every
                  record, not only the ones whose Findings are still empty.

                  The test is deliberately narrow - BOTH the exact species markup this
                  generator used to emit AND its Sensitivity header must be present - so
                  it recognises this generator's own output and not a finding somebody
                  typed. A hand-written note would have to reproduce both verbatim to be
                  caught. Anything that does match regenerates from the same panel
                  results it was derived from in the first place. */
               $isSupersededFindings = (
                   preg_match('#<p>\s*<b>\s*(?:PF|PV|PF/PV)\s+Detected\s*</b>\s*</p>#i', (string)$score[7]) === 1
                   && stripos($score[7], 'Performance score in Sensitivity is') !== false
               );

               // Species actually detected according to this panel's current RCDC reference answers.
               $rdtSpeciesLabel = rdtSpeciesDetectedLabel(rdtPanelRcdcSpecies($conn, $id));

               // Default Recommendation from that species finding — only while no Recommendation has
               // been saved, so a saved/edited Recommendation is never overwritten. Still editable.
               if(rdtHtmlIsBlank($score[8]) && $rdtSpeciesLabel !== ''){
                 $score[8] = rdtSpeciesRecommendationHtml($rdtSpeciesLabel);
               }

               // Generate the default Findings dynamically from the actual panel results whenever
               // findings are empty or still hold the legacy placeholder template. A genuinely
               // edited/saved finding (real data, no placeholder tokens) is shown verbatim so
               // manual edits are never overwritten.
               if($score[7] == "" || $isLegacyFindings || $isSupersededFindings){
                 $pos_species = array('Pf', 'Pv', 'F+V');

                 // Pull every tested slide with its RCDC species, Lab species and comparison flags.
                 $findRes = $conn->query("SELECT t2.slide_number, t3.rcdc_species, t1.lab_species, t1.positivity_flag, t1.species_flag FROM rdt_panel_lab_results t1 JOIN rdt_panel_slide_informations t2 ON t1.slide_id=t2.slide_id LEFT JOIN rdt_panel_rcdc_results t3 ON t1.slide_id=t3.slide_id WHERE t2.panel_id='$id' ORDER BY t2.slide_number");

                 $sen_ok = $sen_err = array();   // Sensitivity  (positive panels)
                 $spc_ok = $spc_err = array();   // Specificity  (negative panels)
                 $spi_ok = $spi_err = array();   // Species Identification (positive panels)
                 $rowCount = 0;

                 if($findRes){
                   while($f = $findRes->fetch_row()){
                     $rowCount++;
                     $pnl     = $f[0] ?? '';
                     $rcdc_sp = $f[1] ?? '';
                     $lab_sp  = $f[2] ?? '';
                     $flag    = $f[3] ?? '';
                     $sflag   = $f[4] ?? 0;

                     $isPos = in_array($rcdc_sp, $pos_species);
                     $isNeg = ($rcdc_sp == "Negative");

                     // Sensitivity: how well positive panels were detected as malaria.
                     if($isPos){
                       if($flag == "TP")      $sen_ok[]  = "Panel $pnl correctly detected as malaria seen.";
                       else if($flag == "FN") $sen_err[] = "Panel $pnl reported as False Negative.";
                     }
                     // Specificity: how well negative panels were reported as Negative.
                     if($isNeg){
                       if($flag == "TN")      $spc_ok[]  = "Panel $pnl correctly identified as Negative.";
                       else if($flag == "FP") $spc_err[] = "Panel $pnl reported as False Positive.";
                     }
                     // Species Identification: whether the reported species matched RCDC.
                     if($isPos){
                       if($sflag == 1)        $spi_ok[]  = "Panel $pnl correctly identified as $rcdc_sp.";
                       else if($flag == "FN") $spi_err[] = "Panel $pnl reported as False Negative.";
                       else                   $spi_err[] = "Panel $pnl Species Identification Error (expected $rcdc_sp, reported ".($lab_sp == "" ? "N/A" : $lab_sp).").";
                     }
                   }
                 }

                 if($rowCount == 0){
                   $score[7] = "<p>Findings will be generated after panel results are available.</p>";
                 }
                 else{
                   // Render one <ul> for a section:
                   //  - no panels in this category  -> the section-specific "not applicable" note
                   //  - all correct                 -> success summary + the correct panels only
                   //  - discrepancies               -> correct panels followed by the affected ones
                   // Success and error lists come from the same flags that produced the scores,
                   // so a section can never contradict its own heading (no 100% with errors listed).
                   $renderList = function($ok, $err, $allOkMsg, $naMsg){
                     $out = "<ul>";
                     if(count($ok) == 0 && count($err) == 0){
                       $out .= "<li style='text-align: justify;'>$naMsg</li>";
                     }
                     else if(count($err) == 0){
                       $out .= "<li style='text-align: justify;'>$allOkMsg</li>";
                       foreach($ok as $line)  $out .= "<li style='text-align: justify;'>$line</li>";
                     }
                     else{
                       foreach($ok as $line)  $out .= "<li style='text-align: justify;'>$line</li>";
                       foreach($err as $line) $out .= "<li style='text-align: justify;'>$line</li>";
                     }
                     $out .= "</ul>";
                     return $out;
                   };

                   $score[7]  = "<p><b>Performance score in Sensitivity is $score[0]%</b></p>";
                   $score[7] .= $renderList($sen_ok, $sen_err, "All positive panels were correctly detected as malaria seen.", "No positive panels available for evaluation.");

                   /* Specificity, laid out exactly like Sensitivity above: the score
                      header from the parameters table, then the panel-by-panel
                      breakdown. $spc_ok / $spc_err were already being collected from
                      the TN / FP flags further up - the section simply was never
                      written out, which is why Findings jumped from Sensitivity
                      straight to Species Identification while the table showed a
                      Specificity score. Rendered when the table has a score for it;
                      a 0 is a real score, so only a missing value skips the section,
                      and the bullet underneath explains a panel with no negative
                      slides rather than leaving a bare 0%. */
                   if(isset($score[1]) && $score[1] !== null && $score[1] !== ''){
                     $score[7] .= "<p><b>Performance score in Specificity is $score[1]%</b></p>";
                     $score[7] .= $renderList($spc_ok, $spc_err, "All negative panels were correctly identified as Negative.", "No negative panels available for evaluation.");
                   }

                   /* The standalone "PF / PV / PF/PV Detected" paragraph used to sit
                      here. It has been removed from the Findings box: every other
                      entry in this field is a score header with its own breakdown,
                      and a bare species label among them read as a stray heading.
                      rdtSpeciesFindingHtml() is therefore no longer called, but the
                      species label itself is untouched and still drives the default
                      Recommendation above (rdtSpeciesRecommendationHtml). */
                   $score[7] .= "<p><b>Performance score in Malaria Species Identification is $score[2]%</b></p>";
                   $score[7] .= $renderList($spi_ok, $spi_err, "All positive panels were correctly identified.", "No positive panels available for evaluation.");
                 }
               }

            ?>
            <table class="table table-bordered table-hover">
              <thead>
                <tr class="text-center">
                  <th width="40%">Parameters</th>
                  <th width="10%">Score</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Sensitivity</td><td class="text-center"><?=$score[0];?></td>
                </tr>
                <tr>  
                  <td>Specificity</td><td class="text-center"><?=$score[1];?></td>
                </tr>
                <tr>
                  <td>% of Agreement(Parasite Species Identification)</td><td class="text-center"><?=$score[2];?></td>
                </tr>
                <tr>
                  <td><strong>Average score 1</strong></td><td class="text-center"><strong><?=$score[3];?></strong></td>
                </tr>
                 <tr>
                  <td><strong>Average score 2</strong></td><td class="text-center"><strong><?=$score[4];?></strong></td>
                </tr>
                 <tr>
                  <td><strong>Final score</strong></td><td class="text-center"><strong><?=$score[5];?></strong></td>
                </tr>
                <tr>
                  <td><strong>Achievement</strong></td><td class="text-center"><?=$achievement;?></td>
                </tr>
              </tbody>  
            </table><br>

            <?php
              /* The findings-library picker (Select Findings + Manage Findings)
                 was removed from this screen. The Findings and Recommendations
                 text is generated above from the participant's own results and
                 is unaffected; rdt_panel_feedback_findings is left in place so
                 previously saved selections and the findings-library in-use
                 checks keep working. */
            ?>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Findings</label>
              <div class="col-sm-9">
                <textarea name="finding" id="finding" class="finding"><?=$score[7];?></textarea>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Recommendations</label>
              <div class="col-sm-9">
                <textarea name="recommendation" id="recommendation" class="form-control"><?=$score[8];?></textarea>
              </div>
            </div>

          <?php
          $chkAction = ($conn->query("SELECT panel_id FROM rdt_panel_lab_result_informations WHERE action !='' AND panel_id = $id"));
          $chkAction = $chkAction ? $chkAction->num_rows : 0;
          if(($roleID == 1 || $roleID == 2) && $chkAction==0){
          ?>

          <div class="card-footer">
            <button type="submit" class="btn bg-gradient-success">Submit</button>
            <?=$delete;?>
            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
          </div>
          <?php
          }          
          ?>
        </form>

        <?php
        $deleteAction = "<a href='delete.php?id=$id&type=action' class='btn bg-gradient-danger' onclick='return confirmDelete()'>Delete</a>";
        $chkRecommendation = ($conn->query("SELECT panel_id FROM rdt_panel_lab_result_informations WHERE recommendation !='' AND panel_id = $id"));
        $chkRecommendation = $chkRecommendation ? $chkRecommendation->num_rows : 0;
        
        if ($chkRecommendation > 0) {
          /* Evidence already stored for this panel. Rendered straight into the
             preview so an opened record shows its image on load, with no wait
             for the AJAX list and nothing lost if that request never lands.
             The same rows seed the script below, so its first paint matches. */
          $actImgRows = rdt_action_images_fetch($conn, $id);
          $actImgLast = $actImgRows ? $actImgRows[count($actImgRows) - 1] : null;
          $actImgCanManage = mn_can_write();
        ?>
        <form class="form-horizontal" id="info" autocomplete="off" method="Post" action="iu-feedbacks.php" enctype="multipart/form-data">
          <input type="hidden" id="id" name="id" value="<?=$id;?>">
          <input type="hidden" id="submit" name="submit" value="action">
          <div class="card-body">
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Actions</label>
              <div class="col-sm-9">
                <textarea name="actions" id="actions" class="form-control"><?=$score[9];?></textarea>
              </div>
            </div>
            <!-- Supporting evidence for this Action. Uploaded straight away over AJAX
                 (action-images.php), so it is independent of the Actions text save. -->
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Upload Image</label>
              <div class="col-sm-9">
                <div class="d-flex flex-wrap align-items-center" style="gap:8px;">
                  <input type="file" id="rdtActImgFile" name="action_image" class="form-control-file" accept="image/jpeg,image/png">
                  <button type="button" class="btn btn-sm bg-gradient-primary" id="rdtActImgUpload">
                    <i class="fa fa-upload"></i> Upload
                  </button>
                  <small class="text-muted">JPG or PNG, up to 3&nbsp;MB.</small>
                </div>
                <div id="rdtActImgMsg" class="mt-1"></div>

                <!-- Preview: the saved image when the record has one, otherwise
                     the file chosen in this visit, before it is uploaded. -->
                <div id="rdtActImgPreviewWrap" class="rdt-img-preview mt-2<?= $actImgLast ? '' : ' d-none'; ?>">
                  <div class="rdt-img-preview-card">
                    <img id="rdtActImgPreview" src="<?= $actImgLast ? htmlspecialchars($actImgLast['url'], ENT_QUOTES) : ''; ?>" alt="Selected image preview">
                    <button type="button" class="btn btn-sm btn-danger rdt-img-preview-remove<?= ($actImgLast && !$actImgCanManage) ? ' d-none' : ''; ?>" id="rdtActImgClear">
                      <?php if ($actImgLast) { ?><i class="fa fa-trash"></i> Delete Image<?php } else { ?><i class="fa fa-times"></i> Remove Image<?php } ?>
                    </button>
                  </div>
                  <div class="rdt-img-preview-meta text-muted" id="rdtActImgPreviewMeta"><?php
                    if ($actImgLast) {
                      echo htmlspecialchars($actImgLast['original_name'], ENT_QUOTES)
                         . ' (' . rdt_action_image_size_text($actImgLast['file_size']) . ') &mdash; saved';
                    }
                  ?></div>
                </div>

                <?php /* One saved image is already in the preview above, so the grid
                         would only repeat it; it lists them once there are several.
                         The "No image uploaded." placeholder belongs to the empty
                         case only, and must not contradict a rendered preview. */ ?>
                <div id="rdtActImgGallery" class="rdt-img-grid mt-2<?= (count($actImgRows) <= 1) && $actImgLast ? ' d-none' : ''; ?>"><?php
                  if (!$actImgRows) { echo '<div class="text-muted small">No image uploaded.</div>'; }
                ?></div>
              </div>
            </div>
          </div>
          <button type="submit" class="btn bg-gradient-success">Submit</button>
          <?=$deleteAction;?>
          <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
        </form>
        <?php
        }
        ?>
      </div>
    </section>
    <!-- /.Feedback -->
  </div>
  <!-- /.content-wrapper -->
  <style>
    /* Action evidence thumbnails */
    .rdt-img-grid{ display:flex; flex-wrap:wrap; gap:10px; }
    .rdt-img-card{ width:150px; border:1px solid #dee2e6; border-radius:6px; padding:6px; background:#fff; }
    .rdt-img-card img{ width:100%; height:95px; object-fit:cover; border-radius:4px; display:block; }
    .rdt-img-meta{ font-size:.72rem; margin:4px 0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    /* Preview of the file just chosen (not yet uploaded) */
    .rdt-img-preview-card{
      position:relative; display:inline-block; padding:6px; background:#fff;
      border:1px solid #dee2e6; border-radius:6px;
    }
    .rdt-img-preview-card img{
      display:block; max-height:180px; max-width:250px; object-fit:contain; border-radius:4px;
    }
    .rdt-img-preview-remove{ position:absolute; top:12px; right:12px; box-shadow:0 1px 3px rgba(0,0,0,.3); }
    .rdt-img-preview-meta{ font-size:.72rem; margin-top:4px; }
  </style>
  <script>
    $(document).ready(function(){     
      $('#info').validate({
        rules: {
          dor: "required"
        },
        errorElement: 'span',
        errorPlacement: function (error, element) {
          error.addClass('invalid-feedback');
          element.closest('.form-group').append(error);
        },
        highlight: function (element, errorClass, validClass) {
          $(element).addClass('is-invalid');
        },
        unhighlight: function (element, errorClass, validClass) {
          $(element).removeClass('is-invalid');
        }
      });

      $('#rcdc').validate({
        rules: {
          species: "required",
          doe: "required"
        },
        errorElement: 'span',
        errorPlacement: function (error, element) {
          error.addClass('invalid-feedback');
          element.closest('.form-group').append(error);
        },
        highlight: function (element, errorClass, validClass) {
          $(element).addClass('is-invalid');
        },
        unhighlight: function (element, errorClass, validClass) {
          $(element).removeClass('is-invalid');
        }
      });

      $('#lab').validate({
        rules: {
          examiner: "required",
          designation: "required",
          doe: "required",
          category: "required"
        },
        errorElement: 'span',
        errorPlacement: function (error, element) {
          error.addClass('invalid-feedback');
          element.closest('.form-group').append(error);
        },
        highlight: function (element, errorClass, validClass) {
          $(element).addClass('is-invalid');
        },
        unhighlight: function (element, errorClass, validClass) {
          $(element).removeClass('is-invalid');
        }
      });

    });

    $(function () {
      /* One shared initialiser for all three editors: it builds them only once
         they are on screen, destroys anything left over from a previous render,
         and stops a toolbar press from taking focus off the text being formatted.
         The toolbar itself is unchanged. */
      var MN_FB_TOOLBAR = [
          ['style', ['bold', 'italic', 'underline', 'clear']],
          ['fontsize', ['fontsize']],
          ['color', ['color']],
          ['para', ['ul', 'ol', 'paragraph']]
      ];
      mnSummernote('#finding, #recommendation, #actions', { toolbar: MN_FB_TOOLBAR });
    });

    /* ============ ACTION: SUPPORTING IMAGES ============
       list / upload / delete against action-images.php. The client checks mirror
       the server rules; the server re-validates every file by its real content. */
    (function(){
      var RDT_IMG_PANEL = <?= (int)$id; ?>;
      /* What the server just rendered. Seeding the state means the saved image
         stays on screen (and its Delete works) from the first frame, instead of
         appearing only once the list request comes back. */
      var RDT_IMG_SEED  = <?= json_encode(isset($actImgRows) ? $actImgRows : array()); ?>;
      var RDT_IMG_WRITE = <?= (isset($actImgCanManage) && $actImgCanManage) ? 'true' : 'false'; ?>;
      var RDT_IMG_MAX   = 3 * 1024 * 1024;
      var canManage = false;
      var $gal, $msg;
      var previewUrl = null;                 // object URL of the file being previewed
      var previewMode = 'none';              // none | file (chosen now) | saved (already uploaded)
      var savedPreviewId = 0;                // image_id shown while previewMode === 'saved'
      var savedRows = [];                    // what the server currently holds

      function esc(v){ return $('<div>').text(v == null ? '' : v).html(); }
      function sizeTxt(b){
        b = parseInt(b, 10) || 0;
        return (b >= 1048576) ? (Math.round(b/104857.6)/10)+' MB' : Math.max(1, Math.round(b/1024))+' KB';
      }
      function note(html){ if($msg) $msg.html(html); }

      function render(rows){
        savedRows = rows || [];

        if(!savedRows.length){ $gal.html('<div class="text-muted small">No image uploaded.</div>'); }
        else {
          var h = '';
          $.each(savedRows, function(_, r){
            h += '<div class="rdt-img-card">'+
                   '<a href="'+r.url+'" target="_blank" rel="noopener">'+
                     '<img src="'+r.url+'" alt="'+esc(r.original_name)+'">'+
                   '</a>'+
                   '<div class="rdt-img-meta" title="'+esc(r.original_name)+'">'+esc(r.original_name)+
                     ' <span class="text-muted">('+sizeTxt(r.file_size)+')</span></div>'+
                   (canManage
                     ? '<button type="button" class="btn btn-sm btn-outline-danger btn-block rdtImgDel" data-id="'+r.image_id+'">'+
                       '<i class="fa fa-trash"></i> Delete</button>'
                     : '')+
                 '</div>';
          });
          $gal.html(h);
        }

        // A file chosen in this visit keeps the preview; otherwise the saved image takes it.
        if(previewMode !== 'file'){ paintSaved(); }
      }

      /* Opening a saved record shows its image straight away, in the same
         container a newly chosen file uses. With a single image (the usual case)
         the grid below would only repeat it, so it is hidden; with several, the
         newest is previewed and the grid still lists them all. */
      function paintSaved(){
        if(!savedRows.length){
          previewMode = 'none'; savedPreviewId = 0;
          $('#rdtActImgPreview').attr('src', '');
          $('#rdtActImgPreviewMeta').text('');
          $('#rdtActImgPreviewWrap').addClass('d-none');
          $gal.removeClass('d-none');
          return;
        }

        var r = savedRows[savedRows.length - 1];
        previewMode = 'saved';
        savedPreviewId = r.image_id;

        $('#rdtActImgPreview').attr('src', r.url);
        $('#rdtActImgPreviewMeta').html(esc(r.original_name) + ' (' + sizeTxt(r.file_size) + ') &mdash; saved');
        $('#rdtActImgPreviewWrap').removeClass('d-none');
        $('#rdtActImgClear')
          .toggleClass('d-none', !canManage)
          .html('<i class="fa fa-trash"></i> Delete Image');

        $gal.toggleClass('d-none', savedRows.length <= 1);
      }

      /* ---- preview of the file chosen but not yet uploaded ----
         The gallery below shows what is already saved; while a preview is on
         screen its "No image uploaded." placeholder would contradict it, so the
         gallery is hidden until the preview is cleared or uploaded. */
      function clearPreview(){
        if(previewUrl){ URL.revokeObjectURL(previewUrl); previewUrl = null; }
        $('#rdtActImgPreview').attr('src', '');
        $('#rdtActImgPreviewMeta').text('');
        $('#rdtActImgPreviewWrap').addClass('d-none');
        $gal.removeClass('d-none');
        previewMode = 'none';
        // Dropping a newly chosen file falls back to the saved image, if any.
        paintSaved();
      }

      function showPreview(file){
        if(previewUrl){ URL.revokeObjectURL(previewUrl); }
        previewUrl = URL.createObjectURL(file);
        previewMode = 'file';
        $('#rdtActImgPreview').attr('src', previewUrl);
        $('#rdtActImgPreviewMeta').text(file.name + ' (' + sizeTxt(file.size) + ') — not uploaded yet');
        $('#rdtActImgPreviewWrap').removeClass('d-none');
        $('#rdtActImgClear')
          .removeClass('d-none')
          .html('<i class="fa fa-times"></i> Remove Image');
        $gal.addClass('d-none');             // hides the "No image uploaded." placeholder
      }

      /* Clears the chosen file everywhere: the input itself, the preview and
         any message, so the control returns to its untouched state. */
      function resetChooser(){
        var input = $('#rdtActImgFile')[0];
        if(input){ input.value = ''; }
        clearPreview();
      }

      function load(){
        $.getJSON('action-images.php', { img_action:'list', id: RDT_IMG_PANEL })
         .done(function(resp){
            if(resp && resp.ok){ canManage = !!resp.canManage; render(resp.rows || []); }
         })
         .fail(function(){ $gal.html('<div class="text-danger small">Could not load images.</div>'); });
      }

      $(function(){
        $gal = $('#rdtActImgGallery'); $msg = $('#rdtActImgMsg');
        if(!$gal.length){ return; }          // Action section not shown for this panel
        canManage = RDT_IMG_WRITE;
        render(RDT_IMG_SEED);                // matches the server-rendered preview
        load();                              // then reconcile with the server

        /* Choosing a file previews it immediately, with the same type/size checks
           the Upload button applies (the server re-validates the content anyway). */
        $('#rdtActImgFile').on('change', function(){
          var f = (this.files && this.files.length) ? this.files[0] : null;
          if(!f){ clearPreview(); note(''); return; }

          if(!/^image\/(jpeg|png)$/i.test(f.type)){
            note('<div class="alert alert-warning py-1 px-2 mb-0 small">Choose a JPG or PNG image.</div>');
            resetChooser();
            return;
          }
          if(f.size > RDT_IMG_MAX){
            note('<div class="alert alert-danger py-1 px-2 mb-0 small">That image is larger than 3 MB.</div>');
            resetChooser();
            return;
          }

          note('');
          showPreview(f);
        });

        /* One button, two meanings: drop the file just chosen, or delete the
           saved image it is previewing (same endpoint the grid's Delete uses). */
        $('#rdtActImgClear').on('click', function(){
          if(previewMode === 'saved'){
            if(!savedPreviewId){ return; }
            if(!confirm('Remove this image?')){ return; }
            $.post('action-images.php', { img_action:'delete', id: RDT_IMG_PANEL, image_id: savedPreviewId }, null, 'json')
             .done(function(resp){
                if(resp && resp.ok){ note(''); load(); }
                else { note('<div class="alert alert-danger py-1 px-2 mb-0 small">'+esc((resp && resp.msg) || 'Delete failed.')+'</div>'); }
             })
             .fail(function(){ note('<div class="alert alert-danger py-1 px-2 mb-0 small">Delete failed.</div>'); });
            return;
          }
          resetChooser();
          note('');
        });

        $('#rdtActImgUpload').on('click', function(){
          var input = $('#rdtActImgFile')[0];
          var f = (input && input.files) ? input.files[0] : null;
          if(!f){ note('<div class="alert alert-warning py-1 px-2 mb-0 small">Choose a JPG or PNG image first.</div>'); return; }
          if(f.size > RDT_IMG_MAX){ note('<div class="alert alert-danger py-1 px-2 mb-0 small">That image is larger than 3 MB.</div>'); return; }

          var fd = new FormData();
          fd.append('img_action', 'upload');
          fd.append('id', RDT_IMG_PANEL);
          fd.append('image', f);

          var $b = $(this).prop('disabled', true);
          note('<span class="text-muted small"><i class="fas fa-spinner fa-spin"></i> Uploading&hellip;</span>');
          $.ajax({ url:'action-images.php', type:'POST', data:fd, processData:false, contentType:false, dataType:'json' })
           .done(function(resp){
              $b.prop('disabled', false);
              if(resp && resp.ok){
                note('<div class="alert alert-success py-1 px-2 mb-0 small">Image uploaded.</div>');
                resetChooser();            // the file is saved: drop the pre-upload preview
                load();                    // and show it from the gallery instead
              } else {
                note('<div class="alert alert-danger py-1 px-2 mb-0 small">'+esc((resp && resp.msg) || 'Upload failed.')+'</div>');
              }
           })
           .fail(function(){
              $b.prop('disabled', false);
              note('<div class="alert alert-danger py-1 px-2 mb-0 small">Upload failed.</div>');
           });
        });

        $(document).on('click', '.rdtImgDel', function(){
          if(!confirm('Remove this image?')){ return; }
          $.post('action-images.php', { img_action:'delete', id: RDT_IMG_PANEL, image_id: $(this).data('id') }, null, 'json')
           .done(function(resp){
              if(resp && resp.ok){ load(); }
              else { note('<div class="alert alert-danger py-1 px-2 mb-0 small">'+esc((resp && resp.msg) || 'Delete failed.')+'</div>'); }
           })
           .fail(function(){ note('<div class="alert alert-danger py-1 px-2 mb-0 small">Delete failed.</div>'); });
        });
      });
    })();

    function confirmDelete() {
			if (confirm("Do you really want to DELETE this record?") == true) { return true; } 
			else { return false; }
		}
  </script>                  
<!-- /.page footer -->
<?php require_once("../includes/footer.php"); ?>
