<?php require_once __DIR__ . '/_inc.php'; ?>
<?php
/* E. coli pathotype strain master. Read-only: bdsis_ecoli_pcr_strains() in
   lab-helpers.php restricts the PCR form to strain_id IN ('01'..'06') by
   value, so adding a strain here would not become selectable without a code
   change; renaming an existing one propagates safely through the reports. */
$rows = array();
$res = $bdsis->query("SELECT strain_id, ecoli_strain FROM ms_ecoli_strain_table ORDER BY strain_id");
if ($res) { while (list($sid, $sn) = $res->fetch_row()) { $rows[] = array('sid'=>(string)$sid, 'name'=>(string)$sn); } $res->free(); }
?>
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
            <h1 class="m-0">E. coli Strains</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">Pathotypes offered by the PCR panel &mdash; read-only</p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
              <li class="breadcrumb-item active">E. coli Strains</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <div class="alert alert-info" style="font-size:.9rem;">
          <strong>Why read-only.</strong> The PCR panel is restricted to
          <code>strain_id IN ('01','02','03','04','05','06')</code> in
          <code>bdsis_ecoli_pcr_strains()</code> (<code>includes/lab-helpers.php</code>).
          Adding a strain here does not enroll it in the panel without editing that gate.
        </div>
        <div class="card">
          <div class="card-body p-0">
            <table class="table table-hover table-sm">
              <thead><tr><th style="width:120px;">Strain ID</th><th>Pathotype</th></tr></thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr><td><code><?= htmlspecialchars($r['sid'], ENT_QUOTES); ?></code></td><td><?= htmlspecialchars($r['name'], ENT_QUOTES); ?></td></tr>
                <?php endforeach; if (count($rows)===0): ?>
                  <tr><td colspan="2" class="text-center text-muted p-4">No strains defined.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
</body>
</html>
