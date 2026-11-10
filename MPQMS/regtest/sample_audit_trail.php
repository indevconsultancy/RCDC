<?php
/*
 * MPQMS — Sample audit-trail API (READ-ONLY).
 *
 *   GET /MPQMS/regtest/sample_audit_trail.php?rid=<registrationID>
 *
 * Returns the derived 6-stage lifecycle for a sample AND the immutable audit
 * rows that back it, as a single JSON document. The endpoint never writes
 * anything; GET and HEAD are the only accepted methods.
 *
 * Role gate: `registration.view` — same roles that may open a sample's record
 * (ADMIN, MPD, NDTL, BFDA by default; configurable via the Roles editor).
 */
include_once("../config/auth_ndtlis.php");
include_once("../config/db_config.php");
include_once("../config/mpq_validate.php");
mpq_require('registration', 'view', true);   // AJAX: returns JSON on deny

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if(!in_array($method, array('GET','HEAD'), true)){
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(array('status'=>'error', 'message'=>'Read-only endpoint — GET or HEAD only.'));
    exit;
}

$rid = isset($_GET['rid']) ? (int)$_GET['rid'] : 0;
if($rid <= 0){
    http_response_code(400);
    echo json_encode(array('status'=>'error', 'message'=>'Missing or invalid "rid" parameter.'));
    exit;
}

require_once(dirname(__DIR__)."/includes/sample_lifecycle_lib.php");

$out = array('status'=>'success', 'registration_id'=>$rid);
try {
    $st = mysqli_prepare($connection, "SELECT sampleID, sample_type FROM registrationtable WHERE registrationID=?");
    mysqli_stmt_bind_param($st, 'i', $rid);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $sampleID, $sampleType);
    $has = mysqli_stmt_fetch($st);
    mysqli_stmt_close($st);
    if(!$has){
        http_response_code(404);
        echo json_encode(array('status'=>'error', 'message'=>'Sample not found.'));
        exit;
    }
    $out['sample_id']   = (string)$sampleID;
    $out['sample_type'] = (string)$sampleType;

    $out['lifecycle']     = mpqms_sample_lifecycle($connection, $rid);
    $out['current_stage'] = $out['lifecycle']['current_stage'];

    $out['audit'] = array();
    $st = mysqli_prepare($connection, "SELECT id, previous_status, new_status, action_by_user_id, `timestamp`, notes
                                       FROM mpqms_sample_audit WHERE sample_id=? ORDER BY `timestamp` ASC, id ASC");
    if($st){
        mysqli_stmt_bind_param($st, 'i', $rid);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        while($rs && ($row = mysqli_fetch_assoc($rs))){
            $out['audit'][] = array(
                'id'                => (int)$row['id'],
                'previous_status'   => $row['previous_status'],
                'new_status'        => $row['new_status'],
                'action_by_user_id' => $row['action_by_user_id'],
                'timestamp'         => $row['timestamp'],
                'notes'             => $row['notes'],
            );
        }
        mysqli_stmt_close($st);
    }
} catch(\Throwable $e){
    http_response_code(500);
    echo json_encode(array('status'=>'error', 'message'=>'Unable to load audit trail.'));
    exit;
}

if($method === 'HEAD'){ exit; }
echo json_encode($out);
?>
