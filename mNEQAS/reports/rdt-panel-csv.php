<?php
    error_reporting(0);
    ini_set('display_errors', '0');
    if (ob_get_length()) { ob_end_clean(); }

    require_once("../config/session.php");
    require_once("../config/auth.php");
    // Only RCDC/Admin can export; participants would leak cross-facility data.
    mn_require_rcdc('You are not authorised to export RDT panel data.', 'plain');

    require_once("../config/commondb-config.php");
    require_once("../config/db-config.php");

    header('Content-Type: text/csv');

    $delimiter = ",";
    $file = fopen('php://memory', 'w');

    $round = isset($_POST['round']) ? $conn->real_escape_string($_POST['round']) : '';

    $filename = "RDT_Panel_$round.csv";
    $header = array(
        "Round #",
        "Health Facility",
        "Panels Rec",
        "Timeliness",
        "Category",
        "Examiner",
        "Designation",
        "Kit Name",
        "Batch/Lot",
        "Manufacturer",
        "Expiry Date",
        "TP",
        "FP",
        "TN",
        "FN",
        "Sensitivity",
        "Specificity",
        "Species Idn",
        "Avg Score 1",
        "Avg Score 2",
        "Final Score",
        "Achievement"
    );
    fputcsv($file, $header, ",", '"', "\\");

    $panel = $conn->query("
        SELECT t1.panel_id, t3.panel_round, t2.center_id, t2.center_name
        FROM rdt_panel_informations t1, rcdc_commondb.ms_health_center_table t2, rdt_panel_round_informations t3
        WHERE t1.facility_id = t2.center_id
          AND t1.round_id = t3.round_id
          AND t3.panel_round = '$round'
    ");

    while ($row = $panel->fetch_row()) {
        // Scoped to THIS panel: a facility can hold several panels in one round
        // (sequential slide ranges), and each must export its own counts.
        $slide_rec = $conn->query("
            SELECT COUNT(*)
            FROM rdt_panel_slide_informations t1
            WHERE t1.panel_id = '$row[0]'
              AND t1.slide_status = 'Intact'
        ");
        $slide_rec = $slide_rec->fetch_row();

        $data = $conn->query("
            SELECT t1.timeliness, t1.category, t1.examiner, t1.designation,
                   t1.kitname, t1.batchlot, t1.manufacturer, t1.expiry_date,
                   t1.sensitivity, t1.specificity, t1.species,
                   t1.avg_score1, t1.avg_score2, t1.final_score, t1.achievement,
                   t1.panel_id
            FROM rdt_panel_lab_result_informations t1
            WHERE t1.panel_id = '$row[0]'
        ");
        $data = $data->fetch_row();
        // Facility may not have submitted a lab result yet — emit blanks rather than warnings.
        if (!$data) {
            $data = array_fill(0, 16, '');
        }

        // FP/FN/TP/TN counted from RDT lab_results joined via slide → panel, NOT the Micro
        // panel_lab_results table. Use panel_id (not information_id) because rdt_panel_lab_results
        // is keyed off slide_id.
        $pid = $data[15] !== '' ? $data[15] : $row[0];
        $tp = $conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results r JOIN rdt_panel_slide_informations s ON r.slide_id=s.slide_id WHERE s.panel_id='$pid' AND r.positivity_flag='TP'")->fetch_row();
        $fp = $conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results r JOIN rdt_panel_slide_informations s ON r.slide_id=s.slide_id WHERE s.panel_id='$pid' AND r.positivity_flag='FP'")->fetch_row();
        $fn = $conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results r JOIN rdt_panel_slide_informations s ON r.slide_id=s.slide_id WHERE s.panel_id='$pid' AND r.positivity_flag='FN'")->fetch_row();
        $tn = $conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results r JOIN rdt_panel_slide_informations s ON r.slide_id=s.slide_id WHERE s.panel_id='$pid' AND r.positivity_flag='TN'")->fetch_row();

        $line = array(
            $row[1],
            $row[3],
            $slide_rec[0],
            $data[0],
            $data[1],
            $data[2],
            $data[3],
            $data[4],
            $data[5],
            $data[6],
            $data[7],
            $tp[0],
            $fp[0],
            $tn[0],
            $fn[0],
            $data[8],
            $data[9],
            $data[10],
            $data[11],
            $data[12],
            $data[13],
            $data[14]
        );
        fputcsv($file, $line, ",", '"', "\\");
    }

    fseek($file, 0);
    if (ob_get_length()) { ob_end_clean(); }
    header("Content-Disposition: attachment; filename=$filename;");
    fpassthru($file);
    exit;
