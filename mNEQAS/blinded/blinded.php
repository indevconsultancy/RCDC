<?php
/* =====================================================================
   Findings & Recommendations master — in-page AJAX endpoint.
   Kept inside blinded.php (no new files): this block runs before ANY
   output, answers with JSON and exits. Only RCDC/Admin may write.
   Actions: fm_action = list | save | delete
   ===================================================================== */
if (isset($_POST['fm_action'])) {
    require_once("../config/session.php");
    require_once("../config/db-config.php");
    header('Content-Type: application/json');

    $fmAdmin = isset($_SESSION["roleID"]) && ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2);
    if (!$fmAdmin) { echo json_encode(array('ok'=>false,'msg'=>'Not authorised.')); exit; }

    /* Categories live in blinded_finding_categories once the migration
       docs/migrations/2026-09-29-blinded-finding-categories.sql has been run.
       Before that the module falls back to the four built-in keys and the
       Manage Categories button stays hidden, so this file is safe to deploy
       either way. */
    $fmCatBuiltIn = array('parasitology','film','stain','general');
    $fmCatTable   = false;
    $fmCatRows    = array();
    $fmChk = @$conn->query("SHOW TABLES LIKE 'blinded_finding_categories'");
    if ($fmChk && $fmChk->num_rows > 0) {
        $fmCatTable = true;
        /* in_use drives the Manage Categories table and the delete rule: a
           category still on a finding is deactivated rather than deleted. */
        $fmCq = $conn->query("SELECT c.category_id, c.cat_key, c.name, c.description, c.status, c.sort_order,
                                     (SELECT COUNT(*) FROM blinded_finding_master m WHERE m.category = c.cat_key) AS in_use
                              FROM blinded_finding_categories c ORDER BY c.sort_order, c.name");
        if ($fmCq) { while ($fmR = $fmCq->fetch_assoc()) { $fmCatRows[] = $fmR; } }
    }
    /* Whatever a finding may be saved as: every known key, plus the built-ins so
       a row written before the migration always remains valid. */
    $fmCats = $fmCatBuiltIn;
    foreach ($fmCatRows as $fmR) {
        if (!in_array($fmR['cat_key'], $fmCats, true)) { $fmCats[] = $fmR['cat_key']; }
    }
    try {
        $act = $_POST['fm_action'];

        if ($act === 'list') {
            $rows = array();
            $rs = $conn->query("SELECT finding_id, title, category, rule_key, finding_content, recommendation_content,
                                       status, sort_order, created_at, updated_at
                                FROM blinded_finding_master ORDER BY category, title, finding_id");
            while ($r = $rs->fetch_assoc()) { $rows[] = $r; }
            echo json_encode(array(
                'ok'          => true,
                'rows'        => $rows,
                'categories'  => $fmCatRows,      // empty until the migration runs
                'catsDynamic' => $fmCatTable
            )); exit;
        }

        /* ---------------- categories ----------------
           Only available once the table exists. Keys are slugs derived from the
           name so the value stored on a finding stays short and stable even if
           the display name is edited later. */
        if ($act === 'cat_save' || $act === 'cat_delete') {
            if (!$fmCatTable) {
                echo json_encode(array('ok'=>false,'msg'=>'Category management is not enabled on this database yet. Run the 2026-09-29 categories migration first.')); exit;
            }
            $cid = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

            if ($act === 'cat_delete') {
                if ($cid <= 0) { echo json_encode(array('ok'=>false,'msg'=>'Invalid category.')); exit; }
                $ck = $conn->prepare("SELECT cat_key FROM blinded_finding_categories WHERE category_id=?");
                $ck->bind_param('i', $cid); $ck->execute(); $ck->bind_result($ckKey);
                $found = $ck->fetch(); $ck->close();
                if (!$found) { echo json_encode(array('ok'=>false,'msg'=>'Category not found.')); exit; }
                if (in_array($ckKey, $fmCatBuiltIn, true)) {
                    echo json_encode(array('ok'=>false,'msg'=>'The four built-in categories cannot be deleted. Set one to Inactive instead.')); exit;
                }
                /* A category still on a finding is never deleted — the finding would
                   be left pointing at nothing. Deactivate it instead, exactly as the
                   findings library does with an entry that is in use. */
                $uc = $conn->prepare("SELECT COUNT(*) FROM blinded_finding_master WHERE category=?");
                $uc->bind_param('s', $ckKey); $uc->execute(); $uc->bind_result($inUse);
                $uc->fetch(); $uc->close();
                if ((int)$inUse > 0) {
                    $up = $conn->prepare("UPDATE blinded_finding_categories SET status=0, updated_at=? WHERE category_id=?");
                    $upNow = date('Y-m-d H:i:s');
                    $up->bind_param('si', $upNow, $cid); $up->execute(); $up->close();
                    echo json_encode(array('ok'=>true,'deactivated'=>true,
                        'msg'=>'This category is used by '.(int)$inUse.' finding(s), so it was set to Inactive instead of deleted.')); exit;
                }
                $dl = $conn->prepare("DELETE FROM blinded_finding_categories WHERE category_id=?");
                $dl->bind_param('i', $cid); $dl->execute(); $dl->close();
                echo json_encode(array('ok'=>true,'deactivated'=>false)); exit;
            }

            /* cat_save: add or update */
            $cname = trim(isset($_POST['name']) ? $_POST['name'] : '');
            $cdesc = trim(isset($_POST['description']) ? $_POST['description'] : '');
            $cstat = (isset($_POST['cat_status']) && (int)$_POST['cat_status'] === 0) ? 0 : 1;
            if ($cname === '')            { echo json_encode(array('ok'=>false,'msg'=>'Category name is required.')); exit; }
            if (mb_strlen($cname) > 100)  { echo json_encode(array('ok'=>false,'msg'=>'Category name is too long (max 100 characters).')); exit; }
            if (mb_strlen($cdesc) > 255)  { echo json_encode(array('ok'=>false,'msg'=>'Description is too long (max 255 characters).')); exit; }

            $dupN = $conn->prepare("SELECT category_id FROM blinded_finding_categories WHERE name=? AND category_id<>?");
            $dupN->bind_param('si', $cname, $cid); $dupN->execute(); $dupN->store_result();
            if ($dupN->num_rows > 0) { $dupN->close(); echo json_encode(array('ok'=>false,'msg'=>'A category with that name already exists.')); exit; }
            $dupN->close();

            $catNow = date('Y-m-d H:i:s');
            if ($cid > 0) {
                /* cat_key is never rewritten: findings already store it. */
                $cs = $conn->prepare("UPDATE blinded_finding_categories SET name=?, description=?, status=?, updated_at=? WHERE category_id=?");
                $cs->bind_param('ssisi', $cname, $cdesc, $cstat, $catNow, $cid);
                $cs->execute(); $cs->close();
                echo json_encode(array('ok'=>true,'category_id'=>$cid)); exit;
            }
            /* new: slug the name, then de-duplicate with a numeric suffix */
            $base = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $cname));
            $base = trim($base, '_');
            if ($base === '') { $base = 'category'; }
            $base = mb_substr($base, 0, 56);
            $key  = $base; $nTry = 1;
            while (true) {
                $kq = $conn->prepare("SELECT category_id FROM blinded_finding_categories WHERE cat_key=?");
                $kq->bind_param('s', $key); $kq->execute(); $kq->store_result();
                $taken = $kq->num_rows > 0; $kq->close();
                if (!$taken) { break; }
                $nTry++; $key = $base . '_' . $nTry;
            }
            $sortQ = $conn->query("SELECT COALESCE(MAX(sort_order),0) + 10 FROM blinded_finding_categories");
            $sortN = $sortQ ? (int)$sortQ->fetch_row()[0] : 10;
            $ci = $conn->prepare("INSERT INTO blinded_finding_categories (cat_key, name, description, status, sort_order, created_at, updated_at)
                                  VALUES (?,?,?,?,?,?,?)");
            $ci->bind_param('sssiiss', $key, $cname, $cdesc, $cstat, $sortN, $catNow, $catNow);
            $ci->execute();
            $newCat = $conn->insert_id;
            $ci->close();
            echo json_encode(array('ok'=>true,'category_id'=>$newCat,'cat_key'=>$key)); exit;
        }

        if ($act === 'save') {
            $fid   = isset($_POST['finding_id']) ? (int)$_POST['finding_id'] : 0;
            $title = trim($_POST['title']);
            $cat   = in_array($_POST['category'], $fmCats) ? $_POST['category'] : 'general';
            /* isset-safe: staging runs with display_errors on, so a missing key
               would emit a warning into the JSON response body. */
            $fc    = isset($_POST['finding_content']) ? $_POST['finding_content'] : '';
            $rc    = isset($_POST['recommendation_content']) ? $_POST['recommendation_content'] : '';
            $st    = isset($_POST['status']) ? (int)$_POST['status'] : 1;
            /* Order was removed from the form: sort_order keeps DEFAULT 0 on insert and
               its existing value on update. The column itself is untouched. */

            if ($title === '') { echo json_encode(array('ok'=>false,'msg'=>'Title is required.')); exit; }

            /* Rule Key was removed from the form. An entry that already carries one
               keeps it: the UPDATE below does not touch the column, so the rule
               engine (Smart Suggest) goes on matching exactly as before. */
            // Duplicate titles are rejected too — the library is master data.
            $dupT = $conn->prepare("SELECT finding_id FROM blinded_finding_master WHERE title=? AND finding_id<>?");
            $dupT->bind_param("si", $title, $fid); $dupT->execute(); $dupT->store_result();
            if ($dupT->num_rows > 0) { $dupT->close(); echo json_encode(array('ok'=>false,'msg'=>'A finding with that title already exists.')); exit; }
            $dupT->close();

            $now = date('Y-m-d H:i:s');
            if ($fid > 0) {
                $stmt = $conn->prepare("UPDATE blinded_finding_master SET title=?, category=?, finding_content=?, recommendation_content=?, status=?, updated_at=? WHERE finding_id=?");
                $stmt->bind_param("ssssisi", $title, $cat, $fc, $rc, $st, $now, $fid);
            } else {
                $stmt = $conn->prepare("INSERT INTO blinded_finding_master(title, category, finding_content, recommendation_content, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param("ssssiss", $title, $cat, $fc, $rc, $st, $now, $now);
            }
            $stmt->execute();
            $newId = ($fid > 0) ? $fid : $conn->insert_id;
            $stmt->close();
            echo json_encode(array('ok'=>true,'id'=>$newId)); exit;
        }

        if ($act === 'delete') {
            $fid = isset($_POST['finding_id']) ? (int)$_POST['finding_id'] : 0;
            if ($fid <= 0) { echo json_encode(array('ok'=>false,'msg'=>'Invalid entry.')); exit; }
            // Preserve history: never orphan a feedback row that referenced this preset.
            $used = $conn->query("SELECT feedback_id FROM blinded_feedback WHERE finding_master_id = $fid
                                  UNION SELECT feedback_id FROM blinded_feedback_findings WHERE finding_id = $fid LIMIT 1");
            if ($used && $used->num_rows > 0) {
                $conn->query("UPDATE blinded_finding_master SET status = 0 WHERE finding_id = $fid");
                echo json_encode(array('ok'=>true,'softDeleted'=>true,'msg'=>'In use by saved feedback — deactivated instead of deleted.')); exit;
            }
            $stmt = $conn->prepare("DELETE FROM blinded_finding_master WHERE finding_id=?");
            $stmt->bind_param("i", $fid); $stmt->execute(); $stmt->close();
            echo json_encode(array('ok'=>true,'softDeleted'=>false)); exit;
        }

        echo json_encode(array('ok'=>false,'msg'=>'Unknown action.')); exit;
    } catch (\Throwable $e) {
        echo json_encode(array('ok'=>false,'msg'=>'Database error — nothing was saved.')); exit;
    }
}

/* =====================================================================
   Feedback & Actions — supporting image attachments.
   In-page endpoints (no new files): img_action = list | upload | delete.
   Images are stored under uploads/action-images/ with a randomised name and
   are validated by real content, not by the client-supplied file name.
   ===================================================================== */
if (isset($_POST['img_action']) || isset($_GET['img_action'])) {
    require_once("../config/session.php");
    require_once("../config/db-config.php");
    header('Content-Type: application/json');

    $imgAdmin = isset($_SESSION["roleID"]) && ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2);
    $imgAct   = isset($_POST['img_action']) ? $_POST['img_action'] : $_GET['img_action'];
    $imgBiid  = isset($_POST['biid']) ? (int)$_POST['biid'] : (isset($_GET['biid']) ? (int)$_GET['biid'] : 0);
    $imgDir   = __DIR__ . '/uploads/action-images/';
    $imgWeb   = 'uploads/action-images/';

    if ($imgBiid <= 0) { echo json_encode(array('ok'=>false,'msg'=>'Missing record reference.')); exit; }
    // Record-level authorization: facility users only for their own facility's record.
    mn_require_record($conn, 'blinded', $imgBiid, 'json');

    try {
        // ---- list (any signed-in user may view) ----
        if ($imgAct === 'list') {
            $rows = array();
            $st = $conn->prepare("SELECT image_id, file_name, original_name, file_size, caption, uploaded_at
                                  FROM blinded_action_images WHERE blinded_id = ? ORDER BY image_id");
            $st->bind_param("i", $imgBiid); $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) {
                $r['url'] = $imgWeb . rawurlencode($r['file_name']);
                unset($r['file_name']);
                $rows[] = $r;
            }
            $st->close();
            echo json_encode(array('ok'=>true,'rows'=>$rows,'canManage'=>$imgAdmin)); exit;
        }

        if (!$imgAdmin) { echo json_encode(array('ok'=>false,'msg'=>'Not authorised.')); exit; }

        // ---- upload ----
        if ($imgAct === 'upload') {
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $why = array(
                    UPLOAD_ERR_INI_SIZE=>'The file is larger than the server limit.',
                    UPLOAD_ERR_FORM_SIZE=>'The file is larger than the form limit.',
                    UPLOAD_ERR_PARTIAL=>'The upload was interrupted — please try again.',
                    UPLOAD_ERR_NO_FILE=>'No file was selected.',
                );
                $code = isset($_FILES['image']) ? $_FILES['image']['error'] : UPLOAD_ERR_NO_FILE;
                echo json_encode(array('ok'=>false,'msg'=>isset($why[$code]) ? $why[$code] : 'The upload failed.')); exit;
            }

            $tmp  = $_FILES['image']['tmp_name'];
            $orig = $_FILES['image']['name'];
            $size = (int)$_FILES['image']['size'];
            $maxB = 3 * 1024 * 1024;                       // 3 MB per image

            if (!is_uploaded_file($tmp)) { echo json_encode(array('ok'=>false,'msg'=>'Invalid upload.')); exit; }
            if ($size <= 0)    { echo json_encode(array('ok'=>false,'msg'=>'The file is empty.')); exit; }
            if ($size > $maxB) { echo json_encode(array('ok'=>false,'msg'=>'The image is '.round($size/1048576,1).' MB. Maximum allowed is 3 MB.')); exit; }

            // Trust the file's content, not its name: it must be a real JPEG/PNG.
            $info = @getimagesize($tmp);
            $allowed = array(IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png');
            if ($info === false || !isset($allowed[$info[2]])) {
                echo json_encode(array('ok'=>false,'msg'=>'Only JPG, JPEG and PNG images are accepted. That file is not a valid image.')); exit;
            }
            $ext  = $allowed[$info[2]];
            $mime = image_type_to_mime_type($info[2]);

            if (!is_dir($imgDir) && !@mkdir($imgDir, 0755, true)) {
                echo json_encode(array('ok'=>false,'msg'=>'Storage folder is not available.')); exit;
            }
            // Randomised name — the original is kept only as a display label.
            $stored = 'bl'.$imgBiid.'_'.bin2hex(random_bytes(8)).'.'.$ext;
            if (!move_uploaded_file($tmp, $imgDir.$stored)) {
                echo json_encode(array('ok'=>false,'msg'=>'The image could not be saved.')); exit;
            }
            @chmod($imgDir.$stored, 0644);

            $cap  = isset($_POST['caption']) ? mb_substr(trim($_POST['caption']), 0, 255) : '';
            $user = isset($_SESSION["userID"]) ? (string)$_SESSION["userID"] : null;
            $now  = date('Y-m-d H:i:s');
            $st = $conn->prepare("INSERT INTO blinded_action_images
                    (blinded_id, file_name, original_name, mime_type, file_size, caption, uploaded_by, uploaded_at)
                    VALUES (?,?,?,?,?,?,?,?)");
            $st->bind_param("isssisss", $imgBiid, $stored, $orig, $mime, $size, $cap, $user, $now);
            $st->execute(); $newId = $conn->insert_id; $st->close();

            echo json_encode(array('ok'=>true,'image'=>array(
                'image_id'=>$newId, 'url'=>$imgWeb.rawurlencode($stored),
                'original_name'=>$orig, 'file_size'=>$size, 'caption'=>$cap, 'uploaded_at'=>$now
            ))); exit;
        }

        // ---- delete ----
        if ($imgAct === 'delete') {
            $iid = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;
            if ($iid <= 0) { echo json_encode(array('ok'=>false,'msg'=>'Invalid image.')); exit; }
            // scoped by blinded_id so one record cannot delete another's evidence
            $st = $conn->prepare("SELECT file_name FROM blinded_action_images WHERE image_id = ? AND blinded_id = ?");
            $st->bind_param("ii", $iid, $imgBiid); $st->execute();
            $st->bind_result($fname); $found = $st->fetch(); $st->close();
            if (!$found) { echo json_encode(array('ok'=>false,'msg'=>'Image not found for this record.')); exit; }

            $path = $imgDir . basename($fname);
            if (is_file($path)) { @unlink($path); }
            $st = $conn->prepare("DELETE FROM blinded_action_images WHERE image_id = ? AND blinded_id = ?");
            $st->bind_param("ii", $iid, $imgBiid); $st->execute(); $st->close();
            echo json_encode(array('ok'=>true)); exit;
        }

        echo json_encode(array('ok'=>false,'msg'=>'Unknown action.')); exit;
    } catch (\Throwable $e) {
        echo json_encode(array('ok'=>false,'msg'=>'Server error — the image was not saved.')); exit;
    }
}

/* =====================================================================
   Controller 1 results export.
   Writes every Controller 1 slide of ONE record (slide_status = 'Intact', the
   same population as the C1 table, import, C2 and scoring) into the NMRL
   worksheet template, one row per slide, in the exact layout the importer
   worksheet layout. Rows are generated rather than filled into the
   template's pre-drawn cells, so any number of slides is exported. RCDC/Admin
   only. Runs before any output and streams a binary file.
   ===================================================================== */
if (isset($_GET['c1_export'])) {
    require_once("../config/session.php");
    require_once("../config/db-config.php");
    require_once("blinded-functions.php");

    $xRole = isset($_SESSION["roleID"]) ? (int)$_SESSION["roleID"] : 0;
    $xId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $xTpl  = __DIR__ . '/templates/blinded-rechecking-worksheet.xlsx';

    if ($xRole !== 1 && $xRole !== 2) {
        header('HTTP/1.1 403 Forbidden'); echo 'You are not authorised to export Controller 1 results.'; exit;
    }
    if ($xId <= 0 || !is_readable($xTpl) || !class_exists('ZipArchive')) {
        header('HTTP/1.1 404 Not Found'); echo 'Export not available.'; exit;
    }

    try {
        // The opened record fixes the reporting period being exported.
        $st = $conn->prepare("SELECT month, year, total_slides FROM blinded_informations WHERE blinded_id = ?");
        $st->bind_param('i', $xId);
        $st->execute();
        $xInfo = $st->get_result()->fetch_row();
        $st->close();
        if (!$xInfo) { header('HTTP/1.1 404 Not Found'); echo 'Record not found.'; exit; }

        $xMonthNo = (int)$xInfo[0];
        $xYear    = (int)$xInfo[1];

        /* Whole-month export: EVERY blinded record of the same month + year, from
           every facility, oldest first (blinded_id follows the submission order).
           The opened record only selects the reporting period — it does not limit
           the rows, so the sheet holds all accumulated Controller 1 slides of that
           month however many submissions they came from. */
        $st = $conn->prepare("SELECT b.blinded_id, b.facility_id, b.total_slides, b.receive_date, c.center_name
                                FROM blinded_informations b
                                LEFT JOIN rcdc_commondb.ms_health_center_table c
                                       ON c.center_id = b.facility_id
                               WHERE b.month = ? AND b.year = ?
                            ORDER BY b.blinded_id ASC");
        $st->bind_param('ii', $xMonthNo, $xYear);
        $st->execute();
        $xGrpRes = $st->get_result();
        $xGroups = array();
        while ($g = $xGrpRes->fetch_assoc()) { $xGroups[] = $g; }
        $st->close();

        // Controller 1 slides of each record (same population as the C1 table, import and scoring).
        $st = $conn->prepare("SELECT slide_no, slide_received_date, c1_chkdate, c1_microscopy, c1_stage, c1_parasite_count, c1_wbc_count, c1_density,
                                     size_thick_smear, size_thin_smear, shape_thin_smear, smear_spacing, eveness_thin_smear, blood_film,
                                     smear_intact, slide_labeling, background, stain_blood_element, cleanliness, stain_deposit
                              FROM blinded_results WHERE blinded_id = ? AND slide_status = 'Intact' ORDER BY result_id");
        foreach ($xGroups as $gk => $g) {
            $gid = (int)$g['blinded_id'];
            $st->bind_param('i', $gid);
            $st->execute();
            $xRes  = $st->get_result();
            $gRows = array();
            while ($r = $xRes->fetch_assoc()) { $gRows[] = $r; }
            $xGroups[$gk]['rows'] = $gRows;
        }
        $st->close();

        // A record with no rechecked (Intact) slides contributes nothing to the sheet.
        $xGroups = array_values(array_filter($xGroups, function ($g) { return count($g['rows']) > 0; }));
        $xRecs   = array();
        foreach ($xGroups as $g) { $xRecs = array_merge($xRecs, $g['rows']); }
    } catch (\Throwable $e) {
        header('HTTP/1.1 500 Internal Server Error'); echo 'Export failed. Please try again.'; exit;
    }
    if (!count($xRecs)) {
        header('HTTP/1.1 404 Not Found'); echo 'No Controller 1 records (Intact slides) to export for this month.'; exit;
    }

    // Paired tick columns of the NMRL worksheet layout.
    /* Paired tick columns of the NMRL worksheet layout. Health Facility occupies
       column A, so every column of the original layout sits one place right:
       Quality of Blood Film is L..AA and Quality of Stain AB..AI. */
    $xPairs = array(
        'size_thick_smear'    => array('L' => 'Acceptable', 'M' => 'NotAcceptable'),
        'size_thin_smear'     => array('N' => 'Acceptable', 'O' => 'NotAcceptable'),
        'shape_thin_smear'    => array('P' => 'Acceptable', 'Q' => 'NotAcceptable'),
        'smear_spacing'       => array('R' => 'Acceptable', 'S' => 'NotAcceptable'),
        'eveness_thin_smear'  => array('T' => 'Even',       'U' => 'NotEven'),
        'blood_film'          => array('V' => 'Both',       'W' => 'Mono'),
        'smear_intact'        => array('X' => 'Intact',     'Y' => 'WashOut'),
        'slide_labeling'      => array('Z' => 'Acceptable', 'AA' => 'NotAcceptable'),
        'background'          => array('AB' => 'Acceptable','AC' => 'NotAcceptable'),
        'stain_blood_element' => array('AD' => 'Acceptable','AE' => 'NotAcceptable'),
        'cleanliness'         => array('AF' => 'Acceptable','AG' => 'NotAcceptable'),
        'stain_deposit'       => array('AH' => 'Acceptable','AI' => 'NotAcceptable'),
    );
    $xCols = array();
    for ($i = 0; $i < 35; $i++) { $xCols[] = ($i < 26) ? chr(65 + $i) : 'A' . chr(65 + $i - 26); }   // A..AI
    $xEsc  = function ($v) { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $xDate = function ($v) { $v = trim((string)$v); return ($v === '' || strpos($v, '0000-00-00') === 0) ? '' : substr($v, 0, 10); };
    $xNum  = function ($v) { return ($v === null || $v === '' || !is_numeric($v)) ? null : (0 + $v); };

    $xMonth = showMonth($xMonthNo);
    $xRows  = '';
    $xLine  = 4;                                             // rows 1-3 are the merged header block
    $xLines = 0;

    /* One worksheet row, in the template's bordered data style. */
    $xEmit = function (array $txt, array $num) use (&$xRows, &$xLine, &$xLines, $xCols, $xEsc) {
        $cells = '';
        foreach ($xCols as $col) {
            $ref = $col . $xLine;
            if (isset($num[$col]) && $num[$col] !== null) {
                $cells .= '<c r="' . $ref . '" s="4"><v>' . $num[$col] . '</v></c>';
            } elseif (isset($txt[$col]) && $txt[$col] !== '') {
                $cells .= '<c r="' . $ref . '" s="4" t="inlineStr"><is><t>' . $xEsc($txt[$col]) . '</t></is></c>';
            } else {
                $cells .= '<c r="' . $ref . '" s="4"/>';
            }
        }
        $xRows .= '<row r="' . $xLine . '" spans="1:35">' . $cells . '</row>';
        $xLine++;
        $xLines++;
    };

    /* Flat dataset: every Controller 1 slide of the month, oldest record first,
       written straight under the template's header. No per-facility header or
       separator rows — each row stands on its own, exactly like the template's
       own data rows, so the sheet can be filtered or pivoted as one table. */
    foreach ($xGroups as $g) {

        foreach ($g['rows'] as $r) {
            $txt = array(); $num = array();
            /* The sheet holds every facility of the month, so each row names its
               own. center_name was already selected by the group query above and
               simply never written; facility_id is the fallback when the centre
               is missing from the shared commondb. */
            $xFac = trim((string)$g['center_name']);
            $txt['A'] = ($xFac !== '') ? $xFac : (string)$g['facility_id'];
            /* Slide Received Date. The per-slide column is preferred so nothing
               that already exports a value changes, but almost no row carries one
               (nothing in the application writes it), so the record's own receipt
               date from the Shipment Receive step is used instead. The two are
               identical wherever both are present. */
            $xRecv = $xDate($r['slide_received_date']);
            if ($xRecv === '') { $xRecv = $xDate($g['receive_date']); }
            $txt['B'] = $xRecv;
            $txt['C'] = $xDate($r['c1_chkdate']);
            $txt['D'] = $xMonth;
            $num['E'] = $xNum($g['total_slides']);
            $txt['F'] = (string)$r['slide_no'];
            $txt['G'] = (string)$r['c1_microscopy'];
            $txt['H'] = (string)$r['c1_stage'];
            $num['I'] = $xNum($r['c1_parasite_count']);
            $num['J'] = $xNum($r['c1_wbc_count']);
            $num['K'] = $xNum($r['c1_density']);
            foreach ($xPairs as $field => $opts) {
                foreach ($opts as $col => $stored) {
                    if ((string)$r[$field] === $stored) { $txt[$col] = "\u{2713}"; }
                }
            }
            $xEmit($txt, $num);
        }
    }

    // Keep the template's 30 bordered data rows when the month is shorter than that.
    while ($xLines < 30) { $xEmit(array(), array()); }

    $tmp = tempnam(sys_get_temp_dir(), 'blc1x');
    copy($xTpl, $tmp);
    $zip = new ZipArchive();
    $ok  = false;
    if ($zip->open($tmp) === true) {
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $pos   = ($sheet !== false) ? strpos($sheet, '<row r="4"') : false;
        $end   = ($sheet !== false) ? strpos($sheet, '</sheetData>') : false;
        if ($pos !== false && $end !== false && $end > $pos) {
            // keep header rows 1-3, replace every template data row with the generated rows
            $sheet = substr($sheet, 0, $pos) . $xRows . substr($sheet, $end);
            $sheet = preg_replace('/<dimension ref="[^"]*"\/>/', '<dimension ref="A1:AI' . (3 + $xLines) . '"/>', $sheet, 1);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
            $ok = true;
        }
        $zip->close();
    }
    if (!$ok) {
        @unlink($tmp);
        header('HTTP/1.1 500 Internal Server Error'); echo 'Export failed. Please try again.'; exit;
    }

    $fname = 'Controller-1-Results-' . $xMonth . '-' . $xYear . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

?>
<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php
    require_once("../config/commondb-config.php");
    require_once("../config/db-config.php");
?>
<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <!-- Top Navbar -->
  <?php require_once("../includes/top-navbar.php"); ?>
  <!-- Main Sidebar Container -->
  <?php require_once("../includes/sidebar-menu.php"); ?>
  <!-- Month Name Function -->
  <?php require_once("blinded-functions.php"); ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Blinded Rechecking</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="blinded-list.php">Blinded List</a></li>
                        <li class="breadcrumb-item active"> Blinded Rechecking | MP Slide Crosschecking</li>
                    </ol>
                </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>

    <!-- Data and page settings -->
<!-- Data and page settings -->
<?php
    $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";

    // === SAFE ACCESS TO $_GET['action'] ===
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    // === FIX: Initialize $readonlySlide here ===
    $readonlySlide = "";   // Default: editable for new entries

    // Role helper
    $isRCDC = ($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2);

    // Defaults so these are always defined regardless of role
    $facilityId = $dzongkhag_code = $dzongkhag_name = $center_name = '';

    // District users are pinned to their own dzongkhag / facility
    if (!$isRCDC) {
        $facilityId = isset($_SESSION["facilityID"]) ? $_SESSION["facilityID"] : '';
        if ($facilityId !== '') {
            $dzdqry = $commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$facilityId' )");
            if ($dzdqry && ($dzrow = $dzdqry->fetch_row())) {
                list($dzongkhag_code, $dzongkhag_name) = $dzrow;
            }
            $centerqry = $commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$facilityId'");
            if ($centerqry && ($ctrow = $centerqry->fetch_row())) {
                list($center_name) = $ctrow;
            }
        }
    }

    if($action == 2){
        $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
        // Record-level authorization (IDOR): facility users may open only their own facility's record.
        mn_require_record($conn, 'blinded', $id);
        $cancel = "blinded.php?action=2&id=$id";

        $binfoqry = ($conn->query("SELECT blinded_id, facility_id, month, year, total_microscopy, total_rdt, total_malaria, positive_slides, negative_slides, total_slides, shipment_date, receive_date, total_microscopy FROM blinded_informations WHERE blinded_id=".$id));
        $binfo = $binfoqry->fetch_row();
        $dorec = $binfo[11];

        $facility = ($commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='$binfo[1]'"));
        $facility = $facility->fetch_row();

        $dzongkhag = ($commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table WHERE dzongkhag_code=(SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id='$binfo[1]' )"));
        $dzongkhag = $dzongkhag->fetch_row();

        $readonlySlide = "readonly";
        if($binfo[4] > 0)  $readonlySlide = "";

        /* Whole-record delete is Administrator-only — delete.php enforces exactly
           this and answers 403 to anyone else, so offering the button to RCDC or
           to the owning facility only produced a dead end. */
        $deletebi = mn_is_admin()
            ? "<a href='delete.php?type=bi&id=$id&biid=$id' class='btn bg-gradient-danger js-confirm-delete'>Delete</a>"
            : "";
        $biMonth = showMonth($binfo[2]);
        $monthInfo = "<option value='$binfo[2]'>$biMonth</option>";
        $yearInfo = "<option value='$binfo[3]'>$binfo[3]</option>";

        // Fetch persistent supporting/reference images for Action Taken
        $actionImages = array();
        if ($id > 0) {
            $aiStmt = $conn->prepare("SELECT image_id, file_name, original_name, mime_type, file_size, caption, uploaded_at 
                                      FROM blinded_action_images 
                                      WHERE blinded_id = ? 
                                      ORDER BY image_id ASC");
            if ($aiStmt) {
                $aiStmt->bind_param("i", $id);
                $aiStmt->execute();
                $aiRes = $aiStmt->get_result();
                while ($air = $aiRes->fetch_assoc()) {
                    $air['url'] = 'uploads/action-images/' . rawurlencode($air['file_name']);
                    $actionImages[] = $air;
                }
                $aiStmt->close();
            }
        }
    }
    else{
        $id = 'NULL';
        $cancel = "blinded.php?action=1";
        $deletebi = "";
        $actionImages = array();
        $binfo = array('', '', '', '', '', '', '', '', '', '','','','');
        $facility = array('Select Facility');
        $dzongkhag = array('','Select Dzongkhag');

        $monthInfo = "<option value=''>Select month</option>";
        $yearInfo = "<option value=".date('Y').">".date('Y')."</option>";

        if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) {
            $dzongkhag[0] = $dzongkhag_code;
            $dzongkhag[1] = $dzongkhag_name;
            $binfo[1] = $facilityId;
            $facility[0] = $center_name;
        }
    }
    $shipment_date = "Shipment Date";
    if ($binfo[4]==0) {
        $shipment_date = "Reporting Date";
    }
?>

<!-- ============================================================= -->
<!-- Professional UX styling (uses existing Bootstrap/AdminLTE)     -->
<!-- ============================================================= -->
<style>
    /* ================================================================= */
    /*  Modern enterprise workflow stepper                                */
    /*  Single reusable component — shared by the New Entry (intake) and  */
    /*  Edit (process) wizards for every role. State is driven purely by  */
    /*  the existing .done / .active / .locked classes, so no workflow,   */
    /*  status or permission logic changes are required.                  */
    /*  States: default = Pending (grey) | .active = In Progress (blue)   */
    /*          | .done = Completed (green check) | .locked = Pending look */
    /* ================================================================= */
    /* Blinded stepper uses the single global standardized design in
       dist/css/mneqas-theme.css — identical to the Onsite M&S stepper.
       The former per-page .bs-wizard styling was removed for consistency. */
    .wizard-pane{display:none;}
    .wizard-pane.active{display:block;}
    .p-pane{display:none;}

    /* MP Slide Information wizard form */
    .wiz-form .form-group{margin-bottom:1rem;}
    .wiz-form label{display:block;min-height:2.5em;margin-bottom:.35rem;font-weight:600;line-height:1.25;}
    .wiz-footer .btn{min-width:130px;}
    .wiz-footer .nav-actions{display:flex;}
    .wiz-footer .nav-actions .btn{margin-left:.5rem;}
    @media (max-width:575.98px){
        .wiz-footer{flex-direction:column;align-items:stretch !important;}
        .wiz-footer .nav-actions{margin-top:.5rem;}
        .wiz-footer .btn{width:100%;margin-left:0 !important;margin-bottom:.35rem;}
    }

    /* View mode */
    .view-hero{background:linear-gradient(135deg,#0062cc 0%,#007bff 100%);color:#fff;border:none;border-radius:.5rem;}
    .view-hero .hero-title{font-size:1.5rem;font-weight:700;margin-bottom:.15rem;}
    .view-hero .hero-sub{opacity:.9;}
    .view-hero .badge-achv{font-size:.95rem;padding:.5rem .85rem;border-radius:2rem;}
    .stat-card{border:none;border-radius:.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);}
    .stat-card .stat-num{font-size:1.35rem;font-weight:700;}
    .stat-card .stat-lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:#6c757d;}

    /* Timeline */
    /* .bl-timeline removed — the view-mode progress now uses the single standardized
       .bs-wizard stepper (dist/css/mneqas-theme.css), so no alternate progress UI remains. */
    .review-box{background:#f8f9fa;border:1px solid #e9ecef;border-radius:.35rem;padding:1rem;}

    /* Review step */
    .review-sec-title{font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#007bff;border-bottom:2px solid #e9ecef;padding-bottom:.35rem;margin:1.5rem 0 .85rem;}
    .review-tile{background:#f8f9fa;border:1px solid #e9ecef;border-radius:.4rem;padding:.65rem .85rem;height:100%;}
    .review-tile small{display:block;color:#6c757d;text-transform:uppercase;font-size:.68rem;letter-spacing:.04em;margin-bottom:.15rem;}
    .review-tile strong{font-size:.95rem;word-break:break-word;}
    .review-content-box{background:#fff;border:1px solid #e9ecef;border-left:4px solid #007bff;border-radius:.35rem;padding:.85rem 1rem;overflow-x:auto;}
    /* Review step: Findings / Recommendations / Action cards */
    .review-fb-card{display:flex;flex-direction:column;width:100%;background:#fff;border:1px solid #e9ecef;border-radius:.45rem;box-shadow:0 1px 3px rgba(0,0,0,.06);overflow:hidden;}
    .review-fb-head{padding:.6rem 1rem;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#f8f9fa;border-bottom:1px solid #e9ecef;}
    .review-fb-head i{margin-right:.4rem;}
    .review-fb-body{flex:1;padding:.9rem 1.1rem;font-size:.9rem;line-height:1.55;max-height:26rem;overflow:auto;}
    .review-fb-body p{margin-bottom:.6rem;}
    .review-fb-body ul{padding-left:1.25rem;margin-bottom:.6rem;}
    .review-fb-body > :last-child{margin-bottom:0;}
    .review-fb-card.fb-find{border-top:3px solid #007bff;}
    .review-fb-card.fb-find .review-fb-head{color:#007bff;}
    .review-fb-card.fb-reco{border-top:3px solid #059669;}
    .review-fb-card.fb-reco .review-fb-head{color:#059669;}
    .review-fb-card.fb-act{border-top:3px solid #D97706;}
    .review-fb-card.fb-act .review-fb-head{color:#D97706;}
</style>

<?php
/* ================================================================= */
/*  BRANCH 1 : NEW INTAKE (action=1)  ->  District Wizard             */
/*  Steps: Information -> Slide Entry -> Review & Submit               */
/* ================================================================= */
if ($action != 2) {
?>
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">MP Slide Information</h3>
                        </div>
                        <div class="card-body">

                            <!-- Stepper -->
                            <?php if ($isRCDC) { ?>
                            <!-- Admin sees the full unified 7-step roadmap from the start; steps 2-7
                                 are locked until Step 1 (MP Slide Info) is saved, which creates the
                                 record and continues in the same wizard (action=2 process wizard). -->
                            <div class="bs-wizard" id="newAdminNav" role="list" aria-label="Blinded Rechecking workflow steps">
                                <div class="wizard-step active" data-step="1" role="listitem" tabindex="0" aria-label="MP Slide Info"><span class="num">1</span>MP Slide Info</div>
                                <div class="wizard-step" data-step="2" role="listitem" tabindex="0" aria-label="District Results"><span class="num">2</span>District Results</div>
                                <div class="wizard-step" data-step="3" role="listitem" tabindex="0" aria-label="Shipment Receive"><span class="num">3</span>Shipment Receive</div>
                                <div class="wizard-step" data-step="4" role="listitem" tabindex="0" aria-label="Controller 1"><span class="num">4</span>Controller 1</div>
                                <div class="wizard-step" data-step="5" role="listitem" tabindex="0" aria-label="Controller 2"><span class="num">5</span>Controller 2</div>
                                <div class="wizard-step" data-step="6" role="listitem" tabindex="0" aria-label="Feedback and Actions"><span class="num">6</span>Feedback &amp; Actions</div>
                                <div class="wizard-step" data-step="7" role="listitem" tabindex="0" aria-label="Review"><span class="num">7</span>Review</div>
                            </div>
                            <?php } else { ?>
                            <div class="bs-wizard" role="list" aria-label="Blinded Rechecking workflow steps">
                                <div class="wizard-step active" data-step="1" role="listitem" tabindex="0" aria-label="Information"><span class="num">1</span>Information</div>
                                <div class="wizard-step" data-step="2" role="listitem" tabindex="0" aria-label="Slide Entry"><span class="num">2</span>Slide Entry</div>
                                <div class="wizard-step" data-step="3" role="listitem" tabindex="0" aria-label="Review and Submit"><span class="num">3</span>Review &amp; Submit</div>
                            </div>
                            <?php } ?>

                            <!-- ============ STEP 1 : INFORMATION ============ -->
                            <div class="wizard-pane active" id="wizard-step-1">
                                <form class="form-horizontal wiz-form" id="blindedInfo" autocomplete="off" method="Post" action="iu-blinded.php" <?= ($_SESSION["roleID"] == 3) ? 'onsubmit="return false;"' : '';?>>

                                    <input type='hidden' name='action' id='action' value='<?=$action;?>'/>
                                    <input type='hidden' name='submit' id='submit' value='blindedInfo' />
                                    <input type="hidden" name="biid" id="biid" value="<?=$binfo[0];?>" />

                                    <!-- Row 1 : Location & Reporting Period -->
                                    <div class="row">
                                        <?php if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) { ?>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm">Dzongkhag:</label>
                                            <select class="form-control select2" name="dzongkhag" id="dzongk" style="width:100%;">
                                                <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                                                <?php if ($dzongkhag_code != '' && $dzongkhag_code != $dzongkhag[0]) { ?>
                                                <option value='<?=$dzongkhag_code;?>'><?=$dzongkhag_name;?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm">Health Facility:</label>
                                            <div id="facilityDiv">
                                                <select class="form-control" name="facility" id="facility">
                                                    <option value="<?=$binfo[1];?>"><?=$facility[0];?></option>
                                                    <?php if ($facilityId != '' && $facilityId != $binfo[1]) { ?>
                                                    <option value="<?=$facilityId;?>"><?=$center_name;?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                        <?php } else { ?>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm">Dzongkhag:</label>
                                            <select class="form-control select2" name="dzongkhag" id="dzongkhag" style="width:100%;">
                                                <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                                                <?php
                                                    $result = $commondb->query($dzsql);
                                                    while($row = $result->fetch_row()) {
                                                        echo("<option value='$row[0]'>$row[1]</option>");
                                                    }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm">Health Facility:</label>
                                            <div id="facilityDiv">
                                                <select class="form-control" name="facility" id="facility">
                                                    <option value="<?=$binfo[1];?>"><?=$facility[0];?></option>
                                                </select>
                                            </div>
                                        </div>
                                        <?php } ?>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm">Month:</label>
                                            <select class="form-control" name="month" id="month" <?php if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) {?> onchange="validateTime(this.value, year.value);" <?php } ?>>
                                                <?=$monthInfo;?>
                                                <option value="1">January</option>
                                                <option value="2">February</option>
                                                <option value="3">March</option>
                                                <option value="4">April</option>
                                                <option value="5">May</option>
                                                <option value="6">June</option>
                                                <option value="7">July</option>
                                                <option value="8">August</option>
                                                <option value="9">September</option>
                                                <option value="10">October</option>
                                                <option value="11">November</option>
                                                <option value="12">December</option>
                                            </select>
                                        </div>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm">Year:</label>
                                            <select class="form-control" name="year" id="year" <?php if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) {?> onchange="validateTime(month.value, this.value);" <?php } ?> >
                                                <?=$yearInfo;?>
                                                <?php
                                                for ($i=date("Y"); $i >= 2010; $i--) {
                                                    echo "<option value='$i'>$i</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Row 2 : Screening Totals & Shipment -->
                                    <div class="row">
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm" for="totalMicroscopy">Total Microscopy Screened<br>(PACD+RACD+PCD):</label>
                                            <input type="number" name="totalMicroscopy" id="totalMicroscopy" class="form-control text-center" min="0" value="<?=$binfo[4];?>" required onchange="reqBlinded(this.value);calTotalMalaria(this.value,totalRDT.value);"/>
                                        </div>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm" for="totalRDT">Total RDT Screened<br>(PACD+RACD+PCD):</label>
                                            <input type="number" name="totalRDT" id="totalRDT" class="form-control text-center" min="0" value="<?=$binfo[5];?>" required onchange="calTotalMalaria(totalMicroscopy.value,this.value);"/>
                                        </div>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm" for="totalMalaria">Total Malaria Screened:</label>
                                            <input type="number" name="totalMalaria" id="totalMalaria" class="form-control text-center" min="0" required value="<?=$binfo[6];?>" readonly/>
                                        </div>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm" for="dateship"><?=$shipment_date;?>:</label>
                                            <div class="input-group date" id="dateship" data-target-input="nearest">
                                                <input type="text" class="form-control datetimepicker-input text-center" data-target="#dateship" name="dateship"  placeholder="YYYY-MM-DD" value="<?=$binfo[10];?>" />
                                                <div class="input-group-append" data-target="#dateship" data-toggle="datetimepicker">
                                                    <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Row 3 : Blinded Slide Selection -->
                                    <div class="row">
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm">Positive Slides Selected:</label>
                                            <input type="number" name="pslides" id="pslides" class="form-control text-center" min="0"  value="<?=$binfo[7];?>" onchange="calTotalSlides(this.value,nslides.value);chkTotalMicroscopy(this.value,nslides.value,totalMicroscopy.value);" <?=$readonlySlide;?> />
                                        </div>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm">Negative Slides Selected:</label>
                                            <input type="number" name="nslides" id="nslides" class="form-control text-center" min="0" value="<?=$binfo[8];?>" onchange="calTotalSlides(pslides.value,this.value);chkTotalMicroscopy(pslides.value,this.value,totalMicroscopy.value);" <?=$readonlySlide;?> />
                                        </div>
                                        <div class="form-group col-12 col-md-6 col-lg-3">
                                            <label class="col-form-label-sm" for="tslides">Total Blinded Slides Selected:</label>
                                            <input type="number" name="tslides" id="tslides" class="form-control text-center" min="0" required value="<?=$binfo[9];?>" readonly/>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <!-- ============ /STEP 1 ============ -->

                            <?php if (!$isRCDC) { /* Admin enters slides/review in the process wizard (pane-district onward) */ ?>
                            <!-- ============ STEP 2 : SLIDE ENTRY ============ -->
                            <div class="wizard-pane" id="wizard-step-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="text-primary col-form-label mb-0">Enter District Lab Results for each Blinded Slide</label>
                                    <button type="button"  onclick="addSlideRow();"></button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <tr class="text-center">
                                                <th>B/S No.</th>
                                                <th>Date of Collection</th>
                                                <th>Microscopy Result</th>
                                                <th>Parasite Stage</th>
                                                <th>Parasite Count</th>
                                                <th>WBC Count</th>
                                                <th>Parasite Density</th>
                                                <?php if ($_SESSION["roleID"] != 3) { ?>
                                                <th>Remove</th>
                                                <?php } ?>
                                            </tr>
                                        </thead>
                                        <tbody id="slideRows"></tbody>
                                    </table>
                                </div>
                                <small class="text-muted">Parasite Density updates in real time and is stored on submission. For a negative (Nmps) result, stage &amp; counts are cleared automatically.</small>

                                <!-- Prototype row cloned by JS (options rendered by existing PHP functions) -->
                                <template id="slideRowTpl">
                                    <tr class="slide-row">
                                        <td><input type="text" class="form-control s-bsno" placeholder="B/S No."></td>
                                        <td>
                                            <div class="input-group date s-doc-wrap" id="__DOCID__" data-target-input="nearest">
                                                <input type="text" class="form-control text-center datetimepicker-input s-doc" data-target="#__DOCID__" placeholder="YYYY-MM-DD">
                                                <div class="input-group-append" data-target="#__DOCID__" data-toggle="datetimepicker">
                                                    <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <select class="form-control s-mic" onchange="rowReqResult(this);">
                                                <option value="">Select Microscopy Result</option>
                                                <?= microscopyResults(); ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-control s-stage">
                                                <option value="">Select Stage</option>
                                                <?= parasiteStages(); ?>
                                            </select>
                                        </td>
                                        <td><input type="number" class="form-control text-center s-pcount"></td>
                                        <td><input type="number" class="form-control text-center s-wbc"></td>
                                        <td><input type="number" class="form-control text-center s-density" readonly></td>
                                        <?php if ($_SESSION["roleID"] != 3) { ?>
                                        <td class="text-center"><button type="button" class="btn btn-sm bg-gradient-danger" onclick="removeSlideRow(this);"><i class="fa fa-times"></i></button></td>
                                        <?php } ?>
                                    </tr>
                                </template>
                            </div>
                            <!-- ============ /STEP 2 ============ -->

                            <!-- ============ STEP 3 : REVIEW ============ -->
                            <div class="wizard-pane" id="wizard-step-3">
                                <div class="review-box" id="reviewBox"></div>
                            </div>
                            <!-- ============ /STEP 3 ============ -->
                            <?php } /* end !$isRCDC intake panes */ ?>

                            <!-- Wizard footer navigation -->
                            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 wiz-footer" id="submitInfo">
                                <a href="<?=$cancel;?>" class="btn bg-gradient-secondary">Cancel</a>
                                <div class="nav-actions">
                                <?php if ($isRCDC) { ?>
                                    <!-- Admin: Step 1 saves (creating the record) and continues straight into
                                         the unified process wizard at District Results — no separate submit,
                                         no Process/Edit reopen. -->
                                    <button type="button" class="btn bg-gradient-primary" id="adminInfoContinueBtn" onclick="adminSaveInfoContinue();">Save &amp; Continue <i class="fa fa-arrow-right"></i></button>
                                <?php } else { ?>
                                    <button type="button" class="btn bg-gradient-secondary" id="wizardBackBtn" style="display:none;" onclick="wizardBack();"><i class="fa fa-arrow-left"></i> Previous</button>
                                    <button type="button" class="btn bg-gradient-primary" id="wizardNextBtn" onclick="wizardNext();">Next <i class="fa fa-arrow-right"></i></button>
                                    <button type="button" class="btn bg-gradient-success" id="wizardSubmitBtn" style="display:none;" onclick="finalizeWizard();"><i class="fa fa-check"></i> Submit</button>
                                <?php } ?>
                                </div>
                            </div>

                        </div>
                        <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                </div>
            </div>
        </div>
    </section>
<?php
}
/* ================================================================= */
/*  BRANCH 2 : EXISTING RECORD (action=2)                            */
/*  -> Professional VIEW mode (default) + PROCESS wizard (toggle)     */
/* ================================================================= */
else {

    // ---- Gating / status computations ----
    $labResExist    = ($conn->query("SELECT lab_microscopy FROM blinded_results WHERE lab_microscopy !='' AND blinded_id = $id"))->num_rows;
    $countC1Results = ($conn->query("SELECT c1_microscopy FROM blinded_results WHERE slide_status = 'Intact' AND c1_microscopy !='' AND blinded_id = $id"))->num_rows;
    $countIntact    = ($conn->query("SELECT slide_status FROM blinded_results WHERE slide_status = 'Intact' AND blinded_id = $id"))->num_rows;
    $chkfeedback    = ($conn->query("SELECT feedback_id FROM blinded_feedback WHERE blinded_id = $id"))->num_rows;
    $c2Count        = ($conn->query("SELECT c2_microscopy FROM blinded_results WHERE c2_microscopy !='' AND slide_status='Intact' AND blinded_id = $id"))->num_rows;

    // Derive counts strictly based on intact eligible slides
    $intactSlidesQuery = $conn->query("SELECT result_id, slide_no, c1_microscopy, c2_microscopy FROM blinded_results WHERE slide_status = 'Intact' AND blinded_id = $id");
    $intactSlides = array();
    $c1Cnt = 0;
    $c2Cnt = 0;
    if ($intactSlidesQuery) {
        while ($isr = $intactSlidesQuery->fetch_assoc()) {
            $intactSlides[] = $isr;
            if ($isr['c1_microscopy'] !== null && trim((string)$isr['c1_microscopy']) !== '') {
                $c1Cnt++;
            }
            if ($isr['c2_microscopy'] !== null && trim((string)$isr['c2_microscopy']) !== '') {
                $c2Cnt++;
            }
        }
    }
    $intactCnt = count($intactSlides);
    $c1Done    = ($intactCnt > 0 && $c1Cnt === $intactCnt);
    $c2Done    = ($c1Done && $intactCnt > 0 && $c2Cnt === $intactCnt);

    $c2Stepper = blDeriveC2StepperStatus($intactCnt, $c1Cnt, $c2Cnt);
    $c2Status  = $c2Stepper['status'];
    $c2BadgeClass = $c2Stepper['badgeClass'];

    $c2Ready = $c1Done;
    $fbExists = ($chkfeedback > 0);

    // Finalization state. The Print Feedback Report button is gated on this:
    // the report becomes printable only after RCDC/admin performs "Final Submit"
    // on the read-only Review step (Preview -> Review -> Final Submit -> Print).
    // Derived from the finalized flag on blinded_feedback (see finalize handler
    // in iu-blinded.php). Not finalized until the row exists AND finalized = 1.
    $isFinalized = false; $finalizedAt = '';
    if ($fbExists) {
        $finRow = ($conn->query("SELECT finalized, finalized_at FROM blinded_feedback WHERE blinded_id = $id"))->fetch_row();
        $isFinalized = ($finRow && (int)$finRow[0] === 1);
        $finalizedAt = ($finRow && $finRow[1]) ? $finRow[1] : '';
    }

    $tmlqry = $conn->query("SELECT timeliness FROM blinded_informations WHERE blinded_id=$id");
    list($vTimeliness) = $tmlqry->fetch_row();

    // Feedback / score for the view (may be null when not yet generated)
    $vScore = null;
    if ($fbExists) {
        $vScore = ($conn->query("SELECT sensitivity, specificity, malaria_infection, parasite_species, parasite_stage, parasite_density, average, achievement, blinded_finding, blinded_recommendation, blinded_action, film_quality, stain_quality FROM blinded_feedback WHERE blinded_id='$id'"))->fetch_row();
    }

    // Status flags for the timeline
    $stSlides   = ($labResExist > 0);
    $stReceived = ($dorec !== null && $dorec !== '');
    $stC1       = $c1Done;
    $stC2       = $c2Done;
    $stFeedback = ($vScore && $vScore[8] !== '' && $vScore[8] !== null);
    $stAction   = ($vScore && $vScore[10] !== '' && $vScore[10] !== null);

    // Achievement badge
    $achv = $vScore ? $vScore[7] : '';
    if ($achv == "Excellent")       { $achvIcon = "<i class='fas fa-award'></i><i class='fas fa-award'></i><i class='fas fa-award'></i>"; $achvClass="badge-success"; }
    else if ($achv == "Very Good")  { $achvIcon = "<i class='fas fa-award'></i><i class='fas fa-award'></i>"; $achvClass="badge-success"; }
    else if ($achv == "Good")       { $achvIcon = "<i class='fas fa-award'></i>"; $achvClass="badge-success"; }
    else if ($achv != "")           { $achvIcon = "<i class='fas fa-thumbs-down'></i>"; $achvClass="badge-danger"; }
    else                            { $achvIcon = "<i class='fas fa-hourglass-half'></i>"; $achvClass="badge-light"; $achv="In Progress"; }

    // Current stage for timeline highlight
    $curStage = 'slides';
    if ($stAction)          $curStage = 'completed';
    else if ($stFeedback)   $curStage = 'action';
    else if ($stC2)         $curStage = 'feedback';
    else if ($stC1)         $curStage = 'c2';
    else if ($stReceived)   $curStage = 'c1';
    else if ($stSlides)     $curStage = 'received';
    else                    $curStage = 'slides';
?>

    <section class="content">
        <div class="container-fluid">

            <!-- Mode toggle -->
            <?php
                // District workflow: the report stays editable only until RCDC
                // receives the shipment or starts Controller 1 processing.
                $districtEditable = (!$stReceived && $countC1Results == 0);
                if ($curStage == 'completed')                { $dStatus = 'Completed';    $dStatusClass = 'badge-success'; }
                else if ($stReceived || $countC1Results > 0) { $dStatus = 'Under Review'; $dStatusClass = 'badge-info'; }
                else                                         { $dStatus = 'Submitted';    $dStatusClass = 'badge-primary'; }
            ?>
            <div class="mb-3 d-flex flex-wrap align-items-center" id="modeToggleBar">
                <div class="btn-group" role="group">
                    <?php if ($isRCDC) { ?>
                    <button type="button" class="btn btn-primary" id="btnProcess" onclick="showProcess();showProcStep(0);"><i class="fas fa-edit"></i> Edit</button>
                    <button type="button" class="btn btn-outline-primary" id="btnView" onclick="showView();"><i class="fas fa-eye"></i>Preview</button>
                    <?php } else { ?>
                    <button type="button" class="btn btn-primary" id="btnView" onclick="showView();"><i class="fas fa-eye"></i> View Summary</button>
                    <?php if ($districtEditable) { ?>
                    <button type="button" class="btn btn-outline-primary" id="btnProcess" onclick="showProcess();showProcStepById('pane-district');"><i class="fas fa-edit"></i> Edit Report</button>
                    <?php } ?>
                    <?php } ?>
                </div>
                <?php if (!$isRCDC && $dStatus != 'Submitted') { ?>
                <span class="badge <?=$dStatusClass;?> ml-2 px-3 py-2"><?=$dStatus;?></span>
                <?php } ?>
                <?php if($fbExists){ ?>
                <!-- Print is available only after Final Submit (finalized). The anchor is
                     rendered once feedback exists but stays hidden (d-none) until finalized,
                     so finalizeRecord() can reveal it client-side without a full reload. -->
                <a id="btnPrintFeedback" href="pdf-blinded-feedbacks.php?id=<?=$id;?>" target="_blank" class="btn bg-gradient-warning ml-auto <?= $isFinalized ? '' : 'd-none'; ?>"><i class="fas fa-print"></i> Print Feedback Report</a>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- ============================= VIEW MODE ============================= -->
    <section class="content" id="viewMode">
        <div class="container-fluid">

            <!-- Progress timeline -->
            <div class="card mb-3">
                <div class="card-body">
                    <!-- View-mode progress now uses the SAME standardized stepper as the
                         wizards (single global design). Read-only: state classes only
                         (done/active/pending), no navigation. -->
                    <div class="bs-wizard">
                        <div class="wizard-step done"><span class="num">1</span>Registered</div>
                        <?php if($binfo[4]!=0){ ?>
                        <div class="wizard-step <?= $stSlides?'done':($curStage=='slides'?'active':'');?>"><span class="num">2</span>Slides Entered</div>
                        <div class="wizard-step <?= $stReceived?'done':($curStage=='received'?'active':'');?>"><span class="num">3</span>Received</div>
                        <div class="wizard-step <?= $stC1?'done':($curStage=='c1'?'active':'');?>"><span class="num">4</span>Controller 1</div>
                        <div class="wizard-step <?= $c2BadgeClass === 'done' ? 'done' : ($curStage == 'c2' || $c2BadgeClass === 'active' ? 'active' : ''); ?>" data-step="5" data-status="<?= $c2Status; ?>" role="listitem" tabindex="0" aria-label="Controller 2"><span class="num">5</span>Controller 2</div>
                        <div class="wizard-step <?= $stFeedback?'done':($curStage=='feedback'?'active':'');?>"><span class="num">6</span>Feedback</div>
                        <div class="wizard-step <?= $stAction?'done':($curStage=='action'?'active':'');?>"><span class="num">7</span>Action</div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <?php if($binfo[4]!=0){ ?>

            <!-- District Results (visible to district & admin, same as original gate) -->
            <?php if($_SESSION["roleID"]==3 || $_SESSION["roleID"]==1 || $_SESSION["roleID"]==2){ ?>
            <div class="card card-outline card-primary">
                <div class="card-header"><h3 class="card-title">District Results</h3></div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead><tr class="text-center">
                            <th>B/S No</th><th>Collection Date</th><th>Microscopy</th><th>Stage</th><th>Parasite</th><th>WBC</th><th>Parasite Density</th>
                        </tr></thead>
                        <tbody>
                        <?php
                            $vdr = $conn->query("SELECT slide_no, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density FROM blinded_results WHERE blinded_id='$id'");
                            while($r=$vdr->fetch_row()){
                                echo "<tr><td>$r[0]</td><td class='text-center'>$r[1]</td><td>$r[2]</td><td class='text-center'>$r[3]</td><td class='text-center'>$r[4]</td><td class='text-center'>$r[5]</td><td class='text-center'>$r[6]</td></tr>";
                            }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php } ?>

            <!-- RCDC Controller 1 Results -->
            <div class="card card-outline card-primary">
                <div class="card-header"><h3 class="card-title">RCDC Controller 1 Results</h3></div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead><tr class="text-center">
                            <th>B/S No</th><th>Collection Date</th><th>Recheck Date</th><th>Microscopy</th><th>Stage</th><th>Parasite</th><th>WBC</th><th>Parasite Density</th>
                        </tr></thead>
                        <tbody>
                        <?php
                            $vc1 = $conn->query("SELECT slide_no, collection_date, c1_chkdate, c1_microscopy, c1_stage, c1_parasite_count, c1_wbc_count, c1_density FROM blinded_results WHERE blinded_id='$id' AND slide_status='Intact'");
                            while($r=$vc1->fetch_row()){
                                echo "<tr><td>$r[0]</td><td class='text-center'>$r[1]</td><td class='text-center'>$r[2]</td><td>$r[3]</td><td class='text-center'>$r[4]</td><td class='text-center'>$r[5]</td><td class='text-center'>$r[6]</td><td class='text-center'>$r[7]</td></tr>";
                            }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if($fbExists){ ?>
            <!-- Comparison / Feedback table -->
            <div class="card card-outline card-primary">
                <div class="card-header"><h3 class="card-title">Comparison &amp; Scores</h3></div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr class="text-center">
                                <th rowspan=2>B/S #</th><th rowspan=2>Collection Date</th><th rowspan=2>Slide Status</th>
                                <th colspan=3>Species</th><th colspan=2>Stages</th><th colspan=2>Parasite</th><th colspan=2>WBC</th>
                                <th rowspan=2>Density Ranges</th><th rowspan=2>Parasite Density</th>
                            </tr>
                            <tr class="text-center">
                                <th>RCDC</th><th>Lab</th><th>Chk</th><th>RCDC</th><th>Lab</th><th>RCDC</th><th>Lab</th><th>RCDC</th><th>Lab</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                            $vfb = $conn->query("SELECT result_id, slide_no, slide_status, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density, c2_microscopy, c2_stage, c2_parasite_count, c2_wbc_count, c2_density, min_range, max_range, positivity_flag FROM blinded_results WHERE blinded_id='$id'");
                            while($row = $vfb->fetch_row()){
                                $denSrc = ($row[13] !== null && $row[13] !== '' && (int)$row[13] > 0)
                                    ? (int)$row[13]
                                    : (($row[8] !== null && $row[8] !== '' && (int)$row[8] > 0) ? (int)$row[8] : null);
                                $pCountSrc = ($row[11] !== null && $row[11] !== '') ? $row[11] : $row[6];
                                $wCountSrc = ($row[12] !== null && $row[12] !== '') ? $row[12] : $row[7];
                                $dr = blCalculateDensityAndRange($pCountSrc, $wCountSrc, $denSrc, $row[14], $row[15]);
                                echo "<tr>
                                    <td>$row[1]</td><td>$row[3]</td><td>$row[2]</td>
                                    <td>$row[9]</td><td>$row[4]</td><td class='text-center'>$row[16]</td>
                                    <td>$row[10]</td><td>$row[5]</td>
                                    <td class='text-center'>$row[11]</td><td class='text-center'>$row[6]</td>
                                    <td class='text-center'>$row[12]</td><td class='text-center'>$row[7]</td>
                                    <td class='text-center'>{$dr['range_txt']}</td>
                                    <td class='text-center'>{$dr['density_txt']}</td>
                                </tr>";
                            }
                        ?>
                        </tbody>
                    </table>

                    <div class="row mt-3">
                        <div class="col-md-6">
                            <table class="table table-bordered table-sm">
                                <thead><tr class="text-center"><th width="70%">Parameter</th><th>Score</th></tr></thead>
                                <tbody>
                                    <tr><td>Sensitivity</td><td class="text-center"><?=$vScore[0];?></td></tr>
                                    <tr><td>Specificity</td><td class="text-center"><?=$vScore[1];?></td></tr>
                                    <tr><td>% Agreement (Malaria Infection Detection)</td><td class="text-center"><?=$vScore[2];?></td></tr>
                                    <tr><td>% Agreement (Parasite Species Identification)</td><td class="text-center"><?=$vScore[3];?></td></tr>
                                    <tr><td>% Agreement (Parasite Stage Identification)</td><td class="text-center"><?=$vScore[4];?></td></tr>
                                    <tr><td>% Agreement (Parasite Density Calculation)</td><td class="text-center"><?=$vScore[5];?></td></tr>
                                    <tr><td>% Agreement (Film Quality)</td><td class="text-center"><?=$vScore[11];?></td></tr>
                                    <tr><td>% Agreement (Stain Quality)</td><td class="text-center"><?=$vScore[12];?></td></tr>
                                    <tr><td><strong>Average Score</strong></td><td class="text-center"><strong><?=$vScore[6];?></strong></td></tr>
                                    <tr><td><strong>Achievement</strong></td><td class="text-center"><?=$achvIcon;?> <?=$vScore[7];?></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Findings</h6>
                            <div class="review-box mb-3"><?= ($vScore[8]!=''? $vScore[8] : '<span class="text-muted">Not yet provided.</span>');?></div>
                            <h6 class="text-primary">Recommendations</h6>
                            <div class="review-box mb-3"><?= ($vScore[9]!=''? $vScore[9] : '<span class="text-muted">Not yet provided.</span>');?></div>
                            <h6 class="text-primary">Action</h6>
                            <div class="review-box">
                                <div><?= ($vScore[10]!=''? $vScore[10] : '<span class="text-muted">Not yet provided.</span>');?></div>
                                <?php if (!empty($actionImages)) { ?>
                                <div class="mt-3 pt-2 border-top">
                                    <small class="font-weight-bold text-muted text-uppercase d-block mb-2"><i class="fas fa-images mr-1"></i> Reference Attachments (<?= count($actionImages); ?>)</small>
                                    <div class="bl-review-img-grid">
                                        <?php foreach ($actionImages as $img) {
                                            $imgCap = !empty($img['caption']) ? htmlspecialchars($img['caption'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($img['original_name'], ENT_QUOTES, 'UTF-8');
                                            $imgSize = (int)$img['file_size'] >= 1048576 ? round($img['file_size'] / 1048576, 1) . ' MB' : max(1, round($img['file_size'] / 1024)) . ' KB';
                                            $imgUrl = htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <div class="bl-review-img-card">
                                            <img src="<?= $imgUrl ?>" alt="<?= $imgCap ?>" class="bl-review-thumb actionImgOpen" 
                                                 data-id="<?= (int)$img['image_id'] ?>" data-url="<?= $imgUrl ?>" data-cap="<?= $imgCap ?>" data-size="<?= $imgSize ?>" 
                                                 title="Click to enlarge">
                                            <div class="bl-review-img-meta">
                                                <div class="bl-review-img-cap text-truncate" title="<?= $imgCap ?>"><?= $imgCap ?></div>
                                                <div class="bl-review-img-sub"><?= $imgSize ?></div>
                                            </div>
                                        </div>
                                        <?php } ?>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>

            <?php } ?>
        </div>
    </section>
    <!-- ============================= /VIEW MODE ============================= -->

    <!-- ============================= PROCESS WIZARD ============================= -->
    <section class="content" id="processMode" style="display:none;">
        <div class="container-fluid">
            <?php
                $blrd = null;
                if (isset($_GET['edit']) && $_GET['edit']=='blr') {
                    $result_id = isset($_GET['rid']) ? $_GET['rid'] : '';
                    $blrdquery = $conn->query("SELECT slide_no, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density FROM blinded_results WHERE blinded_id = '$id' AND result_id = '$result_id'");
                    $blrd = $blrdquery ->fetch_row();
                }
                if ($blrd) {   // edit mode only when the requested row actually exists
                    $bldrAction = 'update';
                    $micrslt = "<option value ='$blrd[2]'>$blrd[2]</option>";
                    $parasrslt = "<option value ='$blrd[3]'>$blrd[3]</option>";
                    $readonlyBS = 'readonly';
                }else {
                    $blrd = array('','','','','','','','');
                    $bldrAction = '';
                    $micrslt   = "<option value =''>Select Microscopy Result</option>";
                    $parasrslt = "<option value =''>Select Stage</option>";
                    $result_id = $readonlyBS = '';
                }
            ?>

            <div class="card card-primary card-outline">
                <div class="card-header"><h3 class="card-title">Blinded Rechecking Wizard</h3></div>
                <div class="card-body">

                    <!-- Stepper nav (built by JS from the visible panes) -->
                    <div class="bs-wizard" id="procWizardNav"></div>

                    <div id="procPanes">

                        <!-- ===== PANE: MP SLIDE INFO (header) ===== -->
                        <div class="p-pane" id="pane-info" data-title="MP Slide Info">
                            <form class="form-horizontal wiz-form" id="blindedInfo" autocomplete="off" method="Post" action="iu-blinded.php">

                                <input type='hidden' name='action' id='action' value='<?=$action;?>'/>
                                <input type='hidden' name='submit' id='submit' value='blindedInfo' />
                                <input type="hidden" name="biid" id="biid" value="<?=$binfo[0];?>" />

                                <div class="row">
                                    <?php
                                    if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) {
                                    ?>
                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm">Dzongkhag:</label>
                                        <select class="form-control select2" name="dzongkhag" id="dzongk" style="width:100%;">
                                            <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                                            <?php if ($dzongkhag_code != '' && $dzongkhag_code != $dzongkhag[0]) { ?>
                                            <option value='<?=$dzongkhag_code;?>'><?=$dzongkhag_name;?></option>
                                            <?php } ?>
                                        </select>
                                    </div>

                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm">Health Facility:</label>
                                        <div id="facilityDiv">
                                            <select class="form-control" name="facility" id="facility">
                                                <option value="<?=$binfo[1];?>"><?=$facility[0];?></option>
                                                <?php if ($facilityId != '' && $facilityId != $binfo[1]) { ?>
                                                <option value="<?=$facilityId;?>"><?=$center_name;?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php
                                    }else{
                                        ?>

                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm">Dzongkhag:</label>
                                        <select class="form-control select2" name="dzongkhag" id="dzongkhag" style="width:100%;">
                                        <option value="<?=$dzongkhag[0];?>"><?=$dzongkhag[1];?></option>
                                            <?php
                                                $result = $commondb->query($dzsql);
                                                while($row = $result->fetch_row()) {
                                                    echo("<option value='$row[0]'>$row[1]</option>");
                                                }
                                            ?>
                                        </select>
                                    </div>

                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm">Health Facility:</label>
                                        <div id="facilityDiv">
                                            <select class="form-control" name="facility" id="facility">
                                                <option value="<?=$binfo[1];?>"><?=$facility[0];?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <?php
                                     }
                                    ?>
                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm">Month:</label>
                                        <select class="form-control" name="month" id="month" <?php if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) {?> onchange="validateTime(this.value, year.value);" <?php } ?>>
                                            <?=$monthInfo;?>
                                            <option value="1">January</option>
                                            <option value="2">February</option>
                                            <option value="3">March</option>
                                            <option value="4">April</option>
                                            <option value="5">May</option>
                                            <option value="6">June</option>
                                            <option value="7">July</option>
                                            <option value="8">August</option>
                                            <option value="9">September</option>
                                            <option value="10">October</option>
                                            <option value="11">November</option>
                                            <option value="12">December</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm">Year:</label>
                                        <select class="form-control" name="year" id="year" <?php if ($_SESSION["roleID"] != 1 && $_SESSION["roleID"] != 2) {?> onchange="validateTime(month.value, this.value);" <?php } ?> >
                                            <?=$yearInfo;?>
                                            <?php
                                            for ($i=date("Y"); $i >= 2010; $i--) {
                                                echo "<option value='$i'>$i</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>

                                </div>

                                <div class="row">

                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm" for = "totalMicroscopy">Total Microscopy Screened<br>(PACD+RACD+PCD):</label>
                                        <input type="number" name="totalMicroscopy" id="totalMicroscopy" class="form-control text-center" min="0" value="<?=$binfo[4];?>" required onchange="reqBlinded(this.value);calTotalMalaria(this.value,totalRDT.value);"/>
                                    </div>

                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm" for = "totalRDT">Total RDT Screened<br>(PACD+RACD+PCD):</label>
                                        <input type="number" name="totalRDT" id="totalRDT" class="form-control text-center" min="0" value="<?=$binfo[5];?>" required onchange="calTotalMalaria(totalMicroscopy.value,this.value);"/>
                                    </div>
                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm" for = "totalMalaria">Total Malaria Screened:</label>
                                        <input type="number" name="totalMalaria" id="totalMalaria" class="form-control text-center" min="0" required value="<?=$binfo[6];?>" readonly/>
                                    </div>
                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm" for="dateship"><?=$shipment_date;?>:</label>
                                        <div class="input-group date" id="dateship" data-target-input="nearest">
                                            <input type="text" class="form-control datetimepicker-input text-center" data-target="#dateship" name="dateship"  placeholder="YYYY-MM-DD" value="<?=$binfo[10];?>" />
                                            <div class="input-group-append" data-target="#dateship" data-toggle="datetimepicker">
                                                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                                <div class="row">

                                     <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm">Positive Slides Selected:</label>
                                        <input type="number" name="pslides" id="pslides" class="form-control text-center" min="0"  value="<?=$binfo[7];?>" onchange="calTotalSlides(this.value,nslides.value);chkTotalMicroscopy(this.value,nslides.value,totalMicroscopy.value);" <?=$readonlySlide;?> />
                                    </div>

                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm">Negative Slides Selected:</label>
                                        <input type="number" name="nslides" id="nslides" class="form-control text-center" min="0" value="<?=$binfo[8];?>" onchange="calTotalSlides(pslides.value,this.value);chkTotalMicroscopy(pslides.value,this.value,totalMicroscopy.value);" <?=$readonlySlide;?> />
                                    </div>

                                    <div class="form-group col-12 col-md-6 col-lg-3">
                                        <label class="col-form-label-sm" for = "tslides">Total Blinded Slides Selected:</label>
                                        <input type="number" name="tslides" id="tslides" class="form-control text-center" min="0" required value="<?=$binfo[9];?>" readonly/>
                                    </div>

                                </div>
                                <?php
                                $labRsltCnt = ($conn->query("SELECT lab_microscopy FROM blinded_results WHERE lab_microscopy !='' AND blinded_id = $id"));
                                $labRsltCnt = $labRsltCnt->num_rows;
                                if ($labRsltCnt == 0 || $_SESSION["roleID"]==1 || $_SESSION["roleID"]==2) {
                                ?>

                                <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 wiz-footer" id="submitInfo">
                                    <div>
                                        <?php if (!$isRCDC) { // Admin/RCDC: wizard footer owns Cancel ?>
                                        <a href="<?=$cancel;?>" class="btn bg-gradient-secondary">Cancel</a>
                                        <?php } ?>
                                    </div>
                                    <div class="nav-actions">
                                        <button type="submit" class="btn bg-gradient-success">Submit</button>
                                        <?php
                                        if($labRsltCnt == 0) echo $deletebi;
                                        ?>
                                    </div>
                                </div>
                                <?php
                                }
                                ?>

                            </form>
                        </div>
                        <!-- ===== /PANE: MP SLIDE INFO ===== -->

                        <?php if($binfo[4]!=0){ ?>

                        <!-- ===== PANE: DISTRICT RESULTS ===== -->
                        <div class="p-pane" id="pane-district" data-title="District Results">
                            <?php
                                $slideStsCnt = ($conn->query("SELECT receive_date FROM blinded_informations WHERE receive_date IS NULL AND blinded_id = $id"));
                                $slideStsCnt = $slideStsCnt->num_rows;
                                $totalSlides = ($conn->query("SELECT COUNT(result_id) FROM blinded_results WHERE blinded_id = $id"));
                                $totalSlides = $totalSlides->fetch_row();

                                $deleteRslt = "<a href='delete.php?type=rslt&id=$result_id&biid=$id' class='btn bg-gradient-danger js-confirm-delete'>Delete</a>";

                            if ($_SESSION["roleID"] == 3) {
                                /* ===== District: every slide editable in one table, saved together.
                                       Rows can be edited or added, never deleted individually. ===== */
                            ?>
                            <div class="card card-outline card-primary">
                                <div class="card-header"><h3 class="card-title">MP SLIDE Information Entry Form</h3></div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="text-primary col-form-label mb-0">Add/Update MP Slide Lab Results</label>
                                        <button type="button"  onclick="addEditSlideRow();"></button>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover">
                                            <thead>
                                                <tr class="text-center">
                                                    <th>B/S No.</th><th>Date of Collection</th><th>Microscopy Result</th><th>Parasite Stage</th><th>Parasite Count</th><th>WBC Count</th><th>Parasite Density</th>
                                                </tr>
                                            </thead>
                                            <tbody id="editSlideRows">
                                            <?php
                                                $edtq = $conn->query("SELECT result_id, slide_no, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density FROM blinded_results WHERE blinded_id = '$id'");
                                                while($er = $edtq->fetch_row()){
                                                    $selMic   = ($er[3] !== '' && $er[3] !== null) ? "<option value='$er[3]'>$er[3]</option>" : "<option value=''>Select Microscopy Result</option>";
                                                    $selStage = ($er[4] !== '' && $er[4] !== null) ? "<option value='$er[4]'>$er[4]</option>" : "<option value=''>Select Stage</option>";
                                                    echo "<tr class='slide-row'>
                                                        <td><input type='hidden' class='s-rid' value='$er[0]'/><input type='text' class='form-control s-bsno' value='".htmlspecialchars($er[1], ENT_QUOTES)."' readonly/></td>
                                                        <td><div class='input-group date s-doc-wrap' id='dsDoc_e".(int)$er[0]."' data-target-input='nearest'><input type='text' class='form-control text-center datetimepicker-input s-doc' data-target='#dsDoc_e".(int)$er[0]."' value='$er[2]' placeholder='YYYY-MM-DD'/><div class='input-group-append' data-target='#dsDoc_e".(int)$er[0]."' data-toggle='datetimepicker'><div class='input-group-text'><i class='fa fa-calendar'></i></div></div></div></td>
                                                        <td><select class='form-control s-mic' onchange='rowReqResult(this);'>$selMic".microscopyResults()."</select></td>
                                                        <td><select class='form-control s-stage'>$selStage".parasiteStages()."</select></td>
                                                        <td><input type='number' class='form-control text-center s-pcount' value='$er[5]'/></td>
                                                        <td><input type='number' class='form-control text-center s-wbc' value='$er[6]'/></td>
                                                        <td><input type='number' class='form-control text-center s-density' value='$er[7]' readonly/></td>
                                                    </tr>";
                                                }
                                            ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <small class="text-muted">Existing rows can be edited and new rows added. Parasite Density updates in real time and is stored on save; for a negative (Nmps) result, stage &amp; counts are cleared automatically. All rows are saved together.</small>

                                    <!-- Prototype row cloned by JS for new slides (rid empty = insert) -->
                                    <template id="editSlideRowTpl">
                                        <tr class="slide-row">
                                            <td><input type="hidden" class="s-rid" value=""/><input type="text" class="form-control s-bsno" placeholder="B/S No."/></td>
                                            <td>
                                                <div class="input-group date s-doc-wrap" id="__DOCID__" data-target-input="nearest">
                                                    <input type="text" class="form-control text-center datetimepicker-input s-doc" data-target="#__DOCID__" placeholder="YYYY-MM-DD"/>
                                                    <div class="input-group-append" data-target="#__DOCID__" data-toggle="datetimepicker">
                                                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><select class="form-control s-mic" onchange="rowReqResult(this);"><option value="">Select Microscopy Result</option><?= microscopyResults(); ?></select></td>
                                            <td><select class="form-control s-stage"><option value="">Select Stage</option><?= parasiteStages(); ?></select></td>
                                            <td><input type="number" class="form-control text-center s-pcount"/></td>
                                            <td><input type="number" class="form-control text-center s-wbc"/></td>
                                            <td><input type="number" class="form-control text-center s-density" readonly/></td>
                                        </tr>
                                    </template>
                                </div>
                            </div>
                            <?php
                            } else if ($isRCDC) {
                                /* ===== RCDC (roles 1/2): one row per selected slide, saved together
                                       in a single transaction (iu-blinded.php 'labresultsBulk').
                                       Row count is bounded by blinded_informations.total_slides. ===== */
                                $rsTotal    = (int)$binfo[9];
                                $rsReceived = ($dorec !== null && $dorec !== '');
                                $rsRows = array(); $rsSaved = 0;
                                $rsq = $conn->prepare("SELECT result_id, slide_no, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density, c1_microscopy FROM blinded_results WHERE blinded_id = ? ORDER BY result_id");
                                $rsBid = (int)$binfo[0];
                                $rsq->bind_param('i', $rsBid);
                                $rsq->execute();
                                $rsRes = $rsq->get_result();
                                while ($rr = $rsRes->fetch_row()) {
                                    $rsRows[] = $rr;
                                    $rsPos = ($rr[3] !== 'Nmps');
                                    if ((string)$rr[1] !== '' && (string)$rr[2] !== '' && (string)$rr[3] !== ''
                                        && (!$rsPos || ((string)$rr[4] !== '' && $rr[5] !== null && $rr[6] !== null))) {
                                        $rsSaved++;
                                    }
                                }
                                $rsq->close();
                                // Mark the stored value selected inside the existing helper's option list.
                                $rsOpt = function ($html, $val) {
                                    if ($val === null || $val === '') { return $html; }
                                    $out = str_replace("value ='" . $val . "'", "value ='" . $val . "' selected", $html);
                                    if ($out === $html) {
                                        $v = htmlspecialchars($val, ENT_QUOTES);
                                        $out = "<option value='$v' selected>$v</option>" . $html;
                                    }
                                    return $out;
                                };
                            ?>
                            <div class="card card-outline card-primary" id="rcdcSlideCard" data-total="<?=$rsTotal;?>" data-saved="<?=$rsSaved;?>" data-received="<?=$rsReceived ? 1 : 0;?>">
                                <div class="card-header"><h3 class="card-title">MP SLIDE Information Entry Form</h3></div>
                                <div class="card-body">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                                        <div class="mb-1">
                                            <span class="mr-3">Total Blinded Slides Selected: <strong><?=$rsTotal;?></strong></span>
                                            <span>Slides Entered: <strong id="rcdcSlidesEntered"><?=$rsSaved;?></strong> / <strong><?=$rsTotal;?></strong></span>
                                        </div>
                                        <button type="button"  id="rcdcAddSlideBtn" onclick="rcdcAddSlideRow(true);"></button>
                                    </div>
                                    <?php if ($rsReceived) { ?>
                                    <div class="alert alert-light border py-2 small mb-2">
                                        The shipment has been received: existing slides can still be corrected, but new slides cannot be added.
                                        Slides that already have Controller 1 results are locked.
                                    </div>
                                    <?php } ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover mb-2">
                                            <thead>
                                                <tr class="text-center">
                                                    <th style="width:40px;">S.No</th><th>B/S No.</th><th>Date of Collection</th><th>Microscopy Result</th><th>Parasite Stage</th><th>Parasite Count</th><th>WBC Count</th><th> Parasite Density</th><th style="width:70px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="rcdcSlideRows">
                                            <?php foreach ($rsRows as $rr) {
                                                $rLocked = ($rr[8] !== null && $rr[8] !== '');
                                                $dis     = $rLocked ? ' disabled' : '';
                                                $docId   = 'rsDocWrap_e' . (int)$rr[0];
                                            ?>
                                                <tr class="rs-row <?= $rLocked ? 'slide-row-locked' : 'slide-row'; ?>"<?= $rLocked ? ' data-locked="1"' : ''; ?>>
                                                    <td class="text-center align-middle rs-num"></td>
                                                    <td><input type="hidden" class="s-rid" value="<?=(int)$rr[0];?>"/><input type="text" class="form-control s-bsno" value="<?=htmlspecialchars((string)$rr[1], ENT_QUOTES);?>"<?=$dis;?>/></td>
                                                    <td>
                                                        <div class="input-group date s-doc-wrap" id="<?=$docId;?>" data-target-input="nearest">
                                                            <input type="text" class="form-control text-center datetimepicker-input s-doc" data-target="#<?=$docId;?>" value="<?=htmlspecialchars((string)$rr[2], ENT_QUOTES);?>" placeholder="YYYY-MM-DD"<?=$dis;?>/>
                                                            <div class="input-group-append" data-target="#<?=$docId;?>" data-toggle="datetimepicker">
                                                                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><select class="form-control s-mic" onchange="rowReqResult(this);"<?=$dis;?>><option value="">Select Microscopy Result</option><?= $rsOpt(microscopyResults(), $rr[3]); ?></select></td>
                                                    <td><select class="form-control s-stage"<?=$dis;?>><option value="">Select Stage</option><?= $rsOpt(parasiteStages(), $rr[4]); ?></select></td>
                                                    <td><input type="number" min="0" class="form-control text-center s-pcount" value="<?=htmlspecialchars((string)$rr[5], ENT_QUOTES);?>"<?=$dis;?>/></td>
                                                    <td><input type="number" min="0" class="form-control text-center s-wbc" value="<?=htmlspecialchars((string)$rr[6], ENT_QUOTES);?>"<?=$dis;?>/></td>
                                                    <td><input type="number" class="form-control text-center s-density" value="<?=htmlspecialchars((string)$rr[7], ENT_QUOTES);?>" readonly/></td>
                                                    <td class="text-center align-middle">
                                                        <?php if ($rLocked) { ?>
                                                        <span class="text-muted" title="Controller 1 results exist for this slide"><i class="fa fa-lock"></i></span>
                                                        <?php } else { ?>
                                                        <span class="badge badge-light border" title="Saved slide: edit the fields and click Save All Slides">Saved</span>
                                                        <?php } ?>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <small class="text-muted">Enter one row per selected slide, then click <strong>Save All Slides</strong>. All rows are saved together; Parasite Density is calculated automatically. For a negative (Nmps) result, stage &amp; counts are cleared for that row only.</small>

                                    <!-- Prototype row for unsaved slides (result_id empty = insert). __DOCID__ is replaced per row. -->
                                    <template id="rcdcSlideRowTpl">
                                        <tr class="rs-row slide-row">
                                            <td class="text-center align-middle rs-num"></td>
                                            <td><input type="hidden" class="s-rid" value=""/><input type="text" class="form-control s-bsno" placeholder="B/S No."/></td>
                                            <td>
                                                <div class="input-group date s-doc-wrap" id="__DOCID__" data-target-input="nearest">
                                                    <input type="text" class="form-control text-center datetimepicker-input s-doc" data-target="#__DOCID__" placeholder="YYYY-MM-DD"/>
                                                    <div class="input-group-append" data-target="#__DOCID__" data-toggle="datetimepicker">
                                                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><select class="form-control s-mic" onchange="rowReqResult(this);"><option value="">Select Microscopy Result</option><?= microscopyResults(); ?></select></td>
                                            <td><select class="form-control s-stage"><option value="">Select Stage</option><?= parasiteStages(); ?></select></td>
                                            <td><input type="number" min="0" class="form-control text-center s-pcount"/></td>
                                            <td><input type="number" min="0" class="form-control text-center s-wbc"/></td>
                                            <td><input type="number" class="form-control text-center s-density" readonly/></td>
                                            <td class="text-center align-middle"><button type="button" class="btn btn-sm btn-outline-danger rs-remove" title="Remove this unsaved row" onclick="rcdcRemoveSlideRow(this);"><i class="fa fa-trash"></i></button></td>
                                        </tr>
                                    </template>
                                </div>
                            </div>
                            <?php
                            } else {
                            if ($slideStsCnt > 0 || (isset($_GET['edit']) && $_GET['edit']=='blr') || $isRCDC ) {
                            ?>
                            <div class="card card-outline card-primary">
                                <div class="card-header"><h3 class="card-title">MP SLIDE Information Entry Form</h3></div>
                                <div class="card-body">
                                    <div class="form-group row">
                                        <label class="col-sm-12 text-primary col-form-label">Add/Update MP Slide Lab Result</label>
                                    </div>
                                    <form class="form-horizontal" id="labResulrs" autocomplete="off" method="Post" action="iu-blinded.php">
                                        <input type='hidden' name='action' id='action' value='<?=$bldrAction;?>'/>
                                        <input type='hidden' name='submit' id='submit' value='labresults'/>
                                        <input type="hidden" name="biid" id="biid" value="<?=$binfo[0];?>"/>
                                        <input type="hidden" name="rid" id="rid" value="<?=$result_id;?>"/>

                                        <div class="row">
                                            <div class="form-group col-12 col-md-6 col-lg-3">
                                                <label class="col-form-label-sm">B/S No.:</label>
                                                <input type="text" name="bsno" id="bsno" class="form-control"  value="<?=$blrd[0];?>" required <?=$readonlyBS;?>/>
                                            </div>
                                            <div class="form-group col-12 col-md-6 col-lg-3">
                                                <label class="col-form-label-sm">Date of Collection:</label>
                                                <div class="input-group date" id="doc" data-target-input="nearest">
                                                    <input type="text" class="form-control datetimepicker-input text-center" data-target="#doc" name="doc"  placeholder="YYYY-MM-DD" value="<?=$blrd[1];?>" required />
                                                    <div class="input-group-append" data-target="#doc" data-toggle="datetimepicker">
                                                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="form-group col-12 col-md-6 col-lg-3">
                                                <label class="col-form-label-sm">Microscopy Result</label>
                                                <select class="form-control" name="microscopy" id="microscopy" required onchange="reqResult(this.value);">
                                                    <?=$micrslt;?>
                                                    <?= microscopyResults(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-12 col-md-6 col-lg-3">
                                                <label class="col-form-label-sm">Parasite Stage</label>
                                                <select class="form-control" name="pstage" id="pstage">
                                                    <?=$parasrslt;?>
                                                    <?= parasiteStages(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-12 col-md-6 col-lg-3">
                                                <label class="col-form-label-sm">No. Parasite Count:</label>
                                                <input type="number" name="pcount" id="pcount" class="form-control text-center"  value="<?=$blrd[4];?>"/>
                                            </div>
                                            <div class="form-group col-12 col-md-6 col-lg-3">
                                                <label class="col-form-label-sm">No. WBC Count:</label>
                                                <input type="number" name="wbcount" id="wbcount" class="form-control text-center"  value="<?=$blrd[5];?>"/>
                                            </div>
                                            <div class="form-group col-12 col-md-6 col-lg-3">
                                                <label class="col-form-label-sm">Parasite Density:</label>
                                                <input type="number" name="density" id="density" class="form-control text-center"  value="<?=$blrd[6];?>" readonly/>
                                            </div>
                                        </div>

                                        <div class="row mt-3">
                                            <div class="form-group col-sm-12">
                                                <button type="submit" class="btn bg-gradient-success">Submit</button>
                                                <?php
                                                if (isset($_GET['edit']) && $_GET['edit'] == 'blr') {
                                                    echo $deleteRslt;
                                                }
                                                ?>
                                                <?php if (!$isRCDC) { // Admin/RCDC: wizard footer owns Cancel ?>
                                                <a href="<?=$cancel;?>" class="btn bg-gradient-secondary float-right">Cancel</a>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <?php
                                }
                            if ($action==2 && $binfoqry->num_rows>0) {
                                $blrquery = $conn->query("SELECT result_id, slide_no, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density FROM blinded_results WHERE blinded_id = '$id'");
                            ?>
                            <div class="card card-outline card-primary">
                                <div class="card-header"><h3 class="card-title">District Results Table</h3></div>
                                <div class="card-body">
                                    <table id="table" class="table table-bordered table-hover">
                                        <thead>
                                            <tr class="text-center">
                                                <th>Edit</th><th>B/S No</th><th>Date of Collection</th><th>Microscopy Result</th><th>Parasite Stage</th><th>Parasite Count</th><th>WBC Count</th><th>Parasite Density</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        //Hide Result from Controller 1
                                        if (($blrquery->num_rows>0) && ($_SESSION["roleID"]==3 || $isRCDC)) {
                                            while($blr=$blrquery->fetch_row()){
                                                echo("<tr>
                                                    <td><a href='$cancel&edit=blr&rid=$blr[0]'><i class='fa fa-edit'></i></a></td>
                                                    <td>$blr[1]</td>
                                                    <td class='text-center'>$blr[2]</td>
                                                    <td>$blr[3]</td>
                                                    <td class='text-center'>$blr[4]</td>
                                                    <td class='text-center'>$blr[5]</td>
                                                    <td class='text-center'>$blr[6]</td>
                                                    <td class='text-center'>$blr[7]</td>
                                                </tr>");
                                            }
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php
                            }
                            } // end district / RCDC-admin split
                            ?>
                        </div>
                        <!-- ===== /PANE: DISTRICT RESULTS ===== -->

                        <?php if($isRCDC){ ?>

                        <!-- ===== PANE: SHIPMENT RECEIVE ===== -->
                        <div class="p-pane" id="pane-shipment" data-title="Shipment Receive">
                            <div class="card card-outline card-primary">
                                <div class="card-header"><h3 class="card-title">Shipment Receive Information</h3></div>
                                <div class="card-body">
                                    <form class="form-horizontal" id="bsStatus" autocomplete="off" method="Post" action="iu-blinded.php">
                                        <input type='hidden' name='submit' id='submit' value='bsStatus'/>
                                        <input type="hidden" name="biid" id="biid" value="<?=$binfo[0];?>"/>
                                        <input type='hidden' name='action' id='action' value=''/>

                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Health Facility:</label>
                                                <input type="text" class="form-control" value="<?=$facility[0];?>" readonly />
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Month:</label>
                                                <input type="text" class="text-center form-control" value="<?=$biMonth;?>" readonly />
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Year:</label>
                                                <input type="text" class="text-center form-control" value="<?=$binfo[3];?>" readonly />
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Date of Shipment:</label>
                                                <input type="text" class="text-center form-control" value="<?=$binfo[10];?>" readonly />
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Date of Receipt:</label>
                                                <div class="input-group date" id="dorec" data-target-input="nearest">
                                                    <input type="text" class="form-control datetimepicker-input text-center" data-target="#dorec" name="dorec"  value="<?=$dorec;?>" placeholder="YYYY-MM-DD" required />
                                                    <div class="input-group-append" data-target="#dorec" data-toggle="datetimepicker">
                                                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <table class="table table-bordered table-hover">
                                                <thead>
                                                    <tr class="text-center"><th>Slide</th><th>Collection Date</th><th>Status</th><th>Remarks</th></tr>
                                                </thead>
                                                <tbody>
                                                <?php
                                                    $slide = $conn->query("SELECT result_id, slide_no, slide_status, collection_date, remarks FROM blinded_results WHERE blinded_id=$id");
                                                    while($row = $slide->fetch_row()) {
                                                ?>
                                                    <tr>
                                                    <td class="text-center">
                                                        <input type="hidden" name="resultID[]" value="<?=$row[0];?>">
                                                        <input type="text" name="slideNo[]" id="slideNo" class="form-control" value="<?=$row[1];?>">
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="input-group date docc-wrap" id="docc_<?=(int)$row[0];?>" data-target-input="nearest">
                                                            <input type="text" class="form-control datetimepicker-input text-center" data-target="#docc_<?=(int)$row[0];?>" name="docc[]"  placeholder="YYYY-MM-DD" value="<?=$row[3];?>" required />
                                                            <div class="input-group-append" data-target="#docc_<?=(int)$row[0];?>" data-toggle="datetimepicker">
                                                                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <select class="form-control" name="status[]" id="status[]" required>
                                                            <option value="<?=$row[2];?>"><?=$row[2];?></option>
                                                            <?=slideStatus();?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="text" name="remarks[]" class="form-control" value="<?=$row[4];?>" placeholder="Remarks">
                                                    </td>
                                                    </tr>
                                                <?php } ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php
                                         $labRsltCnt = ($conn->query("SELECT c1_microscopy FROM blinded_results WHERE c1_microscopy!='' AND blinded_id = $id"));
                                         $labRsltCnt = $labRsltCnt->num_rows;
                                         /* Undo the receipt: clears slide_status / remarks and the receive_date,
                                            putting the record back at District Results. delete.php refuses this
                                            while any Controller 1 result exists, so the button is withheld in
                                            that case instead of being offered and then refused. */
                                         $deleteRcpt = "<a href='delete.php?type=rcpt&id=$id&biid=$id' class='btn btn-outline-danger js-confirm-delete'><i class='fa fa-undo'></i> Undo Shipment Receive</a>";
                                        if ($_SESSION["roleID"]==1 || $_SESSION["roleID"]==2) {
                                        ?>
                                        <!-- Submission is wizard-controlled (footer Submit); the form keeps its
                                             single submit button for the centralised save but the strip stays hidden. -->
                                        <div class="card-footer d-none">
                                            <button type="submit" class="btn bg-gradient-success">Submit</button>
                                        </div>
                                        <?php if ($stReceived && $labRsltCnt == 0) { ?>
                                        <div class="card-footer bg-transparent border-top-0 pt-0">
                                            <?= $deleteRcpt; ?>
                                            <small class="form-text text-muted mt-1">Returns this record to District Results. Available until Controller 1 is entered.</small>
                                        </div>
                                        <?php } elseif ($stReceived && $labRsltCnt > 0) { ?>
                                        <div class="card-footer bg-transparent border-top-0 pt-0">
                                            <small class="form-text text-muted mb-0"><i class="fa fa-lock"></i> Shipment receipt can no longer be undone &mdash; clear Controller 1 first.</small>
                                        </div>
                                        <?php } ?>
                                        <?php
                                        }
                                        ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- ===== /PANE: SHIPMENT RECEIVE ===== -->

                        <!-- ===== PANE: CONTROLLER 1 ===== -->
                        <div class="p-pane" id="pane-c1" data-title="Controller 1">
                            <?php
                            $blrcd = null;
                            if (isset($_GET['edit']) && $_GET['edit']=='blrc') {
                                $result_id = isset($_GET['rid']) ? $_GET['rid'] : '';
                                // index 20 (slide_received_date) appended at the END so every
                                // existing $blrcd[0..19] index keeps its current meaning.
                                $blrcdquery = $conn->query("SELECT slide_no, collection_date, c1_microscopy, c1_stage, c1_parasite_count, c1_wbc_count, c1_density, size_thick_smear, size_thin_smear, shape_thin_smear, smear_spacing, eveness_thin_smear, blood_film, smear_intact, slide_labeling, background, stain_blood_element, cleanliness, stain_deposit, c1_chkdate, slide_received_date FROM blinded_results WHERE blinded_id = '$id' AND result_id = '".(int)$result_id."' AND slide_status = 'Intact'");
                                $blrcd = $blrcdquery ->fetch_row();
                            }
                            if ($blrcd) {   // render the edit form only when the requested row exists
                                $bldrAction = 'update';
                                $micrslt = "<option value ='$blrcd[2]'>$blrcd[2]</option>";
                                $parasrslt = "<option value ='$blrcd[3]'>$blrcd[3]</option>";
                            ?>
                            <div class="card card-outline card-primary">
                                <div class="card-header"><h3 class="card-title">RCDC Controller 1 Result &mdash; Review &amp; Update</h3></div>
                                <div class="card-body">
                                    <form class="form-horizontal" id="c1Resulrs" autocomplete="off" method="Post" action="iu-blinded.php" data-wiz-standalone>
                                        <input type='hidden' name='action' id='action' value='<?=$bldrAction;?>'>
                                        <input type='hidden' name='submit' id='submit' value='c1Resulrs'>
                                        <input type="hidden" name="biid" id="biid" value="<?=$binfo[0];?>">
                                        <input type="hidden" name="rid" id="rid" value="<?=$result_id;?>">

                                        <?php
                                        // Month shown read-only: derived from the slide received date when
                                        // present, otherwise from the date of rechecking; falls back to the
                                        // submission's own month. Kept display-only (no backend change) —
                                        // it maps to the existing blinded_informations.month.
                                        $c1MonthSrc = ($blrcd[20] != '' && $blrcd[20] != '0000-00-00') ? $blrcd[20]
                                                    : (($blrcd[19] != '' && $blrcd[19] != '0000-00-00') ? $blrcd[19] : '');
                                        $c1MonthTxt = ($c1MonthSrc != '') ? date('F', strtotime($c1MonthSrc)) : showMonth($binfo[2]);
                                        ?>
                                        <!-- Top information section (read-only context for the controller) -->
                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Health Facility:</label>
                                                <input type="text" class="form-control" value="<?=$facility[0];?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Month: <small class="text-muted">(auto)</small></label>
                                                <input type="text" id="c1Month" class="text-center form-control" value="<?=$c1MonthTxt;?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Year:</label>
                                                <input type="text" class="text-center form-control" value="<?=$binfo[3];?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Total Blinded Slides:</label>
                                                <input type="text" class="text-center form-control" value="<?=$binfo[9];?>" readonly>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-12 col-form-label">Microscopy Results</label>
                                        </div>
                                        <div class="row">
                                            <div class="form-group col-sm-4">
                                                <label class="col-form-label-sm">B/S No.:</label>
                                                <input type="text" name="bsno" id="bsno" class="form-control"  value="<?=$blrcd[0];?>" disabled >
                                            </div>
                                            <div class="form-group col-sm-4">
                                                <label class="col-form-label-sm">Date of Collection:</label>
                                                <input type="text" name="coldt" id="coldt" class="form-control"  value="<?=$blrcd[1];?>" disabled >
                                            </div>
                                            <div class="form-group col-sm-4">
                                                <label class="col-form-label-sm">Date of Rechecking:</label>
                                                <div class="input-group date" id="drechk" data-target-input="nearest">
                                                    <input type="text" class="form-control datetimepicker-input text-center" data-target="#drechk" name="drechk"  placeholder="YYYY-MM-DD" value="<?=$blrcd[19];?>" required />
                                                    <div class="input-group-append" data-target="#drechk" data-toggle="datetimepicker">
                                                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Microscopy Result</label>
                                                <select class="form-control" name="microscopyc" id="microscopyc" required onchange="reqResultc(this.value);">
                                                    <?= blSavedOption($blrcd[2], 'Select Microscopy Result'); ?>
                                                    <?= microscopyResults(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Parasite Stage</label>
                                                <select class="form-control" name="c1_parasite_stage" id="pstagec">
                                                    <option value="">Select Stage</option>
                                                    <?php
                                                    $stageOptions = ['T', 'S', 'G', 'TS', 'TG', 'TSG'];
                                                    $c1StageVal = $blrcd[3] ?? '';
                                                    foreach ($stageOptions as $stageOption) {
                                                        $sel = ($c1StageVal === $stageOption) ? 'selected' : '';
                                                        echo "<option value=\"$stageOption\" $sel>$stageOption</option>";
                                                    }
                                                    ?>
                                                </select>
                                                <input type="hidden" name="pstagec" id="pstagec_mirror" value="<?= htmlspecialchars((string)$c1StageVal, ENT_QUOTES); ?>">
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">No. Parasite Count:</label>
                                                <input type="number" name="pcountc" id="pcountc" class="form-control" min="0" step="1" value="<?=$blrcd[4];?>">
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">No. WBC Count:</label>
                                                <input type="number" name="wbcountc" id="wbcountc" class="form-control" min="0" step="1" value="<?=$blrcd[5];?>">
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Parasite Density:</label>
                                                <input type="number" name="densityc" id="densityc" class="form-control"  value="<?=$blrcd[6];?>" readonly>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-12 col-form-label">Quality of Blood Film</label>
                                        </div>
                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Thick Smear (Size):</label>
                                                <select class="form-control" name="size_thick_smear" id="size_thick_smear">
                                                    <?= blSavedOption($blrcd[7], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Thin Smear (Size):</label>
                                                <select class="form-control" name="size_thin_smear" id="size_thin_smear">
                                                    <?= blSavedOption($blrcd[8], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Thin Smear (Shape):</label>
                                                <select class="form-control" name="shape_thin_smear" id="shape_thin_smear">
                                                    <?= blSavedOption($blrcd[9], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Smear Spacing:</label>
                                                <select class="form-control" name="smear_spacing" id="smear_spacing">
                                                    <?= blSavedOption($blrcd[10], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Thin Smear (Evenness):</label>
                                                <select class="form-control" name="eveness_thin_smear" id="eveness_thin_smear">
                                                    <?= blSavedOption($blrcd[11], 'Select Result'); ?>
                                                    <option value="Even">Even</option>
                                                    <option value="NotEven">NotEven</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Blood Film:</label>
                                                <select class="form-control" name="blood_film" id="blood_film">
                                                    <?= blSavedOption($blrcd[12], 'Select Blood Film'); ?>
                                                    <option value="Both">Both</option>
                                                    <option value="Mono">Mono</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Smear Intact:</label>
                                                <select class="form-control" name="smear_intact" id="smear_intact">
                                                    <?= blSavedOption($blrcd[13], 'Select Result'); ?>
                                                    <option value="Intact">Intact</option>
                                                    <option value="WashOut">WashOut</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Slide labelling:</label>
                                                <select class="form-control" name="slide_labeling" id="slide_labeling">
                                                    <?= blSavedOption($blrcd[14], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-12 col-form-label">Quality of Stain</label>
                                        </div>
                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Background:</label>
                                                <select class="form-control" name="background" id="background">
                                                    <?= blSavedOption($blrcd[15], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Stain (Blood Elements):</label>
                                                <select class="form-control" name="stain_blood_element" id="stain_blood_element">
                                                    <?= blSavedOption($blrcd[16], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Cleanliness:</label>
                                                <select class="form-control" name="cleanliness" id="cleanliness">
                                                    <?= blSavedOption($blrcd[17], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Stain (Artifact & Stain Deposit):</label>
                                                <select class="form-control" name="stain_deposit" id="stain_deposit">
                                                    <?= blSavedOption($blrcd[18], 'Select Result'); ?>
                                                    <?= acceptableResults(); ?>
                                                </select>
                                            </div>
                                        </div>

                                        <?php if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                                        <!-- Action bar: Save/Update (primary) + Cancel. Shown to RCDC/Admin only,
                                             matching the permissions used elsewhere in this module. -->
                                        <div class="form-actions">
                                            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary" id="c1CancelBtn">
                                                <i class="fa fa-times"></i> Cancel
                                            </a>
                                            <div class="btn-group-right">
                                                <?php
                                                /* Clear this slide's Controller 1 entry and return it to the
                                                   pending list. Offered only when the slide actually holds a
                                                   Controller 1 result and no Controller 2 result — the two
                                                   conditions delete.php itself enforces. */
                                                $c1HasEntry = (trim((string)$blrcd[2]) !== '');
                                                $c1C2Here = $conn->query("SELECT COALESCE(c2_microscopy, '') FROM blinded_results WHERE result_id = '".(int)$result_id."'");
                                                $c1C2Here = $c1C2Here ? $c1C2Here->fetch_row() : null;
                                                $c1C2Here = ($c1C2Here && trim((string)$c1C2Here[0]) !== '');
                                                if ($c1HasEntry && !$c1C2Here) { ?>
                                                <a href="delete.php?type=c1&id=<?=(int)$result_id;?>&biid=<?=$id;?>" class="btn btn-outline-danger mr-2 js-confirm-delete">
                                                    <i class="fa fa-undo"></i> Clear Controller 1
                                                </a>
                                                <?php } elseif ($c1HasEntry && $c1C2Here) { ?>
                                                <span class="text-muted small mr-2"><i class="fa fa-lock"></i> Clear Controller 2 first</span>
                                                <?php } ?>
                                                <button type="submit" class="btn bg-gradient-success" id="c1SaveBtn">
                                                    <i class="fa fa-save"></i> Save &amp; Update
                                                </button>
                                            </div>
                                        </div>
                                        <?php } else { ?>
                                        <div class="form-actions">
                                            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary"><i class="fa fa-arrow-left"></i> Back to Results Table</a>
                                        </div>
                                        <?php } ?>
                                    </form>
                                </div>
                            </div>
                            <?php
                            }
                            ?>

                            <?php
                            // Controller 1 checks its own prerequisite (the record has slide rows)
                            // instead of reusing $blrquery from the District Results pane, which is
                            // only defined on that pane's non-RCDC branch.
                            $c1SlideStmt = $conn->prepare("SELECT COUNT(*) FROM blinded_results WHERE blinded_id = ?");
                            $c1SlideBid  = (int)$id;
                            $c1SlideStmt->bind_param('i', $c1SlideBid);
                            $c1SlideStmt->execute();
                            $c1HasSlides = ((int)$c1SlideStmt->get_result()->fetch_row()[0] > 0);
                            $c1SlideStmt->close();
                            if ($action==2 && $c1HasSlides) {
                                // Controller 1 works only on slides received Intact at Shipment Receive
                                // (same population as the C1 count, C2, the Excel import and scoring).
                                $c1ListStmt = $conn->prepare("SELECT result_id, slide_no, collection_date, c1_microscopy, c1_stage, c1_parasite_count, c1_wbc_count, c1_density, c1_chkdate FROM blinded_results WHERE blinded_id = ? AND slide_status = 'Intact' ORDER BY result_id");
                                $c1ListStmt->bind_param('i', $c1SlideBid);
                                $c1ListStmt->execute();
                                $blrcquery = $c1ListStmt->get_result();
                                $c1ListStmt->close();

                                // Read-only receipt summary for context.
                                $c1Sum = array('Intact' => 0, 'Broken' => 0, 'Missing' => 0, 'none' => 0, 'all' => 0);
                                $c1SumStmt = $conn->prepare("SELECT slide_status, COUNT(*) FROM blinded_results WHERE blinded_id = ? GROUP BY slide_status");
                                $c1SumStmt->bind_param('i', $c1SlideBid);
                                $c1SumStmt->execute();
                                $c1SumRes = $c1SumStmt->get_result();
                                while ($s = $c1SumRes->fetch_row()) {
                                    $c1Sum['all'] += (int)$s[1];
                                    if (isset($c1Sum[$s[0]]) && $s[0] !== 'all' && $s[0] !== 'none') { $c1Sum[$s[0]] += (int)$s[1]; }
                                    else if ($s[0] === null || $s[0] === '') { $c1Sum['none'] += (int)$s[1]; }
                                }
                                $c1SumStmt->close();
                            ?>
                            <div class="alert alert-light border py-2 small mb-3">
                                Total Selected: <strong><?=$c1Sum['all'];?></strong> &nbsp;|&nbsp;
                                Intact: <strong><?=$c1Sum['Intact'];?></strong> &nbsp;|&nbsp;
                                Broken: <strong><?=$c1Sum['Broken'];?></strong> &nbsp;|&nbsp;
                                Missing: <strong><?=$c1Sum['Missing'];?></strong> &nbsp;|&nbsp;
                                Not Received: <strong><?=$c1Sum['none'];?></strong> &nbsp;|&nbsp;
                                Controller 1 Eligible: <strong><?=$c1Sum['Intact'];?></strong>
                            </div>
                            <?php if ($c1Sum['Intact'] === 0) { ?>
                            <div class="alert alert-info">No Intact slides are available for Controller 1.</div>
                            <?php } else { ?>
                            <div class="card card-outline card-primary">
                                <div class="card-header">
                                    <h3 class="card-title">RCDC Controller 1 Results Table</h3>
                                    <?php if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                                    <div class="card-tools">
                                        <a href="blinded.php?c1_export=1&amp;id=<?=(int)$binfo[0];?>" class="btn btn-sm bg-gradient-success mr-1" id="c1ExportBtn"
                                           title="Download all Controller 1 slides of this record in the NMRL worksheet format">
                                            <i class="fas fa-file-excel"></i> Export Excel
                                        </a>
                                    </div>
                                    <?php } ?>
                                </div>
                                <div class="card-body">
                                    <table id="table" class="table table-bordered table-hover">
                                        <thead>
                                            <tr class="text-center">
                                                <th>Edit</th><th>B/S No</th><th>Collection Date</th><th>Recheck Date</th><th>Microscopy Result</th><th>Parasite Stage</th><th>Parasite Count</th><th>WBC Count</th><th>Parasite Density</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        if ($blrcquery->num_rows>0) {
                                            while($blrc=$blrcquery->fetch_row()){
                                                echo("<tr>
                                                    <td>");
                                                    if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2) {
                                                        echo("<a href='$cancel&edit=blrc&rid=$blrc[0]'><i class='fa fa-edit'></i></a>");
                                                    }
                                                    echo("</td>
                                                    <td>$blrc[1]</td>
                                                    <td class='text-center'>$blrc[2]</td>
                                                    <td class='text-center'>$blrc[8]</td>
                                                    <td>$blrc[3]</td>
                                                    <td class='text-center'>$blrc[4]</td>
                                                    <td class='text-center'>$blrc[5]</td>
                                                    <td class='text-center'>$blrc[6]</td>
                                                    <td class='text-center'>$blrc[7]</td>
                                                </tr>");
                                            }
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php } // end: Intact slides available ?>

                            <?php
                            } else if (!$blrcd) {
                                // No slide rows for this record yet (or not in edit mode): say so
                                // instead of showing an empty step, and point back to the stage
                                // that produces them.
                            ?>
                            <div class="alert alert-info mb-0">
                                <strong>Controller 1 has nothing to recheck yet.</strong><br>
                                <?php if (!$c1HasSlides) { ?>
                                    No MP slides have been selected for this record. Add the slides in
                                    <strong>District Results</strong>, then receive the shipment in
                                    <strong>Shipment Receive</strong> &mdash; Controller 1 works on the slides received Intact.
                                <?php } else { ?>
                                    Open this record from the Blinded Rechecking list to enter Controller 1 results.
                                <?php } ?>
                            </div>
                            <?php
                            }
                            ?>
                        </div>
                        <!-- ===== /PANE: CONTROLLER 1 ===== -->

                        <?php
                        // open only when all results are completed by controller 1
                        // (and there is at least one intact slide to complete).
                        if ($countIntact > 0 && $countC1Results == $countIntact) {
                        ?>
                        <!-- ===== PANE: CONTROLLER 2 ===== -->
                        <div class="p-pane" id="pane-c2" data-title="Controller 2">
                            <div class="card card-outline card-primary">
                                <div class="card-header"><h3 class="card-title">RCDC Controller 2 Result</h3></div>
                                <div class="card-body">
                                    <form class="form-horizontal" id="controller2" autocomplete="off" method="Post" action="iu-blinded.php">
                                        <input type='hidden' name='submit' id='submit' value='controller2'>
                                        <input type="hidden" name="biid" id="biid" value="<?=$binfo[0];?>">
                                        <input type='hidden' name='action' id='action' value=''>

                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Health Facility:</label>
                                                <input type="text" class="form-control" value="<?=$facility[0];?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Month:</label>
                                                <input type="text" class="text-center form-control" value="<?=$biMonth;?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Year:</label>
                                                <input type="text" class="text-center form-control" value="<?=$binfo[3];?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Date of Shipment:</label>
                                                <input type="text" class="text-center form-control" value="<?=$binfo[10];?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Date of Receipt:</label>
                                                <input type="text" class="text-center form-control" value="<?=$dorec;?>" readonly>
                                            </div>
                                        </div>

                                        <?php
                                        /* A finalized record's scores are the issued report, so Controller 2 is
                                           read-only here (the same rule iu-blinded.php enforces server-side).
                                           The step still opens and still walks forward to Feedback / Review. */
                                        $c2Locked = !empty($isFinalized);
                                        $c2RO     = $c2Locked ? 'readonly' : '';
                                        $c2Dis    = $c2Locked ? 'disabled' : '';
                                        ?>

                                        <?php if ($c2Locked) { ?>
                                        <div class="alert alert-secondary py-2">
                                            <i class="fa fa-lock"></i>
                                            This report has been finalized, so Controller 2 results are read-only.
                                            Use <strong>Next</strong> to continue to Feedback &amp; Actions.
                                        </div>
                                        <?php } ?>

                                        <div class="row">
                                            <table class="table table-bordered table-hover">
                                                <thead>
                                                    <tr class="text-center">
                                                        <th>B/S No</th><th>Date of Collection</th><th>Microscopy Result</th><th>Parasite Stage</th><th>Parasite Count</th><th>WBC Count</th><th>Parasite Density</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php
                                                    $resultcc = $conn->query("SELECT result_id, slide_no, collection_date, c2_microscopy, c2_stage, c2_parasite_count, c2_wbc_count, c2_density, min_range, max_range FROM blinded_results WHERE blinded_id=$id AND slide_status='Intact'");
                                                    while($row = $resultcc->fetch_row()) {
                                                ?>
                                                    <tr class="c2-row">
                                                    <td class="text-center">
                                                        <input type="hidden" name="resultID[]" value="<?=$row[0];?>">
                                                        <?=$row[1];?>
                                                    </td>
                                                    <td><?=$row[2];?></td>
                                                    <td>
                                                        <select class="form-control c2-mic" name="microscopy[]" required <?=$c2Dis;?>>
                                                            <?= blSavedOption($row[3], 'Select Microscopy Result'); ?>
                                                            <?=microscopyResults();?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-control c2-stage" name="c2_parasite_stage[]" <?=$c2Dis;?>>
                                                            <option value="">Select Stage</option>
                                                            <?php
                                                            $stageOptions = ['T', 'S', 'G', 'TS', 'TG', 'TSG'];
                                                            $c2StageVal = $row[4] ?? '';
                                                            foreach ($stageOptions as $stageOption) {
                                                                $sel = ($c2StageVal === $stageOption) ? 'selected' : '';
                                                                echo "<option value=\"$stageOption\" $sel>$stageOption</option>";
                                                            }
                                                            ?>
                                                        </select>
                                                    </td>
                                                    <td><input type="number" name="pcount[]" class="form-control c2-pcount" min="0" step="1" value="<?=$row[5];?>" <?=$c2RO;?>></td>
                                                    <td><input type="number" name="wbcount[]" class="form-control c2-wbc" min="0" step="1" value="<?=$row[6];?>" <?=$c2RO;?>></td>
                                                    <td><input type="number" name="density[]" class="form-control c2-density" value="<?=$row[7];?>" readonly></td>
                                                    </tr>
                                                <?php } ?>
                                                </tbody>
                                            </table>
                                            <small class="text-muted">Parasite Density updates in real time as Parasite Count and WBC Count are entered, and is recalculated on the server when submitted. For a negative (Nmps) result, stage &amp; counts are cleared for that row only.</small>
                                        </div>
                                        <?php
                                        /* Controller 2 is an RCDC stage: Administrator and RCDC Labs.
                                           The server guard in iu-blinded.php enforces the same pair. */
                                        if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){
                                        ?>
                                        <div class="card-footer d-flex align-items-center">
                                            <button type="submit" class="btn bg-gradient-success">Submit</button>
                                            <?php
                                            /* Clearing Controller 2 also removes the derived scores and the
                                               feedback row, so it is offered only while the record is still a
                                               draft — exactly the rule delete.php applies. */
                                            if (!$isFinalized && $fbExists) { ?>
                                            <a href="delete.php?type=c2&id=<?=$id;?>&biid=<?=$id;?>" class="btn btn-outline-danger ml-auto js-confirm-delete">
                                                <i class="fa fa-undo"></i> Clear Controller 2 &amp; Scores
                                            </a>
                                            <?php } elseif ($isFinalized) { ?>
                                            <span class="text-muted small ml-auto"><i class="fa fa-lock"></i> Finalized &mdash; Controller 2 can no longer be cleared.</span>
                                            <?php } ?>
                                        </div>
                                        <?php
                                        }
                                        ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- ===== /PANE: CONTROLLER 2 ===== -->
                        <?php
                        }
                        ?>

                        <?php
                        if ($chkfeedback>0) {
                        ?>
                        <!-- ===== PANE: FEEDBACK & ACTIONS ===== -->
                        <div class="p-pane" id="pane-feedback" data-title="Feedback &amp; Actions">
                            <div class="card card-outline card-primary">
                                <div class="card-header"><h3 class="card-title">Feedbacks, Recommendations, Findings, and Action</h3></div>
                                <div class="card-body">
                                    <form class="form-horizontal" id="feedbackForm" autocomplete="off" method="Post" action="iu-blinded.php">
                                        <input type="hidden" id="biid" name="biid" value="<?=$id;?>">
                                        <input type="hidden" id="submit" name="submit" value="feedback">
                                        <input type="hidden" id="action" name="action" value="update">

                                        <div class="row">
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Health Facility:</label>
                                                <input type="text" class="form-control" value="<?=$facility[0];?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Month:</label>
                                                <input type="text" class="text-center form-control" value="<?=$biMonth;?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Year:</label>
                                                <input type="text" class="text-center form-control" value="<?=$binfo[3];?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-2">
                                                <label class="col-form-label-sm">Date of Shipment:</label>
                                                <input type="text" class="text-center form-control" value="<?=$binfo[10];?>" readonly>
                                            </div>
                                            <div class="form-group col-sm-3">
                                                <label class="col-form-label-sm">Date of Receipt:</label>
                                                <input type="text" class="text-center form-control" value="<?=$dorec;?>" readonly>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <table class="table table-bordered table-hover">
                                                <thead>
                                                    <tr class="text-center">
                                                        <th rowspan=2>B/S<br>#</th><th rowspan=2>Collection<br>Date</th><th rowspan=2>Slide<br>Status</th>
                                                        <th colspan=3>Species</th><th colspan=2>Stages</th><th colspan=2>Parasite</th><th colspan=2>WBC</th>
                                                        <th rowspan=2>Density<br>Ranges</th><th rowspan=2>Density</th>
                                                    </tr>
                                                    <tr class="text-center">
                                                        <th>RCDC</th><th>Lab</th><th>Chk</th><th>RCDC</th><th>Lab</th><th>RCDC</th><th>Lab</th><th>RCDC</th><th>Lab</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $feedback = $conn->query("SELECT result_id, slide_no, slide_status, collection_date, lab_microscopy, lab_stage, lab_parasite_count, lab_wbc_count, lab_density, c2_microscopy, c2_stage, c2_parasite_count, c2_wbc_count, c2_density, min_range, max_range, positivity_flag FROM blinded_results WHERE blinded_id='$id'");
                                                    while($row = $feedback->fetch_row()) {
                                                        $denSrc = ($row[13] !== null && $row[13] !== '' && (int)$row[13] > 0)
                                                            ? (int)$row[13]
                                                            : (($row[8] !== null && $row[8] !== '' && (int)$row[8] > 0) ? (int)$row[8] : null);
                                                        $pCountSrc = ($row[11] !== null && $row[11] !== '') ? $row[11] : $row[6];
                                                        $wCountSrc = ($row[12] !== null && $row[12] !== '') ? $row[12] : $row[7];
                                                        $dr = blCalculateDensityAndRange($pCountSrc, $wCountSrc, $denSrc, $row[14], $row[15]);
                                                    ?>
                                                    <tr>
                                                        <td><?=$row[1];?></td><td><?=$row[3];?></td><td><?=$row[2];?></td>
                                                        <td><?=$row[9];?></td><td><?=$row[4];?></td><td class='text-center'><?=$row[16];?></td>
                                                        <td><?=$row[10];?></td><td><?=$row[5];?></td>
                                                        <td class='text-center'><?=$row[11];?></td><td class='text-center'><?=$row[6];?></td>
                                                        <td class='text-center'><?=$row[12];?></td><td class='text-center'><?=$row[7];?></td>
                                                        <td class='text-center'><?=$dr['range_txt'];?></td>
                                                        <td class='text-center'><?=$dr['density_txt'];?></td>
                                                    </tr>
                                                    <?php } ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <?php
                                        $score = ($conn->query("SELECT feedback_id, sensitivity, specificity, malaria_infection, parasite_species, parasite_stage, parasite_density, average, achievement, blinded_finding, blinded_recommendation, blinded_action, film_quality, stain_quality, finding_master_id FROM blinded_feedback WHERE blinded_id='$id'"));
                                        $score = $score -> fetch_row();
                                        /* No feedback row exists until this step is first submitted. Give
                                           every column the markup reads a blank default so the page renders
                                           cleanly instead of warning on a null array offset. */
                                        if (!is_array($score)) { $score = array(); }
                                        for ($sc = 0; $sc <= 14; $sc++) { if (!isset($score[$sc])) { $score[$sc] = ''; } }

                                        // Whether real feedback text has ever been saved for this record.
                                        // Captured BEFORE the legacy default placeholder text is substituted
                                        // below, so the automatic suggestion can tell a genuinely blank
                                        // record from one that merely shows the boilerplate.
                                        $hasSavedFeedback = (trim((string)$score[9]) !== '' || trim((string)$score[10]) !== '');

                                        // Selectable Finding presets (dropdown source).
                                        // rule_key IS NULL keeps this list to the manual presets only —
                                        // the rule-bound rows belong to Smart Suggest, not the dropdown.
                                        $findingMasterId = isset($score[14]) ? $score[14] : '';
                                        /* Multiple findings: the selections live in blinded_feedback_findings.
                                           Records saved before that carry only finding_master_id, so fall back
                                           to it and they keep loading exactly as before. */
                                        $findingSelectedIds = array();
                                        if (isset($score[0]) && (int)$score[0] > 0) {
                                            $fsq = $conn->query("SELECT finding_id FROM blinded_feedback_findings
                                                                 WHERE feedback_id = ".(int)$score[0]."
                                                                 ORDER BY sort_order, finding_id");
                                            if ($fsq) { while ($fr = $fsq->fetch_row()) { $findingSelectedIds[] = (int)$fr[0]; } }
                                        }
                                        if (!count($findingSelectedIds) && $findingMasterId !== '' && (int)$findingMasterId > 0) {
                                            $findingSelectedIds[] = (int)$findingMasterId;
                                        }
                                        // Every ACTIVE library entry is available to the evaluation module.
                                        // Manual entries (no rule key) are listed first; rule-based entries
                                        // follow in a clearly labelled group — they are auto-suggested by the
                                        // rule engine, and selectable here only as a deliberate override.
                                        $findingOptions  = $conn->query("SELECT finding_id, title, rule_key FROM blinded_finding_master
                                                                         WHERE status = 1
                                                                         ORDER BY (rule_key IS NOT NULL AND rule_key<>''), category, sort_order, finding_id");

                                        /* ---------- Smart Suggest: evaluate this record against the rule library ----------
                                           Aggregates Controller 2 vs Lab agreement (already scored into the *_flag
                                           columns) and the Controller 1 quality fields across every slide of this
                                           submission, then pulls the admin-managed wording for each triggered rule. */
                                        $smartAgg = $conn->query("SELECT
                                                SUM(positivity_flag='TP') tp,
                                                SUM(positivity_flag='TN') tn,
                                                SUM(positivity_flag='FP') fp,
                                                SUM(positivity_flag='MI') mi,
                                                SUM(positivity_flag='FN') fn,
                                                SUM(positivity_flag='TP' AND species_flag=0) sp_bad,
                                                SUM(positivity_flag='TP' AND stages_flag<1)  st_bad,
                                                SUM(positivity_flag='TP' AND density_flag=0) de_bad,
                                                SUM(positivity_flag='TP' AND density_flag=0 AND max_range IS NOT NULL AND lab_density > max_range) de_high,
                                                SUM(positivity_flag='TP' AND density_flag=0 AND min_range IS NOT NULL AND lab_density < min_range) de_low,
                                                SUM(size_thick_smear='NotAcceptable')   f_thick,
                                                SUM(size_thin_smear='NotAcceptable')    f_thin,
                                                SUM(shape_thin_smear='NotAcceptable')   f_shape,
                                                SUM(smear_spacing='NotAcceptable')      f_space,
                                                SUM(eveness_thin_smear='NotEven')       f_even,
                                                SUM(blood_film='Mono')                  f_mono,
                                                SUM(smear_intact='WashOut')             f_wash,
                                                SUM(slide_labeling='NotAcceptable')     f_label,
                                                SUM(background='NotAcceptable')         s_bg,
                                                SUM(stain_blood_element='NotAcceptable') s_poor,
                                                SUM(stain_deposit='NotAcceptable')      s_dep,
                                                SUM(cleanliness='NotAcceptable')        s_clean
                                            FROM blinded_results WHERE blinded_id='$id'")->fetch_assoc();

                                        $smartKeys = array();
                                        $smartPos  = (int)$smartAgg['tp'] + (int)$smartAgg['fn'];   // RCDC-positive slides
                                        $smartNeg  = (int)$smartAgg['tn'] + (int)$smartAgg['fp'];   // RCDC-negative slides
                                        if ($smartPos > 0) {  // sensitivity-side rules need positive slides
                                            $smartKeys[] = ((int)$smartAgg['fn']     > 0) ? 'detect_fail'  : 'detect_ok';
                                            $smartKeys[] = ((int)$smartAgg['sp_bad'] > 0) ? 'species_fail' : 'species_ok';
                                            $smartKeys[] = ((int)$smartAgg['st_bad'] > 0) ? 'stage_fail'   : 'stage_ok';
                                            // density: distinguish "higher than reference" from "lower"
                                            if ((int)$smartAgg['de_bad'] > 0) {
                                                if ((int)$smartAgg['de_high'] > 0) { $smartKeys[] = 'density_high'; }
                                                if ((int)$smartAgg['de_low']  > 0) { $smartKeys[] = 'density_low'; }
                                                // out of range but direction undetermined (no stored range)
                                                if ((int)$smartAgg['de_high'] == 0 && (int)$smartAgg['de_low'] == 0) { $smartKeys[] = 'density_fail'; }
                                            } else {
                                                $smartKeys[] = 'density_ok';
                                            }
                                        }
                                        if ($smartNeg > 0) {  // specificity needs negative slides
                                            $smartKeys[] = ((int)$smartAgg['fp'] > 0) ? 'specificity_fail' : 'specificity_ok';
                                        }
                                        if ((int)$smartAgg['mi'] > 0) { $smartKeys[] = 'results_missing'; }
                                        $smartMap = array(
                                            'f_thick'=>'film_thick_size', 'f_thin'=>'film_thin_size',  'f_shape'=>'film_thin_shape',
                                            'f_space'=>'film_spacing',    'f_even'=>'film_evenness',   'f_mono'=>'film_mono',
                                            'f_wash' =>'film_washout',    'f_label'=>'film_labeling',
                                            's_bg'   =>'stain_background','s_poor'=>'stain_poor',
                                            's_dep'  =>'stain_deposit',   's_clean'=>'stain_cleanliness',
                                        );
                                        foreach ($smartMap as $col => $rule) {
                                            if ((int)$smartAgg[$col] > 0) { $smartKeys[] = $rule; }
                                        }

                                        $smartLib = array();
                                        if (count($smartKeys)) {
                                            $inList = "'" . implode("','", array_map(array($conn,'real_escape_string'), $smartKeys)) . "'";
                                            $rsLib  = $conn->query("SELECT rule_key, category, title, finding_content, recommendation_content
                                                                    FROM blinded_finding_master
                                                                    WHERE status = 1 AND rule_key IN ($inList)
                                                                    ORDER BY category, sort_order, finding_id");
                                            while ($rl = $rsLib->fetch_assoc()) { $smartLib[] = $rl; }
                                        }

                                        if($score[8] == "Excellent") $achievement = "<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>";
                                        else if($score[8] == "Very Good") $achievement = "<i class='fas fa-lg fa-award text-success'></i>&nbsp<i class='fas fa-lg fa-award text-success'></i>";
                                        else if($score[8] == "Good") $achievement = "<i class='fas fa-lg fa-award text-success'></i>";
                                        else $achievement = "<i class='fas fa-lg fa-thumbs-down text-danger'></i>";

                                        /* Findings and Recommendations open EMPTY.
                                           Two large blocks of boilerplate used to be substituted here whenever
                                           the stored value was blank, so a freshly submitted Controller 2 record
                                           always arrived at this step with text already in both editors. They are
                                           now left exactly as the database holds them: a record with saved
                                           feedback still shows it, and a record without one shows nothing. The
                                           editors are filled only when the evaluator picks entries from
                                           "-- Select Finding(s) --". */



                                        ?>
                                        <div class="row">
                                            <table class="table table-bordered table-hover">
                                                <thead>
                                                    <tr class="text-center"><th width="40%">Parameters</th><th width="10%">Score</th></tr>
                                                </thead>
                                                <tbody>
                                                    <tr><td>Sensitivity</td><td class="text-center"><?=$score[1];?></td></tr>
                                                    <tr><td>Specificity</td><td class="text-center"><?=$score[2];?></td></tr>
                                                    <tr><td>% of Agreement(Malaria Infection Detection)</td><td class="text-center"><?=$score[3];?></td></tr>
                                                    <tr><td>% of Agreement(Parasite Species Identification)</td><td class="text-center"><?=$score[4];?></td></tr>
                                                    <tr><td>% of Agreement(Parasite Stage Identification)</td><td class="text-center"><?=$score[5];?></td></tr>
                                                    <tr><td>% of Agreement(Parasite Density Calculation)</td><td class="text-center"><?=$score[6];?></td></tr>
                                                    <tr><td>% of Agreement(film quality)</td><td class="text-center"><?=$score[12];?></td></tr>
                                                    <tr><td>% of Agreement(stain quality)</td><td class="text-center"><?=$score[13];?></td></tr>
                                                    <tr><td><strong>Average score</strong></td><td class="text-center"><strong><?=$score[7];?></strong></td></tr>
                                                    <tr><td><strong>Achievement</strong></td><td class="text-center"><?=$achievement;?> <?=$score[8];?></td></tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Smart Suggest payload: triggered rule keys + admin-managed wording -->
                                        <script>
                                            var BL_SMART = <?= json_encode(array(
                                                'keys'      => $smartKeys,
                                                'lib'       => $smartLib,
                                                'positives' => $smartPos,
                                                'negatives' => $smartNeg,
                                                'saved'     => $hasSavedFeedback,
                                            )); ?>;
                                        </script>

                                        <div class="row mt-5">
                                            <div class="form-group col-sm-12">
                                                <div class="d-flex align-items-center justify-content-between flex-wrap">
                                                    <label class="text-primary col-form-label mb-0">FINDINGS</label>
                                                    <?php if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                                                    <div class="mb-1">
                                                        <button type="button" class="btn btn-sm bg-gradient-primary" id="btnManageFindings" data-toggle="modal" data-target="#findingMasterModal">
                                                            Manage Findings
                                                        </button>
                                                    </div>
                                                    <?php } ?>
                                                </div>
                                                <?php
                                                /* Category display names, used to head each inserted finding block in the
                                                   two editors. They come from blinded_finding_categories when that table
                                                   exists (2026-09-29 migration), otherwise from the four built-in keys. */
                                                $blCatNames = array('parasitology' => 'Positive Parasitology', 'film' => 'Quality of Film',
                                                                    'stain' => 'Quality of Stain', 'general' => 'General');
                                                $blCatChk = @$conn->query("SHOW TABLES LIKE 'blinded_finding_categories'");
                                                if ($blCatChk && $blCatChk->num_rows > 0) {
                                                    $blCq = $conn->query("SELECT cat_key, name FROM blinded_finding_categories
                                                                          WHERE status = 1 ORDER BY sort_order, name");
                                                    if ($blCq && $blCq->num_rows > 0) {
                                                        $blCatNames = array();
                                                        while ($blCr = $blCq->fetch_row()) { $blCatNames[$blCr[0]] = $blCr[1]; }
                                                    }
                                                }
                                                ?>
                                                <script>
                                                  var BL_CAT_NAMES   = <?= json_encode($blCatNames) ?>;
                                                </script>
                                                <select name="finding_master_id[]" id="findingSelect" class="form-control select2" style="width:100%;"
                                                        multiple data-placeholder="-- Select Finding(s) --">
                                                    <?php
                                                    $selectedFindingTitle = ''; $inManual = false; $inAuto = false;
                                                    while($fopt = $findingOptions->fetch_row()){
                                                        $isRule = ($fopt[2] !== null && $fopt[2] !== '');
                                                      //  if(!$isRule && !$inManual){ echo '<optgroup label="Manual findings">'; $inManual = true; }
                                                        if($isRule && !$inAuto){
                                                            if($inManual){ echo '</optgroup>'; }
                                                           // echo '<optgroup label="Automatic (rule-based) — normally applied by the evaluation">';
                                                            $inAuto = true;
                                                        }
                                                        $isSel = in_array((int)$fopt[0], $findingSelectedIds, true);
                                                        if($isSel) $selectedFindingTitle = ($selectedFindingTitle === '')
                                                                                         ? $fopt[1] : $selectedFindingTitle.', '.$fopt[1];
                                                        $sel = $isSel ? ' selected' : '';
                                                        echo '<option value="'.$fopt[0].'"'.$sel.'>'.htmlspecialchars($fopt[1], ENT_QUOTES)
                                                           . ($isRule ? '  ['.htmlspecialchars($fopt[2], ENT_QUOTES).']' : '') . '</option>';
                                                    }
                                                    if($inManual || $inAuto){ echo '</optgroup>'; }
                                                    ?>
                                                </select>
                                                <div id="findingTitleBar" class="alert alert-primary py-2 mt-2 mb-2" <?=($selectedFindingTitle=='')?'style="display:none;"':'';?>>
                                                    <strong id="findingTitleText"><?=$selectedFindingTitle;?></strong>
                                                </div>
                                                <!-- Suggestion status only. Findings/Recommendations are NOT filled
                                                     automatically: the editors start empty and are populated by the
                                                     Finding(s) dropdown, or by this link when clicked. -->
                                                <div id="autoSuggestBar" class="alert alert-light border py-2 mt-2 mb-2 small" style="display:none;">
                                                    <i class="fas fa-lightbulb text-warning"></i>
                                                    <span id="autoSuggestText"></span>
                                                    <a href="#" id="reapplySuggest" class="ml-1">Re-apply suggestion</a>
                                                    <a href="#" id="viewMatched" class="ml-2">View matched conditions</a>
                                                    <div id="matchedList" class="mt-2" style="display:none;"></div>
                                                </div>
                                                <textarea name="finding" id="finding" class="form-control"><?=$score[9];?></textarea>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-sm-12">
                                                <label class="col-sm-12 text-primary col-form-label">RECOMMENDATIONS</label>
                                                <textarea name="recommendation" id="recommendation" class="form-control"><?=$score[10];?></textarea>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-sm-12">
                                                <label class="text-primary col-form-label mb-1">ACTION</label>
                                                <!-- Two-column: the editor is the primary focus (~75%); the uploaded
                                                     image is only a compact reference preview pinned top-right (~25%),
                                                     so it never inflates the editor height or pushes content down. -->
                                                <div class="row bl-action-row">
                                                    <div class="col-lg-10 col-md-9">
                                                        <textarea name="actions" id="actions" class="form-control"><?=$score[11];?></textarea>
                                                    </div>
                                                    <div class="col-lg-2 col-md-3 bl-refcol">
                                                        <!-- Reference image card (always visible so Upload stays reachable) -->
                                                        <div id="actionImageWrap" class="card bl-refcard mb-0">
                                                            <div class="card-header py-2 px-2 d-flex align-items-center justify-content-between">
                                                                <span class="bl-refcard-title"><i class="fas fa-image text-primary mr-1"></i> Reference <span class="badge badge-primary ml-1" id="actionImageCount"><?= count($actionImages); ?></span></span>
                                                                <?php if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                                                                <button type="button" class="btn btn-sm btn-outline-primary bl-ref-upload" id="btnUploadActionImage"
                                                                        data-toggle="modal" data-target="#actionImageModal" title="Upload reference image">
                                                                    <i class="fas fa-upload"></i>
                                                                </button>
                                                                <?php } ?>
                                                            </div>
                                                            <div class="card-body p-2">
                                                                <div id="actionImageGallery" class="bl-img-gallery-compact">
                                                                    <?php if (empty($actionImages)) { ?>
                                                                        <div class="bl-ref-empty text-center text-muted">
                                                                            <i class="fas fa-image"></i>
                                                                            <div>No reference image</div>
                                                                        </div>
                                                                    <?php } else {
                                                                        foreach ($actionImages as $img) {
                                                                            $cap = !empty($img['caption']) ? htmlspecialchars($img['caption'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($img['original_name'], ENT_QUOTES, 'UTF-8');
                                                                            $size = (int)$img['file_size'] >= 1048576 ? round($img['file_size'] / 1048576, 1) . ' MB' : max(1, round($img['file_size'] / 1024)) . ' KB';
                                                                            $url = htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8');
                                                                            ?>
                                                                            <div class="bl-img-card" data-id="<?= (int)$img['image_id'] ?>">
                                                                                <img class="bl-img-thumb actionImgOpen" src="<?= $url ?>" alt="<?= $cap ?>" title="Click to enlarge"
                                                                                     data-id="<?= (int)$img['image_id'] ?>" data-url="<?= $url ?>" data-cap="<?= $cap ?>" data-size="<?= $size ?>">
                                                                                <?php if ($isRCDC) { ?>
                                                                                <button type="button" class="actionImgDel bl-img-remove-btn" data-id="<?= (int)$img['image_id'] ?>" title="Remove image">
                                                                                    <i class="fas fa-times"></i>
                                                                                </button>
                                                                                <?php } ?>
                                                                                <div class="bl-img-meta">
                                                                                    <div class="bl-img-cap text-truncate" title="<?= $cap ?>"><?= $cap ?></div>
                                                                                    <div class="bl-img-sub"><?= $size ?></div>
                                                                                </div>
                                                                            </div>
                                                                        <?php }
                                                                    } ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                                        <div class="card-footer d-flex align-items-center flex-wrap">
                                            <button type="submit" class="btn bg-gradient-success"><i class="fa fa-check"></i> Submit</button>
                                            <?php
                                            /* Two independent undos, matching delete.php: the Action is cleared
                                               on its own, and the Findings/Recommendation only once no Action
                                               remains (the server refuses the other order). */
                                            $fbHasAction = (trim((string)$score[11]) !== '');
                                            $fbHasText   = (trim((string)$score[9]) !== '' || trim((string)$score[10]) !== '');
                                            if (!$isFinalized && $fbHasAction) { ?>
                                            <a href="delete.php?type=ac&id=<?=$id;?>&biid=<?=$id;?>" class="btn btn-outline-danger ml-auto js-confirm-delete">
                                                <i class="fa fa-undo"></i> Clear Action Taken
                                            </a>
                                            <?php } ?>
                                            <?php if (!$isFinalized && $fbHasText && !$fbHasAction) { ?>
                                            <a href="delete.php?type=recom&id=<?=$id;?>&biid=<?=$id;?>" class="btn btn-outline-danger ml-auto js-confirm-delete">
                                                <i class="fa fa-undo"></i> Clear Findings &amp; Recommendations
                                            </a>
                                            <?php } elseif (!$isFinalized && $fbHasText && $fbHasAction) { ?>
                                            <small class="text-muted w-100 mt-2 mb-0"><i class="fa fa-lock"></i> Clear the Action Taken before the Findings and Recommendations can be cleared.</small>
                                            <?php } ?>
                                            <?php if ($isFinalized) { ?>
                                            <span class="text-muted small ml-auto"><i class="fa fa-lock"></i> Finalized &mdash; feedback is read-only.</span>
                                            <?php } ?>
                                        </div>
                                        <?php } ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- ===== /PANE: FEEDBACK & ACTIONS ===== -->

                        <?php if($_SESSION["roleID"] == 1 || $_SESSION["roleID"] == 2){ ?>
                        <!-- ===== MODAL: Action supporting image upload ===== -->
                        <div class="modal fade" id="actionImageModal" tabindex="-1" role="dialog" aria-hidden="true">
                          <div class="modal-dialog" role="document">
                            <div class="modal-content">
                              <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-image"></i>&nbsp; Upload Supporting Image</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                              </div>
                              <form id="actionImageForm" enctype="multipart/form-data" autocomplete="off">
                                <input type="hidden" name="img_action" value="upload">
                                <input type="hidden" name="biid" value="<?=$binfo[0];?>">
                                <div class="modal-body">
                                  <div class="alert alert-light border py-2 small mb-3">
                                    Accepted formats: <strong>JPG, JPEG, PNG</strong> &mdash; maximum <strong>3&nbsp;MB</strong> per image.
                                    Images are attached to this blinded rechecking record.
                                  </div>
                                  <div class="form-group">
                                    <label class="col-form-label-sm">Choose image</label>
                                    <input type="file" name="image" id="actionImageFile" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                                  </div>
                                  <div class="form-group">
                                    <label class="col-form-label-sm">Caption <small class="text-muted">(optional)</small></label>
                                    <input type="text" name="caption" id="actionImageCaption" class="form-control" maxlength="255" placeholder="e.g. Corrective action taken at facility">
                                  </div>
                                  <div id="actionImagePreviewWrap" class="text-center mb-3" style="display:none;">
                                    <div class="small font-weight-bold text-muted mb-1 text-uppercase">Selected File Preview</div>
                                    <img id="actionImagePreview" src="" alt="Preview"
                                         style="max-width:100%;max-height:220px;border:1px solid #e2e8f0;border-radius:8px;object-fit:contain;">
                                    <div class="small text-muted mt-1" id="actionImageMeta"></div>
                                  </div>
                                  <div id="actionModalExistingWrap" class="border-top pt-2 mt-2" style="<?= empty($actionImages) ? 'display:none;' : '' ?>">
                                    <div class="small text-muted font-weight-bold mb-2">Attached Images (<span class="modal-exist-count"><?= count($actionImages); ?></span>):</div>
                                    <div id="actionModalExistingList" class="d-flex flex-wrap" style="gap:8px;">
                                      <?php if (!empty($actionImages)) {
                                          foreach ($actionImages as $img) {
                                              $cap = !empty($img['caption']) ? htmlspecialchars($img['caption'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($img['original_name'], ENT_QUOTES, 'UTF-8');
                                              $url = htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8');
                                              ?>
                                              <div class="bl-modal-exist-thumb-wrap" title="<?= $cap ?>">
                                                  <img src="<?= $url ?>" alt="<?= $cap ?>" class="bl-modal-exist-thumb actionImgOpen" data-url="<?= $url ?>" data-cap="<?= $cap ?>">
                                              </div>
                                          <?php }
                                      } ?>
                                    </div>
                                  </div>
                                  <div id="actionImageError" class="mt-2"></div>
                                </div>
                                <div class="modal-footer">
                                  <button type="button" class="btn bg-gradient-secondary" data-dismiss="modal" id="actionImageCancel">
                                    <i class="fa fa-times"></i> Cancel
                                  </button>
                                  <button type="submit" class="btn bg-gradient-success" id="actionImageAdd">
                                     Submit
                                  </button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>
                        <!-- ===== /MODAL ===== -->

                        <!-- Enterprise thumbnail-gallery + lightbox styling (presentation only) -->
                        <style>
                          .bl-img-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;}
                          .bl-img-card{border:1px solid #e2e8f0;border-radius:10px;overflow:visible;background:#fff;
                                       box-shadow:0 1px 3px rgba(15,23,42,.08);display:flex;flex-direction:column;
                                       position:relative;
                                       transition:box-shadow .2s ease,transform .2s ease;}
                          .bl-img-card:hover{box-shadow:0 6px 18px rgba(15,23,42,.15);transform:translateY(-2px);}
                          .bl-img-thumb{width:100%;height:130px;object-fit:cover;display:block;cursor:pointer;background:#f1f5f9;border-radius:8px;}
                          .bl-img-meta{padding:8px 12px;}
                          .bl-img-cap{font-size:.8rem;font-weight:600;color:#334155;}
                          .bl-img-sub{font-size:.72rem;color:#94a3b8;}
                          .bl-img-actions{display:flex;gap:8px;padding:0 12px 12px;}
                          .bl-lb-body{background:#0f172a;}
                          .bl-lb-img{max-width:100%;max-height:70vh;object-fit:contain;border-radius:6px;}

                          /* Action editor (left, primary) + compact reference-image card (top-right) */
                          .bl-action-row{margin-left:-8px;margin-right:-8px;}
                          .bl-action-row > [class*="col-"]{padding-left:8px;padding-right:8px;}
                          .bl-refcard{border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 1px 3px rgba(15,23,42,.06);}
                          .bl-refcard .card-header{background:#f8fafc;border-bottom:1px solid #eef2f7;border-radius:10px 10px 0 0;}
                          .bl-refcard-title{font-size:.8rem;font-weight:600;color:#334155;white-space:nowrap;}
                          .bl-ref-upload{line-height:1;padding:.2rem .5rem;}
                          /* Compact single-column gallery inside the narrow reference card */
                          .bl-refcol{display:flex;align-items:flex-start;}
                          .bl-refcard{width:100%;max-width:220px;margin-left:auto;}
                          .bl-img-gallery-compact{display:flex;flex-direction:column;align-items:center;gap:10px;}
                          .bl-img-gallery-compact .bl-img-card{width:auto;max-width:180px;border:none;box-shadow:none;background:transparent;position:relative !important;}
                          .bl-img-gallery-compact .bl-img-thumb{width:170px;height:110px;object-fit:cover;border-radius:8px;border:1px solid #dee2e6;cursor:pointer;}
                          .bl-img-meta{display:none;}
                          .bl-img-actions{display:none;}
                          @media (max-width:991px){.bl-refcard{max-width:180px;}.bl-img-gallery-compact .bl-img-thumb{width:150px;height:95px;}}
                          @media (max-width:767px){.bl-refcol{margin-top:15px;}.bl-refcard{max-width:100%;}}
                          .bl-ref-empty{padding:20px 8px;font-size:.78rem;}
                          .bl-ref-empty i{font-size:1.7rem;opacity:.35;display:block;margin-bottom:6px;}

                          /* Subtle remove button for existing images */
                          .bl-img-remove-btn{
                              position:absolute;top:4px;right:4px;width:22px;height:22px;border-radius:50%;
                              background:rgba(220,53,69,0.92);color:#fff;border:2px solid #fff;font-size:11px;
                              line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;
                              box-shadow:0 1px 4px rgba(0,0,0,0.3);opacity:0.85;
                              transition:opacity .15s ease,transform .15s ease,background-color .15s ease;
                              z-index:10;padding:0;
                          }
                          .bl-img-remove-btn:hover{opacity:1;transform:scale(1.15);background:#c82333;color:#fff;}
                          .bl-img-pending{opacity:0.9;border:2px dashed #007bff !important;position:relative;border-radius:8px;padding:2px;}
                          .bl-img-pending-badge{position:absolute;bottom:6px;left:6px;font-size:10px;padding:2px 5px;z-index:5;}

                          /* Modal existing thumbnails */
                          .bl-modal-exist-thumb{width:52px;height:52px;object-fit:cover;border-radius:6px;border:1px solid #cbd5e1;cursor:pointer;transition:transform .15s ease,border-color .15s ease;}
                          .bl-modal-exist-thumb:hover{transform:scale(1.08);border-color:#007bff;box-shadow:0 2px 6px rgba(0,123,255,0.25);}

                          /* Review Step Image Grid */
                          .bl-review-img-grid{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;}
                          .bl-review-img-card{border:1px solid #e2e8f0;border-radius:8px;background:#fff;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.06);width:140px;display:flex;flex-direction:column;transition:transform .18s ease,box-shadow .18s ease;}
                          .bl-review-img-card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(15,23,42,.12);}
                          .bl-review-thumb{width:140px;height:95px;object-fit:cover;display:block;cursor:pointer;background:#f1f5f9;}
                          .bl-review-img-meta{padding:5px 8px;background:#fafafa;border-top:1px solid #f1f5f9;}
                          .bl-review-img-cap{font-size:.75rem;font-weight:600;color:#334155;line-height:1.2;}
                          .bl-review-img-sub{font-size:.68rem;color:#94a3b8;margin-top:2px;}

                          /* Tablet/mobile: stack the reference card BELOW the editor */
                          @media (max-width:767.98px){ .bl-refcol{margin-top:14px;} }
                        </style>

                        <!-- ===== MODAL: Supporting image lightbox (click-to-enlarge) ===== -->
                        <div class="modal fade" id="actionImageLightbox" tabindex="-1" role="dialog" aria-hidden="true">
                          <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                            <div class="modal-content">
                              <div class="modal-header py-2">
                                <h6 class="modal-title text-truncate" id="blLbCaption">Image</h6>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                              </div>
                              <div class="modal-body text-center bl-lb-body">
                                <img id="blLbImg" src="" alt="" class="bl-lb-img">
                              </div>
                              <div class="modal-footer justify-content-between">
                                <small class="text-muted" id="blLbMeta">&nbsp;</small>
                                <div>
                                  <a id="blLbDownload" href="#" download class="btn btn-sm bg-gradient-primary"><i class="fas fa-download"></i> Download</a>
                                  <button type="button" id="blLbDelete" class="btn btn-sm btn-outline-danger" style="display:none;"><i class="fa fa-trash"></i> Delete</button>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                        <!-- ===== /MODAL ===== -->
                        <!-- ===== SUB-MODAL: category management =====
                             Nested inside the Findings library modal. Bootstrap does not stack
                             modals on its own, so this one carries a higher z-index and its own
                             backdrop bump (see the style block) and the parent is left open
                             underneath rather than being hidden and re-shown. -->
                        <style>
                          #fmCatModal{ z-index:1075; }
                          .modal-backdrop.fmCatBackdrop{ z-index:1070; }
                          #fmCatModal .modal-content{ border-radius:.35rem; }
                          #fmCatModal .table td, #fmCatModal .table th{ vertical-align:middle; font-size:.82rem; }
                          #fmCatModal .fmCatDesc{ color:#6c757d; font-size:.75rem; }
                          #fmCatModal .fmCatRow:hover{ background:#f8f9fa; }
                          #fmCatModal .fmCatUse{ font-size:.7rem; }
                        </style>
                        <div class="modal fade" id="fmCatModal" tabindex="-1" role="dialog" aria-hidden="true">
                          <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                            <div class="modal-content">
                              <div class="modal-header py-2">
                                <h5 class="modal-title mb-0" id="fmCatModalTitle">
                                  <i class="fas fa-tags"></i>&nbsp; Manage Categories
                                </h5>
                                <button type="button" class="btn btn-sm bg-gradient-primary ml-auto mr-2" id="fmCatAdd">
                                  <i class="fas fa-plus"></i> Add Category
                                </button>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                  <span aria-hidden="true">&times;</span>
                                </button>
                              </div>
                              <div class="modal-body">
                                <div id="fmCatMsg"></div>

                                <!-- list -->
                                <div id="fmCatListView">
                                  <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0" id="fmCatTable">
                                      <thead>
                                        <tr>
                                          <th>Category</th>
                                          <th style="width:90px;">Status</th>
                                          <th style="width:90px;">In use</th>
                                          <th style="width:130px;" class="text-right">Action</th>
                                        </tr>
                                      </thead>
                                      <tbody><tr><td colspan="4" class="text-muted">Loading&hellip;</td></tr></tbody>
                                    </table>
                                  </div>
                                </div>

                                <!-- add / edit -->
                                <div id="fmCatFormView" style="display:none;">
                                  <form id="fmCatForm" autocomplete="off" onsubmit="return false;">
                                    <input type="hidden" id="fmCatId" value="0">
                                    <div class="form-row">
                                      <div class="form-group col-md-6">
                                        <label class="col-form-label-sm">Category Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="fmCatName" maxlength="100">
                                        <div class="invalid-feedback">A category name is required.</div>
                                      </div>
                                      <div class="form-group col-md-6">
                                        <label class="col-form-label-sm">Status</label>
                                        <select class="form-control" id="fmCatStatus">
                                          <option value="1">Active</option>
                                          <option value="0">Inactive</option>
                                        </select>
                                      </div>
                                    </div>
                                    <div class="form-group">
                                      <label class="col-form-label-sm">Description</label>
                                      <textarea class="form-control" id="fmCatDescription" rows="2" maxlength="255"
                                                placeholder="Optional — what belongs in this category"></textarea>
                                    </div>
                                  </form>
                                </div>
                              </div>
                              <div class="modal-footer py-2">
                                <button type="button" class="btn btn-sm bg-gradient-secondary" id="fmCatBack" style="display:none;">
                                  <i class="fas fa-arrow-left"></i> Back
                                </button>
                                <button type="button" class="btn btn-sm bg-gradient-success" id="fmCatSave" style="display:none;">
                                  <i class="fas fa-save"></i> Save Category
                                </button>
                                <button type="button" class="btn btn-sm bg-gradient-secondary" data-dismiss="modal" id="fmCatClose">Close</button>
                              </div>
                            </div>
                          </div>
                        </div>

                        <!-- ===== MODAL: Findings & Recommendations master (Add/Edit/Delete) ===== -->
                        <div class="modal fade" id="findingMasterModal" tabindex="-1" role="dialog" aria-hidden="true">
                          <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
                            <div class="modal-content">
                              <!-- Header stays pinned: modal-dialog-scrollable keeps the body
                                   scrolling while the header (and + New) remain fixed in view. -->
                              <div class="modal-header align-items-center flex-nowrap">
                                <div class="mr-auto" style="min-width:0;">
                                  <h5 class="modal-title mb-0 text-truncate"><i class="fas fa-book-medical"></i>&nbsp; Findings &amp; Recommendations Library</h5>
                                  <small class="text-muted d-none d-sm-block">Master data for evaluation findings and their recommendations</small>
                                </div>
                                <div class="d-flex align-items-center flex-shrink-0 ml-3">
                                  <!-- Pinned in the header so category management is reachable from the list
                                       screen too, not only while the Add/Edit form is open. Hidden until the
                                       categories table exists (see FM_CATS_DYNAMIC). -->
                                  <button type="button" class="btn btn-outline-secondary mr-2 d-none" id="fmCatManageHdr">
                                    <i class="fas fa-tags"></i><span class="d-none d-sm-inline">&nbsp;Manage Categories</span>
                                  </button>
                                  <button type="button" class="btn bg-gradient-primary" id="fmNew">
                                    <i class="fas fa-plus"></i><span class="d-none d-sm-inline">&nbsp;New</span>
                                  </button>
                                  <button type="button" class="close ml-3" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                </div>
                              </div>

                              <div class="modal-body">
                                <!-- ============ LIST VIEW (default) ============ -->
                                <div id="fmListView">
                                  <div class="form-row align-items-end mb-2">
                                    <div class="col-md-5 col-12 mb-2">
                                      <label class="col-form-label-sm mb-0">Search</label>
                                      <input type="text" id="fmSearch" class="form-control form-control-sm" placeholder="Search title, rule key or content&hellip;">
                                    </div>
                                    <div class="col-md-3 col-6 mb-2">
                                      <label class="col-form-label-sm mb-0">Category</label>
                                      <select id="fmFilterCat" class="form-control form-control-sm">
                                        <option value="">All categories</option>
                                        <option value="parasitology">Positive Parasitology</option>
                                        <option value="film">Quality of Film</option>
                                        <option value="stain">Quality of Stain</option>
                                        <option value="general">General</option>
                                      </select>
                                    </div>
                                    <div class="col-md-2 col-3 mb-2">
                                      <label class="col-form-label-sm mb-0">Status</label>
                                      <select id="fmFilterStatus" class="form-control form-control-sm">
                                        <option value="">All</option>
                                        <option value="1">Active</option>
                                        <option value="0">Inactive</option>
                                      </select>
                                    </div>
                                  </div>

                                  <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0" id="fmTable">
                                      <thead>
                                        <tr>
                                          <th>Finding Title</th>
                                          <th style="width:130px;">Category</th>
                                          <th style="width:90px;">Status</th>
                                          <th style="width:120px;">Last Updated</th>
                                          <th style="width:140px;" class="text-right">Action</th>
                                        </tr>
                                      </thead>
                                      <tbody><tr><td colspan="5" class="text-muted">Loading&hellip;</td></tr></tbody>
                                    </table>
                                  </div>

                                  <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap">
                                    <small class="text-muted" id="fmCount">&nbsp;</small>
                                    <ul class="pagination pagination-sm mb-0" id="fmPager"></ul>
                                  </div>
                                </div>

                                <!-- ============ FORM VIEW (hidden until New/Edit) ============ -->
                                <div id="fmFormView" style="display:none;">
                                  <div class="d-flex align-items-center mb-3">
                                    <button type="button" class="btn btn-sm bg-gradient-secondary" id="fmBack"><i class="fa fa-arrow-left"></i> Back to list</button>
                                    <strong class="ml-3" id="fmFormTitle">New Finding</strong>
                                  </div>
                                  <form id="fmForm" autocomplete="off" novalidate>
                                    <input type="hidden" name="fm_action" value="save">
                                    <input type="hidden" name="finding_id" id="fmId" value="0">
                                    <div class="form-row">
                                      <div class="form-group col-md-6">
                                        <label class="col-form-label-sm">Finding Title <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="title" id="fmTitle" maxlength="255">
                                        <div class="invalid-feedback">A finding title is required.</div>
                                      </div>
                                      <div class="form-group col-md-3">
                                        <!-- Categories are managed from the one button in the library header. -->
                                        <label class="col-form-label-sm">Category <span class="text-danger">*</span></label>
                                        <select class="form-control" name="category" id="fmCategory">
                                          <option value="parasitology">Positive Parasitology</option>
                                          <option value="film">Quality of Film</option>
                                          <option value="stain">Quality of Stain</option>
                                          <option value="general">General (dropdown preset)</option>
                                        </select>
                                      </div>
                                      <div class="form-group col-md-3">
                                        <label class="col-form-label-sm">Status</label>
                                        <select class="form-control" name="status" id="fmStatus">
                                          <option value="1">Active</option>
                                          <option value="0">Inactive</option>
                                        </select>
                                      </div>
                                    </div>
                                    <style>#findingMasterModal .note-editor.is-invalid{border:1px solid #dc3545;border-radius:.25rem;}</style>
                                    <div class="form-group">
                                      <label class="col-form-label-sm">Finding Content <span class="text-danger">*</span></label>
                                      <textarea class="form-control" name="finding_content" id="fmFinding" rows="4"></textarea>
                                      <small class="form-text text-muted">Type and format the finding using the toolbar (e.g. the bullet-list button for list items). No HTML tags needed.</small>
                                      <div class="invalid-feedback">Finding content is required.</div>
                                    </div>
                                    <div class="form-group">
                                      <label class="col-form-label-sm">Recommendation Content</label>
                                      <textarea class="form-control" name="recommendation_content" id="fmRecommendation" rows="4"></textarea>
                                      <small class="form-text text-muted">Shown as the paired recommendation. Leave blank when the finding needs no corrective action.</small>
                                    </div>
                                    <div class="form-actions">
                                      <button type="button" class="btn bg-gradient-secondary" id="fmCancel"><i class="fa fa-times"></i> Cancel</button>
                                      <div class="btn-group-right">
                                        <button type="submit" class="btn bg-gradient-success" id="fmSave">
                                          <span class="fmSpin spinner-border spinner-border-sm mr-1" style="display:none;"></span><i class="fa fa-save"></i> Save Entry
                                        </button>
                                      </div>
                                    </div>
                                  </form>
                                </div>

                                <!-- ============ VIEW MODE (read-only preview) ============ -->
                                <div id="fmViewView" style="display:none;">
                                  <div class="d-flex align-items-center mb-3">
                                    <button type="button" class="btn btn-sm bg-gradient-secondary" id="fmViewBack"><i class="fa fa-arrow-left"></i> Back to list</button>
                                    <strong class="ml-3" id="fmViewTitle"></strong>
                                  </div>
                                  <div id="fmViewMeta" class="mb-3"></div>
                                  <div class="card card-outline card-primary">
                                    <div class="card-header py-2"><h3 class="card-title">Finding</h3></div>
                                    <div class="card-body py-2" id="fmViewFinding"></div>
                                  </div>
                                  <div class="card card-outline card-secondary">
                                    <div class="card-header py-2"><h3 class="card-title">Recommendation</h3></div>
                                    <div class="card-body py-2" id="fmViewRec"></div>
                                  </div>
                                  <div class="form-actions">
                                    <span></span>
                                    <div class="btn-group-right">
                                      <button type="button" class="btn bg-gradient-primary" id="fmViewEdit"><i class="fa fa-edit"></i> Edit this entry</button>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                        <!-- ===== /MODAL ===== -->
                        <?php } ?>
                        <?php
                        }
                        ?>

                        <?php } // end isRCDC ?>
                        <?php } // end binfo[4]!=0 ?>

                        <!-- ===== PANE: REVIEW ===== -->
                        <div class="p-pane" id="pane-review" data-title="Review">
                            <div class="card card-outline card-primary">
                                <div class="card-body">

                                    <div class="d-flex align-items-center flex-wrap mb-3">
                                        <h5 class="review-sec-title mt-0 mb-0">Report Information</h5>
                                    <?php if(!$isFinalized){ ?>
                                        <span id="reviewDraftBadge" class="badge badge-light border ml-auto px-3 py-2 text-muted"><i class="fas fa-info-circle"></i> Draft &mdash; not yet finalized</span>
                                    <?php } ?>
                                    </div>
                                    <div class="row">
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Health Facility</small><strong><?=$facility[0];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Dzongkhag</small><strong><?=$dzongkhag[1];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Month / Year</small><strong><?=$biMonth;?> <?=$binfo[3];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Total Microscopy</small><strong><?=$binfo[4];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Total RDT</small><strong><?=$binfo[5];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Total Blinded Slides</small><strong><?=$binfo[9];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small><?=$shipment_date;?></small><strong><?= $binfo[10]!=''?$binfo[10]:'&mdash;';?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Date of Receipt</small><strong><?= $dorec!=''?$dorec:'&mdash;';?></strong></div></div>
                                    </div>

                                    <?php if($fbExists){ ?>
                                    <h5 class="review-sec-title">Performance Summary</h5>
                                    <div class="row">
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Sensitivity</small><strong><?=$vScore[0];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>Specificity</small><strong><?=$vScore[1];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>% Agreement (Malaria Infection)</small><strong><?=$vScore[2];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>% Agreement (Parasite Species)</small><strong><?=$vScore[3];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>% Agreement (Parasite Stage)</small><strong><?=$vScore[4];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>% Agreement (Parasite Density)</small><strong><?=$vScore[5];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>% Agreement (Film Quality)</small><strong><?=$vScore[11];?></strong></div></div>
                                        <div class="col-6 col-sm-3 mb-2"><div class="review-tile"><small>% Agreement (Stain Quality)</small><strong><?=$vScore[12];?></strong></div></div>
                                    </div>

                                    <h5 class="review-sec-title">Feedback &amp; Actions</h5>
                                    <div class="row">
                                        <div class="col-lg-6 d-flex mb-3">
                                            <div class="review-fb-card fb-find">
                                                <div class="review-fb-head"><i class="fas fa-search"></i> Findings</div>
                                                <div class="review-fb-body"><?= $vScore[8]!=''?$vScore[8]:'<span class="text-muted">Not recorded yet.</span>';?></div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 d-flex mb-3">
                                            <div class="review-fb-card fb-reco">
                                                <div class="review-fb-head"><i class="fas fa-lightbulb"></i> Recommendations</div>
                                                <div class="review-fb-body"><?= $vScore[9]!=''?$vScore[9]:'<span class="text-muted">Not recorded yet.</span>';?></div>
                                            </div>
                                        </div>
                                        <div class="col-12 d-flex mb-2">
                                            <div class="review-fb-card fb-act">
                                                <div class="review-fb-head d-flex align-items-center justify-content-between">
                                                    <span><i class="fas fa-tasks"></i> Action Taken</span>
                                                    <span class="badge badge-warning" id="reviewActionImageBadge" style="<?= empty($actionImages) ? 'display:none;' : '' ?>">
                                                        <i class="fas fa-paperclip"></i> <span id="reviewActionImageCount"><?= count($actionImages); ?></span> <?= count($actionImages) === 1 ? 'attachment' : 'attachments'; ?>
                                                    </span>
                                                </div>
                                                <div class="review-fb-body">
                                                    <div id="reviewActionText"><?= $vScore[10]!=''?$vScore[10]:'<span class="text-muted">Not recorded yet.</span>';?></div>
                                                    <!-- Action Images Gallery in Review Step -->
                                                    <div id="reviewActionImagesWrap" class="mt-3 pt-3 border-top" style="<?= empty($actionImages) ? 'display:none;' : '' ?>">
                                                        <div class="small font-weight-bold text-muted text-uppercase mb-2">
                                                            <i class="fas fa-images mr-1"></i> Reference Attachments (<span class="review-img-count"><?= count($actionImages); ?></span>)
                                                        </div>
                                                        <div id="reviewActionImageGallery" class="bl-review-img-grid">
                                                            <?php if (!empty($actionImages)) {
                                                                foreach ($actionImages as $img) {
                                                                    $imgCap = !empty($img['caption']) ? htmlspecialchars($img['caption'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($img['original_name'], ENT_QUOTES, 'UTF-8');
                                                                    $imgSize = (int)$img['file_size'] >= 1048576 ? round($img['file_size'] / 1048576, 1) . ' MB' : max(1, round($img['file_size'] / 1024)) . ' KB';
                                                                    $imgUrl = htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8');
                                                                    ?>
                                                                    <div class="bl-review-img-card" data-id="<?= (int)$img['image_id'] ?>">
                                                                        <img src="<?= $imgUrl ?>" alt="<?= $imgCap ?>" class="bl-review-thumb actionImgOpen" 
                                                                             data-id="<?= (int)$img['image_id'] ?>" data-url="<?= $imgUrl ?>" data-cap="<?= $imgCap ?>" data-size="<?= $imgSize ?>" 
                                                                             title="Click to enlarge">
                                                                        <div class="bl-review-img-meta">
                                                                            <div class="bl-review-img-cap text-truncate" title="<?= $imgCap ?>"><?= $imgCap ?></div>
                                                                            <div class="bl-review-img-sub"><?= $imgSize ?></div>
                                                                        </div>
                                                                    </div>
                                                                <?php }
                                                            } ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php } else { ?>
                                    <p class="text-muted mb-0 mt-3"><i class="fas fa-info-circle"></i> Scores, findings and recommendations will appear here once the RCDC evaluation is completed.</p>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                        <!-- ===== /PANE: REVIEW ===== -->

                    </div>
                    <!-- /#procPanes -->

                    <!-- Wizard nav buttons (single wizard-controlled submit) -->
                    <div class="row mt-3">
                        <div class="col-12 d-flex align-items-center">
                        <?php if ($isRCDC) { ?>
                            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary" id="procCancelBtn">Cancel</a>
                            <div class="ml-auto" id="procBtnGroup">
                                <button type="button" class="btn bg-gradient-secondary" id="procBackBtn" onclick="procBack();"><i class="fa fa-arrow-left"></i> Previous</button>
                                <button type="button" class="btn bg-gradient-success ml-2" id="procSaveBtn" style="display:none;" onclick="saveCurrentStep();"><i class="fa fa-check"></i> Save Current Step</button>
                                <button type="button" class="btn bg-gradient-info ml-2" id="procViewBtn" onclick="showView();"><i class="fas fa-eye"></i> View Summary</button>
                                <button type="button" class="btn bg-gradient-primary ml-2" id="procNextBtn" onclick="procNext();">Next <i class="fa fa-arrow-right"></i></button>
                                <!-- Shown only on the read-only Review step, for RCDC/admin, until finalized.
                                     Finalizing enables the Print Feedback Report button. -->
                                <button type="button" class="btn bg-gradient-warning ml-2" id="procFinalSubmitBtn" style="display:none;" onclick="finalizeRecord();"><i class="fas fa-check-double"></i> Final Submit</button>
                                <!-- The one step with no way back: a finalized report locks Controller 2
                                     and every feedback undo. Reopening clears only the finalized flag. -->
                                <a href="delete.php?type=reopen&id=<?=$id;?>&biid=<?=$id;?>" class="btn btn-outline-danger ml-2" id="procReopenBtn" style="display:none;"
                                   onclick="return confirmReopen();"><i class="fa fa-undo"></i> Reopen Report</a>
                            </div>
                        <?php } else { ?>
                            <a href="<?=$cancel;?>" class="btn bg-gradient-secondary" id="procCancelBtn">Cancel</a>
                            <button type="button" class="btn bg-gradient-secondary ml-2" id="procBackBtn" onclick="procBack();"><i class="fa fa-arrow-left"></i> Previous</button>
                            <div class="ml-auto">
                                <button type="button" class="btn bg-gradient-success" id="procSaveBtn" style="display:none;" onclick="saveCurrentStep();"><i class="fa fa-check"></i> Save Current Step</button>
                                <button type="button" class="btn bg-gradient-primary ml-2" id="procNextBtn" onclick="procNext();">Next <i class="fa fa-arrow-right"></i></button>
                            </div>
                        <?php } ?>
                        </div>
                    </div>

                </div>
                <!-- /.card-body -->
            </div>
            <!-- /.card -->
        </div>
    </section>
    <!-- ============================= /PROCESS WIZARD ============================= -->
<?php
}
/* ================================================================= */
/*  END BRANCH 2                                                      */
/* ================================================================= */
?>


</div>
<!-- /.content-wrapper -->
<script>
    /* -----------------------------------------------------------------
       Why a save really failed.

       Every save on this page posts to iu-blinded.php and used to treat a
       non-2xx reply as a transport problem, reporting "Network error while
       saving" even when the server had answered perfectly clearly — e.g.
       HTTP 403 "Controller 2 results cannot be changed on a finalized
       record." or "...restricted to the Administrator.". Those are business
       rules, not network faults, and the user could not see them.

       This reads the reason out of the reply (JSON message, the server's
       "403 - <reason>" denial page, or a PHP error) and always logs the raw
       response, so the real cause is visible in the browser console too.

       Declared at script top level so every save handler on the page can
       use it, whichever closure it lives in.
       ----------------------------------------------------------------- */
    function blFailMessage(xhr, fallback){

        var body = (xhr && typeof xhr.responseText === 'string') ? xhr.responseText : '';
        var status = (xhr && xhr.status) ? xhr.status : 0;
        var msg = '';

        if (body){
            // 1. JSON denial / error payload
            try {
                var parsed = JSON.parse(body);
                if (parsed && (parsed.msg || parsed.message || parsed.error)){
                    msg = parsed.msg || parsed.message || parsed.error;
                }
            } catch (e) { /* not JSON - fall through */ }

            // 2. the server's denial page: "403 &mdash; <reason>"
            if (!msg){
                var denied = body.match(/4\d\d\s*(?:&mdash;|—|-)\s*([^<]+)/);
                if (denied) { msg = $.trim(denied[1]); }
            }

            // 3. a PHP error that reached the response
            if (!msg){
                var php = body.match(/<b>\s*(Fatal error|Parse error|Warning|Notice)\s*<\/b>\s*:\s*([^<]+)/i);
                if (php) { msg = 'Server error: ' + $.trim(php[2]); }
            }
        }

        if (!msg){
            msg = (status === 0)
                ? 'The server could not be reached. Check your connection and try again.'
                : ('The server returned HTTP ' + status +
                   (xhr && xhr.statusText ? ' (' + xhr.statusText + ')' : '') + '.');
        }

        try {
            console.error('[Blinded] save failed - HTTP ' + status, xhr && xhr.statusText, body);
        } catch (e) { /* console unavailable */ }

        return msg || fallback;
    }

    $(document).ready(function(){

        $("#dzongkhag").change(function(){
            var dzongkhag = $('#dzongkhag').val();
            $("#facilityDiv").load("../load-facility.php?dzongkhag="+dzongkhag);
        });

        // ROOT-CAUSE FIX: validate EVERY required field together on the first
        // click — including Select2-backed selects (Dzongkhag, etc.) whose native
        // <select> is display:none. jQuery-Validate's default `ignore: ":hidden"`
        // silently skips those hidden controls, so not all required fields were
        // evaluated/shown at once. Setting the default to [] (validate nothing-
        // ignored) BEFORE any form is bound makes every Blinded validator surface
        // all invalid fields simultaneously. Scoped to this page only (each PHP
        // page sets its own jQuery-Validate defaults), so no other module changes.
        if ($.validator) { $.validator.setDefaults({ ignore: [] }); }

        $('#blindedInfo').validate({
            rules: {
                dzongkhag: "required",
                facility: "required",
                month: "required",
                year: "required",
                totalMicroscopy: "required",
                totalRDT: "required",
                dateship: "required",
            },

            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });

        $('#labResulrs').validate({
            rules: {
                bsno: "required",
                doc: "required",
                microscopy: "required",
            },

            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });

        $('#bsStatus').validate({

            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });

        // Controller 1 review/update form — same required-field rules the manual
        // entry screen has always used, so an imported record cannot be saved
        // back with a mandatory field blanked out.
        $('#c1Resulrs').validate({
            rules: {
                microscopyc: "required",
                drechk: "required",
                size_thick_smear: "required",
                size_thin_smear: "required",
                shape_thin_smear: "required",
                smear_spacing: "required",
                eveness_thin_smear: "required",
                blood_film: "required",
                smear_intact: "required",
                slide_labeling: "required",
                background: "required",
                stain_blood_element: "required",
                cleanliness: "required",
                stain_deposit: "required",
            },
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight:   function (element) { $(element).addClass('is-invalid'); },
            unhighlight: function (element) { $(element).removeClass('is-invalid'); },
            // the hidden input named "submit" shadows form.submit()
            submitHandler: function (form) { HTMLFormElement.prototype.submit.call(form); }
        });

        $('#controller2').validate({
            rules: {
                microscopycii: "required",
            },

            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });

        // ---- Process wizard: build stepper + keep user in Process mode after a submit ----
        if ($('#procPanes').length) {
            buildProcNav();

            // Wizard mode: hide each pane's individual Submit button and drive saving
            // from the single footer control. Only panes that own exactly one form are
            // centralised; multi-form panes (e.g. Feedback & Actions) keep their own
            // buttons so each independent save is preserved.
            $('#procPanes > .p-pane').each(function(){
                var $b = procPaneSubmitButtons(this);   // modal buttons excluded
                if ($b.length === 1){ $b.hide(); }
            });
            var params = new URLSearchParams(window.location.search);
            var edit = params.get('edit');
            var wantProcess = false, targetPane = null;

            if (edit === 'blrc') { wantProcess = true; targetPane = 'pane-c1'; }
            else if (edit === 'blr') { wantProcess = true; targetPane = 'pane-district'; }
            else if (sessionStorage.getItem('bl_mode') === 'process') {
                wantProcess = true;
                targetPane = sessionStorage.getItem('bl_pane');
            }
            // Existing records stay in Preview until the user explicitly chooses
            // Edit. This keeps Save Slide unavailable during ordinary viewing.
            sessionStorage.removeItem('bl_mode'); // one-shot; a manual reload re-applies the default above

            showProcStep(0);
            if (wantProcess) {
                showProcess();
                if (targetPane) showProcStepById(targetPane);
            } else {
                showView();
            }

            $('#processMode form').on('submit', function(){
                sessionStorage.setItem('bl_mode', 'process');
            });
        }
    });

    $(function () {
        $('#doc').datetimepicker({
            format: 'YYYY-MM-DD',
            // maxDate: moment(),
        });

        // District lab-result rows rendered by PHP (edit mode). Rows added later are
        // initialised by addEditSlideRow(); these were never initialised on load.
        $('#editSlideRows .s-doc-wrap').datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false });

        $('.docc-wrap').datetimepicker({          // one picker per Shipment Receive row
            format: 'YYYY-MM-DD',
            // maxDate: moment(),
        });

        $('#dateship').datetimepicker({
            format: 'YYYY-MM-DD',
            // maxDate: moment(),
        });

        $('#drechk').datetimepicker({
            format: 'YYYY-MM-DD',
            // maxDate: moment(),
        });

        // Controller 1: read-only Month derivation. The Slide Received Date field was
        // removed from this form, so the Month now follows the Date of Rechecking.
        function blSyncC1Month(){
            var $m = $('#c1Month');
            if(!$m.length) return;
            var src = $.trim($('input[name="drechk"]').val() || '');
            if(src === '') return;                       // keep the server-rendered fallback
            var d = moment(src, 'YYYY-MM-DD', true);
            if(d.isValid()) $m.val(d.format('MMMM'));
        }
        $('#drechk').on('change.datetimepicker', blSyncC1Month);
        $(document).on('change blur', 'input[name="drechk"]', blSyncC1Month);

        $('#dorec').datetimepicker({
            // maxDate: moment(),
            format: 'YYYY-MM-DD',
        });

        $('#table').DataTable({
            "paging": true,
            "lengthChange": false,
            "searching": false,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "responsive": true,
            "order": [[ 0, "desc" ]]
        });


        initFeedbackEditors($(document));

        //Initialize Select2 Elements
        $('.select2').select2({
            theme: 'bootstrap4',
        });

        // Finding dropdown -> show the title bar + auto-populate the finding and
        // recommendation editors. Delegated so it survives the wizard's in-place
        // pane refresh after a save.
        /* Findings is a multi-select: every selected entry contributes its own
           finding content and its own recommendation, in the order listed, with
           no duplication (the <select> itself guarantees distinct ids). */
        $(document).on('change', '#findingSelect', function(){
            var ids = $(this).val() || [];
            if(!$.isArray(ids)){ ids = ids === '' || ids === null ? [] : [ids]; }

            // option text carries a "  [rule_key]" suffix for automatic entries —
            // strip it so it never leaks into the finding heading.
            var titles = $.map($(this).find('option:selected'), function(o){
                return $.trim($(o).text()).replace(/\s*\[[a-z0-9_]+\]$/i, '');
            });

            if(!ids.length){ $('#findingTitleBar').hide(); return; }
            $('#findingTitleText').text(titles.join(', '));
            $('#findingTitleBar').show();

            $.getJSON('get-finding.php', { ids: ids.join(',') }, function(resp){
                var rows = (resp && resp.rows) ? resp.rows : [];
                var finding = '', recommendation = '';
                /* Library entries are stored two ways: some hold a complete block
                   (<p>/<ul>), others only the bare <li> items. Concatenating the
                   bare ones as they are leaks orphan <li>s into the editor, so
                   each such block gets its own list wrapper and stays a list
                   however many findings are combined. */
                function wrapList(html){
                    var t = $.trim(html || '');
                    return t === '' ? '' : (/^<li[\s>]/i.test(t) ? '<ul>' + t + '</ul>' : t);
                }
                /* Every inserted block is headed by its finding's CATEGORY name, in
                   both editors: the heading sits directly above that finding's own
                   content, not once at the top. Two findings from the same category
                   therefore each carry the heading. The selected titles are still
                   listed as chips in #findingTitleBar, which is unchanged. */
                function headFor(r){
                    var key  = r.category || '';
                    var name = (typeof BL_CAT_NAMES !== 'undefined' && BL_CAT_NAMES && BL_CAT_NAMES[key])
                             ? BL_CAT_NAMES[key] : (key || r.title);
                    return '<p><strong>' + $('<div>').text(name).html() + '</strong></p>';
                }
                $.each(rows, function(_, r){
                    var head = headFor(r);
                    /* Findings editor: heading, then this finding's content. */
                    finding += head;
                    if(r.finding) finding += wrapList(r.finding);
                    /* Recommendations editor: the same heading above this finding's
                       recommendation. An entry with no recommendation contributes
                       nothing, so no heading is left dangling over empty space. */
                    if(r.recommendation){
                        recommendation += head + wrapList(r.recommendation);
                    }
                });
                $('#finding').summernote('code', finding);
                if(recommendation !== ''){
                    $('#recommendation').summernote('code', recommendation);
                }
            });
        });

        /* ================= SMART SUGGEST =================
           Composes Findings + Recommendations from the rule entries that this
           record triggered (BL_SMART, computed server-side). Output goes into
           the Summernote editors and stays fully editable. */
        var BL_SMART_CATS = [
            ['parasitology', 'For positive slides:'],
            ['film',         'Quality of blood film:'],
            ['stain',        'Quality of staining:']
        ];
        function blComposeSuggestion(){
            var lib = (typeof BL_SMART !== 'undefined' && BL_SMART.lib) ? BL_SMART.lib : [];
            var finding = '', recommendation = '';
            $.each(BL_SMART_CATS, function(_, pair){
                var cat = pair[0], heading = pair[1];
                var items = $.grep(lib, function(x){ return x.category === cat; });
                if(!items.length) return;
                var f = '', r = '';
                $.each(items, function(_, it){
                    if(it.finding_content)        f += it.finding_content;
                    if(it.recommendation_content) r += it.recommendation_content;
                });
                if(f) finding        += '<p><b>' + heading + '</b><ul>' + f + '</ul></p>';
                if(r) recommendation += '<p><b>' + heading + '</b><ul>' + r + '</ul></p>';
            });
            return { finding: finding, recommendation: recommendation };
        }
        function blEditorHasText(sel){
            return $.trim($('<div>').html($(sel).summernote('code') || '').text()) !== '';
        }
        /* ---- Suggestion, applied only on request --------------------------
           Composed from the Controller 1 / Controller 2 evaluation, but never
           written into the editors by itself. The status bar reports what
           matched; blApplySuggestion() runs only from the "Re-apply suggestion"
           link, which is a deliberate click. Everything else that fills these
           editors comes from the "-- Select Finding(s) --" dropdown. */
        function blApplySuggestion(silent){
            var out = blComposeSuggestion();
            if(out.finding === '' && out.recommendation === ''){ return false; }
            if(out.finding)        $('#finding').summernote('code', out.finding);
            if(out.recommendation) $('#recommendation').summernote('code', out.recommendation);
            if(!silent){
                Swal.fire({icon:'success', text:'Suggestion applied — edit freely, then Submit to save.',
                           timer:1600, showConfirmButton:false});
            }
            return true;
        }
        function blMatchedConditionsHtml(){
            var lib = (typeof BL_SMART !== 'undefined' && BL_SMART.lib) ? BL_SMART.lib : [];
            if(!lib.length) return '<em class="text-muted">No conditions matched.</em>';
            var byCat = {parasitology:[], film:[], stain:[]};
            $.each(lib, function(_, x){ if(byCat[x.category]) byCat[x.category].push(x.title); });
            var label = {parasitology:'Results (Controller 1 vs Controller 2)', film:'Quality of blood film', stain:'Quality of stain'};
            var h = '';
            $.each(['parasitology','film','stain'], function(_, c){
                if(!byCat[c].length) return;
                h += '<div class="mb-1"><strong>'+label[c]+':</strong> '+
                     $.map(byCat[c], function(t){ return '<span class="badge badge-light border">'+blEsc(t)+'</span>'; }).join(' ')+'</div>';
            });
            return h;
        }
        function blInitAutoSuggest(){
            var $bar = $('#autoSuggestBar');
            if(!$bar.length || typeof BL_SMART === 'undefined') return;
            var lib = BL_SMART.lib || [];
            if(!lib.length){
                $('#autoSuggestText').text('No findings matched this record yet — check that Controller 1 and Controller 2 results are saved, or add rules under Manage Findings.');
                $('#reapplySuggest, #viewMatched').hide();
                $bar.show();
                return;
            }
            $('#matchedList').html(blMatchedConditionsHtml());
            // The suggestion is REPORTED, never applied on its own. Findings and
            // Recommendations stay empty until the evaluator either picks entries
            // from "-- Select Finding(s) --" or clicks "Re-apply suggestion".
            if(!BL_SMART.saved){
                $('#autoSuggestText').html('<strong>'+lib.length+' condition(s)</strong> matched this evaluation. Select findings below, or use the link, to fill the editors.');
            } else {
                $('#autoSuggestText').html('<strong>'+lib.length+' condition(s)</strong> matched this evaluation. Your saved text is shown below.');
            }
            $bar.show();
        }
        $(document).on('click', '#reapplySuggest', function(e){
            e.preventDefault();
            if(blEditorHasText('#finding') || blEditorHasText('#recommendation')){
                Swal.fire({icon:'warning', title:'Replace current text?',
                    text:'Findings and Recommendations will be regenerated from the evaluation results.',
                    showCancelButton:true, confirmButtonText:'Replace', cancelButtonText:'Cancel'})
                .then(function(res){ if(res.value && !blApplySuggestion(false)){
                    Swal.fire({icon:'info', text:'Matching rules have no wording yet. Add content under Manage Findings.'});
                }});
            } else if(!blApplySuggestion(false)){
                Swal.fire({icon:'info', text:'Matching rules have no wording yet. Add content under Manage Findings.'});
            }
        });
        $(document).on('click', '#viewMatched', function(e){
            e.preventDefault(); $('#matchedList').slideToggle(120);
        });
        // Summernote initialises with the panes, so defer until the editors exist.
        setTimeout(blInitAutoSuggest, 700);

        /* ---- shared HTML escape helper used by the in-page widgets ---- */
        function blEsc(v){ return $('<div>').text((v === null || typeof v === 'undefined') ? '' : v).html(); }

        /* ================= ACTION: SUPPORTING IMAGES =================
           Upload / view / remove evidence images attached to this record's
           Action. Client-side checks mirror the server rules; the server
           re-validates every file by its real content. */
        var BL_IMG_MAX = 3 * 1024 * 1024;
        var BL_IMG_TYPES = ['image/jpeg', 'image/png'];
        var BL_IMG_BIID = <?= json_encode(isset($binfo[0]) ? (int)$binfo[0] : 0); ?>;
        var BL_IMG_CAN_MANAGE = <?= $isRCDC ? 'true' : 'false'; ?>;
        var BL_ACTION_IMAGES = <?= json_encode($actionImages); ?>;

        function blImgSize(b){
            b = parseInt(b, 10) || 0;
            return (b >= 1048576) ? (Math.round(b/104857.6)/10)+' MB' : Math.max(1, Math.round(b/1024))+' KB';
        }
        function blRenderActionImages(rows){
            BL_ACTION_IMAGES = rows || [];
            var $g = $('#actionImageGallery').empty();
            $('#actionImageCount').text(BL_ACTION_IMAGES.length);

            // 1. Render Step 6 Reference Card Gallery
            if(!BL_ACTION_IMAGES.length){
                $g.html('<div class="bl-ref-empty text-center text-muted"><i class="fas fa-image"></i><div>No reference image</div></div>');
            } else {
                $.each(BL_ACTION_IMAGES, function(_, r){
                    var cap  = r.caption ? blEsc(r.caption) : blEsc(r.original_name || 'Image');
                    var size = blImgSize(r.file_size);
                    var del = BL_IMG_CAN_MANAGE
                        ? '<button type="button" class="actionImgDel bl-img-remove-btn" data-id="'+r.image_id+'" title="Remove image">&times;</button>'
                        : '';
                    $g.append(
                      '<div class="bl-img-card" data-id="'+r.image_id+'">'+
                        '<img class="bl-img-thumb actionImgOpen" src="'+r.url+'" alt="'+cap+'" title="Click to enlarge" '+
                             'data-id="'+r.image_id+'" data-url="'+r.url+'" data-cap="'+cap+'" data-size="'+size+'">'+
                        del+
                        '<div class="bl-img-meta">'+
                          '<div class="bl-img-cap text-truncate" title="'+cap+'">'+cap+'</div>'+
                          '<div class="bl-img-sub">'+size+'</div>'+
                        '</div>'+
                      '</div>');
                });
            }

            // 2. Render Step 7 Review Action Taken Card Gallery
            var $rw = $('#reviewActionImagesWrap');
            var $rg = $('#reviewActionImageGallery').empty();
            var $badge = $('#reviewActionImageBadge');
            if(!BL_ACTION_IMAGES.length){
                $rw.hide();
                $badge.hide();
            } else {
                $('.review-img-count').text(BL_ACTION_IMAGES.length);
                $('#reviewActionImageCount').text(BL_ACTION_IMAGES.length);
                $badge.show();
                $.each(BL_ACTION_IMAGES, function(_, r){
                    var cap  = r.caption ? blEsc(r.caption) : blEsc(r.original_name || 'Image');
                    var size = blImgSize(r.file_size);
                    $rg.append(
                      '<div class="bl-review-img-card" data-id="'+r.image_id+'">'+
                        '<img src="'+r.url+'" alt="'+cap+'" class="bl-review-thumb actionImgOpen" '+
                             'data-id="'+r.image_id+'" data-url="'+r.url+'" data-cap="'+cap+'" data-size="'+size+'" '+
                             'title="Click to enlarge">'+
                        '<div class="bl-review-img-meta">'+
                          '<div class="bl-review-img-cap text-truncate" title="'+cap+'">'+cap+'</div>'+
                          '<div class="bl-review-img-sub">'+size+'</div>'+
                        '</div>'+
                      '</div>');
                });
                $rw.show();
            }

            // 3. Render Modal Existing Images List
            var $modalList = $('#actionModalExistingList').empty();
            var $modalWrap = $('#actionModalExistingWrap');
            if(!BL_ACTION_IMAGES.length){
                $modalWrap.hide();
            } else {
                $('.modal-exist-count').text(BL_ACTION_IMAGES.length);
                $modalWrap.show();
                $.each(BL_ACTION_IMAGES, function(_, r){
                    var cap  = r.caption ? blEsc(r.caption) : blEsc(r.original_name || 'Image');
                    $modalList.append(
                      '<div class="bl-modal-exist-thumb-wrap" title="'+cap+'">'+
                        '<img src="'+r.url+'" alt="'+cap+'" class="bl-modal-exist-thumb actionImgOpen" '+
                             'data-id="'+r.image_id+'" data-url="'+r.url+'" data-cap="'+cap+'">'+
                      '</div>');
                });
            }
        }
        function blLoadActionImages(){
            if(!BL_IMG_BIID) return;
            $.getJSON('blinded.php', {img_action:'list', biid:BL_IMG_BIID}, function(resp){
                if(resp && resp.ok){ BL_IMG_CAN_MANAGE = !!resp.canManage; blRenderActionImages(resp.rows || []); }
            });
        }

        // live preview + client-side validation
        $(document).on('change', '#actionImageFile', function(){
            var $err = $('#actionImageError').empty();
            var $pw  = $('#actionImagePreviewWrap').hide();
            var f = this.files && this.files[0];
            $('#actionImageGallery .bl-img-pending').remove();
            if(!f) return;
            if(BL_IMG_TYPES.indexOf(f.type) === -1){
                $err.html('<div class="alert alert-warning py-2 mb-0">Only JPG, JPEG and PNG images are accepted.</div>');
                $(this).val(''); return;
            }
            if(f.size > BL_IMG_MAX){
                $err.html('<div class="alert alert-warning py-2 mb-0">This image is '+blImgSize(f.size)+'. Maximum allowed is 3 MB.</div>');
                $(this).val(''); return;
            }
            var rd = new FileReader();
            rd.onload = function(e){
                $('#actionImagePreview').attr('src', e.target.result);
                $('#actionImageMeta').text(f.name + ' — ' + blImgSize(f.size));
                $pw.show();

                // Append pending preview in Step 6 alongside existing images
                $('#actionImageGallery .bl-ref-empty').hide();
                $('#actionImageGallery').append(
                    '<div class="bl-img-card bl-img-pending" id="pendingActionImg">'+
                        '<img class="bl-img-thumb" src="'+e.target.result+'" alt="Pending preview" title="Pending upload: '+blEsc(f.name)+'">'+
                        '<span class="badge badge-info bl-img-pending-badge"><i class="fas fa-clock mr-1"></i>New file</span>'+
                        '<button type="button" class="bl-img-remove-btn bl-img-cancel-pending" title="Cancel this selection">&times;</button>'+
                    '</div>'
                );
            };
            rd.readAsDataURL(f);
        });

        // Cancel pending selection
        $(document).on('click', '.bl-img-cancel-pending', function(e){
            e.stopPropagation();
            $('#actionImageFile').val('');
            $('#actionImagePreviewWrap').hide();
            $('#actionImagePreview').attr('src', '');
            $('#pendingActionImg').remove();
            if(!$('#actionImageGallery .bl-img-card').length){
                $('#actionImageGallery .bl-ref-empty').show();
            }
        });

        $(document).on('submit', '#actionImageForm', function(e){
            e.preventDefault();
            var $btn = $('#actionImageAdd').prop('disabled', true);
            var $err = $('#actionImageError').empty();
            $.ajax({ url:'blinded.php', type:'POST', data:new FormData(this),
                     processData:false, contentType:false, dataType:'json' })
            .done(function(resp){
                $btn.prop('disabled', false);
                if(resp && resp.ok){
                    $('#actionImageModal').modal('hide');
                    blLoadActionImages();
                    Swal.fire({icon:'success', text:'Image added.', timer:1400, showConfirmButton:false});
                } else {
                    $err.html('<div class="alert alert-danger py-2 mb-0">'+blEsc((resp && resp.msg) || 'Upload failed.')+'</div>');
                }
            })
            .fail(function(){
                $btn.prop('disabled', false);
                $err.html('<div class="alert alert-danger py-2 mb-0">Upload failed — nothing was saved.</div>');
            });
        });

        // reset the dialog whenever it closes (Cancel or ×)
        $(document).on('hidden.bs.modal', '#actionImageModal', function(){
            $('#actionImageForm')[0].reset();
            $('#actionImagePreviewWrap').hide();
            $('#actionImagePreview').attr('src','');
            $('#actionImageError').empty();
            $('#actionImageGallery .bl-img-pending').remove();
            if(!BL_ACTION_IMAGES.length){
                $('#actionImageGallery .bl-ref-empty').show();
            }
        });

        // Shared delete flow (used by both the card and the lightbox) — unchanged endpoint.
        function blDeleteActionImage(id){
            Swal.fire({
                icon: 'warning',
                title: 'Remove this image?',
                text: 'This will detach and delete the image reference.',
                showCancelButton: true,
                confirmButtonText: 'Yes, remove',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#d33'
            }).then(function(res){
                if(!res.value) return;
                $.post('blinded.php', {img_action:'delete', biid:BL_IMG_BIID, image_id:id}, null, 'json')
                .done(function(resp){
                    if(resp && resp.ok){
                        blLoadActionImages();
                        Swal.fire({icon:'success', text:'Image removed.', timer:1200, showConfirmButton:false});
                    } else {
                        Swal.fire({icon:'error', text:(resp && resp.msg) || 'Could not remove the image.'});
                    }
                })
                .fail(function(){ Swal.fire({icon:'error', text:'Could not remove the image.'}); });
            });
        }
        $(document).on('click', '.actionImgDel', function(e){
            e.stopPropagation();
            blDeleteActionImage($(this).data('id'));
        });

        // Click-to-enlarge: open the image in the in-page lightbox instead of a new tab.
        $(document).on('click', '.actionImgOpen', function(){
            var $t = $(this);
            $('#blLbImg').attr('src', $t.data('url')).attr('alt', $t.data('cap'));
            $('#blLbCaption').text($t.data('cap'));
            $('#blLbMeta').text($t.data('size'));
            $('#blLbDownload').attr('href', $t.data('url'));
            $('#blLbDelete').toggle(!!BL_IMG_CAN_MANAGE).data('id', $t.data('id'));
            $('#actionImageLightbox').modal('show');
        });
        $(document).on('click', '#blLbDelete', function(){
            var id = $(this).data('id');
            $('#actionImageLightbox').modal('hide');
            blDeleteActionImage(id);
        });

        // Preload existing images immediately without waiting for AJAX round-trip
        if (BL_ACTION_IMAGES && BL_ACTION_IMAGES.length) {
            blRenderActionImages(BL_ACTION_IMAGES);
        }
        blLoadActionImages();

        /* ================= FINDINGS & RECOMMENDATIONS LIBRARY =================
           Master-data manager: list-first with search / filters / pagination,
           a separate form view for New+Edit, and a read-only View mode.
           The library is the single source of truth for both the Findings
           dropdown (manual entries) and the automatic rule suggestions. */
        var FM_ROWS = [], FM_PAGE = 1, FM_PER = 8;

        function fmPost(data){ return $.post('blinded.php', data, null, 'json'); }
        function fmToast(icon, text){
            Swal.fire({icon:icon, text:text, timer:(icon==='success'?1800:3200),
                       showConfirmButton:(icon!=='success'), toast:true, position:'top-end'});
        }
        /* Categories the server sent with the last list call. Empty until the
           2026-09-29 migration has been run, in which case the built-in map
           below still labels everything exactly as it did before. */
        var FM_CATS = [], FM_CATS_DYNAMIC = false;
        var FM_CAT_BUILTIN = {parasitology:'Parasitology', film:'Quality of Film',
                              stain:'Quality of Stain', general:'General'};
        /* ============ Manage Categories (nested sub-modal) ============ */
        function fmCatMsg(kind, text){
            $('#fmCatMsg').html(text ? '<div class="alert alert-'+kind+' py-2 px-3 mb-2 small">'+blEsc(text)+'</div>' : '');
        }
        function fmCatShow(which){
            var form = (which === 'form');
            $('#fmCatListView').toggle(!form);
            $('#fmCatFormView').toggle(form);
            $('#fmCatAdd').toggle(!form);
            $('#fmCatBack').toggle(form);
            $('#fmCatSave').toggle(form);
        }
        function fmCatRenderList(){
            var $tb = $('#fmCatTable tbody').empty();
            if(!FM_CATS.length){
                $tb.append('<tr><td colspan="4" class="text-center text-muted py-3">No categories yet.</td></tr>');
                return;
            }
            $.each(FM_CATS, function(_, c){
                var active  = String(c.status) === '1';
                var builtIn = FM_CAT_BUILTIN.hasOwnProperty(c.cat_key);
                $tb.append(
                  '<tr class="fmCatRow" data-id="'+c.category_id+'">'+
                    '<td><span class="font-weight-bold">'+blEsc(c.name)+'</span>'+
                      (c.description ? '<div class="fmCatDesc">'+blEsc(c.description)+'</div>' : '')+'</td>'+
                    '<td>'+(active ? '<span class="badge badge-success">Active</span>'
                                   : '<span class="badge badge-secondary">Inactive</span>')+'</td>'+
                    '<td><span class="badge badge-light border fmCatUse">'+(parseInt(c.in_use,10)||0)+'</span></td>'+
                    '<td class="text-right">'+
                      '<button type="button" class="btn btn-sm btn-outline-primary fmCatEdit mr-1"><i class="fas fa-edit"></i></button>'+
                      (builtIn
                        ? '<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Built-in category"><i class="fas fa-lock"></i></button>'
                        : '<button type="button" class="btn btn-sm btn-outline-danger fmCatDel"><i class="fas fa-trash"></i></button>')+
                    '</td>'+
                  '</tr>');
            });
        }
        function fmCatById(id){
            for(var i=0;i<FM_CATS.length;i++){ if(String(FM_CATS[i].category_id) === String(id)) return FM_CATS[i]; }
            return null;
        }
        /* Reload the library (which carries the categories) and repaint everything. */
        function fmCatReload(){
            return fmLoad().done(function(){ fmCatRenderList(); });
        }
        $(document).on('click', '#fmCatManageHdr', function(){
            fmCatMsg('', ''); fmCatShow('list'); fmCatRenderList();
            $('#fmCatModal').modal({backdrop:'static'});
        });
        /* Bootstrap does not stack backdrops; tag this one so the CSS can sit it
           between the two modals instead of on top of both. */
        $(document).on('shown.bs.modal', '#fmCatModal', function(){
            $('.modal-backdrop').last().addClass('fmCatBackdrop');
        });
        $(document).on('hidden.bs.modal', '#fmCatModal', function(){
            /* the parent modal is still open, so keep the page in modal mode */
            if($('#findingMasterModal').hasClass('show')) $('body').addClass('modal-open');
        });
        $(document).on('click', '#fmCatAdd', function(){
            $('#fmCatId').val(0); $('#fmCatName').val('').removeClass('is-invalid');
            $('#fmCatDescription').val(''); $('#fmCatStatus').val(1);
            $('#fmCatModalTitle').html('<i class="fas fa-tags"></i>&nbsp; Add Category');
            fmCatMsg('', ''); fmCatShow('form'); $('#fmCatName').focus();
        });
        $(document).on('click', '#fmCatBack', function(){
            $('#fmCatModalTitle').html('<i class="fas fa-tags"></i>&nbsp; Manage Categories');
            fmCatMsg('', ''); fmCatShow('list');
        });
        $(document).on('click', '.fmCatEdit', function(){
            var c = fmCatById($(this).closest('tr').data('id'));
            if(!c) return;
            $('#fmCatId').val(c.category_id);
            $('#fmCatName').val(c.name).removeClass('is-invalid');
            $('#fmCatDescription').val(c.description || '');
            $('#fmCatStatus').val(String(c.status));
            $('#fmCatModalTitle').html('<i class="fas fa-tags"></i>&nbsp; Edit: '+blEsc(c.name));
            fmCatMsg('', ''); fmCatShow('form');
        });
        $(document).on('click', '#fmCatSave', function(){
            var name = $.trim($('#fmCatName').val());
            if(name === ''){ $('#fmCatName').addClass('is-invalid'); return; }
            $('#fmCatName').removeClass('is-invalid');
            var $b = $(this).prop('disabled', true);
            fmPost({
                fm_action:   'cat_save',
                category_id: $('#fmCatId').val(),
                name:        name,
                description: $('#fmCatDescription').val(),
                cat_status:  $('#fmCatStatus').val()
            }).done(function(resp){
                $b.prop('disabled', false);
                if(resp && resp.ok){
                    fmCatReload().done(function(){
                        $('#fmCatModalTitle').html('<i class="fas fa-tags"></i>&nbsp; Manage Categories');
                        fmCatShow('list'); fmCatMsg('success', 'Category saved.');
                    });
                } else { fmCatMsg('danger', (resp && resp.msg) || 'Could not save the category.'); }
            }).fail(function(){ $b.prop('disabled', false); fmCatMsg('danger', 'Could not save the category.'); });
        });
        $(document).on('click', '.fmCatDel', function(){
            var c = fmCatById($(this).closest('tr').data('id'));
            if(!c) return;
            var used = parseInt(c.in_use, 10) || 0;
            var warn = used > 0
                ? '"'+c.name+'" is used by '+used+' finding(s). It will be set to Inactive instead of deleted. Continue?'
                : 'Delete the category "'+c.name+'"? This cannot be undone.';
            if(!confirm(warn)) return;
            fmPost({fm_action:'cat_delete', category_id:c.category_id}).done(function(resp){
                if(resp && resp.ok){
                    fmCatReload().done(function(){
                        fmCatMsg(resp.deactivated ? 'warning' : 'success',
                                 resp.msg || 'Category deleted.');
                    });
                } else { fmCatMsg('danger', (resp && resp.msg) || 'Could not delete the category.'); }
            }).fail(function(){ fmCatMsg('danger', 'Could not delete the category.'); });
        });

        function fmCatLabel(c){
            for(var i=0;i<FM_CATS.length;i++){
                if(String(FM_CATS[i].cat_key) === String(c)) return FM_CATS[i].name;
            }
            return FM_CAT_BUILTIN[c] || c;
        }
        /* Repaint every category picker from FM_CATS. Called after any category
           change so the main dropdown updates without a page reload. An inactive
           category is offered only to a finding that already carries it. */
        function fmCatRepaint(){
            if(!FM_CATS_DYNAMIC || !FM_CATS.length) return;
            var cur  = $('#fmCategory').val();
            var curF = $('#fmFilterCat').val();
            var opts = '', filt = '<option value="">All categories</option>';
            $.each(FM_CATS, function(_, c){
                var active = String(c.status) === '1';
                if(active || String(c.cat_key) === String(cur)){
                    opts += '<option value="'+blEsc(c.cat_key)+'">'+blEsc(c.name)+
                            (active ? '' : ' (inactive)')+'</option>';
                }
                if(active) filt += '<option value="'+blEsc(c.cat_key)+'">'+blEsc(c.name)+'</option>';
            });
            if(opts) $('#fmCategory').html(opts).val(cur);
            $('#fmFilterCat').html(filt).val(curF);
        }
        function fmWhen(v){
            if(!v) return '<span class="text-muted">&mdash;</span>';
            return blEsc(String(v).substring(0,10));
        }
        function fmBusy(on){
            $('#fmSave').prop('disabled', on).find('.fmSpin').toggle(!!on);
        }

        // ---- view switching -------------------------------------------------
        // which = 'List' | 'Form' | 'View'. The + New action belongs to the list
        // view only; compare case-insensitively so callers can't silently miss.
        function fmShow(which){
            var onList = String(which).toLowerCase() === 'list';
            $('#fmListView, #fmFormView, #fmViewView').hide();
            $('#fmNew').toggle(onList);
            /* Manage Categories is the library's own action, so it belongs to the
               list screen only — inside New/Edit or View it would be a second,
               competing entry point. It still obeys FM_CATS_DYNAMIC. */
            $('#fmCatManageHdr').toggleClass('d-none', !(onList && FM_CATS_DYNAMIC));
            $('#fm' + which + 'View').show();
        }

        // ---- filtering ------------------------------------------------------
        function fmFiltered(){
            var q    = $.trim(($('#fmSearch').val() || '')).toLowerCase();
            var cat  = $('#fmFilterCat').val();
            var st   = $('#fmFilterStatus').val();
            return $.grep(FM_ROWS, function(r){
                if(cat && r.category !== cat) return false;
                if(st !== '' && String(r.status) !== st) return false;
                if(q){
                    var hay = ((r.title||'') + ' ' +
                               (r.finding_content||'') + ' ' + (r.recommendation_content||'')).toLowerCase();
                    if(hay.indexOf(q) === -1) return false;
                }
                return true;
            });
        }

        // ---- list rendering -------------------------------------------------
        function fmRenderList(){
            var rows = fmFiltered();
            var pages = Math.max(1, Math.ceil(rows.length / FM_PER));
            if(FM_PAGE > pages) FM_PAGE = pages;
            var slice = rows.slice((FM_PAGE-1)*FM_PER, FM_PAGE*FM_PER);
            var $tb = $('#fmTable tbody').empty();

            if(!rows.length){
                $tb.append('<tr><td colspan="5" class="text-center text-muted py-4">'+
                           '<i class="fas fa-inbox fa-2x d-block mb-2"></i>'+
                           (FM_ROWS.length ? 'No findings match the current filters.'
                                           : 'No findings in the library yet. Use <strong>+ New</strong> to add one.')+
                           '</td></tr>');
            }
            $.each(slice, function(_, r){
                var active = String(r.status) === '1';
                var badge = active
                    ? '<span class="badge badge-success">Active</span>'
                    : '<span class="badge badge-secondary">Inactive</span>';
                $tb.append(
                  '<tr data-id="'+r.finding_id+'">'+
                    '<td><span class="font-weight-bold">'+blEsc(r.title)+'</span></td>'+
                    '<td class="small">'+blEsc(fmCatLabel(r.category))+'</td>'+
                    '<td>'+badge+'</td>'+
                    '<td class="small">'+fmWhen(r.updated_at)+'</td>'+
                    '<td class="text-right text-nowrap">'+
                      '<button type="button" class="btn btn-sm btn-outline-secondary fmView" title="View"><i class="fa fa-eye"></i></button> '+
                      '<button type="button" class="btn btn-sm btn-outline-primary fmEdit" title="Edit"><i class="fa fa-edit"></i></button> '+
                      '<button type="button" class="btn btn-sm btn-outline-danger fmDel" title="Delete"><i class="fa fa-trash"></i></button>'+
                    '</td>'+
                  '</tr>');
            });

            var from = rows.length ? ((FM_PAGE-1)*FM_PER + 1) : 0;
            var to   = Math.min(FM_PAGE*FM_PER, rows.length);
            $('#fmCount').html(rows.length
                ? 'Showing <strong>'+from+'&ndash;'+to+'</strong> of <strong>'+rows.length+'</strong> finding(s)'+
                  (rows.length !== FM_ROWS.length ? ' (filtered from '+FM_ROWS.length+')' : '')
                : '0 findings');

            var $p = $('#fmPager').empty();
            if(pages > 1){
                var li = function(label, page, dis, act){
                    return '<li class="page-item'+(dis?' disabled':'')+(act?' active':'')+'">'+
                           '<a class="page-link fmPage" href="#" data-page="'+page+'">'+label+'</a></li>';
                };
                $p.append(li('&laquo;', FM_PAGE-1, FM_PAGE===1, false));
                for(var i=1;i<=pages;i++){ $p.append(li(i, i, false, i===FM_PAGE)); }
                $p.append(li('&raquo;', FM_PAGE+1, FM_PAGE===pages, false));
            }
        }

        // Rebuild the Findings dropdown from the library so an entry added or
        // edited here is selectable immediately, with no page reload.
        // Every ACTIVE entry is offered, grouped so manual presets and
        // rule-based (auto-suggested) findings stay visually distinct.
        function fmRefreshFindingDropdown(rows){
            var $sel = $('#findingSelect');
            if(!$sel.length || !rows) return;
            // multi-select: preserve every selection that still exists
            var keep = $sel.val() || [];
            if(!$.isArray(keep)){ keep = (keep === '' || keep === null) ? [] : [keep]; }
            var stillIds = [];
            var manual = '', auto = '';
            var active = $.grep(rows, function(r){ return String(r.status) === '1'; });
            active.sort(function(a,b){
                var ar = (a.rule_key?1:0), br = (b.rule_key?1:0);
                if(ar !== br) return ar - br;
                if(a.category !== b.category) return (a.category||'').localeCompare(b.category||'');
                return (parseInt(a.sort_order,10)||0) - (parseInt(b.sort_order,10)||0);
            });
            $.each(active, function(_, r){
                if($.inArray(String(r.finding_id), $.map(keep, String)) !== -1){ stillIds.push(String(r.finding_id)); }
                var o = '<option value="'+r.finding_id+'">'+blEsc(r.title)+
                        (r.rule_key ? '  ['+blEsc(r.rule_key)+']' : '')+'</option>';
                if(r.rule_key){ auto += o; } else { manual += o; }
            });
            var opts = '';
            if(manual) opts += '<optgroup label="Manual findings">'+manual+'</optgroup>';
            if(auto)   opts += '<optgroup label="Automatic (rule-based) — normally applied by the evaluation">'+auto+'</optgroup>';
            $sel.html(opts).val(stillIds);
            if($sel.hasClass('select2-hidden-accessible')){ $sel.trigger('change.select2'); }
            /* was `if(!still)` — an undefined variable that threw on every call and
               aborted the rest of this .done() chain. stillIds holds the selections
               that survived the reload. */
            if(!stillIds.length){ $('#findingTitleBar').hide(); }
        }

        function fmLoad(){
            return fmPost({fm_action:'list'}).done(function(resp){
                if(resp && resp.ok){
                    FM_CATS = resp.categories || [];
                    FM_CATS_DYNAMIC = !!resp.catsDynamic;
                    /* The Manage button only appears where the feature can actually work. */
                    /* fmShow() decides the header button per screen; this only
                       reflects whether the feature is available at all. */
                    if(!FM_CATS_DYNAMIC){ $('#fmCatManageHdr').addClass('d-none'); }
                    else if($('#fmListView').is(':visible')){ $('#fmCatManageHdr').removeClass('d-none'); }
                    fmCatRepaint();
                }
                if(resp && resp.ok){
                    FM_ROWS = resp.rows || [];
                    fmRenderList();
                    fmRefreshFindingDropdown(FM_ROWS);
                } else {
                    $('#fmTable tbody').html('<tr><td colspan="5" class="text-danger">'+((resp&&resp.msg)||'Could not load the library.')+'</td></tr>');
                }
            }).fail(function(){
                $('#fmTable tbody').html('<tr><td colspan="5" class="text-danger">Could not load the library.</td></tr>');
            });
        }

        function fmRowById(id){
            return $.grep(FM_ROWS, function(r){ return String(r.finding_id) === String(id); })[0];
        }

        // ---- form -----------------------------------------------------------
        // Finding/Recommendation content are authored in a WYSIWYG editor (Summernote —
        // the same library the Feedback step already uses for this content) so admins
        // format text with toolbar buttons and never type raw HTML. The stored value is
        // still HTML, so the rule engine, report generation, DB structure and APIs are
        // all unchanged; this only replaces the raw-<textarea> entry experience.
        function fmInitEditors(){
            var tb = [
                ['style', ['bold','italic','underline','clear']],
                ['para',  ['ul','ol','paragraph']]
            ];
            /* Built through the shared initialiser: it waits for the form
               screen to be on screen, destroys any instance left from the
               previous open, and keeps the toolbar from stealing focus. */
            mnSummernote($('#fmFinding, #fmRecommendation'), { toolbar: tb, height: 140 });
        }
        // Load HTML into the editor when present, else fall back to the plain textarea.
        /* ============ Category presets for this form ============
           The paired data model: fmCatPairs() turns the loaded library into
           [{finding, rec}, …] for one category, so item i on the finding side
           always has item i on the recommendation side. N findings therefore
           produce exactly N lines in each editor.

           The inserted block is delimited by markers so a later category change
           replaces the heading and its lines wholesale, and never touches
           whatever the admin typed around it. */
        var FM_CAT_START = '<!--FMCAT_START-->', FM_CAT_END = '<!--FMCAT_END-->';

        function fmCatStrip(html){
            var s = String(html || '');
            var a = s.indexOf(FM_CAT_START), b = s.indexOf(FM_CAT_END);
            if(a === -1 || b === -1 || b < a) return s;
            return s.slice(0, a) + s.slice(b + FM_CAT_END.length);
        }
        /* One entry contributes one line. Stored content may be a bare <li>, a
           <p> or a whole block, so the line is its plain text — that is what
           keeps the two sides index-aligned however an entry was authored. */
        function fmCatLine(html){
            return $.trim($('<div>').html(String(html || '')).text()).replace(/\s+/g, ' ');
        }
        /* Paired preset model for one category, built from the rows the list
           action already returns, so it reflects every add/edit immediately. */
        function fmCatPairs(catKey){
            var out = [];
            $.each(FM_ROWS, function(_, r){
                if(String(r.category) !== String(catKey)) return;
                if(String(r.status) !== '1') return;                 // inactive entries are not presets
                out.push({
                    finding: fmCatLine(r.finding_content) || fmCatLine(r.title),
                    rec:     fmCatLine(r.recommendation_content)
                });
            });
            return out;
        }
        /* A user-driven category change rewrites the block in both editors.
           fmReset()/fmFill() set the value with .val(), which does not fire
           change, so opening Add or Edit never clobbers loaded content. */
        $(document).on('change', '#fmCategory', function(){
            var key   = $(this).val();
            var pairs = fmCatPairs(key);
            var name  = fmCatLabel(key);

            var fBase = fmCatStrip(fmGetEditor('#fmFinding'));
            var rBase = fmCatStrip(fmGetEditor('#fmRecommendation'));

            if(!pairs.length){
                /* Nothing to insert: still drop any block from the old category
                   so a stale heading cannot be left behind. */
                fmSetEditor('#fmFinding', fBase);
                fmSetEditor('#fmRecommendation', rBase);
                return;
            }

            var head = '<p><strong>' + blEsc(name) + ':</strong></p>';
            var fL = '', rL = '';
            $.each(pairs, function(_, p){
                fL += '<li>' + blEsc(p.finding) + '</li>';
                /* an entry without a recommendation still emits a line, so the
                   indexes on the two sides stay in step */
                rL += '<li>' + (p.rec ? blEsc(p.rec) : '<br>') + '</li>';
            });

            fmSetEditor('#fmFinding',
                fBase + FM_CAT_START + head + '<ul>' + fL + '</ul>' + FM_CAT_END);
            fmSetEditor('#fmRecommendation',
                rBase + FM_CAT_START + head + '<ul>' + rL + '</ul>' + FM_CAT_END);

            /* the content requirement is satisfied now, so clear any stale
               validation marks rather than leaving the field looking invalid */
            $('#fmFinding').next('.note-editor').removeClass('is-invalid');
            $('#fmRecommendation').next('.note-editor').removeClass('is-invalid');
        });

        function fmSetEditor(sel, html){
            var $el = $(sel);
            /* Saved library entries often hold bare <li> items with no list
               parent; normalised here so they render as bullets and the list
               buttons act on a real list. See mnNormalizeHtml in css-jquery.php. */
            var code = (typeof window.mnNormalizeHtml === 'function')
                     ? window.mnNormalizeHtml(html || '')
                     : (html || '');
            if ($el.next('.note-editor').length){ $el.summernote('code', code); }
            else { $el.val(code); }
        }
        // Read the editor's HTML (what gets saved) — unchanged format for the backend.
        function fmGetEditor(sel){
            var $el = $(sel);
            return $el.next('.note-editor').length ? ($el.summernote('code') || '') : ($el.val() || '');
        }
        // Empty check by visible text (Summernote reports an empty editor as "<p><br></p>").
        function fmEditorEmpty(sel){ return $.trim($('<div>').html(fmGetEditor(sel)).text()) === ''; }
        function fmClearValidation(){ $('#fmForm .is-invalid').removeClass('is-invalid'); }

        function fmReset(){
            fmInitEditors();
            $('#fmId').val(0); $('#fmTitle').val(''); $('#fmCategory').val('parasitology');
            $('#fmStatus').val(1);
            fmSetEditor('#fmFinding', ''); fmSetEditor('#fmRecommendation', '');
            $('#fmFormTitle').text('New Finding');
            fmClearValidation();
        }
        function fmFill(row){
            fmInitEditors();
            $('#fmId').val(row.finding_id); $('#fmTitle').val(row.title);
            $('#fmCategory').val(row.category);
            $('#fmStatus').val(String(row.status));
            fmSetEditor('#fmFinding', row.finding_content || '');
            fmSetEditor('#fmRecommendation', row.recommendation_content || '');
            $('#fmFormTitle').text('Edit: ' + row.title);
            fmClearValidation();
        }

        $(document).on('shown.bs.modal', '#findingMasterModal', function(){
            FM_PAGE = 1; fmShow('List'); fmLoad();
        });
        $(document).on('click', '#fmNew',  function(){ fmReset(); fmShow('Form'); $('#fmTitle').focus(); });
        $(document).on('click', '#fmBack, #fmCancel, #fmViewBack', function(){ fmShow('List'); });
        $(document).on('input change', '#fmSearch, #fmFilterCat, #fmFilterStatus', function(){
            FM_PAGE = 1; fmRenderList();
        });
        $(document).on('click', '.fmPage', function(e){
            e.preventDefault();
            var pg = parseInt($(this).data('page'), 10);
            if(pg >= 1){ FM_PAGE = pg; fmRenderList(); }
        });

        $(document).on('click', '.fmEdit', function(){
            var row = fmRowById($(this).closest('tr').data('id'));
            if(row){ fmFill(row); fmShow('Form'); }
        });
        $(document).on('click', '.fmView', function(){
            var r = fmRowById($(this).closest('tr').data('id'));
            if(!r) return;
            $('#fmViewTitle').text(r.title);
            $('#fmViewMeta').html(
                '<span class="badge badge-light border mr-1">'+blEsc(fmCatLabel(r.category))+'</span>'+
                (String(r.status)==='1' ? '<span class="badge badge-success mr-1">Active</span>'
                                        : '<span class="badge badge-secondary mr-1">Inactive</span>')+
                '<span class="text-muted small ml-2">'+
                ' &middot; Last updated '+fmWhen(r.updated_at)+'</span>');
            $('#fmViewFinding').html(r.finding_content || '<em class="text-muted">No finding content.</em>');
            $('#fmViewRec').html(r.recommendation_content || '<em class="text-muted">No recommendation.</em>');
            $('#fmViewEdit').data('id', r.finding_id);
            fmShow('View');
        });
        $(document).on('click', '#fmViewEdit', function(){
            var row = fmRowById($(this).data('id'));
            if(row){ fmFill(row); fmShow('Form'); }
        });

        $(document).on('submit', '#fmForm', function(e){
            e.preventDefault();
            fmClearValidation();
            // Push the WYSIWYG HTML into the hidden textareas so serialize() posts exactly
            // the finding_content / recommendation_content HTML the backend already expects.
            $('#fmFinding').val(fmGetEditor('#fmFinding'));
            $('#fmRecommendation').val(fmGetEditor('#fmRecommendation'));
            var ok = true;
            if($.trim($('#fmTitle').val()) === ''){ $('#fmTitle').addClass('is-invalid'); ok = false; }
            if(fmEditorEmpty('#fmFinding')){ $('#fmFinding').next('.note-editor').addClass('is-invalid'); ok = false; }
            if(!ok){ fmToast('warning', 'Please complete the required fields.'); return; }

            fmBusy(true);
            var active = $('#fmStatus').val() === '1';
            fmPost($(this).serialize()).done(function(resp){
                fmBusy(false);
                if(resp && resp.ok){
                    fmLoad().done(function(){ fmShow('List'); });
                    fmReset();
                    fmToast('success', active
                        ? 'Saved and now available in the Findings dropdown.'
                        : 'Saved. It is inactive, so it will not appear in the dropdown.');
                } else {
                    fmToast('error', (resp && resp.msg) || 'Save failed.');
                }
            }).fail(function(){ fmBusy(false); fmToast('error', 'Save failed.'); });
        });

        $(document).on('click', '.fmDel', function(){
            var id  = $(this).closest('tr').data('id');
            var row = fmRowById(id);
            if(!row) return;
            Swal.fire({
                icon:'warning', title:'Delete this finding?',
                html:'<strong>'+blEsc(row.title)+'</strong><br><small class="text-muted">'+
                     'If it is referenced by saved evaluations it will be deactivated instead, so history is preserved.</small>',
                showCancelButton:true, confirmButtonText:'Delete', cancelButtonText:'Cancel'
            }).then(function(res){
                if(!res.value) return;
                fmPost({fm_action:'delete', finding_id:id}).done(function(resp){
                    if(resp && resp.ok){
                        fmLoad();
                        fmToast('success', resp.softDeleted ? resp.msg : 'Finding deleted.');
                    } else { fmToast('error', (resp && resp.msg) || 'Delete failed.'); }
                }).fail(function(){ fmToast('error', 'Delete failed.'); });
            });
        });
    });

    function validateTime(mnth, yr){
        //Disabled for entering past reslts
        if (mnth=="" || yr==""){
			return null;
		}
        var month = $("#month").val();
        var year = $("#year").val();
        var deadline = new Date(year, month, 21);
        if (deadline < new Date()) {
            $("#submitInfo").css('display','none');
            alert( "Deadline Closed for Month " + month );
            $("#month").val("");
            $("#year").val("");
        }else{
            $("#submitInfo").css('display','inline');
        }
    }

    function reqBlinded(totalMicroscopy){
      if(totalMicroscopy != 0){
        $("#tslides").prop("required", true);
        $("#nslides").prop("required", true); $("#nslides").attr("readonly", false);
        $("#pslides").prop("required", true); $("#pslides").attr("readonly", false);
        $("label[for = dateship]").text("Shipment Date:");
      }
      else{
        $("#tslides").prop("required", false);
        $("#nslides").prop("required", false); $("#nslides").attr("readonly", true); $("#nslides").val("");
        $("#pslides").prop("required", false); $("#pslides").attr("readonly", true); $("#pslides").val("");
        $("label[for = dateship]").text("Reporting Date:");
      }
    }

    function calTotalMalaria(tmic,trdt){
        var tmal = Number(tmic) + Number(trdt);
        $("#totalMalaria").val(tmal);
    }

    function calTotalSlides(pos,neg){
        var tsld = Number(pos) + Number(neg);
        $("#tslides").val(tsld);
    }

    function chkTotalMicroscopy(pos, neg, tmic){
         var tsld = Number(pos)+Number(neg);
         if(tsld > Number(tmic)){
            $("#pslides").val("");  $("#nslides").val(""); $("#tslides").val("");
            alert("Total Blinded Slides Selected cannot be greater than Total Microscopy Screened. Please check and re-enter values for Positive Slides Selected and Negative Slides Selected");
         }

    }


    function reqResult(microscopy){
      if(microscopy != "Nmps"){
        $("#pstage").prop("required", true);$("#pstage").attr("readonly", false);
        $("#pcount").prop("required", true); $("#pcount").attr("readonly", false);
        $("#wbcount").prop("required", true); $("#wbcount").attr("readonly", false);
        // $("#density").attr("readonly", false);
      }
      else{
        $("#pstage").prop("required", false); $("#pstage").val(""); $("#pstage").attr("readonly", true);
        $("#pcount").prop("required", false); $("#pcount").val(""); $("#pcount").attr("readonly", true);
        $("#wbcount").prop("required", false); $("#wbcount").val("");$("#wbcount").attr("readonly", true);
        // $("#density").val(""); $("#density").attr("readonly", true); $("#min").val("");  $("#max").val("");
      }
      updateLabDensity();
    }
    // UI-only live preview. The server remains the source of truth when the
    // existing form is submitted and continues to calculate the stored value.
    function updateLabDensity(){
        var $density = $('#density');
        if (!$density.length) return;
        if ($('#microscopy').val() === 'Nmps') { $density.val(''); return; }
        var parasites = Number($('#pcount').val());
        var wbc = Number($('#wbcount').val());
        if ($('#pcount').val() === '' || $('#wbcount').val() === '' || !isFinite(parasites) || !isFinite(wbc) || wbc <= 0){
            $density.val('');
            return;
        }
        $density.val(Math.round((parasites / wbc) * 8000));
    }

    function updateRowDensity($row){
        var $density = $row.find('.s-density');
        if (!$density.length) return;
        if ($row.find('.s-mic').val() === 'Nmps') { $density.val(''); return; }
        var $parasites = $row.find('.s-pcount');
        var $wbc = $row.find('.s-wbc');
        var parasites = Number($parasites.val());
        var wbc = Number($wbc.val());
        if ($parasites.val() === '' || $wbc.val() === '' || !isFinite(parasites) || !isFinite(wbc) || wbc <= 0){
            $density.val('');
            return;
        }
        $density.val(Math.round((parasites / wbc) * 8000));
    }

    function reqResultc(microscopyc){
      if(microscopyc != "Nmps"){
        $("#pstagec").prop("required", true).prop("disabled", false).attr("readonly", false);
        $("#pcountc").prop("required", true).prop("readonly", false);
        $("#wbcountc").prop("required", true).prop("readonly", false);
        // $("#densityc").attr("readonly", true);
      }
      else{
        $("#pstagec").prop("required", false).val("").prop("disabled", true).attr("readonly", true);
        $("#pcountc").prop("required", false).val("").prop("readonly", true);
        $("#wbcountc").prop("required", false).val("").prop("readonly", true);
        // $("#densityc").val(""); $("#densityc").attr("readonly", true); $("#min").val("");  $("#max").val("");
      }
      if ($("#pstagec_mirror").length) {
        $("#pstagec_mirror").val($("#pstagec").val());
      }
      updateC1Density();
    }

    // Controller 1 live Parasite Density (UI preview only). Mirrors the server:
    // calcDensity() = parasite / WBC * 8000, stored in the integer c1_density
    // column (so shown rounded); Nmps, blank, non-numeric, negative or zero WBC
    // gives no density. iu-blinded.php recalculates on save and ignores this field.
    function updateC1Density(){
        var $d = $('#densityc');
        if (!$d.length) return;
        var $p = $('#pcountc'), $w = $('#wbcountc');
        var pRaw = $.trim($p.val()), wRaw = $.trim($w.val());
        var p = Number(pRaw), w = Number(wRaw);
        var pBad = (pRaw !== '' && (!isFinite(p) || p < 0));
        var wBad = (wRaw !== '' && (!isFinite(w) || w < 0));
        $p.toggleClass('is-invalid', pBad);
        $w.toggleClass('is-invalid', wBad);
        if ($('#microscopyc').val() === 'Nmps' || pRaw === '' || wRaw === '' || pBad || wBad || w === 0){
            $d.val('');
            return;
        }
        $d.val(Math.round((p / w) * 8000));
    }
    /* Controller 2 live Parasite Density. One row of the C2 table at a time, so
       every slide calculates independently. Mirrors the server exactly:
       calcDensity() = parasite / WBC * 8000 stored in the integer c2_density
       column (hence Math.round); a negative result (Nmps), a blank/non-numeric
       count or a zero/blank WBC leaves the density empty, which is the NULL the
       server stores. iu-blinded.php recalculates on submit and ignores the
       posted density, so this stays a preview and never becomes the source. */
    function updateC2RowDensity($row){
        var $d = $row.find('.c2-density');
        if (!$d.length) return;
        var $p = $row.find('.c2-pcount'), $w = $row.find('.c2-wbc');
        var pRaw = $.trim($p.val()), wRaw = $.trim($w.val());
        var p = Number(pRaw), w = Number(wRaw);
        var pBad = (pRaw !== '' && (!isFinite(p) || p < 0));
        var wBad = (wRaw !== '' && (!isFinite(w) || w < 0));
        $p.toggleClass('is-invalid', pBad);
        $w.toggleClass('is-invalid', wBad);
        if ($row.find('.c2-mic').val() === 'Nmps' || pRaw === '' || wRaw === '' || pBad || wBad || w === 0){
            $d.val('');
            return;
        }
        $d.val(Math.round((p / w) * 8000));
    }
    // A negative row carries no stage or counts (the same rule the server applies
    // when it writes NULLs for an Nmps row), so clear and lock them for that row only.
    function applyC2RowNegative($row){
        var neg = ($row.find('.c2-mic').val() === 'Nmps');
        var $f  = $row.find('.c2-stage, .c2-pcount, .c2-wbc');
        if (neg){ $f.val('').removeClass('is-invalid'); }
        // Finalized record: Controller 2 is read-only, so keep the whole row locked
        // (this runs on load for every row and would otherwise clear the readonly /
        // disabled attributes the server rendered).
        if (typeof BL_FINALIZED !== 'undefined' && BL_FINALIZED){
            $row.find('.c2-mic, .c2-stage').prop('disabled', true);
            $row.find('.c2-pcount, .c2-wbc').prop('readonly', true);
            updateC2RowDensity($row);
            return;
        }
        $f.prop('readonly', false).prop('disabled', false);
        $row.find('.c2-stage').prop('disabled', neg).prop('required', !neg);            // a select cannot be readonly
        $row.find('.c2-pcount, .c2-wbc').prop('readonly', neg).prop('required', !neg);
        updateC2RowDensity($row);
    }
    $(function(){
        $(document).on('change', '#pstagec', function(){
            if ($('#pstagec_mirror').length) {
                $('#pstagec_mirror').val($(this).val());
            }
        });
        $(document).on('input change paste keyup', '.c2-pcount, .c2-wbc', function(){
            var $row = $(this).closest('tr');
            // paste fires before the value lands; defer one tick so the new value is read
            setTimeout(function(){ updateC2RowDensity($row); }, 0);
        });
        $(document).on('change', '.c2-mic', function(){
            applyC2RowNegative($(this).closest('tr'));
        });
        // A disabled select posts nothing, which would shift the parallel
        // resultID[]/stages[] arrays — re-enable every negative row's stage on
        // submit (its value is already blank, and the server nulls it anyway).
        $(document).on('submit', '#controller2', function(){
            $(this).find('.c2-stage:disabled').prop('disabled', false);
        });
        $('#pane-c2 tr.c2-row').each(function(){ applyC2RowNegative($(this)); });
    });

    $(function(){
        // Export is a plain file download (no page change); block rapid repeat clicks.
        $(document).on('click', '#c1ExportBtn', function(e){
            var $b = $(this);
            if ($b.hasClass('disabled')) { e.preventDefault(); return; }
            $b.addClass('disabled');
            setTimeout(function(){ $b.removeClass('disabled'); }, 3000);
        });
        $(document).on('input change paste keyup', '#pcountc, #wbcountc', function(){
            // paste fires before the value lands; defer one tick so the new value is read
            setTimeout(updateC1Density, 0);
        });
        updateC1Density();
    });

/* ---------------------------------------------------------------------
   One confirmation for every delete in this module. Any link carrying
   `js-confirm-delete` asks the same question and, when confirmed, simply
   follows its own href — so the endpoint, its parameters and its server
   side checks are untouched.
   Deliberately says nothing about dependent records: the server decides
   what a delete removes, and listing it here only made the dialog long.
--------------------------------------------------------------------- */
$(document).on('click', '.js-confirm-delete', function (e) {
    e.preventDefault();
    var href = $(this).attr('href');
    if (!href) { return; }
    if (typeof Swal === 'undefined') {           // no dialog library: still ask
        if (confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
            window.location = href;
        }
        return;
    }
    Swal.fire({
        title: 'Confirm Deletion',
        text: 'Are you sure you want to delete this record? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        reverseButtons: true,                     // Cancel on the left, Delete on the right
        focusCancel: true,
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel',
        buttonsStyling: false,
        customClass: { confirmButton: 'btn btn-danger', cancelButton: 'btn btn-secondary mr-2' }
    }).then(function (res) {
        if (res && (res.isConfirmed || res.value)) { window.location = href; }
    });
});

    /* Reopen is NOT a delete — it clears no data and can be undone by
       finalizing again — so it keeps its own short, accurate question rather
       than the standard deletion wording. */
    function confirmReopen() {
        return confirm('Reopen this finalized report so it can be edited?');
    }


    /* ================================================================= */
    /*  Step-wise validation helpers (shared by the intake & process      */
    /*  wizards). Same rules for New and Edit workflows.                  */
    /* ================================================================= */

    // Validate every filled slide row in a container; marks offending fields
    // inline with .is-invalid and returns {valid, count}. Untouched blank new
    // rows are ignored (same semantics the save handlers always used).
    function validateSlideRows(sel){
        var valid = true, count = 0;
        $(sel).find('tr.slide-row').each(function(){
            var r = $(this);
            r.find('.is-invalid').removeClass('is-invalid');
            var rid   = r.find('.s-rid').val() || '';
            var bsno  = $.trim(r.find('.s-bsno').val());
            var doc   = $.trim(r.find('.s-doc').val());
            var mic   = r.find('.s-mic').val();
            var stage = r.find('.s-stage').val();
            var pc    = r.find('.s-pcount').val();
            var wb    = r.find('.s-wbc').val();
            if (rid === '' && bsno === '' && (mic === '' || mic === null)) return;   // untouched new row
            count++;
            if (bsno === ''){ r.find('.s-bsno').addClass('is-invalid'); valid = false; }
            if (doc === ''){ r.find('.s-doc').addClass('is-invalid'); valid = false; }
            if (mic === '' || mic === null){ r.find('.s-mic').addClass('is-invalid'); valid = false; }
            if (mic !== 'Nmps' && mic !== '' && mic !== null){
                if (stage === '' || stage === null){ r.find('.s-stage').addClass('is-invalid'); valid = false; }
                if (pc === ''){ r.find('.s-pcount').addClass('is-invalid'); valid = false; }
                if (wb === ''){ r.find('.s-wbc').addClass('is-invalid'); valid = false; }
            }
        });
        return { valid: valid, count: count };
    }
    var SLIDE_ROWS_MSG = 'Please complete all slide fields (B/S No, Collection Date, Microscopy; plus Stage & Counts for positive slides). Invalid fields are highlighted in red.';

    $(function(){
        $(document).on('input change', '#pcount, #wbcount', updateLabDensity);
        $(document).on('input change', 'tr.slide-row .s-pcount, tr.slide-row .s-wbc', function(){
            updateRowDensity($(this).closest('tr.slide-row'));
        });
        $('tr.slide-row').each(function(){ updateRowDensity($(this)); });
        updateLabDensity();
        // Clear the inline highlight as soon as the user corrects a field.
        $(document).on('input change', 'tr.slide-row .is-invalid', function(){ $(this).removeClass('is-invalid'); });

        // Inline validation for the MP Slide Information form (intake step 1 and
        // the process wizard's Info pane share the same form). Number fields keep
        // their existing HTML `required`/`min` attributes; the selects and the
        // shipment/reporting date are made mandatory here with inline messages.
        if ($('#blindedInfo').length && $.fn.validate){
            $('#blindedInfo').validate({
                ignore: [],
                rules: {
                    dzongkhag: 'required',
                    facility:  'required',
                    month:     'required',
                    year:      'required',
                    dateship:  'required'
                },
                messages: { dateship: 'This field is required.' },
                errorElement: 'span',
                errorPlacement: function(error, element){
                    error.addClass('invalid-feedback d-block');
                    element.closest('.form-group').append(error);
                },
                highlight: function(el){ $(el).addClass('is-invalid'); },
                unhighlight: function(el){ $(el).removeClass('is-invalid'); }
            });
            // Selects (incl. select2) re-validate immediately on change once marked.
            $('#blindedInfo').on('change', 'select', function(){
                if ($('#blindedInfo').data('validator')){ $(this).valid(); }
            });
        }

        // Apply the conditional required/readonly state for prefilled result forms
        // (previously only applied onchange, so a prefilled positive slide could be
        // saved with cleared stage/counts).
        if ($('#microscopy').length)  reqResult($('#microscopy').val());
        if ($('#microscopyc').length) reqResultc($('#microscopyc').val());
    });

    /* ============ View <-> Process mode toggle (action==2) ============ */
    function showView(){
        $('#viewMode').show();
        $('#processMode').hide();
        $('#btnView').removeClass('btn-outline-primary').addClass('btn-primary');
        $('#btnProcess').removeClass('btn-primary').addClass('btn-outline-primary');
        syncModeBar();
    }
    function showProcess(){
        $('#viewMode').hide();
        $('#processMode').show();
        $('#btnProcess').removeClass('btn-outline-primary').addClass('btn-primary');
        $('#btnView').removeClass('btn-primary').addClass('btn-outline-primary');
        syncModeBar();
    }

    /* Page-mode header visibility. For both roles the header is hidden while
       working through wizard steps and reappears on the final Review step —
       Admin/RCDC gets Process/Edit (left) + Print Feedback Report (right) there
       and in View mode; the Review pane itself carries no action buttons. */
    var BL_IS_DISTRICT = <?= $isRCDC ? 'false' : 'true' ?>;
    // Shipment receipt already recorded on the server (edit mode)
    var BL_SHIPMENT_RECEIVED = <?= (isset($dorec) && $dorec !== null && $dorec !== '') ? 'true' : 'false' ?>;
    // Server-side stage completion (edit mode) — drives forward-navigation gating
    // so a step must be completed & saved before the wizard moves past it.
    var BL_RDT_ONLY = <?= (isset($binfo[4]) && $binfo[4] !== '' && $binfo[4] == 0) ? 'true' : 'false' ?>;
    // Per-stage completion of the SAVED record (server truth). Drives the stepper in
    // Edit/View mode through the shared helper (dist/js/mneqas-stepper-state.js), so a
    // stage that is already saved never shows as "Pending" just because the user is
    // standing on an earlier step. Same flags the status timeline uses.
    var BL_DONE_STAGES = <?= json_encode(array_values(array_filter(array(
        'pane-info',
        (isset($stSlides)   && $stSlides)   ? 'pane-district' : null,
        (isset($stReceived) && $stReceived) ? 'pane-shipment' : null,
        (isset($stC1)       && $stC1)       ? 'pane-c1'       : null,
        (isset($stC2)       && $stC2)       ? 'pane-c2'       : null,
        (isset($stFeedback) && $stFeedback) ? 'pane-feedback' : null,
        (isset($isFinalized)&& $isFinalized)? 'pane-review'   : null
    )))) ?>;
    var BL_ST = {
        slides:   <?= (isset($stSlides) && $stSlides) ? 'true' : 'false' ?>,           // district lab results saved
        c1done:   <?= (isset($stC1) && $stC1) ? 'true' : 'false' ?>,                   // at least one intact slide, and every one has a C1 result
        c2done:   <?= (isset($stC2) && $stC2) ? 'true' : 'false' ?>,         // Controller 2 results saved
        feedback: <?= (isset($stFeedback) && $stFeedback) ? 'true' : 'false' ?>        // feedback (finding) saved
    };
    // Finalization state. Final Submit (Review step) flips BL_FINALIZED and
    // reveals the Print Feedback Report button. (BL_ID is declared further below
    // from the URL param and is what the finalize POST uses.)
    var BL_FINALIZED = <?= (isset($isFinalized) && $isFinalized) ? 'true' : 'false' ?>;
    // Final Submit (final approval) is Administrator-only; enforced in iu-blinded.php too.
    /* Final Submit is an RCDC stage (Administrator or RCDC Labs); iu-blinded.php
       enforces the same rule, so hiding the button is presentation, not the control. */
    var BL_CAN_FINALIZE = <?= mn_is_rcdc() ? 'true' : 'false' ?>;
    /* Reopening a finalized report is Administrator-only; delete.php enforces
       the same rule, so hiding the button is presentation, not the control. */
    var BL_CAN_REOPEN   = <?= mn_is_admin() ? 'true' : 'false' ?>;
    function syncModeBar(){
        var $bar = $('#modeToggleBar');
        if (!$bar.length) return;
        var inProcess = $('#processMode').is(':visible');
        var onReview  = inProcess && procPaneEls.length && (procIdx === procPaneEls.length - 1);
        // Show the mode bar (which carries the Print Feedback Report button) only in
        // View mode or on the final Review step. NOTE: the bar has Bootstrap's
        // `d-flex` (display:flex !important), so jQuery .toggle()/.hide() — which sets
        // an inline `display:none` — is overridden by the !important rule and can
        // never hide it. Toggle the display *classes* instead so it wins.
        var show = (!inProcess || onReview);
        $bar.toggleClass('d-flex', show).toggleClass('d-none', !show);
    }

    /* ============ Final Submit (finalize) ============
       Enterprise flow: Preview -> Review (read-only) -> Final Submit -> Print.
       Finalizing sets a persistent flag (blinded_feedback.finalized) via
       iu-blinded.php and reveals the Print Feedback Report button. The record
       stays editable afterwards (this is a Print gate, not a hard lock). */
    function revealPrintButton(){ $('#btnPrintFeedback').removeClass('d-none'); }

    function finalizeRecord(){
        if (BL_FINALIZED || BL_ID === null) return;
        Swal.fire({
            icon: 'question',
            title: 'Final Submit?',
            html: 'This finalizes the record and enables the <strong>Print Feedback Report</strong>.<br>You can still edit the record afterwards if needed.',
            showCancelButton: true,
            confirmButtonText: 'Yes, Final Submit',
            cancelButtonText: 'Cancel'
        }).then(function(res){
            if (!res.value) return;
            var $btn = $('#procFinalSubmitBtn').prop('disabled', true);
            $.ajax({
                url: 'iu-blinded.php',
                method: 'POST',
                dataType: 'json',
                data: { submit: 'finalize', id: BL_ID }
            }).done(function(resp){
                if (resp && resp.ok){
                    BL_FINALIZED = true;
                    $('#procFinalSubmitBtn').hide();
                    revealPrintButton();
                    BL_REVIEW_STALE = true;            // re-render the Review summary with fresh data
                    // Review stage is now Completed — update the stepper without a reload.
                    $('#procWizardNav .wizard-step[data-paneid="pane-review"]').addClass('done');
                    refreshPane(procIdx, function(){ syncModeBar(); });
                    Swal.fire({ icon: 'success', title: 'Finalized', text: 'The record has been finalized. You can now print the Feedback Report.' });
                } else {
                    $btn.prop('disabled', false);
                    Swal.fire({ icon: 'error', title: 'Not finalized', text: (resp && resp.msg) ? resp.msg : 'Could not finalize the record. Please try again.' });
                }
            }).fail(function(xhr){
                $btn.prop('disabled', false);
                Swal.fire({
                    icon: 'error',
                    title: 'Not finalized',
                    text: blFailMessage(xhr, 'Could not finalize the record. Please try again.')
                });
            });
        });
    }

    /* ============ Process wizard stepper (Back / Next / jump) ============ */
    var procPaneEls = [], procIdx = 0;

    // Canonical unified roadmap for admin — always shown in full so the admin
    // sees every stage from the start. Stages whose prerequisites aren't met yet
    // aren't rendered as panes; they appear as locked "ghost" steps.
    var PROC_CANON = [
        {id:'pane-info',     title:'MP Slide Info',      icon:'fa-file-medical'},
        {id:'pane-district', title:'District Results',   icon:'fa-clipboard-check'},
        {id:'pane-shipment', title:'Shipment Receive',   icon:'fa-truck'},
        {id:'pane-c1',       title:'Controller 1',       icon:'fa-user-check'},
        {id:'pane-c2',       title:'Controller 2',       icon:'fa-user-shield'},
        {id:'pane-feedback', title:'Feedback &amp; Actions', icon:'fa-comments'},
        {id:'pane-review',   title:'Review',             icon:'fa-clipboard-list'}
    ];
    /* Presentational icon lookup (by pane id) for the stepper circles. */
    var STEP_ICONS = {
        'pane-info':'fa-file-medical', 'pane-district':'fa-clipboard-check',
        'pane-shipment':'fa-truck', 'pane-c1':'fa-user-check',
        'pane-c2':'fa-user-shield', 'pane-feedback':'fa-comments',
        'pane-review':'fa-clipboard-list'
    };
    function wzIcon(id){ return STEP_ICONS[id] || 'fa-circle'; }
    function paneIndexById(id){
        for (var i=0;i<procPaneEls.length;i++){ if (procPaneEls[i].id===id) return i; }
        return -1;
    }
    // Canonical position: admin uses the fixed roadmap; district follows the
    // panes it actually has (unchanged behaviour).
    function canonPos(id){
        if (!BL_IS_DISTRICT){
            for (var i=0;i<PROC_CANON.length;i++){ if (PROC_CANON[i].id===id) return i; }
            return -1;
        }
        return paneIndexById(id);
    }

    function buildProcNav(){
        procPaneEls = $('#procPanes > .p-pane').toArray();
        var nav = '';
        if (!BL_IS_DISTRICT){
            // Admin: full 7-step roadmap. Status (Pending / In Progress / Completed)
            // is assigned by showProcStep positionally; steps start Pending here.
            PROC_CANON.forEach(function(step, c){
                nav += '<div class="wizard-step" data-paneid="'+step.id+'" data-step="'+(c+1)+'" role="listitem" tabindex="0" aria-label="'+ step.title +'" onclick="gotoProcById(\''+step.id+'\')"><span class="num">'+ (c+1) +'</span>'+ step.title +'</div>';
            });
        } else {
            // District: stepper follows the rendered panes (unchanged).
            procPaneEls.forEach(function(el, i){
                var pid = (el.id||'');
                nav += '<div class="wizard-step" data-paneid="'+pid+'" data-idx="'+i+'" role="listitem" tabindex="0" aria-label="'+ ($(el).data('title')||'') +'" onclick="showProcStep('+i+')"><span class="num">'+ (i+1) +'</span>'+ $(el).data('title') +'</div>';
            });
        }
        $('#procWizardNav').attr({'role':'list','aria-label':'Blinded Rechecking workflow steps'}).html(nav);
    }

    // A ghost stage (Controller 2 / Feedback) is "available" once its prerequisite
    // is met — its pane just needs a reload to be rendered fully functional.
    function ghostAvailable(id){
        // Controller 2 pane appears once every intact slide has a C1 result.
        if (id === 'pane-c2')       return BL_ST.c1done || BL_C2_NOW_READY;
        // Feedback pane appears only after Controller 2 is SUBMITTED (that write
        // creates the feedback/scores row). A merely seeded C2 isn't enough, so
        // rely on the run-time signal that the pane actually became available.
        if (id === 'pane-feedback') return BL_FEEDBACK_NOW_READY;
        return false;
    }
    function reloadIntoPane(id){
        sessionStorage.setItem('bl_mode', 'process');
        sessionStorage.setItem('bl_pane', id);
        window.location = 'blinded.php?action=2&id=' + encodeURIComponent(BL_ID);
    }
    // Stepper click: go to a rendered pane; reload into a now-available ghost; or
    // explain why a locked step can't be reached yet.
    function gotoProcById(id){
        if (goPaneNeedsReload(id)) return;
        var idx = paneIndexById(id);
        if (idx >= 0){ showProcStep(idx); return; }
        if (ghostAvailable(id)){ reloadIntoPane(id); return; }
        Swal.fire({icon:'info', text: procLockMsgById(id), confirmButtonText:'OK'});
    }

    /* Step completion for forward-navigation gating. A pane counts as complete
       only when its stage is saved on the server (flags above), saved in this
       session (procSubmitted / *_NOW_READY), or — for the district bulk-edit
       step, whose save is wizard-final by design — when its rows validate. */
    function procPaneComplete(i){
        var id = (procPaneEls[i] && procPaneEls[i].id) || '';
        switch (id){
            case 'pane-info':
                return true;                                  // header saved at intake
            case 'pane-district':
                if (document.getElementById('rcdcSlideRows')){
                    // Admin/RCDC: complete only when every selected slide is saved
                    // (server-rendered count) and no edits are pending. Once the
                    // shipment is received no slides can be added, so an older
                    // record is not dead-ended by the count rule.
                    if (BL_RDT_ONLY) return true;
                    if (BL_RCDC_SLIDES_DIRTY) return false;
                    var $rc = $('#rcdcSlideCard');
                    var rsTotal = parseInt($rc.data('total'), 10) || 0;
                    var rsSaved = parseInt($rc.data('saved'), 10) || 0;
                    return (rsTotal > 0 && rsSaved >= rsTotal) || String($rc.data('received')) === '1';
                }
                if (document.getElementById('editSlideRows')){
                    var chk = validateSlideRows('#editSlideRows');
                    if (!chk.valid) return false;             // invalid rows: stay & fix (highlighted)
                    return chk.count > 0 || BL_RDT_ONLY || BL_ST.slides;
                }
                return BL_RDT_ONLY || BL_ST.slides;
            case 'pane-shipment':
                return BL_SHIPMENT_RECEIVED || !!procSubmitted[i];
            case 'pane-c1':
                return BL_ST.c1done || BL_C2_NOW_READY;       // ALL intact slides rechecked
            case 'pane-c2':
                // A finalized record's Controller 2 is read-only, so it never blocks
                // the walk forward to Feedback / Review.
                return BL_FINALIZED || BL_ST.c2done || BL_FEEDBACK_NOW_READY || !!procSubmitted[i];
            case 'pane-feedback':
                return BL_ST.feedback || !!procSubmitted[i];
        }
        return true;
    }
    function procLockMsgById(id){
        switch (id){
            case 'pane-district':
                if (document.getElementById('rcdcSlideRows')){
                    if (BL_RCDC_SLIDES_DIRTY) return 'You have unsaved slide changes. Click Save All Slides before continuing.';
                    return 'Please enter all ' + (parseInt($('#rcdcSlideCard').data('total'), 10) || 0) + ' selected MP slide results before continuing.';
                }
                if (document.getElementById('editSlideRows') && !validateSlideRows('#editSlideRows').valid){
                    return SLIDE_ROWS_MSG;
                }
                return 'District lab results must be entered before continuing.';
            case 'pane-shipment': return 'Save the Shipment Receive information to continue.';
            case 'pane-c1':       return 'Enter and save Controller 1 results for every intact slide to continue.';
            case 'pane-c2':       return 'Submit the Controller 2 results to continue.';
            case 'pane-feedback': return 'Submit the Feedback & Actions to continue.';
            case 'pane-review':   return 'Complete the earlier steps to reach Review.';
        }
        return 'Please complete and save this step before continuing.';
    }
    function procLockMsg(i){
        return procLockMsgById((procPaneEls[i] && procPaneEls[i].id) || '');
    }

    function showProcStep(i){
        if (i < 0 || i >= procPaneEls.length) return;
        // Moving forward requires every step on the way to be completed & saved
        // (going back is always allowed and never loses saved data).
        if (i > procIdx){
            // Walk the CANONICAL steps between here and the target so that locked
            // ghost stages (Controller 2 / Feedback) on the path are enforced too —
            // a jump can't skip an unfinished stage even if its pane isn't rendered.
            var curC = canonPos(procPaneEls[procIdx].id || '');
            var tgtC = canonPos(procPaneEls[i].id || '');
            if (curC > -1 && tgtC > -1){
                for (var cp = curC; cp < tgtC; cp++){
                    var stepId = (!BL_IS_DISTRICT) ? PROC_CANON[cp].id
                                                   : (procPaneEls[cp] && procPaneEls[cp].id) || '';
                    var rp = paneIndexById(stepId);
                    if (rp >= 0){
                        if (!procPaneComplete(rp)){
                            if (rp !== procIdx){ showProcStep(rp); }   // land on the first incomplete step
                            Swal.fire({icon:'info', text: procLockMsgById(stepId), confirmButtonText:'OK'});
                            return;
                        }
                    } else {
                        // Ghost stage on the path: reload into it if now available, else block.
                        if (ghostAvailable(stepId)){ reloadIntoPane(stepId); return; }
                        Swal.fire({icon:'info', text: procLockMsgById(stepId), confirmButtonText:'OK'});
                        return;
                    }
                }
            }
        }
        procIdx = i;
        $(procPaneEls).hide();
        $(procPaneEls[i]).show();
        var curId = procPaneEls[i].id || '';
        var curCanon = canonPos(curId);
        // Completeness of each canonical step (ghosts are never "complete").
        function canonStepComplete(cpos){
            var id = (!BL_IS_DISTRICT) ? (PROC_CANON[cpos] && PROC_CANON[cpos].id)
                                       : ((procPaneEls[cpos] && procPaneEls[cpos].id) || '');
            var rp = paneIndexById(id);
            return rp >= 0 ? procPaneComplete(rp) : false;
        }
        var $navSteps = $('#procWizardNav .wizard-step');
        if (!BL_IS_DISTRICT && window.mnStepperState){
            // ADMIN / RCDC: status comes from the SAVED record (BL_DONE_STAGES) plus any
            // stage saved in this session, never from the position of the step you happen
            // to be standing on. Finalised record => every stage Completed, current one
            // also Active. Draft => saved stages Completed, current In Progress, rest Pending.
            var doneKeys = (BL_DONE_STAGES || []).slice();
            Object.keys(procSubmitted || {}).forEach(function(idx){      // procSubmitted: idx -> true
                var pid = (procPaneEls[idx] && procPaneEls[idx].id) || '';
                if (procSubmitted[idx] && pid && doneKeys.indexOf(pid) === -1){ doneKeys.push(pid); }
            });
            if (BL_FINALIZED && doneKeys.indexOf('pane-review') === -1){ doneKeys.push('pane-review'); }
            var adminState = mnStepperState({
                steps:     $navSteps.map(function(){ return $(this).data('paneid'); }).get(),
                current:   curId,
                completed: BL_FINALIZED,
                done:      doneKeys
            });
            mnApplyStepperState($navSteps, adminState, function($el){ return $el.data('paneid'); });
        } else
        $navSteps.each(function(){
            var pid  = $(this).data('paneid');
            var pidx = paneIndexById(pid);
            var cpos = canonPos(pid);
            $(this).removeClass('active done locked').removeAttr('aria-current');
            if (pid === curId){ $(this).addClass('active').attr('aria-current','step'); return; }
            // DISTRICT (user) workflow — unchanged behaviour.
            var complete = (cpos > -1) ? canonStepComplete(cpos) : false;
            if (complete
                || (cpos > -1 && curCanon > -1 && cpos < curCanon)
                || (pidx > -1 && procSubmitted[pidx])){
                $(this).addClass('done'); return;
            }
            if (cpos > -1){
                var reachable = true;
                for (var e = 0; e < cpos; e++){ if (!canonStepComplete(e)){ reachable = false; break; } }
                if (!reachable){ $(this).addClass('locked'); }
            }
        });
        $('#procBackBtn').toggle(i > 0);
        updateProcFooter(i);
        syncModeBar();
        if (procPaneEls[i].id) sessionStorage.setItem('bl_pane', procPaneEls[i].id);
        // Review summary is rendered at page load; if any stage was saved this
        // session it is stale — re-fetch its fresh HTML so Findings, Recommendations,
        // Actions and scores reflect what was just saved (no full page reload).
        if (procPaneEls[i].id === 'pane-review' && BL_REVIEW_STALE){
            BL_REVIEW_STALE = false;
            refreshPane(i, function(){ syncModeBar(); });
        }
        // Fix DataTables column widths when revealed inside a hidden pane
        if ($.fn.dataTable) { try { $.fn.dataTable.tables({visible:true, api:true}).columns.adjust(); } catch(e){} }
    }

    function procNext(){
        // Controller 1 -> Controller 2 and Controller 2 -> Feedback: those panes
        // are only rendered when their data already existed at page load. If the
        // save that just happened made one available, reload straight into it so
        // the step opens fully functional (forms, not just view).
        var pane = procPaneEls[procIdx];
        var reloadTo = null;
        if (pane && pane.id === 'pane-c1' && BL_C2_NOW_READY && !document.getElementById('pane-c2')) reloadTo = 'pane-c2';
        if (pane && pane.id === 'pane-c2' && BL_FEEDBACK_NOW_READY && !document.getElementById('pane-feedback')) reloadTo = 'pane-feedback';
        if (reloadTo){
            sessionStorage.setItem('bl_mode', 'process');
            sessionStorage.setItem('bl_pane', reloadTo);
            window.location = 'blinded.php?action=2&id=' + encodeURIComponent(BL_ID);
            return;
        }
        var nextPane = procPaneEls[procIdx + 1];
        if (nextPane && goPaneNeedsReload(nextPane.id)) return;   // refresh stale Shipment pane
        showProcStep(procIdx + 1);
    }
    function procBack(){ showProcStep(procIdx - 1); }

    /* ================================================================= */
    /*  RCDC process wizard: single AJAX submit + in-place pane refresh.  */
    /*  Saves the active stage to iu-blinded.php, shows a success alert    */
    /*  and re-renders ONLY the current pane with fresh server data — no   */
    /*  page reload, the user stays on the same step.                      */
    /* ================================================================= */
    var procBusy = false;
    var procSubmitted = {};                                        // idx -> true once saved
    var BL_C2_NOW_READY = false;
    window.saveC1 = function(){
        if ($('#c1Resulrs').length){
            $('#c1Resulrs').submit();
        } else if ($('#procSaveBtn').length){
            saveCurrentStep();
        }
    };         // Controller 2 pane became available after a C1 save
    var BL_FEEDBACK_NOW_READY = false;   // Feedback pane became available after a C2 save
    var BL_DISTRICT_DIRTY = false;       // district slides added/edited this session -> Shipment pane is stale
    var BL_RCDC_SLIDES_DIRTY = false;    // Admin/RCDC District Results: unsaved edits in the slide table
    var BL_REVIEW_STALE = false;         // any stage saved this session -> Review summary (rendered at load) is stale
    var BL_ID = (new URLSearchParams(window.location.search)).get('id');

    // Shipment lists the slides entered in District Results. When those were added
    // this session the pane (rendered at page load) is stale, so reload into it —
    // same reload pattern the Shipment->Controller 1 hop already uses.
    function goPaneNeedsReload(targetId){
        if (targetId === 'pane-shipment' && BL_DISTRICT_DIRTY){ reloadIntoPane('pane-shipment'); return true; }
        return false;
    }

    // Submit buttons that the wizard footer is allowed to centralise.
    // Buttons inside a modal (e.g. the Controller 1 Excel upload dialog) are
    // NOT part of the step's form flow: counting them corrupted every
    // "does this pane own exactly one form?" decision below, which both hid
    // the upload dialog's own Submit and un-hid the C1 entry form's button.
    function procPaneSubmitButtons(pane){
        return $(pane).find('form button[type="submit"]').filter(function(){
            // Buttons inside a modal (the Excel upload dialog) and forms marked
            // data-wiz-standalone (the Controller 1 review/update form) own their
            // own action bar, so they are not centralised into the wizard footer.
            if ($(this).closest('.modal').length) { return false; }
            if ($(this).closest('form[data-wiz-standalone]').length) { return false; }
            return true;
        });
    }

    // Footer state for the active pane.
    function updateProcFooter(i){
        var isLast = (i === procPaneEls.length - 1);
        var paneId = procPaneEls[i].id || '';
        var nForms = procPaneSubmitButtons(procPaneEls[i]).length;
        var $view = $('#procViewBtn');   // Admin/RCDC footer only; empty set for district
        $('#procFinalSubmitBtn').hide(); // shown only on the Review step (re-shown below)

        if (!BL_IS_DISTRICT){
            // Restore the footer defaults the Controller 1 step overrides.
            $('#procCancelBtn').show();
            $('#procBtnGroup').addClass('ml-auto').removeClass('flex-grow-1 d-flex justify-content-between');
            // Admin/RCDC step 1 (MP Slide Info): navigation only.
            if (paneId === 'pane-info'){
                $('#procSaveBtn').hide();
                $view.hide();
                $('#procNextBtn').toggle(!isLast);
                return;
            }
            // Admin/RCDC step 2 (District Results): the single-slide entry form saves
            // through the wizard footer ("Save Slide"); the results table below keeps
            // growing. Next stays visible (gated: district complete once >=1 slide saved).
            if (paneId === 'pane-district'){
                var hasSlideForm = (nForms === 1);
                $view.hide();
                if (document.getElementById('rcdcSlideRows')){
                    // Multi-slide table: one "Save All Slides" while any row is editable.
                    var rsEditable = $('#rcdcSlideRows tr.slide-row').length > 0;
                    $('#procSaveBtn').toggle(rsEditable).html('<i class="fa fa-save"></i> Save All Slides');
                } else {
                    $('#procSaveBtn').toggle(hasSlideForm).html('<i class="fa fa-save"></i> Save Slide');
                }
                $('#procNextBtn').toggle(!isLast);
                return;
            }
            // Admin/RCDC step 3 (Shipment Receive): wizard-controlled Submit until the
            // receipt is saved (this session or previously), then it becomes Next.
            if (paneId === 'pane-shipment'){
                var received = BL_SHIPMENT_RECEIVED || !!procSubmitted[i];
                $view.hide();
                $('#procSaveBtn').toggle(!received).html('<i class="fa fa-check"></i> Submit');
                $('#procNextBtn').toggle(!isLast && received);
                return;
            }
            // Admin/RCDC Controller 1: while the results entry form is open the
            // footer shows only Previous (left) and Submit (right); after a
            // successful submission (or when just the results table is shown)
            // Submit gives way to Next.
            if (paneId === 'pane-c1'){
                var c1FormOpen = (nForms === 1) && !procSubmitted[i];
                $('#procCancelBtn').hide();
                $view.hide();
                $('#procBtnGroup').removeClass('ml-auto').addClass('flex-grow-1 d-flex justify-content-between');
                $('#procSaveBtn').toggle(c1FormOpen).html('<i class="fa fa-check"></i> Submit');
                $('#procNextBtn').toggle(!isLast && !c1FormOpen);
                return;
            }
            // Admin/RCDC Controller 2: Cancel (left), Previous and a wizard-controlled
            // Submit (right); no per-step save or View Summary. After a successful
            // submission Submit gives way to Next (Feedback step).
            if (paneId === 'pane-c2'){
                // Finalized: the step is read-only, so it offers Next rather than Submit.
                var c2FormOpen = (nForms === 1) && !procSubmitted[i] && !BL_FINALIZED;
                $view.hide();
                $('#procSaveBtn').toggle(c2FormOpen).html('<i class="fa fa-check"></i> Submit');
                $('#procNextBtn').toggle(!isLast && !c2FormOpen);
                return;
            }
            // Admin/RCDC Feedback & Actions: one combined form with a wizard-controlled
            // Submit; no View Summary here. After a successful submission Submit gives
            // way to Next (Review step).
            if (paneId === 'pane-feedback'){
                var fbFormOpen = (nForms === 1) && !procSubmitted[i];
                $view.hide();
                $('#procSaveBtn').toggle(fbFormOpen).html('<i class="fa fa-check"></i> Submit');
                $('#procNextBtn').toggle(!isLast && !fbFormOpen);
                return;
            }
            // Admin/RCDC Review: read-only verification step — only Cancel remains in
            // the footer (Edit / Print live in the pane header).
            if (paneId === 'pane-review'){
                $view.hide();
                $('#procSaveBtn').hide();
                $('#procNextBtn').hide();
                $('#procBackBtn').hide();
                // Read-only Review step: offer Final Submit until the record is
                // finalized. Once finalized, the Print button (mode bar) takes over.
                $('#procFinalSubmitBtn').toggle(!BL_IS_DISTRICT && BL_CAN_FINALIZE && !BL_FINALIZED);
                // Once finalized, the Administrator can return the record to draft.
                $('#procReopenBtn').toggle(!BL_IS_DISTRICT && BL_CAN_REOPEN && BL_FINALIZED);
                return;
            }
            $view.show();
        }

        // Next: hidden on the last step. RCDC additionally hides it once a step is
        // saved (the stepper is used to move on). District users always keep Next:
        // their steps have no per-step save, so re-entering Edit mode after a
        // submit must still allow walking forward to the Review step.
        $('#procNextBtn').toggle(!isLast && (BL_IS_DISTRICT || !procSubmitted[i]));
        // Save: whenever the pane owns exactly one form (stays available to re-save).
        // District users see "Submit" on the final (Review) step; RCDC keeps "Mark Complete".
        var lastLabel = BL_IS_DISTRICT
            ? '<i class="fa fa-check"></i> Submit'
            : '<i class="fas fa-flag-checkered"></i> Mark Complete';
        var $save = $('#procSaveBtn');
        if (nForms === 1){
            $save.show().html(isLast ? lastLabel : '<i class="fa fa-check"></i> Save Current Step');
        } else if (isLast){
            $save.show().html(lastLabel);
        } else {
            $save.hide();
        }
    }

    /* Feedback & Actions pane: rich-text editors + client-side validation for the
       combined form. Called on page load and again after the wizard's in-place
       pane refresh (which replaces the DOM and loses editors/validator). */
    var BL_SN_TOOLBAR = [
        ['style', ['bold', 'italic', 'underline', 'clear']],
        ['fontsize', ['fontsize']],
        ['color', ['color']],
        ['para', ['ul', 'ol', 'paragraph']]
    ];
    $.validator.addMethod('htmlRequired', function(value){
        return $.trim($('<div>').html(value).text()).length > 0;
    }, 'This field is required.');
    function initFeedbackEditors($ctx){
        /* Shared initialiser: the Feedback pane is hidden until the wizard
           opens it, and refreshPane() replaces this DOM wholesale, so the
           editors must be (re)built only once visible and never stacked. */
        mnSummernote($ctx.find('#finding, #recommendation, #actions'), { toolbar: BL_SN_TOOLBAR });
        var $form = $ctx.find('#feedbackForm');
        if ($form.length && !$form.data('validator')){
            $form.validate({
                ignore: [],
                rules: {
                    finding: { htmlRequired: true },
                    recommendation: { htmlRequired: true }
                },
                errorElement: 'span',
                errorPlacement: function (error, element) {
                    error.addClass('invalid-feedback d-block');
                    element.closest('.form-group').append(error);
                },
                highlight: function (element) { $(element).next('.note-editor').addClass('border border-danger'); },
                unhighlight: function (element) { $(element).next('.note-editor').removeClass('border border-danger'); }
            });
        }
    }

    /* Re-bind the step forms' jQuery-Validate instances after refreshPane() has
       replaced the pane DOM (which discards the load-time validators bound at
       page load). Uses the SAME required-field rules — this is what keeps ALL
       invalid fields shown together (jQuery-Validate validates every field on
       .valid()/submit and live-clears each on change/blur) instead of dropping
       back to the native one-field-at-a-time bubble. Only binds a form that is
       present and not already validated, so it never double-binds or changes an
       existing validator. Rules are byte-identical to the initial binding. */
    function blBindProcValidators($ctx){
        if (!$.fn.validate) return;
        var base = {
            errorElement: 'span',
            errorPlacement: function (error, element) { error.addClass('invalid-feedback'); element.closest('.form-group').append(error); },
            highlight:   function (element) { $(element).addClass('is-invalid'); },
            unhighlight: function (element) { $(element).removeClass('is-invalid'); }
        };
        function bind(sel, cfg){
            var $f = $ctx.find(sel);
            if ($f.length && !$f.data('validator')) { $f.validate($.extend(true, {}, base, cfg)); }
        }
        bind('#blindedInfo', { rules: { dzongkhag:'required', facility:'required', month:'required', year:'required', totalMicroscopy:'required', totalRDT:'required', dateship:'required' } });
        bind('#labResulrs',  { rules: { bsno:'required', doc:'required', microscopy:'required' } });
        bind('#bsStatus',    { });
        bind('#c1Resulrs',   { rules: { microscopyc:'required', drechk:'required', size_thick_smear:'required', size_thin_smear:'required', shape_thin_smear:'required', smear_spacing:'required', eveness_thin_smear:'required', blood_film:'required', smear_intact:'required', slide_labeling:'required', background:'required', stain_blood_element:'required', cleanliness:'required', stain_deposit:'required' },
                               submitHandler: function (form) { HTMLFormElement.prototype.submit.call(form); } });
        bind('#controller2', { rules: { microscopycii:'required' } });
    }

    /* Fallback for any step form WITHOUT a jQuery-Validate instance: show EVERY
       invalid field at once (never one-at-a-time). Uses each control's own native
       constraint (required/min/max/type/pattern) and the browser's message so no
       existing rule changes, but renders them together in the module's existing
       .invalid-feedback style, focuses the first invalid field, and live-clears
       each as it is corrected. Replaces native reportValidity() (single bubble). */
    function blShowAllInvalid($form){
        var first = null;
        $form.find('input, select, textarea').each(function(){
            var el = this;
            if (!el.willValidate) return;                 // skip hidden/disabled/readonly controls
            var $el = $(el), $host = $el.closest('.form-group');
            if (!$host.length) $host = $el.parent();
            var $msg = $host.find('> .invalid-feedback.bl-native').first();
            if (el.checkValidity()){
                $el.removeClass('is-invalid'); $msg.remove();
            } else {
                $el.addClass('is-invalid');
                if (!$msg.length){ $msg = $('<span class="invalid-feedback bl-native d-block"></span>'); $host.append($msg); }
                $msg.text(el.validationMessage || 'This field is required.');
                if (!first) first = el;
                if (!$el.data('blNativeBound')){
                    $el.data('blNativeBound', true);
                    $el.on('input change blur', function(){
                        var h = $(this).closest('.form-group'); if (!h.length) h = $(this).parent();
                        var m = h.find('> .invalid-feedback.bl-native').first();
                        if (this.checkValidity()){ $(this).removeClass('is-invalid'); m.remove(); }
                        else { $(this).addClass('is-invalid'); if (m.length) m.text(this.validationMessage || 'This field is required.'); }
                    });
                }
            }
        });
        if (first){ try { first.focus(); } catch(e){} return false; }
        return true;
    }

    // (Re)initialise widgets inside a freshly rendered pane.
    function initPaneWidgets($pane){
        $pane.find('.input-group.date').each(function(){
            try { $(this).datetimepicker({ format:'YYYY-MM-DD' }); } catch(e){}
        });
        try { $pane.find('.select2').select2(); } catch(e){}
        initFeedbackEditors($pane);
        // Re-bind the step-form validators discarded when this pane's DOM was
        // replaced, so a refreshed step still shows ALL invalid fields together.
        blBindProcValidators($pane);
        // Re-apply conditional required/readonly state on freshly rendered forms
        if ($pane.find('#microscopy').length)  reqResult($pane.find('#microscopy').val());
        if ($pane.find('#microscopyc').length) reqResultc($pane.find('#microscopyc').val());
        if ($.fn.DataTable){
            $pane.find('table#table').each(function(){
                if (!$.fn.DataTable.isDataTable(this)){
                    try {
                        $(this).DataTable({paging:true,lengthChange:false,searching:false,
                            ordering:true,info:true,autoWidth:false,responsive:true,order:[[0,'desc']]});
                    } catch(e){}
                }
            });
        }
    }

    // Fetch the page again and swap in ONLY this pane's fresh HTML (no visible reload).
    function refreshPane(idx, done){
        var pane = procPaneEls[idx];
        if (!pane.id || !BL_ID){ if (done) done(); return; }
        $.get('blinded.php?action=2&id=' + encodeURIComponent(BL_ID), function(html){
            try {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                if (!document.getElementById('pane-c2') && doc.getElementById('pane-c2')) BL_C2_NOW_READY = true;
                if (!document.getElementById('pane-feedback') && doc.getElementById('pane-feedback')) BL_FEEDBACK_NOW_READY = true;
                var fresh = doc.getElementById(pane.id);
                if (fresh){
                    $(pane).html(fresh.innerHTML);
                    var $b = procPaneSubmitButtons(pane);         // keep in-pane submit hidden
                    if ($b.length === 1){ $b.hide(); }
                    initPaneWidgets($(pane));
                }
            } catch(e){}
            if (done) done();
        }).fail(function(){ if (done) done(); });
    }

    // Single wizard-controlled save for the active stage.
    function saveCurrentStep(){
        if (procBusy) return;
        var idx = procIdx;
        var pane = procPaneEls[idx];
        var $btns = procPaneSubmitButtons(pane);
        if ($btns.length !== 1){
            // District Review step: Submit saves the slide results entered in the
            // District Results step (single wizard-controlled submission).
            if (BL_IS_DISTRICT && document.getElementById('editSlideRows')){ saveDistrictSlides(); return; }
            // Admin/RCDC District Results: all slide rows saved in one transaction.
            if (!BL_IS_DISTRICT && pane.id === 'pane-district' && document.getElementById('rcdcSlideRows')){ saveRcdcSlides(); return; }
            // Review / multi-form (Feedback) panes: nothing single to save here.
            showView(); return;
        }
        var $form = $($btns.get(0)).closest('form');
        var formEl = $form[0];

        // Preserve existing client-side validation. BOTH paths surface EVERY
        // invalid field at once (never one field at a time): jQuery-Validate when
        // bound, else a native-constraint routine that shows all invalid fields.
        if ($form.data('validator')) { if (!$form.valid()) return; }
        else if (formEl.checkValidity && !formEl.checkValidity()) { blShowAllInvalid($form); return; }

        // Controller 2 on a FINALIZED record is read-only: its scores are the report
        // that has already been issued, and the server refuses the write (403). There
        // is nothing to save, so walk straight on to Feedback & Actions instead of
        // posting and showing a failure the user cannot act on.
        if (!BL_IS_DISTRICT && pane.id === 'pane-c2' && BL_FINALIZED){
            procNext();
            return;
        }

        // Controller 2: every positive (non-Nmps) slide must have Parasite & WBC
        // counts and Parasite Stage selected. Blocking here gives a clear message
        // and keeps incomplete fields from reaching the server.
        if (!BL_IS_DISTRICT && pane.id === 'pane-c2'){
            var c2bad = false;
            $form.find('select[name="microscopy[]"]').each(function(){
                var $row = $(this).closest('tr');
                var mic  = $(this).val();
                $row.find('input[name="pcount[]"], input[name="wbcount[]"]').removeClass('is-invalid');
                $row.find('.c2-stage').removeClass('is-invalid');
                if (mic && mic !== 'Nmps'){
                    var $stg = $row.find('.c2-stage');
                    var $pc = $row.find('input[name="pcount[]"]');
                    var $wb = $row.find('input[name="wbcount[]"]');
                    if ($.trim($stg.val()) === ''){ $stg.addClass('is-invalid'); c2bad = true; }
                    if ($.trim($pc.val()) === ''){ $pc.addClass('is-invalid'); c2bad = true; }
                    if ($.trim($wb.val()) === ''){ $wb.addClass('is-invalid'); c2bad = true; }
                }
            });
            if (c2bad){
                Swal.fire({icon:'warning', text:'Select Parasite Stage and enter Parasite Count and WBC Count for every positive slide before submitting Controller 2. Missing fields are highlighted.'});
                return;
            }
        }

        procBusy = true;
        var $save = $('#procSaveBtn');
        var orig = $save.html();
        $save.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        // Temporarily enable disabled inputs before serialize to keep parallel array indices aligned
        var $disabledFields = $form.find(':disabled');
        $disabledFields.prop('disabled', false);
        var formData = $form.serialize();
        $disabledFields.prop('disabled', true);

        $.post('iu-blinded.php', formData, function(resp){
            procBusy = false;
            $save.prop('disabled', false).html(orig);
            if (!/Submitted Successfully/.test(resp)){
                // Surface the handler's specific reason when present (e.g. the >20%
                // density discrepancy business rule) instead of a generic message.
                var dm = resp.match(/Submission Failed!!\s*([^'<]*)/);
                var detail = (dm && $.trim(dm[1])) ? $.trim(dm[1]) : '';
                Swal.fire({icon:'error', text: detail ? ('Save failed: ' + detail) : 'Save failed. Please review the fields and try again.'});
                return;
            }
            var doneMsg = (!BL_IS_DISTRICT && (pane.id === 'pane-shipment' || pane.id === 'pane-c1' || pane.id === 'pane-c2' || pane.id === 'pane-feedback')) ? 'Submitted Successfully' : 'Saved Successfully';
            procSubmitted[idx] = true;
            // Reflect the just-saved stage in the client flags so later steps unlock
            // without a reload (the BL_ST flags are server-state captured at load).
            if (pane.id === 'pane-district'){ BL_ST.slides = true; BL_DISTRICT_DIRTY = true; }
            if (pane.id === 'pane-feedback'){ BL_ST.feedback = true; }
            // Any stage save can change the Review summary (scores, findings,
            // recommendations, actions). The Review pane was rendered at page load,
            // so mark it stale — it is re-fetched when the user next opens it.
            BL_REVIEW_STALE = true;

            // Receiving the shipment is a major server-side state change: it marks the
            // received slides 'Intact' (which opens Controller 1), locks district editing
            // and flips readonly states across panes — all of which were rendered at page
            // load, before receipt. The Controller 1 pane in particular still holds a
            // stale, empty results table with no edit links. Reload into Controller 1 so
            // every pane is consistent and the admin can immediately enter C1 results,
            // instead of doing an in-place refresh of only the shipment pane.
            if (!BL_IS_DISTRICT && pane.id === 'pane-shipment' && !BL_SHIPMENT_RECEIVED){
                sessionStorage.setItem('bl_mode', 'process');
                sessionStorage.setItem('bl_pane', 'pane-c1');
                Swal.fire({icon:'success', confirmButtonText:'Continue to Controller 1', text:doneMsg, showCloseButton:true})
                    .then(function(){ window.location = 'blinded.php?action=2&id=' + encodeURIComponent(BL_ID); });
                return;
            }

            Swal.fire({icon:'success', confirmButtonText:'Close', text:doneMsg, showCloseButton:true});
            // re-render only this pane with fresh data, then refresh footer + stepper
            // (showProcStep re-marks done/active/locked from the refreshed flags).
            refreshPane(idx, function(){ updateProcFooter(idx); showProcStep(idx); });
        }).fail(function(xhr){
            procBusy = false;
            $save.prop('disabled', false).html(orig);
            Swal.fire({
                icon: 'error',
                title: 'Save failed',
                text: blFailMessage(xhr, 'Network error while saving. Please try again.')
            });
        });
    }

    function showProcStepById(id){
        id = String(id).replace('#','');
        for (var i = 0; i < procPaneEls.length; i++){
            if (procPaneEls[i].id === id) { showProcStep(i); return true; }
        }
        return false;
    }

    /* ================================================================= */
    /*  DISTRICT EDIT: bulk slide editing with ONE Save for the report.  */
    /*  Reuses the existing labresults handler row by row: rid present   */
    /*  -> update, rid empty -> insert. No row-level delete.             */
    /* ================================================================= */
    // Template rows carry __DOCID__ in the date wrapper id and in the icon/input
    // data-target; each clone gets its own id so every row opens its own calendar.
    var blDsDocSeq = 0;
    function blStampDocId(frag){
        var docId = 'dsDoc_n' + (++blDsDocSeq);
        [].forEach.call(frag.querySelectorAll('[id="__DOCID__"]'), function(el){ el.id = docId; });
        [].forEach.call(frag.querySelectorAll('[data-target="#__DOCID__"]'), function(el){ el.setAttribute('data-target', '#' + docId); });
        return frag;
    }

    function addEditSlideRow(){
        var tpl = document.getElementById('editSlideRowTpl');
        if (!tpl) return;
        var $host = $('#editSlideRows');
        var beforeCount = $host.find('tr.slide-row').length;
        $host[0].appendChild(blStampDocId(document.importNode(tpl.content, true)));
        // Initialise the picker on the newly-added row's date wrapper.
        // (Existing rows in the pane are covered by initPaneWidgets.)
        $host.find('tr.slide-row').slice(beforeCount).find('.s-doc-wrap').each(function(){
            $(this).datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false });
        });
    }

    function saveDistrictSlides(){
        if (procBusy) return;
        var chk = validateSlideRows('#editSlideRows');   // shared step-wise rules + inline highlighting
        if (!chk.valid){
            Swal.fire({icon:'warning', text: SLIDE_ROWS_MSG});
            return;
        }
        var slides = [];
        $('#editSlideRows tr.slide-row').each(function(){
            var r     = $(this);
            var rid   = r.find('.s-rid').val() || '';
            var bsno  = $.trim(r.find('.s-bsno').val());
            var doc   = $.trim(r.find('.s-doc').val());
            var mic   = r.find('.s-mic').val();
            var stage = r.find('.s-stage').val();
            var pc    = r.find('.s-pcount').val();
            var wb    = r.find('.s-wbc').val();
            if (rid === '' && bsno === '' && mic === '') return;   // skip untouched new rows
            slides.push({rid:rid, bsno:bsno, doc:doc, microscopy:mic, pstage:stage, pcount:pc, wbcount:wb});
        });
        if (!slides.length){
            Swal.fire({icon:'warning', text:'Please add at least one slide.'});
            return;
        }

        procBusy = true;
        var idx  = procIdx;                               // Review step (wizard-controlled Submit)
        var dIdx = idx;                                   // District Results step (holds the slide rows)
        for (var p = 0; p < procPaneEls.length; p++){
            if (procPaneEls[p].id === 'pane-district'){ dIdx = p; break; }
        }
        var $btn = $('#procSaveBtn');
        var orig = $btn.html();
        $btn.prop('disabled', true);

        var i = 0, failed = 0;
        (function next(){
            if (i >= slides.length){
                procBusy = false;
                $btn.prop('disabled', false).html(orig);
                refreshPane(dIdx, function(){ if (procIdx === dIdx) updateProcFooter(dIdx); });
                if (failed){
                    Swal.fire({icon:'error', text:'Some slides could not be saved. Please review and try again.'});
                    return;
                }
                procSubmitted[dIdx] = true;
                procSubmitted[idx] = true;
                $('#procWizardNav .wizard-step[data-idx="'+dIdx+'"], #procWizardNav .wizard-step[data-idx="'+idx+'"]').removeClass('active').addClass('done');
                Swal.fire({icon:'success', confirmButtonText:'Close', text:'Submitted Successfully!!', showCloseButton:true})
                    .then(function(){ showView(); });
                return;
            }
            var s = slides[i];
            $btn.html('<i class="fas fa-spinner fa-spin"></i> Saving slide '+(i+1)+' of '+slides.length+'...');
            $.post('iu-blinded.php', {
                submit:'labresults', action:(s.rid !== '' ? 'update' : ''), biid:BL_ID, rid:s.rid,
                bsno:s.bsno, doc:s.doc, microscopy:s.microscopy,
                pstage:s.pstage, pcount:s.pcount, wbcount:s.wbcount
            }, function(resp){
                if (!/Submitted Successfully/.test(resp)) failed++;
                i++; next();
            }).fail(function(){ failed++; i++; next(); });
        })();
    }

    /* ================================================================= */
    /*  ADMIN/RCDC DISTRICT RESULTS: multi-slide table bounded by the     */
    /*  record's total_slides, saved in ONE transaction (labresultsBulk). */
    /*  Reuses the shared row helpers (rowReqResult / updateRowDensity /  */
    /*  validateSlideRows) so each row behaves independently.             */
    /* ================================================================= */
    var rcdcNewSeq = 0;

    function rcdcCardData(key){ return $('#rcdcSlideCard').data(key); }

    // A row is "entered" when every required field for its result type is filled.
    function rcdcRowComplete($r){
        var mic = $r.find('.s-mic').val();
        if ($.trim($r.find('.s-bsno').val()) === '' || $.trim($r.find('.s-doc').val()) === '' || !mic) return false;
        if (mic === 'Nmps') return true;
        return !!$r.find('.s-stage').val() && $r.find('.s-pcount').val() !== '' && $r.find('.s-wbc').val() !== '';
    }

    // Row numbers, "Slides Entered: Y / X", Add limit and Remove availability.
    function rcdcRefreshCounts(){
        var $rows    = $('#rcdcSlideRows tr.rs-row');
        var total    = parseInt(rcdcCardData('total'), 10) || 0;
        var received = String(rcdcCardData('received')) === '1';
        var entered  = 0;
        $rows.each(function(i){
            $(this).find('.rs-num').text(i + 1);
            if (rcdcRowComplete($(this))) entered++;
        });
        $('#rcdcSlidesEntered').text(entered);
        var atMax = $rows.length >= total;
        $('#rcdcAddSlideBtn').prop('disabled', received || atMax)
            .attr('title', received ? 'New slides cannot be added after the shipment is received.'
                                    : (atMax ? 'All ' + total + ' selected slides already have a row.' : ''));
        $rows.find('.rs-remove').prop('disabled', $rows.length <= 1);
    }

    function rcdcInitRow($r){
        $r.find('.s-doc-wrap').each(function(){
            try { $(this).datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false, allowInputToggle: true }); } catch(e){}
        });
        if ($r.is('.slide-row')){
            var mic = $r.find('.s-mic')[0];
            if (mic && mic.value === 'Nmps') rowReqResult(mic);   // row-scoped only
            updateRowDensity($r);
        }
    }

    // userAction = true when the user clicked "+ Add Slide" (marks the table dirty).
    function rcdcAddSlideRow(userAction){
        var tpl   = document.getElementById('rcdcSlideRowTpl');
        var $host = $('#rcdcSlideRows');
        if (!tpl || !$host.length) return false;
        var total = parseInt(rcdcCardData('total'), 10) || 0;
        if (String(rcdcCardData('received')) === '1' || $host.find('tr.rs-row').length >= total) return false;
        var docId = 'rsDocWrap_n' + (++rcdcNewSeq);
        var $r = $($.trim(tpl.innerHTML.replace(/__DOCID__/g, docId)));
        $host.append($r);
        rcdcInitRow($r);
        if (userAction) BL_RCDC_SLIDES_DIRTY = true;
        rcdcRefreshCounts();
        return true;
    }

    // Only unsaved rows carry a Remove button; persisted rows are edited, not deleted here.
    function rcdcRemoveSlideRow(btn){
        var $rows = $('#rcdcSlideRows tr.rs-row');
        if ($rows.length <= 1) return;
        $(btn).closest('tr.rs-row').remove();
        rcdcRefreshCounts();
    }

    // Initialise the table (page load and after an in-place pane refresh):
    // wire existing rows, then pre-render empty rows up to total_slides.
    function initRcdcSlides(){
        if (!document.getElementById('rcdcSlideRows')) return;
        $('#rcdcSlideRows tr.rs-row').each(function(){ rcdcInitRow($(this)); });
        while (rcdcAddSlideRow(false)) {}
        rcdcRefreshCounts();
        BL_RCDC_SLIDES_DIRTY = false;
        // The wizard may already be showing this step (footer computed before the
        // empty rows existed) — recompute so Save All Slides reflects the rows.
        if (procPaneEls.length && procPaneEls[procIdx] && procPaneEls[procIdx].id === 'pane-district'){
            updateProcFooter(procIdx);
        }
    }

    $(function(){
        $(document).on('input change', '#rcdcSlideRows :input', function(){
            BL_RCDC_SLIDES_DIRTY = true;
            rcdcRefreshCounts();
        });
        $(document).on('change.datetimepicker', '#rcdcSlideRows .s-doc-wrap', function(){
            BL_RCDC_SLIDES_DIRTY = true;
            rcdcRefreshCounts();
        });
        initRcdcSlides();
    });

    function saveRcdcSlides(){
        if (procBusy) return;
        var chk = validateSlideRows('#rcdcSlideRows');      // editable rows only; highlights invalid fields
        if (!chk.valid){ Swal.fire({icon:'warning', text: SLIDE_ROWS_MSG}); return; }

        var slides = [];
        $('#rcdcSlideRows tr.slide-row').each(function(){
            var r     = $(this);
            var rid   = r.find('.s-rid').val() || '';
            var bsno  = $.trim(r.find('.s-bsno').val());
            var mic   = r.find('.s-mic').val() || '';
            if (rid === '' && bsno === '' && mic === '') return;   // untouched empty row
            slides.push({
                rid: rid === '' ? 0 : parseInt(rid, 10), bsno: bsno, doc: $.trim(r.find('.s-doc').val()),
                microscopy: mic, pstage: (mic === 'Nmps' ? '' : (r.find('.s-stage').val() || '')),
                pcount: r.find('.s-pcount').val(), wbcount: r.find('.s-wbc').val()
            });
        });
        if (!slides.length){ Swal.fire({icon:'warning', text:'Please enter at least one slide.'}); return; }

        procBusy = true;
        var idx  = procIdx;
        var $btn = $('#procSaveBtn');
        var orig = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving ' + slides.length + ' slide' + (slides.length === 1 ? '' : 's') + '...');

        $.post('iu-blinded.php', { submit:'labresultsBulk', action:'', biid:BL_ID, slides: JSON.stringify(slides) }, function(resp){
            if (!/Submitted Successfully/.test(resp)){
                procBusy = false;
                $btn.prop('disabled', false).html(orig);
                var dm = resp.match(/Submission Failed!!\s*([^'<]*)/);
                var detail = (dm && $.trim(dm[1])) ? $.trim(dm[1]) : '';
                Swal.fire({icon:'error', text: (detail ? detail + ' ' : '') + 'Nothing was saved.'});
                return;
            }
            BL_RCDC_SLIDES_DIRTY = false;
            BL_DISTRICT_DIRTY = true;     // Shipment pane lists these slides -> reload into it next
            BL_REVIEW_STALE = true;
            // Re-render the pane so new rows carry their result_id (no duplicate insert on re-save).
            refreshPane(idx, function(){
                procBusy = false;
                $btn.prop('disabled', false).html(orig);
                initRcdcSlides();
                var total = parseInt(rcdcCardData('total'), 10) || 0;
                var saved = parseInt(rcdcCardData('saved'), 10) || 0;
                if (total > 0 && saved >= total){ BL_ST.slides = true; procSubmitted[idx] = true; }
                updateProcFooter(idx);
                showProcStep(idx);
                Swal.fire({icon:'success', confirmButtonText:'Close', showCloseButton:true,
                    text: (saved >= total)
                        ? ('All ' + total + ' slides saved successfully.')
                        : (saved + ' of ' + total + ' slides saved. Please enter all ' + total + ' selected MP slide results before continuing.')});
            });
        }).fail(function(xhr){
            procBusy = false;
            $btn.prop('disabled', false).html(orig);
            Swal.fire({
                icon: 'error',
                title: 'Save failed',
                text: blFailMessage(xhr, 'Network error while saving. Nothing was saved.')
            });
        });
    }

    /* ================================================================= */
    /*  DISTRICT INTAKE WIZARD (new record) - client-side orchestration   */
    /*  Saves header (blindedInfo) then each slide (labresults) via the   */
    /*  existing iu-blinded.php handlers, then redirects to action=2.     */
    /* ================================================================= */
    var wizardStep = 1;
    var wizardBusy = false;

    function showStep(n){
        wizardStep = n;
        $('.wizard-pane').removeClass('active');
        $('#wizard-step-'+n).addClass('active');
        $('.bs-wizard .wizard-step').each(function(){
            var s = Number($(this).data('step'));
            if (!s) return;
            $(this).removeClass('active done').removeAttr('aria-current');
            if (s < n) $(this).addClass('done');
            else if (s === n) $(this).addClass('active').attr('aria-current','step');
        });
        $('#wizardBackBtn').toggle(n > 1);
        $('#wizardNextBtn').toggle(n < 3);
        $('#wizardSubmitBtn').toggle(n === 3);
        if (n === 3) buildReview();
    }

    function wizardNext(){
        if (wizardStep === 1) {
            if (!$('#blindedInfo').valid()) return;
            var tmic = Number($('#totalMicroscopy').val() || 0);
            if (tmic === 0) { showStep(3); return; }   // RDT-only: skip slide entry
            var droppedRows = ensureSlideRows();
            showStep(2);
            if (droppedRows > 0) {
                Swal.fire({icon:'info', confirmButtonText:'OK',
                    text:'Total Blinded Slides Selected was reduced, so the last ' + droppedRows + ' filled slide row(s) were removed.'});
            }
        } else if (wizardStep === 2) {
            // Slide rows must be complete before the Review step opens
            // (previously they were only checked at final submit).
            var chk = validateSlideRows('#slideRows');
            if (!chk.valid){ Swal.fire({icon:'warning', text: SLIDE_ROWS_MSG}); return; }
            if (chk.count === 0){ Swal.fire({icon:'warning', text:'Please add at least one slide, or go back and set Total Microscopy Screened to 0.'}); return; }
            showStep(3);
        }
    }

    function wizardBack(){
        if (wizardStep === 3) {
            var tmic = Number($('#totalMicroscopy').val() || 0);
            showStep(tmic === 0 ? 1 : 2);
        } else if (wizardStep === 2) {
            showStep(1);
        }
    }

    // One entry row per selected blinded slide (Positive + Negative = Total Blinded
    // Slides Selected). Called each time the user moves from Information to Slide
    // Entry: missing rows are added, and when the total was lowered the surplus rows
    // are dropped from the end. Rows already filled in keep their values.
    // Returns the number of dropped rows that held data.
    function ensureSlideRows(){
        var target = parseInt($('#tslides').val(), 10);
        if (!(target > 0)) { target = 1; }              // previous behaviour when no total is set
        var $rows = $('#slideRows tr.slide-row');
        var dropped = 0;
        $rows.slice(target).each(function(){
            var $r = $(this);
            if ($.trim($r.find('.s-bsno').val()) !== '' || $.trim($r.find('.s-doc').val()) !== '' || ($r.find('.s-mic').val() || '') !== '') { dropped++; }
            $r.find('.s-doc-wrap').each(function(){ try { $(this).datetimepicker('destroy'); } catch (e) {} });
            $r.remove();
        });
        for (var i = $('#slideRows tr.slide-row').length; i < target; i++) { addSlideRow(); }
        return dropped;
    }

    function addSlideRow(){
        var tpl = document.getElementById('slideRowTpl');
        if (!tpl) return;
        var $host = $('#slideRows');
        var beforeCount = $host.find('tr.slide-row').length;
        var clone = blStampDocId(document.importNode(tpl.content, true));
        $host[0].appendChild(clone);
        // Initialise the picker on the newly-added row's date wrapper.
        // (Rows already in the pane are covered by initPaneWidgets.)
        $host.find('tr.slide-row').slice(beforeCount).find('.s-doc-wrap').each(function(){
            $(this).datetimepicker({ format: 'YYYY-MM-DD', useCurrent: false });
        });
    }

    function removeSlideRow(btn){
        var rows = $('#slideRows tr.slide-row');
        if (rows.length <= 1) { $(btn).closest('tr').find('input,select').val(''); return; }
        $(btn).closest('tr').remove();
    }

    // Row-scoped equivalent of reqResult() for the multi-row slide entry
    function rowReqResult(sel){
        var r = $(sel).closest('tr');
        if (sel.value === 'Nmps') {
            r.find('.s-stage').val('').prop('disabled', true);
            r.find('.s-pcount, .s-wbc').val('').prop('disabled', true);
        } else {
            r.find('.s-stage, .s-pcount, .s-wbc').prop('disabled', false);
        }
        updateRowDensity(r);
    }

    function buildReview(){
        var facilityTxt = $('#facility option:selected').text() || '';
        var monthTxt = $('#month option:selected').text() || '';
        var yearTxt = $('#year').val() || '';
        var tmic = $('#totalMicroscopy').val() || '0';
        var trdt = $('#totalRDT').val() || '0';
        var tmal = $('#totalMalaria').val() || '0';
        var ps = $('#pslides').val() || '0';
        var ns = $('#nslides').val() || '0';
        var ts = $('#tslides').val() || '0';
        var ship = $('input[name=dateship]').val() || '';

        var html = '<div class="row">';
        html += reviewItem('Health Facility', facilityTxt);
        html += reviewItem('Month', monthTxt);
        html += reviewItem('Year', yearTxt);
        html += reviewItem('Total Microscopy', tmic);
        html += reviewItem('Total RDT', trdt);
        html += reviewItem('Total Malaria', tmal);
        html += reviewItem('Positive Slides', ps);
        html += reviewItem('Negative Slides', ns);
        html += reviewItem('Total Blinded Slides', ts);
        html += reviewItem('Shipment / Reporting Date', ship);
        html += '</div>';

        if (Number(tmic) > 0) {
            var rows = '';
            var cnt = 0;
            $('#slideRows tr.slide-row').each(function(){
                var r = $(this);
                var bsno = $.trim(r.find('.s-bsno').val());
                var mic = r.find('.s-mic').val();
                if (bsno === '' && mic === '') return;
                cnt++;
                rows += '<tr><td>'+ escapeHtml(bsno) +'</td><td class="text-center">'+ escapeHtml(r.find('.s-doc').val()) +
                        '</td><td>'+ escapeHtml(mic) +'</td><td>'+ escapeHtml(r.find('.s-stage').val()) +
                        '</td><td class="text-center">'+ escapeHtml(r.find('.s-pcount').val()) +
                        '</td><td class="text-center">'+ escapeHtml(r.find('.s-wbc').val()) +'</td></tr>';
            });
            html += '<hr><h6 class="text-primary">District Lab Results</h6>';
            html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr class="text-center">'+
                    '<th>B/S No</th><th>Collection Date</th><th>Microscopy</th><th>Stage</th><th>Parasite</th><th>WBC</th>'+
                    '</tr></thead><tbody>'+ rows +'</tbody></table></div>';
        } else {
            html += '<hr><p class="text-muted mb-0">RDT-only / no microscopy: no blinded slides to enter.</p>';
        }
        $('#reviewBox').html(html);
    }

    function reviewItem(label, val){
        return '<div class="col-sm-3 mb-2"><small class="text-muted d-block">'+ label +
               '</small><strong>'+ escapeHtml(val) +'</strong></div>';
    }

    function escapeHtml(s){
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function finalizeWizard(){
        if (wizardBusy) return;
        if (!$('#blindedInfo').valid()) { showStep(1); return; }

        var tmic = Number($('#totalMicroscopy').val() || 0);
        var slides = [];

        if (tmic > 0) {
            var chk = validateSlideRows('#slideRows');   // shared step-wise rules + inline highlighting
            if (!chk.valid) {
                Swal.fire({icon:'warning', text: SLIDE_ROWS_MSG});
                showStep(2);
                return;
            }
            if (chk.count === 0) {
                Swal.fire({icon:'warning', text:'Please add at least one slide, or set Total Microscopy Screened to 0.'});
                showStep(2);
                return;
            }
            $('#slideRows tr.slide-row').each(function(){
                var r = $(this);
                var bsno = $.trim(r.find('.s-bsno').val());
                var doc = $.trim(r.find('.s-doc').val());
                var mic = r.find('.s-mic').val();
                var stage = r.find('.s-stage').val();
                var pc = r.find('.s-pcount').val();
                var wb = r.find('.s-wbc').val();
                if (bsno === '' && mic === '') return; // skip fully-empty rows
                slides.push({bsno:bsno, doc:doc, microscopy:mic, pstage:stage, pcount:pc, wbcount:wb});
            });
        }

        wizardBusy = true;
        var $btn = $('#wizardSubmitBtn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        // 1) Save header via existing blindedInfo handler
        $.post('iu-blinded.php', $('#blindedInfo').serialize(), function(resp){
            if (!/Submitted Successfully/.test(resp)) {
                wizardBusy = false;
                $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Submit');
                Swal.fire({icon:'error', text:'Failed to save the header information. Please review and try again.'});
                return;
            }
            var m = resp.match(/id='lnk'\s+value='([^']+)'/);
            var lnk = m ? m[1] : null;
            if (!lnk) {
                wizardBusy = false;
                $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Submit');
                Swal.fire({icon:'error', text:'Could not determine the new record id.'});
                return;
            }
            var biid = lnk.replace(/^.*id=/, '');

            // RDT-only / no slides -> just go to the view page
            if (tmic === 0 || slides.length === 0) {
                Swal.fire({icon:'success', confirmButtonText:'Close', text:'Submitted Successfully!!', showCloseButton:true})
                    .then(function(){ window.location = lnk; });
                return;
            }

            // 2) Save each slide sequentially via existing labresults handler
            var i = 0;
            (function next(){
                if (i >= slides.length) {
                    Swal.fire({icon:'success', confirmButtonText:'Close', text:'Submitted Successfully!!', showCloseButton:true})
                        .then(function(){ window.location = lnk; });
                    return;
                }
                var s = slides[i];
                $btn.html('<i class="fas fa-spinner fa-spin"></i> Saving slide '+(i+1)+' of '+slides.length+'...');
                $.post('iu-blinded.php', {
                    submit:'labresults', action:'', biid:biid, rid:'',
                    bsno:s.bsno, doc:s.doc, microscopy:s.microscopy,
                    pstage:s.pstage, pcount:s.pcount, wbcount:s.wbcount
                }, function(){ i++; next(); }).fail(function(){ i++; next(); });
            })();

        }).fail(function(xhr){
            wizardBusy = false;
            $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Submit');
            Swal.fire({
                icon: 'error',
                title: 'Save failed',
                text: blFailMessage(xhr, 'Network error while saving. Please try again.')
            });
        });
    }

    /* ================================================================= */
    /*  ADMIN UNIFIED NEW: save Step 1 (MP Slide Info), which creates the  */
    /*  record, then continue in the SAME wizard (action=2 process wizard) */
    /*  at District Results — no separate submit, no Process/Edit reopen.  */
    /* ================================================================= */
    function adminSaveInfoContinue(){
        if (wizardBusy) return;
        if (!$('#blindedInfo').valid()) return;   // step-wise validation gate

        wizardBusy = true;
        var $btn = $('#adminInfoContinueBtn');
        var orig = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        $.post('iu-blinded.php', $('#blindedInfo').serialize(), function(resp){
            if (!/Submitted Successfully/.test(resp)){
                wizardBusy = false;
                $btn.prop('disabled', false).html(orig);
                Swal.fire({icon:'error', text:'Failed to save MP Slide Info. Please review the fields and try again.'});
                return;
            }
            var m = resp.match(/id='lnk'\s+value='([^']+)'/);
            var lnk = m ? m[1] : null;                       // blinded.php?action=2&id=N
            if (!lnk){
                wizardBusy = false;
                $btn.prop('disabled', false).html(orig);
                Swal.fire({icon:'error', text:'Could not determine the new record id.'});
                return;
            }
            var biid = lnk.replace(/^.*id=/, '');
            // RDT-only (no microscopy) has no blinded slides, so there is no
            // District/Shipment/Controller workflow — open the record summary,
            // exactly as the original flow did.
            var tmic = Number($('#totalMicroscopy').val() || 0);
            if (tmic > 0){
                // Continue straight into the unified process wizard at District Results.
                sessionStorage.setItem('bl_mode', 'process');
                sessionStorage.setItem('bl_pane', 'pane-district');
            }
            window.location = 'blinded.php?action=2&id=' + encodeURIComponent(biid);
        }).fail(function(xhr){
            wizardBusy = false;
            $btn.prop('disabled', false).html(orig);
            Swal.fire({
                icon: 'error',
                title: 'Save failed',
                text: blFailMessage(xhr, 'Network error while saving. Please try again.')
            });
        });
    }

    // Initialize new-intake wizard footer state on load
    $(function(){
        if ($('#wizard-step-1').length && !$('#newAdminNav').length) { showStep(1); }
    });
</script>
<!-- Page footer -->
<!-- Select2 -->
<!-- <script src="../plugins/select2/js/select2.full.min.js"></script> -->
<?php require_once("../includes/footer.php"); ?>
