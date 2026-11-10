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
            <h1>Cabinet Manager</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Banking | Cabinets</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>
 
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
                      <th>Cabinet</th>
                      <th>CompartmentsPerCabinet</th>
                      <th>RacksPerCompartment</th>
                      <th>SlideCapacity</th>
                      <th>ColumnsPerRack</th>
                      <th>RowsPerRack</th>
                      <th>Location</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    /* How many slides are banked in each cabinet, in one pass rather
                       than a query per row. A cabinet holding slides cannot be
                       deleted: banking_slide_informations.slot_id is ON DELETE
                       RESTRICT, so delete.php's first statement fails and nothing is
                       removed. Knowing the count here lets the icon explain itself
                       instead of offering a button that always fails. */
                    $bankedSlides = array();
                    $bs = $conn->query("SELECT r.cabinet_id, COUNT(sl.slide_id)
                                          FROM banking_rack_informations r
                                          INNER JOIN banking_slot_informations s ON s.rack_id = r.rack_id
                                          INNER JOIN banking_slide_informations sl ON sl.slot_id = s.slot_id
                                         GROUP BY r.cabinet_id");
                    if($bs){ while($b = $bs->fetch_row()){ $bankedSlides[(int)$b[0]] = (int)$b[1]; } }

                    $sql = "SELECT cabinet_id, cabinet_name, compartments_per_cabinet, racks_per_compartment, slide_capacity, columns_per_rack, rows_per_rack, location FROM banking_cabinet_informations";                   
                    $result = $conn->query($sql);
                    while($row = $result->fetch_row()) {
                      $cabId  = (int)$row[0];
                      $banked = isset($bankedSlides[$cabId]) ? $bankedSlides[$cabId] : 0;

                      $editBtn = "<a href='cabinets.php?id=$cabId&action=2' class='btn btn-sm btn-outline-primary mx-1' title='Edit this cabinet'><i class='fa fa-edit'></i></a>";

                      $delBtn = ($banked > 0)
                              ? "<button type='button' class='btn btn-sm btn-outline-secondary mx-1 js-cab-blocked' disabled"
                                . " title='This cabinet holds $banked banked slide(s). Remove them from its racks before deleting the cabinet.'>"
                                . "<i class='fa fa-trash'></i></button>"
                              : "<a href='delete.php?id=$cabId&type=cabinet' class='btn btn-sm btn-outline-danger mx-1 js-confirm-delete' title='Delete this cabinet'><i class='fa fa-trash'></i></a>";

                      echo("<tr class='text-center'><td><a href='cabinets.php?id=$cabId&action=2'>$row[1]</a></td><td>$row[2]</td><td>$row[3]</td><td>$row[4]</td><td>$row[5]</td><td>$row[6]</td><td>$row[7]</td><td class='text-nowrap'>$editBtn$delBtn</td></tr>");
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

    <!-- Add/Update -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-body">
                <div class="form-group row">
                  <label class="col-sm-12 text-primary col-form-label">Manage Cabinets</label>
                </div>
                <form class="form-horizontal" id="form" autocomplete="off" enctype="multipart/form-data" method="Post" action="iu-cabinets.php" onsubmit="return validateForm()">
                <?php
                  $isEditCabinet = (isset($_GET["id"]) AND $_GET["id"]<>"");
                  if($isEditCabinet){
                    $id = $_GET["id"];
                    $data = ($conn->query("SELECT cabinet_name, compartments_per_cabinet, racks_per_compartment, slide_capacity, columns_per_rack, rows_per_rack, location, image_path, remarks FROM banking_cabinet_informations WHERE cabinet_id='$id'"));
                    $data = $data->fetch_row();
                    /* js-confirm-delete gives this the same confirmation as the
                       Delete icons in the list above, so no delete on this page
                       happens without asking. */
                    $delete = "<a href='delete.php?id=$id&type=cabinet' class='btn bg-gradient-danger js-confirm-delete'>Delete</a>";
                    echo("<input type='hidden' name='action' id='action' value='2'>");
                  }  
                  else {  
                    $data = array('', '', '', '', '', '', '', '', '');   $delete = "";
                    /* COALESCE so the very first cabinet gets 1 instead of NULL:
                       MAX(...)+1 over an empty table is NULL, which left this
                       readonly+required field blank and the form unsubmittable. */
                    $id = ($conn->query("SELECT COALESCE(MAX(cabinet_id)+1, 1) FROM banking_cabinet_informations"));
                    $id = $id->fetch_row();
                    $id = $id[0]; 
                    echo("<input type='hidden' name='action' id='action' value='1'>");
                  }  
                ?>
                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Cabinet ID(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="cid" id="cid" class="text-center form-control"  value="<?=$id;?>" readonly required>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Cabinet name(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="cname" id="cname" class="text-center form-control" value="<?=$data[0];?>" required placeholder="Cabinet name">
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Compartments per Cabinet(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="compartment" id="compartment" class="text-center form-control"  min="1" value="<?=$data[1];?>" required placeholder="Compartments per Cabinet">
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Racks per Compartment(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="rack" id="rack" class="text-center form-control" min="1" value="<?=$data[2];?>" required placeholder="Racks per Compartment">
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Slide capacity(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="capacity" id="capacity" class="text-center form-control"  min="1" value="<?=$data[3];?>" required placeholder="Slide capacity">
                    </div>
                     <label class="col-sm-3 col-form-label-sm">Physical location(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="location" id="location" class="text-center form-control"  min="1" value="<?=$data[6];?>" required placeholder="Physical location">
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Columns per rack(*)</label>
                    <div class="col-sm-3">
                      <input type="number" name="column" id="column" class="text-center form-control"  min="1" value="<?=$data[4];?>" required placeholder="Columns per Rack">
                    </div>
                     <label class="col-sm-3 col-form-label-sm">Rows per rack(*)</label>
                    <div class="col-sm-3">
                      <input type="text" name="row" id="row" class="text-center form-control"  min="1" value="<?=$data[5];?>" required placeholder="Rows per rack">
                    </div>
                  </div>

                   <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Remarks</label>
                    <div class="col-sm-6">
                      <!-- $data is cabinet_name, compartments, racks, capacity, columns,
                           rows, location, image_path, remarks - so remarks is [8].
                           This read [6], the SAME index as Physical location above, so
                           opening a cabinet for edit showed the location in both boxes
                           and saving then overwrote the stored remarks with it. -->
                      <textarea name="remarks" id="remarks" class="form-control"><?=$data[8];?></textarea>
                    </div>
                  </div>
                <?php if(isset($_GET["id"]) AND $_GET["id"]<>"" AND $data[7] <> ""){ ?>  
                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Cabinet Photo</label>
                    <div class="col-sm-6">
                      <img src="<?=$data[7];?>" width="400" height="400">
                    </div>
                  </div>
                <?php } ?>  
                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Upload cabinet photo</label>
                    <div class="col-sm-3">
                      <input type="file" class="text-center form-control" name="image" id="image">
                    </div>
                  </div>
    
                  <div class="form-group row">
                    <div class="col-sm-12">
                      <button type="submit" class="btn bg-gradient-success"><?= $isEditCabinet ? 'Update' : 'Submit'; ?></button>
                      <?=$delete;?>
                      <a href="cabinets.php" class="btn bg-gradient-secondary float-right">Cancel</a>
                    </div>  
                  </div>
                </form>
              </div> 
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>  
  <script>
    $(function () {
      $('#table').DataTable({
        "paging": true,
        "lengthChange": false,
        "searching": false,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
        "order": [[ 0, "desc" ]],
        /* Editing navigates to cabinets.php?id=..., so without this the table
           would come back on page 1 in default order every time. stateSave puts
           the page and sort back as the user left them. */
        "stateSave": true,
        /* The Action column holds buttons; sorting by it means nothing. */
        "columnDefs": [ { "orderable": false, "targets": -1 } ]
      });
    });

    /* One confirmation for every cabinet delete on this page - the icons in the
       list and the Delete button under the form all carry js-confirm-delete.
       SweetAlert when it is available, the browser dialog otherwise, so the
       delete is never one unguarded click. */
    $(document).on('click', '.js-confirm-delete', function (e) {
      e.preventDefault();
      var url = $(this).attr('href');
      if (!window.Swal) {
        if (confirm('Are you sure you want to delete this cabinet? This action cannot be undone.')) {
          window.location = url;
        }
        return;
      }
      Swal.fire({
        title: 'Delete cabinet?',
        text: 'Are you sure you want to delete this cabinet? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        reverseButtons: true,
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel'
      }).then(function (r) { if (r.isConfirmed) { window.location = url; } });
    });

    /* The file input is id="image"; this looked for id="photo", so fileInput was
       null and the function threw on EVERY submit. The throw is why saving still
       worked - an exception in an onsubmit handler leaves the submission alone -
       but it meant the checks below never actually ran.

       The photo is optional, so an empty input is valid and returns early;
       without that, fixing the id alone would have demanded a photo on every
       save, including updates that only change a number. */
    function validateForm() {
      const fileInput = document.getElementById('image');
      if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
          return true;                    // no photo chosen: nothing to validate
      }
      const allowedExtensions = /(\.jpg|\.jpeg|\.png)$/i;
      if (!allowedExtensions.exec(fileInput.value)) {
          alert('Please upload a file with a valid extension (.jpg, .jpeg, .png ).');
          fileInput.value = '';
          return false;
      }
      const maxSize = 2 * 1024 * 1024; // 2 MB
      if (fileInput.files[0].size > maxSize) {
          alert('File size must be less than 2 MB.');
          fileInput.value = '';
          return false;
      }
      return true;
  }

  </script>             
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
