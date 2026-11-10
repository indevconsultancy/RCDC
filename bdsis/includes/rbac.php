<?php
/**
 * Role-based access control for BDSIS.
 *
 * ---------------------------------------------------------------------------
 * Why this file exists
 * ---------------------------------------------------------------------------
 * Scoping used to be written per page as:
 *
 *     if ($acl == 3 && $usercat == 3) { $sql .= " AND center_id='$ctid'"; }
 *
 * i.e. "if you are a facility laboratorian, restrict you; otherwise show
 * everything". The user base holds 13 distinct (access_level, user_category)
 * pairs but the code only ever tested three, so 40 of 167 users matched no
 * branch and silently received national visibility -- including 27 BFDA food-
 * safety users who are not clinical staff at all.
 *
 * This file inverts that into default-deny: a role gets national reach only if
 * it is explicitly listed, every other recognised role is confined to its own
 * health facility, and anything unrecognised is refused outright.
 *
 * Role vocabulary (commondb.ms_access_level_table / ms_user_category_table):
 *   access_level  1 Administrator · 2 RCDC Labs · 3 Health Workers
 *                 4 District Health Office · 5 Ministry of Health
 *                 6 BFDA(BAFRA) · 7 BFDA(DRA) · 88 Water supplier · 99 Others
 *   user_category 1 Administrator · 2 Clinicians · 3 Laboratorians · 4 Nurses
 *                 13 EIDL · 14 FNL · 15 BFDA Food Inspector · 16 BFDA Admin
 *                 96 DHO · 99 Others
 */

if (!defined('BDSIS_SCOPE_NATIONAL')) {
    define('BDSIS_SCOPE_NATIONAL', 'national'); // every facility
    define('BDSIS_SCOPE_FACILITY', 'facility'); // own centre only
    define('BDSIS_SCOPE_DISTRICT', 'district'); // every participating centre in own dzongkhag
    define('BDSIS_SCOPE_DENIED',   'denied');   // no access to this module
}

if (!function_exists('bdsis_commondb_name')) {
    /**
     * The schema name of the common database, for statements that reach it from
     * the $bdsis connection.
     *
     * It is rcdc_commondb on this server and plain commondb elsewhere, and the
     * name used to be written into each statement -- where it was wrong, the
     * query returned false and the ->fetch_row() after it was fatal. The config
     * is loaded on demand because some entry points (lab/delete.php) never
     * include it, and the old literal remains the fallback so a deployment
     * without the config behaves exactly as it did before.
     */
    function bdsis_commondb_name()
    {
        if (!defined('BDSIS_COMMONDB')) {
            $cfg = __DIR__ . '/../config/commondb-config.php';
            if (is_readable($cfg)) { require_once $cfg; }
        }
        return defined('BDSIS_COMMONDB') ? BDSIS_COMMONDB : 'commondb';
    }
}

if (!function_exists('bdsis_identity')) {
    /**
     * The caller's BDSIS role, read from the database rather than the session.
     *
     * commondb is shared by ten surveillance systems and one browser session is
     * shared with all of them. $_SESSION['acl'] holds whichever system the user
     * last entered, so BDSIS was reading another system's access level as if it
     * were its own. Both directions were wrong:
     *
     *   - A Drug Regulatory Authority user with no BDSIS row at all arrived
     *     carrying MPQMS's acl=7 and was refused for the wrong reason.
     *   - Seven accounts whose BDSIS row is acl=2 or acl=5 hold acl=1
     *     (Administrator) in another system, so arriving from that system they
     *     became BDSIS administrators -- gaining delete, export and approve.
     *
     * user_system_access_table WHERE system_id=6 is the only thing that says what
     * a user may do in BDSIS, so that is what is read here. It is also what the
     * BDSIS login already queried, which is why logging in through BDSIS itself
     * always behaved correctly.
     *
     * Resolved once per request. 'has_access' distinguishes "no BDSIS row" from
     * "a row whose role BDSIS does not admit", so the refusal can say which.
     *
     * @return array username, acl, usercat, centre, has_access
     */
    function bdsis_identity()
    {
        static $cache = null;
        $username = isset($_SESSION['users']) ? (string)$_SESSION['users'] : '';
        if ($cache !== null && $cache['username'] === $username) { return $cache; }

        $blank = array('username' => $username, 'acl' => '', 'usercat' => '',
                       'centre' => '', 'has_access' => false);
        if ($username === '') { return ($cache = $blank); }

        /* Prefer the connection the page already opened; open our own only when
           the page did not include commondb-config (lab/delete.php, for one). */
        $db = isset($GLOBALS['commondb']) && ($GLOBALS['commondb'] instanceof mysqli)
            ? $GLOBALS['commondb'] : null;
        if ($db === null) {
            $cfg = __DIR__ . '/../config/commondb-config.php';
            if (!is_readable($cfg)) { return ($cache = $blank); }
            require $cfg;                       // defines $commondb
            if (!isset($commondb) || !($commondb instanceof mysqli)) { return ($cache = $blank); }
            $db = $GLOBALS['commondb'] = $commondb;
        }

        $q = $db->prepare(
            "SELECT a.access_level, a.user_category, p.center_id
               FROM user_system_access_table a
               LEFT JOIN user_profile_table p ON p.username = a.username
              WHERE a.username = ? AND a.system_id = 6");
        if (!$q) { return ($cache = $blank); }
        $q->bind_param("s", $username);
        $q->execute();
        $q->bind_result($acl, $usercat, $centre);
        $found = $q->fetch();
        $q->close();

        if (!$found) { return ($cache = $blank); }

        return ($cache = array(
            'username'   => $username,
            'acl'        => (string)$acl,
            'usercat'    => (string)$usercat,
            'centre'     => (string)$centre,
            'has_access' => true,
        ));
    }
}

if (!function_exists('bdsis_scope')) {
    /**
     * Resolve a role to its data scope. This is the single source of truth;
     * pages must not re-derive it with their own comparisons.
     *
     * With no arguments the role comes from bdsis_identity() -- the database --
     * not from the session, which another system may have written.
     *
     * @param  mixed $acl     access_level  (defaults to the caller's BDSIS role)
     * @param  mixed $usercat user_category (defaults to the caller's BDSIS role)
     * @return string BDSIS_SCOPE_*
     */
    function bdsis_scope($acl = null, $usercat = null)
    {
        if ($acl === null && $usercat === null) {
            $me = bdsis_identity();
            if (!$me['has_access']) { return BDSIS_SCOPE_DENIED; }
            $acl     = $me['acl'];
            $usercat = $me['usercat'];
        }
        $acl     = (string)($acl     !== null ? $acl     : '');
        $usercat = (string)($usercat !== null ? $usercat : '');

        /* --- Explicit national allow-list. Nothing else reaches other facilities. --- */
        // Administrator, any category.
        if ($acl === '1') { return BDSIS_SCOPE_NATIONAL; }
        /* RCDC Labs -- the national reference centre that operates BDSIS. ALL of its
           categories are national, not just EIDL(13).

           This is deliberate and was got wrong once: RCDC staff sit at centre 997,
           which holds 2 records, because cases are registered at the district
           hospitals that send RCDC their samples. Scoping an RCDC user to their own
           centre therefore empties the application -- an FNL user (2/14) saw only the
           single case they had just created and reported every earlier record as
           "missing". They were never missing; they were out of scope. */
        if ($acl === '2') { return BDSIS_SCOPE_NATIONAL; }
        // Ministry of Health -- programme oversight.
        if ($acl === '5') { return BDSIS_SCOPE_NATIONAL; }

        /* --- District Health Office: every participating centre in its dzongkhag. --- */
        if ($acl === '4') { return BDSIS_SCOPE_DISTRICT; }

        /* --- Facility-scoped: clinical staff working at one health centre. --- */
        // Health Workers: clinicians, laboratorians, nurses.
        if ($acl === '3') { return BDSIS_SCOPE_FACILITY; }

        /* BFDA (BAFRA food inspectors 6/15, BFDA admin 6/16) get read-only access
           to the NATIONAL surveillance data. BFDA is a food-safety agency with a
           programme interest in the diarrhoeal (foodborne) picture across all
           facilities, so their dashboards, listings and reports show national
           totals -- but the read-only tier (bdsis_permission_tier) means they can
           never create, edit or delete. Scoped to their own BAFRA office instead,
           they saw nothing, because those offices hold no patient records. */
        if ($acl === '6') { return BDSIS_SCOPE_NATIONAL; }

        /* --- Everything else is refused. ---
           Notably access_level 7 (BFDA/DRA drug regulators) and 88 (water
           suppliers): different organisations that happen to share commondb and
           are not part of the diarrhoeal study. */
        return BDSIS_SCOPE_DENIED;
    }
}

if (!defined('BDSIS_TIER_ADMIN')) {
    define('BDSIS_TIER_ADMIN',       'admin');       // Create, Read, Update, Delete (+ verify, export)
    define('BDSIS_TIER_CONTRIBUTOR', 'contributor'); // Create, Read, Update (no Delete)
    define('BDSIS_TIER_READONLY',    'readonly');    // Read (view + print/pdf) only
    define('BDSIS_TIER_DENIED',      'denied');      // no access
}

if (!function_exists('bdsis_roles')) {
    /**
     * Canonical 5 BDSIS-specific roles and permissions.
     * Stored and maintained strictly within BDSIS configs/tables.
     */
    function bdsis_roles()
    {
        return array(
            'administrator' => array(
                'key'         => 'administrator',
                'label'       => 'administrator',
                'acl'         => '1',
                'uc'          => '1',
                'badge'       => 'badge-primary',
                'tier'        => BDSIS_TIER_ADMIN,
                'scope'       => BDSIS_SCOPE_NATIONAL,
                'permissions' => 'Full',
                'actions'     => 'Create, Read, Update, Delete (Full module configuration & user management)',
            ),
            'health_worker' => array(
                'key'         => 'health_worker',
                'label'       => 'Health Worker',
                'acl'         => '3',
                'uc'          => '3',
                'badge'       => 'badge-success',
                'tier'        => BDSIS_TIER_CONTRIBUTOR,
                'scope'       => BDSIS_SCOPE_FACILITY,
                'permissions' => 'Add & Edit',
                'actions'     => 'Create, Read, Update (Form entry and edits; no delete/admin config)',
            ),
            'bfda_bafra' => array(
                'key'         => 'bfda_bafra',
                'label'       => 'BFDA(BAFRA)',
                'acl'         => '6',
                'uc'          => '15',
                'badge'       => 'badge-warning',
                'tier'        => BDSIS_TIER_READONLY,
                'scope'       => BDSIS_SCOPE_NATIONAL,
                'permissions' => 'View only',
                'actions'     => 'Read (View dashboards and records; all inputs/buttons disabled)',
            ),
            'rcdc_labs' => array(
                'key'         => 'rcdc_labs',
                'label'       => 'RCDC Labs',
                'acl'         => '2',
                'uc'          => '13',
                'badge'       => 'badge-info',
                'tier'        => BDSIS_TIER_CONTRIBUTOR,
                'scope'       => BDSIS_SCOPE_NATIONAL,
                'permissions' => 'Add & Edit',
                'actions'     => 'Create, Read, Update (Lab data entry, updates; no record deletion)',
            ),
            'district_health' => array(
                'key'         => 'district_health',
                'label'       => 'District Health',
                'acl'         => '4',
                'uc'          => '96',
                'badge'       => 'badge-secondary',
                'tier'        => BDSIS_TIER_READONLY,
                'scope'       => BDSIS_SCOPE_DISTRICT,
                'permissions' => 'View only',
                'actions'     => 'Read (View district-level records and reports; read-only)',
            ),
        );
    }
}

if (!function_exists('bdsis_resolve_role')) {
    /**
     * Resolves user/form input to a BDSIS role definition.
     * Supports role slugs, exact labels, and case-insensitive aliases.
     *
     * @param string $input
     * @return array|null
     */
    function bdsis_resolve_role($input)
    {
        $roles = bdsis_roles();
        $raw = strtolower(trim((string)$input));
        $aliases = array(
            'admin'           => 'administrator',
            'administrator'   => 'administrator',
            'health worker'   => 'health_worker',
            'health_worker'   => 'health_worker',
            'contributor'     => 'health_worker',
            'bfda(bafra)'     => 'bfda_bafra',
            'bfda'            => 'bfda_bafra',
            'bfda_bafra'      => 'bfda_bafra',
            'rcdc labs'       => 'rcdc_labs',
            'rcdc'            => 'rcdc_labs',
            'rcdc_labs'       => 'rcdc_labs',
            'district health' => 'district_health',
            'district_health' => 'district_health',
            'dho'             => 'district_health',
            'readonly'        => 'district_health',
        );
        $key = isset($aliases[$raw]) ? $aliases[$raw] : (isset($roles[$raw]) ? $raw : null);
        return ($key && isset($roles[$key])) ? $roles[$key] : null;
    }
}

if (!function_exists('bdsis_role_from_pair')) {
    /**
     * Maps (access_level, user_category) from commondb user_system_access_table to a BDSIS role key.
     *
     * @param string|int $acl
     * @param string|int|null $uc
     * @return string
     */
    function bdsis_role_from_pair($acl, $uc = null)
    {
        $acl = (string)$acl;
        switch ($acl) {
            case '1': return 'administrator';
            case '2': return 'rcdc_labs';
            case '3': return 'health_worker';
            case '4': return 'district_health';
            case '6': return 'bfda_bafra';
            default:  return 'district_health';
        }
    }
}

if (!function_exists('bdsis_permission_tier')) {
    /**
     * What class of actions a role may perform -- separate from data scope.
     *
     * The official RBAC matrix decouples "what may I do" from "whose records do I
     * see": the Ministry of Health sees every facility (national scope) yet may
     * only read; a Health Worker sees one facility yet may create and edit. So the
     * old "national scope => may do everything" shortcut is gone; scope answers
     * WHOSE, this answers WHAT.
     *
     *   admin        administrator (acl 1)                    -> C R U D
     *   contributor  RCDC Labs (acl 2) and                    -> C R U  (no delete)
     *                Health Worker (acl 3)
     *   readonly     District Health (acl 4), MoH (acl 5),    -> R      (view + print)
     *                BFDA(BAFRA) (acl 6)
     *   denied       everything else (DRA acl 7, water 88, …) -> none
     *
     * @return string BDSIS_TIER_*
     */
    function bdsis_permission_tier($acl = null, $usercat = null)
    {
        if ($acl === null && $usercat === null) {
            $me = bdsis_identity();
            if (!$me['has_access']) { return BDSIS_TIER_DENIED; }
            $acl     = $me['acl'];
            $usercat = $me['usercat'];
        }
        $acl     = (string)($acl     !== null ? $acl     : '');
        $usercat = (string)($usercat !== null ? $usercat : '');

        if ($acl === '1') { return BDSIS_TIER_ADMIN; }

        // RCDC Labs (all categories) and Health Workers create & edit but never delete.
        if ($acl === '2') { return BDSIS_TIER_CONTRIBUTOR; }
        if ($acl === '3') { return BDSIS_TIER_CONTRIBUTOR; }

        // District Health, Ministry of Health and BFDA(BAFRA) are read-only.
        if ($acl === '4') { return BDSIS_TIER_READONLY; }
        if ($acl === '5') { return BDSIS_TIER_READONLY; }
        if ($acl === '6') { return BDSIS_TIER_READONLY; }

        return BDSIS_TIER_DENIED;
    }
}

if (!function_exists('bdsis_tier_actions')) {
    /**
     * The canonical actions a tier may perform. Print/pdf are part of Read.
     * Verify and Export are administrative and belong to the admin tier only --
     * the official matrix lists neither for any other role.
     *
     * @return array list of canonical action names
     */
    function bdsis_tier_actions($tier)
    {
        switch ($tier) {
            case BDSIS_TIER_ADMIN:
                return array('view', 'add', 'edit', 'delete', 'print', 'verify', 'export');
            case BDSIS_TIER_CONTRIBUTOR:
                return array('view', 'add', 'edit', 'print');
            case BDSIS_TIER_READONLY:
                return array('view', 'print');
            default:
                return array();
        }
    }
}

if (!function_exists('bdsis_role_label')) {
    /**
     * Human-readable BDSIS role for display (the navbar chip, etc.).
     *
     * Derived from the DB-resolved role (bdsis_identity), NOT from the session's
     * acl -- so the label a user sees always matches the permissions they
     * actually have, even when the shared portal session carries another
     * system's access level.
     *
     * @return string
     */
    function bdsis_role_label($acl = null)
    {
        if ($acl === null) {
            $me = bdsis_identity();
            if (!$me['has_access']) { return 'No BDSIS access'; }
            $acl = $me['acl'];
        }
        switch ((string)$acl) {
            case '1': return 'administrator';
            case '2': return 'RCDC Labs';
            case '3': return 'Health Worker';
            case '4': return 'District Health';
            case '5': return 'Ministry of Health';
            case '6': return 'BFDA(BAFRA)';
            default:  return 'BDSIS User';
        }
    }
}

if (!function_exists('bdsis_is_national')) {
    /** @return bool true when the caller may see every facility. */
    function bdsis_is_national($acl = null, $usercat = null)
    {
        return bdsis_scope($acl, $usercat) === BDSIS_SCOPE_NATIONAL;
    }
}

if (!function_exists('bdsis_user_centre')) {
    /**
     * The caller's own health facility, from the session only.
     *
     * Never read the centre from a request parameter: it is the value every
     * scoping decision hangs on. Read from user_profile_table rather than the
     * session for the same reason the role is -- one session is shared across
     * ten systems, and dashboard.php used to let a URL parameter rewrite it.
     *
     * @return string center_id ('' when the user has none)
     */
    function bdsis_user_centre()
    {
        $me = bdsis_identity();
        if ($me['centre'] !== '') { return $me['centre']; }
        return isset($_SESSION['ctid']) ? (string)$_SESSION['ctid'] : '';
    }
}

if (!function_exists('bdsis_deny')) {
    /**
     * Refuse the request and stop.
     *
     * A real 403 and an immediate exit -- not a <script> redirect, which lets
     * the rest of the page render and ship its data to anyone using curl or
     * with JavaScript disabled.
     *
     * @param string $msg shown to the user
     */
    function bdsis_deny($msg = 'You do not have permission to view this record.', $action = '', $module = null)
    {
        /* One refused request, one audit line -- whatever the reason it was refused.
           Recording it here rather than at each check is what makes the log complete:
           the capability check was being logged but the two that actually matter,
           a role with no BDSIS access and a subject id from another facility, were
           not, so URL tampering left no trace at all. */
        bdsis_audit_denial($action !== '' ? $action : 'access', $module, $msg);

        /* Several pages open their layout (header.php, css-jquery.php) before they
           are in a position to authorise the record, so by the time we refuse, half
           a page is already buffered. Throw that away: it lets the real 403 status
           reach the client instead of a 200 carrying a denial message, and it stops
           the refusal being drawn inside a half-built page. Pages that render before
           authorising open a buffer of their own -- see bdsis_page_buffer(). */
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { http_response_code(403); }
        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => false, 'msg' => $msg));
            exit;
        }
        echo '<!doctype html><meta charset="utf-8"><title>Access denied</title>'
           . '<div style="font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;'
           . 'max-width:34rem;margin:12vh auto;padding:0 1.5rem;color:#334155">'
           . '<h1 style="font-size:1.3rem;color:#0f172a;margin:0 0 .5rem">Access denied</h1>'
           . '<p style="margin:0 0 1.25rem">' . htmlspecialchars($msg, ENT_QUOTES) . '</p>'
           . '<a href="/BDSIS/records/record-management.php" style="color:#1f4e79">Back to Records</a>'
           . '</div>';
        exit;
    }
}

if (!function_exists('bdsis_require_module')) {
    /**
     * Gate a whole page. Call immediately after the session include, before any
     * query runs, so a refused role never reaches the data.
     */
    function bdsis_require_module()
    {
        if (bdsis_scope() !== BDSIS_SCOPE_DENIED) { return; }

        /* Say which of the two it is. They need different remedies, and one
           message for both sent people to the ICT unit for an account that
           simply had never been given BDSIS. */
        $me = bdsis_identity();
        if (!$me['has_access']) {
            bdsis_deny('Your account does not have access to BDSIS (the Diarrhoeal Disease '
                     . 'Surveillance Information System). You may be signed in to another '
                     . 'system that shares this login. Ask the RCDC ICT unit to grant BDSIS '
                     . 'access if you need it.', 'module-access');
        }
        bdsis_deny('Your BDSIS role is not permitted to use the record modules. '
                 . 'Contact the RCDC ICT unit if you believe this is wrong.', 'module-access');
    }
}

if (!function_exists('bdsis_scope_clause')) {
    /**
     * SQL fragment restricting a query to the caller's permitted facilities.
     *
     * Returns '' for national roles and " AND <col>='<centre>'" otherwise.
     * A facility role with no centre in its session gets an impossible
     * predicate rather than an open query -- failing closed, not open.
     *
     * @param  mysqli $db  connection used only to escape the centre id
     * @param  string $col qualified column, e.g. "s.center_id"
     * @return string
     */
    function bdsis_scope_clause($db, $col = 'center_id')
    {
        $scope = bdsis_scope();
        if ($scope === BDSIS_SCOPE_NATIONAL) { return ''; }

        $ctid = bdsis_user_centre();
        if ($ctid === '') { return ' AND 1=0'; }
        $ctidEsc = $db->real_escape_string($ctid);

        if ($scope === BDSIS_SCOPE_DISTRICT) {
            /* Every participating centre sharing this user's dzongkhag. Written as a
               correlated subquery against commondb rather than a PHP-built IN list so
               it cannot be desynchronised from the facility master. */
            $cdb = bdsis_commondb_name();
            return " AND " . $col . " IN (SELECT h2.center_id FROM " . $cdb . ".ms_health_center_table h2"
                 . " WHERE h2.dzongkhag_code = (SELECT h3.dzongkhag_code FROM " . $cdb . ".ms_health_center_table h3"
                 . " WHERE h3.center_id='" . $ctidEsc . "'))";
        }

        return " AND " . $col . "='" . $ctidEsc . "'";
    }
}

if (!function_exists('bdsis_subject_centre')) {
    /**
     * The centre a subject belongs to, or null when the subject does not exist.
     *
     * @param  mysqli $bdsis
     * @param  string $sid
     * @return string|null
     */
    function bdsis_subject_centre($bdsis, $sid)
    {
        if (!($bdsis instanceof mysqli) || $sid === null || $sid === '') { return null; }

        $q = $bdsis->prepare("SELECT center_id FROM subject_information_table WHERE subject_id=?");
        if (!$q) { return null; }
        $q->bind_param("s", $sid);
        $q->execute();
        $q->bind_result($centre);
        $found = $q->fetch();
        $q->close();

        return $found ? (string)$centre : null;
    }
}

if (!function_exists('bdsis_can_access_subject')) {
    /**
     * May the caller open this subject?
     *
     * Checked against the SUBJECT's centre, not the session's, which is what
     * stops a facility user reading another hospital's patient by editing the
     * subject id in the URL.
     *
     * @return bool
     */
    function bdsis_can_access_subject($bdsis, $sid)
    {
        $scope = bdsis_scope();
        if ($scope === BDSIS_SCOPE_DENIED)   { return false; }
        if ($scope === BDSIS_SCOPE_NATIONAL) { return true; }

        $centre = bdsis_subject_centre($bdsis, $sid);
        if ($centre === null) { return true; }   // no such subject: let the page show its own "not found"

        $mine = bdsis_user_centre();
        if ($mine === '') { return false; }
        if ($centre === $mine) { return true; }

        if ($scope === BDSIS_SCOPE_DISTRICT) {
            /* Same dzongkhag counts for a District Health Office user. */
            $q = $bdsis->prepare(
                "SELECT 1 FROM " . bdsis_commondb_name() . ".ms_health_center_table a, "
                . bdsis_commondb_name() . ".ms_health_center_table b
                  WHERE a.center_id=? AND b.center_id=? AND a.dzongkhag_code=b.dzongkhag_code"
            );
            if (!$q) { return false; }
            $q->bind_param("ss", $centre, $mine);
            $q->execute();
            $q->store_result();
            $same = $q->num_rows > 0;
            $q->close();
            return $same;
        }

        return false;
    }
}

if (!function_exists('bdsis_require_subject')) {
    /** Gate a per-subject page or write. Refuses and stops when out of scope. */
    function bdsis_require_subject($bdsis, $sid)
    {
        bdsis_require_module();
        if (!bdsis_can_access_subject($bdsis, $sid)) {
            bdsis_deny('This record belongs to another health facility.',
                       'open-subject:' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$sid));
        }
    }
}

if (!function_exists('bdsis_next_subject_id')) {
    /**
     * Next free subject id for a centre, e.g. PHU-26-001.
     *
     * Format is <center_code>-<yy>-<sequence>. The sequence is the highest one
     * already used by that centre in that year, plus one, zero-padded to three
     * digits -- historical ids run to four digits, so the pad is a minimum and
     * not a cap.
     *
     * This is advisory only. subject_id is the PRIMARY KEY, so two users saving
     * at the same instant cannot both get the same id: the loser's INSERT fails
     * on the duplicate key and iu-case.php retries with the next number. That
     * is the real concurrency guarantee; this function just picks a good
     * starting point.
     *
     * @param  mysqli $bdsis
     * @param  mysqli $commondb
     * @param  string $ctid   center_id
     * @return string|null    null when the centre has no code
     */
    function bdsis_next_subject_id($bdsis, $commondb, $ctid)
    {
        if ($ctid === '' || !($bdsis instanceof mysqli) || !($commondb instanceof mysqli)) { return null; }

        $cq = $commondb->prepare("SELECT center_code FROM ms_health_center_table WHERE center_id=?");
        if (!$cq) { return null; }
        $cq->bind_param("s", $ctid);
        $cq->execute();
        $cq->bind_result($code);
        $ok = $cq->fetch();
        $cq->close();
        if (!$ok || $code === null || $code === '') { return null; }

        $prefix = $code . '-' . date('y') . '-';
        $like   = $prefix . '%';

        $sq = $bdsis->prepare(
            "SELECT MAX(CAST(SUBSTRING(subject_id, ?) AS UNSIGNED))
               FROM subject_information_table WHERE subject_id LIKE ?"
        );
        if (!$sq) { return null; }
        $start = strlen($prefix) + 1;          // 1-based offset of the sequence
        $sq->bind_param("is", $start, $like);
        $sq->execute();
        $sq->bind_result($maxSeq);
        $sq->fetch();
        $sq->close();

        $next = ((int)$maxSeq) + 1;

        return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('bdsis_case_centres')) {
    /**
     * The health facilities this user may register a NEW case at.
     *
     * One list, used both to render the Health Facility control and to validate
     * what comes back. It replaces three hardcoded role tests in records/case.php
     * that between them covered 3 of the 13 (access_level, user_category) pairs
     * in use: everyone else matched no branch, so the dropdown rendered with only
     * its placeholder, no Subject ID could be derived, and 13 users -- RCDC FNL,
     * facility clinicians and nurses, the district officer, MoH -- simply could
     * not create a case.
     *
     * Every ACTIVE facility that can receive a case is offered -- status=1 and a
     * non-empty center_code, because the Subject ID prefix is the centre's code
     * and a centre without one would be allocated an id like "-26-001". The
     * list used to stop at the participating sentinel sites (dsis=1, 13 of the
     * 382 centres in the master), which is why the Health Facility control showed
     * only a subset of the facilities the system knows about. The dsis flag now
     * decides ONLY whether a user's own mapping locks the control (below), so the
     * lock behaves exactly as before while the chooser lists every facility.
     *
     *   NATIONAL  every active, coded facility. RCDC, MoH and administrators
     *             register cases on behalf of any site, so they keep the chooser.
     *   DISTRICT  the user's OWN mapped facility when that facility takes part in
     *             the study; otherwise the active, coded facilities in their
     *             dzongkhag. The fallback exists because a district health office
     *             is an administrative centre -- Gasa DHO (980) has no
     *             center_code, so booking a case to it would yield "-26-001".
     *   FACILITY  the user's own centre
     *
     * A hospital user therefore never picks their own hospital from a list: it is
     * whatever user_profile_table.center_id maps them to, and the caller locks the
     * control when this returns exactly one centre.
     *
     * @return array center_id => label, ordered by name
     */
    function bdsis_case_centres($commondb)
    {
        if (!($commondb instanceof mysqli)) { return array(); }
        $scope = bdsis_scope();
        if ($scope === BDSIS_SCOPE_DENIED) { return array(); }

        $select = "SELECT h.center_id, CONCAT(h.center_name,' (',d.dzongkhag_name,')')
                     FROM ms_health_center_table h
                     JOIN ms_dzongkhag_table d ON d.dzongkhag_code = h.dzongkhag_code
                    WHERE h.status=1 AND h.center_code<>''";
        /* Participating sentinel site. Applied only to the own-mapping lock so that
           who gets the chooser and who gets a locked control is unchanged. */
        $study  = " AND h.dsis=1";
        $ctid = $commondb->real_escape_string(bdsis_user_centre());

        $run = function($sql) use ($commondb) {
            $out = array();
            $res = $commondb->query($sql);
            if ($res) { while (list($cid, $label) = $res->fetch_row()) { $out[(string)$cid] = $label; } }
            return $out;
        };

        if ($scope === BDSIS_SCOPE_FACILITY) {
            return $run($select . " AND h.center_id='" . $ctid . "'");
        }

        /* The user's own mapping decides, whatever their role -- if it points at a
           participating hospital, that hospital is the answer and nothing else is
           offered. Someone posted to a district hospital books cases there; being
           shown the other twelve is the defect this closes, and it applied to any
           role above facility scope, not only the district one.

           Administrators are the exception: they administer the whole programme
           and are not attached to the site they happen to be recorded against.

           Users mapped to RCDC (997) or the Ministry (998) fall through, as they
           should -- those are headquarters, not study sites (dsis=0), so there is
           no hospital in their mapping to resolve and they keep the chooser. */
        /* The BDSIS role, not the session's -- everything else in this function
           already reads the database (bdsis_scope above, bdsis_user_centre for
           $ctid) and this one comparison did not. A user who is not a BDSIS
           administrator but arrived from a system where they are was skipping the
           own-mapping lock and getting the full chooser; a real administrator
           arriving from a system where they are not was pinned to their own
           centre and shown fewer facilities than they administer. */
        if ((string)bdsis_identity()['acl'] !== '1') {
            $own = $run($select . $study . " AND h.center_id='" . $ctid . "'");
            if (count($own) > 0) { return $own; }
        }

        if ($scope === BDSIS_SCOPE_DISTRICT) {
            /* Mapped to a district health office rather than a hospital: fall back
               to the participating hospitals in the same dzongkhag. */
            return $run($select . " AND h.dzongkhag_code = (SELECT h2.dzongkhag_code
                          FROM ms_health_center_table h2 WHERE h2.center_id='" . $ctid . "')
                       ORDER BY h.center_name ASC");
        }

        return $run($select . " ORDER BY h.center_name ASC");
    }
}

/* ===========================================================================
   Capability matrix
   ---------------------------------------------------------------------------
   Scope answers "whose records?". Capability answers "what may they do to
   them?". Both live here so the UI and the endpoints read the same table --
   hiding a button is presentation, not enforcement, and every capability below
   is re-checked server-side before the operation runs.
   =========================================================================== */

if (!function_exists('bdsis_permission_matrix')) {
    /**
     * The Normal User permission matrix, verbatim.
     *
     *   Module        View Add Edit Delete Print Verify Approve
     *   Lab List       Y    -   -    -      -     -      -
     *   Bacteriology   Y    Y   Y    -      Y     -      -
     *   PCR            Y    Y   Y    -      Y     -      -
     *   AST            Y    Y   Y    -      Y     -      -
     *   ELISA          Y    Y   Y    -      Y     -      -
     *   Reports        Y    -   -    -      Y     -      -
     *   RCDC Receipt   Y*   -   -    -      Y     -      -   (* only if granted)
     *
     * Anything absent is denied: there is no fall-through. Note what this
     * excludes -- export is not a listed action on any module, so a scoped role
     * cannot pull a bulk extract even of its own facility; Reports is view and
     * print only.
     *
     * 'records' is NOT part of the supplied matrix. It is carried here from the
     * Records module specification agreed separately (View, Add, Edit, Search);
     * dropping it would remove case registration from every facility user.
     *
     * The DISTRICT matrix is a different job, not a wider one. A district health
     * officer supervises the facilities in their dzongkhag: they review, correct
     * and verify what those facilities entered, so they gain 'verify' and lose
     * 'add' -- they do not originate laboratory records. Delete, approve and
     * export are absent from both.
     *
     *                  View Add Edit Delete Print Verify Approve
     *   Lab List       Y    -   -    -      -     -      -
     *   Bacteriology   Y    -   Y*   -      Y     Y      -
     *   PCR            Y    -   Y*   -      Y     Y      -
     *   AST            Y    -   Y*   -      Y     Y      -
     *   ELISA          Y    -   Y*   -      Y     Y      -
     *   Reports        Y    -   -    -      Y     -      -
     *   RCDC Receipt   Y    -   -    -      Y     -      -
     *
     * (*) Edit is further conditional on the record's lifecycle state, which this
     *     table cannot express: a Verified or Approved record is read-only until
     *     it is returned for correction. That gate is bdsis_lab_is_locked() in
     *     includes/lab-workflow.php, applied on top of this matrix.
     *
     * @param  string|null $scope BDSIS_SCOPE_*; defaults to the caller's own
     * @return array module => list of permitted actions
     */
    function bdsis_permission_matrix($scope = null)
    {
        if ($scope === null) { $scope = bdsis_scope(); }

        if ($scope === BDSIS_SCOPE_DISTRICT) {
            return array(
                'lab_list'     => array('view'),
                'bacteriology' => array('view', 'edit', 'print', 'verify'),
                'pcr'          => array('view', 'edit', 'print', 'verify'),
                'ast'          => array('view', 'edit', 'print', 'verify'),
                'elisa'        => array('view', 'edit', 'print', 'verify'),
                'reports'      => array('view', 'print'),
                'rcdc_receipt' => array('view', 'print'),
                /* Not in the supplied matrix, which covers the Lab module. Carried
                   from the Records specification so a district officer keeps the
                   case-registration access they have today. */
                'records'      => array('view', 'add', 'edit', 'search'),
            );
        }

        return array(
            'lab_list'     => array('view'),
            'bacteriology' => array('view', 'add', 'edit', 'print'),
            'pcr'          => array('view', 'add', 'edit', 'print'),
            'ast'          => array('view', 'add', 'edit', 'print'),
            'elisa'        => array('view', 'add', 'edit', 'print'),
            'reports'      => array('view', 'print'),
            'rcdc_receipt' => array('view', 'print'),
            'records'      => array('view', 'add', 'edit', 'search'),
        );
    }
}

if (!function_exists('bdsis_audit_denial')) {
    /**
     * Record a refused attempt.
     *
     * There is no audit table in BDSIS, so this appends to a log file rather
     * than inventing schema. One line per refusal: when, who, their role and
     * centre, what they asked for, and how they got there.
     *
     * Failures here are swallowed deliberately -- an unwritable log must not
     * turn a clean 403 into a 500.
     */
    function bdsis_audit_denial($action, $module, $detail = '')
    {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

        /* Stamped in Bhutan time explicitly, not with date(). Pages set their own
           default timezone at different points -- delete.php switches to Asia/Thimphu
           before its guard runs, most pages never switch at all -- so date() gave the
           same instant two different clock readings depending on which page refused.
           An audit log whose lines cannot be put in order is not evidence. */
        $when = new DateTime('now', new DateTimeZone('Asia/Thimphu'));

        /* The user's real BDSIS role, not the session's copy of it. The session
           may carry another system's access level, which is the thing this file
           now ignores -- logging it would record the claim rather than the fact.
           A dash for acl means the account has no BDSIS access at all. */
        $me = bdsis_identity();

        $line = sprintf(
            "%s\tuser=%s\tacl=%s/%s\tcentre=%s\tscope=%s\taction=%s\tmodule=%s\turi=%s\tip=%s\t%s\n",
            $when->format('Y-m-d H:i:s T'),
            $me['username'] !== '' ? $me['username'] : '-',
            $me['acl']      !== '' ? $me['acl']      : '-',
            $me['usercat']  !== '' ? $me['usercat']  : '-',
            $me['centre']   !== '' ? $me['centre']   : '-',
            bdsis_scope(),
            $action !== '' ? $action : '-',
            $module !== null && $module !== '' ? $module : '-',
            isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : (isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '-'),
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-',
            str_replace(array("\r", "\n", "\t"), ' ', (string)$detail)
        );
        /* The log guards itself rather than relying on logs/.htaccess. This server
           runs nginx, which never reads .htaccess, so the old access-denied.log was
           downloadable by anyone -- and it names users, their roles and their centres.
           Giving the file a .php extension hands an HTTP request to PHP, and the
           leading exit means the response is empty; appending from here is unaffected.
           Restoring the Apache rule later does no harm, so this stays either way. */
        $logFile = $dir . '/access-denied.log.php';
        if (!file_exists($logFile)) { @file_put_contents($logFile, "<?php exit; ?>\n", LOCK_EX); }
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('bdsis_can')) {
    /**
     * May the current role perform this action, on this module?
     *
     * National roles keep everything they had -- this matrix constrains the
     * scoped (facility/district) roles only, and is deliberately a closed list:
     * an action or module not named is denied rather than inferred.
     *
     * $module is optional so existing single-argument calls keep working; when
     * it is omitted the action must be permitted on at least one module, which
     * is the right answer for page-level gates that are not module-specific.
     *
     * @param  string      $action view|add|edit|delete|print|export|verify|approve|...
     * @param  string|null $module key from bdsis_permission_matrix()
     * @return bool
     */
    function bdsis_can($action, $module = null)
    {
        /* The action ceiling is the role's tier; whose records it applies to is
           the role's scope, enforced separately by bdsis_scope_clause() and the
           per-subject checks. A denied tier can do nothing. This replaces the old
           "national scope => allow everything" shortcut, which is why the Ministry
           of Health (national but read-only) and RCDC (no delete) are now correctly
           constrained even though they still see national data. */
        $tier = bdsis_permission_tier();
        if ($tier === BDSIS_TIER_DENIED) { return false; }

        /* Synonyms used across the codebase, mapped onto the matrix vocabulary. */
        $alias = array(
            'search' => 'view',  'filter' => 'view',  'list'   => 'view',
            'save'   => 'add',   'create' => 'add',
            'update' => 'edit',  'modify' => 'edit',
            'pdf'    => 'print', 'report' => 'view',
        );
        $action = (string)$action;
        $canonical = isset($alias[$action]) ? $alias[$action] : $action;

        return in_array($canonical, bdsis_tier_actions($tier), true);
    }
}
if (!function_exists('bdsis_require_can')) {
    /** Enforce a capability, refusing and stopping when it is not held. */
    function bdsis_require_can($action, $msg = null, $module = null)
    {
        bdsis_require_module();
        if (!bdsis_can($action, $module)) {
            bdsis_deny($msg !== null ? $msg
                : 'Your role is not permitted to ' . htmlspecialchars($action, ENT_QUOTES)
                . ' records. Please contact your facility administrator or RCDC.',
                $action, $module);
        }
    }
}

if (!function_exists('bdsis_export_centre')) {
    /**
     * The centre an export must be pinned to, or null when unrestricted.
     *
     * Used by the Excel extract so a facility role cannot widen the export by
     * posting a different facility, or 'All'.
     *
     * @return string|null
     */
    function bdsis_export_centre()
    {
        return bdsis_scope() === BDSIS_SCOPE_NATIONAL ? null : bdsis_user_centre();
    }
}
