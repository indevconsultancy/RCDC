<?php
/**
 * mNEQAS - minimal SMTP client (dependency-free).
 *
 * The project has no PHPMailer/SMTP library (composer.json requires mpdf only)
 * and the only pre-existing mail code used PHP mail(), which cannot authenticate
 * against the configured SMTP relay. This adds ESMTP + STARTTLS/SSL + AUTH LOGIN
 * / PLAIN in one file rather than pulling in a new dependency.
 *
 * One connection can deliver several messages (mn_smtp_open -> mn_smtp_send x N
 * -> mn_smtp_close), so a workflow event with several recipients costs one SMTP
 * session. Nothing here throws: every function returns a status and the caller
 * decides what to do with it.
 */

if (!function_exists('mn_smtp_open')) {

    /** Read one complete (possibly multi-line) SMTP reply. */
    function mn_smtp_read($fp)
    {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            // the last line of a reply has a space (not '-') in position 4
            if (strlen($line) < 4 || substr($line, 3, 1) === ' ') { break; }
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) { break; }
        }
        return $out;
    }

    /** Send one command and return the reply. */
    function mn_smtp_cmd($fp, $cmd)
    {
        fwrite($fp, $cmd . "\r\n");
        return mn_smtp_read($fp);
    }

    /** Reply code of an SMTP response ('' when unreadable). */
    function mn_smtp_code($reply)
    {
        return (strlen($reply) >= 3) ? substr($reply, 0, 3) : '';
    }

    /**
     * Open an authenticated SMTP session.
     * @return array ok (bool), fp (resource|null), error (string)
     */
    function mn_smtp_open(array $cfg)
    {
        $fail = function ($msg) { return array('ok' => false, 'fp' => null, 'error' => $msg); };

        $host    = isset($cfg['SMTP_HOST']) ? (string)$cfg['SMTP_HOST'] : '';
        $port    = isset($cfg['SMTP_PORT']) ? (int)$cfg['SMTP_PORT'] : 25;
        $enc     = isset($cfg['SMTP_ENCRYPTION']) ? strtolower((string)$cfg['SMTP_ENCRYPTION']) : '';
        $timeout = isset($cfg['SMTP_TIMEOUT']) ? (int)$cfg['SMTP_TIMEOUT'] : 15;
        if ($host === '') { return $fail('SMTP host is not configured.'); }

        $scheme = ($enc === 'ssl') ? 'ssl://' : 'tcp://';
        $errNo  = 0;
        $errStr = '';
        $fp = @stream_socket_client($scheme . $host . ':' . $port, $errNo, $errStr, $timeout);
        if (!$fp) { return $fail('Cannot reach the mail server (code ' . (int)$errNo . ').'); }
        stream_set_timeout($fp, $timeout);

        if (mn_smtp_code(mn_smtp_read($fp)) !== '220') {
            fclose($fp);
            return $fail('Mail server refused the connection.');
        }

        $ehlo = 'mNEQAS';
        if (mn_smtp_code(mn_smtp_cmd($fp, 'EHLO ' . $ehlo)) !== '250') {
            fclose($fp);
            return $fail('Mail server rejected the EHLO greeting.');
        }

        if ($enc === 'tls') {
            if (mn_smtp_code(mn_smtp_cmd($fp, 'STARTTLS')) !== '220') {
                fclose($fp);
                return $fail('Mail server did not accept STARTTLS.');
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                fclose($fp);
                return $fail('TLS negotiation with the mail server failed.');
            }
            if (mn_smtp_code(mn_smtp_cmd($fp, 'EHLO ' . $ehlo)) !== '250') {
                fclose($fp);
                return $fail('Mail server rejected the EHLO greeting after TLS.');
            }
        }

        if (!empty($cfg['SMTP_AUTH'])) {
            $user = isset($cfg['SMTP_USERNAME']) ? (string)$cfg['SMTP_USERNAME'] : '';
            $pass = isset($cfg['SMTP_PASSWORD']) ? (string)$cfg['SMTP_PASSWORD'] : '';
            $r = mn_smtp_cmd($fp, 'AUTH LOGIN');
            if (mn_smtp_code($r) === '334') {
                mn_smtp_cmd($fp, base64_encode($user));
                $r = mn_smtp_cmd($fp, base64_encode($pass));
            } else {
                // fall back to AUTH PLAIN when LOGIN is not offered
                $r = mn_smtp_cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
            }
            if (mn_smtp_code($r) !== '235') {
                fclose($fp);
                // Never pass the server's auth text on: it can quote the credential.
                return $fail('The mail server rejected the configured mailbox credentials.');
            }
        }

        return array('ok' => true, 'fp' => $fp, 'error' => '');
    }

    /**
     * Deliver one message on an open session.
     * @return array ok (bool), error (string)
     */
    function mn_smtp_send($fp, array $cfg, $toEmail, $toName, $subject, $htmlBody, $textBody)
    {
        $from     = isset($cfg['MAIL_FROM_EMAIL']) ? (string)$cfg['MAIL_FROM_EMAIL'] : '';
        $fromName = isset($cfg['MAIL_FROM_NAME'])  ? (string)$cfg['MAIL_FROM_NAME']  : 'mNEQAS';

        if (mn_smtp_code(mn_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>')) !== '250') {
            return array('ok' => false, 'error' => 'Mail server rejected the sender address.');
        }
        if (mn_smtp_code(mn_smtp_cmd($fp, 'RCPT TO:<' . $toEmail . '>')) !== '250') {
            mn_smtp_cmd($fp, 'RSET');
            return array('ok' => false, 'error' => 'Mail server rejected the recipient address.');
        }
        if (mn_smtp_code(mn_smtp_cmd($fp, 'DATA')) !== '354') {
            mn_smtp_cmd($fp, 'RSET');
            return array('ok' => false, 'error' => 'Mail server did not accept the message.');
        }

        $encHdr   = function ($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; };
        $boundary = 'mneqas-' . bin2hex(random_bytes(8));

        $headers   = array();
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $encHdr($fromName) . ' <' . $from . '>';
        $headers[] = 'To: ' . (($toName !== '') ? $encHdr($toName) . ' ' : '') . '<' . $toEmail . '>';
        $headers[] = 'Subject: ' . $encHdr($subject);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@mneqas>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Auto-Submitted: auto-generated';
        $headers[] = 'X-Auto-Response-Suppress: All';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body  = '--' . $boundary . "\r\n"
               . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
               . chunk_split(base64_encode($textBody));
        $body .= '--' . $boundary . "\r\n"
               . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
               . chunk_split(base64_encode($htmlBody));
        $body .= '--' . $boundary . "--\r\n";

        // dot-stuffing: a line consisting of a single dot would terminate DATA
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', $data);

        fwrite($fp, $data . "\r\n.\r\n");
        if (mn_smtp_code(mn_smtp_read($fp)) !== '250') {
            return array('ok' => false, 'error' => 'Mail server did not accept the message body.');
        }
        return array('ok' => true, 'error' => '');
    }

    function mn_smtp_close($fp)
    {
        if (is_resource($fp)) {
            @mn_smtp_cmd($fp, 'QUIT');
            @fclose($fp);
        }
    }
}
