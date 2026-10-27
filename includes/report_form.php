<?php
// report form shared by resident/report.php and guest_report.php
require_once __DIR__ . '/notify.php';
// handles both the checking and the saving so both pages behave the same

function report_defaults() {
    return ['type_id' => '', 'description' => '', 'latitude' => '', 'longitude' => '', 'gps_accuracy' => '', 'landmark' => '', 'guest_name' => '', 'guest_contact' => ''];
}

// returns [errors, cleaned data]
function validate_report($post, $files, $types, $guest) {
    $d = report_defaults();
    foreach ($d as $k => $_) $d[$k] = trim((string)($post[$k] ?? ''));
    $e = [];

    if (!in_array((int)$d['type_id'], array_map('intval', array_column($types, 'type_id')), true)) $e['type_id'] = 'Pick what kind of emergency it is.';

    $len = mb_strlen($d['description']);
    if ($len < 10) $e['description'] = 'Describe what is happening in at least 10 characters.';
    elseif ($len > 1000) $e['description'] = 'Keep the description under 1000 characters.';

    // rough box around metro manila so a wrong tap in the ocean gets caught
    $lat = filter_var($d['latitude'], FILTER_VALIDATE_FLOAT);
    $lng = filter_var($d['longitude'], FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false) $e['location'] = 'Set the location: tap "Use my location" or tap the map.';
    elseif ($lat < 14.3 || $lat > 14.85 || $lng < 120.85 || $lng > 121.2) { $e['location'] = 'That pin is outside Metro Manila. Move it to where the emergency is.'; $e['outside'] = true; }
    // barangay 172 can only send teams inside its own area, so far away reports get blocked here
    // (this is the real check, the js warning on the form can be skipped)
    elseif (meters_from_brgy($lat, $lng) > SERVICE_RADIUS_M) {
        $e['location'] = 'That location is outside Barangay 172 (about ' . fmt_meters(meters_from_brgy($lat, $lng)) . ' away). KALASAG only covers Barangay 172, Zone 17. For emergencies elsewhere, call the emergency hotlines right away.';
        $e['outside'] = true;
    }

    if (mb_strlen($d['landmark']) > 150) $e['landmark'] = 'Keep the landmark under 150 characters.';
    if ($d['gps_accuracy'] !== '' && filter_var($d['gps_accuracy'], FILTER_VALIDATE_FLOAT) === false) $d['gps_accuracy'] = '';

    if ($guest) {
        if (mb_strlen($d['guest_name']) > 120) $e['guest_name'] = 'Keep your name under 120 characters.';
        if ($d['guest_contact'] !== '' && !preg_match('/^09\d{9}$/', $d['guest_contact'])) $e['guest_contact'] = 'Use an 11-digit mobile number starting with 09, or leave it blank.';
    }

    $photo_err = check_photos($files['photos'] ?? null);
    if ($photo_err) $e['photos'] = $photo_err;

    return [$e, $d];
}

// saves the incident and everything attached to it, returns [incident_id, tracking_code|null]
function save_report($conn, $d, $files, $resident_id, $user_id, $guest) {
    $lat = (float)$d['latitude']; $lng = (float)$d['longitude'];
    $acc = $d['gps_accuracy'] === '' ? null : (float)$d['gps_accuracy'];
    $hh = nearby_vulnerable_household($conn, $lat, $lng);
    $prio = $hh ? 1 : 0;
    $type = (int)$d['type_id'];
    $landmark = $d['landmark'] === '' ? null : $d['landmark'];

    $conn->begin_transaction();
    try {
        $st = $conn->prepare("INSERT INTO incidents (resident_id, reported_by, type_id, household_id, description, latitude, longitude, gps_accuracy, landmark, status, is_priority)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
        $st->bind_param('iiiisdddsi', $resident_id, $user_id, $type, $hh, $d['description'], $lat, $lng, $acc, $landmark, $prio);
        $st->execute();
        $id = $conn->insert_id;

        $code = null;
        if ($guest) {
            $code = new_tracking_code($conn);
            $name = $d['guest_name'] === '' ? null : $d['guest_name'];
            $contact = $d['guest_contact'] === '' ? null : $d['guest_contact'];
            $st = $conn->prepare('INSERT INTO guest_reports (incident_id, tracking_code, guest_name, guest_contact) VALUES (?, ?, ?, ?)');
            $st->bind_param('isss', $id, $code, $name, $contact);
            $st->execute();
        }

        log_status($conn, $id, $user_id, null, 'pending', $guest ? 'Guest report submitted' : 'Report submitted');
        save_photos($conn, $id, $files['photos'] ?? null);
        $conn->commit();
        // tell the reporter we got it + alert the barangay staff (sent after the page loads)
        notify_reporter($conn, $id, 'received');
        notify_admins_new_report($conn, $id);
        return [$id, $code];
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }
}

function render_report_form($types, $d, $e, $guest) {
    $err = fn($k) => isset($e[$k]) ? '<div class="invalid-feedback d-block">' . e($e[$k]) . '</div>' : '';
    ?>
    <?php if ($e): ?>
        <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-circle-fill"></i><div>Some details need fixing before the report can be sent. Check the fields marked in red.</div></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate id="reportForm">
        <?= csrf_field() ?>

        <section class="panel mb-3">
            <div class="panel-head"><h2>1. What is happening?</h2></div>
            <div class="panel-body">
                <div class="type-grid" role="radiogroup" aria-label="Type of emergency">
                    <?php foreach ($types as $t): ?>
                        <div class="type-opt">
                            <input type="radio" name="type_id" id="type<?= (int)$t['type_id'] ?>" value="<?= (int)$t['type_id'] ?>" <?= (string)$d['type_id'] === (string)$t['type_id'] ? 'checked' : '' ?> required>
                            <label for="type<?= (int)$t['type_id'] ?>"><i class="bi <?= e($t['icon_name']) ?>"></i><?= e($t['type_name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?= $err('type_id') ?>
            </div>
        </section>

        <section class="panel mb-3">
            <div class="panel-head flex-wrap">
                <h2>2. Where is it?</h2>
                <button type="button" class="btn btn-sm btn-umber" id="locateBtn"><i class="bi bi-crosshair me-1"></i>Use my location</button>
            </div>
            <div class="panel-body">
                <p class="small text-secondary mb-2" id="pinNote" aria-live="polite">Tap "Use my location", or tap the map where the emergency is. You can drag the pin.</p>
                <div class="pick-map" id="pickMap" role="region" aria-label="Map for placing the report pin"></div>
                <input type="hidden" name="latitude" id="lat" value="<?= e($d['latitude']) ?>">
                <input type="hidden" name="longitude" id="lng" value="<?= e($d['longitude']) ?>">
                <input type="hidden" name="gps_accuracy" id="acc" value="<?= e($d['gps_accuracy']) ?>">
                <?= $err('location') ?>
                <?php if (isset($e['outside'])): ?>
                    <a class="btn btn-sm btn-outline-danger mt-2" href="<?= url('hotlines.php') ?>"><i class="bi bi-telephone-fill me-1"></i>Emergency hotlines</a>
                <?php endif; ?>
                <!-- shown by js when the pin lands outside the barangay -->
                <div class="alert alert-warning small mt-2 mb-0 d-none" id="areaWarn" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="areaWarnText"></span>
                    <a class="alert-link d-inline-block ms-1" href="<?= url('hotlines.php') ?>">See emergency hotlines</a>
                </div>
                <label class="form-label mt-3" for="landmark">Landmark <span class="text-secondary fw-normal">(helps responders find it)</span></label>
                <input class="form-control<?= isset($e['landmark']) ? ' is-invalid' : '' ?>" id="landmark" name="landmark" maxlength="150" placeholder="Example: beside the basketball court" value="<?= e($d['landmark']) ?>">
                <?= $err('landmark') ?>
            </div>
        </section>

        <section class="panel mb-3">
            <div class="panel-head"><h2>3. Tell us more</h2></div>
            <div class="panel-body">
                <label class="form-label" for="description">What happened? Is anyone hurt or trapped?</label>
                <textarea class="form-control<?= isset($e['description']) ? ' is-invalid' : '' ?>" id="description" name="description" rows="4" maxlength="1000" required placeholder="Tagalog, English, or Taglish is fine"><?= e($d['description']) ?></textarea>
                <?= $err('description') ?>

                <label class="form-label mt-3" for="photos">Photos <span class="text-secondary fw-normal">(optional, up to 3)</span></label>
                <input class="form-control<?= isset($e['photos']) ? ' is-invalid' : '' ?>" type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple data-preview="photoPreview" data-max="3" data-max-mb="<?= MAX_PHOTO_MB ?>">
                <?= $err('photos') ?: '<div class="form-text">JPG, PNG, or WEBP, ' . MAX_PHOTO_MB . ' MB each. Only take photos if it is safe to do so.</div>' ?>
                <div class="photo-preview" id="photoPreview"></div>
            </div>
        </section>

        <?php if ($guest): ?>
            <section class="panel mb-3">
                <div class="panel-head"><h2>4. How can we reach you?</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="guest_name">Your name <span class="text-secondary fw-normal">(optional)</span></label>
                            <input class="form-control<?= isset($e['guest_name']) ? ' is-invalid' : '' ?>" id="guest_name" name="guest_name" maxlength="120" autocomplete="name" value="<?= e($d['guest_name']) ?>">
                            <?= $err('guest_name') ?>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="guest_contact">Mobile number <span class="text-secondary fw-normal">(optional)</span></label>
                            <input class="form-control<?= isset($e['guest_contact']) ? ' is-invalid' : '' ?>" id="guest_contact" name="guest_contact" inputmode="numeric" placeholder="09XXXXXXXXX" autocomplete="tel" value="<?= e($d['guest_contact']) ?>">
                            <?= $err('guest_contact') ?: '<div class="form-text">So the barangay can call or text you back.</div>' ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <button class="btn btn-lg w-100 btn-send" type="submit"><i class="bi bi-broadcast me-2"></i>Send report to the barangay</button>
        <p class="small text-secondary text-center mt-2 mb-0">In immediate danger? <a href="<?= url('hotlines.php') ?>">Call the emergency hotlines</a> first.</p>
    </form>
    <?php
}

function report_form_scripts() {
    $lat = BRGY_LAT; $lng = BRGY_LNG; $radius = SERVICE_RADIUS_M;
    return <<<HTML
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const BRGY = [{$lat}, {$lng}], RADIUS = {$radius};
const latEl = document.getElementById('lat'), lngEl = document.getElementById('lng');
const warn = document.getElementById('areaWarn');

// same haversine as the php side, so the warning matches what the server will do
function metersFromBrgy(lat, lng) {
    const r = x => x * Math.PI / 180;
    const dLat = r(lat - BRGY[0]), dLng = r(lng - BRGY[1]);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(r(BRGY[0])) * Math.cos(r(lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * 6371000 * Math.asin(Math.sqrt(h));
}
function outsideArea() {
    if (!latEl.value) return false;
    return metersFromBrgy(+latEl.value, +lngEl.value) > RADIUS;
}
// warn right away when the pin lands outside, so they can call the hotlines instead of waiting
function checkArea() {
    if (outsideArea()) {
        const m = metersFromBrgy(+latEl.value, +lngEl.value);
        const away = m < 1000 ? Math.round(m / 10) * 10 + ' m' : (m / 1000).toFixed(1) + ' km';
        document.getElementById('areaWarnText').textContent = 'This location is outside Barangay 172 (about ' + away + ' away), so it can\'t be sent here. For emergencies elsewhere, call the hotlines right away.';
        warn.classList.remove('d-none');
    } else {
        warn.classList.add('d-none');
    }
}
// listen before the picker starts so a saved pin gets checked too
latEl.addEventListener('change', checkArea);

const map = KALASAG.pinPicker({ map: 'pickMap', lat: 'lat', lng: 'lng', acc: 'acc', locate: 'locateBtn', note: 'pinNote', center: BRGY, autoLocate: true });
// dashed circle so people can see the area we cover
L.circle(BRGY, { radius: RADIUS, color: '#7A4A2A', weight: 2, dashArray: '6 6', fill: true, fillOpacity: 0.04, interactive: false }).addTo(map);

// stop sending without a pin or with a pin outside, the server checks both too
document.getElementById('reportForm').addEventListener('submit', e => {
    if (!latEl.value) {
        e.preventDefault();
        const n = document.getElementById('pinNote');
        n.textContent = 'Set the location first: tap "Use my location" or tap the map.';
        n.classList.add('text-danger');
        n.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else if (outsideArea()) {
        e.preventDefault();
        checkArea();
        warn.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
HTML;
}
