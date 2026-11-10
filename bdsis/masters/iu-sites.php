<?php
/**
 * iu-sites.php -- WITHDRAWN. Companion write endpoint for the retired
 * Master Data Sites module (see masters/sites.php in this folder).
 *
 * Every previous action (toggle_dsis / update / delete) is now refused so
 * bookmarked, cached or scripted POSTs cannot slip through and mutate the
 * shared ms_health_center_table from within BDSIS. Full behaviour lives in
 * iu-sites.php.bak_20260928_172224 for one-line rollback.
 */
require_once __DIR__ . '/_inc.php';
bdsis_csrf_require();

mdFlash(
    'warning',
    'Site updates from within BDSIS are no longer accepted. Please use the '
    . 'shared administration portal (COMMONNEW) for centre changes.'
);
mdRedirect('index.php');
