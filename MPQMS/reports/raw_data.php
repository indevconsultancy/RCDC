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
						<h1 class="m-0">Raw Data</h1>
					  </div>
					  <div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
						  <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
						  <li class="breadcrumb-item active">Raw Data</li>
						</ol>
					  </div>
					</div>
				  </div>
				</div>

				<section class="content">
					<div class="container-fluid">
						<div class="card card-outline card-primary">
							<div class="card-header">
								<h3 class="card-title"><i class="fas fa-filter text-muted"></i> &nbsp;<b>Data Export Filter</b> — choose the scope of the raw-data export</h3>
								<div class="card-tools">
								  <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
									<i class="fas fa-minus"></i>
								  </button>
								</div>
							</div>
							<div class="card-body">
								<form id="form" class="mp-filter-form" autocomplete="off" method="Post" action="csv_raw.php">
									<!-- Row 1: scope selectors (2 per row — wide content) -->
									<div class="row">
										<div class="col-lg-6 col-md-6 col-12 form-group">
											<label for="dzo">Dzongkhag</label>
											<?php $qry=mysqli_query($commondb,"SELECT dzongkhag_code,dzongkhag_name FROM ms_dzongkhag_table");?>
											<select name="dzo" id="dzo" class="form-control select2bs4" style="width:100%;" required>
												<option value="All">All</option>
											<?php
												while(list($id,$val)=mysqli_fetch_row($qry)){
													echo("<option value='$id'>$val</option>");
												}
											?>
											</select>
										</div>
										<div id="sitediv" class="col-lg-6 col-md-6 col-12 form-group">
											<label>Sampling Sites</label>
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
									<!-- Row 2: test selectors (2 per row — wide content) -->
									<div class="row">
										<div class="col-lg-6 col-md-6 col-12 form-group">
											<label>Test Parameters</label>
											<select class="par" multiple="multiple" name="parid[]" id="parid" required>
										<?php
											$qry=mysqli_query($connection,"SELECT id,parameter FROM mstestparametertable WHERE status=1 ORDER BY ranking ASC");
											while(list($id,$val)=mysqli_fetch_row($qry)){
												echo("<option value='$id'>$val</option>");
											}
										?>
											</select>
										</div>
										<div class="col-lg-6 col-md-6 col-12 form-group">
											<label>Drug Categories</label>
											<select class="dcat" multiple="multiple" name="dcat[]" id="dcat" required>
										<?php
											$qry=mysqli_query($connection,"SELECT id,category FROM msdrugcategorytable WHERE status=1 ORDER BY id ASC");
											while(list($id,$val)=mysqli_fetch_row($qry)){
												echo("<option value='$id'>$val</option>");
											}
										?>
											</select>
										</div>
									</div>
									<!-- Row 3: compact option filter cards (3 per row — small content) -->
									<div class="row">
										<div class="col-lg-4 col-md-6 col-12 form-group">
											<div class="mp-filter-group h-100">
												<span class="mp-fg-label">Test Category</span>
												<div class="mp-chips">
													<div class="form-check-inline">
														<input class="form-check-input" type='checkbox' name='cat[]' value="Surveillance">
														<label class="form-check-label">Surveillance</label>
													</div>
													<div class="form-check-inline">
														<input class="form-check-input" type='checkbox' name='cat[]' value="Complain">
														<label class="form-check-label">Complain</label>
													</div>
													<div class="form-check-inline">
														<input class="form-check-input" type='checkbox' name='cat[]' value="Registration">
														<label class="form-check-label">Registration</label>
													</div>
												</div>
											</div>
										</div>
										<div class="col-lg-4 col-md-6 col-12 form-group">
											<div class="mp-filter-group h-100">
												<span class="mp-fg-label">Sample Acceptance</span>
												<div class="mp-chips">
													<div class="form-check-inline">
														<input class="form-check-input" type='radio' name='acp' id="acp" value="Accepted">
														<label class="form-check-label">Accepted</label>
													</div>
													<div class="form-check-inline">
														<input class="form-check-input" type='radio' name='acp' id="acp" value="Rejected">
														<label class="form-check-label">Rejected</label>
													</div>
												</div>
											</div>
										</div>
										<div class="col-lg-4 col-md-6 col-12 form-group">
											<div class="mp-filter-group h-100">
												<span class="mp-fg-label">Final Result</span>
												<div class="mp-chips">
													<div class="form-check-inline">
														<input class="form-check-input" type='checkbox' name='rslt[]' value="Complies">
														<label class="form-check-label">Complies</label>
													</div>
													<div class="form-check-inline">
														<input class="form-check-input" type='checkbox' name='rslt[]' value="Does not comply">
														<label class="form-check-label">Does not comply</label>
													</div>
													<div class="form-check-inline">
														<input class="form-check-input" type='checkbox' name='rslt[]' value="Inconclusive">
														<label class="form-check-label">Inconclusive</label>
													</div>
												</div>
											</div>
										</div>
									</div>
									<!-- Row 4: period filter cards (2 per row — many chips) -->
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
															$chk ="";
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
															$chk ="";
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
									<div class="mp-filter-footer d-flex justify-content-between align-items-center flex-wrap"><a href="../dashboard/dashboard.php" class="btn bg-gradient-secondary mp-back-btn"><i class="fas fa-arrow-left"></i>&nbsp;Back</a>
										<button type="Submit" class="btn bg-gradient-success mp-gen-btn"><i class="fas fa-file-csv"></i>&nbsp;Export as CSV</button>
									</div>
								</form>
							</div>
						</div>
					</div>
				</section>
			</div>
		<?php include_once("../includes/footer.php"); ?>
		</div>

	<!-- modern Select2 multi-select enhancer (presentation only) -->
	<script src="../dist/js/mpqms-select2multi.js?v=20261012d"></script>
	<script>
		$(document).ready(function(){
		   $("#dzo").change(function(){
				var did = $('#dzo').val();
				$("#sitediv").load("../load_site.php?par1="+did+"&par2=R");
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
		// Modern UI: Select2 on the dzongkhag dropdown + searchable Select2 multi-selects
		// (chips + count + Select All / Clear All), matching the Test Compliance page. The
		// original <select multiple> elements stay as the submit source, so posted data and
		// server-side validation are identical to the previous dual list boxes.
		$('#dzo').select2({ theme: 'bootstrap4', width: '100%' });
		mpqmsSelect2Multi('#site', 'Sampling Sites');
		mpqmsSelect2Multi('#parid', 'Test Parameters');
		mpqmsSelect2Multi('#dcat', 'Drug Categories');
		// The dzongkhag change reloads #sitediv via load_site.php (which re-applies a
		// dual list box); re-skin that reloaded select as a Select2 multi-select too.
		if (window.MutationObserver) {
			var mpSiteDiv = document.getElementById('sitediv');
			if (mpSiteDiv) {
				new MutationObserver(function () {
					var s = mpSiteDiv.querySelector('select#site, select.site');
					if (!s) { return; }
						// load_site.php re-injects an inline script that rebuilds the OLD dual list
						// box AFTER this select was skinned; strip that container on EVERY mutation
						// (not only the first) so the modern .mp-msd dropdown stays the sole control.
						$(mpSiteDiv).find('.bootstrap-duallistbox-container').remove();
						if (!$(s).data('mpqmsS2')) {
							mpqmsSelect2Multi('#site', 'Sampling Sites');
						}
				}).observe(mpSiteDiv, { childList: true, subtree: true });
			}
		}
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

		// ---- Client-side export validation (mirrors the mandatory filters in csv_raw.php).
		// Blocks the export on THIS page (no redirect); highlights fields + inline messages,
		// scrolls to the first invalid one, and shows a single toast. Server guard stays as a
		// bypass-proof backstop for direct POSTs. ----
		var mpMsd = function(id){ return $('#'+id).nextAll('.mp-msd').first(); };
		var mpReq = [
			{ key:'site',  valid:function(){ return $('#site option:selected').length > 0; },
			  box:function(){ return mpMsd('site').find('.mp-msd-toggle'); }, after:function(){ return mpMsd('site'); }, focus:function(){ return mpMsd('site').find('.mp-msd-toggle'); } },
			{ key:'dcat',  valid:function(){ return $('#dcat option:selected').length > 0; },
			  box:function(){ return mpMsd('dcat').find('.mp-msd-toggle'); }, after:function(){ return mpMsd('dcat'); }, focus:function(){ return mpMsd('dcat').find('.mp-msd-toggle'); } },
			{ key:'acp',   valid:function(){ return $('input[name=acp]:checked').length > 0; },
			  box:function(){ return $('input[name=acp]').first().closest('.mp-filter-group'); }, after:function(){ return $('input[name=acp]').first().closest('.mp-filter-group'); }, focus:function(){ return $('input[name=acp]').first(); } },
			{ key:'year',  valid:function(){ return $('input[name="year[]"]:checked').length > 0; },
			  box:function(){ return $('input[name="year[]"]').first().closest('.mp-filter-group'); }, after:function(){ return $('input[name="year[]"]').first().closest('.mp-filter-group'); }, focus:function(){ return $('input[name="year[]"]').first(); } },
			{ key:'month', valid:function(){ return $('input[name="month[]"]:checked').length > 0; },
			  box:function(){ return $('input[name="month[]"]').first().closest('.mp-filter-group'); }, after:function(){ return $('input[name="month[]"]').first().closest('.mp-filter-group'); }, focus:function(){ return $('input[name="month[]"]').first(); } },
			// Mandatory export filters (frontend-only): Test Parameters, Test Category, Final Result.
			{ key:'parid', msg:'Test Parameters is required.',
			  valid:function(){ return $('#parid option:selected').length > 0; },
			  box:function(){ return mpMsd('parid').find('.mp-msd-toggle'); }, after:function(){ return mpMsd('parid'); }, focus:function(){ return mpMsd('parid').find('.mp-msd-toggle'); } },
			{ key:'cat',  msg:'Please select at least one Test Category.',
			  valid:function(){ return $('input[name="cat[]"]:checked').length > 0; },
			  box:function(){ return $('input[name="cat[]"]').first().closest('.mp-filter-group'); }, after:function(){ return $('input[name="cat[]"]').first().closest('.mp-filter-group'); }, focus:function(){ return $('input[name="cat[]"]').first(); } },
			{ key:'rslt', msg:'Please select at least one Final Result.',
			  valid:function(){ return $('input[name="rslt[]"]:checked').length > 0; },
			  box:function(){ return $('input[name="rslt[]"]').first().closest('.mp-filter-group'); }, after:function(){ return $('input[name="rslt[]"]').first().closest('.mp-filter-group'); }, focus:function(){ return $('input[name="rslt[]"]').first(); } }
		];
		function mpClear(c){ c.box().removeClass('mp-invalid'); c.after().nextAll('.mp-invalid-msg').first().remove(); }
		function mpFail(c){ c.box().addClass('mp-invalid'); if(!c.after().next('.mp-invalid-msg').length){ c.after().after('<span class="mp-invalid-msg">'+((c && c.msg) ? c.msg : 'This field is required.')+'</span>'); } }

		$('#form').on('submit', function(e){
			var first = null;
			mpReq.forEach(function(c){ if(c.valid()){ mpClear(c); } else { mpFail(c); if(!first){ first = c; } } });
			if(first){
				e.preventDefault();
				// Inline field validation is the single source of truth — no top-right toast here.
				var $f = first.focus();
				if($f.length && $f[0].scrollIntoView){ $f[0].scrollIntoView({ behavior:'smooth', block:'center' }); }
				setTimeout(function(){ try { $f.trigger('focus'); } catch(err){} }, 250);
				return false;
			}
			// all valid -> allow the native submit (CSV download) to proceed unchanged
		});
		// Clear a field's error state the moment the user provides a value.
		$('#site,#dcat,#parid').on('change', function(){ mpReq.forEach(function(c){ if((c.key==='site'||c.key==='dcat'||c.key==='parid') && c.valid()){ mpClear(c); } }); });
		$(document).on('change', 'input[name=acp], input[name="year[]"], input[name="month[]"], input[name="cat[]"], input[name="rslt[]"]', function(){ mpReq.forEach(function(c){ if(c.valid()){ mpClear(c); } }); });
	  })
	</script>
	</body>
</html>
