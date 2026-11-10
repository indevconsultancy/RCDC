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
    <!-- Month Name Function -->
    <?php require_once("blinded-functions.php"); ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Blinded Rechecking</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Blinded | List</li>
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
                            <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="blinded-list.php">
                                <?php
                                    // Filter column width: RCDC/Admin rows hold 6 elements (col-md-2),
                                    // district rows hold 4 (col-md-3) so the row stays a single full line.
                                    $fcol = ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) ? "col-md-2" : "col-md-3";
                                ?>
                                <div class="form-group row align-items-end">
                                <?php  if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                                    <div class="col-md-2 mb-2 mb-md-0">
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
                                    <div id="facilityDiv" class="col-md-2 mb-2 mb-md-0">
                                        <select class="form-control" name="facility" id="facility">
                                            <option value="">All facility</option>
                                        </select>
                                    </div>
                                <?php } ?>
                                    <div class="<?=$fcol;?> mb-2 mb-md-0">
                                        <select class="form-control" name="year" id="year">
                                            <option value="">All Years</option>
                                            <?php
                                            for ($i=date("Y"); $i >= 2010; $i--) {
                                                echo "<option value='$i'>$i</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="<?=$fcol;?> mb-2 mb-md-0">
                                        <select class="form-control" name="month" id="month">
                                            <option value="">All Months</option>
                                            <option value="1">January</option>
                                            <option value="2">February</option>
                                            <option value="3">March</option>
                                            <option value="4">April</option>
                                            <option value="5">MAy</option>
                                            <option value="6">June</option>
                                            <option value="7">July</option>
                                            <option value="8">August</option>
                                            <option value="9">September</option>
                                            <option value="10">October</option>
                                            <option value="11">November</option>
                                            <option value="12">December</option>
                                        </select>
                                    </div>
                                    <div class="<?=$fcol;?> mb-2 mb-md-0">
                                        <button type="submit" name="submit" class="btn bg-gradient-success btn-block"><i class="fa fa-search"></i>&nbsp Search</button>
                                    </div>
                                    <div class="<?=$fcol;?>">
                                        <a href="blinded-list.php" class="btn btn-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</a>
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
                            <h3 class="card-title">Blinded Rechecking List</h3>
                            <div class="card-tools">
                                <a href="blinded.php" class="btn btn-sm bg-gradient-primary">
                                    <i class="fa fa-plus"></i>&nbsp; New Blinded Rechecking
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="table" class="table table-bordered table-hover">
                                <thead>
                                    <tr class="text-center">
                                    <th>S.No</th>
                                    <th>Year</th>
                                    <th>Month</th>
                                    <th>Dzongkhag</th>
                                    <th>Facility</th>
                                    <th>Total Slides <br> Sent</th>
                                    <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                    $blindedqry = "SELECT t1.blinded_id, t1.facility_id, t1.month, t1.year, t3.dzongkhag_name, t2.center_name, t1.total_slides FROM blinded_informations t1, rcdc_commondb.ms_health_center_table t2, rcdc_commondb.ms_dzongkhag_table t3 WHERE t1.facility_id=t2.center_id AND t2.dzongkhag_code=t3.dzongkhag_code";

                                    if(isset($_POST["submit"])){                      
                                        // Filter values are escaped so they cannot break out of the facility scope below.
                                        if(isset($_POST["dzongkhag"]) AND $_POST["dzongkhag"] != ""){
                                            $dzongkhag= $conn->real_escape_string($_POST["dzongkhag"]);
                                            $blindedqry .= " AND t3.dzongkhag_code='$dzongkhag'";
                                        }
                                        if(isset($_POST["facility"]) AND $_POST["facility"] != ""){
                                            $facility= $conn->real_escape_string($_POST["facility"]);
                                            $blindedqry .= " AND t2.center_id='$facility'";
                                        }

                                        if(isset($_POST["month"]) AND $_POST["month"] != ""){
                                            $month= $conn->real_escape_string($_POST["month"]);
                                            $blindedqry .= " AND t1.month='$month'";
                                        }

                                        if(isset($_POST["year"]) AND $_POST["year"] != ""){
                                            $year= $conn->real_escape_string($_POST["year"]);
                                            $blindedqry .= " AND t1.year='$year'";
                                        }
                                    }

                                    // Facility users see only their own facility (authoritative profile facility).
                                    if(!mn_is_rcdc()){
                                        $facilityId = $conn->real_escape_string((string)mn_facility());
                                        $blindedqry .= " AND t2.center_id='$facilityId'";
                                    }

                                    $blindedqry .= " ORDER BY t1.year DESC, t2.center_name DESC LIMIT 500";
                                
                                    $result = $conn->query($blindedqry);
                                    $sno = 1;   // server-side fallback; DataTables re-sequences on sort/search/paginate
                                    /* Deleting a whole record is Administrator-only — delete.php enforces
                                       exactly that and answers 403 to anyone else, so the icon is rendered
                                       for no one else rather than offering a dead end. */
                                    $blCanDelete = mn_is_admin();
                                    while($row = $result->fetch_row()) {
                                        $blDel = '';
                                        if ($blCanDelete) {
                                            $blDel  = "<a href='delete.php?type=bi&id=$row[0]&biid=$row[0]'"
                                                    . " class='text-danger ml-3 js-confirm-delete' title='Delete this record'>"
                                                    . "<i class='fa fa-trash'></i></a>";
                                        }
                                        echo("<tr>
                                            <td class='text-center'>".$sno++."</td>
                                            <td class='text-center'>$row[3]</td>
                                            <td>".showMonth($row[2])."</td>
                                            <td>$row[4]</td>
                                            <td>$row[5]</td>
                                            <td class='text-center'>$row[6]</td>
                                            <td class='text-center'><a href='blinded.php?action=2&id=$row[0]' title='Open / Edit'><i class='fa fa-edit'></i></a>$blDel</td>
                                        </tr>");
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
/* ---------------------------------------------------------------------
   One confirmation for every delete in this module. Any link carrying
   `js-confirm-delete` asks the same question and, when confirmed, simply
   follows its own href — so the endpoint, its parameters and its server
   side checks are untouched.
   Deliberately says nothing about dependent records: the server decides
   what a delete removes, and listing it here only made the dialog long.
--------------------------------------------------------------------- */
$(document).on('click', '.js-confirm-delete', function (e) {
    e.preventDefault();
    var href = $(this).attr('href');
    if (!href) { return; }
    if (typeof Swal === 'undefined') {           // no dialog library: still ask
        if (confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
            window.location = href;
        }
        return;
    }
    Swal.fire({
        title: 'Confirm Deletion',
        text: 'Are you sure you want to delete this record? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        reverseButtons: true,                     // Cancel on the left, Delete on the right
        focusCancel: true,
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel',
        buttonsStyling: false,
        customClass: { confirmButton: 'btn btn-danger', cancelButton: 'btn btn-secondary mr-2' }
    }).then(function (res) {
        if (res && (res.isConfirmed || res.value)) { window.location = href; }
    });
});
$(document).ready(function(){
    $("#dzongkhag").change(function(){
        var dzongkhag = $('#dzongkhag').val();
        $("#facilityDiv").load("../load-facility.php?dzongkhag="+dzongkhag);
    });	
}); 

$(function () {
    var blTable = $('#table').DataTable({
    "paging": true,
    "lengthChange": true,
    "searching": true,
    "ordering": true,
    "info": true,
    "autoWidth": false,
    "responsive": true,
    // S.No (0) and Action (6) are presentation columns — not sortable/searchable.
    // The data-column order (Year/Month/Dzongkhag/Facility = 1/2/3/4) is unchanged.
    "columnDefs": [{ "orderable": false, "searchable": false, "targets": [0, 6] }],
    "order": [[ 1, "desc" ],[ 2, "desc" ],[ 3, "asc" ],[ 4, "asc" ]]
    });

    // Keep S.No sequential (1..N) for the current sort/search/page — recomputed
    // on every draw so numbering always matches what the user sees.
    blTable.on('draw.dt', function () {
        var info = blTable.page.info();
        blTable.column(0, { page: 'current' }).nodes().each(function (cell, i) {
            cell.innerHTML = info.start + i + 1;
        });
    });
    blTable.draw(false);

    //Initialize Select2 Elements
    $('.select2').select2({
        theme: 'bootstrap4',
    });

});
</script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>