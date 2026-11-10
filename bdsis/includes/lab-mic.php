<?php
/* ===========================================================================
   E. coli AST -- Minimum Inhibitory Concentration (MIC) result entries.
   ---------------------------------------------------------------------------
   Loaded by includes/lab-helpers.php, so everything that includes the lab
   helpers (lab.php, iu-lab.php, load-ecoli-ast.php, the reports) has it.

   Per E. coli pathotype panel the reader chooses the AST method (methoda..l).
   '01' is Disk Diffusion: a fixed list of zones, interpreted from breakpoints.
   '02' is MIC.

   2026-10-16 -- MIC now renders the SAME grid an Enteric Pathogen row renders
   (includes/lab-bact-mic.php): the fixed 14-antibiotic CLSI breakpoint table,
   interpreted as you type, which in turn drives the same conditional ESBL /
   AmpC / Carbapenemase / Carba NP cascade. See the "shared MIC grid" block at
   the foot of this file; the free-list helpers above it are SUPERSEDED and no
   longer rendered or posted to.

   Superseded free-list entry model (kept for the rollback path, and because
   sql/2026-09-17_ecoli_ast_mic.sql -- which adds the entry_uuid column it
   needs -- has not been applied everywhere):

       antibiotic      ms_ast_table, method 01 active (the Disk Diffusion list)
       MIC value       text: "<=0.5", "2", "16", ">64", "0.5/9.5"
       interpretation  stated by the reader: S / I / R / SDD / N/A
       posted as       mic[<prt3 record id>][<entry uuid>][code|value|interp]

   Both models store rows of rcdc_ecoli_pcr_ast_table with ast_method_id '02';
   the shared grid keys them by antimicrobial_code and needs no entry_uuid.
   =========================================================================== */

if (!function_exists('bdsis_mic_method_id')) {
    /** ms_ast_method_table id the form's "MIC" option posts. @return string */
    function bdsis_mic_method_id() { return '02'; }
}

if (!function_exists('bdsis_ast_method_label')) {
    /**
     * Display name of an AST method. ms_ast_method_table names '02' "E-Test"
     * (a gradient technique that yields an MIC); the form has always offered it
     * as "MIC", so that is what a saved record shows too.
     *
     * @param  string $methodId
     * @param  string $fallback name from ms_ast_method_table
     * @return string
     */
    function bdsis_ast_method_label($methodId, $fallback = '')
    {
        $labels = array('01' => 'Disk Diffusion', '02' => 'MIC');
        $methodId = (string)$methodId;
        return isset($labels[$methodId]) ? $labels[$methodId] : (string)$fallback;
    }
}

if (!function_exists('bdsis_mic_interpretations')) {
    /**
     * Interpretation choices: code shown in the form => value stored.
     * S / I / R are stored as the words Disk Diffusion rows already hold, so
     * every consumer (Group Report, Excel export) reads both methods alike.
     * SDD and N/A have no existing word and fit ast_result varchar(20) as-is.
     *
     * @return array
     */
    function bdsis_mic_interpretations()
    {
        return array(
            'S'   => 'Susceptible',
            'I'   => 'Intermediate',
            'R'   => 'Resistant',
            'SDD' => 'SDD',
            'N/A' => 'N/A',
        );
    }
}

if (!function_exists('bdsis_mic_interpretation_labels')) {
    /** Option text for each code. @return array */
    function bdsis_mic_interpretation_labels()
    {
        return array(
            'S'   => 'S — Susceptible',
            'I'   => 'I — Intermediate',
            'R'   => 'R — Resistant',
            'SDD' => 'SDD — Susceptible-dose dependent',
            'N/A' => 'N/A — Not applicable',
        );
    }
}

if (!function_exists('bdsis_mic_normalize_value')) {
    /**
     * Normalise an MIC value, or null when it is not one.
     *
     * Accepted grammar (whitespace and Unicode variants normalised first):
     *   comparator?  number  (- number)?  ( / number  (- number)? )?  ^?
     *
     * Where
     *   comparator  <=, >=, <, >, =, or the Unicode ≤ / ≥ (both mapped to ASCII)
     *   number      integer or decimal (e.g. 0.12, 16, 64)
     *   -           optional range connector, e.g. 0.12-0.5
     *   /           optional combination agent split, e.g. 0.5/9.5, 0.12-0.5/2-4
     *   ^           optional trailing marker as it appears on the client's
     *               reference sheet (e.g. 16^, 2^, 0.12-0.5^). Its semantics
     *               are still to be confirmed with the client, so it is only
     *               STORED here -- no interpretation logic depends on it. Once
     *               the meaning is settled, add the classifier in a follow-up
     *               without changing this normalizer's contract.
     *
     * Anything longer than the column (10 characters) is refused rather than
     * truncated -- the MIC value lives in rcdc_ecoli_pcr_ast_table.zone_diameter
     * which is varchar(10) (see sql/2026-09-17_ecoli_ast_mic.sql), so a longer
     * string would be silently clipped by MySQL.
     *
     * @param  mixed $value
     * @return string|null
     */
    function bdsis_mic_normalize_value($value)
    {
        if (is_array($value)) { return null; }
        $v = trim((string)$value);
        $v = str_replace(array("\xE2\x89\xA4", "\xE2\x89\xA5", ' ', "\t"), array('<=', '>=', '', ''), $v);
        if ($v === '') { return null; }
        /* Build the pattern in parts so the intent stays readable. */
        $num  = '\d+(\.\d+)?';
        $rng  = $num . '(-' . $num . ')?';
        if (!preg_match('~^(<=|>=|<|>|=)?' . $rng . '(/' . $rng . ')?\^?$~', $v)) { return null; }
        if (strlen($v) > 10) { return null; }
        return $v;
    }
}

if (!function_exists('bdsis_uuid_v4')) {
    /** A random RFC 4122 version-4 UUID. @return string */
    function bdsis_uuid_v4()
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $x = bin2hex($b);
        return substr($x, 0, 8) . '-' . substr($x, 8, 4) . '-' . substr($x, 12, 4) . '-' . substr($x, 16, 4) . '-' . substr($x, 20, 12);
    }
}

if (!function_exists('bdsis_uuid_v4_valid')) {
    /** @return bool */
    function bdsis_uuid_v4_valid($value)
    {
        return is_string($value)
            && (bool)preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~', $value);
    }
}

if (!function_exists('bdsis_mic_agents')) {
    /**
     * Antibiotics offered for an MIC entry, method-aware.
     *
     * MIC and Disk Diffusion share ms_ast_table -- the method is a column,
     * not a schema. Prefer the caller's own method-02 (MIC) rows: an admin
     * who has seeded MIC breakpoints via BDSIS/masters/mic.php gets those
     * antibiotics offered in the MIC picker. When no MIC rows exist (the
     * initial state, and today's state for BDSIS), fall back to the DD list
     * (method-01) so behaviour is byte-identical to before this refactor --
     * the picker never opens empty.
     *
     * "Active" is filtered via status='1' in both branches, matching every
     * other read of ms_ast_table. code => name, alphabetical by name.
     *
     * @param  mysqli $bdsis
     * @return array
     */
    function bdsis_mic_agents($bdsis)
    {
        $out = array();
        if (!($bdsis instanceof mysqli)) { return $out; }

        /* Prefer MIC-specific master rows when the master has been seeded
           with them (ast_method_id='02'). */
        $res = $bdsis->query("SELECT code, antimicrobial_agent FROM ms_ast_table
                                WHERE ast_method_id='" . bdsis_mic_method_id() . "' AND status='1'
                                ORDER BY antimicrobial_agent ASC");
        if ($res && $res->num_rows > 0) {
            while (list($code, $agent) = $res->fetch_row()) { $out[(string)$code] = trim((string)$agent); }
            $res->free();
            return $out;
        }
        if ($res) { $res->free(); }

        /* Fallback: the Disk Diffusion antibiotic list, preserving the
           behaviour the picker had before MIC master rows were introduced.
           A picker that opens empty is worse UX than one that offers the
           DD list, and the reader still supplies the interpretation. */
        $res = $bdsis->query("SELECT code, antimicrobial_agent FROM ms_ast_table
                                WHERE ast_method_id='01' AND status='1'
                                ORDER BY antimicrobial_agent ASC");
        if ($res) {
            while (list($code, $agent) = $res->fetch_row()) { $out[(string)$code] = trim((string)$agent); }
            $res->free();
        }
        return $out;
    }
}

if (!function_exists('bdsis_ecoli_prt3_owner')) {
    /**
     * Subject an E. coli pathotype record (rcdc_ecoli_pcr_result_table3) belongs
     * to, or null when there is no such record.
     *
     * @return string|null
     */
    function bdsis_ecoli_prt3_owner($bdsis, $prt3rid)
    {
        $id = trim((string)$prt3rid);
        if ($id === '' || !ctype_digit($id)) { return null; }
        $q = $bdsis->prepare("SELECT p2.subject_id
                                FROM rcdc_ecoli_pcr_result_table3 p3
                                JOIN rcdc_ecoli_pcr_result_table2 p2 ON p2.record_id = p3.prt2_record_id
                               WHERE p3.record_id = ?");
        if (!$q) { return null; }
        $n = (int)$id;
        $q->bind_param("i", $n);
        $q->execute();
        $q->bind_result($owner);
        $found = $q->fetch();
        $q->close();
        return $found ? (string)$owner : null;
    }
}

if (!function_exists('bdsis_ecoli_mic_parse')) {
    /**
     * Validate one panel's posted MIC entries.
     *
     * @param  mysqli $bdsis
     * @param  mixed  $posted  $_POST['mic'][<prt3rid>] (uuid => fields)
     * @param  string $label   panel name for messages
     * @return array 'ok' => bool, 'error' => string,
     *               'rows' => list of array('uuid','code','value','result')
     */
    function bdsis_ecoli_mic_parse($bdsis, $posted, $label)
    {
        $fail = function ($msg) { return array('ok' => false, 'error' => $msg, 'rows' => array()); };
        $agents = bdsis_mic_agents($bdsis);
        $interp = bdsis_mic_interpretations();
        $rows = array();
        $seenCode = array();
        $seenUuid = array();
        $n = 0;
        foreach ((is_array($posted) ? $posted : array()) as $uuid => $f) {
            $n++;
            if (!is_array($f)) { return $fail($label . ': MIC entry ' . $n . ' is malformed.'); }
            $code = (isset($f['code']) && !is_array($f['code'])) ? strtoupper(trim((string)$f['code'])) : '';
            $raw  = isset($f['value']) ? $f['value'] : '';
            $ic   = (isset($f['interp']) && !is_array($f['interp'])) ? strtoupper(trim((string)$f['interp'])) : '';

            if ($code === '' || !isset($agents[$code])) {
                return $fail($label . ': choose the antibiotic for MIC entry ' . $n . '.');
            }
            $name = $agents[$code];
            if (isset($seenCode[$code])) {
                return $fail($label . ': ' . $name . ' is entered more than once. Each antibiotic may appear only once.');
            }
            $value = bdsis_mic_normalize_value($raw);
            if ($value === null) {
                return $fail($label . ': enter a valid MIC value for ' . $name . ' (for example 2, <=0.5, >64 or 0.5/9.5).');
            }
            if (!isset($interp[$ic])) {
                return $fail($label . ': choose the interpretation for ' . $name . ' (S, I, R, SDD or N/A).');
            }
            /* The client's id is kept when it is a well-formed, unrepeated v4 UUID;
               otherwise the entry simply gets a fresh one. */
            $uuid = strtolower((string)$uuid);
            if (!bdsis_uuid_v4_valid($uuid) || isset($seenUuid[$uuid])) { $uuid = bdsis_uuid_v4(); }

            $seenCode[$code] = true;
            $seenUuid[$uuid] = true;
            $rows[] = array('uuid' => $uuid, 'code' => $code, 'value' => $value, 'result' => $interp[$ic]);
        }
        if (count($rows) === 0) {
            return $fail($label . ': MIC is selected but no antibiotic has been added. Add at least one, or choose another method.');
        }
        return array('ok' => true, 'error' => '', 'rows' => $rows);
    }
}

if (!function_exists('bdsis_ecoli_mic_load')) {
    /**
     * Saved MIC entries of one pathotype record, in entry order, in the schema
     * the form uses (interpretation as its code). A row with no stored id is
     * given one for display; it is written back on the next save.
     *
     * @return array list of array('id','antibioticId','antibioticName','micValue','interpretation')
     */
    function bdsis_ecoli_mic_load($bdsis, $prt3rid)
    {
        $out = array();
        $id = trim((string)$prt3rid);
        if ($id === '' || !ctype_digit($id)) { return $out; }
        $toCode = array_flip(bdsis_mic_interpretations());
        $agents = bdsis_mic_agents($bdsis);
        $q = $bdsis->prepare("SELECT entry_uuid, antimicrobial_code, zone_diameter, ast_result
                                FROM rcdc_ecoli_pcr_ast_table
                               WHERE prt3_record_id = ? AND ast_method_id = ?
                               ORDER BY record_id ASC");
        if (!$q) { return $out; }
        $n = (int)$id;
        $m = bdsis_mic_method_id();
        $q->bind_param("is", $n, $m);
        $q->execute();
        $q->bind_result($uuid, $code, $value, $result);
        while ($q->fetch()) {
            $code = (string)$code;
            $out[] = array(
                'id'             => bdsis_uuid_v4_valid((string)$uuid) ? (string)$uuid : bdsis_uuid_v4(),
                'antibioticId'   => $code,
                'antibioticName' => isset($agents[$code]) ? $agents[$code] : $code,
                'micValue'       => (string)$value,
                'interpretation' => isset($toCode[(string)$result]) ? $toCode[(string)$result] : '',
            );
        }
        $q->close();
        return $out;
    }
}

if (!function_exists('bdsis_ecoli_mic_row_html')) {
    /**
     * One MIC entry row. $entry uses the load() schema; an id of '__UUID__'
     * builds the client-side template the Add button clones.
     *
     * @return string
     */
    function bdsis_ecoli_mic_row_html($prt3rid, $entry, $agents)
    {
        $h    = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
        $p    = (string)$prt3rid;
        $u    = (string)$entry['id'];
        $cur  = (string)$entry['antibioticId'];
        $base = 'mic[' . $p . '][' . $u . ']';
        $idp  = 'mic_' . $p . '_' . $u;

        $opts = '<option value="">Select Antibiotic</option>';
        foreach ($agents as $code => $name) {
            $opts .= '<option value="' . $h($code) . '"' . ((string)$code === $cur ? ' selected' : '') . '>' . $h($name) . ' (' . $h($code) . ')</option>';
        }
        /* A saved code no longer in the active list stays visible and selected. */
        if ($cur !== '' && !isset($agents[$cur])) {
            $opts .= '<option value="' . $h($cur) . '" selected>' . $h($entry['antibioticName']) . ' (no longer active)</option>';
        }
        $iopts = '<option value="">Select</option>';
        foreach (bdsis_mic_interpretation_labels() as $code => $label) {
            $iopts .= '<option value="' . $h($code) . '"' . ($code === (string)$entry['interpretation'] ? ' selected' : '') . '>' . $h($label) . '</option>';
        }

        return '<div class="row lr-line lr-mic__row" data-mic-id="' . $h($u) . '">'
            . '<div class="col-md-5 lr-cell form-group"><label class="lr-cell-label" for="' . $h($idp) . '_code">Antibiotic:</label>'
            . '<select class="form-control lr-mic__code" name="' . $h($base) . '[code]" id="' . $h($idp) . '_code" required onchange="bdsisMicSync(this);">' . $opts . '</select></div>'
            . '<div class="col-6 col-md-3 lr-cell form-group"><label class="lr-cell-label" for="' . $h($idp) . '_value">MIC (µg/mL):</label>'
            . '<input type="text" class="form-control text-center lr-mic__value" name="' . $h($base) . '[value]" id="' . $h($idp) . '_value"'
            /* Client pattern MUST accept the same values bdsis_mic_normalize_value()
               accepts on the server, else the browser blocks entries the save path
               would happily store. Grammar mirror: optional comparator, a number,
               an optional range (a-b), an optional combination-agent split (/a-b),
               and an optional trailing "^" (client-supplied marker; semantics TBC
               with the customer, stored verbatim for now). maxlength stays at the
               column's 10-char cap. */
            . ' value="' . $h($entry['micValue']) . '" maxlength="10" required autocomplete="off" spellcheck="false" placeholder="e.g. &lt;=0.5, 16^, 0.12-0.5^"'
            . ' pattern="\s*(&lt;=|&gt;=|&lt;|&gt;|=|≤|≥)?\s*\d+(\.\d+)?(-\d+(\.\d+)?)?(\s*/\s*\d+(\.\d+)?(-\d+(\.\d+)?)?)?\s*\^?\s*"'
            . ' data-msg-pattern="Enter a number (e.g. 16), optionally with a comparator (&lt;=, &gt;=, &lt;, &gt;, =), an optional range (0.12-0.5), an optional combination-agent split (0.5/9.5), and an optional trailing ^ (e.g. 16^)." /></div>'
            . '<div class="col-6 col-md-3 lr-cell form-group"><label class="lr-cell-label" for="' . $h($idp) . '_interp">Interpretation:</label>'
            . '<select class="form-control lr-pill-select lr-mic__interp" name="' . $h($base) . '[interp]" id="' . $h($idp) . '_interp" required>' . $iopts . '</select></div>'
            . '<div class="col-md-1 lr-cell lr-cell--action">'
            . '<button type="button" class="btn lr-btn-icon lr-btn-danger-soft" title="Remove this antibiotic" aria-label="Remove this antibiotic" onclick="bdsisMicRemove(this);">'
            . '<i class="fas fa-trash-alt" aria-hidden="true"></i></button></div>'
            . '</div>';
    }
}

if (!function_exists('bdsis_ecoli_mic_panel_html')) {
    /**
     * The MIC entry panel for one pathotype record, as load-ecoli-ast.php
     * returns it into the panel's container.
     *
     * @return string
     */
    function bdsis_ecoli_mic_panel_html($bdsis, $prt3rid)
    {
        $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
        $p = trim((string)$prt3rid);
        if ($p === '' || !ctype_digit($p)) {
            return '<div class="lr-mic"><div class="lr-help"><i class="fas fa-info-circle" aria-hidden="true"></i>'
                 . '<span>Save the PCR result first; MIC entries are recorded against it.</span></div></div>';
        }
        $agents  = bdsis_mic_agents($bdsis);
        $entries = bdsis_ecoli_mic_load($bdsis, $p);
        $blank   = array('id' => '__UUID__', 'antibioticId' => '', 'antibioticName' => '', 'micValue' => '', 'interpretation' => '');

        $rows = '';
        foreach ($entries as $e) { $rows .= bdsis_ecoli_mic_row_html($p, $e, $agents); }

        return '<div class="lr-mic" data-mic-panel="' . $h($p) . '" data-mic-max="' . count($agents) . '">'
            . '<div class="lr-subcard lr-subcard--mic">'
            . '<div class="lr-subcard__head"><h4 class="lr-subcard__title">MIC Results</h4>'
            . '<span class="lr-subcard__meta">Minimum inhibitory concentration, µg/mL &middot; <span class="lr-mic__count">' . count($entries) . '</span> of ' . count($agents) . ' antibiotics</span></div>'
            . '<div class="row lr-grid-head d-none d-md-flex">'
            . '<div class="col-md-5">Antibiotic <span class="required-star" aria-hidden="true">*</span></div>'
            . '<div class="col-md-3">MIC (µg/mL) <span class="required-star" aria-hidden="true">*</span></div>'
            . '<div class="col-md-3">Interpretation <span class="required-star" aria-hidden="true">*</span></div>'
            . '<div class="col-md-1"><span class="sr-only">Remove</span></div></div>'
            . '<div class="lr-mic__rows">' . $rows . '</div>'
            . '<div class="lr-mic__empty"' . (count($entries) ? ' style="display:none;"' : '') . '>'
            . '<i class="fas fa-vial" aria-hidden="true"></i><span>No antibiotics added yet. Add each antibiotic tested by MIC.</span></div>'
            . '<div class="lr-mic__actions">'
            . '<button type="button" class="btn lr-btn-add lr-mic__add" onclick="bdsisMicAdd(this);"><i class="fas fa-plus" aria-hidden="true"></i>Add Antibiotic</button>'
            . '</div>'
            . '<template class="lr-mic__tpl">' . bdsis_ecoli_mic_row_html($p, $blank, $agents) . '</template>'
            . '<div class="lr-help"><i class="fas fa-info-circle" aria-hidden="true"></i><span>'
            . 'Enter the MIC as read, with its comparator where there is one (&lt;=0.5, 16, &gt;64; 0.5/9.5 for a combination agent). '
            . 'The interpretation is stated by the reader &mdash; it is not calculated. Each antibiotic may be entered once.</span></div>'
            . '</div></div>';
    }
}

/* ===========================================================================
   Shared MIC grid -- the E. coli AST panel's MIC method (2026-10-16).
   ---------------------------------------------------------------------------
   The Enteric Pathogen (non-E. coli) rows and the E. coli pathotype panels now
   render ONE MIC form: bdsis_bact_mic_panel_html() in includes/lab-bact-mic.php.
   Nothing in that renderer is bacteriology-specific -- every field name is
   built from the index it is handed -- so the E. coli panel passes its own
   index, bdsis_ecoli_resistance_key() ('E' . prt3_record_id), the same index
   its ESBL / AmpC / Carbapenemase / Carba NP panels already use.

   What that buys, in one place rather than two:
     * the fixed 14-antibiotic CLSI breakpoint table, interpreted as you type
     * mic_value[<idx>][<code>] / mic_result[<idx>][<code>] field names
     * the MIC phenotype screens that open the confirmatory panels
       (bdsis_bact_mic_proxy_results / MIC_PHENOTYPE in lab.php)
     * the section-aware save validation (bdsis_bact_mic_resistance_plan)

   Only the destination table differs: a bacteriology isolate writes to
   bacteriology_mic_table, an E. coli pathotype to rcdc_ecoli_pcr_ast_table
   with ast_method_id='02' (keyed by antimicrobial_code -- no entry_uuid, so
   this path does not wait on sql/2026-09-17_ecoli_ast_mic.sql).
   =========================================================================== */

if (!function_exists('bdsis_ecoli_mic_grid_load')) {
    /**
     * Saved MIC readings of one pathotype record in the shape the shared grid
     * renders and bdsis_bact_mic_parse_post() returns: code => value + result.
     *
     * Only codes the grid actually shows are returned. A row left over from
     * the superseded free-list model whose antibiotic is not on the breakpoint
     * table has nowhere to render, so it is skipped here rather than shown in
     * a cell that cannot interpret it; it is cleared on the next save, which
     * replaces the panel's rows wholesale as the Disk Diffusion path does.
     *
     * @param  mysqli $bdsis
     * @param  string $prt3rid rcdc_ecoli_pcr_result_table3.record_id
     * @return array  code => array('value','result')
     */
    function bdsis_ecoli_mic_grid_load($bdsis, $prt3rid)
    {
        $out = array();
        $id  = trim((string)$prt3rid);
        if ($id === '' || !ctype_digit($id) || !($bdsis instanceof mysqli)) { return $out; }
        $known = bdsis_bact_mic_breakpoints();
        $q = $bdsis->prepare("SELECT antimicrobial_code, zone_diameter, ast_result
                                FROM rcdc_ecoli_pcr_ast_table
                               WHERE prt3_record_id = ? AND ast_method_id = ?
                               ORDER BY record_id ASC");
        if (!$q) { return $out; }
        $n = (int)$id;
        $m = bdsis_mic_method_id();
        $q->bind_param("is", $n, $m);
        $q->execute();
        $q->bind_result($code, $value, $result);
        while ($q->fetch()) {
            $c = strtoupper(trim((string)$code));
            if ($c === '' || !isset($known[$c])) { continue; }
            $out[$c] = array('value' => (string)$value, 'result' => (string)$result);
        }
        $q->close();
        return $out;
    }
}

if (!function_exists('bdsis_ecoli_mic_grid_panel_html')) {
    /**
     * Modern responsive 4-column card grid layout for E. coli (LF / NLF) MIC testing.
     * Replaces the cramped vertical strip with a Bootstrap responsive card grid:
     * - <div class="row"> with <div class="col-xl-3 col-lg-3 col-md-4 col-sm-6 mb-3 bp-mic-row"> (4 cards per row on desktop)
     * - Each antibiotic is wrapped into a modern card container (.mic-antibiotic-card)
     * - Header: Bold antibiotic name + agent code badge
     * - Body: MIC Value input (.bp-mic-value) + Interpretation select (.bp-mic-result)
     *
     * @param  mysqli $bdsis
     * @param  string $prt3rid rcdc_ecoli_pcr_result_table3.record_id
     * @return string
     */
    function bdsis_ecoli_mic_grid_panel_html($bdsis, $prt3rid)
    {
        $p = trim((string)$prt3rid);
        if ($p === '' || !ctype_digit($p)) {
            return '<div class="col-12 lr-bact-mic mb-3">'
                 . '<div class="alert alert-info py-2 px-3 mb-0" style="font-size:0.875rem;">'
                 . '<i class="fas fa-info-circle mr-1" aria-hidden="true"></i>'
                 . '<span>Save the PCR result first; MIC readings are recorded against it.</span></div></div>';
        }

        $idx   = bdsis_ecoli_resistance_key($p);
        $saved = bdsis_ecoli_mic_grid_load($bdsis, $p);
        $bps   = bdsis_bact_mic_breakpoints();
        $hv    = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };

        $h  = '<div class="lr-bact-mic" id="bpMic'.$idx.'" data-bpidx="'.$hv($idx).'" style="display:block;">';
        $h .= '<div class="lr-subcard lr-subcard--ast lr-subcard--mic">';
        $h .= '<div class="lr-subcard__head"><h4 class="lr-subcard__title">Antimicrobial Susceptibility &mdash; MIC</h4>'
            . '<span class="lr-subcard__meta">Minimum Inhibitory Concentration (mg/L) &middot; interpreted as you type</span></div>';
        $h .= '<div class="row lr-grid-head d-none d-md-flex">'
            . '<div class="col-md-3">Antibiotic</div>'
            . '<div class="col-md-3">Method</div>'
            . '<div class="col-md-2">MIC (mg/L)</div>'
            . '<div class="col-md-4">Interpretation</div></div>';

        $j = 0;
        $nAst = count($bps);
        foreach ($bps as $code => $bp) {
            $cur    = isset($saved[$code]) ? $saved[$code] : array('value'=>'', 'result'=>'');
            $vId    = 'bpMicVal'.$idx.'_'.$code;
            $rId    = 'bpMicRes'.$idx.'_'.$code;
            $hintS  = $bp['mic_s'];
            $hintI  = $bp['mic_i'];
            $hintR  = $bp['mic_r'];
            $bpHint = 'S '.$hintS.' · I '.$hintI.' · R '.$hintR;

            $h .= '<div class="row lr-line bp-mic-row'.($j === $nAst - 1 ? ' lr-line--last' : '').'">';
            $h .= '<div class="col-md-3 lr-cell lr-cell--name"><span class="lr-agent">'.$hv($bp['agent']).'</span>'
                . '<span class="lr-agent-sub">'.$hv($code).'</span></div>';
            $h .= '<div class="col-md-3 lr-cell d-none d-md-block"><span class="lr-chip">MIC</span></div>';
            $h .= '<div class="col-6 col-md-2 lr-cell"><label class="lr-cell-label" for="'.$vId.'">MIC (mg/L)<span class="sr-only">, '.$hv($bp['agent']).'</span>:</label>'
                . '<input type="number" step="any" min="0" name="mic_value['.$idx.']['.$code.']" id="'.$vId.'"'
                . ' class="form-control text-center bp-mic-value" placeholder="Value" data-code="'.$hv($code).'"'
                . ' data-s="'.$hv((string)$bp['s']).'" data-r="'.$hv((string)$bp['r']).'"'
                . ' value="'.$hv($cur['value']).'" autocomplete="off"'
                . ' title="Breakpoints: '.$hv($bpHint).'"'
                . ' oninput="bpBactMicInterpret(this);" /></div>';
            /* Interpretation pill */
            $h .= '<div class="col-6 col-md-4 lr-cell"><label class="lr-cell-label" for="'.$rId.'">Result<span class="sr-only">, '.$hv($bp['agent']).'</span>:</label>'
                . '<input type="text" name="mic_result['.$idx.']['.$code.']" id="'.$rId.'"'
                . ' value="'.$hv($cur['result']).'" class="form-control lr-pill bp-astresult bp-mic-result"'
                . ' readonly tabindex="-1" placeholder="&ndash;" aria-label="MIC result, '.$hv($bp['agent']).'" /></div>';
            $h .= '</div>';
            $j++;
        }

        $h .= '</div></div>';
        return $h;
    }
}

if (!function_exists('bdsis_mic_master_ensure')) {
    /**
     * Idempotent: ensures ms_ast_table contains method 02 rows for all CLSI MIC
     * breakpoint antibiotics so that foreign keys in rcdc_ecoli_pcr_ast_table
     * (REFERENCES ms_ast_table(code)) succeed without manual seeding.
     *
     * @param  mysqli $bdsis
     * @return bool
     */
    function bdsis_mic_master_ensure($bdsis)
    {
        static $done = false;
        if ($done) { return true; }
        if (!($bdsis instanceof mysqli)) { return false; }
        if (!function_exists('bdsis_bact_mic_breakpoints')) { return false; }
        $bps = bdsis_bact_mic_breakpoints();
        $ins = @$bdsis->prepare("INSERT INTO ms_ast_table (code, ast_method_id, antimicrobial_agent, content, susceptible, intermediate1, intermediate2, resistance, status)
                                 VALUES (?, '02', ?, '', ?, ?, ?, ?, '1')
                                 ON DUPLICATE KEY UPDATE status='1'");
        if (!$ins) { return false; }
        foreach ($bps as $code => $bp) {
            $c = (string)$code;
            $ag = (string)$bp['agent'];
            $s = (float)$bp['s'];
            $r = (float)$bp['r'];
            $ins->bind_param("ssdddd", $c, $ag, $s, $s, $r, $r);
            @$ins->execute();
        }
        $ins->close();
        $done = true;
        return true;
    }
}

if (!function_exists('bdsis_ecoli_mic_grid_save')) {
    /**
     * Replace-semantics write of one pathotype panel's MIC readings into
     * rcdc_ecoli_pcr_ast_table. The caller has already deleted the panel's
     * prior rows (the E. coli save path clears the whole panel before writing,
     * for both methods), so this only INSERTs.
     *
     * $rows is bdsis_bact_mic_parse_post() output: code => value + result,
     * already normalised and re-interpreted server-side. An antibiotic the
     * reader left blank was not tested and is not stored, matching both the
     * Disk Diffusion path here and bdsis_bact_mic_save().
     *
     * @param  mysqli $bdsis
     * @param  int    $prt3rid
     * @param  array  $rows
     * @return bool
     */
    function bdsis_ecoli_mic_grid_save($bdsis, $prt3rid, $rows)
    {
        $id = (int)$prt3rid;
        if ($id <= 0 || !($bdsis instanceof mysqli)) { return false; }
        if (!is_array($rows) || count($rows) === 0) { return true; }
        bdsis_mic_master_ensure($bdsis);
        $method = bdsis_mic_method_id();
        $ins = $bdsis->prepare("INSERT INTO rcdc_ecoli_pcr_ast_table
                                (prt3_record_id, ast_method_id, antimicrobial_code, zone_diameter, ast_result)
                                VALUES (?,?,?,?,?)");
        if (!$ins) { return false; }
        foreach ($rows as $code => $row) {
            $value = isset($row['value']) ? (string)$row['value'] : '';
            if ($value === '') { continue; }
            $c = strtoupper((string)$code);
            $r = isset($row['result']) ? (string)$row['result'] : '';
            $ins->bind_param("issss", $id, $method, $c, $value, $r);
            if (!$ins->execute()) { $ins->close(); return false; }
        }
        $ins->close();
        return true;
    }
}

if (!function_exists('bdsis_ecoli_mic_grid_any')) {
    /**
     * True when at least one antibiotic on the grid carries a reading.
     *
     * A panel set to MIC that carries nothing is refused on save, the same way
     * the superseded free-list model refused an empty entry list: "MIC" is a
     * statement that the test was performed.
     *
     * @param  array $rows bdsis_bact_mic_parse_post() output
     * @return bool
     */
    function bdsis_ecoli_mic_grid_any($rows)
    {
        if (!is_array($rows)) { return false; }
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['value']) && trim((string)$row['value']) !== '') { return true; }
        }
        return false;
    }
}

if (!function_exists('bdsis_ecoli_mic_grid_parse')) {
    /**
     * One E. coli panel's posted MIC grid, with the reader's stated
     * interpretation honoured where they gave one.
     *
     * bdsis_bact_mic_parse_post() is authoritative about the value and derives
     * the interpretation from the CLSI breakpoints, which is right for the
     * bacteriology grid: there the Interpretation cell is a readonly pill that
     * only ever shows what the breakpoints already said.
     *
     * On the E. coli grid that cell is a dropdown the reader can change, so
     * deriving unconditionally would accept their override on screen and then
     * discard it on save. The breakpoints stay the default -- the dropdown is
     * pre-set from them as the reader types -- but a deliberate, recognised
     * choice is what gets stored. Anything unrecognised (blank, or a value
     * someone crafted) falls back to the derived interpretation, so the stored
     * result is always one of the known words.
     *
     * The confirmatory triggers are unaffected either way: the MIC phenotype
     * screens read the raw value, never the interpretation.
     *
     * @param  string $idx  'E' . prt3_record_id
     * @param  array  $post $_POST
     * @return array  code => array('value','result')
     */
    function bdsis_ecoli_mic_grid_parse($idx, $post)
    {
        $rows = bdsis_bact_mic_parse_post($idx, $post);
        $stated = (isset($post['mic_result'][$idx]) && is_array($post['mic_result'][$idx]))
            ? $post['mic_result'][$idx]
            : array();
        if (count($stated) === 0) { return $rows; }

        /* The vocabulary the dropdown offers, plus the two the superseded
           free-list model recorded, so a panel that still carries them can be
           re-saved without losing what the reader stated. */
        $allowed = array(
            'susceptible'  => 'Susceptible',
            'intermediate' => 'Intermediate',
            'resistant'    => 'Resistant',
            'sdd'          => 'SDD',
            'n/a'          => 'N/A',
        );
        foreach ($rows as $code => $row) {
            if (!isset($row['value']) || trim((string)$row['value']) === '') { continue; }
            if (!isset($stated[$code]) || is_array($stated[$code])) { continue; }
            $key = strtolower(trim((string)$stated[$code]));
            if (isset($allowed[$key])) { $rows[$code]['result'] = $allowed[$key]; }
        }
        return $rows;
    }
}
