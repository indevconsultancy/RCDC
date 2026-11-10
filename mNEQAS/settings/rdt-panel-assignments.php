<!-- Check session -->
<?php
require_once("../config/session.php");
require_once("../config/auth.php");
mn_require_rcdc();
?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");
?>
<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<style>
  #table {
    table-layout: fixed;
    width: 100% !important;
  }
  #table th:nth-child(5),
  #table td:nth-child(5) {
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
    word-break: break-all !important;
    white-space: normal !important;
    max-width: 280px;
    min-width: 180px;
  }
  #table .slide-no-cell {
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
    word-break: break-all !important;
    white-space: normal !important;
  }
</style>

<?php
$roundsql = "SELECT round_id, panel_round, total_panels FROM rdt_panel_round_informations ORDER BY panel_round DESC";
$dzsql    = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
?>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <?php require_once("../includes/top-navbar.php"); ?>
  <?php require_once("../includes/sidebar-menu.php"); ?>

  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>RDT Panel Assignments</h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item active">Settings | RDT Panel Assignments</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <!-- Search -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-body">
                <form class="form-horizontal" autocomplete="off" method="Post" action="rdt-panel-assignments.php">
                  <div class="form-group row">
                    <div class="col-sm-3">
                      <select class="form-control" name="round" id="round">
                        <option value="">All Round</option>
                        <?php
                          $result = $conn->query($roundsql);
                          while ($row = $result->fetch_row()) {
                            echo "<option value='{$row[1]}'>{$row[1]}</option>";
                          }
                        ?>
                      </select>
                    </div>
                    <div class="col-sm-3">
                      <select class="form-control" name="dzongkhag" id="dzongkhag">
                        <option value="">Select dzongkhag</option>
                        <?php
                          $result = $commondb->query($dzsql);
                          while ($row = $result->fetch_row()) {
                            echo "<option value='{$row[0]}'>{$row[1]}</option>";
                          }
                        ?>
                      </select>
                    </div>
                    <div id="facilityDiv" class="col-sm-3">
                      <select class="form-control" name="facility" id="facility">
                        <option value="">Select facility</option>
                      </select>
                    </div>
                    <div class="col-sm-3">
                      <button type="submit" name="submit" class="btn bg-gradient-success"><i class="fa fa-search"></i>&nbsp;Search</button>
                      <a href="rdt-panel-assignments.php" class="btn bg-gradient-secondary"><i class="fa fa-sync"></i>&nbsp;Reset</a>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Table -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-body">
                <table id="table" class="table table-bordered table-hover">
                  <thead>
                    <tr class="text-center">
                      <th>ID</th>
                      <th>Panel No</th>
                      <th>Round</th>
                      <th>Dzongkhag</th>
                      <th>Facility</th>
                      <th>Slide No</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    $sql = "SELECT t1.panel_id, t1.panel_number, t2.panel_round, t1.facility_id
                            FROM rdt_panel_informations t1
                            INNER JOIN rdt_panel_round_informations t2 ON t1.round_id = t2.round_id
                            INNER JOIN rcdc_commondb.ms_health_center_table t3 ON t1.facility_id = t3.center_id
                            WHERE 1=1";

                    if (isset($_POST['round']) && $_POST['round'] != "") {
                      $round = $conn->real_escape_string($_POST['round']);
                      $sql .= " AND t2.panel_round=$round";
                    }
                    if (isset($_POST['dzongkhag']) && $_POST['dzongkhag'] != "") {
                      $dzongkhag = $conn->real_escape_string($_POST['dzongkhag']);
                      $sql .= " AND t3.dzongkhag_code=$dzongkhag";
                    }
                    if (isset($_POST['facility']) && $_POST['facility'] != "") {
                      $facility = $conn->real_escape_string($_POST['facility']);
                      $sql .= " AND t1.facility_id=$facility";
                    }

                    $result = $conn->query($sql);
                    while ($row = $result->fetch_row()) {
                      $fd = $commondb->query("
                        SELECT t2.dzongkhag_name, t1.center_name
                        FROM ms_health_center_table t1
                        INNER JOIN ms_dzongkhag_table t2 ON t1.dzongkhag_code = t2.dzongkhag_code
                        WHERE t1.center_id='{$row[3]}'
                      ");
                      $fd = $fd->fetch_row();
                      $slidesArr = [];
                      $slide = $conn->query("SELECT slide_number FROM rdt_panel_slide_informations WHERE panel_id='{$row[0]}' ORDER BY slide_id ASC");
                      if ($slide) {
                        while ($data = $slide->fetch_row()) {
                          $sVal = trim($data[0] ?? '');
                          if ($sVal !== '') {
                            $slidesArr[] = $sVal;
                          }
                        }
                      }
                      $slidestr = implode(';', $slidesArr);
                      $pno = $row[1] !== null && $row[1] !== '' ? htmlspecialchars($row[1]) : '-';
                      echo "<tr>
                        <td class='text-center'><a href='rdt-panel-assignments.php?id={$row[0]}'>{$row[0]}</a></td>
                        <td class='text-center'>{$pno}</td>
                        <td class='text-center'>{$row[2]}</td>
                        <td>{$fd[0]}</td>
                        <td>{$fd[1]}</td>
                        <td class='slide-no-cell'>{$slidestr}</td>
                      </tr>";
                    }
                  ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Add/Update -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-body">
                <div class="form-group row">
                  <label class="col-sm-12 text-primary col-form-label">Add/Update RDT Panel Assignment</label>
                </div>
                <form class="form-horizontal" id="form" autocomplete="off" method="Post" action="javascript:void(0);">
                <?php
                  $id = '';
                  $originalFacility = '';
                  if (isset($_GET["id"]) && $_GET["id"] !== "") {
                    $id = $_GET["id"];
                    // panel_id, panel_number, panel_round, center_id, center_name, dz_code, dz_name, round_id, total_panels
                    $dataQ = $conn->query("
                      SELECT
                        t1.panel_id,
                        t1.panel_number,
                        t4.panel_round,
                        t2.center_id,
                        t2.center_name,
                        t3.dzongkhag_code,
                        t3.dzongkhag_name,
                        t4.round_id,
                        t4.total_panels
                      FROM rdt_panel_informations t1
                      INNER JOIN rcdc_commondb.ms_health_center_table t2 ON t1.facility_id = t2.center_id
                      INNER JOIN rcdc_commondb.ms_dzongkhag_table t3 ON t2.dzongkhag_code = t3.dzongkhag_code
                      INNER JOIN rdt_panel_round_informations t4 ON t1.round_id = t4.round_id
                      WHERE t1.panel_id = '$id'
                    ");
                    $data = $dataQ ? $dataQ->fetch_row() : null;
                    if (!$data) {
                      $data = array('', '', '', '', '', '', '', '', 10);
                    }
                    $originalFacility = $data[3];
                    $delete = "<a href='delete.php?id=$id&type=rpa' class='btn bg-gradient-danger'>Delete</a>";
                    echo "<input type='hidden' name='action' id='action' value='2'>";
                  } else {
                    $data = array('', '', '', '', '', '', '', '', 10);
                    $delete = "";
                    echo "<input type='hidden' name='action' id='action' value='1'>";
                  }
                ?>
                  <input type="hidden" name="id" id="id" value="<?= htmlspecialchars($id); ?>">
                  <input type="hidden" name="original_facility" id="original_facility" value="<?= htmlspecialchars($originalFacility); ?>">

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Panel round(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="pround" id="pround">
                        <option value="">Select Panel Round</option>
                        <?php
                          $result = $conn->query($roundsql);
                          while ($row = $result->fetch_row()) {
                            $selected = ($data[7] != "" && $row[0] == $data[7]) ? " selected" : "";
                            $totPanels = (!empty($row[2]) && (int)$row[2] > 0) ? (int)$row[2] : 10;
                            echo "<option value='{$row[0]}' data-total-panels='{$totPanels}'{$selected}>{$row[1]}</option>";
                          }
                        ?>
                      </select>
                    </div>
                    <div class="col-sm-3">
                      <input type="hidden"
                             name="panel_number"
                             id="panel_number"
                             class="form-control"
                             value="<?= htmlspecialchars($data[1] ?? ''); ?>"
                             readonly
                             required
                             placeholder="Auto-generated">
                    </div>
                  </div>

                  <div class="form-group row">
                    <label class="col-sm-3 col-form-label-sm">Dzongkhag(*)</label>
                    <div class="col-sm-3">
                      <select class="form-control" name="dzongkhag2" id="dzongkhag2">
                        <option value="">Select Dzongkhag</option>
                        <?php
                          $result = $commondb->query($dzsql);
                          while ($row = $result->fetch_row()) {
                            $selected = ($data[5] != "" && $row[0] == $data[5]) ? " selected" : "";
                            echo "<option value='{$row[0]}'{$selected}>{$row[1]}</option>";
                          }
                        ?>
                      </select>
                    </div>
                    <label class="col-sm-3 col-form-label-sm">Health facility(*)</label>
                    <div id="facility2Div" class="col-sm-3">
                      <select class="form-control" name="facility" id="facility2">
                      <?php if ($data[3] != "") { ?>
                        <option value="<?= htmlspecialchars($data[3]); ?>" selected><?= htmlspecialchars($data[4]); ?></option>
                      <?php } else { ?>
                        <option value="">Select Health Facility</option>
                      <?php } ?>
                      </select>
                    </div>
                  </div>

                  <div id="panelsDiv">
                  <?php
                    if (isset($_GET["id"]) && $id !== "") {
                      $targetPanels = (!empty($data[8]) && (int)$data[8] > 0) ? (int)$data[8] : 10;
                      $slideQ = $conn->query("SELECT slide_id, slide_number FROM rdt_panel_slide_informations WHERE panel_id='$id' ORDER BY slide_id ASC");
                      $savedSlides = [];
                      if ($slideQ) {
                        while ($sRow = $slideQ->fetch_assoc()) {
                          $savedSlides[] = $sRow;
                        }
                      }
                      for ($i = 1; $i <= $targetPanels; $i++) {
                        $currSlide = $savedSlides[$i - 1] ?? null;
                        $sId = $currSlide['slide_id'] ?? '';
                        $sNo = $currSlide['slide_number'] ?? '';
                  ?>
                    <div class="form-group row panel-row" data-panel-index="<?= $i; ?>">
                      <label class="col-sm-3 col-form-label-sm">Panel No. <?= $i; ?> (*)</label>
                      <div class="col-sm-3">
                        <?php if ($sId !== '') { ?>
                        <input type="hidden" name="slideid[]" value="<?= htmlspecialchars($sId); ?>">
                        <?php } ?>
                        <input type="text" name="slideno[]" class="form-control" value="<?= htmlspecialchars($sNo); ?>" readonly required autocomplete="off">
                      </div>
                    </div>
                  <?php
                      }
                    }
                  ?>
                  </div>

                  <div class="form-group row">
                    <div class="col-sm-12">
                      <button type="submit" class="btn bg-gradient-success">Submit</button>
                      <?= $delete; ?>
                      <a href="rdt-panel-assignments.php" class="btn bg-gradient-secondary float-right">Cancel</a>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

<script>
// bfcache / back-forward navigation safety: when the browser restores this
// page from the back-forward cache the DOM comes back frozen from the moment
// it left — the submit button can still be `disabled` and text "Saving...",
// and the in-flight `rdtSubmitting` flag can still be `true`. That would
// silently swallow the user's next submit attempt. Force a clean interactive
// state on both fresh load and bfcache restore. NOTE: we deliberately do NOT
// call form.reset() because Update mode pre-populates fields from the DB in
// PHP and blanking them here would erase the preserved slide numbers.
function rdtResetFormState() {
  if (typeof rdtSubmitting !== 'undefined') { rdtSubmitting = false; }
  $('#form button[type="submit"]').prop('disabled', false).text('Submit');
}
$(window).on('pageshow', function (evt) {
  if (evt.originalEvent && evt.originalEvent.persisted) { rdtResetFormState(); }
});

$(document).ready(function () {

  // Reset any stale in-flight state on fresh load too (defensive).
  rdtResetFormState();

  $('#form').validate({
    rules: {
      pround: "required",
      dzongkhag2: "required",
      facility: "required",
    },
    errorElement: 'span',
    errorPlacement: function (error, element) {
      error.addClass('invalid-feedback');
      element.closest('.form-group').append(error);
    },
    highlight: function (element) {
      $(element).addClass('is-invalid');
    },
    unhighlight: function (element) {
      $(element).removeClass('is-invalid');
    }
  });
  // AJAX form submit — atomic per user click.
  //
  // Two bugs were producing a false "duplicate" after a successful save:
  //   (a) the success handler's `.then()` was empty, so after the "Submitted
  //       Successfully!!" alert closed the user was left on the same page with
  //       the form still populated. Clicking Submit again ran the pre-check
  //       against the row that had just been inserted → "already exists".
  //   (b) a rapid double-click could fire the submit event twice before
  //       `beforeSend` disabled the button. The first call inserted, the
  //       second's pre-check saw the newly-committed row → "already exists".
  //
  // Fix: re-entrancy flag guarantees at most one in-flight request per user
  // gesture, and the success SweetAlert's `.then()` redirects to the list
  // page (same behavior as Micro's iu-panel-assignments.php's SweetAlert
  // response) so re-submission of the same combination is impossible.
  var rdtSubmitting = false;
  $('#form').on('submit', function (e) {
    e.preventDefault();

    // Run jquery validation first
    if (!$('#form').valid()) {
        return false;
    }

    var $slideInputs = $('#form').find('input[name="slideno[]"]');
    if ($slideInputs.length === 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Validation Error',
        text: 'Slide numbers have not been generated. Please select Round, Dzongkhag and Health Facility.',
        confirmButtonText: 'OK'
      });
      return false;
    }
    var anySlideBlank = false;
    $slideInputs.each(function () {
      if (!$.trim($(this).val())) {
        $(this).addClass('is-invalid');
        anySlideBlank = true;
      }
    });
    if (anySlideBlank) {
      Swal.fire({
        icon: 'warning',
        title: 'Validation Error',
        text: 'All panel slide numbers must be generated before submitting.',
        confirmButtonText: 'OK'
      });
      return false;
    }

    // Re-entrancy guard — belt-and-braces alongside beforeSend button-disable.
    if (rdtSubmitting) return false;
    rdtSubmitting = true;

    var formData = $(this).serialize();

    $.ajax({
        url: 'iu-rdt-panel-assignments.php',
        type: 'POST',
        data: formData,
        dataType: 'json',
        beforeSend: function () {
            $('#form button[type="submit"]').prop('disabled', true).text('Saving...');
        },
        success: function (res) {
            // Server returns {status: 'success'|'error', message: '...'}.
            // Success → green icon + Micro-style SweetAlert + redirect to list.
            // Anything else is a validation/duplicate/business-rule refusal —
            // shown as a warning, not a red-X error (the row wasn't created,
            // nothing broke; the user just needs to correct their input).
            if (res && res.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    confirmButtonText: 'Close',
                    text: res.message || 'RDT Panel Assignment saved successfully.',
                    showCloseButton: true
                }).then(function () {
                    // Redirect to the assignment list after successful commit —
                    // matches iu-panel-assignments.php's SweetAlert behavior and
                    // prevents accidental re-submission of the same combination.
                    window.location = 'rdt-panel-assignments.php';
                });
            } else {
                Swal.fire({
                    icon: 'warning',
                    confirmButtonText: 'Close',
                    text: (res && res.message) ? res.message : 'Submission Failed!!',
                    showCloseButton: true
                });
            }
        },
        error: function (xhr) {
            // True server/network failure (HTTP 4xx/5xx). Try to surface the
            // JSON `message` if the server produced one, otherwise a generic
            // note. Red error icon is reserved for this branch only.
            var msg = 'Something went wrong.';
            try {
                var res = JSON.parse(xhr.responseText);
                if (res && res.message) msg = res.message;
            } catch (e) {}
            Swal.fire({
                icon: 'error',
                confirmButtonText: 'Close',
                text: msg,
                showCloseButton: true
            });
        },
        complete: function () {
            rdtSubmitting = false;
            $('#form button[type="submit"]').prop('disabled', false).text('Submit');
        }
    });
  });

  $(document).on('change', '#dzongkhag', function () {
    var dzongkhag = $(this).val() || '';
    $("#facilityDiv").load("../load-facility.php?dzongkhag=" + encodeURIComponent(dzongkhag));
  });

  $(document).on('change', '#dzongkhag2', function () {
    var dzongkhag = $(this).val() || '';

    $("#facility2Div").html(
      '<select class="form-control" name="facility" id="facility2">' +
      '<option value="">Select Health Facility</option>' +
      '</select>'
    );

    // Add mode: clear panel number until facility chosen again
    if ($('#action').val() === '1') {
      $('#panel_number').val('');
    }

    if (!dzongkhag) {
      return;
    }

    $("#facility2Div").load(
      "../load-facility.php?dzongkhag=" + encodeURIComponent(dzongkhag) + "&id=facility2",
      function (response, status, xhr) {
        if (status === "error") {
          Swal.fire({
            icon: 'error',
            title: 'Facility Loading Failed',
            text: 'Could not load health facilities.',
            confirmButtonText: 'Close'
          });
          return;
        }
        fetchRdtPanelNumber();
        fetchRdtSlideSerials();
      }
    );
  });

  $("#pround").change(function () {
    var round = $(this).val();

    if (!round) {
      $("#panelsDiv").empty();
      if ($('#action').val() === '1') {
        $('#panel_number').val('');
      }
      return;
    }

    var $selected = $(this).find(':selected');
    var totalPanels = parseInt($selected.data('total-panels'), 10);

    var applyRoundPanels = function (count) {
      if ($('#action').val() === '1') {
        $("#panelsDiv").load(
          "load-rdt-panels.php?round=" + encodeURIComponent(round),
          function (response, status, xhr) {
            if (status === "error") {
              console.error("Panel loading failed:", xhr.status, xhr.statusText);
              $("#panelsDiv").empty();
              syncPanelInputs(count);
            }
            fetchRdtPanelNumber();
            fetchRdtSlideSerials();
          }
        );
      } else {
        // Edit mode: dynamically adjust fields to match the selected round's total_panels
        syncPanelInputs(count);
        fetchRdtPanelNumber();
        fetchRdtSlideSerials();
      }
    };

    if (!isNaN(totalPanels) && totalPanels > 0) {
      applyRoundPanels(totalPanels);
    } else {
      // Fallback: fetch round details from endpoint
      $.ajax({
        url: "get-round-details.php",
        type: "GET",
        dataType: "json",
        data: { round_id: round },
        success: function (res) {
          var cnt = (res && res.ok && res.total_panels) ? parseInt(res.total_panels, 10) : 10;
          $selected.attr('data-total-panels', cnt);
          applyRoundPanels(cnt);
        },
        error: function () {
          applyRoundPanels(10);
        }
      });
    }
  });

  $('#form').on('change', 'select[name="facility"]', function () {
    fetchRdtPanelNumber();
    // Slide-serial regeneration in Update mode must NOT fire when the facility
    // is unchanged — otherwise re-selecting the same facility (which is easy to
    // do after the Dzongkhag dropdown clears + reloads Facility) would silently
    // renumber existing slides. Mirrors the guard already used by
    // fetchRdtPanelNumber() for the panel number itself.
    var action = $('#action').val();
    var facility = $(this).val();
    var originalFacility = $('#original_facility').val() || '';
    if (action === '2' && originalFacility !== '' && String(facility) === String(originalFacility)) {
      return;
    }
    fetchRdtSlideSerials();
  });

  // Update page load: keep existing panel number (do not force regenerate)
  // Only slides/panel number refresh if user changes facility/round later.
});

/**
 * Panel number: Add always regenerate when Round+Facility ready.
 * Update: keep existing unless facility changed from original.
 */
function fetchRdtPanelNumber() {
  var $form     = $('#form');
  var action    = $('#action').val();
  var facility  = $form.find('select[name="facility"]').val();
  var round     = $form.find('select[name="pround"]').val();
  var dzongkhag = $form.find('select[name="dzongkhag2"]').val();
  var panelId   = $('#id').val() || '';
  var originalFacility = $('#original_facility').val() || '';
  var currentVal = $('#panel_number').val() || '';

  if (!dzongkhag || !facility || !round) {
    return;
  }

  // Update + same facility + already have number → keep stable
  if (action === '2' && originalFacility !== '' && String(facility) === String(originalFacility) && currentVal !== '') {
    return;
  }

  $.ajax({
    url: "get-rdt-panel-number.php",
    type: "GET",
    dataType: "json",
    data: {
      dzongkhag: dzongkhag,
      facility: facility,
      round: round,
      id: panelId
    },
    success: function (res) {
      if (res && res.ok === true && res.panel_number) {
        $('#panel_number')
          .val(res.panel_number)
          .prop('readonly', true)
          .removeClass('is-invalid');
      } else {
        Swal.fire({
          icon: 'warning',
          title: 'Panel Number Generation Failed',
          text: (res && res.msg) ? res.msg : 'Could not generate panel number.',
          confirmButtonText: 'Close'
        });
      }
    },
    error: function (xhr, status, error) {
      console.error("get-rdt-panel-number.php error:", xhr.status, error, xhr.responseText);
      Swal.fire({
        icon: 'error',
        title: 'Panel Number Service Error',
        text: 'HTTP ' + xhr.status + ' – check Network tab for get-rdt-panel-number.php',
        confirmButtonText: 'Close'
      });
    }
  });
}

function syncPanelInputs(targetCount) {
  targetCount = parseInt(targetCount, 10);
  if (isNaN(targetCount) || targetCount <= 0) targetCount = 10;

  var $panelsDiv = $("#panelsDiv");
  var $existingRows = $panelsDiv.find(".panel-row");
  var currentCount = $existingRows.length;

  if (currentCount > targetCount) {
    // Remove excess fields beyond targetCount
    $existingRows.slice(targetCount).remove();
  } else if (currentCount < targetCount) {
    // Append missing fields up to targetCount
    for (var i = currentCount + 1; i <= targetCount; i++) {
      var rowHtml = '<div class="form-group row panel-row" data-panel-index="' + i + '">' +
          '<label class="col-sm-3 col-form-label-sm">Panel No. ' + i + ' (*)</label>' +
          '<div class="col-sm-3">' +
            '<input type="text" name="slideno[]" class="form-control" value="" readonly required autocomplete="off">' +
          '</div>' +
        '</div>';
      $panelsDiv.append(rowHtml);
    }
  }

  // Renumber labels and data attributes
  $panelsDiv.find(".panel-row").each(function (idx) {
    var num = idx + 1;
    $(this).attr('data-panel-index', num);
    $(this).find('label').text('Panel No. ' + num + ' (*)');
  });
}

function fetchRdtSlideSerials() {
  var $form     = $('#form');
  var facility  = $form.find('select[name="facility"]').val();
  var round     = $form.find('select[name="pround"]').val();
  var dzongkhag = $form.find('select[name="dzongkhag2"]').val();
  var panelId   = $('#id').val() || '';
  var slideCount = $form.find('input[name="slideno[]"]').length;

  if (!dzongkhag || !facility || !round || slideCount <= 0) {
    return;
  }

  $.ajax({
    url: "get-rdt-slide-serial.php",
    type: "GET",
    dataType: "json",
    data: {
      dzongkhag: dzongkhag,
      facility: facility,
      round: round,
      id: panelId,
      count: slideCount
    },
    success: function (res) {
      if (res && res.ok === true && Array.isArray(res.slides)) {
        if (res.slides.length !== $form.find('input[name="slideno[]"]').length) {
          syncPanelInputs(res.slides.length);
        }
        var $inputs = $form.find('input[name="slideno[]"]');
        $inputs.each(function (index) {
          if (res.slides[index] !== undefined) {
            $(this)
              .val(res.slides[index])
              .prop('readonly', true)
              .removeClass('is-invalid');
          }
        });
      } else {
        Swal.fire({
          icon: 'warning',
          title: 'Slide Number Generation Failed',
          text: (res && res.msg) ? res.msg : 'Could not generate slide numbers.',
          confirmButtonText: 'Close'
        });
      }
    },
    error: function (xhr, status, error) {
      console.error("get-rdt-slide-serial.php error:", xhr.status, error, xhr.responseText);
      Swal.fire({
        icon: 'error',
        title: 'Slide Number Service Error',
        text: 'HTTP ' + xhr.status + ' – check Network tab for get-rdt-slide-serial.php',
        confirmButtonText: 'Close'
      });
    }
  });
}

$(function () {
  $('#table').DataTable({
    paging: true,
    lengthChange: false,
    searching: false,
    ordering: true,
    info: true,
    autoWidth: false,
    responsive: true,
    order: [[0, "desc"]]
  });
});
</script>

<?php require_once("../includes/footer.php"); ?>
