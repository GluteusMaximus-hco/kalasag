<?php
// resident sets up their household: where it is + who needs extra help
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
$u = require_role('resident');

$page_title = 'My household';
$active = 'household';
$errors = [];

$st = $conn->prepare('SELECT r.resident_id, h.* FROM residents r LEFT JOIN households h ON h.household_id = r.household_id WHERE r.user_id = ?');
$st->bind_param('i', $u['user_id']);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$has = !empty($row['household_id']);

$d = [
    'address' => $row['address'] ?? '', 'purok' => $row['purok'] ?? '',
    'latitude' => $row['latitude'] ?? '', 'longitude' => $row['longitude'] ?? '',
    'has_elderly' => (int)($row['has_elderly'] ?? 0), 'has_pwd' => (int)($row['has_pwd'] ?? 0),
    'has_pregnant' => (int)($row['has_pregnant'] ?? 0), 'has_bedridden' => (int)($row['has_bedridden'] ?? 0),
];
$flags = ['has_elderly' => ['Elderly (60 and above)', 'bi-person-cane'], 'has_pwd' => ['Person with disability (PWD)', 'bi-person-wheelchair'],
          'has_pregnant' => ['Pregnant', 'bi-heart-pulse'], 'has_bedridden' => ['Bedridden or cannot walk on their own', 'bi-hospital']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $d['address'] = trim($_POST['address'] ?? '');
    $d['purok'] = trim($_POST['purok'] ?? '');
    $d['latitude'] = trim($_POST['latitude'] ?? '');
    $d['longitude'] = trim($_POST['longitude'] ?? '');
    foreach ($flags as $k => $_) $d[$k] = isset($_POST[$k]) ? 1 : 0;

    if ($d['address'] === '' || mb_strlen($d['address']) > 255) $errors['address'] = 'Enter your house number and street.';
    if (mb_strlen($d['purok']) > 50) $errors['purok'] = 'Keep the purok under 50 characters.';
    $lat = filter_var($d['latitude'], FILTER_VALIDATE_FLOAT); $lng = filter_var($d['longitude'], FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false) $errors['location'] = 'Pin your house on the map.';
    elseif ($lat < 14.3 || $lat > 14.85 || $lng < 120.85 || $lng > 121.2) $errors['location'] = 'That pin is outside Metro Manila. Move it to your house.';
    if (empty($_POST['consent'])) $errors['consent'] = 'You need to agree to the privacy notice to save your household.';

    if (!$errors) {
        $score = $d['has_elderly'] + $d['has_pwd'] + $d['has_pregnant'] + $d['has_bedridden'];
        $purok = $d['purok'] === '' ? null : $d['purok'];
        $conn->begin_transaction();
        try {
            if ($has) {
                $st = $conn->prepare('UPDATE households SET address=?, purok=?, latitude=?, longitude=?, has_elderly=?, has_pwd=?, has_pregnant=?, has_bedridden=?, vulnerability_score=? WHERE household_id=?');
                $hid = (int)$row['household_id'];
                $st->bind_param('ssddiiiiii', $d['address'], $purok, $lat, $lng, $d['has_elderly'], $d['has_pwd'], $d['has_pregnant'], $d['has_bedridden'], $score, $hid);
                $st->execute();
            } else {
                $st = $conn->prepare('INSERT INTO households (address, purok, latitude, longitude, has_elderly, has_pwd, has_pregnant, has_bedridden, vulnerability_score) VALUES (?,?,?,?,?,?,?,?,?)');
                $st->bind_param('ssddiiiii', $d['address'], $purok, $lat, $lng, $d['has_elderly'], $d['has_pwd'], $d['has_pregnant'], $d['has_bedridden'], $score);
                $st->execute();
                $hid = $conn->insert_id;
                $st = $conn->prepare('UPDATE residents SET household_id = ? WHERE resident_id = ?');
                $st->bind_param('ii', $hid, $row['resident_id']);
                $st->execute();
            }
            $conn->commit();
            flash('success', 'Household saved. Thank you, this helps the barangay reach the people who need help first.');
            redirect('resident/household.php');
        } catch (Throwable $ex) {
            $conn->rollback();
            error_log('household save: ' . $ex->getMessage());
            $errors['form'] = 'Your household could not be saved. Try again in a minute.';
        }
    }
}

$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . '/../includes/header.php';
?>
<?php if (isset($errors['form'])): ?><div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?><div class="alert alert-danger">Fix the fields marked in red.</div><?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <section class="panel mb-4">
                <div class="panel-head"><h2>Address</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-sm-8">
                            <label class="form-label" for="address">House number and street</label>
                            <input class="form-control<?= isset($errors['address']) ? ' is-invalid' : '' ?>" id="address" name="address" maxlength="255" autocomplete="street-address" value="<?= e($d['address']) ?>" required>
                            <?= $err('address') ?>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="purok">Purok <span class="text-secondary fw-normal">(if any)</span></label>
                            <input class="form-control<?= isset($errors['purok']) ? ' is-invalid' : '' ?>" id="purok" name="purok" maxlength="50" value="<?= e($d['purok']) ?>">
                            <?= $err('purok') ?>
                        </div>
                    </div>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head flex-wrap">
                    <h2>Pin your house</h2>
                    <button type="button" class="btn btn-sm btn-umber" id="locateBtn"><i class="bi bi-crosshair me-1"></i>Use my location</button>
                </div>
                <div class="panel-body">
                    <p class="small text-secondary mb-2" id="pinNote" aria-live="polite">If you are home, tap "Use my location". Otherwise tap your house on the map.</p>
                    <div class="pick-map" id="pickMap" role="region" aria-label="Map for pinning your house"></div>
                    <input type="hidden" name="latitude" id="lat" value="<?= e($d['latitude']) ?>">
                    <input type="hidden" name="longitude" id="lng" value="<?= e($d['longitude']) ?>">
                    <?= $err('location') ?>
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="panel mb-4">
                <div class="panel-head"><h2>Who lives with you?</h2></div>
                <div class="panel-body">
                    <p class="small text-secondary">Check all that apply. During emergencies near your home, the barangay will reach your household first.</p>
                    <?php foreach ($flags as $k => [$label, $icon]): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="<?= $k ?>" name="<?= $k ?>" <?= $d[$k] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= $k ?>"><i class="bi <?= $icon ?> me-1 text-secondary"></i><?= e($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Privacy notice</h2></div>
                <div class="panel-body">
                    <div class="consent mb-3">
                        Barangay 172 collects your household address, map location, and whether anyone at home is elderly, a PWD, pregnant, or bedridden. This is only used to plan and prioritize emergency response. Only authorized barangay staff can see it, and it is handled under the Data Privacy Act of 2012 (RA 10173). You can update or ask the barangay to remove it anytime.
                    </div>
                    <div class="form-check">
                        <input class="form-check-input<?= isset($errors['consent']) ? ' is-invalid' : '' ?>" type="checkbox" id="consent" name="consent" <?= $has ? 'checked' : '' ?> required>
                        <label class="form-check-label small fw-semibold" for="consent">I agree to let the barangay use this information for emergency response.</label>
                    </div>
                    <?= $err('consent') ?>
                    <button class="btn btn-umber w-100 mt-3" type="submit"><?= $has ? 'Save changes' : 'Save household' ?></button>
                </div>
            </section>
        </div>
    </div>
</form>
<?php
$lat = BRGY_LAT; $lng = BRGY_LNG;
$extra_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>KALASAG.pinPicker({ map: 'pickMap', lat: 'lat', lng: 'lng', locate: 'locateBtn', note: 'pinNote', center: [{$lat}, {$lng}] });</script>
HTML;
include __DIR__ . '/../includes/footer.php';
