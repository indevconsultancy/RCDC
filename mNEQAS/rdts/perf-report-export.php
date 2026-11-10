<?php
/**
 * RDT Lot Test — Performance Report export (.xlsx)
 * ============================================================================
 * One worksheet per product of the opened request, each holding that product's
 * complete performance report: the Pf and Pv panel detection scores, the
 * negative control table and the tester information.
 *
 *   perf-export.php?id=<rdt_request_id>
 *
 * WHY THE WORKBOOK IS WRITTEN BY HAND
 * This installation has no PhpSpreadsheet — vendor/ carries mPDF and its
 * dependencies only, and adding a spreadsheet library would mean shipping a
 * new dependency tree to a live server. The Controller 1 export in Blinded
 * Rechecking already writes worksheet XML directly for the same reason; this
 * does the same, but builds the whole workbook rather than filling a template,
 * because there is no template for this report.
 *
 * Nothing here writes to the database. It reads the same rows the wizard's
 * Performance Report step renders, and is reachable only by a user who may
 * already open that request (mn_require_record is the same IDOR guard the
 * wizard page uses).
 */
require_once("../config/session.php");
require_once("../config/auth.php");
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('HTTP/1.1 404 Not Found'); echo 'Request not found.'; exit; }
if (!class_exists('ZipArchive')) { header('HTTP/1.1 500 Internal Server Error'); echo 'Export is unavailable on this server.'; exit; }

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

$facilityName = '';
if ($req['facility_id'] !== null && $req['facility_id'] !== '') {
    $fs = $commondb->prepare("SELECT center_name FROM ms_health_center_table WHERE center_id = ?");
    if ($fs) {
        $fs->bind_param('s', $req['facility_id']);
        $fs->execute();
        $fr = $fs->get_result()->fetch_row();
        $fs->close();
        if ($fr) { $facilityName = (string)$fr[0]; }
    }
}

/* Every product of the request, with its performance report when one exists.
   rdt_performance_report.manufacturer_id holds the PRODUCT id despite its name
   — that is how the wizard links the two. */
$st = $conn->prepare("SELECT p.product_id, p.product_name, p.manufacturer, p.lot_no,
                             p.date_manufacture, p.date_expiry,
                             r.pf_pd_200_temp, r.pf_pds_200, r.pf_pd_2000_temp, r.pf_pds_2000, r.pf_ivr,
                             r.pv_pd_200_temp, r.pv_pds_200, r.pv_pd_2000_temp, r.pv_pds_2000, r.pv_ivr,
                             r.ng_temperature, r.total_tests, r.true_negative, r.false_positive,
                             r.tn_rate, r.fp_rate,
                             r.test_by, r.designation, r.test_date, r.performance_result
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
 *  Minimal .xlsx writer — one flat table, one row per product
 *
 *  Row 1  group headers, merged over each group
 *  Row 2  column headers
 *  Row 3+ one row per product, contiguous
 *
 *  Columns A..AE (31). P and V are deliberately empty separators between the
 *  Pf, Pv and Negative Control groups, so they carry no header, no border and
 *  a narrow width.
 *
 *  The request columns (A..D) repeat on every product row: in a flat table a
 *  row has to stand on its own when it is filtered, sorted or pivoted.
 * ====================================================================== */
define('XS_GAPCOL',  0);   // separator column — no border, no fill
define('XS_GROUP',   1);   // row 1 group header — bold dark on soft blue
define('XS_HEAD',    2);   // row 2 column header — bold white on navy
define('XS_TEXT',    3);   // text value   — left
define('XS_NUM',     4);   // number 0.00  — centred
define('XS_INT',     5);   // whole count  — centred
define('XS_BLANK',   6);   // bordered empty data cell

function xEsc($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function xCol($n) { $s = ''; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; }

/* The sheet's shape, declared once and used to build the headers, the data
   rows and the column widths — so the three can never drift apart. */
function xLayout() {
    return array(
        array('group' => 'Request Information',          'cols' => array(
            array('Report Date',       'reportDate',   'text'),
            array('Date Received',     'date_receive', 'text'),
            array('Request ID',        'requestId',    'text'),
            array('Health Facility',   'facility',     'text'),
        )),
        array('group' => 'Product Details',               'cols' => array(
            array('Product Name',        'product_name',       'text'),
            array('Date of Manufacture', 'date_manufacture',   'text'),
            array('Lot Number',          'lot_no',             'text'),
            array('Date of Expiry',      'date_expiry',        'text'),
            array('Manufacturer',        'manufacturer',       'text'),
            array('Result',              'performance_result', 'text'),
        )),
        array('group' => 'Panel Detection Score for Pf',  'cols' => array(
            array('Temp (200p/µl)',   'pf_pd_200_temp',  'num'),
            array('Score (200p/µl)',  'pf_pds_200',      'num'),
            array('Temp (2000p/µl)',  'pf_pd_2000_temp', 'num'),
            array('Score (2000p/µl)', 'pf_pds_2000',     'num'),
            array('Invalid Rate (%)', 'pf_ivr',          'num'),
        )),
        array('gap' => true),
        array('group' => 'Panel Detection Score for Pv',  'cols' => array(
            array('Temp (200p/µl)',   'pv_pd_200_temp',  'num'),
            array('Score (200p/µl)',  'pv_pds_200',      'num'),
            array('Temp (2000p/µl)',  'pv_pd_2000_temp', 'num'),
            array('Score (2000p/µl)', 'pv_pds_2000',     'num'),
            array('Invalid Rate (%)', 'pv_ivr',          'num'),
        )),
        array('gap' => true),
        array('group' => 'Negative Control Score',        'cols' => array(
            array('Temp (°C)',      'ng_temperature', 'num'),
            array('Total Tests',    'total_tests',    'int'),
            array('True Negative',  'true_negative',  'int'),
            array('False Positive', 'false_positive', 'int'),
            array('TN Rate (%)',    'tn_rate',        'num'),
            array('FP Rate (%)',    'fp_rate',        'num'),
        )),
        array('group' => 'Tester Information',            'cols' => array(
            array('Tested By',   'test_by',     'text'),
            array('Designation', 'designation', 'text'),
            array('Test Date',   'test_date',   'text'),
        )),
    );
}

class XSheet {
    public $name;
    public $rows   = array();
    public $widths = array();
    public $merges = array();
    public $gaps   = array();      // 1-based column numbers that are separators
    public function __construct($name) { $this->name = $name; }

    /* $cells is a full-width row of array(value, style, isNumber). Only cells
       that carry a value size their column, so a merged group header never
       stretches the single column it happens to start in. */
    public function add(array $cells, $measure = true) {
        $this->rows[] = $cells;
        if ($measure) {
            foreach ($cells as $i => $c) {
                $len = mb_strlen((string)$c[0]);
                if (!isset($this->widths[$i]) || $len > $this->widths[$i]) { $this->widths[$i] = $len; }
            }
        }
        return count($this->rows);
    }
    public function blank() { $this->rows[] = array(); return count($this->rows); }
    public function merge($r, $c1, $c2) {
        if ($c2 > $c1) { $this->merges[] = xCol($c1) . $r . ':' . xCol($c2) . $r; }
    }
}

function xSheetXml(XSheet $s) {
    $maxCol = 0;
    foreach ($s->rows as $cells) { if (count($cells) > $maxCol) { $maxCol = count($cells); } }
    if ($maxCol < 1) { $maxCol = 1; }

    /* Explicit padding on every column so nothing is clipped. Headers wrap, so
       a column follows its longest value with a floor that fits the header. */
    $cols = '<cols>';
    for ($i = 0; $i < $maxCol; $i++) {
        if (in_array($i + 1, $s->gaps, true)) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="2.6" customWidth="1"/>';
            continue;
        }
        $len = isset($s->widths[$i]) ? $s->widths[$i] : 10;
        $w   = max(14, min(34, $len * 1.15 + 4.5));
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . round($w, 2) . '" customWidth="1"/>';
    }
    $cols .= '</cols>';

    $body = '';
    foreach ($s->rows as $ri => $cells) {
        $r = $ri + 1;
        if (!count($cells)) { $body .= '<row r="' . $r . '" spans="1:' . $maxCol . '"/>'; continue; }
        $cs = '';
        foreach ($cells as $ci => $c) {
            $ref   = xCol($ci + 1) . $r;
            $val   = $c[0];
            $style = isset($c[1]) ? $c[1] : XS_BLANK;
            $isNum = isset($c[2]) ? $c[2] : false;
            if ($isNum && $val !== null && $val !== '' && is_numeric($val)) {
                $cs .= '<c r="' . $ref . '" s="' . $style . '"><v>' . (0 + $val) . '</v></c>';
            } elseif ($val === null || $val === '') {
                $cs .= '<c r="' . $ref . '" s="' . $style . '"/>';
            } else {
                $cs .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xEsc($val) . '</t></is></c>';
            }
        }
        $body .= '<row r="' . $r . '" spans="1:' . $maxCol . '">' . $cs . '</row>';
    }
    $merges = '';
    if (count($s->merges)) {
        $merges = '<mergeCells count="' . count($s->merges) . '">';
        foreach ($s->merges as $m) { $merges .= '<mergeCell ref="' . $m . '"/>'; }
        $merges .= '</mergeCells>';
    }
    $dim = 'A1:' . xCol($maxCol) . max(1, count($s->rows));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . '<dimension ref="' . $dim . '"/>'
         /* The two header rows stay on screen while the product rows scroll. */
         . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
         . '<pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
         . '<sheetFormatPr defaultRowHeight="16"/>' . $cols
         . '<sheetData>' . $body . '</sheetData>' . $merges
         . '<pageMargins left="0.35" right="0.35" top="0.55" bottom="0.55" header="0.3" footer="0.3"/>'
         . '<pageSetup orientation="landscape" fitToWidth="1" paperSize="9"/>'
         . '</worksheet>';
}

function xStylesXml() {
    /* numFmtId 1 = whole number, 2 = 0.00 (both built in). */
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<fonts count="3">'
        . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF1F3864"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
      . '</fonts>'
      . '<fills count="4">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E1F2"/><bgColor indexed="64"/></patternFill></fill>'
      . '</fills>'
      . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border>'
          . '<left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right>'
          . '<top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/>'
        . '</border>'
      . '</borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="7">'
        /* 0 GAPCOL */ . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        /* 1 GROUP  */ . '<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        /* 2 HEAD   */ . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        /* 3 TEXT   */ . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
        /* 4 NUM    */ . '<xf numFmtId="2" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        /* 5 INT    */ . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        /* 6 BLANK  */ . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
      . '</cellXfs>'
      . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
      . '</styleSheet>';
}

/* ------------------------------------------------------- build the sheet -- */
$reportDate = date('Y-m-d');
$layout     = xLayout();
$sheet      = new XSheet('Performance Report');

/* Row 1: one merged banner per group, with the separator columns left bare.
   Excel draws a merged range's fill and borders from the cells underneath it,
   so every covered cell is written with the same style. */
$g1 = array(); $g2 = array(); $plan = array(); $col = 1;
foreach ($layout as $block) {
    if (isset($block['gap'])) {
        $sheet->gaps[] = $col;
        $g1[] = array('', XS_GAPCOL);
        $g2[] = array('', XS_GAPCOL);
        $col++;
        continue;
    }
    $span = count($block['cols']);
    $g1[] = array($block['group'], XS_GROUP);
    for ($k = 1; $k < $span; $k++) { $g1[] = array('', XS_GROUP); }
    if ($span > 1) { $plan[] = array($col, $col + $span - 1); }
    foreach ($block['cols'] as $c) { $g2[] = array($c[0], XS_HEAD); }
    $col += $span;
}
$r1 = $sheet->add($g1, false);                  // merged text must not size a column
foreach ($plan as $p) { $sheet->merge($r1, $p[0], $p[1]); }
$sheet->add($g2);

/* Rows 3+: one row per product, contiguous. The specification populates data
   from row 3 down with no separator, and a blank row in the middle of a flat
   table stops filters, sorts and pivots at the gap. The request columns (A..D)
   repeat on every row so each row still stands on its own. */
$common = array(
    'reportDate'   => $reportDate,
    'date_receive' => (string)$req['date_receive'],
    'requestId'    => (string)$req['rdt_request_id'],
    'facility'     => ($facilityName !== '' ? $facilityName : (string)$req['facility_id']),
);
foreach ($products as $p) {
    $row = array();
    foreach ($layout as $block) {
        if (isset($block['gap'])) { $row[] = array('', XS_GAPCOL); continue; }
        foreach ($block['cols'] as $c) {
            $key = $c[1];
            $val = array_key_exists($key, $common) ? $common[$key]
                 : (array_key_exists($key, $p) ? $p[$key] : '');
            if ($c[2] === 'num')      { $row[] = array($val, XS_NUM, true); }
            elseif ($c[2] === 'int')  { $row[] = array($val, XS_INT, true); }
            else                      { $row[] = array((string)$val, XS_TEXT); }
        }
    }
    $sheet->add($row);
}

$sheets = array($sheet);
/* ------------------------------------------------------------ the package -- */
$tmp = tempnam(sys_get_temp_dir(), 'rdtperf');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    header('HTTP/1.1 500 Internal Server Error'); echo 'Export failed. Please try again.'; exit;
}

$n = count($sheets);
$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
for ($i = 1; $i <= $n; $i++) {
    $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
}
$ct .= '</Types>';
$zip->addFromString('[Content_Types].xml', $ct);

$zip->addFromString('_rels/.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
  . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
  . '</Relationships>');

$wbSheets = ''; $wbRels = '';
foreach ($sheets as $i => $s) {
    $rid = 'rId' . ($i + 1);
    $wbSheets .= '<sheet name="' . xEsc($s->name) . '" sheetId="' . ($i + 1) . '" r:id="' . $rid . '"/>';
    $wbRels   .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
}
$styleRid = 'rId' . ($n + 1);
$wbRels .= '<Relationship Id="' . $styleRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

$zip->addFromString('xl/workbook.xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
  . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
  . '<sheets>' . $wbSheets . '</sheets></workbook>');

$zip->addFromString('xl/_rels/workbook.xml.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');

$zip->addFromString('xl/styles.xml', xStylesXml());
foreach ($sheets as $i => $s) { $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xSheetXml($s)); }
$zip->close();

$fname = 'RDT-Performance-Report-Request-' . (int)$req['rdt_request_id'] . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
exit;
