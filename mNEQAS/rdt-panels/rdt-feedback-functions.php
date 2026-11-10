<?php
/**
 * rdt-feedback-functions.php
 * ---------------------------------------------------------------------------
 * Species-based default Finding / Recommendation for RDT Panel feedback
 * (feedbacks.php). Read-only: derives text from the panel's current RCDC
 * reference answers in rdt_panel_rcdc_results.rcdc_species. Scoring lives in
 * rdt-panel-functions.php and is not touched here.
 *
 *   Pf        -> PF Detected
 *   Pv        -> PV Detected
 *   F+V       -> PF/PV Detected
 *   Pf + Pv   -> PF/PV Detected   (several slides, each species counted once)
 *   Negative  -> no positive-detection finding
 * ---------------------------------------------------------------------------
 */

if (!function_exists('rdtPanelRcdcSpecies')) {

/** Distinct RCDC reference species recorded for every slide of the panel. */
function rdtPanelRcdcSpecies($conn, $panelId){
    $stmt = $conn->prepare("SELECT DISTINCT r.rcdc_species
                              FROM rdt_panel_rcdc_results r
                              INNER JOIN rdt_panel_slide_informations s ON s.slide_id = r.slide_id
                             WHERE s.panel_id = ?");
    if (!$stmt) { return array(); }
    $pid = (int)$panelId;
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = array();
    while ($row = $res->fetch_row()) {
        if ($row[0] !== null && trim($row[0]) !== '') { $out[] = trim($row[0]); }
    }
    $stmt->close();
    return $out;
}

/** "PF Detected" / "PV Detected" / "PF/PV Detected", or '' when nothing positive. */
function rdtSpeciesDetectedLabel(array $speciesValues){
    $pf = $pv = false;
    foreach ($speciesValues as $sp) {
        $k = strtoupper(preg_replace('/\s+/', '', (string)$sp));
        if ($k === 'PF')                        { $pf = true; }
        elseif ($k === 'PV')                    { $pv = true; }
        elseif ($k === 'F+V' || $k === 'PF+PV') { $pf = true; $pv = true; }
    }
    if ($pf && $pv) { return 'PF/PV Detected'; }
    if ($pf)        { return 'PF Detected'; }
    if ($pv)        { return 'PV Detected'; }
    return '';
}

/** Findings paragraph (same <p><b> style as the other generated headings). */
function rdtSpeciesFindingHtml($label){
    return $label === '' ? '' : '<p><b>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b></p>';
}

/** Default Recommendation for the generated species finding. */
function rdtSpeciesRecommendationHtml($label){
    $map = array(
        'PF Detected'    => 'PF Detected: Ensure malaria RDT results positive for <i>Plasmodium falciparum</i> (PF) are read within the recommended reading time and reported as PF, as per the national malaria RDT SOP.',
        'PV Detected'    => 'PV Detected: Ensure malaria RDT results positive for <i>Plasmodium vivax</i> (PV) are read within the recommended reading time and reported as PV, as per the national malaria RDT SOP.',
        'PF/PV Detected' => 'PF/PV Detected: Ensure malaria RDT results are correctly interpreted and reported by species (PF, PV and mixed PF/PV infection) within the recommended reading time, as per the national malaria RDT SOP.',
    );
    return isset($map[$label]) ? '<p>' . $map[$label] . '</p>' : '';
}

/** True when a Summernote value holds no visible text (e.g. '', '<p><br></p>'). */
function rdtHtmlIsBlank($html){
    $txt = str_replace(array('&nbsp;', "\xC2\xA0"), ' ', strip_tags((string)$html));
    return trim($txt) === '';
}

}
