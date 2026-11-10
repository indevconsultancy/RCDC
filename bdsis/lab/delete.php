<?php
	require_once("../config/session.php");
	require_once("../config/db-config.php"); 
	require_once("../includes/rbac.php");
    include_once("../check-record.php"); 
	date_default_timezone_set('Asia/Thimphu');
	
	$cdt = date('Y-m-d h:i:s', time());
	/* Escape first: these land in DELETE statements and used to be taken raw
	   from the query string. */
	$id   = $bdsis->real_escape_string(isset($_GET["id"])   ? $_GET["id"]   : '');
	$siid = $bdsis->real_escape_string(isset($_GET["siid"]) ? $_GET["siid"] : '');
	$type = $bdsis->real_escape_string(isset($_GET["type"]) ? $_GET["type"] : '');

	/* Authorise the record before deleting anything. The listing hides the delete
	   icon from roles that may not use it, but this endpoint is reachable directly
	   by URL -- which is how a facility user could delete another hospital's data. */
	bdsis_require_subject($bdsis, $id);
	bdsis_require_can('delete', 'Facility users may not delete Lab data.', 'bacteriology');

	/* Assets load only once the caller is known to be entitled to this record,
	   so a refusal is a bare 403 rather than a denial drawn into a styled page. */
	/* Skipped for an AJAX caller: it needs clean JSON, not the SweetAlert page's
	   jQuery/CSS, which would otherwise be emitted before the JSON below. */
	if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
		require_once("../includes/css-jquery.php");
	}


    if($type == 'bacteriology'){
        if(chkeColiPcr($id, $bdsis)==0 AND chkeColiAst($id, $bdsis)==0){
            $lnk = "record-management.php";

            $checkpthree=$bdsis->query("SELECT record_id FROM bacteriology_result_table2 WHERE subject_id = '$id'");
            while(list($brt3id) = $checkpthree->fetch_row()){
                $bdsis->query("DELETE FROM bacteriology_ast_table WHERE brt2_id = '$brt3id'");
            }            
            $delqry3 ="DELETE FROM bacteriology_result_table2 WHERE subject_id = '$id'";
            $delqry2 ="DELETE FROM bacteriology_result_table1 WHERE subject_id = '$id'";
            if($bdsis->query($delqry3) AND $bdsis->query($delqry2)){
                $check = true;
            }else $check = false;
        }else{
            $lnk = "lab.php?sid=$id&action=1";
            $check = false;
        }
	}
    else if($type == 'ecolipcr'){        
		$lnk = "lab.php?sid=$id&action=1";
        if(chkeColiAst($id, $bdsis)==0){
            $checkpthree=$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id = '$id'");
            while(list($brt3id) = $checkpthree->fetch_row()){
                $bdsis->query("DELETE FROM rcdc_ecoli_pcr_result_table3 WHERE prt2_record_id = '$brt3id'");
            }            
            $delqry3 ="DELETE FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id = '$id'";
            $delqry2 ="DELETE FROM rcdc_ecoli_pcr_result_table1 WHERE subject_id = '$id'";
            if($bdsis->query($delqry3) AND $bdsis->query($delqry2)){
                $check = true;
            }else $check = false;
        }else $check = false;
	}
    else if($type == 'ecoliast'){
		$lnk = "lab.php?sid=$id&action=1";
        $check = false;
        $checkpthree=$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id = '$id'");
        $brtid=[];
        while(list($brt2id) = $checkpthree->fetch_row()){
            $brtid[]=$brt2id;
        }
        for($i=0; $i<count($brtid); $i++){
            $checkpcr =$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table3 WHERE prt2_record_id = '$brtid[$i]'");
            while(list($brt3id) = $checkpcr->fetch_row()){
                $delqry1 ="DELETE FROM rcdc_ecoli_pcr_ast_table WHERE prt3_record_id = '$brt3id'";
                $delqry2 ="DELETE FROM rcdc_ecoli_pcr_ast_table1 WHERE record_id = '$brt3id'";
                if($bdsis->query($delqry1) AND $bdsis->query($delqry2)){
                    $check = true;
                }else $check = false;
            }
        }        
	}
    /* BDSIS New Field Request - Rotavirus Genotyping: the G/P selections belong
       to the ELISA section, so clearing that section clears them too. Deleting
       the subject removes them anyway through the foreign key. */
    else if($type == 'elisa'){
		$bdsis->query("DELETE FROM rcdc_elisa_genotype_table WHERE subject_id = '$id'");
		$lnk = "lab.php?sid=$id&action=1";
		$delqry1 = "DELETE FROM rcdc_elisa_result_table1 WHERE subject_id = '$id'";
		$delqry2 = "DELETE FROM rcdc_elisa_result_table2 WHERE subject_id = '$id'";
		if($bdsis->query($delqry2) AND $bdsis->query($delqry1)) {
			$check = true;  
		}  else $check = false;
	} 
    else if($type == 'labAll'){
		$lnk = "record-management.php";
        //Delete Elisa
		$delqry1 = "DELETE FROM rcdc_elisa_result_table1 WHERE subject_id = '$id'";
		$delqry2 = "DELETE FROM rcdc_elisa_result_table2 WHERE subject_id = '$id'";
		if($bdsis->query($delqry2) AND $bdsis->query($delqry1)) {
			$check = true;  
		}  else $check = false;

        //Delete PCR AST
        $checkpthree=$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id = '$id'");
        $brtid=[];
        while(list($brt2id) = $checkpthree->fetch_row()){
            $brtid[]=$brt2id;
        }
        for($i=0; $i<count($brtid); $i++){
            $checkpcr =$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table3 WHERE prt2_record_id = '$brtid[$i]'");
            while(list($brt3id) = $checkpcr->fetch_row()){
                $delqry3 ="DELETE FROM rcdc_ecoli_pcr_ast_table WHERE prt3_record_id = '$brt3id'";
                $delqry4 ="DELETE FROM rcdc_ecoli_pcr_ast_table1 WHERE record_id = '$brt3id'";
                if($bdsis->query($delqry4) AND $bdsis->query($delqry3)){
                    $check = true;
                }else $check = false;
            }
        }

        //Delete PCR
		$checkpt=$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id = '$id'");
		while(list($brt2id) = $checkpt->fetch_row()){
			$bdsis->query("DELETE FROM rcdc_ecoli_pcr_result_table3 WHERE prt2_record_id = '$brt2id'");
		}  
        $delqry7 ="DELETE FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id = '$id'";
        $delqry6 ="DELETE FROM rcdc_ecoli_pcr_result_table1 WHERE subject_id = '$id'";
        if($bdsis->query($delqry7) AND $bdsis->query($delqry6)){
            $check = true;
        }else $check = false;

        //Delete Bacteriology
        $checkbast=$bdsis->query("SELECT record_id FROM bacteriology_result_table2 WHERE subject_id = '$id'");
        while( list($brt3id) = $checkbast->fetch_row()){
            $bdsis->query("DELETE FROM bacteriology_ast_table WHERE brt2_id = '$brt3id'");
        }            
        $delqry10 ="DELETE FROM bacteriology_result_table2 WHERE subject_id = '$id'";
        $delqry9 ="DELETE FROM bacteriology_result_table1 WHERE subject_id = '$id'";
        if($bdsis->query($delqry10) AND $bdsis->query($delqry9)){
            $check = true;
        }else $check = false;
	}
    else{	$check = false;	}

	/* In-place delete: the listing's trash icon calls this over AJAX and stays on
	   the page, so answer with a small JSON result instead of the SweetAlert +
	   window.location page. Every guard, cascade and query above is unchanged, and
	   a direct (non-AJAX) request still gets the popup-and-redirect page below --
	   so the delete behaviour, its messages and its fallback are all preserved;
	   only the response format differs for the in-place caller. */
	if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => (bool)$check,
			'msg' => $check ? 'Deleted Successfully!!' : 'Deletion Failed!!'));
		exit;
	}

	echo("<input type='hidden' name='lnk' id='lnk' value='$lnk'/>");

	if($check){
    	$bdsis->close();		
?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'success',
				confirmButtonText: 'Close',
				text: 'Deleted Successfully!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>		
	<?php }	else{ echo $bdsis -> error;?>
	<script>
		$(function() {
			var lnk = $('#lnk').val();
			Swal.fire({
				icon: 'error',
				confirmButtonText: 'Close',
				text: 'Deletion Failed!!',
				showCloseButton: true
			})
			.then(function (result) { window.location = lnk; })
		});
	</script>
<?php } ?>
