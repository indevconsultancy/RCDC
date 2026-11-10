<?php
	require_once("../config/session.php");
	require_once("../includes/rbac.php");
	bdsis_require_module();   // this endpoint served master data with no session at all

	if(isset($_GET["mid"]) AND $_GET["mid"]!=""){
		require_once("../config/db-config.php");
		/* Escaped: mid and prtrid are interpolated into the queries below and were
		   taken raw from the query string. */
		$mid    = $bdsis->real_escape_string($_GET["mid"]);
		$cntrx  = $_GET["cntr"];
		$codex  = $_GET["code"];
		$zonex  = $_GET["zone"];
		$prtrid = $bdsis->real_escape_string($_GET["prtrid"] ?? '');

		/* Authorise the record this AST panel belongs to. prt3_record_id chains
		   back to a subject via the PCR result tables; a facility user must not
		   read another facility's AST zone diameters by guessing an id. Enforced
		   only when a saved record is requested -- an empty prtrid is a brand-new
		   AST panel built from master data (ms_ast_table), which carries no
		   patient data to protect. Uses the same bdsis_require_subject() as every
		   other record endpoint, so the scope rule is not duplicated here. */
		if($prtrid !== ''){
			$ownerQ = $bdsis->prepare("SELECT p2.subject_id
			                             FROM rcdc_ecoli_pcr_result_table2 p2
			                             JOIN rcdc_ecoli_pcr_result_table3 p3 ON p3.prt2_record_id=p2.record_id
			                            WHERE p3.record_id=?");
			if($ownerQ){
				$ownerQ->bind_param("s", $prtrid);
				$ownerQ->execute();
				$ownerQ->bind_result($astSubject);
				$hasOwner = $ownerQ->fetch();
				$ownerQ->close();
				if($hasOwner && $astSubject !== null && $astSubject !== ''){
					bdsis_require_subject($bdsis, $astSubject);
				}
			}
		}

		/* MIC (method 02): the SAME fixed-breakpoint grid an Enteric Pathogen
		   row renders, followed by the same conditional ESBL / AmpC /
		   Carbapenemase / Carba NP panels that already sit under the Disk
		   Diffusion tiles. The grid posts mic_value[E<prt3rid>][<code>] /
		   mic_result[...] keyed by antibiotic, so it needs no counter field
		   and returns here. Disk Diffusion below is unchanged. */
		require_once("../includes/lab-helpers.php");
		if ($mid === bdsis_mic_method_id()) {
			echo bdsis_ecoli_mic_grid_panel_html($bdsis, $prtrid);
			echo bdsis_ecoli_resistance_panels($bdsis, $prtrid);
			exit;
		}

		$cntr=1;
		/**
		 * Check if already data exist
		 */
		$qry=$bdsis->query("SELECT pat.record_id,pat.antimicrobial_code,mat.code,mat.antimicrobial_agent,mat.content,pat.zone_diameter,pat.ast_result,mat.susceptible,mat.intermediate1,mat.intermediate2,mat.resistance FROM rcdc_ecoli_pcr_ast_table pat, ms_ast_table mat WHERE  pat.antimicrobial_code=mat.code AND pat.prt3_record_id='".$prtrid."' AND mat.ast_method_id='".$mid."' AND mat.status=1 ORDER BY mat.antimicrobial_agent ASC");
		if($qry && $qry->num_rows>0){
			$nAst = $qry->num_rows;
		?>
			<div class="lr-subcard lr-subcard--ast">
				<div class="lr-subcard__head">
					<h4 class="lr-subcard__title">Antimicrobial Susceptibility</h4>
					<span class="lr-subcard__meta">Disk diffusion &middot; interpreted as you type</span>
				</div>
				<div class="row lr-grid-head d-none d-md-flex">
					<div class="col-md-3">Antibiotic</div>
					<div class="col-md-3">Method</div>
					<div class="col-md-2">Zone (mm)</div>
					<div class="col-md-4">Interpretation</div>
				</div>
		<?php
			while(list($patid,$patcode,$code,$agent,$content,$zone,$rslt,$bpSus,$bpInt1,$bpInt2,$bpRes)=$qry->fetch_row()){
				$patida=$prtrid.$cntr;
				$codea=$codex.$cntr;
				$zonea=$zonex.$cntr;
				$agentTxt   = htmlspecialchars((string)$agent, ENT_QUOTES);
				$codeTxt    = htmlspecialchars((string)$code, ENT_QUOTES);
				$contentTxt = htmlspecialchars(html_entity_decode((string)$content, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
				$subMeta    = trim($codeTxt . ($contentTxt !== '' ? ' ' . $contentTxt : ''));
				$isLast     = ($cntr === $nAst);
		?>
				<div class="row lr-line<?=$isLast ? ' lr-line--last' : '';?>">
					<div class="col-md-3 lr-cell lr-cell--name">
						<span class="lr-agent"><?=$agentTxt;?></span>
						<?php if($subMeta !== ''): ?>
							<span class="lr-agent-sub"><?=$subMeta;?></span>
						<?php endif; ?>
						<input type="hidden" name="<?=$patida;?>" id="<?=$patida;?>" value="<?=$patid;?>" />
						<input type="hidden" name="<?=$codea;?>" id="<?=$codea;?>" value="<?=$code;?>" />
					</div>
					<div class="col-md-3 lr-cell d-none d-md-block">
						<span class="lr-chip">Disk Diffusion</span>
					</div>
					<div class="col-6 col-md-2 lr-cell">
						<label class="lr-cell-label" for="<?=$zonea;?>">Zone (mm)<span class="sr-only">, <?=$agentTxt;?></span>:</label>
						<input class="form-control text-center bp-zone eco-zone" type="number" name="<?=$zonea;?>" id="<?=$zonea;?>" placeholder="Zone" value="<?=$zone;?>"
						       data-code="<?=$codeTxt;?>"
						       data-sus="<?=htmlspecialchars((string)$bpSus, ENT_QUOTES);?>"
						       data-int1="<?=htmlspecialchars((string)$bpInt1, ENT_QUOTES);?>"
						       data-int2="<?=htmlspecialchars((string)$bpInt2, ENT_QUOTES);?>"
						       data-res="<?=htmlspecialchars((string)$bpRes, ENT_QUOTES);?>"
						       oninput="ecoAstInterpret(this);" />
					</div>
					<div class="col-6 col-md-4 lr-cell">
						<label class="lr-cell-label" for="<?=$zonea;?>r">Result<span class="sr-only">, <?=$agentTxt;?></span>:</label>
						<input type="text" id="<?=$zonea;?>r" class="form-control lr-pill bp-astresult eco-astresult" readonly tabindex="-1" placeholder="&ndash;" value="<?=htmlspecialchars((string)$rslt, ENT_QUOTES);?>" aria-label="AST result, <?=$agentTxt;?>" />
					</div>
				</div>
		<?php 
				$cntr++;
			}
		?>
			</div>
		<?php
		}else{
			$qry=$bdsis->query("SELECT code,antimicrobial_agent,content,susceptible,intermediate1,intermediate2,resistance FROM ms_ast_table WHERE ast_method_id='".$mid."' AND status=1 ORDER BY antimicrobial_agent ASC");
			if($qry && $qry->num_rows > 0){
				$nAst = $qry->num_rows;
		?>
				<div class="lr-subcard lr-subcard--ast">
					<div class="lr-subcard__head">
						<h4 class="lr-subcard__title">Antimicrobial Susceptibility</h4>
						<span class="lr-subcard__meta">Disk diffusion &middot; interpreted as you type</span>
					</div>
					<div class="row lr-grid-head d-none d-md-flex">
						<div class="col-md-3">Antibiotic</div>
						<div class="col-md-3">Method</div>
						<div class="col-md-2">Zone (mm)</div>
						<div class="col-md-4">Interpretation</div>
					</div>
		<?php
				while(list($code,$agent,$content,$bpSus,$bpInt1,$bpInt2,$bpRes)=$qry->fetch_row()){
					$codea=$codex.$cntr;
					$zonea=$zonex.$cntr;
					$agentTxt   = htmlspecialchars((string)$agent, ENT_QUOTES);
					$codeTxt    = htmlspecialchars((string)$code, ENT_QUOTES);
					$contentTxt = htmlspecialchars(html_entity_decode((string)$content, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
					$subMeta    = trim($codeTxt . ($contentTxt !== '' ? ' ' . $contentTxt : ''));
					$isLast     = ($cntr === $nAst);
		?>
					<div class="row lr-line<?=$isLast ? ' lr-line--last' : '';?>">
						<div class="col-md-3 lr-cell lr-cell--name">
							<span class="lr-agent"><?=$agentTxt;?></span>
							<?php if($subMeta !== ''): ?>
								<span class="lr-agent-sub"><?=$subMeta;?></span>
							<?php endif; ?>
							<input type="hidden" name="<?=$codea;?>" id="<?=$codea;?>" value="<?=$code;?>" />
						</div>
						<div class="col-md-3 lr-cell d-none d-md-block">
							<span class="lr-chip">Disk Diffusion</span>
						</div>
						<div class="col-6 col-md-2 lr-cell">
							<label class="lr-cell-label" for="<?=$zonea;?>">Zone (mm)<span class="sr-only">, <?=$agentTxt;?></span>:</label>
							<input class="form-control text-center bp-zone eco-zone" type="number" name="<?=$zonea;?>" id="<?=$zonea;?>" placeholder="Zone"
							       data-code="<?=$codeTxt;?>"
							       data-sus="<?=htmlspecialchars((string)$bpSus, ENT_QUOTES);?>"
							       data-int1="<?=htmlspecialchars((string)$bpInt1, ENT_QUOTES);?>"
							       data-int2="<?=htmlspecialchars((string)$bpInt2, ENT_QUOTES);?>"
							       data-res="<?=htmlspecialchars((string)$bpRes, ENT_QUOTES);?>"
							       oninput="ecoAstInterpret(this);" />
						</div>
						<div class="col-6 col-md-4 lr-cell">
							<label class="lr-cell-label" for="<?=$zonea;?>r">Result<span class="sr-only">, <?=$agentTxt;?></span>:</label>
							<input type="text" id="<?=$zonea;?>r" class="form-control lr-pill bp-astresult eco-astresult" readonly tabindex="-1" placeholder="&ndash;" aria-label="AST result, <?=$agentTxt;?>" />
						</div>
					</div>
		<?php 
					$cntr++;
				}
		?>
				</div>
		<?php
			}
		}
		$cntr-=1;
?>
	<input type="hidden" name="<?=$cntrx;?>" id="<?=$cntrx;?>" value="<?=$cntr;?>" />
<?php
		/* Only a panel that can carry a routine result carries the conditional
		   tests: Not Required and Pending have nothing to trigger them. */
		if ($mid === '01') { echo bdsis_ecoli_resistance_panels($bdsis, $prtrid); }
	}
?>	