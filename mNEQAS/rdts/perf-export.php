<?php
/**
 * RDT Lot Test — Performance Report export (.xlsx)
 * ============================================================================
 * Fills the client's "RDT LOT testing worksheet" rather than redrawing it: the
 * supplied workbook ships with this file as
 *
 *      rdts/templates/rdt-lot-testing-worksheet.xlsx
 *
 * and every part of it — styles, theme, fonts, column widths, merges, borders,
 * wording — is copied into the download untouched. Only the cells that carry
 * data are rewritten, each keeping the style the template gave it. One sheet
 * per product of the request, cloned from the template's single sheet.
 *
 *   perf-export.php?id=<rdt_request_id>
 *
 * WHERE EACH CELL COMES FROM
 *   B2            Date of lot testing   <- rdt_performance_report.test_date,
 *                                          else rdt_test_request.date_receive,
 *                                          else date_request
 *   A5            RDT (Name/brand)      <- rdt_product_details.product_name
 *   B5            Manufacturer          <- manufacturer
 *   C5            Catalog no            <- catalog_no WHEN THAT COLUMN EXISTS
 *                                          (see the note below), else blank
 *   D5            Lot No                <- lot_no
 *   E5            Manufactur date       <- date_manufacture
 *   F5            Expiry date           <- date_expiry
 *   E18           % Score (Negative)    <- tn_rate
 *   C27 / D27     % Score  200 p/ul     <- pf_pds_200  / pv_pds_200
 *   C32 / D32     % Score 2000 p/ul     <- pf_pds_2000 / pv_pds_2000
 *
 * THE SUMMARY BLOCK, COLUMNS H-L
 * Beside the bench form, in columns the template leaves free, the same product
 * is summarised with LIVE EXCEL FORMULAS rather than numbers computed here, so
 * the sheet keeps recalculating as the bench fills it in.
 *
 *   H3:L3   headings   Panel / Total Tested / Positive / % Positive /
 *                      Recorded Score
 *   H4:L7   the four panels; I and J are left for the bench (see below),
 *           K{r} = IF(I{r}>0,(J{r}/I{r})*100,"")  and L{r} the stored score
 *           <- pf_pds_200 / pf_pds_2000 / pv_pds_200 / pv_pds_2000
 *   H9:L9   Negative Control - 0; here the operands ARE stored, so
 *           I9 <- total_tests, J9 <- true_negative, L9 <- tn_rate, and
 *           K9 = IF(I9>0,(J9/I9)*100,"") evaluates as soon as the file opens
 *   H12:K12 headings   Metric / Count / Total Tests / Value (%)
 *   H13:K15 % Specificity    I13 <- true_negative,  J13 <- total_tests
 *           % False Positive I14 <- false_positive, J14 <- total_tests
 *           % Invalid Rate   no count is stored, so I15 is blank
 *           K{r} = IF(J{r}>0,(I{r}/J{r})*100,"")
 *
 * Rows 4-7 carry no operands because the system stores a PERCENTAGE per panel
 * and never the tested/positive counts behind it; the stored percentage is put
 * in L instead, so a typed result can be checked against what the system holds.
 * Every formula is guarded with IF(...>0, ...) so an unfilled row shows empty
 * rather than #DIV/0!; the arithmetic is otherwise exactly as specified. The
 * workbook is marked fullCalcOnLoad because these formulas ship with no cached
 * result.
 *
 * WHY ROWS 8-17, 19-26 AND 28-31 COME OUT EMPTY
 * The worksheet is a bench form with one row per individual control read. The
 * wizard's Performance Report step stores AGGREGATES per product and nothing
 * per control: the whole RDT module uses five tables — rdt_test_request,
 * rdt_product_details, rdt_shipment_details, rdt_assessment_report and
 * rdt_performance_report — and the performance step writes exactly twenty
 * aggregate columns, none of them a per-control reading. So those rows are left
 * ruled and empty for the bench to complete, and each block's aggregate is
 * written into the "% Score" row that closes it. Deriving per-control values
 * from the true-negative / false-positive counts would be inventing lab data:
 * the counts say how many were correct, never which ones.
 *
 * CATALOG NO
 * rdt_product_details has no catalog_no column and the product form does not
 * ask for one, so C5 is blank today. The lookup is feature-detected: add the
 * column (see 2026-10-05-rdt-product-catalog-no.sql) and this export fills C5
 * with no further change here.
 *
 * Nothing in this file writes to the database, and it is reachable only by a
 * user who may already open the request — mn_require_record is the same IDOR
 * guard rdt-registration.php uses.
 */
require_once("../config/session.php");
require_once("../config/auth.php");
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");

define('RDTX_TEMPLATE', __DIR__ . '/templates/rdt-lot-testing-worksheet.xlsx');
define('RDTX_SHEET',    'xl/worksheets/sheet1.xml');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('HTTP/1.1 404 Not Found'); echo 'Request not found.'; exit; }
if (!class_exists('ZipArchive')) { header('HTTP/1.1 500 Internal Server Error'); echo 'Export is unavailable on this server.'; exit; }
if (!is_file(RDTX_TEMPLATE) || !is_readable(RDTX_TEMPLATE)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'The worksheet template is missing on the server (rdts/templates/rdt-lot-testing-worksheet.xlsx).';
    exit;
}

/* Same record-level guard as rdt-registration.php: a facility user may export
   only their own facility's request. */
mn_require_record($conn, 'rdt_request', $id);

/* ------------------------------------------------------------------ data -- */
$st = $conn->prepare("SELECT rdt_request_id, facility_id, date_request, date_receive, lot_type
                        FROM rdt_test_request WHERE rdt_request_id = ?");
$st->bind_param('i', $id);
$st->execute();
$req = $st->get_result()->fetch_assoc();
$st->close();
if (!$req) { header('HTTP/1.1 404 Not Found'); echo 'Request not found.'; exit; }

/* Catalog no is optional schema: selected only when the column is there, so
   this runs unchanged before and after the migration that adds it. */
$catCol  = '';
$catChk  = @$conn->query("SHOW COLUMNS FROM rdt_product_details LIKE 'catalog_no'");
$hasCat  = ($catChk && $catChk->num_rows > 0);
if ($hasCat) { $catCol = ', p.catalog_no'; }

/* Every product of the request, with its performance report when one exists.
   rdt_performance_report.manufacturer_id holds the PRODUCT id despite its name
   — that is how the wizard links the two. */
$st = $conn->prepare("SELECT p.product_id, p.product_name, p.manufacturer, p.lot_no,
                             p.date_manufacture, p.date_expiry$catCol,
                             r.pf_pds_200, r.pf_pds_2000,
                             r.pv_pds_200, r.pv_pds_2000,
                             r.tn_rate, r.test_date,
                             r.total_tests, r.true_negative, r.false_positive
                        FROM rdt_product_details p
                        LEFT JOIN rdt_performance_report r ON r.manufacturer_id = p.product_id
                       WHERE p.test_request_id = ?
                    ORDER BY p.product_id");
$st->bind_param('i', $id);
$st->execute();
$rs = $st->get_result();
$products = array();
while ($row = $rs->fetch_assoc()) { $products[] = $row; }
$st->close();

if (!count($products)) {
    header('HTTP/1.1 404 Not Found');
    echo 'This request has no products to export.';
    exit;
}

/* ====================================================================== *
 *  Filling the template
 *
 *  The template's cells are plain and self-contained — <c r="B2" s="5"/> or
 *  <c r="A2" s="1" t="s"><v>3</v></c> — so a cell is replaced whole, carrying
 *  its own s= style across. That keeps the substitution exact without having
 *  to touch sharedStrings.xml: values go in as inline strings.
 * ====================================================================== */
function xEsc($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/* A stored date that was never entered comes back as a zero date; either way
   the worksheet cell stays blank rather than showing it. */
function xDate($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') { return ''; }
    return $v;
}

/* Replace one cell in the sheet XML. Returns false when the reference is not in
   the template, so the caller can refuse to ship a half-filled workbook. */
function xPutCell(&$xml, $ref, $value, $numeric = false) {
    /* <c r="REF" ...> ... </c>  or the empty  <c r="REF" .../>  */
    $re = '#<c\s+r="' . preg_quote($ref, '#') . '"([^>]*?)(/>|>.*?</c>)#s';
    if (!preg_match($re, $xml, $m)) { return false; }

    $style = '';
    if (preg_match('#\ss="(\d+)"#', $m[1], $sm)) { $style = ' s="' . $sm[1] . '"'; }

    if ($value === null || $value === '') {
        $cell = '<c r="' . $ref . '"' . $style . '/>';
    } elseif ($numeric && is_numeric($value)) {
        $cell = '<c r="' . $ref . '"' . $style . '><v>' . (0 + $value) . '</v></c>';
    } else {
        $cell = '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
              . xEsc($value) . '</t></is></c>';
    }
    $xml = preg_replace($re, $cell, $xml, 1);
    return true;
}

/* Append a cell to a row that does not already contain it.
   Every cell this adds lives in columns H..L, which sort after the template's
   A..F, so appending to the end of the row keeps the ascending column order
   Excel requires - no ordered insertion needed. The template carries rows 1-35
   already, so the row is always there to append to.
   Pass $formula to write a live formula instead of a value. */
function xAddCell(&$xml, $ref, $value, $numeric = false, $style = '', $formula = null) {
    if (!preg_match('#^([A-Z]+)(\d+)$#', $ref, $rm)) { return false; }
    $rowN = (int)$rm[2];

    $st   = ($style !== '') ? ' s="' . $style . '"' : '';
    if ($formula !== null && $formula !== '') {
        $cell = '<c r="' . $ref . '"' . $st . '><f>' . xEsc($formula) . '</f></c>';
    } elseif ($value === null || $value === '') {
        $cell = '<c r="' . $ref . '"' . $st . '/>';
    } elseif ($numeric && is_numeric($value)) {
        $cell = '<c r="' . $ref . '"' . $st . '><v>' . (0 + $value) . '</v></c>';
    } else {
        $cell = '<c r="' . $ref . '"' . $st . ' t="inlineStr"><is><t xml:space="preserve">'
              . xEsc($value) . '</t></is></c>';
    }

    /* already present (shouldn't be for H..L, but keep it idempotent) */
    $has = '#<c\s+r="' . $ref . '"([^>]*?)(/>|>.*?</c>)#s';
    if (preg_match($has, $xml, $hm)) {
        $keep = $st;
        if ($style === '' && preg_match('#\ss="(\d+)"#', $hm[1], $sm)) {
            $keep = ' s="' . $sm[1] . '"';
            $cell = str_replace('<c r="' . $ref . '"', '<c r="' . $ref . '"' . $keep, $cell);
            $cell = preg_replace('#(<c r="' . $ref . '") s="\d+"( s="\d+")#', '$1$2', $cell);
        }
        $xml = preg_replace($has, $cell, $xml, 1);
        return true;
    }

    /* splice before this row's </row>, by offset rather than preg_replace so a
       $ or backslash anywhere in the row body cannot be taken as a backreference */
    if (!preg_match('#<row[^>]*\sr="' . $rowN . '"[^>]*>#', $xml, $om, PREG_OFFSET_CAPTURE)) { return false; }
    $start = $om[0][1] + strlen($om[0][0]);
    $end   = strpos($xml, '</row>', $start);
    if ($end === false) { return false; }
    $xml = substr($xml, 0, $end) . $cell . substr($xml, $end);
    return true;
}

/* One product's sheet: the template sheet with its data cells rewritten. */
function xFillSheet($sheetXml, array $p, $fallbackDate, array &$missing) {
    $testDate = xDate(isset($p['test_date']) ? $p['test_date'] : '');
    if ($testDate === '') { $testDate = xDate($fallbackDate); }

    $cells = array(
        array('B2',  $testDate,                                                     false),
        array('A5',  (string)$p['product_name'],                                     false),
        array('B5',  (string)$p['manufacturer'],                                     false),
        array('C5',  isset($p['catalog_no']) ? (string)$p['catalog_no'] : '',         false),
        array('D5',  (string)$p['lot_no'],                                           false),
        array('E5',  xDate($p['date_manufacture']),                                  false),
        array('F5',  xDate($p['date_expiry']),                                       false),
        array('E18', isset($p['tn_rate'])      ? $p['tn_rate']      : '',            true),
        array('C27', isset($p['pf_pds_200'])   ? $p['pf_pds_200']   : '',            true),
        array('D27', isset($p['pv_pds_200'])   ? $p['pv_pds_200']   : '',            true),
        array('C32', isset($p['pf_pds_2000'])  ? $p['pf_pds_2000']  : '',            true),
        array('D32', isset($p['pv_pds_2000'])  ? $p['pv_pds_2000']  : '',            true),
    );
    foreach ($cells as $c) {
        if (!xPutCell($sheetXml, $c[0], $c[1], $c[2])) { $missing[] = $c[0]; }
    }
    /* ---- Summary Results and Computed Metrics, columns H-L ----
       Native Excel formulas, so the sheet recalculates as the bench fills it in
       rather than carrying numbers frozen at export time.

       Rows 4-7 and 9 have no stored source: the system records a percentage
       score per panel, never the tested/positive counts behind it. I and J are
       therefore left for the bench and K holds the formula - that is the point
       of the block. The stored score sits in L so a typed result can be checked
       against what the system holds.

       The negative-control metrics DO have stored sources, so their operands are
       written and those formulas evaluate the moment the file opens.

       Each formula is guarded with IF(...>0, ...) rather than the bare division
       in the specification: an empty row would otherwise show #DIV/0! down the
       sheet, which reads as a broken export. The arithmetic is unchanged. */
    xAddCell($sheetXml, 'H2', 'Summary Results', false, '4');
    foreach (array('H3' => 'Panel', 'I3' => 'Total Tested', 'J3' => 'Positive',
                   'K3' => '% Positive', 'L3' => 'Recorded Score') as $ref => $txt) {
        xAddCell($sheetXml, $ref, $txt, false, '1');
    }

    $sumRows = array(
        4 => array('Pf 200 p/ul',  isset($p['pf_pds_200'])  ? $p['pf_pds_200']  : ''),
        5 => array('Pf 2000 p/ul', isset($p['pf_pds_2000']) ? $p['pf_pds_2000'] : ''),
        6 => array('Pv 200 p/ul',  isset($p['pv_pds_200'])  ? $p['pv_pds_200']  : ''),
        7 => array('Pv 2000 p/ul', isset($p['pv_pds_2000']) ? $p['pv_pds_2000'] : ''),
    );
    foreach ($sumRows as $r => $meta) {
        xAddCell($sheetXml, 'H' . $r, $meta[0], false, '2');
        xAddCell($sheetXml, 'I' . $r, '',       false, '2');
        xAddCell($sheetXml, 'J' . $r, '',       false, '2');
        xAddCell($sheetXml, 'K' . $r, null,     false, '2', 'IF(I' . $r . '>0,(J' . $r . '/I' . $r . ')*100,"")');
        xAddCell($sheetXml, 'L' . $r, $meta[1], true,  '2');
    }

    /* the negative control reads as a panel at dilution 0 */
    xAddCell($sheetXml, 'H9', 'Negative Control - 0', false, '2');
    xAddCell($sheetXml, 'I9', isset($p['total_tests'])   ? $p['total_tests']   : '', true, '2');
    xAddCell($sheetXml, 'J9', isset($p['true_negative']) ? $p['true_negative'] : '', true, '2');
    xAddCell($sheetXml, 'K9', null, false, '2', 'IF(I9>0,(J9/I9)*100,"")');   /* % Negative */
    xAddCell($sheetXml, 'L9', isset($p['tn_rate']) ? $p['tn_rate'] : '', true, '2');

    xAddCell($sheetXml, 'H11', 'Computed Metrics', false, '4');
    foreach (array('H12' => 'Metric', 'I12' => 'Count', 'J12' => 'Total Tests',
                   'K12' => 'Value (%)') as $ref => $txt) {
        xAddCell($sheetXml, $ref, $txt, false, '1');
    }

    $ngTotal = isset($p['total_tests']) ? $p['total_tests'] : '';
    $metrics = array(
        13 => array('% Specificity',    isset($p['true_negative'])  ? $p['true_negative']  : ''),
        14 => array('% False Positive', isset($p['false_positive']) ? $p['false_positive'] : ''),
        /* No invalid COUNT is stored for the negative-control panel - only the Pf
           and Pv invalid rates, which measure something else. The row is offered
           with its formula for the bench to complete rather than filled with a
           number that would not mean what the label says. */
        15 => array('% Invalid Rate',   ''),
    );
    foreach ($metrics as $r => $meta) {
        xAddCell($sheetXml, 'H' . $r, $meta[0], false, '2');
        xAddCell($sheetXml, 'I' . $r, $meta[1], true,  '2');
        xAddCell($sheetXml, 'J' . $r, $ngTotal, true,  '2');
        xAddCell($sheetXml, 'K' . $r, null,     false, '2', 'IF(J' . $r . '>0,(I' . $r . '/J' . $r . ')*100,"")');
    }

    /* The block above extends the sheet to column L. The template declares only
       A1:H35, and a dimension narrower than the content makes some readers skip
       the extra cells, so it is widened. Widths are set too, or the new columns
       render default-narrow and the labels are unreadable. */
    $sheetXml = preg_replace('#<dimension ref="A1:[A-Z]+(\d+)"/>#',
                             '<dimension ref="A1:L$1"/>', $sheetXml, 1);
    if (strpos($sheetXml, '<col min="8"') === false) {
        $sheetXml = preg_replace('#</cols>#',
              '<col min="8" max="8" width="22" customWidth="1"/>'
            . '<col min="9" max="10" width="13" customWidth="1"/>'
            . '<col min="11" max="12" width="15" customWidth="1"/></cols>', $sheetXml, 1);
    }

    return $sheetXml;
}

/* A sheet name Excel will accept: no : \ / ? * [ ], 31 characters, unique. */
function xSheetName($raw, array $taken, $n) {
    $name = trim(str_replace(array(':', '\\', '/', '?', '*', '[', ']'), ' ', (string)$raw));
    $name = preg_replace('/\s+/', ' ', $name);
    if ($name === '') { $name = 'Product ' . $n; }
    $name = mb_substr($name, 0, 31);
    if (in_array($name, $taken, true)) {
        $suffix = ' (' . $n . ')';
        $name   = mb_substr($name, 0, 31 - mb_strlen($suffix)) . $suffix;
    }
    return $name;
}

/* ------------------------------------------------- read the whole template -- */
$src = new ZipArchive();
if ($src->open(RDTX_TEMPLATE) !== true) {
    header('HTTP/1.1 500 Internal Server Error'); echo 'The worksheet template could not be opened.'; exit;
}
$parts = array();
for ($i = 0; $i < $src->numFiles; $i++) {
    $name = $src->getNameIndex($i);
    $parts[$name] = $src->getFromIndex($i);
}
$src->close();
if (!isset($parts[RDTX_SHEET]) || !isset($parts['xl/workbook.xml'])) {
    header('HTTP/1.1 500 Internal Server Error'); echo 'The worksheet template is not in the expected format.'; exit;
}
$sheetTpl = $parts[RDTX_SHEET];

/* ------------------------------------------------------- build the sheets -- */
$fallbackDate = xDate($req['date_receive']) !== '' ? $req['date_receive'] : $req['date_request'];
$sheets  = array();           // 1-based index => array(name, xml)
$taken   = array();
$missing = array();
foreach ($products as $pi => $p) {
    $name    = xSheetName($p['product_name'], $taken, $pi + 1);
    $taken[] = $name;
    $sheets[] = array('name' => $name, 'xml' => xFillSheet($sheetTpl, $p, $fallbackDate, $missing));
}
/* A reference the template no longer has would mean the asset was edited and
   the mapping has drifted — better to say so than hand over a wrong report. */
if (count($missing)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'The worksheet template no longer has the expected cells ('
       . xEsc(implode(', ', array_unique($missing))) . '). Export aborted.';
    exit;
}

$n = count($sheets);

/* workbook.xml: only the <sheets> block and the recalculation flag change;
   fileVersion, bookViews, calcId and the extLst survive exactly as the client
   saved them. */
$wbSheets = '';
foreach ($sheets as $i => $s) {
    $wbSheets .= '<sheet name="' . xEsc($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
}
$parts['xl/workbook.xml'] = preg_replace(
    '#<sheets>.*?</sheets>#s', '<sheets>' . $wbSheets . '</sheets>',
    $parts['xl/workbook.xml'], 1);

/* The summary block writes formulas with no cached result, because the result
   depends on cells the bench has still to fill. Readers that trust the cache
   show such a cell blank until something forces a recalculation, so the
   workbook is asked to recalculate the moment it opens. calcId is left alone. */
if (strpos($parts['xl/workbook.xml'], 'fullCalcOnLoad') === false) {
    $parts['xl/workbook.xml'] = preg_replace(
        '#<calcPr([^>]*?)/>#', '<calcPr$1 fullCalcOnLoad="1"/>',
        $parts['xl/workbook.xml'], 1);
}

/* workbook rels: the worksheets take rId1..rIdN, then whatever else the
   template related to the workbook (styles, theme, sharedStrings) is renumbered
   after them so nothing collides. */
$keep = array();
if (isset($parts['xl/_rels/workbook.xml.rels'])
    && preg_match_all('#<Relationship\b[^>]*/>#', $parts['xl/_rels/workbook.xml.rels'], $rm)) {
    foreach ($rm[0] as $rel) {
        if (strpos($rel, '/worksheet"') !== false) { continue; }   // replaced below
        $keep[] = $rel;
    }
}
$rels = '';
for ($i = 1; $i <= $n; $i++) {
    $rels .= '<Relationship Id="rId' . $i . '"'
           . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
           . ' Target="worksheets/sheet' . $i . '.xml"/>';
}
$next = $n + 1;
foreach ($keep as $rel) {
    $rels .= preg_replace('#\sId="[^"]*"#', ' Id="rId' . $next . '"', $rel, 1);
    $next++;
}
$parts['xl/_rels/workbook.xml.rels'] =
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
  . $rels . '</Relationships>';

/* [Content_Types].xml: one worksheet override per sheet, the rest untouched. */
$ctOverride = '';
for ($i = 1; $i <= $n; $i++) {
    $ctOverride .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml"'
                 . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
}
$ct = preg_replace(
    '#<Override PartName="/xl/worksheets/sheet1\.xml"[^>]*/>#', $ctOverride,
    $parts['[Content_Types].xml'], 1);
$parts['[Content_Types].xml'] = $ct;

/* docProps/app.xml names the sheets it was saved with; keep it in step so the
   file's properties do not contradict its contents. */
if (isset($parts['docProps/app.xml'])) {
    $titles = '';
    foreach ($sheets as $s) { $titles .= '<vt:lpstr>' . xEsc($s['name']) . '</vt:lpstr>'; }
    $app = $parts['docProps/app.xml'];
    $app = preg_replace('#(<TitlesOfParts><vt:vector size=")\d+(" baseType="lpstr">).*?(</vt:vector></TitlesOfParts>)#s',
                        '${1}' . $n . '${2}' . $titles . '${3}', $app, 1);
    $app = preg_replace('#(<HeadingPairs>.*?<vt:i4>)\d+(</vt:i4>)#s', '${1}' . $n . '${2}', $app, 1);
    $parts['docProps/app.xml'] = $app;
}

/* ------------------------------------------------------------ the package -- */
unset($parts[RDTX_SHEET]);
foreach ($sheets as $i => $s) { $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $s['xml']; }

$tmp = tempnam(sys_get_temp_dir(), 'rdtperf');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    header('HTTP/1.1 500 Internal Server Error'); echo 'Export failed. Please try again.'; exit;
}
foreach ($parts as $name => $data) { $zip->addFromString($name, $data); }
$zip->close();

$fname = 'RDT-Lot-Testing-Worksheet-Request-' . (int)$req['rdt_request_id'] . '-' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
exit;
