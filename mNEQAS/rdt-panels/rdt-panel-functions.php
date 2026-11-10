<?php
/**
 * Shared RDT Panel scoring / flag helpers.
 *
 * These are byte-for-byte the same generateFlag() / calculateScore() functions
 * that live in iu-results.php. They are extracted here so the unified controller
 * (iu-panel-save.php) can reuse the exact same scoring + density-free calculation
 * logic without duplicating it. iu-results.php is intentionally left unchanged as
 * a rollback safety net, so it keeps its own copies.
 */

//function to calculate density and flags
function generateFlag($conn, $slide, $species){
    $pos_array = array('Pf','Pv','F+V');

    //get RCDC species
    $rcdc = ($conn->query("SELECT rcdc_species FROM rdt_panel_rcdc_results WHERE slide_id='$slide'"));
    $rcdc = $rcdc->fetch_row();

    //flag TP,FP,FN,TN by comparing Lab species with RCDC species
    if($species == "")	$pos_flag = "MI";
    else{
        if (in_array($rcdc[0], $pos_array) && in_array($species, $pos_array)) $pos_flag = "TP";
        if ($rcdc[0] == "Negative" && in_array($species, $pos_array)) $pos_flag = "FP";
        if (in_array($rcdc[0], $pos_array) && $species == "Negative") $pos_flag = "FN";
        if ($rcdc[0]=="Negative" && $species == "Negative") $pos_flag = "TN";
    }

    //flags for only Lab positive species
    $species_flag = 0;

    if(in_array($rcdc[0], $pos_array)){
        //flag species identification
        if($rcdc[0] == $species){
            $species_flag = 1;
        }
    }

    $flag_array = array($pos_flag, $species_flag);
    return $flag_array;
}

//calculate scores and update
function calculateScore($conn, $id){

    $rcdc_pos = ($conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results t1, rdt_panel_slide_informations t2 WHERE t1.slide_id=t2.slide_id AND t2.panel_id='$id' AND t1.rcdc_species IN('Pf','Pv','F+V')"));
    $rcdc_pos = $rcdc_pos->fetch_row();

    $rcdc_neg = ($conn->query("SELECT COUNT(*) FROM rdt_panel_rcdc_results t1, rdt_panel_slide_informations t2 WHERE t1.slide_id=t2.slide_id AND t2.panel_id='$id' AND t1.rcdc_species='Negative'"));
    $rcdc_neg = $rcdc_neg->fetch_row();

    $panel_received = ($conn->query("SELECT COUNT(*) FROM rdt_panel_slide_informations WHERE panel_id='$id' AND slide_status='Intact'"));
    $panel_received = $panel_received->fetch_row();

    $tp = ($conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results t1, rdt_panel_slide_informations t2 WHERE t1.slide_id=t2.slide_id AND t2.panel_id='$id' AND positivity_flag='TP'"));
    $tp = $tp->fetch_row();

    $tn = ($conn->query("SELECT COUNT(*) FROM rdt_panel_lab_results t1, rdt_panel_slide_informations t2 WHERE t1.slide_id=t2.slide_id AND t2.panel_id='$id' AND positivity_flag='TN'"));
    $tn = $tn->fetch_row();

    $species_flag_total = ($conn->query("SELECT SUM(species_flag) FROM rdt_panel_lab_results t1, rdt_panel_slide_informations t2 WHERE t1.slide_id=t2.slide_id AND t2.panel_id='$id'"));
    $species_flag_total = $species_flag_total->fetch_row();

    if($rcdc_pos[0] != 0){
        $sen_score = ($tp[0]/$rcdc_pos[0])*100;
        $spe_score = ($species_flag_total[0]/$rcdc_pos[0])*100;
    }
    else $sen_score = $spe_score = 0;

    if($rcdc_neg[0] != 0){ $spc_score = ($tn[0]/$rcdc_neg[0])*100; } else $spc_score = 0;
    $avg_score1 =round(($sen_score + $spc_score + $spe_score)/3,2)*0.8;

    $tinfo = ($conn->query("SELECT examiner, kitname, batchlot, manufacturer, expiry_date FROM rdt_panel_lab_result_informations WHERE panel_id='$id'"));
    $tinfo = $tinfo->fetch_row();
    $score2 = 0;
    if($tinfo[0] != "") $score2++;
    if($tinfo[1] != "") $score2++;
    if($tinfo[2] != "") $score2++;
    if($tinfo[3] != "") $score2++;
    if($tinfo[4] != "") $score2++;
    $avg_score2 = ($score2/5)*20;

    $fnl_score = $avg_score1 + $avg_score2;

    if($fnl_score == 100) $achievement = "Excellent";
    else if($fnl_score >= 95 && $fnl_score < 100) $achievement = "Very Good";
    else if($fnl_score >= 90 && $fnl_score < 95) $achievement = "Good";
    else if($fnl_score >= 80 && $fnl_score < 90) $achievement = "Satisfactory";
    else $achievement = "Need Improvement";

    $score_array = array($sen_score, $spc_score, $spe_score, $avg_score1, $avg_score2, $fnl_score, $achievement);
    return $score_array;
}
