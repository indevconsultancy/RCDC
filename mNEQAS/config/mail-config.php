<?php
/**
 * mNEQAS — mail / notification configuration.
 *
 * This file lives in config/, which .gitignore already excludes, so the SMTP
 * credentials are never committed. config/mail-config.sample.php is the tracked
 * template; copy it here and fill in the real values on each environment.
 *
 * MAIL_ENABLED = false turns notification DELIVERY off. Workflow saves and
 * notification logging continue exactly as before; queued rows simply stay
 * PENDING until mail is enabled again.
 */
return array(
   // 'MAIL_ENABLED'         => true,

   // 'SMTP_HOST'            => 'smtp.gmail.com',
   // 'SMTP_PORT'            => 587,
  //  'SMTP_ENCRYPTION'      => 'tls',          // 'tls' (STARTTLS, port 587), 'ssl' (port 465) or '' (none)
  //  'SMTP_AUTH'            => true,
  //  'SMTP_USERNAME'        => 'anupampal1307@gmail.com',
  //  'SMTP_PASSWORD'        => 'tkfl kbmp kdan jjav',
   // 'SMTP_TIMEOUT'         => 15,             // seconds per socket operation

  //  'MAIL_FROM_EMAIL'      => 'anupampal1307@gmail.com',
  //  'MAIL_FROM_NAME'       => 'MNEQAS - RCDC',

   // 'APPLICATION_BASE_URL' => 'http://localhost/mNEQAS',

    // Try to deliver in the same request, right after the business transaction
    // has committed. Failures are logged and retried by cron-notifications.php;
    // set to false to queue only (pure cron-driven delivery).
  //  'MAIL_SEND_INLINE'     => true,
   // 'MAIL_MAX_RETRY'       => 3,
);
