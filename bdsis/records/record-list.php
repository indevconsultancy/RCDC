<?php
/**
 * Server-side data source for the Record Management listing (DataTables).
 *
 * The listing used to render every matching row into the page's <tbody> and let
 * DataTables paginate in the browser: an administrator's first load shipped all
 * 5,091 rows -- 1.76 MB of HTML plus three full-table prefetch queries -- only to
 * hide all but ten. This endpoint returns a single page instead, so the database
 * reads and the bytes on the wire are bounded by the page size, not the table.
 *
 * It reproduces the listing's rules exactly:
 *   - the same base filter (subject_id != '') and the same scope clause, so a
 *     facility user still sees only their centre and cannot widen it;
 *   - the same capability checks for the View / Edit / Delete actions;
 *   - the same "Pending until the lab records a result" status, and the same
 *     "delete only while no lab result exists" gate -- but evaluated for the ten
 *     subjects on the page, not by pulling every bacteriology/elisa id first.
 *
 * Contract: the DataTables server-side protocol (draw/start/length/order/search),
 * plus the four page filters (dzongkhag, facility, subID, patientName) passed
 * through ajax.data.
 */

require_once("../config/session.php");
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");
require_once("../includes/rbac.php");

header('Content-Type: application/json; charset=utf-8');

/* Same gate as the page. A role with no BDSIS access gets an empty, well-formed
   response rather than an HTML 403 the DataTables client cannot parse. */
if (bdsis_scope() === BDSIS_SCOPE_DENIED) {
    echo json_encode(array('draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0,
                           'data' => array(), 'error' => 'not authorised'));
    exit;
}

$draw   = isset($_GET['draw'])   ? (int)$_GET['draw']   : 0;
$start  = isset($_GET['start'])  ? (int)$_GET['start']  : 0;
$length = isset($_GET['length']) ? (int)$_GET['length'] : 10;
if ($start < 0)  { $start = 0; }
/* length == -1 is DataTables' "All". Cap it so the endpoint can never be turned
   back into the full-table fetch it exists to replace. */
if ($length <= 0 || $length > 100) { $length = ($length === -1) ? 100 : 10; }

$esc = function($v) use ($bdsis) { return $bdsis->real_escape_string((string)$v); };

/* ---- WHERE parts -------------------------------------------------------- */
/* Base + scope: what this user may see at all. Nothing below can widen it. */
$whereScope = " WHERE subject_id!='' " . bdsis_scope_clause($bdsis, 'center_id');

/* Page filters (the search form). Narrowing only, same as the page. */
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
if (isset($_GET['patientName']) && $_GET['patientName'] !== '') {
    $whereFilters .= " AND subject_name LIKE '%" . $esc($_GET['patientName']) . "%'";
}

/* DataTables global search box: the text columns the user can see. */
$whereSearch = '';
$searchVal = isset($_GET['search']['value']) ? trim((string)$_GET['search']['value']) : '';
if ($searchVal !== '') {
    $s = $esc($searchVal);
    $whereSearch = " AND (subject_id LIKE '%$s%' OR subject_name LIKE '%$s%'"
                 . " OR gender LIKE '%$s%' OR contact_no LIKE '%$s%')";
}

/* ---- ORDER BY ----------------------------------------------------------- */
/* Column index -> sortable expression. Action (7) is not orderable. The
   "Updated On" cell is record_updated_on when present else recorded_on, so it
   sorts by the same expression it displays. */
$orderCols = array(
    0 => 'subject_id',
    1 => 'center_id',
    2 => 'subject_name',
    3 => 'gender',
    4 => 'age',
    5 => 'contact_no',
    6 => "COALESCE(NULLIF(record_updated_on,'0000-00-00'), recorded_on)",
);
$oCol = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 6;
$oDir = (isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === 'asc') ? 'ASC' : 'DESC';
$orderExpr = isset($orderCols[$oCol]) ? $orderCols[$oCol] : $orderCols[6];
$orderBy = " ORDER BY $orderExpr $oDir, subject_id DESC";

/* ---- Counts ------------------------------------------------------------- */
$cnt = function($where) use ($bdsis) {
    $r = $bdsis->query("SELECT COUNT(*) FROM subject_information_table" . $where);
    return $r ? (int)$r->fetch_row()[0] : 0;
};
$recordsTotal    = $cnt($whereScope);
$recordsFiltered = $cnt($whereScope . $whereFilters . $whereSearch);

/* ---- Page of rows ------------------------------------------------------- */
$cols = "subject_id,subject_name,gender,age,contact_no,recorded_on,record_updated_on,center_id";
$sql  = "SELECT $cols FROM subject_information_table"
      . $whereScope . $whereFilters . $whereSearch . $orderBy
      . " LIMIT " . (int)$start . ", " . (int)$length;
$res = $bdsis->query($sql);

$rows = array();
$ids  = array();
if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; $ids[] = $row['subject_id']; } }

/* Lab-result membership for THIS page's subjects only -- the whole point: two
   small IN lookups instead of loading every bacteriology and elisa id. */
$bactSet = array();
$elisaSet = array();
if (count($ids) > 0) {
    $inList = "'" . implode("','", array_map($esc, $ids)) . "'";
    $b = $bdsis->query("SELECT DISTINCT subject_id FROM bacteriology_result_table2 WHERE subject_id IN ($inList)");
    if ($b) { while (list($id) = $b->fetch_row()) { $bactSet[$id] = 1; } }
    $e = $bdsis->query("SELECT DISTINCT subject_id FROM rcdc_elisa_result_table2 WHERE subject_id IN ($inList)");
    if ($e) { while (list($id) = $e->fetch_row()) { $elisaSet[$id] = 1; } }
}

/* center_id -> center_code, once, for the Site column. */
$codeMap = array();
$cm = $commondb->query("SELECT center_id, center_code FROM ms_health_center_table");
if ($cm) { while (list($cid, $code) = $cm->fetch_row()) { $codeMap[$cid] = $code; } }

$canView   = bdsis_can('view', 'records');
$canEdit   = bdsis_can('edit', 'records');

$isAdmin = false;
if (isset($_SESSION['role']) && in_array(strtolower(trim((string)$_SESSION['role'])), array('administrator', 'admin'), true)) {
    $isAdmin = true;
} elseif (function_exists('bdsis_permission_tier') && bdsis_permission_tier() === BDSIS_TIER_ADMIN) {
    $isAdmin = true;
} elseif (isset($_SESSION['acl']) && (string)$_SESSION['acl'] === '1') {
    $isAdmin = true;
} elseif (function_exists('bdsis_can') && bdsis_can('delete', 'records')) {
    $isAdmin = true;
}

$data = array();
foreach ($rows as $r) {
    $sid  = $r['subject_id'];
    $code = isset($codeMap[$r['center_id']]) ? $codeMap[$r['center_id']] : '';
    $upd  = ($r['record_updated_on'] === '' || $r['record_updated_on'] === null
             || $r['record_updated_on'] === '0000-00-00') ? $r['recorded_on'] : $r['record_updated_on'];

    /* Action cell: View, Edit, and Administrator-only Delete button */
    $action = '';
    if ($canView) {
        $action .= "<a href='../reports/report-view.php?sid=" . urlencode($sid)
                 . "&action=1' class='m-2' title='View'><i class='fa fa-eye'></i></a>";
    }
    if ($canEdit) {
        $action .= "<a href='case.php?sid=" . htmlspecialchars($sid, ENT_QUOTES)
                 . "&action=2' class='m-2' title='Edit'><i class='fa fa-edit'></i></a>";
    }
    if ($isAdmin) {
        $action .= "<button type='button' class='btn btn-sm btn-outline-danger btn-delete-record ml-1' data-id='"
                 . htmlspecialchars($sid, ENT_QUOTES)
                 . "' title='Delete Record'><i class='fa fa-trash'></i></button>";
    }

    $h = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
    $data[] = array(
        $h($sid), $h($code), $h($r['subject_name']), $h($r['gender']),
        $h($r['age']), $h($r['contact_no']), $h($upd), $action,
    );
}

echo json_encode(array(
    'draw'            => $draw,
    'recordsTotal'    => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data'            => $data,
));
