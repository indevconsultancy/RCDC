<?php
// require_once($_SERVER["DOCUMENT_ROOT"]."/BDSIS/config/db-config.php");
//Check if Microscopy record Exists
function chkMicroscopy($sid, $bdsis){
    $qry=$bdsis->query("SELECT subject_id FROM microscopy_result_table WHERE subject_id='$sid'");
    if($qry->num_rows>0){
        return 1;
    }else{
        return 0;
    }
}

//Check if Shipment record Exists
function chkShipment($sid, $bdsis){
    $qry=$bdsis->query("SELECT subject_id FROM sample_information_table WHERE subject_id='$sid'");
    if($qry->num_rows>0){
        return 1;
    }else{
        return 0;
    }
}

//Check if Bacteriology record Exists
function chkBacteriology($sid, $bdsis){
    $qry=$bdsis->query("SELECT subject_id FROM bacteriology_result_table2 WHERE subject_id='$sid'");
    if($qry->num_rows>0){
        return 1;
    }else{
        return 0;
    }
}

//Check if e.Coli PCR record Exists
function chkeColiPcr($sid, $bdsis){
    $qry=$bdsis->query("SELECT subject_id FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id='$sid'");
    if($qry->num_rows>0){
        return 1;
    }else{
        return 0;
    }
}

//Check if e.Coli AST record Exists
function chkeColiAst($sid, $bdsis){
    $qry=$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table3 WHERE prt2_record_id IN( SELECT record_id FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id='$sid')");
    $k=0;
    while(list($brt3id) = $qry->fetch_row()){
        $check =$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_ast_table WHERE prt3_record_id='$brt3id'");
        if($check->num_rows>0){
            $k++;
        }
    }
    if($k>0){
        return 1;
    }else{
        return 0;
    }
}

//Check if Elisa record Exists
function chkElisa($sid, $bdsis){
    $qry=$bdsis->query("SELECT subject_id FROM rcdc_elisa_result_table2 WHERE subject_id='$sid'");
    if($qry->num_rows>0){
        return 1;
    }else{
        return 0;
    }
}

?>