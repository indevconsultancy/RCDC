<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php"); 
?>

<!-- Data and page settings -->
<?php
  $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
  $rid = isset($_GET["rid"]) ? (int)$_GET["rid"] : 0;
  // Record-level authorization: own facility's panel, the lab result must belong to it, and
  // facility users see RCDC answers / scores / findings only after the round has CLOSED.
  mn_require_record($conn, 'panel', $id);
  mn_require_child($conn, 'panel_info', $rid, $id);
  if (!mn_is_rcdc()) {
    $authState = mn_panel_state($conn, 'micro', $id);
    if (!$authState || !$authState['closed']) { mn_deny('Feedback is released to facilities after the panel round closes.', $id); }
  }
  $panel =($conn->query("SELECT t2.panel_round, t3.center_name, t2.shipment_date, t1.receive_date FROM panel_informations t1, panel_round_informations t2, rcdc_commondb.ms_health_center_table t3 WHERE t1.facility_id=t3.center_id AND t1.round_id=t2.round_id AND t1.panel_id='$id'"));
  $panel = $panel -> fetch_row();
  if (!is_array($panel)) { $panel = array('', '', '', ''); }
  $lab1 = ($conn->query("SELECT examiner, designation, category, examination_date FROM panel_lab_result_informations WHERE information_id='$rid'"));
  $lab1 = $lab1 -> fetch_row();
  if (!is_array($lab1)) { $lab1 = array('', '', '', ''); }
  $cancel = "feedbacks.php?id=$id&rid=$rid";
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
            <h1>Feedbacks</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="../panels/panel-list.php">Panel List</a></li>
              <li class="breadcrumb-item"><a href="../panels/panels.php?id=<?=$id;?>">Panels</a></li>
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
          <input type="hidden" id="rid" name="rid" value="<?=$rid;?>">
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
                <input type="text" class="form-control" value="<?=$lab1[1];?>" readonly>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Category</label>
              <div class="col-sm-3">
                <input type="text" class="form-control" value="<?=$lab1[2];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Examination date</label>
              <div class="col-sm-3">
                <input type="text" class="text-center form-control" value="<?=$lab1[3];?>" readonly>
              </div>
            </div>

            <table class="table table-bordered table-hover">
              <thead>
                <tr class="text-center">
                  <th rowspan=2>Slide<br>#</th>
                  <th rowspan=2>Receive<br>Status</th>
                  <th colspan=3>Species</th>
                  <th colspan=2>Stages</th>
                  <th colspan=2>Parasite</th>
                  <th colspan=2>WBC</th>
                  <th rowspan=2>Density<br>Ranges</th>
                  <th colspan=2>Density</th>
                </tr>
                <tr class="text-center">
                  <th>RCDC</th><th>Lab</th><th>Chk</th>
                  <th>RCDC</th><th>Lab</th>
                  <th>RCDC</th><th>Lab</th>
                  <th>RCDC</th><th>Lab</th>
                  <th>RCDC</th><th>Lab</th>
                </tr>
              </thead>
              <tbody>
              <?php
                // Render a cell value, or a neutral placeholder when data is missing
                if (!function_exists('fbCell')) {
                  function fbCell($v){ return ($v === null || $v === '') ? '-' : htmlspecialchars($v, ENT_QUOTES); }
                }

                // Collections used to build dynamic findings/recommendations from real data only
                $fnSlides = array();          // positive slide read as Nmps  (False Negative)
                $fpSlides = array();          // negative slide read as positive (False Positive)
                $idErrSlides = array();       // positive slide, wrong species (Identification Error)
                $speciesCorrect = array();    // positive slide, correct species
                $stageErrSlides = array();    // correct species but wrong stage
                $densityOutSlides = array();  // positive slide density outside reference range

                $slide = $conn->query("SELECT slide_id, slide_number, slide_status FROM panel_slide_informations WHERE panel_id='$id'");
                while($row = $slide->fetch_row()) {
                  $rcdc = ($conn->query("SELECT rcdc_species, rcdc_stages, rcdc_parasite_count, rcdc_wbc_count, rcdc_parasite_density, min_range, max_range FROM panel_rcdc_results WHERE slide_id='$row[0]'"));
                  $rcdc = $rcdc -> fetch_row();
                  // no RCDC reference row for this slide -> neutral defaults (avoids null offsets)
                  if (!is_array($rcdc)) { $rcdc = array(null, null, null, null, null, null, null); }

                  $lab2qry = "SELECT lab_species, lab_stages, lab_parasite_count, lab_wbc_count, lab_parasite_density, positivity_flag FROM panel_lab_results WHERE information_id='$rid' AND slide_id='$row[0]'";
                  $lab2res = $conn->query($lab2qry);
                  // 6-element fallback so the positivity_flag (index 5) is always defined
                  $lab2 = ($lab2res && $lab2res->num_rows > 0) ? $lab2res->fetch_row() : array('','','','','','');
                  if (!is_array($lab2)) { $lab2 = array('','','','','',''); }

                  // ----- classify this slide for dynamic feedback (from the stored positivity flag) -----
                  $rSpecies = $rcdc[0]; $lSpecies = $lab2[0]; $flag = $lab2[5];
                  if ($flag === 'FN') {
                    $fnSlides[] = $row[1];
                  } else if ($flag === 'FP') {
                    $fpSlides[] = array('slide'=>$row[1], 'lab'=>$lSpecies);
                  } else if ($flag === 'TP') {
                    if ($rSpecies === $lSpecies) {
                      $speciesCorrect[] = array('slide'=>$row[1], 'species'=>$lSpecies);
                      if ($rcdc[1] !== null && $rcdc[1] !== '' && $rcdc[1] !== $lab2[1]) {
                        $stageErrSlides[] = array('slide'=>$row[1], 'rcdc'=>$rcdc[1], 'lab'=>$lab2[1]);
                      }
                    } else {
                      $idErrSlides[] = array('slide'=>$row[1], 'rcdc'=>$rSpecies, 'lab'=>$lSpecies);
                    }
                    if (is_numeric($lab2[4]) && is_numeric($rcdc[5]) && is_numeric($rcdc[6]) && ($rcdc[5] > 0 || $rcdc[6] > 0)
                        && ((float)$lab2[4] < (float)$rcdc[5] || (float)$lab2[4] > (float)$rcdc[6])) {
                      $densityOutSlides[] = array('slide'=>$row[1], 'lab'=>$lab2[4], 'min'=>$rcdc[5], 'max'=>$rcdc[6]);
                    }
                  }

                  $densityRange = ($rcdc[5] === null && $rcdc[6] === null) ? '-' : (fbCell($rcdc[5]).' - '.fbCell($rcdc[6]));
              ?>
                <tr>
                  <td><?=fbCell($row[1]);?></td>
                  <td><?=fbCell($row[2]);?></td>
                  <td><?=fbCell($rcdc[0]);?></td><td><?=fbCell($lab2[0]);?></td><td class='text-center'><?=fbCell($lab2[5]);?></td>
                  <td><?=fbCell($rcdc[1]);?></td><td><?=fbCell($lab2[1]);?></td>
                  <td class='text-center'><?=fbCell($rcdc[2]);?></td><td class='text-center'><?=fbCell($lab2[2]);?></td>
                  <td class='text-center'><?=fbCell($rcdc[3]);?></td><td class='text-center'><?=fbCell($lab2[3]);?></td>
                  <td class='text-center'><?=$densityRange;?></td>
                  <td class='text-center'><?=fbCell($rcdc[4]);?></td><td class='text-center'><?=fbCell($lab2[4]);?></td>
                </tr>
              <?php } ?>
              </tbody>
            </table><br>
            
            <?php
               $score = ($conn->query("SELECT sensitivity, specificity, infection, species, stages, density, avg_score, achievement, findings, recommendation, action FROM panel_lab_result_informations WHERE information_id='$rid'"));
               $score = $score -> fetch_row();
               if (!is_array($score)) { $score = array_fill(0, 11, ''); }
               $delete = "<a href='delete.php?id=$id&rid=$rid&type=fe' class='btn bg-gradient-danger' onclick='return confirmDelete()'>Delete</a>";

               if($score[7] == "Excellent") $achievement = "<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>";
               else if($score[7] == "Very Good") $achievement = "<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>";
               else if($score[7] == "Good") $achievement = "<i class='fas fa-lg fa-award text-success'></i>";
               else $achievement = "<i class='fas fa-lg fa-thumbs-down text-danger'></i>";

               // ---- Dynamic findings built from the participant's actual results (no hardcoded placeholders) ----
               if ($score[8] == "") {
                 $f  = "<p><b>Sensitivity score is {$score[0]}%</b></p><ul>";
                 if (empty($fnSlides)) {
                   $f .= "<li style='text-align: justify;'>All positive slides were correctly detected as malaria positive.</li>";
                 } else {
                   foreach ($fnSlides as $sn) { $f .= "<li style='text-align: justify;'>Slide No {$sn}: positive slide misread as Nmps &mdash; reported as False Negative.</li>"; }
                 }
                 $f .= "</ul>";

                 $f .= "<p><b>Specificity score is {$score[1]}%</b></p><ul>";
                 if (empty($fpSlides)) {
                   $f .= "<li style='text-align: justify;'>All negative slides were correctly identified as Nmps.</li>";
                 } else {
                   foreach ($fpSlides as $fp) { $lbl = ($fp['lab'] !== null && $fp['lab'] !== '') ? $fp['lab'] : 'a positive species'; $f .= "<li style='text-align: justify;'>Slide No {$fp['slide']}: Nmps slide misread as {$lbl} &mdash; False Positive reported.</li>"; }
                 }
                 $f .= "</ul>";

                 $f .= "<p><b>Malaria Infection Detection score is {$score[2]}%</b></p><ul>";
                 if (count($fnSlides) + count($fpSlides) === 0) {
                   $f .= "<li style='text-align: justify;'>All positive and negative slides in the panel were detected correctly.</li>";
                 } else {
                   $f .= "<li style='text-align: justify;'>".count($fnSlides)." false negative(s) and ".count($fpSlides)." false positive(s) were reported across the panel.</li>";
                 }
                 $f .= "</ul>";

                 $f .= "<p><b>Species Identification score is {$score[3]}%</b></p><ul>";
                 foreach ($speciesCorrect as $sc) { $f .= "<li style='text-align: justify;'>Slide No {$sc['slide']}: identified correctly as {$sc['species']}.</li>"; }
                 foreach ($fnSlides as $sn)       { $f .= "<li style='text-align: justify;'>Slide No {$sn}: positive slide misread as Nmps &mdash; False Negative.</li>"; }
                 foreach ($idErrSlides as $ie)    { $f .= "<li style='text-align: justify;'>Slide No {$ie['slide']}: {$ie['rcdc']} slide misread as {$ie['lab']} &mdash; Identification Error.</li>"; }
                 if (empty($speciesCorrect) && empty($fnSlides) && empty($idErrSlides)) { $f .= "<li style='text-align: justify;'>No positive slides were available for species identification in this panel.</li>"; }
                 $f .= "</ul>";

                 $f .= "<p><b>Stage Identification score is {$score[4]}%</b></p><ul>";
                 if (empty($stageErrSlides) && empty($fnSlides) && empty($idErrSlides)) {
                   $f .= "<li style='text-align: justify;'>All malaria parasite stages were identified correctly.</li>";
                 } else {
                   foreach ($stageErrSlides as $se) { $f .= "<li style='text-align: justify;'>Slide No {$se['slide']}: stage reported as '{$se['lab']}' but the reference stage is '{$se['rcdc']}'.</li>"; }
                   foreach ($fnSlides as $sn)       { $f .= "<li style='text-align: justify;'>Slide No {$sn}: false negative reported &mdash; stage identification score remains zero.</li>"; }
                   foreach ($idErrSlides as $ie)    { $f .= "<li style='text-align: justify;'>Slide No {$ie['slide']}: identification error reported &mdash; stage identification score remains zero.</li>"; }
                 }
                 $f .= "</ul>";

                 $f .= "<p><b>Parasite Density score is {$score[5]}%</b></p><ul>";
                 if (empty($densityOutSlides)) {
                   $f .= "<li style='text-align: justify;'>Parasite counts in all positive panel slides were within &plusmn;50% of the reference laboratory's true value.</li>";
                 } else {
                   foreach ($densityOutSlides as $de) {
                     $rel = ((float)$de['lab'] < (float)$de['min']) ? 'lower' : 'higher';
                     $f .= "<li style='text-align: justify;'>Slide No {$de['slide']}: parasite density {$de['lab']} /&micro;l is {$rel} than the reference range ({$de['min']} - {$de['max']} /&micro;l).</li>";
                   }
                 }
                 $f .= "</ul>";

                 $score[8] = $f;
               }

               // ---- Dynamic recommendations tied to the specific issues detected ----
               if ($score[9] == "") {
                 $r = "<ul>";
                 if (!empty($fnSlides))         { $r .= "<li style='text-align: justify;'>Improve detection of low-density positives to avoid false negatives; review thick-film examination technique and reading time.</li>"; }
                 if (!empty($fpSlides))         { $r .= "<li style='text-align: justify;'>Examine negative slides more carefully to avoid false positives; differentiate artefacts and platelets from parasites.</li>"; }
                 if (!empty($idErrSlides))      { $r .= "<li style='text-align: justify;'>Revise malaria species morphology to improve species identification accuracy.</li>"; }
                 if (!empty($stageErrSlides))   { $r .= "<li style='text-align: justify;'>Review parasite stage morphology (trophozoite / schizont / gametocyte) for accurate stage reporting.</li>"; }
                 if (!empty($densityOutSlides)) { $r .= "<li style='text-align: justify;'>Follow the standard parasite-versus-WBC counting method to keep density within &plusmn;50% of the reference value.</li>"; }
                 if ($r === "<ul>")             { $r .= "<li style='text-align: justify;'>Maintain the current high standard of microscopy and continue routine internal quality control.</li>"; }
                 $r .= "</ul>";
                 $score[9] = $r;
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
                  <td>% of Agreement(Malaria Infection Detection)</td><td class="text-center"><?=$score[2];?></td>
                </tr>
                <tr>
                  <td>% of Agreement(Parasite Species Identification)</td><td class="text-center"><?=$score[3];?></td>
                </tr>
                <tr>  
                  <td>% of Agreement(Parasite Stage Identification)</td><td class="text-center"><?=$score[4];?></td>
                </tr>
                <tr>
                  <td>% of Agreement(Parasite Density Calculation)</td><td class="text-center"><?=$score[5];?></td>
                </tr>
                <tr>
                  <td><strong>Average score</strong></td><td class="text-center"><strong><?=$score[6];?></strong></td>
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
                 is unaffected; panel_feedback_findings is left in place so
                 previously saved selections and the findings-library in-use
                 checks keep working. */
            ?>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Findings</label>
              <div class="col-sm-9">
                <textarea name="finding" id="finding" class="finding"><?=$score[8];?></textarea>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Recommendations</label>
              <div class="col-sm-9">
                <textarea name="recommendation" id="recommendation" class="form-control"><?=$score[9];?></textarea>
              </div>
            </div>

            <!-- <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Actions</label>
              <div class="col-sm-9">
                <textarea name="actions" id="actions" class="form-control"><?=$score[10];?></textarea>
              </div>
            </div> -->

          <?php
          $chkAction = ($conn->query("SELECT information_id FROM panel_lab_result_informations WHERE action !='' AND information_id = $rid"));
          $chkAction = $chkAction->num_rows;
          if(($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) && $chkAction==0){
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
        $deleteAction = "<a href='delete.php?id=$id&rid=$rid&type=action' class='btn bg-gradient-danger' onclick='return confirmDelete()'>Delete</a>";
        $chkRecommendation = ($conn->query("SELECT information_id FROM panel_lab_result_informations WHERE recommendation !='' AND information_id = $rid"));
        $chkRecommendation = $chkRecommendation->num_rows;
        
        if ($chkRecommendation > 0) {
        ?>
        <form class="form-horizontal" id="info" autocomplete="off" method="Post" action="iu-feedbacks.php">
          <input type="hidden" id="id" name="id" value="<?=$id;?>">
          <input type="hidden" id="rid" name="rid" value="<?=$rid;?>">
          <input type="hidden" id="submit" name="submit" value="action">
          <div class="card-body">
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Actions</label>
              <div class="col-sm-9">
                <textarea name="actions" id="actions" class="form-control"><?=$score[10];?></textarea>
              </div>
            </div>
          </div>

          <div class="card-footer">
            <button type="submit" class="btn bg-gradient-success">Submit</button>
            <?=$deleteAction;?>
            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
          </div>
        </form>
        <?php
        }
        ?>
      </div>
    </section>
    <!-- /.Feedback -->
  </div>
  <!-- /.content-wrapper -->
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
      $("#table").DataTable({
        "responsive": true, "lengthChange": false, "autoWidth": false,
        "buttons": ["excel", "pdf", "colvis"]
      }).buttons().container().appendTo('#table_wrapper .col-md-6:eq(0)');
      // #dor and #doe datetimepicker inits removed — the Feedbacks page has no
      // #dor/#doe elements (all date fields on this page are read-only display).
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

    function reqResult(species){
      if(species != "Nmps"){  
        $("#stages").prop("required", true);
        $("#parasite").prop("required", true); 
        $("#wbc").prop("required", true); 
        $("#min").prop("required", true);
        $("#max").prop("required", true);  
      }
      else{  
        $("#stages").prop("required", false); $("#stages").val("");
        $("#parasite").prop("required", false); $("#parasite").val("");
        $("#wbc").prop("required", false); $("#wbc").val("");
        $("#density").val("");
        $("#min").prop("required", false); $("#min").val("");
        $("#max").prop("required", false); $("#max").val("");
      }
    }
    
    function confirmDelete() {
			if (confirm("Do you really want to DELETE this record?") == true) { return true; } 
			else { return false; }
		}
  </script>                  
<!-- /.page footer -->
<?php require_once("../includes/footer.php"); ?>
