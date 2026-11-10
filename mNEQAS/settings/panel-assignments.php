<!-- Check session -->
<?php require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc(); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php");
?>
<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<!-- Data settings -->
<?php
  $mrProbe = @$conn->query("SHOW COLUMNS FROM panel_round_informations LIKE 'total_slides'");
  $mrHasTotalSlides = ($mrProbe && $mrProbe->num_rows > 0);
  $mrCol = $mrHasTotalSlides ? "total_slides" : "5 AS total_slides";
  $roundsql = "SELECT round_id, panel_round, $mrCol FROM panel_round_informations ORDER BY panel_round DESC";
  $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
?>

<!-- Page-scoped enterprise (LIMS) styling. Presentation only — no field name,
     id, query, validation rule or workflow is affected by this stylesheet. -->
<style>
  .pa-page .content-header h1{font-size:1.5rem;font-weight:700;color:#0f172a;}
  .pa-page .card{border:1px solid #e5eaf1;border-radius:12px;box-shadow:0 1px 3px rgba(15,23,42,.06);}
  .pa-page .card + .card{margin-top:0;}
  .pa-page .card-header{background:#fff;border-bottom:1px solid #eef2f7;border-radius:12px 12px 0 0;
       padding:18px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
  .pa-page .card-title{font-size:1.05rem;font-weight:700;color:#0f172a;margin:0;}
  .pa-page .card-subtitle{font-size:.8rem;color:#94a3b8;margin:3px 0 0;font-weight:400;}
  .pa-page .card-body{padding:24px;}

  /* Labels — identical size / weight / spacing everywhere, always above the field */
  .pa-page label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:8px;line-height:1.2;}
  .pa-page .req{color:#dc3545;margin-left:2px;font-weight:600;}

  /* Controls — uniform 46px height, 10px radius, consistent padding + focus */
  .pa-page .form-control{height:46px;border-radius:10px;border:1px solid #cbd5e1;padding:.5rem .85rem;
       font-size:.9rem;color:#1e293b;transition:border-color .15s ease,box-shadow .15s ease;box-shadow:none;}
  .pa-page select.form-control{appearance:none;-webkit-appearance:none;-moz-appearance:none;padding-right:2.25rem;
       background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%2364748b'%3E%3Cpath d='M4.5 6l3.5 3.6L11.5 6z'/%3E%3C/svg%3E");
       background-repeat:no-repeat;background-position:right .8rem center;background-size:15px;}
  .pa-page .form-control:hover:not(:focus):not(:disabled){border-color:#94a3b8;}
  .pa-page .form-control:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.18);outline:0;}
  .pa-page .form-control::placeholder{color:#adb8c6;}
  .pa-page .form-control.is-invalid{border-color:#dc3545;background-position:right .8rem center;}
  .pa-page .form-control.is-invalid:focus{box-shadow:0 0 0 3px rgba(220,53,69,.18);}

  /* Grid rows align to the top so a validation message under one field never
     shifts the control beside it. One message per field, directly beneath it. */
  .pa-page .form-grid{align-items:flex-start;}
  .pa-page .form-group{margin-bottom:18px;position:relative;}
  .pa-page .invalid-feedback{display:block;width:100%;font-size:.75rem;color:#dc3545;margin-top:6px;}

  /* Section divider inside the form */
  .pa-page .section-label{font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
       color:#94a3b8;margin:2px 0 16px;padding-bottom:8px;border-bottom:1px solid #eef2f7;}

  /* Buttons — same height/radius as inputs, aligned on the control baseline */
  .pa-page .btn{height:46px;border-radius:10px;padding:.5rem 1.25rem;font-size:.9rem;font-weight:600;
       display:inline-flex;align-items:center;justify-content:center;gap:6px;}
  .pa-page .filter-actions{display:flex;gap:12px;}
  .pa-page .filter-actions .btn{flex:1 1 0;}

  /* Form footer action bar */
  .pa-page .form-footer{display:flex;align-items:center;gap:12px;flex-wrap:wrap;
       padding-top:18px;margin-top:6px;border-top:1px solid #eef2f7;}
  .pa-page .form-footer .btn-cancel{margin-left:auto;}

  /* Data table */
  .pa-page table.table{margin-bottom:0;}
  .pa-page table.table thead th{background:#f8fafc;border-bottom:2px solid #e2e8f0;border-top:0;
       font-size:.76rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:#64748b;
       padding:14px 16px;vertical-align:middle;}
  .pa-page table.table tbody td{padding:12px 16px;height:52px;vertical-align:middle;font-size:.9rem;
       color:#334155;border-color:#eef2f7;}
  .pa-page table.table tbody tr:hover{background:#f8fbff;}
  .pa-page .pa-edit{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;
       border-radius:9px;color:#2563eb;border:1px solid #dbeafe;background:#eff6ff;transition:all .15s ease;}
  .pa-page .pa-edit:hover{background:#2563eb;color:#fff;border-color:#2563eb;text-decoration:none;}

  @media (max-width:575.98px){
    .pa-page .card-body{padding:18px;}
    .pa-page .filter-actions{flex-direction:column;}
  }
</style>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <!-- Top Navbar -->
  <?php require_once("../includes/top-navbar.php"); ?>
  <!-- Main Sidebar Container -->
  <?php require_once("../includes/sidebar-menu.php"); ?>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper pa-page">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>Microscopy Panel Assignments</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Settings | Microscopy Panel Assignments</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <!-- Search / Filter toolbar -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <div>
                  <h3 class="card-title"><i class="fas fa-filter text-primary mr-1"></i> Filter Assignments</h3>
                </div>
              </div>
              <div class="card-body">
                <form class="form-horizontal" autocomplete="off" method="Post" action="panel-assignments.php">
                  <div class="row form-grid">
                    <div class="form-group col-lg-3 col-md-6">
                      <label>Panel round</label>
                      <select class="form-control" name="round" id="filter_round">
                        <option value="">All Round</option>
                        <?php
                          $result = $conn->query($roundsql);
                          while($row = $result->fetch_row()) {
                            $sel = (isset($_POST['round']) && $_POST['round'] == $row[1]) ? " selected" : "";
                            echo("<option value='{$row[1]}'{$sel}>{$row[1]}</option>");
                          }
                        ?>
                      </select>
                    </div>
                    <div class="form-group col-lg-3 col-md-6">
                      <label>Dzongkhag</label>
                      <select class="form-control" name="dzongkhag" id="dzongkhag">
                        <option value="">Select dzongkhag</option>
                        <?php
                          $result = $commondb->query($dzsql);
                          while($row = $result->fetch_row()) {
                            $sel = (isset($_POST['dzongkhag']) && $_POST['dzongkhag'] == $row[0]) ? " selected" : "";
                            echo("<option value='{$row[0]}'{$sel}>{$row[1]}</option>");
                          }
                        ?>
                      </select>
                    </div>
                    <div class="form-group col-lg-3 col-md-6">
                      <label>Health facility</label>
                      <div id="facilityDiv">
                        <select class="form-control" name="facility" id="filter_facility">
                          <option value="">Select facility</option>
                        </select>
                      </div>
                    </div>
                    <div class="form-group col-lg-3 col-md-6">
                      <label class="d-none d-lg-block">&nbsp;</label>
                      <div class="filter-actions">
                        <button type="submit" name="submit" class="btn bg-gradient-success"><i class="fa fa-search"></i> Search</button>
                        <a href="panel-assignments.php" class="btn bg-gradient-secondary"><i class="fa fa-sync"></i> Reset</a>
                      </div>
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

    <!-- Add/Update -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <div>
                  <h3 class="card-title"><i class="fas fa-vials text-primary mr-1"></i> Add / Update Panel Assignment</h3>
                  <p class="card-subtitle">Assign a panel round and its slide numbers to a health facility.</p>
                </div>
              </div>
              <div class="card-body">
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="iu-panel-assignments.php">
                <?php
                  if(isset($_GET["id"]) && $_GET["id"] !== ""){
                    $id = (int)$_GET["id"];
                    $mrLookupCol = $mrHasTotalSlides ? "t4.total_slides" : "5 AS total_slides";
                    $dataQ = $conn->query("SELECT t1.panel_id, t4.panel_round, t2.center_id, t2.center_name, t3.dzongkhag_code, t3.dzongkhag_name, t4.round_id, $mrLookupCol FROM panel_informations t1, rcdc_commondb.ms_health_center_table t2, rcdc_commondb.ms_dzongkhag_table t3, panel_round_informations t4 WHERE t1.facility_id=t2.center_id AND t2.dzongkhag_code=t3.dzongkhag_code AND t1.round_id=t4.round_id AND t1.panel_id='$id'");
                    $data = $dataQ ? $dataQ->fetch_row() : null;
                    if (!$data) {
                      $data = array('', '', '', '', '', '', '', 5);
                    }

                    $slideid = $slideno = array();
                    $slide = $conn->query("SELECT slide_id, slide_number FROM panel_slide_informations WHERE panel_id='$id' ORDER BY slide_id ASC");
                    if ($slide) {
                      while($row = $slide->fetch_row()) {
                        $slideid[] = $row[0];
                        $slideno[] = $row[1];
                      }
                    }

                    $delete = "<a href='delete.php?id=$id&type=pa' class='btn bg-gradient-danger'><i class='fa fa-trash'></i> Delete</a>";
                    echo("<input type='hidden' name='action' id='action' value='2'>");
                  }
                  else {
                    $data = array('', '', '', '', '', '', '', 5);
                    $slideid = $slideno = array();
                    $id = $delete = "";
                    echo("<input type='hidden' name='action' id='action' value='1'>");
                  }
                ?>
                  <input type="hidden" name="id" id="id" value="<?=$id;?>">

                  <div class="row form-grid">
                    <div class="form-group col-md-6">
                      <label>Panel round <span class="req">*</span></label>
                      <select class="form-control" name="round" id="round">
                        <option value="">Select Panel Round</option>
                        <?php
                          $result = $conn->query($roundsql);
                          while($row = $result->fetch_row()) {
                            $selected = ($data[6] != "" && $row[0] == $data[6]) ? " selected" : "";
                            $totSlides = (!empty($row[2]) && (int)$row[2] > 0) ? (int)$row[2] : 5;
                            echo("<option value='{$row[0]}' data-total-slides='{$totSlides}'{$selected}>{$row[1]}</option>");
                          }
                        ?>
                      </select>
                    </div>
                    <div class="form-group col-md-6">
                      <label>Dzongkhag <span class="req">*</span></label>
                      <select class="form-control" name="dzongkhag2" id="dzongkhag2">
                        <option value="">Select Dzongkhag</option>
                        <?php
                          $result = $commondb->query($dzsql);
                          while($row = $result->fetch_row()) {
                            $selected = ($data[4] != "" && $row[0] == $data[4]) ? " selected" : "";
                            echo("<option value='{$row[0]}'{$selected}>{$row[1]}</option>");
                          }
                        ?>
                      </select>
                    </div>
                    <div class="form-group col-md-6">
                      <label>Health facility <span class="req">*</span></label>
                      <div id="facility2Div">
                        <select class="form-control" name="facility" id="facility">
                        <?php if($data[2] != "") { ?>
                        <option value="<?=$data[2];?>" selected><?=$data[3];?></option>
                        <?php } else { ?>
                        <option value="">Select Health Facility</option>
                        <?php } ?>
                        </select>
                      </div>
                    </div>
                  </div>

                  <div class="section-label">Slide Numbers</div>
                  <div class="row form-grid" id="slidesContainer">
                  <?php
                    if(isset($_GET["id"]) && $_GET["id"] !== "") {
                      $roundTotal = (!empty($data[7]) && (int)$data[7] > 0) ? (int)$data[7] : 5;
                      $count = max($roundTotal, count($slideno));
                      if ($count <= 0) { $count = 5; }
                      for ($i = 1; $i <= $count; $i++) {
                        $sId = isset($slideid[$i - 1]) ? $slideid[$i - 1] : '';
                        $sNo = isset($slideno[$i - 1]) ? $slideno[$i - 1] : '';
                  ?>
                    <div class="form-group col-md-6 slide-field-group" data-slide-index="<?=$i;?>">
                      <label>Slide No. <?=$i;?> <span class="req">*</span></label>
                      <input type="hidden" name="slide_id[]" id="slideid<?=$i;?>" value="<?=htmlspecialchars($sId);?>">
                      <input type="text" name="slide_no[]" id="slide<?=$i;?>" class="form-control" value="<?=htmlspecialchars($sNo);?>" placeholder="Slide No. <?=$i;?>" required>
                    </div>
                  <?php
                      }
                    } else {
                  ?>
                    <div class="col-12 text-muted" id="noRoundNotice" style="margin-bottom: 15px;">
                      <em>Please select a panel round above to generate slide fields.</em>
                    </div>
                  <?php } ?>
                  </div>

                  <div class="form-footer">
                    <button type="submit" class="btn bg-gradient-success"><i class="fa fa-check"></i> Submit</button>
                    <?=$delete;?>
                    <a href="panel-assignments.php" class="btn bg-gradient-secondary btn-cancel"><i class="fa fa-times"></i> Cancel</a>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <!-- /.Add/Update -->

    <!-- Table -->
     <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <div>
                  <h3 class="card-title"><i class="fas fa-list-ul text-primary mr-1"></i> Panel Assignments</h3>
                  <p class="card-subtitle">All microscopy panel assignments recorded in the system.</p>
                </div>
              </div>
              <div class="card-body table-responsive">
                <table id="table" class="table table-hover">
                  <thead>
                    <tr class="text-center">
                      <th style="width:70px;">S.No.</th>
                      <th>Round</th>
                      <th>Dzongkhag</th>
                      <th>Facility</th>
                      <th>Slide No</th>
                      <th style="width:90px;">Action</th>
                      <th>ID</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    $sql = "SELECT t1.panel_id, t2.panel_round, t1.facility_id FROM panel_informations t1, panel_round_informations t2, rcdc_commondb.ms_health_center_table t3 WHERE t1.round_id=t2.round_id AND t1.facility_id=t3.center_id";

                    if(isset($_POST['round']) && $_POST['round'] != "") {
                      $round = $conn->real_escape_string($_POST['round']);
                      $sql .= " AND t2.panel_round='$round'";
                    }

                    if(isset($_POST['dzongkhag']) && $_POST['dzongkhag'] != "") {
                      $dzongkhag = $conn->real_escape_string($_POST['dzongkhag']);
                      $sql .= " AND t3.dzongkhag_code='$dzongkhag'";
                    }

                    if(isset($_POST['facility']) && $_POST['facility'] != "") {
                      $facility = $conn->real_escape_string($_POST['facility']);
                      $sql .= " AND t1.facility_id='$facility'";
                    }

                    $result = $conn->query($sql);
                    $sno = 1;   // presentation serial; DataTables re-sequences on sort/paginate
                    while($row = $result->fetch_row()) {
                      $fd = ($commondb->query("SELECT t2.dzongkhag_name, t1.center_name FROM ms_health_center_table t1, ms_dzongkhag_table t2 WHERE t1.dzongkhag_code=t2.dzongkhag_code AND t1.center_id='$row[2]'"));
                      $fd = $fd ? $fd->fetch_row() : ['', ''];
                      $slidestr = "";
                      $slide =  $conn->query("SELECT slide_number FROM panel_slide_informations WHERE panel_id='$row[0]' ORDER BY slide_id ASC");
                      while($data = $slide->fetch_row()) { $slidestr .=  $data[0]."; ";}
                      $slidestr = rtrim($slidestr, "; ");
                      // Edit moved to the Action column — same URL/param as the old ID link.
                      echo("<tr><td class='text-center'>".$sno++."</td><td class='text-center'>$row[1]</td><td>$fd[0]</td><td>$fd[1]</td><td>$slidestr</td><td class='text-center'><a href='panel-assignments.php?id=$row[0]' class='pa-edit' title='Edit assignment'><i class='fa fa-edit'></i></a></td><td>$row[0]</td></tr>");
                    }
                  ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    <!-- /.table -->
  </div>
  <script>
    function renderSlideInputs(targetCount) {
      targetCount = parseInt(targetCount, 10);
      if (isNaN(targetCount) || targetCount <= 0) targetCount = 5;

      var $container = $('#slidesContainer');
      $('#noRoundNotice').remove();

      var $existingGroups = $container.find('.slide-field-group');
      var currentCount = $existingGroups.length;

      if (currentCount > targetCount) {
        $existingGroups.slice(targetCount).remove();
      } else if (currentCount < targetCount) {
        for (var i = currentCount + 1; i <= targetCount; i++) {
          var html = '<div class="form-group col-md-6 slide-field-group" data-slide-index="' + i + '">' +
            '<label>Slide No. ' + i + ' <span class="req">*</span></label>' +
            '<input type="hidden" name="slide_id[]" id="slideid' + i + '" value="">' +
            '<input type="text" name="slide_no[]" id="slide' + i + '" class="form-control" value="" placeholder="Slide No. ' + i + '" required>' +
            '</div>';
          $container.append(html);
        }
      }

      $container.find('.slide-field-group').each(function(idx) {
        var num = idx + 1;
        $(this).attr('data-slide-index', num);
        $(this).find('label').html('Slide No. ' + num + ' <span class="req">*</span>');
        $(this).find('input[type="hidden"]').attr('id', 'slideid' + num);
        var $txt = $(this).find('input[type="text"]');
        $txt.attr('id', 'slide' + num).attr('placeholder', 'Slide No. ' + num);
      });
    }

    $(document).ready(function(){
      $('#form').validate({
        rules: {
          round: "required",
          dzongkhag2: "required",
          facility: "required"
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

      $('#form').on('submit', function(e){
        var $slideInputs = $('#form').find('input[name="slide_no[]"]');
        if ($slideInputs.length === 0) {
          e.preventDefault();
          Swal.fire({
            icon: 'warning',
            title: 'Validation Error',
            text: 'Slide numbers have not been generated. Please select a panel round.',
            confirmButtonText: 'OK'
          });
          return false;
        }

        var anyBlank = false;
        $slideInputs.each(function(){
          if (!$.trim($(this).val())) {
            $(this).addClass('is-invalid');
            anyBlank = true;
          }
        });
        if (anyBlank) {
          e.preventDefault();
          Swal.fire({
            icon: 'warning',
            title: 'Validation Error',
            text: 'All slide numbers must be filled before submitting.',
            confirmButtonText: 'OK'
          });
          return false;
        }
      });

      $(document).on('change', '#dzongkhag', function(){
        var dzongkhag = $(this).val() || '';
        $("#facilityDiv").load("../load-facility.php?dzongkhag=" + encodeURIComponent(dzongkhag));
      });
      $(document).on('change', '#dzongkhag2', function(){
        var dzongkhag = $(this).val() || '';
        $("#facility2Div").load("../load-facility.php?dzongkhag=" + encodeURIComponent(dzongkhag));
      });

      // Auto-generate slide fields and numbers whenever Round changes
      $('#form').on('change', 'select[name="round"]', function(){
        var round = $(this).val();
        if (!round) {
          $('#slidesContainer').html('<div class="col-12 text-muted" id="noRoundNotice" style="margin-bottom: 15px;"><em>Please select a panel round above to generate slide fields.</em></div>');
          return;
        }

        var $selected = $(this).find(':selected');
        var totalSlides = parseInt($selected.data('total-slides'), 10);

        var applyRoundSlides = function(count) {
          renderSlideInputs(count);
          fetchSlideSerials();
        };

        if (!isNaN(totalSlides) && totalSlides > 0) {
          applyRoundSlides(totalSlides);
        } else {
          $.ajax({
            url: "get-round-details.php",
            type: "GET",
            dataType: "json",
            data: { round_id: round, type: "micro" },
            success: function(res) {
              var cnt = (res && res.ok && (res.total_slides || res.total_panels)) ? parseInt(res.total_slides || res.total_panels, 10) : 5;
              $selected.attr('data-total-slides', cnt);
              applyRoundSlides(cnt);
            },
            error: function() {
              applyRoundSlides(5);
            }
          });
        }
      });

      // Auto-generate slide numbers whenever Facility changes
      $('#form').on('change', 'select[name="facility"]', function(){
        fetchSlideSerials();
      });

      /* A slide number the user has typed is theirs: mark the field so the next
         auto-fill leaves it alone. */
      $('#form').on('input', 'input[name="slide_no[]"]', function(){
        $(this).data('userEdited', true);
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
        "columnDefs": [
          { "orderable": false, "searchable": false, "targets": [0, 5] },
          { "visible": false, "targets": 6 }
        ],
        "order": [[ 6, "desc" ]]
      }).on('draw.dt', function () {
        var api = $('#table').DataTable(), info = api.page.info();
        api.column(0, { page: 'current' }).nodes().each(function (cell, i) {
          cell.innerHTML = info.start + i + 1;
        });
      }).draw(false);
    });

    // Fetch the YY-ROUND-MM- prefix and the next serials for that year + round
    // from the backend, then populate slide fields. Works for both
    // Add mode (action=1) and Update mode (action=2).
    function fetchSlideSerials(){
      var $paForm  = $('#form');
      var facility = $paForm.find('select[name="facility"]').val();
      var round    = $paForm.find('select[name="round"]').val();
      var dzongkhag= $paForm.find('select[name="dzongkhag2"]').val();
      var panelId  = $('#id').val();
      var slideCount = $paForm.find('input[name="slide_no[]"]').length;

      // Round is part of the number (YY-ROUND-MM-SERIAL), so wait for both facility and round.
      if(!facility || !round || slideCount <= 0){ return; }

      $.getJSON("get-slide-serial.php", {
        facility: facility,
        round: round,
        dzongkhag: dzongkhag,
        id: panelId,
        count: slideCount
      }, function(res){
        if(res && res.ok && res.slides){
          if(res.slides.length !== $paForm.find('input[name="slide_no[]"]').length){
            renderSlideInputs(res.slides.length);
          }
          var $inputs = $paForm.find('input[name="slide_no[]"]');
          $inputs.each(function(index){
            if(res.slides[index] !== undefined){
              var $slideField = $(this);
              // Keep what the user typed; fill only fields they haven't edited.
              if(!$slideField.data('userEdited')){
                $slideField.val(res.slides[index]).removeClass('is-invalid');
              }
            }
          });
          if($paForm.data('validator')){ $paForm.validate().resetForm(); }
        } else {
          Swal.fire({icon:'warning', text:(res && res.msg) ? res.msg : 'Could not generate slide numbers.', confirmButtonText:'Close'});
        }
      }).fail(function(){
        Swal.fire({icon:'error', text:'Could not reach the slide-number service. Please try again.', confirmButtonText:'Close'});
      });
    }

  </script>
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
