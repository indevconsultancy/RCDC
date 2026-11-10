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
            <h1>M&E Indicators</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Settings | Indicators</li>
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
                  <label class="col-sm-12 text-primary col-form-label">Add/Update M&S Indicators</label>
                </div>
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="iu-onsite-checklist.php">
                <?php
                  if(isset($_GET["id"]) AND $_GET["id"]<>""){
                    $id = $_GET["id"];
                    $data = ($conn->query("SELECT checklist_id, subcategory_id, checklist, checklist_order, checklist_status FROM ms_onsite_checklists WHERE checklist_id='$id'"));
                    $data = $data->fetch_row();

                    $subcategoryqry = ($conn->query("SELECT category_id, subcategory_value FROM ms_onsite_subcategories WHERE subcategory_id='$data[1]'"));
                    list($category_id,$subcategory) = $subcategoryqry->fetch_row();
                    $categorynqry = $conn->query("SELECT category_value FROM ms_onsite_categories WHERE category_id=".$category_id);
                    list($category) = $categorynqry->fetch_row();

                    $statusChk1 = $statusChk2 = "";
                    if($data[4] == 1) $statusChk1 = "Checked";
                    else $statusChk2 = "Checked";
                    $delete = "<a href='delete.php?id=$id&type=oc' class='btn bg-gradient-danger'>Delete</a>";
                    echo("<input type='hidden' name='action' id='action' value='2'>");
                  }
                  else {
                    $data = array('', '', '', '', '', '');
                    $category='Select Category';
                    $subcategory='Select Subcategory';
                    $category_id='';

                    $id = $statusChk1 = $statusChk2 = $delete = "";
                    echo("<input type='hidden' name='action' id='action' value='1'>");
                  }
                ?>
                  <input type="hidden" name="id" id="id" value="<?=$id;?>">

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Category</label>
                    <div class="col-sm-3">
                      <select class="form-control selectCat" name="category" id="category" style="width:100%;">
                        <option selected="selected" value="<?=$category_id;?>"><?=$category;?></option>
                        <?php
                         $catquery = "SELECT category_id, category_value FROM ms_onsite_categories WHERE category_status=1 ORDER BY category_order ASC";
                         $result1 = $conn->query($catquery);
                         while($cat = $result1->fetch_row()) {
                          echo '<option value="'.$cat[0].'">'.$cat[1].'</option>';
                         }
                        ?>
                      </select>
                    </div>

                    <label class="col-sm-3 col-form-label-sm">SubCategory</label>
                    <div id="subCatDiv" class="col-sm-3" >
                      <select class="form-control selectsub" name="subcategory" id="subcategory" style="width:100%;">
                        <option selected="selected" value="<?=$data[1];?>"> <?=$subcategory;?></option>
                      </select>
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Status</label>
                    <div class="col-sm-3">
                      <div class="form-check-inline">
                        <input class="form-check-input" type="radio" name="status" id="status" value="1" <?=$statusChk1;?>>
                        <label class="form-check-label">Enabled</label> &nbsp
                        <input class="form-check-input" type="radio" name="status" id="status" value="0" <?=$statusChk2;?>>
                        <label class="form-check-label">Disabled</label> &nbsp
                      </div>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Rank</label>
                    <div class="col-sm-3">
                      <input type="number" name="rank" id="rank" class="form-control"  value="<?=$data[3];?>" placeholder="Rank">
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Indicator</label>
                    <div class="col-sm-9">
                      <textarea name="indicator" id="indicator" class="form-control" placeholder="Indicator"><?=$data[2];?></textarea>
                    </div>
                  </div>

                  <div class="form-group row">
                    <div class="col-sm-12">
                      <button type="submit" class="btn bg-gradient-success">Submit</button>
                      <?=$delete;?>
                      <a href="onsite-checklist.php" class="btn bg-gradient-secondary float-right">Cancel</a>
                    </div>
                  </div>
                </form>
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </div>
      <!-- /.container-fluid -->
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
                    <tr>
                      <th>ID</th>
                      <th>Category</th>
                      <th>SubCategory</th>
                      <th>Query</th>
                      <th>Rank</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    $sql = "SELECT checklist_id, subcategory_id, checklist, checklist_order, checklist_status FROM ms_onsite_checklists";
                    $result = $conn->query($sql);
                    while($row = $result->fetch_row()) {
                      $subcategoryqry="SELECT category_id, subcategory_value FROM ms_onsite_subcategories WHERE subcategory_id=".$row[1];
                      list($category_id,$subcategory) = $conn->query($subcategoryqry)->fetch_row();
                      $categoryqry="SELECT category_value FROM ms_onsite_categories WHERE category_id=".$category_id;
                      list($category) = $conn->query($categoryqry)->fetch_row();

                      echo("<tr>
                          <td class='text-center'><a href='onsite-checklist.php?id=$row[0]&action=2'>$row[0]</a></td>
                          <td>$category</td>
                          <td>$subcategory</td>
                          <td>$row[2]</td>
                          <td class='text-center'>$row[3]</td>
                          <td class='text-center'>$row[4]</td></tr>");
                    }
                  ?>
                  </tbody>
                </table>
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.table -->
  </div>
  <!-- /.content-wrapper -->    
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
        "order": [[ 1, "asc" ]]
      });

      //Initialize Select2 Elements
      $('.selectCat').select2({
        theme: 'bootstrap4'
      });

      //Initialize Select2 Elements
      $('.selectsub').select2({
        theme: 'bootstrap4'
      });

    });


    $(document).ready(function(){
      $('#form').validate({
        rules: {
					category: "required",
          subcategory:"required",
          status: "required",
          rank: "required",
          indicator: "required",
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

      $("#category").change(function(){
          var category_id = $('#category').val();
          $("#subCatDiv").load("load-subcategory.php?category_id="+category_id);
      });
    })  
  </script>             
<!-- Page footer -->
<!-- Select2 -->
<!-- <script src="../plugins/select2/js/select2.full.min.js"></script> -->
<?php require_once("../includes/footer.php"); ?>
