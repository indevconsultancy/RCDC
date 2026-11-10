<!-- Check session -->
<?php require_once("../config/session.php"); require_once("../config/auth.php"); mn_require_rcdc(); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php require_once("../config/db-config.php"); ?>
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
            <h1>Microscopy Panel Rounds</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Settings | Microscopy Panel Rounds</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>
 
    <!-- Add/Update -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-body">
                <div class="form-group row">
                  <label class="col-sm-12 text-primary col-form-label">Add/Update Microscopy Panel Round</label>
                </div>
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="iu-panel-rounds.php">
                <?php
                  $mrHasTotalSlides = false;
                  $mrProbe = @$conn->query("SHOW COLUMNS FROM panel_round_informations LIKE 'total_slides'");
                  if ($mrProbe && $mrProbe->num_rows > 0) {
                    $mrHasTotalSlides = true;
                  } else {
                    @$conn->query("ALTER TABLE panel_round_informations ADD COLUMN total_slides INT(11) NULL AFTER panel_round");
                    $mrProbe = @$conn->query("SHOW COLUMNS FROM panel_round_informations LIKE 'total_slides'");
                    if ($mrProbe && $mrProbe->num_rows > 0) { $mrHasTotalSlides = true; }
                  }

                  if(isset($_GET["id"]) && $_GET["id"] !== ""){
                    $id = (int)$_GET["id"];
                    $mrCol  = $mrHasTotalSlides ? 'total_slides' : 'NULL AS total_slides';
                    $lookup = $conn->prepare("SELECT panel_round, $mrCol, closing_date, shipment_date FROM panel_round_informations WHERE round_id = ? LIMIT 1");
                    $lookup->bind_param('i', $id);
                    $lookup->execute();
                    $data = $lookup->get_result()->fetch_row();
                    $lookup->close();
                    if (!$data) { $data = array('', '5', '', ''); }
                    if ($data[1] === null || $data[1] === '' || (int)$data[1] <= 0) { $data[1] = 5; }
                    $delete = "<a href='delete.php?id={$id}&type=pr' class='btn bg-gradient-danger'>Delete</a>";
                    echo("<input type='hidden' name='action' id='action' value='2'>");
                  }
                  else {
                    $id = '';
                    $data = array('', '5', '', '');
                    $delete = "";
                    echo("<input type='hidden' name='action' id='action' value='1'>");
                  }
                ?>
                  <input type="hidden" name="id" id="id" value="<?=$id;?>">
                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Panel round(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="round" id="round" class="text-center form-control"  value="<?=$data[0];?>" placeholder="Panel round">
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Total slides(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="slides" id="slides" class="text-center form-control" min="1" required value="<?=$data[1];?>" placeholder="Total slides">
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Closing date(*)</label>
                    <div class="col-sm-3 input-group date" data-target-input="nearest">
                      <input type="text" class="form-control datetimepicker-input text-center" id="doc" name="doc" value="<?=$data[2];?>" placeholder="Closing date"/>
                      <div class="input-group-append" data-target="#doc" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Shipment date(*)</label>
                    <div class="col-sm-3 input-group date" data-target-input="nearest">
                      <input type="text" class="form-control datetimepicker-input text-center" id="dos" name="dos" value="<?=$data[3];?>" placeholder="Shipment date"/>
                      <div class="input-group-append" data-target="#dos" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                  </div>

                  <div class="form-group row">
                    <div class="col-sm-12">
                      <button type="submit" class="btn bg-gradient-success">Submit</button>
                      <?=$delete;?>
                      <a href="panel-rounds.php" class="btn bg-gradient-secondary float-right">Cancel</a>
                    </div>
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
              <div class="card-body">
                <table id="table" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>ID</th>
                      <th>Round</th>
                      <th>Total Slides</th>
                      <th>Closing Date</th>
                      <th>Shipment Date</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    $mrListCol = $mrHasTotalSlides ? 'total_slides' : 'NULL AS total_slides';
                    $sql = "SELECT round_id, panel_round, $mrListCol, closing_date, shipment_date FROM panel_round_informations ORDER BY round_id DESC";
                    $result = $conn->query($sql);
                    while($row = $result->fetch_row()) {
                      $mrSlides = ($row[2] === null || $row[2] === '' || (int)$row[2] <= 0) ? 5 : (int)$row[2];
                      echo("<tr class='text-center'><td><a href='panel-rounds.php?id=$row[0]&action=2'>$row[0]</a></td><td>$row[1]</td><td>$mrSlides</td><td>$row[3]</td><td>$row[4]</td></tr>");
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
    $(document).ready(function(){
      $('#form').validate({
        rules: {
          round: "required",
          slides: {
            required: true,
            min: 1,
            digits: true
          },
          doc: "required"
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
      $('#table').DataTable({
        "paging": true,
        "lengthChange": false,
        "searching": false,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        "order": [[ 0, "desc" ]]
      });
      $('#doc').datetimepicker({  format: 'YYYY-MM-DD'});
      $('#dos').datetimepicker({  format: 'YYYY-MM-DD'});
    });

  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
