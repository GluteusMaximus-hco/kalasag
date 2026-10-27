<?php
// SMS + email notifications
// SMS goes through the "SMS Gateway for Android" app on a phone (config/sms.php)
// email goes through gmail with PHPMailer (config/mail.php)
// every try gets written to logs/notify.log so the admin can see what was sent or what failed
//
// rule: a phone with no load or gmail being down must NEVER stop a report from saving,
// so everything here catches its own errors and just logs them

require_once __DIR__ . '/mailer.php';

define('NOTIFY_LOG', __DIR__ . '/../logs/notify.log');

// ---------- settings ----------

function sms_ready() {
    static $ready = null;
    if ($ready === null) {
        $f = __DIR__ . '/../config/sms.php';
        $ready = file_exists($f);
        if ($ready) require_once $f;
        $ready = $ready && defined('SMS_ENABLED') && SMS_ENABLED;
    }
    return $ready;
}

// full link to the site for messages, uses APP_URL from config/mail.php if it's set,
// otherwise works it out from the current page (also works behind the cloudflare tunnel)
function app_url($path = '') {
    if (mail_ready() && defined('APP_URL') && APP_URL !== 'http://localhost/kalasag') {
        $base = rtrim(APP_URL, '/');
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
    }
    return $base . '/' . ltrim($path, '/');
}

// ---------- log file (instead of a db table, so the ERD stays the same) ----------

// 0917*****67, the log is for checking delivery, it doesnt need full numbers or emails
function mask_contact($c) {
    if (strpos($c, '@') !== false) { [$u, $d] = explode('@', $c, 2); return substr($u, 0, 2) . '***@' . $d; }
    return strlen($c) > 6 ? substr($c, 0, 4) . str_repeat('*', strlen($c) - 6) . substr($c, -2) : $c;
}

function notify_log($channel, $event, $to, $ok, $detail = '') {
    $dir = dirname(NOTIFY_LOG);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    // keep the file small, start over past ~300 KB
    if (@filesize(NOTIFY_LOG) > 300000) @rename(NOTIFY_LOG, NOTIFY_LOG . '.old');
    $line = json_encode([
        'at' => date('Y-m-d H:i:s'), 'ch' => $channel, 'ev' => $event,
        'to' => is_array($to) ? implode(', ', array_map('mask_contact', $to)) : mask_contact($to),
        'ok' => $ok, 'detail' => mb_substr($detail, 0, 200),
    ]);
    @file_put_contents(NOTIFY_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
}

// newest first
function notify_log_read($limit = 50) {
    if (!is_file(NOTIFY_LOG)) return [];
    $lines = array_slice(file(NOTIFY_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$limit);
    return array_reverse(array_values(array_filter(array_map(fn($l) => json_decode($l, true), $lines))));
}

// ---------- SMS ----------

// 09171234567 -> +639171234567 (the app wants the international format)
function ph_e164($num) {
    $d = preg_replace('/\D/', '', (string)$num);
    if (preg_match('/^09\d{9}$/', $d)) return '+63' . substr($d, 1);
    if (preg_match('/^639\d{9}$/', $d)) return '+' . $d;
    return null;
}

// returns [ok, detail]
function sms_send($numbers, $text, $event = 'sms') {
    $nums = array_values(array_unique(array_filter(array_map('ph_e164', (array)$numbers))));
    if (!$nums) return [false, 'no valid mobile number'];
    if (!sms_ready()) { notify_log('sms', $event, $nums, false, 'skipped, SMS not set up'); return [false, 'SMS is not set up']; }
    if (!function_exists('curl_init')) { notify_log('sms', $event, $nums, false, 'php-curl missing'); return [false, 'php-curl is not installed']; }

    $all_ok = true; $last = '';
    // big lists (announcements) go out in groups of 100 like the app's docs suggest
    foreach (array_chunk($nums, 100) as $group) {
        $ch = curl_init(SMS_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => SMS_USERNAME . ':' . SMS_PASSWORD,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['textMessage' => ['text' => $text], 'phoneNumbers' => $group]),
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,      // never let a slow phone hang the page for long
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $ok = $code >= 200 && $code < 300;
        if ($ok) {
            $res = json_decode((string)$body, true);
            $last = 'queued on phone' . (!empty($res['id']) ? ' (' . $res['id'] . ')' : '');
        } else {
            $res = json_decode((string)$body, true);
            $last = $err ?: ('HTTP ' . $code . ($code === 401 ? ' wrong username/password' : '') . (!empty($res['message']) ? ': ' . $res['message'] : ''));
            $all_ok = false;
        }
        notify_log('sms', $event, $group, $ok, $last);
    }
    return [$all_ok, $last];
}

// ---------- email ----------

// simple branded email around a message
function email_html($title, $paragraphs, $link = null, $link_text = 'Open KALASAG') {
    $p = '';
    foreach ((array)$paragraphs as $para) $p .= '<p style="margin:0 0 12px;line-height:1.5">' . nl2br(e($para)) . '</p>';
    $btn = $link ? '<p style="margin:18px 0"><a href="' . e($link) . '" style="background:#57331F;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:600">' . e($link_text) . '</a></p>' : '';
    return '<div style="font-family:Arial,sans-serif;color:#2B1B12;max-width:560px">'
        . '<div style="background:#1C110B;color:#EAD7AE;padding:14px 18px;font-weight:700;letter-spacing:1px">KALASAG &middot; Barangay 172, Zone 17</div>'
        . '<div style="padding:18px;border:1px solid #E5D8C6;border-top:0">'
        . '<h2 style="margin:0 0 12px;font-size:18px">' . e($title) . '</h2>' . $p . $btn
        . '<p style="margin:18px 0 0;font-size:12px;color:#7A6A5C">For life-threatening emergencies, call the Barangay 172 hotline or the official emergency hotlines right away.</p>'
        . '</div></div>';
}

// one person, returns [ok, detail]
function email_send($to, $name, $subject, $title, $paragraphs, $link = null, $event = 'email', $link_text = 'Open KALASAG') {
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'no valid email'];
    if (!mail_ready()) { notify_log('email', $event, $to, false, 'skipped, email not set up'); return [false, 'Email is not set up']; }
    $text = $title . "\n\n" . implode("\n\n", (array)$paragraphs) . ($link ? "\n\n" . $link : '');
    $ok = send_mail($to, $name, $subject, email_html($title, $paragraphs, $link, $link_text), $text);
    notify_log('email', $event, $to, $ok, $ok ? 'sent' : 'failed, check config/mail.php and the server error log');
    return [$ok, $ok ? 'sent' : 'failed'];
}

// many people in one go (announcements), everyone in BCC so nobody sees the others' emails
function email_send_bcc($emails, $subject, $title, $paragraphs, $link = null, $event = 'email') {
    $emails = array_values(array_unique(array_filter((array)$emails, fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL))));
    if (!$emails) return [false, 'no emails'];
    if (!mail_ready()) { notify_log('email', $event, count($emails) . ' people', false, 'skipped, email not set up'); return [false, 'Email is not set up']; }
    $all_ok = true;
    foreach (array_chunk($emails, 50) as $group) {
        $ok = send_mail_bcc($group, $subject, email_html($title, $paragraphs, $link), $title . "\n\n" . implode("\n\n", (array)$paragraphs));
        notify_log('email', $event, count($group) . ' people', $ok, $ok ? 'sent (bcc)' : 'failed');
        $all_ok = $all_ok && $ok;
    }
    return [$all_ok, $all_ok ? 'sent' : 'some failed'];
}

// ---------- send after the page is done ----------
// admin actions redirect right away, the texts/emails go out after the browser already moved on,
// so a slow phone or gmail doesnt make the admin wait

$GLOBALS['notify_queue'] = [];

function notify_later(callable $fn) {
    if (!$GLOBALS['notify_queue']) register_shutdown_function('notify_run_queue');
    $GLOBALS['notify_queue'][] = $fn;
}

function notify_run_queue() {
    $q = $GLOBALS['notify_queue']; $GLOBALS['notify_queue'] = [];
    if (!$q) return;
    // let the browser go first when this was a redirect (empty body)
    $code = http_response_code();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif ($code >= 300 && $code < 400 && !headers_sent()) {
        ignore_user_abort(true);
        header('Connection: close');
        header('Content-Length: 0');
        while (ob_get_level() > 0) @ob_end_flush();
        @flush();
    }
    @set_time_limit(60);
    foreach ($q as $fn) {
        try { $fn(); } catch (Throwable $ex) { error_log('notify: ' . $ex->getMessage()); notify_log('system', 'error', '-', false, $ex->getMessage()); }
    }
}

// ---------- the actual messages ----------

// who reported this incident, and how to reach them
function incident_contacts($conn, $incident_id) {
    $st = $conn->prepare("SELECT i.incident_id, i.status, i.landmark, i.is_priority, t.type_name,
                COALESCE(us.contact_no, r.contact_no, g.guest_contact) AS phone, us.email,
                COALESCE(CONCAT(r.first_name, ' ', r.last_name), g.guest_name, 'Reporter') AS name,
                g.tracking_code
            FROM incidents i
            JOIN incident_types t ON t.type_id = i.type_id
            LEFT JOIN residents r ON r.resident_id = i.resident_id
            LEFT JOIN users us ON us.user_id = r.user_id
            LEFT JOIN guest_reports g ON g.incident_id = i.incident_id
            WHERE i.incident_id = ?");
    $st->bind_param('i', $incident_id);
    $st->execute();
    return $st->get_result()->fetch_assoc();
}

// short version of a remark so the text stays around one SMS (160 characters)
function sms_cut($s, $max) {
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 3) . '...' : $s;
}

// tell the person who reported it. $event: received, acknowledged, dispatched, arrived, resolved, cancelled, update, message
function notify_reporter($conn, $incident_id, $event, $extra = []) {
    notify_later(function () use ($conn, $incident_id, $event, $extra) {
        $c = incident_contacts($conn, $incident_id);
        if (!$c) return;
        $what = $c['type_name'] . ' report #' . $c['incident_id'];
        $note = $extra['remark'] ?? '';
        $team = $extra['team'] ?? 'A response team';
        switch ($event) {
            case 'received':
                $sms = "KALASAG: Barangay 172 received your $what." . ($c['tracking_code'] ? ' Tracking code: ' . $c['tracking_code'] . '.' : ' We will update you here.');
                $title = 'We received your report';
                $body = ["Barangay 172 received your $what. You will get an update when the barangay acts on it."];
                break;
            case 'acknowledged':
                $sms = "KALASAG: Barangay 172 acknowledged your $what." . ($note ? ' ' . sms_cut($note, 70) : '');
                $title = 'Your report was acknowledged';
                $body = ["Barangay 172 has seen your $what and is working on it."];
                break;
            case 'dispatched':
                $sms = 'KALASAG: ' . sms_cut($team, 40) . " is on the way to your $what. Stay safe.";
                $title = 'A response team is on the way';
                $body = [$team . " was dispatched to your $what."];
                break;
            case 'arrived':
                $sms = 'KALASAG: ' . sms_cut($team, 40) . " has arrived at the location of your $what.";
                $title = 'The response team has arrived';
                $body = [$team . " has arrived at the location of your $what."];
                break;
            case 'resolved':
                $sms = "KALASAG: Your $what is resolved." . ($note ? ' ' . sms_cut($note, 70) : '') . ' Stay safe.';
                $title = 'Your report is resolved';
                $body = ["Barangay 172 marked your $what as resolved."];
                break;
            case 'cancelled':
                $sms = "KALASAG: Barangay 172 closed your $what." . ($note ? ' ' . sms_cut($note, 80) : '');
                $title = 'Your report was closed';
                $body = ["Barangay 172 closed your $what."];
                break;
            case 'update':
                $sms = "KALASAG update on your $what: " . sms_cut($note, 95);
                $title = 'Update on your report';
                $body = ["Barangay 172 posted an update on your $what."];
                break;
            case 'message':
                $sms = "KALASAG: New message about your $what: \"" . sms_cut($extra['message'] ?? '', 75) . '"';
                $title = 'New message about your report';
                $body = ['From ' . ($extra['from'] ?? 'Barangay 172') . ':'];
                $note = $extra['message'] ?? '';
                break;
            default:
                return;
        }
        if ($note !== '' && $event !== 'dispatched' && $event !== 'arrived') $body[] = $note;
        $link = $c['tracking_code'] ? app_url('track.php?code=' . urlencode($c['tracking_code'])) : app_url('resident/report_view.php?id=' . (int)$c['incident_id']);
        if ($c['phone']) sms_send($c['phone'], $sms, 'reporter: ' . $event);
        if ($c['email']) email_send($c['email'], $c['name'], 'KALASAG: ' . $title . ' (#' . $c['incident_id'] . ')', $title, $body, $link, 'reporter: ' . $event, 'View my report');
    });
}

// new report: text the barangay staff (admins) so they dont have to keep watching the dashboard
function notify_admins_new_report($conn, $incident_id) {
    notify_later(function () use ($conn, $incident_id) {
        $c = incident_contacts($conn, $incident_id);
        if (!$c) return;
        $admins = $conn->query("SELECT full_name, contact_no, email FROM users WHERE role = 'admin' AND is_active = 1")->fetch_all(MYSQLI_ASSOC);
        if (!$admins) return;
        $where = $c['landmark'] ? ' near ' . sms_cut($c['landmark'], 40) : '';
        $sms = 'KALASAG ALERT: New ' . $c['type_name'] . ' report #' . $c['incident_id'] . $where . ($c['is_priority'] ? ' (PRIORITY, vulnerable household nearby)' : '') . '. Open the dashboard.';
        sms_send(array_column($admins, 'contact_no'), $sms, 'admin: new report');
        $link = app_url('admin/incident.php?id=' . (int)$c['incident_id']);
        foreach ($admins as $a) {
            if ($a['email']) email_send($a['email'], $a['full_name'], 'KALASAG ALERT: New ' . $c['type_name'] . ' report #' . $c['incident_id'],
                'New ' . $c['type_name'] . ' report #' . $c['incident_id'],
                [($c['is_priority'] ? 'PRIORITY: a household with vulnerable members is nearby. ' : '') . 'Reported by ' . $c['name'] . ($where ? ',' . $where : '') . '.'],
                $link, 'admin: new report', 'Open the incident');
        }
    });
}

// a team was dispatched: text the team's contact number
function notify_team_dispatched($conn, $incident_id, $responder_id) {
    notify_later(function () use ($conn, $incident_id, $responder_id) {
        $c = incident_contacts($conn, $incident_id);
        $st = $conn->prepare('SELECT rp.team_name, rp.contact_no, us.email, us.full_name FROM responders rp JOIN users us ON us.user_id = rp.user_id WHERE rp.responder_id = ?');
        $st->bind_param('i', $responder_id);
        $st->execute();
        $t = $st->get_result()->fetch_assoc();
        if (!$c || !$t) return;
        $where = $c['landmark'] ? ' near ' . sms_cut($c['landmark'], 40) : '';
        $sms = 'KALASAG: ' . sms_cut($t['team_name'], 30) . ', new assignment: ' . $c['type_name'] . ' #' . $c['incident_id'] . $where . '. Log in to KALASAG for the map.';
        sms_send($t['contact_no'], $sms, 'team: dispatched');
        if ($t['email']) email_send($t['email'], $t['full_name'], 'KALASAG: New assignment, ' . $c['type_name'] . ' #' . $c['incident_id'],
            'New assignment for ' . $t['team_name'], [$c['type_name'] . ' #' . $c['incident_id'] . $where . '. Open KALASAG for the map, photos, and directions.'],
            app_url('responder/home.php'), 'team: dispatched', 'Open my assignments');
    });
}

// announcement: text + email every active resident
function notify_announcement($conn, $title, $body, $category) {
    notify_later(function () use ($conn, $title, $body, $category) {
        $rows = $conn->query("SELECT COALESCE(us.contact_no, r.contact_no) AS phone, us.email
                              FROM residents r JOIN users us ON us.user_id = r.user_id WHERE us.is_active = 1")->fetch_all(MYSQLI_ASSOC);
        if (!$rows) return;
        $sms = 'KALASAG ' . ucfirst($category) . ': ' . sms_cut($title, 60) . '. ' . sms_cut($body, 80);
        sms_send(array_column($rows, 'phone'), $sms, 'announcement');
        email_send_bcc(array_column($rows, 'email'), 'KALASAG ' . ucfirst($category) . ': ' . $title, $title, [$body], app_url('resident/home.php'), 'announcement');
    });
}
