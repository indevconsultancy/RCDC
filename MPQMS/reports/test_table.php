<!-- Auth -->
<?php include_once("../config/auth_ndtlis.php"); ?>
<?php if(!($_SESSION['acl']==1 || ($_SESSION['acl']==2 && $_SESSION["usercat"]==18))){ header("Location: ../dashboard/dashboard.php"); exit; } ?>
<?php include_once("../includes/header.php"); ?>
	<!-- DB -->
	<?php include_once("../config/db_config.php"); ?>
	<!-- CSS -->
	<?php include_once("../includes/css-jquery.php"); ?>
	<!-- Highcharts -->
	<?php include_once("../includes/highcharts.php"); ?>
	

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
						<h1 class="m-0">Drug Test</h1>
					  </div>
					  <div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
						  <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
						  <li class="breadcrumb-item active">Test</li>
						</ol>
					  </div>
					</div>
				  </div>
				</div>
				
					<section class="content">
					<div class="container-fluid">
						<div class="card card-outline card-primary">
							<div class="card-header">
								<h3 class="card-title"><i class="fas fa-filter text-muted"></i> &nbsp;<b>Test Chart Filter</b> — select report type and period</h3>
								<div class="card-tools">
								  <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
									<i class="fas fa-minus"></i>
								  </button>
								</div>
							</div>
							<div class="card-body">
								<form id="form" class="form-horizontal" autocomplete="off" method="Post" action="test_chart1.php">	
									<div class="form-group row">
										<label  class="col-sm-2 col-form-label-sm">Type</label>
										<select name="type" id="type" class="col-sm-3 form-control select2bs4" required>
											<option value="">Select</option>
											<option value=1>Month-wise</option>
											<option value=2>Site-wise</option>
										</select>
									</div>
									<div class="form-group row">
										<label class="col-sm-2 col-form-label-sm">Year</label>
										<div class="col-sm-10">
											<div class="form-check-inline">
												<input class="form-check-input" type='checkbox' id="checkall">
												<label class="form-check-label font-weight-bold">Check/Uncheck All</label>
											</div>
											<?php
												for($i=date("Y");$i>=2017;$i--){
													if($i == date("Y")){$chk = "Checked";} else $chk ="";
													echo("<div class='form-check-inline'><input class='form-check-input checkbox' type='checkbox' name='year[]' value='$i' $chk><label class='form-check-label'>$i</label></div>");
												}
											?>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-sm-2 col-form-label-sm">Month</label>
										<div class="col-sm-10">
											<div class="form-check-inline">
												<input class="form-check-input" type='checkbox' id="checkall1">
												<label class="form-check-label font-weight-bold">Check/Uncheck All</label>
											</div>
											<?php
												for($i=1;$i<=12;$i++){
													if($i == date("m")){$chk = "Checked";} else $chk ="";
													$mObj   = DateTime::createFromFormat('!m', $i);
													$mon = $mObj->format('M');
													echo("<div class='form-check-inline'><input class='form-check-input checkbox1' type='checkbox' name='month[]' value='$i' $chk><label class='form-check-label'>$mon</label></div>");
												}
											?>
										</div>
									</div>
									<div class="form-group row">
										<div class="col-md-6 col-12">
											<div class="form-group">
											  <label class="col-form-label-sm">Test Parameters</label>
											  <select class="par" multiple="multiple" name="parid[]" id="parid" required>
											<?php
												$qry=mysqli_query($connection,"SELECT id,parameter FROM mstestparametertable WHERE status=1 ORDER BY ranking ASC");
												while(list($id,$val)=mysqli_fetch_row($qry)){
													echo("<option value='$id'>$val</option>");
												}		
											?>	
											  </select>
											</div>
									  </div>
										<div id="sitediv" class="col-md-6 col-12" style="display: none;">
											<div class="form-group">
											  <label class="col-form-label-sm">Sites</label>
											  <select class="site" multiple="multiple" name="site[]" id="site" required>
											<?php
												$qry=mysqli_query($commondb,"SELECT center_id,center_name FROM ms_health_center_table WHERE ndtlis=1 ORDER BY dzongkhag_code ASC,center_name ASC");
												while(list($id,$val)=mysqli_fetch_row($qry)){
													echo("<option value='$id'>$val</option>");
												}		
											?>	
											  </select>
											</div>
										</div> 	 
									</div>
									<button type="Submit" name="submit_filter" value="Generate Chart" class="btn bg-gradient-success float-right"><i class="fas fa-chart-bar"></i>&nbsp;Generate Chart</button>
								</form>	
							</div>
						</div>
					</div>
				</section>
			
			<?php 
				if($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['submit_filter'])){
					
					//$qry = "SELECT parameterID,MONTH(rt.registrationDate),COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved'";
					
					// if(isset($_POST["type"])) { 
					// 	$type = $_POST["type"]; 
					// 	if($type == 1){

					// 	}
					// 	else{

					// 	}

					// }

					if(isset($_POST["year"])) { 
						$year = $_POST["year"]; 
						$totyear = count($year);
						$str1 = "";
						for($i=0;$i<count($year);$i++){ $str1 .= $year[$i].","; }
						$str1 = substr($str1,0,-1);
					}

					if(isset($_POST["month"])) { 
						$month = $_POST["month"]; 
						$str2 = "";
						for($i=0;$i<count($month);$i++){ $str2 .= $month[$i].",";}
						$str2 = substr($str2,0,-1);
					}

					if(isset($_POST["parid"])) { 
						$parid = $_POST["parid"]; 
						$str3 = $str4 = "";
						for($i=0;$i<count($parid);$i++){ 
							$str3 .= $parid[$i].",";
							list($par) = mysqli_fetch_row(mysqli_query($connection,"SELECT parameter FROM mstestparametertable WHERE id='$parid[$i]'")); 
							$str4 .= $par.",";
						}
						$str3 = substr($str3,0,-1);
						$str4 = substr($str4,0,-1);

					}
					
					// if(isset($_POST["site"])) { 
					// 	$site = $_POST["parid"]; 
					// 	for($i=0;$i<count($site);$i++){ $str1 .= "'".$site[$i]."',"; }
					// 	$str1 = substr($str1,0,-1);
					// 	$qry3 = " AND spt.samTreatmentStatus IN($str1)";

					// }

					//$qry .= " GROUP BY parameterID,MONTH(rt.registrationDate) ORDER BY parameterID ASC";
					
					//echo $qry;
					$pararr = $tstarr = $monarr = array(); 
					$runqry = mysqli_query($connection,$qry); 
					
					$data = array();
					for($i=0;$i<count($parid);$i++){
						for($j=0;$j<count($month);$j++){
							list($tst) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='$parid[$i]' AND YEAR(rt.registrationDate) IN($str1)")); 
							if($tst == 0) $tst = 0;
							$data[] = $tst;
						}
						

						// $qry = "";
						// echo ("$parid[$i]-$month[$j]-$tst:");
						// echo ("<br>");
						//$qry = "SELECT parameterID,COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved'";
					}

					
					$len = count($month);

					for($i=0;$i<count($data);$i+=$len){
						print_r(array_slice($data,$i,$len));
						echo("<br>");
					}	

					// while(list($pid,$mon,$tst)=mysqli_fetch_row($runqry)){
					// 	list($par) = mysqli_fetch_row(mysqli_query($connection,"SELECT parameter FROM mstestparametertable WHERE id='$pid'")); 
					// 	$pararr[] = $par;
					// 	$monarr[] = $mon;
					// 	$tstarr[] = $tst;
					// }	
					// $data_par = json_encode($pararr,JSON_NUMERIC_CHECK);
					// $data_tst = json_encode($tstarr,JSON_NUMERIC_CHECK);
					// $data_cat = json_encode($month,JSON_NUMERIC_CHECK);
					$container = "container0";
				?>
						<section class="content">
							<div class="container-fluid">
						        <div class="row">
						          <div class="col-12">
						            <div class="card card-outline card-info">
							            <div class="card-header">
							            	<h3 class="card-title"><i class="fas fa-chart-bar text-info"></i> &nbsp;<b>Monthly Parameter Wise Testing Report</b> — Year: <?=$str1;?> &middot; Month: <?=$str2;?> &middot; Parameter: <?=$str4;?></h3>
							            </div>
							            <div class="card-body"><div id="<?=$container;?>"></div></div>
						            </div>
						          </div>
						        </div>
							</div>		
						</section>	
				<?php		
						//include("test_chart2.php");
					}	
				?>

			</div>
		<?php include_once("../includes/footer.php"); ?>
		</div>

	<script>
	 	$(document).ready(function(){
		   $("#type").change(function(){
				var type = $('#type').val();
				if(type==2){
					$("#sitediv").show();

				}
				else{
					$("#sitediv").hide();
				}
			});

		   $("#checkall").change(function(){
			 var checked = $(this).is(':checked');
			 if(checked){
			   $(".checkbox").each(function(){
				 $(this).prop("checked",true);
			   });
			 }else{
			   $(".checkbox").each(function(){
				 $(this).prop("checked",false);
			   });
			 }
		   });
		  $(".checkbox").click(function(){
			if($(".checkbox").length == $(".checkbox:checked").length) {
			  $("#checkall").prop("checked", true);
			} else { $("#checkall").removeAttr("checked"); }
		  });

		  $("#checkall1").change(function(){
			 var checked = $(this).is(':checked');
			 if(checked){
			   $(".checkbox1").each(function(){
				 $(this).prop("checked",true);
			   });
			 }else{
			   $(".checkbox1").each(function(){
				 $(this).prop("checked",false);
			   });
			 }
		   });
		  $(".checkbox1").click(function(){
			if($(".checkbox1").length == $(".checkbox1:checked").length) {
			  $("#checkall1").prop("checked", true);
			} else { $("#checkall1").removeAttr("checked"); }
		  });
		});	
	 $(function () {
		$('.site').bootstrapDualListbox();
		$('.par').bootstrapDualListbox();
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
	  })
	</script>	
	</body>
</html>
