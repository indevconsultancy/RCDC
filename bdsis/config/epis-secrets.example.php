<?php
/**
 * Template for config/epis-secrets.php -- copy, rename, fill in.
 *
 * The real file is git-ignored; this one is committed so a new machine knows
 * which keys are expected. Never put a live credential in here.
 */
return array(
    'KEY'    => '',        // ePIS consumer key
    'SECRET' => '',        // ePIS consumer secret

    /* Optional endpoint overrides; blank uses the defaults in epis-config.php. */
    'TOKEN_URL' => '',
    'API_URL'   => '',
);
