<?php
/**
 * ePIS integration settings for BDSIS.
 *
 * No secret belongs in this file -- it is committed. The consumer key and
 * secret come from config/epis-secrets.php (git-ignored) or, failing that,
 * from a BDSIS_EPIS_<KEY> environment variable. Same split FoodSIMS uses.
 *
 * Reachability, verified 24 Sep 2026: the token endpoint resolves from the
 * open internet; the data API host resolves only inside the government
 * network, so a sync without the VPN fails at DNS. bdsis_epis_health() reports
 * the two separately so an error says which leg is down.
 */

$bdsis_epis_env = function ($key, $default = '') {
    static $secrets = null;
    if ($secrets === null) {
        $file = __DIR__ . '/epis-secrets.php';
        $secrets = is_readable($file) ? (array)@include $file : array();
    }
    if (isset($secrets[$key]) && $secrets[$key] !== '') { return $secrets[$key]; }
    $env = getenv('BDSIS_EPIS_' . $key);
    if ($env !== false && $env !== '') { return $env; }
    return $default;
};

return array(

    /* ---- OAuth2 client-credentials grant ---- */
    'token_url' => $bdsis_epis_env('TOKEN_URL', 'https://stg-sso.tech.gov.bt/oauth2/token'),
    'key'       => $bdsis_epis_env('KEY'),
    'secret'    => $bdsis_epis_env('SECRET'),

    /* ---- Notifiable disease feed ---- */
    'api_url'   => $bdsis_epis_env('API_URL', 'https://staging-datahub-apim.tech.gov.bt/epis_childbirthandparentdetailapi/1.0.0/notifiableDiseaseDetails'),

    /* ---- Network ----
       A sync is a user-triggered action, never a page load, so it may take a
       moment -- but it must not hang a browser tab indefinitely. */
    'connect_timeout' => 10,
    'timeout'         => 60,
    'verify_tls'      => true,

    /* Ceiling on one response, so a runaway feed cannot exhaust memory. */
    'max_records'     => 2000,

    /* ---- Token cache ----
       JSON on disk, mode 0600, holding the token, its absolute expiry and a
       fingerprint of the key it was minted for, so rotating the credential
       invalidates the cache instead of replaying a stale token. */
    'token_cache'     => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bdsis_epis_token.json',
    'token_skew'      => 60,

    /* ---- Dates ----
       ePIS timestamps are UTC. Bhutan is +06:00, so taking the first ten
       characters of the string puts every evening encounter on the wrong day. */
    'timezone'        => 'Asia/Thimphu',

    /* ---- Offline rehearsal ----
       A saved ePIS response used when the API is unreachable, so the pipeline
       (match -> store -> verify -> accept -> import) can be exercised without
       the VPN. Never consulted unless 'use_fixture' is true.
       Switched on with BDSIS_EPIS_USE_FIXTURE=1 in the environment rather than
       by editing this file, so a rehearsal cannot be left switched on by a
       commit. */
    'use_fixture'     => (string)$bdsis_epis_env('USE_FIXTURE', '') === '1',
    'fixture_file'    => $bdsis_epis_env('FIXTURE_FILE', __DIR__ . '/epis-sample-response.json'),
);
