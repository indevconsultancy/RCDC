<?php
/*
 * MPQMS — Analyst & Approver Master management (Admin UI).
 *
 * Two curated name lists that drive the "Assigned Analyst" and "Approved By"
 * dropdowns on the Test Result card. Admin can Add, Edit and Deactivate (soft
 * delete) rows. Hard-delete is a separate CTA guarded by can_manage.
 *
 * Access control: analyst_master.view / approver_master.view at page load;
 * CRUD actions are re-checked server-side in save_analyst_approver.php, so a
 * crafted POST cannot bypass the role matrix.
 */
// All includes anchored to __DIR__ so moving this file (e.g. to the MPQMS
// root or into a different settings sub-folder) does not break resolution.
include_once(__DIR__ . '/../config/auth_ndtlis.php');
include_once(__DIR__ . '/../config/db_config.php');
include_once(__DIR__ . '/../config/mpq_validate.php');
// Oct-13 2026 directive — ADMIN-only. Direct-URL access by BFDA / NDTL / NMRL
// is blocked at the top of the page. We require BOTH the effective-role
// identity and the matrix view bit, so a stale mpqms_role_permissions row
// cannot grant entry while the DB migration lands.
if(!(function_exists('mpq_effective_role') && mpq_effective_role() === 'ADMIN')
   || !(mpq_can_do('analyst_master','view') || mpq_can_do('approver_master','view'))){
    header("Location: ../dashboard/dashboard.php"); exit;
}

$canAddA  = mpq_can_do('analyst_master','add');
$canEditA = mpq_can_do('analyst_master','edit');
$canDelA  = mpq_can_do('analyst_master','delete');
$canAddB  = mpq_can_do('approver_master','add');
$canEditB = mpq_can_do('approver_master','edit');
$canDelB  = mpq_can_do('approver_master','delete');

// Ensure tables exist (defensive — the migration is the authoritative creator).
@mysqli_query($connection, "CREATE TABLE IF NOT EXISTS analyst_master (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL, status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
@mysqli_query($connection, "CREATE TABLE IF NOT EXISTS approver_master (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL, status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function mpAnaRows($conn, $tbl){
    $out = array();
    $tbl = ($tbl === 'approver_master') ? 'approver_master' : 'analyst_master';
    $rs  = @mysqli_query($conn, "SELECT id, name, status, created_at FROM $tbl ORDER BY name ASC");
    if($rs){ while($r = mysqli_fetch_assoc($rs)){ $out[] = $r; } }
    return $out;
}
$analystRows  = mpAnaRows($connection, 'analyst_master');
$approverRows = mpAnaRows($connection, 'approver_master');

if(empty($_SESSION['csrf'])){ $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = htmlspecialchars((string)$_SESSION['csrf'], ENT_QUOTES);
?>
<?php include_once(__DIR__ . '/../includes/header.php'); ?>
<?php include_once(__DIR__ . '/../includes/css-jquery.php'); ?>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include_once(__DIR__ . '/../includes/top_navigation.php'); ?>
<?php include_once(__DIR__ . '/../includes/main_navigation.php'); ?>
<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1 class="m-0">Analyst &amp; Approver Masters</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Analyst &amp; Approver</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <!-- Assigned Analyst Master -->
                <div class="col-lg-6 col-12">
                    <div class="card card-outline card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-md text-primary"></i>&nbsp;<b>Assigned Analyst Master</b></h3>
                            <?php if($canAddA){ ?>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-primary mp-aa-add" data-kind="analyst">
                                    <i class="fas fa-plus"></i>&nbsp;Add Analyst
                                </button>
                            </div>
                            <?php } ?>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:50%;">Name</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if(empty($analystRows)){ ?>
                                    <tr><td colspan="4" class="text-center text-muted p-4">No analysts yet. <?php if($canAddA){ echo 'Click "Add Analyst".'; } ?></td></tr>
                                <?php } foreach($analystRows as $r){
                                    $id = (int)$r['id']; $nm = htmlspecialchars((string)$r['name']);
                                    $st = (int)$r['status']; $ca = htmlspecialchars((string)$r['created_at']);
                                ?>
                                    <tr>
                                        <td><?=$nm;?></td>
                                        <td><?= $st ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>'; ?></td>
                                        <td><small class="text-muted"><?=$ca;?></small></td>
                                        <td class="text-right text-nowrap">
                                            <?php if($canEditA){ ?>
                                                <button type="button" class="btn btn-xs btn-outline-primary mp-aa-edit"
                                                    data-kind="analyst" data-id="<?=$id;?>" data-name="<?=$nm;?>" data-status="<?=$st;?>" title="Edit">
                                                    <i class="far fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-xs <?= $st ? 'btn-outline-secondary' : 'btn-outline-success';?> mp-aa-toggle"
                                                    data-kind="analyst" data-id="<?=$id;?>" data-status="<?= $st ? 0 : 1;?>" title="<?= $st ? 'Deactivate' : 'Activate';?>">
                                                    <i class="fas <?= $st ? 'fa-ban' : 'fa-check-circle';?>"></i>
                                                </button>
                                            <?php } if($canDelA){ ?>
                                                <button type="button" class="btn btn-xs btn-outline-danger mp-aa-del"
                                                    data-kind="analyst" data-id="<?=$id;?>" data-name="<?=$nm;?>" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Approved By Master -->
                <div class="col-lg-6 col-12">
                    <div class="card card-outline card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-check text-info"></i>&nbsp;<b>Approved By Master</b></h3>
                            <?php if($canAddB){ ?>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-info mp-aa-add" data-kind="approver">
                                    <i class="fas fa-plus"></i>&nbsp;Add Approver
                                </button>
                            </div>
                            <?php } ?>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:50%;">Name</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if(empty($approverRows)){ ?>
                                    <tr><td colspan="4" class="text-center text-muted p-4">No approvers yet. <?php if($canAddB){ echo 'Click "Add Approver".'; } ?></td></tr>
                                <?php } foreach($approverRows as $r){
                                    $id = (int)$r['id']; $nm = htmlspecialchars((string)$r['name']);
                                    $st = (int)$r['status']; $ca = htmlspecialchars((string)$r['created_at']);
                                ?>
                                    <tr>
                                        <td><?=$nm;?></td>
                                        <td><?= $st ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>'; ?></td>
                                        <td><small class="text-muted"><?=$ca;?></small></td>
                                        <td class="text-right text-nowrap">
                                            <?php if($canEditB){ ?>
                                                <button type="button" class="btn btn-xs btn-outline-primary mp-aa-edit"
                                                    data-kind="approver" data-id="<?=$id;?>" data-name="<?=$nm;?>" data-status="<?=$st;?>" title="Edit">
                                                    <i class="far fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-xs <?= $st ? 'btn-outline-secondary' : 'btn-outline-success';?> mp-aa-toggle"
                                                    data-kind="approver" data-id="<?=$id;?>" data-status="<?= $st ? 0 : 1;?>" title="<?= $st ? 'Deactivate' : 'Activate';?>">
                                                    <i class="fas <?= $st ? 'fa-ban' : 'fa-check-circle';?>"></i>
                                                </button>
                                            <?php } if($canDelB){ ?>
                                                <button type="button" class="btn btn-xs btn-outline-danger mp-aa-del"
                                                    data-kind="approver" data-id="<?=$id;?>" data-name="<?=$nm;?>" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add / Edit modal (shared by both kinds). -->
<div class="modal fade" id="mpAaModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" id="mpAaForm" method="post" action="save_analyst_approver.php" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-tag"></i>&nbsp;<span id="mpAaModalTitle">Add</span></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf"   value="<?=$csrf;?>">
                <input type="hidden" name="action" id="mpAaAction" value="create">
                <input type="hidden" name="kind"   id="mpAaKind"   value="analyst">
                <input type="hidden" name="id"     id="mpAaId"     value="">
                <div class="form-group">
                    <label for="mpAaName">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="mpAaName" name="name" maxlength="120" required>
                </div>
                <div class="form-group">
                    <label for="mpAaStatus">Status</label>
                    <select class="form-control" id="mpAaStatus" name="status">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i>&nbsp;Save</button>
            </div>
        </form>
    </div>
</div>

<?php include_once(__DIR__ . '/../includes/footer.php'); ?>
<script>
(function(){
    function titleFor(kind, action){
        var who = (kind === 'approver') ? 'Approver' : 'Analyst';
        return (action === 'edit' ? 'Edit ' : 'Add ') + who;
    }
    $(document).on('click', '.mp-aa-add', function(){
        var kind = $(this).data('kind');
        $('#mpAaAction').val('create');
        $('#mpAaKind').val(kind);
        $('#mpAaId').val('');
        $('#mpAaName').val('');
        $('#mpAaStatus').val('1');
        $('#mpAaModalTitle').text(titleFor(kind, 'create'));
        $('#mpAaModal').modal('show');
    });
    $(document).on('click', '.mp-aa-edit', function(){
        var $b = $(this);
        $('#mpAaAction').val('update');
        $('#mpAaKind').val($b.data('kind'));
        $('#mpAaId').val($b.data('id'));
        $('#mpAaName').val($b.data('name'));
        $('#mpAaStatus').val(String($b.data('status')));
        $('#mpAaModalTitle').text(titleFor($b.data('kind'), 'edit'));
        $('#mpAaModal').modal('show');
    });
    $(document).on('click', '.mp-aa-toggle', function(){
        var $b = $(this);
        $.post('save_analyst_approver.php', { csrf: '<?=$csrf;?>', action:'toggle', kind:$b.data('kind'), id:$b.data('id'), status:$b.data('status') }, null, 'json')
            .done(function(r){
                if(r && r.status === 'success'){ location.reload(); }
                else { Swal.fire({ icon:'error', text:(r && r.message) || 'Unable to update.' }); }
            })
            .fail(function(){ Swal.fire({ icon:'error', text:'Network error. Please try again.' }); });
    });
    $(document).on('click', '.mp-aa-del', function(){
        var $b = $(this);
        Swal.fire({ title:'Delete "'+$b.data('name')+'"?', text:'This cannot be undone.', icon:'warning',
            showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, delete it' })
        .then(function(res){
            if(!res.isConfirmed) return;
            $.post('save_analyst_approver.php', { csrf: '<?=$csrf;?>', action:'delete', kind:$b.data('kind'), id:$b.data('id') }, null, 'json')
                .done(function(r){
                    if(r && r.status === 'success'){ location.reload(); }
                    else { Swal.fire({ icon:'error', text:(r && r.message) || 'Unable to delete.' }); }
                })
                .fail(function(){ Swal.fire({ icon:'error', text:'Network error. Please try again.' }); });
        });
    });
    $('#mpAaForm').on('submit', function(e){
        e.preventDefault();
        var $f = $(this);
        $.post($f.attr('action'), $f.serialize(), null, 'json')
            .done(function(r){
                if(r && r.status === 'success'){ location.reload(); }
                else { Swal.fire({ icon:'error', text:(r && r.message) || 'Unable to save.' }); }
            })
            .fail(function(){ Swal.fire({ icon:'error', text:'Network error. Please try again.' }); });
    });
})();
</script>
</div>
</body>
</html>
