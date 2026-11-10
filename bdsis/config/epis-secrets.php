<?php
/**
 * ePIS credentials. NOT in version control (see .gitignore).
 *
 * Copy epis-secrets.example.php to this name on each machine and fill it in.
 * Any value may instead be supplied by an environment variable named
 * BDSIS_EPIS_<KEY>, which takes precedence over nothing here but is consulted
 * when a key below is blank -- useful where the web server sets it.
 */
return array(
    'KEY'    => 'cM7Y_g6aIlRA1MSnvcPzULhQPiUa',
    'SECRET' => 'f9lX5TWoqSdvOALZx9c8m7P54Uga',

    /* Optional overrides; blank means "use the default in epis-config.php".
       Point these at production when the time comes. */
    'TOKEN_URL' => '',
    'API_URL'   => '',
);
