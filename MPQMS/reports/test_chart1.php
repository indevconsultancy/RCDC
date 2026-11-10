<?php
ob_start();
session_start();
?>
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

			 <?php if(isset($_SESSION['chart_errors'])){ ?>
<div class="container-fluid mt-2">
    <div id="errorAlert" class="alert alert-danger alert-dismissible fade show">
        <button type="button" class="close" data-dismiss="alert">&times;</button>

        <strong><i class="fas fa-exclamation-triangle"></i> Validation Error!</strong><br>

        <?php
        foreach($_SESSION['chart_errors'] as $error){
            echo $error . "<br>";
        }
        unset($_SESSION['chart_errors']);
        ?>
    </div>
</div>
<?php } ?>
				<div class="content-header">
				  <div class="container-fluid">
					<div class="row mb-2">
					  <div class="col-sm-6">
						<h1 class="m-0">Total Test</h1>
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
								<form id="form" class="mp-filter-form" autocomplete="off" method="Post" action="test_chart1.php">
									<?php
	// The filter redraws with the selection that produced the chart below it. Before, it always
	// reset to the current year and month, so the page showed one selection above a chart built
	// from another. With nothing submitted yet, the defaults are unchanged.
	$mpSelType   = (string)($_POST['type'] ?? '');
	$mpSelYears  = array_map('strval', (array)($_POST['year']  ?? array()));
	$mpSelMonths = array_map('strval', (array)($_POST['month'] ?? array()));
	$mpSubmitted = !empty($mpSelYears) || !empty($mpSelMonths);
	$mpSelSites  = array_map('strval', (array)($_POST['site'] ?? array()));
?>
<!-- Row 1: report type + conditional multi-select (2 large fields) -->
									<div class="row">
										<div class="col-lg-6 col-md-6 col-12 form-group">
											<label for="type">Report Type</label>
											<select name="type" id="type" class="form-control mp-ctl select2bs4">
												<option value="">Select Report Type</option>
												<option value="1"<?=($mpSelType==='1')?' selected':'';?>>Month-wise</option>
												<option value="2"<?=($mpSelType==='2')?' selected':'';?>>Site-wise</option>
												<option value="3"<?=($mpSelType==='3')?' selected':'';?>>Collection-wise</option>
											</select>
										</div>
										<div id="sitediv" class="col-lg-6 col-md-6 col-12 form-group" style="<?=in_array($mpSelType, array('2','3'), true) ? '' : 'display: none;';?>">
											<label>Sites / Collection Centers</label>
											<select class="site" multiple="multiple" name="site[]" id="site">
										<?php
											$qry=mysqli_query($commondb,"SELECT center_id,center_name FROM ms_health_center_table WHERE ndtlis=1 ORDER BY dzongkhag_code ASC,center_name ASC");
											while(list($id,$val)=mysqli_fetch_row($qry)){
												echo("<option value='$id'".(in_array((string)$id, $mpSelSites, true) ? " selected" : "").">$val</option>");
											}
										?>
											</select>
										</div>
									</div>
									<!-- Row 2: period filter cards (2 per row) -->
									<div class="row">
										<div class="col-lg-6 col-md-6 col-12 form-group">
											<div class="mp-filter-group h-100">
												<span class="mp-fg-label">Year</span>
												<div class="mp-chips">
													<div class="form-check-inline">
														<input class="form-check-input" type='checkbox' id="checkall">
														<label class="form-check-label font-weight-bold">Check/Uncheck All</label>
													</div>
													<?php
														for($i=date("Y");$i>=2017;$i--){
															$chk = ($mpSubmitted ? in_array((string)$i, $mpSelYears, true) : ($i == date("Y"))) ? "Checked" : "";
															echo("<div class='form-check-inline'><input class='form-check-input checkbox' type='checkbox' name='year[]' value='$i' $chk><label class='form-check-label'>$i</label></div>");
														}
													?>
												</div>
											</div>
										</div>
										<div class="col-lg-6 col-md-6 col-12 form-group">
											<div class="mp-filter-group h-100">
												<span class="mp-fg-label">Month</span>
												<div class="mp-chips">
													<div class="form-check-inline">
														<input class="form-check-input" type='checkbox' id="checkall1">
														<label class="form-check-label font-weight-bold">Check/Uncheck All</label>
													</div>
													<?php
														for($i=1;$i<=12;$i++){
															$chk = ($mpSubmitted ? in_array((string)$i, $mpSelMonths, true) : ($i == date("m"))) ? "Checked" : "";
															$mObj   = DateTime::createFromFormat('!m', $i);
															$mon = $mObj->format('M');
															echo("<div class='form-check-inline'><input class='form-check-input checkbox1' type='checkbox' name='month[]' value='$i' $chk><label class='form-check-label'>$mon</label></div>");
														}
													?>
												</div>
											</div>
										</div>
									</div>
									<!-- Professional footer -->
									<div class="mp-filter-footer">
										<button type="Submit" name="submit_filter" value="Generate Chart" class="btn bg-gradient-success mp-gen-btn"><i class="fas fa-chart-bar"></i>&nbsp;Generate Chart</button>
									</div>
								</form>	
							</div>
						</div>
					</div>
				</section>
			
			<?php 
				if($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['submit_filter'])){

				   $errors = [];

$header = '';
$str1 = '';
$str2 = '';
$datacat = '[]';

$dataass = '[]';
$datawgt = '[]';
$datadin = '[]';
$datadss = '[]';
$datafri = '[]';
$dataidt = '[]';
$datastt = '[]';
$dataucn = '[]';
$datauwg = '[]';

$type  = $_POST['type'] ?? '';
$year  = $_POST['year'] ?? [];
$month = $_POST['month'] ?? [];
$site  = $_POST['site'] ?? [];

if(empty($type)){
    $errors[] = "Please select Report Type.";
}

if(empty($year)){
    $errors[] = "Please select at least one Year.";
}

if(empty($month)){
    $errors[] = "Please select at least one Month.";
}

if(($type == 2 || $type == 3) && empty($site)){
    $errors[] = "Please select at least one Site/Collection Center.";
}

if(!empty($errors)){
    $_SESSION['chart_errors'] = $errors;
    header("Location: test_chart1.php");
    exit;
}
else{
                    $header = '';
                    $str1 = '';
                    $str2 = '';
					if(isset($_POST["year"])) { 
						$year = $_POST["year"]; 
						$str1 = "";
						for($i=0;$i<count($year);$i++){ $str1 .= $year[$i].","; }
						$str1 = substr($str1,0,-1);
					}

					$monthName = [];
					if(isset($_POST["month"])) { 
						$month = $_POST["month"]; 
						$str2 = "";
						for($i=0;$i<count($month);$i++){ 
							$str2 .= $month[$i].",";
							$monthName[] = date("F", mktime(0, 0, 0, (int)$month[$i], 1));
						}
						$str2 = substr($str2,0,-1);
					}

					$paridarr  = array('1','3','5','6','8','9','15','17','19'); 
					if(isset($_POST["type"])) { 
						$type = $_POST["type"]; 
						if($type == 1){
							if(!empty($month)){
							for($j=0;$j<count($month);$j++){ 
								$ass[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='1' AND YEAR(rt.registrationDate) IN($str1)")); 
								$wgt[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='3' AND YEAR(rt.registrationDate) IN($str1)")); 
								$din[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='5' AND YEAR(rt.registrationDate) IN($str1)")); 
								$dss[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='6' AND YEAR(rt.registrationDate) IN($str1)")); 
								$fri[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='8' AND YEAR(rt.registrationDate) IN($str1)")); 
								$idt[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='9' AND YEAR(rt.registrationDate) IN($str1)")); 
								$stt[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='15' AND YEAR(rt.registrationDate) IN($str1)")); 
								$ucn[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='17' AND YEAR(rt.registrationDate) IN($str1)")); 
								$uwg[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate)='$month[$j]' AND lt.parameterID='19' AND YEAR(rt.registrationDate) IN($str1)")); 
							}
							$datacat = json_encode($monthName,JSON_NUMERIC_CHECK);
							$header = "Monthly Testing Report";
						}}
						else{
							if(isset($_POST["site"])) { 
								$site = $_POST["site"]; 
								for($i=0;$i<count($site);$i++){
									$siteName[] = mysqli_fetch_row(mysqli_query($commondb,"SELECT center_name FROM ms_health_center_table WHERE center_id='$site[$i]'")); 
									if($type == 2){	
										$qrystr = "rt.siteID='$site[$i]'";	
										$header = "Site-wise Testing Report";
									}
									else{	
										$qrystr = "rt.collectID='$site[$i]'";	
										$header = "Collection-wise Testing Report";
									}	
									$qry1 = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='1' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$ass[] = mysqli_fetch_row(mysqli_query($connection,$qry1)); 
									
									$qry = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='3' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$wgt[] = mysqli_fetch_row(mysqli_query($connection,$qry)); 
									
									
									$qry = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='5' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$din[] = mysqli_fetch_row(mysqli_query($connection,$qry)); 
									
									$qry = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='6' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$dss[] = mysqli_fetch_row(mysqli_query($connection,$qry)); 
									
									$qry = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='8' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$fri[] = mysqli_fetch_row(mysqli_query($connection,$qry)); 
									
									$qry = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='9' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$idt[] = mysqli_fetch_row(mysqli_query($connection,$qry)); 
									
									$qry = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='15' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$stt[] = mysqli_fetch_row(mysqli_query($connection,$qry)); 
									
									$qry = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='17' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$ucn[] = mysqli_fetch_row(mysqli_query($connection,$qry)); 

									$qry = "SELECT COUNT(*) FROM registrationtable rt,labresulttable lt WHERE rt.registrationID=lt.registrationID AND lt.approval='Approved' AND MONTH(rt.registrationDate) IN($str2) AND lt.parameterID='19' AND YEAR(rt.registrationDate) IN($str1) AND $qrystr";
									$uwg[] = mysqli_fetch_row(mysqli_query($connection,$qry)); 
								}
							}
							$datacat = json_encode($siteName,JSON_NUMERIC_CHECK);
						}
					}

					$dataass = json_encode($ass,JSON_NUMERIC_CHECK);
					$datawgt = json_encode($wgt,JSON_NUMERIC_CHECK);
					$datadin = json_encode($din,JSON_NUMERIC_CHECK);
					$datadss = json_encode($dss,JSON_NUMERIC_CHECK);
					$datafri = json_encode($fri,JSON_NUMERIC_CHECK);
					$dataidt = json_encode($idt,JSON_NUMERIC_CHECK);
					$datastt = json_encode($stt,JSON_NUMERIC_CHECK);
					$dataucn = json_encode($ucn,JSON_NUMERIC_CHECK);
					$datauwg = json_encode($uwg,JSON_NUMERIC_CHECK);
}
				?>
						<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card card-outline card-info mp-result-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar text-info"></i> &nbsp;<b><?=$header;?></b> — Year: <?=$str1;?> &middot; Month: <?=$str2;?></h3>
                    </div>

                    <div class="card-body">
                        <div id="container" style="height:380px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>	 
				<?php		
						include("test_chart2.php");
					}	
				?>

			</div>
		<?php include_once("../includes/footer.php"); ?>
		</div>

	<!-- modern Select2 multi-select enhancer (presentation only) -->
	<script src="../dist/js/mpqms-select2multi.js?v=20261012d"></script>
	<script>
	 	$(document).ready(function(){
$("#type").change(function(){

    var type = $('#type').val();

    if(type == 2 || type == 3){
        $("#sitediv").show();
        $("#site").prop("required", true);
    }
    else{
        $("#sitediv").hide();
        $("#site").prop("required", false);
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
		mpqmsSelect2Multi('#site', 'Sites / Collection Centers');

		// ---- Client-side validation (same professional UI as Test Compliance: toastr at top,
		// same messages). Blocks Generate Chart on THIS page — no redirect, no reload, no empty
		// chart — highlights invalid fields and scrolls to the first. The server-side check in
		// this same file stays as a bypass-proof backstop. ----
		if(window.toastr){ toastr.options = { closeButton:true, progressBar:true, newestOnTop:true, positionClass:"toast-top-right", preventDuplicates:true, timeOut:5000, extendedTimeOut:1000 }; }
		var mpMsd = function(id){ return $('#'+id).nextAll('.mp-msd').first(); };
		var mpChartValidate = [
			{ key:'type',  msg:'Please select Report Type.',
			  valid:function(){ return $.trim($('#type').val()||'') !== ''; },
			  box:function(){ return $('#type'); }, after:function(){ return $('#type'); }, focus:function(){ return $('#type'); } },
			{ key:'year',  msg:'Please select at least one Year.',
			  valid:function(){ return $('input[name="year[]"]:checked').length > 0; },
			  box:function(){ return $('input[name="year[]"]').first().closest('.mp-filter-group'); }, after:function(){ return $('input[name="year[]"]').first().closest('.mp-filter-group'); }, focus:function(){ return $('input[name="year[]"]').first(); } },
			{ key:'month', msg:'Please select at least one Month.',
			  valid:function(){ return $('input[name="month[]"]:checked').length > 0; },
			  box:function(){ return $('input[name="month[]"]').first().closest('.mp-filter-group'); }, after:function(){ return $('input[name="month[]"]').first().closest('.mp-filter-group'); }, focus:function(){ return $('input[name="month[]"]').first(); } },
			{ key:'site',  msg:'Please select at least one Site/Collection Center.',
			  cond:function(){ var t=$('#type').val(); return t=='2'||t=='3'; },
			  valid:function(){ return $('#site option:selected').length > 0; },
			  box:function(){ return mpMsd('site').find('.mp-msd-toggle'); }, after:function(){ return mpMsd('site'); }, focus:function(){ return mpMsd('site').find('.mp-msd-toggle'); } }
		];
		function mpccClear(c){ c.box().removeClass('mp-invalid'); c.after().nextAll('.mp-invalid-msg').first().remove(); }
		function mpccFail(c){ c.box().addClass('mp-invalid'); if(!c.after().next('.mp-invalid-msg').length){ c.after().after('<span class="mp-invalid-msg">This field is required.</span>'); } }

		$('#form').on('submit', function(e){
			var first = null;
			mpChartValidate.forEach(function(c){
				if(c.cond && !c.cond()){ mpccClear(c); return; }   // not required for this Report Type
				if(c.valid()){ mpccClear(c); }
				else { mpccFail(c); if(!first){ first = c; } }
			});
			if(first){
				e.preventDefault();
				// Inline field validation is the single source of truth — no top-right toast here.
				var $f = first.focus();
				if($f.length && $f[0].scrollIntoView){ $f[0].scrollIntoView({ behavior:'smooth', block:'center' }); }
				setTimeout(function(){ try { $f.trigger('focus'); } catch(err){} }, 250);
				return false;
			}
			// all valid -> allow the normal submit (chart generates) unchanged
		});
		// Clear errors the moment the user provides input.
		$('#type').on('change', function(){ mpChartValidate.forEach(function(c){ if(c.key==='type' && c.valid()){ mpccClear(c); } if(c.key==='site' && c.cond && !c.cond()){ mpccClear(c); } }); });
		$('#site').on('change', function(){ mpChartValidate.forEach(function(c){ if(c.key==='site' && c.valid()){ mpccClear(c); } }); });
		$(document).on('change', 'input[name="year[]"], input[name="month[]"]', function(){ mpChartValidate.forEach(function(c){ if((c.key==='year'||c.key==='month') && c.valid()){ mpccClear(c); } }); });
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
	<script>
$(document).ready(function () {
    setTimeout(function () {
        $("#errorAlert").fadeOut("slow");
    }, 5000); // 5 seconds
});
</script>	
	</body>
	<?php ob_end_flush(); ?>
</html>
