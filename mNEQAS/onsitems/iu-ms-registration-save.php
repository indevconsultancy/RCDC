<?php
    /**
     * M&S Registration – single transactional save for the wizard.
     *
     * Receives the ENTIRE wizard (general info + staff + checklist + recommendation
     * + action + action images) in one POST and persists it inside a single transaction:
     * either everything is saved or nothing is. Rows are upserted in place so that
     * existing child-record ids (staff_id / ora_id / rating_id) are preserved on edit.
     *
     * Always returns standardized JSON: {"status": "success"|"error", "message": "..."}
     * with Content-Type: application/json.
     *
     * Backward compatible: the legacy per-section endpoint (iu-ms-registration.php)
     * and delete.php are left untouched.
     */
    ob_start();
    // Suppress loose notices and deprecations so output buffers and JSON streams remain clean
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');

    require_once(__DIR__ . "/../config/session.php");
    require_once(__DIR__ . "/../config/auth.php");
    require_once(__DIR__ . "/../config/db-config.php");

    // Standardized JSON response helper that wipes any stray output buffer
    function ms_respond_json(string $status, string $message, array $extra = [], int $httpCode = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'status'  => $status,
            'success' => ($status === 'success'),
            'message' => $message,
        ], $extra));
        exit;
    }

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
           || isset($_POST['ajax'])
           || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false);

    // RBAC backend enforcement: only Create/Update roles (Administrator, RCDC Lab,
    // Health Worker) may write here; read-only "Others" are blocked.
    if (!function_exists('mn_user') || mn_user() === '' || mn_role() === 0) {
        if ($isAjax) {
            ms_respond_json('error', 'Your session has expired. Please sign in again.', [], 401);
        }
        mn_deny_as('page', 'Please sign in.');
    }

    if (!function_exists('mn_can_write') || !mn_can_write()) {
        if ($isAjax) {
            ms_respond_json('error', 'You have read-only access and cannot modify data.', [], 403);
        }
        mn_deny_as('page', 'You have read-only access and cannot modify data.');
    }

    $check = false;
    $lnk = "";

    // Make mysqli throw so any failure inside the transaction triggers rollback.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    /**
     * Upload multiple action evidence images for a given general_info_id.
     * Returns true on success (or when no files were sent), false on failure.
     * Used only by Admin (roleID = 1).
     */
    function uploadActionImages(mysqli $conn, int $gid): bool
    {
        if (empty($_FILES['action_images']) || !is_array($_FILES['action_images']['name'])) {
            return true; // nothing to upload
        }

        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5 MB

        // Physical folder on disk (relative to this script)
        $uploadDir = __DIR__ . '/../uploads/ms_actions/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return false;
            }
        }

        $names  = $_FILES['action_images']['name'];
        $tmps   = $_FILES['action_images']['tmp_name'];
        $errors = $_FILES['action_images']['error'];
        $sizes  = $_FILES['action_images']['size'];
        $types  = $_FILES['action_images']['type'];

        $count = count($names);

        for ($i = 0; $i < $count; $i++) {
            if ($errors[$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errors[$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            if (!in_array($types[$i], $allowed, true)) {
                continue;
            }
            if ($sizes[$i] > $maxSize) {
                continue;
            }

            $originalName = basename($names[$i]);
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }

            // Unique filename to avoid collisions
            $newName  = 'action_' . $gid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadDir . $newName;

            if (!move_uploaded_file($tmps[$i], $destPath)) {
                return false;
            }

            // Path stored in DB – matches what the front-end expects
            // (front-end does: '../' . path  when path does not start with /)
            $dbPath = 'uploads/ms_actions/' . $newName;

            $stmt = $conn->prepare(
                "INSERT INTO onsite_action_images (general_info_id, image_name, image_path)
                 VALUES (?, ?, ?)"
            );
            $stmt->bind_param("iss", $gid, $originalName, $dbPath);
            if (!$stmt->execute()) {
                $stmt->close();
                @unlink($destPath); // clean up file if DB insert fails
                return false;
            }
            $stmt->close();
        }

        return true;
    }

    // ---- Input ----
    $gid_in         = isset($_POST['gid']) ? trim($_POST['gid']) : '';
    $facility       = isset($_POST['facility']) ? trim($_POST['facility']) : '';
    $dov            = isset($_POST['dov']) ? trim($_POST['dov']) : '';
    $evaluator      = isset($_POST['evaluator']) ? trim($_POST['evaluator']) : '';
    $designation    = isset($_POST['designation']) ? trim($_POST['designation']) : '';
    $audit          = isset($_POST['audit']) ? trim($_POST['audit']) : '';
    $recommendation = isset($_POST['recommendation']) ? $_POST['recommendation'] : '';
    $findings       = isset($_POST['findings']) ? $_POST['findings'] : '';
    $actions        = isset($_POST['actions']) ? $_POST['actions'] : '';

    $staff_id      = isset($_POST['staff_id'])          ? $_POST['staff_id']          : array();
    $staff_name    = isset($_POST['staff_name'])        ? $_POST['staff_name']        : array();
    $staff_desig   = isset($_POST['staff_designation']) ? $_POST['staff_designation'] : array();
    $staff_freq    = isset($_POST['staff_frequency'])   ? $_POST['staff_frequency']   : array();
    $staff_days    = isset($_POST['staff_days'])        ? $_POST['staff_days']        : array();
    $staff_ldt     = isset($_POST['staff_ldt'])         ? $_POST['staff_ldt']         : array();
    $staff_topic   = isset($_POST['staff_topic'])       ? $_POST['staff_topic']       : array();
    $deleted_staff = isset($_POST['deleted_staff'])     ? $_POST['deleted_staff']     : array();

    $cid     = isset($_POST['cid'])     ? $_POST['cid']     : array();
    $rating  = isset($_POST['rating'])  ? $_POST['rating']  : array();
    $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : array();
    $count   = isset($_POST['count'])   ? (int)$_POST['count'] : 0;

    $check = false;
    $errmsg = '';

    /* ---- Authorization (before any SQL write) ------------------------------
       External audit = RCDC (1, 2). Internal audit = the participating facility
       (Health Worker, own facility) or RCDC. For facility users the facility and
       the audit type are derived server-side, and an existing record must belong
       to their facility and be an Internal audit. */
    if (!in_array($audit, array('External', 'Internal'), true)) {
        if ($isAjax) {
            ms_respond_json('error', 'Invalid audit type: must be External or Internal.', [], 400);
        }
        mn_deny('Invalid audit type.');
    }
    if ($gid_in !== '') {
        if (!ctype_digit($gid_in)) {
            if ($isAjax) {
                ms_respond_json('error', 'Invalid record reference.', [], 400);
            }
            mn_deny('Invalid record reference.');
        }
        if ($isAjax) {
            mn_require_record($conn, 'onsite', (int)$gid_in, 'json');
        } else {
            mn_require_record($conn, 'onsite', (int)$gid_in);
        }
    }
    if (!mn_is_rcdc()) {
        $facility = (string)mn_facility();
        $audit    = 'Internal';
        if ($gid_in !== '') {
            $ac = $conn->prepare("SELECT audit_category FROM onsite_general_informations WHERE gi_id = ?");
            $acId = (int)$gid_in;
            $ac->bind_param('i', $acId);
            $ac->execute();
            $acRow = $ac->get_result()->fetch_row();
            $ac->close();
            if (!$acRow || $acRow[0] !== 'Internal') {
                if ($isAjax) {
                    ms_respond_json('error', 'External audits can only be modified by RCDC.', ['id' => $acId], 403);
                }
                mn_deny('External audits can only be modified by RCDC.', $acId);
            }
        }
    }

    /* ---- Server-side validation (same required rules as the wizard steps) --------
       Nothing is written when any required value is missing or invalid, so a direct
       POST cannot skip a step. Recommendations / Actions remain optional. */
    $isIsoDate = function ($d) {
        $d = trim((string)$d);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return false; }
        list($y, $m, $dd) = array_map('intval', explode('-', $d));
        return checkdate($m, $dd, $y);
    };
    $validationErr = array();
    if (trim((string)$facility) === '')    { $validationErr[] = 'Health Facility is required.'; }
    if (!$isIsoDate($dov))                  { $validationErr[] = 'Date of Visit must be a valid date (YYYY-MM-DD).'; }
    if (trim((string)$evaluator) === '')   { $validationErr[] = 'Evaluator is required.'; }
    if (trim((string)$designation) === '') { $validationErr[] = 'Designation is required.'; }
    $validStaff = 0;
    foreach ($staff_name as $i => $nm) {
        $rowNo = $i + 1;
        $rowHasData = trim((string)$nm) !== '';
        foreach (array($staff_desig, $staff_freq, $staff_days, $staff_ldt, $staff_topic) as $col) {
            if (isset($col[$i]) && trim((string)$col[$i]) !== '') { $rowHasData = true; }
        }
        if (!$rowHasData) { continue; }
        if (trim((string)$nm) === '') { $validationErr[] = "Staff row $rowNo: Staff Name is required."; } else { $validStaff++; }
        foreach (array('Frequency' => $staff_freq, 'Days' => $staff_days) as $lbl => $col) {
            if (isset($col[$i]) && trim((string)$col[$i]) !== '' && !ctype_digit(trim((string)$col[$i]))) { $validationErr[] = "Staff row $rowNo: $lbl must be a whole number."; }
        }
        if (isset($staff_ldt[$i]) && trim((string)$staff_ldt[$i]) !== '' && !$isIsoDate($staff_ldt[$i])) { $validationErr[] = "Staff row $rowNo: Last Training must be a valid date (YYYY-MM-DD)."; }
    }
    if ($validStaff === 0) { $validationErr[] = 'At least one staff member with a Staff Name is required.'; }
    $unrated = 0;
    for ($i = 0; $i < $count; $i++) {
        if (!isset($cid[$i])) { continue; }
        if (!isset($rating[$i]) || !in_array((string)$rating[$i], array('0', '1', '2'), true)) { $unrated++; }
    }
    if ($count <= 0 || count($cid) === 0) { $validationErr[] = 'The checklist was not submitted.'; }
    elseif ($unrated > 0) { $validationErr[] = "Every checklist item must be rated ($unrated not rated)."; }

    if (!empty($validationErr)) {
        $saveErr = implode(' ', $validationErr);
        if ($isAjax) {
            ms_respond_json('error', 'Submission Failed!! ' . $saveErr, [
                'validation_errors' => $validationErr
            ], 422);
        }
        $lnk = ($gid_in === '') ? "ms-registration.php?action=1" : "ms-registration.php?action=2&id=".(int)$gid_in;
        $saveErrEsc = htmlspecialchars($saveErr, ENT_QUOTES, 'UTF-8');
        require_once(__DIR__ . "/../includes/css-jquery.php");
        echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");
        echo("<input type='hidden' id='saveErr' value='$saveErrEsc'/>");
        ?>
    <script>
        $(function() {
            Swal.fire({ icon: 'error', confirmButtonText: 'Close', showCloseButton: true,
                        text: 'Submission Failed!! Nothing was saved. ' + $('<textarea>').html($('#saveErr').val()).text() })
            .then(function () { window.location = $('#lnk').val(); });
        });
    </script>
        <?php
        exit;
    }

    $conn->begin_transaction();
    try {
        // ---- 1) General information (insert new / update existing) ----
        if ($gid_in === '') {
            $stmt = $conn->prepare("INSERT INTO onsite_general_informations(facility_id, date_visit, evaluator, incharge_designation, audit_category) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $facility, $dov, $evaluator, $designation, $audit);
            $stmt->execute();
            $gid = (int)$conn->insert_id;
            $stmt->close();
        } else {
            $gid = (int)$gid_in;
            $stmt = $conn->prepare("UPDATE onsite_general_informations SET facility_id=?, date_visit=?, evaluator=?, incharge_designation=?, audit_category=? WHERE gi_id=?");
            $stmt->bind_param("sssssi", $facility, $dov, $evaluator, $designation, $audit, $gid);
            $stmt->execute();
            $stmt->close();
        }

        // ---- 2) Staff (upsert by staff_id using reused prepared statements) ----
        $stmtStaffUpd = $conn->prepare("UPDATE onsite_staff_informations SET staff_name=?, staff_designation=?, training_frequency=?, training_days=?, last_training_date=?, training_topic=? WHERE staff_id=? AND general_info_id=?");
        $stmtStaffIns = $conn->prepare("INSERT INTO onsite_staff_informations(general_info_id, staff_name, staff_designation, training_frequency, training_days, last_training_date, training_topic) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $sn = count($staff_name);
        for ($i = 0; $i < $sn; $i++) {
            $name = trim($staff_name[$i]);
            if ($name === '') { continue; } // ignore blank rows
            $sid   = isset($staff_id[$i])    ? trim($staff_id[$i]) : '';
            $desig = isset($staff_desig[$i]) ? trim($staff_desig[$i]) : '';
            $freq  = (isset($staff_freq[$i]) && trim((string)$staff_freq[$i]) !== '') ? (int)$staff_freq[$i] : null;
            $days  = (isset($staff_days[$i]) && trim((string)$staff_days[$i]) !== '') ? (int)$staff_days[$i] : null;
            $ldt   = (isset($staff_ldt[$i]) && trim((string)$staff_ldt[$i]) !== '') ? trim((string)$staff_ldt[$i]) : null;
            $topic = isset($staff_topic[$i]) ? trim($staff_topic[$i]) : '';

            if ($sid !== '') {
                $sidInt = (int)$sid;
                $stmtStaffUpd->bind_param("ssiissii", $name, $desig, $freq, $days, $ldt, $topic, $sidInt, $gid);
                $stmtStaffUpd->execute();
            } else {
                $stmtStaffIns->bind_param("issiiss", $gid, $name, $desig, $freq, $days, $ldt, $topic);
                $stmtStaffIns->execute();
            }
        }
        $stmtStaffUpd->close();
        $stmtStaffIns->close();

        if (!empty($deleted_staff)) {
            $dsids = array();
            foreach ($deleted_staff as $dsid) {
                $dsid = (int)$dsid;
                if ($dsid > 0) { $dsids[] = $dsid; }
            }
            if (!empty($dsids)) {
                $conn->query("DELETE FROM onsite_staff_informations WHERE general_info_id = $gid AND staff_id IN (" . implode(',', $dsids) . ")");
            }
        }

        // ---- 3) Checklist ratings (optimized upsert per item without per-item round trips) ----
        $existingRatings = array();
        if ($gid_in !== '') {
            $rRes = $conn->query("SELECT checklists_id, rating_id FROM onsite_checklists_rating WHERE general_info_id = $gid");
            if ($rRes) {
                while ($rRow = $rRes->fetch_row()) {
                    $existingRatings[(int)$rRow[0]] = (int)$rRow[1];
                }
            }
        }

        $stmtRatingUpd = $conn->prepare("UPDATE onsite_checklists_rating SET rating_value=?, remarks=? WHERE rating_id=?");
        $stmtRatingIns = $conn->prepare("INSERT INTO onsite_checklists_rating(general_info_id, checklists_id, rating_value, remarks) VALUES (?, ?, ?, ?)");

        foreach ($cid as $k => $cRaw) {
            $c = (int)$cRaw;
            if ($c <= 0) { continue; }
            if (!isset($rating[$k]) || $rating[$k] === '') { continue; }
            $rv  = (int)$rating[$k];
            $rem = isset($remarks[$k]) ? trim((string)$remarks[$k]) : '';

            if (isset($existingRatings[$c])) {
                $stmtRatingUpd->bind_param("isi", $rv, $rem, $existingRatings[$c]);
                $stmtRatingUpd->execute();
            } else {
                $stmtRatingIns->bind_param("iiis", $gid, $c, $rv, $rem);
                $stmtRatingIns->execute();
            }
        }
        $stmtRatingUpd->close();
        $stmtRatingIns->close();

        // ---- 4) Recompute category scores (single aggregated query) ----
        if ($count > 0) {
            $conn->query("DELETE FROM onsite_checklists_rating_score WHERE general_info_id='$gid'");
            $catSql = "SELECT oc.category_id,
                              COUNT(cr.rating_id) AS total_count,
                              SUM(CASE WHEN cr.rating_value = 1 THEN 1 ELSE 0 END) AS yes_count
                       FROM onsite_checklists_rating cr
                       JOIN ms_onsite_checklists cl ON cr.checklists_id = cl.checklist_id
                       JOIN ms_onsite_subcategories os ON cl.subcategory_id = os.subcategory_id
                       JOIN ms_onsite_categories oc ON os.category_id = oc.category_id
                       WHERE cr.rating_value IN (0, 1) AND cr.general_info_id = $gid
                       GROUP BY oc.category_id";
            $totqry = $conn->query($catSql);
            if ($totqry && $totqry->num_rows > 0) {
                $scoreStmt = $conn->prepare("INSERT INTO onsite_checklists_rating_score(general_info_id, category_id, score_value) VALUES (?, ?, ?)");
                while ($totRow = $totqry->fetch_assoc()) {
                    $catId = (int)$totRow['category_id'];
                    $tCount = (int)$totRow['total_count'];
                    $yCount = (int)$totRow['yes_count'];
                    $scoreVal = $tCount > 0 ? round(($yCount / $tCount) * 100, 2) : 0.00;
                    $scoreStmt->bind_param("iid", $gid, $catId, $scoreVal);
                    $scoreStmt->execute();
                }
                $scoreStmt->close();
            }
        }

        // ---- 5) Recommendation / Action / Findings (single row per record; upsert) ----
        $oraExists = $conn->query("SELECT ora_id FROM onsite_recommendation_action WHERE general_info_id='$gid'");
        if ($oraExists && $oraExists->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE onsite_recommendation_action SET recommendation=?, actions=?, findings=? WHERE general_info_id=?");
            $stmt->bind_param("sssi", $recommendation, $actions, $findings, $gid);
        } else {
            $stmt = $conn->prepare("INSERT INTO onsite_recommendation_action(general_info_id, recommendation, actions, findings) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $gid, $recommendation, $actions, $findings);
        }
        $stmt->execute();
        $stmt->close();

        // ---- 6) Action evidence images (Admin only) ----
        // Must run inside the transaction so a failed upload rolls everything back.
        if (isset($_SESSION['roleID']) && (int)$_SESSION['roleID'] === 1) {
            if (!uploadActionImages($conn, $gid)) {
                throw new Exception('Action image upload failed');
            }
        }

        $conn->commit();
        $check = true;

        /* Workflow notification — AFTER the commit, so a mail problem can never
           roll back the visit. The M&S wizard is one transactional submit, so this
           is the single completion event; who is notified depends on who owns the
           next step: an External audit goes to the facility that was audited, an
           Internal (self) audit goes to RCDC for review. */
        try {
            require_once(__DIR__ . "/../notifications/mn-notify.php");
            $msEvent = (strcasecmp(trim((string)$audit), "External") === 0)
                     ? "MS_EXTERNAL_AUDIT_COMPLETED" : "MS_INTERNAL_AUDIT_COMPLETED";
            mn_notify($conn, $msEvent, (int)$gid);
        } catch (\Throwable $msNotifyErr) { /* never affects the saved visit */ }

        // Decouple PDF generation: No PDF is generated synchronously on save.
        // Return early JSON immediately after commit for AJAX requests!
        if ($isAjax) {
            $conn->close();
            ms_respond_json('success', 'Submitted Successfully', [
                'id'      => (int)$gid,
                'gid'     => (int)$gid,
                'lnk'     => "ms-registration.php?action=2&id=$gid"
            ], 200);
        }

        $lnk = "ms-registration.php?action=2&id=$gid";
    } catch (\Throwable $e) {
        if (isset($conn) && $conn instanceof mysqli && $conn->ping()) {
            @$conn->rollback();
            @$conn->close();
        }
        $check = false;
        $errmsg = $e->getMessage();
        error_log('[mNEQAS ms-registration-save] Error: ' . $errmsg . "\n" . $e->getTraceAsString());
        if ($isAjax) {
            ms_respond_json('error', 'Submission Failed!! ' . $errmsg, [
                'error'        => $errmsg,
                'error_detail' => $errmsg
            ], 500);
        }
        $lnk = ($gid_in === '') ? "ms-registration.php?action=1" : "ms-registration.php?action=2&id=".(int)$gid_in;
    }

    // HTML fallback for non-AJAX submissions
    require_once(__DIR__ . "/../includes/css-jquery.php");
    echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

    if ($check) {
?>
    <script>
        $(function() {
            var lnk = $('#lnk').val();
            Swal.fire({
                icon: 'success',
                confirmButtonText: 'Close',
                text: 'Submitted Successfully!!',
                showCloseButton: true
            })
            .then(function (result) { window.location = lnk; })
        });
    </script>
    <?php } else { ?>
    <script>
        $(function() {
            var lnk = $('#lnk').val();
            Swal.fire({
                icon: 'error',
                confirmButtonText: 'Close',
                text: 'Submission Failed!! Nothing was saved. <?= htmlspecialchars($errmsg ?? '', ENT_QUOTES, 'UTF-8'); ?>',
                showCloseButton: true
            })
            .then(function (result) { window.location = lnk; })
        });
    </script>
<?php } ?>
