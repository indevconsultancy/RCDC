<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php"); 
  error_reporting(E_ALL);
ini_set('display_errors', '1');
?>
<!-- Data and page settings -->
<?php $roundsql = "SELECT DISTINCT(year) FROM blinded_informations ORDER BY year DESC"; ?>

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
            <h1>MS Reports</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Reports | MS</li>
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
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="ms-reports.php">
                   <div class="form-group row">
					  <label class="col-sm-3 col-form-label-sm">Audit Category</label>
					  <select name="type" id="type" class="form-control col-sm-3" required>
						  <option value="">Select category</option>
						  <option value="Internal">Internal</option>
						  <option value="External">External</option>
					  </select>
					  <div class="col-sm-6 text-sm-right align-self-center">
						  <button type="submit" name="filter" class="btn bg-gradient-success"><i class="fa fa-search"></i>&nbsp Generate</button>
						  <a href="ms-reports.php" class="btn bg-gradient-secondary"><i class="fa fa-sync"></i>&nbsp Reset</button></a>
					  </div>
                  </div>
                  <div class="form-group row">										
                    <label class="col-sm-3 col-form-label-sm">Year: </label>
					<div class="col-sm-9">
					<?php	
						for($i=date("Y");$i>=2017;$i--){
							if($i == date("Y")){$chk = "Checked";} else $chk ="";
							echo("<input type='radio' name='year' id='year' value='$i' class='checkbox' $chk>&nbsp $i &nbsp &nbsp"); 
						}
					?>
					</div>
				  </div>
				  <div class="form-group row">										
					  <label class="col-sm-3 col-form-label-sm">Month</label>
					  <div class="col-sm-9">
						<input type='checkbox' id="checkall1">&nbsp <label class="col-form-label-sm">Check/Uncheck All</label>&nbsp &nbsp
						<?php	
							for($i=1;$i<=12;$i++){
								if($i == date("m")){$chk = "Checked";} else $chk ="";
								$mObj   = DateTime::createFromFormat('!m', $i);
								$mon = $mObj->format('M');
								echo("<input type='checkbox' name='month[]' value='$i' class='checkbox1' $chk>&nbsp $mon &nbsp &nbsp"); 
							}
						?>
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
			$type = $conn->real_escape_string(isset($_POST["type"]) ? (string)$_POST["type"] : '');
            $year = $conn->real_escape_string(isset($_POST["year"]) ? (string)$_POST["year"] : '');

			$msqry = "SELECT gi_id, center_name FROM onsite_general_informations t1, rcdc_commondb.ms_health_center_table t2 WHERE t1.facility_id=t2.center_id AND t1.audit_category='$type' AND YEAR(t1.date_visit)='$year'";
			// Facility users: only their own facility's audits (server-side scope).
			if (!mn_is_rcdc()) { $msqry .= " AND t1.facility_id='" . $conn->real_escape_string((string)mn_facility()) . "'"; }

            $monthName = array();
            if(isset($_POST["month"]) && is_array($_POST["month"])) {
              $month = array_values(array_filter(array_map('intval', $_POST["month"]), function($m){ return $m >= 1 && $m <= 12; }));
              $str2 = "";
              for($i=0;$i<count($month);$i++){
                $str2 .= $month[$i].",";
                $dateObj   = DateTime::createFromFormat('!m', $month[$i]);
                $monthName[] = $dateObj->format('M');
              }
              $str2 = substr($str2,0,-1);
			  $msqry .= " AND MONTH(t1.date_visit) IN ($str2)";
            }
			$msqry = $conn->query($msqry);
	?>
	<section class="content">
        <div class="container-fluid">
            <div class="row">
              <div class="col-12">
                <div class="card card-primary">
                  <div class="card-header">
                    <i><strong>"<?=$type;?>" Onsite Monitoring & Supervision Score  for <?=$year;?>(Months : <?=$str2;?>)</strong></i>
                  </div>

                  <div class="card-body">
                    <table id="table" class="table table-bordered table-hover">
                      <thead>
                        <tr class='text-center'>
                          <th width='15'>Health Facility</th>
                          <th width='10%'>Organization & Management</th>
                          <th width='10%'>Lab Design</th>
                          <th width='10%'>Equipment,Lab Consumables & Reagents</th>
                          <th width='10%'>Equipment Maintenance</th>
                          <th width='10%'>Internal Quality Control</th>
						  <th width='10%'>External Quality Control</th>
                          <th width='10%'>Documentation</th>
						  <th width='10%'>Infection Control & Waste Management</th>
                          <th width='5%'>Average</th>
                        </tr>
                      </thead>
                      <tbody>
                      <?php
						while($row = $msqry->fetch_row()) {
							$avg = 0;
							$om = ($conn->query("SELECT ROUND(AVG(score_value),2) FROM onsite_checklists_rating_score WHERE category_id='1' AND general_info_id = '$row[0]'"));
							$om = $om -> fetch_row();
							$avg += $om[0];	
							
							$ld = ($conn->query("SELECT ROUND(AVG(score_value),2) FROM onsite_checklists_rating_score WHERE category_id='2' AND general_info_id = '$row[0]'"));
							$ld = $ld -> fetch_row();
							$avg += $ld[0];
							
							$cr = ($conn->query("SELECT ROUND(AVG(score_value),2) FROM onsite_checklists_rating_score WHERE category_id='3' AND general_info_id = '$row[0]'"));
							$cr = $cr -> fetch_row();
							$avg += $cr[0];
							
							$em = ($conn->query("SELECT ROUND(AVG(score_value),2) FROM onsite_checklists_rating_score WHERE category_id='4' AND general_info_id = '$row[0]'"));
							$em = $em -> fetch_row();
							$avg += $em[0];
							
							$ic = ($conn->query("SELECT ROUND(AVG(score_value),2) FROM onsite_checklists_rating_score WHERE category_id='5' AND general_info_id = '$row[0]'"));
							$ic = $ic -> fetch_row();
							$avg += $ic[0];
							
							$ec = ($conn->query("SELECT ROUND(AVG(score_value),2) FROM onsite_checklists_rating_score WHERE category_id='6' AND general_info_id = '$row[0]'"));
							$ec = $ec -> fetch_row();
							$avg += $ec[0];
							
							$dc = ($conn->query("SELECT ROUND(AVG(score_value),2) FROM onsite_checklists_rating_score WHERE category_id='7' AND general_info_id = '$row[0]'"));
							$dc = $dc -> fetch_row();
							$avg += $dc[0];
							
							$wm = ($conn->query("SELECT ROUND(AVG(score_value),2) FROM onsite_checklists_rating_score WHERE category_id='8' AND general_info_id = '$row[0]'"));
							$wm = $wm -> fetch_row();
							$avg += $wm[0];
							
							$avg = round($avg/8,2);
							
							echo("<tr><td>$row[1]</td><td class='text-center'>$om[0]</td><td class='text-center'>$ld[0]</td><td class='text-center'>$cr[0]</td><td class='text-center'>$em[0]</td><td class='text-center'>$ic[0]</td><td class='text-center'>$ec[0]</td><td class='text-center'>$dc[0]</td><td class='text-center'>$wm[0]</td><td>$avg</td></tr>");
						}	
                      ?>  
                      </tbody>
                    </table>
                  </div>
				  </div>	
				</div>
              </div>
            </div>
        </div>		
    </section>
	<?php } ?>  
  </div>
  <!-- /.content-wrapper -->    
  <script>
    $(document).ready(function(){
		$("#checkall1").change(function(){
			var checked = $(this).is(':checked');
			if(checked){
			  $(".checkbox1").each(function(){
			  $(this).prop("checked",true);
			  });
			}else{
			  $(".checkbox1").each(function(){
			  $(this).prop("checked",false);
			  });
			}
		});
			   
		$(".checkbox1").click(function(){
			if($(".checkbox1").length == $(".checkbox1:checked").length) {
			  $("#checkall1").prop("checked", true);
			} else { $("#checkall1").removeAttr("checked"); }
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
            "buttons": ["csv", "excel", {extend:'pdfHtml5', text:'PDF', download:'open', title:'Onsite M&S Summary', orientation:'landscape', pageSize:'A4'}],
            "order": [[ 1, "asc" ]]
        }).buttons().container().appendTo('#table_wrapper .col-md-6:eq(0)');
    }); 
  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
