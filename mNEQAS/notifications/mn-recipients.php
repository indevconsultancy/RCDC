<?php
/**
 * mNEQAS - notification recipient resolution.
 *
 * Recipients are always read from the LIVE user directory in rcdc_commondb,
 * using exactly the tables the login/RBAC layer already uses:
 *
 *   user_system_access_table  username, system_id, access_level  (system_id = 12 = mNEQAS)
 *   user_profile_table        username, fullname, center_id, email_id
 *   login_table               username, account_status           (1 = active)
 *
 * Nothing is hardcoded and no address is ever invented: a user with no valid
 * email is skipped and reported by the caller, never guessed at.
 */

require_once(__DIR__ . '/../config/auth.php');   // MN_AUTH_* identity constants

if (!function_exists('mn_notify_commondb')) {

    /** Shared handle on the common (user directory) database. */
    function mn_notify_commondb()
    {
        static $db = null;
        if ($db instanceof mysqli) { return $db; }
        if (isset($GLOBALS['commondb']) && $GLOBALS['commondb'] instanceof mysqli) {
            $db = $GLOBALS['commondb'];
            return $db;
        }
        // Same credentials/DB the application already uses; never duplicated here.
        require(__DIR__ . '/../config/commondb-config.php');   // defines $commondb
        $db = $commondb;
        return $db;
    }

    /**
     * The one query every recipient rule is built from: active mNEQAS users
     * with a usable email address. $roles / $facility narrow it further.
     *
     * @param array  $roles    access_level values, empty = any mNEQAS role
     * @param string $facility center_id, '' = any centre
     * @return array of array(username, fullname, email, role, center_id)
     */
    function mn_notify_lookup(array $roles, $facility = '')
    {
        $rows = array();
        try {
            $db = mn_notify_commondb();
            if (!($db instanceof mysqli)) { return $rows; }

            $sql = "SELECT p.username, p.fullname, p.email_id, a.access_level, p.center_id
                      FROM user_system_access_table a
                      INNER JOIN user_profile_table p ON p.username = a.username
                      INNER JOIN login_table l        ON l.username = a.username
                     WHERE a.system_id = 12
                       AND l.account_status = 1
                       AND p.email_id IS NOT NULL
                       AND p.email_id <> ''";
            $types  = '';
            $params = array();

            if (count($roles)) {
                $sql .= ' AND a.access_level IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
                foreach ($roles as $r) { $types .= 'i'; $params[] = (int)$r; }
            }
            if ((string)$facility !== '') {
                $sql .= ' AND p.center_id = ?';
                $types .= 's';
                $params[] = (string)$facility;
            }
            $sql .= ' GROUP BY p.username';

            $st = $db->prepare($sql);
            if (!$st) { return $rows; }
            if ($types !== '') {
                $bind = array($types);
                foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
                call_user_func_array(array($st, 'bind_param'), $bind);
            }
            $st->execute();
            $res = $st->get_result();
            while ($u = $res->fetch_assoc()) {
                $email = trim((string)$u['email_id']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }   // never mail an invalid address
                $rows[] = array(
                    'username'  => (string)$u['username'],
                    'fullname'  => (string)$u['fullname'],
                    'email'     => $email,
                    'role'      => (int)$u['access_level'],
                    'center_id' => (string)$u['center_id'],
                );
            }
            $st->close();
        } catch (\Throwable $e) {
            return array();
        }
        return $rows;
    }

    /**
     * Users responsible for RCDC-side stages: Administrator (1) and RCDC Labs (2).
     * Role-scoped, not facility-scoped - RCDC users sit at several centres.
     */
    function mn_notify_rcdc_users(array $roles = array(1, 2))
    {
        return mn_notify_lookup($roles, '');
    }

    /** Every active mNEQAS user of one health facility (the facility's team). */
    function mn_notify_facility_users($facilityId)
    {
        if ((string)$facilityId === '') { return array(); }
        return mn_notify_lookup(array(), (string)$facilityId);
    }

    /** Facility display name, falling back to the id when it cannot be read. */
    function mn_notify_facility_name($facilityId)
    {
        try {
            $db = mn_notify_commondb();
            if (!($db instanceof mysqli)) { return (string)$facilityId; }
            $st = $db->prepare("SELECT center_name FROM ms_health_center_table WHERE center_id = ?");
            $st->bind_param('s', $facilityId);
            $st->execute();
            $row = $st->get_result()->fetch_row();
            $st->close();
            return ($row && $row[0] !== '') ? (string)$row[0] : (string)$facilityId;
        } catch (\Throwable $e) {
            return (string)$facilityId;
        }
    }

    /** Full name of the user who performed the action (for "Submitted By"). */
    function mn_notify_user_name($username)
    {
        if ((string)$username === '') { return ''; }
        try {
            $db = mn_notify_commondb();
            if (!($db instanceof mysqli)) { return (string)$username; }
            $st = $db->prepare("SELECT fullname FROM user_profile_table WHERE username = ?");
            $st->bind_param('s', $username);
            $st->execute();
            $row = $st->get_result()->fetch_row();
            $st->close();
            return ($row && trim((string)$row[0]) !== '') ? trim((string)$row[0]) : (string)$username;
        } catch (\Throwable $e) {
            return (string)$username;
        }
    }
}
