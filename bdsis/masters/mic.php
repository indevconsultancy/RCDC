<?php require_once __DIR__ . '/_inc.php'; ?>
<?php
/**
 * MIC master.
 *
 * Manages the `ms_ast_table` rows with `ast_method_id='02'`: the MIC
 * antibiotic list and (when present) its CLSI breakpoint interpretation.
 * MIC and Disk-Diffusion share the SAME master table -- the method is a
 * column, not a schema -- so this page and antimicrobials.php read and write
 * the same rows through the same save endpoint (`iu-antimicrobials.php`).
 * This page just pre-filters to method 02 and locks the Method field in the
 * Add form so the caller never has to choose it.
 *
 * Reference to bdsis_mic_agents() in `includes/lab-mic.php`: the MIC entry
 * form in the E. coli AST section currently picks its antibiotic list from
 * the DD method (01) rows, so MIC-specific rows added here become the
 * MIC-only master while DD continues to seed the picker. Both views stay
 * consistent because both read `status=1` -- retire once, disappears
 * everywhere.
 */
$METHOD = '02';

/* Count MIC-specific usages: rows in rcdc_ecoli_pcr_ast_table with BOTH the
   matching antibiotic_code AND ast_method_id='02' -- so a code that exists
   in both the DD and MIC masters only counts its MIC readings here. This
   uses a fresh two-parameter prepared statement rather than the shared
   mdCountRefs (which is one-parameter by design). */
$rows = array();
$usesQ = $bdsis->prepare("SELECT COUNT(*) FROM rcdc_ecoli_pcr_ast_table WHERE antimicrobial_code=? AND ast_method_id=?");

$res = $bdsis->query("SELECT code, antimicrobial_agent, content, susceptible, intermediate1, intermediate2, resistance, status FROM ms_ast_table WHERE ast_method_id='" . $bdsis->real_escape_string($METHOD) . "' ORDER BY antimicrobial_agent");
if ($res) {
    while (list($code, $agent, $content, $sus, $int1, $int2, $r, $status) = $res->fetch_row()) {
        $codeStr = (string)$code;
        $uses = 0;
        if ($usesQ) {
            $usesQ->bind_param("ss", $codeStr, $METHOD);
            $usesQ->execute();
            $usesQ->bind_result($n); $usesQ->fetch();
            $usesQ->free_result();
            $uses = (int)$n;
        }
        $rows[] = array(
            'code'=>$codeStr, 'agent'=>(string)$agent, 'content'=>(string)$content,
            'sus'=>$sus, 'int1'=>$int1, 'int2'=>$int2, 'res'=>$r,
            'status'=>(int)$status,
            'uses'=>$uses,
        );
    }
    $res->free();
}
if ($usesQ) { $usesQ->close(); }

/* Edit mode. `emid` is always '02' here (the page is MIC-only) but it is still
   read from the URL so a bookmarked edit link keeps working. */
$editCode = isset($_GET['edit']) ? (string)$_GET['edit'] : '';
$editRow  = null;
if ($editCode !== '') {
    $q = $bdsis->prepare("SELECT code, antimicrobial_agent, content, susceptible, intermediate1, intermediate2, resistance, status FROM ms_ast_table WHERE code=? AND ast_method_id=?");
    if ($q) {
        $q->bind_param("ss", $editCode, $METHOD); $q->execute();
        $q->bind_result($c,$ag,$cn,$s1,$i1,$i2,$r1,$st);
        if ($q->fetch()) {
            $editRow = array('code'=>(string)$c,'agent'=>(string)$ag,'content'=>(string)$cn,'sus'=>$s1,'int1'=>$i1,'int2'=>$i2,'res'=>$r1,'status'=>(int)$st);
        }
        $q->close();
    }
}
$isEdit = ($editRow !== null);
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/css-jquery.php'; ?>
<?php require_once __DIR__ . '/_ui.php';                     /* refreshed Master Data visual layer -- scoped to body.md-page */ ?>
<body class="hold-transition sidebar-mini md-page">
<div class="wrapper">
  <?php require_once __DIR__ . '/../includes/top-navbar.php'; ?>
  <?php require_once __DIR__ . '/../includes/sidebar-menu.php'; ?>

  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-8">
            <h1 class="m-0">MIC Master</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">
              Minimum Inhibitory Concentration antibiotic panel
            </p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
              <li class="breadcrumb-item active">MIC</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <?= mdFlashRender(); ?>

        <div class="row">
          <div class="col-lg-8">
            <div class="card">
              <div class="card-header"><h3 class="card-title">MIC antibiotics</h3></div>
              <div class="card-body p-0">
                <div class="md-table-scroll">
                  <table id="tblMic" class="table table-bordered table-hover table-sm" style="width:100%;">
                    <thead>
                      <tr>
                        <th style="width:90px;">Code</th>
                        <th style="min-width:180px;">Antimicrobial</th>
                        <th style="width:110px;" class="md-nowrap">Content</th>
                        <th class="text-center" style="width:100px;">Status</th>
                        <th class="text-center" style="width:90px;">In use</th>
                        <th class="text-center" style="width:120px;">Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rows as $r):
                          $canWrite  = bdsis_masters_can_write('mic');
                          $isActive  = ($r['status'] === 1);
                          $codeEsc   = htmlspecialchars($r['code'], ENT_QUOTES);
                          $midEsc    = htmlspecialchars($METHOD,   ENT_QUOTES);
                          /* Decode legacy entity-encoded content strings (e.g. "&micro;g/mL")
                             so the real µ glyph renders instead of the raw entity. */
                          $contentTxt = html_entity_decode((string)$r['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                      ?>
                        <tr class="<?= $isActive ? '' : 'text-muted'; ?>">
                          <td><code><?= $codeEsc; ?></code></td>
                          <td><?= htmlspecialchars($r['agent'], ENT_QUOTES); ?></td>
                          <td class="md-nowrap"><?= htmlspecialchars($contentTxt, ENT_QUOTES); ?></td>
                          <td class="text-center">
                            <?php if ($isActive): ?>
                              <span class="badge badge-success">Active</span>
                            <?php else: ?>
                              <span class="badge badge-secondary">Retired</span>
                            <?php endif; ?>
                          </td>
                          <td class="text-center"><?= (int)$r['uses']; ?></td>
                          <td>
                            <div class="md-action-cell">
                              <?php if ($canWrite): ?>
                                <a href="?edit=<?= urlencode($r['code']); ?>#editForm"
                                   class="btn btn-xs md-btn-icon md-btn-edit"
                                   title="Edit <?= $codeEsc; ?>"
                                   aria-label="Edit MIC entry <?= $codeEsc; ?>">
                                  <i class="fas fa-pen" aria-hidden="true"></i>
                                </a>
                                <?php if ($isActive): ?>
                                  <form method="post" action="iu-antimicrobials.php"
                                        onsubmit="return confirm('Retire MIC entry <?= $codeEsc; ?>? It will disappear from MIC forms but historical readings stay linked.');">
                                    <?= bdsis_csrf_field(); ?>
                                    <input type="hidden" name="do"   value="retire">
                                    <input type="hidden" name="code" value="<?= $codeEsc; ?>">
                                    <input type="hidden" name="mid"  value="<?= $midEsc; ?>">
                                    <button type="submit"
                                            class="btn btn-xs md-btn-icon md-btn-warning"
                                            title="Retire <?= $codeEsc; ?>"
                                            aria-label="Retire MIC entry <?= $codeEsc; ?>">
                                      <i class="fas fa-toggle-on" aria-hidden="true"></i>
                                    </button>
                                  </form>
                                <?php else: ?>
                                  <form method="post" action="iu-antimicrobials.php">
                                    <?= bdsis_csrf_field(); ?>
                                    <input type="hidden" name="do"   value="restore">
                                    <input type="hidden" name="code" value="<?= $codeEsc; ?>">
                                    <input type="hidden" name="mid"  value="<?= $midEsc; ?>">
                                    <button type="submit"
                                            class="btn btn-xs md-btn-icon md-btn-success"
                                            title="Restore <?= $codeEsc; ?>"
                                            aria-label="Restore MIC entry <?= $codeEsc; ?>">
                                      <i class="fas fa-toggle-off" aria-hidden="true"></i>
                                    </button>
                                  </form>
                                <?php endif; ?>
                              <?php else: ?>
                                <span class="md-action-dash" title="Read-only view">&mdash;</span>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; if (count($rows)===0): ?>
                        <tr><td colspan="6" class="text-center text-muted p-4">No MIC entries defined yet. Add one with the form on the right.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <?php if (bdsis_masters_can_write('mic')): ?>
            <div class="card <?= $isEdit ? 'card-warning' : 'card-primary'; ?> card-outline" id="editForm">
              <div class="card-header">
                <h3 class="card-title">
                  <?= $isEdit ? 'Edit MIC entry' : 'Add MIC entry'; ?>
                  <small>Method: MIC (<?= htmlspecialchars($METHOD, ENT_QUOTES); ?>)</small>
                </h3>
              </div>
              <form method="post" action="iu-antimicrobials.php" autocomplete="off">
                <?= bdsis_csrf_field(); ?>
                <input type="hidden" name="do"  value="<?= $isEdit ? 'update' : 'insert'; ?>">
                <input type="hidden" name="mid" value="<?= htmlspecialchars($METHOD, ENT_QUOTES); ?>">

                <div class="card-body">
                  <div class="form-group">
                    <label class="col-form-label-sm">Code</label>
                    <?php if ($isEdit): ?>
                      <input type="hidden" name="code" value="<?= htmlspecialchars($editRow['code'], ENT_QUOTES); ?>">
                      <input type="text" class="form-control" value="<?= htmlspecialchars($editRow['code'], ENT_QUOTES); ?>" readonly>
                      <small class="form-text text-muted">Code + method form the natural key and cannot be renamed.</small>
                    <?php else: ?>
                      <input type="text" name="code" class="form-control" required maxlength="15" pattern="[A-Za-z0-9_-]+" placeholder="e.g. CTX">
                    <?php endif; ?>
                  </div>

                  <!-- Method field intentionally hidden from the form UI: this
                       page is MIC-only and the value is fixed at 02. The hidden
                       `mid` input above still submits it to iu-antimicrobials.php.
                       The card header carries a "Method: MIC (02)" caption so
                       the user still sees the context without an inert field. -->

                  <div class="form-group">
                    <label class="col-form-label-sm">Antimicrobial name</label>
                    <input type="text" name="agent" class="form-control" required maxlength="120" value="<?= $isEdit ? htmlspecialchars($editRow['agent'], ENT_QUOTES) : ''; ?>">
                  </div>

                  <div class="form-group">
                    <label class="col-form-label-sm">Disc content / MIC unit note (optional)</label>
                    <input type="text" name="content" class="form-control" maxlength="60" placeholder="e.g. µg/mL"
                           value="<?= $isEdit ? htmlspecialchars(html_entity_decode((string)$editRow['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES) : ''; ?>">
                  </div>

                  <!-- MIC breakpoint inputs (S / I / R) were removed per the
                       2026-10-10 UI refresh. The E. coli MIC panel records the
                       reader-supplied S / I / R / SDD / N/A, so these numeric
                       breakpoints aren't surfaced to lab users. Any legacy
                       breakpoint values stored on existing rows are left
                       untouched in ms_ast_table (iu-antimicrobials.php's
                       update path doesn't null columns that aren't posted). -->

                  <div class="form-group">
                    <label class="col-form-label-sm">Status</label>
                    <select name="status" class="form-control">
                      <option value="1" <?= $isEdit && $editRow['status']===1 ? 'selected' : ''; ?>>Active</option>
                      <option value="0" <?= $isEdit && $editRow['status']===0 ? 'selected' : ''; ?>>Retired</option>
                    </select>
                  </div>
                </div>

                <div class="card-footer md-form-actions">
                  <?php if ($isEdit): ?>
                    <a href="mic.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn bg-gradient-warning md-primary-action">
                      <i class="fas fa-save" aria-hidden="true"></i> Save changes
                    </button>
                  <?php else: ?>
                    <button type="submit" class="btn bg-gradient-primary md-primary-action md-primary-action--block">
                      <i class="fas fa-plus" aria-hidden="true"></i> Add MIC entry
                    </button>
                  <?php endif; ?>
                </div>
              </form>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary" style="font-size:.86rem;">
              <strong>Read-only view.</strong> Your role can view the MIC breakpoint
              panel but cannot add, edit or retire entries. Contact a BDSIS Administrator
              for changes.
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
<script>
  $(function(){
    $('#tblMic').DataTable({ paging: true, lengthChange: false, pageLength: 25, order: [[1,'asc']], columnDefs: [{ orderable:false, targets:[5] }] });
  });
</script>
</body>
</html>
