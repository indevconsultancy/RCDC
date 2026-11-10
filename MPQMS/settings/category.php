<!-- Auth -->
<?php include_once("../config/auth_ndtlis.php"); ?>
<?php if(!($_SESSION['acl']==1 || ($_SESSION['acl']==2 && $_SESSION["usercat"]==18))){ header("Location: ../dashboard/dashboard.php"); exit; } ?>
<?php include_once("../includes/header.php"); ?>
	<!-- DB -->
	<?php include_once("../config/db_config.php"); ?>
	<!-- CSS -->
	<?php include_once("../includes/css-jquery.php"); ?>
	<body class="hold-transition sidebar-mini layout-fixed">
		<div class="wrapper">
			<!-- Top Navbar -->
			<?php include_once("../includes/top_navigation.php"); ?>
			<!-- Main Sidebar Container -->
			<?php include_once("../includes/main_navigation.php"); ?>
			 <div class="content-wrapper">
				<div class="content-header">
				  <div class="container-fluid">
					<div class="row mb-2">
					  <div class="col-sm-6">
						<h1 class="m-0">Drug Category</h1>
					  </div>
					  <div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
						  <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
						  <li class="breadcrumb-item active">Category</li>
						</ol>
					  </div>
					</div>
				  </div>
				</div>
				
				<section class="content">
					<div class="container-fluid">
						<div class="row">
							<div class="col-lg-6 col-12">
								<div class="card card-outline card-primary">
									<div class="card-header">
									<h3 class="card-title"><i class="fas fa-th-list text-primary"></i> &nbsp;<b>Drug Categories</b></h3>
									</div>
									<div class="card-body">
										<table id="table" class="table table-bordered table-striped" style="visibility:hidden;">
										  <thead>
											  <tr class="text-center">
												<th>ID</th>
												<th>Category</th>
												<th>Status</th>
												<th>Action</th>
											  </tr>
										  </thead>
										  <tbody>
										  <?php 		
											  $qry = mysqli_query($connection,"SELECT id,category,status FROM msdrugcategorytable");
											  while(list($id,$cat,$sts) = mysqli_fetch_row( $qry)){	  
												  if($sts == 1) $sts = "<span class='text-success font-weight-bold'>Active</span>"; else $sts = "<span class='text-muted'>Inactive</span>";
												  $mpDel = ($_SESSION['acl']==1) ? " &nbsp;<a href='javascript:void(0)' class='mp-del-master' data-id='$id' data-type='DC' data-name=\"".htmlspecialchars((string)$cat, ENT_QUOTES)."\" title='Delete'><i class='fas fa-trash-alt text-danger'></i></a>" : "";
												  echo("<tr>
													<td class='text-center'>$id</td>
													<td>$cat</td>
													<td>$sts</td>
													<td class='text-center'><a href='category.php?id=$id' title='Edit'><i class='far fa-edit'></i></a>$mpDel</td></tr>");
											  }	  
										  ?>	  
										  </tbody>
										</table>
									</div>	
								</div>
							</div>	

							<div class="col-lg-6 col-12">
								<div class="card card-primary">
									<div class="card-header">
									<h3 class="card-title"><i class="far fa-edit text-primary"></i> &nbsp;<b class="mp-form-title"><?php echo (isset($_GET["id"]) && $_GET["id"]!="") ? "Edit Category" : "Add Category"; ?></b></h3>
									</div>
									<div class="card-body">
									<form id="form" class="form-horizontal clearfix" method="Post" autocomplete="off" action="save_update.php">  	
						              	<?php 
						              		if(isset($_GET["id"]) AND $_GET["id"]<>""){
						              			$id=$_GET["id"];
						              			echo("<input type='hidden' name='id' value='$id' required><input type='hidden' name='type' value='UC' required>");
						              			list($cat,$sts) = mysqli_fetch_row(mysqli_query($connection,"SELECT category,status FROM msdrugcategorytable WHERE id='$id'"));
						              			if($sts == 1){	$chk1="Checked"; $chk2="";	}
						              			else{	$chk1=""; $chk2="Checked";	}
						              			$action = "Update Category";	
						              			$add = "<button type='button' class='btn bg-gradient-secondary float-left mp-cancel'><i class='fas fa-times'></i>&nbsp;Cancel</button>";
						              		}	
						              		else{
						              			echo("<input type='hidden' name='type' value='NC' required>");
						              			$for = $chk1 = $chk2 = "";
						              			$action = "Save Category";
												$add = "";
						              		}
						              	?>	
						              		
						              	<div class="form-group row">
											<label class="col-sm-4 col-form-label">Category</label>
											<div class="col-sm-8"><input type="text" name="cat" id="cat" class="form-control" value="<?=$cat;?>" placeholder="Enter Category Name" required></div>
										</div>
										<div class="form-group row">
											<label class="col-sm-4 col-form-label">Status</label>
											<div class="col-sm-8 col-form-label">
												<div class="form-check-inline">
													<input class="form-check-input" type='radio' name='sts' value="1" <?=$chk1;?> required>
													<label class="form-check-label">Active</label>
												</div>
												<div class="form-check-inline">
													<input class="form-check-input" type='radio' name='sts' value="0" <?=$chk2;?> required>
													<label class="form-check-label">Inactive</label>
												</div>
											</div>
										</div>
										<?php echo $add;?>
										<input type="submit" value="<?=$action;?>" class="btn bg-gradient-success float-right mp-submit">	
									</form>  
									</div>	
								</div>
							</div>	
						</div>	
					</div>
				</section>
			</div>
		<?php include_once("../includes/footer.php"); ?>
		</div>
		<script src="../dist/js/mpqms-settings.js"></script>
		<script src="../dist/js/mpqms-settings-import.js"></script>
		<script>
		$(function () {
			$("#table").DataTable({
			  "initComplete": function(){ $('#table').css('visibility','visible'); },
			  "responsive": true, "lengthChange": false, "autoWidth": false,
			  "order": [[ 0, "asc" ]]
			}).buttons().container().appendTo('#table_wrapper .col-md-6:eq(0)');

			initMpqmsSettings({ table: '#table', form: '#form', type: 'C', label: 'Category' });
			initMpqmsImport({ table: '#table', type: 'C', label: 'Category', canImport: true });   /* page already restricted to Admin / NDTL lab */
		});
	</script>	
	</body>
</html>
