<!-- Auth -->
<?php include_once("../config/auth_ndtlis.php"); ?>
<?php include_once("../config/mpq_validate.php"); mpq_require_page('registration', 'view'); /* effective-role RBAC: who may open this page */ ?>
<?php
	include_once("../config/db_config.php");
	$regid = (int)($_GET["rid"] ?? 0);   // no record number -> 0 (no row); never an undefined-key warning

	list($sid,$dor,$edlid,$bno) = mysqli_fetch_row(mysqli_query($connection,"SELECT sampleID,registrationDate,edlID,batchNo FROM registrationtable WHERE registrationID='".$regid."'"));
	list($edl) = mysqli_fetch_row(mysqli_query($connection,"SELECT edl FROM msedltable WHERE id='".$edlid."'"));

	// Completed report => read-only (the Update button is hidden; update_result.php enforces the same lock).
	require_once(__DIR__."/report_lock_lib.php");
	$mpLocked = mpqms_report_locked($connection, (int)$regid);
?>
<?php include_once("../includes/header.php"); ?>
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
					<h1 class="m-0">Edit Result</h1>
					<?php if(!empty($mpLocked)){ ?>
					<div class="text-muted small mt-1"><i class="fas fa-lock"></i>&nbsp;<b>Completed report &mdash; read-only.</b> <?=htmlspecialchars(mpqms_locked_message());?></div>
					<?php } ?>
				  </div>
				  <div class="col-sm-6">
					<ol class="breadcrumb float-sm-right">
					  <li class="breadcrumb-item"><a href="../regtest/manager.php">Manager</a></li>
					  <li class="breadcrumb-item active">Result</li>
					</ol>
				  </div>
				</div>
			  </div>
			</div>
				
			<section class="content">
				<div class="container-fluid">
					<div class="row">
						<div class="col-12">
							<div class="card card-primary">
								<div class="card-header">
									<h3 class="card-title"><i class="fas fa-vials text-primary"></i> &nbsp;<b>Edit Result</b> — update laboratory test results</h3>
								</div>
								<form id="form" class="form-horizontal" method="Post" autocomplete="off" action="update_result.php">
									<input type="hidden" name="regid" value="<?=$regid;?>">
									<div class="card-body">
										<div class="form-group row">
											<label  class="col-sm-3 col-form-label-sm">Registration Date</label>
										    <input type="text" class="col-sm-3 form-control text-center" value="<?=$dor;?>" readonly>
											<label  class="col-sm-3 col-form-label-sm">Sample ID</label>
											<input type="text" class="col-sm-3 form-control" value="<?=$sid;?>" readonly>
										</div>
										<div class="form-group row">
											<label  class="col-sm-3 col-form-label-sm">Name of Sample</label>
											<input type="text" class="col-sm-3 form-control" value="<?=$edl;?>" readonly>
											<label  class="col-sm-3 col-form-label-sm">Batch No.</label>
										    <input type="text" class="col-sm-3 form-control" value="<?=$bno;?>" readonly>
										</div>
										<div class="preview-section mt-3">
											<h5>Test Result</h5>
										</div>
										<div class="form-group row">
											<div class="col-sm-12 table-responsive">
												<table class="table table-bordered">
													<thead>
														<tr class="text-center">
														  <th style="width: 10%">Test</th>
														  <th style="width: 20%">Observation</th>
														  <th style="width: 15%">Standard</th>
														  <th style="width: 15%">Result</th>
														  <th style="width: 15%">Test Date</th>
														  <th style="width: 10%">Tested By</th>
														  <th style="width: 15%">Remarks</th>
														</tr>
													</thead>
													<tbody>	
													<?php 
														$qry=mysqli_query($connection,"SELECT resultID,parameter,unit,testDate,testedBy,observation,standard,result,remarks FROM mstestparametertable pt,labresulttable rt WHERE pt.id=rt.parameterID AND rt.registrationID='$regid' ORDER BY id ASC"); 
														$i = 0;
														while(list($rid,$par,$unt,$dot,$tby,$obs,$std,$rslt,$rmk)=mysqli_fetch_row($qry)){
															list($tbyname)=mysqli_fetch_row(mysqli_query($commondb,"SELECT fullname FROM user_profile_table WHERE username='$tby'"));
															if($unt != ""){$unt = "($unt)";}
													?>	
														<tr>
															<td><small><input type="hidden" name="rid[]" value="<?=$rid;?>"/><?=$par;?><br><?=$unt;?></small></td>
															<td><textarea name="obs[]" class="form-control" rows=2 placeholder="Observation"><?=$obs;?></textarea></td>
															<td><textarea name="std[]" class="form-control" rows=2 placeholder="Standard"><?=$std;?></textarea></td>
															<td>
																
																<select name="rslt[]" class="form-control">
																	<option value="<?=$rslt;?>"><?=$rslt;?></option>
																	<option value="Complies">Complies</option>
																	<option value="Does not comply">Does not comply</option>
																</select>
															</td>
															<td><input type="text" name="dot[]" class="text-center form-control" data-inputmask-alias="datetime" data-inputmask-inputformat="yyyy-mm-dd" data-mask value="<?=$dot;?>"/></td>
															<td>
															<?php
																$usrqry=mysqli_query($commondb,"SELECT pt.username,pt.fullname FROM user_system_access_table ut,user_profile_table pt WHERE ut.username=pt.username AND pt.center_id='997' AND ut.system_id=9 AND user_category=18");
															?>
																<select name="tby[]" class="form-control">
																	<option value="<?=$tby;?>"><?=$tbyname;?></option>
																<?php
																	while(list($uid,$usr)=mysqli_fetch_row($usrqry)){
																		echo("<option value='$uid'>$usr</option>");
																	}	
																?>
																</select>
															</td>
															<td><textarea name="rmk[]" class="form-control" rows=2 placeholder="Remarks"><?=$rmk;?></textarea></td>
														</tr>
													<?php } ?>
													</tbody>
												</table>
										  </div>
										</div>
										<a href="manager.php" class="btn bg-gradient-secondary"><i class="fa fa-arrow-left"></i> Cancel</a>
										<?php if(empty($mpLocked)){ /* completed-report lock */ ?>
										<input type="submit" value="Update Result" class="btn bg-gradient-success float-right">
										<?php } ?>
									</div>
								</form>
							</div>
						</div>	
					</div>	
				</div>
			</section>
		</div>
	<?php include_once("../includes/footer.php"); ?>
	</div>
	<script>
	 $(function () {
		$('#form').validate({
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
		 
		$('.select2bs4').select2({
		  theme: 'bootstrap4'
		})
		$('#dor').datetimepicker({
			format: 'L'
		});
		$('#dom').datetimepicker({
			format: 'L'
		});
		$('#doe').datetimepicker({
			format: 'L'
		});
	  })
	</script>	
	</body>
</html>
