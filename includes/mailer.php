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

// why the last email failed, in plain words, so the admin page can show it
function mail_reason($raw) {
    $r = strtolower($raw);
    if (str_contains($r, 'authenticate') || str_contains($r, 'username and password not accepted') || str_contains($r, '535'))
        return 'Gmail rejected the login. Check MAIL_USERNAME and use the 16-letter app password (not the normal Gmail password)';
    if (str_contains($r, 'connect') || str_contains($r, 'timed out'))
        return 'Could not reach Gmail. Check the internet connection, and that php-openssl is on';
    if (str_contains($r, 'invalid address') || str_contains($r, 'from address'))
        return 'MAIL_USERNAME is not a valid email address';
    return $raw !== '' ? $raw : 'unknown error';
}
$GLOBALS['mail_last_error'] = '';

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
        $m->Password = preg_replace('/\s+/', '', MAIL_PASSWORD);   // google shows it with spaces, gmail wants it without
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
        $raw = $m && $m->ErrorInfo ? $m->ErrorInfo : $ex->getMessage();
        $GLOBALS['mail_last_error'] = mail_reason($raw);
        error_log('Mail (bcc) failed: ' . $raw);
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
        $raw = $m && $m->ErrorInfo ? $m->ErrorInfo : $ex->getMessage();
        $GLOBALS['mail_last_error'] = mail_reason($raw);
        error_log('Mail failed: ' . $raw);
        return false;
    }
}
