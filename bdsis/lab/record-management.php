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
  bdsis_require_module();
  include_once("../check-record.php");
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
                    <h1>Record Management</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Lab Record Management</li>
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
                            <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="record-management.php">
                                <div class="row search-filters">
                                    <?php
                                        /* Same search-bar structure as the Records listing, so the two pages
                                           look identical for every role. Driven by bdsis_scope() (not a raw
                                           $_SESSION["acl"] test), so the control shown matches the scope the
                                           query applies -- a district user gets the dzongkhag/facility pickers
                                           here just as they do on Records. Data is scoped server-side either
                                           way; this only decides which controls are drawn. */
                                        $rmScope = bdsis_scope();
                                        if($rmScope === BDSIS_SCOPE_NATIONAL || $rmScope === BDSIS_SCOPE_DISTRICT){
                                    ?>
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
                                    <?php } else {
                                            /* Facility user: their own centre, shown as a disabled select that
                                               matches the national facility picker's styling and position. */
                                            $rmMine = bdsis_user_centre();
                                            $rmName = $rmMine;
                                            $rmq = $commondb->prepare("SELECT CONCAT(h.center_name,' (',d.dzongkhag_name,')')
                                                                         FROM ms_health_center_table h
                                                                         JOIN ms_dzongkhag_table d ON d.dzongkhag_code=h.dzongkhag_code
                                                                        WHERE h.center_id=?");
                                            if($rmq){ $rmq->bind_param("s",$rmMine); $rmq->execute();
                                                      $rmq->bind_result($rmLabel);
                                                      if($rmq->fetch() && $rmLabel!==''){ $rmName=$rmLabel; }
                                                      $rmq->close(); }
                                    ?>
                                        <div class="form-group col-sm-3">
                                            <select class="form-control" id="facilityShown" disabled
                                                    title="Records are limited to your health facility">
                                                <option><?=htmlspecialchars($rmName, ENT_QUOTES);?></option>
                                            </select>
                                        </div>
                                    <?php } ?>
                                    <div class="form-group col-sm-3">
                                        <input class="form-control" type="text" name="subID" id="subID" placeholder="Subject ID" aria-label="Subject ID" />
                                    </div>
                                    <div class="form-group search-actions">
                                        <button type="submit" name="submit" class="btn bg-gradient-success"><i class="fa fa-search"></i>&nbsp Search</button>
                                        <a href="record-management.php" class="btn bg-gradient-secondary"><i class="fa fa-sync"></i>&nbsp Reset</a>
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
                            <h3 class="card-title">Laboratory Record Management</h3>
                        </div>
                        <div class="card-body">
                            <table id="table" class="table table-bordered table-hover">
                                <thead>
                                    <tr class="text-center">
                                        <th>Subject ID</th>
                                        <th>Subject Type</th>
                                        <th>Site</th>
                                        <th>Last Updated On</th>
                                        <th>Bacteriology</th>
                                        <th>E.Coli PCR</th>
                                        <th>ELISA</th>	
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                    /* Rows are served by lab/record-list.php in DataTables
                                       server-side mode. The listing used to load up to 500
                                       subjects and run ~7 result queries per row (~3,500 per
                                       view) before the browser paginated; now one page is
                                       fetched at a time with identical cells and rules. */
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
    /* Server-side: one page per request from lab/record-list.php, carrying the
       search-form fields. Replaces loading up to 500 rows (with ~3,500 result
       queries) and paginating in the browser. */
    var table = $('#table').DataTable({
        "serverSide": true,
        "processing": true,
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        "order": [[ 3, "desc" ],[ 0, "desc" ]],
        "pageLength": 10,
        "ajax": {
            "url": "record-list.php",
            "type": "GET",
            "data": function (d) {
                d.dzongkhag = $('#dzongkhag').val() || '';
                d.facility  = $('#facility').val()  || '';
                d.subID     = $('#subID').val()     || '';
            }
        },
        // Bacteriology/PCR/ELISA (4,5,6) are computed; Action (7) is controls.
        "columnDefs": [ { "orderable": false, "searchable": false, "targets": [4,5,6,7] } ]
    });

    $('#form').on('submit', function (e) { e.preventDefault(); table.ajax.reload(); });
    $('#form a.bg-gradient-secondary').on('click', function (e) {
        e.preventDefault();
        $('#dzongkhag').val('').trigger('change');
        $('#facility').val('');
        $('#subID').val('');
        table.ajax.reload();
    });

    //Initialize Select2 Elements
    $('.select2').select2({
        theme: 'bootstrap4',
    });

});

/* Delete in place: confirm, delete over AJAX, then reload just the table rows
   (page, filters, sort and scroll are kept via reload(null,false)) and show the
   same message as a toast. The <a href> still points at delete.php, and delete.php
   still serves its popup-and-redirect page to any non-AJAX request, so behaviour,
   permissions and the fallback are unchanged -- only the reload is gone. */
function confirmDelete(el) {
    if (confirm("Do you really want to DELETE this record?") != true) { return false; }
    $.ajax({ url: el.getAttribute('href'), dataType: 'json',
             headers: { 'X-Requested-With': 'XMLHttpRequest' } })
     .done(function (r) {
        if (window.Swal) { Swal.fire({ icon: r.ok ? 'success' : 'error', text: r.msg,
                                       timer: 1600, showConfirmButton: false, showCloseButton: true }); }
        if (r.ok) { $('#table').DataTable().ajax.reload(null, false); }
     })
     .fail(function () { if (window.Swal) { Swal.fire({ icon: 'error', text: 'Deletion Failed!!' }); } });
    return false;   // handled in place; never navigate away
}
</script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
