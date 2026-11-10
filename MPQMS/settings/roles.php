<!-- MPQMS — Manage Roles & Permissions (Admin only)
     Oct 2026. Companion to mpqms_roles + mpqms_role_permissions. Each role tab
     renders 6 named sections (per the client spec); each section lists the
     fine-grained code modules it owns, and the 6 permission checkboxes
     (view / enter / edit / review / approve / manage) toggle the matching
     mpqms_role_permissions columns. Save POSTs the whole grid to save_roles.php
     which upserts one row per (role, module). Rows missing from the DB fall
     back to the hard-coded defaults in config/mpq_validate.php. -->
<?php
	// db_config MUST be included BEFORE mpq_validate's require_page / can_do call, otherwise
	// the first mpq_db_permissions() lazy-include happens inside a function and the follow-up
	// `include_once("../config/db_config.php")` is a no-op — leaving $connection undefined in
	// this file (bug observed 2026-10-07 on this page). Explicit order prevents the problem;
	// mpq_db_permissions() also carries a `global` fix as a second line of defence.
	include_once("../config/auth_ndtlis.php");
	include_once("../config/db_config.php");
	include_once("../config/mpq_validate.php");
	mpq_require_page('users', 'view');   // same gate as the Users page — Admin only
	include_once("../includes/header.php");
	include_once("../includes/css-jquery.php");

	// Load the current permission matrix from the DB. Defensive — if the connection
	// couldn't be opened for any reason (shared commondb outage, migration not run yet),
	// fall through to an empty $roles / $perms set; the editor renders a friendly
	// "no roles configured yet" state instead of fatalling.
	$roles = array();
	$perms = array();   // $perms[role_name][module_name] = array of 6 flags
	// Oct 2026 directive — the MPD role is retired from the admin UI. The DB
	// row and its mpqms_role_permissions entries are LEFT UNTOUCHED (so any
	// user still carrying usercat=20 keeps whatever grants they have), but the
	// Roles & Permissions editor hides it from the tab list and from the
	// tab-content loop so no new edits can target it. save_roles.php only
	// writes rows whose role is listed in `roles_posted[]`, so skipping MPD
	// here also means save_roles.php can never zero its DB rows.
	$MPR_HIDDEN_ROLES = array('MPD');
	$mprDbReady = (isset($connection) && ($connection instanceof mysqli));
	if($mprDbReady){
		$resR = @mysqli_query($connection, "SELECT id, role_name, description FROM mpqms_roles ORDER BY id");
		if($resR){
			while($r = mysqli_fetch_assoc($resR)){
				if(in_array(strtoupper((string)$r['role_name']), $MPR_HIDDEN_ROLES, true)){ continue; }
				$roles[] = $r;
			}
		}

		$resP = @mysqli_query($connection,
			"SELECT r.role_name, p.module_name, p.can_view, p.can_enter, p.can_edit, p.can_review, p.can_approve, p.can_manage
			 FROM mpqms_role_permissions p JOIN mpqms_roles r ON r.id=p.role_id");
		if($resP){
			while($row = mysqli_fetch_assoc($resP)){
				$perms[(string)$row['role_name']][(string)$row['module_name']] = $row;
			}
		}
	}
?>
<?php
	// Signalled to the body — rendered as an inline notice above the matrix if we failed
	// to reach the DB or the migration hasn't been applied yet.
	$mprHealthMsg = '';
	if(!$mprDbReady){
		$mprHealthMsg = 'Could not open the MPQMS database connection — the role editor is read-only. Please contact the system administrator.';
	} elseif(empty($roles)){
		$mprHealthMsg = 'The role tables are empty (or every role is hidden by the editor). Run docs/sql/rbac_roles_permissions.sql against the MPQMS database to seed the default roles, then reload this page.';
	}

	// 6 UI section definitions. Each section groups one or more code modules,
	// and declares which code modules it owns + what to call them. The checkbox
	// grid is driven entirely by this structure — add a new section or module
	// here and the UI picks it up automatically.
	$sections = array(
		array('title'=>'Sample Registration',   'icon'=>'fa-file-medical',
		      'modules'=>array(
		          'registration' => 'Sample Registration',
		          'manager'      => 'Registration & Result List',
		      )),
		array('title'=>'Test Assignment',       'icon'=>'fa-user-plus',
		      'modules'=>array(
		          'parameters'   => 'Test Parameters (attach / remove)',
		          'acceptance'   => 'Sample Acceptance',
		      )),
		array('title'=>'Test Results Entry',    'icon'=>'fa-vials',
		      'modules'=>array(
		          'results'      => 'Lab Result rows',
		      )),
		array('title'=>'Review & Approvals',    'icon'=>'fa-user-shield',
		      'modules'=>array(
		          'final_result' => 'Final Result',
		      )),
		array('title'=>'Reports & Analytics',   'icon'=>'fa-chart-bar',
		      'modules'=>array(
		          'reports'         => 'Reports (charts + CSV)',
		          'dashboard'       => 'Dashboard',
		          'pdf'             => 'Analytical Report PDF',
		          'official_report' => 'Official Archive PDF',
		      )),
		array('title'=>'User & System Settings','icon'=>'fa-cog',
		      'modules'=>array(
		          'settings_core'   => 'Formulation / Parameter / Category',
		          'edl'             => 'EDL / NEML',
		          'manufacturer'    => 'Manufacturer master',
		          'mah'             => 'MAH master',
		          'analyst_master'  => 'Assigned Analyst Master',
		          'approver_master' => 'Approved By Master',
		          'users'           => 'Users & role grants',
		      )),
	);

	// 6 permission columns, same order as the DB schema and the spec.
	$cols = array(
		'can_view'    => 'View',
		'can_enter'   => 'Enter / Create',
		'can_edit'    => 'Edit',
		'can_review'  => 'Review',
		'can_approve' => 'Approve',
		'can_manage'  => 'Delete / Manage',
	);
?>
	<body class="hold-transition sidebar-mini layout-fixed">
		<div class="wrapper">
			<?php include_once("../includes/top_navigation.php"); ?>
			<?php include_once("../includes/main_navigation.php"); ?>
			<div class="content-wrapper">
				<div class="content-header">
					<div class="container-fluid">
						<div class="row mb-2">
							<div class="col-sm-6"><h1 class="m-0">Roles &amp; Permissions</h1></div>
							<div class="col-sm-6">
								<ol class="breadcrumb float-sm-right">
									<li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
									<li class="breadcrumb-item active">Roles</li>
								</ol>
							</div>
						</div>
					</div>
				</div>
				<section class="content">
					<div class="container-fluid">
						<div class="card card-outline card-primary">
							<div class="card-header">
								<h3 class="card-title"><i class="fas fa-user-shield text-primary"></i>&nbsp;<b>Manage Roles &amp; Permissions</b> — tick to grant, untick to revoke</h3>
							</div>
							<div class="card-body">
								<?php if($mprHealthMsg !== ''){ ?>
								<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i>&nbsp;<?=htmlspecialchars($mprHealthMsg);?></div>
								<?php } ?>
								<form id="mpr-form" method="post" action="save_roles.php" autocomplete="off">
									<input type="hidden" name="csrf" value="<?=htmlspecialchars((string)($_SESSION['csrf'] ?? ''), ENT_QUOTES);?>">

									<!-- Role tabs -->
									<ul class="nav nav-tabs" role="tablist">
									<?php $firstRole = true; foreach($roles as $r){
										$rn = htmlspecialchars($r['role_name']);
										$active = $firstRole ? ' active' : '';
										echo '<li class="nav-item"><a class="nav-link'.$active.'" data-toggle="tab" href="#mpr-tab-'.$rn.'" role="tab"><b>'.$rn.'</b></a></li>';
										$firstRole = false;
									} ?>
									</ul>

									<div class="tab-content pt-3">
									<?php
									$firstRole = true;
									foreach($roles as $r){
										$rn = (string)$r['role_name'];
										$rid = (int)$r['id'];
										$active = $firstRole ? ' show active' : '';
										echo '<div class="tab-pane fade'.$active.'" id="mpr-tab-'.htmlspecialchars($rn).'" role="tabpanel">';
										echo '<p class="text-muted mb-3"><i class="fas fa-info-circle"></i>&nbsp;<i>'.htmlspecialchars((string)$r['description']).'</i></p>';

										foreach($sections as $sec){
											echo '<div class="card card-outline card-info mb-3"><div class="card-header">';
											echo '<h5 class="card-title"><i class="fas '.htmlspecialchars($sec['icon']).' text-info"></i>&nbsp;<b>'.htmlspecialchars($sec['title']).'</b></h5>';
											echo '</div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0 mpr-grid">';
											echo '<thead><tr><th style="min-width:260px;">Code module</th>';
											foreach($cols as $k => $label){
												echo '<th class="text-center" style="min-width:120px;">'.htmlspecialchars($label).'</th>';
											}
											echo '</tr></thead><tbody>';
											foreach($sec['modules'] as $modKey => $modLabel){
												$row = $perms[$rn][$modKey] ?? null;
												echo '<tr>';
												// Display: show only the human-readable module name. The internal
												// code key ($modKey) is NOT rendered here but still submitted via
												// every checkbox's name attribute (`p[<role>][<modKey>][<col>]`),
												// so save_roles.php keeps writing to the correct DB rows.
												echo '<td>'.htmlspecialchars($modLabel).'</td>';
												foreach($cols as $k => $label){
													$v   = $row ? (int)$row[$k] : 0;
													$chk = $v ? ' checked' : '';
													$id  = 'mpr-'.$rn.'-'.$modKey.'-'.$k;
													$nm  = 'p['.$rn.']['.$modKey.']['.$k.']';
													// Only ADMIN may edit the ADMIN tab's own rows — prevents an admin from locking themselves out mid-click.
													$disabled = ($rn === 'ADMIN' && $k !== 'can_view') ? '' : '';
													echo '<td class="text-center"><div class="icheck-primary d-inline">';
													echo '<input type="checkbox" id="'.$id.'" name="'.$nm.'" value="1"'.$chk.$disabled.'>';
													echo '<label for="'.$id.'">&nbsp;</label></div></td>';
												}
												echo '</tr>';
											}
											echo '</tbody></table></div></div>';
										}
										// Hidden flag so missing checkboxes count as "unticked" for THIS role on save.
										echo '<input type="hidden" name="roles_posted[]" value="'.htmlspecialchars($rn).'">';
										echo '</div>';
										$firstRole = false;
									}
									?>
									</div>

									<div class="d-flex justify-content-between align-items-center mt-3">
										<div class="text-muted small">
										</div>
										<button type="submit" class="btn bg-gradient-success"><i class="fas fa-save"></i>&nbsp;Save all permissions</button>
									</div>
								</form>
							</div>
						</div>
					</div>
				</section>
			</div>
		<?php include_once("../includes/footer.php"); ?>
		</div>
		<script>
		$(function(){
			$('#mpr-form').on('submit', function(e){
				e.preventDefault();
				var $f = $(this);
				var tok = $('meta[name="csrf-token"]').attr('content') || $f.find('input[name=csrf]').val() || '';
				$.ajax({ url: $f.attr('action'), type:'POST', data: $f.serialize(), dataType:'json', headers:{'X-CSRF-Token':tok} })
				 .done(function(resp){
				   var ok = !!(resp && resp.status === 'success');
				   // Success: show a confirmable modal (so the user explicitly closes it)
				   // and then hard-reload the page so the matrix UI is rebuilt from the
				   // freshly-saved DB rows — no stale checkbox state possible.
				   // Failure: show the server-side friendly error, no reload.
				   Swal.fire({
				       icon: ok ? 'success' : 'error',
				       title: ok ? 'Saved' : 'Not saved',
				       text:  ok ? 'Role permissions saved successfully.'
				                 : ((resp && resp.message) || 'Failed to update role permissions. Please try again.'),
				       confirmButtonText: 'OK',
				       allowOutsideClick: false
				   }).then(function(){ if(ok){ location.reload(); } });
				 })
				 .fail(function(){
				   Swal.fire({
				       icon: 'error',
				       title: 'Not saved',
				       text: 'Failed to update role permissions. Please try again.',
				       confirmButtonText: 'OK'
				   });
				 });
			});
		});
		</script>
	</body>
</html>
