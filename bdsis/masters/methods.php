<?php require_once __DIR__ . '/_inc.php'; ?>
<?php
/* AST methods master. Read-only: the three IDs (01, 02, 99) are hardcoded
   throughout the app (30+ code sites), so adding a new method here would not
   automatically become selectable in the AST forms. Renaming is safe but is
   almost never done, and the label change would propagate to every report
   without any other edit. */
$rows = array();
$res = $bdsis->query("SELECT method_id, ast_method FROM ms_ast_method_table ORDER BY method_id");
if ($res) { while (list($mid, $mn) = $res->fetch_row()) { $rows[] = array('mid'=>(string)$mid, 'name'=>(string)$mn); } $res->free(); }
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
            <h1 class="m-0">AST Methods</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">Disk Diffusion / MIC / Not Required &mdash; read-only</p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
              <li class="breadcrumb-item active">AST Methods</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <div class="alert alert-info" style="font-size:.9rem;">
          <strong>Why read-only.</strong> The three method IDs (<code>01</code>, <code>02</code>,
          <code>99</code>) are hardcoded across the AST forms, save handlers and reports.
          Adding a fourth method here would not become selectable without accompanying code
          changes; renaming the existing three would ripple through every report label but is
          rarely wanted. Either edit requires a coordinated deployment, so writes are gated to
          a DB migration rather than to this page.
        </div>
        <div class="card">
          <div class="card-body p-0">
            <table class="table table-hover table-sm">
              <thead><tr><th style="width:120px;">Method ID</th><th>Name</th></tr></thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr><td><code><?= htmlspecialchars($r['mid'], ENT_QUOTES); ?></code></td><td><?= htmlspecialchars($r['name'], ENT_QUOTES); ?></td></tr>
                <?php endforeach; if (count($rows)===0): ?>
                  <tr><td colspan="2" class="text-center text-muted p-4">No AST methods defined.</td></tr>
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
