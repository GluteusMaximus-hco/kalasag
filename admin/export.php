<?php
// downloads incidents as a CSV file (opens in Excel / Google Sheets)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
$u = require_role('admin');

// same filters as the incidents page
$where = ['1=1']; $types = ''; $args = [];
$status = $_GET['status'] ?? 'all';
if ($status === 'active') $where[] = "i.status IN ('pending','acknowledged','dispatched')";
elseif (in_array($status, STATUSES, true)) { $where[] = 'i.status = ?'; $types .= 's'; $args[] = $status; }
if ((int)($_GET['type'] ?? 0)) { $where[] = 'i.type_id = ?'; $types .= 'i'; $args[] = (int)$_GET['type']; }
if (isset($_GET['prio'])) $where[] = 'i.is_priority = 1';
foreach (['from' => ['>=', ' 00:00:00'], 'to' => ['<=', ' 23:59:59']] as $k => [$op, $time]) {
    if (!empty($_GET[$k]) && DateTime::createFromFormat('Y-m-d', $_GET[$k])) { $where[] = "i.date_reported $op ?"; $types .= 's'; $args[] = $_GET[$k] . $time; }
}
if (trim($_GET['q'] ?? '') !== '') {
    $like = '%' . trim($_GET['q']) . '%';
    $where[] = "(i.description LIKE ? OR i.landmark LIKE ? OR CONCAT(r.first_name,' ',r.last_name) LIKE ? OR g.guest_name LIKE ? OR g.tracking_code LIKE ?)";
    $types .= 'sssss'; array_push($args, $like, $like, $like, $like, $like);
}

$st = $conn->prepare("SELECT i.incident_id, i.date_reported, t.type_name, i.status, i.is_priority, u.urgency,
        COALESCE(CONCAT(r.first_name, ' ', r.last_name), g.guest_name, 'Guest') AS reporter, g.tracking_code,
        i.landmark, i.latitude, i.longitude, i.description, i.date_resolved,
        (SELECT GROUP_CONCAT(rp.team_name SEPARATOR '; ') FROM incident_responses x JOIN responders rp ON rp.responder_id = x.responder_id WHERE x.incident_id = i.incident_id) AS teams
    FROM incidents i JOIN incident_types t ON t.type_id = i.type_id
    LEFT JOIN incident_urgency u ON u.incident_id = i.incident_id
    LEFT JOIN residents r ON r.resident_id = i.resident_id
    LEFT JOIN guest_reports g ON g.incident_id = i.incident_id
    WHERE " . implode(' AND ', $where) . " ORDER BY i.date_reported DESC");
if ($args) $st->bind_param($types, ...$args);
$st->execute();
$res = $st->get_result();

// stops excel from running "formulas" someone typed into a report (csv injection)
function safe_cell($v) {
    $v = (string)$v;
    return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="kalasag-incidents-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM so excel reads the tagalog characters right
fputcsv($out, ['Report #', 'Date reported', 'Type', 'Status', 'Priority', 'Urgency', 'Reporter', 'Guest code', 'Landmark', 'Latitude', 'Longitude', 'Description', 'Date resolved', 'Teams dispatched']);
while ($row = $res->fetch_assoc()) {
    fputcsv($out, array_map('safe_cell', [
        $row['incident_id'], $row['date_reported'], $row['type_name'], status_label($row['status']), $row['is_priority'] ? 'Yes' : 'No',
        $row['urgency'] ? ucfirst($row['urgency']) : '', $row['reporter'], $row['tracking_code'], $row['landmark'],
        $row['latitude'], $row['longitude'], $row['description'], $row['date_resolved'], $row['teams'],
    ]));
}
fclose($out);
