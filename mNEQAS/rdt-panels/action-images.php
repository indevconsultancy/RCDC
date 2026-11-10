<?php
/**
 * RDT Panel Action — supporting image upload (AJAX, JSON).
 * ---------------------------------------------------------------------------
 * img_action = list | upload | delete, for one rdt_panel_lab_result_informations
 * Action (keyed by panel_id). Images live under rdt-panels/uploads/action-images/
 * with a randomised name and are validated by their real content, never by the
 * client-supplied file name.
 *
 * Storage and validation live in action-image-store.php, shared with the Action
 * form's own Submit (iu-feedbacks.php), so an image is treated identically
 * whichever way it was sent.
 *
 * This mirrors the Blinded Rechecking action-image endpoint in blinded/blinded.php
 * (same storage layout, same validation, same JSON shape) so both modules behave
 * identically; it lives in its own file because feedbacks.php starts emitting
 * HTML on its first line and cannot return JSON.
 */
require_once("../config/session.php");
require_once("../config/auth.php");
require_once("../config/db-config.php");
require_once(__DIR__ . "/action-image-store.php");

function rdtImgJson(array $out) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

$imgAct = isset($_POST['img_action']) ? $_POST['img_action'] : (isset($_GET['img_action']) ? $_GET['img_action'] : '');
$panelId = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
if ($panelId <= 0) { rdtImgJson(array('ok' => false, 'msg' => 'Missing panel reference.')); }

// Record-level authorization: participants only for their own facility's panel.
mn_require_rdt_panel_ownership($conn, $panelId, 'json');

$imgDir = rdt_action_image_dir();
// Writing evidence follows the Action itself: read-only roles may look, not change.
$canManage = mn_can_write();

try {
    /* ---- list (anyone who may open this panel) ---- */
    if ($imgAct === 'list') {
        rdtImgJson(array('ok' => true,
                         'rows' => rdt_action_images_fetch($conn, $panelId),
                         'canManage' => $canManage));
    }

    if (!$canManage) { rdtImgJson(array('ok' => false, 'msg' => 'Not authorised.')); }

    /* ---- upload ---- */
    if ($imgAct === 'upload') {
        $file = isset($_FILES['image']) ? $_FILES['image'] : array('error' => UPLOAD_ERR_NO_FILE);
        $res  = rdt_action_image_store($conn, $panelId, $file, isset($_POST['caption']) ? $_POST['caption'] : '');
        if (!$res['ok']) { rdtImgJson(array('ok' => false, 'msg' => $res['msg'])); }
        rdtImgJson(array('ok' => true, 'image' => $res['image']));
    }

    /* ---- delete ---- */
    if ($imgAct === 'delete') {
        $iid = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;
        if ($iid <= 0) { rdtImgJson(array('ok' => false, 'msg' => 'Invalid image.')); }
        // Scoped by panel_id so one panel can never delete another's evidence.
        $st = $conn->prepare("SELECT file_name FROM rdt_panel_action_images WHERE image_id = ? AND panel_id = ?");
        $st->bind_param("ii", $iid, $panelId);
        $st->execute();
        $st->bind_result($fname);
        $found = $st->fetch();
        $st->close();
        if (!$found) { rdtImgJson(array('ok' => false, 'msg' => 'Image not found for this panel.')); }

        $path = $imgDir . basename($fname);
        if (is_file($path)) { @unlink($path); }
        $st = $conn->prepare("DELETE FROM rdt_panel_action_images WHERE image_id = ? AND panel_id = ?");
        $st->bind_param("ii", $iid, $panelId);
        $st->execute();
        $st->close();
        rdtImgJson(array('ok' => true));
    }

    rdtImgJson(array('ok' => false, 'msg' => 'Unknown action.'));
} catch (\Throwable $e) {
    error_log('[mNEQAS] rdt action-images: ' . $e->getMessage());
    rdtImgJson(array('ok' => false, 'msg' => 'Server error — the image was not saved.'));
}
