<?php
if (count(get_included_files()) === 1) { http_response_code(404); exit; } // include-only partial
/**
 * dashboard-analytics.php — Dynamic year-scoped analytics cards and breakdown tables
 * Rendered both inline by dashboard.php and standalone via dashboard.php?ajax_year=YYYY.
 * Expects $conn (mysqli) and $sel_year.
 */

if (!function_exists('mn_dash_row')) {
    function mn_dash_row($res, $cols = 1, $default = 0) {
        $row = ($res instanceof mysqli_result) ? $res->fetch_row() : null;
        if (!is_array($row)) { $row = array(); }
        for ($i = 0; $i < $cols; $i++) {
            if (!isset($row[$i])) {
                $row[$i] = is_array($default) ? (isset($default[$i]) ? $default[$i] : 0) : $default;
            }
        }
        return $row;
    }
}

// Sanitize selected year: must be a valid integer between 2016 and current year + 5
$currentSystemYear = (int)date("Y");
if (!isset($sel_year) || !is_numeric($sel_year)) {
    $sel_year = isset($_GET['year']) ? (int)$_GET['year'] : (isset($_GET['ajax_year']) ? (int)$_GET['ajax_year'] : $currentSystemYear);
}
$cur_year = (int)$sel_year;
if ($cur_year < 2016 || $cur_year > $currentSystemYear + 5) {
    $cur_year = $currentSystemYear;
}
$is_cur_year = ($cur_year === $currentSystemYear);
$mn_hl       = $is_cur_year ? (int)date("m") : 0; // highlight current month if current year
$col         = 13; // 1 title column + 12 month columns

$mn_months = array(
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
    5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
    9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
);

/* =========================================================================
   1. TOP KPI SUMMARY COUNTS (Selected Year)
   ========================================================================= */

// 1. Micro Panel Testing (matches registered panel records in panels/panel-list.php)
$stmt = $conn->prepare("SELECT COUNT(*) FROM panel_informations t1
    JOIN panel_round_informations t4 ON t1.round_id = t4.round_id
    WHERE YEAR(COALESCE(t4.shipment_date, t4.closing_date, t1.receive_date)) = ?");
$stmt->bind_param("i", $cur_year);
$stmt->execute();
$kpi_micro = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();

// 2. RDT Panel Testing (matches registered panel records in rdt-panels/panel-list.php)
$stmt = $conn->prepare("SELECT COUNT(*) FROM rdt_panel_informations t1
    JOIN rdt_panel_round_informations t4 ON t1.round_id = t4.round_id
    WHERE YEAR(COALESCE(t4.shipment_date, t4.closing_date, t1.receive_date)) = ?");
$stmt->bind_param("i", $cur_year);
$stmt->execute();
$kpi_rdt_panel = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();

// 3. Blinded Rechecking
$stmt = $conn->prepare("SELECT COUNT(*) FROM blinded_results br
    JOIN blinded_informations bi ON br.blinded_id = bi.blinded_id
    WHERE bi.year = ?");
$stmt->bind_param("i", $cur_year);
$stmt->execute();
$kpi_blinded = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();

// 4. Molecular Testing
$stmt = $conn->prepare("SELECT COUNT(*) FROM dbs_results WHERE YEAR(collection_date) = ?");
$stmt->bind_param("i", $cur_year);
$stmt->execute();
$kpi_molecular = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();

// 5. Onsite M&S
$stmt = $conn->prepare("SELECT COUNT(*) FROM onsite_general_informations WHERE YEAR(date_visit) = ?");
$stmt->bind_param("i", $cur_year);
$stmt->execute();
$kpi_ms = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();

// 6. RDT Lot Testing
$stmt = $conn->prepare("SELECT COUNT(*) FROM rdt_test_request WHERE YEAR(date_request) = ?");
$stmt->bind_param("i", $cur_year);
$stmt->execute();
$kpi_rdt_lot = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();


/* =========================================================================
   2. PANEL TESTING BREAKDOWN
   ========================================================================= */
$panelRound = '-';
$panelStatus = 'N/A';
$panelBadgeClass = 'mn-badge-gray';
$panelShipmentDate = '-';
$panelClosingDate = '-';
$panelLabs = 0;
$panelReports = 0;

$pStmt = $conn->prepare("SELECT round_id, panel_round, closing_date, shipment_date 
    FROM panel_round_informations 
    WHERE YEAR(COALESCE(shipment_date, closing_date)) = ? 
    ORDER BY panel_round DESC LIMIT 1");
$pStmt->bind_param("i", $cur_year);
$pStmt->execute();
$pRes = $pStmt->get_result();
if ($pRow = $pRes->fetch_assoc()) {
    $round_id = (int)$pRow['round_id'];
    $panelRound = !empty($pRow['panel_round']) ? $pRow['panel_round'] : '-';
    $panelClosingDate = !empty($pRow['closing_date']) ? $pRow['closing_date'] : '-';
    $panelShipmentDate = !empty($pRow['shipment_date']) ? $pRow['shipment_date'] : '-';

    if (!empty($pRow['closing_date'])) {
        $today = new DateTime(date('Y-m-d'));
        $closing = new DateTime($pRow['closing_date']);
        if ($today > $closing) {
            $panelStatus = 'CLOSED';
            $panelBadgeClass = 'mn-badge-green';
        } else {
            $panelStatus = 'OPEN';
            $panelBadgeClass = 'mn-badge-amber';
        }
    }

    $lStmt = $conn->prepare("SELECT COUNT(DISTINCT facility_id) FROM panel_informations WHERE round_id = ?");
    $lStmt->bind_param("i", $round_id);
    $lStmt->execute();
    $panelLabs = (int)($lStmt->get_result()->fetch_row()[0] ?? 0);
    $lStmt->close();

    $rStmt = $conn->prepare("SELECT COUNT(DISTINCT t3.facility_id) 
        FROM panel_lab_results t1
        JOIN panel_slide_informations t2 ON t1.slide_id = t2.slide_id
        JOIN panel_informations t3 ON t2.panel_id = t3.panel_id
        WHERE t3.round_id = ?");
    $rStmt->bind_param("i", $round_id);
    $rStmt->execute();
    $panelReports = (int)($rStmt->get_result()->fetch_row()[0] ?? 0);
    $rStmt->close();
}
$pStmt->close();


/* =========================================================================
   3. BLINDED RECHECKING BREAKDOWN (Months 1..12)
   ========================================================================= */
$blinded_labs   = array_fill(1, 12, 0);
$blinded_slides = array_fill(1, 12, 0);

$blStmt = $conn->prepare("SELECT month, COUNT(DISTINCT facility_id) AS cnt 
    FROM blinded_informations 
    WHERE year = ? 
    GROUP BY month");
$blStmt->bind_param("i", $cur_year);
$blStmt->execute();
$blRes = $blStmt->get_result();
while ($r = $blRes->fetch_assoc()) {
    $m = (int)$r['month'];
    if ($m >= 1 && $m <= 12) {
        $blinded_labs[$m] = (int)$r['cnt'];
    }
}
$blStmt->close();

$bsStmt = $conn->prepare("SELECT t1.month, COUNT(t2.result_id) AS cnt 
    FROM blinded_informations t1 
    JOIN blinded_results t2 ON t1.blinded_id = t2.blinded_id 
    WHERE t1.year = ? 
    GROUP BY t1.month");
$bsStmt->bind_param("i", $cur_year);
$bsStmt->execute();
$bsRes = $bsStmt->get_result();
while ($r = $bsRes->fetch_assoc()) {
    $m = (int)$r['month'];
    if ($m >= 1 && $m <= 12) {
        $blinded_slides[$m] = (int)$r['cnt'];
    }
}
$bsStmt->close();

$totalLabsReceived    = array_sum($blinded_labs);
$totalSlidesProcessed = array_sum($blinded_slides);


/* =========================================================================
   4. RDT LOT TESTING BREAKDOWN (Months 1..12)
   ========================================================================= */
$rdt_labs = array_fill(1, 12, 0);
$rdt_ini  = array_fill(1, 12, 0);
$rdt_lt3  = array_fill(1, 12, 0);
$rdt_lt6  = array_fill(1, 12, 0);
$rdt_lt9  = array_fill(1, 12, 0);
$rdt_lt12 = array_fill(1, 12, 0);
$rdt_tot  = array_fill(1, 12, 0);

$rdtStmt = $conn->prepare("SELECT 
    MONTH(date_request) AS m,
    COUNT(DISTINCT facility_id) AS labs,
    SUM(CASE WHEN lot_type = 'Initial' OR lot_type LIKE '%Initial%' THEN 1 ELSE 0 END) AS lot_ini,
    SUM(CASE WHEN lot_type = 'Long-Term(3 Months)' OR (lot_type LIKE '%Long%Term%' AND (lot_type LIKE '%3%' OR lot_type_detail LIKE '%3%')) THEN 1 ELSE 0 END) AS lot_lt3,
    SUM(CASE WHEN lot_type = 'Long-Term(6 Months)' OR (lot_type LIKE '%Long%Term%' AND (lot_type LIKE '%6%' OR lot_type_detail LIKE '%6%')) THEN 1 ELSE 0 END) AS lot_lt6,
    SUM(CASE WHEN lot_type = 'Long-Term(9 Months)' OR (lot_type LIKE '%Long%Term%' AND (lot_type LIKE '%9%' OR lot_type_detail LIKE '%9%')) THEN 1 ELSE 0 END) AS lot_lt9,
    SUM(CASE WHEN lot_type = 'Long-Term(12 Months)' OR (lot_type LIKE '%Long%Term%' AND (lot_type LIKE '%12%' OR lot_type_detail LIKE '%12%')) THEN 1 ELSE 0 END) AS lot_lt12
FROM rdt_test_request
WHERE YEAR(date_request) = ?
GROUP BY MONTH(date_request)");
$rdtStmt->bind_param("i", $cur_year);
$rdtStmt->execute();
$rdtRes = $rdtStmt->get_result();
while ($r = $rdtRes->fetch_assoc()) {
    $m = (int)$r['m'];
    if ($m >= 1 && $m <= 12) {
        $rdt_labs[$m] = (int)$r['labs'];
        $rdt_ini[$m]  = (int)$r['lot_ini'];
        $rdt_lt3[$m]  = (int)$r['lot_lt3'];
        $rdt_lt6[$m]  = (int)$r['lot_lt6'];
        $rdt_lt9[$m]  = (int)$r['lot_lt9'];
        $rdt_lt12[$m] = (int)$r['lot_lt12'];
        $rdt_tot[$m]  = $rdt_ini[$m] + $rdt_lt3[$m] + $rdt_lt6[$m] + $rdt_lt9[$m] + $rdt_lt12[$m];
    }
}
$rdtStmt->close();


/* =========================================================================
   5. MOLECULAR TESTING BREAKDOWN (Months 1..12)
   ========================================================================= */
$mol_labs        = array_fill(1, 12, 0);
$mol_pcd         = array_fill(1, 12, 0);
$mol_acd         = array_fill(1, 12, 0);
$mol_pacd        = array_fill(1, 12, 0);
$mol_racd        = array_fill(1, 12, 0);
$mol_tot_samples = array_fill(1, 12, 0);
$mol_neg         = array_fill(1, 12, 0);
$mol_pos         = array_fill(1, 12, 0);
$mol_tot_pcr     = array_fill(1, 12, 0);

$molStmt = $conn->prepare("SELECT 
    MONTH(collection_date) AS m,
    COUNT(DISTINCT facility_id) AS labs,
    SUM(CASE WHEN sample_type = 'PCD' THEN 1 ELSE 0 END) AS pcd,
    SUM(CASE WHEN sample_type = 'ACD' THEN 1 ELSE 0 END) AS acd,
    SUM(CASE WHEN sample_type = 'PACD' THEN 1 ELSE 0 END) AS pacd,
    SUM(CASE WHEN sample_type = 'RACD' THEN 1 ELSE 0 END) AS racd,
    SUM(CASE WHEN pcr = 'Negative' THEN 1 ELSE 0 END) AS neg,
    SUM(CASE WHEN pcr != 'Negative' AND pcr IS NOT NULL AND pcr != '' THEN 1 ELSE 0 END) AS pos
FROM dbs_results
WHERE YEAR(collection_date) = ?
GROUP BY MONTH(collection_date)");
$molStmt->bind_param("i", $cur_year);
$molStmt->execute();
$molRes = $molStmt->get_result();
while ($r = $molRes->fetch_assoc()) {
    $m = (int)$r['m'];
    if ($m >= 1 && $m <= 12) {
        $mol_labs[$m]        = (int)$r['labs'];
        $mol_pcd[$m]         = (int)$r['pcd'];
        $mol_acd[$m]         = (int)$r['acd'];
        $mol_pacd[$m]        = (int)$r['pacd'];
        $mol_racd[$m]        = (int)$r['racd'];
        $mol_tot_samples[$m] = $mol_pcd[$m] + $mol_acd[$m] + $mol_pacd[$m] + $mol_racd[$m];
        $mol_neg[$m]         = (int)$r['neg'];
        $mol_pos[$m]         = (int)$r['pos'];
        $mol_tot_pcr[$m]     = $mol_neg[$m] + $mol_pos[$m];
    }
}
$molStmt->close();
?>

<!-- =========================================================================
     TOP KPI SUMMARY CARDS (Dynamically linked to $cur_year)
     ========================================================================= -->
<div class="content mb-2">
  <div class="container-fluid">
    <div class="row" id="dashStats">
      <div class="col-xl-2 col-lg-4 col-md-6 col-sm-6 col-12">
        <div class="small-box bg-primary">
          <div class="inner"><b>Micro Panel Testing</b><p><?= $kpi_micro; ?></p></div>
          <div class="icon"><i class="fas fa-microscope"></i></div>
        </div>
      </div>
      <div class="col-xl-2 col-lg-4 col-md-6 col-sm-6 col-12">
        <div class="small-box bg-warning">
          <div class="inner"><b>RDT Panel Testing</b><p><?= $kpi_rdt_panel; ?></p></div>
          <div class="icon"><i class="fas fa-vial"></i></div>
        </div>
      </div>
      <div class="col-xl-2 col-lg-4 col-md-6 col-sm-6 col-12">
        <div class="small-box bg-success">
          <div class="inner"><b>Blinded Rechecking</b><p><?= $kpi_blinded; ?></p></div>
          <div class="icon"><i class="fas fa-clipboard-check"></i></div>
        </div>
      </div>
      <div class="col-xl-2 col-lg-4 col-md-6 col-sm-6 col-12">
        <div class="small-box bg-danger">
          <div class="inner"><b>Molecular Testing</b><p><?= $kpi_molecular; ?></p></div>
          <div class="icon"><i class="fas fa-dna"></i></div>
        </div>
      </div>
      <div class="col-xl-2 col-lg-4 col-md-6 col-sm-6 col-12">
        <div class="small-box bg-info">
          <div class="inner"><b>Onsite M&amp;S</b><p><?= $kpi_ms; ?></p></div>
          <div class="icon"><i class="fas fa-clipboard-list"></i></div>
        </div>
      </div>
      <div class="col-xl-2 col-lg-4 col-md-6 col-sm-6 col-12">
        <div class="small-box bg-secondary">
          <div class="inner"><b>RDT Lot Testing</b><p><?= $kpi_rdt_lot; ?></p></div>
          <div class="icon"><i class="fas fa-virus"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- =========================================================================
     BREAKDOWN TABLES & CARDS
     ========================================================================= -->
<div class="content">
  <div class="container-fluid">   
    <!-- Row 1: Panel Testing & Blinded Rechecking -->
    <div class="row">
      <!-- Layout styles for dashboard analytics tables -->
      <style>
        .card{border-top:1px solid var(--mneqas-border, #dfe3e8) !important;}
        .mn-keyrow{display:flex;gap:14px;margin-bottom:16px;}
        .mn-key{flex:1;display:flex;flex-direction:column;gap:6px;padding:14px 16px;}
        .mn-key-label{font-size:11px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;}
        .mn-key-value{font-size:26px;font-weight:700;line-height:1;}
        .mn-badge{align-self:flex-start;font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;}
        .mn-summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
        .mn-summary{display:flex;flex-direction:column;gap:4px;padding:12px 14px;}
        .mn-summary-label{font-size:11px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;}
        .mn-summary-value{font-size:15px;font-weight:600;}
        .mn-table-wrap{overflow-x:auto;}
        .mn-analytics{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;min-width:420px;}
        .mn-analytics thead th{padding:11px 10px;text-align:center;white-space:nowrap;}
        .mn-analytics tbody td{padding:11px 10px;text-align:center;}
        .mn-analytics tbody tr:last-child td{border-bottom:none;}
        .mn-firstcol{text-align:left !important;font-weight:600;position:sticky;left:0;white-space:nowrap;min-width:170px;}
        .mn-analytics thead th.mn-firstcol{z-index:1;}
        .mn-chip{display:inline-block;min-width:34px;padding:3px 8px;font-weight:600;}
        .mn-br-summary{display:flex;gap:14px;margin-top:16px;}
        .mn-br-stat{flex:1;padding:14px 16px;}
        .mn-br-stat-label{display:block;font-size:11px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;}
        .mn-br-stat-value{font-size:22px;font-weight:700;}
        .mn-analytics tr.mn-section td{font-size:10.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;text-align:center;padding:8px 10px;}
        .mn-analytics tr.mn-total td{font-weight:700;}
        .mn-analytics i{font-style:normal;}
        @media (max-width:480px){.mn-keyrow,.mn-br-summary{flex-direction:column;}.mn-summary-grid{grid-template-columns:1fr;}}
      </style>

      <!-- Panel Testing -->
      <section class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Panel Testing</h3>
          </div>
          <div class="card-body">
            <!-- Key information: Round + Status -->
            <div class="mn-keyrow">
              <div class="mn-key">
                <span class="mn-key-label">Round</span>
                <span class="mn-key-value"><?= htmlspecialchars((string)$panelRound, ENT_QUOTES); ?></span>
              </div>
              <div class="mn-key">
                <span class="mn-key-label">Status</span>
                <span class="mn-badge <?= $panelBadgeClass; ?>"><?= htmlspecialchars((string)$panelStatus, ENT_QUOTES); ?></span>
              </div>
            </div>
            <!-- Summary sections -->
            <div class="mn-summary-grid">
              <div class="mn-summary">
                <span class="mn-summary-label">Shipment Date</span>
                <span class="mn-summary-value"><?= htmlspecialchars((string)$panelShipmentDate, ENT_QUOTES); ?></span>
              </div>
              <div class="mn-summary">
                <span class="mn-summary-label">Closing Date</span>
                <span class="mn-summary-value"><?= htmlspecialchars((string)$panelClosingDate, ENT_QUOTES); ?></span>
              </div>
              <div class="mn-summary">
                <span class="mn-summary-label">Participating Labs</span>
                <span class="mn-summary-value"><?= $panelLabs; ?></span>
              </div>
              <div class="mn-summary">
                <span class="mn-summary-label">Reports Updated</span>
                <span class="mn-summary-value"><?= $panelReports; ?> labs</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Blinded Rechecking -->
      <section class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Blinded Rechecking</h3>
          </div>
          <div class="card-body">
            <div class="mn-table-wrap">
              <table class="mn-analytics">
                <thead>
                  <tr>
                    <th class="mn-firstcol">Month</th>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<th class='mn-num$cur'>" . $mn_months[$i] . "</th>";
                  } ?>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="mn-firstcol">Slides Received From (Labs)</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='$cur'><span class='mn-chip'>" . $blinded_labs[$i] . "</span></td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">Total Slides</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='$cur'><span class='mn-chip mn-chip-strong'>" . $blinded_slides[$i] . "</span></td>";
                  } ?>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Summary totals -->
            <div class="mn-br-summary">
              <div class="mn-br-stat">
                <span class="mn-br-stat-label">Total Labs Received</span>
                <span class="mn-br-stat-value"><?= $totalLabsReceived; ?></span>
              </div>
              <div class="mn-br-stat">
                <span class="mn-br-stat-label">Total Slides Processed</span>
                <span class="mn-br-stat-value"><?= $totalSlidesProcessed; ?></span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Row 2: RDT Lot Testing & Molecular Testing -->
    <div class="row">
      <!-- RDT Lot Testing -->
      <section class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">RDT Lot Testing</h3>
          </div>
          <div class="card-body">
            <div class="mn-table-wrap">
              <table class="mn-analytics">
                <thead>
                  <tr>
                    <th class="mn-firstcol">Month</th>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<th class='$cur'>" . $mn_months[$i] . "</th>";
                  } ?>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="mn-firstcol">RDT Received From (Labs)</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $rdt_labs[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr class="mn-section">
                    <td class="mn-section-cell" colspan="<?= $col; ?>">Type of RDT Lot Test Received</td>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">Initial Lot</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $rdt_ini[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">Long-term (3 months) Lot</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $rdt_lt3[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">Long-term (6 months) Lot</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $rdt_lt6[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">Long-term (9 months) Lot</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $rdt_lt9[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">Long-term (12 months) Lot</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $rdt_lt12[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr class="mn-total">
                    <td class="mn-firstcol">Total Lots Received</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'><i>" . $rdt_tot[$i] . "</i></td>";
                  } ?>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Summary totals -->
            <div class="mn-br-summary">
              <div class="mn-br-stat">
                <span class="mn-br-stat-label">Total Lots Received</span>
                <span class="mn-br-stat-value"><?= array_sum($rdt_tot); ?></span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Molecular Testing -->
      <section class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Molecular Testing</h3>
          </div>
          <div class="card-body">
            <div class="mn-table-wrap">
              <table class="mn-analytics">
                <thead>
                  <tr>
                    <th class="mn-firstcol">Month</th>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<th class='$cur'>" . $mn_months[$i] . "</th>";
                  } ?>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="mn-firstcol">Molecular Samples Received From (Labs)</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $mol_labs[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr class="mn-section">
                    <td class="mn-section-cell" colspan="<?= $col; ?>">Samples Based On Case Type</td>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">PCD</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $mol_pcd[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">ACD</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $mol_acd[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">PACD</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $mol_pacd[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">RACD</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $mol_racd[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr class="mn-total">
                    <td class="mn-firstcol">Total Sample Received</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'><i>" . $mol_tot_samples[$i] . "</i></td>";
                  } ?>
                  </tr>
                  <tr class="mn-section">
                    <td class="mn-section-cell" colspan="<?= $col; ?>">PCR Test Result</td>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">Negative</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $mol_neg[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr>
                    <td class="mn-firstcol">Positive</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'>" . $mol_pos[$i] . "</td>";
                  } ?>
                  </tr>
                  <tr class="mn-total">
                    <td class="mn-firstcol">Total PCR</td>
                  <?php for ($i = 1; $i <= 12; $i++) {
                      $cur = ($i == $mn_hl) ? ' mn-cur' : '';
                      echo "<td class='text-center $cur'><i>" . $mol_tot_pcr[$i] . "</i></td>";
                  } ?>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Summary totals -->
            <div class="mn-br-summary">
              <div class="mn-br-stat">
                <span class="mn-br-stat-label">Total Samples</span>
                <span class="mn-br-stat-value"><?= array_sum($mol_tot_samples); ?></span>
              </div>
              <div class="mn-br-stat">
                <span class="mn-br-stat-label">Total PCR Tested</span>
                <span class="mn-br-stat-value"><?= array_sum($mol_tot_pcr); ?></span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>     
  </div>
</div>
