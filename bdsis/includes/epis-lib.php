<?php
/**
 * ePIS integration for BDSIS.
 *
 * ---------------------------------------------------------------------------
 * What this does, and what it deliberately does not
 * ---------------------------------------------------------------------------
 * It pulls the notifiable-disease feed, keeps the records whose ICD-11 codes
 * BDSIS acts on, and parks them in bdsis_epis_case with status 'New'. That is
 * all it does to the database. An ePIS record never becomes a subject here:
 * somebody has to open it, verify it and accept it, and the case is then
 * created through the ordinary Records wizard so it passes exactly the
 * validation, RBAC and subject-id allocation every other case passes.
 *
 * Nothing in this file throws. Every entry point returns a status array or a
 * plain value, because a disconnected VPN or an expired token must not be able
 * to break a page. BDSIS runs mysqli in exception mode, so database calls are
 * wrapped rather than trusted to return false.
 *
 * The pattern -- config/secrets split, Basic-auth client_credentials, a token
 * cached on disk with a key fingerprint, a SHA-1 content hash for identity,
 * one table with a status column -- follows the working FoodSIMS integration.
 * Two things are done differently, both deliberate:
 *
 *   1. A 401 from the data API purges the cached token and retries once.
 *      FoodSIMS replays a revoked token until its stated expiry passes.
 *   2. Sync and the accept/reject decisions are POST with a CSRF token.
 *      FoodSIMS uses GET links.
 */

require_once __DIR__ . '/../config/db-config.php';

if (!function_exists('bdsis_epis_config')) {
    /**
     * One setting from config/epis-config.php.
     *
     * @return mixed
     */
    function bdsis_epis_config($key, $default = null)
    {
        static $cfg = null;
        if ($cfg === null) {
            $f = __DIR__ . '/../config/epis-config.php';
            $cfg = is_readable($f) ? (array)@include $f : array();
        }
        return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
    }
}

if (!function_exists('bdsis_epis_table_ready')) {
    /** Has the queue table been installed? @return bool */
    function bdsis_epis_table_ready($bdsis)
    {
        static $ready = null;
        if ($ready !== null) { return $ready; }
        try {
            $r = $bdsis->query("SHOW TABLES LIKE 'bdsis_epis_case'");
            $ready = ($r && $r->num_rows > 0);
        } catch (Throwable $e) { $ready = false; }
        return $ready;
    }
}

/* ===========================================================================
   Field hygiene
   =========================================================================== */

if (!function_exists('bdsis_epis_str')) {
    /**
     * A usable string, or ''. ePIS sends the four-letter word "null" as often
     * as it sends a real null, and pads values with spaces.
     */
    function bdsis_epis_str($v, $max = 0)
    {
        if ($v === null || is_array($v)) { return ''; }
        $s = trim((string)$v);
        if ($s === '' || strcasecmp($s, 'null') === 0) { return ''; }
        if ($max > 0 && function_exists('mb_substr')) { $s = mb_substr($s, 0, $max); }
        elseif ($max > 0) { $s = substr($s, 0, $max); }
        return $s;
    }
}

if (!function_exists('bdsis_epis_date')) {
    /**
     * An ePIS timestamp as a Bhutan-local date.
     *
     * The feed is UTC ("2023-07-13T18:00:00.000+00:00"). Bhutan is +06:00, so
     * reading the first ten characters puts every encounter after 18:00 on the
     * previous day -- for an onset date that is a real surveillance error, not
     * a cosmetic one.
     *
     * @return string|null Y-m-d (or Y-m-d H:i:s), null when unparseable
     */
    function bdsis_epis_date($iso, $withTime = false)
    {
        $iso = bdsis_epis_str($iso);
        if ($iso === '') { return null; }
        try {
            $d = new DateTime($iso);
            $d->setTimezone(new DateTimeZone((string)bdsis_epis_config('timezone', 'Asia/Thimphu')));
            return $d->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('bdsis_epis_age_months')) {
    /**
     * Age in MONTHS, which is what subject_information_table.age holds.
     *
     * Measured at the encounter rather than today, so a record imported months
     * later still records the age the patient was when seen.
     *
     * @return int|null
     */
    function bdsis_epis_age_months($birthDate, $onDate = null)
    {
        if (!$birthDate) { return null; }
        try {
            $b = new DateTime($birthDate);
            $o = $onDate ? new DateTime($onDate) : new DateTime('today');
            if ($o < $b) { return null; }
            $d = $b->diff($o);
            return ($d->y * 12) + $d->m;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('bdsis_epis_gender')) {
    /** ePIS gender text mapped onto the vocabulary BDSIS stores. */
    function bdsis_epis_gender($v)
    {
        $s = strtolower(bdsis_epis_str($v));
        if ($s === '') { return ''; }
        if (strpos($s, 'female') !== false || $s === 'f') { return 'Female'; }
        if (strpos($s, 'male')   !== false || $s === 'm') { return 'Male'; }
        return '';
    }
}

if (!function_exists('bdsis_epis_mobile')) {
    /**
     * An ePIS mobile number as BDSIS stores one.
     *
     * Bhutanese mobile numbers are eight digits. ePIS commonly sends them with a
     * trunk zero ("017123456"), which BDSIS does not keep, so a single leading
     * zero is dropped when exactly eight digits remain. Anything else is passed
     * through as digits only and left for the user to correct -- guessing at a
     * number that is not the expected length would be worse than showing what
     * arrived.
     *
     * @return string
     */
    function bdsis_epis_mobile($v)
    {
        $d = preg_replace('~\D~', '', (string)$v);
        if (strlen($d) === 9 && $d[0] === '0') { $d = substr($d, 1); }
        return $d;
    }
}

if (!function_exists('bdsis_epis_dzongkhag_code')) {
    /**
     * ePIS sends a dzongkhag NAME; BDSIS stores a two-character code.
     *
     * Resolved against the shared master so the two systems cannot drift, on a
     * key stripped of everything but letters so spacing and punctuation do not
     * matter. Anything unrecognised resolves to '99' (Others) rather than being
     * guessed at.
     *
     * @return string
     */
    function bdsis_epis_dzongkhag_code($name, $commondb)
    {
        static $map = null;
        $key = strtolower(preg_replace('~[^a-z]~i', '', bdsis_epis_str($name)));
        if ($key === '') { return '99'; }
        if ($map === null) {
            $map = array();
            try {
                $r = $commondb->query("SELECT dzongkhag_code, dzongkhag_name FROM ms_dzongkhag_table");
                if ($r) {
                    while (list($c, $n) = $r->fetch_row()) {
                        $map[strtolower(preg_replace('~[^a-z]~i', '', (string)$n))] = (string)$c;
                    }
                }
            } catch (Throwable $e) { $map = array(); }
            /* Spellings the two systems disagree on. */
            if (isset($map['chhukha']) && !isset($map['chukha'])) { $map['chukha'] = $map['chhukha']; }
            if (isset($map['chukha']) && !isset($map['chhukha'])) { $map['chhukha'] = $map['chukha']; }
        }
        return isset($map[$key]) ? $map[$key] : '99';
    }
}

/* ===========================================================================
   ICD-11 matching
   =========================================================================== */

if (!function_exists('bdsis_epis_active_codes')) {
    /**
     * The ICD-11 codes BDSIS acts on, from config/epis-icd.php.
     *
     * @return array code => disease name
     */
    function bdsis_epis_active_codes()
    {
        static $codes = null;
        if ($codes !== null) { return $codes; }
        $codes = array();
        $f = __DIR__ . '/../config/epis-icd.php';
        $list = is_readable($f) ? (array)@include $f : array();
        foreach ($list as $entry) {
            if (empty($entry['active']) || empty($entry['codes'])) { continue; }
            foreach ($entry['codes'] as $c) {
                $c = strtoupper(trim((string)$c));
                if ($c !== '') { $codes[$c] = isset($entry['disease']) ? $entry['disease'] : ''; }
            }
        }
        return $codes;
    }
}

if (!function_exists('bdsis_epis_parse_icd')) {
    /**
     * The ICD-11 codes inside one ePIS diagnosis field.
     *
     * The field carries "CODE:Description" pairs separated by semicolons, and
     * one encounter can list many. Only the part before the first colon is a
     * code, and it is accepted only if it has the shape of an ICD-11 stem code
     * -- so a description that happens to contain a colon cannot smuggle in a
     * match.
     *
     * @return array upper-case codes
     */
    function bdsis_epis_parse_icd($text)
    {
        $text = bdsis_epis_str($text);
        if ($text === '') { return array(); }
        $out = array();
        foreach (preg_split('~[;\r\n]+~', $text) as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            $code = strtoupper(trim(strpos($part, ':') !== false ? substr($part, 0, strpos($part, ':')) : $part));
            if ($code !== '' && preg_match('~^[0-9A-Z]{2,8}(\.[0-9A-Z]{1,4})?$~', $code)) { $out[] = $code; }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('bdsis_epis_matched_codes')) {
    /**
     * Which configured codes this ePIS record carries.
     *
     * Whole-code equality, never a substring: 1A09 must not match 1A09.0, and
     * 1A40 must stay distinct from 1A40.Z -- they are different diseases and
     * both are configured separately.
     *
     * @return array matched codes
     */
    function bdsis_epis_matched_codes($record)
    {
        $found = array_merge(
            bdsis_epis_parse_icd(isset($record['ProvisionalDiagnosis']) ? $record['ProvisionalDiagnosis'] : ''),
            bdsis_epis_parse_icd(isset($record['NotifiableDiseases'])   ? $record['NotifiableDiseases']   : '')
        );
        if (!$found) { return array(); }
        $hits = array();
        foreach (array_keys(bdsis_epis_active_codes()) as $want) {
            if (in_array($want, $found, true)) { $hits[] = $want; }
        }
        return $hits;
    }
}

/* ===========================================================================
   Transport
   =========================================================================== */

if (!function_exists('bdsis_epis_token')) {
    /**
     * A bearer token, from cache when it is still good.
     *
     * The cache records which consumer key minted the token, so rotating the
     * credential invalidates it rather than replaying a token the new key never
     * issued.
     *
     * @param  string $err   set on failure
     * @param  bool   $fresh true to ignore and overwrite the cache
     * @return string|null
     */
    function bdsis_epis_token(&$err, $fresh = false)
    {
        $err = '';
        $key = (string)bdsis_epis_config('key');
        $sec = (string)bdsis_epis_config('secret');
        if ($key === '' || $sec === '') {
            $err = 'ePIS credentials are not configured (config/epis-secrets.php).';
            return null;
        }
        $cacheFile = (string)bdsis_epis_config('token_cache');
        $skew      = (int)bdsis_epis_config('token_skew', 60);
        $print     = substr($key, 0, 8);

        if (!$fresh && $cacheFile !== '' && is_readable($cacheFile)) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && !empty($c['token']) && !empty($c['expires'])
                && (int)$c['expires'] > time() + $skew
                && isset($c['key']) && $c['key'] === $print) {
                return (string)$c['token'];
            }
        }

        $ch = curl_init((string)bdsis_epis_config('token_url'));
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => array('Content-Type: application/x-www-form-urlencoded'),
            CURLOPT_USERPWD        => $key . ':' . $sec,   // cURL builds the Basic header
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int)bdsis_epis_config('connect_timeout', 10),
            CURLOPT_TIMEOUT        => (int)bdsis_epis_config('timeout', 60),
            CURLOPT_SSL_VERIFYPEER => (bool)bdsis_epis_config('verify_tls', true),
            CURLOPT_SSL_VERIFYHOST => bdsis_epis_config('verify_tls', true) ? 2 : 0,
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            $err = 'Could not obtain an ePIS token (HTTP ' . $code . ')'
                 . ($cerr !== '' ? ': ' . $cerr : '') . '. Check the credentials and the network.';
            @error_log('[BDSIS ePIS] ' . $err);
            return null;
        }
        $j = json_decode((string)$body, true);
        if (!is_array($j) || empty($j['access_token'])) {
            $err = 'The token response was not valid JSON or carried no access_token.';
            @error_log('[BDSIS ePIS] ' . $err);
            return null;
        }
        $token = (string)$j['access_token'];
        $ttl   = isset($j['expires_in']) ? (int)$j['expires_in'] : 3600;
        if ($cacheFile !== '') {
            @file_put_contents($cacheFile, json_encode(array(
                'token' => $token, 'expires' => time() + $ttl, 'key' => $print,
            )));
            @chmod($cacheFile, 0600);
        }
        return $token;
    }
}

if (!function_exists('bdsis_epis_fetch')) {
    /**
     * The notifiable-disease feed, decoded.
     *
     * The endpoint takes no parameters and returns everything, so filtering
     * happens here. A 401 is the one status worth retrying: the cached token
     * may have been revoked before its stated expiry.
     *
     * @param  string $err set on failure
     * @return array|null  list of records
     */
    function bdsis_epis_fetch(&$err)
    {
        $err = '';

        if (bdsis_epis_config('use_fixture')) {
            $f = (string)bdsis_epis_config('fixture_file');
            if (!is_readable($f)) { $err = 'Fixture mode is on but ' . basename($f) . ' is missing.'; return null; }
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j)) { $err = 'The fixture file is not valid JSON.'; return null; }
            return bdsis_epis_rows($j, $err);
        }

        $attempt = 0;
        while ($attempt < 2) {
            $attempt++;
            $token = bdsis_epis_token($err, $attempt > 1);
            if ($token === null) { return null; }

            $ch = curl_init((string)bdsis_epis_config('api_url'));
            curl_setopt_array($ch, array(
                CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token, 'Accept: application/json'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => (int)bdsis_epis_config('connect_timeout', 10),
                CURLOPT_TIMEOUT        => (int)bdsis_epis_config('timeout', 60),
                CURLOPT_SSL_VERIFYPEER => (bool)bdsis_epis_config('verify_tls', true),
                CURLOPT_SSL_VERIFYHOST => bdsis_epis_config('verify_tls', true) ? 2 : 0,
            ));
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            /* Once: the token may have been revoked early. Purge and retry. */
            if ($code === 401 && $attempt < 2) {
                $cache = (string)bdsis_epis_config('token_cache');
                if ($cache !== '') { @unlink($cache); }
                continue;
            }

            if ($body === false || $code !== 200) {
                $err = 'The ePIS request failed (HTTP ' . $code . ')'
                     . ($cerr !== '' ? ': ' . $cerr : '')
                     . '. The VPN may be disconnected or the service unavailable.';
                @error_log('[BDSIS ePIS] ' . $err);
                return null;
            }
            $j = json_decode((string)$body, true);
            if (!is_array($j)) {
                $err = 'ePIS returned a malformed (non-JSON) response.';
                @error_log('[BDSIS ePIS] ' . $err);
                return null;
            }
            return bdsis_epis_rows($j, $err);
        }
        return null;
    }
}

if (!function_exists('bdsis_epis_rows')) {
    /**
     * The record list inside whatever envelope the feed used.
     *
     * @return array|null
     */
    function bdsis_epis_rows($j, &$err)
    {
        $rows = isset($j['notifiableDiseases']['notifiableDisease'])
              ? $j['notifiableDiseases']['notifiableDisease']
              : (isset($j['notifiableDisease']) ? $j['notifiableDisease'] : $j);

        /* A single record may arrive unwrapped. */
        if (is_array($rows) && isset($rows['UHID'])) { $rows = array($rows); }
        if (!is_array($rows)) { $err = 'The ePIS response did not contain a record list.'; return null; }

        $rows = array_values(array_filter($rows, 'is_array'));
        $max  = (int)bdsis_epis_config('max_records', 2000);
        if ($max > 0 && count($rows) > $max) { $rows = array_slice($rows, 0, $max); }
        return $rows;
    }
}

if (!function_exists('bdsis_epis_fingerprint')) {
    /**
     * What makes one ePIS encounter distinct.
     *
     * The feed carries no encounter id and the same UHID recurs with different
     * onset dates, so identity is the content that would differ between two
     * genuine encounters.
     *
     * @return string sha1
     */
    function bdsis_epis_fingerprint($r)
    {
        return sha1(implode('|', array(
            strtoupper(bdsis_epis_str(isset($r['UHID']) ? $r['UHID'] : '')),
            (string)bdsis_epis_date(isset($r['SymptomsOnSetDate']) ? $r['SymptomsOnSetDate'] : '', true),
            bdsis_epis_str(isset($r['ProvisionalDiagnosis']) ? $r['ProvisionalDiagnosis'] : ''),
            bdsis_epis_str(isset($r['NotifiableDiseases']) ? $r['NotifiableDiseases'] : ''),
        )));
    }
}

/* ===========================================================================
   Sync
   =========================================================================== */

if (!function_exists('bdsis_epis_sync')) {
    /**
     * Pull the feed and park the BDSIS-relevant records.
     *
     * Re-running this is safe and is the normal case: a record already here is
     * refreshed from the feed, but its status and decision are never touched,
     * so a sync can never undo an accept or resurrect a rejection.
     *
     * @return array ok, message, fetched, matched, inserted, updated
     */
    function bdsis_epis_sync($bdsis, $commondb, $actor)
    {
        $out = array('ok' => false, 'message' => '', 'fetched' => 0, 'matched' => 0,
                     'inserted' => 0, 'updated' => 0);

        if (!bdsis_epis_table_ready($bdsis)) {
            $out['message'] = 'The bdsis_epis_case table does not exist yet -- run sql/2026-09-24_epis_pending.sql.';
            return $out;
        }

        $err  = '';
        $rows = bdsis_epis_fetch($err);
        if ($rows === null) { $out['message'] = $err; return $out; }
        $out['fetched'] = count($rows);

        $actor = bdsis_epis_str($actor, 50);
        foreach ($rows as $r) {
            $hits = bdsis_epis_matched_codes($r);
            if (!$hits) { continue; }              // not a BDSIS disease: never stored
            $out['matched']++;

            $hash  = bdsis_epis_fingerprint($r);
            $onset = bdsis_epis_date(isset($r['SymptomsOnSetDate']) ? $r['SymptomsOnSetDate'] : '');
            $adm   = bdsis_epis_date(isset($r['AdmissionDate']) ? $r['AdmissionDate'] : '');
            $dob   = bdsis_epis_date(isset($r['BirthDate']) ? $r['BirthDate'] : '');
            $odate = bdsis_epis_date(isset($r['OutcomeDate']) ? $r['OutcomeDate'] : '');
            $dzName = bdsis_epis_str(isset($r['PremanentDzongkhag']) ? $r['PremanentDzongkhag'] : '');
            if ($dzName === '') { $dzName = bdsis_epis_str(isset($r['PresentDzongkhag']) ? $r['PresentDzongkhag'] : ''); }

            $vals = array(
                'record_hash'           => $hash,
                'epis_uid'              => bdsis_epis_str(isset($r['Id']) ? $r['Id'] : (isset($r['EPISID']) ? $r['EPISID'] : ''), 60),
                'uhid'                  => bdsis_epis_str(isset($r['UHID']) ? $r['UHID'] : '', 30),
                'patient_name'          => bdsis_epis_str(isset($r['PatientName']) ? $r['PatientName'] : '', 100),
                'birth_date'            => $dob,
                'gender'                => bdsis_epis_gender(isset($r['GenderText']) ? $r['GenderText'] : ''),
                'mobile_no'             => bdsis_epis_str(isset($r['MobileNo']) ? $r['MobileNo'] : '', 20),
                'permanent_dzongkhag'   => bdsis_epis_str(isset($r['PremanentDzongkhag']) ? $r['PremanentDzongkhag'] : '', 60),
                'present_dzongkhag'     => bdsis_epis_str(isset($r['PresentDzongkhag']) ? $r['PresentDzongkhag'] : '', 60),
                'dzongkhag_code'        => bdsis_epis_dzongkhag_code($dzName, $commondb),
                'provisional_diagnosis' => bdsis_epis_str(isset($r['ProvisionalDiagnosis']) ? $r['ProvisionalDiagnosis'] : ''),
                'notifiable_diseases'   => bdsis_epis_str(isset($r['NotifiableDiseases']) ? $r['NotifiableDiseases'] : ''),
                'matched_codes'         => bdsis_epis_str(implode(',', $hits), 120),
                'symptoms_onset_date'   => $onset,
                'admission_date'        => $adm,
                'other_chief_complaint' => bdsis_epis_str(isset($r['OtherChiefComplaint']) ? $r['OtherChiefComplaint'] : ''),
                'is_hospitalized'       => bdsis_epis_str(isset($r['IsHospitalized']) ? $r['IsHospitalized'] : '', 10),
                'hospital_name'         => bdsis_epis_str(isset($r['HospitalName']) ? $r['HospitalName'] : '', 100),
                'outcome'               => bdsis_epis_str(isset($r['Outcome']) ? $r['Outcome'] : '', 60),
                'outcome_date'          => $odate,
                'laboratory_results'    => bdsis_epis_str(isset($r['LaboratoryResults']) ? $r['LaboratoryResults'] : ''),
                'raw_payload'           => json_encode($r, JSON_UNESCAPED_UNICODE),
            );

            try {
                $q = $bdsis->prepare("SELECT id FROM bdsis_epis_case WHERE record_hash=? LIMIT 1");
                $q->bind_param('s', $hash);
                $q->execute();
                $q->bind_result($existingId);
                $found = $q->fetch();
                $q->close();

                if ($found) {
                    /* Refresh what ePIS owns; never the decision. */
                    $sets = array(); $types = ''; $args = array();
                    foreach ($vals as $col => $v) {
                        if ($col === 'record_hash') { continue; }
                        $sets[] = "`$col`=?"; $types .= 's'; $args[] = $v;
                    }
                    $sets[] = "`last_seen_on`=NOW()";
                    $sql = "UPDATE bdsis_epis_case SET " . implode(',', $sets) . " WHERE id=?";
                    $types .= 'i'; $args[] = (int)$existingId;
                    $u = $bdsis->prepare($sql);
                    $u->bind_param($types, ...$args);
                    $u->execute();
                    $u->close();
                    $out['updated']++;
                } else {
                    $cols = array_keys($vals);
                    $sql  = "INSERT IGNORE INTO bdsis_epis_case (`" . implode('`,`', $cols)
                          . "`,`status`,`synced_on`,`last_seen_on`,`synced_by`) VALUES ("
                          . rtrim(str_repeat('?,', count($cols)), ',') . ",'New',NOW(),NOW(),?)";
                    $i = $bdsis->prepare($sql);
                    $types = str_repeat('s', count($cols)) . 's';
                    $args  = array_values($vals); $args[] = $actor;
                    $i->bind_param($types, ...$args);
                    $i->execute();
                    $affected = $bdsis->affected_rows;
                    $i->close();
                    if ($affected > 0) { $out['inserted']++; } else { $out['updated']++; }
                }
            } catch (Throwable $e) {
                @error_log('[BDSIS ePIS] store failed: ' . $e->getMessage());
                $out['message'] = 'Some records could not be stored; see the error log.';
            }
        }

        $out['ok'] = true;
        if ($out['message'] === '') {
            $out['message'] = 'Synced from ePIS: ' . $out['fetched'] . ' record(s) received, '
                            . $out['matched'] . ' matched the BDSIS ICD-11 codes ('
                            . $out['inserted'] . ' new, ' . $out['updated'] . ' updated).';
        }
        return $out;
    }
}

/* ===========================================================================
   Reading the queue
   =========================================================================== */

if (!function_exists('bdsis_epis_counts')) {
    /** @return array status => count, plus 'total' -- within the caller's scope */
    function bdsis_epis_counts($bdsis, $commondb = null)
    {
        $out = array('New' => 0, 'Accepted' => 0, 'Rejected' => 0, 'Imported' => 0, 'total' => 0);
        if (!bdsis_epis_table_ready($bdsis)) { return $out; }
        $scope = $commondb ? bdsis_epis_scope_clause($bdsis, $commondb) : '';
        try {
            $r = $bdsis->query("SELECT status, COUNT(*) FROM bdsis_epis_case WHERE 1=1" . $scope . " GROUP BY status");
            while ($r && (list($s, $n) = $r->fetch_row())) {
                $out[(string)$s] = (int)$n;
                $out['total'] += (int)$n;
            }
        } catch (Throwable $e) { /* leave zeros */ }
        return $out;
    }
}

if (!function_exists('bdsis_epis_cases')) {
    /**
     * Queue rows, pending first.
     *
     * @param  array $f status, q (UHID or name), limit
     * @return array
     */
    function bdsis_epis_cases($bdsis, $commondb, $f = array())
    {
        if (!bdsis_epis_table_ready($bdsis)) { return array(); }
        $where = array('1=1' . bdsis_epis_scope_clause($bdsis, $commondb));
        $types = ''; $args = array();
        $status = isset($f['status']) ? bdsis_epis_str($f['status'], 20) : '';
        if ($status !== '' && in_array($status, array('New', 'Accepted', 'Rejected', 'Imported'), true)) {
            $where[] = 'status=?'; $types .= 's'; $args[] = $status;
        }
        $q = isset($f['q']) ? bdsis_epis_str($f['q'], 60) : '';
        if ($q !== '') {
            $where[] = '(uhid LIKE ? OR patient_name LIKE ?)';
            $types .= 'ss'; $args[] = '%' . $q . '%'; $args[] = '%' . $q . '%';
        }
        $limit = isset($f['limit']) ? max(1, min(500, (int)$f['limit'])) : 200;

        $sql = "SELECT * FROM bdsis_epis_case WHERE " . implode(' AND ', $where)
             . " ORDER BY (status='New') DESC, symptoms_onset_date DESC, id DESC LIMIT " . $limit;
        try {
            $st = $bdsis->prepare($sql);
            if ($types !== '') { $st->bind_param($types, ...$args); }
            $st->execute();
            $res = $st->get_result();
            $rows = array();
            while ($row = $res->fetch_assoc()) { $rows[] = $row; }
            $st->close();
            return $rows;
        } catch (Throwable $e) {
            @error_log('[BDSIS ePIS] list failed: ' . $e->getMessage());
            return array();
        }
    }
}

if (!function_exists('bdsis_epis_case')) {
    /** One queue row. @return array|null */
    function bdsis_epis_case($bdsis, $id)
    {
        if (!bdsis_epis_table_ready($bdsis)) { return null; }
        $id = (int)$id;
        if ($id <= 0) { return null; }
        try {
            $st = $bdsis->prepare("SELECT * FROM bdsis_epis_case WHERE id=? LIMIT 1");
            $st->bind_param('i', $id);
            $st->execute();
            $res = $st->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $st->close();
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }
}

/* ===========================================================================
   Decisions
   =========================================================================== */

if (!function_exists('bdsis_epis_set_status')) {
    /**
     * Accept or reject a pending record.
     *
     * Only a 'New' row can be decided, guarded both here and in the UPDATE, so
     * a double submit or a replayed request cannot decide the same record twice
     * or overturn an earlier decision.
     *
     * @return array ok, message
     */
    function bdsis_epis_set_status($bdsis, $id, $status, $actor, $reason = '')
    {
        if (!in_array($status, array('Accepted', 'Rejected'), true)) {
            return array('ok' => false, 'message' => 'Unrecognised decision.');
        }
        $row = bdsis_epis_case($bdsis, $id);
        if ($row === null) { return array('ok' => false, 'message' => 'That ePIS record no longer exists.'); }
        if ($row['status'] !== 'New') {
            return array('ok' => false, 'message' => 'This ePIS record has already been marked ' . $row['status'] . '.');
        }
        try {
            $st = $bdsis->prepare("UPDATE bdsis_epis_case
                                      SET status=?, decided_by=?, decided_on=NOW(), decision_reason=?
                                    WHERE id=? AND status='New'");
            $a = bdsis_epis_str($actor, 50);
            $r = bdsis_epis_str($reason, 255);
            $i = (int)$id;
            $st->bind_param('sssi', $status, $a, $r, $i);
            $st->execute();
            $changed = $bdsis->affected_rows;
            $st->close();
            if ($changed < 1) { return array('ok' => false, 'message' => 'The record was decided by someone else a moment ago.'); }
            return array('ok' => true, 'message' => 'ePIS record marked ' . $status . '.');
        } catch (Throwable $e) {
            @error_log('[BDSIS ePIS] decision failed: ' . $e->getMessage());
            return array('ok' => false, 'message' => 'The decision could not be saved.');
        }
    }
}

if (!function_exists('bdsis_epis_mark_imported')) {
    /**
     * Tie an accepted ePIS record to the BDSIS subject created from it.
     *
     * Called once the case has been saved through the ordinary Records wizard.
     * linked_subject_id is unique, so the same ePIS record cannot be imported
     * twice even if two people had the wizard open.
     *
     * @return array ok, message
     */
    function bdsis_epis_mark_imported($bdsis, $id, $subjectId, $actor)
    {
        $row = bdsis_epis_case($bdsis, $id);
        if ($row === null) { return array('ok' => false, 'message' => 'That ePIS record no longer exists.'); }
        if ($row['status'] === 'Imported') {
            return array('ok' => false, 'message' => 'Already imported as ' . $row['linked_subject_id'] . '.');
        }
        if ($row['status'] !== 'Accepted') {
            return array('ok' => false, 'message' => 'Only an accepted ePIS record can be imported.');
        }
        try {
            $st = $bdsis->prepare("UPDATE bdsis_epis_case
                                      SET status='Imported', linked_subject_id=?, imported_on=NOW(), imported_by=?
                                    WHERE id=? AND status='Accepted'");
            $s = bdsis_epis_str($subjectId, 15);
            $a = bdsis_epis_str($actor, 50);
            $i = (int)$id;
            $st->bind_param('ssi', $s, $a, $i);
            $st->execute();
            $changed = $bdsis->affected_rows;
            $st->close();
            if ($changed < 1) { return array('ok' => false, 'message' => 'The ePIS record was not in a state to import.'); }
            return array('ok' => true, 'message' => 'Imported as ' . $subjectId . '.');
        } catch (Throwable $e) {
            @error_log('[BDSIS ePIS] import link failed: ' . $e->getMessage());
            /* The unique key on linked_subject_id is the last line of defence
               against two notifications claiming one subject -- say which one
               already holds it rather than reporting a nameless failure. */
            if (stripos($e->getMessage(), 'uniq_linked_subject') !== false
                || stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return array('ok' => false, 'message' => 'Case ' . $subjectId
                    . ' is already linked to another ePIS notification.');
            }
            return array('ok' => false, 'message' => 'The import could not be recorded.');
        }
    }
}

/* ===========================================================================
   Prefill
   =========================================================================== */

if (!function_exists('bdsis_epis_prefill')) {
    /**
     * The Records wizard fields an accepted ePIS record can fill in.
     *
     * Only fields ePIS genuinely carries. Health facility is NOT among them:
     * the case belongs to the centre the user is entitled to register at, which
     * the wizard decides server-side, and ePIS names a hospital in free text.
     * Nor is anything laboratory-related: ePIS reports an encounter, not a
     * specimen.
     *
     * @return array wizard field name => value
     */
    function bdsis_epis_prefill($row)
    {
        if (!is_array($row)) { return array(); }
        $age = bdsis_epis_age_months(
            isset($row['birth_date']) ? $row['birth_date'] : null,
            !empty($row['admission_date']) ? $row['admission_date'] : (!empty($row['symptoms_onset_date']) ? $row['symptoms_onset_date'] : null)
        );
        $out = array(
            'cname'    => (string)($row['patient_name'] ?? ''),
            'cgen'     => (string)($row['gender'] ?? ''),
            'dob'      => (string)($row['birth_date'] ?? ''),
            'cage'     => $age === null ? '' : (string)$age,
            'cno'      => bdsis_epis_mobile((string)($row['mobile_no'] ?? '')),
            'district' => (string)($row['dzongkhag_code'] ?? ''),
            'dohv'     => (string)($row['admission_date'] ?? ''),
            'doio'     => (string)($row['symptoms_onset_date'] ?? ''),
            'admission_date' => (string)($row['admission_date'] ?? ''),
            'hospital_name'  => (string)($row['hospital_name'] ?? ''),
        );
        /* ePIS records an encounter that a clinician notified: OPD unless it
           says the patient was admitted. */
        $hosp = strtolower((string)($row['is_hospitalized'] ?? ''));
        if ($hosp !== '') { $out['ccli_status'] = (strpos($hosp, 'y') === 0 || $hosp === 'true' || $hosp === '1') ? 'IPD' : 'OPD'; }
        return array_filter($out, function ($v) { return $v !== '' && $v !== null; });
    }
}

if (!function_exists('bdsis_epis_health')) {
    /**
     * Which leg of the integration is up.
     *
     * The token endpoint is on the open internet; the data API is published
     * inside the government network. Reporting them separately turns "sync
     * failed" into "the VPN is down".
     *
     * @return array token => bool/message, api => bool/message
     */
    function bdsis_epis_health()
    {
        $out = array();
        $err = '';
        $t = bdsis_epis_token($err);
        $out['token'] = array('ok' => $t !== null, 'message' => $t !== null ? 'Credentials accepted.' : $err);

        $host = parse_url((string)bdsis_epis_config('api_url'), PHP_URL_HOST);
        $resolves = $host ? (gethostbyname($host) !== $host) : false;
        $out['api'] = array(
            'ok' => $resolves,
            'message' => $resolves
                ? 'The ePIS host resolves from here.'
                : 'The ePIS host (' . $host . ') does not resolve -- connect the VPN.',
        );
        return $out;
    }
}

if (!function_exists('bdsis_epis_scope_clause')) {
    /**
     * Which ePIS rows this role may see, as a SQL fragment.
     *
     * ePIS names a dzongkhag, never a health centre, so the ordinary
     * bdsis_scope_clause() (which keys on center_id) does not apply. A national
     * role sees the whole queue; a district or facility role sees the
     * notifications for its own dzongkhag, which is the closest true equivalent
     * -- a hospital cannot register a case for a patient another dzongkhag
     * notified, so showing them the rest of the country's queue would only
     * offer work they cannot do.
     *
     * Returns ' AND 1=0' rather than '' when the dzongkhag cannot be resolved,
     * so a broken lookup shows nothing instead of everything.
     *
     * @return string
     */
    function bdsis_epis_scope_clause($bdsis, $commondb)
    {
        if (bdsis_scope() === BDSIS_SCOPE_NATIONAL) { return ''; }

        $ctid = bdsis_user_centre();
        if ($ctid === '') { return ' AND 1=0'; }

        static $dz = null;
        if ($dz === null) {
            $dz = '';
            try {
                $q = $commondb->prepare("SELECT dzongkhag_code FROM ms_health_center_table WHERE center_id=? LIMIT 1");
                $q->bind_param('s', $ctid);
                $q->execute();
                $q->bind_result($code);
                if ($q->fetch()) { $dz = (string)$code; }
                $q->close();
            } catch (Throwable $e) { $dz = ''; }
        }
        if ($dz === '') { return ' AND 1=0'; }
        return " AND dzongkhag_code='" . $bdsis->real_escape_string($dz) . "'";
    }
}

if (!function_exists('bdsis_epis_can_see')) {
    /** May this role open this particular ePIS row? @return bool */
    function bdsis_epis_can_see($row, $bdsis, $commondb)
    {
        if (!is_array($row)) { return false; }
        $clause = bdsis_epis_scope_clause($bdsis, $commondb);
        if ($clause === '') { return true; }
        if ($clause === ' AND 1=0') { return false; }
        return preg_match("~dzongkhag_code='(.*)'~", $clause, $m)
            && (string)$m[1] === (string)($row['dzongkhag_code'] ?? '');
    }
}
