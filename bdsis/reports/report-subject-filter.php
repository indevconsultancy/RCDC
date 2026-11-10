<?php
/**
 * Shared subject-list query for the report pages.
 *
 * Both Individual Report and Group Report list the same subjects with the same
 * filters and the same facility restriction. That restriction is a security
 * control, and it has already been silently lost once on this screen (the
 * filter was appended to one variable while a different one was executed), so
 * it is defined here once rather than copied into each page.
 */

require_once(__DIR__ . "/../includes/rbac.php");

if (!function_exists('bdsis_report_subject_sql')) {
    /**
     * Build the subject-list SELECT for a report screen.
     *
     * @param  mysqli $commondb connection to commondb (health-centre lookups)
     * @param  array  $post     the request array carrying the filters
     * @param  string $acl      unused; the scope comes from bdsis_scope_clause()
     * @param  string $usercat  unused; kept so the callers need not change
     * @param  string $ctid     unused; the centre is read from the session only
     * @return string           the full SQL, ready to execute on $bdsis
     */
    function bdsis_report_subject_sql($commondb, $post, $acl, $usercat, $ctid)
    {
        $sql = "SELECT subject_id,subject_name,gender,age,contact_no,subject_category,center_id,record_updated_on
                  FROM subject_information_table WHERE subject_id!='' ";

        if (isset($post["submit"])) {
            if (isset($post["dzongkhag"]) && $post["dzongkhag"] != "") {
                $dzongkhag = $post["dzongkhag"];
                $facilist  = array();
                $facilitylist = $commondb->query(
                    "SELECT center_id FROM ms_health_center_table WHERE dzongkhag_code='"
                    . $commondb->real_escape_string($dzongkhag) . "' AND dsis=1"
                );
                if ($facilitylist) {
                    while (list($faciliti) = $facilitylist->fetch_row()) {
                        $facilist[] = $faciliti;
                    }
                }
                if (count($facilist) > 0) {
                    $sql .= " AND center_id IN ('" . implode("','", $facilist) . "')";
                } else {
                    // Dzongkhag with no participating facility: match nothing
                    // rather than silently falling through to every subject.
                    $sql .= " AND 1=0";
                }
            }
            if (isset($post["facility"]) && $post["facility"] != "") {
                $sql .= " AND center_id='" . $commondb->real_escape_string($post["facility"]) . "'";
            }
            if (isset($post["subID"]) && $post["subID"] != "") {
                $sql .= " AND subject_id='" . $commondb->real_escape_string($post["subID"]) . "'";
            }
            if (isset($post["patientName"]) && $post["patientName"] != "") {
                $sql .= " AND subject_name LIKE '%" . $commondb->real_escape_string($post["patientName"]) . "%'";
            }
        }

        /* Scoped users only ever see their own centre's subjects. Applied last so
           no filter above can widen it -- a posted `facility` or `dzongkhag` adds
           a second predicate, it does not replace this one.

           Taken from bdsis_scope_clause() rather than tested here. The test used
           to be `$acl == 3 && $usercat == 3`, which named one of the three
           facility roles: a clinician (3/2) or a nurse (3/4) matched no branch and
           so listed every subject in the country on both report screens, and a
           district health officer (4/96) likewise. */
        $sql .= bdsis_scope_clause($commondb, 'center_id');

        $sql .= " ORDER BY recorded_on DESC, subject_id DESC LIMIT 500";

        return $sql;
    }
}
