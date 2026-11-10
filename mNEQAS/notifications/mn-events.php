<?php
/**
 * mNEQAS - notification event registry.
 *
 * ONE definition per real workflow transition that exists in the code. Each entry says:
 *
 *   module      display name of the module
 *   record      key into mn_notify_record() - how the record's facility/label is read
 *   stage       the stage that has just been completed (stored on the log row)
 *   next        the stage that is now pending
 *   audience    'rcdc'      -> Administrator + RCDC Labs (roles 1, 2)
 *               'admin'     -> Administrator only (role 1)
 *               'facility'  -> active mNEQAS users of the record's own facility
 *   subject     email subject (facility name is appended by the template)
 *   action      one line telling the recipient what to do next
 *   link        page the record opens on, relative to APPLICATION_BASE_URL
 *
 * Events NOT registered here are deliberate; see docs/notification-system.md for
 * the ones that were dropped because they are per-row saves rather than workflow
 * transitions, or because the "next actor" is the person who just submitted.
 */

if (!function_exists('mn_notify_events')) {

    function mn_notify_events()
    {
        static $map = null;
        if ($map !== null) { return $map; }

        $map = array(

            /* ---------------- Blinded Rechecking ---------------- */
            'BLINDED_SUBMITTED' => array(
                'module' => 'Blinded Rechecking', 'record' => 'blinded',
                'stage'  => 'District submission', 'next' => 'Shipment receipt at RCDC',
                'audience' => 'rcdc',
                'subject' => 'New Blinded Rechecking submission awaiting receipt',
                'action'  => 'Receive the slide shipment and record the receipt in the Blinded Rechecking module.',
                'link'    => 'blinded/blinded.php?action=2&id=',
            ),
            'BLINDED_RECEIVED' => array(
                'module' => 'Blinded Rechecking', 'record' => 'blinded',
                'stage'  => 'Shipment receipt', 'next' => 'Controller 1 rechecking',
                'audience' => 'rcdc',
                'subject' => 'Blinded Rechecking shipment received - Controller 1 pending',
                'action'  => 'Perform the Controller 1 recheck for every intact slide.',
                'link'    => 'blinded/blinded.php?action=2&id=',
            ),
            'BLINDED_C1_COMPLETED' => array(
                'module' => 'Blinded Rechecking', 'record' => 'blinded',
                'stage'  => 'Controller 1', 'next' => 'Controller 2 and scoring',
                'audience' => 'admin',           // Controller 2 is Administrator-only in this module
                'subject' => 'Controller 1 completed - Controller 2 pending',
                'action'  => 'Complete the Controller 2 recheck so the scores can be generated.',
                'link'    => 'blinded/blinded.php?action=2&id=',
            ),
            'BLINDED_C2_COMPLETED' => array(
                'module' => 'Blinded Rechecking', 'record' => 'blinded',
                'stage'  => 'Controller 2 and scoring', 'next' => 'Findings and recommendation',
                'audience' => 'rcdc',
                'subject' => 'Controller 2 completed - feedback pending',
                'action'  => 'Record the findings and recommendation for this record.',
                'link'    => 'blinded/blinded.php?action=2&id=',
            ),
            'BLINDED_FEEDBACK_COMPLETED' => array(
                'module' => 'Blinded Rechecking', 'record' => 'blinded',
                'stage'  => 'Findings and recommendation', 'next' => 'Action taken',
                'audience' => 'rcdc',
                'subject' => 'Blinded Rechecking feedback recorded - action taken pending',
                'action'  => 'Record the action taken so the record can be finalised.',
                'link'    => 'blinded/blinded.php?action=2&id=',
            ),
            'BLINDED_ACTION_COMPLETED' => array(
                'module' => 'Blinded Rechecking', 'record' => 'blinded',
                'stage'  => 'Action taken', 'next' => 'Facility review of the feedback report',
                'audience' => 'facility',
                'subject' => 'Blinded Rechecking feedback report is available',
                'action'  => 'Review the feedback report and act on the recommendation.',
                'link'    => 'blinded/blinded.php?action=2&id=',
            ),

            /* ---------------- Onsite M&S ---------------- */
            'MS_EXTERNAL_AUDIT_COMPLETED' => array(
                'module' => 'Onsite Monitoring & Supervision', 'record' => 'onsite',
                'stage'  => 'External audit submitted', 'next' => 'Facility review and action',
                'audience' => 'facility',
                'subject' => 'Onsite M&S report is available',
                'action'  => 'Review the monitoring report, findings and recommendations for your facility.',
                'link'    => 'onsitems/ms-registration.php?action=2&id=',
            ),
            'MS_INTERNAL_AUDIT_COMPLETED' => array(
                'module' => 'Onsite Monitoring & Supervision', 'record' => 'onsite',
                'stage'  => 'Internal audit submitted', 'next' => 'RCDC review',
                'audience' => 'rcdc',
                'subject' => 'Internal Onsite M&S audit submitted',
                'action'  => 'Review the internal audit submitted by the facility.',
                'link'    => 'onsitems/ms-registration.php?action=2&id=',
            ),

            /* ---------------- Malaria RDT QA ---------------- */
            'RDT_REQUEST_SUBMITTED' => array(
                'module' => 'Malaria RDT QA', 'record' => 'rdt_request',
                'stage'  => 'Facility request and lot details', 'next' => 'Shipment receipt at RCDC',
                'audience' => 'rcdc',
                'subject' => 'New Malaria RDT QA request awaiting receipt',
                'action'  => 'Receive the RDT lot shipment and record the receipt.',
                'link'    => 'rdts/rdt-registration.php?action=2&id=',
            ),
            'RDT_RECEIPT_COMPLETED' => array(
                'module' => 'Malaria RDT QA', 'record' => 'rdt_request',
                'stage'  => 'Shipment receipt', 'next' => 'Lot assessment',
                'audience' => 'rcdc',
                'subject' => 'RDT lot received - assessment pending',
                'action'  => 'Complete the RDT lot assessment checklist.',
                'link'    => 'rdts/rdt-registration.php?action=2&id=',
            ),
            'RDT_ASSESSMENT_COMPLETED' => array(
                'module' => 'Malaria RDT QA', 'record' => 'rdt_request',
                'stage'  => 'Lot assessment', 'next' => 'Performance testing',
                'audience' => 'rcdc',
                'subject' => 'RDT lot assessment completed - performance testing pending',
                'action'  => 'Complete the RDT performance testing for this lot.',
                'link'    => 'rdts/rdt-registration.php?action=2&id=',
            ),
            'RDT_PERFORMANCE_COMPLETED' => array(
                'module' => 'Malaria RDT QA', 'record' => 'rdt_request',
                'stage'  => 'Performance testing', 'next' => 'Facility review of the QA result',
                'audience' => 'facility',
                'subject' => 'Malaria RDT QA result is available',
                'action'  => 'Review the RDT lot quality assurance result for your facility.',
                'link'    => 'rdts/rdt-registration.php?action=2&id=',
            ),

            /* ---------------- Molecular Testing ---------------- */
            'MOLECULAR_SHIPMENT_SUBMITTED' => array(
                'module' => 'Molecular Testing (DBS)', 'record' => 'dbs_shipment',
                'stage'  => 'Facility shipment', 'next' => 'Shipment receipt at RCDC',
                'audience' => 'rcdc',
                'subject' => 'New Molecular (DBS) shipment awaiting receipt',
                'action'  => 'Receive and process the DBS shipment.',
                'link'    => 'molecular/molecular-shipment.php?action=2&id=',
            ),
            'MOLECULAR_SHIPMENT_RECEIVED' => array(
                'module' => 'Molecular Testing (DBS)', 'record' => 'dbs_shipment',
                'stage'  => 'Shipment receipt', 'next' => 'PCR testing at RCDC',
                'audience' => 'facility',
                'subject' => 'Molecular (DBS) shipment received by RCDC',
                'action'  => 'No action is required; PCR testing is in progress at RCDC.',
                'link'    => 'molecular/molecular-shipment.php?action=2&id=',
            ),
            'MOLECULAR_PCR_COMPLETED' => array(
                'module' => 'Molecular Testing (DBS)', 'record' => 'dbs_shipment',
                'stage'  => 'PCR testing', 'next' => 'Facility review of the PCR results',
                'audience' => 'facility',
                'subject' => 'Molecular (DBS) PCR results are available',
                'action'  => 'Review the PCR results for the samples in this shipment.',
                'link'    => 'molecular/molecular-shipment.php?action=2&id=',
            ),

            /* ---------------- Micro Panel Testing ---------------- */
            'MICRO_PANEL_ASSIGNED' => array(
                'module' => 'Micro Panel Testing', 'record' => 'panel',
                'stage'  => 'Panel assignment', 'next' => 'Panel receipt at the facility',
                'audience' => 'facility',
                'subject' => 'A microscopy panel has been assigned to your facility',
                'action'  => 'Record the panel receipt and submit your laboratory results before the round closes.',
                'link'    => 'panels/panels.php?id=',
            ),
            'MICRO_PANEL_RECEIVED' => array(
                'module' => 'Micro Panel Testing', 'record' => 'panel',
                'stage'  => 'Panel receipt', 'next' => 'Facility laboratory results',
                'audience' => 'rcdc',
                'subject' => 'Microscopy panel receipt recorded by a facility',
                'action'  => 'No action is required yet; the facility is now entering its panel results.',
                'link'    => 'panels/panels.php?id=',
            ),
            'MICRO_PANEL_RESULTS_SUBMITTED' => array(
                'module' => 'Micro Panel Testing', 'record' => 'panel',
                'stage'  => 'Facility laboratory results', 'next' => 'RCDC findings and recommendation',
                'audience' => 'rcdc',
                'subject' => 'Microscopy panel results submitted - review pending',
                'action'  => 'Review the panel results and record the findings and recommendation.',
                'link'    => 'panels/panels.php?id=',
            ),
            'MICRO_PANEL_FEEDBACK_COMPLETED' => array(
                'module' => 'Micro Panel Testing', 'record' => 'panel',
                'stage'  => 'Findings and recommendation', 'next' => 'Facility action taken',
                'audience' => 'facility',
                'subject' => 'Microscopy panel feedback is available',
                'action'  => 'Review the panel feedback and record the action taken.',
                'link'    => 'panels/panels.php?id=',
            ),
            'MICRO_PANEL_ACTION_COMPLETED' => array(
                'module' => 'Micro Panel Testing', 'record' => 'panel',
                'stage'  => 'Action taken', 'next' => 'RCDC closure',
                'audience' => 'rcdc',
                'subject' => 'Microscopy panel action taken recorded',
                'action'  => 'The facility has recorded the action taken for this panel.',
                'link'    => 'panels/panels.php?id=',
            ),

            /* ---------------- RDT Panel Testing ---------------- */
            'RDT_PANEL_ASSIGNED' => array(
                'module' => 'RDT Panel Testing', 'record' => 'rdt_panel',
                'stage'  => 'Panel assignment', 'next' => 'Panel receipt at the facility',
                'audience' => 'facility',
                'subject' => 'An RDT panel has been assigned to your facility',
                'action'  => 'Record the panel receipt and submit your RDT panel results before the round closes.',
                'link'    => 'rdt-panels/panels.php?id=',
            ),
            'RDT_PANEL_RECEIVED' => array(
                'module' => 'RDT Panel Testing', 'record' => 'rdt_panel',
                'stage'  => 'Panel receipt', 'next' => 'Facility RDT panel results',
                'audience' => 'rcdc',
                'subject' => 'RDT panel receipt recorded by a facility',
                'action'  => 'No action is required yet; the facility is now entering its panel results.',
                'link'    => 'rdt-panels/panels.php?id=',
            ),
            'RDT_PANEL_RESULTS_SUBMITTED' => array(
                'module' => 'RDT Panel Testing', 'record' => 'rdt_panel',
                'stage'  => 'Facility RDT panel results', 'next' => 'RCDC findings and recommendation',
                'audience' => 'rcdc',
                'subject' => 'RDT panel results submitted - review pending',
                'action'  => 'Review the RDT panel results and record the findings and recommendation.',
                'link'    => 'rdt-panels/panels.php?id=',
            ),
            'RDT_PANEL_FEEDBACK_COMPLETED' => array(
                'module' => 'RDT Panel Testing', 'record' => 'rdt_panel',
                'stage'  => 'Findings and recommendation', 'next' => 'Facility action taken',
                'audience' => 'facility',
                'subject' => 'RDT panel feedback is available',
                'action'  => 'Review the RDT panel feedback and record the action taken.',
                'link'    => 'rdt-panels/panels.php?id=',
            ),
            'RDT_PANEL_ACTION_COMPLETED' => array(
                'module' => 'RDT Panel Testing', 'record' => 'rdt_panel',
                'stage'  => 'Action taken', 'next' => 'RCDC closure',
                'audience' => 'rcdc',
                'subject' => 'RDT panel action taken recorded',
                'action'  => 'The facility has recorded the action taken for this panel.',
                'link'    => 'rdt-panels/panels.php?id=',
            ),
        );
        return $map;
    }

    /**
     * Read the owning facility and a human label for a record, using the same
     * tables the authorization layer already maps records through. Prepared
     * statements only; the record id is an int everywhere.
     *
     * @return array|null facility_id, label
     */
    function mn_notify_record(mysqli $conn, $recordKey, $recordId)
    {
        $recordId = (int)$recordId;
        if ($recordId <= 0) { return null; }

        $sql = array(
            'blinded'      => "SELECT facility_id, CONCAT('Blinded Rechecking - ', month, '/', year) FROM blinded_informations WHERE blinded_id = ?",
            'onsite'       => "SELECT facility_id, CONCAT(COALESCE(audit_category,'Onsite'), ' audit - visit ', COALESCE(date_visit,'')) FROM onsite_general_informations WHERE gi_id = ?",
            'rdt_request'  => "SELECT facility_id, CONCAT('RDT QA request - ', COALESCE(lot_type,''), ' (', COALESCE(date_request,''), ')') FROM rdt_test_request WHERE rdt_request_id = ?",
            'dbs_shipment' => "SELECT facility_id, CONCAT('DBS shipment #', shipment_id, ' (', COALESCE(shipment_date,''), ')') FROM dbs_shipment_details WHERE shipment_id = ?",
            'panel'        => "SELECT p.facility_id, CONCAT('Microscopy panel - round ', COALESCE(r.panel_round, p.round_id)) FROM panel_informations p LEFT JOIN panel_round_informations r ON r.round_id = p.round_id WHERE p.panel_id = ?",
            'rdt_panel'    => "SELECT p.facility_id, CONCAT('RDT panel ', COALESCE(p.panel_number, CONCAT('#', p.panel_id))) FROM rdt_panel_informations p WHERE p.panel_id = ?",
        );
        if (!isset($sql[$recordKey])) { return null; }

        try {
            $st = $conn->prepare($sql[$recordKey]);
            if (!$st) { return null; }
            $st->bind_param('i', $recordId);
            $st->execute();
            $row = $st->get_result()->fetch_row();
            $st->close();
            if (!$row) { return null; }
            return array('facility_id' => (string)$row[0], 'label' => (string)$row[1]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
