<?php
/**
 * Shared per-subject report HTML builder.
 *
 * Extracted from reports/report-pdf.php so that the single-subject report and
 * the multi-subject group report (reports/report-group-pdf.php) render from one
 * source of truth. The markup produced here is intentionally identical to what
 * report-pdf.php emitted before the extraction.
 *
 * Included by reports/report-pdf.php and reports/report-group-pdf.php.
 */

// Self-sufficient: the ELISA section resolves rotavirus genotype ids through
// bdsis_rotavirus_genotype_label(). report-group-pdf.php does not pull the
// helpers in itself, so this file must not rely on its caller having done so.
require_once(__DIR__ . "/../includes/lab-helpers.php");

/* ----------------------------------------------------------------------
   Predefined report templates.
   ----------------------------------------------------------------------
   A template is a NAMED SET of sections, which is what separates it from
   the legacy $rpt string (one section, or the magic value "All"). Defined
   here -- next to the code that consumes them -- so the selector on
   report-management-individual.php and the validation in
   report-group-pdf.php can never drift apart from what the builder
   actually renders.

   Section keys are the four legacy $rpt values; they are also the keys the
   builder gates on, so adding a section to a template is a one-line
   change. Template ids are stable and url-safe: they are posted by the
   form and may end up in a filename. */

if (!function_exists('bdsis_report_sections')) {
    /**
     * Every renderable section key, in the order the builder emits them.
     *
     * @return array
     */
    function bdsis_report_sections()
    {
        return array('microscopy', 'bacteriology_ast', 'pcr_ast', 'elisa');
    }
}

if (!function_exists('bdsis_report_templates')) {
    /**
     * The predefined report templates.
     *
     * @return array id => array('label' => string, 'sections' => array)
     */
    function bdsis_report_templates()
    {
        return array(
            'full' => array(
                'label'    => 'Full Laboratory Report',
                // Deliberately the same four sections the legacy "All" resolves
                // to, so "All" and "full" are indistinguishable in the output.
                'sections' => bdsis_report_sections(),
            ),
            'microscopy' => array(
                'label'    => 'Stool Microscopy Only',
                'sections' => array('microscopy'),
            ),
            'bacteriology' => array(
                'label'    => 'Bacteriology & AST Only',
                'sections' => array('bacteriology_ast'),
            ),
            'virology' => array(
                'label'    => 'Virology (PCR & ELISA)',
                'sections' => array('pcr_ast', 'elisa'),
            ),
            'pcr' => array(
                'label'    => 'PCR & AST Only',
                'sections' => array('pcr_ast'),
            ),
            'elisa' => array(
                'label'    => 'ELISA Only',
                'sections' => array('elisa'),
            ),
            'group_result' => array(
                'label'    => 'Result Template for Outbreak/Group Report',
                'sections' => array(
                    'group_result'
               ),
            ),
        );
    }
}

if (!function_exists('bdsis_report_template_sections')) {
    /**
     * Section set for a template id.
     *
     * Unknown ids fall back to the full template rather than to an empty
     * set: a report missing every section reads as a broken download, and
     * this function is reachable from posted input.
     *
     * @param  string $id
     * @return array
     */
    function bdsis_report_template_sections($id)
    {
        $templates = bdsis_report_templates();

        return isset($templates[$id])
            ? $templates[$id]['sections']
            : $templates['full']['sections'];
    }
}

if (!function_exists('bdsis_report_template_label')) {
    /**
     * Human-readable label for a template id (falls back to the full label).
     *
     * @param  string $id
     * @return string
     */
    function bdsis_report_template_label($id)
    {
        $templates = bdsis_report_templates();

        return isset($templates[$id])
            ? $templates[$id]['label']
            : $templates['full']['label'];
    }
}

if (!function_exists('bdsis_report_resolve_sections')) {
    /**
     * Normalise whatever the caller passed as $rpt into a set of section keys.
     *
     * Accepts, in this order of precedence:
     *   - an array of section keys              (the template-aware path)
     *   - the legacy string "All"               -> all four sections
     *   - a legacy single-section string        -> that one section
     *   - a template id                         -> that template's sections
     *   - anything else                         -> all four sections
     *
     * The legacy strings are checked before template ids so that the five
     * values report-pdf.php and report-view.php pass keep their exact
     * historical meaning. This matters for "microscopy" and "elisa", which
     * are BOTH legacy section keys and template ids -- they happen to
     * resolve to the same single-section set either way, but the ordering
     * makes that a guarantee rather than a coincidence.
     *
     * @param  mixed $rpt
     * @return array section keys
     */
    function bdsis_report_resolve_sections($rpt)
    {
        $all = bdsis_report_sections();

        if (is_array($rpt)) {
            $picked = array();
            foreach ($all as $section) {
                // Intersect rather than trust: preserves emission order and
                // silently drops anything that is not a real section.
                if (in_array($section, $rpt, true)) {
                    $picked[] = $section;
                }
            }

            return count($picked) > 0 ? $picked : $all;
        }

        $rpt = (string) $rpt;

        if ($rpt === 'All') {
            return $all;
        }
        if (in_array($rpt, $all, true)) {
            return array($rpt);
        }

        $templates = bdsis_report_templates();
        if (isset($templates[$rpt])) {
            return $templates[$rpt]['sections'];
        }

        return $all;
    }
}

if (!function_exists('bdsis_report_styles')) {
    /**
     * The <style> block used by the report tables.
     *
     * Kept separate from the subject body so the group report can emit it once
     * for the whole document instead of once per subject.
     *
     * @return string
     */
    function bdsis_report_styles()
    {
        return '<style type="text/css">
						table.pdfTable {font-size:12px;color:#333333;width:100%;border-width: 1px;border-color: #333333;border-collapse: collapse;}
						table.pdfTable th {font-size:12px;background-color:#ffffff;border-width: 1px;padding: 8px;border-style: solid;border-color: #333333;text-align:center;}
						table.pdfTable tr {background-color:#ffffff;}
						table.pdfTable td {font-size:12px;border-width: 1px;padding: 8px;border-style: solid;border-color: #333333;text-align:left;}
						table.pdfTable .center {text-align:center;}
						.pdfLogo {text-align:center;margin-bottom:4px;}
					</style>';
    }
}

if (!function_exists('bdsis_report_logo_html')) {
    /**
     * Official logo shown at the top of every generated PDF report.
     *
     * Change this one path to swap the logo; mPDF reads it off disk, so it does
     * not depend on DOCUMENT_ROOT or on the file being web-reachable. If the
     * file is missing the logo block is simply omitted rather than emitting a
     * broken image, so report generation can never fail on a bad asset path.
     *
     * @return string
     */
    function bdsis_report_logo_html()
    {
        // Official RCDC / Ministry of Health letterhead. It is a wide banner
        // (1024x160), so it is sized to the full content width rather than given
        // a fixed height -- A4 portrait with 15mm side margins leaves 180mm,
        // which puts the banner at roughly 28mm tall at its native ratio.
        $reportLogoPath = realpath(__DIR__ . "/../dist/img/rcdc-letterhead.jpg");

        return ($reportLogoPath !== false && is_readable($reportLogoPath))
            ? '<div class="pdfLogo"><img src="' . $reportLogoPath . '" width="180mm" /></div>'
            : '';
    }
}

if (!function_exists('bdsis_report_ast_codes')) {
    /**
     * Active antimicrobial codes (ms_ast_table).
     *
     * Subject-independent, so the result is memoised for the life of the
     * request -- a 100-subject group report would otherwise re-run this query
     * 100 times.
     *
     * NOTE: this list is deliberately loaded regardless of which section the
     * caller asked for. The original code only populated it inside the
     * bacteriology block, yet the PCR block consumes it too, so a
     * rpt=pcr_ast report used to iterate an undefined variable and silently
     * rendered no AST rows at all.
     *
     * @param  mysqli $bdsis
     * @return array
     */
    function bdsis_report_ast_codes($bdsis)
    {
        static $codes = null;
        if ($codes !== null) {
            return $codes;
        }
        $codes = array();
        /* DISTINCT: ms_ast_table is keyed (code, ast_method_id), so an
           antibiotic offered by more than one method -- which every CLSI MIC
           breakpoint agent now is, since the method-02 seed
           (db-migrations/2026-10-16-mic-clsi-master-seed.sql) added rows for
           codes the Disk Diffusion master already carried -- appears once per
           method. The callers below print one row per entry in this list, so
           without DISTINCT those antibiotics print twice in every AST report. */
        $astQry = $bdsis->query("SELECT DISTINCT code FROM ms_ast_table WHERE status=1");
        if ($astQry) {
            while (list($astCode) = $astQry->fetch_row()) {
                $codes[] = $astCode;
            }
        }

        return $codes;
    }
}

if (!function_exists('bdsis_report_ecoli_strain_codes')) {
    /**
     * E.coli strain ids (ms_ecoli_strain_table). Memoised, see above.
     *
     * @param  mysqli $bdsis
     * @return array
     */
    function bdsis_report_ecoli_strain_codes($bdsis)
    {
        static $codes = null;
        if ($codes !== null) {
            return $codes;
        }
        $codes = array();
        $qry = $bdsis->query("SELECT strain_id FROM ms_ecoli_strain_table ORDER BY strain_id ASC");
        if ($qry) {
            while (list($strainId) = $qry->fetch_row()) {
                $codes[] = $strainId;
            }
        }

        return $codes;
    }
}

if (!function_exists('bdsis_report_printed_by')) {
    /**
     * Full name of the logged-in user, for the signature block. Memoised.
     *
     * @param  mysqli $commondb
     * @return string
     */
    function bdsis_report_printed_by($commondb)
    {
        static $fullname = null;
        if ($fullname !== null) {
            return $fullname;
        }
        $fullname = '';
        $usr = isset($_SESSION["users"]) ? $_SESSION["users"] : '';
        if ($usr !== '' && ($commondb instanceof mysqli)) {
            $userQ = $commondb->query(
                "SELECT fullname FROM user_profile_table WHERE username='"
                . $commondb->real_escape_string($usr) . "'"
            );
            if ($userQ && $userQ->num_rows > 0) {
                list($fullname) = $userQ->fetch_row();
                $fullname = ($fullname === null) ? '' : $fullname;
            }
        }

        return $fullname;
    }
}

if (!function_exists('bdsis_report_name_key')) {
    /**
     * Normalised key for matching a person by full name: trimmed, single
     * spaces, lower case. Names are typed by hand in more than one place, so
     * "Dorji  Tshering " and "dorji tshering" must meet.
     *
     * @param  string $name
     * @return string
     */
    function bdsis_report_name_key($name)
    {
        return strtolower(preg_replace('/\s+/', ' ', trim((string) $name)));
    }
}

if (!function_exists('bdsis_report_signatories')) {
    /**
     * The report signatories: client-supplied designations and the verifying
     * officer, from config/report-signatories.php. Memoised.
     *
     * A missing or malformed file yields an empty roster rather than an error,
     * so the report still renders -- designations then come from the user
     * profile and "Verified By" prints "-".
     *
     * @return array 'designations' => name-key => designation, 'verified_by' => full name
     */
    function bdsis_report_signatories()
    {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        $cfg  = array('designations' => array(), 'roster' => array(), 'verified_by' => '');
        $file = __DIR__ . '/../config/report-signatories.php';
        if (is_readable($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                if (isset($loaded['designations']) && is_array($loaded['designations'])) {
                    foreach ($loaded['designations'] as $name => $designation) {
                        $key = bdsis_report_name_key($name);
                        if ($key !== '') {
                            $cfg['designations'][$key] = trim((string) $designation);
                            /* Display names kept in file order as well, because the
                               map above is keyed on a normalised name and the report
                               and the pickers must show the name as it is written. */
                            $cfg['roster'][$key] = array(
                                'name'        => trim((string) $name),
                                'designation' => trim((string) $designation),
                                'roles'       => array(),
                            );
                        }
                    }
                }
                /* Which roster entries each picker may offer. A person absent
                   from both lists keeps a designation but cannot be chosen. */
                foreach (array('analysts' => 'analyst', 'verifiers' => 'verifier') as $cfgKey => $role) {
                    if (isset($loaded[$cfgKey]) && is_array($loaded[$cfgKey])) {
                        foreach ($loaded[$cfgKey] as $name) {
                            $key = bdsis_report_name_key($name);
                            if ($key !== '' && isset($cfg['roster'][$key])) {
                                $cfg['roster'][$key]['roles'][$role] = true;
                            }
                        }
                    }
                }
                if (isset($loaded['verified_by'])) {
                    $cfg['verified_by'] = trim((string) $loaded['verified_by']);
                }
            }
        }

        return $cfg;
    }
}

if (!function_exists('bdsis_report_roster')) {
    /**
     * The selectable lab personnel, in the order config/report-signatories.php
     * lists them. Used to build the Analyst / Verified By pickers and to
     * validate what those pickers send back.
     *
     * @param  string|null $role 'analyst' or 'verifier' to return only the
     *         people configured for that picker; null returns everyone, which
     *         is what any caller that predates the split still gets.
     * @return array list of array('name' => string, 'designation' => string)
     */
    function bdsis_report_roster($role = null)
    {
        $cfg = bdsis_report_signatories();
        if ($role === null) {
            return array_values($cfg['roster']);
        }
        $out = array();
        foreach ($cfg['roster'] as $entry) {
            if (!empty($entry['roles'][$role])) { $out[] = $entry; }
        }

        return $out;
    }
}

if (!function_exists('bdsis_report_roster_pick')) {
    /**
     * Resolve a chosen personnel name against the roster.
     *
     * A whitelist, deliberately: the name arrives from the request, so only an
     * entry that is actually configured can reach the report, and its
     * designation always comes from the configuration rather than the request.
     *
     * @param  string $name
     * @return array|null array('name','designation'), or null when not chosen
     *                    or not a configured person
     */
    function bdsis_report_roster_pick($name, $role = null)
    {
        $key = bdsis_report_name_key($name);
        if ($key === '') { return null; }
        $cfg = bdsis_report_signatories();
        /* The pickers send "Name - Designation". Fall back to the name in front
           of the separator so both that and a bare name resolve -- the bare form
           is what older bookmarked print links carry. The designation printed on
           the report always comes from configuration, never from this string. */
        if (!isset($cfg['roster'][$key]) && strpos($name, ' - ') !== false) {
            $key = bdsis_report_name_key(substr($name, 0, strpos($name, ' - ')));
        }
        if (!isset($cfg['roster'][$key])) { return null; }
        /* The role is enforced here as well as in the picker, so a hand-typed or
           bookmarked URL cannot print someone in a capacity they are not
           configured for. An entry that fails the check falls back to the
           automatic signature block rather than being printed. */
        if ($role !== null && empty($cfg['roster'][$key]['roles'][$role])) { return null; }

        return $cfg['roster'][$key];
    }
}

if (!function_exists('bdsis_report_designation')) {
    /**
     * Designation printed against a person on the report.
     *
     * The configured roster wins, matched on full name; otherwise the
     * designation on the person's user profile (ms_designation_table);
     * otherwise ''. The roster comes first because the profile designations
     * are generic ("Laboratory Officer" for everyone at RCDC) and cannot say
     * "Sr. Laboratory Technician" or "Chief Laboratory Officer".
     *
     * @param  string $username  login the result was recorded under ('' if unknown)
     * @param  string $fullname  display name
     * @param  mysqli $commondb
     * @return string
     */
    function bdsis_report_designation($username, $fullname, $commondb)
    {
        $roster = bdsis_report_signatories();
        $key    = bdsis_report_name_key($fullname);
        if ($key !== '' && isset($roster['designations'][$key]) && $roster['designations'][$key] !== '') {
            return $roster['designations'][$key];
        }
        if ($username !== null && $username !== '' && ($commondb instanceof mysqli)) {
            $q = $commondb->query(
                "SELECT d.value FROM user_profile_table p
                   JOIN ms_designation_table d ON d.id = p.designation
                  WHERE p.username='" . $commondb->real_escape_string($username) . "'"
            );
            if ($q && $q->num_rows > 0) {
                list($value) = $q->fetch_row();
                if ($value !== null && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }
}

if (!function_exists('bdsis_report_analysts')) {
    /**
     * The lab staff who actually analysed this subject's samples, with their
     * designations, for the signature block.
     *
     * "Analyst" is not stored as its own field -- it is the processed_by
     * recorded against each result. Those live in a different table per result
     * type, and one subject can carry several (a different technician per
     * pathogen, or per assay), so collect the distinct people across only the
     * sections this report actually includes. A report that omits a section
     * must not name the person who ran it.
     *
     * Names are rendered through bdsis_processed_by_label(), so legacy rows
     * holding a username still resolve to a full name while the newer free-text
     * entries pass through unchanged. Designation comes from
     * bdsis_report_designation().
     *
     * @param  string $sidEsc   already-escaped subject_id
     * @param  array  $sections resolved section keys for this report
     * @param  mysqli $bdsis
     * @param  mysqli $commondb
     * @return array  list of array('user' => string, 'name' => string, 'designation' => string)
     */
    function bdsis_report_analysts($sidEsc, $sections, $bdsis, $commondb)
    {
        $queries = array(
            'microscopy'       => array("SELECT processed_by FROM microscopy_result_table WHERE subject_id='" . $sidEsc . "'"),
            'bacteriology_ast' => array("SELECT processed_by FROM bacteriology_result_table2 WHERE subject_id='" . $sidEsc . "'"),
            'pcr_ast'          => array(
                "SELECT processed_by FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id='" . $sidEsc . "'",
                "SELECT a.processed_by FROM rcdc_ecoli_pcr_ast_table1 a
                   JOIN rcdc_ecoli_pcr_result_table3 t3 ON t3.record_id = a.record_id
                   JOIN rcdc_ecoli_pcr_result_table2 t2 ON t2.record_id = t3.prt2_record_id
                  WHERE t2.subject_id='" . $sidEsc . "'",
            ),
            'elisa'            => array("SELECT processed_by FROM rcdc_elisa_result_table2 WHERE subject_id='" . $sidEsc . "'"),
        );

        $people = array();
        foreach ($queries as $section => $sqlList) {
            if (!in_array($section, $sections, true)) {
                continue;
            }
            foreach ($sqlList as $sql) {
                $res = $bdsis->query($sql);
                if (!$res) {
                    continue;
                }
                while (list($raw) = $res->fetch_row()) {
                    if ($raw === null || $raw === '') {
                        continue;
                    }
                    $label = bdsis_processed_by_label($raw, $commondb);
                    if ($label === '' || isset($people[$label])) {
                        continue;
                    }
                    $people[$label] = array(
                        'user'        => (string) $raw,
                        'name'        => $label,
                        'designation' => bdsis_report_designation((string) $raw, $label, $commondb),
                    );
                }
            }
        }

        return array_values($people);
    }
}

if (!function_exists('bdsis_report_analyzed_by')) {
    /**
     * Comma-separated analyst names, '' when none recorded. Kept for any
     * caller that only needs the names; the signature block itself uses
     * bdsis_report_analysts() so it can print designations too.
     *
     * @param  string $sidEsc   already-escaped subject_id
     * @param  array  $sections resolved section keys for this report
     * @param  mysqli $bdsis
     * @param  mysqli $commondb
     * @return string
     */
    function bdsis_report_analyzed_by($sidEsc, $sections, $bdsis, $commondb)
    {
        $names = array();
        foreach (bdsis_report_analysts($sidEsc, $sections, $bdsis, $commondb) as $person) {
            $names[] = $person['name'];
        }

        return implode(', ', $names);
    }
}

if (!function_exists('bdsis_report_verified_by')) {
    /**
     * Who is named under "Verified By" for this subject.
     *
     * When the lab workflow has verified the record (lab_record_status_table,
     * status = verified) that verifier is named -- it would be wrong to print
     * someone else's name over a verification another officer performed.
     * Otherwise, and for every record verified before the workflow existed,
     * the configured verifying officer is printed (config/report-signatories.php).
     *
     * @param  string $sidEsc   already-escaped subject_id
     * @param  mysqli $bdsis
     * @param  mysqli $commondb
     * @return array  array('user' => string, 'name' => string, 'designation' => string)
     */
    function bdsis_report_verified_by($sidEsc, $bdsis, $commondb)
    {
        $user = '';
        $name = '';
        if ($bdsis instanceof mysqli) {
            $verified = defined('BDSIS_LAB_VERIFIED') ? BDSIS_LAB_VERIFIED : 'verified';
            $q = $bdsis->query(
                "SELECT verified_by FROM lab_record_status_table
                  WHERE subject_id='" . $sidEsc . "'
                    AND status='" . $bdsis->real_escape_string($verified) . "'
                    AND verified_by IS NOT NULL AND verified_by <> ''"
            );
            if ($q && $q->num_rows > 0) {
                list($user) = $q->fetch_row();
                $user = (string) $user;
                $name = bdsis_processed_by_label($user, $commondb);
            }
        }
        if ($name === '') {
            $roster = bdsis_report_signatories();
            $user   = '';
            $name   = $roster['verified_by'];
        }

        return array(
            'user'        => $user,
            'name'        => $name,
            'designation' => ($name === '') ? '' : bdsis_report_designation($user, $name, $commondb),
        );
    }
}

if (!function_exists('bdsis_build_group_result_html')) {

    /**
     * Build compact group/outbreak result report.
     *
     * Columns:
     * Sample ID
     * Name
     * Age/Sex
     * Bacteriology Result
     * PCR Result
     * ELISA Result
     * Dynamic antibiotic columns
     *
     * Antibiotic columns are generated from antibiotics actually tested
     * for the selected subjects.
     */
    function bdsis_build_group_result_html(
        $subjects,
        $bdsis,
        $commondb,
        $subjectTypeLabel = ''
    ) {

        /*
         * --------------------------------------------------------------
         * 1. Find all antibiotics actually tested for these subjects.
         * --------------------------------------------------------------
         *
         * This creates the dynamic columns.
         *
         * Example:
         *
         * AMP | CZO | LEX | CIP
         *
         * If a subject was not tested for CIP, its CIP cell remains "-".
         */

        $antibiotics = array();

        foreach ($subjects as $subject) {

            $sidEsc = $bdsis->real_escape_string($subject['subject_id']);

            $astQry = $bdsis->query(
                "SELECT DISTINCT antimicrobial_code
                 FROM bacteriology_ast_table
                 WHERE brt2_id IN (
                     SELECT record_id
                     FROM bacteriology_result_table2
                     WHERE subject_id = '" . $sidEsc . "'
                 )
                 AND antimicrobial_code IS NOT NULL
                 AND antimicrobial_code <> ''
                 ORDER BY antimicrobial_code ASC"
            );

            if ($astQry) {

                while (list($code) = $astQry->fetch_row()) {

                    $code = trim((string)$code);

                    if ($code !== '') {
                        $antibiotics[$code] = $code;
                    }
                }
            }
        }

        /*
         * Preserve a predictable order.
         */
        $antibiotics = array_values($antibiotics);
        sort($antibiotics, SORT_NATURAL | SORT_FLAG_CASE);


        /*
         * --------------------------------------------------------------
         * 2. Build HTML
         * --------------------------------------------------------------
         */

        $html = '';

        $html .= '
        <style type="text/css">

            table.groupResultTable {
                width: 100%;
                border-collapse: collapse;
                font-size: 8.5px;
                color: #333333;
            }

            table.groupResultTable th {
                border: 1px solid #333333;
                padding: 5px 4px;
                text-align: center;
                vertical-align: middle;
                font-weight: bold;
                background-color: #ffffff;
            }

            table.groupResultTable td {
                border: 1px solid #333333;
                padding: 5px 4px;
                text-align: center;
                vertical-align: middle;
            }

            table.groupResultTable td.left {
                text-align: left;
            }

            .groupReportTitle {
                text-align: center;
                font-size: 15px;
                font-weight: bold;
                margin-bottom: 8px;
            }

            .groupReportType {
                text-align: center;
                font-size: 10px;
                margin-bottom: 10px;
            }

        </style>
        ';


        $html .= '<div class="groupReportTitle">
                    Result Report
                  </div>';


        if ($subjectTypeLabel !== '') {
            $html .= '<div class="groupReportType">
                        Subject Type: <strong>'
                        . htmlspecialchars($subjectTypeLabel, ENT_QUOTES)
                        . '</strong>
                      </div>';
        }


        /*
         * --------------------------------------------------------------
         * Table Header
         * --------------------------------------------------------------
         */

        $html .= '<table class="groupResultTable">';

        $html .= '<thead>
                    <tr>

                        <th>Sample ID</th>

                        <th>Name</th>

                        <th>Age/Sex</th>

                        <th>Bacteriology Result</th>

                        <th>PCR Result</th>

                        <th>ELISA Result</th>

                        <th colspan="' . max(1, count($antibiotics)) . '">
                            Antibiotics
                        </th>

                    </tr>';

        /*
         * Antibiotic names.
         */

        if (count($antibiotics) > 0) {

            $html .= '<tr>
                        <th colspan="6"></th>';

            foreach ($antibiotics as $code) {

                $html .= '<th>'
                    . htmlspecialchars($code, ENT_QUOTES)
                    . '</th>';
            }

            $html .= '</tr>';

        } else {

            $html .= '<tr>
                        <th colspan="7">No AST antibiotic records</th>
                      </tr>';
        }

        $html .= '</thead>';


        /*
         * --------------------------------------------------------------
         * Table Body
         * --------------------------------------------------------------
         */

        $html .= '<tbody>';


        foreach ($subjects as $subject) {

            $sid = $subject['subject_id'];

            $sidEsc = $bdsis->real_escape_string($sid);


            /*
             * ----------------------------------------------------------
             * Bacteriology
             * ----------------------------------------------------------
             */

            $bacteriologyResults = array();

            $bactQry = $bdsis->query(
                "SELECT pathogen_id, e_pathogen, ast_status
                 FROM bacteriology_result_table2
                 WHERE subject_id = '" . $sidEsc . "'
                 ORDER BY record_id ASC"
            );

            if ($bactQry) {

                while (list($pathogenId, $ePathogen, $astStatus) =
                       $bactQry->fetch_row()) {

                    $pathogenName = '';

                    $pathQry = $bdsis->query(
                        "SELECT enteric_pathogen
                         FROM ms_enteric_bacterial_pathogen_table
                         WHERE pathogen_id = '" .
                         $bdsis->real_escape_string($pathogenId) . "'"
                    );

                    if ($pathQry && $pathQry->num_rows > 0) {
                        list($pathogenName) = $pathQry->fetch_row();
                    }

                    if ($pathogenName === '') {
                        $pathogenName = $ePathogen;
                    }

                    if ($pathogenName !== '') {
                        $bacteriologyResults[] = $pathogenName;
                    }
                }
            }

            $bacteriologyText = count($bacteriologyResults) > 0
                ? implode(', ', array_unique($bacteriologyResults))
                : '-';


            /*
             * ----------------------------------------------------------
             * PCR
             * ----------------------------------------------------------
             */

            $pcrResults = array();

            $pcrQry = $bdsis->query(
                "SELECT ecoli_id, ecoli_strain_result
                 FROM rcdc_ecoli_pcr_result_table2
                 WHERE subject_id = '" . $sidEsc . "'
                 ORDER BY record_id ASC"
            );

            if ($pcrQry) {

                while (list($ecoliId, $pcrResult) =
                       $pcrQry->fetch_row()) {

                    $pathogenName = '';

                    $pathQry = $bdsis->query(
                        "SELECT enteric_pathogen
                         FROM ms_enteric_bacterial_pathogen_table
                         WHERE pathogen_id = '" .
                         $bdsis->real_escape_string($ecoliId) . "'"
                    );

                    if ($pathQry && $pathQry->num_rows > 0) {
                        list($pathogenName) = $pathQry->fetch_row();
                    }

                    $value = '';

                    if ($pathogenName !== '') {
                        $value .= $pathogenName . ': ';
                    }

                    $value .= $pcrResult;

                    if ($value !== '') {
                        $pcrResults[] = $value;
                    }
                }
            }

            $pcrText = count($pcrResults) > 0
                ? implode(', ', $pcrResults)
                : '-';


            /*
             * ----------------------------------------------------------
             * ELISA
             * ----------------------------------------------------------
             */

            $elisaResults = array();

            $elisaQry = $bdsis->query(
                "SELECT virus_id, elisa_result, genotype_id
                 FROM rcdc_elisa_result_table2
                 WHERE subject_id = '" . $sidEsc . "'
                 ORDER BY virus_id ASC"
            );

            if ($elisaQry) {

                while (list($virusId, $elisaResult, $genotypeId) =
                       $elisaQry->fetch_row()) {

                    $virusName = '';

                    $virusQry = $bdsis->query(
                        "SELECT virus
                         FROM ms_virus_table
                         WHERE virus_id = '" .
                         $bdsis->real_escape_string($virusId) . "'"
                    );

                    if ($virusQry && $virusQry->num_rows > 0) {
                        list($virusName) = $virusQry->fetch_row();
                    }

                    $value = '';

                    if ($virusName !== '') {
                        $value .= $virusName . ': ';
                    }

                    $value .= $elisaResult;

                    /*
                     * Add genotype when available.
                     */
                    $genotype = bdsis_rotavirus_genotype_label(
                        $bdsis,
                        $genotypeId
                    );

                    if ($genotype !== '') {
                        $value .= ' (' . $genotype . ')';
                    }

                    if ($value !== '') {
                        $elisaResults[] = $value;
                    }
                }
            }

            $elisaText = count($elisaResults) > 0
                ? implode(', ', $elisaResults)
                : '-';


            /*
             * ----------------------------------------------------------
             * Antibiotic results for THIS subject.
             *
             * Important:
             * Only antibiotics actually tested for this subject are
             * populated.
             * ----------------------------------------------------------
             */

            $subjectAst = array();

            $astQry = $bdsis->query(
                "SELECT a.antimicrobial_code,
                        a.ast_result
                 FROM bacteriology_ast_table a
                 INNER JOIN bacteriology_result_table2 b
                    ON b.record_id = a.brt2_id
                 WHERE b.subject_id = '" . $sidEsc . "'"
            );

            if ($astQry) {

                while (list($antibioticCode, $astResult) =
                       $astQry->fetch_row()) {

                    $antibioticCode = trim((string)$antibioticCode);

                    if ($antibioticCode !== '') {
                        $subjectAst[$antibioticCode] = $astResult;
                    }
                }
            }


            /*
             * ----------------------------------------------------------
             * Row
             * ----------------------------------------------------------
             */

            $html .= '<tr>';

            $html .= '<td>'
                . htmlspecialchars($sid, ENT_QUOTES)
                . '</td>';

            $html .= '<td class="left">'
                . htmlspecialchars($subject['subject_name'], ENT_QUOTES)
                . '</td>';

            $ageSex = $subject['age'];

            if ($subject['gender'] !== '') {
                $ageSex .= ' / ' . $subject['gender'];
            }

            $html .= '<td>'
                . htmlspecialchars($ageSex, ENT_QUOTES)
                . '</td>';

            $html .= '<td class="left">'
                . htmlspecialchars($bacteriologyText, ENT_QUOTES)
                . '</td>';

            $html .= '<td class="left">'
                . htmlspecialchars($pcrText, ENT_QUOTES)
                . '</td>';

            $html .= '<td class="left">'
                . htmlspecialchars($elisaText, ENT_QUOTES)
                . '</td>';


            /*
             * Dynamic antibiotic cells.
             */

            if (count($antibiotics) > 0) {

                foreach ($antibiotics as $code) {

                    $value = isset($subjectAst[$code])
                        ? $subjectAst[$code]
                        : '-';

                    $html .= '<td>'
                        . htmlspecialchars($value, ENT_QUOTES)
                        . '</td>';
                }

            } else {

                $html .= '<td>-</td>';
            }

            $html .= '</tr>';
        }


        $html .= '</tbody></table>';

        return $html;
    }
}

if (!function_exists('bdsis_build_report_html')) {
    /**
     * Build the complete report body for ONE subject.
     *
     * Every piece of per-subject state lives inside this function, so calling it
     * in a loop cannot leak data between subjects. That matters: the code this
     * was extracted from appended to $astBCode and $ecoliStrainCode with []=
     * without ever initialising them, which in a loop would have accumulated the
     * master lists and duplicated every AST row for the second subject onwards.
     *
     * @param  string      $sid            subject_id
     * @param  mixed       $rpt            What to render. Accepts a legacy
     *                                     string (All|microscopy|bacteriology_ast|
     *                                     pcr_ast|elisa), a template id from
     *                                     bdsis_report_templates(), or an array
     *                                     of section keys. See
     *                                     bdsis_report_resolve_sections().
     * @param  mysqli      $bdsis
     * @param  mysqli      $commondb
     * @param  string      $reportLogoHtml pre-rendered logo block ('' to omit)
     * @param  bool        $includeStyles  emit the <style> block (false when the
     *                                     caller already emitted it once)
     * @return string
     */
    /**
     * @param array|null $signatories Optional chosen personnel for the signature
     *        block: array('analyst' => name, 'verifier' => name). Both are
     *        optional and are resolved through bdsis_report_roster_pick(), so
     *        only configured people are ever printed. Omitted or unrecognised
     *        entries fall back to the automatic behaviour, which is what every
     *        existing caller gets.
     */
    function bdsis_build_report_html($sid, $rpt, $bdsis, $commondb, $reportLogoHtml = '', $includeStyles = true, $signatories = null)
    {
        /* One normalisation up front; every section below gates on membership
           in this set. The Patient Information header and the signature block
           stay unconditional, exactly as before. */
        $sections = bdsis_report_resolve_sections($rpt);

        /* Master lists: loaded once per request, copied per subject. */
        $astBCode        = bdsis_report_ast_codes($bdsis);
        $ecoliStrainCode = bdsis_report_ecoli_strain_codes($bdsis);
        $user            = bdsis_report_printed_by($commondb);

        $sidEsc = $bdsis->real_escape_string($sid);

        $sitqry = $bdsis->query("SELECT subject_name, gender, age, center_id FROM subject_information_table WHERE subject_id='" . $sidEsc . "'");
        list($sname, $sex, $age, $ctid) = $sitqry->fetch_row();
        $docQry = $bdsis->query("SELECT collection_date FROM sample_information_table WHERE subject_id='" . $sidEsc . "'");
        list($doc) = $docQry->fetch_row();
        $micQry = $bdsis->query("SELECT color,mucus,blood,wbc,rbc,ova_cyst,others,processed_by,processed_on FROM microscopy_result_table WHERE subject_id='" . $sidEsc . "'");
        list($clr, $muc, $bld, $wbc, $rbc, $ova_cyst, $othrs, $pby, $pon) = $micQry->fetch_row();
        $cnameQ = $commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id='" . $commondb->real_escape_string($ctid) . "'");
        list($cname) = $cnameQ->fetch_row();
        /* Free text on the Case Management form: resolve a username to a full
           name, otherwise show what was typed. The raw lookup this replaces left
           the field blank for any value that was not a user. */
        $pbyname = bdsis_processed_by_label($pby, $commondb);

        $ageyrs = intval($age / 12);
        $ageMonths = $age % 12;

// Content begins
                $html = $includeStyles ? bdsis_report_styles() : '';
                    $html.=$reportLogoHtml;
                    $html.='<h6>Patient Information</h6>
					<table class="pdfTable" border="1">
						<tr>
							<td><label>Health Center: <u>'.$cname.'</u></label></td>
							<td><label>Subject ID: <u>'.$sid.'</u></label></td>
						</tr>
						<tr>
							<td><label>Patient Name:<u>'.$sname.'</u></label></td>
							<td><label>Age: <u>'.$age.' Months('.$ageyrs.' Years, '.$ageMonths.' Months)</u></label></td>
						</tr>
						<tr>
							<td><label>Gender: <u>'.$sex.'</u></label></td>
							<td><label>Sample Collection Date: <u>'.$doc.'</u></label></td>
						</tr>
					</table><br/>';

                if(in_array("microscopy", $sections, true)){
                    $html.='<h6>Stool Microscopy Result</h6>
					<table class="pdfTable" border="1">
						<tr>
							<td><label>Colour *: <u>'.$clr.'</u></label></td>
							<td><label>Mucus *: <u>'.$muc.'</u></label></td>
							<td><label>Blood *: <u>'.$bld.'</u></label></td>
						</tr>
						<tr>
							<td><label>White Blood Cell(WBC): <u>'.$wbc.'</u></label></td>
							<td><label>Reb Blood Cell(RBC): <u>'.$rbc.'</u></label></td>
							<td><label>Parasite: <u>'.$ova_cyst.'</u></label></td>
						</tr>
						<tr>
							<td><label>Parasite(Others): <u>'.$othrs.'</u></label></td>
							<td><label>Sample Processed By: <u>'.$pbyname.'</u></label></td>
							<td><label>Sample Processed On: <u>'.$pon.'</u></label></td>
						</tr>
					</table><br/>';
                }


                if(in_array("bacteriology_ast", $sections, true)){
                        $html.='<h6>Bacterology/AST Result</h6>';

                        $bacteriology2=$bdsis->query("SELECT record_id, pathogen_id, e_pathogen, ast_status, processed_by, processed_on FROM bacteriology_result_table2 WHERE subject_id='".$sidEsc."' ORDER BY CAST(SUBSTRING(e_pathogen,2) AS UNSIGNED) ASC, record_id ASC");

                    while(list($brecid,$bpid,$epathogen,$astSts,$pby,$pon)=$bacteriology2->fetch_row()){
                        $patnNameQry = $bdsis->query("SELECT enteric_pathogen FROM ms_enteric_bacterial_pathogen_table WHERE pathogen_id='".$bpid."'");
                        list($pathogenName)=$patnNameQry->fetch_row();
                        $pbyName = bdsis_processed_by_label($pby, $commondb);
                        $html.='
						<table class="pdfTable" border="1">
							<tr>
								<td><label>Pathogen:</label></td>
								<td><label><u>'.$pathogenName.'</u></label></td>
								<td><label>AST Status:</label></td>
								<td><label><u>'.$astSts.'</u></label></td>
								<td><label>Processed By:</label></td>
								<td><label><u>'.$pbyName.'</u></label></td>
								<td><label>Processed On:</label></td>
								<td><label><u>'.$pon.'</u></label></td>
							</tr>';

							if($astSts == 'R'){
								$html.='<tr>
									<td colspan=2><label><B>AST Method</B></label></td>
									<td colspan=2><label><B>Antimicrobial Code</B></label></td>
									<td colspan=2><label><B>Zone Diameter</B></label></td>
									<td colspan=2><label><B>Ast Result</B></label></td>
								</tr>';
								foreach($astBCode as $astBid){
									$astQuery=$bdsis->query("SELECT record_id,ast_method_id, antimicrobial_code, zone_diameter, ast_result FROM bacteriology_ast_table WHERE brt2_id='".$brecid."' AND antimicrobial_code='".$astBid."'");
                                    list($astRecid,$astMethod,$antimicrobialCode,$zoneDiameter,$astResult)=$astQuery->fetch_row();
                                    $astMNameQry=$bdsis->query("SELECT ast_method FROM ms_ast_method_table WHERE method_id='".$astMethod."'");
                                    list($astMethodName)=$astMNameQry->fetch_row();
								$html.='<tr>
									<td colspan=2><label><u>'.$astMethodName.'</u></label></td>
									<td colspan=2><label><u>'.$antimicrobialCode.'</u></label></td>
									<td colspan=2><label><u>'.$zoneDiameter.'</u></label></td>
									<td colspan=2><label><u>'.$astResult.'</u></label></td>
								</tr>';
								}
								$html .= bdsis_bact_resistance_report_rows($bdsis, $brecid);   // ESBL / carbapenemase, only when triggered and stored
							}
							$asrRemarksQry = $bdsis->query("SELECT remarks FROM bacteriology_result_table1 WHERE subject_id='".$sidEsc."'");
                            list($astRemarks)=$asrRemarksQry->fetch_row();

							$html.='<tr><td colspan=8>'.$astRemarks.'</td></tr>
						</table><br/>';
                    }
                }



                if(in_array("pcr_ast", $sections, true)){
                        $html.='<h6>PCR & AST Result </h6>';
                        $ecoli_pcr=$bdsis->query("SELECT record_id, ecoli_id, ecoli_strain_result, processed_by, processed_on FROM rcdc_ecoli_pcr_result_table2 WHERE subject_id='".$sidEsc."' ORDER BY ecoli_id ASC");
                    while(list($pcrId,$pcrEcoli,$ecoliResult,$pcrPby,$pcrPon)=$ecoli_pcr->fetch_row()){
                        $pcrPathogenNameQry=$bdsis->query("SELECT enteric_pathogen FROM ms_enteric_bacterial_pathogen_table WHERE pathogen_id='".$pcrEcoli."'");
                        list($pcrPathogenName)=$pcrPathogenNameQry->fetch_row();
                        $pcrPbyNameQry=$commondb->query("SELECT fullname FROM user_profile_table WHERE username='".$pcrPby."'");
                        list($pcrPbyName)=$pcrPbyNameQry->fetch_row();
                        $html.='
						<table class="pdfTable" border="1">
							<tr>
								<td><label>Pathogen:</label></td>
								<td><label><u>'.$pcrPathogenName.'</u></label></td>
								<td><label>PCR Result:</label></td>
								<td><label><u>'.$ecoliResult.'</u></label></td>
								<td><label>PCR Processed By:</label></td>
								<td><label><u>'.$pcrPbyName.'</u></label></td>
								<td><label>PCR Processed On:</label></td>
								<td><label><u>'.$pcrPon.'</u></label></td>
							</tr>';
							/* Whether this isolate's PCR block prints is the one shared
							   rule, so the report can never show a different set of
							   blocks from the data-entry form. The per-strain
							   conditional further down is a different thing: it
							   decides which individual pathology-type rows print. */
							if(bdsis_ecoli_pcr_applies($bdsis, $sidEsc, $pcrEcoli)){
								foreach($ecoliStrainCode as $ecoliStrain){
									$pcrStrainqry=$bdsis->query("SELECT record_id, ecoli_strain_id, strain_result FROM rcdc_ecoli_pcr_result_table3 WHERE ecoli_strain_id='".$ecoliStrain."' AND prt2_record_id='".$pcrId."'");
                                    list($ecoliPcrRecid,$ecoliPcrCode,$ecoliPcrResult)=$pcrStrainqry->fetch_row();

                                    $ecoliStrainName=$bdsis->query("SELECT ecoli_strain FROM ms_ecoli_strain_table WHERE strain_id='".$ecoliPcrCode."'");
                                    list($ecoliStrainName)=$ecoliStrainName->fetch_row();

									if($ecoliPcrResult=='Positive' || $ecoliPcrResult=='Negative'){
										$html.='<tr>
											<td colspan=8><label>'.$ecoliStrainName.': <u>'.$ecoliPcrResult.'</u></label></td>
										</tr>';
                                        /* Conditional ESBL / carbapenemase results recorded on this
                                           pathotype's AST panel. Read from their own stored record, so
                                           a pathotype that never triggered either test prints exactly
                                           as it did before. */
                                        $html .= bdsis_ecoli_resistance_report_rows($bdsis, $ecoliPcrRecid);
                                        $pcrAstCountx=$bdsis->query("SELECT record_id FROM rcdc_ecoli_pcr_ast_table WHERE prt3_record_id='".$ecoliPcrRecid."'");
                                        list($pcrAstCount)=$pcrAstCountx->num_rows;
										if($pcrAstCount>0){
											$pcrAstPbyQry = $bdsis->query("SELECT processed_by, processed_on FROM rcdc_ecoli_pcr_ast_table1 WHERE record_id='".$ecoliPcrRecid."'");
                                            list($ecoliPcrPby,$ecoliPcrPon)=$pcrAstPbyQry->fetch_row();
                                            $ecoliPcrPbyNameQ=$commondb->query("SELECT fullname FROM user_profile_table WHERE username='".$ecoliPcrPby."'");
                                            list($ecoliPcrPbyName)=$ecoliPcrPbyNameQ->fetch_row();
											$html.='<tr>
												<td colspan=2><label></label></td>
												<td colspan=3><label>AST Processed By: <u>'.$ecoliPcrPbyName.'</u></label></td>
												<td colspan=3><label>AST Processed On: <u>'.$ecoliPcrPon.'</u></label></td>
											</tr>';
											$html.='<tr>
												<td colspan=2><label><B>AST Method</B></label></td>
												<td colspan=2><label><B>Antimicrobial Code</B></label></td>
												<td colspan=2><label><B>Zone Diameter</B></label></td>
												<td colspan=2><label><B>Ast Result</B></label></td>
											</tr>';

											foreach($astBCode as $ecoliAstid){
												$astQ=$bdsis->query("SELECT record_id,ast_method_id, antimicrobial_code, zone_diameter, ast_result FROM rcdc_ecoli_pcr_ast_table WHERE prt3_record_id='".$ecoliPcrRecid."' AND antimicrobial_code='".$ecoliAstid."'");
                                                list($astRecid,$astMethod,$antimicrobialCode,$zoneDiameter,$astResult)=$astQ->fetch_row();
                                                $pcrAstMethodNameQ=$commondb->query("SELECT ast_method FROM ms_ast_method_table WHERE method_id='".$astMethod."'");
                                                list($pcrAstMethodName)=$pcrAstMethodNameQ->fetch_row();
												if($astRecid>0){
												$html.='<tr>
													<td colspan=2><label><u>'.$pcrAstMethodName.'</u></label></td>
													<td colspan=2><label><u>'.$antimicrobialCode.'</u></label></td>
													<td colspan=2><label><u>'.$zoneDiameter.'</u></label></td>
													<td colspan=2><label><u>'.$astResult.'</u></label></td>
												</tr>';
												}
											}
										}
									}
								}
							}
							$PcrAstRemarks=$bdsis->query("SELECT remarks FROM rcdc_ecoli_pcr_result_table1 WHERE subject_id='".$sidEsc."'");
                            list($PcrAstRemarks)=$PcrAstRemarks->fetch_row();
							$html.='<tr><td colspan=8>'.$PcrAstRemarks.'</td></tr>
						</table><br/>';
                    }
                }


                if(in_array("elisa", $sections, true)){
                    $elisaQry=$bdsis->query("SELECT record_id, virus_id, elisa_result, od_value, processed_by, assay_date, genotype_id FROM rcdc_elisa_result_table2 WHERE subject_id='".$sidEsc."' ORDER BY virus_id ASC");
                    $html.='<h6>Elisa Result </h6>
					<table class="pdfTable" border="1">
						<tr>
							<td colspan=2><label><B>Elisa Virus</B></label></td>
							<td colspan=2><label><B>Elisa Result</B></label></td>
							<td colspan=2><label><B>Elisa OD Value</B></label></td>
							<td colspan=2><label><B>Elisa By</B></label></td>
							<td colspan=2><label><B>Assay Date</B></label></td>
						</tr>';
						while(list($elisaId,$elisaVirusID,$elisaResult,$elisaValue,$elisaPby,$elisaPon,$elisaGenotypeID)=$elisaQry->fetch_row()){
							/* Rotavirus genotype -- only a handful of rows carry one, so it is
							   appended to the result cell rather than given a column of its own,
							   and nothing at all is emitted when none is stored. */
							$elisaGenotype=bdsis_rotavirus_genotype_label($bdsis, $elisaGenotypeID);
							$elisaGenotypeTxt=($elisaGenotype!=='') ? ' <small>(Genotype: '.htmlspecialchars($elisaGenotype).')</small>' : '';
							$elisaVirusNameQ=$bdsis->query("SELECT virus FROM ms_virus_table WHERE virus_id='".$elisaVirusID."'");
                            list($elisaVirusName)=$elisaVirusNameQ->fetch_row();
                            $pelisaPbyNameQ=$commondb->query("SELECT fullname FROM user_profile_table WHERE username='".$elisaPby."'");
                            list($pelisaPbyName)=$pelisaPbyNameQ->fetch_row();
							$html.='<tr>
								<td colspan=2><label><u>'.$elisaVirusName.'</u></label></td>
								<td colspan=2><label><u>'.$elisaResult.'</u>'.$elisaGenotypeTxt.'</label></td>
								<td colspan=2><label><u>'.$elisaValue.'</u></label></td>
								<td colspan=2><label><u>'.$pelisaPbyName.'</u></label></td>
								<td colspan=2><label><u>'.$elisaPon.'</u></label></td>
							</tr>';
						}
						$elisaRemarksQ=$bdsis->query("SELECT remarks FROM rcdc_elisa_result_table1 WHERE subject_id='".$sidEsc."'");
                        list($elisaRemarks)=$elisaRemarksQ->fetch_row();
							$html.='<tr><td colspan=10>'.$elisaRemarks.'</td></tr>
					</table><br/>';
                }


					/* Signature block. The analyst rows are the people whose processed_by is
					   recorded against the sections this report includes -- the same
					   source "Analyzed By" always used -- each now with a designation.
					   "Verified By" names the workflow verifier when the record has been
					   verified, otherwise the configured verifying officer. Both come
					   from config/report-signatories.php; see the helpers above. The
					   Printed By line and the signature cell are unchanged. */
					$analysts = bdsis_report_analysts($sidEsc, $sections, $bdsis, $commondb);
					$verifier = bdsis_report_verified_by($sidEsc, $bdsis, $commondb);

					/* An explicitly chosen analyst or verifier replaces the derived
					   one. Both are whitelisted against the configured roster, so the
					   printed designation always comes from the configuration and never
					   from the request. Nothing chosen means the behaviour above is
					   unchanged, which is what the group report and every other caller
					   continues to get. */
					if (is_array($signatories)) {
						$pickA = bdsis_report_roster_pick(isset($signatories['analyst']) ? $signatories['analyst'] : '', 'analyst');
						if ($pickA !== null) {
							$analysts = array(array('user' => '', 'name' => $pickA['name'], 'designation' => $pickA['designation']));
						}
						$pickV = bdsis_report_roster_pick(isset($signatories['verifier']) ? $signatories['verifier'] : '', 'verifier');
						if ($pickV !== null) {
							$verifier = array('user' => '', 'name' => $pickV['name'], 'designation' => $pickV['designation']);
						}
					}
					$sigTxt   = function ($v) { return ($v === '' || $v === null) ? '-' : htmlspecialchars($v, ENT_QUOTES); };

					$html.='<br/><br/><br/><br/><br/><br/><table class="pdfTable" border="1">';
					if (count($analysts) === 0) {
						$html.='<tr><td>Analyst Name : <strong>-</strong></td><td>Designation : <strong>-</strong></td></tr>';
					}
					foreach ($analysts as $analyst) {
						$html.='<tr><td>Analyst Name : <strong>'.$sigTxt($analyst['name']).'</strong></td>'
						      .'<td>Designation : <strong>'.$sigTxt($analyst['designation']).'</strong></td></tr>';
					}
					$html.='<tr><td>Verified By : <strong>'.$sigTxt($verifier['name']).'</strong></td>'
					      .'<td>Designation : <strong>'.$sigTxt($verifier['designation']).'</strong></td></tr>
						<tr><td>Printed By :<strong>'.$user.'</strong> On : <strong>'.date('d-m-Y').'</strong></td>
							<td>Verified By (Signature & Date)</td>
						</tr>
						</table>';

        return $html;
    }
}
