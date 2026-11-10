<?php
/*
 * MPQMS — shared server-side validation guard.
 *
 * Rejects a submission BEFORE any INSERT/UPDATE when a mandatory field is missing,
 * mirroring the client-side `required` rules so validation cannot be bypassed by a
 * direct POST or with JavaScript disabled. It only guards ENTRY — it does not change
 * any business logic, SQL query, workflow, or field semantics. A value of "0"
 * (e.g. an Inactive status radio) counts as filled; only null/absent/blank is rejected.
 *
 * Response mirrors the handlers' existing conventions:
 *   - AJAX  -> {status:'error', message:'...'} JSON (what the AJAX submitters already expect)
 *   - non-AJAX -> a SweetAlert2 error then history.back() (SweetAlert is already loaded via
 *                 css-jquery.php on the non-AJAX branch of every handler).
 */
if (!function_exists('mpq_field_filled')) {

	function mpq_field_filled($v) {
		if (is_array($v)) {
			foreach ($v as $x) { if (trim((string)$x) !== '') { return true; } }
			return false;
		}
		return trim((string)$v) !== '';   // "0" is treated as filled
	}

	/**
	 * @param array $rules  either a simple list ['a','b'] or a name=>Label map ['a'=>'Field A']
	 * @return array missing field labels
	 */
	function mpq_missing($rules) {
		$missing = array();
		foreach ($rules as $name => $label) {
			if (is_int($name)) { $name = $label; }   // simple list entry
			$v = isset($_REQUEST[$name]) ? $_REQUEST[$name] : null;
			if ($v === null || !mpq_field_filled($v)) { $missing[] = $label; }
		}
		return $missing;
	}

	function mpq_fail($msg, $mpAjax, $backLink = '../dashboard/dashboard.php') {
		if ($mpAjax) {
			header('Content-Type: application/json');
			echo json_encode(array('status' => 'error', 'message' => $msg));
			exit;
		}
		// non-AJAX: SweetAlert2 is already loaded on the handler's non-AJAX branch.
		echo '<script>(function(){function go(){history.length>1?history.back():window.location=' . json_encode($backLink) . ';}'
		   . 'if(window.Swal){Swal.fire({icon:"error",text:' . json_encode($msg) . ',confirmButtonText:"Close"}).then(go);}else{alert(' . json_encode($msg) . ');go();}})();</script>';
		exit;
	}

	/** Guard: if any required field is missing, emit the standard error and exit. */
	function mpq_guard($rules, $mpAjax, $backLink = '../dashboard/dashboard.php') {
		$missing = mpq_missing($rules);
		if (!empty($missing)) {
			mpq_fail('Please complete all required fields: ' . implode(', ', $missing) . '.', $mpAjax, $backLink);
		}
	}

	/**
	 * Server-side role guard for POST handlers whose UI buttons are already role-gated.
	 * Rejects (JSON error for AJAX / SweetAlert-back otherwise) any user whose access_level
	 * is not in $allowedAcl, so a hidden button cannot be bypassed via direct POST / session
	 * manipulation. Mirrors the existing page guards (view.php / pdf_report.php / delete.php).
	 */
	function mpq_require_role($allowedAcl, $mpAjax, $msg = 'You are not authorised to perform this action.') {
		$acl = (int)($_SESSION['acl'] ?? 0);
		if (!in_array($acl, $allowedAcl, true)) {
			mpq_fail($msg, $mpAjax);
		}
	}

	/**
	 * CSRF guard for state-changing POST handlers. The token is minted per session in
	 * auth_ndtlis.php, surfaced as <meta name="csrf-token"> and attached to every jQuery
	 * AJAX request as X-CSRF-Token (dist/js/mpqms-validate.js). A cross-site forged POST
	 * cannot set that header, so it is rejected. Also accepts a `csrf` POST field as fallback.
	 */
	function mpq_csrf_check($mpAjax) {
		$sess = $_SESSION['csrf'] ?? '';
		$sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ($_REQUEST['csrf'] ?? ''));
		if ($sess === '' || !is_string($sent) || !hash_equals($sess, $sent)) {
			mpq_fail('Security token missing or invalid. Please refresh the page and try again.', $mpAjax);
		}
	}

	/* =====================================================================
	 * CENTRALIZED RBAC — single authoritative permission matrix for MPQMS.
	 *
	 * Roles are keyed off the portal access level already resolved into the
	 * session (system_id 9), so this reuses the existing authentication/role
	 * infrastructure — it does NOT introduce a second auth system:
	 *     acl 1 = Administrator   (full CRUD)
	 *     acl 2 = RCDC Labs        (Create / Read / Update — NO Delete)
	 *     acl 7 = BFDA (DRA)       (Read only)
	 * Matrix:  Create = Update = {1,2}   Delete = {1}   Read = {1,2,7}
	 *
	 * Use mpq_can_*()/mpq_can() for UI visibility, and the mpq_require_*()
	 * enforcers in backend handlers so a hidden button, a hand-typed URL, or a
	 * forged AJAX/API call is rejected server-side (deny -> mpq_fail -> exit).
	 * ===================================================================== */
	function mpq_acl()        { return (int)($_SESSION['acl'] ?? 0); }
	function mpq_role_name()  { $a = mpq_acl(); return $a === 1 ? 'Administrator' : ($a === 2 ? 'RCDC Labs' : ($a === 7 ? 'BFDA' : 'Guest')); }

	function mpq_can_read()   { return in_array(mpq_acl(), array(1, 2, 7), true); }   // all authenticated roles
	function mpq_can_create() { return in_array(mpq_acl(), array(1, 2), true); }       // Admin + RCDC
	function mpq_can_update() { return in_array(mpq_acl(), array(1, 2), true); }       // Admin + RCDC
	function mpq_can_delete() { return mpq_acl() === 1; }                              // Administrator ONLY

	/** Generic gate for UI/logic: mpq_can('create'|'read'|'update'|'delete'). */
	function mpq_can($action) {
		switch (strtolower((string)$action)) {
			case 'create': case 'add':  case 'new':    return mpq_can_create();
			case 'update': case 'edit':                return mpq_can_update();
			case 'delete': case 'remove':              return mpq_can_delete();
			case 'read':   case 'view': case 'list':   return mpq_can_read();
			default: return false;
		}
	}

	/* Backend enforcers — call at the top of a protected handler. On denial they
	 * emit the standard authorization response (JSON for AJAX / SweetAlert-back
	 * otherwise) and exit, so the operation never touches the database. */
	/* =====================================================================
	 * EFFECTIVE-ROLE RBAC — the single authoritative permission definition,
	 * shared by the UI and by every backend handler.
	 *
	 * The legacy helpers above key off access_level alone. That is not enough:
	 * access_level 2 ("RCDC Labs") covers TWO different business roles, the
	 * NDTL/NMPTL laboratory (user_category 18) and the NMRL laboratory
	 * (user_category 19), which do not have the same permissions. Authorisation
	 * is therefore resolved to an EFFECTIVE ROLE from the access level AND the
	 * user category, both of which are re-derived from
	 * commondb.user_system_access_table (system_id 9) on the server.
	 *
	 *     ADMIN  access_level 1            (user_category 1, and the legacy 1:18
	 *                                       account, both behave as Administrator
	 *                                       because level 1 is unambiguous)
	 *     NDTL   access_level 2 + cat 18   NDTL / NMPTL laboratory
	 *     NMRL   access_level 2 + cat 19   NMRL laboratory
	 *     BFDA   access_level 7            BFDA / DRA regulator
	 *     NONE   anything else             no permissions at all
	 *
	 * DENY BY DEFAULT: a module/action pair that is not listed grants nothing,
	 * and an unrecognised or unresolved role grants nothing. Use mpq_can_do()
	 * for UI visibility and mpq_require()/mpq_require_page() in the handler, so
	 * a hidden button, a hand-typed URL and a forged POST all reach the same
	 * decision.
	 * ===================================================================== */
	function mpq_effective_role() {
		$acl  = (int)($_SESSION['acl'] ?? 0);
		$ucat = (int)($_SESSION['usercat'] ?? 0);
		// MPD (Medicinal Products Division, Oct 2026) is a specific usercat under acl=1
		// — the specific match MUST be checked BEFORE the generic acl=1 → ADMIN fallthrough.
		if ($acl === 1 && $ucat === 20) { return 'MPD'; }
		if ($acl === 1) { return 'ADMIN'; }
		if ($acl === 2 && $ucat === 18) { return 'NDTL'; }
		if ($acl === 2 && $ucat === 19) { return 'NMRL'; }
		if ($acl === 7) { return 'BFDA'; }
		return 'NONE';
	}

	function mpq_effective_role_label() {
		switch (mpq_effective_role()) {
			case 'ADMIN': return 'Administrator';
			case 'MPD':   return 'MPD (Medicinal Products Division)';
			case 'NDTL':  return 'NMPTL Laboratory';
			case 'NMRL':  return 'NMRL Laboratory';
			case 'BFDA':  return 'BFDA / DRA';
			default:      return 'Unrecognised role';
		}
	}

	/**
	 * module => action => roles allowed. Anything absent is denied.
	 * Conditions that depend on record state (the completed-report lock, and the
	 * Administrator-verification gate on the Final Result) are NOT expressed here:
	 * they are separate, existing checks that still run after this one.
	 */
	function mpq_permission_matrix() {
		static $m = null;
		if ($m !== null) { return $m; }
		/*
		 * Oct 2026 updates (per client decision):
		 *   • MPD is a new 5th role (program oversight, Medicinal Products Division).
		 *     Scope: view + export only, across every data surface MPD needs to oversee.
		 *     NEVER granted write / approve / delete / user-management — overseers audit
		 *     the work, they do not modify data. (If MPD needs to intervene they do it
		 *     through the ADMIN account, same way the shared RCDC portal works today.)
		 *   • BFDA (the regulator) now has FULL write access on the sample workflow
		 *     (registration add/edit/delete, acceptance submit, parameters add/delete,
		 *     results edit, final_result finalize). APPROVAL stays Administrator-only —
		 *     that is the quality-control checkpoint and must remain in the lab's hands.
		 *     This reverses the July-2026 "BFDA = read-only" rule; see memory.
		 */
		$m = array(
			'dashboard'       => array('view'=>array('ADMIN','MPD','NDTL','NMRL','BFDA')),
			// The Registration & Result manager list and the sample record itself.
			'manager'         => array('view'=>array('ADMIN','MPD','NDTL','BFDA'),
			                           'export'=>array('ADMIN','MPD','NDTL','BFDA')),
			'registration'    => array('view'=>array('ADMIN','MPD','NDTL','BFDA'),
			                           'add'=>array('ADMIN','NDTL','BFDA'),
			                           'edit'=>array('ADMIN','NDTL','BFDA'),
			                           'delete'=>array('ADMIN','BFDA')),
			'acceptance'      => array('submit'=>array('ADMIN','NDTL','BFDA')),
			'parameters'      => array('add'=>array('ADMIN','NDTL','BFDA'),
			                           'delete'=>array('ADMIN','BFDA')),
			'results'         => array('edit'=>array('ADMIN','NDTL','BFDA'),
			                           // Approval remains the quality-control checkpoint
			                           // and stays Administrator-only.
			                           'approve'=>array('ADMIN')),
			// NDTL / BFDA may finalise only once the Administrator has decided every
			// result row (SRS gate — enforced in save_update_details.php). Admin bypasses.
			'final_result'    => array('finalize'=>array('ADMIN','NDTL','BFDA')),
			'pdf'             => array('view'=>array('ADMIN','MPD','NDTL','BFDA')),
			'official_report' => array('view'=>array('ADMIN','MPD','NDTL','BFDA')),
			'reports'         => array('view'=>array('ADMIN','MPD','NDTL'),
			                           'export'=>array('ADMIN','MPD','NDTL')),
			// Formulation / Parameter / Category share one rule.
			'settings_core'   => array('view'=>array('ADMIN','MPD','NDTL'),
			                           'add'=>array('ADMIN','NDTL'),
			                           'edit'=>array('ADMIN','NDTL'),
			                           'delete'=>array('ADMIN'),
			                           'import'=>array('ADMIN','NDTL')),
			'edl'             => array('view'=>array('ADMIN','MPD'), 'add'=>array('ADMIN'),
			                           'edit'=>array('ADMIN'), 'delete'=>array('ADMIN'),
			                           'import'=>array('ADMIN')),
			// Manufacturer and MAH are open master data: every role may view, add and
			// edit. Delete stays Administrator-only. Bulk import is NOT part of the
			// BFDA or MPD grant.
			'manufacturer'    => array('view'=>array('ADMIN','MPD','NDTL','NMRL','BFDA'),
			                           'add'=>array('ADMIN','NDTL','NMRL','BFDA'),
			                           'edit'=>array('ADMIN','NDTL','NMRL','BFDA'),
			                           'delete'=>array('ADMIN'),
			                           'import'=>array('ADMIN','NDTL','NMRL')),
			'mah'             => array('view'=>array('ADMIN','MPD','NDTL','NMRL','BFDA'),
			                           'add'=>array('ADMIN','NDTL','NMRL','BFDA'),
			                           'edit'=>array('ADMIN','NDTL','NMRL','BFDA'),
			                           'delete'=>array('ADMIN'),
			                           'import'=>array('ADMIN','NDTL','NMRL')),
			// User & role management stays with the technical Administrator; MPD is an
			// oversight role, not a user-management one.
			'users'           => array('view'=>array('ADMIN'), 'add'=>array('ADMIN'),
			                           'edit'=>array('ADMIN'), 'delete'=>array('ADMIN')),
			// Oct 2026 — Assigned-Analyst and Approver masters. ADMIN-only across the
			// board. The dropdowns in registration.php read the DB tables directly
			// (no mpq_can_do check), so this restriction hides only the admin UI;
			// non-admin lab users still see the names in the dropdowns on the Test
			// Result card. Oct-13 directive: view also narrows to ADMIN so the
			// Analyst & Approver menu link is hidden from BFDA, NDTL and NMRL.
			'analyst_master'  => array('view'=>array('ADMIN'),
			                           'add'=>array('ADMIN'),'edit'=>array('ADMIN'),
			                           'delete'=>array('ADMIN')),
			'approver_master' => array('view'=>array('ADMIN'),
			                           'add'=>array('ADMIN'),'edit'=>array('ADMIN'),
			                           'delete'=>array('ADMIN')),
		);
		return $m;
	}

	/*
	 * Oct 2026 — DB-backed override of the hard-coded matrix (above).
	 *
	 * The 5 effective roles (ADMIN, MPD, NDTL, NMRL, BFDA) still come from
	 * (access_level, user_category); only the per-role PERMISSIONS move to the
	 * database. One row per (role_id, module_name) in `mpqms_role_permissions`
	 * carries six boolean action buckets:
	 *     can_view, can_enter, can_edit, can_review, can_approve, can_manage
	 *
	 * mpq_can_do($module, $action) maps the fine-grained action keywords used
	 * across the ~40 call sites to those 6 buckets:
	 *     view                      -> can_view
	 *     add  / submit             -> can_enter
	 *     edit                      -> can_edit
	 *     approve / finalize        -> can_approve
	 *     delete / import / export  -> can_manage
	 *     (review is reserved for a future action)
	 *
	 * Rules:
	 *   • If the (role, module) row EXISTS in mpqms_role_permissions and the
	 *     mapped column is 1 -> allowed; else denied by that row.
	 *   • If the row is MISSING (role simply has no explicit grant on this
	 *     module) OR the DB is unavailable -> fall back to the hard-coded
	 *     `mpq_permission_matrix()`. This keeps the system working during a
	 *     migration or a DB blip.
	 * Cached once per request to avoid repeating the SELECT on every page gate.
	 */
	function mpq_action_bucket($action) {
		switch (strtolower((string)$action)) {
			case 'view':                             return 'can_view';
			case 'add': case 'submit': case 'new':   return 'can_enter';
			case 'edit': case 'update':              return 'can_edit';
			case 'review':                           return 'can_review';
			case 'approve': case 'finalize':         return 'can_approve';
			case 'delete': case 'remove':
			case 'import': case 'export':
			case 'manage':                           return 'can_manage';
			default:                                 return null;
		}
	}

	function mpq_db_permissions() {
		static $cache = null;
		if ($cache !== null) { return $cache; }
		$cache = array();
		// $connection is opened by config/db_config.php. mpq_can_do() is often called from
		// mpq_require_page() BEFORE a page includes db_config.php, so we lazy-include here
		// if needed. `global` MUST be declared BEFORE the include so the file's `$connection`
		// / `$commondb` assignments land on the globals, not on function-local variables —
		// otherwise include_once masks the second include elsewhere on the page and the
		// variables stay undefined (bug seen 2026-10-07 on settings/roles.php). Fall back to
		// the empty cache + hard-coded matrix if no connection could be opened.
		global $connection, $commondb;
		if (!isset($connection) || !($connection instanceof mysqli)) {
			$db = dirname(__DIR__) . '/config/db_config.php';
			if (is_file($db)) { @include_once($db); }
		}
		$conn = (isset($connection) && ($connection instanceof mysqli)) ? $connection : null;
		if (!$conn) { return $cache; }
		try {
			$sql = "SELECT r.role_name, p.module_name, p.can_view, p.can_enter, p.can_edit,
			               p.can_review, p.can_approve, p.can_manage
			        FROM mpqms_role_permissions p
			        JOIN mpqms_roles r ON r.id = p.role_id";
			$res = @mysqli_query($conn, $sql);
			if ($res) {
				while ($row = mysqli_fetch_assoc($res)) {
					$cache[(string)$row['role_name']][(string)$row['module_name']] = array(
						'can_view'    => (int)$row['can_view'],
						'can_enter'   => (int)$row['can_enter'],
						'can_edit'    => (int)$row['can_edit'],
						'can_review'  => (int)$row['can_review'],
						'can_approve' => (int)$row['can_approve'],
						'can_manage'  => (int)$row['can_manage'],
					);
				}
			}
		} catch (\Throwable $e) { /* fall back silently */ }
		return $cache;
	}

	/** UI + backend permission test. Unknown module/action or role => false. */
	function mpq_can_do($module, $action) {
		$role = mpq_effective_role();
		if ($role === 'NONE') { return false; }
		// 1. DB-stored matrix — authoritative when a (role, module) row exists.
		$bucket = mpq_action_bucket($action);
		if ($bucket !== null) {
			$db = mpq_db_permissions();
			if (isset($db[$role][$module])) {
				return (bool)$db[$role][$module][$bucket];
			}
		}
		// 2. Hard-coded fallback (used when the DB row for this role/module is
		//    missing — e.g. during migration or if a new module was added in code
		//    but not yet seeded into the DB).
		$m = mpq_permission_matrix();
		if (!isset($m[$module][$action])) { return false; }
		return in_array($role, $m[$module][$action], true);
	}

	/** Backend enforcer for handlers/endpoints: denies with the standard response and exits. */
	function mpq_require($module, $action, $mpAjax = false, $msg = null) {
		if (!mpq_can_do($module, $action)) {
			mpq_fail($msg !== null ? $msg : 'You are not authorised to perform this action.', $mpAjax);
		}
	}

	/** Page enforcer: redirects a disallowed role away before any output. */
	function mpq_require_page($module, $action, $redirect = '../dashboard/dashboard.php') {
		if (!mpq_can_do($module, $action)) {
			header('Location: '.$redirect);
			exit;
		}
	}

	function mpq_require_create($mpAjax = false, $msg = 'You are not authorised to perform this action.') { if (!mpq_can_create()) { mpq_fail($msg, $mpAjax); } }
	function mpq_require_update($mpAjax = false, $msg = 'You are not authorised to perform this action.') { if (!mpq_can_update()) { mpq_fail($msg, $mpAjax); } }
	function mpq_require_delete($mpAjax = false, $msg = 'You are not authorised to perform this action.') { if (!mpq_can_delete()) { mpq_fail($msg, $mpAjax); } }
	function mpq_require_read($mpAjax = false,   $msg = 'You are not authorised to view this resource.')   { if (!mpq_can_read())   { mpq_fail($msg, $mpAjax); } }
}
