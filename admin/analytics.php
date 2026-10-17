<?php
// numbers for barangay officials + printable report
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
$u = require_role('admin');

$page_title = 'Analytics & reports';
$active = 'analytics';

$ranges = ['30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months', 'all' => 'All time'];
$range = $_GET['range'] ?? '30';
if (!isset($ranges[$range])) $range = '30';
$since = $range === 'all' ? '1970-01-01 00:00:00' : date('Y-m-d H:i:s', strtotime("-$range days"));

function one($conn, $sql, $since) {
    $st = $conn->prepare($sql);
    $st->bind_param('s', $since);
    $st->execute();
    return $st->get_result()->fetch_assoc();
}
function all($conn, $sql, $since) {
    $st = $conn->prepare($sql);
    $st->bind_param('s', $since);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

$tot = one($conn, "SELECT COUNT(*) AS total, COALESCE(SUM(status='resolved'),0) AS resolved, COALESCE(SUM(status='cancelled'),0) AS cancelled,
        COALESCE(SUM(is_priority),0) AS priority FROM incidents WHERE date_reported >= ?", $since);

// minutes from report to first acknowledge, and from report to first responder arrival
$speed = one($conn, "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, i.date_reported, l.first_ack))) AS ack_min FROM incidents i
        JOIN (SELECT incident_id, MIN(changed_at) AS first_ack FROM incident_status_log WHERE new_status IN ('acknowledged','dispatched') GROUP BY incident_id) l
        ON l.incident_id = i.incident_id WHERE i.date_reported >= ?", $since);
$arrive = one($conn, "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, i.date_reported, r.first_arrival))) AS arrive_min FROM incidents i
        JOIN (SELECT incident_id, MIN(arrived_at) AS first_arrival FROM incident_responses WHERE arrived_at IS NOT NULL GROUP BY incident_id) r
        ON r.incident_id = i.incident_id WHERE i.date_reported >= ?", $since);

$by_type = all($conn, "SELECT t.type_name, COUNT(i.incident_id) AS n FROM incident_types t
        LEFT JOIN incidents i ON i.type_id = t.type_id AND i.date_reported >= ? GROUP BY t.type_id ORDER BY n DESC, t.type_name", $since);
$by_status_raw = all($conn, "SELECT status, COUNT(*) AS n FROM incidents WHERE date_reported >= ? GROUP BY status", $since);
$by_status = array_fill_keys(STATUSES, 0);
foreach ($by_status_raw as $r) $by_status[$r['status']] = (int)$r['n'];

// last 12 months always, so the trend is readable even with a short range picked
$months = [];
for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime("first day of -$i month"))] = 0;
$mrows = $conn->query("SELECT DATE_FORMAT(date_reported, '%Y-%m') AS ym, COUNT(*) AS n FROM incidents
        WHERE date_reported >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01') GROUP BY ym")->fetch_all(MYSQLI_ASSOC);
foreach ($mrows as $r) if (isset($months[$r['ym']])) $months[$r['ym']] = (int)$r['n'];

$teams = all($conn, "SELECT rp.team_name, COUNT(r.response_id) AS jobs,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, r.dispatched_at, r.arrived_at))) AS to_arrive,
        COALESCE(SUM(r.cleared_at IS NOT NULL), 0) AS cleared
    FROM responders rp LEFT JOIN incident_responses r ON r.responder_id = rp.responder_id AND r.dispatched_at >= ?
    GROUP BY rp.responder_id ORDER BY jobs DESC, rp.team_name", $since);

$recent = all($conn, "SELECT i.incident_id, i.date_reported, i.status, i.landmark, i.is_priority, t.type_name FROM incidents i
        JOIN incident_types t ON t.type_id = i.type_id WHERE i.date_reported >= ? ORDER BY i.date_reported DESC LIMIT 50", $since);

$fmt_min = fn($m) => $m === null ? 'No data yet' : ($m < 60 ? $m . ' min' : floor($m / 60) . ' hr ' . ($m % 60) . ' min');
$resolved_pct = $tot['total'] ? round($tot['resolved'] / $tot['total'] * 100) : null;
$export_from = $range === 'all' ? '' : date('Y-m-d', strtotime("-$range days"));

include __DIR__ . '/../includes/header.php';
?>
<div class="print-only mb-3">
    <h2 class="mb-0">KALASAG incident report</h2>
    <div>Barangay 172, Zone 17, Malibay, Pasay City &middot; <?= e($ranges[$range]) ?> &middot; printed <?= e(date('M j, Y g:i A')) ?> by <?= e($u['full_name']) ?></div>
</div>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-end mb-4 no-print">
    <form method="get" class="d-flex gap-2 align-items-end">
        <div><label class="form-label" for="range">Period</label>
            <select class="form-select" id="range" name="range" onchange="this.form.submit()">
                <?php foreach ($ranges as $k => $lbl): ?><option value="<?= $k ?>" <?= $range === (string)$k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
            </select></div>
        <noscript><button class="btn btn-umber" type="submit">Show</button></noscript>
    </form>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('admin/export.php?status=all' . ($export_from ? '&from=' . $export_from : '')) ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
        <button class="btn btn-umber" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print report</button>
    </div>
</div>

<div class="stat-row mb-4">
    <div class="stat"><div class="num"><?= (int)$tot['total'] ?></div><div class="lbl">Reports</div></div>
    <div class="stat"><div class="num"><?= $resolved_pct === null ? '&ndash;' : $resolved_pct . '%' ?></div><div class="lbl">Resolved (<?= (int)$tot['resolved'] ?>)</div></div>
    <div class="stat"><div class="num"><?= e($fmt_min($speed['ack_min'] === null ? null : (int)$speed['ack_min'])) ?></div><div class="lbl">Average time from report to acknowledge</div></div>
    <div class="stat"><div class="num"><?= e($fmt_min($arrive['arrive_min'] === null ? null : (int)$arrive['arrive_min'])) ?></div><div class="lbl">Average time from report until responders arrive</div></div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <section class="panel h-100">
            <div class="panel-head"><h2>Reports per month</h2><span class="hint">Last 12 months</span></div>
            <div class="panel-body"><div class="chart-box" style="height:260px"><canvas id="monthChart" role="img" aria-label="Line chart of reports per month for the last 12 months"></canvas></div></div>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="panel h-100">
            <div class="panel-head"><h2>By type</h2><span class="hint"><?= e($ranges[$range]) ?></span></div>
            <div class="panel-body"><div class="chart-box" style="height:260px"><canvas id="typeChart" role="img" aria-label="Bar chart of reports by incident type"></canvas></div></div>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="panel h-100">
            <div class="panel-head"><h2>By status</h2><span class="hint"><?= e($ranges[$range]) ?></span></div>
            <div class="panel-body">
                <div class="chart-box" style="height:190px"><canvas id="statusChart" role="img" aria-label="Bar chart of reports by status"></canvas></div>
                <table class="table table-sm small mt-3 mb-0">
                    <tbody><?php foreach ($by_status as $s => $n): ?><tr><td><?= e(status_label($s)) ?></td><td class="text-end fw-semibold"><?= $n ?></td></tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="panel h-100">
            <div class="panel-head"><h2>Responder teams</h2><span class="hint"><?= e($ranges[$range]) ?></span></div>
            <?php if (!$teams): ?><div class="empty pt-0"><i class="bi bi-people" style="color:var(--muted)"></i>No responder teams yet.</div>
            <?php else: ?>
                <div class="table-wrap"><table class="table data-table">
                    <thead><tr><th>Team</th><th class="text-end">Dispatched</th><th class="text-end">Cleared</th><th class="text-end">Avg time from dispatch to arrival</th></tr></thead>
                    <tbody><?php foreach ($teams as $t): ?>
                        <tr><td class="fw-semibold"><?= e($t['team_name']) ?></td><td class="text-end"><?= (int)$t['jobs'] ?></td><td class="text-end"><?= (int)$t['cleared'] ?></td>
                            <td class="text-end"><?= e($t['to_arrive'] === null ? 'No data' : $fmt_min((int)$t['to_arrive'])) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-12">
        <section class="panel">
            <div class="panel-head"><h2>Reports in this period</h2><span class="hint">Latest 50</span></div>
            <?php if (!$recent): ?><div class="empty pt-0"><i class="bi bi-inbox" style="color:var(--muted)"></i>No reports in this period.</div>
            <?php else: ?>
                <div class="table-wrap"><table class="table data-table">
                    <thead><tr><th>#</th><th>Date</th><th>Type</th><th>Landmark</th><th>Priority</th><th>Status</th></tr></thead>
                    <tbody><?php foreach ($recent as $r): ?>
                        <tr><td><a href="<?= url('admin/incident.php?id=' . (int)$r['incident_id']) ?>"><?= (int)$r['incident_id'] ?></a></td>
                            <td class="text-nowrap"><?= e(date('M j, Y g:i A', strtotime($r['date_reported']))) ?></td>
                            <td><?= e($r['type_name']) ?></td><td><?= e($r['landmark'] ?: '') ?></td>
                            <td><?= $r['is_priority'] ? 'Yes' : '' ?></td><td><?= e(status_label($r['status'])) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php
$m_labels = json_encode(array_map(fn($ym) => date('M Y', strtotime($ym . '-01')), array_keys($months)));
$m_data = json_encode(array_values($months));
$t_labels = json_encode(array_column($by_type, 'type_name'));
$t_data = json_encode(array_map('intval', array_column($by_type, 'n')));
$s_labels = json_encode(array_map('status_label', STATUSES));
$s_data = json_encode(array_values($by_status));
$extra_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
<script>
// one series per chart, so one color each and no legend (the title says what it is)
const INK = '#26170F', MUTED = '#7B6658', GRID = '#EDE4D6', BAR = '#57331F', HOVER = '#E2A43A';
const STATUS = ['#C0392B', '#2E6DB4', '#D9861C', '#3E8A5B', '#8C8177'];   // same colors as the badges everywhere
const base = {
    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1C110B', padding: 10, displayColors: false } },
    scales: {
        x: { grid: { display: false }, ticks: { color: MUTED, font: { family: 'Public Sans' } } },
        y: { beginAtZero: true, grid: { color: GRID }, border: { display: false }, ticks: { color: MUTED, precision: 0, font: { family: 'Public Sans' } } }
    },
    maintainAspectRatio: false, responsive: true
};
document.fonts.ready.then(() => {
    new Chart(document.getElementById('monthChart'), { type: 'line', options: base,
        data: { labels: {$m_labels}, datasets: [{ data: {$m_data}, borderColor: BAR, borderWidth: 2, pointRadius: 4, pointHoverRadius: 6,
                pointBackgroundColor: '#fff', pointBorderColor: BAR, pointBorderWidth: 2, tension: 0.25, fill: false }] } });

    new Chart(document.getElementById('typeChart'), { type: 'bar',
        options: { ...base, indexAxis: 'y', scales: { x: { ...base.scales.y }, y: { grid: { display: false }, ticks: { color: INK, font: { family: 'Public Sans', weight: 600 } } } } },
        data: { labels: {$t_labels}, datasets: [{ data: {$t_data}, backgroundColor: BAR, hoverBackgroundColor: HOVER, borderRadius: 4, maxBarThickness: 22 }] } });

    new Chart(document.getElementById('statusChart'), { type: 'bar',
        options: { ...base, indexAxis: 'y', scales: { x: { ...base.scales.y }, y: { grid: { display: false }, ticks: { color: INK, font: { family: 'Public Sans', weight: 600 } } } } },
        data: { labels: {$s_labels}, datasets: [{ data: {$s_data}, backgroundColor: STATUS, borderRadius: 4, maxBarThickness: 18 }] } });
});
</script>
HTML;
include __DIR__ . '/../includes/footer.php';
