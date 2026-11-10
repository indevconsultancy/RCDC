<?php
/* =====================================================================
   mNEQAS — central server-side authorization layer
   ---------------------------------------------------------------------
   Identity: config/session.php re-reads the caller's system_id=12 role
   and facility from rcdc_commondb on EVERY request (mn_bootstrap_session)
   and pins them in MN_AUTH_* constants. Guards below read those constants,
   never a URL/POST value and never a session value another application
   sharing the PHP session cookie may have written.

   Role model (system_id = 12, rcdc_commondb.ms_access_level_table):
     1  Administrator / RCDC Super User  all facilities, all stages, delete,
                                          Blinded Controller 2 + final scoring
     2  RCDC Labs                        all facilities, RCDC operational stages,
                                          Settings, Slide Banking — NOT Blinded
                                          Controller 2 / final scoring
     3  Health Worker (facility)         own facility only, participant stages
     4  District Health Office           own facility only, READ-ONLY (no
                                          district mapping exists in mNEQAS, no
                                          write operation is defined for it)
     5, 6, 7, 99 and anything else       DEFAULT DENY for mNEQAS module
                                          functions (dashboard only)

   Include AFTER config/session.php, then call a guard. Denials send HTTP 403,
   are written to the PHP error log with user/role/facility/endpoint, and stop.
   ===================================================================== */

if (!defined('MN_ROLE_ADMIN')) {
    define('MN_ROLE_ADMIN', 1);
    define('MN_ROLE_RCDC_LABS', 2);
    define('MN_ROLE_HEALTH_WORKER', 3);
    define('MN_ROLE_DISTRICT', 4);
}

/* ---- Identity ------------------------------------------------------- */
if (!function_exists('mn_role')) {
    function mn_role() {
        if (defined('MN_AUTH_ROLE')) { return MN_AUTH_ROLE; }
        return isset($_SESSION['roleID']) ? (int)$_SESSION['roleID'] : 0;
    }
}
if (!function_exists('mn_facility')) {
    function mn_facility() {
        if (defined('MN_AUTH_FACILITY')) { return MN_AUTH_FACILITY; }
        return isset($_SESSION['facilityID']) ? $_SESSION['facilityID'] : null;
    }
}
if (!function_exists('mn_user')) {
    function mn_user() {
        if (defined('MN_AUTH_USER')) { return MN_AUTH_USER; }
        return isset($_SESSION['userID']) ? (string)$_SESSION['userID'] : '';
    }
}

/**
 * Called by config/session.php. Looks the logged-in username up in commondb for
 * system_id=12 (the same join login/authentications.php uses) and pins role +
 * facility for this request. A session without a system-12 grant (e.g. one
 * created by another portal/app on the shared cookie) is refused with 403.
 * The session is closed for writing first, so mNEQAS never rewrites keys that
 * sibling applications on the same cookie read; the in-memory $_SESSION values
 * are aligned for the existing code that reads $_SESSION["roleID"] directly.
 */
if (!function_exists('mn_bootstrap_session')) {
    function mn_bootstrap_session($commondb) {
        if (defined('MN_AUTH_ROLE')) { return; }
        $username = isset($_SESSION['userID']) ? (string)$_SESSION['userID'] : '';
        $row = null;
        try {
            $st = $commondb->prepare("SELECT t3.access_level, t2.center_id
                                        FROM login_table t1
                                        INNER JOIN user_profile_table t2 ON t2.username = t1.username
                                        INNER JOIN user_system_access_table t3 ON t3.username = t1.username AND t3.system_id = 12
                                       WHERE t1.username = ?
                                       LIMIT 1");
            $st->bind_param('s', $username);
            $st->execute();
            $row = $st->get_result()->fetch_row();
            $st->close();
        } catch (\Throwable $e) {
            $row = null;
        }
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

        if (!$row) {
            define('MN_AUTH_ROLE', 0);
            define('MN_AUTH_FACILITY', null);
            define('MN_AUTH_USER', $username);
            mn_log_denied('no system_id=12 access');
            if (!headers_sent()) { http_response_code(403); }
            echo '<!doctype html><meta charset="utf-8"><title>Access denied</title>'
               . '<div style="font-family:Arial,sans-serif;max-width:560px;margin:80px auto;text-align:center">'
               . '<h2>403 &mdash; Access denied</h2><p>Your account does not have access to mNEQAS.</p>'
               . '<p><a href="/MNEQAS/login/signout.php">Sign in with an mNEQAS account</a></p></div>';
            exit;
        }
        define('MN_AUTH_ROLE', (int)$row[0]);
        define('MN_AUTH_FACILITY', $row[1] === null ? null : (string)$row[1]);
        define('MN_AUTH_USER', $username);
        $_SESSION['roleID']     = (string)$row[0];
        $_SESSION['facilityID'] = $row[1];
    }
}

/* ---- Role predicates (explicit, no "not RCDC = participant") --------- */
if (!function_exists('mn_is_admin')) {
    function mn_is_admin() { return mn_role() === MN_ROLE_ADMIN; }
}
if (!function_exists('mn_is_rcdc_labs')) {
    function mn_is_rcdc_labs() { return mn_role() === MN_ROLE_RCDC_LABS; }
}
if (!function_exists('mn_is_rcdc')) {
    function mn_is_rcdc() { $r = mn_role(); return ($r === MN_ROLE_ADMIN || $r === MN_ROLE_RCDC_LABS); }
}
if (!function_exists('mn_is_health_worker')) {
    function mn_is_health_worker() { return mn_role() === MN_ROLE_HEALTH_WORKER; }
}
if (!function_exists('mn_is_district')) {
    function mn_is_district() { return mn_role() === MN_ROLE_DISTRICT; }
}
// Participant = a facility user who performs participant workflow stages (role 3).
if (!function_exists('mn_is_participant')) {
    function mn_is_participant() { return mn_is_health_worker(); }
}
// Facility-scoped viewer: roles 3 and 4 with a facility on their profile.
if (!function_exists('mn_is_facility_user')) {
    function mn_is_facility_user() {
        $r = mn_role();
        $f = mn_facility();
        return ($r === MN_ROLE_HEALTH_WORKER || $r === MN_ROLE_DISTRICT) && $f !== null && trim((string)$f) !== '';
    }
}
// May use the mNEQAS modules at all (lists, records, reports).
if (!function_exists('mn_can_access_modules')) {
    function mn_can_access_modules() { return mn_is_rcdc() || mn_is_facility_user(); }
}

/* ---- Facility scope -------------------------------------------------- */
if (!function_exists('mn_same_facility')) {
    function mn_same_facility($a, $b) {
        $a = trim((string)$a); $b = trim((string)$b);
        if ($a === '' || $b === '') { return false; }
        if (ctype_digit($a) && ctype_digit($b)) { return (int)$a === (int)$b; }
        return $a === $b;
    }
}
/* RCDC (1/2): any facility. Facility users (3/4): only their own. Others: none. */
if (!function_exists('mn_owns_facility')) {
    function mn_owns_facility($facilityId) {
        if (mn_is_rcdc()) { return true; }
        if (!mn_is_facility_user()) { return false; }
        return mn_same_facility($facilityId, mn_facility());
    }
}
if (!function_exists('mn_can_access_facility')) {
    function mn_can_access_facility($facilityId) { return mn_owns_facility($facilityId); }
}

/* ---- Audit log + deny responses -------------------------------------- */
if (!function_exists('mn_log_denied')) {
    function mn_log_denied($reason, $recordId = null) {
        $entry = array(
            'ts'       => date('c'),
            'user'     => mn_user(),
            'role'     => mn_role(),
            'facility' => mn_facility(),
            'endpoint' => isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : (PHP_SAPI === 'cli' ? 'cli' : ''),
            'method'   => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
            'record'   => $recordId,
            'action'   => isset($_POST['submit']) ? (string)$_POST['submit'] : (isset($_GET['type']) ? (string)$_GET['type'] : ''),
            'result'   => 'DENIED',
            'reason'   => (string)$reason,
        );
        error_log('[mNEQAS-AUTHZ] ' . json_encode($entry));
    }
}
// Page / form-POST context: 403 + alert + back to the dashboard.
if (!function_exists('mn_deny')) {
    function mn_deny($msg = 'You are not authorised to access this resource.', $recordId = null) {
        mn_log_denied($msg, $recordId);
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { http_response_code(403); header('Content-Type: text/html; charset=utf-8'); }
        echo '<!doctype html><meta charset="utf-8"><title>403 Access denied</title>'
           . '<p style="font-family:Arial,sans-serif">403 &mdash; ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
           . '<script>alert(' . json_encode($msg) . ');window.location="/MNEQAS/dashboard/dashboard.php";</script>';
        exit;
    }
}
// JSON / AJAX context. Carries both key styles used by existing endpoints.
if (!function_exists('mn_deny_json')) {
    function mn_deny_json($msg = 'Not authorised.', $recordId = null) {
        mn_log_denied($msg, $recordId);
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(array('ok' => false, 'success' => false, 'status' => 'error', 'msg' => $msg, 'message' => $msg));
        exit;
    }
}
// Plain-text context (e.g. AJAX HTML fragment loaders, CSV/PDF endpoints).
if (!function_exists('mn_deny_plain')) {
    function mn_deny_plain($msg = 'Access denied.', $recordId = null) {
        mn_log_denied($msg, $recordId);
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); }
        echo '403 - ' . $msg;
        exit;
    }
}
if (!function_exists('mn_deny_as')) {
    function mn_deny_as($mode, $msg, $recordId = null) {
        if ($mode === 'json')  { mn_deny_json($msg, $recordId); }
        if ($mode === 'plain') { mn_deny_plain($msg, $recordId); }
        mn_deny($msg, $recordId);
    }
}

/* ---- Guards ---------------------------------------------------------- */
if (!function_exists('mn_require_login')) {
    function mn_require_login($mode = 'page') {
        if (mn_user() === '' || mn_role() === 0) { mn_deny_as($mode, 'Please sign in.'); }
    }
}
if (!function_exists('mn_require_role')) {
    function mn_require_role(array $roles, $mode = 'page', $msg = 'You are not authorised to perform this action.') {
        if (!in_array(mn_role(), array_map('intval', $roles), true)) { mn_deny_as($mode, $msg); }
    }
}
if (!function_exists('mn_require_module_access')) {
    function mn_require_module_access($mode = 'page', $msg = 'Your role does not have access to this mNEQAS module.') {
        if (!mn_can_access_modules()) { mn_deny_as($mode, $msg); }
    }
}
if (!function_exists('mn_require_rcdc')) {
    function mn_require_rcdc($msg = 'You are not authorised to access this page.', $mode = 'page') {
        if (!mn_is_rcdc()) { mn_deny_as($mode, $msg); }
    }
}
if (!function_exists('mn_require_admin')) {
    function mn_require_admin($msg = 'Administrator access is required.', $mode = 'page') {
        if (!mn_is_admin()) { mn_deny_as($mode, $msg); }
    }
}
if (!function_exists('mn_require_facility')) {
    function mn_require_facility($facilityId, $msg = 'You are not authorised to access this record.', $mode = 'page') {
        if (!mn_owns_facility($facilityId)) { mn_deny_as($mode, $msg); }
    }
}

/* ---- Area (folder) policy — default deny, applied by config/session.php ----
   dashboard/            any user with a system_id=12 grant (aggregate counts only)
   settings/, bankings/  RCDC (1, 2)
   every other folder    module access (RCDC, or facility user 3/4); record,
                         stage and write checks are then done by each endpoint. */
if (!function_exists('mn_enforce_area_policy')) {
    function mn_enforce_area_policy() {
        if (PHP_SAPI === 'cli' && empty($_SERVER['REQUEST_METHOD'])) { return; }
        $script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
        $root   = realpath(__DIR__ . '/..');
        $area   = '';
        if ($script !== false && $root !== false && strpos($script, $root . DIRECTORY_SEPARATOR) === 0) {
            $rel  = str_replace('\\', '/', substr($script, strlen($root) + 1));
            $area = (strpos($rel, '/') !== false) ? strtolower(strstr($rel, '/', true)) : '';
        }
        $xhr  = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $mode = $xhr ? 'json' : 'page';
        if ($area === 'dashboard') { return; }
        if ($area === 'settings' || $area === 'bankings') {
            mn_require_rcdc('This area is restricted to RCDC users.', $mode);
            return;
        }
        mn_require_module_access($mode);
    }
}

/* ---- CRUD capability model ------------------------------------------
   Create/Update: Administrator (1), RCDC Labs (2), Health Worker (3, own
   facility). District (4) and every other role: no write.
   Whole-record delete: Administrator only. Workflow undo/clear operations
   are stage permissions checked by each endpoint. */
if (!function_exists('mn_can_write')) {
    function mn_can_write() { $r = mn_role(); return ($r === 1 || $r === 2 || ($r === 3 && mn_is_facility_user())); }
}
if (!function_exists('mn_is_readonly')) {
    function mn_is_readonly() { return !mn_can_write(); }
}
if (!function_exists('mn_require_write')) {
    function mn_require_write($msg = 'You have read-only access and cannot modify data.', $mode = 'page') {
        if (!mn_can_write()) { mn_deny_as($mode, $msg); }
    }
}
if (!function_exists('mn_can_delete')) {
    function mn_can_delete() { return mn_is_admin(); }
}
if (!function_exists('mn_require_delete')) {
    function mn_require_delete($msg = 'Only an Administrator can delete records.', $mode = 'page') {
        if (!mn_can_delete()) { mn_deny_as($mode, $msg); }
    }
}
if (!function_exists('mn_can_write_facility')) {
    function mn_can_write_facility($facilityId) { return mn_can_write() && mn_owns_facility($facilityId); }
}

/* ---- Records: authoritative facility of a record ---------------------
   Whitelisted lookups only (kind => SQL). Every id is bound as integer. */
if (!function_exists('mn_record_sql')) {
    function mn_record_sql($kind) {
        static $map = array(
            'blinded'         => "SELECT facility_id FROM blinded_informations WHERE blinded_id = ?",
            'blinded_result'  => "SELECT b.facility_id FROM blinded_results r INNER JOIN blinded_informations b ON b.blinded_id = r.blinded_id WHERE r.result_id = ?",
            'onsite'          => "SELECT facility_id FROM onsite_general_informations WHERE gi_id = ?",
            'onsite_staff'    => "SELECT g.facility_id FROM onsite_staff_informations s INNER JOIN onsite_general_informations g ON g.gi_id = s.general_info_id WHERE s.staff_id = ?",
            'rdt_request'     => "SELECT facility_id FROM rdt_test_request WHERE rdt_request_id = ?",
            'rdt_product'     => "SELECT t.facility_id FROM rdt_product_details p INNER JOIN rdt_test_request t ON t.rdt_request_id = p.test_request_id WHERE p.product_id = ?",
            'dbs'             => "SELECT facility_id FROM dbs_results WHERE dbs_id = ?",
            'dbs_shipment'    => "SELECT facility_id FROM dbs_shipment_details WHERE shipment_id = ?",
            'panel'           => "SELECT facility_id FROM panel_informations WHERE panel_id = ?",
            'panel_slide'     => "SELECT p.facility_id FROM panel_slide_informations s INNER JOIN panel_informations p ON p.panel_id = s.panel_id WHERE s.slide_id = ?",
            'panel_info'      => "SELECT p.facility_id FROM panel_lab_result_informations i INNER JOIN panel_informations p ON p.panel_id = i.panel_id WHERE i.information_id = ?",
            'rdt_panel'       => "SELECT facility_id FROM rdt_panel_informations WHERE panel_id = ?",
            'rdt_panel_slide' => "SELECT p.facility_id FROM rdt_panel_slide_informations s INNER JOIN rdt_panel_informations p ON p.panel_id = s.panel_id WHERE s.slide_id = ?",
        );
        return isset($map[$kind]) ? $map[$kind] : null;
    }
}
/* Returns array('found'=>bool, 'facility'=>string|null). */
if (!function_exists('mn_record_facility')) {
    function mn_record_facility($conn, $kind, $id) {
        $sql = mn_record_sql($kind);
        if ($sql === null) { throw new InvalidArgumentException('Unknown record kind'); }
        $iid = (int)$id;
        if ($iid <= 0) { return array('found' => false, 'facility' => null); }
        $st = $conn->prepare($sql);
        $st->bind_param('i', $iid);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();
        return $row ? array('found' => true, 'facility' => $row[0]) : array('found' => false, 'facility' => null);
    }
}
if (!function_exists('mn_can_view_record')) {
    function mn_can_view_record($conn, $kind, $id) {
        if (!mn_can_access_modules()) { return false; }
        $rec = mn_record_facility($conn, $kind, $id);
        if (mn_is_rcdc()) { return true; }
        return $rec['found'] && mn_owns_facility($rec['facility']);
    }
}
/* Deny unless the caller may access the record. RCDC passes even when the id
   does not exist (existing pages render their own "not found"). Returns the
   owning facility id (or null). */
if (!function_exists('mn_require_record')) {
    function mn_require_record($conn, $kind, $id, $mode = 'page', $msg = 'You are not authorised to access this record.') {
        mn_require_module_access($mode);
        $rec = mn_record_facility($conn, $kind, $id);
        if (mn_is_rcdc()) { return $rec['facility']; }
        if (!$rec['found'] || !mn_owns_facility($rec['facility'])) { mn_deny_as($mode, $msg, (int)$id); }
        return $rec['facility'];
    }
}
if (!function_exists('mn_require_record_write')) {
    function mn_require_record_write($conn, $kind, $id, $mode = 'page', $msg = 'You are not authorised to modify this record.') {
        mn_require_write('You have read-only access and cannot modify data.', $mode);
        return mn_require_record($conn, $kind, $id, $mode, $msg);
    }
}

/* ---- Child record belongs to parent (IDOR on secondary ids) ---------- */
if (!function_exists('mn_child_belongs')) {
    function mn_child_belongs($conn, $kind, $childId, $parentId) {
        static $map = array(
            'blinded_result'  => "SELECT 1 FROM blinded_results WHERE result_id = ? AND blinded_id = ?",
            'onsite_staff'    => "SELECT 1 FROM onsite_staff_informations WHERE staff_id = ? AND general_info_id = ?",
            'rdt_product'     => "SELECT 1 FROM rdt_product_details WHERE product_id = ? AND test_request_id = ?",
            'rdt_performance' => "SELECT 1 FROM rdt_performance_report r INNER JOIN rdt_product_details p ON p.product_id = r.manufacturer_id WHERE r.performance_id = ? AND p.test_request_id = ?",
            'rdt_assessment'  => "SELECT 1 FROM rdt_assessment_report r INNER JOIN rdt_product_details p ON p.product_id = r.manufacturer_id WHERE r.rar_id = ? AND p.test_request_id = ?",
            'panel_slide'     => "SELECT 1 FROM panel_slide_informations WHERE slide_id = ? AND panel_id = ?",
            'panel_info'      => "SELECT 1 FROM panel_lab_result_informations WHERE information_id = ? AND panel_id = ?",
            'panel_result'    => "SELECT 1 FROM panel_lab_results WHERE result_id = ? AND information_id = ?",
            'rdt_panel_slide' => "SELECT 1 FROM rdt_panel_slide_informations WHERE slide_id = ? AND panel_id = ?",
        );
        if (!isset($map[$kind])) { throw new InvalidArgumentException('Unknown child kind'); }
        $c = (int)$childId; $p = (int)$parentId;
        if ($c <= 0 || $p <= 0) { return false; }
        $st = $conn->prepare($map[$kind]);
        $st->bind_param('ii', $c, $p);
        $st->execute();
        $ok = (bool)$st->get_result()->fetch_row();
        $st->close();
        return $ok;
    }
}
if (!function_exists('mn_require_child')) {
    function mn_require_child($conn, $kind, $childId, $parentId, $mode = 'page', $msg = 'The submitted item does not belong to this record.') {
        if (!mn_child_belongs($conn, $kind, $childId, $parentId)) { mn_deny_as($mode, $msg, (int)$childId); }
    }
}

/* ---- Panel workflow state (Micro + RDT panels) -----------------------
   CLOSED = today is after the round closing_date (same rule as the panel
   pages). ONTIME/LATE = today within / after receive_date + 30 days. */
if (!function_exists('mn_panel_state')) {
    function mn_panel_state($conn, $module, $panelId) {
        $sql = ($module === 'rdt')
            ? "SELECT DATE(r.closing_date), DATE(p.receive_date), CURDATE() FROM rdt_panel_informations p INNER JOIN rdt_panel_round_informations r ON r.round_id = p.round_id WHERE p.panel_id = ?"
            : "SELECT DATE(r.closing_date), DATE(p.receive_date), CURDATE() FROM panel_informations p INNER JOIN panel_round_informations r ON r.round_id = p.round_id WHERE p.panel_id = ?";
        $pid = (int)$panelId;
        $st = $conn->prepare($sql);
        $st->bind_param('i', $pid);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();
        if (!$row) { return null; }
        list($closing, $receive, $today) = $row;
        $closed = ($closing !== null && $today > $closing);
        if ($closed)              { $flag = 'CLOSED'; }
        elseif ($receive === null) { $flag = 'OPEN'; }
        else                       { $flag = ($today <= date('Y-m-d', strtotime($receive . ' +30 days'))) ? 'ONTIME' : 'LATE'; }
        return array('closed' => $closed, 'received' => $receive !== null, 'flag' => $flag, 'closing_date' => $closing, 'receive_date' => $receive);
    }
}

/* ---- RDT Panel ownership guard (kept for existing callers) ----------- */
if (!function_exists('mn_require_rdt_panel_ownership')) {
    function mn_require_rdt_panel_ownership($conn, $panel_id, $mode = 'page', $msg = 'You are not authorised to access this RDT panel.') {
        if ((int)$panel_id <= 0) { mn_deny_as($mode, 'Invalid panel id.'); }
        return mn_require_record($conn, 'rdt_panel', $panel_id, $mode, $msg);
    }
}
