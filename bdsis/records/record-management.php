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
  bdsis_require_module();   // refuse roles with no BDSIS record access
  include_once("../check-record.php");

  $isAdmin = false;
  if (isset($_SESSION['role']) && in_array(strtolower(trim((string)$_SESSION['role'])), array('administrator', 'admin'), true)) {
      $isAdmin = true;
  } elseif (function_exists('bdsis_permission_tier') && bdsis_permission_tier() === BDSIS_TIER_ADMIN) {
      $isAdmin = true;
  } elseif (isset($_SESSION['acl']) && (string)$_SESSION['acl'] === '1') {
      $isAdmin = true;
  } elseif (function_exists('bdsis_can') && bdsis_can('delete', 'records')) {
      $isAdmin = true;
  }
?>
<!-- Data and page settings -->
<?php
  $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
?>

<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>
<style>
.card-header {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    padding: 12px 15px;
}

.card-header .card-title {
    margin: 0;
}

.card-header .card-title a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.12);
}

.card-header .card-title a:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.18);
    text-decoration: none;
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
                    <h1>Record Management</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Record Management</li>
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
                                        /* The dzongkhag + facility pickers only make sense for a role that
                                           can actually reach more than one centre. Driven by the scope
                                           helper rather than a literal acl test, so the control shown always
                                           matches the scope the query will apply. */
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
                                    <?php } else { ?>
                                        <?php
                                            /* Facility user: show their own centre instead of an empty
                                               "Select Facility". It is disabled because it is the only
                                               possible value -- the query is scoped from the session either
                                               way, so the control is informational, not a choice. */
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
                                            <!-- Matches the Administrator's facility <select> exactly (same control,
                                                 same position, label-free) so the search bar looks identical for
                                                 every role. It is disabled because a facility user has only their
                                                 own centre; the query is scoped from the session regardless, so the
                                                 control is informational, not a choice. -->
                                            <select class="form-control" id="facilityShown" disabled
                                                    title="Records are limited to your health facility">
                                                <option><?=htmlspecialchars($rmName, ENT_QUOTES);?></option>
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
                            <?php /* New Case is the 'add' capability -- hidden from read-only roles
                                     (DHO, MoH, BFDA). case.php also refuses action=1 without it. */
                                  if(bdsis_can('add', 'records')){ ?>
                            <h3 class="card-title"><a href='<?php echo("case.php?action=1'");?>' class="btn-sm btn-primary btn-block">New Case</a></h3>
                            <?php } ?>
                        </div>
                        <div class="card-body">
                            <table id="table" class="table table-bordered table-hover">
                                <thead>
                                    <tr class="text-center">
                                        <th>ID</th>
                                        <th>Site</th>
                                        <th>Name</th>
                                        <th>Gender</th>
                                        <th>Age</th>
                                        <th>Contact</th>
                                        <th>Updated On</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                    /* Rows are no longer rendered here. This listing used to SELECT every
                                       matching subject (all 5,091 for an administrator), echo each into
                                       this <tbody>, and let DataTables paginate in the browser -- a 1.76 MB
                                       first load plus three full-table prefetch queries, to then hide all
                                       but ten rows.

                                       DataTables now runs in server-side mode: it requests one page from
                                       record-list.php, which applies the same scope, filters, status and
                                       capability rules and returns only that page. The filter, search and
                                       sort behaviour is unchanged; only the volume moved off the wire. */
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
    /* Server-side mode: DataTables asks record-list.php for one page at a time
       and never holds the whole table. The four search-form fields ride along on
       every request via ajax.data, so filtering, the global search box, sorting
       and paging all resolve in one bounded query. */
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
        "order": [[ 6, "desc" ]],
        "pageLength": 10,
        "ajax": {
            "url": "record-list.php",
            "type": "GET",
            "data": function (d) {
                d.dzongkhag   = $('#dzongkhag').val()   || '';
                d.facility    = $('#facility').val()    || '';
                d.subID       = $('#subID').val()       || '';
                d.patientName = $('#patientName').val() || '';
            }
        },
        // Action column is neither sortable nor part of the global search.
        "columnDefs": [ { "orderable": false, "searchable": false, "targets": 7 } ]
    });

    /* The Search button now reloads the table instead of reposting the whole
       page. Reset clears the fields and reloads. Behaviour is the same; the full
       page round-trip (and its full-table render) is gone. */
    $('#form').on('submit', function (e) {
        e.preventDefault();
        table.ajax.reload();
    });
    $('#form a.bg-gradient-secondary').on('click', function (e) {
        e.preventDefault();
        $('#dzongkhag').val('').trigger('change');
        $('#facility').val('');
        $('#subID').val('');
        $('#patientName').val('');
        table.ajax.reload();
    });

    //Initialize Select2 Elements
    $('.select2').select2({
        theme: 'bootstrap4',
    });

});
/* Role-based delete action for administrators */
$(document).on('click', '.btn-delete-record', function (e) {
    e.preventDefault();
    var id = $(this).data('id');
    if (!id) { return; }

    var performDelete = function () {
        $.ajax({
            url: 'record-delete.php',
            type: 'POST',
            data: { id: id },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (r) {
            if (r && r.success) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: r.message || 'Record deleted successfully',
                        timer: 1600,
                        showConfirmButton: false,
                        showCloseButton: true
                    });
                } else {
                    alert(r.message || 'Record deleted successfully');
                }
                $('#table').DataTable().ajax.reload(null, false);
            } else {
                var err = (r && r.message) ? r.message : 'Deletion Failed!!';
                if (window.Swal) {
                    Swal.fire({ icon: 'error', title: 'Error', text: err });
                } else {
                    alert(err);
                }
            }
        }).fail(function (xhr) {
            var msg = 'Deletion Failed!!';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            } else if (xhr.status === 403) {
                msg = 'Forbidden: Only administrators can delete records.';
            }
            if (window.Swal) {
                Swal.fire({ icon: 'error', title: 'Error', text: msg });
            } else {
                alert(msg);
            }
        });
    };

    if (window.Swal) {
        Swal.fire({
            title: 'Are you sure?',
            text: 'Are you sure you want to delete this record? This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then(function (result) {
            if (result.isConfirmed || result.value) {
                performDelete();
            }
        });
    } else {
        if (confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
            performDelete();
        }
    }
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
