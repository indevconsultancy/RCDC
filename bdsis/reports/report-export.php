<?php ob_start(); /* Buffer the page so a refused role returns a real 403, not a 200
     carrying a half-built page: header.php below emits <!DOCTYPE> before the
     authorisation guard runs, and bdsis_deny() discards this buffer to answer
     cleanly. Same guard-ordering fix as records/case.php and lab/lab.php. */ ?>
<!-- //in addition to existing report selection menu, create option to select routine or outbreak or all -->
<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php"); 
require_once("../includes/rbac.php");
bdsis_require_can('export');
?>
<!-- Data and page settings -->
<?php
/* Same shared-login problem as report-management-individual.php: one browser
   session is shared by ten surveillance systems, so these keys hold whichever
   one the user last entered -- and neither is set when they arrive from a
   system that never wrote them, which raised "Undefined array key" here under
   PHP 8. The user's BDSIS row is the authority for both. */
$me = bdsis_identity(); $cid = $me['centre']; $acl = $me['acl'];
  $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
  $fasql = "SELECT center_id, center_name FROM ms_health_center_table WHERE dsis=1 ORDER BY center_name";
  $ctsql = "SELECT center_id, center_name FROM ms_health_center_table WHERE dsis=1 AND center_id ='$cid'";
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
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Report Management</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Export Report</li>
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
                            <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="report-export-excel.php">
                                <div class="row search-filters">
                                    <?php if(bdsis_is_national()){ ?>
                                        <div class="form-group col-sm-3">
                                            <label class="col-form-label-sm">Health Facility:</label>
                                            <select class="form-control select2" name="facility" id="facility">
                                                <option value="All">All facility</option>
                                                <?php
                                                $result = $commondb->query($fasql);
                                                while($row = $result->fetch_row()) {
                                                    echo("<option value='$row[0]'>$row[1]</option>");
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    <?php } else { ?>
                                        <?php
                                            /* Scope-limited roles export their own facility only --
                                               report-export-excel.php pins the centre from the session and
                                               ignores whatever is posted. Shown read-only rather than as a
                                               select, which implied a choice that does not exist and carried
                                               a dead "Not Available" option. Driven by bdsis_is_national()
                                               instead of a literal acl==3 test, so district and other scoped
                                               roles get the control too -- they previously fell through to an
                                               empty else and saw no facility field at all. */
                                            $expMine  = bdsis_user_centre();
                                            $expLabel = $expMine;
                                            $eq = $commondb->prepare("SELECT CONCAT(h.center_name,' (',d.dzongkhag_name,')')
                                                                        FROM ms_health_center_table h
                                                                        JOIN ms_dzongkhag_table d ON d.dzongkhag_code=h.dzongkhag_code
                                                                       WHERE h.center_id=?");
                                            if($eq){ $eq->bind_param("s",$expMine); $eq->execute(); $eq->bind_result($eLbl);
                                                     if($eq->fetch() && $eLbl!==''){ $expLabel=$eLbl; } $eq->close(); }
                                        ?>
                                        <div class="form-group col-sm-3">
                                            <label class="col-form-label-sm" for="facilityShown">Health Facility:</label>
                                            <input type="text" class="form-control" id="facilityShown"
                                                   value="<?=htmlspecialchars($expLabel, ENT_QUOTES);?>" readonly
                                                   title="Exports are limited to your health facility" />
                                        </div>
                                    <?php } ?>
                                    <div class="form-group col-sm-3">
                                        <label class="col-form-label-sm">Category:</label>
                                        <select class="form-control" name="category" id="category">
                                            <option value="All">All</option>
                                            <option value="1">Surveillance</option>
                                            <option value="2">Outbreak</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-sm-3">
                                        <label class="col-form-label-sm">Hospital Visit Date From:</label>
                                        <input class="form-control datepicker" type="date" name="dtfrm" id="dtfrm" value="<?=date('Y').'-01-01';?>" placeholder="Hospital Visit Date From" aria-label="Hospital Visit Date From" />
                                    </div>
                                    <div class="form-group col-sm-3">
                                        <label class="col-form-label-sm">Hospital Visit Date To:</label>
                                        <input class="form-control datepicker" type="date" name="dtto" id="dtto" value="<?=date('Y-m-d');?>" placeholder="Hospital Visit Date To" aria-label="Hospital Visit Date To" />
                                    </div>
                                    <div class="form-group search-actions">
                                        <button type="submit" name="submit" class="btn bg-gradient-success"><i class="fa fa-table"></i>&nbsp Export</button>
                                        <a href="report-export.php" class="btn bg-gradient-secondary"><i class="fa fa-sync"></i>&nbsp Reset</a>
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
</div>
<!-- /.content-wrapper -->    
<script>
$(document).ready(function(){
    $("#dzongkhag").change(function(){
        var dzongkhag = $('#dzongkhag').val();
        $("#facilityDiv").load("../load-facility.php?dzongkhag="+dzongkhag);
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
    "order": [[ 7, "desc" ],[ 0, "desc" ]]
    });

    //Initialize Select2 Elements
    $('.select2').select2({
        theme: 'bootstrap4',
    });

});
</script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>