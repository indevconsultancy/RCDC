<?php
/**
 * Sites / Health Centers module -- REMOVED.
 *
 * The BDSIS Master Data Sites screen was withdrawn at the client's request.
 * Site maintenance moves to the shared administration portal (COMMONNEW),
 * because commondb.ms_health_center_table is used by every RCDC surveillance
 * system on this server -- edits from BDSIS alone would drift the shared
 * master from what other sibling apps see.
 *
 * The full backing file remains available as sites.php.bak_20260928_172224
 * for one-line rollback if the module ever needs to come back. Its
 * companion iu-sites.php also refuses writes now (see the file for
 * details).
 *
 * Behaviour for anyone hitting this URL:
 *   1. authorise them into the Master Data area (unchanged _inc.php guards);
 *   2. drop a one-shot flash message explaining the withdrawal;
 *   3. 303 redirect to /BDSIS/masters/index.php so bookmarks and links land
 *      cleanly on the Master Data overview instead of a 404.
 *
 * IMPORTANT: nothing about the underlying data is changed by this stub.
 * ms_health_center_table rows, every subject_information_table.center_id
 * FK, every historical Subject-ID (<code>-<yy>-<seq>) and every report
 * stays intact. Case creation still resolves the caller's facility from
 * their profile (records/case.php has been updated to auto-assign for
 * scoped users; the national-scope chooser is unchanged).
 */
require_once __DIR__ . '/_inc.php';   /* module guard + view gate + mdFlash */

mdFlash(
    'info',
    'The Sites / Health Centers module has been withdrawn from BDSIS. '
    . 'Site records are maintained in the shared administration portal '
    . '(COMMONNEW). All existing site data, case links and reports are '
    . 'unaffected.'
);
mdRedirect('index.php');
