<?php
/*
 * MPQMS Settings — unified master-record DELETE handler (Administrator only).
 *
 * Uniform delete for all six Settings sub-modules (Formulation / Parameter / Category /
 * EDL / Manufacturer / MAH). Strategy: a record is hard-deleted ONLY when it is not
 * referenced by any real data (FK-safe). If it is referenced, the request is refused
 * with a clear "linked to existing records" message — no schema change, no soft-delete
 * column. EDL additionally removes its own EDL<->Category mappings (owned child rows)
 * when it is otherwise unreferenced. All id-bound queries use prepared statements; the
 * table/column names come only from a server-side whitelist (never user input).
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

function dout($status, $msg){ if(ob_get_length()) ob_clean(); echo json_encode(array('status'=>$status, 'message'=>$msg)); exit; }

try {
    include_once("../config/auth_ndtlis.php");
    include_once("../config/db_config.php");
    include_once("../config/mpq_validate.php");
    if(ob_get_length()) ob_clean();

    // Administrator-only (RBAC: Delete = Admin only) + CSRF — verified server-side.
    if(!isset($_SESSION['acl']) || (int)$_SESSION['acl'] !== 1){ dout('error', 'Only Administrators are permitted to delete settings records.'); }
    if(function_exists('mpq_require_role')){ mpq_require_role(array(1), true); }
    if(function_exists('mpq_csrf_check')){ mpq_csrf_check(true); }

    $type = (string)($_POST['type'] ?? '');
    $id   = (int)($_POST['id'] ?? 0);
    if($id <= 0){ dout('error', 'Invalid record reference.'); }

    // Whitelist: table, friendly label, dependents (block if referenced) and owned children (cascade-delete).
    $map = array(
        'DF' => array('table'=>'msformulationtable',  'label'=>'Formulation',  'deps'=>array(array('registrationtable','formulationID')),                'children'=>array()),
        'DP' => array('table'=>'mstestparametertable','label'=>'Parameter',    'deps'=>array(array('labresulttable','parameterID')),                     'children'=>array()),
        'DC' => array('table'=>'msdrugcategorytable', 'label'=>'Category',      'deps'=>array(array('msedlcategorymaptable','categoryID')),                'children'=>array()),
        'DE' => array('table'=>'msedltable',          'label'=>'EDL',           'deps'=>array(array('registrationtable','edlID')),                         'children'=>array(array('msedlcategorymaptable','edlID'))),
        'DM' => array('table'=>'msmanufacturertable', 'label'=>'Manufacturer',  'deps'=>array(array('registrationtable','manufacturer')),                  'children'=>array()),
        'DH' => array('table'=>'msmahtable',          'label'=>'MAH',           'deps'=>array(array('registrationtable','mahID')),                         'children'=>array()),
    );
    if(!isset($map[$type])){ dout('error', 'Unknown settings module.'); }
    $cfg = $map[$type];
    $tbl = $cfg['table'];

    // Record must exist.
    $stmt = mysqli_prepare($connection, "SELECT COUNT(*) FROM $tbl WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $exists);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    if((int)$exists === 0){ dout('error', 'Record not found — it may already have been deleted.'); }

    // FK dependency check — refuse if the record is referenced by existing data.
    foreach($cfg['deps'] as $d){
        list($depTbl, $depCol) = $d;
        $s = mysqli_prepare($connection, "SELECT COUNT(*) FROM $depTbl WHERE $depCol = ?");
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        mysqli_stmt_bind_result($s, $refCount);
        mysqli_stmt_fetch($s);
        mysqli_stmt_close($s);
        if((int)$refCount > 0){ dout('blocked', 'Cannot delete this '.$cfg['label'].' as it is linked to existing records.'); }
    }

    // Orphan record — safe to hard-delete. Remove owned child rows first (e.g. EDL<->Category map).
    foreach($cfg['children'] as $c){
        list($chTbl, $chCol) = $c;
        $s = mysqli_prepare($connection, "DELETE FROM $chTbl WHERE $chCol = ?");
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
    }

    $s = mysqli_prepare($connection, "DELETE FROM $tbl WHERE id = ?");
    mysqli_stmt_bind_param($s, 'i', $id);
    $ok = mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
    if(!$ok){ dout('error', 'Failed to delete the record. Please try again.'); }

    dout('success', $cfg['label'].' successfully deleted.');

} catch (Throwable $e){
    dout('error', 'A server error occurred while deleting the record.');
}
