<?php require_once __DIR__ . '/_inc.php'; ?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/css-jquery.php'; ?>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <?php require_once __DIR__ . '/../includes/top-navbar.php'; ?>
  <?php require_once __DIR__ . '/../includes/sidebar-menu.php'; ?>

  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-8">
            <h1 class="m-0">Master Data</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">
              Reference lists behind every BDSIS dropdown &mdash; administrator-managed
            </p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Master Data</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <?= mdFlashRender(); ?>

        <div class="row">
          <div class="col-md-4 col-lg-3">
            <div class="card card-primary card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-bacterium"></i>&nbsp;Enteric Pathogens</h3></div>
              <div class="card-body">
                <p class="text-muted" style="font-size:.86rem;">The pathogen list shown in Bacteriology. Add, rename, or retire.</p>
                <p><span class="badge badge-success">Full CRUD</span></p>
              </div>
              <div class="card-footer"><a href="pathogens.php" class="btn btn-sm bg-gradient-primary">Manage</a></div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card card-primary card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-vial"></i>&nbsp;AST Antimicrobials</h3></div>
              <div class="card-body">
                <p class="text-muted" style="font-size:.86rem;">Disk-Diffusion antibiotic panel and CLSI zone breakpoints.</p>
                <p><span class="badge badge-success">Full CRUD</span></p>
              </div>
              <div class="card-footer"><a href="antimicrobials.php" class="btn btn-sm bg-gradient-primary">Manage</a></div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card card-primary card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-eye-dropper"></i>&nbsp;MIC</h3></div>
              <div class="card-body">
                <p class="text-muted" style="font-size:.86rem;">MIC breakpoints and antibiotics (<code>ast_method_id='02'</code>).</p>
                <p><span class="badge badge-success">Full CRUD</span></p>
              </div>
              <div class="card-footer"><a href="mic.php" class="btn btn-sm bg-gradient-primary">Manage</a></div>
            </div>
          </div>

          <!-- Sites / Health Centers card removed at the client's request. The
               underlying commondb.ms_health_center_table stays intact so every
               subject_information_table.center_id FK, subject-ID prefix
               (<code>-<yy>-<seq>) and historical report keeps resolving; site
               maintenance moves to the shared administration portal. Direct
               access to /BDSIS/masters/sites.php now redirects here with a
               deprecation notice. -->

          <div class="col-md-4 col-lg-3">
            <div class="card card-secondary card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-user-md"></i>&nbsp;Processing Staff</h3></div>
              <div class="card-body">
                <p class="text-muted" style="font-size:.86rem;">Free-text on newer records; the shared user directory backs the E.&nbsp;coli AST staff picker.</p>
                <p><span class="badge badge-secondary">Read-only</span></p>
              </div>
              <div class="card-footer"><a href="staff.php" class="btn btn-sm bg-gradient-secondary">View</a></div>
            </div>
          </div>

          <?php /* Admin-only: BDSIS User Management. The card itself is only
                   rendered when the viewing user is a BDSIS Admin so that
                   non-admins never see a link to a page they can't open. The
                   write endpoint (iu-users.php) also re-checks admin on POST. */
                if (bdsis_permission_tier() === BDSIS_TIER_ADMIN): ?>
          <div class="col-md-4 col-lg-3">
            <div class="card card-primary card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-users-cog"></i>&nbsp;User Management</h3></div>
              <div class="card-body">
                <p class="text-muted" style="font-size:.86rem;">Grant, edit or revoke BDSIS Access for existing commondb users. Scoped strictly to <code>system_id=6</code>.</p>
                <p><span class="badge badge-info">Admin only</span></p>
              </div>
              <div class="card-footer"><a href="users.php" class="btn btn-sm bg-gradient-primary">Manage</a></div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="alert alert-info" style="font-size:.9rem;">
          <strong>Cross-app note.</strong> Sites, Districts, Occupations and the shared user
          directory live in <code>commondb</code> and are used by every sibling RCDC system.
          BDSIS shows them here for reference, but writes belong to the shared administration
          portal to avoid silently changing what the other systems see.
        </div>
      </div>
    </section>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
</body>
</html>
