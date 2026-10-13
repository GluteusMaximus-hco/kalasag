<?php
// evacuation centers: where they are, how many fit, and if they're open
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
$u = require_role('admin');

$page_title = 'Evacuation centers';
$active = 'evac';
$statuses = ['open' => 'Open', 'full' => 'Full', 'closed' => 'Closed'];
$errors = [];
$edit_id = (int)($_GET['edit'] ?? 0);
$d = ['center_name' => '', 'address' => '', 'latitude' => '', 'longitude' => '', 'capacity' => '', 'status' => 'closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $cid = (int)($_POST['center_id'] ?? 0);

    if ($action === 'status' && isset($statuses[$_POST['status'] ?? ''])) {
        $s = $_POST['status'];
        $st = $conn->prepare('UPDATE evacuation_centers SET status = ?, updated_by = ? WHERE center_id = ?');
        $st->bind_param('sii', $s, $u['user_id'], $cid); $st->execute();
        flash('success', 'Center marked ' . strtolower($statuses[$s]) . '.');
        redirect('admin/evacuation.php');
    }
    if ($action === 'delete') {
        $st = $conn->prepare('DELETE FROM evacuation_centers WHERE center_id = ?');
        $st->bind_param('i', $cid); $st->execute();
        flash('success', 'Center removed.');
        redirect('admin/evacuation.php');
    }
    if ($action === 'save') {
        foreach ($d as $k => $_) $d[$k] = trim($_POST[$k] ?? '');
        if ($d['center_name'] === '' || mb_strlen($d['center_name']) > 120) $errors['center_name'] = 'Enter the center name.';
        if (mb_strlen($d['address']) > 255) $errors['address'] = 'Keep the address under 255 characters.';
        $cap = filter_var($d['capacity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($cap === false) $errors['capacity'] = 'Enter how many people it can hold.';
        if (!isset($statuses[$d['status']])) $d['status'] = 'closed';
        $lat = filter_var($d['latitude'], FILTER_VALIDATE_FLOAT); $lng = filter_var($d['longitude'], FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false) $errors['location'] = 'Pin the center on the map so residents can find it.';
        elseif ($lat < 14.3 || $lat > 14.85 || $lng < 120.85 || $lng > 121.2) $errors['location'] = 'That pin is outside Metro Manila.';

        if (!$errors) {
            $addr = $d['address'] === '' ? null : $d['address'];
            if ($cid) {
                $st = $conn->prepare('UPDATE evacuation_centers SET center_name=?, address=?, latitude=?, longitude=?, capacity=?, status=?, updated_by=? WHERE center_id=?');
                $st->bind_param('ssddisii', $d['center_name'], $addr, $lat, $lng, $cap, $d['status'], $u['user_id'], $cid);
            } else {
                $st = $conn->prepare('INSERT INTO evacuation_centers (center_name, address, latitude, longitude, capacity, status, updated_by) VALUES (?,?,?,?,?,?,?)');
                $st->bind_param('ssddisi', $d['center_name'], $addr, $lat, $lng, $cap, $d['status'], $u['user_id']);
            }
            $st->execute();
            flash('success', $cid ? 'Center updated.' : 'Center added. Residents can now see it on their nearby centers map.');
            redirect('admin/evacuation.php');
        }
        $edit_id = $cid;
    }
}

if ($edit_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $conn->prepare('SELECT * FROM evacuation_centers WHERE center_id = ?');
    $st->bind_param('i', $edit_id); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if ($row) $d = $row; else $edit_id = 0;
}

$centers = $conn->query("SELECT c.*, COALESCE(us.full_name, '') AS updater FROM evacuation_centers c LEFT JOIN users us ON us.user_id = c.updated_by
                         ORDER BY FIELD(c.status, 'open', 'full', 'closed'), c.center_name")->fetch_all(MYSQLI_ASSOC);
$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-7">
        <section class="panel">
            <div class="panel-head"><h2>Centers <span class="count-pill"><?= count($centers) ?></span></h2><span class="hint">Change status during an emergency</span></div>
            <div class="panel-body">
                <?php if (!$centers): ?><div class="empty pt-0"><i class="bi bi-building" style="color:var(--muted)"></i>No evacuation centers yet. Add the ones the barangay uses.</div><?php endif; ?>
                <?php foreach ($centers as $c): ?>
                    <div class="evac-row">
                        <div>
                            <div class="fw-semibold"><?= e($c['center_name']) ?></div>
                            <div class="small text-secondary"><?= e($c['address'] ?: 'No address') ?> &middot; fits about <?= (int)$c['capacity'] ?></div>
                            <div class="small text-secondary">Updated <?= e(time_ago($c['last_updated'])) ?><?= $c['updater'] ? ' by ' . e($c['updater']) : '' ?></div>
                            <div class="d-flex gap-2 mt-2">
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$c['center_id'] ?>">Edit</a>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="center_id" value="<?= (int)$c['center_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Remove <?= e($c['center_name']) ?>?">Remove</button></form>
                            </div>
                        </div>
                        <form method="post" class="btn-group btn-group-sm align-self-start" role="group" aria-label="Status of <?= e($c['center_name']) ?>">
                            <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="center_id" value="<?= (int)$c['center_id'] ?>">
                            <?php foreach ($statuses as $k => $lbl): ?>
                                <button type="submit" name="status" value="<?= $k ?>" class="btn <?= $c['status'] === $k ? 'btn-umber' : 'btn-outline-secondary' ?>" <?= $c['status'] === $k ? 'aria-pressed="true"' : '' ?>><?= $lbl ?></button>
                            <?php endforeach; ?>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="panel">
            <div class="panel-head"><h2><?= $edit_id ? 'Edit center' : 'Add a center' ?></h2><?php if ($edit_id): ?><a class="hint" href="<?= url('admin/evacuation.php') ?>">Cancel</a><?php endif; ?></div>
            <div class="panel-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="center_id" value="<?= $edit_id ?>">
                    <div class="mb-3"><label class="form-label" for="center_name">Name</label>
                        <input class="form-control<?= isset($errors['center_name']) ? ' is-invalid' : '' ?>" id="center_name" name="center_name" maxlength="120" placeholder="Example: Barangay 172 Covered Court" value="<?= e($d['center_name']) ?>"><?= $err('center_name') ?></div>
                    <div class="mb-3"><label class="form-label" for="address">Address</label>
                        <input class="form-control<?= isset($errors['address']) ? ' is-invalid' : '' ?>" id="address" name="address" maxlength="255" value="<?= e($d['address']) ?>"><?= $err('address') ?></div>
                    <div class="row g-3 mb-3">
                        <div class="col-6"><label class="form-label" for="capacity">Capacity (people)</label>
                            <input class="form-control<?= isset($errors['capacity']) ? ' is-invalid' : '' ?>" id="capacity" name="capacity" type="number" min="1" inputmode="numeric" value="<?= e($d['capacity']) ?>"><?= $err('capacity') ?></div>
                        <div class="col-6"><label class="form-label" for="status">Status</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach ($statuses as $k => $lbl): ?><option value="<?= $k ?>" <?= $d['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                            </select></div>
                    </div>
                    <label class="form-label">Location</label>
                    <p class="small text-secondary mb-2" id="pinNote">Tap the map where the center is. You can drag the pin.</p>
                    <div class="pick-map" id="pickMap" role="region" aria-label="Map for pinning the center"></div>
                    <input type="hidden" name="latitude" id="lat" value="<?= e($d['latitude']) ?>">
                    <input type="hidden" name="longitude" id="lng" value="<?= e($d['longitude']) ?>">
                    <?= $err('location') ?>
                    <button class="btn btn-umber w-100 mt-3" type="submit"><?= $edit_id ? 'Save changes' : 'Add center' ?></button>
                </form>
            </div>
        </section>
    </div>
</div>
<?php
$lat = BRGY_LAT; $lng = BRGY_LNG;
$extra_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>KALASAG.pinPicker({ map: 'pickMap', lat: 'lat', lng: 'lng', note: 'pinNote', center: [{$lat}, {$lng}] });</script>
HTML;
include __DIR__ . '/../includes/footer.php';
