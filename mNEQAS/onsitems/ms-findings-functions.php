<?php
/* =====================================================================
   M&S findings — the single source of truth for turning a checklist item
   rated "No" into a professional negative finding.

   Used by:
     - ms-registration.php (data-finding attribute read by the Recommendation
       auto-findings script)
     - pdf-ms-registration.php (rebuilds the findings block from the current
       ratings so the PDF never shows stale wording)

   The checklist master text is never modified; it is only the input.
   Resolution order:
     1. A curated per-item wording stored in ms_onsite_checklists.findings,
        when that column exists and holds real finding text (not a copy of
        the statement and not a "No, ..." prefix).
     2. The curated wording below, keyed on normalised statement text (not
        IDs, so edited/renumbered checklists still match).
     3. Sentence-pattern rules for any other statement.
   ===================================================================== */

if (!function_exists('msFindingKey')) {

/** Normalise statement text for matching: lower case, letters/digits only. */
function msFindingKey($s){
    $s = msFindingCleanSource($s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Tidy the raw checklist statement (stray "\r\n"/"rn" artefacts, spacing, end punctuation). */
function msFindingCleanSource($s){
    $s = (string)$s;
    $s = str_replace(array("\r\n", "\n", "\r", '\\r\\n'), ' ', $s);
    $s = preg_replace('/:\s*rn(?=[A-Z])/', ': ', $s);   // "maintained:rnGoods" import artefact
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/** Curated professional wording for the known checklist items. */
function msFindingCuratedMap(){
    static $map = null;
    if ($map !== null) { return $map; }
    $pairs = array(
        // Organization and Management
        'Laboratory has organization and management structure' => 'No organization and management structure is maintained in the laboratory.',
        'Laboratory conducts staff coordination meeting' => 'No staff coordination meeting is conducted by the laboratory.',
        'Laboratory has maintained and compiled minutes of meeting' => 'The laboratory does not maintain and compile minutes of meeting.',
        'Laboratory has appropriate platform for information sharing' => 'No appropriate platform for information sharing is available in the laboratory.',
        'Laboratory conducts internal audit on malaria diagnosis' => 'No internal audit on malaria diagnosis is conducted by the laboratory.',
        'Laboratory has maintained the internal audit record file' => 'The internal audit record file is not maintained by the laboratory.',
        'Laboratory conducts and organizes CME on malaria diagnosis' => 'The laboratory does not conduct and organize CME on malaria diagnosis.',
        'Laboratory maintained record on CME conducted' => 'No record of CME conducted is maintained by the laboratory.',
        // Laboratory Design
        'Laboratory has adequate working space' => 'Adequate working space is not available in the laboratory.',
        'Washbasin / sink' => 'No washbasin / sink is available in the laboratory.',
        'Designated area for staining of malaria slides' => 'No designated area for staining of malaria slides is available in the laboratory.',
        'Storage space for supplies and materials' => 'No storage space for supplies and materials is available in the laboratory.',
        'Adequate furnitures, A/C & fans, computers with internet connection' => 'Adequate furniture, A/C and fans, and computers with internet connection are not available in the laboratory.',
        'A/C & fans available in lab' => 'No A/C or fans are available in the laboratory.',
        // Microscope
        'Is there functional microscope available in laboratory' => 'No functional microscope is available in the laboratory.',
        'No of functional microscope in laboratory' => 'The number of functional microscopes in the laboratory is not adequate.',
        'Proper set up of the microscope (stable bench away from staining area and vibration producing equipment)' => 'The microscope is not properly set up (stable bench away from the staining area and vibration-producing equipment).',
        'The microscope lamp has sufficient power to provide good illumination when set at x100 objective' => 'The microscope lamp does not provide sufficient illumination at the x100 objective.',
        'Updated maintenance logbook for microscope' => 'No updated maintenance logbook is maintained for the microscope.',
        // Microscope slides
        'Microscope slides are of free from scratches or surface aberrations' => 'Microscope slides have scratches or surface aberrations.',
        'Transparent with adequate thickness' => 'Microscope slides are not transparent or do not have adequate thickness.',
        'Microscopes slides do not have fungal contamination' => 'Microscope slides have fungal contamination.',
        'In areas with high humidity, microscope slides are protected against fungal contamination' => 'In areas with high humidity, microscope slides are not protected against fungal contamination.',
        'Microscope slides are cleaned before use' => 'Microscope slides are not cleaned before use.',
        // Staining reagents
        'All required staining reagent are available Giemsa stain & Buffered water / Distilled water' => 'Not all required staining reagents (Giemsa stain and buffered water / distilled water) are available.',
        'All staining reagents are within the recommended expiry date' => 'Not all staining reagents are within the recommended expiry date.',
        "Staining solutions are stored as per the manufacturer's recommendation / SOP" => "Staining solutions are not stored as per the manufacturer's recommendation / SOP.",
        'SOPs are available for preparation of working stain solution' => 'SOPs for preparation of working stain solution are not available.',
        'Laboratory performed quality control on giemsa stain' => 'Quality control on Giemsa stain is not performed by the laboratory.',
        'Corrective action against staining troubleshoots' => 'No corrective action is taken against staining problems.',
        // Lab consumables
        'Laboratory inventory maintained: Goods received and use of lab consumables' => 'The laboratory inventory of goods received and use of lab consumables is not maintained.',
        'Analytical grade with acetone free' => 'Methanol of analytical grade and free from acetone is not available.',
        'Supplied of good quality recommended for microscopy' => 'Immersion oil of the recommended quality is not available for microscopy.',
        'Gauze / tissues for cleaning slides' => 'No gauze / tissues for cleaning slides are available.',
        'Lens cleaning paper' => 'No lens cleaning paper is available.',
        'Staining rack / Suitable staining jar' => 'No staining rack / suitable staining jar is available for microscopy procedures.',
        'Drying rack' => 'No drying rack is available for microscopy procedures.',
        'Timer' => 'No timer is available for microscopy procedures.',
        'Slides boxes for storage' => 'No slide boxes are available for slide storage.',
        'Reagent bottles' => 'No reagent bottles are available for microscopy procedures.',
        'pH meter / litmus paper' => 'No pH meter / litmus paper is available for microscopy procedures.',
        'Tally counter' => 'No tally counter is available for microscopy procedures.',
        'Filter paper' => 'No filter paper is available for microscopy procedures.',
        'indicator' => 'No indicator is available/identified as required.',
        // Malaria RDTs / temperature
        'Lab performed QC on malaria RDT' => 'QC on malaria RDT is not performed by the laboratory.',
        'Laboratory maintained room temperature log' => 'No room temperature log is maintained by the laboratory.',
        'All the temperature log is daily recorded' => 'The temperature log is not recorded daily.',
        'Documentation of daily temperature monitoring of refrigerators (if using) and testing area available' => 'Documentation of daily temperature monitoring of refrigerators (if in use) and the testing area is not available.',
        // Internal Quality Control
        'In-house SOP for malaria diagnosis is available in lab' => 'The in-house SOP for malaria diagnosis is not available in the laboratory.',
        'SOP for malaria diagnosis is available in lab' => 'The SOP for malaria diagnosis is not available in the laboratory.',
        'SOPs is followed strictly and implemented' => 'SOPs are not strictly followed and implemented.',
        'Prepared giemsa working solution is filtered before staining' => 'Prepared Giemsa working solution is not filtered before staining.',
        'Freshly prepared giemsa working solution is used within 4 hr' => 'Freshly prepared Giemsa working solution is not used within 4 hours.',
        'Buffered water / Distilled water pH 7.2 (+- 0.2) used to dilute the giemsa stain' => 'Buffered water / distilled water of pH 7.2 (±0.2) is not used to dilute the Giemsa stain.',
        'Giemsa working solution prepared in correct dilution' => 'Giemsa working solution is not prepared in the correct dilution.',
        'Performed IQC on Giemsa stain as per SOP' => 'IQC on Giemsa stain is not performed as per SOP.',
        'Adequate size (1 cm diameter) in circular shape' => 'Blood films are not of adequate size (1 cm diameter) and circular shape.',
        'Thickness of the smear (news paper readable) is correct' => 'The thickness of the smear (newspaper readable) is not correct.',
        'Standard labelling of slide' => 'Slides are not labelled as per the standard.',
        'Smears are not washed off' => 'Smears are washed off.',
        'Shape of thin smear (Tongue shape)' => 'The thin smear is not tongue-shaped.',
        'Length of the thin smear (2 -3 cm)' => 'The length of the thin smear is not within 2-3 cm.',
        'Examine a minimum of 10 minutes per slide or 100 fields before reporting negative' => 'Slides are not examined for a minimum of 10 minutes or 100 fields before reporting negative.',
        'Do you report the MP result in species?' => 'The MP result is not reported by species.',
        "Do you report MP result in parasite's stage?" => 'The MP result is not reported by parasite stage.',
        'Do you report the parasites count in the result?' => 'The parasite count is not reported in the result.',
        'Are examined slides stored and archived properly' => 'Examined slides are not stored and archived properly.',
        // External Quality Assessment Scheme
        'Does the laboratory timely submit the slides for blinded cross checking?' => 'Slides are not timely submitted for blinded cross-checking.',
        'Does laboratory participate in malaria panel testing scheme?' => 'The laboratory does not participate in the malaria panel testing scheme.',
        'Corrective action based on the blinded rechecking feedback report' => 'No corrective action is taken based on the blinded rechecking feedback report.',
        'Corrective action based on the Panel testing feedback report' => 'No corrective action is taken based on the panel testing feedback report.',
        'Corrective action based on the On-site monitoring and supervision feedback report' => 'No corrective action is taken based on the on-site monitoring and supervision feedback report.',
        // Documentation
        'Technical manuals and bench aids available in the laboratory' => 'Technical manuals and bench aids are not available in the laboratory.',
        'Are equipment maintenance logbook available in the laboratory' => 'Equipment maintenance logbooks are not available in the laboratory.',
        'Are internal QC log sheet available in the laboratory' => 'Internal QC log sheets are not available in the laboratory.',
        'Are blinded rechecking feedback report documented and filed?' => 'Blinded rechecking feedback reports are not documented and filed.',
        'Are panel testing feedback report documented and filed?' => 'Panel testing feedback reports are not documented and filed.',
        'Are onsite monitoring and supervision feedback report documented and filed?' => 'Onsite monitoring and supervision feedback reports are not documented and filed.',
        'Corrective action performed and filed' => 'Corrective actions are not performed and filed.',
        'Test kit / leaflet documented and filed' => 'Test kit / leaflet information is not documented and filed.',
        // Infection Control and Waste Management
        'Is national guideline available for infection control?' => 'The national guideline for infection control is not available.',
        'Posters or signs to discourage people entering the laboratory in place' => 'Posters or signs to discourage people from entering the laboratory are not in place.',
        'Is there a hand washing poster in front of the washbasin?' => 'There is no hand washing poster in front of the washbasin.',
        'infectious and non-infectious waste clearly labelled' => 'Infectious and non-infectious waste is not clearly labelled.',
        'Availability of soap / alcohol hand rub in all appropriate place' => 'Soap / alcohol hand rub is not available in all appropriate places.',
        'Decontamination log for each working area' => 'No decontamination log is maintained for each working area.',
        'Minimum personal protective equipment available and used' => 'Minimum personal protective equipment is not adequately available and used.',
        'Colour coded bins for Sharps (puncture resistant)' => 'No colour-coded bins for sharps (puncture resistant) are available.',
        'Colour coded bins for infectious wastes (Red)' => 'No colour-coded bins for infectious waste (red) are available.',
        'Colour coded bins for non-infectious (Green)' => 'No colour-coded bins for non-infectious waste (green) are available.',
        'Appropriate concentration of decontamination solution used' => 'An appropriate concentration of decontamination solution is not used.',
    );
    $map = array();
    foreach ($pairs as $statement => $finding) { $map[msFindingKey($statement)] = $finding; }
    return $map;
}

/** Keep a leading acronym/technical token as-is, otherwise lower-case the first letter. */
function msFindingLcFirst($s){
    $first = strtok($s, ' ');
    if ($first !== false && (preg_match('/[A-Z].*[A-Z]|\d|\//', $first) || preg_match('/^p[A-Z]/', $first))) { return $s; }
    return lcfirst($s);
}

/** Rough plural test on the head noun (text before the first preposition). */
function msFindingIsPlural($phrase){
    $p = strtolower(trim(preg_replace('/\([^)]*\)/', '', $phrase)));
    $p = preg_split('/\s+(?:for|of|in|on|at|with|to|from|by|as)\s+/', $p)[0];
    $p = trim($p);
    if (preg_match('/\s(and|&|or)\s|,/', $p)) { return true; }
    $words = preg_split('/\s+/', $p);
    $last  = end($words);
    return (bool)preg_match('/[a-z]{2,}s$/i', $last) && !preg_match('/(ss|us|is)$/', $last);
}

/** Third-person singular verb -> base form (conducts -> conduct, organizes -> organize). */
function msFindingBaseVerb($v){
    if (preg_match('/[^aeiou]ies$/', $v)) { return substr($v, 0, -3) . 'y'; }
    if (preg_match('/(ss|sh|ch|x|zz)es$/', $v)) { return substr($v, 0, -2); }
    if (preg_match('/s$/', $v) && !preg_match('/ss$/', $v)) { return substr($v, 0, -1); }
    return $v;
}

/** Past participles the rules recognise in passive checklist statements. */
function msFindingParticiples(){
    return 'documented|filed|stored|archived|maintained|recorded|labelled|labeled|performed|used|followed|implemented|'
         . 'cleaned|protected|submitted|reported|conducted|displayed|kept|prepared|filtered|calibrated|updated|checked|'
         . 'verified|reviewed|placed|posted|trained|segregated|disposed|decontaminated|compiled|organized|organised|'
         . 'monitored|examined|validated|installed|taken|done|provided|supplied|practised|practiced|worn|applied';
}

/** Pluralise the last word of a noun phrase (log sheet -> log sheets). */
function msFindingPluralLast($phrase){
    return preg_replace_callback('/([A-Za-z]+)(\s*)$/', function($m){
        $w = $m[1];
        if (preg_match('/s$/i', $w)) { return $w . $m[2]; }
        if (preg_match('/[^aeiou]y$/i', $w)) { return substr($w, 0, -1) . 'ies' . $m[2]; }
        return $w . 's' . $m[2];
    }, $phrase, 1);
}

/** Normalise location wording ("in lab" -> "in the laboratory"). */
function msFindingPlace($s){
    $s = preg_replace('/\bin (the )?lab\b(?!oratory)/i', 'in the laboratory', $s);
    $s = preg_replace('/\bin laboratory\b/i', 'in the laboratory', $s);
    return $s;
}

/** Sentence-pattern rules for statements that are not in the curated list. */
function msFindingByRules($src, $subcategory = '', $category = ''){
    $s = rtrim($src, " .?!");
    $s = msFindingPlace($s);

    // --- Questions -> declarative
    if (preg_match('/^is there (?:an? )?(.+?) available(?: (in|at|for) (.+))?$/i', $s, $m)) {
        return 'No ' . msFindingLcFirst($m[1]) . ' is available' . (isset($m[2]) && $m[2] !== '' ? ' ' . $m[2] . ' ' . $m[3] : '') . '.';
    }
    if (preg_match('/^is there (?:an? )?(.+)$/i', $s, $m)) {
        return 'There is no ' . msFindingLcFirst($m[1]) . '.';
    }
    if (preg_match('/^is (?:the )?(.+?) available(?: (.+))?$/i', $s, $m)) {
        return 'The ' . msFindingLcFirst($m[1]) . (isset($m[2]) ? ' ' . $m[2] : '') . ' is not available.';
    }
    if (preg_match('/^are (.+?) available(.*)$/i', $s, $m)) {
        return ucfirst(msFindingPluralLast($m[1])) . ' are not available' . $m[2] . '.';
    }
    $pp = msFindingParticiples();
    if (preg_match('/^are (.+?) ((?:' . $pp . ')(?: and (?:' . $pp . '))?)\b(.*)$/i', $s, $m)) {
        return ucfirst(msFindingPluralLast($m[1])) . ' are not ' . $m[2] . $m[3] . '.';
    }
    if (preg_match('/^do you report (?:the )?(.+?) (in|by) (the )?(.+)$/i', $s, $m)) {
        $what = preg_replace("/'s\b/", '', $m[4]);
        return 'The ' . msFindingLcFirst($m[1]) . ' is not reported ' . ($m[3] ? 'in the ' : 'by ') . $what . '.';
    }
    if (preg_match('/^does (?:the )?(?:lab|laboratory) (.+)$/i', $s, $m)) {
        return 'The laboratory does not ' . $m[1] . '.';
    }
    if (preg_match('/^(?:do|does|is|are|can|has|have) /i', $s)) {
        return 'The following requirement was not met: ' . ucfirst($s) . '.';
    }

    // --- Already-negative statements -> positive (avoid double negatives)
    if (preg_match('/^(.+?) (?:is|are) (?:of )?free from (.+)$/i', $s, $m)) {
        return ucfirst($m[1]) . ' ' . (msFindingIsPlural($m[1]) ? 'have ' : 'has ') . $m[2] . '.';
    }
    if (preg_match('/^(.+?) (do|does) not (have|contain|show) (.+)$/i', $s, $m)) {
        $verb = (strtolower($m[2]) === 'does') ? ($m[3] === 'have' ? 'has' : $m[3] . 's') : $m[3];
        return ucfirst($m[1]) . ' ' . $verb . ' ' . $m[4] . '.';
    }
    if (preg_match('/^(.+?) (is|are) not (.+)$/i', $s, $m)) {
        return ucfirst($m[1]) . ' ' . $m[2] . ' ' . $m[3] . '.';
    }
    if (preg_match('/^(.+?) (?:without|free of) (.+)$/i', $s, $m)) {
        return 'The following requirement was not met: ' . ucfirst($s) . '.';
    }

    // --- Laboratory as subject
    if (preg_match('/^(?:the )?(?:lab|laboratory) has ([a-z]+ed(?: and [a-z]+ed)?) (.+)$/i', $s, $m)) {
        return 'The laboratory has not ' . $m[1] . ' ' . $m[2] . '.';
    }
    if (preg_match('/^(?:the )?(?:lab|laboratory) (?:has|have) (.+)$/i', $s, $m)) {
        return 'The laboratory does not have ' . $m[1] . '.';
    }
    if (preg_match('/^(?:the )?(?:lab|laboratory) ([a-z]+ed)(?: and ([a-z]+ed))? (.+)$/i', $s, $m)) {
        return 'The laboratory has not ' . $m[1] . (!empty($m[2]) ? ' and ' . $m[2] : '') . ' ' . $m[3] . '.';
    }
    if (preg_match('/^(?:the )?(?:lab|laboratory) ([a-z]+s)(?: and ([a-z]+s))? (.+)$/i', $s, $m)) {
        return 'The laboratory does not ' . msFindingBaseVerb($m[1]) . (!empty($m[2]) ? ' and ' . msFindingBaseVerb($m[2]) : '') . ' ' . $m[3] . '.';
    }

    // --- Any other subject with has/have (the microscope lamp has ...)
    if (preg_match('/^(.+?) (has|have) (?!been\b)(.+)$/i', $s, $m) && !preg_match('/\b(is|are)\b/i', $m[1])) {
        return ucfirst($m[1]) . (strtolower($m[2]) === 'has' ? ' does' : ' do') . ' not have ' . $m[3] . '.';
    }

    // --- "Corrective action ..." items
    if (preg_match('/^corrective actions? (?:based on|against|for|on) (.+)$/i', $s, $m)) {
        return 'No corrective action is taken based on ' . msFindingLcFirst($m[1]) . '.';
    }

    // --- "No of X" count items
    if (preg_match('/^no\.? of (.+)$/i', $s, $m)) {
        return 'The number of ' . msFindingLcFirst(msFindingPluralLast(preg_replace('/ in (the )?laboratory$/i', '', $m[1])))
             . (preg_match('/ in (the )?laboratory$/i', $m[1]) ? ' in the laboratory' : '') . ' is not adequate.';
    }

    // --- "All X is/are ..." -> "Not all X are ..." / "The X is not ..."
    if (preg_match('/^all (the )?(.+?) (is) (.+)$/i', $s, $m)) {
        return 'The ' . msFindingLcFirst($m[2]) . ' is not ' . $m[4] . '.';
    }
    if (preg_match('/^all (.+?) (are) (.+)$/i', $s, $m)) {
        return 'Not all ' . msFindingLcFirst($m[1]) . ' are ' . $m[3] . '.';
    }

    // --- Availability / passive statements with an explicit is/are
    if (preg_match('/^(.+?) (is|are) (.+)$/i', $s, $m)) {
        $verb = msFindingIsPlural($m[1]) ? 'are' : strtolower($m[2]);
        return ucfirst($m[1]) . ' ' . $verb . ' not ' . $m[3] . '.';
    }

    // --- Statements with the verb dropped (X available / X labelled / X in place)
    $adv = '(?:clearly|properly|daily|strictly|regularly|timely|adequately|correctly) ';
    if (preg_match('/^(.+?) ((?:' . $adv . ')?(?:available|in place|' . $pp . ')(?: and (?:' . $pp . '))?)\b(.*)$/i', $s, $m)
        && !preg_match('/^(?:' . $pp . ')$/i', strtok($s, ' '))) {
        return ucfirst($m[1]) . (msFindingIsPlural($m[1]) ? ' are' : ' is') . ' not ' . $m[2] . $m[3] . '.';
    }

    // --- Leading participle (Performed IQC on Giemsa stain as per SOP)
    if (preg_match('/^(' . $pp . ') (?!(?:of|with|for|in|on|at|by|to|from)\b)(.+?)( as per .+| according to .+| before .+| after .+)?$/i', $s, $m)) {
        return ucfirst($m[2]) . (msFindingIsPlural($m[2]) ? ' are' : ' is') . ' not ' . strtolower($m[1]) . (isset($m[3]) ? $m[3] : '') . '.';
    }

    // --- Imperative instructions (Examine a minimum of 10 minutes ...)
    if (preg_match('/^(examine|use|record|maintain|perform|check|ensure|keep|store|label|report|prepare|clean|dispose|wear|follow)\b (.+)$/i', $s, $m)) {
        return 'The requirement to ' . strtolower($m[1]) . ' ' . $m[2] . ' is not met.';
    }

    // --- "Availability of X" items
    if (preg_match('/^availability of (.+?)( in .+)?$/i', $s, $m)) {
        return ucfirst($m[1]) . (msFindingIsPlural($m[1]) ? ' are' : ' is') . ' not available' . (isset($m[2]) ? $m[2] : '') . '.';
    }

    // --- Quality attributes (size / shape / thickness / set up / labelling ...)
    if (preg_match('/^(?:(?:proper|standard|correct|adequate|appropriate)\s+)?(?:size|shape|length|thickness|set ?up|labell?ing|concentration|dilution|volume|quality)\b/i', $s)
        || preg_match('/^(?:size|shape|length|thickness|set ?up|labell?ing|concentration|dilution|volume|quality) of\b/i', $s)
        || !preg_match('/[a-z]{3,}/i', preg_replace('/\([^)]*\)/', '', $s))) {
        return 'The requirement for ' . msFindingLcFirst($s) . ' is not met.';
    }

    // --- Noun fragments (equipment / consumables / infrastructure items)
    $words = preg_split('/\s+/', preg_replace('/\([^)]*\)/', '', $s));
    if (preg_match('/^(?:adequate|sufficient|enough)\s+(?!with\b)/i', $s) && !preg_match('/\b(is|are|has|have|not)\b/i', $s)) {
        return ucfirst($s) . (msFindingIsPlural($s) ? ' are' : ' is') . ' not available'
             . (preg_match('/design|infrastructure|facilit/i', $subcategory . ' ' . $category) ? ' in the laboratory' : '') . '.';
    }
    if (count($words) <= 10 && !preg_match('/\b(is|are|has|have|was|were|do|does|did|not)\b/i', $s)) {
        $ctx = strtolower($subcategory . ' ' . $category);
        $tail = ' is available';
        if (msFindingIsPlural($s)) { $tail = ' are available'; }
        if (strpos($ctx, 'staining') !== false || strpos($ctx, 'microscop') !== false) {
            $tail .= ' for microscopy procedures';
        } elseif (strpos($ctx, 'design') !== false || strpos($ctx, 'infrastructure') !== false || strpos($ctx, 'facilit') !== false) {
            $tail .= ' in the laboratory';
        }
        return 'No ' . msFindingLcFirst($s) . $tail . '.';
    }

    // --- Last resort: never echo the positive statement as if it were a finding
    return 'The following requirement was not met: ' . ucfirst($s) . '.';
}

/** Final tidy: one space, capital first letter, exactly one closing full stop. */
function msFindingFormat($s){
    $s = strip_tags((string)$s);
    $s = preg_replace('/\s+/', ' ', trim($s));
    $s = preg_replace('/\s+([,.;:])/', '$1', $s);
    $s = preg_replace('/,{2,}/', ',', $s);
    $s = rtrim($s, " .");
    if ($s === '') { return ''; }
    // keep technical terms in their standard form
    $terms = array('giemsa' => 'Giemsa', 'rdts' => 'RDTs', 'rdt' => 'RDT', 'sops' => 'SOPs', 'sop' => 'SOP', 'iqc' => 'IQC',
                   'qc' => 'QC', 'cme' => 'CME', 'ppe' => 'PPE', 'eqa' => 'EQA', 'eqas' => 'EQAS');
    foreach ($terms as $low => $std) { $s = preg_replace('/\b' . $low . '\b/i', $std, $s); }
    if (!preg_match('/^[a-z][A-Z]/', $s)) { $s = ucfirst($s); }   // keep "pH ..." as written
    return $s . '.';
}

/**
 * Professional negative finding for a checklist item rated "No".
 * $stored is ms_onsite_checklists.findings when that column exists (optional).
 */
function msNegativeFinding($statement, $subcategory = '', $category = '', $stored = null){
    $src = msFindingCleanSource($statement);
    if ($src === '') { return ''; }
    $key = msFindingKey($src);

    // 1. explicit per-item wording kept in the database, if it is a real finding
    if ($stored !== null) {
        $st = trim(strip_tags((string)$stored));
        if ($st !== '' && msFindingKey($st) !== $key && !preg_match('/^no\s*,/i', $st)) {
            return msFindingFormat($st);
        }
    }
    // 2. curated wording
    $map = msFindingCuratedMap();
    if (isset($map[$key])) { return $map[$key]; }
    // 3. rules
    return msFindingFormat(msFindingByRules($src, $subcategory, $category));
}

/**
 * HTML block of findings for the Recommendation field — the same markup and
 * markers that ms-registration.php's auto-findings script writes, so the page
 * and the PDF always agree. $findings = list of finding strings.
 */
function msFindingsBlockHtml($findings){
    $findings = array_values(array_unique(array_filter(array_map('strval', $findings), 'strlen')));
    if (!count($findings)) { return ''; }
    $html = '<!--MS_FINDINGS_START-->'
          . '<hr style="margin:10px 0 8px;border:0;border-top:1px solid #dee2e6;">'
          . '<p style="margin:0 0 4px;"><strong>Findings (items rated No):</strong></p>'
          . '<ul style="margin:0 0 0 18px;padding:0;">';
    foreach ($findings as $f) {
        $html .= '<li style="margin:0 0 3px;">' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    return $html . '</ul><!--MS_FINDINGS_END-->';
}

/**
 * Replace the auto-generated findings block inside a saved recommendation with
 * one built from the current "No" ratings — the server-side twin of
 * updateRecommendationFindings() in ms-registration.php. Text the evaluator
 * wrote outside the markers is kept exactly; with no "No" items the block is
 * removed.
 */
function msApplyFindingsBlock($recommendation, $findings){
    $html  = (string)$recommendation;
    $start = strpos($html, '<!--MS_FINDINGS_START-->');
    $end   = strpos($html, '<!--MS_FINDINGS_END-->');
    if ($start !== false && $end !== false && $end > $start) {
        $html = substr($html, 0, $start) . substr($html, $end + strlen('<!--MS_FINDINGS_END-->'));
    }
    $html = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);
    $html = trim(preg_replace('#(<br\s*/?>\s*)+$#i', '', $html));
    return $html . msFindingsBlockHtml($findings);
}

/**
 * Remove the auto-generated findings block from a saved recommendation, leaving
 * only what the evaluator typed. Findings now live in their own column, but
 * records saved before that change still carry the block inside recommendation.
 */
function msStripFindingsBlock($recommendation){
    $html  = (string)$recommendation;
    $start = strpos($html, '<!--MS_FINDINGS_START-->');
    $end   = strpos($html, '<!--MS_FINDINGS_END-->');
    if ($start !== false && $end !== false && $end > $start) {
        $html = substr($html, 0, $start) . substr($html, $end + strlen('<!--MS_FINDINGS_END-->'));
    }
    $html = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);
    return trim(preg_replace('#(<br\s*/?>\s*)+$#i', '', $html));
}

}
