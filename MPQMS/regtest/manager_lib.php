<?php
/*
 * Shared query/logic for the Registration & Result Manager list.
 * Used by manager_data.php (server-side DataTables paging) and manager_export.php
 * (full filtered export) so role visibility, filters, search and the derived status
 * columns are computed identically in both places.  Business logic is unchanged from
 * the original manager.php — it is only centralised and made page-bounded.
 */

if(!function_exists('mpqms_manager_from_where')){
	// Returns array($from, $baseWhere, $searchWhere).
	//  - baseWhere  = role visibility + the year/month/dzongkhag/facility filters
	//  - searchWhere = the DataTables global search box (empty string if none)
	function mpqms_manager_from_where($connection, $commondb, $session, $req){
		$esc = function($v) use ($connection){ return mysqli_real_escape_string($connection, (string)$v); };
		require_once(dirname(__DIR__)."/includes/db_names.php");   // real database names (local or prefixed host)
		$cdb = mpq_db($connection, 'commondb');

		// All extra joins are 1:1 (PK on the right side) so they never multiply rows / counts.
		// Oct-13 2026 — cby is LEFT (was INNER) so a sentinel collectID (998 = NMPTL)
		// without a master row still appears in ADMIN / NDTL / NMRL listings. The
		// mpqms_manager_compute() helper backfills the display name for 998.
		$from = "FROM registrationtable t1
			LEFT JOIN `".$cdb."`.ms_health_center_table cby ON t1.collectID = cby.center_id
			LEFT JOIN `".$cdb."`.ms_health_center_table site ON t1.siteID = site.center_id
			LEFT JOIN msedltable edl ON t1.edlID = edl.id
			LEFT JOIN samplestatustable ss ON t1.registrationID = ss.registrationID
			LEFT JOIN finalresulttable fr ON t1.registrationID = fr.registrationID";

		$base = array("1=1");

		$year  = trim($req['year'] ?? '');
		$month = trim($req['month'] ?? '');
		$dz    = trim($req['dzongkhag'] ?? '');
		$fac   = trim($req['facility'] ?? '');
		// The user-chosen filters narrow the list.
		if($year  !== '') $base[] = "YEAR(t1.registrationDate)='".$esc($year)."'";
		if($month !== '') $base[] = "MONTH(t1.registrationDate)='".$esc($month)."'";
		if($dz    !== '') $base[] = "cby.dzongkhag_code='".$esc($dz)."'";
		if($fac   !== '') $base[] = "cby.center_id='".$esc($fac)."'";

		// Role data scope. Applied ALWAYS, on top of whatever the user filtered by — previously it
		// was skipped as soon as any filter was supplied, which let a centre-scoped role see every
		// centre's samples simply by choosing a year. Administrator and the laboratory roles are
		// national by design and are not narrowed.
		$mpRole = function_exists('mpq_effective_role') ? mpq_effective_role() : '';
		// Oct-13 2026 — BFDA MUST NOT see samples collected by NMPTL (collectID=998),
		// regardless of their centre scope. Explicit filter is defence-in-depth; the
		// centre-id scope below already excludes it, but centralising the rule here
		// also protects any future BFDA-visible path that reuses this helper.
		if($mpRole === 'BFDA'){ $base[] = "t1.collectID <> 998"; }
		if(!in_array($mpRole, array('ADMIN', 'NDTL', 'NMRL'), true)){
			$ucid = '';
			$st = mysqli_prepare($commondb, "SELECT center_id FROM user_profile_table WHERE username = ? LIMIT 1");
			if($st){
				mysqli_stmt_bind_param($st, 's', $session['users']);
				mysqli_stmt_execute($st);
				mysqli_stmt_bind_result($st, $ucid);
				if(!mysqli_stmt_fetch($st)){ $ucid = ''; }
				mysqli_stmt_close($st);
			}
			// No resolvable centre -> no rows, rather than the whole country.
			$base[] = "cby.center_id='".$esc((string)$ucid)."'";
		}

		$searchWhere = "";
		$search = trim($req['search'] ?? '');
		if($search !== ''){
			$s = $esc($search);
			$searchWhere = "(t1.sampleID LIKE '%$s%' OR t1.batchNo LIKE '%$s%' OR t1.pharmaClaim LIKE '%$s%'
				OR t1.pharmaOther LIKE '%$s%' OR t1.testCategory LIKE '%$s%'
				OR site.center_name LIKE '%$s%' OR cby.center_name LIKE '%$s%' OR edl.edl LIKE '%$s%'
				OR ss.status LIKE '%$s%' OR fr.result LIKE '%$s%')";
		}

		return array($from, implode(' AND ', $base), $searchWhere);
	}
}

if(!function_exists('mpqms_manager_order')){
	function mpqms_manager_order($req){
		// Only stored/joined columns are orderable; derived status columns are not.
		$cols = array(
			0 => 't1.pharmaClaim', 1 => 't1.registrationDate', 2 => 't1.sampleID', 3 => 't1.testCategory',
			4 => 'site.center_name', 5 => 'cby.center_name', 6 => 'edl.edl', 7 => 't1.batchNo'
		);
		$ci  = isset($req['order_col']) ? (int)$req['order_col'] : 1;
		$dir = (isset($req['order_dir']) && strtolower($req['order_dir']) === 'asc') ? 'ASC' : 'DESC';
		$col = isset($cols[$ci]) ? $cols[$ci] : 't1.registrationDate';
		// Oct-2026 directive — "newest registered entries always appear at the very top
		// of the list across all user views". Default is registrationDate DESC;
		// registrationID DESC is appended as a stable secondary sort so same-date
		// entries fall newest-first and paging stays deterministic for every role.
		if($col === 't1.registrationDate'){
			return "ORDER BY t1.registrationDate DESC, t1.registrationID DESC";
		}
		return "ORDER BY $col $dir, t1.registrationDate DESC, t1.registrationID DESC";
	}
}

if(!function_exists('mpqms_manager_select')){
	function mpqms_manager_select(){
		return "SELECT t1.registrationID,t1.registrationDate,t1.sampleID,t1.testCategory,
			t1.siteID,t1.siteOther,t1.collectID,t1.collectOther,t1.edlID,t1.edlOther,
			t1.batchNo,t1.pharmaClaim,t1.pharmaOther";
	}
}

if(!function_exists('mpqms_manager_load_maps')){
	// Batch status maps — used by the EXPORT (many rows) to avoid per-row N+1 queries.
	function mpqms_manager_load_maps($connection, $commondb){
		$m = array('site'=>array(),'edl'=>array(),'acp'=>array(),'final'=>array(),'labT'=>array(),'labC'=>array(),'labA'=>array());
		$r = mysqli_query($commondb,"SELECT center_id,center_name FROM ms_health_center_table"); while($x=mysqli_fetch_row($r)){ $m['site'][$x[0]]=$x[1]; }
		$r = mysqli_query($connection,"SELECT id,edl FROM msedltable"); while($x=mysqli_fetch_row($r)){ $m['edl'][$x[0]]=$x[1]; }
		$r = mysqli_query($connection,"SELECT registrationID,status FROM samplestatustable"); while($x=mysqli_fetch_row($r)){ $m['acp'][$x[0]]=$x[1]; }
		$r = mysqli_query($connection,"SELECT registrationID,result FROM finalresulttable"); while($x=mysqli_fetch_row($r)){ $m['final'][$x[0]]=$x[1]; }
		$r = mysqli_query($connection,"SELECT registrationID,COUNT(*),SUM(result IN('Complies','Does not comply','Inconclusive')),SUM(approval IN('Approved','Disapproved')) FROM labresulttable GROUP BY registrationID");
		while($x=mysqli_fetch_row($r)){ $m['labT'][$x[0]]=$x[1]; $m['labC'][$x[0]]=$x[2]; $m['labA'][$x[0]]=$x[3]; }
		return $m;
	}
}

if(!function_exists('mpqms_manager_compute')){
	// Compute the display fields for one registration row. $maps = null → per-row lookups
	// (cheap for a single page); an array → use pre-loaded batch maps (export).
	// $r = [rid,dor,sid,cat,siteid,siteothr,cbyid,cbyothr,edlid,edlothr,batch,pcl,pcloth]
	function mpqms_manager_compute($connection, $commondb, $r, $maps){
		list($rid,$dor,$sid,$cat,$siteid,$siteothr,$cbyid,$cbyothr,$edlid,$edlothr,$batch,$pcl,$pcloth) = $r;

		$pclaim = ($pcl == "Others") ? $pcloth : $pcl;

		if($maps){
			$site = isset($maps['site'][$siteid]) ? $maps['site'][$siteid] : null;
			$cby  = isset($maps['site'][$cbyid])  ? $maps['site'][$cbyid]  : null;
			$edl  = isset($maps['edl'][$edlid])   ? $maps['edl'][$edlid]   : null;
			$acp  = isset($maps['acp'][$rid])     ? $maps['acp'][$rid]     : null;
			$tstcnt  = isset($maps['labT'][$rid]) ? $maps['labT'][$rid] : 0;
			$rsltcnt = isset($maps['labC'][$rid]) ? $maps['labC'][$rid] : 0;
			$apvcnt  = isset($maps['labA'][$rid]) ? $maps['labA'][$rid] : 0;
			$frslt_db = isset($maps['final'][$rid]) ? $maps['final'][$rid] : null;
		} else {
			list($site) = mysqli_fetch_row(mysqli_query($commondb,"SELECT center_name FROM ms_health_center_table WHERE center_id='".mysqli_real_escape_string($commondb,$siteid)."'"));
			list($cby)  = mysqli_fetch_row(mysqli_query($commondb,"SELECT center_name FROM ms_health_center_table WHERE center_id='".mysqli_real_escape_string($commondb,$cbyid)."'"));
			list($edl)  = mysqli_fetch_row(mysqli_query($connection,"SELECT edl FROM msedltable WHERE id='".mysqli_real_escape_string($connection,$edlid)."'"));
			$acp = null; $ridE = mysqli_real_escape_string($connection,$rid);
			list($acp) = mysqli_fetch_row(mysqli_query($connection,"SELECT status FROM samplestatustable WHERE registrationID='$ridE'"));
			list($tstcnt)  = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM labresulttable WHERE registrationID='$ridE'"));
			list($rsltcnt) = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM labresulttable WHERE result IN('Complies','Does not comply','Inconclusive') AND registrationID='$ridE'"));
			list($apvcnt)  = mysqli_fetch_row(mysqli_query($connection,"SELECT COUNT(*) FROM labresulttable WHERE approval IN('Approved','Disapproved') AND registrationID='$ridE'"));
			list($frslt_db) = mysqli_fetch_row(mysqli_query($connection,"SELECT result FROM finalresulttable WHERE registrationID='$ridE'"));
		}

		if($siteid == 999){ $site = "$site($siteothr)"; }
		if($cbyid  == 999){ $cby  = "$cby($cbyothr)"; }
		if($edlid  == 999){ $edl  = "$edl($edlothr)"; }
		// Oct-13 2026 — fallback label for the new NMPTL sentinel when the
		// shared commondb migration row isn't present yet.
		if((int)$cbyid === 998 && ($cby === null || $cby === '')){ $cby = 'National Medical Product Testing Laboratory'; }

		if($cat == "Surveillance"){ $catAbbr = "Sur"; } else if($cat == "Complain"){ $catAbbr = "Com"; } else { $catAbbr = "Reg"; }

		$frslt = null;
		if($acp == "Accepted"){
			$acpstr = "Accepted";
			// A sample with NO test parameters has nothing to complete and nothing to approve.
			// Comparing the two zero counts used to read as Completed / Approved, so a record
			// that had never been tested displayed as fully approved. Require at least one row.
			$sts = ($tstcnt > 0 && $tstcnt == $rsltcnt) ? "Completed" : "Pending";
			$apv = ($tstcnt > 0 && $tstcnt == $apvcnt)  ? "Approved"  : "Pending";
		} else if($acp == "Rejected"){
			$acpstr = "Rejected"; $sts = "Rejected"; $apv = "Rejected"; $frslt = "Rejected";
		} else {
			$acpstr = "Pending"; $sts = "Pending"; $apv = "Pending";
		}
		if($acp != "Rejected"){ $frslt = $frslt_db; }

		return array(
			'rid'=>$rid, 'pclaim'=>$pclaim, 'dor'=>$dor, 'sid'=>$sid, 'cat'=>$catAbbr,
			'site'=>$site, 'cby'=>$cby, 'edl'=>$edl, 'batch'=>$batch,
			'acp'=>$acpstr, 'sts'=>$sts, 'apv'=>$apv, 'frslt'=>$frslt
		);
	}
}

if(!function_exists('mpqms_status_span')){
	function mpqms_status_span($v){
		if($v === "" || $v === null){ return "<span class='text-muted'>-</span>"; }
		if(in_array($v, array("Accepted","Completed","Approved","Complies"))){ return "<span class='text-success font-weight-bold'>".htmlspecialchars($v)."</span>"; }
		if($v === "Pending"){ return "<span class='text-warning font-weight-bold'>Pending</span>"; }
		if(in_array($v, array("Rejected","Does not comply","Disapproved"))){ return "<span class='text-danger font-weight-bold'>".htmlspecialchars($v)."</span>"; }
		return htmlspecialchars($v);
	}
}
?>
