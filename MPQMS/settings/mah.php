<!-- Auth -->
<?php include_once("../config/auth_ndtlis.php"); ?>
<?php include_once("../config/mpq_validate.php"); mpq_require_page('mah', 'view'); /* effective-role RBAC: who may open this page */ ?>
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
						<h1 class="m-0">MAH List</h1>
					  </div>
					  <div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
						  <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
						  <li class="breadcrumb-item active">MAH</li>
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
									<h3 class="card-title"><i class="fas fa-th-list text-primary"></i> &nbsp;<b>MAH List</b></h3>
									</div>
									<div class="card-body">
										<table id="table" class="table table-bordered table-striped" style="visibility:hidden;">
										  <thead>
											  <tr class="text-center">
												<th>ID</th>
												<th>MAH</th>
												<th>Status</th>
												<th>Action</th>
											  </tr>
										  </thead>
										  <tbody>
										  <?php 		
											  $qry = mysqli_query($connection,"SELECT id,name,status FROM msmahtable");
											  while(list($id,$mah,$sts) = mysqli_fetch_row( $qry)){	  
												  if($sts == 1) $sts = "<span class='text-success font-weight-bold'>Active</span>"; else $sts = "<span class='text-muted'>Inactive</span>";
												  $mpEdit = mpq_can_do('mah','edit') ? "<a href='mah.php?id=$id' title='Edit'><i class='far fa-edit'></i></a>" : "";
												  $mpDel = mpq_can_do('mah','delete') ? " &nbsp;<a href='javascript:void(0)' class='mp-del-master' data-id='$id' data-type='DH' data-name=\"".htmlspecialchars((string)$mah, ENT_QUOTES)."\" title='Delete'><i class='fas fa-trash-alt text-danger'></i></a>" : "";
												  echo("<tr>
													<td class='text-center'>$id</td>
													<td>$mah</td>
													<td>$sts</td>
													<td class='text-center'>$mpEdit$mpDel</td></tr>");
											  }	  
										  ?>	  
										  </tbody>
										</table>
									</div>	
								</div>
							</div>	

							<?php if(mpq_can_do('mah','add') || mpq_can_do('mah','edit')){ /* effective-role RBAC: who may add or edit */ ?>
							<div class="col-lg-6 col-12">
								<div class="card card-primary">
									<div class="card-header">
									<h3 class="card-title"><i class="far fa-edit text-primary"></i> &nbsp;<b class="mp-form-title"><?php echo (isset($_GET["id"]) && $_GET["id"]!="") ? "Edit MAH" : "Add MAH"; ?></b></h3>
									</div>
									<div class="card-body">
									<form id="form" class="form-horizontal clearfix" method="Post" autocomplete="off" action="save_update.php">  	
						              	<?php 
						              		// Edit mode. The record id is never trusted: it must be a positive integer,
						              		// it is looked up with a prepared statement, and the form is only shown when
						              		// the record really exists and this role may edit it.
						              		$mpEditId = (isset($_GET["id"]) && trim((string)$_GET["id"]) !== "" && ctype_digit(trim((string)$_GET["id"]))) ? (int)trim($_GET["id"]) : 0;
						              		$mpRow = null;
						              		if($mpEditId > 0 && mpq_can_do('mah','edit')){
						              			$mpSt = mysqli_prepare($connection, "SELECT name, status FROM msmahtable WHERE id = ?");
						              			mysqli_stmt_bind_param($mpSt, 'i', $mpEditId);
						              			mysqli_stmt_execute($mpSt);
						              			mysqli_stmt_bind_result($mpSt, $mpName, $mpSts);
						              			if(mysqli_stmt_fetch($mpSt)){ $mpRow = array($mpName, $mpSts); }
						              			mysqli_stmt_close($mpSt);
						              		}
						              		if($mpRow !== null){
						              			$id = $mpEditId;
						              			echo("<input type='hidden' name='id' value='".(int)$id."' required><input type='hidden' name='type' value='UH' required>");
						              			list($mah,$sts) = $mpRow;
						              			if($sts == 1){	$chk1="Checked"; $chk2="";	}
						              			else{	$chk1=""; $chk2="Checked";	}
						              			$action = "Update MAH";	
						              			$add = "<button type='button' class='btn bg-gradient-secondary float-left mp-cancel'><i class='fas fa-times'></i>&nbsp;Cancel</button>";
						              		}	
						              		else{
						              			echo("<input type='hidden' name='type' value='NH' required>");
						              			$for = $chk1 = $chk2 = "";
						              			$action = "Save MAH";
												$add = "";
						              		}
						              	?>	
						              		
						              	<div class="form-group row">
											<label class="col-sm-4 col-form-label">MAH</label>
											<div class="col-sm-8"><input type="text" name="mah" id="mah" class="form-control" value="<?=$mah;?>" placeholder="Enter MAH Name" required></div>
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
						<?php } ?>
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

			initMpqmsSettings({ table: '#table', form: '#form', type: 'H', label: 'MAH' });
			initMpqmsImport({ table: '#table', type: 'H', label: 'MAH', canImport: <?= mpq_can_do('mah','import') ? 'true' : 'false'; ?> });   /* same roles that may Add (save_update.php NH) */
		});
	</script>	
	</body>
</html>
