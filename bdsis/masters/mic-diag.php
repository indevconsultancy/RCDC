<?php require_once __DIR__ . '/_inc.php'; ?>
<?php
/* =============================================================================
   MIC method-persistence diagnostic
   -----------------------------------------------------------------------------
   One-shot admin-gated check for the Bacteriology AST Method = MIC bug fix.
   Reports:
     1. Whether db-migrations/2026-10-03-bact-mic-table.sql has been applied
        (i.e. does `bacteriology_mic_table` exist on this DB).
        If missing, offers an admin-triggered "Apply migration now" button
        that runs the CREATE TABLE IF NOT EXISTS inside a CSRF-protected POST.
     2. How many rows live in bacteriology_mic_table (overall + distinct brt2
        ids) + the 10 most-recent brt2_ids written there.
     3. How many legacy rows live in bacteriology_ast_table WHERE
        ast_method_id='02' (the pre-fix storage location).
     4. For an optional ?subject=SUBJECTID, what the load-time probe in
        lab.php would detect for every brt2 row of that subject -- so after a
        MIC save you can read off whether Method will reload as MIC.

   READ-ONLY except for the explicit "Apply migration" POST, which runs the
   migration DDL once (CREATE TABLE IF NOT EXISTS -- idempotent, no data
   touched). Safe to leave in production, but intended as a short-lived
   diagnostic -- delete after the issue is resolved.

   RBAC: inherits bdsis_require_admin() from masters/_inc.php -- ADMIN tier
   only (acl=1). Any other tier is denied before any query runs.
   CSRF:  bdsis_csrf_require() guards the Apply-migration POST.

   Path: /BDSIS/masters/mic-diag.php
   ============================================================================= */

$hv = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };

/* ---- POST: admin-triggered CREATE TABLE ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_migration') {
    bdsis_csrf_require();   // 403 if token missing/mismatched

    $sql = "CREATE TABLE IF NOT EXISTS bacteriology_mic_table (\n"
         . "  id                 INT(11)      NOT NULL AUTO_INCREMENT,\n"
         . "  brt2_id            INT(11)      NOT NULL,\n"
         . "  antimicrobial_code VARCHAR(15)  NOT NULL,\n"
         . "  mic_value          VARCHAR(20)  NULL,\n"
         . "  ast_result         VARCHAR(30)  NULL,\n"
         . "  recorded_on        DATETIME     NULL,\n"
         . "  PRIMARY KEY (id),\n"
         . "  KEY idx_brt2 (brt2_id),\n"
         . "  KEY idx_brt2_code (brt2_id, antimicrobial_code)\n"
         . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($bdsis->query($sql)) {
        mdFlash('success', 'Migration applied. bacteriology_mic_table is ready — MIC saves now persist.');
    } else {
        mdFlash('danger', 'Could not create the table: ' . $bdsis->error);
    }
    mdRedirect('mic-diag.php');
}

/* ---- 1. Migration applied? ---- */
$micTableExists = false;
$q = @$bdsis->query("SHOW TABLES LIKE 'bacteriology_mic_table'");
if ($q) { $micTableExists = ($q->num_rows > 0); $q->free(); }

/* ---- 2. New-table population ---- */
$micRowCount    = 0;
$micDistinctBrt = 0;
$micRecentIds   = array();
if ($micTableExists) {
    $r = @$bdsis->query("SELECT COUNT(*) FROM bacteriology_mic_table");
    if ($r) { list($micRowCount) = $r->fetch_row(); $r->free(); }
    $r = @$bdsis->query("SELECT COUNT(DISTINCT brt2_id) FROM bacteriology_mic_table");
    if ($r) { list($micDistinctBrt) = $r->fetch_row(); $r->free(); }
    $r = @$bdsis->query("SELECT brt2_id, COUNT(*) AS n, MAX(recorded_on) AS last
                           FROM bacteriology_mic_table
                       GROUP BY brt2_id
                       ORDER BY last DESC
                          LIMIT 10");
    if ($r) {
        while ($row = $r->fetch_assoc()) { $micRecentIds[] = $row; }
        $r->free();
    }
}

/* ---- 3. Legacy rows in bacteriology_ast_table WHERE ast_method_id='02' ---- */
$legacyRowCount    = 0;
$legacyDistinctBrt = 0;
$legacyRecentIds   = array();
$astHasRows = false;
$r = @$bdsis->query("SELECT COUNT(*) FROM bacteriology_ast_table WHERE ast_method_id='02'");
if ($r) { list($legacyRowCount) = $r->fetch_row(); $r->free(); $astHasRows = true; }
if ($astHasRows) {
    $r = @$bdsis->query("SELECT COUNT(DISTINCT brt2_id) FROM bacteriology_ast_table WHERE ast_method_id='02'");
    if ($r) { list($legacyDistinctBrt) = $r->fetch_row(); $r->free(); }
    $r = @$bdsis->query("SELECT brt2_id, COUNT(*) AS n
                           FROM bacteriology_ast_table
                          WHERE ast_method_id='02'
                       GROUP BY brt2_id
                       ORDER BY brt2_id DESC
                          LIMIT 10");
    if ($r) {
        while ($row = $r->fetch_assoc()) { $legacyRecentIds[] = $row; }
        $r->free();
    }
}

/* ---- 4. Per-subject probe simulation ---- */
$subject   = isset($_GET['subject']) ? trim((string)$_GET['subject']) : '';
$subjectHits = array();
$subjectRecIds = array();
$subjectProbe  = array();
if ($subject !== '') {
    /* Mirror the id resolution lab.php uses: bacteriology_result_table2 (brt2)
       is the "isolate" table; its PK (record_id, often also called brt2_id)
       is what both bacteriology_mic_table and bacteriology_ast_table key on. */
    $sS = $bdsis->real_escape_string($subject);
    $r = @$bdsis->query("SELECT record_id, pathogen_id
                           FROM bacteriology_result_table2
                          WHERE subject_id='".$sS."'
                       ORDER BY record_id");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $subjectRecIds[] = (int)$row['record_id'];
            $subjectHits[(int)$row['record_id']] = array(
                'pathogen' => (string)$row['pathogen_id'],
                'in_mic'   => false,
                'in_ast02' => false,
            );
        }
        $r->free();
    }
    if (count($subjectRecIds) > 0) {
        $inList = implode(',', $subjectRecIds);

        if ($micTableExists) {
            $r = @$bdsis->query("SELECT DISTINCT brt2_id FROM bacteriology_mic_table WHERE brt2_id IN ($inList)");
            if ($r) {
                while (list($id) = $r->fetch_row()) {
                    if (isset($subjectHits[(int)$id])) { $subjectHits[(int)$id]['in_mic'] = true; }
                }
                $r->free();
            }
        }

        $r = @$bdsis->query("SELECT DISTINCT brt2_id
                               FROM bacteriology_ast_table
                              WHERE brt2_id IN ($inList)
                                AND ast_method_id='02'");
        if ($r) {
            while (list($id) = $r->fetch_row()) {
                if (isset($subjectHits[(int)$id])) { $subjectHits[(int)$id]['in_ast02'] = true; }
            }
            $r->free();
        }

        foreach ($subjectHits as $recid => $h) {
            $probe = ($h['in_mic'] || $h['in_ast02']) ? 'MIC (02)' : 'Disk Diffusion (01)';
            $subjectProbe[$recid] = $probe;
        }
    }
}
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
            <h1 class="m-0">MIC Method Persistence &mdash; Diagnostic</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">
              Admin-only. Reports whether the 2026-10-03 bact-MIC migration is applied and whether MIC saves are landing in the new table.
            </p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
              <li class="breadcrumb-item active">MIC Diag</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <?= mdFlashRender(); ?>

        <!-- Step 1 - Migration status -->
        <div class="card <?= $micTableExists ? 'card-success' : 'card-danger'; ?> card-outline">
          <div class="card-header">
            <h3 class="card-title">
              <i class="fas fa-<?= $micTableExists ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
              &nbsp;Step 1 &mdash; <code>bacteriology_mic_table</code> exists
            </h3>
          </div>
          <div class="card-body">
            <?php if ($micTableExists): ?>
              <p class="mb-1"><strong class="text-success">Yes.</strong> The migration <code>db-migrations/2026-10-03-bact-mic-table.sql</code> has been applied on this database.</p>
            <?php else: ?>
              <p class="mb-2"><strong class="text-danger">No &mdash; the migration has NOT been applied.</strong>
                Without this table, every MIC save is a silent no-op (the <code>bdsis_bact_mic_table_exists()</code> probe returns false),
                so after reload Method reverts to Disk Diffusion because there is nothing for the load-time probe to find.
                <strong>This is the root cause of the bug.</strong></p>

              <form method="post" action="mic-diag.php" class="mb-3"
                    onsubmit="return confirm('Create bacteriology_mic_table on this database? This is idempotent and touches no existing data.');">
                <?= bdsis_csrf_field(); ?>
                <input type="hidden" name="action" value="apply_migration" />
                <button type="submit" class="btn btn-danger">
                  <i class="fas fa-bolt"></i>&nbsp;Apply migration now
                </button>
                <span class="text-muted ml-2" style="font-size:.86rem;">
                  Runs <code>CREATE TABLE IF NOT EXISTS bacteriology_mic_table (...)</code> &mdash; admin + CSRF guarded, idempotent.
                </span>
              </form>

              <details>
                <summary style="cursor:pointer;" class="text-muted">Show the SQL (for manual phpMyAdmin apply)</summary>
                <pre style="background:#f4f6f9; padding:1rem; border:1px solid #d2d6de; font-size:.8rem; overflow:auto; margin-top:.5rem;">CREATE TABLE IF NOT EXISTS bacteriology_mic_table (
  id                 INT(11)      NOT NULL AUTO_INCREMENT,
  brt2_id            INT(11)      NOT NULL,
  antimicrobial_code VARCHAR(15)  NOT NULL,
  mic_value          VARCHAR(20)  NULL,
  ast_result         VARCHAR(30)  NULL,
  recorded_on        DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_brt2 (brt2_id),
  KEY idx_brt2_code (brt2_id, antimicrobial_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
                <p class="text-muted" style="font-size:.86rem;">Same SQL as <code>/BDSIS/db-migrations/2026-10-03-bact-mic-table.sql</code>.</p>
              </details>
            <?php endif; ?>
          </div>
        </div>

        <!-- Step 2 - new-table population -->
        <div class="card card-outline <?= ($micTableExists && $micRowCount > 0) ? 'card-success' : ($micTableExists ? 'card-warning' : 'card-secondary'); ?>">
          <div class="card-header">
            <h3 class="card-title">
              <i class="fas fa-database"></i>
              &nbsp;Step 2 &mdash; New-table rows
            </h3>
          </div>
          <div class="card-body">
            <?php if (!$micTableExists): ?>
              <p class="mb-0 text-muted">Skipped &mdash; the table does not exist yet (see Step 1).</p>
            <?php else: ?>
              <p class="mb-1"><strong>Total rows:</strong> <code><?= (int)$micRowCount; ?></code> across <code><?= (int)$micDistinctBrt; ?></code> isolate(s).</p>
              <?php if ($micRowCount === 0): ?>
                <p class="mb-0 text-muted" style="font-size:.9rem;">
                  No MIC saves have landed yet. If you have just submitted an isolate with Method = MIC and this still reads 0,
                  the save handler aborted before writing &mdash; capture the <code>iu-lab.php</code> network response
                  (should be <code>{"ok":true}</code>) and the subject id used, and report back.
                </p>
              <?php else: ?>
                <p class="mb-2"><strong>Most recent 10 isolates with MIC rows (by last recorded_on):</strong></p>
                <table class="table table-sm table-bordered" style="font-size:.86rem; max-width:620px;">
                  <thead><tr><th>brt2_id</th><th>row count</th><th>last recorded_on</th></tr></thead>
                  <tbody>
                    <?php foreach ($micRecentIds as $row): ?>
                      <tr>
                        <td><code><?= $hv($row['brt2_id']); ?></code></td>
                        <td><?= $hv($row['n']); ?></td>
                        <td><?= $hv($row['last']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Step 3 - legacy pre-fix rows -->
        <div class="card card-outline <?= ($legacyRowCount > 0) ? 'card-warning' : 'card-success'; ?>">
          <div class="card-header">
            <h3 class="card-title">
              <i class="fas fa-history"></i>
              &nbsp;Step 3 &mdash; Legacy <code>bacteriology_ast_table</code> rows with <code>ast_method_id='02'</code>
            </h3>
          </div>
          <div class="card-body">
            <p class="mb-1"><strong>Legacy rows:</strong> <code><?= (int)$legacyRowCount; ?></code> across <code><?= (int)$legacyDistinctBrt; ?></code> isolate(s).</p>
            <?php if ($legacyRowCount > 0): ?>
              <p class="mb-2 text-muted" style="font-size:.9rem;">
                These are MIC rows written to the old location between the Phase 1 ship (2026-10-02) and the fix (2026-10-03).
                The load-time probe in <code>lab.php</code> treats them as valid MIC history (legacy fallback branch),
                so Method correctly reloads as MIC for them. New saves no longer go here.
              </p>
              <?php if (count($legacyRecentIds) > 0): ?>
                <table class="table table-sm table-bordered" style="font-size:.86rem; max-width:420px;">
                  <thead><tr><th>brt2_id</th><th>row count</th></tr></thead>
                  <tbody>
                    <?php foreach ($legacyRecentIds as $row): ?>
                      <tr>
                        <td><code><?= $hv($row['brt2_id']); ?></code></td>
                        <td><?= $hv($row['n']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            <?php else: ?>
              <p class="mb-0 text-muted" style="font-size:.9rem;">No legacy rows &mdash; nothing was written to the old location before the fix landed.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Step 4 - per-subject probe simulation -->
        <div class="card card-outline card-info">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-search"></i>&nbsp;Step 4 &mdash; Per-subject probe</h3>
          </div>
          <div class="card-body">
            <form method="get" class="form-inline mb-3">
              <label class="mr-2" for="subject">Subject ID:</label>
              <input type="text" class="form-control mr-2" id="subject" name="subject"
                     value="<?= $hv($subject); ?>" placeholder="e.g. BAR-26-003" style="min-width:260px;" />
              <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i>&nbsp;Check</button>
              <?php if ($subject !== ''): ?>
                <a href="mic-diag.php" class="btn btn-outline-secondary ml-2">Clear</a>
              <?php endif; ?>
            </form>

            <?php if ($subject === ''): ?>
              <p class="mb-0 text-muted" style="font-size:.9rem;">
                Enter a subject you just saved as MIC to see exactly what <code>lab.php</code> would
                detect for each of its isolates. If the probe column reads <code>MIC (02)</code> for the
                saved isolate, the reload will keep Method = MIC.
              </p>
            <?php elseif (count($subjectRecIds) === 0): ?>
              <div class="alert alert-warning mb-0">
                No isolate rows (<code>bacteriology_result_table2</code>) found for <code><?= $hv($subject); ?></code>.
                Check the subject id spelling.
              </div>
            <?php else: ?>
              <p class="mb-2">
                Isolates for <code><?= $hv($subject); ?></code>:
                <strong><?= count($subjectRecIds); ?></strong> row(s).
              </p>
              <table class="table table-sm table-bordered" style="font-size:.86rem; max-width:720px;">
                <thead>
                  <tr>
                    <th>brt2_id</th>
                    <th>pathogen_id</th>
                    <th>in <code>bacteriology_mic_table</code>?</th>
                    <th>in <code>bacteriology_ast_table</code> (02)?</th>
                    <th>Probe verdict</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($subjectHits as $recid => $h):
                    $verdict = isset($subjectProbe[$recid]) ? $subjectProbe[$recid] : 'Disk Diffusion (01)';
                    $vClass  = ($verdict === 'MIC (02)') ? 'badge badge-success' : 'badge badge-secondary';
                  ?>
                    <tr>
                      <td><code><?= $hv($recid); ?></code></td>
                      <td><code><?= $hv($h['pathogen']); ?></code></td>
                      <td><?= $h['in_mic']   ? '<span class="badge badge-success">yes</span>' : '<span class="badge badge-light">no</span>'; ?></td>
                      <td><?= $h['in_ast02'] ? '<span class="badge badge-warning">yes (legacy)</span>' : '<span class="badge badge-light">no</span>'; ?></td>
                      <td><span class="<?= $vClass; ?>"><?= $hv($verdict); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <p class="mb-0 text-muted" style="font-size:.86rem;">
                <strong>Reading this table:</strong> any row with <em>Probe verdict</em> = <code>MIC (02)</code> will
                open with Method = MIC on next reload. A row with <code>Disk Diffusion (01)</code> means neither
                table has MIC data for that isolate.
              </p>
            <?php endif; ?>
          </div>
        </div>

        <div class="alert alert-info" style="font-size:.86rem;">
          <strong>About this page.</strong> This diagnostic is read-only (except for the single admin-triggered
          Apply-migration POST, which runs a <code>CREATE TABLE IF NOT EXISTS</code> and touches no data). It
          is admin-tier gated and intended as a short-lived verification page for the 2026-10-03 MIC fix.
          Delete <code>masters/mic-diag.php</code> once the issue is confirmed resolved.
        </div>

      </div>
    </section>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
<?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
