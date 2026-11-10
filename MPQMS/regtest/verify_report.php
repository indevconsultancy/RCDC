<?php
/*
 * MPQMS — Immutable report retrieval + integrity-verification endpoint (READ-ONLY).
 *
 * GET only. Clients/admins retrieve indexed report metadata by Registration/Test ID,
 * Batch/Serial Number, or Client ID, and each result carries a live tamper check
 * (the stored PDF's SHA-256 is recomputed and compared to the indexed hash).
 *
 * By design this endpoint NEVER writes: PUT / PATCH / DELETE (and any non-GET method)
 * are rejected, and it performs no INSERT/UPDATE/DELETE on the archived files or index.
 */
include_once("../config/auth_ndtlis.php");            // authenticated users only (clients/admins)
	// Effective-role RBAC: archive metadata follows the official-report permission.
	include_once("../config/mpq_validate.php");
	mpq_require('official_report', 'view', true);
include_once("../config/db_config.php");
require_once(__DIR__."/report_archive_index.php");

header('Content-Type: application/json; charset=utf-8');

function vout($status, $extra = array()){ echo json_encode(array_merge(array('status'=>$status), $extra)); exit; }

// Read-only guarantee at the API layer: reject any write method outright.
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if(!in_array($method, array('GET','HEAD'), true)){
	http_response_code(405);
	header('Allow: GET, HEAD');
	vout('error', array('message'=>'Read-only endpoint. Completed report files cannot be modified or deleted.'));
}

mpqms_ensure_report_index_table($connection);

$rid    = isset($_GET['rid'])    ? (int)$_GET['rid']            : 0;
$batch  = isset($_GET['batch'])  ? trim($_GET['batch'])         : '';
$client = isset($_GET['client']) ? trim($_GET['client'])        : '';

$where = array();
if($rid > 0)        { $where[] = "registration_id = '".(int)$rid."'"; }
if($batch  !== '')  { $where[] = "batch_serial_number = '".mysqli_real_escape_string($connection, $batch)."'"; }
if($client !== '')  { $where[] = "client_id = '".mysqli_real_escape_string($connection, $client)."'"; }
if(empty($where)){ vout('error', array('message'=>'Provide at least one filter: rid, batch, or client.')); }

$res = mysqli_query($connection, "SELECT * FROM report_archive_index WHERE ".implode(' AND ', $where)." ORDER BY archived_at DESC");
$reports = array();
if($res){
	while($r = mysqli_fetch_assoc($res)){
		$reports[] = array(
			'registration_id'     => (int)$r['registration_id'],
			'sample_id'           => $r['sample_id'],
			'client_id'           => $r['client_id'],
			'batch_serial_number' => $r['batch_serial_number'],
			'final_result'        => $r['final_result'],
			'completed_at'        => $r['completed_at'],
			'pdf_storage_uri'     => $r['pdf_storage_uri'],
			'file_sha256_hash'    => $r['file_sha256_hash'],
			'file_version'        => (int)$r['file_version'],
			'is_locked'           => (int)$r['is_locked'],
			'archived_at'         => $r['archived_at'],
			'integrity'           => mpqms_verify_report_integrity($r),   // live tamper check
		);
	}
}

vout('success', array('count'=>count($reports), 'reports'=>$reports));
?>
