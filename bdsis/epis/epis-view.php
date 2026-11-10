<?php ob_start(); /* Buffered so a refused role gets a true 403 instead of a 200
     carrying a part-rendered page. */ ?>
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
  $epId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  $epRow = bdsis_epis_case($bdsis, $epId);
  if ($epRow === null) {
      bdsis_deny('That ePIS notification could not be found. It may have been removed.', 'view', 'records');
  }
  /* Authorise the ROW, not just the page: the id is in the URL and can be
     changed, exactly as a subject id can on records/case.php. */
  if (!bdsis_epis_can_see($epRow, $bdsis, $commondb)) {
      bdsis_deny('That ePIS notification belongs to another dzongkhag.', 'view', 'records');
  }

  $epMayAct  = bdsis_can('add', 'records') && $epRow['status'] === 'New';
  $epCodes   = array_filter(explode(',', (string)$epRow['matched_codes']));
  $epCatalog = bdsis_epis_active_codes();
  $epAge     = bdsis_epis_age_months($epRow['birth_date'],
                   $epRow['admission_date'] ?: $epRow['symptoms_onset_date']);

  /* One row of the notification. Blank fields are shown as an em dash rather
     than hidden -- on a verification screen, "ePIS did not send this" is
     information the verifier needs, not noise to tidy away. */
  $epField = function ($label, $value, $wide = false) {
      $v = trim((string)$value);
      $shown = $v === '' ? '<span class="text-muted">&mdash;</span>' : htmlspecialchars($v, ENT_QUOTES);
      echo '<div class="form-group col-12 ' . ($wide ? 'col-md-8' : 'col-md-4') . '">'
         . '<label class="col-form-label-sm">' . htmlspecialchars($label, ENT_QUOTES) . '</label>'
         . '<div class="ep-value">' . $shown . '</div></div>';
  };
?>

<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>
<style>
.ep-value       { min-height: 34px; padding: 6px 10px; background: #f6f8fa; border: 1px solid #dee2e6;
                  border-radius: 4px; font-size: 14px; word-break: break-word; }
.ep-value.tall  { min-height: 70px; white-space: pre-wrap; }
.ep-actionbar   { display: flex; align-items: center; padding: 12px 15px; border-top: 1px solid #dee2e6;
                  background: #fbfcfd; }
.ep-actionbar .ep-right { margin-left: auto; display: flex; gap: 8px; }
.ep-match       { font-size: 13px; }
.ep-match li    { margin-bottom: 2px; }
</style>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
    <?php require_once("../includes/top-navbar.php"); ?>
    <?php require_once("../includes/sidebar-menu.php"); ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>ePIS Notification</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="epis-management.php">ePIS Verification</a></li>
                        <li class="breadcrumb-item active">Notification</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if($epRow['status'] !== 'New'){ ?>
            <div class="alert <?=$epRow['status'] === 'Rejected' ? 'alert-secondary' : 'alert-info';?>">
                <h5 class="mb-1"><i class="icon fas fa-info"></i> Already <?=htmlspecialchars((string)$epRow['status'], ENT_QUOTES);?></h5>
                <?php if($epRow['status'] === 'Imported'){ ?>
                    Registered in BDSIS as
                    <a href="../records/case.php?action=2&sid=<?=urlencode((string)$epRow['linked_subject_id']);?>"><strong><?=htmlspecialchars((string)$epRow['linked_subject_id'], ENT_QUOTES);?></strong></a>
                    on <?=htmlspecialchars(substr((string)$epRow['imported_on'], 0, 16), ENT_QUOTES);?>
                    by <?=htmlspecialchars((string)$epRow['imported_by'], ENT_QUOTES);?>.
                <?php } else { ?>
                    Marked <?=htmlspecialchars(strtolower((string)$epRow['status']), ENT_QUOTES);?>
                    on <?=htmlspecialchars(substr((string)$epRow['decided_on'], 0, 16), ENT_QUOTES);?>
                    by <?=htmlspecialchars((string)$epRow['decided_by'], ENT_QUOTES);?>.
                    <?php if(trim((string)$epRow['decision_reason']) !== ''){ ?>
                        <div class="mt-1"><em><?=htmlspecialchars((string)$epRow['decision_reason'], ENT_QUOTES);?></em></div>
                    <?php } ?>
                <?php } ?>
            </div>
            <?php } ?>

            <!-- Why this notification is here at all. Put first, because it is
                 the one thing the verifier is being asked to confirm. -->
            <div class="card card-outline card-primary">
                <div class="card-header"><h3 class="card-title" style="font-weight:600">Why this was flagged</h3></div>
                <div class="card-body">
                    <?php if($epCodes){ ?>
                    <ul class="ep-match mb-2">
                        <?php foreach($epCodes as $c){ ?>
                        <li><span class="badge badge-primary"><?=htmlspecialchars($c, ENT_QUOTES);?></span>
                            <?=htmlspecialchars(isset($epCatalog[$c]) ? $epCatalog[$c] : '', ENT_QUOTES);?></li>
                        <?php } ?>
                    </ul>
                    <small class="text-muted">Matched on the whole ICD&#8209;11 code, not on part of it, against the
                    diagnosis and notifiable-disease fields below.</small>
                    <?php } else { ?>
                    <span class="text-muted">No matched code recorded.</span>
                    <?php } ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title" style="font-weight:600">Patient</h3></div>
                <div class="card-body"><div class="row">
                    <?php
                      $epField('UHID', $epRow['uhid']);
                      $epField('Patient Name', $epRow['patient_name']);
                      $epField('Gender', $epRow['gender']);
                      $epField('Date of Birth', $epRow['birth_date']);
                      $epField('Age at encounter', $epAge === null ? '' : $epAge . ' month(s)');
                      $epField('Contact Number', $epRow['mobile_no']);
                      $epField('Permanent Dzongkhag', $epRow['permanent_dzongkhag']);
                      $epField('Present Dzongkhag', $epRow['present_dzongkhag']);
                      $epField('Dzongkhag code (BDSIS)', $epRow['dzongkhag_code']);
                    ?>
                </div></div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title" style="font-weight:600">Clinical</h3></div>
                <div class="card-body"><div class="row">
                    <?php
                      $epField('Symptoms Onset Date', $epRow['symptoms_onset_date']);
                      $epField('Admission Date', $epRow['admission_date']);
                      $epField('Hospitalised', $epRow['is_hospitalized']);
                      $epField('Hospital', $epRow['hospital_name']);
                      $epField('Outcome', $epRow['outcome']);
                      $epField('Outcome Date', $epRow['outcome_date']);
                    ?>
                    <div class="form-group col-12 col-md-6">
                        <label class="col-form-label-sm">Provisional Diagnosis</label>
                        <div class="ep-value tall"><?=htmlspecialchars((string)$epRow['provisional_diagnosis'], ENT_QUOTES) ?: '<span class="text-muted">&mdash;</span>';?></div>
                    </div>
                    <div class="form-group col-12 col-md-6">
                        <label class="col-form-label-sm">Notifiable Diseases</label>
                        <div class="ep-value tall"><?=htmlspecialchars((string)$epRow['notifiable_diseases'], ENT_QUOTES) ?: '<span class="text-muted">&mdash;</span>';?></div>
                    </div>
                    <div class="form-group col-12 col-md-6">
                        <label class="col-form-label-sm">Other Chief Complaint</label>
                        <div class="ep-value tall"><?=htmlspecialchars((string)$epRow['other_chief_complaint'], ENT_QUOTES) ?: '<span class="text-muted">&mdash;</span>';?></div>
                    </div>
                    <div class="form-group col-12 col-md-6">
                        <label class="col-form-label-sm">Laboratory Results (as reported to ePIS)</label>
                        <div class="ep-value tall"><?=htmlspecialchars((string)$epRow['laboratory_results'], ENT_QUOTES) ?: '<span class="text-muted">&mdash;</span>';?></div>
                    </div>
                </div></div>

                <!-- Cancel left, the decision on the right: the same action bar
                     shape the Lab and Records forms use. -->
                <div class="ep-actionbar">
                    <a href="epis-management.php" class="btn bg-gradient-secondary">Cancel</a>
                    <?php if($epMayAct){ ?>
                    <div class="ep-right">
                        <button type="button" class="btn bg-gradient-danger" id="epRejectBtn">
                            <i class="fas fa-times"></i>&nbsp;Reject
                        </button>
                        <button type="button" class="btn bg-gradient-success" id="epAcceptBtn">
                            <i class="fas fa-check"></i>&nbsp;Accept &amp; Register Case
                        </button>
                    </div>
                    <?php } elseif($epRow['status'] === 'Accepted' && bdsis_can('add', 'records')){ ?>
                    <div class="ep-right">
                        <a class="btn bg-gradient-success" href="../records/case.php?action=1&epis=<?=(int)$epRow['id'];?>">
                            <i class="fas fa-plus"></i>&nbsp;Register Case
                        </a>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <?php if(bdsis_can('add', 'records')){ ?>
            <!-- The untouched payload, for anyone reconciling a disagreement with
                 ePIS. Collapsed: it is evidence, not part of the normal read. -->
            <div class="card collapsed-card">
                <div class="card-header">
                    <h3 class="card-title" style="font-weight:600">Original ePIS payload</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <div class="card-body">
                    <pre style="max-height:320px;overflow:auto;font-size:12px"><?php
                        $raw = json_decode((string)$epRow['raw_payload'], true);
                        echo htmlspecialchars(is_array($raw)
                            ? json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : (string)$epRow['raw_payload'], ENT_QUOTES);
                    ?></pre>
                    <small class="text-muted">
                        Received <?=htmlspecialchars(substr((string)$epRow['synced_on'], 0, 16), ENT_QUOTES);?>,
                        last seen in the feed <?=htmlspecialchars(substr((string)$epRow['last_seen_on'], 0, 16), ENT_QUOTES);?>.
                    </small>
                </div>
            </div>
            <?php } ?>

            <?php if($epMayAct){ ?>
            <form method="post" action="epis-action.php" id="epDecisionForm" class="d-none">
                <?=bdsis_csrf_field();?>
                <input type="hidden" name="id" value="<?=(int)$epRow['id'];?>">
                <input type="hidden" name="do" id="epDo" value="">
                <input type="hidden" name="reason" id="epReason" value="">
            </form>
            <?php } ?>

        </div>
    </section>
</div>
<!-- /.content-wrapper -->

<?php if($epMayAct){ ?>
<script>
$(function () {
    /* Both decisions are final, so both are confirmed first, and the buttons
       lock while the request is in flight -- a double-click must not be able to
       submit a second decision. */
    function send(what, reason) {
        $('#epAcceptBtn, #epRejectBtn').prop('disabled', true);
        $('#epDo').val(what);
        $('#epReason').val(reason || '');
        $.ajax({ url: 'epis-action.php', type: 'POST', dataType: 'json',
                 data: $('#epDecisionForm').serialize(),
                 headers: { 'X-Requested-With': 'XMLHttpRequest' } })
         .done(function (r) {
            if (r.ok && r.redirect) { window.location = r.redirect; return; }
            Swal.fire({ icon: r.ok ? 'success' : 'error', text: r.message,
                        showCloseButton: true, confirmButtonText: 'Close' })
                .then(function () { if (r.ok) { window.location = 'epis-management.php'; } });
         })
         .fail(function () {
            Swal.fire({ icon: 'error', showCloseButton: true,
                        text: 'The decision could not be saved. Please try again.' });
         })
         .always(function () { $('#epAcceptBtn, #epRejectBtn').prop('disabled', false); });
    }

    $('#epAcceptBtn').on('click', function () {
        Swal.fire({
            icon: 'question',
            title: 'Accept this notification?',
            text: 'The case registration form will open with the ePIS details filled in. '
                + 'Nothing is added to BDSIS until you save it.',
            showCancelButton: true, confirmButtonText: 'Accept', cancelButtonText: 'Cancel'
        }).then(function (res) { if (res.value) { send('accept'); } });
    });

    $('#epRejectBtn').on('click', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Reject this notification?',
            input: 'text',
            inputLabel: 'Reason (recorded against the notification)',
            inputPlaceholder: 'e.g. duplicate of an existing case, or not a diarrhoeal case',
            showCancelButton: true, confirmButtonText: 'Reject', cancelButtonText: 'Cancel',
            inputValidator: function (v) {
                if (!v || !v.trim()) { return 'Please give a reason.'; }
            }
        }).then(function (res) { if (res.value) { send('reject', res.value); } });
    });
});
</script>
<?php } ?>
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
