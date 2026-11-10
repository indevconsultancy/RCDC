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
  $hcsql = "SELECT center_id, center_name FROM ms_health_center_table";
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
            <h1>Monitoring and Supervision</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Monitoring and Supervision | List</li>
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
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="ms-list.php">

                  <?php
                    // Filter column width: RCDC/Admin rows hold 6 elements (col-md-2),
                    // district rows hold 4 (col-md-3) so the row stays a single full line.
                    $fcol = ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) ? "col-md-2" : "col-md-3";
                  ?>
                  <div class="form-group row align-items-end">
                  <?php  if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                    <div class="col-md-2 mb-2 mb-md-0">
                      <select class="form-control" name="dzongkhag" id="dzongkhag">
                        <option value="All">All dzongkhag</option>
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
                      <div class="input-group date" id="dfrom" data-target-input="nearest">
                        <input type="text" class="form-control datetimepicker-input text-center" data-target="#dfrom" name="dfrom"  placeholder="Visit Date From"/>
                        <div class="input-group-append" data-target="#dfrom" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                      </div>
                    </div>

                    <div class="<?=$fcol;?> mb-2 mb-md-0">
                      <div class="input-group date" id="dto" data-target-input="nearest">
                        <input type="text" class="form-control datetimepicker-input text-center" data-target="#dto" name="dto"  placeholder="Visit Date To"/>
                        <div class="input-group-append" data-target="#dto" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                      </div>
                    </div>

                    <div class="<?=$fcol;?> mb-2 mb-md-0">
                      <button type="submit" name="submit" class="btn bg-gradient-success btn-block"><i class="fa fa-search"></i>&nbsp Search</button>
                    </div>
                    <div class="<?=$fcol;?>">
                      <a href="ms-list.php" class="btn btn-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</a>
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
                <h3 class="card-title">Monitoring and Supervision List</h3>
                <div class="card-tools">
                  <a href="ms-registration.php?action=1" class="btn btn-sm bg-gradient-primary"><i class="fa fa-plus"></i>&nbsp; New MS Registration</a>
                </div>
              </div>
              <div class="card-body">
                <table id="table" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>Sl. No.</th>
                      <th>Visit Date</th>
                      <th>Dzongkhag</th>
                      <th>Facility</th>
                      <th>Evaluator</th>
                      <th>Category</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                     $msqry = "SELECT t1.gi_id, t1.date_visit, t1.evaluator, t1.audit_category, t2.center_name, t3.dzongkhag_name  FROM onsite_general_informations t1, rcdc_commondb.ms_health_center_table t2, rcdc_commondb.ms_dzongkhag_table t3 WHERE t1.facility_id=t2.center_id AND t2.dzongkhag_code=t3.dzongkhag_code";

                    if(isset($_POST["submit"])){
                      // Filter values are escaped so they cannot break out of the facility scope below.
                      if(isset($_POST["dfrom"]) AND $_POST["dfrom"] != ""){
                        $dfrom= $conn->real_escape_string($_POST["dfrom"]);
                        $msqry .= " AND t1.date_visit>='$dfrom'";
                      }
                      if(isset($_POST["dto"]) AND $_POST["dto"] != ""){
                        $dto= $conn->real_escape_string($_POST["dto"]);
                        $msqry .= " AND t1.date_visit<='$dto'";
                      }

                      if(isset($_POST["dzongkhag"]) AND $_POST["dzongkhag"] != "All"){
                        $dzongkhag= $conn->real_escape_string($_POST["dzongkhag"]);
                        $msqry .= " AND t3.dzongkhag_code='$dzongkhag'";

                        if(isset($_POST["facility"]) AND $_POST["facility"] != ""){
                          $facility= $conn->real_escape_string($_POST["facility"]);
                          $msqry .= " AND t2.center_id='$facility'";
                        }
                      }
                    }

                    // Facility users see only their own facility (authoritative profile facility).
                    if(!mn_is_rcdc()){
                      $facilityId = $conn->real_escape_string((string)mn_facility());
                      $msqry .= " AND t2.center_id='$facilityId'";
                    }

                    $msqry .= " ORDER BY t1.date_visit DESC, t2.center_name DESC LIMIT 500";
                  //  echo $msqry;
                    $result =$conn->query($msqry);
                    $sno = 1;   // server-side fallback; DataTables re-sequences on sort/search/paginate
                    while($row = $result->fetch_row()) {
                      $oraCnt = ($conn->query("SELECT ora_id, recommendation, actions FROM onsite_recommendation_action WHERE general_info_id = $row[0]"));
                      $oraCnt = $oraCnt->num_rows;
                      // Print/Edit unchanged (same URLs, params, target, permission gate);
                      // only their position moved to the Action column. mx-1 = equal spacing.
                      $print = "";
                      if ($oraCnt > 0) {
                        $print = "<a href='pdf-ms-registration.php?id=$row[0]' target='_blank' class='mx-1'><i class='fas fa-print'></i></a>";
                      }
                      /* Deleting a whole record is Administrator-only. delete.php enforces that
                         and answers 403 to anyone else, so the icon is rendered for no one else
                         rather than offering a dead end. */
                      $pdDel = mn_is_admin()
                             ? "<a href='delete.php?type=gi&id=$row[0]' class='mx-1 text-danger js-confirm-delete' title='Delete this record'><i class='fa fa-trash'></i></a>"
                             : "";
                      echo("<tr>
                        <td class='text-center'>".$sno++."</td>
                        <td>$row[1]</td>
                        <td>$row[5]</td>
                        <td>$row[4]</td>
                        <td>$row[2]</td>
                        <td>$row[3]</td>
                        <td class='text-center align-middle text-nowrap'>
                          <a href='ms-registration.php?action=2&id=$row[0]' class='mx-1'><i class='fa fa-edit'></i></a> $pdDel
                          $print
                        </td>
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
    $(document).ready(function(){
      $(document).on('change', '#dzongkhag', function(){
          var dzongkhag = $(this).val() || '';
          $("#facilityDiv").load("../load-facility.php?dzongkhag=" + encodeURIComponent(dzongkhag));
      });	
    }); 

    $(function () {
      var msTable = $('#table').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        // Sl. No. (0) and Action (6) are presentation columns — not sortable/searchable.
        // Data columns (Visit Date/Dzongkhag/Facility/Evaluator/Category = 1..5) unchanged.
        "columnDefs": [{ "orderable": false, "searchable": false, "targets": [0, 6] }],
        "order": [[ 1, "desc" ]]
      });

      // Keep Sl. No. sequential (1..N) for the current sort/search/page — recomputed
      // on every draw so numbering always matches what the user sees.
      msTable.on('draw.dt', function () {
        var info = msTable.page.info();
        msTable.column(0, { page: 'current' }).nodes().each(function (cell, i) {
          cell.innerHTML = info.start + i + 1;
        });
      });
      msTable.draw(false);

      $('#dfrom').datetimepicker({  format: 'YYYY-MM-DD', useCurrent: false, maxDate: moment().endOf('day'),});   // end of today: today stays selectable
      $('#dto').datetimepicker({  format: 'YYYY-MM-DD', useCurrent: false, maxDate: moment().endOf('day'),});   // end of today: today stays selectable
    });
  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>

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
</script>
