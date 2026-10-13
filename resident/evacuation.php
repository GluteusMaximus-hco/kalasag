<?php
// nearby evacuation centers for residents
// sorts centers by distance from the phone's location (if allowed) or the household pin
require_once __DIR__ . '/../includes/auth.php';
$u = require_role('resident');

$page_title = 'Evacuation centers';
$active = 'evac';

try {
    $centers = $conn->query("SELECT center_name, address, latitude, longitude, capacity, status, last_updated
            FROM evacuation_centers
            ORDER BY FIELD(status, 'open', 'full', 'closed'), center_name")->fetch_all(MYSQLI_ASSOC);

    // household pin is the fallback starting point if the phone wont share its location
    $st = $conn->prepare('SELECT h.latitude, h.longitude FROM residents r
                          JOIN households h ON h.household_id = r.household_id
                          WHERE r.user_id = ? AND h.latitude IS NOT NULL');
    $st->bind_param('i', $u['user_id']);
    $st->execute();
    $home = $st->get_result()->fetch_assoc();
} catch (mysqli_sql_exception $ex) {
    error_log('evac page: ' . $ex->getMessage());
    http_response_code(500);
    exit('This page could not load. Try again in a minute.');
}

// only centers with a map location can be sorted by distance
$mapped = array_values(array_filter($centers, fn($c) => $c['latitude'] !== null && $c['longitude'] !== null));
$points = array_map(fn($c) => [
    'name' => $c['center_name'], 'address' => $c['address'], 'status' => $c['status'],
    'capacity' => (int)$c['capacity'], 'lat' => (float)$c['latitude'], 'lng' => (float)$c['longitude'],
], $mapped);

$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">
    <div class="col-lg-7">
        <section class="panel">
            <div class="panel-head flex-wrap">
                <h2>Map</h2>
                <span class="hint" id="originNote">Finding your location...</span>
            </div>
            <div id="evacMap" role="region" aria-label="Map of evacuation centers"></div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="panel">
            <div class="panel-head"><h2>Nearest first</h2></div>
            <div class="panel-body">
                <?php if (!$centers): ?>
                    <div class="empty pt-0"><i class="bi bi-building" style="color:var(--muted)"></i>No evacuation centers posted yet. The barangay will add them here.</div>
                <?php else: ?>
                    <div id="evacList">
                        <?php foreach ($centers as $c): ?>
                            <div class="evac-row" <?php if ($c['latitude'] !== null): ?>data-lat="<?= e($c['latitude']) ?>" data-lng="<?= e($c['longitude']) ?>"<?php endif; ?>>
                                <div>
                                    <div class="fw-semibold"><?= e($c['center_name']) ?></div>
                                    <div class="text-secondary small"><?= e($c['address'] ?: 'No address posted') ?></div>
                                    <div class="small mt-1">
                                        <span class="evac-status <?= e($c['status']) ?>"><?= e(ucfirst($c['status'])) ?></span>
                                        <span class="text-secondary"> &middot; fits about <?= (int)$c['capacity'] ?> people</span>
                                    </div>
                                    <?php if ($c['latitude'] !== null): ?>
                                        <a class="small fw-semibold" target="_blank" rel="noopener"
                                           href="https://www.google.com/maps/dir/?api=1&destination=<?= e($c['latitude']) ?>,<?= e($c['longitude']) ?>">
                                            <i class="bi bi-sign-turn-right-fill me-1"></i>Directions
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <span class="dist"></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php
$points_json = json_encode($points);
$home_json = json_encode($home ? ['lat' => (float)$home['latitude'], 'lng' => (float)$home['longitude']] : null);
$extra_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const CENTERS = {$points_json};
const HOME = {$home_json};
// barangay 172 coords from PhilAtlas, used when we dont know where the resident is
const BRGY = [14.5351, 121.0114];

const map = L.map('evacMap', { scrollWheelZoom: false }).setView(BRGY, 16);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

CENTERS.forEach(c => {
    L.marker([c.lat, c.lng], { icon: L.divIcon({ className: '', html: '<div class="evac-pin ' + (c.status === 'open' ? '' : 'closed') + '"><i class="bi bi-house-fill"></i></div>', iconSize: [28, 28], iconAnchor: [14, 14] }) })
        .bindPopup('<div class="popup-title">' + esc(c.name) + '</div>' + esc(c.status) + ', fits about ' + esc(c.capacity))
        .addTo(map);
});

// straight-line distance in meters (haversine)
function meters(a, b) {
    const R = 6371000, r = x => x * Math.PI / 180;
    const dLat = r(b[0] - a[0]), dLng = r(b[1] - a[1]);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(r(a[0])) * Math.cos(r(b[0])) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}
const nice = m => m < 1000 ? Math.round(m / 10) * 10 + ' m' : (m / 1000).toFixed(1) + ' km';

// sort the list by distance from wherever the resident is
let youMarker = null;
function useOrigin(origin, label) {
    document.getElementById('originNote').textContent = label;
    if (youMarker) map.removeLayer(youMarker);   // gps can replace the household pin later
    youMarker = L.marker(origin, { zIndexOffset: 1000, icon: L.divIcon({ className: '', html: '<div class="you-pin"></div>', iconSize: [18, 18], iconAnchor: [9, 9] }) })
        .bindPopup(label).addTo(map);

    const list = document.getElementById('evacList');
    if (!list) { map.setView(origin, 16); return; }
    const rows = [...list.querySelectorAll('.evac-row')];
    rows.forEach(row => {
        if (!row.dataset.lat) { row.dataset.d = Infinity; return; }
        const d = meters(origin, [+row.dataset.lat, +row.dataset.lng]);
        row.dataset.d = d;
        row.querySelector('.dist').textContent = nice(d);
    });
    rows.sort((a, b) => a.dataset.d - b.dataset.d).forEach(row => list.appendChild(row));

    // zoom so you and the nearest few centers fit
    const pts = [origin, ...CENTERS.map(c => [c.lat, c.lng]).sort((a, b) => meters(origin, a) - meters(origin, b)).slice(0, 3)];
    map.fitBounds(pts, { padding: [40, 40], maxZoom: 17 });
}

function noOrigin() {
    if (HOME) return useOrigin([HOME.lat, HOME.lng], 'Distances from your household pin');
    document.getElementById('originNote').textContent = 'Allow location or set up your household to see distances';
    if (CENTERS.length) map.fitBounds(CENTERS.map(c => [c.lat, c.lng]), { padding: [40, 40], maxZoom: 17 });
}

// sort by the household pin right away so nobody waits on gps, then redo it if gps answers
if (HOME) useOrigin([HOME.lat, HOME.lng], 'Distances from your household pin');

// phones only share GPS on https (or localhost while developing)
if ('geolocation' in navigator) {
    navigator.geolocation.getCurrentPosition(
        pos => useOrigin([pos.coords.latitude, pos.coords.longitude], 'Distances from where you are now'),
        noOrigin,
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
    );
} else {
    noOrigin();
}
</script>
HTML;
include __DIR__ . '/../includes/footer.php';
