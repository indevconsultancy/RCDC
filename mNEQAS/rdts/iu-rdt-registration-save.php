<?php
    /**
     * RDT Lot Test Registration - transactional save (wizard).
     *
     * Stage-wise transactional handlers (each is all-or-nothing):
     *   submit=facility       Request + Products + Shipment (Phase 1 - facility stage)
     *   submit=receipt        RCDC receipt: received counts + lot correction + receipt info
     *   submit=assessment     Per-product accessory assessment checklist
     *   submit=performance    Per-product performance report (+ TN/FP rates + Pass/Fail verdict)
     *   submit=combined_rcdc  Receipt + all assessments + all performance reports in ONE
     *                         transaction (kept for compatibility; the wizard posts stage-wise)
     *
     * Fixes vs the legacy handler: AUTO_INCREMENT/insert_id, prepared statements,
     * transactions, safe stub performance rows, and the undefined $manufacturer_id
     * bug in the performance INSERT (now uses the real product id).
     */
    require_once("../config/session.php");
    // RBAC backend enforcement: only Create/Update roles (Administrator, RCDC Lab,
    // Health Worker) may write here; read-only "Others" are blocked for direct URL,
    // form submit, and AJAX alike. (Delete is gated separately in delete.php.)
    require_once("../config/auth.php"); mn_require_write();
    require_once("../config/db-config.php");
    require_once("../includes/css-jquery.php");

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // throw -> rollback

    function nz($v){ $v = is_string($v) ? trim($v) : $v; return ($v === '' || $v === null) ? null : $v; }

    require_once("../notifications/mn-notify.php");   // workflow e-mail notifications
    $submit = isset($_POST['submit']) ? $_POST['submit'] : '';
    $check  = false;
    $lnk    = "rdt-list.php";

    /* ---- Stage + record authorization (before any SQL) ----------------------
       Facility stage (request / products / shipment): Health Worker for their own
       facility, or RCDC. Receipt / accessory assessment / performance verdict:
       RCDC (1, 2) only. The owning facility is read from rdt_test_request, never
       the POST; product / performance ids must belong to the request. */
    if (!in_array($submit, array('facility', 'combined_rcdc', 'receipt', 'assessment', 'performance'), true)) {
        mn_deny('Unknown submission stage.');
    }
    if ($submit !== 'facility' && !mn_is_rcdc()) {
        mn_deny('RDT receipt, assessment and performance verdict are restricted to RCDC users.');
    }
    $authTrid = isset($_POST['trid']) ? trim((string)$_POST['trid']) : '';
    if ($authTrid !== '' && !ctype_digit($authTrid)) { mn_deny('Invalid record reference.'); }
    if ($authTrid === '' && $submit !== 'facility') { mn_deny('Missing record reference.'); }
    $authTridInt = (int)$authTrid;
    if ($authTrid !== '') { mn_require_record($conn, 'rdt_request', $authTridInt); }
    $authIds = function ($key) { return (isset($_POST[$key]) && is_array($_POST[$key])) ? $_POST[$key] : array(); };
    if ($submit === 'facility') {
        if (!mn_is_rcdc()) {
            $_POST['facility'] = (string)mn_facility();   // own facility only
            if ($authTrid !== '') {
                $rc = $conn->prepare("SELECT date_receive FROM rdt_test_request WHERE rdt_request_id = ?");
                $rc->bind_param('i', $authTridInt);
                $rc->execute();
                $rcRow = $rc->get_result()->fetch_row();
                $rc->close();
                if ($rcRow && $rcRow[0] !== null) { mn_deny('The request is locked once RCDC has received the shipment.', $authTridInt); }
            }
        }
        foreach (array_merge($authIds('product_pid'), $authIds('deleted_pid')) as $authPid) {
            $authPid = trim((string)$authPid);
            if ($authPid === '') { continue; }
            if ($authTrid === '') { mn_deny('Invalid product reference.'); }
            mn_require_child($conn, 'rdt_product', (int)$authPid, $authTridInt);
        }
    } else {
        foreach ($authIds('pid') as $authPid) {
            if (trim((string)$authPid) !== '') { mn_require_child($conn, 'rdt_product', (int)$authPid, $authTridInt); }
        }
        if (isset($_POST['mfgid']) && !is_array($_POST['mfgid']) && trim((string)$_POST['mfgid']) !== '') {
            mn_require_child($conn, 'rdt_product', (int)$_POST['mfgid'], $authTridInt);
        }
        $authPrids = isset($_POST['prid']) ? (is_array($_POST['prid']) ? $_POST['prid'] : array($_POST['prid'])) : array();
        foreach ($authPrids as $authPrid) {
            if (trim((string)$authPrid) !== '' && (int)$authPrid > 0) { mn_require_child($conn, 'rdt_performance', (int)$authPrid, $authTridInt); }
        }
    }

    // ==================================================================
    //  FACILITY STAGE: request + products + shipment
    // ==================================================================
    if ($submit === 'facility') {
        $trid_in     = isset($_POST['trid']) ? trim($_POST['trid']) : '';
        $facility    = isset($_POST['facility']) ? $_POST['facility'] : '';   // own facility for non-RCDC (set above)
        $dor         = nz(isset($_POST['dor']) ? $_POST['dor'] : '');
        $staffName   = nz(isset($_POST['staffName']) ? $_POST['staffName'] : '');
        $designation = nz(isset($_POST['designation']) ? $_POST['designation'] : '');
        $tol         = nz(isset($_POST['tol']) ? $_POST['tol'] : '');
        // Long Term detail (optional free text). Cleared whenever the type is not
        // Long Term, so a stale detail can never outlive the selection.
        $tolDetail   = nz(isset($_POST['tol_long_term']) ? $_POST['tol_long_term'] : '');
        if ($tol !== 'Long Term') { $tolDetail = null; }
        $shdt        = nz(isset($_POST['shdt']) ? $_POST['shdt'] : '');

        $p_pid   = isset($_POST['product_pid'])  ? $_POST['product_pid']  : array();
        $p_name  = isset($_POST['pname'])        ? $_POST['pname']        : array();
        $p_manu  = isset($_POST['manufacturer']) ? $_POST['manufacturer'] : array();
        /* Catalog No is optional schema: the input is only rendered when the
           column exists, so an older database simply posts nothing here. */
        $p_catno = isset($_POST['catno'])        ? $_POST['catno']        : array();
        $catChk  = @$conn->query("SHOW COLUMNS FROM rdt_product_details LIKE 'catalog_no'");
        $hasCatalog = ($catChk && $catChk->num_rows > 0);
        $p_lno   = isset($_POST['lno'])          ? $_POST['lno']          : array();
        $p_mdt   = isset($_POST['mdt'])          ? $_POST['mdt']          : array();
        $p_edt   = isset($_POST['edt'])          ? $_POST['edt']          : array();
        $p_snbox = isset($_POST['snbox'])        ? $_POST['snbox']        : array();
        $p_stpbox= isset($_POST['stpbox'])       ? $_POST['stpbox']       : array();
        $deleted = isset($_POST['deleted_pid'])  ? $_POST['deleted_pid']  : array();

        $conn->begin_transaction();
        try {
            if ($trid_in === '') {
                $stmt = $conn->prepare("INSERT INTO rdt_test_request (facility_id, date_request, staff_name, staff_designation, lot_type, lot_type_detail, date_shipment) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssss", $facility, $dor, $staffName, $designation, $tol, $tolDetail, $shdt);
                $stmt->execute(); $trid = $conn->insert_id; $stmt->close();
            } else {
                $trid = (int)$trid_in;
                $stmt = $conn->prepare("UPDATE rdt_test_request SET facility_id=?, date_request=?, staff_name=?, staff_designation=?, lot_type=?, lot_type_detail=?, date_shipment=? WHERE rdt_request_id=?");
                $stmt->bind_param("sssssssi", $facility, $dor, $staffName, $designation, $tol, $tolDetail, $shdt, $trid);
                $stmt->execute(); $stmt->close();
            }

            $n = count($p_name);
            for ($i = 0; $i < $n; $i++) {
                $name = trim($p_name[$i]);
                if ($name === '') { continue; }
                $pidIn = isset($p_pid[$i]) ? trim($p_pid[$i]) : '';
                $manu = nz($p_manu[$i]); $lno = nz($p_lno[$i]); $mdt = nz($p_mdt[$i]); $edt = nz($p_edt[$i]);
                $catno = $hasCatalog ? nz(isset($p_catno[$i]) ? $p_catno[$i] : '') : null;
                if ($pidIn !== '') {
                    $pid = (int)$pidIn;
                    if ($hasCatalog) {
                        $stmt = $conn->prepare("UPDATE rdt_product_details SET product_name=?, manufacturer=?, catalog_no=?, lot_no=?, date_manufacture=?, date_expiry=? WHERE product_id=? AND test_request_id=?");
                        $stmt->bind_param("ssssssii", $name, $manu, $catno, $lno, $mdt, $edt, $pid, $trid);
                    } else {
                        $stmt = $conn->prepare("UPDATE rdt_product_details SET product_name=?, manufacturer=?, lot_no=?, date_manufacture=?, date_expiry=? WHERE product_id=? AND test_request_id=?");
                        $stmt->bind_param("sssssii", $name, $manu, $lno, $mdt, $edt, $pid, $trid);
                    }
                    $stmt->execute(); $stmt->close();
                } else {
                    if ($hasCatalog) {
                        $stmt = $conn->prepare("INSERT INTO rdt_product_details (test_request_id, product_name, manufacturer, catalog_no, lot_no, date_manufacture, date_expiry) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("issssss", $trid, $name, $manu, $catno, $lno, $mdt, $edt);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO rdt_product_details (test_request_id, product_name, manufacturer, lot_no, date_manufacture, date_expiry) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssss", $trid, $name, $manu, $lno, $mdt, $edt);
                    }
                    $stmt->execute(); $pid = $conn->insert_id; $stmt->close();
                    $stub = $conn->prepare("INSERT INTO rdt_performance_report (manufacturer_id) VALUES (?)");
                    $stub->bind_param("i", $pid); $stub->execute(); $stub->close();
                }
                $snbox = nz($p_snbox[$i]); $stpbox = nz($p_stpbox[$i]);
                if ($snbox !== null || $stpbox !== null) {
                    $sExists = $conn->query("SELECT shipment_id FROM rdt_shipment_details WHERE product_details_id='$pid'");
                    if ($sExists->num_rows > 0) {
                        $stmt = $conn->prepare("UPDATE rdt_shipment_details SET sent_no_box=?, sent_test_per_box=? WHERE product_details_id=?");
                        $stmt->bind_param("iii", $snbox, $stpbox, $pid);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO rdt_shipment_details (product_details_id, sent_no_box, sent_test_per_box) VALUES (?, ?, ?)");
                        $stmt->bind_param("iii", $pid, $snbox, $stpbox);
                    }
                    $stmt->execute(); $stmt->close();
                }
            }
            foreach ($deleted as $dpid) {
                $dpid = (int)$dpid; if ($dpid <= 0) { continue; }
                $recv = $conn->query("SELECT shipment_id FROM rdt_shipment_details WHERE product_details_id='$dpid' AND received_no_box IS NOT NULL");
                $perf = $conn->query("SELECT performance_id FROM rdt_performance_report WHERE manufacturer_id='$dpid' AND performance_result IS NOT NULL");
                if ($recv->num_rows > 0 || $perf->num_rows > 0) { continue; }
                $conn->query("DELETE FROM rdt_assessment_report WHERE manufacturer_id='$dpid'");
                $conn->query("DELETE FROM rdt_performance_report WHERE manufacturer_id='$dpid'");
                $conn->query("DELETE FROM rdt_shipment_details WHERE product_details_id='$dpid'");
                $stmt = $conn->prepare("DELETE FROM rdt_product_details WHERE product_id=? AND test_request_id=?");
                $stmt->bind_param("ii", $dpid, $trid); $stmt->execute(); $stmt->close();
            }

            $conn->commit(); $check = true;
            // Workflow notification (post-COMMIT, best-effort, never fatal): facility request -> RCDC
            mn_notify($conn, 'RDT_REQUEST_SUBMITTED', (int)$trid);
            // The RCDC Next button posts `nextstep` so the save lands on the
            // step the user was moving to (the wizard's enabled-step fallback
            // still clamps a locked target). Without it: land on Receipt only
            // when the shipment is actually complete (date saved); otherwise
            // return to Shipment so the user can finish it.
            $nextstep = isset($_POST['nextstep']) ? (int)$_POST['nextstep'] : 0;
            $dest = ($nextstep >= 1 && $nextstep <= 7) ? $nextstep : (($shdt !== null) ? 4 : 3);
            $lnk = "rdt-registration.php?action=2&id=$trid&step=".$dest;
        } catch (\Throwable $e) {
            $conn->rollback(); $check = false;
            $lnk = ($trid_in === '') ? "rdt-registration.php?action=1" : "rdt-registration.php?action=2&id=".(int)$trid_in;
        }
    }

    // ==================================================================
    //  RCDC COMPLETE PROCESSING: receipt + assessments + performance
    //  reports for every product, saved in ONE transaction (all-or-nothing).
    // ==================================================================
    else if ($submit === 'combined_rcdc') {
        $trid   = (int)$_POST['trid'];

        // --- receipt inputs (same shape as the legacy receipt stage)
        $rdt    = nz(isset($_POST['rdt']) ? $_POST['rdt'] : '');
        $rname  = nz(isset($_POST['rname']) ? $_POST['rname'] : '');
        $rdesig = nz(isset($_POST['rdesignation']) ? $_POST['rdesignation'] : '');
        $cnt    = isset($_POST['productCnt']) ? (int)$_POST['productCnt'] : 0;
        $pid    = isset($_POST['pid'])    ? $_POST['pid']    : array();
        $lno    = isset($_POST['lno'])    ? $_POST['lno']    : array();
        $rnbox  = isset($_POST['rnbox'])  ? $_POST['rnbox']  : array();
        $rtpbox = isset($_POST['rtpbox']) ? $_POST['rtpbox'] : array();

        // --- assessment inputs: rating[product][item] / remarks[product][item];
        //     the checklist ids in cid[item] are shared by every product
        $assessCnt = isset($_POST['assessCnt']) ? (int)$_POST['assessCnt'] : 0;
        $cid       = isset($_POST['cid'])       ? $_POST['cid']       : array();
        $rating    = isset($_POST['rating'])    ? $_POST['rating']    : array();
        $remarks   = isset($_POST['remarks'])   ? $_POST['remarks']   : array();

        // --- performance inputs: every field is an array indexed by product row
        $prid = isset($_POST['prid']) ? $_POST['prid'] : array();
        $pfv  = function($k, $i){ return (isset($_POST[$k]) && isset($_POST[$k][$i])) ? nz($_POST[$k][$i]) : null; };

        $conn->begin_transaction();
        try {
            // A) Receipt: received counts + lot correction + receipt info
            for ($i = 0; $i < $cnt; $i++) {
                $p  = (int)$pid[$i];
                $l  = nz($lno[$i]);
                $rb = nz($rnbox[$i]);
                $tb = nz($rtpbox[$i]);
                $stmt = $conn->prepare("UPDATE rdt_shipment_details SET received_no_box=?, received_test_per_box=? WHERE product_details_id=?");
                $stmt->bind_param("iii", $rb, $tb, $p); $stmt->execute(); $stmt->close();
                $stmt = $conn->prepare("UPDATE rdt_product_details SET lot_no=? WHERE product_id=?");
                $stmt->bind_param("si", $l, $p); $stmt->execute(); $stmt->close();
            }
            $stmt = $conn->prepare("UPDATE rdt_test_request SET date_receive=?, received_by=?, designation=? WHERE rdt_request_id=?");
            $stmt->bind_param("sssi", $rdt, $rname, $rdesig, $trid); $stmt->execute(); $stmt->close();

            // B) Accessory assessment checklist, per product
            for ($i = 0; $i < $cnt; $i++) {
                $mfgid = (int)$pid[$i];
                for ($j = 0; $j < $assessCnt; $j++) {
                    if (!isset($cid[$j])) { continue; }
                    $c = (int)$cid[$j];
                    if (!isset($rating[$i][$j]) || $rating[$i][$j] === '') { continue; }
                    $rv  = (int)$rating[$i][$j];
                    $rem = isset($remarks[$i][$j]) ? $remarks[$i][$j] : '';
                    $ex = $conn->query("SELECT rar_id FROM rdt_assessment_report WHERE manufacturer_id='$mfgid' AND assesment_id='$c'");
                    if ($ex->num_rows > 0) {
                        $stmt = $conn->prepare("UPDATE rdt_assessment_report SET result=?, comments=? WHERE manufacturer_id=? AND assesment_id=?");
                        $stmt->bind_param("isii", $rv, $rem, $mfgid, $c);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO rdt_assessment_report(assesment_id, manufacturer_id, result, comments) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("iiis", $c, $mfgid, $rv, $rem);
                    }
                    $stmt->execute(); $stmt->close();
                }
            }

            // C) Performance report, per product (server-side rates + verdict,
            //    same rule as the legacy performance stage)
            for ($i = 0; $i < $cnt; $i++) {
                $mfgid = (int)$pid[$i];
                $pr    = isset($prid[$i]) ? (int)$prid[$i] : 0;

                $pf_pd_200_temp=$pfv('pf_pd_200_temp',$i); $pf_pds_200=$pfv('pf_pds_200',$i);
                $pf_pds_2000=$pfv('pf_pds_2000',$i); $pf_ivr=$pfv('pf_ivr',$i);
                $pv_pd_200_temp=$pfv('pv_pd_200_temp',$i); $pv_pds_200=$pfv('pv_pds_200',$i);
                $pv_pds_2000=$pfv('pv_pds_2000',$i); $pv_ivr=$pfv('pv_ivr',$i);
                $ng_temperature=$pfv('ng_temperature',$i); $total_tests=$pfv('total_tests',$i);
                $true_negative=$pfv('true_negative',$i); $false_positive=$pfv('false_positive',$i);
                $test_by=$pfv('test_by',$i); $designation=$pfv('designation',$i); $test_date=$pfv('test_date',$i);

                $tot = (float)$total_tests;
                $tn_rate = $tot > 0 ? ((float)$true_negative / $tot * 100) : 0;
                $fp_rate = $tot > 0 ? ((float)$false_positive / $tot * 100) : 0;
                if ((float)$pf_pds_200 < 75 || (float)$pf_pds_2000 < 75 || (float)$pv_pds_200 < 75 || (float)$pv_pds_2000 < 75
                    || (float)$pv_ivr >= 5 || (float)$pf_ivr >= 5 || $fp_rate >= 10) {
                    $result = "Fail";
                } else {
                    $result = "Pass";
                }

                $ex = $conn->query("SELECT performance_id FROM rdt_performance_report WHERE performance_id='$pr'");
                if ($ex->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE rdt_performance_report SET pf_pd_200_temp=?, pf_pds_200=?, pf_pds_2000=?, pf_ivr=?, pv_pd_200_temp=?, pv_pds_200=?, pv_pds_2000=?, pv_ivr=?, ng_temperature=?, total_tests=?, true_negative=?, false_positive=?, tn_rate=?, fp_rate=?, test_by=?, designation=?, test_date=?, performance_result=? WHERE performance_id=?");
                    $stmt->bind_param("iiiiiiiiiiiiddssssi",
                        $pf_pd_200_temp,$pf_pds_200,$pf_pds_2000,$pf_ivr,
                        $pv_pd_200_temp,$pv_pds_200,$pv_pds_2000,$pv_ivr,
                        $ng_temperature,$total_tests,$true_negative,$false_positive,$tn_rate,$fp_rate,
                        $test_by,$designation,$test_date,$result,$pr);
                } else {
                    $stmt = $conn->prepare("INSERT INTO rdt_performance_report (manufacturer_id, pf_pd_200_temp, pf_pds_200, pf_pds_2000, pf_ivr, pv_pd_200_temp, pv_pds_200, pv_pds_2000, pv_ivr, ng_temperature, total_tests, true_negative, false_positive, tn_rate, fp_rate, test_by, designation, test_date, performance_result) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiiiiiiiiiiiddssss",
                        $mfgid,$pf_pd_200_temp,$pf_pds_200,$pf_pds_2000,$pf_ivr,
                        $pv_pd_200_temp,$pv_pds_200,$pv_pds_2000,$pv_ivr,
                        $ng_temperature,$total_tests,$true_negative,$false_positive,$tn_rate,$fp_rate,
                        $test_by,$designation,$test_date,$result);
                }
                $stmt->execute(); $stmt->close();
            }

            $conn->commit(); $check = true;
            // Workflow notification (post-COMMIT, best-effort, never fatal): whole RCDC side complete -> facility
            mn_notify($conn, 'RDT_PERFORMANCE_COMPLETED', (int)$trid);
            // Everything saved together -> land on Review (step 7 in the RCDC wizard).
            $lnk = "rdt-registration.php?action=2&id=$trid&step=7";
        } catch (\Throwable $e) {
            $conn->rollback(); $check = false;
            $lnk = "rdt-registration.php?action=2&id=$trid&step=6";
        }
    }

    // ==================================================================
    //  RCDC RECEIPT: received counts + lot correction + receipt info
    // ==================================================================
    else if ($submit === 'receipt') {
        $trid  = (int)$_POST['trid'];
        $rdt   = nz(isset($_POST['rdt']) ? $_POST['rdt'] : '');
        $rname = nz(isset($_POST['rname']) ? $_POST['rname'] : '');
        $rdesig= nz(isset($_POST['rdesignation']) ? $_POST['rdesignation'] : '');
        $cnt   = isset($_POST['productCnt']) ? (int)$_POST['productCnt'] : 0;
        $pid   = isset($_POST['pid'])   ? $_POST['pid']   : array();
        $lno   = isset($_POST['lno'])   ? $_POST['lno']   : array();
        $rnbox = isset($_POST['rnbox']) ? $_POST['rnbox'] : array();
        $rtpbox= isset($_POST['rtpbox'])? $_POST['rtpbox']: array();

        $conn->begin_transaction();
        try {
            for ($i = 0; $i < $cnt; $i++) {
                $p  = (int)$pid[$i];
                $l  = nz($lno[$i]);
                $rb = nz($rnbox[$i]);
                $tb = nz($rtpbox[$i]);
                $stmt = $conn->prepare("UPDATE rdt_shipment_details SET received_no_box=?, received_test_per_box=? WHERE product_details_id=?");
                $stmt->bind_param("iii", $rb, $tb, $p); $stmt->execute(); $stmt->close();
                $stmt = $conn->prepare("UPDATE rdt_product_details SET lot_no=? WHERE product_id=?");
                $stmt->bind_param("si", $l, $p); $stmt->execute(); $stmt->close();
            }
            $stmt = $conn->prepare("UPDATE rdt_test_request SET date_receive=?, received_by=?, designation=? WHERE rdt_request_id=?");
            $stmt->bind_param("sssi", $rdt, $rname, $rdesig, $trid); $stmt->execute(); $stmt->close();

            $conn->commit(); $check = true;
            // Workflow notification (post-COMMIT, best-effort, never fatal): receipt -> assessment
            mn_notify($conn, 'RDT_RECEIPT_COMPLETED', (int)$trid);
            // Receipt saved -> Assessment/Performance are now unlocked; land there.
            $lnk = "rdt-registration.php?action=2&id=$trid&step=5";
        } catch (\Throwable $e) {
            $conn->rollback(); $check = false;
            $lnk = "rdt-registration.php?action=2&id=$trid&step=4";
        }
    }

    // ==================================================================
    //  ACCESSORY ASSESSMENT: per-product checklist
    // ==================================================================
    else if ($submit === 'assessment') {
        $trid  = (int)$_POST['trid'];
        $mfgid = (int)$_POST['mfgid'];
        $cnt   = isset($_POST['count']) ? (int)$_POST['count'] : 0;
        $cid   = isset($_POST['cid'])     ? $_POST['cid']     : array();
        $rating= isset($_POST['rating'])  ? $_POST['rating']  : array();
        $remarks=isset($_POST['remarks']) ? $_POST['remarks'] : array();

        $conn->begin_transaction();
        try {
            for ($i = 0; $i < $cnt; $i++) {
                if (!isset($cid[$i])) { continue; }
                $c = (int)$cid[$i];
                if (!isset($rating[$i]) || $rating[$i] === '') { continue; }
                $rv  = (int)$rating[$i];
                $rem = isset($remarks[$i]) ? $remarks[$i] : '';
                $ex = $conn->query("SELECT rar_id FROM rdt_assessment_report WHERE manufacturer_id='$mfgid' AND assesment_id='$c'");
                if ($ex->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE rdt_assessment_report SET result=?, comments=? WHERE manufacturer_id=? AND assesment_id=?");
                    $stmt->bind_param("isii", $rv, $rem, $mfgid, $c);
                } else {
                    $stmt = $conn->prepare("INSERT INTO rdt_assessment_report(assesment_id, manufacturer_id, result, comments) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("iiis", $c, $mfgid, $rv, $rem);
                }
                $stmt->execute(); $stmt->close();
            }
            $conn->commit(); $check = true;
            // Workflow notification (post-COMMIT, best-effort, never fatal): assessment -> performance testing
            mn_notify($conn, 'RDT_ASSESSMENT_COMPLETED', (int)$trid);
            $lnk = "rdt-registration.php?action=2&id=$trid&step=5";
        } catch (\Throwable $e) {
            $conn->rollback(); $check = false;
            $lnk = "rdt-registration.php?action=2&id=$trid&step=5";
        }
    }

    // ==================================================================
    //  PERFORMANCE REPORT: per-product (+ TN/FP rates + Pass/Fail)
    // ==================================================================
    else if ($submit === 'performance') {
        $trid  = (int)$_POST['trid'];
        $prid  = (int)$_POST['prid'];          // performance_id (stub exists)
        $mfgid = (int)$_POST['mfgid'];         // product id (fixes the legacy undefined var)

        $f = function($k){ return isset($_POST[$k]) ? nz($_POST[$k]) : null; };
        $pf_pd_200_temp=$f('pf_pd_200_temp'); $pf_pds_200=$f('pf_pds_200');
        $pf_pds_2000=$f('pf_pds_2000'); $pf_ivr=$f('pf_ivr');
        $pv_pd_200_temp=$f('pv_pd_200_temp'); $pv_pds_200=$f('pv_pds_200');
        $pv_pds_2000=$f('pv_pds_2000'); $pv_ivr=$f('pv_ivr');
        $ng_temperature=$f('ng_temperature'); $total_tests=$f('total_tests');
        $true_negative=$f('true_negative'); $false_positive=$f('false_positive');
        $test_by=$f('test_by'); $designation=$f('designation'); $test_date=$f('test_date');

        // Server-side computation (authoritative; same rule as legacy)
        $tot = (float)$total_tests;
        $tn_rate = $tot > 0 ? ((float)$true_negative / $tot * 100) : 0;
        $fp_rate = $tot > 0 ? ((float)$false_positive / $tot * 100) : 0;
        if ((float)$pf_pds_200 < 75 || (float)$pf_pds_2000 < 75 || (float)$pv_pds_200 < 75 || (float)$pv_pds_2000 < 75
            || (float)$pv_ivr >= 5 || (float)$pf_ivr >= 5 || $fp_rate >= 10) {
            $result = "Fail";
        } else {
            $result = "Pass";
        }

        $conn->begin_transaction();
        try {
            $ex = $conn->query("SELECT performance_id FROM rdt_performance_report WHERE performance_id='$prid'");
            if ($ex->num_rows > 0) {
                $stmt = $conn->prepare("UPDATE rdt_performance_report SET pf_pd_200_temp=?, pf_pds_200=?, pf_pds_2000=?, pf_ivr=?, pv_pd_200_temp=?, pv_pds_200=?, pv_pds_2000=?, pv_ivr=?, ng_temperature=?, total_tests=?, true_negative=?, false_positive=?, tn_rate=?, fp_rate=?, test_by=?, designation=?, test_date=?, performance_result=? WHERE performance_id=?");
                $stmt->bind_param("iiiiiiiiiiiiddssssi",
                    $pf_pd_200_temp,$pf_pds_200,$pf_pds_2000,$pf_ivr,
                    $pv_pd_200_temp,$pv_pds_200,$pv_pds_2000,$pv_ivr,
                    $ng_temperature,$total_tests,$true_negative,$false_positive,$tn_rate,$fp_rate,
                    $test_by,$designation,$test_date,$result,$prid);
            } else {
                $stmt = $conn->prepare("INSERT INTO rdt_performance_report (manufacturer_id, pf_pd_200_temp, pf_pds_200, pf_pds_2000, pf_ivr, pv_pd_200_temp, pv_pds_200, pv_pds_2000, pv_ivr, ng_temperature, total_tests, true_negative, false_positive, tn_rate, fp_rate, test_by, designation, test_date, performance_result) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiiiiiiiiiiiddssss",
                    $mfgid,$pf_pd_200_temp,$pf_pds_200,$pf_pds_2000,$pf_ivr,
                    $pv_pd_200_temp,$pv_pds_200,$pv_pds_2000,$pv_ivr,
                    $ng_temperature,$total_tests,$true_negative,$false_positive,$tn_rate,$fp_rate,
                    $test_by,$designation,$test_date,$result);
            }
            $stmt->execute(); $stmt->close();
            $conn->commit(); $check = true;
            // Workflow notification (post-COMMIT, best-effort, never fatal): performance result -> facility
            mn_notify($conn, 'RDT_PERFORMANCE_COMPLETED', (int)$trid);
            $lnk = "rdt-registration.php?action=2&id=$trid&step=6";
        } catch (\Throwable $e) {
            $conn->rollback(); $check = false;
            $lnk = "rdt-registration.php?action=2&id=$trid&step=6";
        }
    }

    // ==================================================================
    //  Shared response
    // ==================================================================
    echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");
    if ($check) {
        $conn->close();
?>
    <script>
        $(function() {
            var lnk = $('#lnk').val();
            Swal.fire({ icon:'success', confirmButtonText:'Close', text:'Submitted Successfully!!', showCloseButton:true })
            .then(function(){ window.location = lnk; })
        });
    </script>
<?php } else { ?>
    <script>
        $(function() {
            var lnk = $('#lnk').val();
            Swal.fire({ icon:'error', confirmButtonText:'Close', text:'Submission Failed!! Nothing was saved.', showCloseButton:true })
            .then(function(){ window.location = lnk; })
        });
    </script>
<?php } ?>
