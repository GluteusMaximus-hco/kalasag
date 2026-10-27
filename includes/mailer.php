<?php
// sends emails using PHPMailer + gmail smtp
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

// email only works once config/mail.php exists
function mail_ready() {
    static $ready = null;
    if ($ready === null) {
        $f = __DIR__ . '/../config/mail.php';
        $ready = file_exists($f);
        if ($ready) require_once $f;
    }
    return $ready;
}

// shared gmail setup for both senders below
function mailer_new() {
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Timeout = 10;   // phpmailer waits 5 minutes by default if gmail doesnt answer, way too long
    $m->Host = MAIL_HOST;
    $m->Port = MAIL_PORT;
    if (MAIL_PASSWORD !== '') {
        $m->SMTPAuth = true;
        $m->Username = MAIL_USERNAME;
        $m->Password = MAIL_PASSWORD;
    }
    if (MAIL_SECURE !== '') $m->SMTPSecure = MAIL_SECURE;
    $m->CharSet = 'UTF-8';
    $m->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
    return $m;
}

// one email to many people, all in BCC (announcements)
function send_mail_bcc($emails, $subject, $html, $text) {
    if (!mail_ready()) return false;
    $m = null;
    try {
        $m = mailer_new();
        $m->addAddress(MAIL_USERNAME, MAIL_FROM_NAME);   // "to" is the system itself, residents are hidden in bcc
        foreach ($emails as $e) $m->addBCC($e);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;
        $m->send();
        return true;
    } catch (MailException $ex) {
        error_log('Mail (bcc) failed: ' . ($m ? $m->ErrorInfo : $ex->getMessage()));
        return false;
    }
}

function send_mail($to, $to_name, $subject, $html, $text) {
    if (!mail_ready()) return false;

    $m = null;
    try {
        $m = mailer_new();
        $m->addAddress($to, $to_name);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;   // plain text version for old email apps

        $m->send();
        return true;
    } catch (MailException $ex) {
        error_log('Mail failed: ' . ($m ? $m->ErrorInfo : $ex->getMessage()));
        return false;
    }
}
