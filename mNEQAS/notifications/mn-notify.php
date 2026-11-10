<?php
/**
 * mNEQAS - central notification engine.
 *
 * ONE entry point for the whole application:
 *
 *     require_once("../notifications/mn-notify.php");
 *     mn_notify($conn, 'BLINDED_RECEIVED', $biid);
 *
 * Call it ONLY after the business write has actually succeeded (after commit(),
 * or after the query that sets $check = true). It then:
 *
 *     resolve the event -> read the record's facility -> resolve the recipients
 *     -> write one PENDING row per recipient -> try to deliver -> mark SENT/FAILED
 *
 * Guarantees, by design:
 *  - it NEVER throws. Every path is wrapped, so a mail problem can never fail or
 *    roll back a committed transaction, and never changes an AJAX response.
 *  - it never sends twice. uq_event_recipient (event, record_id, recipient_user_id)
 *    makes a refresh / double click / duplicate POST / cron re-run a no-op.
 *  - it never mails the user who performed the action.
 *  - it never invents an address: users without a valid email are skipped.
 *  - with MAIL_ENABLED = false it still logs, and sends nothing.
 */

require_once(__DIR__ . '/mn-events.php');
require_once(__DIR__ . '/mn-recipients.php');
require_once(__DIR__ . '/mn-templates.php');
require_once(__DIR__ . '/mn-smtp.php');

if (!function_exists('mn_notify')) {

    /** Mail configuration (config/ is gitignored). Missing file = feature off. */
    function mn_notify_config()
    {
        static $cfg = null;
        if ($cfg !== null) { return $cfg; }
        $file = __DIR__ . '/../config/mail-config.php';
        $cfg = is_readable($file) ? (array)require($file) : array('MAIL_ENABLED' => false);
        return $cfg;
    }

    /** Administrator-visible trail; never contains credentials. */
    function mn_notify_log($msg)
    {
        error_log('[mNEQAS-NOTIFY] ' . $msg);
    }

    /** Username of whoever is performing the action ('' for CLI / cron). */
    function mn_notify_actor()
    {
        if (defined('MN_AUTH_USER') && MN_AUTH_USER !== '') { return (string)MN_AUTH_USER; }
        return isset($_SESSION['userID']) ? (string)$_SESSION['userID'] : '';
    }

    /**
     * Queue (and, when enabled, send) the notification for one workflow event.
     *
     * @param mysqli $conn     the module's own connection - already committed
     * @param string $event    key from mn_notify_events()
     * @param int    $recordId the workflow record the event belongs to
     * @param array  $ctx      optional overrides: facility_id, label
     * @return bool            true when at least one notification row was queued
     */
    function mn_notify(mysqli $conn, $event, $recordId, array $ctx = array())
    {
        try {
            $events = mn_notify_events();
            if (!isset($events[$event])) {
                mn_notify_log('unknown event ' . $event);
                return false;
            }
            $def      = $events[$event];
            $recordId = (int)$recordId;
            if ($recordId <= 0) { return false; }

            // ---- record context (facility + label) ----
            $rec = mn_notify_record($conn, $def['record'], $recordId);
            if (!$rec) {
                if (!isset($ctx['facility_id'])) { return false; }
                $rec = array('facility_id' => (string)$ctx['facility_id'],
                             'label'       => isset($ctx['label']) ? (string)$ctx['label'] : ('#' . $recordId));
            }

            // ---- recipients: whoever owns the NEXT stage ----
            switch ($def['audience']) {
                case 'admin':    $users = mn_notify_rcdc_users(array(1)); break;
                case 'rcdc':     $users = mn_notify_rcdc_users(array(1, 2)); break;
                case 'facility': $users = mn_notify_facility_users($rec['facility_id']); break;
                default:         $users = array();
            }
            $actor = mn_notify_actor();
            $queued = 0;
            if (!count($users)) {
                mn_notify_log($event . ' record ' . $recordId . ': no eligible recipient (audience '
                              . $def['audience'] . ', facility ' . $rec['facility_id'] . ')');
                return false;
            }

            $cfg          = mn_notify_config();
            $facilityName = mn_notify_facility_name($rec['facility_id']);
            $actorName    = mn_notify_user_name($actor);
            $baseUrl      = isset($cfg['APPLICATION_BASE_URL']) ? rtrim((string)$cfg['APPLICATION_BASE_URL'], '/') : '';
            $url          = ($baseUrl !== '') ? $baseUrl . '/' . $def['link'] . $recordId : '';
            $now          = date('Y-m-d H:i:s');

            $ins = $conn->prepare(
                "INSERT IGNORE INTO notification_logs
                    (notification_type, module, `event`, record_id, workflow_stage, sender_user_id,
                     recipient_user_id, recipient_email, subject, body, status, created_at)
                 VALUES ('EMAIL', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)"
            );
            if (!$ins) { return false; }

            foreach ($users as $u) {
                if ($actor !== '' && strcasecmp($u['username'], $actor) === 0) { continue; }   // never self-notify

                $mail = mn_notify_render(array(
                    'module'         => $def['module'],
                    'subject'        => $def['subject'],
                    'facility'       => $facilityName,
                    'record'         => $rec['label'],
                    'stage'          => $def['stage'],
                    'next'           => $def['next'],
                    'action'         => $def['action'],
                    'submitted_by'   => $actorName,
                    'submitted_on'   => $now,
                    'url'            => $url,
                    'recipient_name' => $u['fullname'],
                ));

                $body = $mail['html'] . "\n<!--TEXT-->\n" . $mail['text'];
                $ins->bind_param(
                    'ssisssssss',
                    $def['module'], $event, $recordId, $def['stage'], $actor,
                    $u['username'], $u['email'], $mail['subject'], $body, $now
                );
                $ins->execute();
                if ($conn->affected_rows > 0) { $queued++; }
            }
            $ins->close();

            if ($queued > 0) {
                mn_notify_log($event . ' record ' . $recordId . ': queued ' . $queued . ' notification(s)');
            }

            // ---- best-effort immediate delivery ----
            if ($queued > 0 && !empty($cfg['MAIL_ENABLED']) && !empty($cfg['MAIL_SEND_INLINE'])) {
                mn_notify_flush($conn, $event, $recordId);
            }
            return ($queued > 0);

        } catch (\Throwable $e) {
            // A committed workflow must never be affected by a notification problem.
            mn_notify_log('suppressed error on ' . $event . ' record ' . (int)$recordId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Deliver queued rows. With no arguments it drains the whole queue (used by
     * cron-notifications.php); with an event/record it sends just that event.
     *
     * @return array sent, failed
     */
    function mn_notify_flush(mysqli $conn, $event = null, $recordId = null, $limit = 50)
    {
        $sent = 0; $failed = 0;
        try {
            $cfg = mn_notify_config();
            if (empty($cfg['MAIL_ENABLED'])) { return array('sent' => 0, 'failed' => 0); }
            $maxRetry = isset($cfg['MAIL_MAX_RETRY']) ? (int)$cfg['MAIL_MAX_RETRY'] : 3;

            $sql = "SELECT notification_id, recipient_email, recipient_user_id, subject, body
                      FROM notification_logs
                     WHERE status <> 'SENT' AND retry_count < ?";
            $types  = 'i';
            $params = array($maxRetry);
            if ($event !== null)    { $sql .= " AND `event` = ?";   $types .= 's'; $params[] = (string)$event; }
            if ($recordId !== null) { $sql .= ' AND record_id = ?'; $types .= 'i'; $params[] = (int)$recordId; }
            $sql .= ' ORDER BY notification_id LIMIT ' . (int)$limit;

            $st = $conn->prepare($sql);
            if (!$st) { return array('sent' => 0, 'failed' => 0); }
            $bind = array($types);
            foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
            call_user_func_array(array($st, 'bind_param'), $bind);
            $st->execute();
            $res  = $st->get_result();
            $rows = array();
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            $st->close();
            if (!count($rows)) { return array('sent' => 0, 'failed' => 0); }

            $ok = mn_smtp_open($cfg);
            if (!$ok['ok']) {
                mn_notify_mark_failed($conn, $rows, $ok['error']);
                mn_notify_log('SMTP unavailable: ' . $ok['error'] . ' (' . count($rows) . ' message(s) left queued)');
                return array('sent' => 0, 'failed' => count($rows));
            }

            $markSent = $conn->prepare("UPDATE notification_logs SET status='SENT', sent_at=NOW(), error_message=NULL WHERE notification_id=?");
            foreach ($rows as $row) {
                $parts = explode("\n<!--TEXT-->\n", $row['body'], 2);
                $html  = $parts[0];
                $text  = isset($parts[1]) ? $parts[1] : strip_tags($parts[0]);

                $r = mn_smtp_send($ok['fp'], $cfg, $row['recipient_email'], '', $row['subject'], $html, $text);
                if ($r['ok']) {
                    $markSent->bind_param('i', $row['notification_id']);
                    $markSent->execute();
                    $sent++;
                } else {
                    mn_notify_mark_failed($conn, array($row), $r['error']);
                    $failed++;
                }
            }
            $markSent->close();
            mn_smtp_close($ok['fp']);
            mn_notify_log('delivery: ' . $sent . ' sent, ' . $failed . ' failed');

        } catch (\Throwable $e) {
            mn_notify_log('suppressed delivery error: ' . $e->getMessage());
        }
        return array('sent' => $sent, 'failed' => $failed);
    }

    /** Record a delivery failure (sanitised) and count the attempt. */
    function mn_notify_mark_failed(mysqli $conn, array $rows, $error)
    {
        try {
            $error = substr(preg_replace('/\s+/', ' ', (string)$error), 0, 255);
            $st = $conn->prepare("UPDATE notification_logs
                                     SET status='FAILED', error_message=?, retry_count = retry_count + 1
                                   WHERE notification_id=?");
            if (!$st) { return; }
            foreach ($rows as $row) {
                $id = (int)$row['notification_id'];
                $st->bind_param('si', $error, $id);
                $st->execute();
            }
            $st->close();
        } catch (\Throwable $e) {
            // nothing further to do - the row simply stays PENDING/FAILED
        }
    }
}
