<?php
// shared stuff for incidents: photos, priority flag, status log, who can see what
// include after auth.php

define('UPLOAD_DIR', realpath(__DIR__ . '/..') . '/uploads/incidents');
define('MAX_PHOTOS', 3);
// php.ini sizes like "2M" -> bytes
function ini_bytes($v) {
    $v = trim((string)$v); $n = (float)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return (int)$n;
}
// 5 MB per photo, or less if the server's php.ini is set lower (default is only 2 MB)
define('MAX_PHOTO_BYTES', min(5 * 1024 * 1024, ini_bytes(ini_get('upload_max_filesize')) ?: PHP_INT_MAX));
define('MAX_PHOTO_MB', round(MAX_PHOTO_BYTES / 1048576, 1) + 0);
define('PRIORITY_RADIUS_M', 100);             // reports within 100 m of a vulnerable household get flagged

// barangay 172 coords from PhilAtlas, default map center everywhere
const BRGY_LAT = 14.5351;
const BRGY_LNG = 121.0114;
// reports farther than this from the barangay center get blocked (not our jurisdiction)
// 1 km covers the whole barangay plus room for GPS being a bit off
const SERVICE_RADIUS_M = 1000;

// straight-line distance in meters between 2 points (haversine)
function meters_between($lat1, $lng1, $lat2, $lng2) {
    $r = 6371000;
    $dlat = deg2rad($lat2 - $lat1);
    $dlng = deg2rad($lng2 - $lng1);
    $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) ** 2;
    return 2 * $r * asin(sqrt($a));
}

function meters_from_brgy($lat, $lng) {
    return meters_between(BRGY_LAT, BRGY_LNG, $lat, $lng);
}

const STATUSES = ['pending', 'acknowledged', 'dispatched', 'resolved', 'cancelled'];
const URGENCIES = ['critical', 'moderate', 'low'];

function incident_types($conn) {
    return $conn->query('SELECT type_id, type_name, icon_name, description FROM incident_types ORDER BY type_id')->fetch_all(MYSQLI_ASSOC);
}

// checks the photos before anything gets saved (max 3, real images only, size limit)
// returns an error message, or '' if everything is fine
function check_photos($files) {
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) return '';
    $count = 0;
    foreach ($files['error'] as $i => $err) {
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $count++;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || $files['size'][$i] > MAX_PHOTO_BYTES) {
            return 'Each photo must be ' . MAX_PHOTO_MB . ' MB or smaller.';
        }
        if ($err !== UPLOAD_ERR_OK) return 'A photo failed to upload. Try again.';
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($files['tmp_name'][$i]);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return 'Photos must be JPG, PNG, or WEBP.';
        }
    }
    if ($count > MAX_PHOTOS) return 'You can attach up to 3 photos only.';
    return '';
}

// saves the photos that passed check_photos()
function save_photos($conn, $incident_id, $files) {
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) return;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0750, true);

    foreach ($files['error'] as $i => $err) {
        if ($err !== UPLOAD_ERR_OK) continue;
        $tmp = $files['tmp_name'][$i];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $name = bin2hex(random_bytes(16));   // random name so nobody can guess other photos

        // re-save the image with GD if we have it: this drops EXIF data (phone GPS, device info)
        // and makes sure the file really is just an image
        $saved = false;
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring(file_get_contents($tmp));
            if ($img) {
                // phones save portrait photos sideways + an "orientation" tag, we drop the tag below
                // so rotate the actual pixels first or the photo shows up sideways
                if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                    $exif = @exif_read_data($tmp);
                    switch ((int)($exif['Orientation'] ?? 1)) {
                        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
                        case 3: $img = imagerotate($img, 180, 0); break;
                        case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
                        case 5: imageflip($img, IMG_FLIP_HORIZONTAL); $img = imagerotate($img, 90, 0); break;
                        case 6: $img = imagerotate($img, -90, 0); break;
                        case 7: imageflip($img, IMG_FLIP_HORIZONTAL); $img = imagerotate($img, -90, 0); break;
                        case 8: $img = imagerotate($img, 90, 0); break;
                    }
                }
                // see-through png/webp would turn black as jpg, so paint it on white first
                if ($mime !== 'image/jpeg') {
                    $bg = imagecreatetruecolor(imagesx($img), imagesy($img));
                    imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
                    imagecopy($bg, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
                    imagedestroy($img);
                    $img = $bg;
                }
                // shrink big phone photos so they load fast on mobile data
                $w = imagesx($img); $h = imagesy($img); $max = 1600;
                if ($w > $max || $h > $max) {
                    $scale = $max / max($w, $h);
                    $img = imagescale($img, (int)($w * $scale), (int)($h * $scale));
                }
                $file = $name . '.jpg';
                $saved = imagejpeg($img, UPLOAD_DIR . '/' . $file, 82);
                imagedestroy($img);
            }
        }
        if (!$saved) {
            // no GD, just move the original after the mime check
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $file = $name . '.' . $ext;
            if (!move_uploaded_file($tmp, UPLOAD_DIR . '/' . $file)) continue;
        }

        $path = 'incidents/' . $file;
        $st = $conn->prepare('INSERT INTO incident_photos (incident_id, file_path) VALUES (?, ?)');
        $st->bind_param('is', $incident_id, $path);
        $st->execute();
    }
}

// nearest vulnerable household within PRIORITY_RADIUS_M, or null
function nearby_vulnerable_household($conn, $lat, $lng) {
    $st = $conn->prepare("SELECT household_id,
                6371000 * 2 * ASIN(SQRT(POW(SIN(RADIANS(latitude - ?) / 2), 2)
                  + COS(RADIANS(?)) * COS(RADIANS(latitude)) * POW(SIN(RADIANS(longitude - ?) / 2), 2))) AS meters
            FROM households
            WHERE vulnerability_score > 0 AND latitude IS NOT NULL
            HAVING meters <= ?
            ORDER BY meters LIMIT 1");
    $r = PRIORITY_RADIUS_M;
    $st->bind_param('dddi', $lat, $lat, $lng, $r);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ? (int)$row['household_id'] : null;
}

function log_status($conn, $incident_id, $user_id, $old, $new, $remarks) {
    $st = $conn->prepare('INSERT INTO incident_status_log (incident_id, updated_by, old_status, new_status, remarks) VALUES (?, ?, ?, ?, ?)');
    $st->bind_param('iisss', $incident_id, $user_id, $old, $new, $remarks);
    $st->execute();
}

// KLS-XXXXXX with no look-alike letters (no 0/O, 1/I)
function new_tracking_code($conn) {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'KLS-';
        for ($i = 0; $i < 6; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)];
        $st = $conn->prepare('SELECT 1 FROM guest_reports WHERE tracking_code = ?');
        $st->bind_param('s', $code);
        $st->execute();
    } while ($st->get_result()->num_rows);
    return $code;
}

// the resident_id that belongs to the logged in user (or null)
function my_resident_id($conn, $user_id) {
    $st = $conn->prepare('SELECT resident_id FROM residents WHERE user_id = ?');
    $st->bind_param('i', $user_id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    return $r ? (int)$r['resident_id'] : null;
}

// can this person see this incident? admin sees all, resident sees own, responder sees assigned,
// guests see the ones they unlocked with a tracking code this session
function can_view_incident($conn, $u, $incident_id) {
    // a code they unlocked on track.php works even if they're logged in (like a resident who reported as a guest before signing up)
    if (in_array((int)$incident_id, $_SESSION['guest_incidents'] ?? [], true)) return true;
    if ($u && $u['role'] === 'admin') return true;
    if ($u && $u['role'] === 'resident') {
        $rid = my_resident_id($conn, $u['user_id']);
        $st = $conn->prepare('SELECT 1 FROM incidents WHERE incident_id = ? AND resident_id = ?');
        $st->bind_param('ii', $incident_id, $rid);
        $st->execute();
        return (bool)$st->get_result()->num_rows;
    }
    if ($u && $u['role'] === 'responder') {
        $st = $conn->prepare('SELECT 1 FROM incident_responses r JOIN responders rp ON rp.responder_id = r.responder_id
                              WHERE r.incident_id = ? AND rp.user_id = ?');
        $st->bind_param('ii', $incident_id, $u['user_id']);
        $st->execute();
        return (bool)$st->get_result()->num_rows;
    }
    return false;
}

// full incident row with type, urgency, reporter
function load_incident($conn, $incident_id) {
    $st = $conn->prepare("SELECT i.*, t.type_name, t.icon_name, u.urgency, u.urgency_overridden,
                COALESCE(CONCAT(r.first_name, ' ', r.last_name), g.guest_name) AS reporter_name,
                COALESCE(r.contact_no, g.guest_contact) AS reporter_contact,
                g.tracking_code, (g.guest_report_id IS NOT NULL) AS is_guest,
                h.address AS hh_address, h.has_elderly, h.has_pwd, h.has_pregnant, h.has_bedridden
            FROM incidents i
            JOIN incident_types t ON t.type_id = i.type_id
            LEFT JOIN incident_urgency u ON u.incident_id = i.incident_id
            LEFT JOIN residents r ON r.resident_id = i.resident_id
            LEFT JOIN guest_reports g ON g.incident_id = i.incident_id
            LEFT JOIN households h ON h.household_id = i.household_id
            WHERE i.incident_id = ?");
    $st->bind_param('i', $incident_id);
    $st->execute();
    return $st->get_result()->fetch_assoc();
}

function incident_timeline($conn, $incident_id) {
    $st = $conn->prepare("SELECT l.*, COALESCE(u.full_name, 'System') AS who FROM incident_status_log l
            LEFT JOIN users u ON u.user_id = l.updated_by WHERE l.incident_id = ? ORDER BY l.changed_at, l.log_id");
    $st->bind_param('i', $incident_id);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function incident_messages($conn, $incident_id) {
    $st = $conn->prepare("SELECT m.*, COALESCE(u.full_name, 'Guest') AS who FROM incident_messages m
            LEFT JOIN users u ON u.user_id = m.sender_id WHERE m.incident_id = ? ORDER BY m.sent_at, m.message_id");
    $st->bind_param('i', $incident_id);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function incident_photos($conn, $incident_id) {
    $st = $conn->prepare('SELECT photo_id FROM incident_photos WHERE incident_id = ? ORDER BY photo_id');
    $st->bind_param('i', $incident_id);
    $st->execute();
    return array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'photo_id');
}

function add_message($conn, $incident_id, $sender_id, $sender_type, $text) {
    $st = $conn->prepare('INSERT INTO incident_messages (incident_id, sender_id, sender_type, message) VALUES (?, ?, ?, ?)');
    $st->bind_param('iiss', $incident_id, $sender_id, $sender_type, $text);
    $st->execute();
}

// changes whenever a report, status, dispatch, or team availability changes
// the dashboard compares it to know when its lists (not just the map) are out of date
function dashboard_version($conn) {
    return $conn->query("SELECT CONCAT(
            (SELECT COALESCE(MAX(incident_id), 0) FROM incidents), '-',
            (SELECT COALESCE(MAX(log_id), 0) FROM incident_status_log), '-',
            (SELECT COALESCE(MAX(response_id), 0) FROM incident_responses), '-',
            (SELECT COALESCE(MAX(message_id), 0) FROM incident_messages), '-',
            (SELECT COUNT(*) FROM incident_responses WHERE cleared_at IS NULL), '-',
            (SELECT COALESCE(SUM(availability = 'available'), 0) FROM responders)) AS v")->fetch_assoc()['v'];
}

function fmt_meters($m) {
    return $m < 1000 ? (round($m / 10) * 10) . ' m' : number_format($m / 1000, 1) . ' km';
}

// little helpers for the shared partials
function vuln_flags($row) {
    $f = [];
    if (!empty($row['has_elderly']))   $f[] = 'Elderly';
    if (!empty($row['has_pwd']))       $f[] = 'PWD';
    if (!empty($row['has_pregnant']))  $f[] = 'Pregnant';
    if (!empty($row['has_bedridden'])) $f[] = 'Bedridden';
    return $f;
}
