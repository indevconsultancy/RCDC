<?php
	// Effective-role RBAC drives menu visibility, using the same matrix the pages and the
	// backend handlers use (config/mpq_validate.php), so the sidebar can never offer a link
	// the server would refuse.
	include_once(dirname(__DIR__).'/config/mpq_validate.php');
	// Highlight the menu entry for the page being viewed.
	$mpPage = basename($_SERVER['PHP_SELF']);
	$mpActive = function($pages) use ($mpPage){ return in_array($mpPage, (array)$pages) ? "active" : ""; };
	$mpReportPages = array('raw_data.php', 'test_chart1.php', 'compliance_chart1.php', 'test_table.php');
	$mpSettingPages = array('formulation.php', 'parameter.php', 'category.php', 'edl.php', 'manufacturer.php', 'mah.php');
?>
  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="../dashboard/dashboard.php" class="brand-link">
      <img src="../dist/img/NDTLIS_LOGO.png" alt="MPQMS" class="brand-image img-circle elevation-3" style="opacity: .9">
      <span class="brand-text">MPQMS
        <span class="nt-brand-sub">Medical Products Quality Monitoring System</span>
      </span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
      <!-- Sidebar Menu -->
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
          <li class="nav-header">NAVIGATION</li>
          <li class="nav-item">
          <!--  <a href="/COMMONNEW/dashboard/dashboard.php" class="nav-link">  -->
            <a href="../dashboard/dashboard.php" class="nav-link">
              <i class="nav-icon fas fa-home" aria-hidden="true"></i>
              <p>RCDC Home</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="../dashboard/dashboard.php" class="nav-link <?=$mpActive('dashboard.php');?>">
              <i class="nav-icon fas fa-tachometer-alt"></i>
              <p>Dashboard</p>
            </a>
          </li>
          <?php if(mpq_can_do('manager','view')){ ?>
          <li class="nav-item">
            <a href="../regtest/manager.php" class="nav-link <?=$mpActive(array('manager.php', 'registration.php', 'view.php', 'result.php'));?>">
              <i class="nav-icon fas fa-flask"></i>
              <p>Registration &amp; Result</p>
            </a>
          </li>
          <?php } ?>
          <?php if(mpq_can_do('reports','view')){ ?>
          <li class="nav-item <?=$mpActive($mpReportPages) ? 'menu-open' : '';?>">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-chart-line"></i>
              <p>
                Reports
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="../reports/raw_data.php" class="nav-link <?=$mpActive('raw_data.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Raw Data</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="../reports/test_chart1.php" class="nav-link <?=$mpActive(array('test_chart1.php', 'test_table.php'));?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Total Test</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="../reports/compliance_chart1.php" class="nav-link <?=$mpActive('compliance_chart1.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Test Compliance</p>
                </a>
              </li>
            </ul>
          </li>
          <?php } ?>
          <li class="nav-item <?=$mpActive($mpSettingPages) ? 'menu-open' : '';?>">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-cog"></i>
              <p>
                Settings
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if(mpq_can_do('settings_core','view')){ ?>
              <li class="nav-item">
                <a href="../settings/formulation.php" class="nav-link <?=$mpActive('formulation.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Formulation</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="../settings/parameter.php" class="nav-link <?=$mpActive('parameter.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Parameter</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="../settings/category.php" class="nav-link <?=$mpActive('category.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Category</p>
                </a>
              </li>
              <?php if(mpq_can_do('edl','view')){ /* EDL / NEML (Medical Product List) management is Administrator-only */ ?>
              <li class="nav-item">
                <a href="../settings/edl.php" class="nav-link <?=$mpActive('edl.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>EDL</p>
                </a>
              </li>
              <?php } ?>
              <?php } ?>
              <li class="nav-item">
                <a href="../settings/manufacturer.php" class="nav-link <?=$mpActive('manufacturer.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Manufacturer</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="../settings/mah.php" class="nav-link <?=$mpActive('mah.php');?>">
                  <i class="far fa-circle nav-icon"></i>
                  <p>MAH</p>
                </a>
              </li>
            </ul>
          </li>
          <?php
          // Oct-13 2026 directive — Analyst & Approver menu is ADMIN-only. We
          // require BOTH the matrix bit (mpq_can_do) AND the effective-role
          // identity so a stale mpqms_role_permissions row (pre-Oct-13 seed)
          // cannot leak the link to non-admins while the DB migration lands.
          if((function_exists('mpq_effective_role') && mpq_effective_role() === 'ADMIN')
             && (mpq_can_do('analyst_master','view') || mpq_can_do('approver_master','view'))){ ?>
          <li class="nav-item">
            <a href="../settings/analyst_approver_master.php" class="nav-link <?=$mpActive('analyst_approver_master.php');?>">
              <i class="nav-icon fas fa-user-tag"></i>
              <p>Analyst &amp; Approver</p>
            </a>
          </li>
          <?php } ?>
          <?php if(mpq_can_do('users','view')){ ?>
          <li class="nav-item">
            <a href="../settings/users.php" class="nav-link <?=$mpActive('users.php');?>">
              <i class="nav-icon fas fa-users-cog"></i>
              <p>User Management</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="../settings/roles.php" class="nav-link <?=$mpActive('roles.php');?>">
              <i class="nav-icon fas fa-user-shield"></i>
              <p>Roles &amp; Permissions</p>
            </a>
          </li>
          <?php } ?>
          <li class="nav-item">
            <a href="../login/signout.php" class="nav-link">
              <i class="nav-icon fas fa-sign-out-alt"></i>
              <p>Signout</p>
            </a>
          </li>
        </ul>
      </nav>
      <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
  </aside>
