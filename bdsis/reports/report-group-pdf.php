<?php
	require_once("../config/session.php");
    require_once("../config/commondb-config.php");
    require_once("../config/db-config.php");
    require_once("../includes/lab-helpers.php");
    require_once("../includes/rbac.php");
    require_once("../includes/amr-phenotype.php");   /* per-subject AMR phenotype summary for the new column */
    require_once("report-html-builder.php");

    bdsis_require_can('pdf', null, 'reports');

	// Download script: never emit PHP warnings/notices into the .pdf output (it corrupts the file).
	ini_set('display_errors', '0');
	ini_set('max_execution_time', '300');

	/* No hard cap on subjects per group PDF: when the user selects N subjects,
	   all N are included. max_execution_time is raised above to absorb large
	   batches rather than truncating the report. */

	/**
	 * Render a plain HTML message instead of a PDF and stop.
	 * Used for the "nothing to generate" cases -- emitting a zero-subject PDF
	 * would just look like a broken download.
	 */
	function bdsis_group_report_stop($title, $message)
	{
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><html><head><title>' . htmlspecialchars($title) . '</title>'
			. '<style>body{font-family:"Source Sans Pro",Arial,sans-serif;margin:60px;color:#333;}'
			. 'h2{color:#c0392b;}a{color:#17a2b8;}</style></head><body>'
			. '<h2>' . htmlspecialchars($title) . '</h2>'
			. '<p>' . htmlspecialchars($message) . '</p>'
			. '<p><a href="report-management-individual.php">&laquo; Back to Report Management</a></p>'
			. '</body></html>';
		exit;
	}

	/**
	 * Result Template for Outbreak/Group Report: one row per subject.
	 *
	 * Local to this endpoint on purpose. It is the only caller, and keeping it
	 * here leaves report-html-builder.php -- shared with report-view.php and
	 * report-pdf.php -- untouched.
	 *
	 * Columns: SAMPLE ID | NAME | CID NO. | AGE/SEX | BACTERIOLOGY RESULT | PCR RESULT |
	 * ELISA RESULT | ANTIBIOTIC SUSCEPTIBILITY TESTING (AMP | CZO | LEX | CIP ...) |
	 * AMR PHENOTYPES. A template
	 * antibiotic is shown only when at least one of the listed subjects actually
	 * has a result for it -- a column of nothing but "-" tells the reader
	 * nothing -- and with none of them tested the Antibiotics group disappears
	 * altogether. Template order is kept, and any other antibiotic actually
	 * tested for one of the listed subjects is appended after them. AST is read
	 * from BOTH panels that record it -- the bacteriology isolate and the E.coli
	 * (PCR) isolate -- and two results for one antibiotic are shown as "S/R".
	 *
	 * Every query is guarded: a failed query, a missing master row or an empty
	 * result renders "-", never a warning into the PDF stream and never a fatal.
	 */
	function bdsis_group_result_table_html($subjects, $bdsis, $subjectTypeLabel = '', $commondb = null)
	{
		$templateCodes = array('AMP', 'CZO', 'LEX', 'CIP');
		$dash = '-';

		$sids = array();
		foreach ((array) $subjects as $s) {
			$sid = (is_array($s) && isset($s['subject_id'])) ? trim((string) $s['subject_id']) : '';
			if ($sid !== '') { $sids[$sid] = $s; }
		}
		$inList = '';
		if (count($sids) > 0) {
			$esc = array();
			foreach (array_keys($sids) as $sid) { $esc[] = "'" . $bdsis->real_escape_string($sid) . "'"; }
			$inList = implode(',', $esc);
		}

		/* Run one query and hand each row to $fn; a failed query is simply no rows. */
		$each = function ($sql, $fn) use ($bdsis, $inList) {
			if ($inList === '') { return; }
			$res = $bdsis->query($sql);
			if (!$res) { return; }
			while ($row = $res->fetch_row()) { $fn($row); }
			$res->free();
		};
		/* "Negative" wins over Not Done / Pending when both were recorded. */
		$statusText = function ($arr) use ($dash) {
			if (!is_array($arr) || count($arr) === 0) { return $dash; }
			if (isset($arr['Negative'])) { return 'Negative'; }
			return implode(', ', array_keys($arr));
		};
		$joinKeys = function ($arr, $sep = ', ') use ($dash) {
			return (is_array($arr) && count($arr) > 0) ? implode($sep, array_keys($arr)) : $dash;
		};
		/* Precedence for AST interpretations: R > SDD > I > S > - */
		$resolveAst = function ($arr) use ($dash) {
			if (!is_array($arr) || count($arr) === 0) { return $dash; }
			$keys = array();
			foreach (array_keys($arr) as $k) {
				$keys[] = strtoupper(trim((string) $k));
			}
			if (in_array('R', $keys, true) || in_array('RESISTANT', $keys, true)) {
				return 'R';
			}
			if (in_array('SDD', $keys, true) || in_array('SUSCEPTIBLE-DOSE DEPENDENT', $keys, true) || in_array('SUSCEPTIBLE DOSE DEPENDENT', $keys, true)) {
				return 'SDD';
			}
			if (in_array('I', $keys, true) || in_array('INTERMEDIATE', $keys, true)) {
				return 'I';
			}
			if (in_array('S', $keys, true) || in_array('SUSCEPTIBLE', $keys, true) || in_array('SENSITIVE', $keys, true)) {
				return 'S';
			}
			foreach ($keys as $k) {
				if ($k !== '' && $k !== '-' && $k !== 'N/A' && $k !== 'NA') {
					return $k;
				}
			}
			return $dash;
		};
		$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

		/* Bacteriology: pathogen names per subject, in slot order (P1, P2, ...). */
		$bact = array();
		$each("SELECT b.subject_id, COALESCE(NULLIF(m.enteric_pathogen,''), b.pathogen_id)
		         FROM bacteriology_result_table2 b
		         LEFT JOIN ms_enteric_bacterial_pathogen_table m ON m.pathogen_id = b.pathogen_id
		        WHERE b.subject_id IN (" . $inList . ")
		        ORDER BY b.subject_id, CAST(SUBSTRING(b.e_pathogen,2) AS UNSIGNED), b.record_id",
			function ($r) use (&$bact) {
				$name = trim((string) $r[1]);
				if ($name !== '') { $bact[$r[0]][$name] = true; }
			});

		/* PCR: the positive E.coli pathotypes; otherwise the isolate-level result. */
		$pcrPos = array(); $pcrOther = array();
		$each("SELECT p2.subject_id, p2.ecoli_strain_result, s.ecoli_strain, p3.strain_result
		         FROM rcdc_ecoli_pcr_result_table2 p2
		         LEFT JOIN rcdc_ecoli_pcr_result_table3 p3 ON p3.prt2_record_id = p2.record_id
		         LEFT JOIN ms_ecoli_strain_table s ON s.strain_id = p3.ecoli_strain_id
		        WHERE p2.subject_id IN (" . $inList . ")
		        ORDER BY p2.subject_id, p2.record_id, p3.ecoli_strain_id",
			function ($r) use (&$pcrPos, &$pcrOther) {
				$strain = trim((string) $r[2]);
				if ((string) $r[3] === 'Positive' && $strain !== '') { $pcrPos[$r[0]][$strain] = true; }
				$lvl = trim((string) $r[1]);
				if ($lvl !== '') { $pcrOther[$r[0]][$lvl] = true; }
			});

		/* ELISA: positive / equivocal viruses with genotype; otherwise the status. */
		$elisaPos = array(); $elisaOther = array();
		$each("SELECT e.subject_id, COALESCE(NULLIF(v.virus,''), e.virus_id), e.elisa_result, e.genotype_id
		         FROM rcdc_elisa_result_table2 e
		         LEFT JOIN ms_virus_table v ON v.virus_id = e.virus_id
		        WHERE e.subject_id IN (" . $inList . ")
		        ORDER BY e.subject_id, e.virus_id",
			function ($r) use (&$elisaPos, &$elisaOther, $bdsis) {
				$virus = trim((string) $r[1]);
				$rslt  = trim((string) $r[2]);
				if ($rslt === 'Positive' || $rslt === 'Equivocal') {
					$label = $virus;
					$geno  = '';
					if (function_exists('bdsis_rotavirus_genotype_label') && (string) $r[3] !== '') {
						$geno = trim((string) bdsis_rotavirus_genotype_label($bdsis, $r[3]));
					}
					if ($geno !== '') { $label .= ' (' . $geno . ')'; }
					if ($rslt === 'Equivocal') { $label .= ': Equivocal'; }
					if ($label !== '') { $elisaPos[$r[0]][$label] = true; }
				} elseif ($rslt !== '') {
					$elisaOther[$r[0]][$rslt] = true;
				}
			});

		/* AST from both panels: subject => code => result => true. */
		$ast = array(); $seenCodes = array();
		$collect = function ($r) use (&$ast, &$seenCodes) {
			$code = strtoupper(trim((string) $r[1]));
			if ($code === '') { return; }
			$map = array('SUSCEPTIBLE' => 'S', 'INTERMEDIATE' => 'I', 'RESISTANT' => 'R');
			$raw = strtoupper(trim((string) $r[2]));
			$val = isset($map[$raw]) ? $map[$raw] : trim((string) $r[2]);
			if ($val === '') { return; }
			$ast[$r[0]][$code][$val] = true;
			$seenCodes[$code] = true;
		};
		$each("SELECT b.subject_id, a.antimicrobial_code, a.ast_result
		         FROM bacteriology_ast_table a
		         JOIN bacteriology_result_table2 b ON b.record_id = a.brt2_id
		        WHERE b.subject_id IN (" . $inList . ")", $collect);
		$each("SELECT p2.subject_id, pa.antimicrobial_code, pa.ast_result
		         FROM rcdc_ecoli_pcr_ast_table pa
		         JOIN rcdc_ecoli_pcr_result_table3 p3 ON p3.record_id = pa.prt3_record_id
		         JOIN rcdc_ecoli_pcr_result_table2 p2 ON p2.record_id = p3.prt2_record_id
		        WHERE p2.subject_id IN (" . $inList . ")", $collect);

		/* A column is shown only when at least one of the selected subjects
		   actually has a result for it -- a column of nothing but "-" tells
		   the reader nothing. Template codes (AMP/CZO/LEX/CIP) lead so the
		   column widths and the matching on-screen Group Report layout stay
		   anchored; every OTHER antibiotic actually tested across the selected
		   subjects is appended after them in alphabetical order. This
		   replaces the previous behaviour that dropped extra drugs from the
		   panel. */
		$codes = array();
		foreach ($templateCodes as $code) {
			if (isset($seenCodes[$code])) { $codes[] = $code; unset($seenCodes[$code]); }
		}
		$extraCodes = array_keys($seenCodes);
		sort($extraCodes);
		$codes = array_merge($codes, $extraCodes);

		/* Full antibiotic names for the footnote (best effort; missing = no note). */
		$agentNames = array();
		$res = $bdsis->query("SELECT code, antimicrobial_agent FROM ms_ast_table WHERE ast_method_id='01'");
		if ($res) {
			while ($r = $res->fetch_row()) { $agentNames[strtoupper(trim((string) $r[0]))] = trim((string) $r[1]); }
			$res->free();
		}

		$html  = '<style type="text/css">
			table.grpTable {width:100%;border-collapse:collapse;font-size:9px;color:#333333;}
			table.grpTable th {border:1px solid #333333;padding:4px 3px;text-align:center;vertical-align:middle;font-weight:bold;background-color:#ffffff;}
			table.grpTable td {border:1px solid #333333;padding:4px 3px;text-align:center;vertical-align:middle;}
			table.grpTable td.left {text-align:left;}
			.grpTitle {text-align:center;font-size:14px;font-weight:bold;margin:0 0 4px 0;}
			.grpSub {text-align:center;font-size:10px;margin:0 0 8px 0;}
			.grpNote {font-size:8px;color:#555555;margin-top:6px;}
		</style>';
		/* OUTBREAK ONLY: reinstated at the client's request. The in-body
		   "Laboratory Results for Diarrhea Outbreak/Surveillance" title and
		   its "Subject Type: Outbreak" subline print above the table for
		   outbreak reports; Surveillance and mixed (All) group reports still
		   render without them (their earlier "Clean Header" state is
		   preserved). The mPDF running-header banner for this template
		   remains cleared for every subject type -- see the SetHeader guard
		   in the caller below. */
		if ($subjectTypeLabel === 'Outbreak') {
			$html .= '<div class="grpTitle">Laboratory Results for Diarrhea Outbreak/Surveillance</div>';
			$html .= '<div class="grpSub">Subject Type: <b>' . $h($subjectTypeLabel) . '</b></div>';
		}

		/* With no antibiotic column to show, the Antibiotics group and its second
		   header row are dropped entirely and the leading columns become a plain
		   single-row header. */
		$nCodes = count($codes);
		$rs     = $nCodes > 0 ? ' rowspan="2"' : '';
		/* Header labels align with the on-screen Group Report table -- upper-cased
		   here as agreed with the client. The four-antibiotic template (AMP, CZO,
		   LEX, CIP) is preserved. Any antibiotic outside the template that was
		   actually tested for a listed subject is still appended after these
		   four. */
		/* AMR phenotype tokens per subject (MDR / ESBL / CRE / CPE / FQ-R),
		   derived in one batch so the PDF reads identically to the on-screen
		   Group Report. Empty array -> dash cell. */
		$phen = function_exists('bdsis_amr_phenotype_summary')
		      ? bdsis_amr_phenotype_summary($bdsis, array_keys($sids))
		      : array();

		/* CID No. (2026-10-07): new fixed column positioned between NAME and
		   AGE/SEX, mirroring the on-screen Group Report layout. Value comes
		   from subject_information_table.cid_no in bulk via the loader at
		   the bottom of this file; empty for a subject whose record was
		   created before the ALTER was applied. */
		$html .= '<table class="grpTable"><thead><tr>'
			. '<th' . $rs . '>SAMPLE ID</th><th' . $rs . '>NAME</th>'
			. '<th' . $rs . '>CID NO.</th>'
			. '<th' . $rs . '>AGE/SEX</th>'
			. '<th' . $rs . '>BACTERIOLOGY RESULT</th><th' . $rs . '>PCR RESULT</th><th' . $rs . '>ELISA RESULT</th>';
		if ($nCodes > 0) {
			$html .= '<th colspan="' . $nCodes . '">ANTIBIOTIC SUSCEPTIBILITY TESTING</th>'
			       . '<th' . $rs . '>AMR PHENOTYPES</th></tr><tr>';
			foreach ($codes as $code) {
				$html .= '<th>' . $h($code) . '</th>';
			}
		} else {
			/* No AST columns: AMR PHENOTYPES is a plain header on the single header row. */
			$html .= '<th>AMR PHENOTYPES</th>';
		}
		$html .= '</tr></thead><tbody>';

		if (count($sids) === 0) {
			/* +1 for the AMR phenotypes column so the empty-state row still spans the full width.
			   (7 fixed columns now: SAMPLE ID + NAME + CID NO. + AGE/SEX + BACTERIOLOGY + PCR + ELISA.) */
			$html .= '<tr><td colspan="' . (7 + count($codes) + 1) . '">No subjects to report.</td></tr>';
		}
		foreach ($sids as $sid => $s) {
			$name   = isset($s['subject_name']) ? trim((string) $s['subject_name']) : '';
			$cid    = isset($s['cid_no']) ? trim((string) $s['cid_no']) : '';
			$age    = isset($s['age']) ? trim((string) $s['age']) : '';
			$sex    = isset($s['gender']) ? trim((string) $s['gender']) : '';
			$ageSex = trim($age . (($age !== '' && $sex !== '') ? ' / ' : '') . $sex);

			$bactText  = isset($bact[$sid]) ? $joinKeys($bact[$sid]) : $dash;
			$pcrText   = isset($pcrPos[$sid]) ? $joinKeys($pcrPos[$sid])
			           : (isset($pcrOther[$sid]) ? $statusText($pcrOther[$sid]) : $dash);
			$elisaText = isset($elisaPos[$sid]) ? $joinKeys($elisaPos[$sid])
			           : (isset($elisaOther[$sid]) ? $statusText($elisaOther[$sid]) : $dash);
			$phenText  = (isset($phen[$sid]) && is_array($phen[$sid]) && count($phen[$sid]) > 0)
			           ? implode(', ', $phen[$sid]) : $dash;

			$html .= '<tr>'
				. '<td>' . $h($sid) . '</td>'
				. '<td class="left">' . ($name !== '' ? $h($name) : $dash) . '</td>'
				. '<td>' . ($cid !== '' ? $h($cid) : $dash) . '</td>'
				. '<td>' . ($ageSex !== '' ? $h($ageSex) : $dash) . '</td>'
				. '<td class="left">' . $h($bactText) . '</td>'
				. '<td class="left">' . $h($pcrText) . '</td>'
				. '<td class="left">' . $h($elisaText) . '</td>';
			foreach ($codes as $code) {
				$cell = isset($ast[$sid][$code]) ? $resolveAst($ast[$sid][$code]) : $dash;
				$html .= '<td>' . $h($cell) . '</td>';
			}
			$html .= '<td>' . $h($phenText) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		/* OUTBREAK ONLY: the expanded legend and the sign-off block below it.
		   A Surveillance or mixed ("All") group report keeps exactly the
		   one-line note it has always printed -- these are additions to the
		   Outbreak report, not a change to the others. */
		$reportType = ($subjectTypeLabel === 'Outbreak') ? 'OUTBREAK' : 'OTHER';
		if ($reportType === 'OUTBREAK') {
			/* Everything Outbreak-specific, styles included, is emitted here and
			   only here, so the Surveillance / All output is exactly what it was
			   before any of it existed. mPDF reads <style> blocks from anywhere in
			   the document.

			   The Analyst / Verified-By sign-off table sits IMMEDIATELY BELOW the
			   legend/note and is DYNAMIC on this Outbreak batch. Names come from
			   the actual records the report covers, not from a fixed roster:
			   * "Analyzed by"  -- the distinct processed_by values from every
			                       Bacteriology / PCR / ELISA row of the selected
			                       subjects, resolved through bdsis_processed_by_label()
			                       so legacy usernames and free-text entries both
			                       render as full names.
			   * "Verified by"  -- the union of lab_record_status_table.verified_by
			                       values across the selected subjects (records the
			                       workflow has verified). Empty union falls back to
			                       the config verifying officer so the table never
			                       prints "-".
			   Both use the same .grpFoot layout as the legacy fixed block. The
			   fixed helper bdsis_group_signoff_html() is kept defined but no longer
			   called; use it if you ever want the roster-only form. Signature
			   images live at config/signatures/<slug>.(png|jpg|jpeg); missing =
			   blank cell to hand-sign. */
			$html .= bdsis_group_outbreak_css();
			$html .= bdsis_group_ast_legend_html($codes, $agentNames);
			$html .= bdsis_group_dynamic_signoff_html(
				bdsis_group_report_analysts($inList, $bdsis, $commondb),
				bdsis_group_report_verifiers($inList, $bdsis, $commondb)
			);

			return $html;
		}

		$notes = array();
		foreach ($codes as $code) {
			if (isset($agentNames[$code]) && $agentNames[$code] !== '') {
				$notes[] = $code . ' = ' . $agentNames[$code];
			}
		}
		$html .= '<div class="grpNote">S = Susceptible, I = Intermediate, R = Resistant, - = not tested / no result.'
			. (count($notes) > 0 ? ' ' . $h(implode('; ', $notes)) . '.' : '') . '</div>';

		return $html;
	}

	/**
	 * OUTBREAK ONLY: the abbreviations note printed under the AST table.
	 *
	 * Only the antibiotics actually shown in this report are listed -- the
	 * column list is already reduced to the ones some listed subject has a
	 * result for, so the note follows it and never explains a column that is
	 * not there.
	 *
	 * The printed names come from the agreed list below rather than from
	 * ms_ast_table, because the master spells two of them differently (SXT is
	 * held as "Trimethoprin Sulfamethoxazole", not "Co-Trimoxazole"). Any
	 * antibiotic outside that list still falls back to the master name, so an
	 * agent added later is described rather than silently unexplained.
	 *
	 * @param  array $codes      antibiotic codes shown, in column order
	 * @param  array $agentNames code => name from ms_ast_table (fallback)
	 * @return string
	 */
	function bdsis_group_ast_legend_html($codes, $agentNames)
	{
		$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

		/* Agreed spellings for the reported panel. */
		$canonical = array(
			'AMP' => 'Ampicillin',
			'CZO' => 'Cefazolin',
			'LEX' => 'Cephalexin',
			'CIP' => 'Ciprofloxacin',
			'CHL' => 'Chloramphenicol',
			'CRO' => 'Ceftriaxone',
			'GEN' => 'Gentamycin',
			'NAL' => 'Nalidixic Acid',
			'SXT' => 'Co-Trimoxazole',
			'TCY' => 'Tetracycline',
		);

		$notes = array();
		foreach ((array) $codes as $code) {
			$code = strtoupper(trim((string) $code));
			if ($code === '') { continue; }
			if (isset($canonical[$code])) {
				$name = $canonical[$code];
			} elseif (isset($agentNames[$code]) && $agentNames[$code] !== '') {
				$name = $agentNames[$code];
			} else {
				continue;
			}
			$notes[] = $code . ' = ' . $name;
		}

		$html = '<div class="grpLegend">'
			. '<div class="grpLegendRow"><b>Results:</b> S = Susceptible, I = Intermediate, R = Resistant, '
			. '&#8211; = Not Tested / No Result, NEP = No enteric pathogen Isolated</div>';
		if (count($notes) > 0) {
			$html .= '<div class="grpLegendRow"><b>Antibiotics:</b> ' . $h(implode(', ', $notes)) . '</div>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * OUTBREAK ONLY: styles for the legend and the sign-off footer.
	 *
	 * Kept out of the shared <style> block in bdsis_group_result_table_html()
	 * so a Surveillance report's markup carries nothing Outbreak-specific.
	 * Tables are used for layout because they are what mPDF renders most
	 * predictably; page-break-inside keeps the footer in one piece.
	 *
	 * @return string
	 */
	function bdsis_group_outbreak_css()
	{
		return '<style type="text/css">
			.grpLegend {font-size:8px;color:#333333;margin-top:6px;}
			.grpLegendRow {margin-bottom:2px;}
			table.grpFoot {width:100%;border-collapse:collapse;font-size:9px;color:#333333;margin-top:14px;page-break-inside:avoid;}
			table.grpFoot th {border:1px solid #333333;padding:5px 6px;text-align:left;font-weight:bold;background-color:#f5f5f5;}
			table.grpFoot td {border:1px solid #333333;padding:6px 6px;vertical-align:middle;text-align:left;height:28px;}
			table.grpFoot td.grpFootSig {width:30%;}
			.grpFootGap {height:10px;}
			img.grpSig {height:30px;}
		</style>';
	}

	/**
	 * OUTBREAK ONLY: the laboratory personnel sign-off block.
	 *
	 *   Analyst Name     | Designation               | Signature
	 *   Ugyen Chophel    | Laboratory Officer        |
	 *   Dorji Tshering   | Sr. Laboratory Technician |
	 *   Rinzin Wangchuk  | Sr. Laboratory Technician |
	 *
	 *   Verified By      | Designation               | Signature
	 *   Kinley Gyem      | Chief Laboratory Officer  |
	 *
	 * The people and their designations are the approved laboratory roster in
	 * config/report-signatories.php -- the same list the Individual Detail
	 * Report's pickers are validated against -- read here by role: everyone
	 * marked 'analyst', then the verifying officer. Nothing is hardcoded in this
	 * file and nothing is read from the sample data, so every Outbreak report
	 * carries the same signatories, in roster order.
	 *
	 * Signature images: nothing in this system stores one, so a file convention
	 * is used -- config/signatures/<full-name-slug>.png|jpg (see the README
	 * there). A signer with a file gets it printed; otherwise the cell is left
	 * blank to be signed by hand.
	 *
	 * Called only from the OUTBREAK branch, so no other report can show it.
	 *
	 * @return string
	 */
	function bdsis_group_signoff_html()
	{
		$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

		$cfg    = bdsis_report_signatories();
		$roster = isset($cfg['roster']) && is_array($cfg['roster']) ? $cfg['roster'] : array();

		$analysts  = array();
		$verifiers = array();
		foreach ($roster as $person) {
			$roles = isset($person['roles']) && is_array($person['roles']) ? $person['roles'] : array();
			if (!empty($roles['analyst']))  { $analysts[]  = $person; }
			if (!empty($roles['verifier'])) { $verifiers[] = $person; }
		}
		/* The configured verifying officer, when the roster does not mark one. */
		if (count($verifiers) === 0 && trim((string) $cfg['verified_by']) !== '') {
			$key = bdsis_report_name_key($cfg['verified_by']);
			$verifiers[] = isset($roster[$key]) ? $roster[$key]
				: array('name' => trim((string) $cfg['verified_by']), 'designation' => '');
		}

		/* This person's signature image, or an empty cell to sign by hand. */
		$sig = function ($name) {
			$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string) $name))), '-');
			if ($slug !== '') {
				foreach (array('png', 'jpg', 'jpeg') as $ext) {
					$path = __DIR__ . '/../config/signatures/' . $slug . '.' . $ext;
					if (is_file($path) && is_readable($path)) {
						return '<img src="' . htmlspecialchars($path, ENT_QUOTES) . '" class="grpSig" />';
					}
				}
			}
			return '&nbsp;';
		};

		$table = function ($heading, $people) use ($h, $sig) {
			$rows = '';
			foreach ($people as $p) {
				$d = trim((string) $p['designation']);
				$rows .= '<tr><td>' . $h($p['name']) . '</td><td>' . $h($d === '' ? '-' : $d) . '</td>'
					   . '<td class="grpFootSig">' . $sig($p['name']) . '</td></tr>';
			}
			if ($rows === '') { $rows = '<tr><td>-</td><td>-</td><td class="grpFootSig">&nbsp;</td></tr>'; }
			return '<table class="grpFoot"><thead><tr><th>' . $heading . '</th><th>Designation</th><th>Signature</th></tr></thead>'
				 . '<tbody>' . $rows . '</tbody></table>';
		};

		return $table('Analyst Name', $analysts) . '<div class="grpFootGap"></div>' . $table('Verified By', $verifiers);
	}

	/* --------------------------------------------------------------------
	   Dynamic (record-based) sign-off for the Outbreak Group Report.

	   The four helpers below collect the actual people who processed and
	   verified the selected subjects, and render them into the same
	   .grpFoot tables the fixed helper above uses. They are called only
	   from bdsis_group_result_table_html()'s OUTBREAK branch -- Surveillance
	   and mixed group reports do not emit either the fixed or the dynamic
	   block. Individual reports (report-view.php / report-pdf.php) are on
	   a completely different code path and stay on bdsis_report_analysts()
	   / bdsis_report_verified_by() in report-html-builder.php -- untouched.
	   -------------------------------------------------------------------- */

	/**
	 * Signature <img> for a name, or a non-breaking space when no file exists.
	 *
	 * Lifted out of the inline $sig closure inside bdsis_group_signoff_html()
	 * so both the fixed roster block above and the dynamic block below can
	 * share exactly one image-lookup rule. Slug convention unchanged: lower-
	 * case, non-alphanumerics collapsed to dashes, matched against
	 * config/signatures/<slug>.(png|jpg|jpeg).
	 */
	function bdsis_group_signature_img($name)
	{
		$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string) $name))), '-');
		if ($slug !== '') {
			foreach (array('png', 'jpg', 'jpeg') as $ext) {
				$path = __DIR__ . '/../config/signatures/' . $slug . '.' . $ext;
				if (is_file($path) && is_readable($path)) {
					return '<img src="' . htmlspecialchars($path, ENT_QUOTES) . '" class="grpSig" />';
				}
			}
		}
		return '&nbsp;';
	}

	/**
	 * Distinct analysts across the selected Outbreak batch.
	 *
	 * Reads processed_by across Bacteriology (bacteriology_result_table2),
	 * E. coli PCR (rcdc_ecoli_pcr_result_table2), E. coli PCR-AST
	 * (rcdc_ecoli_pcr_ast_table1 joined back through _table3/_table2) and
	 * ELISA (rcdc_elisa_result_table2) -- the four sections whose data
	 * appears in the Group Report table. Microscopy is deliberately
	 * omitted: microscopy results are not printed on this report, so its
	 * technician would be credited for work the reader cannot see.
	 *
	 * Each raw value is resolved to a display name via
	 * bdsis_processed_by_label() -- legacy usernames become full names,
	 * free-text entries pass through unchanged -- and rows are deduped by
	 * that resolved name so the same person appears once even if some rows
	 * held a username and others held their typed name. Designation via
	 * bdsis_report_designation() (roster first, user_profile fallback).
	 *
	 * @param  string $inList  the caller's pre-escaped IN clause (matches
	 *                         the one already built at the top of
	 *                         bdsis_group_result_table_html()). '' when no
	 *                         subjects were selected -- the function then
	 *                         returns an empty array so nothing is rendered.
	 * @param  mysqli $bdsis
	 * @param  mysqli $commondb
	 * @return array  list of array('user' => string, 'name' => string, 'designation' => string)
	 */
	function bdsis_group_report_analysts($inList, $bdsis, $commondb)
	{
		if ($inList === '' || !($bdsis instanceof mysqli)) { return array(); }

		$queries = array(
			"SELECT DISTINCT processed_by FROM bacteriology_result_table2 WHERE subject_id IN (" . $inList . ") AND processed_by IS NOT NULL AND processed_by <> ''",
			"SELECT DISTINCT processed_by FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id IN (" . $inList . ") AND processed_by IS NOT NULL AND processed_by <> ''",
			"SELECT DISTINCT a.processed_by FROM rcdc_ecoli_pcr_ast_table1 a
			   JOIN rcdc_ecoli_pcr_result_table3 t3 ON t3.record_id = a.record_id
			   JOIN rcdc_ecoli_pcr_result_table2 t2 ON t2.record_id = t3.prt2_record_id
			  WHERE t2.subject_id IN (" . $inList . ") AND a.processed_by IS NOT NULL AND a.processed_by <> ''",
			"SELECT DISTINCT processed_by FROM rcdc_elisa_result_table2 WHERE subject_id IN (" . $inList . ") AND processed_by IS NOT NULL AND processed_by <> ''",
		);

		$uniqueAnalysts = array();
		$cfg = function_exists('bdsis_report_signatories') ? bdsis_report_signatories() : array();

		foreach ($queries as $sql) {
			$res = $bdsis->query($sql);
			if (!$res) { continue; }
			while (list($raw) = $res->fetch_row()) {
				if ($raw === null || trim((string) $raw) === '') { continue; }
				$raw = trim((string) $raw);
				$label = trim((string) bdsis_processed_by_label($raw, $commondb));
				if ($label === '') { continue; }

				$key = function_exists('bdsis_report_name_key')
					? bdsis_report_name_key($label)
					: strtolower(preg_replace('/\s+/', ' ', trim($label)));

				if ($key === '') { continue; }

				/* Prefer canonical display name from roster if available */
				if (isset($cfg['roster'][$key]['name']) && $cfg['roster'][$key]['name'] !== '') {
					$label = $cfg['roster'][$key]['name'];
				}

				if (isset($uniqueAnalysts[$key])) { continue; }

				$d = function_exists('bdsis_report_designation')
					? bdsis_report_designation($raw, $label, $commondb)
					: '';

				$uniqueAnalysts[$key] = array(
					'user'        => $raw,
					'name'        => $label,
					'designation' => ($d !== null && trim((string) $d) !== '') ? trim((string) $d) : '-',
				);
			}
			$res->free();
		}
		return array_values($uniqueAnalysts);
	}

	/**
	 * Distinct workflow verifiers across the selected Outbreak batch.
	 *
	 * One SELECT against lab_record_status_table for subjects whose status
	 * is 'verified' and whose verified_by is set. Each value is resolved
	 * through bdsis_processed_by_label() and deduped by resolved name, so a
	 * mixed batch that carries three different verifiers prints all three.
	 * If no subject in the batch has been workflow-verified, falls back to
	 * the configured verifying officer (config/report-signatories.php's
	 * verified_by -- currently Kinley Gyem) so the table never shows "-".
	 *
	 * @param  string $inList
	 * @param  mysqli $bdsis
	 * @param  mysqli $commondb
	 * @return array  list of array('user' => string, 'name' => string, 'designation' => string)
	 */
	function bdsis_group_report_verifiers($inList, $bdsis, $commondb)
	{
		$people = array();
		if ($inList !== '' && $bdsis instanceof mysqli) {
			$verified = defined('BDSIS_LAB_VERIFIED') ? BDSIS_LAB_VERIFIED : 'verified';
			$sql = "SELECT DISTINCT verified_by FROM lab_record_status_table
			         WHERE subject_id IN (" . $inList . ")
			           AND status = '" . $bdsis->real_escape_string($verified) . "'
			           AND verified_by IS NOT NULL AND verified_by <> ''";
			$res = $bdsis->query($sql);
			if ($res) {
				while (list($raw) = $res->fetch_row()) {
					if ($raw === null || $raw === '') { continue; }
					$label = bdsis_processed_by_label($raw, $commondb);
					if ($label === '' || isset($people[$label])) { continue; }
					$people[$label] = array(
						'user'        => (string) $raw,
						'name'        => $label,
						'designation' => bdsis_report_designation((string) $raw, $label, $commondb),
					);
				}
				$res->free();
			}
		}
		/* Fallback: the configured verifying officer. Keeps the printed
		   table honest -- an unverified batch still names who would sign. */
		if (count($people) === 0) {
			$cfg  = bdsis_report_signatories();
			$name = trim((string) (isset($cfg['verified_by']) ? $cfg['verified_by'] : ''));
			if ($name !== '') {
				$people[$name] = array(
					'user'        => '',
					'name'        => $name,
					'designation' => bdsis_report_designation('', $name, $commondb),
				);
			}
		}
		return array_values($people);
	}

	/**
	 * Render the dynamic Analyzed by / Verified by sign-off tables.
	 *
	 * Same two .grpFoot tables the fixed bdsis_group_signoff_html() uses:
	 * Name | Designation | Signature. When an input list is empty (which
	 * for verifiers shouldn't happen thanks to the config fallback), the
	 * table prints a single "-" row so the layout is unchanged.
	 *
	 * @param  array $analysts  from bdsis_group_report_analysts()
	 * @param  array $verifiers from bdsis_group_report_verifiers()
	 * @return string
	 */
	function bdsis_group_dynamic_signoff_html($analysts, $verifiers)
	{
		/* Deduplicate analysts list so each analyst appears strictly once in 'Analyzed by' */
		$uniqueAnalysts = array();
		foreach ((array) $analysts as $analyst) {
			$name = trim((string) (isset($analyst['name']) ? $analyst['name'] : (isset($analyst['user']) ? $analyst['user'] : '')));
			if ($name === '') { continue; }
			$key = function_exists('bdsis_report_name_key')
				? bdsis_report_name_key($name)
				: strtolower(preg_replace('/\s+/', ' ', trim($name)));
			if ($key === '' || isset($uniqueAnalysts[$key])) { continue; }
			$d = trim((string) (isset($analyst['designation']) ? $analyst['designation'] : ''));
			$uniqueAnalysts[$key] = array(
				'user'        => isset($analyst['user']) ? $analyst['user'] : '',
				'name'        => $name,
				'designation' => ($d === '' ? '-' : $d),
			);
		}
		$analysts = array_values($uniqueAnalysts);

		$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
		$table = function ($heading, $people) use ($h) {
			$rows = '';
			foreach ((array) $people as $p) {
				$d = trim((string) (isset($p['designation']) ? $p['designation'] : ''));
				$rows .= '<tr><td>' . $h($p['name']) . '</td><td>' . $h($d === '' ? '-' : $d) . '</td>'
					   . '<td class="grpFootSig">' . bdsis_group_signature_img($p['name']) . '</td></tr>';
			}
			if ($rows === '') { $rows = '<tr><td>-</td><td>-</td><td class="grpFootSig">&nbsp;</td></tr>'; }
			return '<table class="grpFoot"><thead><tr><th>' . $heading . '</th><th>Designation</th><th>Signature</th></tr></thead>'
				 . '<tbody>' . $rows . '</tbody></table>';
		};
		return $table('Analyzed by', $analysts) . '<div class="grpFootGap"></div>' . $table('Verified by', $verifiers);
	}

	/* ------------------------------------------------------------------
	   1. Requested subjects
	   ------------------------------------------------------------------ */
	/* Which sections to render.

	   The form posts `template` (a predefined template id). `rpt` is the
	   legacy single-section parameter this endpoint originally took; it is
	   still honoured when no template is posted so any bookmarked or
	   hand-rolled post keeps working.

	   Neither value is trusted: `template` is checked against the authoritative
	   list from the builder and `rpt` against the five legacy strings, with
	   `full` as the fallback in both cases. */
	$templates = bdsis_report_templates();
    

	/*
 * ---------------------------------------------------------------
 * Subject Type
 * ---------------------------------------------------------------
 *
 * 1 = Surveillance
 * 2 = Outbreak
 * empty = All
 */
$subjectType = isset($_POST['subject_type'])
    ? (string) $_POST['subject_type']
    : '';

if (!in_array($subjectType, array('', '1', '2'), true)) {
    $subjectType = '';
}

	$templateId = isset($_POST["template"]) ? (string) $_POST["template"] : '';
	if ($templateId === '' && isset($_POST["rpt"])) {
		// Backward compatible path: map the legacy value onto a template where
		// one lines up, otherwise let the builder resolve the raw string.
		$legacyRpt  = (string) $_POST["rpt"];
		$allowedRpt = array("All", "microscopy", "bacteriology_ast", "pcr_ast", "elisa");
		if (in_array($legacyRpt, $allowedRpt, true)) {
			$legacyToTemplate = array(
				"All"             => "full",
				"microscopy"      => "microscopy",
				"bacteriology_ast" => "bacteriology",
				"pcr_ast"         => "pcr",
				"elisa"           => "elisa",
			);
			$templateId = $legacyToTemplate[$legacyRpt];
		}
	}

	/*
 * Surveillance / Outbreak exports must use the compact
 * Result Template for Outbreak/Group Report.
 */
if ($subjectType === '1' || $subjectType === '2') {

    $templateId = 'group_result';

} elseif (!isset($templates[$templateId])) {

    $templateId = 'full';
}

	$templateLabel    = bdsis_report_template_label($templateId);
	$templateSections = bdsis_report_template_sections($templateId);

	$requested = isset($_POST["sids"]) ? $_POST["sids"] : array();
	if (!is_array($requested)) {
		$requested = array($requested);
	}
	// Normalise: strings only, trimmed, no blanks, no duplicates.
	$clean = array();
	foreach ($requested as $one) {
		if (is_array($one)) { continue; }
		$one = trim((string) $one);
		if ($one !== '' && !in_array($one, $clean, true)) {
			$clean[] = $one;
		}
	}

	if (count($clean) === 0) {
		bdsis_group_report_stop(
			'No subjects selected',
			'Select at least one subject from the list before generating a group report.'
		);
	}

	/* ------------------------------------------------------------------
	   2. Access control -- re-derived server side, never taken from the post.
	   ------------------------------------------------------------------
	   report-pdf.php / report-view.php historically applied no per-subject
	   centre check. A group endpoint that accepts an arbitrary sids[] array
	   would make harvesting other centres' subjects trivial, so the posted ids
	   are treated purely as a wish list: they are intersected against the rows
	   the caller's own session is entitled to see.

	   Which rows those are is decided by bdsis_scope_clause(), the same helper the
	   listings and the exports use. This endpoint used to carry its own copy of the
	   rule (acl==1, or acl==2 && usercat==13, else own centre), which had already
	   drifted: it gave a district supervisor only their own facility instead of
	   their dzongkhag, and it demoted the MoH role to a single centre. One rule,
	   one place -- so a change to the role model cannot leave this endpoint behind.
	   ------------------------------------------------------------------ */
	$placeholders = implode(',', array_fill(0, count($clean), '?'));
	$sql = "SELECT subject_id
        FROM subject_information_table
        WHERE subject_id IN (" . $placeholders . ")";

if ($subjectType === '1') {

    $sql .= " AND subject_category = '1'";

} elseif ($subjectType === '2') {

    $sql .= " AND subject_category = '2'";
}

$sql .= bdsis_scope_clause($bdsis, 'center_id');

$sql .= " ORDER BY subject_id ASC";
	$types  = str_repeat('s', count($clean));
	$params = $clean;

	$permitted = array();
	$stmt = $bdsis->prepare($sql);
	if ($stmt) {
		$stmt->bind_param($types, ...$params);
		$stmt->execute();
		$res = $stmt->get_result();
		if ($res) {
			while (list($okSid) = $res->fetch_row()) {
				$permitted[] = $okSid;
			}
		}
		$stmt->close();
	}

	$droppedCount = count($clean) - count($permitted);

	if (count($permitted) === 0) {
		bdsis_group_report_stop(
			'Nothing to report',
			'None of the selected subjects are available to your account. '
			. 'They may have been removed, or they belong to another health centre.'
		);
	}
	/*
 * Load subject information required by the compact group result report.
 */
$groupSubjects = array();

$permittedPlaceholders = implode(
    ',',
    array_fill(0, count($permitted), '?')
);

/* CID No. (2026-10-07): optional alphanumeric identifier added as a new
   column in subject_information_table. Pulled only when the column probe
   confirms the ALTER has been applied, so a database without the
   migration still generates the PDF successfully -- just without the
   CID NO. column being populated. */
$hasCidCol = function_exists('bdsis_subject_cid_no_column_exists') && bdsis_subject_cid_no_column_exists($bdsis);
$cidSelect = $hasCidCol ? ",\n        cid_no" : '';
$subjectSql = "
    SELECT
        subject_id,
        subject_name,
        gender,
        age,
        center_id{$cidSelect}
    FROM subject_information_table
    WHERE subject_id IN ($permittedPlaceholders)
    ORDER BY subject_id ASC
";

$subjectStmt = $bdsis->prepare($subjectSql);

if ($subjectStmt) {

    $subjectTypes = str_repeat('s', count($permitted));

    $subjectStmt->bind_param(
        $subjectTypes,
        ...$permitted
    );

    $subjectStmt->execute();

    $subjectResult = $subjectStmt->get_result();

    if ($subjectResult) {

        while ($row = $subjectResult->fetch_assoc()) {

            $groupSubjects[] = array(
                'subject_id'   => $row['subject_id'],
                'subject_name' => $row['subject_name'],
                'gender'       => $row['gender'],
                'age'          => $row['age'],
                'center_id'    => $row['center_id'],
                'cid_no'       => isset($row['cid_no']) ? (string)$row['cid_no'] : '',
            );
        }
    }

    $subjectStmt->close();
}

	/* ------------------------------------------------------------------
	   3. Build one HTML document: styles once, then one chunk per subject.
	   ------------------------------------------------------------------
	   Chunks are JOINED with mPDF's native <pagebreak /> rather than having a
	   break appended to each -- appending would leave a trailing break and so a
	   blank final page. */
	$notice = '';
	if ($droppedCount > 0) {
		$lines = array();
		$lines[] = $droppedCount . ' selected subject(s) were left out because they are not available to your account.';
		$notice = '<div class="pdfNotice"><b>Note:</b> ' . implode(' ', array_map('htmlspecialchars', $lines)) . '</div>';
	}

	$chunks = array();
$logoHtml = bdsis_report_logo_html();

/*
 * Compact group/outbreak result report.
 */
if ($templateId === 'group_result') {

    $subjectTypeLabel = '';

    if (
        isset($_POST['subject_type']) &&
        (string)$_POST['subject_type'] === '1'
    ) {
        $subjectTypeLabel = 'Surveillance';

    } elseif (
        isset($_POST['subject_type']) &&
        (string)$_POST['subject_type'] === '2'
    ) {
        $subjectTypeLabel = 'Outbreak';
    }

    /* Logo and table in ONE chunk: chunks are joined with <pagebreak />, so a
       separate logo chunk put the logo alone on page 1 and the table on page 2. */
    $chunks[] = $logoHtml . bdsis_group_result_table_html(
        $groupSubjects,
        $bdsis,
        $subjectTypeLabel,
        $commondb
    );

} else {

    /*
     * Existing Full / Microscopy / Bacteriology /
     * PCR / ELISA reports remain unchanged.
     */
    foreach ($permitted as $oneSid) {

        $chunks[] = bdsis_build_report_html(
            $oneSid,
            $templateSections,
            $bdsis,
            $commondb,
            $logoHtml,
            false
        );
    }
}

	$html = bdsis_report_styles()
		. '<style type="text/css">.pdfNotice{font-size:11px;color:#8a6d3b;border:1px solid #8a6d3b;padding:6px;margin-bottom:8px;}</style>'
		. $notice
		. implode('<pagebreak />', $chunks);

    require_once($_SERVER["DOCUMENT_ROOT"]."/BDSIS/mpdf/vendor/autoload.php");
	$mpdf=new \Mpdf\Mpdf([
		'mode' => 'utf-8',
		'format' => 'A4',
		'default_font_size' => 11,
		'default_font' => '',
		'margin_left' => 15,
		'margin_right' => 15,
		'margin_top' => 20,
		'margin_bottom' => 20,
		'margin_header' => 10,
		'margin_footer' => 5,
		/* Landscape for the group table only (6 columns + one per antibiotic). */
		'orientation' => ($templateId === 'group_result') ? 'L' : 'P',
	]);
	/* Mirrored margins are deliberately OFF here (the single report keeps them
	   on). With mirroring, a subject whose section happens to start on an even
	   page gets its left/right margins swapped, so subjects in the same PDF
	   would not line up with each other. */
	$mpdf->mirrorMargins = 0;
	/* The running header names the template so a printed or forwarded copy
	   says which sections it does (and does not) contain. */
	$reportHeaderTitle = 'Stool Examination Result - ' . $templateLabel;

if ($templateId === 'group_result') {
    /* Client asked to remove the top text banner
       "BDSIS - Result Template for Outbreak/Group Report - <Type>" from every
       page of the Group Report PDF. Setting the header title to '' here (plus
       the SetHeader guard below) drops the running header entirely for this
       template only. The other templates (Full / Microscopy / Bacteriology /
       PCR / ELISA) keep their "Stool Examination Result - <label>" banner. */
    $reportHeaderTitle = '';
}

$header = array(
    'C' => array(
        'content' => $reportHeaderTitle,
        'font-size' => 12,
        'font-style' => 'B',
        'font-family' => 'arial',
        'color' => '#333333'
    )
);
	$footer = array (
		'C' => array (
		  'content' => '- {PAGENO} - | Bhutan Diarrheal Study Information System(BDSIS) | localhost',
		  'font-size' => 8,
		  'font-style' => '',
		  'font-family' => 'arial',
		  'color'=>'#333333'
		),
		'line' => 1,
	);

	$mpdf->SetTitle('BDSIS Result Group Report - ' . $templateLabel);
	/* SetHeader is skipped when the caller cleared the title (currently the
	   Group Report template). mPDF renders nothing in the header area in that
	   case, giving the requested clean top-of-page. */
	if ($reportHeaderTitle !== '') {
		$mpdf->SetHeader($header,'O');
		$mpdf->SetHeader($header,'E');
	}
	$mpdf->SetFooter($footer,'O');
	$mpdf->SetFooter($footer,'E');
	$mpdf->SetDisplayMode('fullpage');
	$mpdf->WriteHTML($html);
	/* Name the file after the template so several batches downloaded in a row
	   do not all land as the same filename. Ids are already url-safe; the
	   filter is belt-and-braces against a future id with punctuation in it. */
	$fileSlug = preg_replace('/[^A-Za-z0-9_]/', '', ucfirst($templateId));
	$mpdf->Output("BDSIS_Group_Report_" . $fileSlug . ".pdf", "I");
	exit;
?>
