<?php
// save_signature.php
header('Content-Type: application/json; charset=utf-8');
include_once("../config/auth_ndtlis.php");
include_once("../config/db_config.php");

$response = ['status' => 'error', 'message' => 'Invalid input'];

/*
 * DATA-SAFETY BACKSTOP. The digital signature is no longer saved on its own: the signature
 * modal stages it into the Test Result form and update_result.php persists it in the SAME
 * transaction as the result rows, so a signature can never exist without its result data.
 * This endpoint therefore refuses every standalone save (including direct/scripted POSTs).
 * It is kept only so any stale client still calling it receives a clear explanation.
 */
echo json_encode([
    'status'  => 'error',
    'message' => 'For data safety the digital signature is saved together with the Final Result. '
               . 'Please attach the signature in the Final Result card and click "Update Final Result"; standalone signature saves are not permitted. Nothing was saved.'
]);
exit;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $regid       = isset($_POST['regid']) ? (int)$_POST['regid'] : 0;
    $method      = $_POST['sig_method'] ?? '';
    $signer_name = trim($_POST['signer_name'] ?? '');
    $sig_date    = $_POST['sig_date'] ?? date('Y-m-d H:i:s');

    if ($regid <= 0) {
        throw new Exception('Missing or invalid Registration ID');
    }
    // Completed reports are read-only (server-side lock; see report_lock_lib.php).
    require_once(__DIR__.'/report_lock_lib.php');
    if (mpqms_report_locked($connection, $regid)) {
        throw new Exception(mpqms_locked_message());
    }
    if ($signer_name === '') {
        throw new Exception('Signer name is required');
    }
    if (!in_array($method, ['draw', 'upload'])) {
        throw new Exception('Invalid signature method');
    }

    $signature_data = null;

    if ($method === 'draw') {
        $raw = $_POST['signature_data'] ?? '';
        if (strpos($raw, 'data:image/png;base64,') !== 0) {
            throw new Exception('Invalid signature data (draw)');
        }
        // Optional: strip the header and store pure base64, or keep the data URI
        $signature_data = $raw;   // keep full data URI so <img src="..."> works
    } else {
        if (empty($_FILES['sig_image']['tmp_name'])) {
            throw new Exception('No image uploaded');
        }
        $file = $_FILES['sig_image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload error code: ' . $file['error']);
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            throw new Exception('Image larger than 2 MB');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif'])) {
            throw new Exception('Only PNG / JPG / GIF allowed');
        }
        $signature_data = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
    }

    if (empty($signature_data)) {
        throw new Exception('Empty signature data');
    }

    // Apply the SAME signature to EVERY result row of this registration
    // (one signature for the whole report)
    $stmt = $connection->prepare(
        "UPDATE labresulttable 
         SET digital_signature = ?, 
             signature_type = ?, 
             signature_date = ?, 
             signed_by = ? 
         WHERE registrationID = ?"
    );
    $stmt->bind_param('ssssi', $signature_data, $method, $sig_date, $signer_name, $regid);

    if (!$stmt->execute()) {
        throw new Exception('Database update failed: ' . $stmt->error);
    }

    // Echo back what was stored so the page can refresh the signature preview in place,
    // without reloading the Test Result grid (which would discard unsaved result entries).
    $response = [
        'status'         => 'success',
        'message'        => 'Signature saved successfully for the whole report',
        'signature'      => $signature_data,
        'signed_by'      => $signer_name,
        'signature_date' => $sig_date
    ];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;