<?php require_once __DIR__ . '/_inc.php'; ?>
<?php
/* Processing Staff listing = the filtered set of commondb.user_profile_table
   users the E. coli AST staff picker uses. Full user administration (create,
   deactivate, reassign centres, reset passwords, change usernames) belongs
   in COMMONNEW -- BDSIS is not authoritative for auth. What we DO offer here
   is editing the person's designation, because that designation drives BDSIS
   report signatures and is BDSIS's business to keep tidy.

   Read: every BDSIS user with tier != DENIED.
   Edit (designation only): ADMIN only (cross-app: user_profile_table is shared). */

$canWrite = bdsis_masters_can_write('staff');   /* ADMIN only for cross-app */

/* Master list of designations for the edit dropdown. */
$designations = array();
$dq = $commondb->query("SELECT id, value FROM ms_designation_table ORDER BY value");
if ($dq) { while (list($did, $dval) = $dq->fetch_row()) { $designations[(string)$did] = (string)$dval; } $dq->free(); }

/* Edit-mode: ?edit=<username>. Only meaningful for admins. */
$editUsername = ($canWrite && isset($_GET['edit'])) ? (string)$_GET['edit'] : '';
$editRow      = null;
if ($editUsername !== '') {
    $q = $commondb->prepare("SELECT up.username, up.fullname, up.center_id, IFNULL(hc.center_name,''),
                                    IFNULL(up.designation, 0), IFNULL(md.value, '')
                               FROM user_profile_table up
                               JOIN user_system_access_table usa ON usa.username = up.username
                               LEFT JOIN ms_health_center_table hc ON hc.center_id = up.center_id
                               LEFT JOIN ms_designation_table md ON md.id = up.designation
                              WHERE up.username=?
                                AND usa.system_id=6
                                AND usa.access_level IN (2,3)
                                AND usa.user_category IN (3,13)");
    if ($q) {
        $q->bind_param("s", $editUsername); $q->execute();
        $q->bind_result($un, $fn, $cid, $cn, $dId, $dVal);
        if ($q->fetch()) {
            $editRow = array('un'=>(string)$un, 'fn'=>(string)$fn, 'cid'=>(string)$cid,
                             'cn'=>(string)$cn, 'dId'=>(string)$dId, 'dVal'=>(string)$dVal);
        }
        $q->close();
    }
    if (!$editRow) { $editUsername = ''; }
}
$isEdit = ($editRow !== null);

$sql = "SELECT up.username, up.fullname, up.center_id, IFNULL(hc.center_name, ''),
               usa.access_level, usa.user_category,
               IFNULL(md.value, '')
          FROM user_profile_table up
          JOIN user_system_access_table usa ON usa.username = up.username
          LEFT JOIN ms_health_center_table hc ON hc.center_id = up.center_id
          LEFT JOIN ms_designation_table md ON md.id = up.designation
         WHERE usa.system_id = 6
           AND usa.access_level IN (2, 3)
           AND usa.user_category IN (3, 13)
         ORDER BY up.fullname";
$rows = array();
$res = $commondb->query($sql);
if ($res) {
    while (list($un, $fn, $cid, $cn, $acl, $uc, $desig) = $res->fetch_row()) {
        $rows[] = array('un'=>(string)$un, 'fn'=>(string)$fn, 'cid'=>(string)$cid, 'cn'=>(string)$cn,
                        'acl'=>(string)$acl, 'uc'=>(string)$uc, 'desig'=>(string)$desig);
    }
    $res->free();
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
            <h1 class="m-0">Processing Staff</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">
              Users offered in the E.&nbsp;coli AST staff picker &mdash; designations drive BDSIS report signatures
            </p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
              <li class="breadcrumb-item active">Processing Staff</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <?= mdFlashRender(); ?>

        <div class="row">
          <div class="col-lg-<?= $canWrite ? '8' : '12'; ?>">
            <div class="card">
              <div class="card-header"><h3 class="card-title">Users offered in the AST staff picker</h3></div>
              <div class="card-body p-0">
                <div class="md-table-scroll">
                  <table id="tblStaff" class="table table-bordered table-hover table-sm" style="width:100%;">
                    <thead>
                      <tr>
                        <!-- 2026-10-10 UI refresh: ACCESS and CATEGORY columns
                             removed from the UI only. The SELECT below still
                             pulls access_level and user_category because they
                             are part of the shared commondb WHERE filter that
                             defines "AST picker users" (system_id=6 + the
                             allowed level/category pairs). Nothing in the
                             underlying data model, user roles, or user table
                             schema has changed. -->
                        <th style="width:28%;">Full name</th>
                        <th style="width:18%;" class="md-nowrap">Username</th>
                        <th style="width:24%;">Centre</th>
                        <th style="width:22%;">Designation</th>
                        <?php if ($canWrite): ?><th class="text-center" style="width:8%; min-width:72px;">Action</th><?php endif; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rows as $r):
                          $unEsc = htmlspecialchars($r['un'], ENT_QUOTES);
                          $fnEsc = htmlspecialchars($r['fn'], ENT_QUOTES);
                      ?>
                        <tr>
                          <td><?= $fnEsc; ?></td>
                          <td class="md-nowrap"><code><?= $unEsc; ?></code></td>
                          <td><?= htmlspecialchars($r['cn'] !== '' ? $r['cn'] : $r['cid'], ENT_QUOTES); ?></td>
                          <td><?= htmlspecialchars($r['desig'] !== '' ? $r['desig'] : '—', ENT_QUOTES); ?></td>
                          <?php if ($canWrite): ?>
                          <td>
                            <div class="md-action-cell">
                              <a href="?edit=<?= urlencode($r['un']); ?>#editForm"
                                 class="btn btn-xs md-btn-icon md-btn-edit"
                                 title="Edit designation for <?= $fnEsc; ?>"
                                 aria-label="Edit designation for <?= $fnEsc; ?>">
                                <i class="fas fa-pen" aria-hidden="true"></i>
                              </a>
                            </div>
                          </td>
                          <?php endif; ?>
                        </tr>
                      <?php endforeach; if (count($rows)===0): ?>
                        <tr><td colspan="<?= $canWrite ? 5 : 4; ?>" class="text-center text-muted p-4">No staff match the AST filter.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <?php if ($canWrite): ?>
          <div class="col-lg-4">
            <div class="card <?= $isEdit ? 'card-warning' : 'card-secondary'; ?> card-outline" id="editForm">
              <div class="card-header">
                <h3 class="card-title"><?= $isEdit ? 'Edit designation' : 'Edit a staff designation'; ?></h3>
              </div>
              <?php if ($isEdit): ?>
                <form method="post" action="iu-staff.php" autocomplete="off">
                  <?= bdsis_csrf_field(); ?>
                  <input type="hidden" name="do"       value="update_designation">
                  <input type="hidden" name="username" value="<?= htmlspecialchars($editRow['un'], ENT_QUOTES); ?>">
                  <div class="card-body">
                    <!-- Compact read-only context. Replaces three inert
                         <input readonly> fields; the values themselves aren't
                         POSTed (the record is keyed by the hidden `username`
                         above), so plain text is enough. -->
                    <div class="md-context">
                      <dl>
                        <dt>Full name</dt>
                        <dd><?= htmlspecialchars($editRow['fn'], ENT_QUOTES); ?></dd>
                        <dt>Username</dt>
                        <dd><code><?= htmlspecialchars($editRow['un'], ENT_QUOTES); ?></code></dd>
                        <dt>Centre</dt>
                        <dd><?= htmlspecialchars($editRow['cn'] !== '' ? $editRow['cn'] : $editRow['cid'], ENT_QUOTES); ?></dd>
                      </dl>
                    </div>
                    <div class="form-group">
                      <label class="col-form-label-sm">Designation</label>
                      <select name="designation" class="form-control">
                        <option value="0">— None —</option>
                        <?php foreach ($designations as $did => $dv): ?>
                          <option value="<?= htmlspecialchars($did, ENT_QUOTES); ?>" <?= (string)$did === (string)$editRow['dId'] ? 'selected' : ''; ?>><?= htmlspecialchars($dv, ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <small class="form-text text-muted">The designation label printed on BDSIS report signatures for this analyst.</small>
                    </div>
                  </div>
                  <div class="card-footer md-form-actions">
                    <a href="staff.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn bg-gradient-warning md-primary-action">
                      <i class="fas fa-save" aria-hidden="true"></i> Save designation
                    </button>
                  </div>
                </form>
              <?php else: ?>
                <div class="card-body">
                  <p class="text-muted" style="font-size:.9rem;">Click <strong>Edit</strong> on any row to change that person's designation.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
<script>
  $(function(){
    $('#tblStaff').DataTable({ paging: true, lengthChange: false, pageLength: 25, order: [[0,'asc']]<?= $canWrite ? ", columnDefs: [{ orderable:false, targets:[4] }]" : ""; ?> });
  });
</script>
</body>
</html>
