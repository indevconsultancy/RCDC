<?php require_once __DIR__ . '/_inc.php'; ?>
<?php
/* Load rows + a per-pathogen "in use" count so the admin can see which rows are
   safe to delete outright and which must be renamed / left alone. Two SELECTs,
   both indexed on pathogen_id -- cheap. */
$rows = array();
$res = $bdsis->query("SELECT pathogen_id, enteric_pathogen FROM ms_enteric_bacterial_pathogen_table ORDER BY pathogen_id ASC");
if ($res) {
    while (list($pid, $pname) = $res->fetch_row()) {
        $rows[] = array(
            'pid'  => (string)$pid,
            'name' => (string)$pname,
            'uses' => mdCountRefs($bdsis,
                "SELECT COUNT(*) FROM bacteriology_result_table2 WHERE pathogen_id=?", "s", (string)$pid)
                    + mdCountRefs($bdsis,
                "SELECT COUNT(*) FROM rcdc_ecoli_pcr_result_table2 WHERE ecoli_id=?", "s", (string)$pid),
        );
    }
    $res->free();
}

/* Edit-mode: if ?edit=<pid> is in the URL, pre-fill the form; otherwise blank
   Add form. Rename never changes the pid (that would silently reinterpret every
   past bacteriology row); only enteric_pathogen is editable. */
$editPid  = isset($_GET['edit']) ? (string)$_GET['edit'] : '';
$editName = '';
if ($editPid !== '') {
    foreach ($rows as $r) { if ($r['pid'] === $editPid) { $editName = $r['name']; break; } }
    if ($editName === '') { $editPid = ''; }   // unknown id -> back to Add mode
}

/* Retired pathogens (hidden from the "new isolate" pick list) are still hardcoded
   in lab.php ($bpRetiredPathogens). Read it defensively so the badge is
   informational and never breaks if the array moves. */
$retiredIds = array();
$labPath = __DIR__ . '/../lab/lab.php';
if (is_readable($labPath)) {
    $labSrc = @file_get_contents($labPath);
    if ($labSrc !== false && preg_match('~\$bpRetiredPathogens\s*=\s*array\((.*?)\)~', $labSrc, $m)) {
        if (preg_match_all("~'(\\d+)'~", $m[1], $mm)) { $retiredIds = $mm[1]; }
    }
}
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
            <h1 class="m-0">Enteric Pathogens</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">
              Reference list backing the Bacteriology &amp; E.&nbsp;coli PCR dropdowns
            </p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
              <li class="breadcrumb-item active">Enteric Pathogens</li>
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
                <label class="sr-only" for="pathSearch">Search pathogens</label>
                <input type="search" id="pathSearch" class="form-control form-control-sm"
                       placeholder="Search&hellip;" autocomplete="off" aria-label="Search pathogens">
              </div>
              <div class="md-toolbar__actions">
                <?php if (bdsis_masters_can_write('pathogens')): ?>
                  <button type="button" class="btn btn-sm bg-gradient-primary md-primary-action"
                          data-toggle="modal" data-target="#pathAddModal">
                    Add Pathogen
                  </button>
                <?php endif; ?>
              </div>
            </div>
            <div class="md-table-scroll">
              <table id="tblPath" class="table table-bordered table-hover table-sm" style="width:100%;">
                <thead>
                  <tr>
                    <th style="width:90px;">ID</th>
                    <th>Name</th>
                    <th class="text-center" style="width:90px;">In use</th>
                    <th class="text-center" style="width:110px;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r):
                      $canWrite  = bdsis_masters_can_write('pathogens');
                      $canDelete = bdsis_masters_can_delete('pathogens');
                      $inUse     = (int)$r['uses'];
                      $pidEsc    = htmlspecialchars($r['pid'], ENT_QUOTES);
                      $nameEsc   = htmlspecialchars($r['name'], ENT_QUOTES);
                  ?>
                  <tr>
                    <td><code><?= $pidEsc; ?></code></td>
                    <td>
                      <?= $nameEsc; ?>
                      <?php if (in_array($r['pid'], $retiredIds, true)): ?>
                        <span class="badge badge-warning" title="Hidden from the new-isolate pick list; still shown on records that carry it">Retired</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center"><?= $inUse; ?></td>
                    <td>
                      <div class="md-action-cell">
                        <?php if ($canWrite): ?>
                          <button type="button"
                                  class="btn btn-xs md-btn-icon md-btn-edit"
                                  data-toggle="modal"
                                  data-target="#pathEditModal"
                                  data-pid="<?= $pidEsc; ?>"
                                  data-name="<?= $nameEsc; ?>"
                                  title="Edit pathogen <?= $pidEsc; ?>"
                                  aria-label="Edit pathogen <?= $pidEsc; ?>">
                            <i class="fas fa-pen" aria-hidden="true"></i>
                          </button>
                        <?php endif; ?>
                        <?php if ($canDelete && $inUse === 0): ?>
                          <form method="post" action="iu-pathogens.php"
                                onsubmit="return confirm('Delete pathogen <?= $pidEsc; ?> permanently? This cannot be undone.');">
                            <?= bdsis_csrf_field(); ?>
                            <input type="hidden" name="do" value="delete">
                            <input type="hidden" name="pid" value="<?= $pidEsc; ?>">
                            <button type="submit"
                                    class="btn btn-xs md-btn-icon md-btn-delete"
                                    title="Delete pathogen"
                                    aria-label="Delete pathogen <?= $pidEsc; ?>">
                              <i class="fas fa-trash" aria-hidden="true"></i>
                            </button>
                          </form>
                        <?php elseif ($canDelete && $inUse > 0): ?>
                          <button type="button"
                                  class="btn btn-xs md-btn-icon md-btn-delete md-btn-icon--disabled"
                                  disabled
                                  title="Delete blocked while <?= $inUse; ?> record(s) still reference this pathogen"
                                  aria-label="Delete disabled: pathogen in use">
                            <i class="fas fa-trash" aria-hidden="true"></i>
                          </button>
                        <?php elseif (!$canWrite): ?>
                          <span class="md-action-dash" title="Read-only view">&mdash;</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; if (count($rows)===0): ?>
                  <tr><td colspan="4" class="text-center text-muted p-4">No pathogens defined yet.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- Add Pathogen Modal -->
  <div class="modal fade" id="pathAddModal" tabindex="-1" role="dialog" aria-labelledby="pathAddLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <form method="post" action="iu-pathogens.php" autocomplete="off">
        <?= bdsis_csrf_field(); ?>
        <input type="hidden" name="do" value="insert">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="pathAddLabel">Add Pathogen</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Pathogen ID <span class="text-danger">*</span></label>
              <input type="text" name="pid" class="form-control" required maxlength="10" pattern="[A-Za-z0-9_-]+" placeholder="e.g. 027" title="Alphanumeric, dash and underscore only.">
              <small class="form-text text-muted">Use a fresh, unused ID. Common convention is 3 digits.</small>
            </div>
            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Pathogen Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required maxlength="120" placeholder="e.g. Salmonella enterica">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn bg-gradient-primary">
              Add Pathogen
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Pathogen Modal -->
  <div class="modal fade" id="pathEditModal" tabindex="-1" role="dialog" aria-labelledby="pathEditLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <form method="post" action="iu-pathogens.php" autocomplete="off">
        <?= bdsis_csrf_field(); ?>
        <input type="hidden" name="do" value="update">
        <input type="hidden" name="pid" id="pathEditPid" value="">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="pathEditLabel"><i class="fas fa-pen"></i>&nbsp; Rename Pathogen</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Pathogen ID</label>
              <input type="text" id="pathEditPidDisplay" class="form-control" readonly>
              <small class="form-text text-muted">ID is never renamed; historical records reference it by value.</small>
            </div>
            <div class="form-group mb-3">
              <label class="form-label col-form-label-sm">Pathogen Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="pathEditName" class="form-control" required maxlength="120">
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
    var pathTable = $('#tblPath').DataTable({
      paging: true,
      lengthChange: false,
      pageLength: 25,
      order: [[0,'asc']],
      columnDefs: [{ orderable:false, targets:[3] }],
      dom: "<'row'<'col-sm-12'tr>>" +
           "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
    });

    $('#pathSearch').on('input', function () {
      pathTable.search(this.value).draw();
    });

    $('#pathEditModal').on('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      if (!btn) return;
      var pid = btn.getAttribute('data-pid') || '';
      var name = btn.getAttribute('data-name') || '';
      $('#pathEditPid').val(pid);
      $('#pathEditPidDisplay').val(pid);
      $('#pathEditName').val(name);
    });

<?php if ($editPid !== ''): ?>
    (function () {
      $('#pathEditPid').val(<?= json_encode($editPid); ?>);
      $('#pathEditPidDisplay').val(<?= json_encode($editPid); ?>);
      $('#pathEditName').val(<?= json_encode($editName); ?>);
      $('#pathEditModal').modal('show');
    })();
<?php endif; ?>
  });
</script>
</body>
</html>
