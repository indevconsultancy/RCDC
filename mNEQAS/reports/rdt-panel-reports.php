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
<?php $roundsql = "SELECT panel_round FROM rdt_panel_round_informations ORDER BY panel_round DESC"; ?>

<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<!-- Highcharts -->
<?php require_once("../includes/highcharts.php"); ?>

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
            <h1>RDT Panel Reports</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Reports | Panels</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <!-- Search -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <!-- /.card-header -->
              <div class="card-body">
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="rdt-panel-reports.php">
                  <div class="form-group row align-items-start mb-0">
                    <div class="col-md-3 mb-2 mb-md-0">
                      <label class="col-form-label-sm">Report type:</label>
                      <select name="type" id="type" class="form-control" required>
                        <option value="">Select type</option>
                        <option value="Chart">Score Charts</option>
                        <option value="GTable">Group Tabular Reports</option>
                        <?php
                        if ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) {
                          echo '<option value="Export">Export Data</option>';
                        }
                        ?>

                      </select>
                    </div>
                    <div class="col-md-3 mb-2 mb-md-0">
                      <label class="col-form-label-sm">Panel rounds:</label>
                      <select name="round" id="round" class="form-control" required>
                        <option value="">Select round</option>
                        <?php
                          $result = $conn->query($roundsql);
                          while($row = $result->fetch_row()) {
                            echo("<option value='$row[0]'>$row[0]</option>");
                          }
                        ?>
                      </select>
                    </div>
                    <div class="col-md-3 mb-2 mb-md-0">
                      <!-- spacer label keeps the button level with the selects; hidden when columns stack on mobile -->
                      <label class="col-form-label-sm d-none d-md-block" aria-hidden="true">&nbsp;</label>
                      <button type="submit" name="filter" class="btn bg-gradient-success btn-block"><i class="fa fa-search"></i>&nbsp Generate</button>
                    </div>
                    <div class="col-md-3">
                      <label class="col-form-label-sm d-none d-md-block" aria-hidden="true">&nbsp;</label>
                      <a href="rdt-panel-reports.php" class="btn bg-gradient-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</a>
                    </div>
                  </div>
                </form> 
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <!-- /.search -->
     
    <!-- Score Charts -->
    <?php 
				if(isset($_POST['filter'])){
          $type = isset($_POST["type"]) ? (string)$_POST["type"] : '';
          $round = $conn->real_escape_string(isset($_POST["round"]) ? (string)$_POST["round"] : '');
          $roundHtml = htmlspecialchars($round, ENT_QUOTES, 'UTF-8');
          // Facility users: every chart/table row is limited to their own facility (server-side).
          $authFac  = $commondb->real_escape_string((string)mn_facility());
          $authScope   = mn_is_rcdc() ? '' : " AND center_id='$authFac'";
          $authScopeT1 = mn_is_rcdc() ? '' : " AND t1.center_id='$authFac'";
          /* Performance scores are released to participating labs only once the
             round has closed. RCDC/Administrator are unaffected. The same rule
             mn_panel_state() uses: closed when today is past closing_date. */
          $authClosed  = mn_is_rcdc() ? '' : " AND t3.closing_date IS NOT NULL AND t3.closing_date < CURDATE()";
          $scoresReleased = true;
          if (!mn_is_rcdc()) {
              $relStmt = $conn->prepare("SELECT MAX(closing_date IS NOT NULL AND closing_date < CURDATE()) FROM rdt_panel_round_informations WHERE panel_round = ?");
              if ($relStmt) {
                  $relStmt->bind_param('s', $round);
                  $relStmt->execute();
                  $relRow = $relStmt->get_result()->fetch_row();
                  $relStmt->close();
                  $scoresReleased = ($relRow && (int)$relRow[0] === 1);
              }
          }

          if($type == "Chart"){
            $scoreCategory = array('Sensitivity', 'Specificity', 'Species Identification', 'Final Score');

            $facilityqry = $commondb->query("SELECT center_id, center_name FROM ms_health_center_table WHERE mneqas=1".$authScope);
            $facilityid = $facility = array();
            while($row = $facilityqry->fetch_row()) {  $facilityid[] = $row[0]; $facility[] = $row[1]; }

            $chartTitle = json_encode("RDT Panel Testing Scores");
            $yaxisTitle = json_encode("Score in %");
            $category = json_encode($facility);
            $series = json_encode($scoreCategory);
            
            for($i=0; $i<COUNT($facilityid); $i++){
              $score_array = "";
              $scoreqry = "SELECT sensitivity, specificity, species, final_score FROM rdt_panel_lab_result_informations t1, rdt_panel_informations t2, rdt_panel_round_informations t3 WHERE t1.panel_id=t2.panel_id AND t2.round_id=t3.round_id AND t3.panel_round='$round' AND t2.facility_id='$facilityid[$i]' AND t1.category='Group'".$authClosed;
              
              if(($conn->query($scoreqry)->num_rows) > 0){
                $score_array = ($conn->query($scoreqry));
                $score_array = $score_array->fetch_row();
                $sen[] =  $score_array[0];
                $spc[] =  $score_array[1];
                $spe[] =  $score_array[2];
                $avg[] =  $score_array[3];
              }  
              else{
                $sen[] = $spc[] = $spe[] = $avg[] = 0;
              } 
            }

            $sen = json_encode($sen,JSON_NUMERIC_CHECK);
            $spc = json_encode($spc,JSON_NUMERIC_CHECK);
            $spe = json_encode($spe,JSON_NUMERIC_CHECK);
            $avg = json_encode($avg,JSON_NUMERIC_CHECK);
    ?>
      <section class="content">
        <div class="container-fluid">
              <div class="row">
                <div class="col-12">
                  <div class="card card-primary">
                    <div class="card-header">
                      <i><strong>RDT Panel Testing Scores for Round <?=$roundHtml;?></strong></i>
                    </div>
                    <?php if (!$scoresReleased) { ?>
                    <!-- The round is still open: the score is calculated and stored, but is
                         not shown to participating labs until the closing date has passed.
                         A chart here would render zeros and read as a score of nil. -->
                    <div class="card-body">
                      <div class="alert alert-info mb-0">
                        <i class="fas fa-lock"></i>
                        Performance scores for this round are released after its closing date.
                        Panel testing and result submission are unaffected.
                      </div>
                    </div>
                    <?php } else { ?>
                    <div class="card-body"><div id="container"></div></div>
                    <?php } ?>
                  </div>
                </div>
              </div>
        </div>		
      </section>	
    <?php
        include("rdt-panel-score-charts.php"); 
      }
      else if($type == "GTable"){
        $category = "Group";
    ?>
      <section class="content">
        <div class="container-fluid">
            <div class="row">
              <div class="col-12">
                <div class="card card-primary">
                  <div class="card-header">
                    <i><strong><?=$category;?> Panel Testing Reports for Round <?=$roundHtml;?></strong></i>
                  </div>

                  <div class="card-body table-responsive p-0">
                    <table id="table" class="table table-bordered table-hover">
                      <thead>
                        <tr class='text-center'>
                          <th width='%15'>Dzongkhag</th>
                          <th width='%29'>Health Facility</th>
                          <th width='8%'>Participants</th>
                          <th width='8%'>Ontime</th>
                          <th width='8%'>Late</th>
                          <th width='8%'>Panels Rec</th>
                          <th width='8%'>Panels Seen</th>
                          <th width='8%'>FP</th>
                          <th width='8%'>FN</th>
                        </tr>
                      </thead>
                      <tbody>
                      <?php
                         $total1 = $total2 = $total3 = $total4 = $total5 = $total6 = $total7 = 0;

                         $facilityqry = $commondb->query("SELECT t2.dzongkhag_name, t1.center_id, t1.center_name  FROM ms_health_center_table t1, ms_dzongkhag_table t2 WHERE t1.dzongkhag_code=t2.dzongkhag_code AND t1.mneqas=1".$authScopeT1);
                         
                         while($row = $facilityqry->fetch_row()) { 
                          $slide_rec = ($conn->query("SELECT COUNT(*) FROM rdt_panel_slide_informations t1, rdt_panel_informations t2, rdt_panel_round_informations t3 WHERE t1.panel_id=t2.panel_id AND t2.round_id=t3.round_id AND t3.panel_round='$round' AND t2.facility_id='$row[1]' AND t1.slide_status='Intact'"));
                          $slide_rec = $slide_rec -> fetch_row(); 
                          $total4 += $slide_rec[0];

                          $participant = ($conn->query("SELECT COUNT(*) FROM rdt_panel_lab_result_informations t1, rdt_panel_informations t2, rdt_panel_round_informations t3 WHERE t1.panel_id=t2.panel_id AND t2.round_id=t3.round_id AND t3.panel_round='$round' AND t2.facility_id='$row[1]' AND t1.category='$category'"));
                          $participant = $participant -> fetch_row(); 
                          $total1 += $participant[0];

                          $slide_seen = $slide_rec[0]*$participant[0];
                          $total5 += $slide_seen;

                          $ontime = ($conn->query("SELECT COUNT(*) FROM rdt_panel_lab_result_informations t1, rdt_panel_informations t2, rdt_panel_round_informations t3 WHERE t1.panel_id=t2.panel_id AND t2.round_id=t3.round_id AND t3.panel_round='$round' AND t2.facility_id='$row[1]' AND t1.timeliness='ONTIME' AND t1.category='$category'"));
                          $ontime = $ontime -> fetch_row();
                          $total2 += $ontime[0];

                          $late = ($conn->query("SELECT COUNT(*) FROM rdt_panel_lab_result_informations t1, rdt_panel_informations t2, rdt_panel_round_informations t3 WHERE t1.panel_id=t2.panel_id AND t2.round_id=t3.round_id AND t3.panel_round='$round' AND t2.facility_id='$row[1]' AND t1.timeliness='LATE' AND t1.category='$category'"));
                          $late = $late -> fetch_row();
                          $total3 += $late[0];

                          $fp = ($conn->query("SELECT COUNT(*) FROM rdt_panel_round_informations t1, rdt_panel_informations t2, rdt_panel_lab_result_informations t3, rdt_panel_slide_informations t4, rdt_panel_lab_results t5 WHERE t1.round_id=t2.round_id AND t2.panel_id=t3.panel_id AND t2.panel_id=t4.panel_id AND t4.slide_id=t5.slide_id AND t1.panel_round='$round' AND t2.facility_id='$row[1]' AND t3.category='$category' AND t5.positivity_flag='FP'"));
                          $fp = $fp -> fetch_row();
                          $total6 += $fp[0];

                          $fn = ($conn->query("SELECT COUNT(*) FROM rdt_panel_round_informations t1, rdt_panel_informations t2, rdt_panel_lab_result_informations t3, rdt_panel_slide_informations t4, rdt_panel_lab_results t5 WHERE t1.round_id=t2.round_id AND t2.panel_id=t3.panel_id AND t2.panel_id=t4.panel_id AND t4.slide_id=t5.slide_id AND t1.panel_round='$round' AND t2.facility_id='$row[1]' AND t3.category='$category' AND t5.positivity_flag='FN'"));
                          $fn = $fn -> fetch_row();
                          $total7 += $fn[0];

                          $reports = $ontime[0] + $late[0];
                          if($reports == 0) $clr = "text-danger"; else $clr = "";
                          echo("<tr class='$clr'><td>$row[0]</td><td>$row[2]</td><td class='text-center'>$participant[0]</td><td class='text-center'>$ontime[0]</td><td class='text-center'>$late[0]</td><td class='text-center'>$slide_rec[0]</td><td class='text-center'>$slide_seen</td><td class='text-center'>$fp[0]</td><td class='text-center'>$fn[0]</td></tr>");
                        }  

                        echo("<tr class='$clr'><td><strong>Total</strong></td><td></td><td class='text-center'>$total1</td><td class='text-center'>$total2</td><td class='text-center'>$total3</td><td class='text-center'>$total4</td><td class='text-center'>$total5</td><td class='text-center'>$total6</td><td class='text-center'>$total7</td></tr>");
                      ?>  
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
        </div>		
      </section>
    <?php   
      }
      else if (mn_is_rcdc()) {   // CSV export form: RCDC only (rdt-panel-csv.php enforces the same)
    ?>
      <section class="content">
        <div class="container-fluid">
          <div class="row">
            <div class="col-12">
              <div class="card">
                <!-- /.card-header -->
                <div class="card-body">
                  <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="rdt-panel-csv.php">
                    <input type="hidden" name="round" value="<?=$roundHtml;?>" />
                    <div class="form-group row">
                      <div class="col-sm-12 text-center">
                        <button type="submit" name="filter" class="btn bg-gradient-info"><i class="fa fa-download"></i>&nbsp Export Panel Data in CSV Format</button>
                        <a href="rdt-panel-reports.php" class="btn bg-gradient-secondary"><i class="fa fa-sync"></i>&nbsp Cancel</button></a>
                      </div>
                    </div>    	
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    <?php    
      }
    }
    ?>
    <!-- /.Charts/Graphs -->    
  </div>
  <!-- /.content-wrapper -->    
  <script>
    $(document).ready(function(){
      $('#form').validate({
        rules: {
					type: "required",
          round: "required"
        },
        errorElement: 'span',
        errorPlacement: function (error, element) {
          // Both selects and the buttons share one .form-group row, so appending
          // to the row would stack every message after the buttons. Placing the
          // error right after its own select keeps it below that field only, and
          // Bootstrap's .is-invalid ~ .invalid-feedback sibling rule displays it.
          error.addClass('invalid-feedback');
          error.insertAfter(element);
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
      $('#table').DataTable({
        "paging": false,
        "lengthChange": false,
        "searching": false,
        "ordering": false,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        "buttons": ["copy", "csv", "excel", {extend:'pdfHtml5', text:'PDF', download:'open', title:'RDT Panel Summary', orientation:'landscape', pageSize:'A4'}, "print"],
        "order": [[ 0, "asc" ]]
      }).buttons().container().appendTo('#table_wrapper .col-md-6:eq(0)');
    }); 
  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
