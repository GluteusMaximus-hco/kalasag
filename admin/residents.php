<?php
// residents + their households, admin only
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
$u = require_role('admin');

$page_title = 'Residents & households';
$active = 'residents';
$tab = ($_GET['tab'] ?? 'residents') === 'households' ? 'households' : 'residents';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $rid = (int)($_POST['resident_id'] ?? 0);
    if ($action === 'verify') {
        $st = $conn->prepare('UPDATE residents SET is_verified = 1 - is_verified WHERE resident_id = ?');
        $st->bind_param('i', $rid); $st->execute();
        flash('success', 'Verification updated.');
    } elseif ($action === 'toggle_active') {
        $st = $conn->prepare('UPDATE users us JOIN residents r ON r.user_id = us.user_id SET us.is_active = 1 - us.is_active WHERE r.resident_id = ?');
        $st->bind_param('i', $rid); $st->execute();
        flash('success', 'Account updated.');
    } elseif ($action === 'reset_password') {
        // for residents without email: they come to the hall, admin checks their ID, then gives a temporary password
        $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 10; $i++) $temp .= $abc[random_int(0, strlen($abc) - 1)];
        $st = $conn->prepare('SELECT first_name FROM residents WHERE resident_id = ?');
        $st->bind_param('i', $rid); $st->execute();
        $who = $st->get_result()->fetch_assoc()['first_name'] ?? 'the resident';
        $hash = password_hash($temp, PASSWORD_DEFAULT);
        $st = $conn->prepare('UPDATE users us JOIN residents r ON r.user_id = us.user_id SET us.password_hash = ?, us.reset_token_hash = NULL, us.reset_expires_at = NULL WHERE r.resident_id = ?');
        $st->bind_param('si', $hash, $rid); $st->execute();
        flash('warning', 'Temporary password for ' . $who . ': ' . $temp . '. Give it to them in person and ask them to change it in Account settings right away. It will not be shown again.');
    }
    redirect('admin/residents.php?tab=' . $tab . (isset($_GET['q']) ? '&q=' . urlencode($_GET['q']) : ''));
}

$q = trim($_GET['q'] ?? '');
$like = '%' . $q . '%';

$st = $conn->prepare("SELECT r.resident_id, r.first_name, r.last_name, r.birthdate, r.contact_no, r.is_verified,
        us.username, us.email, us.is_active, us.created_at, h.address, h.purok, h.has_elderly, h.has_pwd, h.has_pregnant, h.has_bedridden,
        (SELECT COUNT(*) FROM incidents i WHERE i.resident_id = r.resident_id) AS reports
    FROM residents r JOIN users us ON us.user_id = r.user_id
    LEFT JOIN households h ON h.household_id = r.household_id
    WHERE (? = '' OR CONCAT(r.first_name, ' ', r.last_name) LIKE ? OR us.username LIKE ? OR r.contact_no LIKE ? OR h.address LIKE ?)
    ORDER BY r.is_verified, us.created_at DESC");
$st->bind_param('sssss', $q, $like, $like, $like, $like);
$st->execute();
$residents = $st->get_result()->fetch_all(MYSQLI_ASSOC);

// subqueries instead of GROUP BY so it works even with strict ONLY_FULL_GROUP_BY
$households = $conn->query("SELECT h.*,
        (SELECT COUNT(*) FROM residents r WHERE r.household_id = h.household_id) AS members,
        (SELECT GROUP_CONCAT(CONCAT(r.first_name, ' ', r.last_name) SEPARATOR ', ') FROM residents r WHERE r.household_id = h.household_id) AS names
    FROM households h ORDER BY h.vulnerability_score DESC, h.address")->fetch_all(MYSQLI_ASSOC);

$counts = $conn->query("SELECT (SELECT COUNT(*) FROM residents) AS residents, (SELECT COUNT(*) FROM residents WHERE is_verified = 0) AS unverified,
        (SELECT COUNT(*) FROM households) AS households, (SELECT COUNT(*) FROM households WHERE vulnerability_score > 0) AS vulnerable")->fetch_assoc();

$extra_head = $tab === 'households' ? '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">' : '';
include __DIR__ . '/../includes/header.php';
?>
<div class="stat-row mb-4">
    <div class="stat"><div class="num"><?= (int)$counts['residents'] ?></div><div class="lbl">Registered residents</div></div>
    <div class="stat"><div class="num"><?= (int)$counts['unverified'] ?></div><div class="lbl">Waiting for verification</div></div>
    <div class="stat"><div class="num"><?= (int)$counts['households'] ?></div><div class="lbl">Households set up</div></div>
    <div class="stat"><div class="num" style="color:#8A5210"><?= (int)$counts['vulnerable'] ?></div><div class="lbl">With vulnerable members</div></div>
</div>

<ul class="nav nav-tabs">
    <li class="nav-item"><a class="nav-link <?= $tab === 'residents' ? 'active' : '' ?>" href="?tab=residents">Residents</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'households' ? 'active' : '' ?>" href="?tab=households">Households</a></li>
</ul>

<section class="panel" style="border-top-left-radius:0">
<?php if ($tab === 'residents'): ?>
    <div class="panel-body pt-3">
        <form class="filter-bar" method="get">
            <input type="hidden" name="tab" value="residents">
            <div class="flex-grow-1"><label class="visually-hidden" for="q">Search</label>
                <input class="form-control" type="search" id="q" name="q" placeholder="Search name, username, mobile, or address" value="<?= e($q) ?>"></div>
            <button class="btn btn-umber" type="submit">Search</button>
        </form>
    </div>
    <?php if (!$residents): ?>
        <div class="empty pt-0"><i class="bi bi-people" style="color:var(--muted)"></i><?= $q ? 'No residents match that search.' : 'No residents have registered yet.' ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table data-table">
                <thead><tr><th>Resident</th><th>Contact</th><th>Household</th><th>Reports</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($residents as $r): $flags = vuln_flags($r); ?>
                    <tr class="<?= $r['is_active'] ? '' : 'opacity-50' ?>">
                        <td><div class="fw-semibold"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div>
                            <div class="small text-secondary">@<?= e($r['username']) ?> &middot; joined <?= e(date('M j, Y', strtotime($r['created_at']))) ?></div></td>
                        <td class="small"><?= e($r['contact_no']) ?><?= $r['email'] ? '<br>' . e($r['email']) : '' ?></td>
                        <td class="small"><?= $r['address'] ? e($r['address']) . ($r['purok'] ? ', ' . e(stripos($r['purok'], 'purok') === 0 ? $r['purok'] : 'Purok ' . $r['purok']) : '') : '<span class="text-secondary">Not set up</span>' ?>
                            <?php if ($flags): ?><div><span class="prio-flag"><?= e(implode(', ', $flags)) ?></span></div><?php endif; ?></td>
                        <td><?= (int)$r['reports'] ?></td>
                        <td><?= $r['is_verified'] ? '<span class="evac-status open"><i class="bi bi-patch-check-fill"></i> Verified</span>' : '<span class="evac-status full">Not verified</span>' ?></td>
                        <td class="text-end">
                            <div class="dropdown">
                                <!-- fixed strategy so the menu isnt cut off by the scrolling table (last row bug) -->
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">Actions</button>
                                <div class="dropdown-menu dropdown-menu-end p-1">
                                    <?php foreach ([
                                        ['verify', $r['is_verified'] ? 'Remove verification' : 'Mark as verified', ''],
                                        ['reset_password', 'Reset password', 'Reset the password for ' . $r['first_name'] . '? Only do this after checking their valid ID in person.'],
                                        ['toggle_active', $r['is_active'] ? 'Deactivate account' : 'Activate account', $r['is_active'] ? 'Deactivate this account? They will not be able to log in.' : ''],
                                    ] as [$act, $lbl, $confirm]): ?>
                                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="resident_id" value="<?= (int)$r['resident_id'] ?>">
                                            <button class="dropdown-item small" type="submit" <?= $confirm ? 'data-confirm="' . e($confirm) . '"' : '' ?>><?= e($lbl) ?></button></form>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="panel-body pt-3">
        <p class="small text-secondary mb-2">Households with elderly, PWD, pregnant, or bedridden members are marked in gold. Reports within <?= PRIORITY_RADIUS_M ?> m of them are flagged as priority.</p>
        <div id="hhMap" class="pick-map mb-3" role="region" aria-label="Map of households"></div>
    </div>
    <?php if (!$households): ?>
        <div class="empty pt-0"><i class="bi bi-house" style="color:var(--muted)"></i>No households set up yet. Residents add theirs from "My household".</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table data-table">
                <thead><tr><th>Address</th><th>Purok</th><th>Vulnerable members</th><th>Residents</th></tr></thead>
                <tbody>
                <?php foreach ($households as $h): $flags = vuln_flags($h); ?>
                    <tr><td class="fw-semibold"><?= e($h['address']) ?></td><td><?= e($h['purok'] ?: '') ?></td>
                        <td><?= $flags ? '<span class="prio-flag">' . e(implode(', ', $flags)) . '</span>' : '<span class="text-secondary small">None</span>' ?></td>
                        <td class="small"><?= e($h['names'] ?: 'No linked residents') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
</section>
<?php
if ($tab === 'households') {
    $pts = js_json(array_values(array_map(fn($h) => ['lat' => (float)$h['latitude'], 'lng' => (float)$h['longitude'], 'v' => (int)$h['vulnerability_score'], 'a' => $h['address'], 'f' => implode(', ', vuln_flags($h))],
        array_filter($households, fn($h) => $h['latitude'] !== null))));
    $lat = BRGY_LAT; $lng = BRGY_LNG;
    $extra_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const HH = {$pts};
const map = L.map('hhMap', { scrollWheelZoom: false }).setView([{$lat}, {$lng}], 16);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
HH.forEach(h => L.circleMarker([h.lat, h.lng], { radius: h.v ? 9 : 6, color: '#fff', weight: 2, fillColor: h.v ? '#E2A43A' : '#57331F', fillOpacity: 0.95 })
    .bindPopup('<strong>' + esc(h.a) + '</strong>' + (h.f ? '<br>' + esc(h.f) : '')).addTo(map));
if (HH.length) map.fitBounds(HH.map(h => [h.lat, h.lng]), { padding: [40, 40], maxZoom: 17 });
</script>
HTML;
}
include __DIR__ . '/../includes/footer.php';
