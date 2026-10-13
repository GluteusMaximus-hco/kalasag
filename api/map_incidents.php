<?php
// json feed for the live map, the dashboard calls this every 30 secs
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$u = current_user();
if (!$u || $u['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in as admin']);
    exit;
}

try {
    // active ones + anything resolved in the last 24 hrs so the map isnt empty after a busy night
    $sql = "SELECT i.incident_id, i.latitude, i.longitude, i.landmark, i.description, i.status,
                   i.is_priority, i.date_reported, t.type_name, t.icon_name,
                   u.urgency, (g.guest_report_id IS NOT NULL) AS is_guest
            FROM incidents i
            JOIN incident_types t ON t.type_id = i.type_id
            LEFT JOIN incident_urgency u ON u.incident_id = i.incident_id
            LEFT JOIN guest_reports g ON g.incident_id = i.incident_id
            WHERE i.status IN ('pending','acknowledged','dispatched')
               OR (i.status = 'resolved' AND i.date_resolved >= NOW() - INTERVAL 1 DAY)
            ORDER BY i.date_reported DESC";
    $incidents = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    foreach ($incidents as &$row) {
        $row['latitude']  = (float)$row['latitude'];
        $row['longitude'] = (float)$row['longitude'];
        $row['is_priority'] = (bool)$row['is_priority'];
        $row['is_guest'] = (bool)$row['is_guest'];
        $row['ago'] = time_ago($row['date_reported']);
        $row['status_label'] = status_label($row['status']);
    }
    unset($row);

    $centers = $conn->query("SELECT center_name, latitude, longitude, capacity, status FROM evacuation_centers WHERE latitude IS NOT NULL")->fetch_all(MYSQLI_ASSOC);

    $counts = $conn->query("SELECT
            SUM(status IN ('pending','acknowledged','dispatched')) AS active,
            SUM(status = 'pending') AS pending,
            SUM(status IN ('pending','acknowledged','dispatched') AND is_priority = 1) AS priority
        FROM incidents")->fetch_assoc();

    echo json_encode([
        'incidents' => $incidents,
        'centers'   => $centers,
        'counts'    => array_map('intval', $counts),
        'updated'   => date('g:i A'),
    ]);
} catch (mysqli_sql_exception $ex) {
    error_log('map feed: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load incidents']);
}
