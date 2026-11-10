<?php
    /**
     * M&S Registration – delete one saved action-evidence image (AJAX, JSON).
     *
     * Called by the "Delete" button on saved action evidence in ms-registration.php
     * (shown to Administrators only). POST: image_id, gid.
     * Response: {"success": true} or {"success": false, "message": "..."}.
     */
    require_once("../config/session.php");
    require_once("../config/auth.php");
    require_once("../config/db-config.php");

    function ms_img_json(array $out) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ms_img_json(array('success' => false, 'message' => 'Invalid request.')); }

    // Same rule as the upload and the button: Administrators only.
    mn_require_admin('Only an Administrator can delete action evidence.', 'json');

    $imageId = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;
    $gid     = isset($_POST['gid']) ? (int)$_POST['gid'] : 0;
    if ($imageId <= 0 || $gid <= 0) { ms_img_json(array('success' => false, 'message' => 'Invalid image.')); }

    mn_require_record($conn, 'onsite', $gid, 'json');

    // The image must belong to this record.
    $st = $conn->prepare("SELECT image_path FROM onsite_action_images WHERE image_id = ? AND general_info_id = ?");
    $st->bind_param('ii', $imageId, $gid);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    if (!$row) { ms_img_json(array('success' => false, 'message' => 'Image not found for this record.')); }

    try {
        $del = $conn->prepare("DELETE FROM onsite_action_images WHERE image_id = ? AND general_info_id = ?");
        $del->bind_param('ii', $imageId, $gid);
        $del->execute();
        $del->close();
    } catch (\Throwable $e) {
        error_log('[mNEQAS] delete-action-image: ' . $e->getMessage());
        ms_img_json(array('success' => false, 'message' => 'Delete failed.'));
    }

    // Remove the file only when it resolves inside uploads/ms_actions/.
    $baseDir = realpath(__DIR__ . '/../uploads/ms_actions');
    $file    = realpath(__DIR__ . '/../' . ltrim((string)$row[0], '/'));
    if ($baseDir !== false && $file !== false && strpos($file, $baseDir . DIRECTORY_SEPARATOR) === 0 && is_file($file)) {
        unlink($file);
    }

    ms_img_json(array('success' => true));
