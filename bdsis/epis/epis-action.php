<?php
/**
 * ePIS actions: sync, accept, reject.
 *
 * POST only, CSRF-checked, capability-checked. There is deliberately no GET
 * path: a sync writes and a decision is irreversible, so neither may be
 * triggered by a link, a prefetch or an <img> on another page.
 *
 * Answers JSON to an AJAX caller and redirects back to the listing with a flash
 * message otherwise, so the page works with scripting unavailable.
 */

require_once("../config/session.php");
require_once("../config/db-config.php");
require_once("../config/commondb-config.php");
require_once("../includes/rbac.php");
require_once("../includes/csrf.php");
require_once("../includes/epis-lib.php");

bdsis_require_module();
bdsis_csrf_require();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('This endpoint accepts POST only.');
}

/* Everything here either pulls new records in or decides one. Only a role that
   can register a case has any use for that, so all three are gated on 'add'
   rather than on 'view' -- a read-only role sees the queue and acts on nothing. */
bdsis_require_can('add', 'Your role is not permitted to act on ePIS notifications.', 'records');

$me     = bdsis_identity();
$actor  = $me['username'] !== '' ? $me['username'] : 'unknown';
$what   = isset($_POST['do']) ? (string)$_POST['do'] : '';
$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$reason = isset($_POST['reason']) ? (string)$_POST['reason'] : '';

$result = array('ok' => false, 'message' => 'Unrecognised request.', 'redirect' => '');

switch ($what) {

    case 'sync':
        $r = bdsis_epis_sync($bdsis, $commondb, $actor);
        $result = array('ok' => $r['ok'], 'message' => $r['message'], 'redirect' => '',
                        'stats' => array('fetched' => $r['fetched'], 'matched' => $r['matched'],
                                         'inserted' => $r['inserted'], 'updated' => $r['updated']));
        break;

    case 'accept':
    case 'reject':
        $row = bdsis_epis_case($bdsis, $id);
        if ($row === null) {
            $result['message'] = 'That ePIS notification no longer exists.';
            break;
        }
        /* Scope is checked on the row itself, not only on the listing that
           offered it: the id travels in the POST body and can be changed. */
        if (!bdsis_epis_can_see($row, $bdsis, $commondb)) {
            bdsis_deny('That ePIS notification belongs to another dzongkhag.', $what, 'records');
        }
        if ($what === 'reject' && trim($reason) === '') {
            $result['message'] = 'Please say why the notification is being rejected.';
            break;
        }
        $status = ($what === 'accept') ? 'Accepted' : 'Rejected';
        $r = bdsis_epis_set_status($bdsis, $id, $status, $actor, $reason);
        $result = array('ok' => $r['ok'], 'message' => $r['message'], 'redirect' => '');

        /* Accepting is only half the job: the case still has to be registered.
           Send the user straight into the ordinary Records wizard with the ePIS
           values filled in, rather than writing a subject here -- that way the
           case passes exactly the validation, id allocation and scope rules
           every other case passes. */
        if ($r['ok'] && $what === 'accept') {
            $result['redirect'] = '../records/case.php?action=1&epis=' . (int)$id;
            $result['message']  = 'Accepted. Complete the case details to register it in BDSIS.';
        }
        break;
}

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

/* No scripting: carry the outcome in the session and bounce back. */
$_SESSION['bdsis_epis_flash'] = array('ok' => $result['ok'], 'message' => $result['message']);
header('Location: ' . ($result['redirect'] !== '' ? $result['redirect'] : 'epis-management.php'));
exit;
