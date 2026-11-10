  <!-- Check session -->
  <?php require_once("../config/session.php"); ?>
  <?php require_once(__DIR__."/rbac.php"); ?>
  <?php
	/* Highlight the menu entry for the page being viewed.

	   Matched on "directory/file", not on the filename alone: record-management.php
	   exists in BOTH records/ and lab/, so a basename comparison lit up Records
	   while the user was on the Lab listing. Comparison is lower-cased because the
	   URL prefix is /BDSIS/ while the folders on disk are lower case. */
	$ntParts = explode('/', str_replace('\\', '/', $_SERVER['PHP_SELF']));
	$ntPage  = strtolower(implode('/', array_slice($ntParts, -2)));
	$ntActive = function($pages) use ($ntPage){
		foreach ((array)$pages as $p) {
			if (strtolower($p) === $ntPage) { return "active"; }
		}
		return "";
	};
  ?>
  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="/BDSIS/dashboard/dashboard.php" class="brand-link">
      <img src="/BDSIS/dist/img/bdsis_logo.png" alt="BDSIS" class="brand-image img-circle elevation-3" style="opacity: .9">
      <span class="brand-text">BDSIS
        <span class="nt-brand-sub">Diarrheal Study Surveillance</span>
      </span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
      <!-- Sidebar Menu -->
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
          <li class="nav-header">NAVIGATION</li>
          <li class="nav-item">
            <a href="/COMMONNEW/dashboard/dashboard.php" class="nav-link">
              <i class="nav-icon fas fa-home" aria-hidden="true"></i>
              <p>RCDC Home</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="/BDSIS/dashboard/dashboard.php" class="nav-link <?=$ntActive('dashboard/dashboard.php');?>">
              <i class="nav-icon fas fa-tachometer-alt"></i>
              <p>Dashboard</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="/BDSIS/records/record-management.php" class="nav-link <?=$ntActive(array('records/record-management.php', 'records/case.php'));?>">
              <i class="nav-icon fas fa-list"></i>
              <p>Records</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="/BDSIS/lab/record-management.php" class="nav-link <?=$ntActive(array('lab/lab.php', 'lab/record-management.php'));?>">
              <i class="nav-icon fas fa-microscope"></i>
              <p>Lab</p>
            </a>
          </li>
          <li class="nav-item <?=$ntActive(array('reports/report-management-individual.php', 'reports/report-group.php', 'reports/report-export.php', 'reports/report-view.php')) ? 'menu-open' : '';?>">
            <a href="#" class="nav-link <?=$ntActive(array('reports/report-management-individual.php', 'reports/report-group.php', 'reports/report-export.php', 'reports/report-view.php'));?>">
              <i class="nav-icon fas fa-chart-line"></i>
              <p>
                Reports
                <i class="right fas fa-angle-left"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="/BDSIS/reports/report-management-individual.php" class="nav-link <?=$ntActive(array('reports/report-management-individual.php', 'reports/report-view.php'));?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Individual Report</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/BDSIS/reports/report-group.php" class="nav-link <?=$ntActive('reports/report-group.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Group Report</p>
                </a>
              </li>
              <?php if(bdsis_can('export')){ /* Raw Export is not a permitted action for
                     scoped roles under the Normal User matrix, so the menu entry is not
                     shown to them; the endpoint refuses it independently. */ ?>
              <li class="nav-item">
                <a href="/BDSIS/reports/report-export.php" class="nav-link <?=$ntActive('reports/report-export.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Export</p>
                </a>
              </li>
              <?php } ?>
            </ul>
          </li>
          <?php if (bdsis_permission_tier() === BDSIS_TIER_ADMIN): /* Master Data:
                 Administrator-only. RCDC (contributor), DHO, MoH and BFDA never see this
                 entry. bdsis_require_admin() in masters/_inc.php re-checks server-side.
                 The Overview, AST Methods and E. coli Strains sub-items were trimmed;
                 their pages remain on disk for anyone who bookmarked them, but they are
                 no longer part of the navigation. */ ?>
          <li class="nav-item <?=$ntActive(array('masters/index.php', 'masters/pathogens.php', 'masters/antimicrobials.php', 'masters/mic.php', 'masters/staff.php')) ? 'menu-open' : '';?>">
            <a href="#" class="nav-link <?=$ntActive(array('masters/index.php', 'masters/pathogens.php', 'masters/antimicrobials.php', 'masters/mic.php', 'masters/staff.php'));?>">
              <i class="nav-icon fas fa-database"></i>
              <p>
                Master Data
                <i class="right fas fa-angle-left"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item"><a href="/BDSIS/masters/pathogens.php"      class="nav-link <?=$ntActive('masters/pathogens.php');?>"><i class="far fa-circle nav-icon"></i><p>Enteric Pathogens</p></a></li>
              <li class="nav-item"><a href="/BDSIS/masters/antimicrobials.php" class="nav-link <?=$ntActive('masters/antimicrobials.php');?>"><i class="far fa-circle nav-icon"></i><p>AST Antimicrobials</p></a></li>
              <!-- Sites removed from the BDSIS Master Data nav (client request).
                   The underlying commondb.ms_health_center_table master, its rows,
                   and every subject_information_table.center_id FK are untouched
                   so historical Subject-IDs and reports keep resolving; site
                   maintenance moves to the shared administration portal. -->
            </ul>
          </li>
          <?php endif; ?>
          <?php if (bdsis_permission_tier() === BDSIS_TIER_ADMIN): /* User
                 Management: direct top-level link, Administrator-only. The
                 masters/users.php page itself re-checks admin via
                 bdsis_require_admin(), and masters/iu-users.php (the write
                 endpoint) also re-checks on POST, so non-admins never see
                 this entry and can never reach the write path even by URL. */ ?>
          <li class="nav-item">
            <a href="/BDSIS/masters/users.php" class="nav-link <?=$ntActive('masters/users.php');?>">
              <i class="nav-icon fas fa-users-cog"></i>
              <p>User Management</p>
            </a>
          </li>
          <?php endif; ?>
          <li class="nav-item">
            <a href="../login/signout.php" class="nav-link">
              <i class="nav-icon fas fa-sign-out-alt"></i>
              <p>Logout</p>
            </a>
          </li>
        </ul>
      </nav>
      <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
  </aside>
