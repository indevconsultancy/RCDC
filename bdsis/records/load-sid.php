<?php
/**
 * Next Subject ID for a health facility (AJAX).
 *
 * Used by the New Case form when a national-role user picks a facility. It
 * previously returned only the prefix ("PHU-26-") for the user to complete by
 * hand; it now returns the full generated id, because manual entry has been
 * removed -- that was the source of duplicate-ID collisions.
 *
 * This value is a PREVIEW. iu-case.php regenerates the id inside the insert and
 * retries on a duplicate key, so the id actually stored is unique even if two
 * users create a case at the same instant.
 */
require_once("../config/session.php");
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");
require_once("../includes/rbac.php");

header("Content-Type: text/plain; charset=utf-8");

bdsis_require_module();

$ctid = isset($_GET["ctid"]) ? (string)$_GET["ctid"] : '';

/* Answer only for a centre this user may actually register a case at -- the same
   list the form is built from, so the endpoint cannot be coaxed into previewing
   an id for a facility the user is not mapped to.

   This used to read "if not national, use your own centre", which was wrong at
   both ends: a district user mapped to a health office had no usable centre of
   their own, and a non-administrator mapped to one hospital could still ask for
   another hospital's next id. */
$allowed = bdsis_case_centres($commondb);
if (count($allowed) === 1) {
    $ctid = (string)key($allowed);            // no choice to make: settle it here
} elseif (!isset($allowed[$ctid])) {
    $ctid = '';                                // not a centre this user may use
}

if ($ctid === '') { exit; }

$next = bdsis_next_subject_id($bdsis, $commondb, $ctid);
echo ($next === null) ? '' : $next;
