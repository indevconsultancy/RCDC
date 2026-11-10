<?php
/**
 * CSRF protection.
 *
 * BDSIS had none: every state-changing form posted with nothing but a session
 * cookie, so any page a logged-in user visited could silently post to
 * iu-lab.php or iu-case.php on their behalf.
 *
 * One token per session, checked on POST. The token is not rotated per form --
 * the application opens several forms on one page (lab.php renders four) and
 * rotating would invalidate whichever was submitted second.
 */

if (!function_exists('bdsis_csrf_token')) {
    /** The session's CSRF token, minted on first use. */
    function bdsis_csrf_token()
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        if (empty($_SESSION['bdsis_csrf'])) {
            /* random_bytes is cryptographically secure and is available on the
               PHP 8 this runs on; the fallback keeps the include safe if it is
               ever loaded on a build without it. */
            $_SESSION['bdsis_csrf'] = function_exists('random_bytes')
                ? bin2hex(random_bytes(32))
                : hash('sha256', uniqid(mt_rand(), true));
        }
        return $_SESSION['bdsis_csrf'];
    }
}

if (!function_exists('bdsis_csrf_field')) {
    /** Hidden input carrying the token. Drop inside every state-changing form. */
    function bdsis_csrf_field()
    {
        return '<input type="hidden" name="bdsis_csrf" value="'
             . htmlspecialchars(bdsis_csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('bdsis_csrf_valid')) {
    /** @return bool does this request carry the session's token? */
    function bdsis_csrf_valid()
    {
        $sent = isset($_POST['bdsis_csrf']) ? (string)$_POST['bdsis_csrf'] : '';
        if ($sent === '') { return false; }
        // hash_equals: constant time, so a wrong token cannot be found by timing.
        return hash_equals(bdsis_csrf_token(), $sent);
    }
}

if (!function_exists('bdsis_csrf_require')) {
    /**
     * Refuse a POST that does not carry the token.
     *
     * Only POST is checked. The GET endpoints in BDSIS that change state
     * (delete.php) are already refused for every role this matters to, and
     * adding a token to them would break existing bookmarked admin links
     * without closing anything that is still open.
     */
    function bdsis_csrf_require()
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return; }
        if (bdsis_csrf_valid()) { return; }

        if (function_exists('bdsis_deny')) {
            bdsis_deny('This form has expired or was submitted from another page. '
                     . 'Please reopen the record and try again.', 'csrf');
        }
        http_response_code(403);
        exit('CSRF check failed.');
    }
}
