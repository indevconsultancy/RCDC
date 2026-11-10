<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php"); 
?>

<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<!-- Highcharts -->
<?php require_once("../includes/highcharts.php"); ?>

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
            <h1>Molecular (DBS) Reports</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Reports | Molecular (DBS)</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <?php
        $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
    ?>
    <!-- Search -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card card-success">
              <div class="card-header">
                  <h3 class="card-title">Patient Information</h3>
              </div>
              <div class="card-body">
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="molecular-reports.php">
                  <div class="form-group row align-items-end">
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
                    <div class="col-md-2 mb-2 mb-md-0">
                      <div class="input-group date" id="dfrom" data-target-input="nearest">
                        <input type="text" class="form-control datetimepicker-input text-center" data-target="#dfrom" name="dfrom"  placeholder="Collection Date (From)"/>
                        <div class="input-group-append" data-target="#dfrom" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-2 mb-2 mb-md-0">
                      <div class="input-group date" id="dto" data-target-input="nearest">
                        <input type="text" class="form-control datetimepicker-input text-center" data-target="#dto" name="dto"  placeholder="Collection Date (To)"/>
                        <div class="input-group-append" data-target="#dto" data-toggle="datetimepicker">
                          <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-2 mb-2 mb-md-0">
                      <button type="submit" name="submit" class="btn bg-gradient-success btn-block"><i class="fa fa-search"></i>&nbsp Search</button>
                    </div>
                    <div class="col-md-2">
                      <a href="molecular-reports.php" class="btn bg-gradient-secondary btn-block"><i class="fa fa-sync"></i>&nbsp Reset</a>
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
                <h3 class="card-title">Patient Information</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <form id="form" action="molecular-pdf-report.php" target="_blank" autocomplete="off" method="Post" >
                  <table id="table" class="table table-bordered table-hover">
                    <thead>
                      <tr class="text-center">
                        <th><input type="checkbox" id='checkall' checked/> <br>Check/Uncheck All</th>
                        <th>Sl. No</th>
                        <th>BSE No</th>
                        <th>Health Center</th>
                        <th>Patient Name</th>
                        <th>Age</th>
                        <th>Sex</th>
                        <th>Collection Date</th>
                        <th>PCR Result</th>
                        <th>PCR Date</th>
                        <th>Remarks</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php
                      $msqry = "SELECT t1.dbs_id, t1.bse_no, t2.center_name, t1.patient_name, t1.age, t1.gender, t1.collection_date, t1.pcr, t1.pcr_date, t1.pcr_remarks, t3.dzongkhag_name FROM dbs_results t1, rcdc_commondb.ms_health_center_table t2, rcdc_commondb.ms_dzongkhag_table t3 WHERE t1.facility_id=t2.center_id AND t2.dzongkhag_code=t3.dzongkhag_code";

                      if(isset($_POST["submit"])){
                        if(isset($_POST["dfrom"]) AND $_POST["dfrom"] != ""){ 
                          $dfrom= $conn->real_escape_string($_POST["dfrom"]);
                          $msqry .= " AND t1.collection_date>='$dfrom'";
                        }
                        if(isset($_POST["dto"]) AND $_POST["dto"] != ""){
                          $dto= $conn->real_escape_string($_POST["dto"]);
                          $msqry .= " AND t1.collection_date<='$dto'";
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
                      // Patient data: facility users see only their own facility (server-side scope).
                      if(!mn_is_rcdc()){
                        $msqry .= " AND t1.facility_id='" . $conn->real_escape_string((string)mn_facility()) . "'";
                      }

                      $msqry .= " ORDER BY t1.pcr_date DESC, t2.center_name DESC LIMIT 100";
                    //  echo $msqry;
                      $result =$conn->query($msqry);
                      $sl = 0;
                      while($row = $result->fetch_row()) {
                          $sl++;
                        echo("<tr class='text-center'>
                          <td><input type='checkbox' name='check_list[]'  value ='$row[0]' class='checkbox' checked /></td>
                          <td>$sl</td>
                          <td>$row[1]</td>
                          <td>$row[2]</td>
                          <td>$row[3]</td>
                          <td>$row[4]</td>
                          <td>$row[5]</td>
                          <td>$row[6]</td>
                          <td>$row[7]</td>
                          <td>$row[8]</td>
                          <td>$row[9]</td>
                        </tr>");
                      }
                    ?>
                    </tbody>
                  </table>
                  <div class="form-group col-sm-12">
                      <button type="submit" name="Print" class="btn bg-gradient-success"><i class="fa fa-print"></i>&nbsp Print</button>
                  </div> 
                </form>
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
    /* ---- Single source of truth for the export selection -----------------------
       DataTables keeps rows of other pages outside the DOM, so every lookup goes
       through the DataTables API (all pages, current search filter applied).
       Rule: rows whose checkbox is ticked are exported; if nothing is ticked, all
       rows are exported (same as Select All). Used by Copy/CSV/Excel/PDF/Print
       buttons and by the server-side Print (PDF) form. */
    var msDt = null;
    function msRowBoxes() {
      var rows = msDt ? msDt.rows({ search: 'applied' }).nodes() : $('#table tbody tr');
      return $(rows).find('input.checkbox');
    }
    function msAllBoxes() {
      return msDt ? $(msDt.rows().nodes()).find('input.checkbox') : $('#table tbody input.checkbox');
    }
    function msSelectedIds() {
      var $boxes = msRowBoxes(), $checked = $boxes.filter(':checked');
      return ($checked.length ? $checked : $boxes).map(function(){ return this.value; }).get();
    }
    function msExportRow(idx, data, node) {
      var ids = msSelectedIds();
      var id = $(node).find('input.checkbox').val();
      return ids.indexOf(id) !== -1;
    }

    $(document).ready(function(){
      $("#checkall").change(function(){
        var checked = $(this).is(':checked');
        if(checked){
          msAllBoxes().each(function(){
          $(this).prop("checked",true);
          });
        }else{
          msAllBoxes().each(function(){
          $(this).prop("checked",false);
          });
        }
		  });

		  $(".checkbox").click(function(){
        var $all = msAllBoxes();
        if($all.length == $all.filter(":checked").length) {
          $("#checkall").prop("checked", true);
        } else { $("#checkall").prop("checked", false); }
		  });

      // Print (server PDF): post exactly the selected ids from every page. The in-page
      // checkboxes are un-named for this submit so rows are not posted twice.
      $('form[action="molecular-pdf-report.php"]').on('submit', function(){
        var $form = $(this), $boxes = msAllBoxes();
        $form.find('input.ms-export-id').remove();
        $.each(msSelectedIds(), function(_, id){
          $('<input type="hidden" class="ms-export-id" name="check_list[]">').val(id).appendTo($form);
        });
        $boxes.removeAttr('name');
        setTimeout(function(){ $boxes.attr('name', 'check_list[]'); $form.find('input.ms-export-id').remove(); }, 0);
      });

      $(document).on('change', '#dzongkhag', function(){
        var dzongkhag = $(this).val() || '';
        $("#facilityDiv").load("../load-facility-all.php?dzongkhag=" + encodeURIComponent(dzongkhag));
      });
      $('#form').validate({
          rules: {
              type: "required",
              round: "required"
          },
          errorElement: 'span',
          errorPlacement: function (error, element) {
              error.addClass('invalid-feedback');
              element.closest('.form-group').append(error);
          },
          highlight: function (element, errorClass, validClass) {
              $(element).addClass('is-invalid');
          },
          unhighlight: function (element, errorClass, validClass) {
              $(element).removeClass('is-invalid');
          }
      });       
    });

    $(function () {
        msDt = $('#table').DataTable({
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": false,
            "info": true,
            "autoWidth": false,
            "responsive": true,
            // Exclude the first column (the "Check/Uncheck All" selection checkbox)
            // from every export/print output. It stays visible on-screen only.
            // ':not(:first-child)' selects all data columns except column 0.
            "buttons": [
                // rows: msExportRow -> only the ticked records (all records when none ticked).
                { extend: 'copy',  exportOptions: { columns: ':not(:first-child)', rows: msExportRow } },
                { extend: 'csv',   exportOptions: { columns: ':not(:first-child)', rows: msExportRow } },
                { extend: 'excel', exportOptions: { columns: ':not(:first-child)', rows: msExportRow } },
                { extend: 'pdfHtml5', text: 'PDF', download: 'open', title: 'Molecular Testing Summary', orientation: 'landscape', pageSize: 'A4', exportOptions: { columns: ':not(:first-child)', rows: msExportRow } },
                { extend: 'print', exportOptions: { columns: ':not(:first-child)', rows: msExportRow } }
            ],
            "order": [[ 0, "asc" ]]
        });
        msDt.buttons().container().appendTo('#table_wrapper .col-md-6:eq(0)');
        
        $('#dfrom').datetimepicker({  format: 'YYYY-MM-DD'});
        $('#dto').datetimepicker({  format: 'YYYY-MM-DD'});
    }); 
  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
