<?php

/**
 * iu-molecular-shipment.php
 *
 * Compatibility endpoint.
 *
 * New Molecular Workflow uses:
 *
 *     iu-molecular.php
 *
 * This endpoint is retained so old URLs do not silently fail.
 */

require_once("../config/session.php");

$roleID = isset($_SESSION["roleID"])
    ? (int)$_SESSION["roleID"]
    : 0;

if ($roleID <= 0) {

    header(
        "Location: ../login.php"
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Old form submissions
|--------------------------------------------------------------------------
|
| We deliberately do NOT duplicate shipment business logic here.
|
| There must be one authoritative shipment implementation:
|
|     iu-molecular.php
|
| Duplicating shipment logic in two files creates a risk that one
| endpoint gets security fixes while the other remains vulnerable.
|
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $legacySubmit =
        isset($_POST['submit'])
            ? trim((string)$_POST['submit'])
            : '';


    /*
    |--------------------------------------------------------------------------
    | OLD SHIPMENT CREATION
    |--------------------------------------------------------------------------
    */

    if (
        $legacySubmit === 'lab'
    ) {

        $_POST['operation'] =
            'save_shipment';

    }


    /*
    |--------------------------------------------------------------------------
    | OLD RCDC RECEIPT
    |--------------------------------------------------------------------------
    */

    elseif (
        $legacySubmit === 'shipmentReceipt'
    ) {

        $_POST['operation'] =
            'save_receipt';

    }


    /*
    |--------------------------------------------------------------------------
    | Unknown legacy operation
    |--------------------------------------------------------------------------
    */

    else {

        http_response_code(400);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode([
            'success' => false,
            'message' =>
                'Unknown legacy shipment operation.'
        ]);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Authoritative backend
    |--------------------------------------------------------------------------
    */

    require_once(
        "iu-molecular.php"
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| GET request
|--------------------------------------------------------------------------
*/

$shipmentId = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if ($shipmentId > 0) {

    header(
        "Location: molecular-shipment.php?action=2&id=" .
        $shipmentId
    );

} else {

    header(
        "Location: molecular.php?action=1&step=1"
    );
}

exit;