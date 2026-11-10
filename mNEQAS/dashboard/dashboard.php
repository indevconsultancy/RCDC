<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<?php
	/* SECURITY: the authenticated role comes ONLY from the session established at
	   login. A prior `?par=<base64>` backdoor here decoded a URL parameter and
	   overwrote $_SESSION["roleID"], letting any logged-in user self-promote to
	   Administrator. It has been removed — role must never come from the URL.
	   (Verified: nothing in the app links to dashboard.php?par=.) */
?>
<?php
// AJAX partial: return year-scoped analytics cards + breakdown tables for the chosen
// year (used by the header Year dropdown). Must run before any page output.
if (isset($_GET['ajax_year'])) {
    require_once("../config/db-config.php");
    $sel_year = (int)$_GET['ajax_year'];
    require __DIR__ . "/dashboard-analytics.php";
    exit;
}
?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php require_once("../config/db-config.php"); ?>
<!-- CSS-Jquery -->
<?php require_once("../includes/css-jquery.php"); ?>
<!-- Highcharts -->
<?php require_once("../includes/highcharts.php"); ?>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <!-- Top Navbar -->
  <?php require_once("../includes/top-navbar.php"); ?>
  <!-- Main Sidebar Container -->
  <?php require_once("../includes/sidebar-menu.php"); ?>

  <?php
    // Dynamic Year range: 2016 to current system year
    $currentSystemYear = (int)date("Y");
    $startYear = 2016;
    $dashYears = array();
    for ($y = $currentSystemYear; $y >= $startYear; $y--) {
        $dashYears[] = $y;
    }

    // Selected year drives analytics cards; defaults to current year, retains active selection on reload
    $sel_year = isset($_GET['year']) ? (int)$_GET['year'] : (isset($_GET['ajax_year']) ? (int)$_GET['ajax_year'] : $currentSystemYear);
    if ($sel_year < 2016 || $sel_year > $currentSystemYear + 5) {
        $sel_year = $currentSystemYear;
    }
  ?>
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2 align-items-center">
          <div class="col-sm-6">
            <h1 class="m-0">Dashboard</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <div class="d-flex align-items-center justify-content-sm-end flex-wrap">
              <!-- Global Dynamic Year filter: refreshes summary cards & breakdown tables -->
              <div class="form-inline mr-3 my-1">
                <label for="dashYear" class="mr-2 mb-0 font-weight-bold text-muted small">YEAR</label>
                <select id="dashYear" class="form-control form-control-sm" style="min-width:110px;">
                  <?php foreach ($dashYears as $yr) {
                        echo '<option value="' . (int)$yr . '"'
                           . (($yr == $sel_year) ? ' selected' : '') . '>'
                           . (int)$yr . '</option>';
                  } ?>
                </select>
              </div>
              <ol class="breadcrumb bg-transparent p-0 m-0">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
              </ol>
            </div>
          </div><!-- /.col -->
        </div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Dashboard Summary Cards & Analytics Styles -->
    <style>
      #dashStats .small-box{
        position:relative; overflow:hidden;
        background:#fff !important;
        border:1px solid #e8edf3 !important;
        border-radius:12px !important;
        box-shadow:0 1px 3px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04) !important;
        transition:transform .2s ease, box-shadow .2s ease !important;
        height:calc(100% - 20px);
      }
      #dashStats .small-box:hover{
        transform:translateY(-4px);
        box-shadow:0 12px 26px rgba(15,23,42,.13) !important;
      }
      #dashStats .small-box .inner{
        position:relative; z-index:2; padding:18px 20px; padding-right:80px;
      }
      #dashStats .small-box .inner b{
        display:block; font-size:.78rem; font-weight:600; text-transform:uppercase;
        letter-spacing:.5px; color:#64748b; line-height:1.3; margin-bottom:6px;
        white-space:normal; overflow-wrap:anywhere;
      }
      #dashStats .small-box .inner p{
        margin:0; font-size:1.9rem; font-weight:800; color:#0f172a; line-height:1.05;
      }
      #dashStats .small-box .icon{
        position:absolute; top:16px; right:16px; z-index:1; pointer-events:none;
        width:48px; height:48px; border-radius:12px;
        display:flex !important; align-items:center; justify-content:center;
        background:var(--acc-soft,rgba(37,99,235,.12));
      }
      #dashStats .small-box .icon i{
        position:static !important; font-size:26px !important; line-height:1; opacity:1;
        color:var(--acc,#2563eb) !important;
      }
      #dashStats .small-box.bg-primary  { --acc:#2563eb; --acc-soft:rgba(37,99,235,.12); }
      #dashStats .small-box.bg-warning  { --acc:#f59e0b; --acc-soft:rgba(245,158,11,.15); }
      #dashStats .small-box.bg-success  { --acc:#16a34a; --acc-soft:rgba(22,163,74,.12); }
      #dashStats .small-box.bg-danger   { --acc:#dc2626; --acc-soft:rgba(220,38,38,.12); }
      #dashStats .small-box.bg-info     { --acc:#0891b2; --acc-soft:rgba(8,145,178,.12); }
      #dashStats .small-box.bg-secondary{ --acc:#475569; --acc-soft:rgba(71,85,105,.12); }
      #dashAnalytics .card{ border-top:1px solid var(--mneqas-border, #dfe3e8) !important; }
      @media (max-width:575.98px){ #dashStats .small-box .inner{ padding-right:76px; } }
    </style>

    <!-- Main dynamic dashboard container: holds summary cards and breakdown tables -->
    <div id="dashAnalytics">
      <?php require __DIR__ . "/dashboard-analytics.php"; ?>
    </div>
  </div>
  <!-- /.content-wrapper -->

  <script>
    $(function () {
      if ($('#table').length) {
        $('#table').DataTable({
          "paging": true,
          "lengthChange": false,
          "searching": false,
          "ordering": true,
          "info": true,
          "autoWidth": false,
          "responsive": true,
          "order": [[ 0, "asc" ]]
        });
      }

      // Dynamic Year filter: reload year-scoped summary cards + breakdown tables via AJAX.
      var $dashContent = $('#dashAnalytics');
      $('#dashYear').on('change', function () {
        var year = $(this).val();
        var $sel = $(this).prop('disabled', true);
        $dashContent.css('opacity', 0.45);
        $.get('dashboard.php', { ajax_year: year })
          .done(function (html) {
            $dashContent.html(html);
            // Retain active year in browser history for page refresh
            if (window.history && window.history.replaceState) {
              var newUrl = window.location.pathname + '?year=' + encodeURIComponent(year);
              window.history.replaceState(null, '', newUrl);
            }
          })
          .fail(function () {
            Swal.fire({ icon: 'error', text: 'Could not load data for ' + year + '. Please try again.' });
          })
          .always(function () {
            $dashContent.css('opacity', 1);
            $sel.prop('disabled', false);
          });
      });
    });
  </script>
  
<!-- /.page footer -->
<?php require_once("../includes/footer.php"); ?>
