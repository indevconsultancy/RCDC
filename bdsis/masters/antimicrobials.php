<?php require_once __DIR__ . '/_inc.php'; ?>
<?php
/* AST antimicrobial master. Same table for Disk Diffusion (ast_method_id='01')
   and MIC (ast_method_id='02'). Both are managed here; a method filter is a
   simple GET so the URL is bookmarkable and reflects the caller's intent.

   Every read in the app filters `status=1`, so a soft-delete (status=0) hides
   the antibiotic from every dropdown without dropping historical AST readings.
*/
$methodFilter = isset($_GET['m']) ? (string)$_GET['m'] : 'all';
if (!in_array($methodFilter, array('all','01','02'), true)) { $methodFilter = 'all'; }

$where = "";
if ($methodFilter !== 'all') {
    $where = " WHERE ast_method_id='" . $bdsis->real_escape_string($methodFilter) . "'";
}

/* Load rows + a per-row "in use" count aggregated across both AST tables. */
$rows = array();
$res = $bdsis->query("SELECT code, antimicrobial_agent, content, ast_method_id, susceptible, intermediate1, intermediate2, resistance, status FROM ms_ast_table" . $where . " ORDER BY ast_method_id, antimicrobial_agent");
if ($res) {
    while (list($code, $agent, $content, $mid, $sus, $int1, $int2, $r, $status) = $res->fetch_row()) {
        $uses = mdCountRefs($bdsis, "SELECT COUNT(*) FROM bacteriology_ast_table WHERE antimicrobial_code=?", "s", (string)$code)
              + mdCountRefs($bdsis, "SELECT COUNT(*) FROM rcdc_ecoli_pcr_ast_table WHERE antimicrobial_code=?", "s", (string)$code);
        $rows[] = array(
            'id'=>(string)$code,
            'code'=>(string)$code,
            'name'=>(string)$agent,
            'agent'=>(string)$agent,
            'content'=>(string)$content,
            'mid'=>(string)$mid,
            'sus'=>$sus,
            'int1'=>$int1,
            'int2'=>$int2,
            'res'=>$r,
            'status'=>(int)$status,
            'in_use'=>$uses,
            'uses'=>$uses,
        );
    }
    $res->free();
}

/* Method labels for display, from the method master itself so a future rename
   propagates without editing this file. */
$methodLabels = array();
$mq = $bdsis->query("SELECT method_id, ast_method FROM ms_ast_method_table");
if ($mq) { while (list($mid, $mn) = $mq->fetch_row()) { $methodLabels[(string)$mid] = (string)$mn; } }

/* Edit mode: ?edit=<code>&m=<method_id>. code alone is not enough because
   (code, ast_method_id) is the natural composite key. */
$editCode = isset($_GET['edit'])  ? (string)$_GET['edit']  : '';
$editMid  = isset($_GET['emid'])  ? (string)$_GET['emid']  : '';
$editRow  = null;
if ($editCode !== '' && $editMid !== '') {
    foreach ($rows as $r) { if ($r['code']===$editCode && $r['mid']===$editMid) { $editRow = $r; break; } }
    /* If the row is not in the filtered list (e.g. filter=DD but editing an MIC
       row), fall through with a fresh SELECT so the form still opens. */
    if (!$editRow) {
        $q = $bdsis->prepare("SELECT code, antimicrobial_agent, content, ast_method_id, susceptible, intermediate1, intermediate2, resistance, status FROM ms_ast_table WHERE code=? AND ast_method_id=?");
        if ($q) {
            $q->bind_param("ss", $editCode, $editMid); $q->execute();
            $q->bind_result($c,$ag,$cn,$mi,$s1,$i1,$i2,$r1,$st);
            if ($q->fetch()) {
                $editRow = array('code'=>(string)$c,'agent'=>(string)$ag,'content'=>(string)$cn,'mid'=>(string)$mi,'sus'=>$s1,'int1'=>$i1,'int2'=>$i2,'res'=>$r1,'status'=>(int)$st,'uses'=>0);
            }
            $q->close();
        }
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
            <h1 class="m-0">AST Antimicrobials</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">
              Disk Diffusion &amp; MIC antibiotic panel + CLSI breakpoints
            </p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
              <li class="breadcrumb-item active">Antimicrobials</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <?= mdFlashRender(); ?>

        <div class="card">
          <div class="card-body p-0 md-has-toolbar">
            <div class="md-toolbar">
              <div class="md-toolbar__search">
                <label class="sr-only" for="abxSearch">Search antibiotics</label>
                <input type="search" id="abxSearch" class="form-control form-control-sm"
                       placeholder="Search&hellip;" autocomplete="off" aria-label="Search antibiotics">
              </div>
              <div class="md-toolbar__actions">
                <div class="btn-group btn-group-sm" role="group" aria-label="Method filter">
                  <a href="antimicrobials.php?m=all" class="btn <?= $methodFilter==='all' ? 'btn-primary' : 'btn-outline-primary'; ?>">All</a>
                  <a href="antimicrobials.php?m=01"  class="btn <?= $methodFilter==='01'  ? 'btn-primary' : 'btn-outline-primary'; ?>">Disk Diffusion</a>
                  <a href="antimicrobials.php?m=02"  class="btn <?= $methodFilter==='02'  ? 'btn-primary' : 'btn-outline-primary'; ?>">MIC</a>
                </div>
                <?php if (bdsis_masters_can_write('antimicrobials')): ?>
                  <button type="button" class="btn btn-sm bg-gradient-primary md-primary-action"
                          data-toggle="modal" data-target="#abxAddModal">
                     Add Antibiotic
                  </button>
                <?php endif; ?>
              </div>
            </div>
            <div class="md-table-scroll">
              <table id="tblAgents" class="table table-bordered table-hover table-sm" style="width:100%;">
                <thead>
                  <tr>
                    <th style="width:80px;">Code</th>
                    <th style="min-width:160px;">Antimicrobial</th>
                    <th style="width:90px;" class="md-nowrap">Content</th>
                    <th style="width:140px;">Method</th>
                    <th class="text-center md-nowrap" style="width:70px;">S &ge;</th>
                    <th class="text-center md-nowrap" style="width:80px;">I</th>
                    <th class="text-center md-nowrap" style="width:70px;">R &le;</th>
                    <th class="text-center" style="width:90px;">Status</th>
                    <th class="text-center" style="width:80px;">In use</th>
                    <th class="text-center" style="width:130px;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r):
                      $canWrite  = bdsis_masters_can_write('antimicrobials');
                      $canDelete = bdsis_masters_can_delete('antimicrobials');
                      $isActive  = ($r['status'] === 1);
                      $inUse     = (int)($r['in_use'] ?? $r['uses'] ?? 0);
                      $codeEsc   = htmlspecialchars($r['code'], ENT_QUOTES);
                      $midEsc    = htmlspecialchars($r['mid'],  ENT_QUOTES);
                      $nameEsc   = htmlspecialchars($r['name'] ?? $r['agent'], ENT_QUOTES);
                      $idEsc     = htmlspecialchars($r['id'] ?? $r['code'], ENT_QUOTES);
                      /* Content stored with legacy HTML entities (e.g. "30 &#181;g").
                         Decode first so "µg" renders as the real glyph, THEN
                         htmlspecialchars for XSS safety -- µ is not a special
                         HTML char so it survives the escape untouched. */
                      $contentTxt = html_entity_decode((string)$r['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                  ?>
                    <tr class="<?= $isActive ? '' : 'text-muted'; ?>">
                      <td><code><?= $codeEsc; ?></code></td>
                      <td><?= htmlspecialchars($r['agent'], ENT_QUOTES); ?></td>
                      <td class="md-nowrap"><?= htmlspecialchars($contentTxt, ENT_QUOTES); ?></td>
                      <td><?= htmlspecialchars($methodLabels[$r['mid']] ?? $r['mid'], ENT_QUOTES); ?></td>
                      <td class="text-center md-nowrap"><?= htmlspecialchars((string)$r['sus'], ENT_QUOTES); ?></td>
                      <td class="text-center md-nowrap"><?= htmlspecialchars((string)$r['int1'], ENT_QUOTES); ?>&ndash;<?= htmlspecialchars((string)$r['int2'], ENT_QUOTES); ?></td>
                      <td class="text-center md-nowrap"><?= htmlspecialchars((string)$r['res'], ENT_QUOTES); ?></td>
                      <td class="text-center">
                        <?php if ($isActive): ?>
                          <span class="badge badge-success">Active</span>
                        <?php else: ?>
                          <span class="badge badge-secondary">Retired</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-center"><?= $inUse; ?></td>
                      <td>
                        <div class="md-action-cell">
                          <?php if ($canWrite): ?>
                            <button type="button"
                                    class="btn btn-xs md-btn-icon md-btn-edit"
                                    data-toggle="modal"
                                    data-target="#abxEditModal"
                                    data-code="<?= $codeEsc; ?>"
                                    data-mid="<?= $midEsc; ?>"
                                    data-midlabel="<?= htmlspecialchars($methodLabels[$r['mid']] ?? $r['mid'], ENT_QUOTES); ?>"
                                    data-agent="<?= $nameEsc; ?>"
                                    data-content="<?= htmlspecialchars($contentTxt, ENT_QUOTES); ?>"
                                    data-sus="<?= htmlspecialchars((string)($r['sus'] ?? ''), ENT_QUOTES); ?>"
                                    data-int1="<?= htmlspecialchars((string)($r['int1'] ?? ''), ENT_QUOTES); ?>"
                                    data-int2="<?= htmlspecialchars((string)($r['int2'] ?? ''), ENT_QUOTES); ?>"
                                    data-res="<?= htmlspecialchars((string)($r['res'] ?? ''), ENT_QUOTES); ?>"
                                    data-status="<?= (int)$r['status']; ?>"
                                    title="Edit <?= $codeEsc; ?>"
                                    aria-label="Edit antibiotic <?= $codeEsc; ?>">
                              <i class="fas fa-pen" aria-hidden="true"></i>
                            </button>
                            <?php if ($isActive): ?>
                              <form method="post" action="iu-antimicrobials.php"
                                    onsubmit="return confirm('Mark <?= $codeEsc; ?> as retired (status=0)? It will disappear from all forms but historical readings stay.');">
                                <?= bdsis_csrf_field(); ?>
                                <input type="hidden" name="do" value="retire">
                                <input type="hidden" name="code" value="<?= $codeEsc; ?>">
                                <input type="hidden" name="mid" value="<?= $midEsc; ?>">
                                <button type="submit"
                                        class="btn btn-xs md-btn-icon md-btn-warning"
                                        title="Retire <?= $codeEsc; ?>"
                                        aria-label="Retire antibiotic <?= $codeEsc; ?>">
                                  <i class="fas fa-toggle-on" aria-hidden="true"></i>
                                </button>
                              </form>
                            <?php else: ?>
                              <form method="post" action="iu-antimicrobials.php">
                                <?= bdsis_csrf_field(); ?>
                                <input type="hidden" name="do" value="restore">
                                <input type="hidden" name="code" value="<?= $codeEsc; ?>">
                                <input type="hidden" name="mid" value="<?= $midEsc; ?>">
                                <button type="submit"
                                        class="btn btn-xs md-btn-icon md-btn-success"
                                        title="Restore <?= $codeEsc; ?>"
                                        aria-label="Restore antibiotic <?= $codeEsc; ?>">
                                  <i class="fas fa-toggle-off" aria-hidden="true"></i>
                                </button>
                              </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                              <button type="button"
                                      class="btn btn-sm btn-outline-danger md-btn-icon md-btn-delete btn-delete-antimicrobial"
                                      data-id="<?= $idEsc; ?>"
                                      data-code="<?= $codeEsc; ?>"
                                      data-mid="<?= $midEsc; ?>"
                                      data-name="<?= $nameEsc; ?>"
                                      data-in-use="<?= $inUse; ?>"
                                      title="Delete Antimicrobial"
                                      aria-label="Delete antimicrobial <?= $codeEsc; ?>">
                                  <i class="fas fa-trash bi bi-trash"></i>
                              </button>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="md-action-dash" title="Read-only view">&mdash;</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; if (count($rows)===0): ?>
                    <tr><td colspan="10" class="text-center text-muted p-4">No antibiotics defined for this filter.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- Add Antibiotic Modal -->
  <div class="modal fade" id="abxAddModal" tabindex="-1" role="dialog" aria-labelledby="abxAddLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <form method="post" action="iu-antimicrobials.php" autocomplete="off">
        <?= bdsis_csrf_field(); ?>
        <input type="hidden" name="do" value="insert">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="abxAddLabel">Add Antibiotic</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Code <span class="text-danger">*</span></label>
              <input type="text" name="code" class="form-control" required maxlength="15" pattern="[A-Za-z0-9_-]+" placeholder="e.g. CTX">
            </div>

            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Method <span class="text-danger">*</span></label>
              <select name="mid" class="form-control" required>
                <option value="01" <?= $methodFilter==='01' ? 'selected' : ''; ?>>Disk Diffusion (01)</option>
                <option value="02" <?= $methodFilter==='02' ? 'selected' : ''; ?>>MIC (02)</option>
              </select>
            </div>

            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Antimicrobial Name <span class="text-danger">*</span></label>
              <input type="text" name="agent" class="form-control" required maxlength="120" placeholder="e.g. Cefotaxime">
            </div>

            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Disc Content (optional)</label>
              <input type="text" name="content" class="form-control" maxlength="60" placeholder="e.g. 30 µg">
            </div>

            <fieldset class="border p-2 mb-3">
              <legend class="col-form-label-sm w-auto px-1">Breakpoints (zone mm)</legend>
              <div class="row">
                <div class="col-4">
                  <label class="col-form-label-sm">S &ge;</label>
                  <input type="number" step="0.1" name="sus" class="form-control text-center">
                </div>
                <div class="col-4">
                  <label class="col-form-label-sm">I from</label>
                  <input type="number" step="0.1" name="int1" class="form-control text-center">
                </div>
                <div class="col-4">
                  <label class="col-form-label-sm">I to</label>
                  <input type="number" step="0.1" name="int2" class="form-control text-center">
                </div>
              </div>
              <div class="form-group mt-2 mb-1">
                <label class="col-form-label-sm">R &le;</label>
                <input type="number" step="0.1" name="res" class="form-control text-center">
              </div>
              <small class="form-text text-muted">Breakpoints apply to Disk Diffusion. MIC rows may leave them empty &mdash; interpretation is reader-supplied.</small>
            </fieldset>

            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Status</label>
              <select name="status" class="form-control">
                <option value="1" selected>Active</option>
                <option value="0">Retired</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn bg-gradient-primary">
               Add Antibiotic
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Antibiotic Modal -->
  <div class="modal fade" id="abxEditModal" tabindex="-1" role="dialog" aria-labelledby="abxEditLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <form method="post" action="iu-antimicrobials.php" autocomplete="off">
        <?= bdsis_csrf_field(); ?>
        <input type="hidden" name="do" value="update">
        <input type="hidden" name="code" id="abxEditCode" value="">
        <input type="hidden" name="mid" id="abxEditMid" value="">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="abxEditLabel"><i class="fas fa-pen"></i>&nbsp; Edit Antibiotic</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Code</label>
              <input type="text" id="abxEditCodeDisplay" class="form-control" readonly>
              <small class="form-text text-muted">Code + method form the natural key and cannot be renamed.</small>
            </div>

            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Method</label>
              <input type="text" id="abxEditMidDisplay" class="form-control" readonly>
            </div>

            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Antimicrobial Name <span class="text-danger">*</span></label>
              <input type="text" name="agent" id="abxEditAgent" class="form-control" required maxlength="120">
            </div>

            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Disc Content (optional)</label>
              <input type="text" name="content" id="abxEditContent" class="form-control" maxlength="60" placeholder="e.g. 30 µg">
              <small class="form-text text-muted">Use the real glyph (µg) &mdash; the display row decodes legacy HTML entities automatically.</small>
            </div>

            <fieldset class="border p-2 mb-3">
              <legend class="col-form-label-sm w-auto px-1">Breakpoints (zone mm)</legend>
              <div class="row">
                <div class="col-4">
                  <label class="col-form-label-sm">S &ge;</label>
                  <input type="number" step="0.1" name="sus" id="abxEditSus" class="form-control text-center">
                </div>
                <div class="col-4">
                  <label class="col-form-label-sm">I from</label>
                  <input type="number" step="0.1" name="int1" id="abxEditInt1" class="form-control text-center">
                </div>
                <div class="col-4">
                  <label class="col-form-label-sm">I to</label>
                  <input type="number" step="0.1" name="int2" id="abxEditInt2" class="form-control text-center">
                </div>
              </div>
              <div class="form-group mt-2 mb-1">
                <label class="col-form-label-sm">R &le;</label>
                <input type="number" step="0.1" name="res" id="abxEditRes" class="form-control text-center">
              </div>
              <small class="form-text text-muted">Breakpoints apply to Disk Diffusion. MIC rows may leave them empty &mdash; interpretation is reader-supplied.</small>
            </fieldset>

            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Status</label>
              <select name="status" id="abxEditStatus" class="form-control">
                <option value="1">Active (offered on forms)</option>
                <option value="0">Retired (hidden)</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn bg-gradient-warning">
              <i class="fas fa-save" aria-hidden="true"></i> Save changes
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
<script>
  $(function(){
    var abxTable = $('#tblAgents').DataTable({
      paging: true,
      lengthChange: false,
      pageLength: 25,
      order: [[1,'asc']],
      columnDefs: [{ orderable:false, targets:[9] }],
      dom: "<'row'<'col-sm-12'tr>>" +
           "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
    });

    $('#abxSearch').on('input', function () {
      abxTable.search(this.value).draw();
    });

    $('#abxEditModal').on('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      if (!btn) return;
      $('#abxEditCode').val(btn.getAttribute('data-code') || '');
      $('#abxEditCodeDisplay').val(btn.getAttribute('data-code') || '');
      $('#abxEditMid').val(btn.getAttribute('data-mid') || '');
      $('#abxEditMidDisplay').val(btn.getAttribute('data-midlabel') || btn.getAttribute('data-mid') || '');
      $('#abxEditAgent').val(btn.getAttribute('data-agent') || '');
      $('#abxEditContent').val(btn.getAttribute('data-content') || '');
      $('#abxEditSus').val(btn.getAttribute('data-sus') || '');
      $('#abxEditInt1').val(btn.getAttribute('data-int1') || '');
      $('#abxEditInt2').val(btn.getAttribute('data-int2') || '');
      $('#abxEditRes').val(btn.getAttribute('data-res') || '');
      $('#abxEditStatus').val(btn.getAttribute('data-status') || '1');
    });

<?php if ($isEdit && $editRow):
    $editContentDecoded = html_entity_decode((string)$editRow['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $seedData = array(
      'code' => (string)$editRow['code'],
      'mid' => (string)$editRow['mid'],
      'midLabel' => (string)($methodLabels[$editRow['mid']] ?? $editRow['mid']),
      'agent' => (string)$editRow['agent'],
      'content' => $editContentDecoded,
      'sus' => (string)($editRow['sus'] ?? ''),
      'int1' => (string)($editRow['int1'] ?? ''),
      'int2' => (string)($editRow['int2'] ?? ''),
      'res' => (string)($editRow['res'] ?? ''),
      'status' => (string)$editRow['status'],
    );
?>
    (function () {
      var seed = <?= json_encode($seedData, JSON_UNESCAPED_UNICODE); ?>;
      $('#abxEditCode').val(seed.code);
      $('#abxEditCodeDisplay').val(seed.code);
      $('#abxEditMid').val(seed.mid);
      $('#abxEditMidDisplay').val(seed.midLabel);
      $('#abxEditAgent').val(seed.agent);
      $('#abxEditContent').val(seed.content);
      $('#abxEditSus').val(seed.sus);
      $('#abxEditInt1').val(seed.int1);
      $('#abxEditInt2').val(seed.int2);
      $('#abxEditRes').val(seed.res);
      $('#abxEditStatus').val(seed.status);
      $('#abxEditModal').modal('show');
    })();
<?php endif; ?>

    /* Role-based delete action for administrators with dependency validation & confirmation */
    $(document).on('click', '.btn-delete-antimicrobial', function (e) {
      e.preventDefault();
      var btn = $(this);
      var id = btn.data('id') || btn.data('code') || '';
      var code = btn.data('code') || id;
      var mid = btn.data('mid') || '';
      var name = btn.data('name') || code;
      var inUse = parseInt(btn.data('in-use'), 10) || 0;

      if (inUse > 0) {
        var warningHtml = '<strong>' + $('<div>').text(name).html() + ' (' + $('<div>').text(code).html() + ')</strong> cannot be deleted because it is currently referenced by <strong>' + inUse + '</strong> AST record(s).<br><br>' +
          '<span class="text-muted" style="font-size:0.88rem;">To preserve historical laboratory records and data integrity, active references cannot be removed. You can <strong>Retire</strong> this antimicrobial using the toggle button to hide it from active entry forms.</span>';
        if (window.Swal) {
          Swal.fire({
            icon: 'warning',
            title: 'Cannot Delete Antimicrobial',
            html: warningHtml,
            confirmButtonColor: '#0d9488',
            confirmButtonText: 'Understood'
          });
        } else {
          alert('Cannot delete "' + name + '" (' + code + '): It is currently referenced by ' + inUse + ' record(s). Please Retire it instead.');
        }
        return;
      }

      var confirmText = 'Are you sure you want to permanently delete antimicrobial "' + name + '" (' + code + ')? This action cannot be undone.';
      var performDelete = function () {
        var csrfToken = $('input[name="bdsis_csrf"]').first().val() || '<?= bdsis_csrf_token(); ?>';
        $.ajax({
          url: 'iu-antimicrobials.php',
          type: 'POST',
          data: {
            do: 'delete',
            code: code,
            mid: mid,
            bdsis_csrf: csrfToken
          },
          dataType: 'json',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (r) {
          if (r && r.success) {
            if (window.Swal) {
              Swal.fire({
                icon: 'success',
                title: 'Deleted!',
                text: r.message || 'Antimicrobial deleted successfully.',
                timer: 1600,
                showConfirmButton: false
              }).then(function () {
                location.reload();
              });
            } else {
              alert(r.message || 'Antimicrobial deleted successfully.');
              location.reload();
            }
          } else {
            var err = (r && r.message) ? r.message : 'Deletion failed.';
            if (window.Swal) {
              Swal.fire({ icon: 'error', title: 'Cannot Delete', text: err });
            } else {
              alert(err);
            }
          }
        }).fail(function (xhr) {
          var msg = 'Deletion failed.';
          if (xhr.responseJSON && xhr.responseJSON.message) {
            msg = xhr.responseJSON.message;
          } else if (xhr.status === 403) {
            msg = 'Permission denied: Only administrators can delete antimicrobials.';
          }
          if (window.Swal) {
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
          } else {
            alert(msg);
          }
        });
      };

      if (window.Swal) {
        Swal.fire({
          title: 'Delete Antimicrobial?',
          text: confirmText,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#dc2626',
          cancelButtonColor: '#64748b',
          confirmButtonText: 'Yes, delete it!',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed || result.value) {
            performDelete();
          }
        });
      } else {
        if (confirm(confirmText)) {
          performDelete();
        }
      }
    });
  });
</script>
</body>
</html>
