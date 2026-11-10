<?php
/**
 * Backend deletion endpoint for Record Management.
 *
 * Exclusively accessible to users with the administrator role.
 * Uses database transactions to safely and cascade-delete all dependent rows
 * across lab and clinical tables before deleting the primary subject record.
 */

require_once("../config/session.php");
require_once("../config/db-config.php");
require_once("../config/commondb-config.php");
require_once("../includes/rbac.php");

header('Content-Type: application/json; charset=utf-8');

/* -------------------------------------------------------------------------
   1. Role / Auth Check: Administrator only
   ------------------------------------------------------------------------- */
$isAdmin = false;
if (isset($_SESSION['role']) && in_array(strtolower(trim((string)$_SESSION['role'])), array('administrator', 'admin'), true)) {
    $isAdmin = true;
} elseif (function_exists('bdsis_permission_tier') && bdsis_permission_tier() === BDSIS_TIER_ADMIN) {
    $isAdmin = true;
} elseif (isset($_SESSION['acl']) && (string)$_SESSION['acl'] === '1') {
    $isAdmin = true;
} elseif (function_exists('bdsis_can') && bdsis_can('delete', 'records')) {
    $isAdmin = true;
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Forbidden: Only administrators can delete records.'));
    exit;
}

/* -------------------------------------------------------------------------
   2. Input validation
   ------------------------------------------------------------------------- */
$id = '';
if (isset($_POST['id']) && trim((string)$_POST['id']) !== '') {
    $id = trim((string)$_POST['id']);
} elseif (isset($_POST['subject_id']) && trim((string)$_POST['subject_id']) !== '') {
    $id = trim((string)$_POST['subject_id']);
} elseif (isset($_GET['id']) && trim((string)$_GET['id']) !== '') {
    $id = trim((string)$_GET['id']);
}

if ($id === '') {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => 'Invalid or missing Subject ID.'));
    exit;
}

$idEsc = $bdsis->real_escape_string($id);

/* Check existence of the subject record */
$chk = $bdsis->query("SELECT subject_id FROM subject_information_table WHERE subject_id = '$idEsc'");
if (!$chk || $chk->num_rows === 0) {
    http_response_code(404);
    echo json_encode(array('success' => false, 'message' => 'Record not found.'));
    exit;
}

/* -------------------------------------------------------------------------
   3. Transaction & Cascade Hard Delete
   ------------------------------------------------------------------------- */
$bdsis->begin_transaction();

try {
    // 1. E.coli PCR AST child tables & PCR tables
    $pcrRes2 = $bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id = '$idEsc'");
    if ($pcrRes2) {
        while (list($prt2_id) = $pcrRes2->fetch_row()) {
            $pcrRes3 = $bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table3 WHERE prt2_record_id = '$prt2_id'");
            if ($pcrRes3) {
                while (list($prt3_id) = $pcrRes3->fetch_row()) {
                    $bdsis->query("DELETE FROM rcdc_ecoli_pcr_ast_table WHERE prt3_record_id = '$prt3_id'");
                    $bdsis->query("DELETE FROM rcdc_ecoli_pcr_ast_table1 WHERE record_id = '$prt3_id'");
                }
            }
            $bdsis->query("DELETE FROM rcdc_ecoli_pcr_result_table3 WHERE prt2_record_id = '$prt2_id'");
        }
    }
    $bdsis->query("DELETE FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id = '$idEsc'");
    $bdsis->query("DELETE FROM rcdc_ecoli_pcr_result_table1 WHERE subject_id = '$idEsc'");

    // 2. Bacteriology AST, MIC, Resistance & Bacteriology tables
    $bactRes2 = $bdsis->query("SELECT record_id FROM bacteriology_result_table2 WHERE subject_id = '$idEsc'");
    if ($bactRes2) {
        while (list($brt2_id) = $bactRes2->fetch_row()) {
            $bdsis->query("DELETE FROM bacteriology_ast_table WHERE brt2_id = '$brt2_id'");
            $bdsis->query("DELETE FROM bacteriology_mic_table WHERE brt2_id = '$brt2_id'");
            $bdsis->query("DELETE FROM bacteriology_resistance_table WHERE brt2_id = '$brt2_id'");
        }
    }
    $bdsis->query("DELETE FROM bacteriology_result_table2 WHERE subject_id = '$idEsc'");
    $bdsis->query("DELETE FROM bacteriology_result_table1 WHERE subject_id = '$idEsc'");
    $bdsis->query("DELETE FROM rcdc_bacteriology_result_table WHERE subject_id = '$idEsc'");

    // 3. ELISA genotype & ELISA result tables
    $bdsis->query("DELETE FROM rcdc_elisa_genotype_table WHERE subject_id = '$idEsc'");
    $bdsis->query("DELETE FROM rcdc_elisa_result_table2 WHERE subject_id = '$idEsc'");
    $bdsis->query("DELETE FROM rcdc_elisa_result_table1 WHERE subject_id = '$idEsc'");

    // 4. Microscopy, Lab Status, Sample Info, Case Clinical Info
    $bdsis->query("DELETE FROM microscopy_result_table WHERE subject_id = '$idEsc'");
    $bdsis->query("DELETE FROM lab_record_status_table WHERE subject_id = '$idEsc'");
    $bdsis->query("DELETE FROM sample_information_table WHERE subject_id = '$idEsc'");
    $bdsis->query("DELETE FROM case_clinical_information_table WHERE subject_id = '$idEsc'");

    // 5. Primary record from subject_information_table
    $delSub = $bdsis->query("DELETE FROM subject_information_table WHERE subject_id = '$idEsc'");
    if (!$delSub) {
        throw new Exception("Failed to delete primary record from subject_information_table: " . $bdsis->error);
    }

    $bdsis->commit();
    echo json_encode(array('success' => true, 'message' => 'Record deleted successfully'));
} catch (Exception $e) {
    $bdsis->rollback();
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => 'Error deleting record: ' . $e->getMessage()));
}
