<?php
/*
 * MPQMS — Sample Lifecycle (6-stage chain-of-custody) helpers.
 *
 * The strict stage progression is:
 *   1. COLLECTED           — sample taken at source
 *   2. SUBMITTED           — handed over for dispatch to NMPTL
 *   3. RECEIVED_AT_NMPTL   — custody taken by the lab
 *   4. REGISTERED          — recorded in MPQMS
 *   5. IN_TESTING          — at least one lab result row carries real data
 *   6. REPORTED            — Final Result committed
 *
 * The underlying MPQMS data model stores enough information to DERIVE which
 * stages have been reached, from the fields that already live in
 * registrationtable / samplestatustable / labresulttable / finalresulttable.
 * Rather than invent a seventh status column that would duplicate state, this
 * library derives the stage set each page load and records a one-time audit
 * row (mpqms_sample_audit) when a write handler actually causes a transition.
 *
 * `mpqms_log_transition()` is idempotent: UNIQUE(sample_id, new_status) means
 * calling it twice for the same (sample, stage) is a no-op, so handlers can
 * call it freely. The audit table has no UPDATE/DELETE path in any handler,
 * so entries are effectively immutable.
 *
 * No side effects at include time.
 */

if(!function_exists('mpqms_sample_stages')){
    function mpqms_sample_stages(){
        return array(
            array('key'=>'COLLECTED',         'label'=>'Sample Collection',   'icon'=>'fa-hand-holding-medical'),
            array('key'=>'SUBMITTED',         'label'=>'Submission / Dispatch','icon'=>'fa-truck'),
            array('key'=>'RECEIVED_AT_NMPTL', 'label'=>'NMPTL Receipt',       'icon'=>'fa-inbox'),
            array('key'=>'REGISTERED',        'label'=>'Registration',        'icon'=>'fa-file-signature'),
            array('key'=>'IN_TESTING',        'label'=>'Testing',             'icon'=>'fa-vials'),
            array('key'=>'REPORTED',          'label'=>'Reporting',           'icon'=>'fa-check-circle'),
        );
    }
}

if(!function_exists('mpqms_sample_lifecycle')){
    /**
     * Derive the lifecycle state of a sample.
     *
     * @param  mysqli $conn  ndtlisdb connection
     * @param  int    $rid   registrationID
     * @return array  {registration_id, current_stage, stages:[{key, label, icon, reached, timestamp, actor, source, notes}]}
     *
     * Timestamps come from mpqms_sample_audit when a row exists; otherwise are
     * derived from the underlying data as a best-effort (so historic samples
     * still light up the timeline). `source` says which.
     */
    function mpqms_sample_lifecycle($conn, $rid){
        $rid = (int)$rid;
        $out = array('registration_id'=>$rid, 'current_stage'=>null, 'stages'=>array());
        if($rid <= 0) return $out;

        $audit = array();
        try {
            $st = mysqli_prepare($conn, "SELECT new_status, action_by_user_id, `timestamp`, notes
                                         FROM mpqms_sample_audit WHERE sample_id=? ORDER BY `timestamp` ASC");
            if($st){
                mysqli_stmt_bind_param($st, 'i', $rid);
                mysqli_stmt_execute($st);
                $res = mysqli_stmt_get_result($st);
                while($res && ($row = mysqli_fetch_assoc($res))){
                    $audit[(string)$row['new_status']] = $row;
                }
                mysqli_stmt_close($st);
            }
        } catch(\Throwable $e){ /* table may not exist on a pre-migration DB */ }

        $meta = array(
            'collected'=>null,     'collection_actor'=>null,
            'submitted'=>null,     'submitted_actor'=>null,
            'received'=>null,      'received_actor'=>null,
            'registered'=>null,    'registered_actor'=>null,
            'testing_start'=>null,
            'reported'=>null,      'reported_actor'=>null,
        );
        try {
            $st = mysqli_prepare($conn, "SELECT sampling_date, sampled_by, sent_by, entryUser, entrytimestamp FROM registrationtable WHERE registrationID=?");
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_stmt_execute($st);
            mysqli_stmt_bind_result($st, $samplingDate, $sampledBy, $sentBy, $entryUser, $entryTs);
            if(mysqli_stmt_fetch($st)){
                $meta['collected']        = (!empty($samplingDate) && $samplingDate !== '0000-00-00' && $samplingDate !== '1900-01-01') ? ($samplingDate.' 00:00:00') : null;
                $meta['collection_actor'] = $sampledBy;
                $meta['submitted_actor']  = $sentBy;
                $meta['registered']       = $entryTs;
                $meta['registered_actor'] = $entryUser;
                if(!empty($sentBy)){ $meta['submitted'] = $entryTs; }
            }
            mysqli_stmt_close($st);
        } catch(\Throwable $e){ }

        try {
            $st = mysqli_prepare($conn, "SELECT receiveDate, received_by, entryUser, entryTimestamp FROM samplestatustable WHERE registrationID=?");
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_stmt_execute($st);
            mysqli_stmt_bind_result($st, $recDate, $recBy, $recEntryUser, $recEntryTs);
            if(mysqli_stmt_fetch($st)){
                $meta['received']       = (!empty($recDate) && $recDate !== '0000-00-00' && $recDate !== '1900-01-01') ? ($recDate.' 00:00:00') : $recEntryTs;
                $meta['received_actor'] = !empty($recBy) ? $recBy : $recEntryUser;
            }
            mysqli_stmt_close($st);
        } catch(\Throwable $e){ }

        try {
            $st = mysqli_prepare($conn, "SELECT MIN(entrytimestamp) FROM labresulttable
                                         WHERE registrationID=? AND (
                                             (observation IS NOT NULL AND TRIM(observation)<>'')
                                          OR (result      IS NOT NULL AND TRIM(result)<>'')
                                          OR (standard    IS NOT NULL AND TRIM(standard)<>'')
                                         )");
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_stmt_execute($st);
            mysqli_stmt_bind_result($st, $firstResult);
            if(mysqli_stmt_fetch($st)){ $meta['testing_start'] = $firstResult; }
            mysqli_stmt_close($st);
        } catch(\Throwable $e){ }

        try {
            $st = mysqli_prepare($conn, "SELECT entryTimestamp, entryUser FROM finalresulttable WHERE registrationID=?");
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_stmt_execute($st);
            mysqli_stmt_bind_result($st, $frTs, $frUser);
            if(mysqli_stmt_fetch($st)){ $meta['reported'] = $frTs; $meta['reported_actor'] = $frUser; }
            mysqli_stmt_close($st);
        } catch(\Throwable $e){ }

        $plan = array(
            'COLLECTED'         => array('ts'=>$meta['collected'],     'actor'=>$meta['collection_actor']),
            'SUBMITTED'         => array('ts'=>$meta['submitted'],     'actor'=>$meta['submitted_actor']),
            'RECEIVED_AT_NMPTL' => array('ts'=>$meta['received'],      'actor'=>$meta['received_actor']),
            'REGISTERED'        => array('ts'=>$meta['registered'],    'actor'=>$meta['registered_actor']),
            'IN_TESTING'        => array('ts'=>$meta['testing_start'], 'actor'=>null),
            'REPORTED'          => array('ts'=>$meta['reported'],      'actor'=>$meta['reported_actor']),
        );

        $currentIdx = -1;
        foreach(mpqms_sample_stages() as $i => $def){
            $k = $def['key'];
            $auditRow = $audit[$k] ?? null;
            $ts    = $auditRow ? (string)$auditRow['timestamp']         : (string)($plan[$k]['ts']    ?? '');
            $actor = $auditRow ? (string)$auditRow['action_by_user_id'] : (string)($plan[$k]['actor'] ?? '');
            $reached = ($auditRow !== null) || !empty($plan[$k]['ts']);
            if($reached){ $currentIdx = $i; }
            $out['stages'][] = array(
                'key'       => $k,
                'label'     => $def['label'],
                'icon'      => $def['icon'],
                'reached'   => $reached,
                'timestamp' => $ts,
                'actor'     => $actor,
                'source'    => $auditRow ? 'audit' : ($reached ? 'derived' : null),
                'notes'     => $auditRow ? (string)$auditRow['notes'] : null,
            );
        }
        if($currentIdx >= 0){ $out['current_stage'] = $out['stages'][$currentIdx]['key']; }
        return $out;
    }
}

if(!function_exists('mpqms_log_transition')){
    /**
     * Record a stage transition. Idempotent via UNIQUE(sample_id, new_status).
     *
     * @return bool  true if a new row was written; false otherwise (already existed / write failed)
     */
    function mpqms_log_transition($conn, $sampleId, $newStatus, $user, $notes = ''){
        $sampleId = (int)$sampleId;
        if($sampleId <= 0 || !$conn || !($conn instanceof mysqli)) return false;
        $validKeys = array('COLLECTED','SUBMITTED','RECEIVED_AT_NMPTL','REGISTERED','IN_TESTING','REPORTED');
        if(!in_array($newStatus, $validKeys, true)) return false;
        try {
            $prev = null;
            $st = mysqli_prepare($conn, "SELECT new_status FROM mpqms_sample_audit
                                         WHERE sample_id=? ORDER BY `timestamp` DESC LIMIT 1");
            if($st){
                mysqli_stmt_bind_param($st, 'i', $sampleId);
                mysqli_stmt_execute($st);
                mysqli_stmt_bind_result($st, $prev);
                mysqli_stmt_fetch($st);
                mysqli_stmt_close($st);
            }
            $st = mysqli_prepare($conn, "INSERT IGNORE INTO mpqms_sample_audit
                                         (sample_id, previous_status, new_status, action_by_user_id, `timestamp`, notes)
                                         VALUES (?,?,?,?, NOW(), ?)");
            if(!$st) return false;
            $notes = (string)$notes;
            mysqli_stmt_bind_param($st, 'issss', $sampleId, $prev, $newStatus, $user, $notes);
            mysqli_stmt_execute($st);
            $wrote = (mysqli_stmt_affected_rows($st) > 0);
            mysqli_stmt_close($st);
            return $wrote;
        } catch(\Throwable $e){ return false; }
    }
}

if(!function_exists('mpqms_log_registration_transitions')){
    /**
     * Called from save_update_registration.php on INSERT. Logs COLLECTED (if
     * sampling_date / sampled_by present), SUBMITTED (if sent_by present), then
     * REGISTERED. All idempotent.
     */
    function mpqms_log_registration_transitions($conn, $sampleId, $user, $sampledBy, $samplingDate, $sentBy){
        $sampledBy    = trim((string)$sampledBy);
        $samplingDate = trim((string)$samplingDate);
        $sentBy       = trim((string)$sentBy);
        if($sampledBy !== '' || ($samplingDate !== '' && $samplingDate !== '1900-01-01' && $samplingDate !== '0000-00-00')){
            mpqms_log_transition($conn, $sampleId, 'COLLECTED', $user,
                'sampled_by='.$sampledBy.' sampling_date='.$samplingDate);
        }
        if($sentBy !== ''){
            mpqms_log_transition($conn, $sampleId, 'SUBMITTED', $user, 'sent_by='.$sentBy);
        }
        mpqms_log_transition($conn, $sampleId, 'REGISTERED', $user, 'registration record created');
    }
}
?>
