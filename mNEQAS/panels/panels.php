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
  // Record-level authorization (IDOR): facility users may open only their own facility's panel,
  // and a secondary id (slide / lab result) must belong to this panel.
  mn_require_record($conn, 'panel', $id);
  if (isset($_GET['rid'])) {
    $_GET['rid'] = (string)(int)$_GET['rid'];
    $authType = isset($_GET['type']) ? (string)$_GET['type'] : '';
    if ($authType === 're') { mn_require_child($conn, 'panel_slide', (int)$_GET['rid'], $id); }
    if ($authType === 'la') { mn_require_child($conn, 'panel_info', (int)$_GET['rid'], $id); }
  }
  $panel = ($conn->query("SELECT t2.panel_round, t3.center_name, t2.shipment_date, t1.receive_date FROM panel_informations t1, panel_round_informations t2, rcdc_commondb.ms_health_center_table t3 WHERE t1.facility_id=t3.center_id AND t1.round_id=t2.round_id AND t1.panel_id='$id'"));
  $panel = $panel -> fetch_row();
  $cancel = "panels.php?id=$id";
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
            <h1>Microscopy  Panels</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="../panels/panel-list.php">Panel List</a></li>
              <li class="breadcrumb-item active">Panels</li>
            </ol>
          </div>
        </div> 
      </div>
    </section>

    <!-- Panel Information -->
    <section class="content">
      <div class="card card-primary">
        <div class="card-header">
          <h3 class="card-title">Microscopy  Panel Information</h3>
        </div>
        <div class="card-body">
          <?php
            $reporting_flag = "";
            $date_array = ($conn->query("SELECT t2.shipment_date, t1.receive_date, DATE_ADD(t1.receive_date, interval 30 day), t2.closing_date FROM panel_informations t1, panel_round_informations t2 WHERE t1.round_id=t2.round_id AND t1.panel_id='$id'"));
            // receive_date (and so the derived deadline) is NULL until the panel is
            // received; '' keeps the previous "now" meaning without PHP 8.1+ null deprecations.
            $date_array = $date_array -> fetch_row() ?: array(null, null, null, null);
            $today = new DateTime(date('Y-m-d'));
            $shipment = new DateTime($date_array[0] ?? '');
            $receive = new DateTime($date_array[1] ?? '');
            $deadline = new DateTime($date_array[2] ?? '');
            $closing = new DateTime($date_array[3] ?? '');
            
            //closed
            if($today > $closing){  $reporting_flag = "CLOSED";  $clr = "text-danger";  }
            else{
              //open
              if($date_array[1] == "" && $today <= $deadline) {  $reporting_flag = "OPEN";  $clr = "text-success";}
              //ontime
              if($date_array[1] != "" && $today <= $deadline) {  $reporting_flag = "ONTIME";  $clr = "text-success";}
              //late
              if($date_array[1] != "" && $today > $deadline) {  $reporting_flag = "LATE";  $clr = "text-warning";}
            }

            // Hoisted up from inside the info block so both variables are
            // ALWAYS defined by the time downstream sections (Lab Result) test
            // them. Previously $chkLabResult only existed when the info block
            // rendered, and $chkRcdcResult was fine but placed inside the same
            // <?php island — moving both here removes the ordering hazard
            // without changing any query, condition, or downstream logic.
            $chkRcdcResult = ($conn->query("SELECT slide_id FROM panel_slide_informations WHERE slide_id IN(SELECT slide_id FROM panel_rcdc_results) AND  panel_id='$id'"));
            $chkRcdcResult = $chkRcdcResult -> num_rows;
            $chkLabResult = ($conn->query("SELECT COUNT(*) FROM panel_lab_result_informations WHERE panel_id='$id'"));
            $chkLabResult = $chkLabResult -> fetch_row();
          ?>
          <div class="form-group row">
              <label class="col-sm-3 col-form-label <?=$clr;?>">REPORTING DEADLINE</label>
              <label class="col-sm-3 col-form-label <?=$clr;?>"><?=$reporting_flag;?></label>
              <label class="col-sm-3 col-form-label text-danger">Closing Date: <?=$date_array[3];?></label>
            </div>
          <form class="form-horizontal" id="info" autocomplete="off" method="Post" action="iu-panels.php">
            <input type="hidden" id="id" name="id" value="<?=$id;?>">
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

            <?php
            // $chkRcdcResult is now computed at the top of the page (after
            // $date_array) so it is always defined regardless of which sections
            // render. Condition kept identical.
            if ($chkRcdcResult > 0) {
            ?>
            
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Shipment date</label>
              <div class="col-sm-3">
                <input type="text" class="text-center form-control" value="<?=$panel[2];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Receive date(*)</label>
              <div class="col-sm-3 input-group date" data-target-input="nearest">
                <input type="text" class="form-control datetimepicker-input text-center" id="dor" name="dor" value="<?=$panel[3];?>" placeholder="Receive date"/>
                <div class="input-group-append" data-target="#dor" data-toggle="datetimepicker">
                  <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                </div>
              </div>
            </div>

            <table class="table table-bordered table-hover">
              <thead>
                <tr class="text-center">
                  <th>Slide</th>
                  <th>Status</th>
                  <th>Remarks</th>
                </tr>
              </thead>
              <tbody>
              <?php
                $slide = $conn->query("SELECT slide_id, slide_number, slide_status, slide_remarks FROM panel_slide_informations WHERE panel_id='$id'");
                while($row = $slide->fetch_row()) { 
              ?>
                <tr>
                  <td class="text-center">
                    <input type="hidden" name="slide[]" class="text-center" value="<?=$row[0];?>">
                    <?=$row[1];?>
                  </td>
                  <td>
                    <select class="form-control" name="status[]" id="status[]">
                      <option value="<?=$row[2];?>"><?= (($row[2] ?? '') !== '') ? $row[2] : 'Select Slide Status' ?></option>
                      <option value="Intact">Intact</option>
                      <option value="Broken">Broken</option>
                      <option value="Missing">Missing</option>
                    </select>
                  </td>
                  <td>
                    <input type="text" name="remarks[]" class="form-control" value="<?=$row[3];?>" placeholder="Remarks">
                  </td>
                </tr>
              <?php } ?>
              </tbody>
            </table>
          <!-- /.allow update until lab results are entered -->
          <?php
            // $chkLabResult is now computed at the top of the page (after
            // $date_array) so it is always defined regardless of which sections
            // render. Condition kept identical.
            if( $chkLabResult[0] == 0 && ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2 || $_SESSION["roleID"] == 3)){
          ?>
          <div class="card-footer">
            <button type="submit" class="btn bg-gradient-success">Submit</button>
            <a href='delete.php?id=<?=$id;?>&type=rdate&rid=0' onclick='return confirmDelete()'><i class='btn bg-gradient-danger'>Delete</i></a>            
            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
          </div>
          <?php } } else {
            // chkRcdcResult == 0 → RCDC reference hasn't been entered yet.
            // Participants (roleID 3|4|…) legitimately need to wait for RCDC.
            // But Admin/RCDC (1|2) IS the RCDC focal — the old message left them
            // deadlocked. Give them a direct action button that jumps to the
            // RCDC Result section below (already rendered for RCDC roles), so
            // they can start entering true answers immediately.
            if ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) {
              echo '<a href="#" class=""></a>';
            } else {
              echo '<label class="col-sm-3 col-form-label text-danger">Ask RCDC Focal to Add Panel Result</label>';
            }
          } ?>
        </form>
      </div>
    </section>
    <!-- /.Panel Information -->

    <!-- RCDC Result -->
    <?php if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2 || ($_SESSION["roleID"] == 3 && $reporting_flag == "CLOSED")){?>
    <section class="content" id="rcdc-section">
      <div class="card card-primary">
        <div class="card-header">
          <h3 class="card-title">RCDC Result</h3>
        </div>
        <div class="card-body">
          <table class="table table-bordered table-hover">
            <thead>
              <tr class="text-center">
                <th>Slide</th>
                <th>Species</th>
                <th>Stages</th>
                <th>Parasite</th>
                <th>WBC</th>
                <th>Density</th>
                <th>Range</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
            <?php
              $slide = $conn->query("SELECT slide_id, slide_number FROM panel_slide_informations WHERE panel_id='$id'");
              while($row = $slide->fetch_row()) { 
                $chkResult = ($conn->query("SELECT COUNT(*) FROM panel_rcdc_results WHERE slide_id='$row[0]'"));
                $chkResult = $chkResult -> fetch_row();  
                if($chkResult[0] > 0){ $result = ($conn->query("SELECT rcdc_species, rcdc_stages, rcdc_parasite_count, rcdc_wbc_count, rcdc_parasite_density, min_range, max_range FROM panel_rcdc_results WHERE slide_id='$row[0]'")); $result = $result -> fetch_row();}
                else $result = array('', '', '', '', '', '', '');
                // RCDC/Admin (role 1|2) can keep entering / correcting reference
                // answers whenever the round isn't CLOSED — the old rule
                // (reporting_flag == "OPEN") locked them out as soon as
                // receive_date was set, leaving missing slides un-fillable.
                // Delete icon is only shown when a saved row actually exists
                // for the slide, so empty slides render an Edit-only cell.
                // Role 3+/CLOSED remain no-action, exactly as before.
                if (($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) && $reporting_flag != "CLOSED") {
                  $udlink = "<a href='panels.php?id=$id&rid=$row[0]&type=re'><i class='fa fa-edit'></i></a>";
                  if ($chkResult[0] > 0) {
                    $udlink .= " &nbsp <a href='delete.php?id=$id&type=rcdc&rid=$row[0]' onclick='return confirmDelete()'><i class='fa fa-trash text-danger'></i></a>";
                  }
                } else {
                  $udlink = "";
                }
                echo("<tr class='text-center'><td>$row[1]</td><td>$result[0]</td><td>$result[1]</td><td>$result[2]</td><td>$result[3]</td><td>$result[4]</td><td>$result[5] - $result[6]</td><td>$udlink</td></tr>");
              }
            ?>
            </tbody>
          </table><br>
          
          <?php
            if(isset($_GET['rid']) && $_GET['type'] == 're'){
              $rid = $_GET['rid'];
              $slide = ($conn->query("SELECT slide_number FROM panel_slide_informations WHERE slide_id='$rid'"));
              $slide = $slide -> fetch_row();
              $chkResult = ($conn->query("SELECT COUNT(*) FROM panel_rcdc_results WHERE slide_id='$rid'"));
              $chkResult = $chkResult -> fetch_row();  
              if($chkResult[0] > 0){
                $result = ($conn->query("SELECT rcdc_species, rcdc_stages, rcdc_parasite_count, rcdc_wbc_count, rcdc_parasite_density, min_range, max_range FROM panel_rcdc_results WHERE slide_id='$rid'")); $result = $result -> fetch_row();}
              else $result = array('', '', '', '', '', '', '');  
          ?>

          <div class="form-group row">
            <label class="col-sm-12 text-primary col-form-label">Update Panel Result</label>
          </div> 

          <form class="form-horizontal" id="rcdc" autocomplete="off" method="Post" action="iu-results.php">
            <input type="hidden" id="id" name="id" value="<?=$id;?>">
            <input type="hidden" id="rid" name="rid" value="<?=$rid;?>">
            <input type="hidden" id="type" name="type" value="R">
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Slide No.(*)</label>
              <div class="col-sm-3">
                <input type="text" class="text-center form-control" value="<?=$slide[0];?>" placeholder="Slide No" readonly>
              </div>
            </div>
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Plasmodium species(*)</label>
              <div class="col-sm-3">
                <select class="form-control" name="species" id="species" onchange="reqResult(this.value);">
                    <option value="<?=$result[0];?>"><?= (($result[0] ?? '') !== '') ? $result[0] : 'Select Species' ?></option>
                    <option value="Nmps">Nmps</option>
                    <option value="Pf">Pf</option>
                    <option value="Pv">Pv</option>
                    <option value="Po">Po</option>
                    <option value="Pm">Pm</option>
                    <option value="Pk">Pk</option>
                    <option value="F+V">F+V</option>
                  </select>
              </div>
              <label class="col-sm-3 col-form-label-sm">Plasmodium stages(*)</label>
              <div class="col-sm-3">
                <select class="form-control" name="stages" id="stages">
                    <option value="<?=$result[1];?>"><?= (($result[1] ?? '') !== '') ? $result[1] : 'Select Stage' ?></option>
                    <option value="T">T</option>
                    <option value="S">S</option>
                    <option value="G">G</option>
                    <option value="TS">TS</option>
                    <option value="TG">TG</option>
                    <option value="SG">SG</option>
                    <option value="TSG">TSG</option>
                  </select>
              </div>
            </div>
            
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Parasite count(*)</label>
              <div class="col-sm-3">
                <input type="number" id="parasite" name="parasite" class="text-center form-control" value="<?=$result[2];?>" placeholder="Parasite count">
              </div>
              <label class="col-sm-3 col-form-label-sm">WBC count(*)</label>
              <div class="col-sm-3">
                <input type="number" id="wbc" name="wbc" class="text-center form-control" value="<?=$result[3];?>" placeholder="WBC count">
              </div>
            </div>
            
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Density range(*)</label>
              <div class="col-sm-3">
                <input type="number" id="min" name="min" class="text-center form-control" value="<?=$result[5];?>" placeholder="Minimum" readonly>
              </div>
              <div class="col-sm-3">
                <input type="number" id="max" name="max" class="text-center form-control" value="<?=$result[6];?>" placeholder="Maximum" readonly>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Parasite density(*)</label>
              <div class="col-sm-3">
                <input type="number" id="density" name="density" class="text-center form-control" value="<?=$result[4];?>" placeholder="Parasite density" readonly>
              </div>
            </div>

          <div class="card-footer">
            <button type="submit" class="btn bg-gradient-success">Submit</button>
            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
          </div>
        </form>
        <?php } ?>
      </div>
    </section>
    <?php } ?>
    <!-- /.RCDC Result -->

    <!-- Lab Result -->
    <section class="content">
      <div class="card card-primary">
        <div class="card-header">
          <h3 class="card-title">Lab Result</h3>
        </div>
        <div class="card-body">
          <table id="table" class="table table-bordered table-hover">
            <thead>
              <tr class="text-center">
                <th>Action</th>
                <th>Date</th>
                <th>Category</th>
                <th>Examiner</th>
                <th>Avg</th>
                <th>Sens</th>
                <th>Spec</th>
                <th>Inf</th>
                <th>SpecId</th>
                <th>StagId</th>
                <th>DensCal</th>
              </tr>
            </thead>
            <tbody>
            <?php
              $result = $conn->query("SELECT information_id, examination_date, category, examiner, avg_score, sensitivity, specificity, infection, species, stages, density FROM panel_lab_result_informations WHERE panel_id='$id'");
              while($row = $result->fetch_row()) { 
                $udlink = $fdlink = $pdflink = $actionlink = "";
                $avg = $sens = $spec = $inf = $specid = $stagid = $denscal = "";
                $chkRecommendation = ($conn->query("SELECT information_id FROM panel_lab_result_informations WHERE findings !='' AND information_id = $row[0]"));
                $chkRecommendation = $chkRecommendation->num_rows;
                if($reporting_flag != "CLOSED" && ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2 || $_SESSION["roleID"] == 3) && ($chkRecommendation == 0))
                  $udlink = "
                    <a href='panels.php?id=$id&rid=$row[0]&type=la'><i class='fa fa-edit'></i></a> &nbsp 
                    <a href='delete.php?id=$id&type=lab&rid=$row[0]' onclick='return confirmDelete()'><i class='fa fa-trash text-danger'></i></a> &nbsp
                  ";
                if(($reporting_flag == "CLOSED") || ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2)){
                  $avg = $row[4];
                  $sens = $row[5];
                  $spec = $row[6];
                  $inf = $row[7];
                  $specid = $row[8];
                  $stagid = $row[9];
                  $denscal = $row[10];
                  if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2)
                  $fdlink = "<a href='feedbacks.php?id=$id&rid=$row[0]'><i class='fa fa-flag'></i></a> &nbsp";
                  if($chkRecommendation > 0)
                  $actionlink = "<a href='feedbacks.php?id=$id&rid=$row[0]'><i class='fa fa-blind'></i></a> &nbsp";                  
                  
                  $pdflink = "<a href='pdf-feedbacks.php?id=$id&rid=$row[0]' target='_BLANK'><i class='fa fa-download'></i></a> &nbsp";
                }
                   
                echo("<tr class='text-center'><td>$udlink $fdlink $actionlink $pdflink</td><td>$row[1]</td><td>$row[2]</td><td>$row[3]</td><td>$avg</td><td>$sens</td><td>$spec</td><td>$inf</td><td>$specid</td><td>$stagid</td><td>$denscal</td></tr>");
              }
            ?>
            </tbody>
          </table><br>
          
          <!-- hide block after closing date-->
          <?php if($reporting_flag != "CLOSED" && $date_array[1] != "" && ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2 || $_SESSION["roleID"] == 3) && ($chkRcdcResult > 0))  { ?>

          <div class="form-group row">
            <label class="col-sm-12 text-primary col-form-label">Add/Update Panel Result</label>
          </div>

          <form class="form-horizontal" id="lab" autocomplete="off" method="Post" action="iu-results.php">
            <?php
              $lab1 = array('', '', '', '');
              $lab2 = array('', '', '', '', '', '', '');
              if(isset($_GET['rid']) && $_GET['type'] == "la"){
                $rid = $_GET['rid'];
                $lab1 = ($conn->query("SELECT examiner, category, designation, examination_date FROM panel_lab_result_informations WHERE information_id='$rid'"));
                $lab1 = $lab1 -> fetch_row();
                // fetch_row() returns null when no row matches; fall back to the
                // empty-value default so the header fields render blank instead
                // of triggering "array offset on null" warnings (PHP 8+).
                if (!is_array($lab1)) { $lab1 = array('', '', '', ''); }
                echo("<input type='hidden' id='action' name='action' value='2'><input type='hidden' id='rid' name='rid' value='$rid'>");
              }
              else {  
                echo("<input type='hidden' id='action' name='action' value='1'>");
              }
            ?>
            <input type="hidden" id="id" name="id" value="<?=$id;?>">
            <input type="hidden" id="type" name="type" value="L">
            <input type="hidden" id="timeliness" name="timeliness" value="<?=$reporting_flag;?>">
            <table class="table table-bordered table-hover">
              <thead>
              <tr>
                  <td colspan=3>
                    <div class="form-inline">
                      <label class="col-form-label-sm">Examiner(*)</label> &nbsp &nbsp &nbsp &nbsp 
                      <input type="text" name="examiner" id="examiner" size="40" class="form-control" value="<?=$lab1[0];?>" placeholder="Examiner">
                    </div>
                  </td>
                  <td colspan=3>
                    <div class="form-inline">
                      <label class="col-form-label-sm">Category(*)</label> &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp
                      <select class="form-control" name="category" id="category" >
                        <option value="<?=$lab1[1];?>"><?= (($lab1[1] ?? '') !== '') ? $lab1[1] : 'Select Category' ?></option>
                        <option value="Individual">Individual</option>
                        <option value="Group">Group</option>
                      </select>
                    </div>
                  </td>
                </tr>
                <tr>
                <td colspan=3>
                    <div class="form-inline">
                      <label class="col-form-label-sm">Designation(*)</label> &nbsp &nbsp 
                      <input type="text" name="designation" id="designation" size="40" class="form-control" value="<?=$lab1[2];?>" placeholder="Designation">
                    </div>
                  </td>
                  <td colspan=3>
                    <div class="form-inline">
                      <label class="col-form-label-sm">Examination date(*)</label> &nbsp &nbsp 
                      <div class="input-group date" id="doe" data-target-input="nearest">
                        <input type="text" name="doe" class="form-control datetimepicker-input text-center" data-target="#doe" size="10" value="<?=$lab1[3];?>"/>
                        <div class="input-group-append" data-target="#doe" data-toggle="datetimepicker">
                            <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                    </div>
                    </div>
                  </td>
                </tr>  
                <tr class="text-center">
                  <th width="20%">Slide</th>
                  <th width="15%">Species</th>
                  <th width="15%">Stages</th>
                  <th width="15%">Parasite</th>
                  <th width="15%">WBC</th>
                  <th width="20%">Density</th>
                </tr>
              </thead>
              <tbody>
              <?php
                $slide = $conn->query("SELECT slide_id, slide_number FROM panel_slide_informations WHERE slide_status='Intact' AND panel_id='$id'");
                while($row = $slide->fetch_row()) { 
                  if(isset($_GET['rid']) && $_GET['type'] == "la"){ 
                    $lab2 = ($conn->query("SELECT result_id, lab_species, lab_stages, lab_parasite_count, lab_wbc_count, lab_parasite_density FROM panel_lab_results WHERE information_id='$rid' AND slide_id='$row[0]'"));
                    $lab2 = $lab2 -> fetch_row();
                    // A slide can be Intact yet have no saved lab result for this
                    // result-info (e.g. added later). fetch_row() then returns null,
                    // so fall back to the empty-value default: the slide row still
                    // renders with blank Species/Stage/Count/WBC/Density fields
                    // instead of throwing "array offset on null" warnings (PHP 8+).
                    if (!is_array($lab2)) { $lab2 = array('', '', '', '', '', '', ''); }
                    echo("<input type='hidden' name='rsltid[]' value='$lab2[0]'");
                  }  
              ?>
                <tr>
                  <td class="text-center">
                    <input type="hidden" name="slide[]" id="slide[]" class="text-center form-control" value="<?=$row[0];?>">
                    <?=$row[1];?>
                  </td>
                  <td>
                    <select class="form-control" name="species[]" onchange="reqLabResult(this);">
                      <option value="<?=$lab2[1];?>"><?= (($lab2[1] ?? '') !== '') ? $lab2[1] : 'Select Species' ?></option>
                      <option value="Nmps">Nmps</option>
                      <option value="Pf">Pf</option>
                      <option value="Pv">Pv</option>
                      <option value="Po">Po</option>
                      <option value="Pm">Pm</option>
                      <option value="Pk">Pk</option>
                      <option value="F+V">F+V</option>
                    </select>
                  </td>
                  <td>
                    <select class="form-control" name="stages[]">
                      <option value="<?=$lab2[2];?>"><?= (($lab2[2] ?? '') !== '') ? $lab2[2] : 'Select Stage' ?></option>
                      <option value="T">T</option>
                      <option value="S">S</option>
                      <option value="G">G</option>
                      <option value="TS">TS</option>
                      <option value="TG">TG</option>
                      <option value="SG">SG</option>
                      <option value="TSG">TSG</option>
                    </select>
                  </td>
                  <td>
                    <input type="number" name="parasite[]" class="text-center form-control" placeholder="Count" value="<?=$lab2[3];?>">
                  </td>
                  <td>
                    <input type="number" name="wbc[]" class="text-center form-control" placeholder="WBC" value="<?=$lab2[4];?>">
                  </td>
                  <td>
                    <input type="number" name="density[]" class="text-center form-control" placeholder="Density" value="<?=$lab2[5];?>" readonly>
                  </td>
                </tr>
              <?php } ?>
              </tbody>
            </table>
            <div class="card-footer">
              <button type="submit" class="btn bg-gradient-success">Submit</button>
              <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
            </div>
          </form>
        <?php } ?>  
        <!-- /. hide block after closing date -->
        </div>
      </section>
      <!-- /.Lab Result -->
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
        submitHandler: function (form) {
          // re-enable disabled Nmps stage selects so every slide row is
          // submitted, keeping the stages[] array aligned with slide[]/species[]
          $(form).find("select[name='stages[]']").prop("disabled", false);
          form.submit();
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
        // Excel + PDF exports skip the first column (Action — icons only, always
        // empty in export output). Column stays visible in the on-screen UI;
        // `exportOptions.columns` is a DataTables Buttons override that scopes
        // the exclusion to the export files only. `colvis` is untouched so users
        // can still toggle Action visibility on-screen.
        "buttons": [
          { extend: "excel", exportOptions: { columns: ":not(:first-child)" } },
          { extend: "pdf",   exportOptions: { columns: ":not(:first-child)" } },
          "colvis"
        ]
      }).buttons().container().appendTo('#table_wrapper .col-md-6:eq(0)');
      $('#dor').datetimepicker({  format: 'YYYY-MM-DD',});
      $('#doe').datetimepicker({  format: 'YYYY-MM-DD',});

      // apply the Nmps state to lab rows already saved as negative
      $("#lab select[name='species[]']").each(function(){
        if(this.value == "Nmps") reqLabResult(this);
      });
    });

    function reqResult(species){
      if(species != "Nmps"){  
        $("#stages").prop("required", true);
        $("#parasite").prop("required", true); 
        $("#wbc").prop("required", true); 
        // $("#min").prop("required", true);
        // $("#max").prop("required", true);
        // $("#density").prop("required", true);
      }
      else{  
        $("#stages").prop("required", false); $("#stages").val("");
        $("#parasite").prop("required", false); $("#parasite").val("");
        $("#wbc").prop("required", false); $("#wbc").val("");
        $("#density").val("");  $("#min").val("");  $("#max").val("");
        // $("#min").prop("required", false); $("#min").val("");
        // $("#max").prop("required", false); $("#max").val("");
        // $("#density").prop("required", false); $("#density").val("");
      }
    }
    
    // Row-scoped Nmps toggle for the Lab Result slide rows (mirrors reqResult())
    function reqLabResult(sel){
      var row      = $(sel).closest('tr');
      var stages   = row.find("select[name='stages[]']");
      var parasite = row.find("input[name='parasite[]']");
      var wbc      = row.find("input[name='wbc[]']");
      var density  = row.find("input[name='density[]']");
      if(sel.value == "Nmps"){
        //negative: zero the counts, clear/disable stages, drop required
        stages.prop("required", false).val("").prop("disabled", true);
        parasite.prop("required", false).val(0).attr("readonly", true);
        wbc.prop("required", false).val(0).attr("readonly", true);
        density.val(0);
      }
      else{
        //positive: enable all fields and keep them required
        stages.prop("disabled", false).prop("required", true);
        parasite.attr("readonly", false).prop("required", true);
        wbc.attr("readonly", false).prop("required", true);
      }
    }

    function confirmDelete() {
			if (confirm("Do you really want to DELETE this record?") == true) { return true; }
			else { return false; }
		}
  </script>                  
<!-- /.page footer -->
<?php require_once("../includes/footer.php"); ?>
