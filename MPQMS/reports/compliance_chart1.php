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
               <?php
// Client-side inline validation is now the single source of truth for filter errors; the
// server-side check (below) stays as a bypass backstop but no longer raises a top-right toast.
if(isset($_SESSION['chart_errors'])){ unset($_SESSION['chart_errors']); }
?>



				<div class="content-header">
				  <div class="container-fluid">
					<div class="row mb-2">
					  <div class="col-sm-6">
						<h1 class="m-0">Test Compliance</h1>
					  </div>
					  <div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
						  <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
						  <li class="breadcrumb-item active">Compliance</li>
						</ol>
					  </div>
					</div>
				  </div>
				</div>
				
					<section class="content">
					<div class="container-fluid">
						<div class="card card-outline card-primary">
							<div class="card-header">
								<h3 class="card-title"><i class="fas fa-filter text-muted"></i> &nbsp;<b>Compliance Chart Filter</b> — select report type and period</h3>
								<div class="card-tools">
								  <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
									<i class="fas fa-minus"></i>
								  </button>
								</div>
							</div>
							<div class="card-body">
								<form id="form" class="mp-filter-form" autocomplete="off" method="Post" action="compliance_chart1.php">
									<?php
	// The filter redraws with the selection that produced the chart below it. Before, it always
	// reset to the current year and month, so the page showed one selection above a chart built
	// from another. With nothing submitted yet, the defaults are unchanged.
	$mpSelType   = (string)($_POST['type'] ?? '');
	$mpSelYears  = array_map('strval', (array)($_POST['year']  ?? array()));
	$mpSelMonths = array_map('strval', (array)($_POST['month'] ?? array()));
	$mpSubmitted = !empty($mpSelYears) || !empty($mpSelMonths);
	$mpSelFormu  = array_map('strval', (array)($_POST['formu'] ?? array()));
	$mpSelDcat   = array_map('strval', (array)($_POST['dcat']  ?? array()));
?>
<!-- Row 1: report type + conditional multi-select (2 large fields) -->
									<div class="row">
										<div class="col-lg-6 col-md-6 col-12 form-group">
											<label for="type">Report Type</label>
											<select name="type" id="type" class="form-control mp-ctl select2bs4" required>
												<option value="">Select Report Type</option>
												<option value="1"<?=($mpSelType==='1')?' selected':'';?>>Month-wise</option>
												<option value="2"<?=($mpSelType==='2')?' selected':'';?>>Formulation-wise</option>
												<option value="3"<?=($mpSelType==='3')?' selected':'';?>>Drug Category-wise</option>
											</select>
										</div>
										<div id="formudiv" class="col-12 col-md-6 form-group" style="<?=($mpSelType === '2') ? '' : 'display: none;';?>">
											<label>Formulations</label>
											<select class="formu" multiple="multiple" name="formu[]" id="formu" required>
										<?php
											$qry=mysqli_query($connection,"SELECT id,formulation FROM msformulationtable WHERE status=1 ORDER BY id ASC");
											while(list($id,$val)=mysqli_fetch_row($qry)){
												echo("<option value='$id'".(in_array((string)$id, $mpSelFormu, true) ? " selected" : "").">$val</option>");
											}
										?>
											</select>
										</div>
										<div id="dcatdiv" class="col-12 col-md-6 form-group" style="<?=($mpSelType === '3') ? '' : 'display: none;';?>">
											<label>Drug Categories</label>
											<select class="dcat" multiple="multiple" name="dcat[]" id="dcat" required>
										<?php
											$qry=mysqli_query($connection,"SELECT id,category FROM msdrugcategorytable WHERE status=1 ORDER BY id ASC");
											while(list($id,$val)=mysqli_fetch_row($qry)){
												echo("<option value='$id'".(in_array((string)$id, $mpSelDcat, true) ? " selected" : "").">$val</option>");
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
										<button type="Submit" name="submit_filter" value="Generate Chart" class="btn bg-gradient-success mp-gen-btn"><i class="fas fa-chart-pie"></i>&nbsp;Generate Chart</button>
									</div>
								</form>
							</div>
						</div>
					</div>
				</section>
			
			<?php 
				if($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['submit_filter'])){

				    $errors = [];

    $type  = $_POST['type'] ?? '';
    $year  = $_POST['year'] ?? [];
    $month = $_POST['month'] ?? [];
    $formu = $_POST['formu'] ?? [];
    $dcat  = $_POST['dcat'] ?? [];

    if(empty($type)){
        $errors[] = "Please select Report Type.";
    }

    if(empty($year)){
        $errors[] = "Please select at least one Year.";
    }

    if(empty($month)){
        $errors[] = "Please select at least one Month.";
    }

    if($type == 2 && empty($formu)){
        $errors[] = "Please select at least one Formulation.";
    }

    if($type == 3 && empty($dcat)){
        $errors[] = "Please select at least one Drug Category.";
    }

    if(!empty($errors)){
        $_SESSION['chart_errors'] = $errors;
        header("Location: compliance_chart1.php");
        exit;
    }

					if(isset($_POST["year"])) { 
						$year = $_POST["year"]; 
						$str1 = "";
						for($i=0;$i<count($year);$i++){ $str1 .= $year[$i].","; }
						$str1 = substr($str1,0,-1);
					}

					$monthName = array();
					if(isset($_POST["month"])) { 
						$month = $_POST["month"]; 
						$str2 = "";
						for($i=0;$i<count($month);$i++){ 
							$str2 .= $month[$i].",";
							$monthName[] = date("F", mktime(0, 0, 0, (int)$month[$i], 1));
						}
						$str2 = substr($str2,0,-1);
					}

					if(isset($_POST["type"])) { 
						$type = $_POST["type"]; 
						if($type == 1){
							for($j=0;$j<count($month);$j++){ 
								$comp[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND MONTH(rt.registrationDate)='$month[$j]' AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Complies'")); 
								$dcomp[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND MONTH(rt.registrationDate)='$month[$j]' AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Does not comply'")); 
								$incon[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND MONTH(rt.registrationDate)='$month[$j]' AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Inconclusive'")); 
							}
							$datacat = json_encode($monthName,JSON_NUMERIC_CHECK);
							$header = "Monthly Compliance Report";
						}
						else if($type == 2){
							if(isset($_POST["formu"])) {  
								$formu = $_POST["formu"]; 
								for($j=0;$j<count($formu);$j++){ 
									$formuName[] = mysqli_fetch_row(mysqli_query($connection,"SELECT formulation FROM msformulationtable WHERE id='$formu[$j]'"));
									$comp[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND MONTH(rt.registrationDate) IN($str2) AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Complies' AND rt.formulationID='$formu[$j]'")); 
									$dcomp[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND MONTH(rt.registrationDate) IN($str2) AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Does not comply' AND rt.formulationID='$formu[$j]'")); 
									$incon[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft WHERE rt.registrationID=ft.registrationID AND MONTH(rt.registrationDate) IN($str2) AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Inconclusive' AND rt.formulationID='$formu[$j]'"));  
								}
							}
							$datacat = json_encode($formuName,JSON_NUMERIC_CHECK);
							$header = "Formulation Compliance Report";
						}
						else{
							if(isset($_POST["dcat"])) {  
								$dcat = $_POST["dcat"]; 
								for($j=0;$j<count($dcat);$j++){ 
									$catName[] = mysqli_fetch_row(mysqli_query($connection,"SELECT category FROM msdrugcategorytable WHERE id='$dcat[$j]'"));
									$comp[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft,msedlcategorymaptable ect WHERE rt.registrationID=ft.registrationID AND rt.edlID=ect.edlID AND MONTH(rt.registrationDate) IN($str2) AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Complies' AND ect.categoryID='$dcat[$j]'")); 
									$dcomp[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft,msedlcategorymaptable ect WHERE rt.registrationID=ft.registrationID AND rt.edlID=ect.edlID AND MONTH(rt.registrationDate) IN($str2) AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Does not comply' AND ect.categoryID='$dcat[$j]'")); 
									$incon[] = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM registrationtable rt,finalresulttable ft,msedlcategorymaptable ect WHERE rt.registrationID=ft.registrationID AND rt.edlID=ect.edlID AND MONTH(rt.registrationDate) IN($str2) AND YEAR(rt.registrationDate) IN($str1) AND ft.result='Inconclusive' AND ect.categoryID='$dcat[$j]'")); 
								}
							}
							$datacat = json_encode($catName,JSON_NUMERIC_CHECK);
							$header = "Drug Category Compliance Report";
						}
					}

					// Flatten the per-bucket COUNT(*) rows (nested arrays like [[12],[15]]) into
					// simple [12,15,…] arrays so the Highcharts series + the "Total: N" per-
					// category label consume the same shape. Nothing beyond this computation is
					// needed — the view is a single grouped bar chart (no KPI cards, no toggle).
					$mp_flatten = function($rows){
						$out = array();
						foreach((array)$rows as $r){ $out[] = (int)(is_array($r) ? ($r[0] ?? 0) : $r); }
						return $out;
					};
					$mpComp  = $mp_flatten($comp);
					$mpDcomp = $mp_flatten($dcomp);
					$mpIncon = $mp_flatten($incon);
					$mpCat   = array();
					foreach((array)(isset($catName) ? $catName : (isset($formuName) ? $formuName : $monthName)) as $r){
						$mpCat[] = (string)(is_array($r) ? ($r[0] ?? '') : $r);
					}
					$mpTotal = array();
					$n = max(count($mpComp), count($mpDcomp), count($mpIncon), count($mpCat));
					for($i=0; $i<$n; $i++){
						$mpTotal[] = (int)($mpComp[$i] ?? 0) + (int)($mpDcomp[$i] ?? 0) + (int)($mpIncon[$i] ?? 0);
					}

					$datacat   = json_encode($mpCat,   JSON_UNESCAPED_UNICODE);
					$datacomp  = json_encode($mpComp);
					$datadcomp = json_encode($mpDcomp);
					$dataincon = json_encode($mpIncon);
					$datatotal = json_encode($mpTotal);
				?>
						<section class="content">
							<div class="container-fluid">
						        <div class="row">
						          <div class="col-12">
						            <div class="card card-outline card-info mp-result-card">
							            <div class="card-header">
							            	<h3 class="card-title"><i class="fas fa-chart-bar text-info"></i> &nbsp;<b><?=$header;?></b> — Year: <?=$str1;?> &middot; Month: <?=$str2;?></h3>
							            </div>
							            <div class="card-body"><div id="container" style="height:420px;"></div></div>
						            </div>
						          </div>
						        </div>
							</div>
						</section>
				<?php
						include("compliance_chart2.php");
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
				if(type==2){
					$("#formudiv").show();
					$("#dcatdiv").hide();
				}
				else if(type==3){
					$("#formudiv").hide();
					$("#dcatdiv").show();
				}
				else{
					$("#formudiv").hide();
					$("#dcatdiv").hide();
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
		// Modern UI: skin the Formulations / Drug Categories multi-selects as collapsed,
		// searchable Select2 dropdowns (chips + count + Select All/Clear All). The original
		// <select multiple> stays as the submit source, so posted data + validation are identical.
		mpqmsSelect2Multi('#formu', 'Formulations');
		mpqmsSelect2Multi('#dcat', 'Drug Categories');

		// Inline validation for the Year / Month checkbox groups (jQuery Validate below covers the
		// selects but not these groups). Bound BEFORE .validate() so it runs first; on failure it
		// shows a red border + helper text under the group and stops the submit — no top-right toast.
		function ccFail($g, msg){ $g.addClass('mp-invalid'); if(!$g.next('.mp-invalid-msg').length){ $g.after('<span class="mp-invalid-msg">'+msg+'</span>'); } }
		function ccClear($g){ $g.removeClass('mp-invalid'); $g.next('.mp-invalid-msg').remove(); }
		function ccGrp(name){ return $('input[name="'+name+'"]').first().closest('.mp-filter-group'); }
		function ccCheckGroups(){
			var bad = false;
			if($('input[name="year[]"]:checked').length === 0){ ccFail(ccGrp('year[]'), 'Please select at least one Year.'); bad = true; } else { ccClear(ccGrp('year[]')); }
			if($('input[name="month[]"]:checked').length === 0){ ccFail(ccGrp('month[]'), 'Please select at least one Month.'); bad = true; } else { ccClear(ccGrp('month[]')); }
			return !bad;
		}
		$(document).on('change', 'input[name="year[]"]', function(){ if($('input[name="year[]"]:checked').length){ ccClear(ccGrp('year[]')); } });
		$(document).on('change', 'input[name="month[]"]', function(){ if($('input[name="month[]"]:checked').length){ ccClear(ccGrp('month[]')); } });

		$('#form').validate({
			// jQuery Validate handles the selects; the Year/Month checkbox groups are validated
			// here in submitHandler (called ONLY once its own rules pass) so the form submits only
			// when everything is valid — inline errors, no top-right toast.
			submitHandler: function (form) {
				if(!ccCheckGroups()){ return false; }   // Year/Month missing -> show inline, do not submit
				form.submit();
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
	  })
	</script>
	<script>
toastr.options = {
    closeButton: true,
    progressBar: true,
    newestOnTop: true,
    positionClass: "toast-top-right",
    preventDuplicates: true,
    timeOut: 5000,
    extendedTimeOut: 1000
};
</script>	
	</body>
	<?php ob_end_flush(); ?>
</html>
