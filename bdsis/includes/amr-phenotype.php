<?php
/**
 * Per-subject AMR phenotype summary (MDR / ESBL / CRE / CPE / FQ-R).
 *
 * Shared by the Group Report (reports/report-group.php and reports/report-group-pdf.php)
 * so the AMR phenotypes column reads identically on screen and in the PDF.
 * Reuses the 16-class map that the Dashboard's Key AMR phenotypes widget uses
 * (dashboard/key-amr-phenotypes.php); update both files if the protocol list ever
 * changes so the Dashboard widget and the Group Report stay in sync.
 *
 * Rules (bench-interpreted, no new master):
 *   MDR   subject has an isolate resistant to >=3 distinct antimicrobial classes.
 *   ESBL  bacteriology_resistance_table.esbl_result = 'ESBL producer'.
 *   CRE   subject has an isolate where EVERY tested carbapenem is Resistant
 *         (and at least one carbapenem was tested). Shown only when the
 *         carbapenemase_result column has not already resolved the phenotype
 *         to MBL / Serine; a confirmed CPE line replaces CRE.
 *   CPE   bacteriology_resistance_table.carbapenemase_result indicates MBL
 *         (Metallo-β-lactamase) or Serine carbapenemase, or an interim
 *         "Carbapenemase Positive". Rendered as "CPE (MBL)" / "CPE (Serine)"
 *         / "CPE". Negative / Indeterminate values do NOT render a token.
 *   FQ-R  any Fluoroquinolone Resistant.
 *
 * Both AST sources are combined per isolate: bacteriology_ast_table and
 * rcdc_ecoli_pcr_ast_table (which may hold Disk Diffusion OR MIC rows via
 * method 01 / 02; interpretations are stored as 'Susceptible' / 'Intermediate'
 * / 'Resistant' for both methods, so the same R-check works on both).
 *
 * All queries are guarded (`@` + null checks) so a missing table or a failed
 * query simply yields an empty phenotype set for that subject; a report row
 * then prints "-" rather than halting the page.
 *
 * @param  mysqli $bdsis
 * @param  array  $sids   subject ids (plain strings; this function escapes them)
 * @return array          sid => array of ordered phenotype tokens (empty when none)
 */
if (!function_exists('bdsis_amr_phenotype_summary')) {
    function bdsis_amr_phenotype_summary($bdsis, array $sids)
    {
        $out = array();
        if (!($bdsis instanceof mysqli) || count($sids) === 0) { return $out; }

        /* ---------- Antibiotic -> class map (same 16 classes the Dashboard widget uses). */
        static $classMap = array(
            'Penicillins'                          => array('ampicillin','amoxicillin'),
            'Extended spectrum cephalosporins'     => array('ceftazidime','cefepime','ceftriaxone','cefotaxime'),
            'Cephamycins'                          => array('cefoxitin','cefotetan'),
            'Penicillins + BLI'                    => array('amoxicillin-clavulanate','ampicillin-sulbactam','amoxicillin/clavulanate','co-amoxiclav'),
            'Anti-pseudomonal penicillins + BLI'   => array('piperacillin-tazobactam','ticarcillin-clavulanate','piperacillin/tazobactam'),
            'Carbapenems'                          => array('ertapenem','imipenem','meropenem','doripenem'),
            'Monobactams'                          => array('aztreonam'),
            'Aminoglycosides'                      => array('gentamicin','amikacin','tobramycin'),
            'Fluoroquinolones'                     => array('ciprofloxacin','levofloxacin','norfloxacin','ofloxacin','moxifloxacin'),
            'Folate pathway inhibitors'            => array('trimethoprim','sulfamethoxazole','cotrimoxazole','co-trimoxazole','sxt','tmp-smx'),
            'Tetracyclines'                        => array('tetracycline','doxycycline'),
            'Phenicols'                            => array('chloramphenicol'),
            'Fosfomycins'                          => array('fosfomycin'),
            'Lipopeptides'                         => array('colistin','polymyxin b'),
            'Glycylcycline'                        => array('tigecycline'),
            'Siderophore'                          => array('cefiderocol'),
        );

        /* Build code -> class from the master using case-insensitive substring. */
        $codeClass = array();
        $agq = @$bdsis->query("SELECT code, antimicrobial_agent FROM ms_ast_table WHERE ast_method_id='01'");
        if ($agq) {
            while (list($code, $agent) = $agq->fetch_row()) {
                $lc = strtolower(trim((string)$agent));
                if ($lc === '') { continue; }
                foreach ($classMap as $cls => $syns) {
                    foreach ($syns as $syn) {
                        if (strpos($lc, $syn) !== false) {
                            $codeClass[strtoupper((string)$code)] = $cls;
                            break 2;
                        }
                    }
                }
            }
            $agq->free();
        }
        $carbCodes = array_keys(array_filter($codeClass, function ($c) { return $c === 'Carbapenems'; }));
        $fqCodes   = array_keys(array_filter($codeClass, function ($c) { return $c === 'Fluoroquinolones'; }));

        /* ---------- IN-list for the three queries below. */
        $esc = array();
        foreach ($sids as $sid) {
            $sidStr = (string)$sid;
            if ($sidStr === '') { continue; }
            $esc[] = "'" . $bdsis->real_escape_string($sidStr) . "'";
        }
        if (count($esc) === 0) { return $out; }
        $in = implode(',', $esc);

        /* ---------- AST readings per (sid, isolate) across both sources. */
        $tested = array();   // sid => isolate-key => set of tested codes
        $resSet = array();   // sid => isolate-key => set of codes with Resistant
        $queries = array(
            "SELECT b.subject_id, CONCAT('b-', b.record_id), UPPER(a.antimicrobial_code), a.ast_result
               FROM bacteriology_ast_table a
               JOIN bacteriology_result_table2 b ON b.record_id = a.brt2_id
              WHERE b.subject_id IN ($in)",
            "SELECT p2.subject_id, CONCAT('e-', p3.record_id), UPPER(pa.antimicrobial_code), pa.ast_result
               FROM rcdc_ecoli_pcr_ast_table pa
               JOIN rcdc_ecoli_pcr_result_table3 p3 ON p3.record_id = pa.prt3_record_id
               JOIN rcdc_ecoli_pcr_result_table2 p2 ON p2.record_id = p3.prt2_record_id
              WHERE p2.subject_id IN ($in)",
        );
        foreach ($queries as $sql) {
            $res = @$bdsis->query($sql);
            if (!$res) { continue; }
            while ($row = $res->fetch_row()) {
                $sid  = (string)$row[0];
                $iso  = (string)$row[1];
                $code = (string)$row[2];
                $rr   = (string)$row[3];
                if ($code === '') { continue; }
                $tested[$sid][$iso][$code] = true;
                if ($rr === 'Resistant') { $resSet[$sid][$iso][$code] = true; }
            }
            $res->free();
        }

        /* ---------- ESBL / CPE phenotype from the resistance table, if present. */
        $esbl = array();   // sid => true
        $cpe  = array();   // sid => normalised token
        $rt = @$bdsis->query("SHOW TABLES LIKE 'bacteriology_resistance_table'");
        if ($rt && $rt->num_rows > 0) {
            $q = @$bdsis->query("SELECT b.subject_id, x.esbl_result, x.carbapenemase_result
                                   FROM bacteriology_resistance_table x
                                   JOIN bacteriology_result_table2 b ON b.record_id = x.brt2_id
                                  WHERE b.subject_id IN ($in)");
            if ($q) {
                while ($row = $q->fetch_row()) {
                    $sid = (string)$row[0];
                    if ((string)$row[1] === 'ESBL producer') { $esbl[$sid] = true; }
                    $cv = (string)$row[2];
                    if ($cv === '') { continue; }
                    if (stripos($cv, 'Negative') !== false || stripos($cv, 'Indeterminate') !== false) {
                        continue;   // not a phenotype worth reporting
                    }
                    if      (stripos($cv, 'Metallo')  !== false) { $cpe[$sid] = 'CPE (MBL)'; }
                    else if (stripos($cv, 'Serine')   !== false) { $cpe[$sid] = 'CPE (Serine)'; }
                    else if (stripos($cv, 'Positive') !== false) { $cpe[$sid] = 'CPE'; }
                }
                $q->free();
            }
        }
        if ($rt) { $rt->free(); }

        /* ---------- Derive per subject in a stable, readable order. */
        foreach ($sids as $sidKey) {
            $sid = (string)$sidKey;
            if ($sid === '') { continue; }

            $labels = array();
            $isMdr = false; $isFqr = false; $isCre = false;

            if (isset($resSet[$sid])) {
                foreach ($resSet[$sid] as $iso => $codes) {
                    /* MDR: distinct classes with any R, >=3 */
                    $clsSet = array();
                    foreach ($codes as $code => $_t) {
                        if (isset($codeClass[$code])) { $clsSet[$codeClass[$code]] = true; }
                    }
                    if (count($clsSet) >= 3) { $isMdr = true; }

                    /* FQ-R: any Fluoroquinolone R */
                    foreach ($fqCodes as $fq) {
                        if (isset($codes[$fq])) { $isFqr = true; break; }
                    }

                    /* CRE: every tested carbapenem is R, >=1 tested */
                    if (!empty($carbCodes)) {
                        $tst = isset($tested[$sid][$iso]) ? $tested[$sid][$iso] : array();
                        $tcCarb = array_intersect_key(array_flip($carbCodes), $tst);
                        if (count($tcCarb) > 0) {
                            $allR = true;
                            foreach ($tcCarb as $cc => $_) {
                                if (!isset($codes[$cc])) { $allR = false; break; }
                            }
                            if ($allR) { $isCre = true; }
                        }
                    }
                }
            }

            if ($isMdr)                 { $labels[] = 'MDR'; }
            if (isset($esbl[$sid]))     { $labels[] = 'ESBL'; }
            if (isset($cpe[$sid]))      { $labels[] = $cpe[$sid]; }
            else if ($isCre)            { $labels[] = 'CRE'; }
            if ($isFqr)                 { $labels[] = 'FQ-R'; }

            $out[$sid] = $labels;
        }

        return $out;
    }
}
