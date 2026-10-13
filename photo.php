<?php
// serves incident photos only to people allowed to see that incident
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/incidents.php';

$id = (int)($_GET['id'] ?? 0);
$st = $conn->prepare('SELECT incident_id, file_path FROM incident_photos WHERE photo_id = ?');
$st->bind_param('i', $id);
$st->execute();
$photo = $st->get_result()->fetch_assoc();

if (!$photo || !can_view_incident($conn, current_user(), (int)$photo['incident_id'])) {
    http_response_code(404);
    exit;
}

// make sure the path stays inside the uploads folder
$base = realpath(__DIR__ . '/uploads');
$file = realpath($base . '/' . $photo['file_path']);
if (!$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($file));
readfile($file);
