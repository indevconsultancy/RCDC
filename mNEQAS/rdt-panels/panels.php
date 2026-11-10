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
?>

<!-- Data and page settings -->
<?php
  // Cast at intake — every SQL below then uses an int-safe $id (defense in depth
  // even though the ownership guard already verified it resolves to a real row).
  $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
  $roleID = $_SESSION["roleID"] ?? 0;
  // F1: facility-ownership guard. RCDC/Admin bypass; participants (3/4/…) can
  // only open panels belonging to their own facilityID.
  mn_require_rdt_panel_ownership($conn, $id, 'page');
  $panel = ($conn->query("SELECT t2.panel_round, t3.center_name, t2.shipment_date, t1.receive_date FROM rdt_panel_informations t1, rdt_panel_round_informations t2, rcdc_commondb.ms_health_center_table t3 WHERE t1.facility_id=t3.center_id AND t1.round_id=t2.round_id AND t1.panel_id='$id'"));
  $panel = $panel -> fetch_row() ?? array('', '', '', '');
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
            <h1>RDT Panels</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="../rdt-panels/panel-list.php">Panel List</a></li>
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
          <h3 class="card-title">RDT Panel Information</h3>
        </div>
        <div class="card-body">
          <?php
            $reporting_flag = "";
            $clr = "";
            $date_array = ($conn->query("SELECT t2.shipment_date, t1.receive_date, DATE_ADD(t1.receive_date, interval 30 day), t2.closing_date FROM rdt_panel_informations t1, rdt_panel_round_informations t2 WHERE t1.round_id=t2.round_id AND t1.panel_id='$id'"));
            $date_array = $date_array -> fetch_row() ?? array('', '', '', '');
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

          ?>
          <div class="form-group row">
              <label class="col-sm-3 col-form-label <?=$clr;?>">REPORTING DEADLINE</label>
              <label class="col-sm-3 col-form-label <?=$clr;?>"><?=$reporting_flag;?></label>
              <label class="col-sm-3 col-form-label text-danger">Closing Date: <?=$date_array[3];?></label>
            </div>
          <form class="form-horizontal" id="info" autocomplete="off" method="Post" action="iu-panel-save.php">
            <input type="hidden" id="id" name="id" value="<?=$id;?>">
            <input type="hidden" name="info_submit" value="1">
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
            $chkRcdcResult = ($conn->query("SELECT slide_id FROM rdt_panel_slide_informations WHERE slide_id IN(SELECT slide_id FROM rdt_panel_rcdc_results) AND  panel_id='$id'"));
            $chkRcdcResult = $chkRcdcResult -> num_rows;
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
                  <th>Panel#</th>
                  <th>Status</th>
                  <th>Remarks</th>
                </tr>
              </thead>
              <tbody>
              <?php
                $slide = $conn->query("SELECT slide_id, slide_number, slide_status, slide_remarks FROM rdt_panel_slide_informations WHERE panel_id='$id'");
                while($row = $slide->fetch_row()) { 
              ?>
                <tr>
                  <td class="text-center">
                    <input type="hidden" name="info_slide[]" class="text-center" value="<?=$row[0];?>">
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
            $chkLabResult = ($conn->query("SELECT COUNT(*) FROM rdt_panel_lab_result_informations WHERE panel_id='$id'"));
$chkLabResult = $chkLabResult -> fetch_row() ?? array(0);

// Admin (roleID == 1) can always edit/delete receipt even after lab results exist.
// Other roles keep original rule: only while no lab results exist.
$canEditReceipt = ($roleID == 1) || ($chkLabResult[0] == 0 && ($roleID == 2 || $roleID == 3));

if ($canEditReceipt) {
?> 
  <button type="submit" class="btn bg-gradient-success">Submit</button>
  <a href='delete.php?id=<?=$id;?>&type=rdate&rid=0' onclick='return confirmDelete()'><i class='btn bg-gradient-danger'>Delete</i></a>            
  <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
<?php 
} 
} else {
  echo '<label class="col-sm-3 col-form-label text-danger">Ask RCDC Focal to Add Panel Result<?=$date_array[3];?></label>';
}
?>
        </form>
      </div>
    </section>
    <!-- /.Panel Information -->

    <!-- RCDC Result -->
    <?php if($roleID == 1 || $roleID == 2 || ($roleID == 3 && $reporting_flag == "CLOSED")){?>
    <section class="content">
      <div class="card card-primary">
        <div class="card-header">
          <h3 class="card-title">RCDC Result</h3>
        </div>
        <div class="card-body">
          <table class="table table-bordered table-hover">
            <thead>
              <tr class="text-center">
                <th>Panel</th>
                <th>Species</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
            <?php
              $slide = $conn->query("SELECT slide_id, slide_number FROM rdt_panel_slide_informations WHERE panel_id='$id'");
              while($row = $slide->fetch_row()) { 
                $chkResult = ($conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results WHERE slide_id='$row[0]'"));
                $chkResult = $chkResult -> fetch_row() ?? array(0);
                if($chkResult[0] > 0){ $result = ($conn->query("SELECT rcdc_species FROM rdt_panel_rcdc_results WHERE slide_id='$row[0]'")); $result = $result -> fetch_row() ?? array('');}
                else $result = array('');
                //allow rcdc result update delete untill samples are received
                if ($roleID == 1 || $reporting_flag == "OPEN") 
                  $udlink = "<a href='panels.php?id=$id&rid=$row[0]&type=re'><i class='fa fa-edit'></i></a> &nbsp <a href='delete.php?id=$id&type=rcdc&rid=$row[0]' onclick='return confirmDelete()'><i class='fa fa-trash text-danger'></i></a>";
                else  $udlink = "";
                echo("<tr class='text-center'><td>$row[1]</td><td>$result[0]</td><td>$udlink</td></tr>");
              }
            ?>
            </tbody>
          </table><br>
          
          <?php
            if(isset($_GET['rid']) && ($_GET['type'] ?? '') == 're'){
              $rid = (int)$_GET['rid'];
              mn_require_child($conn, 'rdt_panel_slide', $rid, $id);   // slide must belong to this panel
              $slide = ($conn->query("SELECT slide_number FROM rdt_panel_slide_informations WHERE slide_id='$rid'"));
              $slide = $slide -> fetch_row() ?? array('');
              $chkResult = ($conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results WHERE slide_id='$rid'"));
              $chkResult = $chkResult -> fetch_row() ?? array(0);
              if($chkResult[0] > 0){
                $result = ($conn->query("SELECT rcdc_species FROM rdt_panel_rcdc_results WHERE slide_id='$rid'")); $result = $result -> fetch_row() ?? array('');}
              else $result = array('');
          ?>

          <div class="form-group row">
            <label class="col-sm-12 text-primary col-form-label">Update Panel Result</label>
          </div> 

          <form class="form-horizontal" id="rcdc" autocomplete="off" method="Post" action="iu-panel-save.php">
            <input type="hidden" id="id" name="id" value="<?=$id;?>">
            <input type="hidden" id="rcdc_rid" name="rcdc_rid" value="<?=$rid;?>">
            <input type="hidden" name="rcdc_submit" value="1">
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Panel(*)</label>
              <div class="col-sm-3">
                <input type="text" class="text-center form-control" value="<?=$slide[0];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Plasmodium species(*)</label>
              <div class="col-sm-3">
                <select class="form-control" name="rcdc_species" id="rcdc_species">
                    <option value="<?=$result[0];?>"><?= (($result[0] ?? '') !== '') ? $result[0] : 'Select Species' ?></option>
                    <option value="Negative">Negative</option>
                    <option value="Pf">Pf</option>
                    <option value="Pv">Pv</option>
                    <option value="F+V">F+V</option>
                  </select>
              </div>
            </div>
            <button type="submit" class="btn bg-gradient-success">Submit</button>
            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
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
          <div class="form-group row">
            <label class="col-sm-12 text-primary col-form-label">Add/Update RDT Panel Result</label>
          </div>

          <form class="form-horizontal" id="lab" autocomplete="off" method="Post" action="iu-panel-save.php">
            <?php
             $lab1 = ($conn->query("SELECT examiner, category, designation, examination_date, kitname, batchlot, manufacturer, expiry_date, sensitivity, specificity, species, avg_score1, avg_score2, final_score, achievement FROM rdt_panel_lab_result_informations WHERE panel_id='$id'"));
              $lab1 = $lab1 -> fetch_row();
              // A saved lab result already exists -> this is an Update (Edit); otherwise a new Add.
              // Drives both the action flag (INSERT vs UPDATE) and the submit-button label.
              $isLabUpdate = !empty($lab1);
              if($isLabUpdate) echo("<input type='hidden' id='lab_action' name='lab_action' value='2'>");
              else {
                $lab1 = array('', '', '', '', '', '', '', '', '', '', '', '', '', '', '');
                echo("<input type='hidden' id='lab_action' name='lab_action' value='1'>");
              }
              // query selects 15 columns (indexes 0-14); guarantee every index exists and NULLs render as blanks
              for($i = 0; $i < 15; $i++){ $lab1[$i] = $lab1[$i] ?? ''; }
            ?>
            <input type="hidden" id="id" name="id" value="<?=$id;?>">
            <input type="hidden" name="lab_submit" value="1">
            <input type="hidden" id="timeliness" name="timeliness" value="<?=$reporting_flag;?>">
            
             <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Test performed by(*)</label>
              <div class="col-sm-3">
                <input type="text" name="examiner" id="examiner" class="form-control" value="<?=$lab1[0];?>" placeholder="Performed by">
              </div>
              <label class="col-sm-3 col-form-label-sm">Category(*)</label>
              <div class="col-sm-3">
                <select class="form-control" name="category" id="category" >
                  <option value="<?=$lab1[1];?>"><?= (($lab1[1] ?? '') !== '') ? $lab1[1] : 'Select Category' ?></option>
                  <option value="Group">Group</option>
                </select>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Designation</label>
              <div class="col-sm-3">
                <input type="text" name="designation" id="designation" class="form-control" value="<?=$lab1[2];?>" placeholder="Designation">
              </div>
              <label class="col-sm-3 col-form-label-sm">Test performed on(*)</label>
              <div class="col-sm-3">
                <div class="input-group date" id="doe" data-target-input="nearest">
                      <input type="text" name="doe" class="form-control datetimepicker-input text-center" data-target="#doe" value="<?=$lab1[3];?>"/>
                      <div class="input-group-append" data-target="#doe" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                  </div>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Test kit name</label>
              <div class="col-sm-3">
                <input type="text" id="kitname" name="kitname" class="form-control" value="<?=$lab1[4];?>">
              </div>
              <label class="col-sm-3 col-form-label-sm">Batch/Lot</label>
              <div class="col-sm-3">
                <input type="text" id="batchlot" name="batchlot" class="form-control" value="<?=$lab1[5];?>">
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Kit manufacturer</label>
              <div class="col-sm-3">
                <input type="text" id="mfg" name="mfg" class="form-control" value="<?=$lab1[6];?>">
              </div>
              <label class="col-sm-3 col-form-label-sm">Kit expiry data</label>
              <div class="col-sm-3 input-group date" data-target-input="nearest">
                <input type="text" class="form-control datetimepicker-input text-center" id="doex" name="doex" value="<?=$lab1[7];?>" placeholder="Expiry date"/>
                <div class="input-group-append" data-target="#doex" data-toggle="datetimepicker">
                  <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                </div>
              </div>
            </div>

            <table class="table table-bordered table-hover">
              <thead> 
                <tr class="text-center">
                  <th>Panel</th>
                  <th>Species</th>
                </tr>
              </thead>
              <tbody>
              <?php
                $slide = $conn->query("SELECT slide_id, slide_number FROM rdt_panel_slide_informations WHERE slide_status='Intact' AND panel_id='$id'");
                while($row = $slide->fetch_row()) { 
                    $lab2 = ($conn->query("SELECT lab_species FROM rdt_panel_lab_results WHERE slide_id='$row[0]'"));
                    $lab2 = $lab2 -> fetch_row();
                    if(empty($lab2))  $lab2 = array('');
                    $lab2[0] = $lab2[0] ?? '';
              ?>
                <tr>
                  <td class="text-center">
                    <input type="hidden" name="lab_slide[]" id="lab_slide[]" class="text-center form-control" value="<?=$row[0];?>" required>
                    <?=$row[1];?>
                  </td>
                  <td>
                    <select class="form-control" name="species[]">
                      <option value="<?=$lab2[0];?>"><?= (($lab2[0] ?? '') !== '') ? $lab2[0] : 'Select Species' ?></option>
                      <option value="Negative">Negative</option>
                      <option value="Pf">Pf</option>
                      <option value="Pv">Pv</option>
                      <option value="F+V">F+V</option>
                    </select>
                  </td>
                </tr>
              <?php } ?>
              </tbody>
            </table>
          <?php if(!empty($lab1)){ ?>  
            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Sensitivity Score</label>
              <div class="col-sm-3">
                <input type="text" class="form-control text-center" value="<?=$lab1[8];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Specificity Score</label>
              <div class="col-sm-3">
                <input type="text" class="form-control text-center" value="<?=$lab1[9];?>" readonly>
              </div>
            </div> 

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Species Identification Score</label>
              <div class="col-sm-3">
                <input type="text" class="form-control text-center" value="<?=$lab1[10];?>" readonly>
              </div>
            </div> 

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Average Score 1(80%)</label>
              <div class="col-sm-3">
                <input type="text" class="form-control text-center" value="<?=$lab1[11];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Average Score 2(20%)</label>
              <div class="col-sm-3">
                <input type="text" class="form-control text-center" value="<?=$lab1[12];?>" readonly>
              </div>
            </div> 

            <div class="form-group row">
              <label class="col-sm-3 col-form-label-sm">Final Score</label>
              <div class="col-sm-3">
                <input type="text" class="form-control text-center" value="<?=$lab1[13];?>" readonly>
              </div>
              <label class="col-sm-3 col-form-label-sm">Achievement</label>
              <div class="col-sm-3">
                <input type="text" class="form-control text-center" value="<?=$lab1[14];?>" readonly>
              </div>
            </div> 
        <?php 
            }     
            // Admin (roleID == 1) can always Add / Update / Delete Lab Result.
            // Other roles keep the original business rules.
            $canEditLab = ($roleID == 1)
                       || (
                            $reporting_flag != "CLOSED"
                            && $date_array[1] != ""
                            && ($roleID == 2 || $roleID == 3)
                            && $chkRcdcResult > 0
                          );

            if ($canEditLab) {
              $delete = "delete.php?id=$id&type=lab";
        ?>    
            <button type="submit" class="btn bg-gradient-success"><?= $isLabUpdate ? 'Update Record' : 'Submit Record'; ?></button>
            <a href="<?=$delete;?>" class="btn bg-gradient-danger" onclick="return confirmDelete()">Delete</a>
            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
        <?php  
            }   
            $chkRecommendation = ($conn->query("SELECT panel_id FROM rdt_panel_lab_result_informations WHERE findings !='' AND panel_id = '$id'"));
            $chkRecommendation = $chkRecommendation->num_rows;
            $fdlink = $pdflink = $actionlink = "";
            if(($reporting_flag == "CLOSED") || ($roleID == 1 || $roleID == 2)){
                if($roleID == 1 || $roleID == 2)
                $fdlink = "<a href='feedbacks.php?id=$id'><i class='btn bg-gradient-warning'>Findings & Recommendations</i></a> &nbsp";
                if($chkRecommendation > 0)
                $actionlink = "<a href='feedbacks.php?id=$id'><i class='btn bg-gradient-warning'>Action Taken</i></a> &nbsp";                  
                $pdflink = "<a href='pdf-feedbacks.php?id=$id' target='_BLANK'><i class='btn bg-gradient-warning'>Download</i></a> &nbsp";
            }
             echo $fdlink." ".$actionlink." ".$pdflink;
        ?>        
          </form>
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
          rcdc_species: "required"
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
      $('#dor').datetimepicker({  format: 'YYYY-MM-DD',});
      $('#doe').datetimepicker({  format: 'YYYY-MM-DD',});
      $('#doex').datetimepicker({  format: 'YYYY-MM-DD',});
    });
    
    function confirmDelete() {
			if (confirm("Do you really want to DELETE this record?") == true) { return true; } 
			else { return false; }
		}
  </script>                  
<!-- /.page footer -->
<?php require_once("../includes/footer.php"); ?>
