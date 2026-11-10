  <!-- Check session -->
  <?php require_once("../config/session.php"); ?>
  <?php
    // Active-state helpers (mirrors the NTESIS sidebar). Matching is
    // directory-aware because some filenames repeat across modules
    // (e.g. panel-list.php exists in both panels/ and rdt-panels/).
    $mnDir  = basename(dirname($_SERVER['PHP_SELF']));
    $mnPage = basename($_SERVER['PHP_SELF']);
    $mnIn   = function($dirs) use ($mnDir) { return in_array($mnDir, (array)$dirs); };
    $mnOn   = function($pages) use ($mnPage) { return in_array($mnPage, (array)$pages); };
    $mnAct  = function($cond) { return $cond ? ' active' : ''; };
    $mnOpen = function($cond) { return $cond ? ' menu-open' : ''; };
    // Molecular splits into Patient vs Shipment by filename
    $mnMolecular = $mnIn('molecular');
    $mnMolShip   = $mnMolecular && (strpos($mnPage, 'shipment') !== false);
    $mnMolPat    = $mnMolecular && !$mnMolShip;
    $mnReports   = $mnIn('reports');
    $mnBanking   = $mnIn('bankings');
    $mnSettings  = $mnIn('settings');
  ?>
  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="/MNEQAS/dashboard/dashboard.php" class="brand-link">
      <img src="/MNEQAS/dist/img/Logo.png" alt="mNEQAS" class="brand-image img-circle elevation-3" style="opacity: .8">
      <span class="brand-text">mNEQAS<span class="nt-brand-sub">Malaria External Quality Assessment</span></span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
      <!-- Sidebar Menu -->
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
          <li class="nav-header">NAVIGATION</li>
          <li class="nav-item ">
            <a href="/COMMONNEW/dashboard/dashboard.php" class="nav-link">
            <i class="nav-icon fas fa-home"  aria-hidden="true"></i>
              <p>RCDC Home</p>
            </a>
          </li>
          <li class="nav-item ">
            <a href="/MNEQAS/dashboard/dashboard.php" class="nav-link<?=$mnAct($mnIn('dashboard'));?>">
              <i class="nav-icon fas fa-tachometer-alt"></i>
              <p>Dashboard</p>
            </a>
          </li>
          <?php if (mn_can_access_modules()) { // module menus: RCDC (1/2) and facility users (3/4); server-side policy in config/auth.php ?>
          <li class="nav-item">
            <a href="/MNEQAS/blinded/blinded-list.php" class="nav-link<?=$mnAct($mnIn('blinded'));?>">
              <i class="nav-icon fas fa-microscope"></i>
              <p>Blinded Rechecking</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="/MNEQAS/onsitems/ms-list.php" class="nav-link<?=$mnAct($mnIn('onsitems'));?>">
              <i class="nav-icon fas fa-list"></i>
              <p>Onsite M & S</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="/MNEQAS/rdts/rdt-list.php" class="nav-link<?=$mnAct($mnIn('rdts'));?>">
              <i class="nav-icon fas fa-medkit"></i>
              <p>Malaria RDT QA</p>
            </a>
          </li>
          <li class="nav-item<?=$mnOpen($mnMolecular);?>">
            <a href="#" class="nav-link<?=$mnAct($mnMolecular);?>">
              <i class="nav-icon fas fa-vials"></i>
              <p>Molecular Testing</p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="/MNEQAS/molecular/molecular-list.php" class="nav-link<?=$mnAct($mnMolPat);?>">
                  <p>Patient & Shipment Information</p>
                </a>
              </li>
        
            </ul>
          </li>
          <li class="nav-item">
            <a href="/MNEQAS/panels/panel-list.php" class="nav-link<?=$mnAct($mnIn('panels'));?>">
              <i class="nav-icon fas fa-microscope"></i>
              <p>Micro Panel Testing</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="/MNEQAS/rdt-panels/panel-list.php" class="nav-link<?=$mnAct($mnIn('rdt-panels'));?>">
              <i class="nav-icon fas fa-medkit"></i>
              <p>RDT Panel Testing</p>
            </a>
          </li>
          <li class="nav-item<?=$mnOpen($mnReports);?>">
            <a href="#" class="nav-link<?=$mnAct($mnReports);?>">
              <i class="nav-icon fas fa-chart-line"></i>
              <p>Reports</p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="/MNEQAS/reports/blinded-reports.php" class="nav-link<?=$mnAct($mnReports && $mnOn('blinded-reports.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Blinded Reports</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/reports/molecular-reports.php" class="nav-link<?=$mnAct($mnReports && $mnOn('molecular-reports.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Molecular Reports</p>
                </a>
              </li>
			       <li class="nav-item">
                <a href="/MNEQAS/reports/ms-reports.php" class="nav-link<?=$mnAct($mnReports && $mnOn('ms-reports.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>MS Reports</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/reports/panel-reports.php" class="nav-link<?=$mnAct($mnReports && $mnOn('panel-reports.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Micro Panel Reports</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/reports/rdt-panel-reports.php" class="nav-link<?=$mnAct($mnReports && $mnOn('rdt-panel-reports.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>RDT Panel Reports</p>
                </a>
              </li>
              <!-- <li class="nav-item">
                <a href="/MNEQAS/reports/rdt-reports.php" class="nav-link">
                  <i class="fas fa-check nav-icon"></i>
                  <p>RDT Reports</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/reports/onsitems-reports.php" class="nav-link">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Onsite MS Reports</p>
                </a>
              </li> -->
            </ul>
          </li>
          <?php } // end module menus ?>
        <?php if(mn_is_rcdc()) { // Slide Banking + Settings: RCDC only ?>
          <li class="nav-item<?=$mnOpen($mnBanking);?>">
            <a href="#" class="nav-link<?=$mnAct($mnBanking);?>">
              <i class="nav-icon fas fa-warehouse"></i>
              <p>
                Slide Banking
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="/MNEQAS/bankings/reports.php" class="nav-link<?=$mnAct($mnBanking && $mnOn('reports.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Reports</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/bankings/mappings.php" class="nav-link<?=$mnAct($mnBanking && $mnOn('mappings.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Mappings</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/bankings/cabinets.php" class="nav-link<?=$mnAct($mnBanking && $mnOn('cabinets.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Cabinets</p>
                </a>
              </li>
            </ul>
          </li>
          <li class="nav-item<?=$mnOpen($mnSettings);?>">
            <a href="#" class="nav-link<?=$mnAct($mnSettings);?>">
              <i class="nav-icon fas fa-cog"></i>
              <p>
                Settings
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="/MNEQAS/settings/onsite-checklist.php" class="nav-link<?=$mnAct($mnSettings && $mnOn('onsite-checklist.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>M&S Checklist</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/settings/panel-rounds.php" class="nav-link<?=$mnAct($mnSettings && $mnOn('panel-rounds.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Micro Panel Round</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/settings/panel-assignments.php" class="nav-link<?=$mnAct($mnSettings && $mnOn('panel-assignments.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>Micro Panel Assignment</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/settings/rdt-panel-rounds.php" class="nav-link<?=$mnAct($mnSettings && $mnOn('rdt-panel-rounds.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>RDT Panel Round</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/MNEQAS/settings/rdt-panel-assignments.php" class="nav-link<?=$mnAct($mnSettings && $mnOn('rdt-panel-assignments.php'));?>">
                  <i class="fas fa-check nav-icon"></i>
                  <p>RDT Panel Assignment</p>
                </a>
              </li>
            </ul>
          </li>
          <?php } ?>
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