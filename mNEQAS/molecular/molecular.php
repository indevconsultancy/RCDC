<?php
/**
 * molecular.php
 *
 * Unified Molecular Testing Workflow
 *
 * Steps:
 * 1. Sample Information
 * 2. Shipment
 * 3. RCDC Receipt
 * 4. PCR Result
 * 5. Review & Complete
 *
 * Existing database structure is preserved:
 *   dbs_results
 *   dbs_shipment_details
 *   dbs_results.dbs_shipment_id
 */

require_once("../config/session.php");
require_once("../config/commondb-config.php");
require_once("../config/db-config.php");
require_once("../includes/header.php");
require_once("../includes/css-jquery.php");
require_once("../includes/wizard-step-delete.php");   // shared per-step Delete

$isRCDC = (
    isset($_SESSION["roleID"]) &&
    ((int)$_SESSION["roleID"] === 1 || (int)$_SESSION["roleID"] === 2)
);

$userFacilityId = isset($_SESSION["facilityID"])
    ? (string)$_SESSION["facilityID"]
    : '';

$action = isset($_GET["action"]) ? (int)$_GET["action"] : 1;
$dbsid  = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
// Record-level authorization (IDOR): facility users may open only their own facility's sample.
if ($action === 2 && $dbsid > 0) { mn_require_record($conn, 'dbs', $dbsid); }

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Default record
|--------------------------------------------------------------------------
*/
$sample = [
    'dbs_id'           => '',
    'facility_id'      => '',
    'sample_type'      => '',
    'patient_name'     => '',
    'age'              => '',
    'gender'           => '',
    'nationality'      => '',
    'bse_no'           => '',
    'collection_date'  => '',
    'collected_by'     => '',
    'microscopy'       => '',
    'rdt'              => '',
    'pcr'              => '',
    'pcr_date'         => '',
    'pcr_remarks'      => '',
    'dbs_shipment_id'  => '',
    'collection_site'  => '',
    'ref_code'         => ''
];

$shipment = [
    'shipment_id'       => '',
    'facility_id'       => '',
    'total_dbs_shipped' => '',
    'shipped_by'        => '',
    'shipment_date'     => '',
    'total_dbs_received'=> '',
    'received_by'       => '',
    'receive_date'      => ''
];

$facilityName = '';
$dzongkhagCode = '';
$dzongkhagName = '';

/*
|--------------------------------------------------------------------------
| Load existing sample
|--------------------------------------------------------------------------
*/
if ($action === 2 && $dbsid > 0) {

    $stmt = $conn->prepare("
        SELECT
            dbs_id,
            facility_id,
            sample_type,
            patient_name,
            age,
            gender,
            nationality,
            bse_no,
            collection_date,
            collected_by,
            microscopy,
            rdt,
            pcr,
            pcr_date,
            pcr_remarks,
            dbs_shipment_id,
            collection_site,
            ref_code
        FROM dbs_results
        WHERE dbs_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $dbsid);
    $stmt->execute();

    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        echo "<script>alert('Molecular sample not found.'); window.location='molecular-list.php';</script>";
        exit;
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    $sample = array_merge($sample, $row);

    /*
    |--------------------------------------------------------------------------
    | District/Facility ownership check
    |--------------------------------------------------------------------------
    */
    function verify_facility_access(
    $facility,
    $isRCDC,
    $userFacilityId,
    $commondb
) {
    if ($facility === '') {
        response_error('Facility is required.');
    }

    $stmt = $commondb->prepare("
        SELECT center_id
        FROM ms_health_center_table
        WHERE center_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        response_error('Unable to validate health facility.');
    }

    $stmt->bind_param("s", $facility);
    $stmt->execute();

    $result = $stmt->get_result();
    $exists = $result && $result->num_rows === 1;

    $stmt->close();

    if (!$exists) {
        response_error('Invalid health facility.', 422);
    }

    if (
        !$isRCDC &&
        (string)$facility !== (string)$userFacilityId
    ) {
        response_error(
            'You are not authorized to access this facility.',
            403
        );
    }
}

    /*
    |--------------------------------------------------------------------------
    | Facility information
    |--------------------------------------------------------------------------
    */
    $stmt = $commondb->prepare("
        SELECT
            h.center_name,
            h.dzongkhag_code,
            d.dzongkhag_name
        FROM ms_health_center_table h
        LEFT JOIN ms_dzongkhag_table d
            ON d.dzongkhag_code = h.dzongkhag_code
        WHERE h.center_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("s", $sample['facility_id']);
    $stmt->execute();

    $facilityResult = $stmt->get_result();

    if ($facilityResult && $facilityResult->num_rows > 0) {
        $facilityRow = $facilityResult->fetch_assoc();

        $facilityName   = $facilityRow['center_name'];
        $dzongkhagCode  = $facilityRow['dzongkhag_code'];
        $dzongkhagName  = $facilityRow['dzongkhag_name'];
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Load shipment
    |--------------------------------------------------------------------------
    */
    if (!empty($sample['dbs_shipment_id'])) {

        $shipmentId = (int)$sample['dbs_shipment_id'];

        $stmt = $conn->prepare("
            SELECT
                shipment_id,
                facility_id,
                total_dbs_shipped,
                shipped_by,
                shipment_date,
                total_dbs_received,
                received_by,
                receive_date
            FROM dbs_shipment_details
            WHERE shipment_id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $shipmentId);
        $stmt->execute();

        $shipmentResult = $stmt->get_result();

        if ($shipmentResult && $shipmentResult->num_rows > 0) {
            $shipment = array_merge(
                $shipment,
                $shipmentResult->fetch_assoc()
            );
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| New sample - district user facility
|--------------------------------------------------------------------------
*/
if ($action !== 2 && !$isRCDC && $userFacilityId !== '') {

    $sample['facility_id'] = $userFacilityId;

    $stmt = $commondb->prepare("
        SELECT
            h.center_name,
            h.dzongkhag_code,
            d.dzongkhag_name
        FROM ms_health_center_table h
        LEFT JOIN ms_dzongkhag_table d
            ON d.dzongkhag_code = h.dzongkhag_code
        WHERE h.center_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("s", $userFacilityId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();

        $facilityName  = $row['center_name'];
        $dzongkhagCode = $row['dzongkhag_code'];
        $dzongkhagName = $row['dzongkhag_name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Workflow status
|--------------------------------------------------------------------------
*/
$hasSample   = ($action === 2 && !empty($sample['dbs_id']));
$hasShipment = !empty($shipment['shipment_id']);

/*
 * IMPORTANT:
 * 0 is a valid received count, therefore do NOT use empty().
 */
$hasReceipt = (
    isset($shipment['total_dbs_received']) &&
    $shipment['total_dbs_received'] !== null &&
    $shipment['total_dbs_received'] !== ''
);

$hasPCR = (
    isset($sample['pcr']) &&
    $sample['pcr'] !== null &&
    $sample['pcr'] !== ''
);

$receipt = [
    'dbs_received'       => ($hasReceipt && $shipment['total_dbs_received'] !== null && $shipment['total_dbs_received'] !== '')
                             ? $shipment['total_dbs_received']
                             : null,
    'total_dbs_received' => ($hasReceipt && $shipment['total_dbs_received'] !== null && $shipment['total_dbs_received'] !== '')
                             ? $shipment['total_dbs_received']
                             : null,
    'received_by'        => $shipment['received_by'] ?? '',
    'receive_date'       => $shipment['receive_date'] ?? ''
];


/*
|--------------------------------------------------------------------------
| Current workflow state
|--------------------------------------------------------------------------
|
| New:
|   Step 1
|
| Existing sample:
|   No shipment       => Step 2
|   Shipment exists   => Step 3
|   Receipt exists    => Step 4
|   PCR exists        => Step 5
|
/*
|--------------------------------------------------------------------------
| FINAL MOLECULAR WORKFLOW STATE
|--------------------------------------------------------------------------
|
| Step 1 = Patient + Shipment
| Step 2 = RCDC Receipt
| Step 3 = PCR Result
| Step 4 = Review & Complete
|
*/

$maxAccessibleStep = $isRCDC ? 4 : 1;

$currentStep = 1;

if ($action === 2) {

    if (!$hasSample) {

        $currentStep = 1;

    } elseif (!$hasShipment) {

        $currentStep = 1;

    } elseif (!$hasReceipt) {

        /*
         * Shipment exists.
         * Only RCDC can proceed to receipt.
         */
        $currentStep = $isRCDC ? 2 : 1;

    } elseif (!$hasPCR) {

        /*
         * Receipt exists.
         * Only RCDC can proceed to PCR.
         */
        $currentStep = $isRCDC ? 3 : 2;

    } else {

        $currentStep = 4;
    }
}

/*
|--------------------------------------------------------------------------
| Requested step
|--------------------------------------------------------------------------
*/

if (isset($_GET['step'])) {

    $requestedStep = (int)$_GET['step'];

    if ($requestedStep >= 1 && $requestedStep <= 4) {

        if ($requestedStep <= $maxAccessibleStep) {

            /*
             * Do not allow jumping to an invalid
             * future state.
             */

            if ($requestedStep === 2 && !$hasShipment) {
                $currentStep = 1;

            } elseif ($requestedStep === 3 && !$hasReceipt) {
                $currentStep = $isRCDC ? 2 : 1;

            } elseif ($requestedStep === 4 && !$hasPCR) {
                $currentStep = $isRCDC ? 3 : 2;

            } else {
                $currentStep = $requestedStep;
            }

        } else {

            $currentStep = $maxAccessibleStep;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Shipment samples
|--------------------------------------------------------------------------
*/
$shipmentSamples = [];

if (!empty($shipment['shipment_id'])) {

    $shipmentId = (int)$shipment['shipment_id'];

    $stmt = $conn->prepare("
        SELECT
            dbs_id,
            patient_name,
            age,
            gender,
            bse_no,
            collection_date,
            microscopy,
            rdt,
            nationality,
            collected_by,
            collection_site,
            sample_type,
            pcr,
            pcr_date,
            ref_code,
            pcr_remarks
        FROM dbs_results
        WHERE dbs_shipment_id = ?
        ORDER BY dbs_id ASC
    ");

    $stmt->bind_param("i", $shipmentId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $shipmentSamples[] = $row;
            if (!empty($row['pcr'])) {
                $hasPCR = true;
            }
            if ((int)$row['dbs_id'] === (int)$sample['dbs_id']) {
                $sample = array_merge($sample, $row);
            }
        }
    }

    $stmt->close();
}


/*
 * Maximum editable step based on role.
 *
 * District:
 *   Step 1 = Sample
 *   Step 2 = Shipment
 *
 * RCDC/Admin:
 *   Step 1 = Sample
 *   Step 2 = Shipment
 *   Step 3 = Receipt
 *   Step 4 = PCR
 *   Step 5 = Review
 */

/*
|--------------------------------------------------------------------------
| Step edit permissions
|--------------------------------------------------------------------------
*/
$sampleEditable = true;
$shipmentEditable = true;
$receiptEditable = $isRCDC;
$pcrEditable = $isRCDC;

/*
|--------------------------------------------------------------------------
| Once shipment is received, shipment header/sample membership is locked.
|--------------------------------------------------------------------------
*/
if ($hasReceipt) {
    $shipmentEditable = false;
}

/*
|--------------------------------------------------------------------------
| Delete links, one per workflow step.
|--------------------------------------------------------------------------
|
| Each step owns a different thing, so each gets the scope that clears that
| thing and nothing else:
|
|   1  Patient & Shipment  the sample row itself          delete.php?type=gi
|   2  RCDC Receipt        the receipt on the shipment    delete.php?type=rcdcship
|   3  PCR Result          this sample's PCR fields       delete.php?type=pcr
|   4  Review & Complete   the whole record               delete.php?type=gi
|
| A URL is built only when the signed-in user would actually be allowed to
| use it, because delete.php answers 403 otherwise and a button that always
| fails is worse than no button. That is also a fix: the old "Delete Sample"
| button was shown to everyone even though type=gi is Administrator-only, so
| a Health Worker or RCDC user clicking it got nothing but a denial.
|
| `back`/`backid` bring the user back to this workflow afterwards instead of
| the page each scope defaults to; delete.php whitelists them.
*/
$sampleId   = $hasSample ? (int)$sample['dbs_id'] : 0;
$shipmentId = $hasShipment ? (int)$shipment['shipment_id'] : 0;
$backHere   = '&back=molecular&backid=' . $sampleId;

/* The sample row: Administrator only, and for an Administrator it is
   unconditional - whatever stage the record reached, the whole thing goes,
   exactly as Malaria RDT QA and Blinded Rechecking behave. delete.php no
   longer refuses on account of a later stage either; its cascade clears the
   PCR columns with the row, puts the batch's stored count right and removes
   the batch outright if this was its last sample. */
$canDeleteSample = ($hasSample && mn_is_admin());

$deleteSampleUrl = $canDeleteSample
    ? "delete.php?type=gi&dbsid=" . $sampleId
    : '';

/* The receipt: RCDC only, and only while no PCR result depends on it. */
$deleteReceiptUrl = ($hasReceipt && $isRCDC && !$hasPCR && $shipmentId > 0)
    ? "delete.php?type=rcdcship&dbsid=" . $shipmentId . $backHere
    : '';

$receiptDenied = !$hasReceipt
    ? 'There is no receipt recorded for this shipment yet.'
    : (!$isRCDC
        ? 'Deleting a receipt is restricted to RCDC users.'
        : 'The receipt cannot be deleted while a PCR result exists. Delete the PCR result first.');

$sampleDenied = 'Deleting a Molecular Testing record is restricted to an Administrator.';

/* This sample's PCR fields: RCDC only. */
$deletePCRUrl = ($hasSample && $hasPCR && $isRCDC)
    ? "delete.php?type=pcr&dbsid=" . $sampleId . $backHere
    : '';

$dzongkhagResult = $commondb->query("
    SELECT dzongkhag_code, dzongkhag_name
    FROM ms_dzongkhag_table
    ORDER BY dzongkhag_name
");

?>

<body class="hold-transition sidebar-mini">

<div class="wrapper">

<?php require_once("../includes/top-navbar.php"); ?>
<?php require_once("../includes/sidebar-menu.php"); ?>

<div class="content-wrapper">

<section class="content-header">
    <div class="container-fluid">

        <div class="row mb-2">

            <div class="col-sm-6">
                <h1>Molecular Testing</h1>
            </div>

            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item">
                        <a href="../dashboard/dashboard.php">Dashboard</a>
                    </li>

                    <li class="breadcrumb-item">
                        <a href="molecular-list.php">Molecular Patient List</a>
                    </li>

                    <li class="breadcrumb-item active">
                        Molecular Testing
                    </li>
                </ol>
            </div>

        </div>

    </div>
</section>

<section class="content">

<div class="container-fluid">

<div class="card card-primary card-outline">

<div class="card-header">

    <h3 class="card-title">
        <i class="fa fa-vials"></i>
        Molecular Testing Workflow
    </h3>

</div>

<div class="card-body">

<?php if (!$isRCDC) { ?>

<div class="alert alert-info">
    <i class="fa fa-info-circle"></i>

    You can create and edit samples belonging to your facility.
    Shipment and receipt information is controlled according to your permissions.
    PCR results are entered by RCDC/Admin.
</div>

<?php } ?>


<!-- ==========================================================
     STEPPER
========================================================== -->

<div class="workflow-stepper">

    <!-- STEP 1 -->

    <div
        class="workflow-step"
        data-step="1"
        data-enabled="1"
        data-editable="1"
    >

        <div class="step-circle">
            1
        </div>

        <div class="step-title">
            Patient & Shipment
        </div>

        <div
            class="step-status"
            id="status1"
        >
            <?php

            if ($hasSample && $hasShipment) {
                echo 'Completed';
            } else {
                echo 'In Progress';
            }

            ?>
        </div>

    </div>


    <div class="workflow-line"></div>


    <!-- STEP 2 -->

    <div
        class="workflow-step"
        data-step="2"
        data-enabled="<?php echo $hasShipment ? '1' : '0'; ?>"
        data-editable="<?php echo ($hasShipment && $isRCDC) ? '1' : '0'; ?>"
    >

        <div class="step-circle">
            2
        </div>

        <div class="step-title">
            RCDC Receipt
        </div>

        <div
            class="step-status"
            id="status2"
        >
            <?php

            echo $hasReceipt
                ? 'Completed'
                : ($hasShipment ? 'Pending' : 'Locked');

            ?>
        </div>

    </div>


    <div class="workflow-line"></div>


    <!-- STEP 3 -->

    <div
        class="workflow-step"
        data-step="3"
        data-enabled="<?php echo $hasReceipt ? '1' : '0'; ?>"
        data-editable="<?php echo ($hasReceipt && $isRCDC) ? '1' : '0'; ?>"
    >

        <div class="step-circle">
            3
        </div>

        <div class="step-title">
            PCR Result
        </div>

        <div
            class="step-status"
            id="status3"
        >
            <?php

            echo $hasPCR
                ? 'Completed'
                : ($hasReceipt ? 'Pending' : 'Locked');

            ?>
        </div>

    </div>


    <div class="workflow-line"></div>


    <!-- STEP 4 -->

    <div
        class="workflow-step"
        data-step="4"
        data-enabled="<?php echo $hasPCR ? '1' : '0'; ?>"
        data-editable="0"
    >

        <div class="step-circle">
            4
        </div>

        <div class="step-title">
            Review & Complete
        </div>

        <div
            class="step-status"
            id="status4"
        >
            <?php
            echo ($currentStep === 4) ? 'Pending Review' : ($hasPCR ? 'Ready' : 'Pending');
            ?>
        </div>

    </div>

</div>

<div class="progress workflow-progress">

    <div
        id="workflowProgress"
        class="progress-bar bg-primary"
        role="progressbar"
        style="width:0%;">
    </div>

</div>

<!-- ==========================================================
     ONE FORM FOR ENTIRE WORKFLOW
========================================================== -->

<form
    method="POST"
    action="iu-molecular.php"
    id="molecularWorkflow"
    autocomplete="off"
>

<input type="hidden"
       name="workflow"
       value="molecular">

<input type="hidden"
       name="dbsid"
       id="dbsid"
       value="<?php echo h($sample['dbs_id']); ?>">

<input type="hidden"
       name="sid"
       id="sid"
       value="<?php echo h($shipment['shipment_id']); ?>">

<input type="hidden"
       name="current_step"
       id="current_step"
       value="<?php echo (int)$currentStep; ?>">

<input type="hidden"
       name="operation"
       id="operation"
       value="">

<!-- ==========================================================
     STEP 1
========================================================== -->

<div class="workflow-pane" data-step="1">

    <div class="section-heading d-flex justify-content-between align-items-center flex-wrap">

       <span>
           <i class="fa fa-user"></i>
           Patient &amp; Shipment Information
       </span>

       <?php if (!$hasSample) { ?>

       <button type="button" class="btn btn-sm bg-gradient-success ml-auto" id="mnAddEntry">
           <i class="fa fa-plus"></i> Add Another Entry
       </button>

       <?php } ?>

    </div>

    <div class="row">

        <?php if ($isRCDC) { ?>

        <div class="form-group col-lg-3 col-md-6">

            <label>Dzongkhag <span class="required">*</span></label>

            <select
                name="dzongkhag"
                id="dzongkhag"
                class="form-control select2"
                required
            >

                <option value="">Select Dzongkhag</option>

                <?php
                if ($dzongkhagResult) {

                    while ($dz = $dzongkhagResult->fetch_assoc()) {

                        $selected =
                            ((string)$dz['dzongkhag_code'] ===
                             (string)$dzongkhagCode)
                            ? 'selected'
                            : '';

                        echo '<option value="' .
                            h($dz['dzongkhag_code']) .
                            '" ' . $selected . '>' .
                            h($dz['dzongkhag_name']) .
                            '</option>';
                    }
                }
                ?>

            </select>

        </div>

        <?php } else { ?>

        <div class="form-group col-lg-3 col-md-6">

            <label>Dzongkhag</label>

            <input
                type="text"
                class="form-control"
                value="<?php echo h($dzongkhagName); ?>"
                readonly
            >

            <input
                type="hidden"
                name="dzongkhag"
                value="<?php echo h($dzongkhagCode); ?>"
            >

        </div>

        <?php } ?>

        <div class="form-group col-lg-3 col-md-6">

            <label>Health Facility <span class="required">*</span></label>

            <?php if ($isRCDC) { ?>

            <select
                name="facility"
                id="facility"
                class="form-control"
                required
            >

                <?php if (!empty($sample['facility_id'])) { ?>

                <option value="<?php echo h($sample['facility_id']); ?>">
                    <?php echo h($facilityName); ?>
                </option>

                <?php } else { ?>

                <option value="">Select Facility</option>

                <?php } ?>

            </select>

            <?php } else { ?>

            <input
                type="text"
                class="form-control"
                value="<?php echo h($facilityName); ?>"
                readonly
            >

            <input
                type="hidden"
                name="facility"
                value="<?php echo h($sample['facility_id']); ?>"
            >

            <?php } ?>

        </div>

    </div>

    <!-- ==========================================================
         Sample entries. Dzongkhag / Health Facility / Shipped By /
         Shipment Date above are shared by the whole shipment; everything
         below belongs to ONE patient sample. Entry 1 is the record's own
         sample; "Add Another Entry" appends more cards (entry 2, 3, ...)
         saved as their own dbs_results rows on the same shipment.
    ========================================================== -->
    <div class="card card-outline card-primary mn-entry-first" id="mnEntryOne">

      <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mb-0">
          <i class="fa fa-user"></i>
          <?php echo $hasSample ? 'Patient Sample' : 'Entry 1'; ?>
        </h3>
      </div>

      <div class="card-body pb-0">

        <div class="row">

        <div class="form-group col-lg-3 col-md-6">

            <label>Collection Site</label>

            <input
                type="text"
                name="csite"
                id="csite"
                class="form-control"
                value="<?php echo h($sample['collection_site']); ?>"
                placeholder="Collection Site"
            >

        </div>

        <div class="form-group col-lg-3 col-md-6">

            <label>Case Type <span class="required">*</span></label>

            <select
                name="case_type"
                id="case_type"
                class="form-control"
                required
            >

                <option value="">Select Type</option>

                <?php
                $caseTypes = ['PCD', 'RACD', 'PACD', 'ACD'];

                foreach ($caseTypes as $type) {

                    $selected =
                        ($sample['sample_type'] === $type)
                        ? 'selected'
                        : '';

                    echo '<option value="' . h($type) . '" ' .
                         $selected . '>' .
                         h($type) .
                         '</option>';
                }
                ?>

            </select>

        </div>

    </div>

    <div class="row">

        <div class="form-group col-lg-3 col-md-6">

            <label>Patient Name <span class="required">*</span></label>

            <input
                type="text"
                name="pname"
                class="form-control"
                value="<?php echo h($sample['patient_name']); ?>"
                required
            >

        </div>

        <div class="form-group col-lg-3 col-md-6">

            <label>Age <span class="required">*</span></label>

            <input
                type="number"
                name="age"
                class="form-control"
                value="<?php echo h($sample['age'] !== '' && $sample['age'] !== null && is_numeric($sample['age']) ? (string)(0 + $sample['age']) : $sample['age']); ?>"
                min="0"
                step="1"
                inputmode="numeric"
                required
            >

        </div>

        <div class="form-group col-lg-3 col-md-6">

            <label>Gender <span class="required">*</span></label>

            <select
                name="gender"
                class="form-control"
                required
            >

                <option value="">Select Gender</option>

                <?php
                foreach (['Male', 'Female', 'Others'] as $gender) {

                    $selected =
                        ($sample['gender'] === $gender)
                        ? 'selected'
                        : '';

                    echo '<option value="' . h($gender) .
                         '" ' . $selected . '>' .
                         h($gender) .
                         '</option>';
                }
                ?>

            </select>

        </div>

        <div class="form-group col-lg-3 col-md-6">

            <label>Nationality <span class="required">*</span></label>

            <select
                name="nationality"
                class="form-control"
                required
            >

                <option value="">Select Nationality</option>

                <?php
                foreach (['Bhutanese', 'NonBhutanese'] as $nationality) {

                    $selected =
                        ($sample['nationality'] === $nationality)
                        ? 'selected'
                        : '';

                    echo '<option value="' . h($nationality) .
                         '" ' . $selected . '>' .
                         h($nationality) .
                         '</option>';
                }
                ?>

            </select>

        </div>

    </div>

    <div class="row">

        <div class="form-group col-lg-2 col-md-6">

            <label>BSE No. <span class="required">*</span></label>

            <input
                type="text"
                name="bseno"
                class="form-control"
                value="<?php echo h($sample['bse_no']); ?>"
                required
            >

        </div>

        <div class="form-group col-lg-3 col-md-6">

            <label>Collection Date <span class="required">*</span></label>

            <div class="input-group date" data-target-input="nearest">
                <input
                    type="text"
                    name="doc"
                    id="doc"
                    class="form-control datetimepicker-input"
                    data-target="#doc"
                    value="<?php echo h($sample['collection_date']); ?>"
                    placeholder="YYYY-MM-DD"
                    required
                >
                <div class="input-group-append" data-target="#doc" data-toggle="datetimepicker">
                    <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                </div>
            </div>

        </div>

        <div class="form-group col-lg-3 col-md-6">

            <label>Collected By <span class="required">*</span></label>

            <input
                type="text"
                name="collectedby"
                class="form-control"
                value="<?php echo h($sample['collected_by']); ?>"
                required
            >

        </div>

        <div class="form-group col-lg-2 col-md-6">

            <label>Microscopy <span class="required">*</span></label>

            <select
                name="microscopy"
                class="form-control"
                required
            >

                <option value="">Select</option>

                <?php
                $microscopyOptions = [
                    'Nmps',
                    'Pf',
                    'Pv',
                    'F+V',
                    'Po',
                    'Pm',
                    'Pk',
                    'Not Done'
                ];

                foreach ($microscopyOptions as $option) {

                    $selected =
                        ($sample['microscopy'] === $option)
                        ? 'selected'
                        : '';

                    echo '<option value="' . h($option) .
                         '" ' . $selected . '>' .
                         h($option) .
                         '</option>';
                }
                ?>

            </select>

        </div>

        <div class="form-group col-lg-2 col-md-6">

            <label>RDT <span class="required">*</span></label>

            <select
                name="rdt"
                class="form-control"
                required
            >

                <option value="">Select</option>

                <?php
                $rdtOptions = [
                    'Neg',
                    'Pf',
                    'Pv',
                    'F+V',
                    'Po',
                    'Pm',
                    'Pk',
                    'Not Done'
                ];

                foreach ($rdtOptions as $option) {

                    $selected =
                        ($sample['rdt'] === $option)
                        ? 'selected'
                        : '';

                    echo '<option value="' . h($option) .
                         '" ' . $selected . '>' .
                         h($option) .
                         '</option>';
                }
                ?>

            </select>

        </div>

        </div>

      </div>
    </div>
    <!-- /Entry 1 -->

<?php
/* ===========================================================
   Opening a saved record (action=2, or Previous from a later step) shows the
   rest of the shipment as the same Entry 2, 3 ... cards the create path uses,
   pre-filled from dbs_results. They are read-only: once a shipment exists its
   sample membership is fixed server-side (save_patient_shipment refuses an
   already-shipped sample, and Step 1 then saves only the shipment header), so
   editable inputs or a Remove button here would promise a save that no path
   performs. Each card links to its own record for editing.
=========================================================== */
$mnOtherSamples = [];
if ($hasSample) {
    $mnOpenId = (int)$sample['dbs_id'];
    foreach ($shipmentSamples as $mnRow) {
        if ((int)$mnRow['dbs_id'] !== $mnOpenId) { $mnOtherSamples[] = $mnRow; }
    }
}
?>

<?php if ($hasSample && count($mnOtherSamples)) { ?>
<div class="mn-extra-wrap">

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
        <div>
            <strong class="text-primary">Additional Patient Entries</strong>
            <small class="text-muted d-block">
                Entry 1 is the card above. The entries below were shipped in the same
                batch and are shown as saved &mdash; a shipment&rsquo;s samples cannot be
                added or removed once it exists. Open an entry to edit it.
            </small>
        </div>
    </div>

    <?php
    /* Rendered from the saved row, field for field like Entry 1 and the
       template, so the three cannot drift. No name attributes: these cards
       are a view of the shipment and must never post anything. */
    $mnRoText = function ($label, $value, $req = false) {
        echo '<div class="form-group col-lg-3 col-md-6">'
           . '<label>' . h($label) . ($req ? ' <span class="required">*</span>' : '') . '</label>'
           . '<input type="text" class="form-control" value="' . h((string)$value) . '" readonly>'
           . '</div>';
    };
    foreach ($mnOtherSamples as $mnIdx => $mnRow) {
        $mnAge = (string)$mnRow['age'];
        if ($mnAge !== '' && is_numeric($mnAge)) { $mnAge = (string)(0 + $mnAge); }
    ?>
    <div class="card card-outline card-primary mn-entry mn-entry-saved">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">
          <i class="fa fa-user"></i> Entry <?php echo $mnIdx + 2; ?>
        </h3>
        <a class="btn btn-sm btn-outline-primary ml-auto"
           href="molecular.php?action=2&amp;id=<?php echo (int)$mnRow['dbs_id']; ?>&amp;step=1">
          <i class="fa fa-external-link-alt"></i> Open
        </a>
      </div>
      <div class="card-body pb-0">
        <div class="row">
          <?php
          $mnRoText('Patient Name',    $mnRow['patient_name'],    true);
          $mnRoText('Age',             $mnAge,                     true);
          $mnRoText('Gender',          $mnRow['gender'],          true);
          $mnRoText('Nationality',     $mnRow['nationality'],     true);
          $mnRoText('BSE No.',         $mnRow['bse_no'],          true);
          $mnRoText('Collection Date', $mnRow['collection_date'], true);
          $mnRoText('Collected By',    $mnRow['collected_by'],    true);
          $mnRoText('Collection Site', $mnRow['collection_site']);
          $mnRoText('Case Type',       $mnRow['sample_type'],     true);
          $mnRoText('Microscopy',      $mnRow['microscopy'],      true);
          $mnRoText('RDT',             $mnRow['rdt'],             true);
          ?>
        </div>
      </div>
    </div>
    <?php } ?>

</div>
<?php } ?>

<?php if (!$hasSample) { ?>
<!-- ==========================================================
     Additional patient entries (Entry 2, 3, ...).
     One shipment, many patients: each block below is saved as its own
     dbs_results row against the same dbs_shipment_id. Only offered while
     creating a record; editing an existing record still edits that sample.
========================================================== -->
<div class="mn-extra-wrap">

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
        <div>
            <strong class="text-primary">Additional Patient Entries</strong>
            <small class="text-muted d-block">
                Entry 1 is the card above. Use "Add Another Entry" in the section heading
                to add more patients; they are shipped together as one batch and
                "No. DBS Shipped" follows the number of entries.
            </small>
        </div>
    </div>

    <div id="mnExtraEntries"></div>

</div>

<?php
/* The template is rendered from the same option lists as Entry 1, so the two
   can never drift apart. Names are plain arrays: every field posts on every
   row, so the parallel arrays stay aligned. */
$mnOpts = function ($list) {
    $out = '<option value="">Select</option>';
    foreach ($list as $o) {
        $out .= '<option value="' . h($o) . '">' . h($o) . '</option>';
    }
    return $out;
};
?>
<template id="mnEntryTpl">
  <div class="card card-outline card-primary mn-entry">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <h3 class="card-title mb-0"><i class="fa fa-user"></i> <span class="mn-entry-no"></span></h3>
      <!-- ml-auto: AdminLTE adds a clearfix ::after to .card-header, which becomes a
           third flex item and leaves justify-content-between placing this button in
           the middle. margin-left:auto pins it to the far right instead. -->
      <button type="button" class="btn btn-sm btn-outline-danger mn-entry-del ml-auto">
        <i class="fa fa-times"></i> Remove
      </button>
    </div>
    <div class="card-body pb-0">
      <div class="row">
        <div class="form-group col-lg-3 col-md-6">
          <label>Patient Name <span class="required">*</span></label>
          <input type="text" name="extra_pname[]" class="form-control" required>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>Age <span class="required">*</span></label>
          <input type="number" name="extra_age[]" class="form-control" step="0.01" min="0" required>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>Gender <span class="required">*</span></label>
          <select name="extra_gender[]" class="form-control" required><?= $mnOpts(['Male', 'Female', 'Others']) ?></select>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>Nationality <span class="required">*</span></label>
          <select name="extra_nationality[]" class="form-control" required><?= $mnOpts(['Bhutanese', 'NonBhutanese']) ?></select>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>BSE No. <span class="required">*</span></label>
          <input type="text" name="extra_bseno[]" class="form-control" required>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>Collection Date <span class="required">*</span></label>
          <input type="date" name="extra_doc[]" class="form-control" required>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>Collected By <span class="required">*</span></label>
          <input type="text" name="extra_collectedby[]" class="form-control" required>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>Collection Site</label>
          <input type="text" name="extra_csite[]" class="form-control">
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>Case Type <span class="required">*</span></label>
          <select name="extra_case_type[]" class="form-control" required><?= $mnOpts(['PCD', 'RACD', 'PACD', 'ACD']) ?></select>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>Microscopy <span class="required">*</span></label>
          <select name="extra_microscopy[]" class="form-control" required><?= $mnOpts(['Nmps', 'Pf', 'Pv', 'F+V', 'Po', 'Pm', 'Pk', 'Not Done']) ?></select>
        </div>
        <div class="form-group col-lg-3 col-md-6">
          <label>RDT <span class="required">*</span></label>
          <select name="extra_rdt[]" class="form-control" required><?= $mnOpts(['Neg', 'Pf', 'Pv', 'F+V', 'Po', 'Pm', 'Pk', 'Not Done']) ?></select>
        </div>
      </div>
    </div>
  </div>
</template>
<?php } ?>

<?php if (!$hasShipment) { ?>

<div class="row">

    <div class="form-group col-lg-4 col-md-6">
        <label>No. DBS Shipped</label>

        <input
            type="number"
            name="totaldbs"
            id="totaldbs"
            class="form-control"
            value="<?php echo $hasSample ? '1' : '0'; ?>"
            readonly
        >
    </div>

    <div class="form-group col-lg-4 col-md-6">
        <label>
            Shipped By
            <span class="required">*</span>
        </label>

        <input
            type="text"
            name="shippedby"
            id="shippedby"
            class="form-control"
            value="<?php echo h($shipment['shipped_by']); ?>"
            placeholder="Enter person who shipped the sample"
            required
        >
    </div>

    <div class="form-group col-lg-4 col-md-6">
        <label>
            Shipment Date
            <span class="required">*</span>
        </label>

        <div class="input-group date" data-target-input="nearest">
            <input
                type="text"
                name="dos"
                id="dos"
                class="form-control datetimepicker-input"
                data-target="#dos"
                value="<?php echo h($shipment['shipment_date']); ?>"
                placeholder="YYYY-MM-DD"
                required
            >
            <div class="input-group-append" data-target="#dos" data-toggle="datetimepicker">
                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
        </div>
    </div>

</div>

<?php } else { ?>

<div class="row">

    <div class="form-group col-lg-4 col-md-6">
        <label>No. DBS Shipped</label>

        <input
            type="number"
            class="form-control"
            value="<?php echo h($shipment['total_dbs_shipped']); ?>"
            readonly
        >
    </div>

    <div class="form-group col-lg-4 col-md-6">
        <label>Shipped By</label>

        <input
            type="text"
            name="shippedby"
            id="shippedby"
            class="form-control"
            value="<?php echo h($shipment['shipped_by']); ?>"
            <?php echo $shipmentEditable ? '' : 'readonly'; ?>
        >
    </div>

    <div class="form-group col-lg-4 col-md-6">
        <label>Shipment Date</label>

        <div class="input-group date" data-target-input="nearest">
            <input
                type="text"
                name="dos"
                id="dos"
                class="form-control datetimepicker-input"
                data-target="#dos"
                value="<?php echo h($shipment['shipment_date']); ?>"
                <?php echo $shipmentEditable ? '' : 'readonly'; ?>
            >
            <div class="input-group-append" data-target="#dos" data-toggle="datetimepicker">
                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
        </div>
    </div>

</div>

<?php } ?>

</div>


<!-- ==========================================================
     STEP 2
========================================================== -->

<div class="workflow-pane" data-step="2">

    <div class="section-heading">
        <i class="fa fa-inbox"></i>
        RCDC Receipt
    </div>

    <?php if (!$hasShipment) { ?>

        <div class="alert alert-warning">
            Shipment must be created before recording receipt.
        </div>

    <?php } else { ?>

        <!-- ==========================================================
             Every sample of this shipment, not just the opened one.
        ========================================================== -->

        <div class="card card-outline card-primary mn-shipment-samples">

            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fa fa-vials"></i>
                    Samples in this shipment
                </h3>
                <span class="badge badge-primary ml-auto">
                    <?php echo count($shipmentSamples); ?>
                    of
                    <?php echo (int)$shipment['total_dbs_shipped']; ?>
                    shipped
                </span>
            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-sm table-bordered mb-0">

                        <thead class="thead-light">
                            <tr>
                                <th style="width:48px;">#</th>
                                <th>Patient</th>
                                <th style="width:70px;">Age</th>
                                <th style="width:90px;">Gender</th>
                                <th>BSE No.</th>
                                <th style="width:140px;">Collection Date</th>
                                <th style="width:120px;">PCR</th>
                                <th style="width:90px;">Action</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php

                            $openDbsId = (int)$sample['dbs_id'];

                            foreach ($shipmentSamples as $sampleIndex => $shipmentSample) {

                                $rowId = (int)$shipmentSample['dbs_id'];

                                $rowAge = (string)$shipmentSample['age'];

                                if ($rowAge !== '' && is_numeric($rowAge)) {
                                    $rowAge = (string)(0 + $rowAge);
                                }

                                $rowPcr = (string)$shipmentSample['pcr'];
                                ?>

                                <tr class="<?php echo $rowId === $openDbsId ? 'table-primary' : ''; ?>">

                                    <td><?php echo $sampleIndex + 1; ?></td>
                                    <td><?php echo h($shipmentSample['patient_name']); ?></td>
                                    <td><?php echo h($rowAge); ?></td>
                                    <td><?php echo h($shipmentSample['gender']); ?></td>
                                    <td><?php echo h($shipmentSample['bse_no']); ?></td>
                                    <td><?php echo h($shipmentSample['collection_date']); ?></td>

                                    <td>
                                        <?php if ($rowPcr !== '') { ?>
                                            <span class="badge badge-success"><?php echo h($rowPcr); ?></span>
                                        <?php } else { ?>
                                            <span class="badge badge-secondary">Pending</span>
                                        <?php } ?>
                                    </td>

                                    <td>
                                        <?php if ($rowId === $openDbsId) { ?>
                                            <?php /* A state, not an action: this is the row already open.
                                                     It used to read "Open", which looked like the link the
                                                     other rows carry and made the column read inconsistently. */ ?>
                                            <span class="badge badge-primary">Currently open</span>
                                        <?php } else { ?>
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="molecular.php?action=2&id=<?php echo $rowId; ?>&step=2">Open</a>
                                        <?php } ?>
                                    </td>

                                </tr>

                            <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <div class="row">

            <div class="form-group col-lg-4 col-md-6">

                <label>No. DBS Received <span class="required">*</span></label>

                <?php
                /* The ceiling is what this shipment actually carries: the number of
                   samples linked to it. total_dbs_shipped is the same number (the
                   server counts the samples when the shipment is created) and stays
                   the fallback if the rows could not be read. */
                $mnShippedMax = count($shipmentSamples) > 0
                    ? count($shipmentSamples)
                    : (int)$shipment['total_dbs_shipped'];
                if (empty($shipment['total_dbs_shipped']) && $mnShippedMax > 0) {
                    $shipment['total_dbs_shipped'] = $mnShippedMax;
                }
                ?>

                <input
                    type="number"
                    name="dbs_received"
                    id="numberdbs"
                    class="form-control"
                    value="<?= htmlspecialchars((string)($receipt['dbs_received'] ?? $shipment['total_dbs_shipped'] ?? '')) ?>"
                    min="0"
                    max="<?= htmlspecialchars((string)($shipment['total_dbs_shipped'] ?? '')) ?>"
                    <?php echo $receiptEditable ? '' : 'readonly'; ?>
                >
                <input type="hidden" name="numberdbs" id="numberdbs_legacy" value="<?= htmlspecialchars((string)($receipt['dbs_received'] ?? $shipment['total_dbs_shipped'] ?? '')) ?>">

                <small class="text-muted">
                    Maximum:
                    <?php echo $mnShippedMax; ?>
                </small>

            </div>

            <div class="form-group col-lg-4 col-md-6">

                <label>Received By <span class="required">*</span></label>

                <input
                    type="text"
                    name="receivedby"
                    id="receivedby"
                    class="form-control"
                    value="<?php echo h($shipment['received_by']); ?>"
                    <?php echo $receiptEditable ? '' : 'readonly'; ?>
                >

            </div>

            <div class="form-group col-lg-4 col-md-6">

                <label>Receipt Date <span class="required">*</span></label>

                <div class="input-group date" data-target-input="nearest">
                    <input
                        type="text"
                        name="dor"
                        id="dor"
                        class="form-control datetimepicker-input"
                        data-target="#dor"
                        value="<?php echo h($shipment['receive_date']); ?>"
                        placeholder="YYYY-MM-DD"
                        <?php echo $receiptEditable ? '' : 'readonly'; ?>
                    >
                    <div class="input-group-append" data-target="#dor" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                    </div>
                </div>

            </div>

        </div>

        <?php
        /* Every patient/DBS entry submitted under this shipment. A shipment with a
           single entry simply shows one row, exactly as before. */
        $mnEntries = [];
        if (!empty($shipment['shipment_id'])) {
            $mnEntryStmt = $conn->prepare("
                SELECT dbs_id, patient_name, bse_no, collection_date, sample_type, microscopy, rdt, pcr
                FROM dbs_results
                WHERE dbs_shipment_id = ?
                ORDER BY dbs_id
            ");
            if ($mnEntryStmt) {
                $mnShipId = (int)$shipment['shipment_id'];
                $mnEntryStmt->bind_param("i", $mnShipId);
                $mnEntryStmt->execute();
                $mnRes = $mnEntryStmt->get_result();
                while ($mnRow = $mnRes->fetch_assoc()) { $mnEntries[] = $mnRow; }
                $mnEntryStmt->close();
            }
        }
        ?>

        <div class="section-heading mt-3">
            <i class="fa fa-list"></i>
            Entries in this Shipment (<?php echo count($mnEntries); ?>)
        </div>

        <?php if (count($mnEntries) === 0) { ?>
            <div class="alert alert-light border">No patient entries are linked to this shipment.</div>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr class="text-center">
                        <th style="width:60px;">Entry</th>
                        <th>Patient Name</th>
                        <th>BSE No.</th>
                        <th>Collection Date</th>
                        <th>Case Type</th>
                        <th>Microscopy</th>
                        <th>RDT</th>
                        <th>PCR</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mnEntries as $mnIdx => $mnRow) { ?>
                    <tr<?php echo ((int)$mnRow['dbs_id'] === (int)$dbsid) ? ' class="table-primary"' : ''; ?>>
                        <td class="text-center"><?php echo $mnIdx + 1; ?></td>
                        <td><?php echo h($mnRow['patient_name']); ?></td>
                        <td><?php echo h($mnRow['bse_no']); ?></td>
                        <td class="text-center"><?php echo h($mnRow['collection_date']); ?></td>
                        <td class="text-center"><?php echo h($mnRow['sample_type']); ?></td>
                        <td class="text-center"><?php echo h($mnRow['microscopy']); ?></td>
                        <td class="text-center"><?php echo h($mnRow['rdt']); ?></td>
                        <td class="text-center"><?php echo $mnRow['pcr'] === null || $mnRow['pcr'] === '' ? '&mdash;' : h($mnRow['pcr']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>

        <?php if (!$isRCDC) { ?>

        <div class="alert alert-secondary">
            <i class="fa fa-lock"></i>
            Receipt information is read-only.
            Only RCDC/Admin can record or change the receipt.
        </div>

        <?php } ?>

    <?php } ?>

</div>

<!-- ==========================================================
     STEP 4
========================================================== -->

<div class="workflow-pane" data-step="3">

    <div class="section-heading">
        <i class="fa fa-microscope"></i>
        PCR Result
    </div>

    <?php if (!$hasReceipt) { ?>

        <div class="alert alert-warning">
            RCDC receipt must be completed before entering PCR results.
        </div>

    <?php } else { ?>

        <?php

        /*
         * PCR is recorded per sample, and a shipment may carry several, so every
         * sample of this shipment gets its own row. They are saved together by
         * save_pcr (pcrrow[<dbs_id>][...]), which re-checks on the server that
         * each posted id really belongs to this shipment.
         */
        $pcrSamples = count($shipmentSamples) > 0
            ? $shipmentSamples
            : [$sample];

        $pcrOptions = [
            'Negative',
            'Pf',
            'Pv',
            'F+V',
            'Po',
            'Pm',
            'Pk',
            'NA'
        ];

        $openDbsId = (int)$sample['dbs_id'];
        ?>

        <div class="card card-outline card-primary mn-shipment-samples">

            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fa fa-vials"></i>
                    PCR results for this shipment
                </h3>
                <span class="badge badge-primary ml-auto">
                    <?php echo count($pcrSamples); ?>
                    <?php echo count($pcrSamples) === 1 ? 'sample' : 'samples'; ?>
                </span>
            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-sm table-bordered mb-0">

                        <thead class="thead-light">
                            <tr>
                                <th style="width:44px;">#</th>
                                <th>Patient</th>
                                <th style="width:120px;">BSE No.</th>
                                <th style="width:180px;">Sample Reference Code</th>
                                <th style="width:150px;">PCR Result <span class="required">*</span></th>
                                <th style="width:170px;">PCR Date <span class="required">*</span></th>
                                <th>Remarks</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php

                            foreach ($pcrSamples as $pcrIndex => $pcrSample) {

                                $rowId = (int)$pcrSample['dbs_id'];

                                if ($rowId <= 0) {
                                    continue;
                                }

                                $rowDateId = 'pcrdate_' . $rowId;
                                $rowPcr    = (string)$pcrSample['pcr'];
                                ?>

                                <tr class="<?php echo $rowId === $openDbsId ? 'table-primary' : ''; ?>" data-pcr-row="<?php echo $rowId; ?>">

                                    <td><?php echo $pcrIndex + 1; ?></td>

                                    <td><?php echo h($pcrSample['patient_name']); ?></td>

                                    <td><?php echo h($pcrSample['bse_no']); ?></td>

                                    <td>
                                        <input
                                            type="text"
                                            name="pcrrow[<?php echo $rowId; ?>][ref_code]"
                                            class="form-control form-control-sm"
                                            value="<?php echo h($pcrSample['ref_code']); ?>"
                                            <?php echo $pcrEditable ? '' : 'readonly'; ?>
                                        >
                                    </td>

                                    <td>
                                        <select
                                            name="pcrrow[<?php echo $rowId; ?>][pcr]"
                                            class="form-control form-control-sm pcr-row-result"
                                            <?php echo $pcrEditable ? '' : 'disabled'; ?>
                                        >
                                            <option value="">Select</option>

                                            <?php
                                            foreach ($pcrOptions as $pcrOption) {

                                                echo '<option value="' . h($pcrOption) . '" ' .
                                                     ($rowPcr === $pcrOption ? 'selected' : '') .
                                                     '>' . h($pcrOption) . '</option>';
                                            }
                                            ?>

                                        </select>

                                        <?php if (!$pcrEditable) { ?>

                                        <input
                                            type="hidden"
                                            name="pcrrow[<?php echo $rowId; ?>][pcr]"
                                            value="<?php echo h($rowPcr); ?>"
                                        >

                                        <?php } ?>
                                    </td>

                                    <td>
                                        <div class="input-group input-group-sm date" data-target-input="nearest">

                                            <input
                                                type="text"
                                                name="pcrrow[<?php echo $rowId; ?>][pcr_date]"
                                                id="<?php echo h($rowDateId); ?>"
                                                class="form-control form-control-sm datetimepicker-input pcr-row-date"
                                                data-target="#<?php echo h($rowDateId); ?>"
                                                value="<?php echo h($pcrSample['pcr_date']); ?>"
                                                placeholder="YYYY-MM-DD"
                                                <?php echo $pcrEditable ? '' : 'readonly'; ?>
                                            >

                                            <div
                                                class="input-group-append"
                                                data-target="#<?php echo h($rowDateId); ?>"
                                                data-toggle="datetimepicker"
                                            >
                                                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                            </div>

                                        </div>
                                    </td>

                                    <td>
                                        <input
                                            type="text"
                                            name="pcrrow[<?php echo $rowId; ?>][remarks]"
                                            class="form-control form-control-sm"
                                            value="<?php echo h($pcrSample['pcr_remarks']); ?>"
                                            <?php echo $pcrEditable ? '' : 'readonly'; ?>
                                        >
                                    </td>

                                </tr>

                            <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <?php if (!$isRCDC) { ?>

        <div class="alert alert-secondary">
            <i class="fa fa-lock"></i>
            PCR result is read-only.
            Only RCDC/Admin can enter or edit PCR results.
        </div>

        <?php } ?>

    <?php } ?>

</div>

<!-- ==========================================================
     STEP 5
========================================================== -->

<div class="workflow-pane" data-step="4">

    <div class="section-heading">
        <i class="fa fa-clipboard-check"></i>
        Review & Complete
    </div>

    <!-- SAMPLE SUMMARY -->

    <?php

    /* One shipment can carry several patients, so the review lists the whole
       batch; the record the page was opened with is highlighted. */
    $reviewSamples = count($shipmentSamples) > 0
        ? $shipmentSamples
        : [$sample];

    $openDbsId = (int)$sample['dbs_id'];
    ?>

    <div class="review-card">

        <h5>
            <i class="fa fa-vial"></i>
            Sample Information
            <span class="badge badge-primary">
                <?php echo count($reviewSamples); ?>
                <?php echo count($reviewSamples) === 1 ? 'sample' : 'samples'; ?>
            </span>
        </h5>

        <div class="table-responsive">

            <table class="table table-sm table-bordered mb-0">

                <thead class="thead-light">
                    <tr>
                        <th style="width:44px;">#</th>
                        <th style="width:90px;">Sample ID</th>
                        <th>Patient</th>
                        <th style="width:60px;">Age</th>
                        <th style="width:90px;">Gender</th>
                        <th>BSE No.</th>
                        <th style="width:130px;">Collection Date</th>
                        <th style="width:110px;">Microscopy</th>
                        <th style="width:90px;">RDT</th>
                        <th style="width:110px;">PCR</th>
                        <th style="width:130px;">PCR Date</th>
                    </tr>
                </thead>

                <tbody>

                    <?php

                    foreach ($reviewSamples as $reviewIndex => $reviewSample) {

                        $rowId = (int)$reviewSample['dbs_id'];

                        $rowAge = (string)$reviewSample['age'];

                        if ($rowAge !== '' && is_numeric($rowAge)) {
                            $rowAge = (string)(0 + $rowAge);
                        }

                        $rowPcr = (string)$reviewSample['pcr'];
                        ?>

                        <tr
                            class="<?php echo $rowId === $openDbsId ? 'table-primary' : ''; ?>"
                            data-review-row="<?php echo $rowId; ?>"
                        >

                            <td><?php echo $reviewIndex + 1; ?></td>
                            <td><?php echo h($reviewSample['dbs_id']); ?></td>
                            <td><?php echo h($reviewSample['patient_name']); ?></td>
                            <td><?php echo h($rowAge); ?></td>
                            <td><?php echo h($reviewSample['gender']); ?></td>
                            <td><?php echo h($reviewSample['bse_no']); ?></td>
                            <td><?php echo h($reviewSample['collection_date']); ?></td>

                            <td>
                                <?php
                                echo h(
                                    isset($reviewSample['microscopy'])
                                        ? $reviewSample['microscopy']
                                        : ''
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo h(
                                    isset($reviewSample['rdt'])
                                        ? $reviewSample['rdt']
                                        : ''
                                );
                                ?>
                            </td>

                            <td class="review-pcr">
                                <?php if ($rowPcr !== '') { ?>
                                    <span class="badge badge-success"><?php echo h($rowPcr); ?></span>
                                <?php } else { ?>
                                    <span class="badge badge-secondary">Pending</span>
                                <?php } ?>
                            </td>

                            <td class="review-pcr-date"><?php echo h($reviewSample['pcr_date']); ?></td>

                        </tr>

                    <?php } ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- SHIPMENT SUMMARY -->

    <div class="review-card">

        <h5>
            <i class="fa fa-truck"></i>
            Shipment
        </h5>

        <div class="row">

            <div class="col-md-3">
                <strong>Shipment ID</strong>
                <div><?php echo h($shipment['shipment_id']); ?></div>
            </div>

            <div class="col-md-3">
                <strong>Total DBS Shipped</strong>
                <div><?php echo h($shipment['total_dbs_shipped']); ?></div>
            </div>

            <div class="col-md-3">
                <strong>Shipped By</strong>
                <div><?php echo h($shipment['shipped_by']); ?></div>
            </div>

            <div class="col-md-3">
                <strong>Shipment Date</strong>
                <div><?php echo h($shipment['shipment_date']); ?></div>
            </div>

        </div>

    </div>

    <!-- RECEIPT SUMMARY -->

    <div class="review-card">

        <h5>
            <i class="fa fa-inbox"></i>
            RCDC Receipt
        </h5>

        <div class="row">

            <div class="col-md-4">
                <strong>DBS Received</strong>
                <div><?php echo h($shipment['total_dbs_received']); ?></div>
            </div>

            <div class="col-md-4">
                <strong>Received By</strong>
                <div><?php echo h($shipment['received_by']); ?></div>
            </div>

            <div class="col-md-4">
                <strong>Receipt Date</strong>
                <div><?php echo h($shipment['receive_date']); ?></div>
            </div>

        </div>

    </div>

    <!-- PCR SUMMARY -->

    <div class="review-card">

        <h5>
            <i class="fa fa-microscope"></i>
            PCR Result
            <span class="badge badge-primary">
                <?php echo count($reviewSamples); ?>
                <?php echo count($reviewSamples) === 1 ? 'sample' : 'samples'; ?>
            </span>
        </h5>

        <div class="table-responsive">

            <table class="table table-sm table-bordered mb-0" id="pcrReviewTable">

                <thead class="thead-light">
                    <tr>
                        <?php if (count($reviewSamples) > 1) { ?>
                            <th style="width:44px;">#</th>
                            <th>Patient</th>
                            <th style="width:120px;">BSE No.</th>
                        <?php } ?>
                        <th style="<?php echo count($reviewSamples) === 1 ? 'width:25%;' : 'width:200px;'; ?>">Reference Code</th>
                        <th style="<?php echo count($reviewSamples) === 1 ? 'width:25%;' : 'width:150px;'; ?>">PCR Result</th>
                        <th style="<?php echo count($reviewSamples) === 1 ? 'width:25%;' : 'width:170px;'; ?>">PCR Date</th>
                        <th style="<?php echo count($reviewSamples) === 1 ? 'width:25%;' : ''; ?>">Remarks</th>
                    </tr>
                </thead>

                <tbody id="pcrReviewTableBody">

                    <?php
                    foreach ($reviewSamples as $pcrRevIndex => $pcrRevSample) {
                        $pcrRevId   = (int)$pcrRevSample['dbs_id'];
                        $revRefCode = (string)($pcrRevSample['ref_code'] ?? '');
                        $revPcr     = (string)($pcrRevSample['pcr'] ?? '');
                        $revPcrDate = (string)($pcrRevSample['pcr_date'] ?? '');
                        $revRemarks = (string)($pcrRevSample['pcr_remarks'] ?? '');
                        ?>
                        <tr data-pcr-review-row="<?php echo $pcrRevId; ?>" class="<?php echo $pcrRevId === $openDbsId ? 'table-primary' : ''; ?>">
                            <?php if (count($reviewSamples) > 1) { ?>
                                <td><?php echo $pcrRevIndex + 1; ?></td>
                                <td><?php echo h($pcrRevSample['patient_name'] ?? ''); ?></td>
                                <td><?php echo h($pcrRevSample['bse_no'] ?? ''); ?></td>
                            <?php } ?>
                            <td class="pcr-review-ref-code"><?php echo h($revRefCode); ?></td>
                            <td class="pcr-review-pcr">
                                <?php if ($revPcr !== '') { ?>
                                    <span class="badge badge-success"><?php echo h($revPcr); ?></span>
                                <?php } else { ?>
                                    <span class="badge badge-secondary">Pending</span>
                                <?php } ?>
                            </td>
                            <td class="pcr-review-date"><?php echo h($revPcrDate); ?></td>
                            <td class="pcr-review-remarks"><?php echo h($revRemarks); ?></td>
                        </tr>
                    <?php } ?>

                </tbody>

            </table>

        </div>

    </div>

    <?php
    $isWorkflowComplete = false;
    $samplesToCheck = count($shipmentSamples) > 0 ? $shipmentSamples : (!empty($sample['dbs_id']) ? [$sample] : []);

    if ($hasSample && $hasShipment && $hasReceipt && count($samplesToCheck) > 0) {
        $allPcrComplete = true;
        foreach ($samplesToCheck as $s) {
            $sPcr  = trim((string)($s['pcr'] ?? ''));
            $sDate = trim((string)($s['pcr_date'] ?? ''));
            if ($sPcr === '' || $sDate === '') {
                $allPcrComplete = false;
                break;
            }
        }
        $isWorkflowComplete = $allPcrComplete;
    }
    ?>

    <div class="alert alert-warning <?php echo $isWorkflowComplete ? 'd-none' : ''; ?>" id="workflowIncompleteAlert">

        <i class="fa fa-exclamation-triangle"></i>

        The workflow is not yet complete.

        Please complete all applicable steps before final completion.

    </div>

</div>

<!-- ==========================================================
     ACTION BAR
========================================================== -->

<div class="workflow-actions mt-4">

    <div>

        <button
            type="button"
            class="btn btn-secondary"
            id="btnPrevious"
        >
            <i class="fa fa-arrow-left"></i>
            Previous
        </button>

        <a
            href="molecular-list.php"
            class="btn btn-outline-secondary"
        >
            Cancel
        </a>

        <?php
        /* One Delete, whose meaning follows the step on screen - the same
           shared control every other module's stepper uses, so the wording,
           the confirmation and the draft-clearing behaviour match.

           Steps 1-3 name the scope that clears that step. Step 4 is a
           read-only summary with no fields of its own, so it is declared
           'record' and removes the whole sample, exactly as the RDT wizard's
           Review & Print step does. An empty url on any step means the
           signed-in user may not perform that delete, and the control says so
           instead of posting to be refused. */
        render_wizard_step_delete(array(
            'module'    => 'molecular',
            'record_id' => $sampleId,
            'can_write' => $hasSample,
            'steps'     => array(
                1 => array('name' => 'Patient & Shipment Information', 'rows' => 'record',
                           'saved'   => $hasSample,
                           'action'  => 'record',
                           'url'     => $deleteSampleUrl,
                           'confirm' => 'Are you sure you want to delete this Molecular Testing record?',
                           'denied'  => $sampleDenied),
                2 => array('name' => 'RCDC Receipt', 'rows' => 'record',
                           'saved'   => $hasReceipt,
                           'action'  => 'record',
                           'url'     => $deleteReceiptUrl,
                           'confirm' => 'Are you sure you want to delete this record?',
                           'denied'  => $receiptDenied),
                3 => array('name' => 'PCR Result', 'rows' => 'record',
                           'saved'   => $hasPCR,
                           'action'  => 'record',
                           'url'     => $deletePCRUrl,
                           'confirm' => 'Are you sure you want to delete this record?',
                           'denied'  => 'Deleting a PCR result is restricted to RCDC users.'),
                4 => array('name' => 'Review & Complete', 'rows' => 'none',
                           'saved'   => false,
                           'action'  => 'record',
                           'url'     => $deleteSampleUrl,
                           'confirm' => 'Are you sure you want to delete this Molecular Testing record?',
                           'denied'  => $sampleDenied),
            ),
        ));
        ?>

    </div>

    <div>

        <button
            type="button"
            class="btn btn-primary"
            id="btnNext"
        >
            Save & Continue
            <i class="fa fa-arrow-right"></i>
        </button>

        <button
    type="button"
    class="btn btn-success d-none"
    id="btnComplete"
    <?php echo !$isWorkflowComplete ? 'disabled' : ''; ?>
>
    <i class="fa fa-check"></i>
    Submit
</button>

    </div>

</div>

</form>

</div>
</div>

</div>

</section>

</div>

<style>

/* ==========================================================
   UNIFIED MOLECULAR WORKFLOW
========================================================== */

.workflow-stepper {
    display:flex;
    align-items:flex-start;
    width:100%;
    margin:15px 0 10px;
}

.workflow-step {
    flex:1;
    text-align:center;
    position:relative;
    cursor:pointer;
    min-width:110px;
}

.step-circle {
    width:46px;
    height:46px;
    margin:0 auto 8px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#e9ecef;
    color:#6c757d;
    font-weight:700;
    border:3px solid #fff;
    box-shadow:0 0 0 1px #dee2e6;
    transition:.2s;
}

.step-title {
    font-size:13px;
    font-weight:600;
    color:#6c757d;
}

.step-status {
    font-size:11px;
    margin-top:3px;
    color:#999;
}

.workflow-line {
    height:4px;
    flex:1;
    background:#e9ecef;
    margin-top:21px;
}

.workflow-step.active .step-circle,
.workflow-step.completed .step-circle {
    background:#007bff;
    color:#fff;
    box-shadow:none;
}

.workflow-step.active .step-title,
.workflow-step.completed .step-title {
    color:#212529;
}

.workflow-step.completed .step-status {
    color:#007bff;
}

.workflow-step.active .step-status {
    color:#007bff;
    font-weight:600;
}

.workflow-step.locked {
    opacity:.45;
    cursor:not-allowed;
}

.workflow-step.completed + .workflow-line {
    background:#007bff;
}

.workflow-progress {
    height:4px;
    margin-bottom:25px;
}

.workflow-pane {
    display:none;
}

.workflow-pane.active {
    display:block;
}

.section-heading {
    font-size:17px;
    font-weight:600;
    color:#007bff;
    margin-bottom:18px;
    padding-bottom:8px;
    border-bottom:1px solid #e9ecef;
}

.section-heading i {
    margin-right:7px;
}

.required {
    color:#dc3545;
}

.form-control {
    min-height:42px;
}

.review-card {
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:18px;
    margin-bottom:15px;
    background:#fff;
}

.review-card h5 {
    color:#007bff;
    margin-bottom:15px;
}

.workflow-actions {
    display:flex;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
}

@media(max-width:768px) {

    .workflow-stepper {
        overflow-x:auto;
    }

    .workflow-step {
        min-width:100px;
    }

    .step-title {
        font-size:11px;
    }

    .step-circle {
        width:38px;
        height:38px;
    }

    .workflow-line {
        margin-top:17px;
    }
}

</style>

<script>

$(function () {

    $('.select2').select2({
        theme:'bootstrap4',
        width:'100%'
    });

    /* .pcr-row-date = the per-sample PCR date on Step 3; each row carries its own
       id + data-target, so a picker only ever writes into its own row. */
    $('#doc, #dos, #dor, #pcr_date, .pcr-row-date').datetimepicker({
    format: 'YYYY-MM-DD',
    useCurrent: false,
    icons: {
        time: 'fa fa-clock-o',
        date: 'fa fa-calendar',
        up: 'fa fa-chevron-up',
        down: 'fa fa-chevron-down',
        previous: 'fa fa-chevron-left',
        next: 'fa fa-chevron-right',
        today: 'fa fa-screenshot',
        clear: 'fa fa-trash',
        close: 'fa fa-times'
    }
    });

    var currentStep = <?php echo (int)$currentStep; ?>;

    var isRCDC = <?php echo $isRCDC ? 'true' : 'false'; ?>;

    var hasSample = <?php echo $hasSample ? 'true' : 'false'; ?>;

    var hasShipment = <?php echo $hasShipment ? 'true' : 'false'; ?>;

    var hasReceipt = <?php echo $hasReceipt ? 'true' : 'false'; ?>;

    var hasPCR = <?php echo $hasPCR ? 'true' : 'false'; ?>;

    var shipmentEditable = <?php echo $shipmentEditable ? 'true' : 'false'; ?>;

    var workflowCompleted = false;

    /* ---- Step 1: additional patient entries ----------------------------
       Clones the server-rendered template, keeps the "Entry N" captions in
       order, and mirrors the count into the read-only No. DBS Shipped field
       (the server recomputes that from the real sample count on save). */
    (function () {

        function mnRenumberEntries() {

            var $rows = $('#mnExtraEntries').find('.mn-entry');

            $rows.each(function (i) {
                $(this).find('.mn-entry-no').text('Entry ' + (i + 2));
            });

            // Entry 1 is the patient above, so the shipment carries rows + 1.
            $('#totaldbs').val($rows.length + 1);
        }

        $(function () {

            var tpl = document.getElementById('mnEntryTpl');

            if (!tpl) { return; }          // editing an existing record

            $(document).on('click', '#mnAddEntry', function () {
                $('#mnExtraEntries').append(tpl.content.cloneNode(true));
                mnRenumberEntries();
            });

            $(document).on('click', '.mn-entry-del', function () {
                $(this).closest('.mn-entry').remove();
                mnRenumberEntries();
            });

            mnRenumberEntries();
        });
    })();

    var availableSamplesLoaded = false;

    /*
    |--------------------------------------------------------------------------
    | Step availability
    |--------------------------------------------------------------------------
    */

    function isStepEnabled(step) {

    if (step === 1) {
        return true;
    }

    if (step === 2) {
        return hasShipment;
    }

    if (step === 3) {
        return hasReceipt;
    }

    if (step === 4) {
        return hasPCR;
    }

    return false;
}

    /*
    |--------------------------------------------------------------------------
    | Show step
    |--------------------------------------------------------------------------
    */

    function checkPcrCompletion() {
        var $pcrRows = $('#molecularWorkflow [data-pcr-row]');
        if ($pcrRows.length > 0) {
            var allComplete = true;
            $pcrRows.each(function () {
                var $row = $(this);
                var res = $.trim($row.find('.pcr-row-result').val() || '');
                var dt  = $.trim($row.find('.pcr-row-date').val() || '');
                if (!res || !dt) {
                    allComplete = false;
                    return false;
                }
            });
            return allComplete;
        }

        // Fallback: check PCR review table rows
        var $revRows = $('[data-pcr-review-row]');
        if ($revRows.length > 0) {
            var allRevComplete = true;
            $revRows.each(function () {
                var $r = $(this);
                var res = $.trim($r.find('.pcr-review-pcr').text() || '');
                var dt  = $.trim($r.find('.pcr-review-date').text() || '');
                if (!res || res === 'Pending' || res === 'Select' || !dt) {
                    allRevComplete = false;
                    return false;
                }
            });
            return allRevComplete;
        }

        return false;
    }

    function updateWorkflowCompletionGate() {
        var isComplete = hasSample && hasShipment && hasReceipt && checkPcrCompletion();
        if (isComplete) {
            $('#workflowIncompleteAlert').addClass('d-none');
            $('#btnComplete').prop('disabled', false).removeClass('disabled');
        } else {
            $('#workflowIncompleteAlert').removeClass('d-none');
            $('#btnComplete').prop('disabled', true).addClass('disabled');
        }
        return isComplete;
    }

    /* The review table is rendered server-side, so mirror PCR values entered in
       this visit into it rather than showing the page-load state. */
    function syncReviewFromPcrRows() {

        $('#molecularWorkflow [data-pcr-row]').each(function () {

            var $row = $(this);
            var rowId = String($row.data('pcr-row') || '');

            if (!rowId) {
                return;
            }

            var refCode = $.trim($row.find('input[name*="[ref_code]"]').val() || '');
            var result  = $.trim($row.find('.pcr-row-result').val() || '');
            var date    = $.trim($row.find('.pcr-row-date').val() || '');
            var remarks = $.trim($row.find('input[name*="[remarks]"]').val() || '');

            // 1. Sync to Sample Information review table
            var $review = $('[data-review-row="' + rowId + '"]');
            if ($review.length) {
                $review.find('.review-pcr').html(
                    result
                        ? $('<span>').addClass('badge badge-success').text(result)
                        : $('<span>').addClass('badge badge-secondary').text('Pending')
                );
                $review.find('.review-pcr-date').text(date);
            }

            // 2. Sync to PCR Result review table
            var $pcrReview = $('[data-pcr-review-row="' + rowId + '"]');
            if ($pcrReview.length) {
                $pcrReview.find('.pcr-review-ref-code').text(refCode);
                $pcrReview.find('.pcr-review-pcr').html(
                    result
                        ? $('<span>').addClass('badge badge-success').text(result)
                        : $('<span>').addClass('badge badge-secondary').text('Pending')
                );
                $pcrReview.find('.pcr-review-date').text(date);
                $pcrReview.find('.pcr-review-remarks').text(remarks);
            }
        });

        updateWorkflowCompletionGate();
    }

    function showStep(step) {

    if (!isStepEnabled(step)) {

        Swal.fire({
            icon: 'info',
            title: 'Step not available',
            text: 'Please complete the previous step first.',
            confirmButtonText: 'OK'
        });

        return;
    }

    if (step === 4) {
        syncReviewFromPcrRows();
    }

    currentStep = step;

    $('#current_step').val(step);


    $('.workflow-pane')
        .removeClass('active');


    $('.workflow-pane[data-step="' + step + '"]')
        .addClass('active');


    /*
    |--------------------------------------------------------------------------
    | Step status — shared helper (dist/js/mneqas-stepper-state.js)
    |--------------------------------------------------------------------------
    | Status comes from what the RECORD holds, not from the step being viewed:
    | completed record => every step Completed, current one also
    | Active; draft => saved stages Completed, current In Progress / Pending Review,
    | the rest Pending (Locked while their prerequisite is missing).
    */
    var molDone = [];
    if (hasSample && hasShipment) { molDone.push(1); }
    if (hasReceipt)               { molDone.push(2); }
    if (hasPCR)                   { molDone.push(3); }
    if (workflowCompleted)        { molDone.push(4); }

    var molReachable = [1, 2, 3, 4].filter(isStepEnabled);
    var molState = window.mnStepperState
        ? mnStepperState({ steps: [1, 2, 3, 4], current: step, completed: workflowCompleted, done: molDone, reachable: molReachable })
        : {};

    $('.workflow-step').each(function () {
        var s  = parseInt($(this).data('step'), 10);
        var st = molState[s] || '';
        var isDone = st.indexOf('done') !== -1;
        var isCur  = st.indexOf('active') !== -1;

        $(this).removeClass('active completed locked')
               .toggleClass('completed', isDone)
               .toggleClass('active', isCur)
               .toggleClass('locked', st === 'locked');

        var statusText = isDone
            ? 'Completed'
            : (isCur
                ? (s === 4 ? 'Pending Review' : 'In Progress')
                : (st === 'locked' ? 'Locked' : (s === 4 ? 'Pending Review' : 'Pending')));
        $('#status' + s).text(statusText);
    });

    if (step === 4) {
        updateWorkflowCompletionGate();
    }

    /*
    |--------------------------------------------------------------------------
    | Four steps = three intervals
    |--------------------------------------------------------------------------
    */

    var percentage =
        ((step - 1) / 3) * 100;


    $('#workflowProgress')
        .css(
            'width',
            percentage + '%'
        );


    $('#btnPrevious')
        .prop(
            'disabled',
            step <= 1
        );


    if (step < 4) {

        $('#btnNext')
            .removeClass('d-none');

        $('#btnComplete')
            .addClass('d-none');

    } else {

        $('#btnNext')
            .addClass('d-none');

        $('#btnComplete')
            .removeClass('d-none');
    }
}

    /*
    |--------------------------------------------------------------------------
    | Load available unshipped samples
    |--------------------------------------------------------------------------
    */

    function loadAvailableSamples() {

        availableSamplesLoaded = true;

        var facility = $('input[name="facility"]').val();

        if ($('#facility').length) {
            facility = $('#facility').val();
        }

        if (!facility) {

            $('#availableSamplesContainer').html(
                '<div class="alert alert-warning">' +
                'Please select a health facility first.' +
                '</div>'
            );

            return;
        }

        $('#availableSamplesContainer').html(
            '<div class="text-center p-4">' +
            '<i class="fa fa-spinner fa-spin"></i> Loading...' +
            '</div>'
        );

        $.get(
            'load-shipment-sample.php',
            {
                facility:facility
            },
            function (html) {

                $('#availableSamplesContainer')
                    .html(html);

                updateShipmentCount();
            }
        ).fail(function () {

            availableSamplesLoaded = false;

            $('#availableSamplesContainer').html(
                '<div class="alert alert-danger">' +
                'Unable to load available samples.' +
                '</div>'
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Count selected samples
    |--------------------------------------------------------------------------
    */

    function updateShipmentCount() {

        var count =
            $('#availableSamplesContainer input[name="sample_id[]"]:checked')
            .length;

        $('#totaldbs').val(count);
    }

    $(document).on(
        'change',
        '#availableSamplesContainer input[name="sample_id[]"]',
        updateShipmentCount
    );

    /*
    |--------------------------------------------------------------------------
    | RCDC facility cascade
    |--------------------------------------------------------------------------
    */
    $(document).on('change', '#dzongkhag', function () {
        var dzongkhag = $(this).val();
        var $facility = $('#facility');

        $facility.html('<option value="">Loading...</option>');

        if (!dzongkhag) {
            $facility.html('<option value="">Select Facility</option>');
            return;
        }

        $.ajax({
            url: '../load-facility-all.php',
            type: 'GET',
            data: { dzongkhag: dzongkhag },
            dataType: 'html',
            success: function (html) {
                // Robustly handle both:
                // 1. Full <select>…</select> (what load-facility*.php actually returns)
                // 2. Bare <option>…</option> list
                var $temp = $('<div>').append($.parseHTML(html, document, true));
                var $sourceSelect = $temp.find('select').first();
                var optionsHtml = '';

                if ($sourceSelect.length) {
                    // Response contains a complete <select>
                    $sourceSelect.find('option').each(function () {
                        var val = $(this).attr('value');
                        if (val !== undefined && val !== '') {
                            optionsHtml += this.outerHTML;
                        }
                    });
                } else {
                    // Response is already a list of <option> tags (or empty)
                    $temp.find('option').each(function () {
                        var val = $(this).attr('value');
                        if (val !== undefined && val !== '') {
                            optionsHtml += this.outerHTML;
                        }
                    });
                    // Last-resort fallback if the server returned raw option markup
                    if (!optionsHtml && $.trim(html)) {
                        optionsHtml = html;
                    }
                }

                $facility.html(
                    '<option value="">Select Facility</option>' + optionsHtml
                );
            },
            error: function () {
                $facility.html(
                    '<option value="">Unable to load facilities</option>'
                );
                Swal.fire({
                    icon: 'error',
                    title: 'Facility load failed',
                    text: 'Could not load health facilities for the selected Dzongkhag.'
                });
            }
        });

        availableSamplesLoaded = false;
    });

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /* Age: whole numbers only (step 1). Block decimal/exponent/sign keys and strip any
       non-digit characters that arrive by paste or autofill. Scoped to the Age input only. */
    $(document).on('keydown', 'input[name="age"]', function (e) {
        if (['.', ',', 'e', 'E', '+', '-'].indexOf(e.key) !== -1) { e.preventDefault(); }
    });
    $(document).on('input', 'input[name="age"]', function () {
        var v = String(this.value || '');
        if (v !== '' && !/^\d+$/.test(v)) { this.value = v.split(/[.,]/)[0].replace(/\D/g, ''); }   // 2.5 -> 2
    });

    function validateCurrentStep() {

        var pane =
            $('.workflow-pane[data-step="' + currentStep + '"]');

        var valid = true;

        pane.find('input,select,textarea').each(function () {

            var element = $(this);

            if (
                element.prop('required') &&
                !element.prop('disabled') &&
                !element.val()
            ) {

                element.addClass('is-invalid');

                valid = false;

            } else {

                element.removeClass('is-invalid');
            }
        });

        if (!valid) {

            Swal.fire({
                icon:'warning',
                title:'Required information missing',
                text:'Please complete the required fields in this step.',
                confirmButtonText:'OK'
            });

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Shipment validation
        |--------------------------------------------------------------------------
        */

        /*
|--------------------------------------------------------------------------
| STEP 1 - PATIENT + SHIPMENT VALIDATION
|--------------------------------------------------------------------------
|
| For a NEW record:
|   - There is no existing sample_id[].
|   - The backend will create the sample first.
|   - The newly generated dbs_id will automatically become
|     the shipment sample.
|
| For an EXISTING unshipped sample:
|   - The current dbsid is automatically used.
|
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| STEP 1 - PATIENT + SHIPMENT VALIDATION
|--------------------------------------------------------------------------
*/

if (currentStep === 1 && !hasShipment) {

    var shippedBy = $.trim($('#shippedby').val());
    var shipmentDate = $.trim($('#dos').val());

    if (shippedBy === '') {

        $('#shippedby').addClass('is-invalid').focus();

        Swal.fire({
            icon: 'warning',
            title: 'Shipped By required',
            text: 'Please enter Shipped By.',
            confirmButtonText: 'OK'
        });

        return false;
    }

    $('#shippedby').removeClass('is-invalid');

    if (shipmentDate === '') {

        $('#dos').addClass('is-invalid').focus();

        Swal.fire({
            icon: 'warning',
            title: 'Shipment date required',
            text: 'Please enter Shipment Date.',
            confirmButtonText: 'OK'
        });

        return false;
    }

    $('#dos').removeClass('is-invalid');

    /*
    |--------------------------------------------------------------------------
    | Validate shipment date format
    |--------------------------------------------------------------------------
    */

    var datePattern = /^\d{4}-\d{2}-\d{2}$/;

    if (!datePattern.test(shipmentDate)) {

        $('#dos').addClass('is-invalid').focus();

        Swal.fire({
            icon: 'warning',
            title: 'Invalid Shipment Date',
            text: 'Shipment Date must be in YYYY-MM-DD format.',
            confirmButtonText: 'OK'
        });

        return false;
    }

    $('#dos').removeClass('is-invalid');
}

        /*
        |--------------------------------------------------------------------------
        | Receipt validation
        |--------------------------------------------------------------------------
        */

        if (currentStep === 2 && isRCDC) {

            var received =
                parseInt($('#numberdbs').val(),10);

            var shipped =
                <?php echo (int)$shipment['total_dbs_shipped']; ?>;

            if (
                isNaN(received) ||
                received < 0 ||
                received > shipped
            ) {

                Swal.fire({
                    icon:'warning',
                    title:'Invalid received count',
                    text:'Received DBS must be between 0 and total DBS shipped.',
                    confirmButtonText:'OK'
                });

                return false;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | PCR validation
        |--------------------------------------------------------------------------
        */

        if (currentStep === 3 && isRCDC) {

    /* One row per sample of the shipment: every row needs a result and date */
    var pcrDatePattern = /^\d{4}-\d{2}-\d{2}$/;
    var missingResultRow = 0;
    var missingDateRow = 0;
    var badDateRow = 0;

    $('#molecularWorkflow [data-pcr-row]').each(function (index) {

        var $row = $(this);
        var $result = $row.find('.pcr-row-result');
        var $date = $row.find('.pcr-row-date');

        if (!$.trim($result.val() || '')) {

            $result.addClass('is-invalid');

            if (!missingResultRow) {
                missingResultRow = index + 1;
                $result.trigger('focus');
            }

        } else {

            $result.removeClass('is-invalid');
        }

        var rowDate = $.trim($date.val() || '');

        if (!rowDate) {

            $date.addClass('is-invalid');

            if (!missingDateRow) {
                missingDateRow = index + 1;
                if (!missingResultRow) {
                    $date.trigger('focus');
                }
            }

        } else if (!pcrDatePattern.test(rowDate)) {

            $date.addClass('is-invalid');

            if (!badDateRow) {
                badDateRow = index + 1;
            }

        } else {

            $date.removeClass('is-invalid');
        }
    });

    if (missingResultRow) {

        Swal.fire({
            icon: 'warning',
            title: 'PCR result required',
            text: 'Please select the PCR result for sample ' + missingResultRow + '.',
            confirmButtonText: 'OK'
        });

        return false;
    }

    if (missingDateRow) {

        Swal.fire({
            icon: 'warning',
            title: 'PCR Date required',
            text: 'Please enter the PCR Date for sample ' + missingDateRow + '.',
            confirmButtonText: 'OK'
        });

        return false;
    }

    if (badDateRow) {

        Swal.fire({
            icon: 'warning',
            title: 'Invalid PCR Date',
            text: 'PCR Date for sample ' + badDateRow +
                  ' must be in YYYY-MM-DD format.',
            confirmButtonText: 'OK'
        });

        return false;
    }
}

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Save current step
    |--------------------------------------------------------------------------
    */

    function saveStep(operation, nextStep) {

        if (!validateCurrentStep()) {
            return;
        }
        // Ensure date fields are clean YYYY-MM-DD before submit
        $('#doc, #dos, #dor, #pcr_date, .pcr-row-date').each(function () {
            var $el = $(this);
            var v = $.trim($el.val() || '');
            // Keep only the date part if a time somehow got appended
            if (v && v.indexOf(' ') > -1) {
                v = v.split(' ')[0];
            }
            $el.val(v);
        });

        $('#operation').val(operation);

        $('#current_step').val(currentStep);

        var form =
            document.getElementById('molecularWorkflow');

        var formData =
            new FormData(form);

        Swal.fire({
            title:'Saving...',
            text:'Please wait.',
            allowOutsideClick:false,
            didOpen:function () {
                Swal.showLoading();
            }
        });

        $.ajax({

            url:'iu-molecular.php',

            type:'POST',

            data:formData,

            processData:false,

            contentType:false,

            dataType:'json',

            success:function(response) {

                if (response.success) {

                    if (response.dbs_id) {

                        $('#dbsid').val(response.dbs_id);

                        hasSample = true;
                    }

                    if (response.shipment_id) {

                        $('#sid').val(response.shipment_id);

                        hasShipment = true;
                    }

                    if (response.receipt_saved) {

                        hasReceipt = true;
                    }

                    if (response.pcr_saved) {

                        hasPCR = true;
                    }

                    Swal.close();

                    if (response.redirect) {

                        window.location =
                            response.redirect;

                    } else {

                        showStep(nextStep);
                    }

                } else {

                    Swal.fire({
                        icon:'error',
                        title:'Unable to save',
                        text:response.message ||
                             'Submission failed.',
                        confirmButtonText:'OK'
                    });
                }
            },

            error:function(xhr) {

                var message =
                    'Server error occurred while saving.';

                try {

                    var response =
                        JSON.parse(xhr.responseText);

                    if (response.message) {
                        message = response.message;
                    }

                } catch(e) {}

                Swal.fire({
                    icon:'error',
                    title:'Save failed',
                    text:message,
                    confirmButtonText:'OK'
                });
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Next button
    |--------------------------------------------------------------------------
    */

        /*
    |--------------------------------------------------------------------------
    | Next button
    |--------------------------------------------------------------------------
    */
    
    $('#btnNext').on('click', function () {

    /*
    |--------------------------------------------------------------------------
    | STEP 1 - PATIENT + SHIPMENT
    |--------------------------------------------------------------------------
    */

    if (currentStep === 1) {

        if (!hasShipment) {

            // Creates the patient entries of this submission and their shipment.
            saveStep(
                'save_patient_shipment',
                2
            );

        } else if (shipmentEditable) {

            /* The shipment exists, so its sample membership is fixed and
               save_patient_shipment would refuse the already-shipped sample.
               Only the shipment header can still change here. */
            saveStep(
                'save_shipment',
                2
            );

        } else {

            // Fully locked (received): navigation only, nothing to save.
            showStep(2);
        }

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | STEP 2 - RCDC RECEIPT
    |--------------------------------------------------------------------------
    */

    if (currentStep === 2) {

        if (!isRCDC) {

            return;
        }

        if (!validateCurrentStep()) {
            return;
        }

        var dbsReceived = parseInt($('#numberdbs').val() || $('[name="dbs_received"]').val(), 10);
        var totalShipped = parseInt($('#numberdbs').attr('max'), 10);
        if (isNaN(totalShipped) || totalShipped <= 0) {
            totalShipped = <?php echo (int)($shipment['total_dbs_shipped'] ?: count($shipmentSamples)); ?>;
        }

        if (!isNaN(dbsReceived) && !isNaN(totalShipped) && dbsReceived < totalShipped) {
            var confirmMsg = 'You entered ' + dbsReceived + ' received DBS samples out of ' + totalShipped + ' shipped. Do you want to proceed?';
            if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
                Swal.fire({
                    title: 'Confirm Received DBS Count',
                    text: confirmMsg,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, proceed',
                    cancelButtonText: 'No, cancel'
                }).then(function (result) {
                    if (result && (result.isConfirmed || result.value)) {
                        saveStep(
                            'save_receipt',
                            3
                        );
                    }
                });
            } else {
                if (window.confirm(confirmMsg)) {
                    saveStep(
                        'save_receipt',
                        3
                    );
                }
            }
            return;
        }

        saveStep(
            'save_receipt',
            3
        );

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | STEP 3 - PCR
    |--------------------------------------------------------------------------
    */

    if (currentStep === 3) {

        if (!isRCDC) {

            return;
        }

        saveStep(
            'save_pcr',
            4
        );

        return;
    }

});

    $(document).on('input change', '#numberdbs', function () {
        $('#numberdbs_legacy').val($(this).val());
    });

    /*
    |--------------------------------------------------------------------------
    | Complete workflow
    |--------------------------------------------------------------------------
    */

    $('#btnComplete').on('click', function (e) {

    e.preventDefault();   // ★ important – stops normal form submit

    if (!hasSample) {
        showStep(1);
        return;
    }

    if (!updateWorkflowCompletionGate()) {
        Swal.fire({
            icon: 'warning',
            title: 'Workflow Incomplete',
            text: 'Each received DBS sample must have a valid PCR Result and PCR Date recorded before completing the workflow.',
            confirmButtonText: 'OK'
        });
        return;
    }

    $('#operation').val('complete');

    var form = document.getElementById('molecularWorkflow');
    var formData = new FormData(form);

    Swal.fire({
        title: 'Completing workflow...',
        text: 'Please wait.',
        allowOutsideClick: false,
        didOpen: function () {
            Swal.showLoading();
        }
    });

    $.ajax({
        url: 'iu-molecular.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',

        success: function (response) {

            if (response.success) {

                workflowCompleted = true;
                $('.workflow-step[data-step="4"]')
                    .removeClass('active')
                    .addClass('completed');
                $('#status4').text('Completed');

                Swal.fire({
                    icon: 'success',
                    title: 'Workflow Complete',
                    text: 'Molecular testing workflow completed successfully.',
                    confirmButtonText: 'OK'
                }).then(function () {
                    // Prefer backend redirect, fallback to listing
                    window.location = response.redirect || 'molecular-list.php';
                });

            } else {

                Swal.fire({
                    icon: 'warning',
                    title: 'Cannot complete workflow',
                    text: response.message || 'Please complete all required steps.',
                    confirmButtonText: 'OK'
                });
            }
        },

        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Unable to complete the workflow.',
                confirmButtonText: 'OK'
            });
        }
    });
});

    /*
    |--------------------------------------------------------------------------
    | Previous
    |--------------------------------------------------------------------------
    */

    $('#btnPrevious').on('click', function () {

        if (currentStep > 1) {

            showStep(currentStep - 1);
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Step click
    |--------------------------------------------------------------------------
    */

    $('.workflow-step').on('click', function () {

        var step =
            parseInt($(this).data('step'),10);

        if (isStepEnabled(step)) {

            showStep(step);
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Start
    |--------------------------------------------------------------------------
    */

    showStep(currentStep);

});

</script>

<?php require_once("../includes/footer.php"); ?>

</body>
</html>