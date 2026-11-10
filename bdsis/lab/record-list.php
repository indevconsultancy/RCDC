<?php
/**
 * Server-side data source for the Lab Results listing (DataTables).
 *
 * The Lab listing was the worse of the two: it loaded up to 500 subjects and ran
 * roughly seven queries PER ROW to build the Bacteriology / E. coli PCR / ELISA
 * cells -- about 3,500 queries for one page view, then paginated in the browser.
 *
 * This returns one page. The per-row result queries still run, but for the ten
 * subjects on the page instead of five hundred, so the query count falls by ~50x.
 * The cell contents are produced by the exact same logic as before, so the
 * displayed Bacteriology / PCR / ELISA text is unchanged.
 */

require_once("../config/session.php");
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");
require_once("../includes/rbac.php");
require_once("../check-record.php");   // chkBacteriology / chkElisa, as the page used

header('Content-Type: application/json; charset=utf-8');

if (bdsis_scope() === BDSIS_SCOPE_DENIED) {
    echo json_encode(array('draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => array()));
    exit;
}

$draw   = isset($_GET['draw'])   ? (int)$_GET['draw']   : 0;
$start  = isset($_GET['start'])  ? (int)$_GET['start']  : 0;
$length = isset($_GET['length']) ? (int)$_GET['length'] : 10;
if ($start < 0) { $start = 0; }
if ($length <= 0 || $length > 100) { $length = ($length === -1) ? 100 : 10; }

$esc = function($v) use ($bdsis) { return $bdsis->real_escape_string((string)$v); };

/* WHERE: base + scope, then the page filters, then the global search. */
$whereScope = " WHERE subject_id!='' " . bdsis_scope_clause($bdsis, 'center_id');

$whereFilters = '';
$dz = isset($_GET['dzongkhag']) ? trim((string)$_GET['dzongkhag']) : '';
if ($dz !== '') {
    $fac = array();
    $fl = $commondb->query("SELECT center_id FROM ms_health_center_table WHERE dzongkhag_code='"
                          . $commondb->real_escape_string($dz) . "' AND dsis=1");
    if ($fl) { while (list($f) = $fl->fetch_row()) { $fac[] = $esc($f); } }
    $whereFilters .= count($fac) > 0 ? " AND center_id IN ('" . implode("','", $fac) . "')" : " AND 1=0";
}
if (isset($_GET['facility']) && $_GET['facility'] !== '') {
    $whereFilters .= " AND center_id='" . $esc($_GET['facility']) . "'";
}
if (isset($_GET['subID']) && $_GET['subID'] !== '') {
    $whereFilters .= " AND subject_id='" . $esc($_GET['subID']) . "'";
}

/* Global search covers the base columns only. The Bacteriology/PCR/ELISA cells
   are computed per row and are not queryable here without materialising them, so
   the box searches subject id and type -- the columns that identify a record. */
$whereSearch = '';
$searchVal = isset($_GET['search']['value']) ? trim((string)$_GET['search']['value']) : '';
if ($searchVal !== '') {
    $s = $esc($searchVal);
    $whereSearch = " AND (subject_id LIKE '%$s%' OR subject_type LIKE '%$s%')";
}

/* ORDER BY: the derived result columns (4,5,6) and Action (7) are not sortable. */
$orderCols = array(
    0 => 'subject_id',
    1 => 'subject_type',
    2 => 'center_id',
    3 => "COALESCE(NULLIF(record_updated_on,'0000-00-00'), recorded_on)",
);
$oCol = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 3;
$oDir = (isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === 'asc') ? 'ASC' : 'DESC';
$orderExpr = isset($orderCols[$oCol]) ? $orderCols[$oCol] : $orderCols[3];
$orderBy = " ORDER BY $orderExpr $oDir, subject_id DESC";

$cnt = function($where) use ($bdsis) {
    $r = $bdsis->query("SELECT COUNT(*) FROM subject_information_table" . $where);
    return $r ? (int)$r->fetch_row()[0] : 0;
};
$recordsTotal    = $cnt($whereScope);
$recordsFiltered = $cnt($whereScope . $whereFilters . $whereSearch);

$sql = "SELECT subject_id,subject_type,center_id,record_updated_on,recorded_on
          FROM subject_information_table"
     . $whereScope . $whereFilters . $whereSearch . $orderBy
     . " LIMIT " . (int)$start . ", " . (int)$length;
$res = $bdsis->query($sql);

/* center_id -> center_code, once. */
$codeMap = array();
$cm = $commondb->query("SELECT center_id, center_code FROM ms_health_center_table");
if ($cm) { while (list($cid, $code) = $cm->fetch_row()) { $codeMap[$cid] = $code; } }

$labCanEditBase = bdsis_can('edit', 'bacteriology');
/* Read from the database, not the session: this is the UI half of the lab-site
   rule that iu-lab.php enforces on save, and the two have to agree. The session
   carries whichever of the ten shared systems the user last entered, so a
   facility health worker who is an administrator elsewhere was shown an Edit
   control that the save endpoint then refused. */
$me             = bdsis_identity();
$isFacility33   = ((string)$me['acl'] === '3' && (string)$me['usercat'] === '3');
$atSentinel     = in_array((string)$me['centre'], array('016','063','128','143','997'), true);
$labCanEdit     = $labCanEditBase && (!$isFacility33 || $atSentinel);
$canDelete      = bdsis_can('delete', 'lab_list');

/* --- Per-row result cells: byte-for-byte the same logic the page used. --- */
$bactCell = function($sid) use ($bdsis, $esc) {
    $q = $bdsis->query("SELECT subject_id FROM bacteriology_result_table2 WHERE subject_id='" . $esc($sid) . "'");
    if (!$q || $q->num_rows == 0) { return "<i>No Result</i>"; }
    $out = "";
    $bq = $bdsis->query("SELECT brt.pathogen_id,bpt.enteric_pathogen FROM ms_enteric_bacterial_pathogen_table bpt,bacteriology_result_table2 brt WHERE bpt.pathogen_id=brt.pathogen_id AND brt.subject_id='" . $esc($sid) . "'");
    if ($bq) { while (list($pid, $pname) = $bq->fetch_row()) {
        $out .= ($pid == "025" || $pid == "026" || $pid == "101") ? $pname . "<br />" : $pname;
    } }
    return $out;
};
$pcrCell = function($sid) use ($bdsis, $esc) {
    $c1 = $bdsis->query("SELECT subject_id FROM rcdc_ecoli_pcr_result_table1 WHERE subject_id='" . $esc($sid) . "'");
    $c1 = $c1 ? $c1->num_rows : 0;
    $c2 = $bdsis->query("SELECT subject_id FROM bacteriology_result_table2 WHERE subject_id='" . $esc($sid) . "' AND (pathogen_id='025' OR pathogen_id='026')");
    $c2 = $c2 ? $c2->num_rows : 0;
    if ($c1 == 0 && $c2 > 0)  { return "<i>No Result</i>"; }
    if ($c1 == 0 && $c2 == 0) { return "<i>Not Required</i>"; }
    $out = "";
    $q1 = $bdsis->query("SELECT bphl.pathogen_id,mpt.enteric_pathogen FROM bacteriology_result_table2 bphl,ms_enteric_bacterial_pathogen_table mpt WHERE bphl.subject_id='" . $esc($sid) . "' AND bphl.pathogen_id=mpt.pathogen_id AND (bphl.pathogen_id='025' OR bphl.pathogen_id='026')");
    if ($q1) { while (list($pid, $pname) = $q1->fetch_row()) {
        $out .= $pname;
        $q2 = $bdsis->query("SELECT record_id,ecoli_strain_result FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id='" . $esc($sid) . "' AND ecoli_id='" . $esc($pid) . "'");
        if ($q2) { while (list($rid, $rslt) = $q2->fetch_row()) { $out .= ":" . $rslt . "<br>"; } }
    } }
    return $out;
};
$elisaCell = function($sid) use ($bdsis, $esc) {
    $c = $bdsis->query("SELECT subject_id FROM rcdc_elisa_result_table1 WHERE subject_id='" . $esc($sid) . "'");
    if (!$c || $c->num_rows == 0) { return "<i>No Result</i>"; }
    $out = "";
    $q = $bdsis->query("SELECT mvt.virus,ert.elisa_result FROM rcdc_elisa_result_table2 ert,ms_virus_table mvt WHERE ert.subject_id='" . $esc($sid) . "' AND ert.virus_id=mvt.virus_id");
    if ($q) { while (list($vname, $rslt) = $q->fetch_row()) { $out .= $vname . "(<i>" . $rslt . "</i>)<br>"; } }
    return $out;
};

$data = array();
if ($res) {
    while (list($sid, $stype, $cid, $opdt) = $res->fetch_row()) {
        $ccode = isset($codeMap[$cid]) ? $codeMap[$cid] : '';

        $action = '';
        if ($labCanEdit) {
            $action .= "<a href='lab.php?sid=" . htmlspecialchars($sid, ENT_QUOTES)
                     . "&action=1' class='mx-2'><i class='fa fa-edit'></i></a>";
        }
        if ($canDelete && (chkBacteriology($sid, $bdsis) > 0 || chkElisa($sid, $bdsis) > 0)) {
            $action .= "<a href='delete.php?type=labAll&id=" . htmlspecialchars($sid, ENT_QUOTES)
                     . "&siid=" . htmlspecialchars($sid, ENT_QUOTES)
                     . "' class='mx-2' onclick='return confirmDelete(this)'>"
                     . "<i class='fa fa-trash text-danger'></i></a>";
        }

        $data[] = array(
            htmlspecialchars((string)$sid, ENT_QUOTES),
            htmlspecialchars((string)$stype, ENT_QUOTES),
            htmlspecialchars((string)$ccode, ENT_QUOTES),
            htmlspecialchars((string)$opdt, ENT_QUOTES),
            $bactCell($sid),     // already contains intentional markup
            $pcrCell($sid),
            $elisaCell($sid),
            $action,
        );
    }
}

echo json_encode(array(
    'draw'            => $draw,
    'recordsTotal'    => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data'            => $data,
));
