<?php ob_start(); /* Buffer the page so a refused role returns a real 403, not a 200
     carrying a half-built page: header.php below emits <!DOCTYPE> before the
     authorisation guard runs, and bdsis_deny() discards this buffer to answer
     cleanly. Same guard-ordering fix as records/case.php and lab/lab.php. */ ?>
<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php");
  require_once("../includes/rbac.php");
  bdsis_require_can('report', null, 'reports');
  /* commondb's login is shared by ten surveillance systems, so $_SESSION['acl']
     holds whichever one the user last entered -- and when they arrive from a
     system that never wrote it, reading it raised "Undefined array key" twice
     on the filter row below. The BDSIS role comes from the database instead,
     which is the same source bdsis_scope() and the guard above already use. */
  $me      = bdsis_identity();
  $acl     = $me['acl'];
  $usercat = $me['usercat'];
  // Shared subject-list query, including the facility restriction.
  require_once("report-subject-filter.php");
?>
<!-- Data and page settings -->
<?php
  $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
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
                        <li class="breadcrumb-item active">Individual Reports</li>
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
                            <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="report-management-individual.php">
                                <div class="row search-filters">
                                    <?php  if($acl == 1 || $acl == 2){ ?>
                                        <div class="form-group col-sm-3">
                                            <select class="form-control select2" name="dzongkhag" id="dzongkhag">
                                                <option value="">All dzongkhag</option>
                                                <?php
                                                $result = $commondb->query($dzsql);
                                                while($row = $result->fetch_row()) {
                                                    echo("<option value='$row[0]'>$row[1]</option>");
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div id="facilityDiv" class="form-group col-sm-3">
                                            <select class="form-control" name="facility" id="facility">
                                                <option value="">All facility</option>
                                            </select> 
                                        </div>
                                    <?php } ?>
                                    <div class="form-group col-sm-3">
                                        <input class="form-control" type="text" name="subID" id="subID" placeholder="Subject ID" aria-label="Subject ID" />
                                    </div>
                                    <div class="form-group col-sm-3">
                                        <input class="form-control" type="text" name="patientName" id="patientName" placeholder="Patient Name" aria-label="Patient Name" />
                                    </div>
                                    <div class="form-group search-actions">
                                        <button type="submit" name="submit" class="btn bg-gradient-success"><i class="fa fa-search"></i>&nbsp Search</button>
                                        <a href="report-management-individual.php" class="btn bg-gradient-secondary"><i class="fa fa-sync"></i>&nbsp Reset</a>
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
     
    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Subject Report</h3>
                        </div>
                        <div class="card-body">
                            <table id="table" class="table table-bordered table-hover">
                                <thead>
                                    <tr class="text-center">
                                        <th>Subject ID</th>
                                        <th>Subject Name</th>
                                        <th>Gender</th>
                                        <th>Age</th>
                                        <th>Contact</th>
                                        <th>Subject Type</th>
                                        <th>Site</th>
                                        <th>Last Updated On</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                    $subjectqry = bdsis_report_subject_sql($commondb, $_POST, $acl, $usercat, $me['centre']);
                                    $result = $bdsis->query($subjectqry);

                                    while(list($sid,$name,$sex,$age,$cno,$cat,$cid,$opdt)=$result->fetch_row()){
                                        $ccodeqry=$commondb->query("SELECT center_code FROM ms_health_center_table WHERE center_id='".$cid."'");
                                        list($ccode)=$ccodeqry->fetch_row();
                                        if($cat==2){ $category = "Outbreak"; }else if($cat==1){ $category = "Surveillance";} else{$category = "";}
                                        echo("<tr>");
                                        echo("<td>$sid</td>");
                                        echo("<td>$name</td>");
                                        echo("<td>$sex</td>");
                                        echo("<td>$age</td>");
                                        echo("<td>$cno</td>");
                                        echo("<td>$category</td>");
                                        echo("<td>$ccode</td>");
                                        echo("<td>$opdt</td>");
                                        echo("<td>");
                                        echo("<a href='report-view.php?sid=$sid&action=1' class='mx-auto' ><i class='fa fa-eye' aria-hidden='true'></i></a>");
                                        echo("</td></tr>");
                                    }
                                ?>
                            </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.content -->
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
