<?php
/**
 * RDT Panel Action — shared storage for supporting images.
 * ---------------------------------------------------------------------------
 * One implementation behind both ways an image can arrive:
 *   - action-images.php  (the Upload button, AJAX, stores immediately)
 *   - iu-feedbacks.php   (the Action form's Submit, stored with the text)
 * Keeping the validation in one place is the point: a file accepted by one
 * route must be accepted by the other, and both are validated by the file's
 * real content rather than its client-supplied name.
 *
 * Rows live in rdt_panel_action_images keyed by panel_id; files live under
 * rdt-panels/uploads/action-images/ with a randomised name. Callers sit in
 * rdt-panels/, so the returned url is relative to that folder.
 */

if (!function_exists('rdt_action_image_dir')) {
    function rdt_action_image_dir() { return __DIR__ . '/uploads/action-images/'; }
    function rdt_action_image_web() { return 'uploads/action-images/'; }
}

if (!function_exists('rdt_action_image_size_text')) {
    /** Same wording the gallery uses client-side, for server-rendered captions. */
    function rdt_action_image_size_text($bytes) {
        $b = (int)$bytes;
        if ($b >= 1048576) { return (round($b / 104857.6) / 10) . ' MB'; }
        return max(1, (int)round($b / 1024)) . ' KB';
    }
}

if (!function_exists('rdt_action_images_fetch')) {
    /**
     * Every image stored for one panel, oldest first, each with a ready url.
     * Used to render an opened record's evidence without waiting for AJAX.
     */
    function rdt_action_images_fetch($conn, $panelId) {
        $panelId = (int)$panelId;
        $rows = array();
        if ($panelId <= 0) { return $rows; }
        $st = $conn->prepare("SELECT image_id, file_name, original_name, file_size, caption, uploaded_at
                              FROM rdt_panel_action_images WHERE panel_id = ? ORDER BY image_id");
        if (!$st) { return $rows; }
        $st->bind_param('i', $panelId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $r['url'] = rdt_action_image_web() . rawurlencode($r['file_name']);
            unset($r['file_name']);
            $rows[] = $r;
        }
        $st->close();
        return $rows;
    }
}

if (!function_exists('rdt_action_image_store')) {
    /**
     * Validate and store one uploaded file against a panel.
     * $file is one entry of $_FILES. Returns
     *   array('ok' => true,  'image' => array(image_id, url, original_name, file_size, caption, uploaded_at))
     *   array('ok' => false, 'msg' => 'reason shown to the user')
     * Nothing is written unless every check passes.
     */
    function rdt_action_image_store($conn, $panelId, array $file, $caption = '') {
        $panelId = (int)$panelId;
        if ($panelId <= 0) { return array('ok' => false, 'msg' => 'Missing panel reference.'); }

        $err = isset($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            $why = array(
                UPLOAD_ERR_INI_SIZE  => 'The file is larger than the server limit.',
                UPLOAD_ERR_FORM_SIZE => 'The file is larger than the form limit.',
                UPLOAD_ERR_PARTIAL   => 'The upload was interrupted — please try again.',
                UPLOAD_ERR_NO_FILE   => 'No file was selected.',
            );
            return array('ok' => false, 'msg' => isset($why[$err]) ? $why[$err] : 'The upload failed.');
        }

        $tmp  = $file['tmp_name'];
        $orig = isset($file['name']) ? $file['name'] : '';
        $size = isset($file['size']) ? (int)$file['size'] : 0;
        $maxB = 3 * 1024 * 1024;                        // 3 MB per image, as in Blinded

        if (!is_uploaded_file($tmp)) { return array('ok' => false, 'msg' => 'Invalid upload.'); }
        if ($size <= 0)    { return array('ok' => false, 'msg' => 'The file is empty.'); }
        if ($size > $maxB) { return array('ok' => false, 'msg' => 'The image is ' . round($size / 1048576, 1) . ' MB. Maximum allowed is 3 MB.'); }

        // Trust the content, not the name: it must be a real JPEG/PNG. This is what
        // stops a .php (or anything else) being stored with an image extension.
        $info    = @getimagesize($tmp);
        $allowed = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png');
        if ($info === false || !isset($allowed[$info[2]])) {
            return array('ok' => false, 'msg' => 'Only JPG, JPEG and PNG images are accepted. That file is not a valid image.');
        }
        $ext  = $allowed[$info[2]];
        $mime = image_type_to_mime_type($info[2]);

        $dir = rdt_action_image_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return array('ok' => false, 'msg' => 'Storage folder is not available.');
        }
        // Randomised name; the original is kept only as a display label.
        $stored = 'rp' . $panelId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . $stored)) {
            return array('ok' => false, 'msg' => 'The image could not be saved.');
        }
        @chmod($dir . $stored, 0644);

        $cap  = mb_substr(trim((string)$caption), 0, 255);
        $user = isset($_SESSION['userID']) ? (string)$_SESSION['userID'] : null;
        $now  = date('Y-m-d H:i:s');
        $st = $conn->prepare("INSERT INTO rdt_panel_action_images
                (panel_id, file_name, original_name, mime_type, file_size, caption, uploaded_by, uploaded_at)
                VALUES (?,?,?,?,?,?,?,?)");
        if (!$st) {
            // The file is orphaned rather than left half-recorded.
            @unlink($dir . $stored);
            return array('ok' => false, 'msg' => 'The image could not be saved.');
        }
        $st->bind_param('isssisss', $panelId, $stored, $orig, $mime, $size, $cap, $user, $now);
        $st->execute();
        $newId = $conn->insert_id;
        $st->close();

        return array('ok' => true, 'image' => array(
            'image_id' => $newId, 'url' => rdt_action_image_web() . rawurlencode($stored),
            'original_name' => $orig, 'file_size' => $size, 'caption' => $cap, 'uploaded_at' => $now
        ));
    }
}
