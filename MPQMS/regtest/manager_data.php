<?php
	// Server-side DataTables endpoint for the Registration & Result Manager.
	// Returns ONLY the requested page of rows (LIMIT/OFFSET) plus the total counts,
	// so the browser never receives the full dataset.  Read-only.
	include_once("../config/auth_ndtlis.php");
	// Effective-role RBAC: the list data follows the same rule as the manager page itself,
	// so a direct POST cannot retrieve rows the role may not open in the UI.
	include_once("../config/mpq_validate.php");
	mpq_require('manager', 'view', true);
	include_once("../config/db_config.php");
	require_once(__DIR__."/manager_lib.php");
	header('Content-Type: application/json');

	$draw   = isset($_POST['draw']) ? (int)$_POST['draw'] : 0;
	$start  = isset($_POST['start']) ? max(0,(int)$_POST['start']) : 0;
	$length = isset($_POST['length']) ? (int)$_POST['length'] : 10;
	if($length <= 0 || $length > 200){ $length = 10; }   // guard (also caps "All")

	$req = array(
		'year'      => $_POST['year']      ?? '',
		'month'     => $_POST['month']     ?? '',
		'dzongkhag' => $_POST['dzongkhag'] ?? '',
		'facility'  => $_POST['facility']  ?? '',
		'search'    => $_POST['search']['value'] ?? '',
		'order_col' => $_POST['order'][0]['column'] ?? 1,
		'order_dir' => $_POST['order'][0]['dir'] ?? 'desc',
	);
	$session = array(
		'acl'     => $_SESSION['acl']     ?? 0,
		'usercat' => $_SESSION['usercat'] ?? 0,
		'users'   => $_SESSION['users']   ?? '',
	);

	list($from, $baseWhere, $searchWhere) = mpqms_manager_from_where($connection, $commondb, $session, $req);
	$fullWhere = $baseWhere . ($searchWhere !== '' ? " AND $searchWhere" : "");
	$order = mpqms_manager_order($req);

	// Counts
	list($recordsTotal)    = mysqli_fetch_row(mysqli_query($connection, "SELECT COUNT(*) $from WHERE $baseWhere"));
	$recordsFiltered = $recordsTotal;
	if($searchWhere !== ''){
		list($recordsFiltered) = mysqli_fetch_row(mysqli_query($connection, "SELECT COUNT(*) $from WHERE $fullWhere"));
	}

	// Page of rows only
	$sql = mpqms_manager_select()." $from WHERE $fullWhere $order LIMIT ".(int)$length." OFFSET ".(int)$start;
	$res = mysqli_query($connection, $sql);

	// Buffer this page's rows first so the official-report lookup is a single batched
	// query for the whole page instead of one query per row.
	$rows = array();
	if($res){ while($row = mysqli_fetch_row($res)){ $rows[] = $row; } }

	// Official (immutable) Analytical Reports available for this page. Returns an empty
	// map when the archive table does not exist yet, so the list is unaffected.
	require_once(__DIR__."/official_report_lib.php");
	require_once(__DIR__."/report_lock_lib.php");   // completed reports: no Delete action
	$mpRidList = array();
	foreach($rows as $r){ $mpRidList[] = (int)$r[0]; }
	$mpOfficial = mpqms_official_report_map($connection, $mpRidList);
	// Same read access as view.php / pdf_report.php / download_official_report.php.
	$mpCanOfficial = mpq_can_do('official_report','view');

	$data = array();
	foreach($rows as $row){
		$c = mpqms_manager_compute($connection, $commondb, $row, null);
		$rid = (int)$c['rid'];

		// RBAC action column: everyone may View; Administrator + RCDC/NDTL labs may Edit;
		// only the Administrator may Delete. BFDA/DRA (acl 7) sees View only (read-only).
		$mpCanEdit   = mpq_can_do('registration','edit');
		$mpCanDelete = mpq_can_do('registration','delete');
		$str  = "<a href='view.php?rid=$rid' title='View'><i class='fas fa-eye text-primary'></i></a>&nbsp;";
		if($mpCanEdit){
			$str .= "<a href='registration.php?par=2&rid=$rid' title='Review / edit'><i class='far fa-edit'></i></a>&nbsp;";
		}
		if($mpCanDelete && !mpqms_report_locked($connection, $rid)){   // completed reports cannot be deleted
			$str .= "<a id='delete-report' data-id='$rid' href='javascript:void(0)' title='Delete'><i class='fas fa-trash-alt text-danger'></i></a>";
		}
		// Locked/official indicator — only for samples whose official report has been issued.
		if($mpCanOfficial && isset($mpOfficial[$rid])){
			$str .= "&nbsp;<a href='download_official_report.php?rid=$rid' target='_blank' title='Download Official Report (official, locked)'>"
				 ."<i class='fas fa-file-pdf text-success'></i><i class='fas fa-lock text-success' style='font-size:.7em;vertical-align:super;'></i></a>";
		}

		$data[] = array(
			"<span class='text-center'>".htmlspecialchars((string)$c['pclaim'])."</span>",
			htmlspecialchars((string)$c['dor']),
			htmlspecialchars((string)$c['sid']),
			htmlspecialchars((string)$c['cat']),
			htmlspecialchars((string)$c['site']),
			htmlspecialchars((string)$c['cby']),
			htmlspecialchars((string)$c['edl']),
			htmlspecialchars((string)$c['batch']),
			mpqms_status_span($c['acp']),
			"<a id='view-report' data-id='$rid' href='javascript:void(0)' title='Test status'>".mpqms_status_span($c['sts'])."</a>",
			mpqms_status_span($c['apv']),
			mpqms_status_span($c['frslt']),
			"<span class='text-nowrap'>$str</span>"
		);
	}

	echo json_encode(array(
		'draw'            => $draw,
		'recordsTotal'    => (int)$recordsTotal,
		'recordsFiltered' => (int)$recordsFiltered,
		'data'            => $data
	));
?>
