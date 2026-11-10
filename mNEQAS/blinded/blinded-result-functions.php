<?php
//function to calculate density and flags
	function generateFlag($conn, $result_id, $species, $stages, $parasite, $wbc, $density){
		$pos_array = array('Pf','Pv','Po','Pm','Pk','F+V');

		//get RCDC species, stages, density ranges
		$rcdc = ($conn->query("SELECT c2_microscopy, c2_stage, c2_parasite_count, c2_wbc_count, c2_density, min_range, max_range FROM blinded_results WHERE result_id='$result_id'"));
		$rcdc = $rcdc->fetch_row();

		//flag TP,FP,FN,TN by comparing Lab species with RCDC species
		if($species == "")	$pos_flag = "MI";
		else{
			if (in_array($rcdc[0], $pos_array) && in_array($species, $pos_array)) $pos_flag = "TP";
			if ($rcdc[0] == "Nmps" && in_array($species, $pos_array)) $pos_flag = "FP";
			if (in_array($rcdc[0], $pos_array) && $species == "Nmps") $pos_flag = "FN";
			if ($rcdc[0]=="Nmps" && $species == "Nmps") $pos_flag = "TN";
		}	

		//flags for only Lab positive species	
		$species_flag = $stages_flag = $density_flag = 0;

		if(in_array($rcdc[0], $pos_array)){
			//flag species identification
			if($rcdc[0] == $species){	$species_flag = 1;

				//flag stage identification
				//rcdc and lab stage is exact match
				if($rcdc[1] == $stages)	$stages_flag = 1;
				else{
					$rcdc_cnt = strlen($rcdc[1]);
					//$lab_cnt = strlen($stages);
					$rcdc_arr = str_split($rcdc[1]);
					$lab_arr = str_split($stages);
					$common = count(array_intersect($rcdc_arr, $lab_arr)); 
					//all rcdc stages is in lab stages
					if($common == $rcdc_cnt) $stages_flag = 1;
					//partial lab stages in rcdc stages
					else $stages_flag = $common/$rcdc_cnt;
				}

				//flag parasite density
				if($density >= $rcdc[5] && $density <= $rcdc[6])	$density_flag = 1;
			}
		}
		
		$flag_array = array($density, $pos_flag, $species_flag, $stages_flag, $density_flag);
		return $flag_array;
	}

	//calculate scores and update
	function calculateScore($conn, $biid){

		$rcdc_pos = ($conn->query("SELECT COUNT(c2_microscopy) FROM blinded_results WHERE c2_microscopy IN('Pf','Pv','Po','Pm','Pk','F+V') AND blinded_id = $biid"));
		$rcdc_pos = $rcdc_pos->fetch_row();

		$rcdc_neg = ($conn->query("SELECT COUNT(c2_microscopy) FROM blinded_results WHERE c2_microscopy IN('Nmps') AND blinded_id = $biid"));
		$rcdc_neg = $rcdc_neg->fetch_row();

		$panel_received = ($conn->query("SELECT COUNT(slide_status) FROM blinded_results WHERE blinded_id = $biid AND slide_status='Intact'"));
		$panel_received = $panel_received->fetch_row();

		$tp = ($conn->query("SELECT COUNT(positivity_flag) FROM blinded_results WHERE blinded_id=$biid AND positivity_flag='TP'"));
		$tp = $tp->fetch_row();

		$tn = ($conn->query("SELECT COUNT(positivity_flag) FROM blinded_results WHERE blinded_id=$biid AND positivity_flag='TN'"));
		$tn = $tn->fetch_row();
		
		$species_flag_total = ($conn->query("SELECT SUM(species_flag) FROM blinded_results WHERE blinded_id=$biid"));
		$species_flag_total = $species_flag_total->fetch_row();

		$stages_flag_total = ($conn->query("SELECT SUM(stages_flag) FROM blinded_results WHERE blinded_id=$biid"));
		$stages_flag_total = $stages_flag_total->fetch_row();

		$density_flag_total = ($conn->query("SELECT SUM(density_flag) FROM blinded_results WHERE blinded_id=$biid"));
		$density_flag_total = $density_flag_total->fetch_row();

		$size_thick_smear = ($conn->query("SELECT COUNT(size_thick_smear) FROM blinded_results WHERE size_thick_smear='Acceptable' AND blinded_id=$biid"));
		$size_thick_smear = $size_thick_smear->fetch_row();

		$size_thin_smear = ($conn->query("SELECT COUNT(size_thin_smear) FROM blinded_results WHERE size_thin_smear='Acceptable' AND blinded_id=$biid"));
		$size_thin_smear = $size_thin_smear->fetch_row();

		$shape_thin_smear = ($conn->query("SELECT COUNT(shape_thin_smear) FROM blinded_results WHERE shape_thin_smear='Acceptable' AND blinded_id=$biid"));
		$shape_thin_smear = $shape_thin_smear->fetch_row();

		$smear_spacing = ($conn->query("SELECT COUNT(smear_spacing) FROM blinded_results WHERE smear_spacing='Acceptable' AND blinded_id=$biid"));
		$smear_spacing = $smear_spacing->fetch_row();

		$eveness_thin_smear = ($conn->query("SELECT COUNT(eveness_thin_smear) FROM blinded_results WHERE eveness_thin_smear='Even' AND blinded_id=$biid"));
		$eveness_thin_smear = $eveness_thin_smear->fetch_row();

		$blood_film = ($conn->query("SELECT COUNT(blood_film) FROM blinded_results WHERE blood_film='Both' AND blinded_id=$biid"));
		$blood_film = $blood_film->fetch_row();

		$smear_intact = ($conn->query("SELECT COUNT(smear_intact) FROM blinded_results WHERE smear_intact='Intact' AND blinded_id=$biid"));
		$smear_intact = $smear_intact->fetch_row();

		$slide_labeling = ($conn->query("SELECT COUNT(slide_labeling) FROM blinded_results WHERE slide_labeling='Acceptable' AND blinded_id=$biid"));
		$slide_labeling = $slide_labeling->fetch_row();




		$background = ($conn->query("SELECT COUNT(background) FROM blinded_results WHERE background='Acceptable' AND blinded_id=$biid"));
		$background = $background->fetch_row();

		$stain_blood_element = ($conn->query("SELECT COUNT(stain_blood_element) FROM blinded_results WHERE stain_blood_element='Acceptable' AND blinded_id=$biid"));
		$stain_blood_element = $stain_blood_element->fetch_row();

		$cleanliness = ($conn->query("SELECT COUNT(cleanliness) FROM blinded_results WHERE cleanliness='Acceptable' AND blinded_id=$biid"));
		$cleanliness = $cleanliness->fetch_row();
		
		$stain_deposit = ($conn->query("SELECT COUNT(stain_deposit) FROM blinded_results WHERE stain_deposit='Acceptable' AND blinded_id=$biid"));
		$stain_deposit = $stain_deposit->fetch_row();

		if ($rcdc_neg[0]==0) {
			$spc_score = 0;
		}else{
			$spc_score = ($tn[0]/$rcdc_neg[0])*100;
		}		
		$inf_score = (($tp[0]+$tn[0])/$panel_received[0])*100;
		$film_quality = (((($size_thick_smear[0]/$panel_received[0])+($size_thin_smear[0]/$panel_received[0])+($shape_thin_smear[0]/$panel_received[0])+($smear_spacing[0]/$panel_received[0])+($eveness_thin_smear[0]/$panel_received[0])+($blood_film[0]/$panel_received[0])+($smear_intact[0]/$panel_received[0])+($slide_labeling[0]/$panel_received[0]))/8)*100);

		$stain_quality = round(((($background[0]/$panel_received[0])+($stain_blood_element[0]/$panel_received[0])+($cleanliness[0]/$panel_received[0])+($stain_deposit[0]/$panel_received[0]))/4)*100,2);

		if ($spc_score=='NAN') $spc_score = 0;
		if ($inf_score=='NAN') $inf_score = 0;
		if ($film_quality=='NAN') $film_quality = 0;
		if ($stain_quality=='NAN') $stain_quality = 0;
		
		if ($rcdc_pos[0]==0) {
			$sen_score = $spe_score = $sta_score = $den_score = 'NULL';
			$avg_score =round(($spc_score + $inf_score +  $film_quality + $stain_quality)/4,2);
		}else{
			$sen_score = ($tp[0]/$rcdc_pos[0])*100;			
			$spe_score = ($species_flag_total[0]/$rcdc_pos[0])*100;
			$sta_score = ($stages_flag_total[0]/$rcdc_pos[0])*100;
			$den_score = ($density_flag_total[0]/$rcdc_pos[0])*100;

			if ($sen_score=='NAN') $sen_score = 0;
			if ($spe_score=='NAN') $spe_score = 0;
			if ($sta_score=='NAN') $sta_score = 0;
			if ($den_score=='NAN') $den_score = 0;

			$avg_score =round(($sen_score + $spc_score + $inf_score + $spe_score + $sta_score + $den_score + $film_quality + $stain_quality)/8,2);
		}
		if($avg_score >= 90) $achievement = "Excellent";
		else if($avg_score >= 80 && $avg_score < 90) $achievement = "Very Good";
		else if($avg_score >= 70 && $avg_score < 80) $achievement = "Good";
		else $achievement = "Poor";

		$score_array = array($sen_score, $spc_score, $inf_score, $spe_score, $sta_score, $den_score, $film_quality, $stain_quality, $avg_score, $achievement);
		return $score_array;
	}
    ?>