<!-- Auth
     Oct 2026 — db_config MUST be included BEFORE mpq_validate's require_page call so
     $commondb / $connection reach global scope. mpq_require_page internally calls
     mpq_can_do(), which lazy-includes db_config inside a function if it is missing;
     a subsequent include_once("../config/db_config.php") then becomes a no-op and
     $commondb stays undefined in this page's global scope. Explicit include order
     prevents that. The matrix call itself can also tolerate a missing connection —
     it falls back to the hard-coded defaults so an admin never gets locked out. -->
<?php include_once("../config/auth_ndtlis.php"); ?>
<?php include_once("../config/db_config.php"); ?>
<?php include_once("../config/mpq_validate.php"); mpq_require_page('manager', 'view'); /* effective-role RBAC: who may open this page */ ?>
<?php include_once("../includes/header.php"); ?>
	<!-- DB -->
	<?php
		// db_config is already included above; this stays as a safety belt (include_once no-ops).
		include_once("../config/db_config.php");
		$dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code != '99' ORDER BY dzongkhag_name ASC";
		$cur_year = date("Y");
		// Null-check for the shared commondb handle; render an empty Dzongkhag option list
		// gracefully if the connection is unavailable so the whole page doesn't fatal.
		$mpgDzongkhags = array();
		if(isset($commondb) && ($commondb instanceof mysqli)){
			$mpgRes = @$commondb->query($dzsql);
			if($mpgRes){
				while($r = $mpgRes->fetch_row()){ $mpgDzongkhags[] = $r; }
			}
		}
	?>
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
						<h1 class="m-0">Registration & Result Manager</h1>
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
				
				<!-- Search -->
				<section class="content">
					<div class="container-fluid">
						<div class="row">
						<div class="col-12">
							<div class="card">
								<div class="card-header">
									<h3 class="card-title">&nbsp;Search &amp; Filter Registrations</h3>
								</div>
							<div class="card-body">
								<form class="form-horizontal" id="form" autocomplete="off" method="Post" action="manager.php">
									<div class="row mp-filter-panel align-items-end">
										<div class="col-lg-2 col-md-4 col-12 form-group mb-2">
											<select class="form-control" name="year" id="year">
												<option value="">Select year</option>
												<option value="<?=$cur_year;?>"><?=$cur_year;?></option>
												<?php for($i=($cur_year-1);$i >= 2020; $i--) { echo("<option value='$i'>$i</option>"); } ?>
											</select>
										</div>
										<div class="col-lg-2 col-md-4 col-12 form-group mb-2">
											<select class="form-control" name="month" id="month">
												<option value="">Select month</option>
												<?php for($i=1;$i <=12; $i++) { echo("<option value='$i'>$i</option>"); } ?>
											</select>
										</div>
										<div class="col-lg-2 col-md-4 col-12 form-group mb-2">
											<select class="form-control" name="dzongkhag" id="dzongkhag">
												<option value="">Select dzongkhag</option>
												<?php foreach($mpgDzongkhags as $row){ echo("<option value='".htmlspecialchars($row[0], ENT_QUOTES)."'>".htmlspecialchars($row[1])."</option>"); } ?>
											</select>
										</div>
										<div id="facilityDiv" class="col-lg-2 col-md-4 col-12 form-group mb-2">
											<select class="form-control" name="facility" id="facility">
												<option value="">Select facility</option>
											</select>
										</div>
										<div class="col-lg-2 col-md-4 col-12 form-group mb-2">
											<button type="submit" name="submit" class="btn bg-gradient-success mp-filter-btn btn-block"><i class="fa fa-search"></i>&nbsp;Search</button>
										</div>
										<div class="col-lg-2 col-md-4 col-12 form-group mb-2">
											<a href="#" id="resetFilter" class="btn bg-gradient-secondary mp-filter-btn mp-reset-btn btn-block"><i class="fa fa-sync"></i>&nbsp;Reset</a>
										</div>
									</div>
								</form> 
							</div>
							</div>
						</div>
						</div>
					</div>
				</section>
				<!-- /.search -->
				
				<section class="content">
					<div class="container-fluid">
						<div class="row">
							<div class="col-12">
								<div class="card">
									<div class="card-header">
										<h3 class="card-title"> &nbsp;<b>Registrations</b> — samples &amp; test results</h3>
										<div class="card-tools">
											<?php if(mpq_can_do('registration','add')){ /* effective-role RBAC: who may create a registration */ ?>
											<a href="registration.php?par=1" class="btn bg-gradient-primary"><i class="fas fa-plus-circle"></i>&nbsp; New Registration</a>
											<?php } ?>
										</div>
									</div>
									<div class="card-body">
										<table id="regtest" class="table table-bordered table-striped table-hover table-sm" style="visibility:hidden;">
										  <thead>
											  <tr class="text-center">
												<th>Pharma Claim</th>
												<th>DoR</th>
												<th>SampleID</th>
												<th>Cat</th>
												<th>Site</th>
												<th>CollectedBy</th>
												<th>Sample</th>
												<th>Batch</th>
												<th>Acp</th>
												<th>Sts</th>
												<th>Apv</th>
												<th>Result</th>
												<th>Action</th>
											  </tr>
										  </thead>
										  <tbody></tbody>
										</table>
									</div>	
								</div>
							</div>	
						</div>	
					</div>
				</section>
			</div>
		<?php include_once("../includes/footer.php"); ?>
		</div>

	  <div class="modal fade" id="modal-lg">
			<div class="modal-dialog modal-lg">
			  <div class="modal-content">
				<div class="modal-header">
				  <h4 class="modal-title">Test Status</h4>
				  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				  </button>
				</div>
				<div class="modal-body">
					
				</div>
				<div class="modal-footer justify-content-between">
				  <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
				</div>
			  </div>
			</div>
	  </div>

		<script>
		$(document).ready(function(){
			$("#dzongkhag").change(function(){
				$("#facilityDiv").load("load_facility.php?dzongkhag=" + $('#dzongkhag').val());
			});

			// TRUE server-side pagination — only the current page is fetched from the DB.
			var regTable = $("#regtest").DataTable({
				"processing": true,
				"serverSide": true,
				"ajax": {
					"url": "manager_data.php",
					"type": "POST",
					"data": function(d){
						d.year      = $('#year').val();
						d.month     = $('#month').val();
						d.dzongkhag = $('#dzongkhag').val();
						d.facility  = $('#facility').val();
					}
				},
				// UI-only: show every column (incl. Acp, Sts, Apv, Result, Action) in a single row for
				// ALL roles, exactly like the Administrator's wide-screen view. Responsive column
				// collapsing (the expandable "+" child row on the Pharma Claim cell) is turned off and
				// replaced with horizontal scrolling, so narrow screens scroll instead of hiding columns.
				// No data, logic, or permissions change — purely how the table lays out.
				"responsive": false,
				"scrollX": true,
				"lengthChange": true,
				"lengthMenu": [[10,25,50,100],[10,25,50,100]],
				"pageLength": 10,
				"autoWidth": false,
				"order": [[ 1, "desc" ]],
				"columnDefs": [
					{ "orderable": false, "targets": [8,9,10,11,12] }
				],
				"dom": "<'row'<'col-sm-4'l><'col-sm-4 text-center'B><'col-sm-4'f>>rtip",
				"buttons": [
					{ text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-sm bg-gradient-secondary',
					  action: function(){ window.location = 'manager_export.php?type=excel&' + mpExportParams(); } },
					{ text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-sm bg-gradient-secondary',
					  action: function(){ window.location = 'manager_export.php?type=pdf&' + mpExportParams(); } }
				],
				// Reveal the table after DataTables has styled it (anti-FOUC). Under scrollX the
				// header is a separate cloned table inside .dataTables_scrollHead that inherits the
				// initial visibility:hidden and has no #regtest id, so un-hiding only #regtest would
				// leave the header invisible. Un-hide every table in the wrapper (real body + header
				// clone) so the header shows and stays aligned. UI-only; no logic/data change.
				"initComplete": function(){ $('#regtest').closest('.dataTables_wrapper').find('table').css('visibility','visible'); }
			});

			function mpExportParams(){
				return $.param({
					year: $('#year').val(), month: $('#month').val(),
					dzongkhag: $('#dzongkhag').val(), facility: $('#facility').val(),
					search: regTable.search()
				});
			}

			// Filter form drives the server-side query — no page reload.
			$('#form').on('submit', function(e){ e.preventDefault(); regTable.ajax.reload(); });
			$('#resetFilter').on('click', function(e){
				e.preventDefault();
				$('#year,#month,#dzongkhag,#facility').val('');
				$('#facilityDiv').html('<select class="form-control" name="facility" id="facility"><option value="">Select facility</option></select>');
				regTable.ajax.reload();
			});

			// Row actions (delegated — survive redraws).
			$(document).on('click', '#delete-report', function(e){ e.preventDefault(); deleteReport($(this).data('id')); });
			$(document).on('click', '#view-report', function(){
				var vid = $(this).data('id');
				$('.modal-body').load('load_status.php?vid=' + vid, function(){ $('#modal-lg').modal({show:true}); });
			});

			window.deleteReport = function(rid){
				Swal.fire({ title:'Do you really want to delete this report?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', cancelButtonColor:'#3085d6', confirmButtonText:'Yes, delete it!', allowOutsideClick:false })
				.then(function(result){
					if(result.isConfirmed){
						$.ajax({ url:'delete.php', type:'POST', data:{ rid:rid, rtyp:'R' }, dataType:'json' })
						.done(function(response){
							regTable.ajax.reload(null, false);   // refetch current page; server counts stay correct
							Swal.fire({ icon:(response&&response.status)||'success', text:(response&&response.message)||'Deleted.', timer:1500, showConfirmButton:false });
						})
						.fail(function(){ Swal.fire({ icon:'error', text:'Unable to delete. Please try again.' }); });
					}
				});
			};
		});
	</script>	
	</body>
</html>
