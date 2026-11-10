<?php
/**
 * dashboard/chart-data.php
 * =============================================================================
 * AJAX JSON endpoint powering the PER-CHART Year filter on the dashboard.
 *
 * Each dashboard chart card's hamburger (`≡`) menu now carries a "View year
 * <YYYY>" sub-list. Clicking an item on, say, the Antimicrobial Resistance
 * chart posts a GET to:
 *
 *     dashboard/chart-data.php?chart=amr&year=2024
 *
 * and this endpoint runs the SAME SQL that chart.php's PHP would have run for
 * that chart on page load, but scoped to the requested year. The client then
 * applies the payload to the chart in place (series.setData, setSubtitle),
 * leaving every OTHER chart and the top-of-page toolbar at their own year.
 *
 * Supported chart ids (8 year-scoped charts):
 *   trend  prevalence  method  seg  geo  ageSex  amr  site
 *
 * NOT supported (deliberately — these charts span every surveillance year):
 *   yoy   seasonal   key-amr-phenotypes
 *
 * Response envelope:
 *   { "ok": true, "chart": "<id>", "year": N, "subtitle": "...", "payload": {...} }
 *
 * On any guard failure, returns { "ok": false, "error": "..." } with a 4xx /
 * 5xx status so the client's catch() branch logs the failure cleanly and the
 * chart keeps its previous data on screen.
 *
 * Security: identical module gate and scope clause as chart.php -- a facility-
 * scoped user still only sees their own centre's rows, and a DENIED tier is
 * refused up front.
 */

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/../config/session.php';
    require_once __DIR__ . '/../config/commondb-config.php';
    require_once __DIR__ . '/../config/db-config.php';
    require_once __DIR__ . '/../includes/rbac.php';
    bdsis_require_module();
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$SCOPE    = bdsis_scope_clause($bdsis, "center_id");       // subject table, unaliased
$SCOPE_SU = bdsis_scope_clause($bdsis, "su.center_id");
$SCOPE_S  = bdsis_scope_clause($bdsis, "s.center_id");

$chart = isset($_GET['chart']) ? trim((string)$_GET['chart']) : '';
$year  = isset($_GET['year'])  ? (int)$_GET['year']           : 0;

$ALLOWED = array('trend', 'prevalence', 'method', 'seg', 'geo', 'ageSex', 'amr', 'site');
if (!in_array($chart, $ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown chart']);
    exit;
}
if ($year < 2015 || $year > ((int)date('Y') + 1)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid year']);
    exit;
}
$prev = $year - 1;

/* Same vocabularies chart.php uses so the per-chart queries behave identically. */
$CASE     = "subject_category IN ('1','2')";
$NEGATIVE = "('101','999','025','026')";

$subtitle = 'Surveillance year: ' . $year;
$payload  = array();

switch ($chart) {

/* ---------- Incidence Trend ----------
   Monthly cases for the chosen year vs the previous year, plus outbreak
   markers for months above mean + 2 SD of the chosen year. Mirrors chart.php
   lines ~50-59. */
case 'trend':
    $mThis = array_fill(1, 12, 0);
    $mPrev = array_fill(1, 12, 0);
    $tr = $bdsis->query("SELECT YEAR(hospital_visit_date) y, MONTH(hospital_visit_date) m, COUNT(*) c
                           FROM subject_information_table
                          WHERE YEAR(hospital_visit_date) IN ($year,$prev) AND $CASE $SCOPE
                          GROUP BY y, m");
    if ($tr) {
        while ($r = $tr->fetch_row()) {
            if ((int)$r[0] === $year) { $mThis[(int)$r[1]] = (int)$r[2]; }
            else                      { $mPrev[(int)$r[1]] = (int)$r[2]; }
        }
    }
    $vals = array_values($mThis);
    $n = count($vals);
    $mean = $n > 0 ? array_sum($vals) / $n : 0;
    $sd = 0; foreach ($vals as $v) { $sd += ($v - $mean) * ($v - $mean); }
    $sd = $n > 0 ? sqrt($sd / $n) : 0;
    $threshold = round($mean + 2 * $sd, 1);
    $outbreak = array();
    foreach ($vals as $idx => $v) {
        if ($v > $threshold && $threshold > 0) {
            $outbreak[] = array('x' => $idx, 'y' => $v);
        }
    }
    $payload = array(
        'this'       => $vals,
        'prev'       => array_values($mPrev),
        'outbreak'   => $outbreak,
        'threshold'  => $threshold,
        'yearLabel'  => (string)$year,
        'prevLabel'  => (string)$prev,
    );
    break;

/* ---------- Pathogen Prevalence ----------
   Top 10 isolates for the chosen year. Mirrors chart.php lines ~62-69. */
case 'prevalence':
    $cat = array(); $val = array();
    $pq = $bdsis->query("SELECT p.enteric_pathogen, COUNT(*) c
                           FROM bacteriology_result_table2 b
                           JOIN subject_information_table su ON b.subject_id = su.subject_id
                           LEFT JOIN ms_enteric_bacterial_pathogen_table p ON b.pathogen_id = p.pathogen_id
                          WHERE YEAR(su.hospital_visit_date) = $year $SCOPE_SU
                            AND b.pathogen_id NOT IN $NEGATIVE
                          GROUP BY b.pathogen_id ORDER BY c DESC LIMIT 10");
    if ($pq) {
        while ($r = $pq->fetch_row()) {
            $cat[] = $r[0] ? $r[0] : 'Unspecified';
            $val[] = (int)$r[1];
        }
    }
    $payload = array('categories' => $cat, 'data' => $val);
    break;

/* ---------- Diagnostic Method ---------- Mirrors chart.php lines ~72-84. */
case 'method':
    $tested = array(); $pos = array();
    $tested['Culture/AST'] = (int)$bdsis->query("SELECT COUNT(DISTINCT b.subject_id) FROM bacteriology_result_table2 b JOIN subject_information_table su ON b.subject_id=su.subject_id WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU")->fetch_row()[0];
    $pos['Culture/AST']    = (int)$bdsis->query("SELECT COUNT(DISTINCT b.subject_id) FROM bacteriology_result_table2 b JOIN subject_information_table su ON b.subject_id=su.subject_id WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU AND b.pathogen_id NOT IN $NEGATIVE")->fetch_row()[0];
    $tested['ELISA']       = (int)$bdsis->query("SELECT COUNT(DISTINCT e.subject_id) FROM rcdc_elisa_result_table2 e JOIN subject_information_table su ON e.subject_id=su.subject_id WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU")->fetch_row()[0];
    $pos['ELISA']          = (int)$bdsis->query("SELECT COUNT(DISTINCT e.subject_id) FROM rcdc_elisa_result_table2 e JOIN subject_information_table su ON e.subject_id=su.subject_id WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU AND e.elisa_result='Positive'")->fetch_row()[0];
    $tested['E. coli PCR'] = (int)$bdsis->query("SELECT COUNT(DISTINCT p.subject_id) FROM rcdc_ecoli_pcr_result_table2 p JOIN subject_information_table su ON p.subject_id=su.subject_id WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU")->fetch_row()[0];
    $pos['E. coli PCR']    = (int)$bdsis->query("SELECT COUNT(DISTINCT p.subject_id) FROM rcdc_ecoli_pcr_result_table2 p JOIN subject_information_table su ON p.subject_id=su.subject_id WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU AND p.ecoli_strain_result='Positive'")->fetch_row()[0];
    $tested['Microscopy']  = (int)$bdsis->query("SELECT COUNT(DISTINCT m.subject_id) FROM microscopy_result_table m JOIN subject_information_table su ON m.subject_id=su.subject_id WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU")->fetch_row()[0];
    $pos['Microscopy']     = null;   // Microscopy positivity not derived (free-text ova/cyst)
    $payload = array(
        'categories' => array_keys($tested),
        'tested'     => array_values($tested),
        'positive'   => array_values($pos),
    );
    break;

/* ---------- Pathogens by Method ---------- Mirrors chart.php lines ~87-101. */
case 'seg':
    /* Reuse the Pathogen Prevalence query for the bacterial half. */
    $prevCat = array(); $prevVal = array();
    $pq = $bdsis->query("SELECT p.enteric_pathogen, COUNT(*) c
                           FROM bacteriology_result_table2 b
                           JOIN subject_information_table su ON b.subject_id=su.subject_id
                           LEFT JOIN ms_enteric_bacterial_pathogen_table p ON b.pathogen_id=p.pathogen_id
                          WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU AND b.pathogen_id NOT IN $NEGATIVE
                          GROUP BY b.pathogen_id ORDER BY c DESC LIMIT 10");
    if ($pq) { while ($r = $pq->fetch_row()) { $prevCat[]=$r[0]?:'Unspecified'; $prevVal[]=(int)$r[1]; } }
    $elisaCat = array(); $elisaVal = array();
    $eq = $bdsis->query("SELECT v.virus, COUNT(DISTINCT e.subject_id) c
                           FROM rcdc_elisa_result_table2 e
                           JOIN subject_information_table su ON e.subject_id=su.subject_id
                           LEFT JOIN ms_virus_table v ON e.virus_id=v.virus_id
                          WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU AND e.elisa_result='Positive'
                          GROUP BY e.virus_id ORDER BY c DESC");
    if ($eq) { while ($r = $eq->fetch_row()) { $elisaCat[]=$r[0]?:'Unspecified'; $elisaVal[]=(int)$r[1]; } }
    $pcrPos = (int)$bdsis->query("SELECT COUNT(DISTINCT p.subject_id) FROM rcdc_ecoli_pcr_result_table2 p JOIN subject_information_table su ON p.subject_id=su.subject_id WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU AND p.ecoli_strain_result='Positive'")->fetch_row()[0];
    $nb = count($prevCat); $nv = count($elisaCat);
    $payload = array(
        'categories' => array_merge($prevCat, $elisaCat, array('Diarrhoeagenic E. coli (PCR)')),
        'bact'       => array_merge($prevVal, array_fill(0,$nv,0), array(0)),
        'elisa'      => array_merge(array_fill(0,$nb,0), $elisaVal, array(0)),
        'pcr'        => array_merge(array_fill(0,$nb,0), array_fill(0,$nv,0), array($pcrPos)),
    );
    break;

/* ---------- Geographic Distribution ---------- Mirrors chart.php lines ~104-111. */
case 'geo':
    $cat = array(); $val = array();
    $gq = $bdsis->query("SELECT COALESCE(d.dzongkhag_name,'Unknown') nm, COUNT(*) c
                           FROM subject_information_table s
                           LEFT JOIN rcdc_commondb.ms_dzongkhag_table d ON s.dzongkhag_code=d.dzongkhag_code
                          WHERE YEAR(s.hospital_visit_date)=$year AND $CASE $SCOPE_S
                          GROUP BY s.dzongkhag_code ORDER BY c DESC LIMIT 20");
    if ($gq) { while ($r = $gq->fetch_row()) { $cat[]=$r[0]; $val[]=(int)$r[1]; } }
    $payload = array('categories' => $cat, 'data' => $val);
    break;

/* ---------- Age Group & Sex ---------- Mirrors chart.php lines ~114-126. */
case 'ageSex':
    $ageGroups = array('<1','1-4','5-14','15-24','25-44','45-64','65+');
    $ageM = array_fill_keys($ageGroups, 0);
    $ageF = array_fill_keys($ageGroups, 0);
    $aq = $bdsis->query("SELECT CASE WHEN age<1 THEN '<1' WHEN age<5 THEN '1-4' WHEN age<15 THEN '5-14'
                                WHEN age<25 THEN '15-24' WHEN age<45 THEN '25-44' WHEN age<65 THEN '45-64' ELSE '65+' END grp,
                                gender, COUNT(*) c
                           FROM subject_information_table
                          WHERE YEAR(hospital_visit_date)=$year AND $CASE $SCOPE AND age BETWEEN 0 AND 100
                          GROUP BY grp, gender");
    $known = 0;
    if ($aq) {
        while ($r = $aq->fetch_row()) {
            $g = $r[0]; $known += (int)$r[2];
            if (stripos($r[1], 'F') === 0) { $ageF[$g] = (int)$r[2]; } else { $ageM[$g] = (int)$r[2]; }
        }
    }
    $payload = array(
        'categories' => $ageGroups,
        'male'       => array_values($ageM),
        'female'     => array_values($ageF),
        'known'      => $known,
    );
    break;

/* ---------- Antimicrobial Resistance ---------- Mirrors chart.php lines ~144-158. */
case 'amr':
    $cat = array(); $r = array(); $i = array(); $s = array();
    $tot = 0; $resTot = 0;
    $amr = $bdsis->query("SELECT COALESCE(NULLIF(TRIM(t.antimicrobial_agent),''), a.antimicrobial_code) nm,
                                 SUM(a.ast_result='Resistant') r, SUM(a.ast_result='Intermediate') i,
                                 SUM(a.ast_result='Susceptible') s, COUNT(*) n
                            FROM bacteriology_ast_table a
                            JOIN bacteriology_result_table2 b ON a.brt2_id=b.record_id
                            JOIN subject_information_table su ON b.subject_id=su.subject_id
                            LEFT JOIN ms_ast_table t ON a.antimicrobial_code=t.code AND a.ast_method_id=t.ast_method_id
                           WHERE YEAR(su.hospital_visit_date)=$year $SCOPE_SU
                           GROUP BY a.antimicrobial_code HAVING COUNT(*) >= 10
                           ORDER BY (SUM(a.ast_result='Resistant')/COUNT(*)) DESC LIMIT 12");
    if ($amr) {
        while ($row = $amr->fetch_row()) {
            $cat[] = $row[0];
            $r[]   = (int)$row[1];
            $i[]   = (int)$row[2];
            $s[]   = (int)$row[3];
            $tot   += (int)$row[4];
            $resTot += (int)$row[1];
        }
    }
    $overall = $tot > 0 ? round(100 * $resTot / $tot, 1) : 0;
    $payload = array(
        'categories' => $cat, 'r' => $r, 'i' => $i, 's' => $s,
        'overall'    => $overall, 'tot' => $tot,
    );
    /* AMR card's subtitle also carries the overall + tests count; the client
       pulls these out when it rebuilds the subtitle text. */
    $subtitle = 'Surveillance year: ' . $year . ' · Overall resistance '
              . $overall . '% of ' . number_format($tot) . ' tests';
    break;

/* ---------- Samples by Sentinel Site ---------- Mirrors chart.php lines ~161-173. */
case 'site':
    $siteNames = array(); $siteIdx = array(); $idx = 0;
    $hc = $bdsis->query("SELECT center_id, center_name
                           FROM rcdc_commondb.ms_health_center_table
                          WHERE dsis=1 $SCOPE ORDER BY center_name");
    if ($hc) { while ($row = $hc->fetch_row()) { $siteIdx[$row[0]] = $idx++; $siteNames[] = $row[1]; } }
    $nSite = count($siteNames);
    $siteMonth = array();
    for ($m = 1; $m <= 12; $m++) { $siteMonth[$m] = array_fill(0, max($nSite, 1), 0); }
    $sq = $bdsis->query("SELECT su.center_id, MONTH(su.hospital_visit_date) m, COUNT(*) c
                           FROM subject_information_table su
                           JOIN rcdc_commondb.ms_health_center_table h ON su.center_id=h.center_id AND h.dsis=1
                          WHERE YEAR(su.hospital_visit_date)=$year AND $CASE $SCOPE_SU
                          GROUP BY su.center_id, m");
    if ($sq) {
        while ($row = $sq->fetch_row()) {
            if (isset($siteIdx[$row[0]])) {
                $siteMonth[(int)$row[1]][$siteIdx[$row[0]]] = (int)$row[2];
            }
        }
    }
    $monthNames = array(1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec');
    $series = array();
    foreach ($monthNames as $m => $nm) {
        $series[] = array('name' => $nm, 'data' => array_values($siteMonth[$m]));
    }
    $payload = array('sites' => $siteNames, 'series' => $series);
    break;
}

echo json_encode(array(
    'ok'       => true,
    'chart'    => $chart,
    'year'     => $year,
    'subtitle' => $subtitle,
    'payload'  => $payload,
), JSON_NUMERIC_CHECK);
