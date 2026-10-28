<?php
// guests follow their report using the tracking code
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/incidents.php';
require_once __DIR__ . '/includes/partials.php';
require_once __DIR__ . '/includes/auth_layout.php';

$code = strtoupper(preg_replace('/\s+/', '', $_GET['code'] ?? $_POST['code'] ?? ''));
// "7KK2WM" works too, people forget the KLS- part
if (preg_match('/^[A-Z0-9]{6}$/', $code)) $code = 'KLS-' . $code;
$inc = null;
$error = '';

if ($code !== '') {
    // slow down people guessing codes: 10 wrong tries = wait 5 minutes
    if (($_SESSION['track_fails'] ?? 0) >= 10 && time() < ($_SESSION['track_lock'] ?? 0)) {
        $error = 'Too many wrong codes. Wait a few minutes, then try again.';
    } else {
        $st = $conn->prepare('SELECT incident_id FROM guest_reports WHERE tracking_code = ?');
        $st->bind_param('s', $code);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row) {
            $id = (int)$row['incident_id'];
            $_SESSION['guest_incidents'] = array_values(array_unique(array_merge($_SESSION['guest_incidents'] ?? [], [$id])));
            $_SESSION['track_fails'] = 0;
            $inc = load_incident($conn, $id);
        } else {
            $_SESSION['track_fails'] = ($_SESSION['track_fails'] ?? 0) + 1;
            if ($_SESSION['track_fails'] >= 10) $_SESSION['track_lock'] = time() + 300;
            $error = 'No report matches that code. Check the letters and try again.';
        }
    }
}

if ($inc && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $msg = trim($_POST['message'] ?? '');
    if (in_array($inc['status'], ['resolved', 'cancelled'], true)) {
        flash('warning', 'This report is closed.');
    } elseif ($msg === '' || mb_strlen($msg) > 1000) {
        flash('danger', 'Write a message under 1000 characters.');
    } else {
        add_message($conn, (int)$inc['incident_id'], null, 'guest', $msg);
        flash('success', 'Sent to the barangay.');
    }
    redirect('track.php?code=' . urlencode($code));
}

$extra_head = $inc ? '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">' : '';
auth_top('Track a report', $extra_head, true);
show_flash();
?>
<?php if (!$inc): ?>
    <h2>Track a report</h2>
    <p class="lead-sm">Enter the tracking code you got after sending a guest report.</p>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="get" novalidate>
        <label class="form-label" for="code">Tracking code</label>
        <input class="form-control form-control-lg text-uppercase" id="code" name="code" placeholder="KLS-XXXXXX" value="<?= e($code) ?>" autocomplete="off" required>
        <button class="btn btn-umber btn-lg w-100 mt-3" type="submit">Check status</button>
    </form>
    <p class="text-center mt-4 mb-0 small"><a href="<?= url('guest_report.php') ?>" class="fw-semibold">Send a report</a> &middot; <a href="<?= url('login.php') ?>" class="fw-semibold">Log in</a></p>
<?php else: ?>
    <?php if (isset($_GET['new'])): ?>
        <div class="alert alert-success"><strong>Report sent.</strong> The barangay can see it now. Save this code so you can check for updates:</div>
        <div class="track-code mb-2"><?= e($inc['tracking_code']) ?></div>
        <p class="small text-secondary mb-4">Take a screenshot or write it down. Anyone with this code can see this report.<?= $inc['reporter_contact'] ? ' The code was also texted to ' . e($inc['reporter_contact']) . '.' : '' ?></p>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="mb-0"><?= e($inc['type_name']) ?></h2><?= badge_status($inc['status']) ?>
    </div>
    <p class="small text-secondary">Code <?= e($inc['tracking_code']) ?> &middot; sent <?= e(time_ago($inc['date_reported'])) ?></p>

    <section class="panel mb-3"><div class="panel-body pt-3"><?php render_timeline(incident_timeline($conn, (int)$inc['incident_id']), $inc['status']); ?></div></section>

    <section class="panel mb-3">
        <div class="panel-head"><h2>Details</h2></div>
        <div class="panel-body">
            <dl class="kv">
                <dt>Landmark</dt><dd><?= e($inc['landmark'] ?: 'None given') ?></dd>
                <dt>Description</dt><dd><?= nl2br(e($inc['description'])) ?></dd>
            </dl>
            <div class="mt-3"><?php render_mini_map($inc['latitude'], $inc['longitude']); ?></div>
            <div class="mt-2"><?php render_photos(incident_photos($conn, (int)$inc['incident_id'])); ?></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Messages</h2><span class="hint">With the barangay</span></div>
        <div class="panel-body">
            <?php render_messages(incident_messages($conn, (int)$inc['incident_id']), 'guest'); ?>
            <?php if (!in_array($inc['status'], ['resolved', 'cancelled'], true)): ?>
                <form method="post" class="msg-form mt-3" action="<?= url('track.php?code=' . urlencode($code)) ?>">
                    <?= csrf_field() ?>
                    <label class="visually-hidden" for="msg">Message</label>
                    <textarea class="form-control" id="msg" name="message" rows="2" maxlength="1000" placeholder="Add details or reply to the barangay" required></textarea>
                    <button class="btn btn-umber" type="submit"><i class="bi bi-send-fill"></i></button>
                </form>
            <?php endif; ?>
        </div>
    </section>
    <p class="text-center mt-4 mb-0 small"><a href="<?= url('track.php') ?>" class="fw-semibold">Track another code</a> &middot; <a href="<?= url('login.php') ?>" class="fw-semibold">Log in</a></p>
<?php endif; ?>
<?php auth_bottom($inc ? '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>' : ''); ?>
