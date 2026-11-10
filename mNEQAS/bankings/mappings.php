<!-- Check session -->
<?php require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc(); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php"); 
  $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
?>
<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<style>
/* Page-level containment & unified full-width alignment */
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

/* Ensure all cards take full container width and align cleanly */
.content .card {
    width: 100%;
    max-width: 100%;
    margin-bottom: 1.25rem;
}

/* Upper Compartment Matrix Table */
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

/* Bottom Slot Matrix Table */
.slot-matrix-table {
    min-width: 100%;
    width: max-content;
    margin-bottom: 0;
}

.slot-matrix-table th,
.slot-matrix-table td {
    min-width: 180px;
    vertical-align: middle;
}

.slot-matrix-table .form-control {
    min-width: 110px;
}

.slot-matrix-table .form-check-inline {
    white-space: nowrap;
    margin-top: 4px;
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
            <h1>Mapping</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Banking | Mapping</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>
 
    <!-- Table -->
     <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card w-100">
              <div class="card-body">
                <div class="table-responsive">
                  <table id="table" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>CabinetID</th>
                      <th>CabinetName</th>
                      <th>Mapping</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    $sql = "SELECT cabinet_id, cabinet_name FROM banking_cabinet_informations";                   
                    $result = $conn->query($sql);
                    while($row = $result->fetch_row()) {
                      $map = "Unmapped";
                      $rak = ($conn->query("SELECT COUNT(*) FROM banking_rack_informations WHERE cabinet_id='$row[0]'"));
                      $rak = $rak->fetch_row();
                      if($rak[0] > 0) $map = "Mapped";
                      echo("<tr class='text-center'><td><a href='mappings.php?id=$row[0]'>$row[0]</a></td><td>$row[1]</td><td>$map</td></tr>");
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
            <div class="card w-100">
              <div class="card-header bg-white">
                <h3 class="card-title text-primary font-weight-bold">Map Compartments, Racks and Slide Slots</h3>
              </div>
              <form class="form-horizontal" id="formMappings" autocomplete="off" method="Post" action="iu-mappings.php">
                <?php
                    $id = $conn->real_escape_string($_GET["id"]);
                    $data = ($conn->query("SELECT cabinet_name, compartments_per_cabinet, racks_per_compartment, slide_capacity FROM banking_cabinet_informations WHERE cabinet_id='$id'"));
                    $data = $data ? $data->fetch_row() : null;
                    
                    if (!empty($data)) {
                      $chkmap = $conn->query("SELECT COUNT(*) FROM banking_rack_informations WHERE cabinet_id='$id'");
                      $chkmap = $chkmap ? $chkmap->fetch_row() : null;

                      $chkrinfo = $conn->query("SELECT COUNT(*) FROM banking_rack_specimen_informations t1, banking_rack_informations t2 WHERE t1.rack_id=t2.rack_id AND t2.cabinet_id='$id'");
                      $chkrinfo = $chkrinfo ? $chkrinfo->fetch_row() : null;
                      
                      $delete = "";
                      $submit = "<button type='submit' class='btn bg-gradient-success'>Map Compartment-Rack-Slot</button>";

                      if(($chkmap[0] ?? 0) > 0 && ($chkrinfo[0] ?? 0) == 0) $delete = "<a href='delete.php?id=$id&type=map' class='btn bg-gradient-danger mr-2'>Delete</a>";

                      if(($chkmap[0] ?? 0) > 0) $submit = "";
                ?>
                  <input type="hidden" name="cid" value="<?=$id;?>" />
                  <input type="hidden" name="comp" value="<?=$data[1];?>" />
                  <input type="hidden" name="rack" value="<?=$data[2];?>" />
                  <input type="hidden" name="slot" value="<?=$data[3];?>" />

                  <div class="card-body p-3 overflow-auto" style="max-width: 100%; overflow-x: auto;">
                    <table class="table table-bordered table-hover compartment-matrix-table" style="min-width: 100%; width: max-content; margin-bottom: 0;">
                      <thead>
                        <tr><th colspan="<?=$data[1];?>" class="text-center bg-gradient-primary"><?php echo htmlspecialchars($data[0] ?? '');?></th></tr>
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
                                if(($chkmap[0] ?? 0) == 0) {
                                  echo("<td class='text-center' style='min-width: 140px;'><a href='#' class='btn bg-gradient-secondary btn-block text-nowrap'>Rack $r : " . ($data[3] ?? 0) . " slots</a></td>");
                                } else {
                                  $compname = "Compartment $n";
                                  $rackname = "Rack $r";
                                  $rackid = $conn->query("SELECT rack_id FROM banking_rack_informations WHERE cabinet_id='$id' AND rack_name='$rackname' AND compartment='$compname'");
                                  $rackid = $rackid ? $rackid->fetch_row() : null;
                                  $cur_rack_id = $rackid[0] ?? '';
                                  $eslots = $conn->query("SELECT count(*) FROM banking_slot_informations WHERE rack_id='$cur_rack_id' AND status='Empty'");
                                  $eslots = $eslots ? $eslots->fetch_row() : null;
                                  $empty_slots = $eslots[0] ?? 0;
                                  $chkrack = $conn->query("SELECT count(*) FROM banking_rack_specimen_informations WHERE rack_id='$cur_rack_id' AND status='Current'");
                                  $chkrack = $chkrack ? $chkrack->fetch_row() : null;
                                  $color = (($chkrack[0] ?? 0) > 0) ? "bg-gradient-info" : "bg-gradient-secondary";

                                  echo("<td class='text-center' style='min-width: 140px;'><a href='mappings.php?id=$id&rid=$cur_rack_id' class='btn $color btn-block text-nowrap'>$rackname : Total " . ($data[3] ?? 0) . " slots($empty_slots empty)</a></td>");
                                }
                              }  
                              $r++;
                              echo("</tr>");
                            }
                        ?>    
                      </tbody>
                    </table>
                  </div>
                  <div class="card-footer bg-white clearfix">
                    <?=$submit;?>
                    <?=$delete;?>
                    <a href="mappings.php" class="btn bg-gradient-secondary float-right">Cancel</a>
                  </div>
                <?php } ?>
              </form>
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
        $iid = $delete = $mapslide = "";
        $action = 1;
        $data = ($conn->query("SELECT t1.information_id, t3.dzongkhag_code, t3.dzongkhag_name, t1.collection_facility_id, t2.center_name, t1.collection_date, t1.donar_number, t1.specimen_type, t1.blood_volume, t1.malaria_species, t1.parasite_count, t1.wbc_count, t1.density, t1.status, t1.remarks, t1.slide_start, t1.slide_end FROM banking_rack_specimen_informations t1, rcdc_commondb.ms_health_center_table t2, rcdc_commondb.ms_dzongkhag_table t3 WHERE t1.collection_facility_id=t2.center_id AND t2.dzongkhag_code=t3.dzongkhag_code AND t1.rack_id='$rid' AND t1.status='Current'"));
        $data = $data->fetch_row();
        // Guard: no 'Current' specimen mapped to this rack yet -> fetch_row() returns
        // null. Fill with blanks (17 selected columns) so the form fields below render
        // empty instead of throwing "array offset on null" for every $data[x] access.
        if ($data === null) { $data = array_fill(0, 17, ''); }
        if($data[0] != "") {
          $iid = $data[0];
          $action = 2;
          $chkslide = $conn->query("SELECT COUNT(*) FROM banking_slot_informations WHERE status='Filled' AND rack_id='$rid'");
          $chkslide = $chkslide ? $chkslide->fetch_row() : null;
          if(($chkslide[0] ?? 0) == 0)  $delete = "<a href='delete.php?id=$id&iid=$data[0]&type=rinfo' class='btn bg-gradient-danger'>Delete</a>";
          $mapslide = "<a href='mappings.php?id=$id&rid=$rid&iid=$data[0]' class='btn bg-gradient-info'>Assign Slides to Rack</a>"; 
        }


        $rack = $conn->query("SELECT rack_name, compartment FROM banking_rack_informations WHERE rack_id='$rid'");
        $rack = $rack->fetch_row();
        if ($rack === null) { $rack = array('', ''); }
    ?>
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card w-100">
              <div class="card-header bg-white">
                <h3 class="card-title text-primary font-weight-bold">Specimen Informations for <i>Cabinet <?=$id;?> <?=$rack[1];?> <?=$rack[0];?></i></h3>
              </div>
              <div class="card-body">
                <form class="form-horizontal" id="formSpecimens" autocomplete="off" method="Post" action="iu-specimens.php">
                  <input type="hidden" name="action" value="<?=$action;?>" />
                  <input type="hidden" name="cid" value="<?=$id;?>" />
                  <input type="hidden" name="rid" value="<?=$rid;?>" />
                  <input type="hidden" name="iid" value="<?=$iid;?>" />

                  <div class="form-group row"> 
                    <label class="col-sm-3 col-form-label-sm">Dzongkhag(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="dzongkhag" id="dzongkhag" required>
                        <option value="<?=$data[1];?>"><?= (($data[1] ?? '') !== '') ? $data[2] : 'Select Dzongkhag' ?></option>
                        <?php
                          $result = $commondb->query($dzsql);
                          while($row = $result->fetch_row()) {
                            echo("<option value='$row[0]'>$row[1]</option>");
                          }
                        ?>
                      </select>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Specimen collected from(*)</label>
                    <div id="facilityDiv" class="col-sm-3">
                      <select class="form-control" name="facility" id="facility" required>
                        <option value="<?=$data[3];?>"><?= (($data[3] ?? '') !== '') ? $data[4] : 'Select Health Facility' ?></option>
                      </select> 
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Specimen collection date(*)</label>
                    <div class="col-sm-3 input-group date" data-target-input="nearest">
                      <input type="text" class="form-control datetimepicker-input text-center" id="doc" name="doc" value="<?=$data[5];?>" placeholder="Collection date" required/>
                      <div class="input-group-append" data-target="#doc" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Specimen donar number(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="donar" id="donar" class="text-center form-control" value="<?=$data[6];?>" required placeholder="Donar number" required>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Slide number start(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="sstart" id="sstart" class="text-center form-control" value="<?=$data[15];?>" required placeholder="Slide start" required>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Slide number end(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="send" id="send" class="text-center form-control" value="<?=$data[16];?>" required placeholder="Slide end" required>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Specimen type(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="specimen" id="specimen" required>
                        <option value="<?=$data[7];?>"><?= (($data[7] ?? '') !== '') ? $data[7] : 'Select Specimen' ?></option>
                        <option value="Whole Blood">Whole Blood</option>
                      </select> 
                    </div>
                     <label class="col-sm-3 col-form-label-sm">Blood volume(ml)(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="volume" id="volume" class="text-center form-control"  min="1" value="<?=$data[8];?>" required placeholder="Blood volumen in ml" required>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Malaria species(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="species" id="species" required onchange="reqPositive(this.value);">
                        <option value="<?=$data[9];?>"><?= (($data[9] ?? '') !== '') ? $data[9] : 'Select Species' ?></option>
                        <option value="Negative">Negative</option>
                        <option value="Pf">Pf</option>
                        <option value="Pv">Pv</option>
                        <option value="Po">Po</option>
                        <option value="Pm">Pm</option>
                        <option value="Pk">Pk</option>
                        <option value="F+V">F+V</option>
                    </select>
                    </div>
                     <label class="col-sm-3 col-form-label-sm">Parasite count(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="parasite" id="parasite" class="text-center form-control"  min="0" value="<?=$data[10];?>" required placeholder="Parasite count" required>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">WBC count(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="wbc" id="wbc" class="text-center form-control"  min="0" value="<?=$data[11];?>" required placeholder="WBC count">
                    </div>
                     <label class="col-sm-3 col-form-label-sm">Density(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="density" id="density" class="text-center form-control"  min="0" value="<?=$data[12];?>" placeholder="PDensity" readonly>
                    </div>
                  </div>

                   <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Status(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="status" id="status" required>
                        <option value="<?=$data[13];?>"><?= (($data[13] ?? '') !== '') ? $data[13] : 'Select Status' ?></option>
                        <option value="Current">Current</option>
                        <option value="Historical">Historical</option>
                      </select> 
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Remarks</label>
                    <div class="col-sm-3">
                      <textarea name="remarks" id="remarks" class="form-control"><?=$data[14];?></textarea>
                    </div>
                  </div>

                  <div class="form-group row mb-0">
                    <div class="col-sm-12">
                      <button type='submit' class='btn bg-gradient-success'>Submit</button>
                      <?=$mapslide;?>
                      <?=$delete;?>
                      <a href="mappings.php?id=<?=$id;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
                    </div>  
                  </div>
                </form>
              </div> 
            </div>
          </div>
        </div>
      </div>
    </section>
    <?php } ?>

    <?php 
      if(isset($_GET["id"]) AND isset($_GET["rid"]) AND isset($_GET["iid"])){ 
        $id = $_GET["id"];
        $rid = $_GET["rid"];
        $iid = $_GET["iid"];
        $action = 1;
        $stslnk1 = $stslnk2 = $unassign = $delete = "";
        
        $rowcolRes = $conn->query("SELECT columns_per_rack, rows_per_rack, slide_capacity FROM banking_cabinet_informations WHERE cabinet_id='$id'");
        $rowcol = $rowcolRes ? $rowcolRes->fetch_row() : null;
        if (!is_array($rowcol)) { $rowcol = [0, 0, 0]; }

        $rackRes = $conn->query("SELECT rack_name, compartment FROM banking_rack_informations WHERE rack_id='$rid'");
        $rack = $rackRes ? $rackRes->fetch_row() : null;
        if (!is_array($rack)) { $rack = ['', '']; }

        $sinfoRes = $conn->query("SELECT RIGHT(YEAR(collection_date),2), donar_number, slide_start, slide_end FROM banking_rack_specimen_informations WHERE information_id='$iid'");
        $sinfo = $sinfoRes ? $sinfoRes->fetch_row() : null;
        if (!is_array($sinfo)) { $sinfo = ['', '', 0, 0]; }
        
        $chkslotRes = $conn->query("SELECT COUNT(*) FROM banking_slot_informations WHERE rack_id='$rid' AND status='Filled'");
        $chkslot = $chkslotRes ? $chkslotRes->fetch_row() : null;
        if(($chkslot[0] ?? 0) > 0){
          $delete = "<a href='delete.php?id=$id&rid=$rid&type=sinfo' class='btn bg-gradient-danger float-right'>Delete</a> ";
          $unassign = "<a href='delete.php?id=$id&rid=$rid&type=unassign' class='btn bg-gradient-info float-right'>Unassign Slides</a>";
          $action = 2;
          $stslnk1="<a href='slide-status.php?id=$id&rid=$rid&iid=$iid&sts=Current' class='btn bg-gradient-info'>Mark All as Current</a>";
          $stslnk2="<a href='slide-status.php?id=$id&rid=$rid&iid=$iid&sts=In-use' class='btn bg-gradient-info'>Mark All as In-use</a>";
        } 
    ?>
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card w-100">
              <div class="card-header bg-white">
                <h3 class="card-title text-primary font-weight-bold">Slide Information for <i>Cabinet <?=$id;?> <?=$rack[1];?> <?=$rack[0];?></i></h3>
              </div>
              <form class="form-horizontal" id="formSlides" autocomplete="off" method="Post" action="iu-slides.php">
                <input type="hidden" name="action" value="<?=$action;?>" />
                <input type="hidden" name="cid" value="<?=$id;?>" />
                <input type="hidden" name="rid" value="<?=$rid;?>" />
                <input type="hidden" name="iid" value="<?=$iid;?>" />

                <div class="card-body p-3 overflow-auto" style="max-width: 100%; overflow-x: auto;">
                  <table class="table table-bordered table-hover slot-matrix-table" style="min-width: 100%; width: max-content; margin-bottom: 0;">
                    <thead>
                      <tr class="text-center">
                      <?php
                        for($i=1; $i<=$rowcol[0]; $i++){
                          echo("<th style='min-width: 180px;'><a href='#' class='btn bg-gradient-primary btn-block text-nowrap'>Column $i</a></th>");
                        }
                      ?>  
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                        if($action == 1){
                          $sno = $sinfo[2];
                          for($m=1; $m<=$rowcol[1]; $m++){
                              echo("<tr>");
                              for($n=1; $n<=$rowcol[0]; $n++){
                                if($sno <= $sinfo[3]) $slideno = "$sinfo[0]-$sinfo[1]-$sno";
                                else $slideno = "";
                                echo("
                                  <td class='text-center' style='min-width: 140px;'>
                                    <input type='hidden' name='row[]' class='text-center form-control'  value='$n'>
                                    <input type='hidden' name='column[]' class='text-center form-control'  value='$m'>
                                    <input type='text' name='slideno[]' class='text-center form-control'  value='$slideno'>
                                 </td>");
                                $sno += $rowcol[1];
                              }  
                              $sno = $sno - $rowcol[2] +1;
                              echo("</tr>");
                            }
                        } 
                        else{
                          $cntr = 0;
                          for($m=1; $m<=$rowcol[1]; $m++){
                            echo("<tr>");
                            for($n=1; $n<=$rowcol[0]; $n++){
                              $slideRes = $conn->query("SELECT t1.slide_id, t1.slide_no, t2.slot_id, t1.status, t2.status AS slot_status FROM banking_slot_informations t2 LEFT JOIN banking_slide_informations t1 ON t1.slot_id=t2.slot_id AND t1.status IN ('Current','In-use','Empty') WHERE t2.rack_id='$rid' AND t2.column_no='$m' AND t2.row_no='$n' LIMIT 1");
                              $slot = $slideRes ? $slideRes->fetch_assoc() : null;

                              $slide_no  = (is_array($slot) && isset($slot['slide_no'])) ? $slot['slide_no'] : ($slot[1] ?? '');
                              $slide_id  = (is_array($slot) && isset($slot['slide_id'])) ? $slot['slide_id'] : ($slot[0] ?? '');
                              $slot_id   = (is_array($slot) && isset($slot['slot_id'])) ? $slot['slot_id'] : ($slot[2] ?? '');
                              $status    = $slot['status'] ?? ($slot['slot_status'] ?? ($slot[3] ?? 'empty'));
                              $species   = $slot['species'] ?? ($sinfo[1] ?? '');
                              $donor_id  = $slot['donor_id'] ?? ($sinfo[1] ?? '');

                              if (empty($slot_id)) {
                                $fallbackSlot = $conn->query("SELECT slot_id, status FROM banking_slot_informations WHERE rack_id='$rid' AND column_no='$m' AND row_no='$n' LIMIT 1");
                                if ($fallbackSlot && ($fsRow = $fallbackSlot->fetch_assoc())) {
                                  $slot_id = $fsRow['slot_id'] ?? '';
                                  if (empty($status) || $status === 'empty') {
                                    $status = $fsRow['status'] ?? 'Empty';
                                  }
                                }
                              }

                              $rdsts1 = ($status == "Current") ? "Checked" : "";
                              $rdsts2 = ($status == "In-use") ? "Checked" : "";
                              $rdid1 = "id1$cntr";
                              $rdid2 = "id2$cntr";
                              $rdname = "status$cntr";

                              echo("
                                  <td class='text-center' style='min-width: 180px;'>
                                    <input type='hidden' name='slideid[]' class='text-center form-control' value='" . htmlspecialchars((string)$slide_id) . "'>
                                    <input type='hidden' name='slotid[]' class='text-center form-control' value='" . htmlspecialchars((string)$slot_id) . "'>
                                    <input type='text' name='slideno[]' class='text-center form-control' value='" . htmlspecialchars((string)$slide_no) . "'>
                                    <div class='form-check form-check-inline mt-1' style='white-space: nowrap;'>
                                      <input type='radio' name='$rdname' id='$rdid1' class='form-check-input' value='Current' $rdsts1>
                                      <label class='form-check-label mr-2' for='$rdid1'>Current</label>
                                      <input type='radio' name='$rdname' id='$rdid2' class='form-check-input' value='In-use' $rdsts2>
                                      <label class='form-check-label' for='$rdid2'>In-use</label>
                                    </div>  
                                 </td>");
                             $cntr++; 
                            }
                            echo("</tr>");  
                          }
                        }   
                      ?>    
                    </tbody>
                  </table>
                </div>
                <div class="card-footer bg-white clearfix">
                  <button type='submit' class='btn bg-gradient-success'>Submit</button>
                  <?=$stslnk1;?>
                  <?=$stslnk2;?>
                  <a href="mappings.php?id=<?=$id;?>&rid=<?=$rid;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
                  <?=$delete;?>
                  <?=$unassign;?>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>
    <?php } ?>

  </div>  
  <script>
    $(document).ready(function(){
      $(document).on('change', '#dzongkhag', function(){
        var dzongkhag = $(this).val() || '';
        $("#facilityDiv").load("../load-facility.php?dzongkhag=" + encodeURIComponent(dzongkhag));
      });
    }); 

    $(function () {
      $('#table').DataTable({
        "paging": true,
        "lengthChange": false,
        "searching": false,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        "order": [[ 0, "desc" ]]
      });
      $('#doc').datetimepicker({  format: 'YYYY-MM-DD'});
    });

    function reqPositive(species){
      if(species == "Negative"){
        $("#parasite").val("0"); $("#parasite").attr("readonly", true);
        $("#wbc").val("0"); $("#wbc").attr("readonly", true);
      }
      else{  
        $("#parasite").val(""); $("#parasite").attr("readonly", false);
        $("#wbc").val(""); $("#wbc").attr("readonly", false);
      }
    }

    // AJAX submission for Slide Mapping (#formSlides)
    $(document).on('submit', '#formSlides', function(e) {
      e.preventDefault();
      var form = this;
      var $btn = $(form).find('button[type="submit"]');
      var origHtml = $btn.html();

      $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');

      var formData = new FormData(form);
      formData.append('format', 'json');
      formData.append('ajax', '1');

      $.ajax({
        url: form.action || 'iu-slides.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
          $btn.prop('disabled', false).html(origHtml);
          var isSuccess = resp && (resp.status === 'success' || resp.success === true);
          var msg = resp && (resp.message || resp.msg) ? (resp.message || resp.msg) : (isSuccess ? 'Mapped Successfully!!' : 'Mapping Failed!!');

          if (isSuccess) {
            Swal.fire({
              icon: 'success',
              title: 'Success',
              text: msg,
              confirmButtonText: 'OK',
              showCloseButton: true
            }).then(function() {
              if (resp.redirect) {
                window.location = resp.redirect;
              } else {
                window.location.reload();
              }
            });
          } else {
            Swal.fire({
              icon: 'error',
              title: 'Mapping Failed',
              text: msg,
              confirmButtonText: 'Close',
              showCloseButton: true
            });
          }
        },
        error: function(xhr, status, error) {
          $btn.prop('disabled', false).html(origHtml);
          console.error('Slide mapping submission failed:', status, error, xhr.responseText);
          var serverMsg = '';
          if (xhr.responseJSON) {
            serverMsg = xhr.responseJSON.message || xhr.responseJSON.error || '';
          } else if (xhr.responseText) {
            try {
              var parsed = JSON.parse(xhr.responseText);
              serverMsg = parsed.message || parsed.error || '';
            } catch(e) {
              if (xhr.responseText.length > 0 && xhr.responseText.length < 300) {
                serverMsg = xhr.responseText.replace(/<[^>]*>/g, '').trim();
              }
            }
          }
          Swal.fire({
            icon: 'error',
            title: 'Submission Error',
            text: serverMsg || 'An unexpected error occurred while mapping slides. Please try again.',
            confirmButtonText: 'OK',
            showCloseButton: true
          });
        }
      });
    });

    <?php if (isset($_GET['status']) && $_GET['status'] === 'success') { ?>
    $(function() {
      Swal.fire({
        icon: 'success',
        title: 'Success',
        text: <?= json_encode($_GET['msg'] ?? 'Mapped Successfully!!') ?>,
        confirmButtonText: 'OK',
        showCloseButton: true
      });
    });
    <?php } elseif (isset($_GET['status']) && $_GET['status'] === 'error') { ?>
    $(function() {
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: <?= json_encode($_GET['msg'] ?? 'Mapping Failed!!') ?>,
        confirmButtonText: 'OK',
        showCloseButton: true
      });
    });
    <?php } ?>
  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
