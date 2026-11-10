<?php ob_start(); /* Buffer the page so a refused role returns a real 403, not a 200
     carrying a half-built page: header.php below emits <!DOCTYPE> before the
     authorisation guard runs, and bdsis_deny() discards this buffer to answer
     cleanly. Same guard-ordering fix as records/case.php and lab/lab.php. */ ?>
<!-- create print button for individual report and aswell print all option -->

<!-- Check session -->
<?php require_once("../config/session.php"); ?>
<!-- Page header -->
<?php require_once("../includes/header.php"); ?>
<!-- Database Connection -->
<?php 
  require_once("../config/commondb-config.php");
  require_once("../config/db-config.php");
  require_once("../includes/lab-helpers.php");
  require_once("../includes/rbac.php");
  /* Signatory roster for the Analyst / Verified By pickers below. The builder is
     already the single definition of that list, and is safe to include here: it
     only defines functions. */
  require_once("report-html-builder.php");
  /* A report exposes the full clinical and laboratory record, so it is authorised
     exactly like the record itself. Without this a facility user could read any
     hospital's patient simply by changing ?sid= -- subject ids are sequential. */
  bdsis_require_can('print', null, 'reports');
  if(!isset($_GET["sid"]) OR $_GET["sid"] === ""){ bdsis_deny('No report record was specified.'); }
  bdsis_require_subject($bdsis, $_GET["sid"]);
?>
<!-- Data and page settings -->
<?php
  $dzsql = "SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table";
  $sid=$bdsis->real_escape_string($_GET["sid"]);
	$siqry=$bdsis->query("SELECT subject_name, gender, age, center_id, subject_category FROM subject_information_table WHERE subject_id='".$sid."'");
    list($sname,$sex,$age,$ctid,$rvSubjectCategory)=$siqry->fetch_row();
    /* '2' = Outbreak. The Analyst / Verified By pickers are hidden for an
       outbreak case: its personnel are the fixed roster printed by the Outbreak
       Group Report (reports/report-group-pdf.php), so there is nothing to choose
       here. Surveillance subjects keep the pickers, and every PDF -- individual
       and outbreak -- still prints its signatories either way. */
    $rvIsOutbreak = ((string)$rvSubjectCategory === '2');
	$colqry=$bdsis->query("SELECT collection_date FROM sample_information_table WHERE subject_id='".$sid."'");
    list($doc)=$colqry->fetch_row();
    $mrtqry = $bdsis->query("SELECT color,mucus,blood,wbc,rbc,ova_cyst,others,processed_by,processed_on FROM microscopy_result_table WHERE subject_id='".$sid."'");
	list($clr,$muc,$bld,$wbc,$rbc,$ova_cyst,$othrs,$pby,$pon)=$mrtqry->fetch_row();
    
    $cnameqry=$commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='".$ctid."'");
	list($cname)=$cnameqry->fetch_row();
    /* "Sample Processed by" is free text on the Case Management form, so the
	   stored value is not always a username. The helper resolves one when it is
	   and returns the typed text when it is not -- the raw lookup this replaces
	   produced a blank field for anything that did not match a user. */
	$pbyname = bdsis_processed_by_label($pby, $commondb);
	
	$ageyrs=intval($age/12);
	$ageMonths=$age%12;
?>

<!-- CSS-JQuery -->
<?php require_once("../includes/css-jquery.php"); ?>

<style>
/* ---------- Detail Report presentation ----------
   Inline to this screen, so no other page inherits any of it. Presentation
   only: no value, query or print binding is affected. */

/* Key/value tables. One padding and one border colour everywhere, so rows line
   up whatever mix of colspans a section uses. */
.content-wrapper table.table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
.content-wrapper table.table td {
  padding: 12px;
  border: 1px solid #E5E7EB;
  vertical-align: middle;
  font-size: .9rem;
  line-height: 1.45;
}
/* Static data, so the row-hover tint of .table-hover is noise here. */
.content-wrapper table.table.table-hover tbody tr:hover td,
.content-wrapper table.table tr:hover td { background-color: transparent; }
.content-wrapper table.table label { margin-bottom: 0; font-weight: 400; }

/* Labels: muted fill, quiet colour, sized to their text. */
td.rv-key, td.rv-head {
  background-color: #F9FAFB;
  color: #475569;
  font-weight: 600;
  width: 1%;
  white-space: nowrap;
}
td.rv-key label, td.rv-head label { font-weight: 600; }
td.rv-head { text-align: center; width: auto; }

/* Values: printable text, never underlined and never link-like. */
.content-wrapper table.table u { text-decoration: none; }
td.rv-val, td.rv-mixed { color: #0F172A; }
td.rv-val u, td.rv-mixed u { font-weight: 600; color: #0F172A; }
/* The label half of a "Label: value" cell. */
.rv-k { color: #475569; font-weight: 600; margin-right: 4px; }

/* Signatory bar: controls on one centred row, note pushed to the right. */
.rv-signatories { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; }
.rv-sig-field { display: flex; flex-direction: column; flex: 0 1 300px; min-width: 240px; }
.rv-sig-field > label {
  margin-bottom: 6px;
  font-size: .8rem;
  font-weight: 600;
  color: #475569;
}
.rv-sig-note { margin-left: auto; color: #64748B; text-align: right; }
@media (max-width: 767.98px) {
  .rv-sig-field { flex: 1 1 100%; }
  .rv-sig-note { margin-left: 0; text-align: left; }
}

/* Print action sits at the right edge of its card, clear of the table. */
.content-wrapper .card-footer {
  display: flex;
  justify-content: flex-end;
  padding: 12px 16px;
  background-color: #fff;
  border-top: 1px solid #E5E7EB;
}
</style>

<body class="hold-transition sidebar-mini">
<div class="wrapper">
    <!-- Top Navbar -->
    <?php require_once("../includes/top-navbar.php"); ?>
    <!-- Main Sidebar Container -->
    <?php require_once("../includes/sidebar-menu.php"); ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Detail Report</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="report-management-individual.php">Individual Report Management</a></li>
                        <li class="breadcrumb-item active">Preview Report</li>
                    </ol>
                </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>

    <!-- Report signatories. Chosen here and carried on every "Print ..." button
         below, so the printed PDF names the analyst and the verifying officer.
         Both lists come from config/report-signatories.php and are pre-selected,
         so a report printed without touching them still carries a name. The
         former "Automatic" options, which derived the analyst from whoever
         processed each result section, have been removed at the client's
         request: a printed report now always names one chosen analyst.

         Not shown for an outbreak case -- see $rvIsOutbreak above. The Print
         buttons below then simply post no choice, and report-pdf.php names the
         signatories itself, exactly as it does when nothing is picked. -->

    <!-- AMR Summary -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header text-white bg-primary">
                            <h3 class="card-title">AMR Summary</h3>
                        </div>
                        <div class="card-body">
                            <table id="table" class="table table-bordered table-hover">
                                <tr>
                                    <td class="rv-mixed" colspan=2><label><span class="rv-k">Health Center:</span> <u><?= $cname;?></u></label></td>
                                    <td class="rv-mixed" colspan=2><label><span class="rv-k">Subject ID:</span> <u><?= $sid;?></u></label></td>
                                </tr>
                                <tr>
                                    <td class="rv-mixed"><label><span class="rv-k">Patient Name:</span> <u><?= $sname;?></u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">Age:</span> <u><?= $age;?> Months(<?= $ageyrs." Years, ".$ageMonths." Months";?>)</u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">Gender:</span> <u><?= $sex;?></u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">Sample Collection Date:</span> <u><?= $doc;?></u></label></td>
                                </tr>
                            </table>
                        </div>
                        <div class="card-body">
                            <table id="table" class="table table-bordered table-hover">
                                <tr>
                                    <td class="rv-mixed"><label><span class="rv-k">Colour *:</span> <u><?= $clr;?></u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">Mucus *:</span> <u><?= $muc;?></u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">Blood *:</span> <u><?= $bld;?></u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">White Blood Cell(WBC):</span> <u><?= $wbc;?></u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">Red Blood Cell(RBC):</span> <u><?= $rbc;?></u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">Parasite:</span> <u><?= $ova_cyst;?></u></label></td>
                                </tr>
                                <tr>
                                    <td class="rv-mixed" colspan=3><label><span class="rv-k">Parasite(Others):</span> <u><?= $othrs;?></u></label></td>
                                    <td class="rv-mixed" colspan=2><label><span class="rv-k">Sample Processed By:</span> <u><?= $pbyname;?></u></label></td>
                                    <td class="rv-mixed"><label><span class="rv-k">Sample Processed On:</span> <u><?= $pon;?></u></label></td>
                                </tr>
                            </table>
                        </div>
                        <div class="card-footer">
                            <input type='button'  value='Print Microscopy Report' onClick='bdsisPrintReport("microscopy", "<?=$sid;?>")' class='btn bg-gradient-success'/>
                        </div>
                    </div>
                </div>
            </div>
        </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.content -->

    <!-- Bacterology/AST Result -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <?php
                        /* DISTINCT: ms_ast_table is keyed (code, ast_method_id), so an antibiotic
                           carried by both the Disk Diffusion and the MIC master would otherwise
                           be listed -- and printed -- twice. */
                        $astQry=$bdsis->query("SELECT DISTINCT code FROM ms_ast_table WHERE status=1");
                        while(list($astCode)=$astQry->fetch_row()){
                            $astBCode[]=$astCode;
                        }
                        
                        $bacteriology2=$bdsis->query("SELECT record_id, pathogen_id, e_pathogen, ast_status, processed_by, processed_on FROM bacteriology_result_table2 WHERE subject_id='".$sid."' ORDER BY CAST(SUBSTRING(e_pathogen,2) AS UNSIGNED) ASC, record_id ASC");
                        if($bacteriology2->num_rows>0){
                            ?>
                            <div class="card-header text-white bg-primary">
                                <h3 class="card-title">Bacterology/AST Result</h3>
                            </div>
                            <?php
                            while(list($brecid,$bpid,$epathogen,$astSts,$pby,$pon)=$bacteriology2->fetch_row()){
                                $patnNameQry = $bdsis->query("SELECT enteric_pathogen FROM ms_enteric_bacterial_pathogen_table WHERE pathogen_id='".$bpid."'");
                                list($pathogenName)=$patnNameQry->fetch_row();
                                $pbyName = bdsis_processed_by_label($pby, $commondb);                                
                                ?>                        
                                <div class="card-body">
                                    <table id="table" class="table table-bordered table-hover">
                                        <tr>
                                            <td class="rv-key"><label>Pathogen:</label></td>
                                            <td class="rv-val"><label><u><?=$pathogenName;?></u></label></td>
                                            <td class="rv-key"><label>AST Status:</label></td>
                                            <td class="rv-val"><label><u><?=$astSts;?></u></label></td>
                                            <td class="rv-key"><label>Processed By:</label></td>
                                            <td class="rv-val"><label><u><?=$pbyName; ?></u></label></td>
                                            <td class="rv-key"><label>Processed On:</label></td>
                                            <td class="rv-val"><label><u><?=$pon; ?></u></label></td>
                                        </tr>
                                            
                                        <?php
                                        if($astSts == 'R'){
                                            ?>
                                            <tr>							
                                                <td class="rv-head" colspan=2><label><B>AST Method</B></label></td>
                                                <td class="rv-head" colspan=2><label><B>Antimicrobial Code</B></label></td>
                                                <td class="rv-head" colspan=2><label><B>Zone Diameter</B></label></td>
                                                <td class="rv-head" colspan=2><label><B>Ast Result</B></label></td>
                                            </tr>
                                            <?php
                                            foreach($astBCode as $astBid){
                                                $astQuery=$bdsis->query("SELECT record_id,ast_method_id, antimicrobial_code, zone_diameter, ast_result FROM bacteriology_ast_table WHERE brt2_id='".$brecid."' AND antimicrobial_code='".$astBid."'");
                                                list($astRecid,$astMethod,$antimicrobialCode,$zoneDiameter,$astResult)=$astQuery->fetch_row();
                                                $astMNameQry=$bdsis->query("SELECT ast_method FROM ms_ast_method_table WHERE method_id='".$astMethod."'");
                                                list($astMethodName)=$astMNameQry->fetch_row();
                                                /* An antibiotic with no stored result produced a row of four
                                                   blank cells inside the card. The E.coli PCR/AST loop below
                                                   already skips those; this section was missing the same
                                                   guard. Display only -- no result is hidden, only rows that
                                                   hold nothing at all. */
                                                if($astRecid>0){
                                                ?>
                                            <tr>
                                                <td class="rv-val" colspan=2><label><u><?=$astMethodName;?></u></label></td>
                                                <td class="rv-val" colspan=2><label><u><?=$antimicrobialCode;?></u></label></td>
                                                <td class="rv-val" colspan=2><label><u><?=$zoneDiameter;?></u></label></td>
                                                <td class="rv-val" colspan=2><label><u><?=$astResult;?></u></label></td>
                                            </tr>
                                        <?php
                                                }
                                            }
                                        	echo bdsis_bact_resistance_report_rows($bdsis, $brecid);   // ESBL / carbapenemase, only when triggered and stored
                                        }
                                        $asrRemarksQry = $bdsis->query("SELECT remarks FROM bacteriology_result_table1 WHERE subject_id='".$sid."'");
                                        list($astRemarks)=$asrRemarksQry->fetch_row();
                                        ?>
                                                                
                                        <?php if(trim((string)$astRemarks) !== ""){ ?><tr><td class="rv-val" colspan=8><?=$astRemarks;?></td></tr><?php } ?>
                                    </table>
                                </div>
                                <?php
                            }
                            ?>
                            <div class="card-footer">
                                <input type='button'  value='Print Bacteriology/AST Report' onClick='bdsisPrintReport("bacteriology_ast", "<?=$sid;?>")' class='btn bg-gradient-success'/>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.content -->


    <!-- PCR & AST Result -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <?php
                        $ecoliStrainQry=$bdsis->query("SELECT strain_id FROM ms_ecoli_strain_table ORDER BY strain_id ASC");
                        while(list($ecoliStrainID)=$ecoliStrainQry->fetch_row()){
                            $ecoliStrainCode[]=$ecoliStrainID;
                        }
                        
                        $ecoli_pcr=$bdsis->query("SELECT record_id, ecoli_id, ecoli_strain_result, processed_by, processed_on FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id='".$sid."' ORDER BY ecoli_id ASC");
                        if($ecoli_pcr->num_rows>0){
                            ?>
                            <div class="card-header text-white bg-primary">
                                <h3 class="card-title">PCR & AST Result</h3>
                            </div>
                            <?php
                           while(list($pcrId,$pcrEcoli,$ecoliResult,$pcrPby,$pcrPon)=$ecoli_pcr->fetch_row()){
                            $pcrPathogenNameQry=$bdsis->query("SELECT enteric_pathogen FROM ms_enteric_bacterial_pathogen_table WHERE pathogen_id='".$pcrEcoli."'");
							list($pcrPathogenName)=$pcrPathogenNameQry->fetch_row();
                            $pcrPbyNameQry=$commondb->query("SELECT fullname FROM user_profile_table WHERE username='".$pcrPby."'");
							list($pcrPbyName)=$pcrPbyNameQry->fetch_row();                               
                                ?>                        
                                <div class="card-body">
                                    <table id="table" class="table table-bordered table-hover">
                                        <tr>
                                            <td class="rv-key"><label>Pathogen:</label></td>
                                            <td class="rv-val"><label><u><?=$pcrPathogenName;?></u></label></td>
                                            <td class="rv-key"><label>PCR Result:</label></td>
                                            <td class="rv-val"><label><u><?=$ecoliResult;?></u></label></td>
                                            <td class="rv-key"><label>PCR Processed By:</label></td>
                                            <td class="rv-val"><label><u><?=$pcrPbyName; ?></u></label></td>
                                            <td class="rv-key"><label>PCR Processed On:</label></td>
                                            <td class="rv-val"><label><u><?=$pcrPon; ?></u></label></td>
                                        </tr>
                                        <?php
                                        /* Whether this isolate's PCR block prints is the one
                                           shared rule, so the report can never show a
                                           different set of blocks from the data-entry form.
                                           The per-strain conditional further down is a
                                           different thing: it decides which individual
                                           pathology-type rows print. */
                                        if(bdsis_ecoli_pcr_applies($bdsis, $sid, $pcrEcoli)){
                                            ?>
                                            <?php
                                            foreach($ecoliStrainCode as $ecoliStrain){
                                                $pcrStrainqry=$bdsis->query("SELECT record_id, ecoli_strain_id, strain_result FROM rcdc_ecoli_pcr_result_table3 WHERE ecoli_strain_id='".$ecoliStrain."' AND prt2_record_id='".$pcrId."'");
                                                list($ecoliPcrRecid,$ecoliPcrCode,$ecoliPcrResult)=$pcrStrainqry->fetch_row();			
                                                
                                                $ecoliStrainName=$bdsis->query("SELECT ecoli_strain FROM ms_ecoli_strain_table WHERE strain_id='".$ecoliPcrCode."'");
                                                list($ecoliStrainName)=$ecoliStrainName->fetch_row();
                                                
                                                if($ecoliPcrResult=='Positive' || $ecoliPcrResult=='Negative'){
                                                ?>
                                                    <tr>
                                                        <td colspan=8><label><?=$ecoliStrainName;?>: <u><?= $ecoliPcrResult;?></u></label></td>
                                                    </tr>
                                                    <?php
                                                    /* ESBL / carbapenemase recorded on this pathotype's AST panel.
                                                       Prints nothing when neither test was triggered or stored. */
                                                    echo bdsis_ecoli_resistance_report_rows($bdsis, $ecoliPcrRecid);
                                                    $pcrAstCountx=$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_ast_table WHERE prt3_record_id='".$ecoliPcrRecid."'");
                                                    list($pcrAstCount)=$pcrAstCountx->num_rows;
                                                    if($pcrAstCount>0){
                                                        $pcrAstPbyQry = $bdsis->query("SELECT processed_by, processed_on FROM rcdc_ecoli_pcr_ast_table1 WHERE record_id='".$ecoliPcrRecid."'");
                                                        list($ecoliPcrPby,$ecoliPcrPon)=$pcrAstPbyQry->fetch_row();
                                                        $ecoliPcrPbyNameQ=$commondb->query("SELECT fullname FROM user_profile_table WHERE username='".$ecoliPcrPby."'");
                                                        list($ecoliPcrPbyName)=$ecoliPcrPbyNameQ->fetch_row();
                                                    ?>
                                                        <tr>
                                                            <td colspan=2><label></label></td>
                                                            <td class="rv-mixed" colspan=3><label><span class="rv-k">AST Processed By:</span> <u><?= $ecoliPcrPbyName;?></u></label></td>
                                                            <td class="rv-mixed" colspan=3><label><span class="rv-k">AST Processed On:</span> <u><?= $ecoliPcrPon;?></u></label></td>
                                                        </tr>
                                                        <tr>							
                                                            <td class="rv-head" colspan=2><label><B>AST Method</B></label></td>
                                                            <td class="rv-head" colspan=2><label><B>Antimicrobial Code</B></label></td>
                                                            <td class="rv-head" colspan=2><label><B>Zone Diameter</B></label></td>
                                                            <td class="rv-head" colspan=2><label><B>Ast Result</B></label></td>
                                                        </tr>
                                                    
                                                    <?php
                                                        foreach($astBCode as $ecoliAstid){
                                                            $astQ=$bdsis->query("SELECT record_id,ast_method_id, antimicrobial_code, zone_diameter, ast_result FROM rcdc_ecoli_pcr_ast_table WHERE prt3_record_id='".$ecoliPcrRecid."' AND antimicrobial_code='".$ecoliAstid."'");
                                                            list($astRecid,$astMethod,$antimicrobialCode,$zoneDiameter,$astResult)=$astQ->fetch_row();
                                                            $pcrAstMethodNameQ=$commondb->query("SELECT ast_method FROM ms_ast_method_table WHERE method_id='".$astMethod."'");
                                                            list($pcrAstMethodName)=$pcrAstMethodNameQ->fetch_row();
                                                            if($astRecid>0){
                                                            ?>
                                                            <tr>
                                                                <td class="rv-val" colspan=2><label><u><?=$pcrAstMethodName;?></u></label></td>
                                                                <td class="rv-val" colspan=2><label><u><?=$antimicrobialCode;?></u></label></td>
                                                                <td class="rv-val" colspan=2><label><u><?=$zoneDiameter;?></u></label></td>
                                                                <td class="rv-val" colspan=2><label><u><?=$astResult;?></u></label></td>
                                                            </tr>
                                            
                                                <?php	
                                                            }
                                                        }
                                                    }
                                                }								
                                            }
                                        }
                                        ?>
                                        <?php
                                        $PcrAstRemarks=$bdsis->query("SELECT remarks FROM rcdc_ecoli_pcr_result_table1 WHERE subject_id='".$sid."'");
                                        list($PcrAstRemarks)=$PcrAstRemarks->fetch_row();
                                        ?>
                                        <?php if(trim((string)$PcrAstRemarks) !== ""){ ?><tr><td class="rv-val" colspan=8><?=$PcrAstRemarks;?></td></tr><?php } ?>
                                    </table>
                                </div>
                                <?php
                            }
                            ?>
                            <div class="card-footer">
                                <input type='button'  value='Print PCR/AST Report' onClick='bdsisPrintReport("pcr_ast", "<?=$sid;?>")' class='btn bg-gradient-success'/>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.content -->


    <!-- Elisa Result -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <?php
                        $elisaQry=$bdsis->query("SELECT record_id, virus_id, elisa_result, od_value, processed_by, assay_date, genotype_id FROM rcdc_elisa_result_table2 WHERE subject_id='".$sid."' ORDER BY virus_id ASC");
                        if($elisaQry->num_rows>0){
                            ?>
                            <div class="card-header text-white bg-primary">
                                <h3 class="card-title">Elisa Result</h3>
                            </div>
                                <div class="card-body">
                                    <table id="table" class="table table-bordered table-hover">
                                        <tr>							
                                            <td class="rv-head" colspan=2><label><B>Elisa Virus</B></label></td>
                                            <td class="rv-head" colspan=2><label><B>Elisa Result</B></label></td>
                                            <td class="rv-head" colspan=2><label><B>Elisa OD Value</B></label></td>
                                            <td class="rv-head" colspan=2><label><B>Elisa By</B></label></td>
                                            <td class="rv-head" colspan=2><label><B>Assay Date</B></label></td>
                                        </tr>
                                        <?php
                                        while(list($elisaId,$elisaVirusID,$elisaResult,$elisaValue,$elisaPby,$elisaPon,$elisaGenotypeID)=$elisaQry->fetch_row()){
                                            /* Rotavirus genotype -- only a handful of rows carry one, so it is
                                               appended to the result cell rather than given a column of its own,
                                               and nothing at all is emitted when none is stored. */
                                            $elisaGenotype=bdsis_rotavirus_genotype_label($bdsis, $elisaGenotypeID);
                                            $elisaVirusNameQ=$bdsis->query("SELECT virus FROM ms_virus_table WHERE virus_id='".$elisaVirusID."'");
                                            list($elisaVirusName)=$elisaVirusNameQ->fetch_row();
                                            $pelisaPbyNameQ=$commondb->query("SELECT fullname FROM rcdc_commondb.user_profile_table WHERE username='".$elisaPby."'");
                                            list($pelisaPbyName)=$pelisaPbyNameQ->fetch_row();
                                        ?>
                                            <tr>
                                                <td class="rv-val" colspan=2><label><u><?=$elisaVirusName;?></u></label></td>
                                                <td class="rv-val" colspan=2><label><u><?=$elisaResult;?></u><?php if($elisaGenotype!==''){ ?> <small>(Genotype: <?=htmlspecialchars($elisaGenotype);?>)</small><?php } ?></label></td>
                                                <td class="rv-val" colspan=2><label><u><?=$elisaValue;?></u></label></td>
                                                <td class="rv-val" colspan=2><label><u><?=$pelisaPbyName;?></u></label></td>
                                                <td class="rv-val" colspan=2><label><u><?=$elisaPon;?></u></label></td>
                                            </tr>
                                        <?php
                                        }
                                        $elisaRemarksQ=$bdsis->query("SELECT remarks FROM rcdc_elisa_result_table1 WHERE subject_id='".$sid."'");
                                        list($elisaRemarks)=$elisaRemarksQ->fetch_row();
                                        ?>
                                        <?php if(trim((string)$elisaRemarks) !== ""){ ?><tr><td class="rv-val" colspan=10><?=$elisaRemarks;?></td></tr><?php } ?>
                                    </table>
                                </div>
                            <div class="card-footer">
                                <input type='button'  value='Print Elisa Report' onClick='bdsisPrintReport("elisa", "<?=$sid;?>")' class='btn bg-gradient-success'/>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.content -->

    <!-- Elisa Result -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-footer">
                            <input type='button'  value='Print All Report' onClick='bdsisPrintReport("All", "<?=$sid;?>")' class='btn bg-gradient-success'/>
                        </div>
                    </div>
                </div>
            </div>
        </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.content -->
    
</div>
<script>
    /* Open a report PDF, carrying the Analyst / Verified By chosen above. Empty
       means "Automatic", in which case nothing is added and report-pdf.php
       behaves exactly as before. The names are validated server side against
       the configured roster. */
    function bdsisPrintReport(rpt, sid){
        var a = document.getElementById('rptAnalyst');
        var v = document.getElementById('rptVerifier');
        var url = 'report-pdf.php?rpt=' + encodeURIComponent(rpt) + '&sid=' + encodeURIComponent(sid);
        if(a && a.value){ url += '&analyst=' + encodeURIComponent(a.value); }
        if(v && v.value){ url += '&verified_by=' + encodeURIComponent(v.value); }
        window.open(url);
    }
</script>
<?php require_once("../includes/footer.php"); ?>
