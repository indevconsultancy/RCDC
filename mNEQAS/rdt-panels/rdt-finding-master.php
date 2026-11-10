<?php
/**
 * RDT Panel Testing — findings & categories endpoint (RDT only).
 *
 * The RDT library lives in rdt_panel_finding_master and
 * rdt_panel_finding_categories. Fixing the module here, in code, is the point
 * of this file: the engine can no longer be pointed at Microscopy's library by
 * a request parameter, so the two modules are separated by the code path and
 * not only by a WHERE clause.
 *
 * All behaviour — list, content, save, delete, cat_list, cat_save, cat_delete,
 * authorisation, cat_key stability, in-use rules — is the shared engine's.
 * Nothing is duplicated, and because the module is fixed before the include,
 * nothing can mix the two libraries.
 */
define('PFM_FIXED_MODULE', 'rdt');
require __DIR__ . '/../panels/finding-master.php';
