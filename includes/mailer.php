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

function send_mail($to, $to_name, $subject, $html, $text) {
    if (!mail_ready()) return false;

    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
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
        $m->addAddress($to, $to_name);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;   // plain text version for old email apps

        $m->send();
        return true;
    } catch (MailException $ex) {
        error_log('Mail failed: ' . $m->ErrorInfo);
        return false;
    }
}
