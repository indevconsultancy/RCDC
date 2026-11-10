<!-- Check session -->
<?php require_once("../config/session.php"); ?>

<?php
  $roleID = $_SESSION["roleID"];
?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php"); 
?>
<!-- Data and page settings -->
<?php
  $roundsql = "SELECT panel_round FROM rdt_panel_round_informations";
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
            <h1>RDT Panel Testing</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Panels | List</li>
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
              <!-- Add New Panel Assignment - Admin Only -->
              <!-- /.card-header -->
              <div class="card-body">
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="panel-list.php">
                  <div class="form-group row align-items-end">
                    <div class="col-md mb-2 mb-md-0">
                      <select class="form-control" name="round" id="round">
                        <option value="">All Round</option>
                        <?php
                          $result = $conn->query($roundsql);
                          while($row = $result->fetch_row()) {
                            echo("<option value='$row[0]'>$row[0]</option>");
                          }
                        ?>
                      </select>
                    </div>
                  <?php  if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                    <div class="col-md mb-2 mb-md-0">
                      <select class="form-control" name="dzongkhag" id="dzongkhag">
                        <option value="">All dzongkhag</option>
                        <?php
                          $result = $commondb->query($dzsql);
                          while($row = $result->fetch_row()) {
                            echo("<option value='$row[0]'>$row[1]</option>");
                          }
                        ?>
                      </select>
                    </div>
                    <div id="facilityDiv" class="col-md mb-2 mb-md-0">
                      <select class="form-control" name="facility" id="facility">
                        <option value="">All facility</option>
                      </select> 
                    </div>
                  <?php } ?>
                  <?php if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                  
                    <div class="col-md mb-2 mb-md-0">
                      <button type="submit" name="submit" class="btn bg-gradient-success btn-block"><i class="fa fa-search"></i>&nbsp Search</button>
                    </div>
                    <div class="col-md mb-2 mb-md-0">
                      <a href="panel-list.php" class="btn btn-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</a>
                    </div>
                  <?php } else { ?>
                    <div class="col-md mb-2 mb-md-0">
                      <button type="submit" name="submit" class="btn bg-gradient-success btn-block"><i class="fa fa-search"></i>&nbsp Search</button>
                    </div>
                    <div class="col-md mb-2 mb-md-0">
                      <a href="panel-list.php" class="btn bg-gradient-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</a>
                    </div>
                    <!-- remaining quarter of the row intentionally left empty -->
                  <?php } ?>
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
              <div class="card-body">
                <table id="table" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>Sl. No.</th>
                      <th>Round</th>
                      <th>Dzongkhag</th>
                      <th>Facility</th>
                      <th>Examination</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    // Parameterized filter query — POST filters and session facilityID
                    // are bound as placeholders so no user-controlled string ever enters
                    // the SQL text directly.
                    $panelsql = "SELECT t1.panel_id, t4.panel_round, t3.dzongkhag_name, t2.center_id, t2.center_name
                                 FROM rdt_panel_informations t1, rcdc_commondb.ms_health_center_table t2,
                                      rcdc_commondb.ms_dzongkhag_table t3, rdt_panel_round_informations t4
                                 WHERE t1.facility_id = t2.center_id
                                   AND t2.dzongkhag_code = t3.dzongkhag_code
                                   AND t1.round_id = t4.round_id";
                    $params = [];
                    $types  = '';

                    if(isset($_POST["submit"])){
                      if(isset($_POST["round"]) && $_POST["round"] !== ""){
                        $panelsql .= " AND t4.panel_round = ?";
                        $params[] = $_POST["round"]; $types .= 's';
                      }
                      if(isset($_POST["dzongkhag"]) && $_POST["dzongkhag"] !== ""){
                        $panelsql .= " AND t3.dzongkhag_code = ?";
                        $params[] = $_POST["dzongkhag"]; $types .= 's';
                      }
                      if(isset($_POST["facility"]) && $_POST["facility"] !== ""){
                        $panelsql .= " AND t2.center_id = ?";
                        $params[] = $_POST["facility"]; $types .= 's';
                      }
                    }

                    // Facility users see only their own facility (authoritative profile facility).
                    if(!mn_is_rcdc()){
                      $panelsql .= " AND t2.center_id = ?";
                      $params[] = (string)mn_facility(); $types .= 's';
                    }
                    $panelsql .= " ORDER BY t4.panel_round DESC, t2.center_name DESC LIMIT 500";

                    $listStmt = $conn->prepare($panelsql);
                    if ($types !== '') { $listStmt->bind_param($types, ...$params); }
                    $listStmt->execute();
                    $result = $listStmt->get_result();

                    /* Per-row examination count, bound to the panel on that row. It used to
                       bind facility_id, which counted every round that facility had ever
                       taken part in and showed the same total on each of its rows.
                       rdt-panels/panels.php already counts per panel; this matches it. */
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM rdt_panel_lab_result_informations WHERE panel_id = ?");
                    $sno = 1;   // server-side fallback; DataTables re-sequences on sort/search/paginate
                    while($row = $result->fetch_row()) {
                      $countStmt->bind_param('i', $row[0]);
                      $countStmt->execute();
                      $total = $countStmt->get_result()->fetch_row();
                      // Edit unchanged (same URL, params, behavior); only moved to the Action column.
                      /* Deleting a whole record is Administrator-only. delete.php enforces that
                         and answers 403 to anyone else, so the icon is rendered for no one else
                         rather than offering a dead end. */
                      $pdDel = mn_is_admin()
                             ? "<a href='delete.php?type=pi&id=".(int)$row[0]."' class='mx-1 text-danger js-confirm-delete' title='Delete this record'><i class='fa fa-trash'></i></a>"
                             : "";
                      echo("<tr><td class='text-center'>".$sno++."</td><td class='text-center'>".htmlspecialchars($row[1])."</td><td>".htmlspecialchars($row[2])."</td><td>".htmlspecialchars($row[4])."</td><td class='text-center'>".(int)$total[0]."</td><td class='text-center align-middle'><a href='panels.php?id=".(int)$row[0]."' class='mx-1'><i class='fa fa-edit'></i></a> $pdDel</td></tr>");
                    }
                    $countStmt->close();
                    $listStmt->close();
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
      var panelTable = $('#table').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        // Sl. No. (0) and Action (5) are presentation columns — not sortable/searchable.
        // Data columns (Round/Dzongkhag/Facility/Examination = 1..4) unchanged. The old
        // order was [[0,"desc"]] on the (empty) edit column — a no-op that left rows in the
        // SQL order (panel_round DESC, center_name DESC); order:[] preserves that exact view.
        "columnDefs": [{ "orderable": false, "searchable": false, "targets": [0, 5] }],
        "order": []
      });

      // Keep Sl. No. sequential (1..N) for the current sort/search/page — recomputed
      // on every draw so numbering always matches what the user sees.
      panelTable.on('draw.dt', function () {
        var info = panelTable.page.info();
        panelTable.column(0, { page: 'current' }).nodes().each(function (cell, i) {
          cell.innerHTML = info.start + i + 1;
        });
      });
      panelTable.draw(false);
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
