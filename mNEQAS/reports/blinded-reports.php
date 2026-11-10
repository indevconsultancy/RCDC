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
            <h1>Blinded Reports</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Reports | Blinded</li>
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
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="blinded-reports.php">
                  <div class="row">
                      <div class="form-group col-sm-6 position-relative">
                          <label  class="col-form-label-sm">Report type</label>
                          <select name="type" id="type" class="form-control" required>
                              <option value="">Select type</option>
                              <option value="rs">Reporting Timeliness Chart</option>
                              <option value="trp">Slides Rechecking Vs Labs Chart</option>
                              <option value="tnp">Slides Rechecking Vs Negative/Positive Chart</option>
                              <option value="tfp">Malaria Positives by Type Chart</option>
                              <option value="pd">Parasite Density Chart</option>
                              <option value="pdi">Parasite Detection and Identification Chart</option>
                              <option value="ps">Performance Score Chart</option>
                              <option value="rst">Reporting Timeliness Table</option>
							  <option value="st">Summary Table</option>
                          </select>
                      </div>
                      <div class="form-group col-sm-6 text-sm-right">
                          <label class="col-form-label-sm invisible d-none d-sm-block">Report type</label>
                          <button type="submit" name="filter" class="btn bg-gradient-success mr-2"><i class="fa fa-search"></i>&nbsp Generate</button>
                          <a href="blinded-reports.php" class="btn bg-gradient-secondary"><i class="fa fa-sync"></i>&nbsp Reset</a>
                      </div>
                  </div>
                  <div class="row">
										<div class="form-group col-sm-10">
                      <label class="col-form-label-sm">Year: </label>
											<?php
												for($i=date("Y");$i>=2017;$i--){
													if($i == date("Y")){$chk = "Checked";} else $chk ="";
													echo("<input type='radio' name='year[]' id='year' value='$i' class='checkbox' $chk>&nbsp $i &nbsp &nbsp");
												}
											?>
										</div>
									</div>
									<div class="form-group row">
										<div class="form-group col-sm-10">
                      <label class="col-form-label-sm">Month</label>
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
            $type = isset($_POST["type"]) ? (string)$_POST["type"] : '';
            // Year/month filters are integers only (they are placed into IN(...) lists below).
            if (isset($_POST["year"])) { $_POST["year"] = array_values(array_map('intval', (array)$_POST["year"])); }
            if (isset($_POST["month"])) { $_POST["month"] = array_values(array_filter(array_map('intval', (array)$_POST["month"]), function($m){ return $m >= 1 && $m <= 12; })); }
            $year = isset($_POST["year"]) ? $_POST["year"] : array();

            // Facility scope (server-side): facility users aggregate only their own facility's
            // records; every blinded_informations reference below reads through $BI.
            $authFac = $conn->real_escape_string((string)mn_facility());
            $BI = mn_is_rcdc() ? "blinded_informations" : "(SELECT * FROM blinded_informations WHERE facility_id='" . $authFac . "')";
            $authScopeT1 = mn_is_rcdc() ? '' : " AND t1.center_id='" . $authFac . "'";

            if(isset($_POST["year"])) { 
              $year = $_POST["year"]; 
              $str1 = "";
              for($i=0;$i<count($year);$i++){ $str1 .= $year[$i].","; }
              $str1 = substr($str1,0,-1);
            }
  
            $monthName = array();
            if(isset($_POST["month"])) { 
              $month = $_POST["month"];
              $str2 = "";
              for($i=0;$i<count($month);$i++){ 
                $str2 .= $month[$i].",";
                $dateObj   = DateTime::createFromFormat('!m', $month[$i]);
                $monthName[] = $dateObj->format('M');
              }
              $str2 = substr($str2,0,-1);
            }

            if($type == "rs"){
                $scoreCategory = array('Timely Reported', 'Late Reported', 'Not Reported', 'Zero Report');

                $chartTitle = json_encode("Reporting timeliness of blinded rechecking $str1");
                $yaxisTitle = json_encode("Percentage");
                $category = json_encode($monthName,JSON_NUMERIC_CHECK);
                $series = json_encode($scoreCategory);

                $facilityqry = $commondb->query("SELECT COUNT(center_id) FROM ms_health_center_table WHERE mneqas=1" . str_replace('t1.', '', $authScopeT1));
                $reptsites = $facilityqry->fetch_row();

                for($j=0;$j<count($month);$j++){
                    $ontimeqry = $conn->query("SELECT COUNT(DISTINCT(facility_id)) FROM {$BI} bis WHERE year IN($str1) AND month='$month[$j]' AND timeliness='ONTIME'");
                    $lateqry = $conn->query("SELECT COUNT(DISTINCT(facility_id)) FROM {$BI} bis WHERE year IN($str1) AND month='$month[$j]' AND timeliness='LATE'");
                    $zeroqry = $conn->query("SELECT COUNT(DISTINCT(facility_id)) FROM {$BI} bis WHERE year IN($str1) AND month='$month[$j]' AND total_microscopy='0'");
                    
                    $ontime = $ontimeqry->fetch_row();
                    $late = $lateqry->fetch_row();
                    $zero = $zeroqry->fetch_row();

                    $ontimes[] = round($ontime[0]/$reptsites[0]*100);
                    $lates[] = round($late[0]/$reptsites[0]*100);
                    $zeros[] = round($zero[0]/$reptsites[0]*100);
                    $nr[] = round(($reptsites[0] - ($ontime[0] + $late[0] + $zero[0]))/$reptsites[0]*100);
                }

                $ont = json_encode($ontimes,JSON_NUMERIC_CHECK);
                $lte = json_encode($lates,JSON_NUMERIC_CHECK);
                $zer = json_encode($zeros,JSON_NUMERIC_CHECK);
                $nrc = json_encode($nr,JSON_NUMERIC_CHECK);
                ?>
                <section class="content">
                    <div class="container-fluid">
                        <div class="row">
                            <div class="col-12">
                            <div class="card card-primary">
                                <div class="card-header">
                                <i><strong>Reporting timeliness of blinded rechecking, <?=$str1;?> (<?=$str2;?>)</strong></i>
                                </div>
                                <div class="card-body"><div id="container"></div></div>
                            </div>
                            </div>
                        </div>
                    </div>		
                </section>	
                <?php
                include("blinded-rs-charts.php"); 
            }

            elseif($type == "trp"){
                $category = json_encode($monthName,JSON_NUMERIC_CHECK);
                $scoreCategory = array('Slides Rechecked', 'Labs');

                $chartTitle = json_encode("Blinded Slides Rechecking Vs Participating Labs $str1");
                $xAxisTitle = json_encode("Months");
                $yaxisTitle = json_encode("Total Blinded Slides Rechecked");
                $y2axisTitle = json_encode("Total Participating Labs");
                $series = json_encode($scoreCategory);

                for($j=0;$j<count($month);$j++){
                    $tplqry = $conn->query("SELECT COUNT(DISTINCT(facility_id)) FROM {$BI} bis WHERE year IN($str1) AND month='$month[$j]'");

                    $tbsrqry = $conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.slide_status='Intact' AND t2.c2_microscopy != 'NULL'");
                    
                    $tpl[] = $tplqry->fetch_row();
                    $tbsr[] = $tbsrqry->fetch_row();
                }

                $tpl = json_encode($tpl,JSON_NUMERIC_CHECK);
                $tbsr = json_encode($tbsr,JSON_NUMERIC_CHECK);
                ?>
                <section class="content">
                    <div class="container-fluid">
                        <div class="row">
                            <div class="col-12">
                            <div class="card card-primary">
                                <div class="card-header">
                                <i><strong>Blinded Slides Rechecking Vs Participating Labs, <?=$str1;?></strong></i>
                                </div>
                                <div class="card-body"><div id="container"></div></div>
                            </div>
                            </div>
                        </div>
                    </div>		
                </section>	
                <?php
                include("blinded-trp-charts.php"); 
            }
            elseif($type == "tnp"){
              $category = json_encode($monthName,JSON_NUMERIC_CHECK);
              $scoreCategory = array('Slides Rechecked', 'Malaria Negative', 'Malaria Positive');

              $chartTitle = json_encode("Blinded Slides Rechecking Vs Malaria Result $str1");
              $xAxisTitle = json_encode("Months");
              $yaxisTitle = json_encode("Total Slide & Total Negative");
              $y2axisTitle = json_encode("Total Positive");
              $series = json_encode($scoreCategory);

              for($j=0;$j<count($month);$j++){
                  $tnsqry = $conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.slide_status='Intact' AND t2.c2_microscopy = 'Nmps'");
                  $tpsqry = $conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.slide_status='Intact' AND t2.c2_microscopy IN('Pv','Pf','F+V','Po','Pm','Pk')");
                  $tbsrqry = $conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.slide_status='Intact' AND t2.c2_microscopy != 'NULL'");
                  
                  $tns[] = $tnsqry->fetch_row();
                  $tps[] = $tpsqry->fetch_row();
                  $tbsr[] = $tbsrqry->fetch_row();
              }

              $tns = json_encode($tns,JSON_NUMERIC_CHECK);
              $tps = json_encode($tps,JSON_NUMERIC_CHECK);
              $tbsr = json_encode($tbsr,JSON_NUMERIC_CHECK);
              ?>
              <section class="content">
                  <div class="container-fluid">
                      <div class="row">
                          <div class="col-12">
                          <div class="card card-primary">
                              <div class="card-header">
                              <i><strong>Blinded Slides Rechecking Vs Malaria Result, <?=$str1;?></strong></i>
                              </div>
                              <div class="card-body"><div id="container"></div></div>
                          </div>
                          </div>
                      </div>
                  </div>		
              </section>	
              <?php
              include("blinded-tnp-charts.php");
          }
          elseif($type == "tfp"){
            $category = json_encode($monthName,JSON_NUMERIC_CHECK);
            $scoreCategory = array('Total Positive','Total P.falciparum', 'Total P.vivax', 'Mix');

            $chartTitle = json_encode("Malaria Positives by Type $str1");
            $xAxisTitle = json_encode("Months");
            $yaxisTitle = json_encode("Malaria cases ( Species)");
            $y2axisTitle = json_encode("Total positive");
            $series = json_encode($scoreCategory);

            for($j=0;$j<count($month);$j++){
                $tpsqry = $conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.slide_status='Intact' AND t2.c2_microscopy IN('Pv','Pf','F+V','Po','Pm','Pk')");
                $tpfqry = $conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.slide_status='Intact' AND t2.c2_microscopy IN('Pf')");
                $tpvqry = $conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.slide_status='Intact' AND t2.c2_microscopy IN('Pv')");
                $mixqry = $conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.slide_status='Intact' AND t2.c2_microscopy IN('F+V')");
                
                $tps[] = $tpsqry->fetch_row();
                $tpf[] = $tpfqry->fetch_row();
                $tpv[] = $tpvqry->fetch_row();
                $mix[] = $mixqry->fetch_row();
            }

            $tps = json_encode($tps,JSON_NUMERIC_CHECK);
            $tpf = json_encode($tpf,JSON_NUMERIC_CHECK);
            $tpv = json_encode($tpv,JSON_NUMERIC_CHECK);
            $mix = json_encode($mix,JSON_NUMERIC_CHECK);
            ?>
            <section class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12">
                        <div class="card card-primary">
                            <div class="card-header">
                            <i><strong>Malaria Positives by Type, <?=$str1;?></strong></i>
                            </div>
                            <div class="card-body"><div id="container"></div></div>
                        </div>
                        </div>
                    </div>
                </div>		
            </section>	
            <?php
            include("blinded-tfp-charts.php");
        }
        elseif($type == "pd"){
          $category = json_encode($monthName,JSON_NUMERIC_CHECK);
          $scoreCategory = array('Percentage Score');

          $chartTitle = json_encode("Parasite Density, $str1");
          $xAxisTitle = json_encode("Months");
          $yaxisTitle = json_encode("Total Slide & Total negative");
          $series = json_encode($scoreCategory);

          for($j=0;$j<count($month);$j++){
              $tpdqry = $conn->query("SELECT AVG(t2.parasite_density) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.parasite_density>0");              
              $pd[] = $tpdqry->fetch_row();
          }

          $pd = json_encode($pd,JSON_NUMERIC_CHECK);
          ?>
          <section class="content">
              <div class="container-fluid">
                  <div class="row">
                      <div class="col-12">
                      <div class="card card-primary">
                          <div class="card-header">
                          <i><strong>Parasite Density, <?=$str1;?></strong></i>
                          </div>
                          <div class="card-body"><div id="container"></div></div>
                      </div>
                      </div>
                  </div>
              </div>		
          </section>	
          <?php
          include("blinded-pd-charts.php");
      }
      elseif($type == "pdi"){
        $category = json_encode($monthName,JSON_NUMERIC_CHECK);
        $scoreCategory = array('Malaria parasite Detection', 'Malaria parasite species Identification');

        $chartTitle = json_encode("Malaria Parasite Detection and Species Identification, $str1");
        $xAxisTitle = json_encode("Months");
        $yaxisTitle = json_encode("Percentage Score");
        $series = json_encode($scoreCategory);

        for($j=0;$j<count($month);$j++){
            $mpdqry = $conn->query("SELECT AVG(t2.malaria_infection) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.malaria_infection>0");           
            $mpsiqry = $conn->query("SELECT AVG(t2.parasite_species) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.parasite_species>0");           
            $mpd[] = $mpdqry->fetch_row();
            $mpsi[] = $mpsiqry->fetch_row();
        }

          $mpd = json_encode($mpd,JSON_NUMERIC_CHECK);
          $mpsi = json_encode($mpsi,JSON_NUMERIC_CHECK);
        ?>
        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                    <div class="card card-primary">
                        <div class="card-header">
                        <i><strong>Malaria Parasite Detection and Species Identification, <?=$str1;?></strong></i>
                        </div>
                        <div class="card-body"><div id="container"></div></div>
                    </div>
                    </div>
                </div>
            </div>		
        </section>	
        <?php
        include("blinded-pdi-charts.php");
    }
    elseif($type == "ps"){
      $category = json_encode($monthName,JSON_NUMERIC_CHECK);
      $scoreCategory = array('Sensitivity (True positive detection)', 'Specificity (True negative detection)', 'Malaria parasite detection', 'Malaria parasite species identification', 'Malaria parasite stages identification', 'Parasite density', 'Quality of Blood Film', 'Quality of Stain');

      $chartTitle = json_encode("Performance score on blinded rechecking, $str1");
      $xAxisTitle = json_encode("Months");
      $yaxisTitle = json_encode("Percentage Score");
      $series = json_encode($scoreCategory);

      for($j=0;$j<count($month);$j++){
          $senqry = $conn->query("SELECT AVG(t2.sensitivity) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.sensitivity>0");      
          $speqry = $conn->query("SELECT AVG(t2.specificity) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.specificity>0");      
          $mpdetectqry = $conn->query("SELECT AVG(t2.malaria_infection) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.malaria_infection>0");       
          $mpSpeciesIry = $conn->query("SELECT AVG(t2.parasite_species) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.parasite_species>0");
          $mpStagesIqry = $conn->query("SELECT AVG(t2.parasite_stage) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.parasite_stage>0");
          $tpdenqry = $conn->query("SELECT AVG(t2.parasite_density) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1) AND t2.parasite_density>0");
          $filmqry = $conn->query("SELECT AVG(t2.film_quality) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1)");
          $stainqry = $conn->query("SELECT AVG(t2.stain_quality) FROM {$BI} t1, blinded_feedback t2 WHERE t1.blinded_id=t2.blinded_id AND t1.month='$month[$j]' AND t1.year IN($str1)");
          
          
          $sen[] = $senqry->fetch_row();
          $spe[] = $speqry->fetch_row();
          $mpdetect[] = $mpdetectqry->fetch_row();
          $mpspecies[] = $mpSpeciesIry->fetch_row();
          $mpstage[] = $mpStagesIqry->fetch_row();
          $pden[] = $tpdenqry->fetch_row();
          $film[] = $filmqry->fetch_row();
          $stain[] = $stainqry->fetch_row();
      }

        $sen = json_encode($sen,JSON_NUMERIC_CHECK);
        $spe = json_encode($spe,JSON_NUMERIC_CHECK);
        $mpdetect = json_encode($mpdetect,JSON_NUMERIC_CHECK);
        $mpspecies = json_encode($mpspecies,JSON_NUMERIC_CHECK);
        $mpstage = json_encode($mpstage,JSON_NUMERIC_CHECK);
        $pden = json_encode($pden,JSON_NUMERIC_CHECK);
        $film = json_encode($film,JSON_NUMERIC_CHECK);
        $stain = json_encode($stain,JSON_NUMERIC_CHECK);
      ?>
      <section class="content">
          <div class="container-fluid">
              <div class="row">
                  <div class="col-12">
                  <div class="card card-primary">
                      <div class="card-header">
                      <i><strong>Performance Score, <?=$str1;?></strong></i>
                      </div>
                      <div class="card-body"><div id="container"></div></div>
                  </div>
                  </div>
              </div>
          </div>		
      </section>	
      <?php
			include("blinded-ps-charts.php");
		}
		else if($type == "rst"){
      ?>
        <section class="content">
            <div class="container-fluid">
                <div class="row">
                  <div class="col-12">
                    <div class="card card-primary">
                      <div class="card-header">
                        <i><strong>Blinded Testing Timeliness for Year <?=$str1;?></strong></i>
                      </div>
    
                      <div class="card-body table-responsive p-0">
                        <table id="table" class="table table-bordered table-hover">
                          <thead>
                            <tr class='text-center'>
                              <th rowspan="2">Dzongkhag</th>
                              <th rowspan="2">Health Facility</th>
                              <th colspan="13">Timeliness</th>
                            </tr>
                            <tr class='text-center'>
                              <?php 
                              foreach ($monthName as $mont) {
                                echo "<th>$mont</th>";
                              }
                              ?>
                            </tr>
                          </thead>
                          <tbody>
                          <?php    
                            $facilityqry = $commondb->query("SELECT t2.dzongkhag_name, t1.center_id, t1.center_name  FROM ms_health_center_table t1, ms_dzongkhag_table t2 WHERE t1.dzongkhag_code=t2.dzongkhag_code AND t1.mneqas=1" . $authScopeT1);
                             
                            while($row = $facilityqry->fetch_row()) {
                              echo("<tr><td>$row[0]</td><td>$row[2]</td>");
                              for($j=0;$j<count($month);$j++){
                                $ontime = ($conn->query("SELECT t1.timeliness FROM {$BI} t1 WHERE t1.year IN('$str1') AND t1.month = '$month[$j]' AND t1.facility_id='$row[1]' AND t1.timeliness IN('ONTIME','LATE')"));
                                $ontime = $ontime -> fetch_row();
                                if(empty($ontime[0])){
                                  $zr = ($conn->query("SELECT COUNT(*) FROM {$BI} t1 WHERE t1.year IN('$str1') AND t1.month = '$month[$j]' AND t1.facility_id='$row[1]' AND t1.total_microscopy = '0'"));
                                  $zr = $zr -> fetch_row();
                                  if(empty($zr[0])){
                                    $sts = '<span class="text-danger">NR</span>';
                                  }else{
                                    $sts = 'ZR';
                                  }                                  
                                }else $sts = $ontime[0];

                                echo("<td class='text-center'>$sts</td>"); 
                              }
                                echo("</tr>");
                            }
                          ?>  
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
            </div>		
        </section>
    <?php  } else{ ?>
      <section class="content">
        <div class="container-fluid">
            <div class="row">
              <div class="col-12">
                <div class="card card-primary">
                  <div class="card-header">
                    <i><strong>Blinded Testing Summary for Year <?php echo "$str1(Month : $str2)"; ?></strong></i>
                  </div>

                  <div class="card-body table-responsive p-0">
                    <table id="table" class="table table-bordered table-hover">
                      <thead>
                        <tr class='text-center'>
                          <th width='%15'>Dzongkhag</th>
                          <th width='%20'>Health<br>Facility</th>
                          <th width='6.5%'>Total<br>Microscopy</th>
                          <th width='6.5%'>Total<br>RDT</th>
                          <th width='6.5%'>Total<br>Malaria<br>Screened</th>
                          <th width='6.5%'>Pos<br>Selected</th>
                          <th width='6.5%'>Neg<br>Selected</th>
            						  <th width='6.5%'>Total<br>Selected</th>
            						  <th width='6.5%'>Slides<br>Received</th>
            						  <th width='6.5%'>Slides<br>Seen</th>
                          <th width='6.5%'>FP</th>
                          <th width='6.5%'>FN</th>
                        </tr>
                      </thead>
                      <tbody>
                      <?php
                        $total1 = $total2 = $total3 = $total4 = $total5 = $total6 = $total7 = $total8 = $total9 = $total10 = $total11 = 0;

                        $facilityqry = $commondb->query("SELECT t2.dzongkhag_name, t1.center_id, t1.center_name  FROM ms_health_center_table t1, ms_dzongkhag_table t2 WHERE t1.dzongkhag_code=t2.dzongkhag_code AND t1.mneqas=1" . $authScopeT1);
                         
                        while($row = $facilityqry->fetch_row()) {
							$slide_sec = ($conn->query("SELECT SUM(total_microscopy), SUM(total_rdt), SUM(total_malaria), SUM(positive_slides), SUM(negative_slides), SUM(total_slides) FROM {$BI} bis WHERE facility_id='$row[1]' AND year IN($str1) AND month IN($str2)"));

                            $slide_sec = $slide_sec -> fetch_row(); 
                            $total1 += $slide_sec[0];
              							$total2 += $slide_sec[1];
              							$total3 += $slide_sec[2];
                            $total4 += $slide_sec[3];
                            $total5 += $slide_sec[4];
                            $total6 += $slide_sec[5];
                        
                            
                            $slide_rec = ($conn->query("SELECT COUNT(t1.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.facility_id='$row[1]' AND t1.year IN($str1) AND t1.month IN($str2) AND t2.slide_status='Intact'"));
                            $slide_rec = $slide_rec -> fetch_row(); 
                            $total7 += $slide_rec[0]; 

                            $slide_seen = ($conn->query("SELECT COUNT(t2.blinded_id) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.facility_id='$row[1]' AND t1.year IN($str1) AND t1.month IN($str2) AND t2.slide_status='Intact' AND t2.c2_microscopy IS NOT NULL"));
                            $slide_seen = $slide_seen -> fetch_row(); 
                            $total8 += $slide_seen[0];

                            $fp = ($conn->query("SELECT COUNT(*) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.year IN($str1) AND t1.month IN($str2) AND t1.facility_id='$row[1]' AND t2.positivity_flag='FP'"));
                            $fp = $fp -> fetch_row();
                            $total9 += $fp[0];

                            $fn = ($conn->query("SELECT COUNT(*) FROM {$BI} t1, blinded_results t2 WHERE t1.blinded_id=t2.blinded_id AND t1.year IN($str1) AND t1.month IN($str2) AND t1.facility_id='$row[1]' AND t2.positivity_flag='FN'"));
                            $fn = $fn -> fetch_row();
                            $total10 += $fn[0];

                            echo("<tr><td>$row[0]</td><td>$row[2]</td><td class='text-center'>$slide_sec[0]</td><td class='text-center'>$slide_sec[1]</td><td class='text-center'>$slide_sec[2]</td><td class='text-center'>$slide_sec[3]</td><td class='text-center'>$slide_sec[4]</td><td class='text-center'>$slide_sec[5]</td><td class='text-center'>$slide_rec[0]</td><td class='text-center'>$slide_seen[0]</td><td class='text-center'>$fp[0]</td><td class='text-center'>$fn[0]</td></tr>");
                        }

                        echo("<tr><td><strong>Total</strong></td><td></td><td class='text-center'>$total1</td><td class='text-center'>$total2</td><td class='text-center'>$total3</td><td class='text-center'>$total4</td><td class='text-center'>$total5</td><td class='text-center'>$total6</td><td class='text-center'>$total7</td><td class='text-center'>$total8</td><td class='text-center'>$total9</td><td class='text-center'>$total10</td></tr>");
                      ?>  
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
        </div>		
    </section>
    <?php  } } ?>
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
            error.addClass('invalid-feedback position-absolute');
            element.closest('.form-group').append(error);
        },
        highlight: function (element, errorClass, validClass) {
            $(element).addClass('is-invalid');
        },
        unhighlight: function (element, errorClass, validClass) {
            $(element).removeClass('is-invalid');
        }
      });

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
            "buttons": ["csv", "excel", {extend:'pdfHtml5', text:'PDF', download:'open', title:'Blinded Testing Summary', orientation:'landscape', pageSize:'A4'}],
            "order": [[ 0, "asc" ]]
        }).buttons().container().appendTo('#table_wrapper .col-md-6:eq(0)');
    }); 
  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
