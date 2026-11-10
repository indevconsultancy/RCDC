<?php require_once __DIR__ . '/_inc.php'; ?>
<?php
/**
 * BDSIS User Management (2026-10-10, admin-only).
 *
 * SCOPE
 * -----
 * BDSIS acts as the user-management surface for its own admins. The Add User
 * flow creates a REAL commondb user (login_table + user_profile_table) and
 * grants that user BDSIS access (user_system_access_table, system_id=6).
 * Edit updates the shared profile fields plus the BDSIS role. Revoke still
 * drops ONLY the system_id=6 access row -- the shared login/profile survive,
 * so the user keeps every other system they already had.
 *
 *   Add     INSERT login_table (username, password=md5)
 *           INSERT user_profile_table (username, fullname, <emailcol>, ...)
 *           INSERT user_system_access_table (username, system_id=6, ...)
 *
 *   Edit    UPDATE user_profile_table SET fullname=?, <emailcol>=?
 *           UPDATE login_table SET password=md5(?)  (only if password given)
 *           UPDATE user_system_access_table SET access_level=?, user_category=?
 *            WHERE username=? AND system_id=6
 *
 *   Revoke  DELETE FROM user_system_access_table WHERE username=? AND system_id=6
 *
 * Password hashing matches /BDSIS/login/authentications.php exactly (unsalted
 * MD5); strengthening the scheme is a cross-system change not scoped here.
 *
 * Role vocabulary (maps to rbac.php L194-244):
 *   Admin        access_level=1  user_category=1   -> BDSIS_TIER_ADMIN
 *   Contributor  access_level=2  user_category=3   -> BDSIS_TIER_CONTRIBUTOR
 *   Read-only    access_level=4  user_category=3   -> BDSIS_TIER_READONLY
 *
 * Access to the page itself is Admin-only (bdsis_require_admin below).
 */

bdsis_require_admin('open User Management');

/* The 5 sanctioned BDSIS roles. Stored and maintained strictly within BDSIS configs/tables.
   Centralized in rbac.php (bdsis_roles). */
$BDSIS_ROLES = bdsis_roles();
$roleFromPair = function ($acl, $uc) {
    return bdsis_role_from_pair($acl, $uc);
};

/* ---- Discover the email column on user_profile_table ------------------
   The shared COMMONNEW directory has not kept the email column name stable
   across sibling systems ("email", "user_email", "email_address", "emailid"
   have all been seen). Hard-coding one of those made this page 500 on
   staging. Discover it at page load instead so the module keeps working
   even if the DBA renames the column later. If no mail column exists at
   all, email is simply omitted and the UI shows an em-dash.
   Guarded by a strict identifier regex -- the admin schema is trusted, but
   splicing a column name into SQL still deserves a hard sanity check. */
$emailCol = '';
if ($rChk = $commondb->query("SHOW COLUMNS FROM user_profile_table LIKE '%mail%'")) {
    if ($rowChk = $rChk->fetch_assoc()) {
        $cand = (string)$rowChk['Field'];
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $cand)) { $emailCol = $cand; }
    }
    $rChk->free();
}
$emailExpr = ($emailCol !== '') ? "IFNULL(up.`" . $emailCol . "`,'')" : "''";

/* Master list of health facilities for dropdown */
$centers = array();
$cq = $commondb->query("SELECT center_id, center_name FROM ms_health_center_table WHERE status=1 ORDER BY center_name");
if ($cq) {
    while ($r = $cq->fetch_assoc()) {
        $centers[$r['center_id']] = $r['center_name'];
    }
    $cq->free();
}

/* Master list of designations for dropdown */
$designations = array();
$dq = $commondb->query("SELECT id, value FROM ms_designation_table ORDER BY value");
if ($dq) {
    while ($r = $dq->fetch_assoc()) {
        $designations[$r['id']] = $r['value'];
    }
    $dq->free();
}

/* ---- List: every commondb user with a BDSIS access row ----------------- */
$rows = array();
$sql = "SELECT up.username, up.fullname, " . $emailExpr . " AS email,
               up.center_id, IFNULL(hc.center_name, '') AS center_name,
               up.designation, IFNULL(md.value, '') AS designation_name,
               usa.access_level, usa.user_category
          FROM user_profile_table up
          JOIN user_system_access_table usa ON usa.username = up.username
          LEFT JOIN ms_health_center_table hc ON hc.center_id = up.center_id
          LEFT JOIN ms_designation_table md ON md.id = up.designation
         WHERE usa.system_id = 6
         ORDER BY up.fullname";
$res = $commondb->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $slug = $roleFromPair($r['access_level'], $r['user_category']);
        $roleInfo = isset($BDSIS_ROLES[$slug]) ? $BDSIS_ROLES[$slug] : null;
        $rows[] = array(
            'un'      => (string)$r['username'],
            'fn'      => (string)$r['fullname'],
            'email'   => (string)$r['email'],
            'cid'     => (string)$r['center_id'],
            'cname'   => (string)$r['center_name'],
            'desig'   => (string)$r['designation'],
            'desname' => (string)$r['designation_name'],
            'acl'     => (string)$r['access_level'],
            'uc'      => (string)$r['user_category'],
            'role'    => $slug,
            'label'   => $roleInfo ? $roleInfo['label'] : bdsis_role_label($r['access_level']),
            'badge'   => $roleInfo ? $roleInfo['badge'] : 'badge-secondary',
        );
    }
    $res->free();
}

/* ---- Edit mode: ?edit=<username> pre-fills the Edit modal via server-rendered
       data attributes read by a small JS hook at the bottom. */
$editUser = isset($_GET['edit']) ? (string)$_GET['edit'] : '';
$editSeed = null;
if ($editUser !== '') {
    foreach ($rows as $r) { if ($r['un'] === $editUser) { $editSeed = $r; break; } }
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/css-jquery.php'; ?>
<?php require_once __DIR__ . '/_ui.php'; ?>
<body class="hold-transition sidebar-mini md-page">
<div class="wrapper">
  <?php require_once __DIR__ . '/../includes/top-navbar.php'; ?>
  <?php require_once __DIR__ . '/../includes/sidebar-menu.php'; ?>

  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-8">
            <h1 class="m-0">User Management</h1>
            <p class="text-muted mb-0" style="font-size:.86rem;">
              Create, edit or revoke BDSIS users &mdash; shared commondb records are kept in sync.
            </p>
          </div>
          <div class="col-sm-4">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
              <li class="breadcrumb-item active">User Management</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <?= mdFlashRender(); ?>

        <div class="card">
          <!-- No .card-header: the "BDSIS users" title was removed, and an empty
               header would only contribute padding and a divider. The page
               already names itself in .content-header above. -->
          <div class="card-body p-0 md-has-toolbar">
            <!-- Listing toolbar: search on the left, primary action on the right,
                 one row. The search input is OURS rather than the one DataTables
                 renders: its built-in filter is locked inside the wrapper's own
                 first row, which cannot host the Add User button, so that row is
                 switched off via `dom` below and this input drives the same
                 table.search() API instead. Behaviour is unchanged -- still
                 DataTables' smart search across every column. -->
            <div class="md-toolbar">
              <div class="md-toolbar__search">
                <label class="sr-only" for="umSearch">Search users</label>
                <input type="search" id="umSearch" class="form-control form-control-sm"
                       placeholder="Search&hellip;" autocomplete="off" aria-label="Search users">
              </div>
              <div class="md-toolbar__actions">
                <button type="button" class="btn btn-sm bg-gradient-primary md-primary-action"
                        data-toggle="modal" data-target="#umAddModal">
                  Add User
                </button>
              </div>
            </div>
            <div class="md-table-scroll">
              <table id="tblUsers" class="table table-bordered table-hover table-sm" style="width:100%;">
                <thead>
                  <tr>
                    <th style="width:14%;" class="md-nowrap">Username</th>
                    <th style="width:24%;">Full name</th>
                    <th style="width:22%;">Facility / Center</th>
                    <th style="width:18%;">Email</th>
                    <th class="text-center" style="width:12%;">Role</th>
                    <th class="text-center" style="width:10%; min-width:110px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r):
                      $unEsc = htmlspecialchars($r['un'],    ENT_QUOTES);
                      $fnEsc = htmlspecialchars($r['fn'],    ENT_QUOTES);
                      $cnEsc = htmlspecialchars($r['cname'], ENT_QUOTES);
                      $emEsc = htmlspecialchars($r['email'], ENT_QUOTES);
                      $lbEsc = htmlspecialchars($r['label'], ENT_QUOTES);
                      $slug  = htmlspecialchars($r['role'],  ENT_QUOTES);
                      $badge = htmlspecialchars($r['badge'], ENT_QUOTES);
                  ?>
                  <tr>
                    <td class="md-nowrap"><code><?= $unEsc; ?></code></td>
                    <td><?= $fnEsc; ?></td>
                    <td><?= $cnEsc !== '' ? $cnEsc : '<span class="text-muted">&mdash;</span>'; ?></td>
                    <td><?= $emEsc !== '' ? $emEsc : '<span class="text-muted">&mdash;</span>'; ?></td>
                    <td class="text-center"><span class="badge <?= $badge; ?>"><?= $lbEsc; ?></span></td>
                    <td>
                      <div class="md-action-cell">
                        <button type="button"
                                class="btn btn-xs md-btn-icon md-btn-edit"
                                data-toggle="modal"
                                data-target="#umEditModal"
                                data-un="<?= $unEsc; ?>"
                                data-fn="<?= $fnEsc; ?>"
                                data-em="<?= $emEsc; ?>"
                                data-cid="<?= htmlspecialchars($r['cid'], ENT_QUOTES); ?>"
                                data-role="<?= $slug; ?>"
                                title="Edit user <?= $fnEsc; ?>"
                                aria-label="Edit user <?= $fnEsc; ?>">
                          <i class="fas fa-pen" aria-hidden="true"></i>
                        </button>
                        <form method="post" action="iu-users.php"
                              onsubmit="return confirm('Revoke BDSIS Access for <?= $unEsc; ?>?\nThis removes the system_id=6 row only. The user keeps access to every other RCDC system they already had.');">
                          <?= bdsis_csrf_field(); ?>
                          <input type="hidden" name="do"       value="revoke">
                          <input type="hidden" name="username" value="<?= $unEsc; ?>">
                          <button type="submit"
                                  class="btn btn-xs md-btn-icon md-btn-delete"
                                  title="Revoke BDSIS Access"
                                  aria-label="Revoke BDSIS Access for <?= $fnEsc; ?>">
                            <i class="fas fa-user-slash" aria-hidden="true"></i>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; if (count($rows)===0): ?>
                    <tr><td colspan="6" class="text-center text-muted p-4">No BDSIS users yet. Click <strong>Add User</strong> to create or grant access.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- Add User modal (full form: Username / Full Name / Email / Facility / Role / Password) -->
  <div class="modal fade" id="umAddModal" tabindex="-1" role="dialog" aria-labelledby="umAddLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <form method="post" action="iu-users.php" autocomplete="off">
        <?= bdsis_csrf_field(); ?>
        <input type="hidden" name="do" value="add">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="umAddLabel">Add User</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group mb-3">
              <label class="form-label">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" required maxlength="64"
                     pattern="[A-Za-z0-9_.\-]{1,64}" autocomplete="off">
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="fullname" class="form-control" required maxlength="128" autocomplete="off">
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" maxlength="128" autocomplete="off">
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Health Facility / Center <span class="text-danger">*</span></label>
              <select name="center_id" id="umAddCenter" class="form-control" required>
                <?php foreach ($centers as $cid => $cname): ?>
                  <option value="<?= htmlspecialchars($cid, ENT_QUOTES); ?>" <?= $cid === '997' ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($cname, ENT_QUOTES); ?> (<?= htmlspecialchars($cid, ENT_QUOTES); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Role <span class="text-danger">*</span></label>
              <select name="role" class="form-control" required>
                <?php foreach ($BDSIS_ROLES as $slug => $r): ?>
                  <option value="<?= htmlspecialchars($slug, ENT_QUOTES); ?>" <?= $slug === 'health_worker' ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($r['label'], ENT_QUOTES); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" required autocomplete="new-password">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn bg-gradient-primary">
              Create User
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit User modal (full form: Username locked / Full Name / Email / Facility / Role / Password optional) -->
  <div class="modal fade" id="umEditModal" tabindex="-1" role="dialog" aria-labelledby="umEditLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <form method="post" action="iu-users.php" autocomplete="off">
        <?= bdsis_csrf_field(); ?>
        <input type="hidden" name="do" value="update">
        <input type="hidden" name="username" id="umEditUn" value="">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="umEditLabel"><i class="fas fa-user-cog"></i>&nbsp; Edit User</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group mb-3">
              <label class="form-label">Username</label>
              <input type="text" id="umEditUnDisplay" class="form-control" readonly>
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="fullname" id="umEditFn" class="form-control" required maxlength="128" autocomplete="off">
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Email</label>
              <input type="email" name="email" id="umEditEm" class="form-control" maxlength="128" autocomplete="off">
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Health Facility / Center <span class="text-danger">*</span></label>
              <select name="center_id" id="umEditCid" class="form-control" required>
                <?php foreach ($centers as $cid => $cname): ?>
                  <option value="<?= htmlspecialchars($cid, ENT_QUOTES); ?>">
                    <?= htmlspecialchars($cname, ENT_QUOTES); ?> (<?= htmlspecialchars($cid, ENT_QUOTES); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Role <span class="text-danger">*</span></label>
              <select name="role" id="umEditRole" class="form-control" required>
                <?php foreach ($BDSIS_ROLES as $slug => $r): ?>
                  <option value="<?= htmlspecialchars($slug, ENT_QUOTES); ?>"><?= htmlspecialchars($r['label'], ENT_QUOTES); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Password</label>
              <input type="password" name="password" id="umEditPw" class="form-control" minlength="6" maxlength="64"
                     autocomplete="new-password" placeholder="Leave blank to keep current password">
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
    var umTable = $('#tblUsers').DataTable({
      paging:true, lengthChange:false, pageLength:10, order:[[1,'asc']],
      columnDefs:[{ orderable:false, targets:[5] }],
      dom: "<'row'<'col-sm-12'tr>>" +
           "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
    });

    /* Toolbar search -> the same smart search the built-in box used. */
    $('#umSearch').on('input', function () {
      umTable.search(this.value).draw();
    });

    /* Seed the Edit modal from the pencil button's data-* attributes. */
    $('#umEditModal').on('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      if (!btn) return;
      var un = btn.getAttribute('data-un') || '';
      $('#umEditUn').val(un);
      $('#umEditUnDisplay').val(un);
      $('#umEditFn').val(btn.getAttribute('data-fn') || '');
      $('#umEditEm').val(btn.getAttribute('data-em') || '');
      $('#umEditCid').val(btn.getAttribute('data-cid') || '997');
      $('#umEditRole').val(btn.getAttribute('data-role') || 'health_worker');
      $('#umEditPw').val('');
    });

<?php if ($editSeed !== null): ?>
    /* Deep-link ?edit=<username> opens the Edit modal pre-seeded for that user. */
    (function () {
      var seed = <?= json_encode($editSeed, JSON_UNESCAPED_UNICODE); ?>;
      $('#umEditUn').val(seed.un);
      $('#umEditUnDisplay').val(seed.un);
      $('#umEditFn').val(seed.fn);
      $('#umEditEm').val(seed.email);
      $('#umEditCid').val(seed.cid || '997');
      $('#umEditRole').val(seed.role);
      $('#umEditPw').val('');
      $('#umEditModal').modal('show');
    })();
<?php endif; ?>
  });
</script>
</body>
</html>
