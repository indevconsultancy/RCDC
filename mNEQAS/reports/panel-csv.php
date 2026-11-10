<?php
    /* This endpoint streams a file, so anything PHP prints lands inside the
       download and corrupts it. PHP 8.4 on this server raises
       "fputcsv(): the $escape parameter must be provided as its default value
       will change" (E_DEPRECATED) and display_errors is On, which is how those
       notices ended up in the exported CSV. The fputcsv() calls below now pass
       every parameter explicitly, which removes the cause; these two lines are
       the backstop so no future notice - or a fatal, which error_reporting
       alone does not silence - can reach the body of a download. */
    error_reporting(0);
    ini_set('display_errors', '0');

    header('Content-Type: text/csv');
    require_once("../config/session.php");
    // Independent export authorization: all-facility microscopy panel data is RCDC-only.
    mn_require_rcdc('You are not authorised to export panel data.', 'plain');
    require_once("../config/commondb-config.php");
    require_once("../config/db-config.php"); 
   
    $delimiter = ",";
    $enclosure = '"';
    $escape    = "\\";          // the current default, stated explicitly
    $file = fopen('php://memory', 'w');

    $round = isset($_POST['round']) ? $conn->real_escape_string($_POST['round']) : '';

    $filename = "Panel_$round.csv";
    $header = array("Round #","Health Facility", "Slides Rec", "Timeliness", "Category", "Examiner", "Designation",  "TP", "FP", "TN", "FN", "Sensitivity", "Specificity", "Infection Detection", "Species Idn", "Stages Idn", "Density Cal", "Avg Score", "Achievement");
    fputcsv($file, $header, $delimiter, $enclosure, $escape);

    $panel = $conn->query("SELECT t1.panel_id, t3.panel_round, t2.center_id, t2.center_name FROM panel_informations t1, rcdc_commondb.ms_health_center_table t2, panel_round_informations t3 WHERE t1.facility_id=t2.center_id AND t1.round_id=t3.round_id AND t3.panel_round='$round'");

    while($row = $panel->fetch_row()) {    
        $slide_rec = ($conn->query("SELECT COUNT(*) FROM panel_slide_informations t1, panel_informations t2, panel_round_informations t3 WHERE t1.panel_id=t2.panel_id AND t2.round_id=t3.round_id AND t3.panel_round='$round' AND t2.facility_id='$row[2]' AND t1.slide_status='Intact'"));
        $slide_rec = $slide_rec -> fetch_row(); 

        $data = ($conn->query("SELECT t1.timeliness, t1.category, t1.examiner, t1.designation, t1.sensitivity, t1.specificity, t1.infection, t1.species, t1.stages, t1.density, t1.avg_score, t1.achievement, t1.information_id FROM panel_lab_result_informations t1, panel_informations t2, panel_round_informations t3 WHERE t1.panel_id=t2.panel_id AND t2.round_id=t3.round_id AND t3.panel_round='$round' AND t2.facility_id='$row[2]'"));
        $data = $data -> fetch_row() ?: array_fill(0, 13, '');   // facility has no lab result yet
        
        $tp = ($conn->query("SELECT COUNT(*) FROM panel_lab_results WHERE information_id='$data[12]' AND positivity_flag='TP'"));
        $tp = $tp->fetch_row();
        if($tp[0] == "") $tp[0] = "";
        $fp = ($conn->query("SELECT COUNT(*) FROM panel_lab_results WHERE information_id='$data[12]' AND positivity_flag='FP'"));
        $fp = $fp->fetch_row();
        $fn = ($conn->query("SELECT COUNT(*) FROM panel_lab_results WHERE information_id='$data[12]' AND positivity_flag='FN'"));
        $fn = $fn->fetch_row();
        $tn = ($conn->query("SELECT COUNT(*) FROM panel_lab_results WHERE information_id='$data[12]' AND positivity_flag='TN'"));
        $tn = $tn->fetch_row();
        
        $data = array("$row[1]","$row[3]","$slide_rec[0]","$data[0]","$data[1]","$data[2]","$data[3]","$tp[0]","$fp[0]","$tn[0]","$fn[0]","$data[4]","$data[5]","$data[6]","$data[7]","$data[8]","$data[9]","$data[10]","$data[11]",);
        fputcsv($file, $data, $delimiter, $enclosure, $escape);
    }    
   
    fseek($file, 0);

    /* Drop every output buffer still open - config/session.php opens one so an
       authorization denial can still send headers, and that job is done by the
       time execution reaches here. Anything sitting in it is not part of the
       CSV, so it is discarded rather than flushed into the download. */
    while (ob_get_level() > 0) { ob_end_clean(); }

    header("Content-Disposition: attachment; filename=$filename;");
    fpassthru($file);
	exit;
?>