<?php
/**
 * Shared bootstrap for every page under BDSIS/masters/.
 *
 * A single include so a change to the guard, the flash-message convention or
 * the layout scaffold applies uniformly. Each page in this folder starts with
 *
 *     require_once __DIR__ . '/_inc.php';
 *
 * and gets:
 *   - buffered output (so a refused role can discard the layout and return 403)
 *   - the session + both DB handles ($commondb, $bdsis)
 *   - RBAC (module gate + Administrator-only tier gate)
 *   - CSRF helpers loaded (call bdsis_csrf_require() on write endpoints)
 *   - a one-shot flash-message API (mdFlash / mdFlashRender)
 *   - two small helpers for referential-integrity checks used by the CRUD pages.
 *
 * No page under masters/ writes to any table outside its own master. Every
 * write is a prepared statement, admin-tier-guarded and CSRF-checked.
 */

if (!defined('BDSIS_MASTERS_INC')) {
    define('BDSIS_MASTERS_INC', 1);

    ob_start();

    require_once __DIR__ . '/../config/session.php';
    require_once __DIR__ . '/../config/commondb-config.php';
    require_once __DIR__ . '/../config/db-config.php';
    require_once __DIR__ . '/../includes/rbac.php';
    require_once __DIR__ . '/../includes/csrf.php';

    bdsis_require_module();

    /* ------------------------------------------------------------------
       Two-tier RBAC for Master Data.

       View: any user with any BDSIS access (tier != DENIED) can OPEN each
       master's read-only listing. Actions are gated per row.

       Actions:
         Add / Edit / Retire / Restore on LOCAL BDSIS masters (Pathogens,
             AST Antimicrobials, MIC)                     -> ADMIN or CONTRIBUTOR
         Delete on any master, and any WRITE on CROSS-APP
             masters (Sites, Processing Staff)             -> ADMIN only

       Cross-app masters (commondb tables) stay admin-only for writes
       because a Site rename ripples through 10 sibling apps and a
       user_profile_table change touches auth everywhere. Local BDSIS
       masters can be maintained by any Contributor.

       The mapping is enforced UI-side (buttons hidden or greyed via
       bdsis_masters_can_*) AND server-side (bdsis_masters_require_*
       called at the top of every iu-*.php handler).
       ------------------------------------------------------------------ */
    if (!defined('BDSIS_MASTERS_CROSS_APP')) {
        /* Modules whose master rows live in commondb (shared with sibling
           surveillance systems). Any write on these needs ADMIN. */
        define('BDSIS_MASTERS_CROSS_APP', 'sites,staff');
    }

    if (!function_exists('bdsis_masters_is_cross_app')) {
        function bdsis_masters_is_cross_app($module)
        {
            return in_array((string)$module, explode(',', BDSIS_MASTERS_CROSS_APP), true);
        }
    }

    if (!function_exists('bdsis_masters_can_view')) {
        /** Module configuration and Master Data listings are restricted exclusively to administrator. */
        function bdsis_masters_can_view()
        {
            return bdsis_permission_tier() === BDSIS_TIER_ADMIN;
        }
    }

    if (!function_exists('bdsis_masters_can_write')) {
        /**
         * Master data module configuration is restricted exclusively to administrator.
         *
         * @param  string $module 'pathogens'|'antimicrobials'|'mic'|'sites'|'staff'
         * @return bool
         */
        function bdsis_masters_can_write($module)
        {
            return bdsis_permission_tier() === BDSIS_TIER_ADMIN;
        }
    }

    if (!function_exists('bdsis_masters_can_delete')) {
        /** Delete (and hard-retire on cross-app) is ADMIN only, everywhere. */
        function bdsis_masters_can_delete($module)
        {
            return bdsis_permission_tier() === BDSIS_TIER_ADMIN;
        }
    }

    if (!function_exists('bdsis_masters_require_view')) {
        function bdsis_masters_require_view()
        {
            if (!bdsis_masters_can_view()) {
                bdsis_deny(
                    'Master Data listings are available to users with BDSIS access only.',
                    'view',
                    'masters'
                );
            }
        }
    }

    if (!function_exists('bdsis_masters_require_write')) {
        function bdsis_masters_require_write($module, $action = 'write')
        {
            if (!bdsis_masters_can_write($module)) {
                bdsis_deny(
                    'Your role is not permitted to perform this Master Data action.',
                    $action,
                    'masters:' . $module
                );
            }
        }
    }

    if (!function_exists('bdsis_masters_require_delete')) {
        function bdsis_masters_require_delete($module)
        {
            if (!bdsis_masters_can_delete($module)) {
                bdsis_deny(
                    'Deleting Master Data rows is restricted to BDSIS Administrators.',
                    'delete',
                    'masters:' . $module
                );
            }
        }
    }

    /* Backward-compat: bdsis_require_admin() still exists for callers that
       want strict admin (e.g. cross-app writes call it explicitly). It no
       longer runs on _inc.php include; each page decides its own gate. */
    if (!function_exists('bdsis_require_admin')) {
        function bdsis_require_admin($action = 'access')
        {
            if (bdsis_permission_tier() !== BDSIS_TIER_ADMIN) {
                bdsis_deny(
                    'This Master Data action is limited to BDSIS Administrators. '
                    . 'Contact the RCDC ICT unit if you need this access.',
                    $action,
                    'masters'
                );
            }
        }
    }

    /* View gate (per new RBAC). Individual handlers add their write / delete
       gates on top. */
    bdsis_masters_require_view();

    /* Flash messages survive one redirect after a POST so the success/error
       shows on the list page rather than on the handler. Kept in the session
       because it is per-user, one-shot, and small. */
    if (!function_exists('mdFlash')) {
        function mdFlash($type, $text)
        {
            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            $_SESSION['md_flash'] = array('type' => (string)$type, 'text' => (string)$text);
        }
    }
    if (!function_exists('mdFlashRender')) {
        function mdFlashRender()
        {
            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            if (empty($_SESSION['md_flash'])) { return ''; }
            $f = $_SESSION['md_flash']; unset($_SESSION['md_flash']);
            $type = in_array($f['type'], array('success','danger','warning','info'), true) ? $f['type'] : 'info';
            return '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES) . ' alert-dismissible fade show" role="alert">'
                 . htmlspecialchars($f['text'], ENT_QUOTES)
                 . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>'
                 . '</div>';
        }
    }

    /* Small helpers for referential-integrity checks used by the pathogen and
       antimicrobial pages. Both return an integer count. */
    if (!function_exists('mdCountRefs')) {
        function mdCountRefs($bdsis, $sql, $bindType, $bindValue)
        {
            if (!($bdsis instanceof mysqli)) { return 0; }
            $q = $bdsis->prepare($sql);
            if (!$q) { return 0; }
            $q->bind_param($bindType, $bindValue);
            $q->execute();
            $q->bind_result($n);
            $q->fetch();
            $q->close();
            return (int)$n;
        }
    }

    /* Consistent way to end a POST handler: flash + 303 See Other + exit. */
    if (!function_exists('mdRedirect')) {
        function mdRedirect($relPath)
        {
            /* Same base as the referring page. `relPath` is a sibling file name
               like "pathogens.php" -- no protocol, no host, no leading slash. */
            $safe = preg_replace('~[^A-Za-z0-9_.\-?&=]~', '', (string)$relPath);
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Location: ' . $safe, true, 303);
            exit;
        }
    }
}
