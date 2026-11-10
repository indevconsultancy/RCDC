<?php
/**
 * iu-molecular.php
 *
 * Unified Molecular Workflow Backend
 *
 * Operations:
 *   save_sample
 *   save_shipment
 *   save_receipt
 *   save_pcr
 *   complete
 *
 * IMPORTANT:
 * - Server-side RBAC enforced
 * - Facility ownership enforced
 * - AUTO_INCREMENT used
 * - Shipment creation + sample assignment is transactional
 * - Already-shipped samples rejected
 * - Receipt count validated
 * - PCR restricted to RCDC/Admin
 */

require_once("../config/session.php");
require_once("../config/auth.php");
require_once("../config/db-config.php");
require_once("../config/commondb-config.php");

header('Content-Type: application/json; charset=utf-8');



/*
|--------------------------------------------------------------------------
| Basic authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["roleID"])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please login again.'
    ]);
    exit;
}

$roleID = (int)$_SESSION["roleID"];
$isRCDC = ($roleID === 1 || $roleID === 2);

// Write capability (default deny): Administrator, RCDC Labs, Health Worker only.
// District Health Office and all other roles are read-only for Molecular data.
if (!mn_can_write()) {
    mn_deny_json('You have read-only access and cannot modify Molecular data.');
}
$userFacilityId = isset($_SESSION["facilityID"])
    ? (string)$_SESSION["facilityID"]
    : '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/* Workflow e-mail notifications. Included here so every operation below can
   raise its event AFTER the data is committed; mn_notify() never throws, so a
   mail problem cannot turn a successful JSON response into a failure. */
require_once(__DIR__ . '/../notifications/mn-notify.php');

function response_success($data = [])
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function response_error($message, $status = 400)
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

function post_string($name, $default = '')
{
    return isset($_POST[$name]) ? trim((string)$_POST[$name]) : $default;
}

function post_int($name, $default = 0)
{
    return isset($_POST[$name]) ? (int)$_POST[$name] : $default;
}

function valid_date($date)
{
    if ($date === '') {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/*
|--------------------------------------------------------------------------
| Request
|--------------------------------------------------------------------------
*/

$operation = post_string('operation');

if ($operation === '') {
    response_error('No workflow operation was supplied.');
}

/*
|--------------------------------------------------------------------------
| Common sample fields
|--------------------------------------------------------------------------
*/

$dbsid        = post_int('dbsid');
$facility     = post_string('facility');
$csite        = post_string('csite');
$caseType     = post_string('case_type');
$pname        = post_string('pname');
$age          = post_string('age');
$gender       = post_string('gender');
$nationality  = post_string('nationality');
$bseno        = post_string('bseno');
$doc          = post_string('doc');
$collectedby  = post_string('collectedby');
$microscopy   = post_string('microscopy');
$rdt          = post_string('rdt');

/*
|--------------------------------------------------------------------------
| Validation lists
|--------------------------------------------------------------------------
*/

$allowedCaseTypes = ['PCD', 'RACD', 'PACD', 'ACD'];
$allowedGender    = ['Male', 'Female', 'Others'];
$allowedNationality = ['Bhutanese', 'NonBhutanese'];
$allowedMicroscopy = [
    'Nmps', 'Pf', 'Pv', 'F+V', 'Po', 'Pm', 'Pk', 'Not Done'
];
$allowedRDT = [
    'Neg', 'Pf', 'Pv', 'F+V', 'Po', 'Pm', 'Pk', 'Not Done'
];
$allowedPCR = [
    'Negative', 'Pf', 'Pv', 'F+V', 'Po', 'Pm', 'Pk', 'NA'
];

/*
|--------------------------------------------------------------------------
| Validate sample data
|--------------------------------------------------------------------------
*/

function validate_sample_data(
    $facility,
    $caseType,
    $pname,
    $age,
    $gender,
    $nationality,
    $bseno,
    $doc,
    $collectedby,
    $microscopy,
    $rdt,
    $allowedCaseTypes,
    $allowedGender,
    $allowedNationality,
    $allowedMicroscopy,
    $allowedRDT
) {
    if ($facility === '') {
        response_error('Health facility is required.');
    }
    if ($caseType === '' || !in_array($caseType, $allowedCaseTypes, true)) {
        response_error('Invalid case type.');
    }
    if ($pname === '') {
        response_error('Patient name is required.');
    }
    if ($age === '' || !is_numeric($age) || (float)$age < 0) {
        response_error('Valid patient age is required.');
    }
    // Age is a whole number of years (the form uses step="1").
    if (!ctype_digit(trim((string)$age))) {
        response_error('Patient age must be a whole number.');
    }
    if (!in_array($gender, $allowedGender, true)) {
        response_error('Invalid gender.');
    }
    if (!in_array($nationality, $allowedNationality, true)) {
        response_error('Invalid nationality.');
    }
    if ($bseno === '') {
        response_error('BSE number is required.');
    }
    if (!valid_date($doc)) {
        response_error('Collection date must be YYYY-MM-DD.');
    }
    if ($collectedby === '') {
        response_error('Collected By is required.');
    }
    if (!in_array($microscopy, $allowedMicroscopy, true)) {
        response_error('Invalid microscopy result.');
    }
    if (!in_array($rdt, $allowedRDT, true)) {
        response_error('Invalid RDT result.');
    }
}

/*
|--------------------------------------------------------------------------
| Validate facility ownership
|--------------------------------------------------------------------------
*/

function verify_facility_access($facility, $isRCDC, $userFacilityId)
{
    if ($facility === '') {
        response_error('Facility is required.');
    }

    if (!$isRCDC && (string)$facility !== (string)$userFacilityId) {
        response_error('You are not authorized to access this facility.', 403);
    }
    // Facility existence is enforced by the session / UI cascade.
    // No query against dbs_results is needed (or safe) here.
}

/*
|--------------------------------------------------------------------------
| Require RCDC/Admin
|--------------------------------------------------------------------------
*/

function require_rcdc($isRCDC)
{
    if (!$isRCDC) {

        response_error(
            'Only RCDC/Admin users are authorized for this action.',
            403
        );
    }
}


/*
|--------------------------------------------------------------------------
| Verify facility exists
|--------------------------------------------------------------------------
*/

function verify_facility_exists($commondb, $facility)
{
    $stmt = $commondb->prepare("
        SELECT center_id
        FROM ms_health_center_table
        WHERE center_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        response_error(
            'Unable to validate health facility.'
        );
    }

    $stmt->bind_param(
        "s",
        $facility
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $exists =
        $result &&
        $result->num_rows === 1;

    $stmt->close();

    if (!$exists) {

        response_error(
            'Invalid health facility.',
            422
        );
    }
}


/*
|--------------------------------------------------------------------------
| Verify facility access + existence
|--------------------------------------------------------------------------
*/

function verify_facility_access_strict(
    $commondb,
    $facility,
    $isRCDC,
    $userFacilityId
) {

    if ($facility === '') {

        response_error(
            'Health facility is required.'
        );
    }

    verify_facility_exists(
        $commondb,
        $facility
    );

    if (
        !$isRCDC &&
        (string)$facility !==
        (string)$userFacilityId
    ) {

        response_error(
            'You are not authorized to access this facility.',
            403
        );
    }
}


/*
|--------------------------------------------------------------------------
| SAVE PATIENT + SHIPMENT
|--------------------------------------------------------------------------
|
| This is the new unified Step 1 operation.
|
| One user action:
|
| Patient information
|       +
| Shipment information
|
| is saved atomically.
|
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| SAVE PATIENT + SHIPMENT
|--------------------------------------------------------------------------
*/

if ($operation === 'save_patient_shipment') {

    /*
    |--------------------------------------------------------------------------
    | STEP 1 - Validate patient
    |--------------------------------------------------------------------------
    */

    validate_sample_data(
        $facility,
        $caseType,
        $pname,
        $age,
        $gender,
        $nationality,
        $bseno,
        $doc,
        $collectedby,
        $microscopy,
        $rdt,
        $allowedCaseTypes,
        $allowedGender,
        $allowedNationality,
        $allowedMicroscopy,
        $allowedRDT
    );


    /*
    |--------------------------------------------------------------------------
    | Facility authorization
    |--------------------------------------------------------------------------
    */

    verify_facility_access_strict(
    $commondb,
    $facility,
    $isRCDC,
    $userFacilityId
    );


    /*
    |--------------------------------------------------------------------------
    | Additional patient entries (Entry 2, 3, ... of the same submission)
    |--------------------------------------------------------------------------
    | Posted as parallel arrays by the "Add Another Entry" block on Step 1.
    | Validated here, before the transaction opens, so a bad entry rejects the
    | whole submission without writing anything. A submission with no extra
    | entries behaves exactly as it always has.
    */

    $extraRows = [];

    if (isset($_POST['extra_pname']) && is_array($_POST['extra_pname'])) {

        $extraCount = count($_POST['extra_pname']);

        for ($ei = 0; $ei < $extraCount; $ei++) {

            $pick = function ($field) use ($ei) {
                return (isset($_POST[$field]) && is_array($_POST[$field]) && isset($_POST[$field][$ei]))
                    ? trim((string)$_POST[$field][$ei])
                    : '';
            };

            $row = [
                'pname'       => $pick('extra_pname'),
                'age'         => $pick('extra_age'),
                'gender'      => $pick('extra_gender'),
                'nationality' => $pick('extra_nationality'),
                'bseno'       => $pick('extra_bseno'),
                'doc'         => $pick('extra_doc'),
                'collectedby' => $pick('extra_collectedby'),
                'csite'       => $pick('extra_csite'),
                'case_type'   => $pick('extra_case_type'),
                'microscopy'  => $pick('extra_microscopy'),
                'rdt'         => $pick('extra_rdt'),
            ];

            // A completely blank row is an untouched template: skip it.
            $filled = false;
            foreach ($row as $v) { if ($v !== '') { $filled = true; break; } }
            if (!$filled) { continue; }

            // Same rules as Entry 1 - each entry is validated independently.
            validate_sample_data(
                $facility,
                $row['case_type'],
                $row['pname'],
                $row['age'],
                $row['gender'],
                $row['nationality'],
                $row['bseno'],
                $row['doc'],
                $row['collectedby'],
                $row['microscopy'],
                $row['rdt'],
                $allowedCaseTypes,
                $allowedGender,
                $allowedNationality,
                $allowedMicroscopy,
                $allowedRDT
            );

            $extraRows[] = $row;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Shipment fields
    |--------------------------------------------------------------------------
    */

    $sid =
        post_int('sid');

    $shippedBy =
        post_string('shippedby');

    $shipmentDate =
        post_string('dos');


    if ($shippedBy === '') {

        response_error(
            'Shipped By is required.'
        );
    }


    if (!valid_date($shipmentDate)) {

        response_error(
            'Shipment date must be YYYY-MM-DD.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Optional existing shipment samples
    |--------------------------------------------------------------------------
    */

    $sampleIds = [];

    if (
        isset($_POST['sample_id']) &&
        is_array($_POST['sample_id'])
    ) {

        foreach (
            $_POST['sample_id']
            as $selectedId
        ) {

            $selectedId =
                (int)$selectedId;

            if ($selectedId > 0) {

                $sampleIds[] =
                    $selectedId;
            }
        }
    }

    $sampleIds = array_values(
        array_unique($sampleIds)
    );


    /*
    |--------------------------------------------------------------------------
    | TRANSACTION
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | A. CREATE OR UPDATE PATIENT/SAMPLE
        |--------------------------------------------------------------------------
        */

        if ($dbsid <= 0) {

            /*
            * NEW SAMPLE
            */

            $stmt = $conn->prepare("
                INSERT INTO dbs_results
                (
                    facility_id,
                    collection_site,
                    sample_type,
                    patient_name,
                    age,
                    gender,
                    nationality,
                    bse_no,
                    collection_date,
                    collected_by,
                    microscopy,
                    rdt
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {

                throw new Exception(
                    'Unable to prepare patient insert: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                "ssssssssssss",
                $facility,
                $csite,
                $caseType,
                $pname,
                $age,
                $gender,
                $nationality,
                $bseno,
                $doc,
                $collectedby,
                $microscopy,
                $rdt
            );

            if (!$stmt->execute()) {

                $error =
                    $stmt->error;

                $stmt->close();

                throw new Exception(
                    'Unable to create molecular sample: ' .
                    $error
                );
            }

            $dbsid =
                (int)$conn->insert_id;

            $stmt->close();


            if ($dbsid <= 0) {

                throw new Exception(
                    'Sample ID was not generated.'
                );
            }

        } else {

            /*
            * EXISTING SAMPLE
            */

            $stmt = $conn->prepare("
                SELECT
                    dbs_id,
                    facility_id,
                    dbs_shipment_id
                FROM dbs_results
                WHERE dbs_id = ?
                LIMIT 1
            ");

            if (!$stmt) {

                throw new Exception(
                    'Unable to prepare sample lookup: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                "i",
                $dbsid
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            if (
                !$result ||
                $result->num_rows === 0
            ) {

                $stmt->close();

                throw new Exception(
                    'Molecular sample not found.'
                );
            }

            $existing =
                $result->fetch_assoc();

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | Ownership
            |--------------------------------------------------------------------------
            */

            if (
                !$isRCDC &&
                (string)$existing['facility_id'] !==
                (string)$userFacilityId
            ) {

                throw new Exception(
                    'You are not authorized to modify this sample.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Already shipped
            |--------------------------------------------------------------------------
            */

            if (
                !empty(
                    $existing['dbs_shipment_id']
                )
            ) {

                throw new Exception(
                    'This sample has already been shipped.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Update patient/sample
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE dbs_results
                SET
                    facility_id = ?,
                    collection_site = ?,
                    sample_type = ?,
                    patient_name = ?,
                    age = ?,
                    gender = ?,
                    nationality = ?,
                    bse_no = ?,
                    collection_date = ?,
                    collected_by = ?,
                    microscopy = ?,
                    rdt = ?
                WHERE dbs_id = ?
            ");

            if (!$stmt) {

                throw new Exception(
                    'Unable to prepare patient update: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                "ssssssssssssi",
                $facility,
                $csite,
                $caseType,
                $pname,
                $age,
                $gender,
                $nationality,
                $bseno,
                $doc,
                $collectedby,
                $microscopy,
                $rdt,
                $dbsid
            );

            if (!$stmt->execute()) {

                $error =
                    $stmt->error;

                $stmt->close();

                throw new Exception(
                    'Unable to update molecular sample: ' .
                    $error
                );
            }

            $stmt->close();
        }


        /*
        |--------------------------------------------------------------------------
        | A2. CREATE THE ADDITIONAL PATIENT ENTRIES
        |--------------------------------------------------------------------------
        | Each one is a normal dbs_results row for this facility. They are created
        | inside the same transaction, so either every entry of the submission is
        | saved or none is.
        */

        $extraIds = [];

        if (count($extraRows) > 0) {

            $extraStmt = $conn->prepare("
                INSERT INTO dbs_results
                (
                    facility_id,
                    collection_site,
                    sample_type,
                    patient_name,
                    age,
                    gender,
                    nationality,
                    bse_no,
                    collection_date,
                    collected_by,
                    microscopy,
                    rdt
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$extraStmt) {

                throw new Exception(
                    'Unable to prepare additional patient insert: ' .
                    $conn->error
                );
            }

            foreach ($extraRows as $exIndex => $ex) {

                $extraStmt->bind_param(
                    "ssssssssssss",
                    $facility,
                    $ex['csite'],
                    $ex['case_type'],
                    $ex['pname'],
                    $ex['age'],
                    $ex['gender'],
                    $ex['nationality'],
                    $ex['bseno'],
                    $ex['doc'],
                    $ex['collectedby'],
                    $ex['microscopy'],
                    $ex['rdt']
                );

                if (!$extraStmt->execute()) {

                    $error = $extraStmt->error;
                    $extraStmt->close();

                    throw new Exception(
                        'Unable to save patient entry ' .
                        ($exIndex + 2) . ': ' . $error
                    );
                }

                $newExtraId = (int)$conn->insert_id;

                if ($newExtraId <= 0) {

                    $extraStmt->close();

                    throw new Exception(
                        'Sample ID was not generated for patient entry ' .
                        ($exIndex + 2) . '.'
                    );
                }

                $extraIds[] = $newExtraId;
            }

            $extraStmt->close();
        }


        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        | If no sample was explicitly selected,
        | ship the current patient/sample.
        |--------------------------------------------------------------------------
        */

        if (count($sampleIds) === 0) {

            $sampleIds = [$dbsid];
        }


        /*
        | The extra entries of this submission ship with it, so they all end up
        | under the same dbs_shipment_id and total_dbs_shipped counts them all.
        */

        foreach ($extraIds as $extraId) {

            if (!in_array($extraId, $sampleIds, true)) {

                $sampleIds[] = $extraId;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | B. VALIDATE SHIPMENT SAMPLES
        |--------------------------------------------------------------------------
        */

        $checkStmt = $conn->prepare("
            SELECT
                dbs_id,
                facility_id,
                dbs_shipment_id
            FROM dbs_results
            WHERE dbs_id = ?
            LIMIT 1
        ");

        if (!$checkStmt) {

            throw new Exception(
                'Unable to prepare shipment sample validation.'
            );
        }


        foreach ($sampleIds as $selectedId) {

            $checkStmt->bind_param(
                "i",
                $selectedId
            );

            if (!$checkStmt->execute()) {

                throw new Exception(
                    'Unable to validate shipment sample.'
                );
            }

            $result =
                $checkStmt->get_result();

            if (
                !$result ||
                $result->num_rows === 0
            ) {

                throw new Exception(
                    'Selected sample #' .
                    $selectedId .
                    ' does not exist.'
                );
            }

            $selectedSample =
                $result->fetch_assoc();


            /*
            |--------------------------------------------------------------------------
            | Facility
            |--------------------------------------------------------------------------
            */

            if (
                (string)$selectedSample['facility_id'] !==
                (string)$facility
            ) {

                throw new Exception(
                    'Selected sample #' .
                    $selectedId .
                    ' does not belong to the selected facility.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | District ownership
            |--------------------------------------------------------------------------
            */

            if (
                !$isRCDC &&
                (string)$selectedSample['facility_id'] !==
                (string)$userFacilityId
            ) {

                throw new Exception(
                    'You are not authorized to ship sample #' .
                    $selectedId .
                    '.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Already shipped
            |--------------------------------------------------------------------------
            */

            if (
                !empty(
                    $selectedSample['dbs_shipment_id']
                )
            ) {

                throw new Exception(
                    'Sample #' .
                    $selectedId .
                    ' has already been shipped.'
                );
            }
        }


        $checkStmt->close();


        /*
        |--------------------------------------------------------------------------
        | C. CREATE SHIPMENT
        |--------------------------------------------------------------------------
        */

        $totalDBS =
            count($sampleIds);


        $stmt = $conn->prepare("
            INSERT INTO dbs_shipment_details
            (
                facility_id,
                total_dbs_shipped,
                shipped_by,
                shipment_date
            )
            VALUES (?, ?, ?, ?)
        ");

        if (!$stmt) {

            throw new Exception(
                'Unable to prepare shipment insert: ' .
                $conn->error
            );
        }


        $stmt->bind_param(
            "siss",
            $facility,
            $totalDBS,
            $shippedBy,
            $shipmentDate
        );


        if (!$stmt->execute()) {

            $error =
                $stmt->error;

            $stmt->close();

            throw new Exception(
                'Unable to create shipment: ' .
                $error
            );
        }


        $newShipmentId =
            (int)$conn->insert_id;

        $stmt->close();


        if ($newShipmentId <= 0) {

            throw new Exception(
                'Shipment ID was not generated.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | D. ASSIGN SAMPLES
        |--------------------------------------------------------------------------
        */

        $assignStmt = $conn->prepare("
            UPDATE dbs_results
            SET dbs_shipment_id = ?
            WHERE dbs_id = ?
              AND facility_id = ?
              AND dbs_shipment_id IS NULL
        ");

        if (!$assignStmt) {

            throw new Exception(
                'Unable to prepare sample assignment.'
            );
        }


        foreach ($sampleIds as $selectedId) {

            $assignStmt->bind_param(
                "iis",
                $newShipmentId,
                $selectedId,
                $facility
            );


            if (!$assignStmt->execute()) {

                throw new Exception(
                    'Unable to assign sample #' .
                    $selectedId .
                    ' to shipment.'
                );
            }


            if (
                $assignStmt->affected_rows !== 1
            ) {

                throw new Exception(
                    'Sample #' .
                    $selectedId .
                    ' was already assigned to another shipment.'
                );
            }
        }


        $assignStmt->close();


        /*
        |--------------------------------------------------------------------------
        | E. VERIFY
        |--------------------------------------------------------------------------
        */

        $verifyStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM dbs_results
            WHERE dbs_shipment_id = ?
        ");

        if (!$verifyStmt) {

            throw new Exception(
                'Unable to verify shipment.'
            );
        }

        $verifyStmt->bind_param(
            "i",
            $newShipmentId
        );

        $verifyStmt->execute();

        $verifyResult =
            $verifyStmt->get_result();

        $verifyRow =
            $verifyResult->fetch_assoc();

        $verifyStmt->close();


        if (
            (int)$verifyRow['total'] !==
            $totalDBS
        ) {

            throw new Exception(
                'Shipment sample assignment verification failed.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | COMMIT
        |--------------------------------------------------------------------------
        */

        $conn->commit();


        response_success([
            'dbs_id' =>
                $dbsid,

            'shipment_id' =>
                $newShipmentId,

            'patient_saved' =>
                true,

            'shipment_saved' =>
                true,

            'redirect' =>
                'molecular.php?action=2&id=' .
                $dbsid .
                '&step=2'
        ]);


    } catch (Throwable $e) {

        $conn->rollback();

        response_error(
            $e->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| SAVE SAMPLE
|--------------------------------------------------------------------------
*/

if ($operation === 'save_sample') {

    validate_sample_data(
        $facility,
        $caseType,
        $pname,
        $age,
        $gender,
        $nationality,
        $bseno,
        $doc,
        $collectedby,
        $microscopy,
        $rdt,
        $allowedCaseTypes,
        $allowedGender,
        $allowedNationality,
        $allowedMicroscopy,
        $allowedRDT
    );

    verify_facility_access($facility, $isRCDC, $userFacilityId);

    // -------------------- New sample --------------------
    if ($dbsid <= 0) {

        $stmt = $conn->prepare("
            INSERT INTO dbs_results
            (
                facility_id,
                collection_site,
                sample_type,
                patient_name,
                age,
                gender,
                nationality,
                bse_no,
                collection_date,
                collected_by,
                microscopy,
                rdt
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            response_error('Unable to prepare sample insert: ' . $conn->error);
        }

        $stmt->bind_param(
            "ssssssssssss",
            $facility,
            $csite,
            $caseType,
            $pname,
            $age,
            $gender,
            $nationality,
            $bseno,
            $doc,
            $collectedby,
            $microscopy,
            $rdt
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            response_error('Unable to create molecular sample: ' . $error);
        }

        $newDbSid = (int)$conn->insert_id;
        $stmt->close();

        if ($newDbSid <= 0) {
            response_error('Sample was inserted but no generated ID was returned.');
        }

        response_success([
            'dbs_id'   => $newDbSid,
            'redirect' => 'molecular.php?action=2&id=' . $newDbSid . '&step=2'
        ]);
    }

    // -------------------- Existing sample --------------------
    $stmt = $conn->prepare("
        SELECT
            dbs_id,
            facility_id,
            dbs_shipment_id,
            pcr
        FROM dbs_results
        WHERE dbs_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        response_error('Unable to prepare sample lookup: ' . $conn->error);
    }

    $stmt->bind_param("i", $dbsid);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        response_error('Molecular sample not found.', 404);
    }

    $existing = $result->fetch_assoc();
    $stmt->close();

    // Facility ownership
    if (!$isRCDC && (string)$existing['facility_id'] !== (string)$userFacilityId) {
        response_error('You are not authorized to modify this sample.', 403);
    }

    // Facility users cannot change a sample once it has been batched into a shipment
    // (same lock as save_patient_shipment); RCDC keeps edit rights.
    if (!$isRCDC && !empty($existing['dbs_shipment_id'])) {
        response_error('A shipped sample can only be modified by RCDC.', 403);
    }

    // District user cannot move sample to another facility
    if (!$isRCDC && (string)$facility !== (string)$existing['facility_id']) {
        response_error('You cannot move a sample to another facility.', 403);
    }

    // Once shipment exists, facility must stay the same
    if (!empty($existing['dbs_shipment_id']) &&
        (string)$facility !== (string)$existing['facility_id']) {
        response_error('A shipped sample cannot be moved to another facility.');
    }

    // Update
    $stmt = $conn->prepare("
        UPDATE dbs_results
        SET
            facility_id     = ?,
            collection_site = ?,
            sample_type     = ?,
            patient_name    = ?,
            age             = ?,
            gender          = ?,
            nationality     = ?,
            bse_no          = ?,
            collection_date = ?,
            collected_by    = ?,
            microscopy      = ?,
            rdt             = ?
        WHERE dbs_id = ?
    ");

    if (!$stmt) {
        response_error('Unable to prepare sample update: ' . $conn->error);
    }

    $stmt->bind_param(
        "ssssssssssssi",
        $facility,
        $csite,
        $caseType,
        $pname,
        $age,
        $gender,
        $nationality,
        $bseno,
        $doc,
        $collectedby,
        $microscopy,
        $rdt,
        $dbsid
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        response_error('Unable to update sample: ' . $error);
    }

    $stmt->close();

    response_success([
        'dbs_id' => $dbsid
    ]);
}

/*
|--------------------------------------------------------------------------
| SAVE SHIPMENT
|--------------------------------------------------------------------------
*/

if ($operation === 'save_shipment') {

    $sid          = post_int('sid');
    $shippedBy    = post_string('shippedby');
    $shipmentDate = post_string('dos');

    if ($shippedBy === '') {
        response_error('Shipped By is required.');
    }
    if (!valid_date($shipmentDate)) {
        response_error('Shipment date must be YYYY-MM-DD.');
    }

    verify_facility_access($facility, $isRCDC, $userFacilityId);

    // -------------------- UPDATE EXISTING SHIPMENT --------------------
    if ($sid > 0) {

        $stmt = $conn->prepare("
            SELECT
                shipment_id,
                facility_id,
                total_dbs_shipped,
                total_dbs_received
            FROM dbs_shipment_details
            WHERE shipment_id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result || $result->num_rows === 0) {
            $stmt->close();
            response_error('Shipment not found.', 404);
        }

        $existingShipment = $result->fetch_assoc();
        $stmt->close();

        if (!$isRCDC &&
            (string)$existingShipment['facility_id'] !== (string)$userFacilityId) {
            response_error('You are not authorized to modify this shipment.', 403);
        }

        if (!empty($existingShipment['total_dbs_received'])) {
            response_error('This shipment has already been received and is locked.');
        }

        $stmt = $conn->prepare("
            UPDATE dbs_shipment_details
            SET
                shipped_by    = ?,
                shipment_date = ?
            WHERE shipment_id = ?
        ");

        $stmt->bind_param("ssi", $shippedBy, $shipmentDate, $sid);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            response_error('Unable to update shipment: ' . $error);
        }

        $stmt->close();

        response_success([
            'shipment_id' => $sid
        ]);
    }

    // -------------------- CREATE NEW SHIPMENT --------------------
    $sampleIds = [];

    if (isset($_POST['sample_id']) && is_array($_POST['sample_id'])) {
        foreach ($_POST['sample_id'] as $sampleId) {
            $sampleId = (int)$sampleId;
            if ($sampleId > 0) {
                $sampleIds[] = $sampleId;
            }
        }
    }

    $sampleIds = array_values(array_unique($sampleIds));

    if (count($sampleIds) === 0) {
        response_error('Select at least one DBS sample.');
    }

    $conn->begin_transaction();

    try {
        $checkStmt = $conn->prepare("
            SELECT
                dbs_id,
                facility_id,
                dbs_shipment_id
            FROM dbs_results
            WHERE dbs_id = ?
            LIMIT 1
        ");

        if (!$checkStmt) {
            throw new Exception('Unable to prepare sample validation.');
        }

        foreach ($sampleIds as $selectedId) {
            $checkStmt->bind_param("i", $selectedId);

            if (!$checkStmt->execute()) {
                throw new Exception('Unable to validate selected sample.');
            }

            $result = $checkStmt->get_result();

            if (!$result || $result->num_rows === 0) {
                throw new Exception('Selected sample #' . $selectedId . ' does not exist.');
            }

            $selectedSample = $result->fetch_assoc();

            if ((string)$selectedSample['facility_id'] !== (string)$facility) {
                throw new Exception(
                    'Selected sample #' . $selectedId .
                    ' does not belong to the selected facility.'
                );
            }

            if (!$isRCDC &&
                (string)$selectedSample['facility_id'] !== (string)$userFacilityId) {
                throw new Exception(
                    'You are not authorized to ship sample #' . $selectedId . '.'
                );
            }

            if (!empty($selectedSample['dbs_shipment_id'])) {
                throw new Exception(
                    'Sample #' . $selectedId . ' has already been shipped.'
                );
            }
        }

        $checkStmt->close();

        $totalDBS = count($sampleIds);

        $stmt = $conn->prepare("
            INSERT INTO dbs_shipment_details
            (
                facility_id,
                total_dbs_shipped,
                shipped_by,
                shipment_date
            )
            VALUES (?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception('Unable to prepare shipment insert.');
        }

        $stmt->bind_param(
            "siss",
            $facility,
            $totalDBS,
            $shippedBy,
            $shipmentDate
        );

        if (!$stmt->execute()) {
            throw new Exception('Unable to create shipment: ' . $stmt->error);
        }

        $newShipmentId = (int)$conn->insert_id;
        $stmt->close();

        if ($newShipmentId <= 0) {
            throw new Exception('Shipment was inserted but generated ID was not returned.');
        }

        $assignStmt = $conn->prepare("
            UPDATE dbs_results
            SET dbs_shipment_id = ?
            WHERE dbs_id = ?
              AND facility_id = ?
              AND dbs_shipment_id IS NULL
        ");

        if (!$assignStmt) {
            throw new Exception('Unable to prepare sample assignment.');
        }

        foreach ($sampleIds as $selectedId) {
            $assignStmt->bind_param("iis", $newShipmentId, $selectedId, $facility);

            if (!$assignStmt->execute()) {
                throw new Exception(
                    'Unable to assign sample #' . $selectedId . ' to shipment.'
                );
            }

            if ($assignStmt->affected_rows !== 1) {
                throw new Exception(
                    'Sample #' . $selectedId .
                    ' was already assigned to another shipment.'
                );
            }
        }

        $assignStmt->close();

        $verifyStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM dbs_results
            WHERE dbs_shipment_id = ?
        ");

        $verifyStmt->bind_param("i", $newShipmentId);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();
        $verifyRow    = $verifyResult->fetch_assoc();
        $verifyStmt->close();

        if ((int)$verifyRow['total'] !== $totalDBS) {
            throw new Exception('Shipment sample assignment verification failed.');
        }

        $conn->commit();

        mn_notify($conn, 'MOLECULAR_SHIPMENT_SUBMITTED', (int)$newShipmentId);   // -> RCDC receipt

        response_success([
            'shipment_id' => $newShipmentId,
            'redirect'    => 'molecular.php?action=2&id=' . $dbsid . '&step=3'
        ]);

    } catch (Throwable $e) {
        $conn->rollback();
        response_error($e->getMessage());
    }
}



/*
|--------------------------------------------------------------------------
| SAVE RECEIPT
|--------------------------------------------------------------------------
*/

if ($operation === 'save_receipt') {

    if (!$isRCDC) {
        response_error('Only RCDC/Admin can record shipment receipt.', 403);
    }

    $sid         = post_int('sid');
    $numberDBS   = post_string('dbs_received');
    if ($numberDBS === '') {
        $numberDBS = post_string('numberdbs');
    }
    $receivedBy  = post_string('receivedby');
    $receiptDate = post_string('dor');

    if ($sid <= 0) {
        response_error('Shipment ID is required.');
    }

    if ($numberDBS === '' || !is_numeric($numberDBS)) {
        response_error('Valid received DBS count is required.');
    }

    $numberDBS = (int)$numberDBS;

    if ($receivedBy === '') {
        response_error('Received By is required.');
    }

    if (!valid_date($receiptDate)) {
        response_error('Receipt date must be YYYY-MM-DD.');
    }

    $stmt = $conn->prepare("
        SELECT
            shipment_id,
            facility_id,
            total_dbs_shipped,
            total_dbs_received
        FROM dbs_shipment_details
        WHERE shipment_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        response_error('Shipment not found.', 404);
    }

    $existingShipment = $result->fetch_assoc();
    $stmt->close();

    $totalShipped = (int)$existingShipment['total_dbs_shipped'];

    if ($numberDBS < 0 || $numberDBS > $totalShipped) {
        response_error('Received DBS count must be between 0 and total DBS shipped.');
    }

    $stmt = $conn->prepare("
        UPDATE dbs_shipment_details
        SET
            total_dbs_received = ?,
            received_by        = ?,
            receive_date       = ?
        WHERE shipment_id = ?
    ");

    if (!$stmt) {
        response_error('Unable to prepare receipt update.');
    }

    $stmt->bind_param(
        "issi",
        $numberDBS,
        $receivedBy,
        $receiptDate,
        $sid
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        response_error('Unable to save receipt: ' . $error);
    }

    $stmt->close();

    mn_notify($conn, 'MOLECULAR_SHIPMENT_RECEIVED', (int)$sid);   // -> facility (informational)

    response_success([
    'receipt_saved' => true,
    'shipment_id'   => $sid,
    'redirect'      => 'molecular.php?action=2&id=' . $dbsid . '&step=3'
    ]);
}

/*
|--------------------------------------------------------------------------
| SAVE PCR
|--------------------------------------------------------------------------
*/

if ($operation === 'save_pcr') {

    if (!$isRCDC) {
        response_error('Only RCDC/Admin can enter PCR results.', 403);
    }

    if ($dbsid <= 0) {
        response_error('Sample ID is required.');
    }

    /*
    |--------------------------------------------------------------------------
    | PCR rows
    |--------------------------------------------------------------------------
    |
    | Step 3 posts one pcrrow[<dbs_id>][...] group per sample of the shipment.
    | A caller that posts the older single scalar set is normalised into one row
    | for the opened record, so both shapes share the validation and save below.
    |
    */

    $pcrRows = [];

    if (isset($_POST['pcrrow']) && is_array($_POST['pcrrow'])) {

        foreach ($_POST['pcrrow'] as $rowId => $row) {

            $rowId = (int)$rowId;

            if ($rowId <= 0 || !is_array($row)) {
                continue;
            }

            $pcrRows[$rowId] = [
                'ref_code' => isset($row['ref_code']) ? trim((string)$row['ref_code']) : '',
                'pcr'      => isset($row['pcr']) ? trim((string)$row['pcr']) : '',
                'pcr_date' => isset($row['pcr_date']) ? trim((string)$row['pcr_date']) : '',
                'remarks'  => isset($row['remarks']) ? trim((string)$row['remarks']) : ''
            ];
        }
    }

    if (count($pcrRows) === 0) {

        $pcrRows[$dbsid] = [
            'ref_code' => post_string('ref_code'),
            'pcr'      => post_string('pcr'),
            'pcr_date' => post_string('pcr_date'),
            'remarks'  => post_string('remarks')
        ];
    }

    foreach ($pcrRows as $rowId => $row) {

        if ($row['pcr'] === '' || !in_array($row['pcr'], $allowedPCR, true)) {
            response_error('Invalid PCR result for sample #' . $rowId . '.');
        }

        if ($row['pcr_date'] !== '' && !valid_date($row['pcr_date'])) {
            response_error('PCR date for sample #' . $rowId . ' must be YYYY-MM-DD.');
        }
    }

    $stmt = $conn->prepare("
        SELECT
            dbs_id,
            facility_id,
            dbs_shipment_id
        FROM dbs_results
        WHERE dbs_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $dbsid);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        response_error('Molecular sample not found.', 404);
    }

    $sample = $result->fetch_assoc();
    $stmt->close();

    if (empty($sample['dbs_shipment_id'])) {
        response_error('PCR cannot be entered before shipment.');
    }

    $shipmentId = (int)$sample['dbs_shipment_id'];

    $stmt = $conn->prepare("
        SELECT total_dbs_received
        FROM dbs_shipment_details
        WHERE shipment_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $shipmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        response_error('Shipment record not found.');
    }

    $shipmentRow = $result->fetch_assoc();
    $stmt->close();

    if ($shipmentRow['total_dbs_received'] === null ||
        $shipmentRow['total_dbs_received'] === '') {
        response_error('PCR cannot be entered before RCDC receipt.');
    }

    /*
    |--------------------------------------------------------------------------
    | Every posted row must belong to THIS shipment
    |--------------------------------------------------------------------------
    |
    | The opened record fixes the shipment (checked above). A row naming any
    | other sample is refused rather than silently written, so a tampered form
    | cannot reach samples outside this workflow.
    |
    */

    $shipmentSampleIds = [];

    $stmt = $conn->prepare("
        SELECT dbs_id
        FROM dbs_results
        WHERE dbs_shipment_id = ?
    ");

    if (!$stmt) {
        response_error('Unable to load shipment samples.');
    }

    $stmt->bind_param("i", $shipmentId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_row()) {
        $shipmentSampleIds[(int)$row[0]] = true;
    }

    $stmt->close();

    foreach ($pcrRows as $rowId => $row) {

        if (!isset($shipmentSampleIds[$rowId])) {

            response_error(
                'Sample #' . $rowId . ' does not belong to this shipment.',
                403
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Save all rows in one transaction
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("
            UPDATE dbs_results
            SET
                ref_code     = ?,
                pcr          = ?,
                pcr_date     = ?,
                pcr_remarks  = ?
            WHERE dbs_id = ?
              AND dbs_shipment_id = ?
        ");

        if (!$stmt) {
            throw new Exception('Unable to prepare PCR update.');
        }

        foreach ($pcrRows as $rowId => $row) {

            $rowRefCode = $row['ref_code'];
            $rowPcr     = $row['pcr'];
            $rowPcrDate = $row['pcr_date'];
            $rowRemarks = $row['remarks'];

            $stmt->bind_param(
                "ssssii",
                $rowRefCode,
                $rowPcr,
                $rowPcrDate,
                $rowRemarks,
                $rowId,
                $shipmentId
            );

            if (!$stmt->execute()) {

                $error = $stmt->error;

                $stmt->close();

                throw new Exception(
                    'Unable to save PCR result for sample #' . $rowId . ': ' . $error
                );
            }
        }

        $stmt->close();

        $conn->commit();

    } catch (Throwable $e) {

        $conn->rollback();

        response_error($e->getMessage());
    }

    /* PCR may be entered per sample or for the whole shipment at once, so the
       shipment-level notification is raised only when the LAST outstanding sample
       has been resulted. One e-mail per shipment, never one per DBS sample. */
    $pcrDone = $conn->prepare("SELECT COUNT(*), SUM(pcr IS NOT NULL AND pcr <> '')
                                 FROM dbs_results WHERE dbs_shipment_id = ?");
    if ($pcrDone) {
        $pcrDone->bind_param("i", $shipmentId);
        $pcrDone->execute();
        $pcrRow = $pcrDone->get_result()->fetch_row();
        $pcrDone->close();
        if ($pcrRow && (int)$pcrRow[0] > 0 && (int)$pcrRow[0] === (int)$pcrRow[1]) {
            mn_notify($conn, 'MOLECULAR_PCR_COMPLETED', (int)$shipmentId);
        }
    }

    response_success([
        'pcr_saved'   => true,
        'dbs_id'      => $dbsid,
        'saved_count' => count($pcrRows),
        'pcr_rows'    => $pcrRows
    ]);
}

/*
|--------------------------------------------------------------------------
| COMPLETE
|--------------------------------------------------------------------------
*/

if ($operation === 'complete') {

    require_rcdc($isRCDC);

    if ($dbsid <= 0) {
        response_error('Sample ID is required.');
    }

    $stmt = $conn->prepare("
        SELECT
            r.dbs_id,
            r.facility_id,
            r.dbs_shipment_id,
            r.pcr,
            s.total_dbs_shipped,
            s.total_dbs_received
        FROM dbs_results r
        LEFT JOIN dbs_shipment_details s
            ON s.shipment_id = r.dbs_shipment_id
        WHERE r.dbs_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $dbsid);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        response_error('Sample not found.', 404);
    }

    $final = $result->fetch_assoc();
    $stmt->close();

    if (!$isRCDC &&
        (string)$final['facility_id'] !== (string)$userFacilityId) {
        response_error('You are not authorized to complete this workflow.', 403);
    }

    if (empty($final['dbs_shipment_id'])) {
        response_error('Shipment has not been completed.');
    }

    if ($final['total_dbs_received'] === null ||
        $final['total_dbs_received'] === '') {
        response_error('RCDC receipt has not been completed.');
    }

    // Verify each received DBS sample has a valid PCR Result and PCR Date
    $shipmentId = (int)$final['dbs_shipment_id'];
    $stmtPcr = $conn->prepare("
        SELECT dbs_id, pcr, pcr_date
        FROM dbs_results
        WHERE dbs_shipment_id = ?
    ");

    if ($stmtPcr) {
        $stmtPcr->bind_param("i", $shipmentId);
        $stmtPcr->execute();
        $resPcr = $stmtPcr->get_result();
        $shipmentPcrRows = [];
        while ($pRow = $resPcr->fetch_assoc()) {
            $shipmentPcrRows[] = $pRow;
        }
        $stmtPcr->close();

        if (empty($shipmentPcrRows)) {
            if ($final['pcr'] === null || trim((string)$final['pcr']) === '' ||
                $final['pcr_date'] === null || trim((string)$final['pcr_date']) === '') {
                response_error('Each received DBS sample must have a valid PCR Result and PCR Date recorded.');
            }
        } else {
            foreach ($shipmentPcrRows as $pRow) {
                if ($pRow['pcr'] === null || trim((string)$pRow['pcr']) === '' ||
                    $pRow['pcr_date'] === null || trim((string)$pRow['pcr_date']) === '') {
                    response_error('Each received DBS sample must have a valid PCR Result and PCR Date recorded.');
                }
            }
        }
    } else {
        if ($final['pcr'] === null || trim((string)$final['pcr']) === '' ||
            $final['pcr_date'] === null || trim((string)$final['pcr_date']) === '') {
            response_error('Each received DBS sample must have a valid PCR Result and PCR Date recorded.');
        }
    }

    // ★ CHANGED: go to listing page after complete
    response_success([
        'completed' => true,
        'dbs_id'    => $dbsid,
        'redirect'  => 'molecular-list.php'
    ]);
}

/*
|--------------------------------------------------------------------------
| Unknown operation
|--------------------------------------------------------------------------
*/

response_error('Unknown workflow operation.');