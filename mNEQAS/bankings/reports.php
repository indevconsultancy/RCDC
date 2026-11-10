<!-- Check session -->
<?php 
require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc(); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php"); 
?>
<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<style>
/* Page-level containment & full-width layout */
html, body {
    max-width: 100%;
    overflow-x: hidden;
}

.wrapper {
    max-width: 100%;
    overflow-x: hidden;
}

.content-wrapper {
    max-width: 100%;
}

.content,
.content > .container-fluid {
    width: 100%;
}

/* ===== Search Panel UI ===== */

.search-panel .form-control{
    height:46px;
    border-radius:6px;
    font-size:14px;
}

.search-panel .btn{
    width:100%;
    height:46px;
    border-radius:6px;
    font-size:14px;
    font-weight:600;
}

.search-panel .col-lg-3,
.search-panel .col-md-3{
    margin-bottom:0;
}

.search-panel .form-group{
    margin-bottom:0;
}

.search-panel .btn+.btn{
    margin-left:8px;
}

/* Compartment Matrix Table */
.compartment-matrix-table {
    min-width: 100%;
    width: max-content;
    margin-bottom: 0;
}

.compartment-matrix-table th,
.compartment-matrix-table td {
    min-width: 140px;
    vertical-align: middle;
}

.compartment-matrix-table .btn {
    white-space: nowrap;
    font-size: 13px;
    padding: 0.375rem 0.5rem;
}
</style>

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
            <h1>Slide Banking Reports</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Banking | Reports</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <!-- Quick Stat -->
    <?php

      $neg = $conn->query("SELECT COUNT(*) FROM banking_rack_specimen_informations t1, banking_slot_informations t2, banking_slide_informations t3 WHERE t1.rack_id=t2.rack_id AND t2.slot_id=t3.slot_id AND t1.status='Current' AND t1.malaria_species='Negative' AND t3.status IN ('Current','In-use')");
      $neg = $neg->fetch_row();

      $pf = $conn->query("SELECT COUNT(*) FROM banking_rack_specimen_informations t1, banking_slot_informations t2, banking_slide_informations t3 WHERE t1.rack_id=t2.rack_id AND t2.slot_id=t3.slot_id AND t1.status='Current' AND t1.malaria_species='Pf' AND t3.status IN ('Current','In-use')");
      $pf = $pf->fetch_row();

      $pv = $conn->query("SELECT COUNT(*) FROM banking_rack_specimen_informations t1, banking_slot_informations t2, banking_slide_informations t3 WHERE t1.rack_id=t2.rack_id AND t2.slot_id=t3.slot_id AND t1.status='Current' AND t1.malaria_species='Pv' AND t3.status IN ('Current','In-use')");
      $pv = $pv->fetch_row();

      $fv = $conn->query("SELECT COUNT(*) FROM banking_rack_specimen_informations t1, banking_slot_informations t2, banking_slide_informations t3 WHERE t1.rack_id=t2.rack_id AND t2.slot_id=t3.slot_id AND t1.status='Current' AND t1.malaria_species='F+V' AND t3.status IN ('Current','In-use')");
      $fv = $fv->fetch_row();

      $inbank = $conn->query("SELECT COUNT(*) FROM banking_slide_informations WHERE status='Current'");
      $inbank = $inbank->fetch_row();

      $inuse = $conn->query("SELECT COUNT(*) FROM banking_slide_informations WHERE status='In-use'");
      $inuse = $inuse->fetch_row();

      $hist = $conn->query("SELECT COUNT(*) FROM banking_slide_informations WHERE status='Historical'");
      $hist = $hist->fetch_row();

      $eslots = $conn->query("SELECT COUNT(*) FROM banking_slot_informations WHERE status='Empty'");
      $eslots = $eslots->fetch_row();
    ?>
    <section class="content">
      <div class="container-fluid">
        <!-- Small boxes (Stat box) -->
        <div class="row">
          <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
            <!-- small box -->
            <div class="small-box bg-info">
              <div class="inner">
                <b>Negative Slides</b>
                <h4 class="text-center"><?=$neg[0];?></h4>
              </div>
            </div>
          </div>
          <!-- ./col -->
          <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
            <!-- small box -->
            <div class="small-box bg-success">
              <div class="inner">
                <b>Pf Slides</b>
                <h4 class="text-center"><?=$pf[0];?></h4>
              </div>
            </div>
          </div>
          <!-- ./col -->
          <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
            <!-- small box -->
            <div class="small-box bg-warning">
              <div class="inner">
                <b>Pv Slides</b>
                <h4 class="text-center"><?=$pv[0];?></h4>
              </div>
            </div>
          </div>
          <!-- ./col -->
          <!-- ./col -->
          <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
            <!-- small box -->
            <div class="small-box bg-danger">
              <div class="inner">
                <b>F+V Slides</b>
                <h4 class="text-center"><?=$fv[0];?></h4>
              </div>
            </div>
          </div>
        </div>
        <!-- /.row -->
      </div> 
    </section>

    <section class="content">
      <div class="container-fluid">
        <!-- Small boxes (Stat box) -->
        <div class="row">
          <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
            <!-- small box -->
            <div class="small-box bg-info">
              <div class="inner">
                <b>In-bank Slides</b>
                <h4 class="text-center"><?=$inbank[0];?></h4>
              </div>
            </div>
          </div>
          <!-- ./col -->
          <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
            <!-- small box -->
            <div class="small-box bg-success">
              <div class="inner">
                <b>In-use Slides</b>
                <h4 class="text-center"><?=$inuse[0];?></h4>
              </div>
            </div>
          </div>
          <!-- ./col -->
          <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
            <!-- small box -->
            <div class="small-box bg-warning">
              <div class="inner">
                <b>Historical Slides</b>
                <h4 class="text-center"><?=$hist[0];?></h4>
              </div>
            </div>
          </div>
          <!-- ./col -->
          <!-- ./col -->
          <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
            <!-- small box -->
            <div class="small-box bg-danger">
              <div class="inner">
                <b>Empty Slots</b>
                <h4 class="text-center"><?=$eslots[0];?></h4>
              </div>
            </div>
          </div>
        </div>
        <!-- /.row -->
      </div> 
    </section>
    <!-- /.Quick Stat -->

    <!-- Search -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card card-info shadow-sm">
                <div class="card-header">
                    <h3 class="card-title">
                     <i class="fas fa-search mr-2"></i> Search Slides Position
                    </h3>
                </div>
              </div>  
              <div class="card-body">
                <form class="form-horizontal search-panel" id="form" autocomplete="off" method="Post" action="reports.php">
                  <div class="form-group row align-items-center">
                    <div class="col-lg-3 col-md-3">
                      <select class="form-control" name="species" id="species">
                        <option value="">Select species</option>
                        <option value="Negative">Negative</option>
                        <option value="Pf">Pf</option>
                        <option value="Pv">Pv</option>
                        <option value="Po">Po</option>
                        <option value="Pm">Pm</option>
                        <option value="Pk">Pk</option>
                        <option value="F+V">F+V</option>
                      </select>
                    </div>
                    <div class="col-lg-3 col-md-3">
                      <input type="text" name="donar" id="donar" class="form-control" placeholder="Enter donar number">
                    </div>
                    <div class="col-lg-3 col-md-3">
                      <button type="submit" name="submit" class="btn btn-primary btn-block"><i class="fa fa-search"></i>&nbsp Search</button>
                    </div> 
                    <div class="col-lg-3 col-md-3">
            
                      <a href="reports.php" class="btn btn-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</button></a>
                    </div> 
                  </div>
                </form> 
              <?php if(isset($_POST["submit"])){ ?>  
                <div class="table-responsive mt-3">
                <table id="table" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>Cabinet</th>
                      <th>Compartment</th>
                      <th>Rack</th>
                      <th>Donar#</th>
                      <th>Species</th>
                      <th>Slide#</th>
                      <th>Column#</th>
                      <th>Row#</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                     $qry = "SELECT rack_id, donar_number, malaria_species FROM banking_rack_specimen_informations WHERE status='Current'";

                      if(isset($_POST["species"]) AND $_POST["species"] != ""){ 
                        $species= $_POST["species"];  
                        $qry .= " AND malaria_species='$species'";
                      }

                      if(isset($_POST["donar"]) AND $_POST["donar"] != ""){ 
                        $donar= $_POST["donar"];  
                        $qry .= " AND donar_number='$donar'";
                      }
                      
                    
                    $qry .= " ORDER BY information_id DESC";
                   
                    $result = $conn->query($qry);
                    while($row1 = $result->fetch_row()) {

                      $rack = $conn->query("SELECT cabinet_id, rack_name, compartment FROM banking_rack_informations WHERE rack_id='$row1[0]'");
                      $rack = $rack->fetch_row();

                      $cabinet = $conn->query("SELECT cabinet_name FROM banking_cabinet_informations WHERE cabinet_id='$rack[0]'");
                      $cabinet = $cabinet->fetch_row();

                      $slot = $conn->query("SELECT t2.slide_no, t1.column_no, t1.row_no, t2.status FROM banking_slot_informations t1, banking_slide_informations t2 WHERE t1.slot_id=t2.slot_id AND t1.rack_id='$row1[0]' AND t2.status IN ('Current', 'In-use')");
                      
                      while($row2 = $slot->fetch_row()) {
                        echo("<tr class='text-center'><td>$cabinet[0]</td><td>$rack[2]</td><td>$rack[1]</td><td >$row1[1]</td><td>$row1[2]</td><td>$row2[0]</td><td>$row2[1]</td><td>$row2[2]</td><td>$row2[3]</td></tr>");
                      }  
                    }
                  ?>
                  </tbody>
                </table>
                </div>
              <?php } ?>    

              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <!-- /.search -->

    <!-- Search -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">Cabinet Occupancy Report</h3>
                </div>
              </div>  
              <div class="card-body">
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="reports.php">
                  <div class="form-group row">
                    <div class="col-lg-3 col-md-3">
                      <select class="form-control" name="cabinet" id="cabinet" required>
                        <option value="">Select cabinet</option>
                        <?php
                          $qry = $conn->query("SELECT cabinet_id, cabinet_name FROM banking_cabinet_informations ORDER BY cabinet_id DESC");
                          if ($qry) {
                            while($row = $qry->fetch_assoc()) {
                              $cab_id = htmlspecialchars($row['cabinet_id'] ?? '');
                              $cab_name = htmlspecialchars($row['cabinet_name'] ?? '');
                              $selected = (isset($_POST["cabinet"]) && (string)$_POST["cabinet"] === (string)($row['cabinet_id'] ?? '')) ? "selected" : "";
                              echo "<option value='$cab_id' $selected>$cab_name</option>";
                            }
                          }
                        ?>
                        
                      </select>
                    </div>
                    <div id="compDiv" class="col-lg-3 col-md-3">
                      <select class="form-control" name="compartment" id="compartment">
                        <option value="">Select Compartment</option>
                        <?php
                          if (isset($_POST["cabinet"]) && $_POST["cabinet"] !== "") {
                            $sel_cabinet = $conn->real_escape_string($_POST["cabinet"]);
                            $comp_qry = $conn->query("SELECT DISTINCT compartment FROM banking_rack_informations WHERE cabinet_id='$sel_cabinet' ORDER BY compartment ASC");
                            if ($comp_qry) {
                              while ($comp_row = $comp_qry->fetch_assoc()) {
                                $comp_val = htmlspecialchars($comp_row['compartment'] ?? '');
                                $comp_selected = (isset($_POST["compartment"]) && $_POST["compartment"] === ($comp_row['compartment'] ?? '')) ? "selected" : "";
                                echo "<option value='$comp_val' $comp_selected>$comp_val</option>";
                              }
                            }
                          }
                        ?>
                      </select>
                    </div>
                    <div class="col-lg-3 col-md-3">
                      <button type="submit" name="submit1" class="btn btn-primary btn-block"><i class="fa fa-search"></i>&nbsp Generate</button>
                    </div> 
                    <div class="col-lg-3 col-md-3">                    
                      <a href="reports.php" class="btn btn-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</a>
                    </div> 
                  </div>
                </form> 
              <?php 
                if(isset($_POST["submit1"])){ 
                  $cabinet = isset($_POST["cabinet"]) ? $conn->real_escape_string($_POST["cabinet"]) : ""; 
                  $compartment = isset($_POST["compartment"]) ? $conn->real_escape_string($_POST["compartment"]) : ""; 
              ?>  

                <div class="table-responsive mt-3">
                <table id="table" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>Cabinet</th>
                      <th>Compartment</th>
                      <th>Occupancy</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                     $qry = "SELECT t2.cabinet_name, t1.rack_id, t1.rack_name, t1.compartment, t2.slide_capacity FROM banking_rack_informations t1, banking_cabinet_informations t2 WHERE t1.cabinet_id=t2.cabinet_id AND t1.cabinet_id='$cabinet'";

                      if($compartment != ""){ 
                        $qry .= " AND t1.compartment='$compartment'";
                      }  
                    
                    $qry .= " ORDER BY t1.compartment ASC";
                   
                    $result = $conn->query($qry);
                    if ($result) {
                      while($row = $result->fetch_assoc()) {
                        $rack_id = $conn->real_escape_string($row['rack_id'] ?? '');
                        $cabinet_name = htmlspecialchars($row['cabinet_name'] ?? '');
                        $compartment_name = htmlspecialchars($row['compartment'] ?? '');
                        $rack_name = htmlspecialchars($row['rack_name'] ?? '');
                        $slide_capacity = (int)($row['slide_capacity'] ?? 0);

                        $eslot_res = $conn->query("SELECT COUNT(*) AS total FROM banking_slot_informations WHERE rack_id='$rack_id' AND status='Empty'");
                        $eslot_row = $eslot_res ? $eslot_res->fetch_assoc() : null;
                        $empty_slot = (int)($eslot_row['total'] ?? 0);
                        $filled_slot = max(0, $slide_capacity - $empty_slot);

                        $cslide_res = $conn->query("SELECT COUNT(*) AS total FROM banking_slot_informations t1, banking_slide_informations t2 WHERE t1.slot_id=t2.slot_id AND t1.rack_id='$rack_id' AND t2.status='Current'");
                        $cslide_row = $cslide_res ? $cslide_res->fetch_assoc() : null;
                        $inbank_slide = (int)($cslide_row['total'] ?? 0);

                        $uslide_res = $conn->query("SELECT COUNT(*) AS total FROM banking_slot_informations t1, banking_slide_informations t2 WHERE t1.slot_id=t2.slot_id AND t1.rack_id='$rack_id' AND t2.status='In-use'");
                        $uslide_row = $uslide_res ? $uslide_res->fetch_assoc() : null;
                        $inuse_slide = (int)($uslide_row['total'] ?? 0);

                        $str = "<b>$rack_name</b> - Empty Slide Slot : <b>$empty_slot</b> | Filled Slide Slot : <b>$filled_slot</b> | In-bank Slide : <b>$inbank_slide</b> | In-use Slide : <b>$inuse_slide</b>";
                        echo("<tr><td>$cabinet_name</td><td>$compartment_name</td><td>$str</td></tr>");
                      }
                    }
                  ?>
                  </tbody>
                </table> 
                </div>
              <?php } ?>    

              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <!-- /.search -->
 
    <!-- Table -->
     <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">View Cabinet Layout and Slide Position</h3>
                </div>
              </div>
              <div class="card-body">
                <div class="table-responsive">
                <table id="table" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>Cabinet</th>
                      <th>Compartments</th>
                      <th>Racks</th>
                      <th>SlideCapacity</th>
                      <th>ColumnsPerRack</th>
                      <th>RowsPerRack</th>
                      <th>Location</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    $sql = "SELECT cabinet_id, cabinet_name, compartments_per_cabinet, racks_per_compartment, slide_capacity, columns_per_rack, rows_per_rack, location FROM banking_cabinet_informations";                   
                    $result = $conn->query($sql);
                    while($row = $result->fetch_row()) {
                      echo("<tr class='text-center'><td><a href='reports.php?id=$row[0]&action=2'>$row[1]</a></td><td>$row[2]</td><td>$row[3]</td><td>$row[4]</td><td>$row[5]</td><td>$row[6]</td><td>$row[7]</td></tr>");
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
    <!-- /.table -->

    <?php if(isset($_GET["id"]) AND $_GET["id"]<>""){ ?>
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-body p-3 overflow-auto" style="max-width: 100%; overflow-x: auto;">
                <?php
                    $id = $conn->real_escape_string($_GET["id"]);
                    $data = ($conn->query("SELECT cabinet_name, compartments_per_cabinet, racks_per_compartment, slide_capacity FROM banking_cabinet_informations WHERE cabinet_id='$id'"));
                    $data = $data ? $data->fetch_row() : null;
                    
                    if (!empty($data)) {
                      $chkmap = $conn->query("SELECT COUNT(*) FROM banking_rack_informations WHERE cabinet_id='$id'");
                      $chkmap = $chkmap ? $chkmap->fetch_row() : null;

                      $chkrinfo = $conn->query("SELECT COUNT(*) FROM banking_rack_specimen_informations t1, banking_rack_informations t2 WHERE t1.rack_id=t2.rack_id AND t2.cabinet_id='$id'");
                      $chkrinfo = $chkrinfo ? $chkrinfo->fetch_row() : null;
                ?>
                  <table class="table table-bordered table-hover compartment-matrix-table" style="min-width: 100%; width: max-content; margin-bottom: 0;">
                    <thead>
                      <tr><th colspan="<?=$data[1];?>" class='text-center bg-gradient-primary'><?php echo htmlspecialchars($data[0] ?? '');?></th></tr>
                      <tr class="text-center">
                      <?php
                        for($i=1; $i<=$data[1]; $i++){
                          echo("<th style='min-width: 140px;'><a href='#' class='btn bg-gradient-primary btn-block text-nowrap'>Compartment $i</a></th>");
                        }
                      ?>  
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                        $r = 1;
                        for($m=1; $m<=$data[2]; $m++){
                            echo("<tr>");
                            for($n=1; $n<=$data[1]; $n++){
                                $compname = "Compartment $n";
                                $rackname = "Rack $r";
                                $rackid_res = $conn->query("SELECT rack_id FROM banking_rack_informations WHERE cabinet_id='$id' AND rack_name='$rackname' AND compartment='$compname'");
                                $rackid = $rackid_res ? $rackid_res->fetch_row() : null;
                                
                                if (!empty($rackid)) {
                                    $cur_rack_id = $rackid[0] ?? '';
                                    $eslots_res = $conn->query("SELECT count(*) FROM banking_slot_informations WHERE rack_id='$cur_rack_id' AND status='Empty'");
                                    $eslots = $eslots_res ? $eslots_res->fetch_row() : null;
                                    $empty_slots = $eslots[0] ?? 0;

                                    $chkrack_res = $conn->query("SELECT count(*) FROM banking_rack_specimen_informations WHERE rack_id='$cur_rack_id' AND status='Current'");
                                    $chkrack = $chkrack_res ? $chkrack_res->fetch_row() : null;
                                    $has_specimens = ($chkrack[0] ?? 0) > 0;

                                    if($has_specimens) { 
                                        $color = "bg-gradient-info"; 
                                        $lnk = "reports.php?id=$id&rid=$cur_rack_id"; 
                                    } else { 
                                        $color = "bg-gradient-secondary"; 
                                        $lnk = "reports.php?id=$id"; 
                                    }
                                    echo("<td class='text-center' style='min-width: 140px;'><a href='$lnk' class='btn $color btn-block text-nowrap'>$rackname : Total $data[3] slots($empty_slots empty)</a></td>");
                                } else {
                                    echo("<td class='text-center' style='min-width: 140px;'><a href='reports.php?id=$id' class='btn bg-gradient-secondary btn-block text-nowrap'>$rackname : Total $data[3] slots(0 empty)</a></td>");
                                }
                              
                            }  
                            $r++;
                            echo("</tr>");
                          }
                      ?>    
                    </tbody>
                  </table>
                <?php } ?>
              </div> 
              <div class="card-footer bg-white border-top-0 clearfix">
                <a href="reports.php" class="btn bg-gradient-secondary float-right">Hide</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <?php } ?>

    <?php 
      if(isset($_GET["id"]) AND isset($_GET["rid"])){ 
        $id = $_GET["id"];
        $rid = $_GET["rid"];
        $action = $iid = "";   // read-only view: the form below has no submit

        $data = ($conn->query("SELECT t1.information_id, t3.dzongkhag_code, t3.dzongkhag_name, t1.collection_facility_id, t2.center_name, t1.collection_date, t1.donar_number, t1.specimen_type, t1.blood_volume, t1.malaria_species, t1.parasite_count, t1.wbc_count, t1.density, t1.status, t1.remarks, t1.slide_start, t1.slide_end FROM banking_rack_specimen_informations t1, rcdc_commondb.ms_health_center_table t2, rcdc_commondb.ms_dzongkhag_table t3 WHERE t1.collection_facility_id=t2.center_id AND t2.dzongkhag_code=t3.dzongkhag_code AND t1.rack_id='$rid' AND t1.status='Current'"));
        $data = $data->fetch_row();
        
        $rowcol = ($conn->query("SELECT columns_per_rack, rows_per_rack, slide_capacity FROM banking_cabinet_informations WHERE cabinet_id='$id'"));
        $rowcol = $rowcol->fetch_row();

        $rack = $conn->query("SELECT rack_name, compartment FROM banking_rack_informations WHERE rack_id='$rid'");
        $rack = $rack->fetch_row();
    ?>
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-body">
                <div class="form-group row">
                  <label class="col-sm-12 text-primary col-form-label">Slide Information for <i>Cabinet <?=$id;?> <?=$rack[1];?> <?=$rack[0];?></i></label>
                </div>
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="iu-slides.php">
                  
                  <input type="hidden" name="action" value="<?=$action;?>" />
                  <input type="hidden" name="cid" value="<?=$id;?>" />
                  <input type="hidden" name="rid" value="<?=$rid;?>" />
                  <input type="hidden" name="iid" value="<?=$iid;?>" />

                  <div class="form-group row"> 
                    <label class="col-sm-3 col-form-label-sm">Dzongkhag(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="donar" id="donar" class="text-center form-control" value="<?=$data[2];?>" readonly>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Specimen collected from(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="donar" id="donar" class="text-center form-control" value="<?=$data[4];?>" readonly>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Specimen collection date(*)</label>
                    <div class="col-sm-3 input-group date" data-target-input="nearest">
                      <input type="text" class="form-control datetimepicker-input text-center" id="doc" name="doc" value="<?=$data[5];?>" placeholder="Collection date" readonly/>
                      <div class="input-group-append" data-target="#doc" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Specimen donar number(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="donar" id="donar" class="text-center form-control" value="<?=$data[6];?>" readonly placeholder="Donar number" readonly>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Slide number start(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="sstart" id="sstart" class="text-center form-control" value="<?=$data[15];?>" readonly placeholder="Slide start" readonly>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Slide number end(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="send" id="send" class="text-center form-control" value="<?=$data[16];?>" readonly placeholder="Slide end" readonly>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Specimen type(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="specimen" id="specimen" readonly>
                        <option value="<?=$data[7];?>"><?=$data[7];?></option>
                      </select> 
                    </div>
                     <label class="col-sm-3 col-form-label-sm">Blood volume(ml)(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="volume" id="volume" class="text-center form-control"  min="1" value="<?=$data[8];?>" readonly>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Malaria species(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="species" id="species" readonly>
                        <option value="<?=$data[9];?>"><?=$data[9];?></option>
                    </select>
                    </div>
                     <label class="col-sm-3 col-form-label-sm">Parasite count(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="parasite" id="parasite" class="text-center form-control"  min="0" value="<?=$data[10];?>" readonly>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">WBC count(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="wbc" id="wbc" class="text-center form-control"  min="0" value="<?=$data[11];?>" readonly>
                    </div>
                     <label class="col-sm-3 col-form-label-sm">Density(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="density" id="density" class="text-center form-control"  min="0" value="<?=$data[12];?>" readonly>
                    </div>
                  </div>

                   <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Status(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="status" id="status" readonly>
                        <option value="<?=$data[13];?>"><?=$data[13];?></option>
                      </select> 
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Remarks</label>
                    <div class="col-sm-3">
                      <textarea name="remarks" id="remarks" class="form-control" readonly><?=$data[14];?></textarea>
                    </div>
                  </div>

                  <div class="overflow-auto" style="max-width: 100%; overflow-x: auto; margin-bottom: 1rem;">
                  <table class="table table-bordered table-hover" style="min-width: 100%; width: max-content; margin-bottom: 0;">
                    <thead>
                      <tr class="text-center">
                      <?php
                        for($i=1; $i<=$rowcol[0]; $i++){
                          echo("<th style='min-width: 120px;'><a href='#' class='btn bg-gradient-primary btn-block text-nowrap'>Column $i</a></th>");
                        }
                      ?>  
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                        $cntr = 0;
                        for($m=1; $m<=$rowcol[1]; $m++){
                          echo("<tr>");
                          for($n=1; $n<=$rowcol[0]; $n++){
                            $slide = $conn->query("SELECT t1.slide_id, t1.slide_no, t2.slot_id, t1.status FROM banking_slide_informations t1, banking_slot_informations t2 WHERE t1.slot_id=t2.slot_id AND t2.rack_id='$rid' AND t2.column_no='$m' AND t2.row_no='$n' AND t1.status IN ('Current','In-use','Empty')");
                            $slide = $slide ? $slide->fetch_row() : null;
                            // Guard: fetch_row() returns null when the slot has no slide
                            $slideNo     = $slide[1] ?? '';
                            $slideStatus = $slide[3] ?? '';
                            echo("
                                <td class='text-center' style='min-width: 120px;'>
                                  <input type='text' class='text-center form-control' value='$slideNo : $slideStatus' readonly>
                               </td>");
                           $cntr++; 
                          }
                          echo("</tr>");  
                        } 
                      ?>    
                    </tbody>
                  </table>
                  </div>
                  <div class="clearfix">
                    <a href="reports.php?id=<?=$id;?>&rid=<?=$rid;?>" class="btn bg-gradient-secondary float-right">Hide</a>
                  </div>
                </form>
              </div> 
            </div>
          </div>
        </div>
      </div>
    </section>
    <?php } ?>
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->    
  <script>
    $(document).ready(function(){
      $("#cabinet").change(function(){
        var cabinet = $('#cabinet').val();
        $("#compDiv").load("load-compartment.php?cid="+cabinet);
      });
    });

    $(function () {
      $('#table').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        "order": [[ 0, "desc" ]]
      });
      // Match the collection-date wrapper markup below so the calendar icon is
      // functional in the reports view. Field itself is readonly (data-view
      // only), useCurrent:false prevents the picker pre-selecting today.
      $('#doc').datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false });
    });
  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
