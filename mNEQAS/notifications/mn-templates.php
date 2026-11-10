<?php
/**
 * mNEQAS - notification email template.
 *
 * ONE layout for every module/event, filled from the event registry. Keeping the
 * markup here means no module file contains an HTML email block.
 *
 * The email carries only what the recipient needs to identify the record and act
 * on it - module, facility, record label, who submitted it, when, and what is now
 * pending. No laboratory results, patient data or scores are ever included: the
 * application remains the source of truth, and the link opens the normal page,
 * which performs its own login and RBAC checks.
 */

if (!function_exists('mn_notify_render')) {

    /**
     * @param array $d module, event, facility, record, submitted_by, submitted_on,
     *                 stage, next, action, url, recipient_name
     * @return array subject, html, text
     */
    function mn_notify_render(array $d)
    {
        $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

        $subject = 'mNEQAS - ' . $d['subject'];
        if ((string)$d['facility'] !== '') { $subject .= ' (' . $d['facility'] . ')'; }

        $rows = array(
            'Module'        => $d['module'],
            'Facility'      => $d['facility'],
            'Record'        => $d['record'],
            'Stage Completed' => $d['stage'],
            'Submitted By'  => $d['submitted_by'],
            'Submitted On'  => $d['submitted_on'],
            'Next Stage'    => $d['next'],
        );

        /* ---- plain text ---- */
        $text = 'mNEQAS - ' . $d['subject'] . "\r\n\r\n";
        foreach ($rows as $k => $v) {
            if ((string)$v === '') { continue; }
            $text .= str_pad($k . ':', 18) . $v . "\r\n";
        }
        $text .= "\r\nAction Required:\r\n" . $d['action'] . "\r\n";
        if ((string)$d['url'] !== '') {
            $text .= "\r\nOpen Record:\r\n" . $d['url'] . "\r\n";
        }
        $text .= "\r\nYou are receiving this message because your mNEQAS account is responsible for the next step in this workflow.\r\n";
        $text .= "Sign in to mNEQAS to view the record. This is an automated message - please do not reply.\r\n";

        /* ---- html ---- */
        $tr = '';
        foreach ($rows as $k => $v) {
            if ((string)$v === '') { continue; }
            $tr .= '<tr>'
                 . '<td style="padding:6px 12px 6px 0;color:#6c757d;font-size:13px;white-space:nowrap;vertical-align:top;">' . $e($k) . '</td>'
                 . '<td style="padding:6px 0;color:#212529;font-size:13px;font-weight:600;">' . $e($v) . '</td>'
                 . '</tr>';
        }

        $button = '';
        if ((string)$d['url'] !== '') {
            $button = '<p style="margin:24px 0 8px;">'
                    . '<a href="' . $e($d['url']) . '" style="background:#007bff;color:#ffffff;text-decoration:none;'
                    . 'padding:10px 18px;border-radius:4px;font-size:14px;display:inline-block;">Open Record in mNEQAS</a>'
                    . '</p>'
                    . '<p style="margin:0;color:#6c757d;font-size:12px;word-break:break-all;">' . $e($d['url']) . '</p>';
        }

        $greeting = ((string)$d['recipient_name'] !== '')
            ? '<p style="margin:0 0 16px;font-size:14px;color:#212529;">Dear ' . $e($d['recipient_name']) . ',</p>'
            : '';

        $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f6f9;">'
              . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 12px;">'
              . '<tr><td align="center">'
              . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #dee2e6;border-radius:6px;font-family:Arial,Helvetica,sans-serif;">'
              . '<tr><td style="background:#007bff;color:#ffffff;padding:16px 24px;border-radius:6px 6px 0 0;font-size:16px;font-weight:bold;">mNEQAS</td></tr>'
              . '<tr><td style="padding:24px;">'
              . $greeting
              . '<p style="margin:0 0 20px;font-size:15px;color:#212529;font-weight:600;">' . $e($d['subject']) . '</p>'
              . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-top:1px solid #e9ecef;border-bottom:1px solid #e9ecef;padding:8px 0;">'
              . $tr
              . '</table>'
              . '<p style="margin:20px 0 0;font-size:13px;color:#6c757d;">Action Required</p>'
              . '<p style="margin:4px 0 0;font-size:14px;color:#212529;">' . $e($d['action']) . '</p>'
              . $button
              . '</td></tr>'
              . '<tr><td style="padding:16px 24px;background:#f8f9fa;color:#6c757d;font-size:12px;border-radius:0 0 6px 6px;">'
              . 'You are receiving this message because your mNEQAS account is responsible for the next step in this workflow. '
              . 'Sign in to mNEQAS to view the record. This is an automated message &mdash; please do not reply.'
              . '</td></tr>'
              . '</table></td></tr></table></body></html>';

        return array('subject' => $subject, 'html' => $html, 'text' => $text);
    }
}
