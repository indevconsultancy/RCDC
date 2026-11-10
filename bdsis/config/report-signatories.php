<?php
/**
 * Signatories for the Stool Examination Result report.
 *
 * The report's signature block names the analyst(s) who processed the sample
 * -- taken, as before, from the processed_by recorded against each result --
 * and the officer who verifies the report. Designations are NOT reliably held
 * in commondb.ms_designation_table (it has no "Sr. Laboratory Technician" or
 * "Chief Laboratory Officer", and the profiles of the people below carry the
 * generic "Laboratory Officer"), so the client-supplied designations live here.
 *
 * Matching is by full name as it appears in user_profile_table.fullname,
 * case- and whitespace-insensitive. Anyone not listed falls back to the
 * designation on their user profile, or "-" when that is empty.
 *
 * Read by bdsis_report_signatories() in reports/report-html-builder.php.
 */
return array(
    /* Full name => designation printed under "Designation". */
    'designations' => array(
        'Ugyen Chophel'   => 'Laboratory Officer',
        'Dorji Tshering'  => 'Sr. Laboratory Technician',
        'Rinzin Wangchuk' => 'Sr. Laboratory Technician',
        'Kinley Gyem'     => 'Chief Laboratory Officer',
    ),

    /* Who may be CHOSEN in each picker on the Individual Detail Report screen.
       The designation map above deliberately still covers everyone, because an
       automatically derived analyst is looked up there too: someone who is not
       selectable as an analyst must still print with the right designation when
       the results record them as having processed the sample.

       Names must match the designation keys above. Anyone omitted from both
       lists simply cannot be picked. */
    'analysts' => array(
        'Ugyen Chophel',
        'Dorji Tshering',
        'Rinzin Wangchuk',
    ),
    'verifiers' => array(
        'Kinley Gyem',
    ),

    /* Printed under "Verified By" unless the lab workflow has recorded a
       verifier for the subject (lab_record_status_table, status = verified),
       in which case that person is named instead. */
    'verified_by' => 'Kinley Gyem',
);
