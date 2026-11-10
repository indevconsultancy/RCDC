<?php
/**
 * Blinded Rechecking – e-mail notification helper.
 *
 * Best-effort notification sent to the concerned facility users once the
 * blinded rechecking feedback (findings / recommendation / action) is
 * finalised. It never throws and never affects the calling save operation –
 * if anything is missing or mail() fails, it simply returns false.
 *
 * Recipients: users in rcdc_commondb.user_profile_table whose center_id matches the
 * facility of the blinded record and who have a valid email_id.
 */

if (!function_exists('blindedMonthName')) {
    function blindedMonthName($m) {
        $names = array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                       7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December');
        return isset($names[(int)$m]) ? $names[(int)$m] : $m;
    }
}

if (!function_exists('sendBlindedFeedbackNotification')) {
    /**
     * @param mysqli $conn           Active mNEQAS connection (used to read blinded_informations).
     * @param int    $biid           Blinded record id.
     * @param string $testRecipient  Optional. When set, overrides the facility recipients
     *                               with this single address (for initial testing only).
     * @return bool                  True if the mail() call was accepted for at least one recipient.
     */
    function sendBlindedFeedbackNotification($conn, $biid, $testRecipient = null) {
        try {
            $biid = (int)$biid;
            if ($biid <= 0) { return false; }

            // Facility + period for this blinded record.
            $info = $conn->query("SELECT facility_id, month, year FROM blinded_informations WHERE blinded_id = $biid");
            if (!$info || $info->num_rows === 0) { return false; }
            list($facilityId, $month, $year) = $info->fetch_row();

            // Recipients & facility name live in the live commondb. Use the same
            // per-environment credentials as the rest of the app (config/commondb-config.php).
            require(__DIR__ . '/../config/commondb-config.php');   // defines $commondb
            if (!($commondb instanceof mysqli) || $commondb->connect_error) { return false; }

            $facilityIdEsc = $commondb->real_escape_string($facilityId);

            $fnRes = $commondb->query("SELECT center_name FROM ms_health_center_table WHERE center_id = '$facilityIdEsc'");
            $centerName = ($fnRes && $fnRes->num_rows) ? $fnRes->fetch_row()[0] : $facilityId;

            $recipients = array();
            $uRes = $commondb->query("SELECT email_id FROM user_profile_table WHERE center_id = '$facilityIdEsc' AND email_id IS NOT NULL AND email_id <> ''");
            if ($uRes) {
                while ($u = $uRes->fetch_row()) {
                    if (filter_var($u[0], FILTER_VALIDATE_EMAIL)) { $recipients[] = $u[0]; }
                }
            }
            $commondb->close();

            // Testing override: send only to the supplied address when provided.
            if ($testRecipient !== null && filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
                $recipients = array($testRecipient);
            }

            if (empty($recipients)) { return false; }

            $period  = blindedMonthName($month) . " " . $year;
            $subject = "mNEQAS: Blinded Rechecking Feedback Available - $period";

            $body  = "Dear $centerName team,\r\n\r\n";
            $body .= "The blinded rechecking feedback (findings, recommendations and action) for $period ";
            $body .= "has been finalised and is now available in the mNEQAS system.\r\n\r\n";
            $body .= "Please log in to review the feedback report and take the necessary action.\r\n\r\n";
            $body .= "Regards,\r\n";
            $body .= "National Malaria Reference Laboratory (RCDC)\r\n";
            $body .= "This is an automated message - please do not reply.";

            // Program mailbox on the To: line; facility users go to Bcc so their
            // addresses are not exposed to each other.
            $fromAddr = "mneqas-noreply@rcdc.gov.bt";
            $headers  = "From: mNEQAS <$fromAddr>\r\n";
            $headers .= "Bcc: " . implode(",", $recipients) . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

            return @mail($fromAddr, $subject, $body, $headers);
        } catch (Throwable $e) {
            return false;
        }
    }
}
?>
