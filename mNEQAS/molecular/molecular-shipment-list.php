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
            <h1>Molecular Shipment</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Molecular Shipment | List</li>
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
            <div class="card card-success">
              <div class="card-header">
                  <h3 class="card-title">Shipment Information</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="molecular-shipment-list.php">

                  <?php
                    // Filter column width: RCDC/Admin rows hold 6 elements (col-md-2),
                    // district rows hold 4 (col-md-3) so the row stays a single full line.
                    $fcol = ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) ? "col-md-2" : "col-md-3";
                  ?>
                  <div class="form-group row align-items-end">
                  <?php  if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                    <div class="col-md-2 mb-2 mb-md-0">
                      <select class="form-control" name="dzongkhag2" id="dzongkhag2">
                        <option value="All">All dzongkhag</option>
                        <?php
                          $result = $commondb->query($dzsql);
                          while($row = $result->fetch_row()) {
                            echo("<option value='$row[0]'>$row[1]</option>");
                          }
                        ?>
                      </select>
                    </div>

                    <div id="facilityDiv2" class="col-md-2 mb-2 mb-md-0">
                      <select class="form-control" name="facility2" id="facility2">
                        <option value="">All facility</option>
                      </select>
                    </div>
                  <?php } ?>

                    <div class="<?=$fcol;?> mb-2 mb-md-0">
                      <div class="input-group date" id="sdfrom" data-target-input="nearest">
                        <input type="text" class="form-control datetimepicker-input text-center" data-target="#sdfrom" name="sdfrom"  placeholder="Shipment Date (From)"/>
                        <div class="input-group-append" data-target="#sdfrom" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                      </div>
                    </div>

                    <div class="<?=$fcol;?> mb-2 mb-md-0">
                      <div class="input-group date" id="sdto" data-target-input="nearest">
                        <input type="text" class="form-control datetimepicker-input text-center" data-target="#sdto" name="sdto"  placeholder="Shipment Date (To)"/>
                        <div class="input-group-append" data-target="#sdto" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                      </div>
                    </div>

                    <div class="<?=$fcol;?> mb-2 mb-md-0">
                      <button type="submit" name="submit" class="btn bg-gradient-success btn-block"><i class="fa fa-search"></i>&nbsp Search</button>
                    </div>
                    <div class="<?=$fcol;?>">
                      <a href="molecular-shipment-list.php" class="btn btn-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</a>
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
                <h3 class="card-title">Molecular Shipment List</h3>
                <div class="card-tools">
                  <a href="molecular-shipment.php?action=1" class="btn btn-sm bg-gradient-primary"><i class="fa fa-plus"></i>&nbsp; New DBS Shipment</a>
                </div>
              </div>
              <div class="card-body">
                <table id="shipment" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>Sl. No.</th>
                      <th>Dzongkhag</th>
                      <th>Facility</th>
                      <th>No. DBS Sent</th>
                      <th>Shipment Date</th>
                      <th>No. DBS Received</th>
                      <th>Received Date</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                  //SELECT `shipment_id`, `facility_id`, `total_dbs_shipped`, `shipped_by`, `shipment_date`, `total_dbs_received`, `received_by`, `receive_date` FROM `dbs_shipment_details` WHERE 1
                     $msqry = "SELECT t1.shipment_id, t1.total_dbs_shipped, t1.shipment_date, t1.total_dbs_received, t1.receive_date, t3.dzongkhag_name, t2.center_name FROM dbs_shipment_details t1, rcdc_commondb.ms_health_center_table t2, rcdc_commondb.ms_dzongkhag_table t3 WHERE t1.facility_id=t2.center_id AND t2.dzongkhag_code=t3.dzongkhag_code";

                    if(isset($_POST["submit"])){
                      if(isset($_POST["sdfrom"]) AND $_POST["sdfrom"] != ""){ 
                        $dfrom= $conn->real_escape_string($_POST["sdfrom"]);
                        $msqry .= " AND t1.shipment_date>='$dfrom'";
                      }
                      if(isset($_POST["sdto"]) AND $_POST["sdto"] != ""){
                        $dto= $conn->real_escape_string($_POST["sdto"]);
                        $msqry .= " AND t1.shipment_date<='$dto'";
                      }

                      if(isset($_POST["dzongkhag2"]) AND $_POST["dzongkhag2"] != "All"){
                        $dzongkhag= $conn->real_escape_string($_POST["dzongkhag2"]);
                        $msqry .= " AND t3.dzongkhag_code='$dzongkhag'";

                        if(isset($_POST["facility2"]) AND $_POST["facility2"] != ""){
                          $facility= $conn->real_escape_string($_POST["facility2"]);
                          $msqry .= " AND t2.center_id='$facility'";
                        }
                      }
                    }
                    // Facility users see only their own facility (authoritative profile facility).
                    if(!mn_is_rcdc()){
                      $facilityId = $conn->real_escape_string((string)mn_facility());
                      $msqry .= " AND t2.center_id='$facilityId'";
                    }

                    $msqry .= " ORDER BY (t1.receive_date IS NULL) DESC, t1.shipment_date DESC, t2.center_name ASC LIMIT 200";
                  //  echo $msqry;
                    $result =$conn->query($msqry);
                    $sno = 1;   // server-side fallback; DataTables re-sequences on sort/search/paginate
                    while($row = $result->fetch_row()) {
                      // Edit unchanged (same URL, params, behavior); only moved to the Action column.
                      /* A shipment is un-batched by RCDC at any time, or by the owning
                         facility until RCDC has received it - the same rule delete.php
                         enforces. $row[4] is receive_date, so a null there means not yet
                         received. Non-RCDC users only ever see their own facility's rows
                         (the query is scoped below), so "owner" needs no extra test here.
                         Anyone else gets no icon rather than a dead end. */
                      $pdDel = (mn_is_rcdc() || $row[4] === null)
                             ? "<a href='delete.php?type=labship&id=$row[0]' class='mx-1 text-danger js-confirm-delete' title='Delete this record'><i class='fa fa-trash'></i></a>"
                             : "";
                      echo("<tr>
                        <td class='text-center'>".$sno++."</td>
                        <td>$row[5]</td>
                        <td>$row[6]</td>
                        <td>$row[1]</td>
                        <td>$row[2]</td>
                        <td>$row[3]</td>
                        <td>$row[4]</td>
                        <td class='text-center align-middle'><a href='molecular-shipment.php?action=2&id=$row[0]' class='mx-1'><i class='fa fa-edit'></i></a> $pdDel</td>
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
          $("#facilityDiv").load("../load-facility-all.php?dzongkhag=" + encodeURIComponent(dzongkhag));
      });
      
      $(document).on('change', '#dzongkhag2', function(){
          var dzongkhag = $(this).val() || '';
          $("#facilityDiv2").load("../load-facility.php?dzongkhag=" + encodeURIComponent(dzongkhag));
      });	
    }); 

    $(function () {
      var shipTable = $("#shipment").DataTable({
      "responsive": true, "lengthChange": false, "autoWidth": false,
      // Sl. No. (0) and Action (7) are presentation columns — not sortable/searchable.
      // Data columns (Dzongkhag/Facility/No. Sent/Shipment Date/No. Received/Received Date = 1..6) unchanged.
      "columnDefs": [{ "orderable": false, "searchable": false, "targets": [0, 7] }],
      "order": [[ 4, "desc" ]],
      // "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
    });
      shipTable.buttons().container().appendTo('#shipment_wrapper .col-md-6:eq(0)');

      // Keep Sl. No. sequential (1..N) for the current sort/search/page — recomputed
      // on every draw so numbering always matches what the user sees.
      shipTable.on('draw.dt', function () {
        var info = shipTable.page.info();
        shipTable.column(0, { page: 'current' }).nodes().each(function (cell, i) {
          cell.innerHTML = info.start + i + 1;
        });
      });
      shipTable.draw(false);

      $('#table').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        "order": [[ 1, "desc" ]]
      }).buttons().container().appendTo('#table_wrapper .col-md-6:eq(0)');
      
      
      $('#sdfrom').datetimepicker({  format: 'YYYY-MM-DD'});
      $('#sdto').datetimepicker({  format: 'YYYY-MM-DD'});
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
