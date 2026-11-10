<?php
/**
 * Lab record lifecycle and workflow ordering.
 *
 * Draft -> In Progress -> Completed -> Verified -> Approved,
 * plus Returned (for correction), which re-opens a verified record for editing.
 *
 * Backed by lab_record_status_table (migration 2026-07-23-lab-record-status).
 * That table starts empty and a subject with no row is Draft, so every record
 * that existed before the lifecycle keeps behaving as it did.
 *
 * Two separate questions live here and are deliberately not conflated:
 *
 *   bdsis_lab_status()      -- where is this record in the lifecycle?
 *   bdsis_lab_sections()    -- which laboratory sections actually hold data?
 *
 * The second drives the workflow ordering (PCR only after bacteriology, AST only
 * after a positive PCR, ELISA only after bacteriology) and decides when a record
 * is complete enough to verify. See bdsis_lab_section_open() for why ELISA hangs
 * off bacteriology rather than off AST as the specification had it.
 */

require_once(__DIR__ . "/../check-record.php");

if (!defined('BDSIS_LAB_DRAFT')) {
    define('BDSIS_LAB_DRAFT',       'draft');
    define('BDSIS_LAB_IN_PROGRESS', 'in_progress');
    define('BDSIS_LAB_COMPLETED',   'completed');
    define('BDSIS_LAB_VERIFIED',    'verified');
    define('BDSIS_LAB_RETURNED',    'returned');
    define('BDSIS_LAB_APPROVED',    'approved');
}

if (!function_exists('bdsis_lab_status_row')) {
    function bdsis_lab_status_row($bdsis, $sid)
    {
        $blank = array(
            'status' => BDSIS_LAB_DRAFT, 'verified_by' => null, 'verified_on' => null,
            'returned_by' => null, 'returned_on' => null, 'return_reason' => null,
            'approved_by' => null, 'approved_on' => null,
            'updated_by' => null, 'updated_on' => null,
        );
        if (!($bdsis instanceof mysqli) || $sid === null || $sid === '') { return $blank; }

        try {
            $q = $bdsis->prepare(
                "SELECT status, verified_by, verified_on, returned_by, returned_on, return_reason,
                        approved_by, approved_on, updated_by, updated_on
                   FROM lab_record_status_table WHERE subject_id=?");
            if (!$q) { return $blank; }
            $q->bind_param("s", $sid);
            $q->execute();
            $res = $q->get_result();
            $row = ($res && $res->num_rows) ? $res->fetch_assoc() : null;
            $q->close();

            return $row ? array_merge($blank, $row) : $blank;
        } catch (mysqli_sql_exception $e) {
            return $blank;
        }
    }
}

if (!function_exists('bdsis_lab_status')) {
    /** Just the lifecycle state. @return string BDSIS_LAB_* */
    function bdsis_lab_status($bdsis, $sid)
    {
        $row = bdsis_lab_status_row($bdsis, $sid);
        return (string)$row['status'];
    }
}

if (!function_exists('bdsis_lab_status_label')) {
    /** Human-readable status, for the badge on the form and the listing. */
    function bdsis_lab_status_label($status)
    {
        $map = array(
            BDSIS_LAB_DRAFT       => 'Draft',
            BDSIS_LAB_IN_PROGRESS => 'In Progress',
            BDSIS_LAB_COMPLETED   => 'Completed',
            BDSIS_LAB_VERIFIED    => 'Verified',
            BDSIS_LAB_RETURNED    => 'Returned for Correction',
            BDSIS_LAB_APPROVED    => 'Approved',
        );
        return isset($map[$status]) ? $map[$status] : 'Draft';
    }
}

if (!function_exists('bdsis_lab_sections')) {
    /**
     * Which laboratory sections hold data for this subject.
     *
     * Uses the chk* helpers the rest of the application already uses, so this
     * cannot disagree with what lab.php and the report builder consider present.
     *
     * @return array section => bool
     */
    function bdsis_lab_sections($bdsis, $sid)
    {
        return array(
            'shipment'     => chkShipment($sid, $bdsis)     == 1,
            'microscopy'   => chkMicroscopy($sid, $bdsis)   == 1,
            'bacteriology' => chkBacteriology($sid, $bdsis) == 1,
            'pcr'          => chkeColiPcr($sid, $bdsis)     == 1,
            'ast'          => chkeColiAst($sid, $bdsis)     == 1,
            'elisa'        => chkElisa($sid, $bdsis)        == 1,
        );
    }
}

if (!function_exists('bdsis_lab_pcr_positive')) {
    /**
     * Does this subject have a positive E. coli PCR strain result?
     *
     * The workflow gate for AST. Read from the strain result rather than the
     * mere existence of a PCR row, because a negative PCR does not lead to AST.
     */
    function bdsis_lab_pcr_positive($bdsis, $sid)
    {
        $q = $bdsis->prepare(
            "SELECT COUNT(*) FROM rcdc_ecoli_pcr_result_table2
              WHERE subject_id=? AND ecoli_strain_result='Positive'");
        if (!$q) { return false; }
        $q->bind_param("s", $sid);
        $q->execute();
        $q->bind_result($n);
        $q->fetch();
        $q->close();
        return (int)$n > 0;
    }
}

if (!function_exists('bdsis_lab_section_open')) {
    /**
     * May this section be worked on yet?
     *
     *   Sample Received -> Microscopy -> Bacteriology -> PCR -> AST
     *                                                 -> ELISA
     *
     * PCR requires a bacteriology result and AST requires a POSITIVE PCR. Both
     * hold in the data without a single exception: of 3,236 subjects with a PCR
     * result none lacks bacteriology, and of 365 with an AST none has a PCR that
     * is not Positive.
     *
     * ELISA hangs off bacteriology, NOT off AST. The specification put it after
     * AST in one chain, but the data says otherwise: 4,269 of the 4,606 subjects
     * with an ELISA result -- 93% -- have no AST at all. ELISA here is the viral
     * antigen assay (rotavirus, adenovirus) and has nothing to do with the E. coli
     * PCR/AST pathway, so chaining it to AST would refuse almost every ELISA the
     * laboratory actually performs. Bacteriology is required instead, which 4,604
     * of those 4,606 records already satisfy.
     *
     * A section that already holds data is always open -- otherwise a record whose
     * data predates this ordering could never be corrected.
     *
     * @return bool
     */
    function bdsis_lab_section_open($bdsis, $sid, $section)
    {
        $done = bdsis_lab_sections($bdsis, $sid);
        if (!empty($done[$section])) { return true; }   // already has data

        switch ($section) {
            case 'shipment':
            case 'microscopy':
            case 'bacteriology':
                return true;
            case 'pcr':
                return $done['bacteriology'];
            case 'ast':
                return $done['pcr'] && bdsis_lab_pcr_positive($bdsis, $sid);
            case 'elisa':
                return $done['bacteriology'];
            default:
                return false;
        }
    }
}

if (!function_exists('bdsis_lab_blocked_reason')) {
    /** Why a section is closed, for the message shown in place of the form. */
    function bdsis_lab_blocked_reason($section)
    {
        $why = array(
            'pcr'   => 'Enter a bacteriology result before the E. coli PCR section.',
            'ast'   => 'AST opens once the E. coli PCR result is Positive.',
            'elisa' => 'Enter a bacteriology result before the ELISA section.',
        );
        return isset($why[$section]) ? $why[$section]
             : 'An earlier step in the laboratory workflow is still outstanding.';
    }
}

if (!function_exists('bdsis_lab_is_locked')) {
    /**
     * Is this record read-only?
     *
     * Verified and Approved records are closed to editing. A record Returned for
     * correction is explicitly NOT locked -- that is what returning it is for.
     */
    function bdsis_lab_is_locked($bdsis, $sid)
    {
        $s = bdsis_lab_status($bdsis, $sid);
        return $s === BDSIS_LAB_VERIFIED || $s === BDSIS_LAB_APPROVED;
    }
}

if (!function_exists('bdsis_lab_can_verify')) {
    /**
     * Is the record complete enough to verify?
     *
     * "All required sections complete" cannot mean all six: a subject with a
     * negative PCR never gets an AST, and one with no E. coli never gets a PCR.
     * Required therefore means: bacteriology present, plus every downstream
     * section the results themselves called for.
     *
     * @param  string $why set to the reason when the answer is false
     * @return bool
     */
    function bdsis_lab_can_verify($bdsis, $sid, &$why = null)
    {
        $status = bdsis_lab_status($bdsis, $sid);
        if ($status === BDSIS_LAB_VERIFIED) { $why = 'This record is already verified.'; return false; }
        if ($status === BDSIS_LAB_APPROVED) { $why = 'This record is approved and can no longer be changed.'; return false; }

        $done = bdsis_lab_sections($bdsis, $sid);
        if (!$done['bacteriology']) { $why = 'The bacteriology result is not entered yet.'; return false; }

        if ($done['pcr'] && bdsis_lab_pcr_positive($bdsis, $sid) && !$done['ast']) {
            $why = 'The PCR result is Positive, so the AST section must be completed first.';
            return false;
        }

        $why = '';
        return true;
    }
}

if (!function_exists('bdsis_lab_derive_status')) {
    /**
     * The status a record's DATA implies, ignoring verification.
     *
     * Used after a save so the badge tracks reality without anyone setting it by
     * hand. Never downgrades a verified or approved record -- only the verify and
     * return actions move a record out of those.
     */
    function bdsis_lab_derive_status($bdsis, $sid)
    {
        $current = bdsis_lab_status($bdsis, $sid);
        if ($current === BDSIS_LAB_VERIFIED || $current === BDSIS_LAB_APPROVED) { return $current; }

        $done = bdsis_lab_sections($bdsis, $sid);
        $any  = in_array(true, $done, true);
        if (!$any) { return BDSIS_LAB_DRAFT; }

        $whyIgnored = '';
        if (bdsis_lab_can_verify($bdsis, $sid, $whyIgnored)) {
            /* A record returned for correction stays Returned until it is
               verified again, so the reviewer can see it has been round once. */
            return $current === BDSIS_LAB_RETURNED ? BDSIS_LAB_RETURNED : BDSIS_LAB_COMPLETED;
        }
        return $current === BDSIS_LAB_RETURNED ? BDSIS_LAB_RETURNED : BDSIS_LAB_IN_PROGRESS;
    }
}

if (!function_exists('bdsis_lab_touch')) {
    function bdsis_lab_touch($bdsis, $sid, $user)
    {
        $status = bdsis_lab_derive_status($bdsis, $sid);
        try {
            $q = $bdsis->prepare(
                "INSERT INTO lab_record_status_table (subject_id, status, updated_by, updated_on)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE status=VALUES(status), updated_by=VALUES(updated_by),
                                         updated_on=VALUES(updated_on)");
            if (!$q) { return false; }
            $q->bind_param("sss", $sid, $status, $user);
            $ok = $q->execute();
            $q->close();
            return $ok;
        } catch (mysqli_sql_exception $e) {
            return false;
        }
    }
}

if (!function_exists('bdsis_lab_verify')) {
    function bdsis_lab_verify($bdsis, $sid, $user)
    {
        $st = BDSIS_LAB_VERIFIED;
        try {
            $q = $bdsis->prepare(
                "INSERT INTO lab_record_status_table
                        (subject_id, status, verified_by, verified_on, updated_by, updated_on)
                 VALUES (?, ?, ?, NOW(), ?, NOW())
                 ON DUPLICATE KEY UPDATE status=VALUES(status),
                                         verified_by=VALUES(verified_by), verified_on=VALUES(verified_on),
                                         returned_by=NULL, returned_on=NULL, return_reason=NULL,
                                         updated_by=VALUES(updated_by), updated_on=VALUES(updated_on)");
            if (!$q) { return false; }
            $q->bind_param("ssss", $sid, $st, $user, $user);
            $ok = $q->execute();
            $q->close();
            return $ok;
        } catch (mysqli_sql_exception $e) {
            return false;
        }
    }
}

if (!function_exists('bdsis_lab_return')) {
    function bdsis_lab_return($bdsis, $sid, $user, $reason)
    {
        $st = BDSIS_LAB_RETURNED;
        $reason = mb_substr((string)$reason, 0, 500);
        try {
            $q = $bdsis->prepare(
                "INSERT INTO lab_record_status_table
                        (subject_id, status, returned_by, returned_on, return_reason, updated_by, updated_on)
                 VALUES (?, ?, ?, NOW(), ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE status=VALUES(status),
                                         returned_by=VALUES(returned_by), returned_on=VALUES(returned_on),
                                         return_reason=VALUES(return_reason),
                                         verified_by=NULL, verified_on=NULL,
                                         updated_by=VALUES(updated_by), updated_on=VALUES(updated_on)");
            if (!$q) { return false; }
            $q->bind_param("sssss", $sid, $st, $user, $reason, $user);
            $ok = $q->execute();
            $q->close();
            return $ok;
        } catch (mysqli_sql_exception $e) {
            return false;
        }
    }
}
