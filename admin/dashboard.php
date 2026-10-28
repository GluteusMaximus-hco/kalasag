<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
$u = require_role('admin');

$page_title = 'Dashboard';
$active = 'dashboard';

try {
    // numbers for the top strip
    $counts = $conn->query("SELECT
            COALESCE(SUM(status IN ('pending','acknowledged','dispatched')), 0) AS active,
            COALESCE(SUM(status = 'pending'), 0) AS pending,
            COALESCE(SUM(status IN ('pending','acknowledged','dispatched') AND is_priority = 1), 0) AS priority
        FROM incidents")->fetch_assoc();

    $resp = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(availability = 'available'), 0) AS free FROM responders")->fetch_assoc();

    // queue: priority households first, then critical, then oldest
    $queue = $conn->query("SELECT i.incident_id, i.description, i.landmark, i.status, i.is_priority, i.date_reported,
                t.type_name, t.icon_name, u.urgency,
                COALESCE(CONCAT(r.first_name, ' ', r.last_name), CONCAT(g.guest_name, ' (guest)'), 'Guest') AS reporter
            FROM incidents i
            JOIN incident_types t ON t.type_id = i.type_id
            LEFT JOIN incident_urgency u ON u.incident_id = i.incident_id
            LEFT JOIN residents r ON r.resident_id = i.resident_id
            LEFT JOIN guest_reports g ON g.incident_id = i.incident_id
            WHERE i.status IN ('pending','acknowledged','dispatched')
            ORDER BY i.is_priority DESC,
                     FIELD(COALESCE(u.urgency, 'none'), 'critical', 'moderate', 'low', 'none'),
                     i.date_reported ASC")->fetch_all(MYSQLI_ASSOC);

    // chart: reports per type, last 30 days
    $by_type = $conn->query("SELECT t.type_name, COUNT(i.incident_id) AS total
            FROM incident_types t
            LEFT JOIN incidents i ON i.type_id = t.type_id AND i.date_reported >= NOW() - INTERVAL 30 DAY
            GROUP BY t.type_id, t.type_name ORDER BY total DESC, t.type_name")->fetch_all(MYSQLI_ASSOC);

    // latest status changes
    $activity = $conn->query("SELECT l.old_status, l.new_status, l.remarks, l.changed_at, t.type_name, l.incident_id,
                COALESCE(us.full_name, 'System') AS who
            FROM incident_status_log l
            JOIN incidents i ON i.incident_id = l.incident_id
            JOIN incident_types t ON t.type_id = i.type_id
            LEFT JOIN users us ON us.user_id = l.updated_by
            ORDER BY l.changed_at DESC LIMIT 7")->fetch_all(MYSQLI_ASSOC);

    // subqueries instead of GROUP BY so it works even with strict ONLY_FULL_GROUP_BY (mysql 8, some hosts)
    $responders = $conn->query("SELECT rp.team_name, rp.availability,
                (SELECT GROUP_CONCAT(t.type_name ORDER BY t.type_name SEPARATOR ', ') FROM responder_capabilities c JOIN incident_types t ON t.type_id = c.type_id WHERE c.responder_id = rp.responder_id) AS handles
            FROM responders rp
            ORDER BY FIELD(rp.availability, 'available', 'deployed', 'off_duty'), rp.team_name")->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $ex) {
    error_log('dashboard: ' . $ex->getMessage());
    http_response_code(500);
    exit('The dashboard could not load its data. Check the database connection and reload.');
}

$status_colors = [
    'pending' => '#C0392B', 'acknowledged' => '#2E6DB4', 'dispatched' => '#D9861C',
    'resolved' => '#3E8A5B', 'cancelled' => '#8C8177',
];

$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';

include __DIR__ . '/../includes/header.php';
?>

<section class="situation mb-4" aria-label="Current situation">
    <div class="sit-item">
        <div class="sit-num" id="cActive"><?= (int)$counts['active'] ?></div>
        <div class="sit-label">Active incidents</div>
    </div>
    <div class="sit-item <?= $counts['pending'] > 0 ? 'alert-on' : '' ?>" id="cPendingBox">
        <div class="sit-num" id="cPending"><?= (int)$counts['pending'] ?></div>
        <div class="sit-label">Waiting for a response</div>
    </div>
    <div class="sit-item gold">
        <div class="sit-num" id="cPriority"><?= (int)$counts['priority'] ?></div>
        <div class="sit-label">Near vulnerable households</div>
    </div>
    <div class="sit-item">
        <div class="sit-num"><?= (int)$resp['free'] ?><span class="fs-4 opacity-50">/<?= (int)$resp['total'] ?></span></div>
        <div class="sit-label">Responder teams available</div>
    </div>
</section>

<div class="row g-4">
    <div class="col-xl-8">
        <section class="panel">
            <div class="panel-head flex-wrap">
                <h2>Live incident map</h2>
                <span class="live-tag" id="liveTag">Updated <?= date('g:i A') ?></span>
            </div>
            <div class="panel-body pb-3">
                <div class="map-legend">
                    <?php foreach (['pending','acknowledged','dispatched','resolved'] as $s): ?>
                        <span><i class="dot" style="background:<?= $status_colors[$s] ?>"></i><?= e(status_label($s)) ?></span>
                    <?php endforeach; ?>
                    <span><i class="dot" style="background:#fff;border:3px solid var(--gold)"></i>Priority household</span>
                    <span><i class="dot" style="background:var(--st-resolved);border-radius:3px"></i>Evacuation center</span>
                </div>
            </div>
            <div class="map-wrap"><div id="incidentMap" role="region" aria-label="Map of incidents in Barangay 172"></div></div>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="panel h-100">
            <div class="panel-head">
                <h2>Needs attention</h2>
                <!-- shows when new reports or updates came in after the page loaded -->
                <a class="hint fw-semibold text-danger d-none" id="newUpdates" href="<?= url('admin/dashboard.php') ?>"><i class="bi bi-arrow-clockwise me-1"></i>New updates, reload</a>
                <a class="hint fw-semibold" id="seeAll" href="<?= url('admin/incidents.php') ?>">See all</a>
            </div>
            <?php if (!$queue): ?>
                <div class="empty"><i class="bi bi-check-circle-fill"></i>No active incidents right now.</div>
            <?php else: ?>
                <ul class="queue">
                    <?php foreach ($queue as $q): ?>
                        <li>
                            <a class="q-row" href="<?= url('admin/incident.php?id=' . (int)$q['incident_id']) ?>">
                                <div class="q-icon" style="background:<?= $status_colors[$q['status']] ?>"><i class="bi <?= e($q['icon_name']) ?>"></i></div>
                                <div class="min-w-0">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="q-title"><?= e($q['type_name']) ?></span>
                                        <span class="st st-<?= e($q['status']) ?>"><?= e(status_label($q['status'])) ?></span>
                                    </div>
                                    <div class="q-desc"><?= e($q['description']) ?></div>
                                    <div class="q-meta">
                                        <?php if ($q['urgency']): ?><span class="urg urg-<?= e($q['urgency']) ?>"><?= e(ucfirst($q['urgency'])) ?></span><?php endif; ?>
                                        <?php if ($q['is_priority']): ?><span class="prio-flag"><i class="bi bi-shield-fill-exclamation"></i>Priority</span><?php endif; ?>
                                        <span class="q-land"><?= e($q['landmark'] ?: 'No landmark') ?></span>
                                        <span>&middot;</span>
                                        <span><?= e(time_ago($q['date_reported'])) ?></span>
                                        <span>&middot;</span>
                                        <span><?= e($q['reporter']) ?></span>
                                    </div>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="panel h-100">
            <div class="panel-head">
                <h2>Reports by type</h2>
                <span class="hint">Last 30 days</span>
            </div>
            <?php if (array_sum(array_column($by_type, 'total')) > 0): ?>
                <div class="panel-body"><canvas id="typeChart" height="220" aria-label="Bar chart of reports by incident type"></canvas></div>
            <?php else: ?>
                <div class="empty pt-0"><i class="bi bi-bar-chart" style="color:var(--muted)"></i>No reports in the last 30 days.</div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-4">
        <section class="panel h-100">
            <div class="panel-head"><h2>Recent updates</h2></div>
            <div class="panel-body">
                <?php if (!$activity): ?>
                    <p class="text-secondary small mb-0">No updates yet.</p>
                <?php else: ?>
                    <ul class="activity">
                        <?php foreach ($activity as $a): ?>
                            <li>
                                <time datetime="<?= e($a['changed_at']) ?>"><?= e(time_ago($a['changed_at'])) ?></time>
                                <div>
                                    <strong><?= e($a['type_name']) ?> #<?= (int)$a['incident_id'] ?></strong>
                                    <?php if ($a['old_status'] === null): ?>was reported
                                    <?php elseif ($a['old_status'] === $a['new_status']): ?>got an update
                                    <?php else: ?>marked <span class="st st-<?= e($a['new_status']) ?>"><?= e(status_label($a['new_status'])) ?></span><?php endif; ?>
                                    <?php if ($a['remarks']): ?><div class="small"><?= e($a['remarks']) ?></div><?php endif; ?>
                                    <?php if ($a['who'] !== 'System'): ?><div class="text-secondary small">by <?= e($a['who']) ?></div><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-lg-3">
        <section class="panel h-100">
            <div class="panel-head"><h2>Responders</h2></div>
            <div class="panel-body">
                <?php foreach ($responders as $r): ?>
                    <div class="resp-row">
                        <div>
                            <div class="fw-semibold"><?= e($r['team_name']) ?></div>
                            <div class="text-secondary small"><?= e($r['handles'] ?: 'No incident types set') ?></div>
                        </div>
                        <span class="avail avail-<?= e($r['availability']) ?>"><?= e(ucfirst(str_replace('_', ' ', $r['availability']))) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$responders): ?><p class="text-secondary small mb-0">No responder teams added yet.</p><?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php
$feed_url = url('api/map_incidents.php');
$incident_url = url('admin/incident.php?id=');
$chart_labels = js_json(array_column($by_type, 'type_name'));
$chart_data = js_json(array_map('intval', array_column($by_type, 'total')));
$colors_json = js_json($status_colors);
$version_json = js_json(dashboard_version($conn));
$login_url = url('login.php');
$blat = BRGY_LAT; $blng = BRGY_LNG;

$extra_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
<script>
const STATUS_COLORS = {$colors_json};

// ---------- map ----------
// barangay 172 coords from PhilAtlas, the map zooms to the pins anyway once there are reports
const map = L.map('incidentMap', { scrollWheelZoom: false }).setView([{$blat}, {$blng}], 16);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

const incidentLayer = L.layerGroup().addTo(map);
const centerLayer = L.layerGroup().addTo(map);
let firstLoad = true;
const PAGE_VERSION = {$version_json};
let openId = null;   // incident whose popup the admin has open, so the 30s refresh doesnt close it

// escape text before putting it in popups
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const cap = s => String(s ?? '').charAt(0).toUpperCase() + String(s ?? '').slice(1);

function pinIcon(inc) {
    const pulse = inc.status === 'pending' && inc.urgency === 'critical' ? ' pulse' : '';
    const prio = inc.is_priority ? ' priority' : '';
    return L.divIcon({
        className: '',
        html: '<div class="pin' + prio + pulse + '" style="background:' + STATUS_COLORS[inc.status] + '"><i class="bi ' + esc(inc.icon_name) + '"></i></div>',
        iconSize: [34, 34],
        iconAnchor: [17, 34],
        popupAnchor: [0, -30]
    });
}

function loadFeed() {
    fetch('{$feed_url}', { credentials: 'same-origin' })
        .then(r => {
            // logged out (session expired or account deactivated), go log in again instead of retrying forever
            if (r.status === 401) { window.location = '{$login_url}'; throw new Error('401'); }
            if (!r.ok) throw new Error(r.status);
            return r.json();
        })
        .then(data => {
            const keepOpen = openId;
            incidentLayer.clearLayers();
            centerLayer.clearLayers();
            const bounds = [];

            data.incidents.forEach(inc => {
                const pos = [inc.latitude, inc.longitude];
                bounds.push(pos);
                const m = L.marker(pos, { icon: pinIcon(inc), zIndexOffset: inc.status === 'resolved' ? 0 : 1000 })
                    .bindPopup(
                        '<div class="popup-title">' + esc(inc.type_name) + ' #' + inc.incident_id + '</div>' +
                        '<span class="st st-' + esc(inc.status) + '">' + esc(inc.status_label) + '</span> ' +
                        (inc.urgency ? '<span class="urg urg-' + esc(inc.urgency) + '">' + esc(cap(inc.urgency)) + '</span> ' : '') +
                        (inc.is_priority ? '<span class="prio-flag">Priority</span>' : '') +
                        '<p class="mb-1 mt-2">' + esc(inc.description) + '</p>' +
                        '<div class="text-secondary">' + esc(inc.landmark || 'No landmark') + ' &middot; ' + esc(inc.ago) + (inc.is_guest ? ' &middot; guest report' : '') + '</div>' +
                        '<a class="d-inline-block mt-2 fw-semibold" href="{$incident_url}' + inc.incident_id + '">Open incident</a>',
                        { autoPan: !keepOpen }
                    )
                    .addTo(incidentLayer);
                m.on('popupopen', () => { openId = inc.incident_id; });
                m.on('popupclose', () => { if (openId === inc.incident_id) openId = null; });
                if (keepOpen === inc.incident_id) m.openPopup();
            });

            data.centers.forEach(c => {
                L.marker([c.latitude, c.longitude], {
                    icon: L.divIcon({ className: '', html: '<div class="evac-pin ' + (c.status === 'open' ? '' : esc(c.status)) + '"><i class="bi bi-house-fill"></i></div>', iconSize: [28, 28], iconAnchor: [14, 14] })
                }).bindPopup('<div class="popup-title">' + esc(c.center_name) + '</div>' + esc(cap(c.status)) + (c.capacity > 0 ? ' &middot; fits about ' + esc(c.capacity) + ' people' : ''))
                  .addTo(centerLayer);
            });

            // zoom to fit the pins only on first load so it doesnt jump while the admin is looking around
            if (firstLoad && bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 17 });
            firstLoad = false;

            // update the numbers at the top too
            document.getElementById('cActive').textContent = data.counts.active;
            document.getElementById('cPending').textContent = data.counts.pending;
            document.getElementById('cPriority').textContent = data.counts.priority;
            document.getElementById('cPendingBox').classList.toggle('alert-on', data.counts.pending > 0);

            // the lists on this page dont refresh by themselves, so tell the admin when theyre behind
            if (data.version !== PAGE_VERSION) document.getElementById('newUpdates').classList.remove('d-none');

            const tag = document.getElementById('liveTag');
            tag.textContent = 'Updated ' + data.updated;
            tag.style.color = '';
        })
        .catch(() => {
            const tag = document.getElementById('liveTag');
            tag.textContent = 'Can\'t reach the server, retrying';
            tag.style.color = 'var(--st-pending)';
        });
}

loadFeed();
setInterval(loadFeed, 30000); // refresh every 30 secs

// ---------- chart ----------
// wait for the fonts first or chart.js measures the labels wrong and cuts them
const chartEl = document.getElementById('typeChart');
if (chartEl) document.fonts.ready.then(() => new Chart(chartEl, {
    type: 'bar',
    data: {
        labels: {$chart_labels},
        datasets: [{ data: {$chart_data}, backgroundColor: '#57331F', hoverBackgroundColor: '#E2A43A', borderRadius: 4, maxBarThickness: 28 }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#EDE4D6' } },
            y: { grid: { display: false }, ticks: { font: { family: 'Public Sans', weight: 600 } } }
        }
    }
}));
</script>
HTML;

include __DIR__ . '/../includes/footer.php';
