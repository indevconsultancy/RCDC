<?php ob_start(); /* Buffer the page so a refused role returns a real 403, not a 200
     carrying a half-built page: header.php below emits <!DOCTYPE> before the
     authorisation guard runs, and bdsis_deny() discards this buffer to answer
     cleanly. Same guard-ordering fix as records/case.php and lab/lab.php. */ ?>
<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php");
  require_once("../includes/rbac.php");
  bdsis_require_can('report', null, 'reports');
  // Report helpers; also pulls in includes/lab-helpers.php, which the listing
  // below uses for the ELISA genotype labels.
  require_once("report-html-builder.php");
  // Shared subject-list query, including the facility restriction.
  require_once("report-subject-filter.php");
  // Per-subject AMR phenotype summary (MDR / ESBL / CRE / CPE / FQ-R)
  // used by the new AMR phenotypes column added to the Group Report table.
  require_once("../includes/amr-phenotype.php");
?>
<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

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
        <!-- `px-2` shrinks the outer gutter so the table card has as much
             horizontal real estate as the viewport can give; combined with
             the compact AST sub-columns below, the whole 11-13 column table
             fits a standard laptop viewport without horizontal scroll. -->
        <div class="container-fluid px-2">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Group Report</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Group Report</li>
                    </ol>
                </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid px-2">
            <!-- One form wraps both cards: the template selector in the filter
                 panel and the ticked subjects in the table below are posted
                 together to report-group-pdf.php. Selections are tracked in JS and
                 written into hidden inputs on submit, because DataTables keeps only
                 the current page in the DOM. -->
            <form id="groupForm" method="post" action="report-group-pdf.php" target="_blank">
                <!-- Filter panel: the Subject Type select, the Generate button and the
                     selection badge on one row, in the same card + .search-filters bar
                     as the Records screen. The report template is fixed to the "Result
                     Template for Outbreak/Group Report" through the hidden input below;
                     the template selector this page used to carry has been removed. -->
                <style>
                    /* Page-local layout for the three controls. The shared .search-filters
                       rules already pin the select, the button and the badge to one height
                       (calc(2.25rem + 2px)); these only size the field, bottom-align the
                       row so the controls share a baseline under the label, and space the
                       actions evenly. Scoped to #groupFilters so nothing else is touched. */
                    #groupFilters { align-items: flex-end; }
                    #groupFilters > .form-group { margin-bottom: 0; }
                    #groupFilters > .grp-type { flex: 0 1 240px; min-width: 200px; max-width: 260px; }
                    #groupFilters > .search-actions { gap: 12px; margin-left: 4px; }
                    #groupFilters .col-form-label-sm { margin-bottom: .35rem; }
                    @media (max-width: 575.98px) {
                        #groupFilters > .grp-type { flex: 1 1 100%; max-width: none; }
                        /* justify-content: the shared bar rule right-aligns the actions,
                           which only shows once they wrap onto their own line. */
                        #groupFilters > .search-actions { flex: 1 1 100%; flex-wrap: wrap; justify-content: flex-start; margin-left: 0; margin-top: 10px; }
                    }
                </style>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row search-filters" id="groupFilters">
                                    <input type="hidden" name="template" value="group_result" />

                                    <!-- Subject Type -->
                                    <div class="form-group grp-type">
                                        <label class="col-form-label-sm" for="subject_type">Subject Type:</label>
                                        <select class="form-control" name="subject_type" id="subject_type">
                                            <option value="">All</option>
                                            <option value="1">Surveillance</option>
                                            <option value="2">Outbreak</option>
                                        </select>
                                    </div>

                                    <!-- Generate + selection count -->
                                    <div class="form-group search-actions">
                                        <button type="submit" id="groupBtn" class="btn bg-gradient-primary">
                                            <i class="fa fa-file-pdf"></i>&nbsp;Generate Group Report
                                        </button>
                                        <span id="selCount" class="sel-count-badge">No subject selected</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subject table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Select Subjects</h3>
                            </div>
                            <div class="card-body">
                            <div id="selHidden"></div>
<?php
                                    // No filter form on this screen -- an empty filter set is
                                    // passed so the shared query still applies the facility
                                    // restriction and the row cap. Narrowing is done with the
                                    // table's own search box, which searches every loaded row,
                                    // not just the visible page.
                                    $subjectqry = bdsis_report_subject_sql($commondb, array(), $acl, $usercat, $_SESSION["ctid"]);
                                    $result = $bdsis->query($subjectqry);

                                    /* Collect the listed subjects first: the result columns are
                                       fetched in ONE batch per source for the whole list below,
                                       not with a query per row. */
                                    $grpRows = array();
                                    if ($result) {
                                        while(list($sid,$name,$sex,$age,$cno,$cat,$cid,$opdt)=$result->fetch_row()){
                                            $grpRows[] = array('sid'=>(string)$sid, 'name'=>(string)$name, 'sex'=>(string)$sex,
                                                               'age'=>(string)$age, 'cat'=>(string)$cat, 'opdt'=>(string)$opdt);
                                        }
                                    }
                                    $listed = count($grpRows);

                                    /* Result columns, by the same rules as the PDF's Result Template
                                       for Outbreak/Group Report: bacteriology = isolated pathogens in
                                       slot order; PCR = positive E.coli pathotypes, else the
                                       isolate-level result; ELISA = positive/equivocal viruses (with
                                       genotype), else the status; antibiotics = S/I/R from both AST
                                       panels, "/"-joined when both tested the same drug. Anything
                                       absent shows "-". Every query is guarded, so a failed lookup
                                       leaves a dash rather than a broken page. */
                                    $grpDash  = '-';
                                    /* AMP/CZO/LEX/CIP sit first for backward-compatible
                                       column ordering. Every OTHER antibiotic that any
                                       listed subject has actually been tested for is
                                       appended after them (alphabetical by code) -- the
                                       discovery pass runs below once $grpAst is populated.
                                       This replaces the previous behaviour that hard-capped
                                       the panel at 4 columns and silently dropped any extra
                                       tested drugs. */
                                    $grpTemplate = array('AMP', 'CZO', 'LEX', 'CIP');
                                    $grpBact = array(); $grpPcrPos = array(); $grpPcrOther = array();
                                    $grpElisaPos = array(); $grpElisaOther = array(); $grpAst = array();
                                    /* CID No. (2026-10-07): per-subject lookup, gated on the
                                       column probe so a database without the ALTER applied
                                       simply reports no CID -- the dash renders in place. */
                                    $grpCidNo = array();
                                    $grpHasCid = function_exists('bdsis_subject_cid_no_column_exists')
                                                 && bdsis_subject_cid_no_column_exists($bdsis);
                                    if ($listed > 0) {
                                        $grpEsc = array();
                                        foreach ($grpRows as $gr) { $grpEsc[] = "'" . $bdsis->real_escape_string($gr['sid']) . "'"; }
                                        $grpIn = implode(',', $grpEsc);
                                        $grpEach = function ($sql, $fn) use ($bdsis) {
                                            $res = $bdsis->query($sql);
                                            if (!$res) { return; }
                                            while ($row = $res->fetch_row()) { $fn($row); }
                                            $res->free();
                                        };
                                        $grpEach("SELECT b.subject_id, COALESCE(NULLIF(m.enteric_pathogen,''), b.pathogen_id)
                                                    FROM bacteriology_result_table2 b
                                                    LEFT JOIN ms_enteric_bacterial_pathogen_table m ON m.pathogen_id = b.pathogen_id
                                                   WHERE b.subject_id IN (" . $grpIn . ")
                                                   ORDER BY b.subject_id, CAST(SUBSTRING(b.e_pathogen,2) AS UNSIGNED), b.record_id",
                                            function ($r) use (&$grpBact) {
                                                $n = trim((string)$r[1]);
                                                if ($n !== '') { $grpBact[$r[0]][$n] = true; }
                                            });
                                        $grpEach("SELECT p2.subject_id, p2.ecoli_strain_result, s.ecoli_strain, p3.strain_result
                                                    FROM rcdc_ecoli_pcr_result_table2 p2
                                                    LEFT JOIN rcdc_ecoli_pcr_result_table3 p3 ON p3.prt2_record_id = p2.record_id
                                                    LEFT JOIN ms_ecoli_strain_table s ON s.strain_id = p3.ecoli_strain_id
                                                   WHERE p2.subject_id IN (" . $grpIn . ")
                                                   ORDER BY p2.subject_id, p2.record_id, p3.ecoli_strain_id",
                                            function ($r) use (&$grpPcrPos, &$grpPcrOther) {
                                                $strain = trim((string)$r[2]);
                                                if ((string)$r[3] === 'Positive' && $strain !== '') { $grpPcrPos[$r[0]][$strain] = true; }
                                                $lvl = trim((string)$r[1]);
                                                if ($lvl !== '') { $grpPcrOther[$r[0]][$lvl] = true; }
                                            });
                                        $grpEach("SELECT e.subject_id, COALESCE(NULLIF(v.virus,''), e.virus_id), e.elisa_result, e.genotype_id
                                                    FROM rcdc_elisa_result_table2 e
                                                    LEFT JOIN ms_virus_table v ON v.virus_id = e.virus_id
                                                   WHERE e.subject_id IN (" . $grpIn . ")
                                                   ORDER BY e.subject_id, e.virus_id",
                                            function ($r) use (&$grpElisaPos, &$grpElisaOther, $bdsis) {
                                                $virus = trim((string)$r[1]);
                                                $rslt  = trim((string)$r[2]);
                                                if ($rslt === 'Positive' || $rslt === 'Equivocal') {
                                                    $label = $virus;
                                                    $geno  = '';
                                                    if (function_exists('bdsis_rotavirus_genotype_label') && (string)$r[3] !== '') {
                                                        $geno = trim((string)bdsis_rotavirus_genotype_label($bdsis, $r[3]));
                                                    }
                                                    if ($geno !== '') { $label .= ' (' . $geno . ')'; }
                                                    if ($rslt === 'Equivocal') { $label .= ': Equivocal'; }
                                                    if ($label !== '') { $grpElisaPos[$r[0]][$label] = true; }
                                                } elseif ($rslt !== '') {
                                                    $grpElisaOther[$r[0]][$rslt] = true;
                                                }
                                            });
                                        $grpCollect = function ($r) use (&$grpAst) {
                                            $code = strtoupper(trim((string)$r[1]));
                                            if ($code === '') { return; }
                                            $map = array('SUSCEPTIBLE' => 'S', 'INTERMEDIATE' => 'I', 'RESISTANT' => 'R');
                                            $raw = strtoupper(trim((string)$r[2]));
                                            $val = isset($map[$raw]) ? $map[$raw] : trim((string)$r[2]);
                                            if ($val === '') { return; }
                                            $grpAst[$r[0]][$code][$val] = true;
                                        };
                                        $grpEach("SELECT b.subject_id, a.antimicrobial_code, a.ast_result
                                                    FROM bacteriology_ast_table a
                                                    JOIN bacteriology_result_table2 b ON b.record_id = a.brt2_id
                                                   WHERE b.subject_id IN (" . $grpIn . ")", $grpCollect);
                                        $grpEach("SELECT p2.subject_id, pa.antimicrobial_code, pa.ast_result
                                                    FROM rcdc_ecoli_pcr_ast_table pa
                                                    JOIN rcdc_ecoli_pcr_result_table3 p3 ON p3.record_id = pa.prt3_record_id
                                                    JOIN rcdc_ecoli_pcr_result_table2 p2 ON p2.record_id = p3.prt2_record_id
                                                   WHERE p2.subject_id IN (" . $grpIn . ")", $grpCollect);
                                        if ($grpHasCid) {
                                            $grpEach("SELECT subject_id, cid_no FROM subject_information_table WHERE subject_id IN (" . $grpIn . ")",
                                                function ($r) use (&$grpCidNo) {
                                                    $v = trim((string)($r[1] ?? ''));
                                                    if ($v !== '') { $grpCidNo[$r[0]] = $v; }
                                                });
                                        }
                                    }
                                    $grpJoin = function ($arr, $sep = ', ') use ($grpDash) {
                                        return (is_array($arr) && count($arr) > 0) ? implode($sep, array_keys($arr)) : $grpDash;
                                    };
                                    /* Precedence for AST interpretations: R > SDD > I > S > - */
                                    $grpResolveAst = function ($arr) use ($grpDash) {
                                        if (!is_array($arr) || count($arr) === 0) { return $grpDash; }
                                        $keys = array();
                                        foreach (array_keys($arr) as $k) {
                                            $keys[] = strtoupper(trim((string)$k));
                                        }
                                        if (in_array('R', $keys, true) || in_array('RESISTANT', $keys, true)) {
                                            return 'R';
                                        }
                                        if (in_array('SDD', $keys, true) || in_array('SUSCEPTIBLE-DOSE DEPENDENT', $keys, true) || in_array('SUSCEPTIBLE DOSE DEPENDENT', $keys, true)) {
                                            return 'SDD';
                                        }
                                        if (in_array('I', $keys, true) || in_array('INTERMEDIATE', $keys, true)) {
                                            return 'I';
                                        }
                                        if (in_array('S', $keys, true) || in_array('SUSCEPTIBLE', $keys, true) || in_array('SENSITIVE', $keys, true)) {
                                            return 'S';
                                        }
                                        foreach ($keys as $k) {
                                            if ($k !== '' && $k !== '-' && $k !== 'N/A' && $k !== 'NA') {
                                                return $k;
                                            }
                                        }
                                        return $grpDash;
                                    };
                                    /* "Negative" wins over Not Done / Pending when both were recorded. */
                                    $grpStatus = function ($arr) use ($grpDash) {
                                        if (!is_array($arr) || count($arr) === 0) { return $grpDash; }
                                        if (isset($arr['Negative'])) { return 'Negative'; }
                                        return implode(', ', array_keys($arr));
                                    };
                                    $grpH = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };

                                    /* Build the visible antibiotic set from the aggregated AST data:
                                       any antibiotic with at least one reading across the listed
                                       subjects earns a column. Template codes (AMP/CZO/LEX/CIP)
                                       come first in their canonical order; everything else is
                                       appended alphabetically. A column of nothing but "-" would
                                       tell the reader nothing, so antibiotics that nobody has data
                                       for are simply absent -- same rule the PDF applies. */
                                    $grpAllCodes = array();
                                    foreach ($grpAst as $grpSidAst) {
                                        foreach ($grpSidAst as $grpCode => $_grpV) { $grpAllCodes[strtoupper((string)$grpCode)] = true; }
                                    }
                                    $grpVisibleCodes = array();
                                    foreach ($grpTemplate as $grpT) {
                                        if (isset($grpAllCodes[$grpT])) { $grpVisibleCodes[] = $grpT; unset($grpAllCodes[$grpT]); }
                                    }
                                    $grpExtra = array_keys($grpAllCodes);
                                    sort($grpExtra);
                                    $grpVisibleCodes = array_merge($grpVisibleCodes, $grpExtra);
                                    $grpNAbx = count($grpVisibleCodes);

                                    /* Per-subject AMR phenotype tokens (MDR / ESBL / CRE / CPE / FQ-R)
                                       derived in one batch from both AST tables + bacteriology_resistance_table.
                                       Returns sid => array of tokens; empty array renders as "-". */
                                    $grpPhen = ($listed > 0 && function_exists('bdsis_amr_phenotype_summary'))
                                             ? bdsis_amr_phenotype_summary($bdsis, array_map(function ($g) { return $g['sid']; }, $grpRows))
                                             : array();
?>
                            <!-- Compact-cell layout + fixed widths for the Group Report table,
                                 scoped to #table so no other list is affected.

                                 Three layout rules the client asked for live here:
                                   1. Card-level horizontal scroll via `.grp-table-wrap`
                                      (`overflow-x: auto; max-width: 100%`) — the scrollbar
                                      stays inside the card body, not at the bottom of the
                                      viewport.
                                   2. Checkbox + Sample ID columns are frozen on the left
                                      with `position: sticky`, so context is maintained
                                      when the user scrolls AST sub-columns and AMR
                                      phenotypes into view.
                                   3. AST sub-columns (AMP, CZO, LEX, CIP, plus any extras)
                                      are narrow with tight padding so the whole table
                                      naturally fits a standard desktop viewport. -->
                            <style>
                              /* ---- Table-only scroll container ----
                                 DataTables renders this wrapper around just the <table>
                                 via its `dom` option (below). The Search box, "Show
                                 entries" selector, "Showing X of Y" info and pagination
                                 all sit in sibling flex rows outside this container, so a
                                 horizontal scroll of the table body cannot shift them. */
                              .table-responsive-container { width: 100%; max-width: 100%; overflow-x: auto; overflow-y: visible; -webkit-overflow-scrolling: touch; }

                              /* ---- Anchor the top/bottom DataTables control rows ----
                                 The dom-generated .grp-dt-top and .grp-dt-bot rows carry
                                 Bootstrap grid columns inside, but we also want them to
                                 be flex-anchored (length / info on the left; search /
                                 pagination on the right) at every breakpoint, not just
                                 col-sm+. */
                              .grp-dt-top, .grp-dt-bot { width: 100%; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; row-gap: .35rem; }
                              .grp-dt-top > div, .grp-dt-bot > div { display: flex; align-items: center; }
                              .grp-dt-top .dataTables_length,
                              .grp-dt-top .dataTables_filter,
                              .grp-dt-bot .dataTables_info,
                              .grp-dt-bot .dataTables_paginate { margin: 0; }
                              .grp-dt-bot .pagination { margin: 0; }

                              /* ---- Base compaction ---- */
                              #table.table { font-size: 12px; margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
                              #table.table thead th { white-space: nowrap; padding: 4px 6px; vertical-align: middle; background: #f1f3f5; font-size: 12px; }
                              #table.table tbody td { padding: 4px 6px; vertical-align: middle; background: #fff; font-size: 12px; }
                              #table.table.table-hover tbody tr:hover td { background: #f6f8fa; }
                              #table.table td.text-center { white-space: nowrap; }

                              /* ---- Column-specific sizing ----
                                 Widths are fixed so the cumulative left-offsets below for
                                 the sticky columns stay in sync:
                                   Checkbox 40px  -> Sample ID starts at left: 40px
                                   Sample ID 90px -> Name starts at left: 40 + 90 = 130px */
                              /* 2026-10-14: every fixed-width rule below is scoped to
                                 `thead tr:first-child`. <thead> has a second row holding the AST
                                 sub-headers whenever $grpNAbx > 0, and :nth-child() counts within
                                 each parent row -- so unscoped, these identifier-column widths also
                                 landed on CZO (90px), LEX (160px) and CIP (110px), fighting the 70px
                                 the AST body cells use and knocking the sub-headers out of step with
                                 the columns they label. Same root cause as the sticky overlap below.
                                 The tbody halves stay unscoped: body rows are flat, one cell per
                                 column, so there is no second row to leak onto. */
                              /* Checkbox (col 1) — fixed 40px. */
                              #table.table thead tr:first-child th:nth-child(1),
                              #table.table tbody td:nth-child(1) { width: 40px; min-width: 40px; max-width: 40px; }
                              /* Sample ID (col 2) — nowrap + fixed 90px. */
                              #table.table thead tr:first-child th:nth-child(2),
                              #table.table tbody td:nth-child(2) { white-space: nowrap; width: 90px; min-width: 90px; max-width: 90px; font-weight: 500; }
                              /* Name (col 3) — fixed 160px, wraps gracefully. */
                              #table.table thead tr:first-child th:nth-child(3),
                              #table.table tbody td:nth-child(3) { width: 160px; min-width: 160px; max-width: 180px; white-space: normal; word-wrap: break-word; }
                              /* CID No. (col 4, 2026-10-07) — compact alphanumeric column
                                 sitting between Name and Age/Sex. Non-sticky so the three
                                 identifier columns to its left (Checkbox / Sample ID / Name)
                                 remain the only pinned header; sized just wide enough to
                                 hold a 16-20 char CID without wrapping. */
                              #table.table thead tr:first-child th:nth-child(4),
                              #table.table tbody td:nth-child(4) { width: 110px; min-width: 100px; max-width: 140px; white-space: nowrap; font-variant-numeric: tabular-nums; }
                              /* Bacteriology / PCR / ELISA — text-heavy, allow wrap.
                                 (Shifted one slot right by the CID No. insertion.) */
                              #table.table tbody td:nth-child(6),
                              #table.table tbody td:nth-child(7),
                              #table.table tbody td:nth-child(8) { min-width: 120px; max-width: 220px; white-space: normal; word-wrap: break-word; }

                              /* ---- Uniform AST sub-columns (col 9 .. second-to-last) ----
                                 60-80px fixed band, centred three-letter codes (AMP / CZO /
                                 LEX / CIP, plus any extras). :not(:last-child) excludes the
                                 always-present AMR phenotypes column so it keeps its wider
                                 sizing below. Padding stays modest. (Shifted from n+8 to
                                 n+9 by the CID No. insertion.) */
                              #table.table thead th:nth-child(n+9):not(:last-child),
                              #table.table tbody td:nth-child(n+9):not(:last-child) {
                                min-width: 60px; width: 70px; max-width: 80px;
                                padding-left: 4px; padding-right: 4px;
                                text-align: center;
                                white-space: nowrap;
                              }
                              /* Strip DataTables' sort-arrow icon + its right-side padding on
                                 the AST sub-column headers. The columnDefs below also marks
                                 these columns `orderable:false` so clicking the header does
                                 nothing -- consistent with the missing arrow. */
                              #table.table thead th:nth-child(n+9):not(:last-child).sorting,
                              #table.table thead th:nth-child(n+9):not(:last-child).sorting_asc,
                              #table.table thead th:nth-child(n+9):not(:last-child).sorting_desc,
                              #table.table thead th:nth-child(n+9):not(:last-child).sorting_disabled {
                                background-image: none !important;
                                padding-right: 4px !important;
                                cursor: default;
                              }

                              /* ---- AMR phenotypes cell (last column) ----
                                 Carries multiple tokens; wraps rather than stretches. */
                              #table.table thead th:last-child,
                              #table.table tbody td:last-child { min-width: 150px; max-width: 220px; white-space: normal; word-wrap: break-word; text-align: center; }

                              /* ---- Sticky-left columns: Checkbox + Sample ID + Name ----
                                 All three anchor to the left edge of .table-responsive-container
                                 as the user scrolls right, and scrolled cells must slide
                                 UNDERNEATH them.

                                 2026-10-14 FIX -- the thead selectors are scoped to
                                 `thead tr:first-child`. <thead> has TWO rows whenever AST
                                 columns are present ($grpNAbx > 0): row 1 holds the pinned
                                 columns (rowspan=2), row 2 holds the AST sub-headers (AMP,
                                 CZO, LEX, CIP...). :nth-child() counts within each PARENT
                                 ROW, so the old unscoped `thead th:nth-child(1..3)` ALSO
                                 matched the first three sub-headers and pinned AMP / CZO /
                                 LEX to left: 0 / 40px / 130px at the SAME z-index: 10 as the
                                 real pinned columns. Equal z-index resolves by document
                                 order and the sub-header row comes later, so the sub-headers
                                 painted on top and appeared to slide OVER Sample ID / Name
                                 when scrolling right.

                                 Stacking order, lowest to highest:
                                   normally-flowing cells        z-index: auto (static)
                                   AST sub-header row            z-index: auto (static; reset
                                                                 explicitly below)
                                   pinned tbody cells            z-index: 5
                                   pinned thead cells (row 1)    z-index: 10
                                 The numbers stay deliberately small -- these cells live
                                 inside the card, and inflating them would start competing
                                 with AdminLTE's own layers (navbar, dropdowns, modals).
                                 Correct ORDER is what fixes this, not bigger numbers.

                                 Solid, non-transparent backgrounds (thead #f8f9fa, tbody
                                 #ffffff) stop text bleeding through from underneath.

                                 Left offsets are cumulative:
                                     Checkbox   left: 0px     (40px wide)
                                     Sample ID  left: 40px    (90px wide)
                                     Name       left: 130px   (last sticky; carries the shadow) */
                              #table.table tbody td:nth-child(1),
                              #table.table tbody td:nth-child(2),
                              #table.table tbody td:nth-child(3) { position: sticky; z-index: 5; background-color: #ffffff; }
                              #table.table thead tr:first-child th:nth-child(1),
                              #table.table thead tr:first-child th:nth-child(2),
                              #table.table thead tr:first-child th:nth-child(3) { position: sticky; z-index: 10; background-color: #f8f9fa; }
                              #table.table thead tr:first-child th:nth-child(1),
                              #table.table tbody td:nth-child(1) { left: 0; }
                              #table.table thead tr:first-child th:nth-child(2),
                              #table.table tbody td:nth-child(2) { left: 40px; }
                              #table.table thead tr:first-child th:nth-child(3),
                              #table.table tbody td:nth-child(3) { left: 130px; box-shadow: 2px 0 5px -2px rgba(0, 0, 0, 0.15); }

                              /* Belt and braces: the AST sub-header row must never be pinned,
                                 whatever a future selector does. It scrolls with the body
                                 columns it labels, and an explicit static position with auto
                                 z-index means it can never tie with the pinned cells and win
                                 on document order. */
                              #table.table thead tr:nth-child(2) th { position: static; z-index: auto; left: auto; box-shadow: none; }

                              /* Keep .table-bordered's right-edge border on the frozen cells
                                 so scrolled content doesn't abut them. */
                              #table.table.table-bordered thead tr:first-child th:nth-child(1),
                              #table.table.table-bordered thead tr:first-child th:nth-child(2),
                              #table.table.table-bordered thead tr:first-child th:nth-child(3),
                              #table.table.table-bordered tbody td:nth-child(1),
                              #table.table.table-bordered tbody td:nth-child(2),
                              #table.table.table-bordered tbody td:nth-child(3) { border-right: 1px solid #dee2e6; }

                              /* Hover state propagates to the frozen cells. */
                              #table.table.table-hover tbody tr:hover td:nth-child(1),
                              #table.table.table-hover tbody tr:hover td:nth-child(2),
                              #table.table.table-hover tbody tr:hover td:nth-child(3) { background: #f6f8fa; }

                              /* DataTables sort-active column background should not look through
                                 the frozen Sample ID cell when it is the sort column. */
                              #table.table.dataTable tbody tr td.sorting_1 { background-color: #fafbfd; }
                            </style>
                            <!-- Table is intentionally NOT wrapped by a .table-responsive div
                                 here anymore. DataTables' `dom` option (set further down)
                                 places just the <table> inside a `.table-responsive-container`,
                                 which carries `overflow-x: auto`. That way the Search box,
                                 "Show entries" selector, "Showing X of Y" info and
                                 pagination controls sit in sibling flex rows ABOVE and
                                 BELOW the scroll container, so a horizontal scroll of the
                                 table body never shifts them. -->
                            <table id="table" class="table table-bordered table-hover">
                                <thead>
                                    <!-- Same layout as the PDF "Result Template for Outbreak/Group
                                         Report", minus the Subject Type column. The Subject Type
                                         filter above the table still POSTs to report-group-pdf.php
                                         to narrow the generated PDF; it no longer narrows this
                                         on-screen list, because there is no column here for the
                                         client-side filter to search. There is no Last Updated On
                                         column either -- it was hidden the same way and showed a
                                         FOUC flash on a hard refresh, and nothing else used it. -->
<?php $rs = $grpNAbx > 0 ? ' rowspan="2"' : ''; ?>
                                    <tr class="text-center">
                                        <th<?=$rs;?> style="width:34px;"><input type="checkbox" id="checkAll" title="Select all (all pages)" /></th>
                                        <th<?=$rs;?>>Sample ID</th>
                                        <th<?=$rs;?>>Name</th>
                                        <th<?=$rs;?>>CID No.</th>
                                        <th<?=$rs;?>>Age/Sex</th>
                                        <th<?=$rs;?>>Bacteriology result</th>
                                        <th<?=$rs;?>>PCR result</th>
                                        <th<?=$rs;?>>ELISA result</th>
<?php if ($grpNAbx > 0): ?>
                                        <th colspan="<?=$grpNAbx;?>">Antibiotic Susceptibility Testing</th>
<?php endif; ?>
                                        <!-- AMR phenotypes sits at the end so it spans both header rows
                                             when the AST sub-headers render (rowspan via $rs); when no
                                             AST columns are present the parent row collapses to a single
                                             header line and this cell renders as a plain <th>. -->
                                        <th<?=$rs;?>>AMR phenotypes</th>
                                    </tr>
<?php if ($grpNAbx > 0): ?>
                                    <tr class="text-center">
                                        <?php foreach ($grpVisibleCodes as $grpCode){ echo '<th>'.htmlspecialchars($grpCode, ENT_QUOTES).'</th>'; } ?>
                                    </tr>
<?php endif; ?>
                                </thead>
                                <tbody>
                                <?php

                                    foreach ($grpRows as $gr) {
                                        $sid = $gr['sid'];
                                        /* Category label kept for the on-screen Subject Type filter -- rendered
                                           into a data-cat attribute on the <tr> rather than as a visible cell,
                                           so the client-side filter has something to search without adding a
                                           column back to the layout. The dropdown's server-side role (POST to
                                           report-group-pdf.php) is unchanged. */
                                        if     ($gr['cat'] === '2') { $category = 'Outbreak'; }
                                        elseif ($gr['cat'] === '1') { $category = 'Surveillance'; }
                                        else                        { $category = ''; }
                                        $ageSex    = trim($gr['age'] . (($gr['age'] !== '' && $gr['sex'] !== '') ? ' / ' : '') . $gr['sex']);
                                        $bactText  = isset($grpBact[$sid]) ? $grpJoin($grpBact[$sid]) : $grpDash;
                                        $pcrText   = isset($grpPcrPos[$sid]) ? $grpJoin($grpPcrPos[$sid])
                                                   : (isset($grpPcrOther[$sid]) ? $grpStatus($grpPcrOther[$sid]) : $grpDash);
                                        $elisaText = isset($grpElisaPos[$sid]) ? $grpJoin($grpElisaPos[$sid])
                                                   : (isset($grpElisaOther[$sid]) ? $grpStatus($grpElisaOther[$sid]) : $grpDash);
                                        $cidText = isset($grpCidNo[$sid]) ? $grpCidNo[$sid] : $grpDash;
                                        echo("<tr data-cat='".$grpH($category)."'>");
                                        echo("<td class='text-center'><input type='checkbox' class='subjChk' value='".$grpH($sid)."' /></td>");
                                        echo("<td>".$grpH($sid)."</td>");
                                        echo("<td>".$grpH($gr['name'])."</td>");
                                        echo("<td class='text-center'>".$grpH($cidText)."</td>");
                                        echo("<td class='text-center'>".($ageSex !== '' ? $grpH($ageSex) : $grpDash)."</td>");
                                        echo("<td>".$grpH($bactText)."</td>");
                                        echo("<td>".$grpH($pcrText)."</td>");
                                        echo("<td>".$grpH($elisaText)."</td>");
                                        foreach ($grpVisibleCodes as $code) {
                                            echo("<td class='text-center'>".$grpH(isset($grpAst[$sid][$code]) ? $grpResolveAst($grpAst[$sid][$code]) : $grpDash)."</td>");
                                        }
                                        /* AMR phenotypes cell: comma-joined tokens from the shared helper
                                           (MDR, ESBL, CRE, CPE (MBL)/CPE (Serine)/CPE, FQ-R) or "-" when
                                           none of those phenotypes were triggered for this subject. */
                                        $grpPhenCell = (isset($grpPhen[$sid]) && is_array($grpPhen[$sid]) && count($grpPhen[$sid]) > 0)
                                                     ? implode(', ', $grpPhen[$sid]) : $grpDash;
                                        echo("<td class='text-center'>".$grpH($grpPhenCell)."</td>");
                                        echo("</tr>");
                                    }
                                ?>
                            </tbody>
                            </table>
                            <!-- (end of the <table>; the enclosing
                                 `.table-responsive-container` is created by DataTables
                                 via its `dom` option, see the init block below.) -->
                            <?php
                                /* Say so when the cap is reached, rather than letting a
                                   truncated list read as "these are all the subjects". */
                                if ($listed >= 500) {
                                    echo('<small class="text-muted">Showing the 500 most recently recorded subjects. '
                                       . 'Use the search box above to find a subject within this list.</small>');
                                } else {
                                    echo('<small class="text-muted">' . $listed . ' subject' . ($listed === 1 ? '' : 's') . ' available.</small>');
                                }
                            ?>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->
<script>
$(function () {
    // Column 0 is the selection checkbox; 1 Sample ID; 2 Name; 3 Age/Sex;
    // 4 Bacteriology; 5 PCR; 6 ELISA; then ONE COLUMN PER ANTIBIOTIC THAT HAS
    // DATA (grouped under "Antibiotic Susceptibility Testing"); and finally
    // the AMR phenotypes column at the end. The Subject Type column was
    // removed but the dropdown still filters
    // the on-screen list instantly, without a page reload: each <tr> carries
    // a data-cat attribute (set in PHP above), and the DataTables custom search
    // below reads that attribute and matches it against #subject_type's value.
    // The dropdown's server-side role (POST to report-group-pdf.php for PDF
    // generation) is unchanged.
    var table = $('#table').DataTable({
    /* `dom` layout isolates the table in its own scroll container.
       -------------------------------------------------------------------
       Resulting DOM (inside .dataTables_wrapper):

         <div class="grp-dt-top">
           <div><label>Show entries selector</label></div>   <-- 'l'
           <div><label>Search box</label></div>              <-- 'f'
         </div>
         <div class="table-responsive-container">            <-- scroll area
           <div class="dataTables_processing">...            <-- 'r'
           <table id="table">...                             <-- 't'
         </div>
         <div class="grp-dt-bot">
           <div><div class="dataTables_info">...             <-- 'i'
           <div><ul class="pagination">...                   <-- 'p'
         </div>

       The .grp-dt-top / .grp-dt-bot wrappers are styled above as
       `display:flex; justify-content:space-between`, so the length
       control sits top-left and Search top-right at every width;
       pagination sits bottom-right and info bottom-left. Neither row
       is a child of .table-responsive-container, so a horizontal
       scroll of the table body cannot shift them. */
    "dom":
        "<'grp-dt-top'" +
            "<'grp-dt-top__left'l>" +
            "<'grp-dt-top__right'f>" +
        ">" +
        "<'table-responsive-container'rt>" +
        "<'grp-dt-bot'" +
            "<'grp-dt-bot__left'i>" +
            "<'grp-dt-bot__right'p>" +
        ">",
    "paging": true,
    "lengthChange": true,
    "searching": true,
    "ordering": true,
    "info": true,
    "autoWidth": false,
    /* `responsive: false` keeps every column visible at every zoom level --
       no automatic child-row collapsing, no red "+" toggle buttons on
       narrow screens. Horizontal overflow is handled by the surrounding
       .table-responsive wrapper (Bootstrap's `overflow-x: auto`) so the
       whole table scrolls as one unit when the viewport cannot fit it. */
    "responsive": false,
    "columnDefs": [
        /* Column 0 is the selection checkbox (not a data cell).
           Columns 8 .. 7 + $grpNAbx are the AST sub-columns (AMP / CZO / LEX /
           CIP ...) -- they only exist when at least one listed subject has an
           AST result, and sorting a 3-char cell group gives no value. The
           CSS above also strips the sort-arrow icon so the header reads as a
           plain label; this definition stops click-to-sort server-side.
           (Shifted from 7 / 6+N by the CID No. insertion at column 3.) */
        { "targets": 0, "orderable": false, "searchable": false }<?php if ($grpNAbx > 0): ?>,
        { "targets": [<?php echo implode(',', range(8, 7 + $grpNAbx)); ?>], "orderable": false, "searchable": false }<?php endif; ?>
    ],
    /* No default sort: the rows arrive from the query already ordered
       (recorded_on DESC, subject_id DESC), and the Last Updated On column that
       used to drive the default sort is gone. Clicking a header still sorts
       exactly as before. */
    "order": []
    });

    /* ----------------------------------------------------------------
       Multi-page selection.

       DataTables keeps only the current page in the DOM, so reading the
       ticked checkboxes at submit time would silently lose everything the
       user picked on other pages. The authoritative selection therefore
       lives in this object, and the checkboxes are just a view of it:
         - ticking/unticking updates the object
         - every redraw re-applies the object to the visible checkboxes
         - submit serialises the object into hidden inputs
       ---------------------------------------------------------------- */
    var selected = {};

    function selectedIds() { return Object.keys(selected); }

    function refreshCount() {
        var n = selectedIds().length;
        // Text is unchanged; .has-selection just swaps the badge from neutral to
        // the primary accent once something is picked (styled in bdsis-theme.css).
        $('#selCount')
            .text(n === 0 ? 'No subject selected'
                          : n + ' subject' + (n === 1 ? '' : 's') + ' selected')
            .toggleClass('has-selection', n > 0);
    }

    function syncVisible() {
        var allTicked = true, any = false;
        $('#table tbody input.subjChk').each(function () {
            var on = !!selected[this.value];
            this.checked = on;
            any = true;
            if (!on) { allTicked = false; }
        });
        $('#checkAll').prop('checked', any && allTicked);
    }

    /* ----------------------------------------------------------------
       Subject Type on-screen filter.

       The visible Subject Type column is gone, but each <tr> carries a
       data-cat attribute set in PHP ("Outbreak" / "Surveillance" / "").
       A custom DataTables search callback reads that attribute for each
       candidate row -- it fires for every row on every draw() -- and
       filters by the dropdown's current value:
         - "" (All)   -> keep every row
         - "1"        -> keep only rows with data-cat="Surveillance"
         - "2"        -> keep only rows with data-cat="Outbreak"

       Changing the dropdown resets the selection (rows that fall out of
       view should not stay ticked) and triggers table.draw(), which the
       callback re-runs. This is instant -- no AJAX, no re-query.

       The dropdown is ALSO submitted with the form to report-group-pdf.php,
       so the generated PDF's server-side subject_type filter continues to
       work exactly as before. That server-side role is independent of this
       on-screen callback. */
    $.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData, counter) {
        if (settings.nTable.id !== 'table') { return true; }   // only affect this table
        var want = $('#subject_type').val() || '';
        if (want === '') { return true; }                       // "All" -- no filter
        var row = settings.aoData[index] && settings.aoData[index].nTr;
        var cat = row ? (row.getAttribute('data-cat') || '') : '';
        if (want === '1') { return cat === 'Surveillance'; }
        if (want === '2') { return cat === 'Outbreak'; }
        return true;
    });

    $('#subject_type').on('change', function () {
        /* Reset selection: rows that disappear from view must not stay ticked,
           and the count badge must reflect what the user has now selected -- which
           after a filter change is nothing. Then redraw so the custom filter
           re-runs against the new dropdown value. */
        selected = {};
        refreshCount();
        table.draw();
        syncVisible();
    });

    $('#table tbody').on('change', 'input.subjChk', function () {
        if (this.checked) { selected[this.value] = true; }
        else { delete selected[this.value]; }
        refreshCount();
        syncVisible();
    });

    // Select-all applies to every row matching the current search/filter,
    // not just the visible page.
    $('#checkAll').on('change', function () {
        var on = this.checked;
        table.rows({ search: 'applied' }).nodes().to$().find('input.subjChk').each(function () {
            if (on) { selected[this.value] = true; }
            else { delete selected[this.value]; }
        });
        refreshCount();
        syncVisible();
    });

    table.on('draw', syncVisible);

    $('#groupForm').on('submit', function () {
        var ids = selectedIds();
        if (ids.length === 0) {
            // Swal rather than alert() to match the rest of the app.
            if (window.Swal) {
                Swal.fire({
                    icon: 'info',
                    title: 'No subject selected',
                    text: 'Tick at least one subject in the list below, then generate the report.',
                    confirmButtonText: 'OK'
                });
            } else {
                alert('Please select at least one subject to include in the group report.');
            }
            return false;
        }
        var box = $('#selHidden').empty();
        $.each(ids, function (i, id) {
            box.append($('<input>', { type: 'hidden', name: 'sids[]' }).val(id));
        });
        return true;
    });

    refreshCount();
});
</script>
<!-- Page footer -->
<?php require_once("../includes/footer.php"); ?>
