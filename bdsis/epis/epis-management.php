<?php ob_start(); /* Buffered so a refused role gets a real 403 rather than a 200
     carrying half a page -- header.php emits <!DOCTYPE> before the guard below
     runs. Same ordering as records/record-management.php. */ ?>
<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php");
  require_once("../includes/rbac.php");
  require_once("../includes/csrf.php");
  require_once("../includes/epis-lib.php");
  bdsis_require_module();
  bdsis_require_can('view', 'Your role is not permitted to view ePIS notifications.', 'records');
?>
<?php
  /* Nothing here is a BDSIS record yet. These rows are notifications pulled from
     ePIS: they are held in their own queue, they never appear in the Records
     listing, and one becomes a case only when somebody accepts it and completes
     the ordinary case wizard. Rejected rows stay for audit. */
  $epMayAct  = bdsis_can('add', 'records');
  $epStatus  = isset($_GET['status']) ? (string)$_GET['status'] : 'New';
  if (!in_array($epStatus, array('New', 'Accepted', 'Rejected', 'Imported', 'All'), true)) { $epStatus = 'New'; }
  $epQuery   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $epCounts  = bdsis_epis_counts($bdsis, $commondb);
  $epRows    = bdsis_epis_cases($bdsis, $commondb, array(
                   'status' => ($epStatus === 'All' ? '' : $epStatus),
                   'q'      => $epQuery,
               ));
  $epReady   = bdsis_epis_table_ready($bdsis);

  $epFlash = isset($_SESSION['bdsis_epis_flash']) ? $_SESSION['bdsis_epis_flash'] : null;
  unset($_SESSION['bdsis_epis_flash']);

  $epBadge = function ($status) {
      switch ($status) {
          case 'New':      return 'badge-warning';
          case 'Accepted': return 'badge-info';
          case 'Imported': return 'badge-success';
          case 'Rejected': return 'badge-secondary';
      }
      return 'badge-light';
  };
  $epTab = function ($key) use ($epStatus) { return $epStatus === $key ? 'active' : ''; };
?>

<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>
<style>
/* Queue-specific only; nothing here changes any other page. */
.ep-summary .info-box            { margin-bottom: 0; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.ep-summary .info-box-number     { font-size: 1.4rem; line-height: 1.2; }
.ep-tabs .nav-link               { font-size: 14px; font-weight: 600; }
.ep-codes .badge                 { font-weight: 600; margin: 0 2px 2px 0; }
.ep-dx                           { display: block; max-width: 320px; font-size: 12px; color: #6c757d;
                                   white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card-header.ep-head             { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; }
.card-header.ep-head .card-title { margin: 0; font-size: 15px; font-weight: 600; }
</style>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
    <!-- Top Navbar -->
    <?php require_once("../includes/top-navbar.php"); ?>
    <!-- Main Sidebar Container -->
    <?php require_once("../includes/sidebar-menu.php"); ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>ePIS Verification</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">ePIS Verification</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if(!$epReady){ ?>
            <div class="alert alert-danger">
                <h5><i class="icon fas fa-ban"></i> ePIS queue not installed</h5>
                The <code>bdsis_epis_case</code> table does not exist. Run
                <code>sql/2026-09-24_epis_pending.sql</code> against this database, then reload.
            </div>
            <?php } ?>

            <!-- Counts. Deliberately plain: this is a work queue, and the only
                 number anyone acts on is "Pending verification". -->
            <div class="row ep-summary mb-3">
                <div class="col-6 col-md-3">
                    <div class="info-box"><span class="info-box-icon bg-warning"><i class="far fa-clock"></i></span>
                        <div class="info-box-content"><span class="info-box-text">Pending</span>
                            <span class="info-box-number"><?=(int)$epCounts['New'];?></span></div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="info-box"><span class="info-box-icon bg-info"><i class="far fa-check-circle"></i></span>
                        <div class="info-box-content"><span class="info-box-text">Accepted</span>
                            <span class="info-box-number"><?=(int)$epCounts['Accepted'];?></span></div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-notes-medical"></i></span>
                        <div class="info-box-content"><span class="info-box-text">Registered</span>
                            <span class="info-box-number"><?=(int)$epCounts['Imported'];?></span></div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-times"></i></span>
                        <div class="info-box-content"><span class="info-box-text">Rejected</span>
                            <span class="info-box-number"><?=(int)$epCounts['Rejected'];?></span></div></div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header ep-head">
                            <h3 class="card-title">
                                Notifications received from ePIS
                                <small class="text-muted d-block" style="font-weight:400">
                                    Matched against the <?=count(bdsis_epis_active_codes());?> ICD&#8209;11 codes BDSIS
                                    follows. These are not BDSIS records until they are accepted and registered.
                                </small>
                            </h3>
                            <?php if($epMayAct){ ?>
                            <form method="post" action="epis-action.php" id="epSyncForm" class="m-0">
                                <?=bdsis_csrf_field();?>
                                <input type="hidden" name="do" value="sync">
                                <button type="submit" class="btn bg-gradient-primary" id="epSyncBtn">
                                    <i class="fas fa-sync"></i>&nbsp;Sync from ePIS
                                </button>
                            </form>
                            <?php } ?>
                        </div>

                        <div class="card-body">
                            <ul class="nav nav-pills ep-tabs mb-3">
                                <li class="nav-item"><a class="nav-link <?=$epTab('New');?>"      href="?status=New">Pending <span class="badge badge-light"><?=(int)$epCounts['New'];?></span></a></li>
                                <li class="nav-item"><a class="nav-link <?=$epTab('Accepted');?>" href="?status=Accepted">Accepted <span class="badge badge-light"><?=(int)$epCounts['Accepted'];?></span></a></li>
                                <li class="nav-item"><a class="nav-link <?=$epTab('Imported');?>" href="?status=Imported">Registered <span class="badge badge-light"><?=(int)$epCounts['Imported'];?></span></a></li>
                                <li class="nav-item"><a class="nav-link <?=$epTab('Rejected');?>" href="?status=Rejected">Rejected <span class="badge badge-light"><?=(int)$epCounts['Rejected'];?></span></a></li>
                                <li class="nav-item"><a class="nav-link <?=$epTab('All');?>"      href="?status=All">All</a></li>
                            </ul>

                            <table id="epTable" class="table table-bordered table-hover">
                                <thead>
                                    <tr class="text-center">
                                        <th>UHID</th>
                                        <th>Patient Name</th>
                                        <th>Gender</th>
                                        <th>Dzongkhag</th>
                                        <th>Matched ICD-11</th>
                                        <th>Onset Date</th>
                                        <?php /* When it was pulled from ePIS is on the notification itself,
                                                 not here: it is provenance, not something anyone triages on,
                                                 and nine columns pushed Status and Action off the width. */ ?>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach($epRows as $r){
                                        $codes = array_filter(explode(',', (string)$r['matched_codes']));
                                        $dz    = $r['permanent_dzongkhag'] !== '' ? $r['permanent_dzongkhag'] : $r['present_dzongkhag'];
                                ?>
                                    <tr>
                                        <td><?=htmlspecialchars((string)$r['uhid'], ENT_QUOTES);?></td>
                                        <td>
                                            <?=htmlspecialchars((string)$r['patient_name'], ENT_QUOTES);?>
                                            <span class="ep-dx" title="<?=htmlspecialchars((string)$r['provisional_diagnosis'], ENT_QUOTES);?>"><?=htmlspecialchars((string)$r['provisional_diagnosis'], ENT_QUOTES);?></span>
                                        </td>
                                        <td class="text-center"><?=htmlspecialchars((string)$r['gender'], ENT_QUOTES);?></td>
                                        <td><?=htmlspecialchars((string)$dz, ENT_QUOTES);?></td>
                                        <td class="ep-codes">
                                            <?php foreach($codes as $c){ ?>
                                                <span class="badge badge-primary"><?=htmlspecialchars($c, ENT_QUOTES);?></span>
                                            <?php } ?>
                                        </td>
                                        <td class="text-center"><?=htmlspecialchars((string)$r['symptoms_onset_date'], ENT_QUOTES);?></td>
                                        <td class="text-center">
                                            <span class="badge <?=$epBadge($r['status']);?>"><?=htmlspecialchars((string)$r['status'], ENT_QUOTES);?></span>
                                            <?php if($r['status'] === 'Imported' && $r['linked_subject_id'] !== ''){ ?>
                                                <a class="d-block small" href="../records/case.php?action=2&sid=<?=urlencode((string)$r['linked_subject_id']);?>"><?=htmlspecialchars((string)$r['linked_subject_id'], ENT_QUOTES);?></a>
                                            <?php } ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="epis-view.php?id=<?=(int)$r['id'];?>" class="btn btn-sm bg-gradient-info" title="Open the full notification">
                                                <i class="fas fa-search"></i>&nbsp;<?=($r['status'] === 'New' && $epMayAct) ? 'Verify' : 'View';?>
                                            </a>
                                            <?php if($r['status'] === 'Accepted' && $epMayAct){ ?>
                                            <a href="../records/case.php?action=1&epis=<?=(int)$r['id'];?>" class="btn btn-sm bg-gradient-success" title="Register this case in BDSIS">
                                                <i class="fas fa-plus"></i>&nbsp;Register
                                            </a>
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
<!-- /.content-wrapper -->

<script>
$(function () {
    $('#epTable').DataTable({
        "paging": true, "lengthChange": true, "searching": true, "ordering": true,
        "info": true, "autoWidth": false, "responsive": true, "pageLength": 25,
        "order": [[ 5, "desc" ]],
        // Codes and Action are neither sortable nor searchable as text.
        "columnDefs": [ { "orderable": false, "searchable": false, "targets": 7 } ],
        "language": { "emptyTable": "No ePIS notifications in this list." }
    });

    <?php if($epFlash){ ?>
    Swal.fire({ icon: <?=json_encode($epFlash['ok'] ? 'success' : 'error');?>,
                text: <?=json_encode((string)$epFlash['message']);?>,
                showCloseButton: true, confirmButtonText: 'Close' });
    <?php } ?>

    /* Sync in place. A pull can take a while over the VPN, so the button reports
       that it is working and cannot be pressed twice -- a second sync would be
       harmless (the content hash makes it idempotent) but would look broken. */
    $('#epSyncForm').on('submit', function (e) {
        e.preventDefault();
        var $b = $('#epSyncBtn');
        if ($b.prop('disabled')) { return false; }
        $b.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>&nbsp;Contacting ePIS…');
        $.ajax({ url: 'epis-action.php', type: 'POST', dataType: 'json',
                 data: $('#epSyncForm').serialize(),
                 headers: { 'X-Requested-With': 'XMLHttpRequest' } })
         .done(function (r) {
            Swal.fire({ icon: r.ok ? 'success' : 'error', text: r.message,
                        showCloseButton: true, confirmButtonText: 'Close' })
                .then(function () { if (r.ok) { window.location.reload(); } });
         })
         .fail(function () {
            Swal.fire({ icon: 'error', showCloseButton: true,
                        text: 'The sync request did not complete. Check that the VPN is connected and try again.' });
         })
         .always(function () {
            $b.prop('disabled', false).html('<i class="fas fa-sync"></i>&nbsp;Sync from ePIS');
         });
        return false;
    });
});
</script>
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
