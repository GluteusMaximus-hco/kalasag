<?php
require_once __DIR__ . '/../includes/auth.php';
$u = require_role('resident');

$page_title = 'Home';
$active = 'home';

try {
    // find the resident row linked to this login
    $st = $conn->prepare('SELECT resident_id, first_name, household_id, is_verified FROM residents WHERE user_id = ?');
    $st->bind_param('i', $u['user_id']);
    $st->execute();
    $me = $st->get_result()->fetch_assoc();

    $reports = [];
    if ($me) {
        $st = $conn->prepare("SELECT i.incident_id, i.status, i.date_reported, i.landmark, t.type_name, t.icon_name
                FROM incidents i JOIN incident_types t ON t.type_id = i.type_id
                WHERE i.resident_id = ? ORDER BY i.date_reported DESC LIMIT 5");
        $st->bind_param('i', $me['resident_id']);
        $st->execute();
        $reports = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    $news = $conn->query("SELECT title, body, category, date_posted FROM announcements WHERE is_active = 1 ORDER BY date_posted DESC LIMIT 3")->fetch_all(MYSQLI_ASSOC);
    // nearest 3 centers to the household pin (haversine in meters), or by status if no pin yet
    $hh = null;
    if ($me && $me['household_id']) {
        $st = $conn->prepare('SELECT latitude, longitude FROM households WHERE household_id = ? AND latitude IS NOT NULL');
        $st->bind_param('i', $me['household_id']);
        $st->execute();
        $hh = $st->get_result()->fetch_assoc();
    }
    if ($hh) {
        $st = $conn->prepare("SELECT center_name, capacity, status,
                    6371000 * 2 * ASIN(SQRT(POW(SIN(RADIANS(latitude - ?) / 2), 2)
                      + COS(RADIANS(?)) * COS(RADIANS(latitude)) * POW(SIN(RADIANS(longitude - ?) / 2), 2))) AS meters
                FROM evacuation_centers WHERE latitude IS NOT NULL
                ORDER BY meters LIMIT 3");
        $st->bind_param('ddd', $hh['latitude'], $hh['latitude'], $hh['longitude']);
        $st->execute();
        $centers = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $centers = $conn->query("SELECT center_name, capacity, status, NULL AS meters FROM evacuation_centers
                ORDER BY FIELD(status,'open','full','closed'), center_name LIMIT 3")->fetch_all(MYSQLI_ASSOC);
    }
} catch (mysqli_sql_exception $ex) {
    error_log('resident home: ' . $ex->getMessage());
    http_response_code(500);
    exit('This page could not load. Try again in a minute.');
}

// how far along each report is, for the little progress bar
$steps = ['pending' => 1, 'acknowledged' => 2, 'dispatched' => 3, 'resolved' => 4, 'cancelled' => 0];

include __DIR__ . '/../includes/header.php';
?>

<p class="fs-5 mb-3">Hi <?= e($me['first_name'] ?? $u['full_name']) ?>.</p>

<?php if ($me && !$me['household_id']): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start">
        <i class="bi bi-house-exclamation-fill fs-5"></i>
        <div>Your household info isn't set up yet. It helps the barangay prioritize homes with elderly, PWD, pregnant, or bedridden members during emergencies. <a class="fw-semibold" href="<?= url('resident/household.php') ?>">Set it up now</a></div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <a class="report-cta mb-4" href="<?= url('resident/report.php') ?>">
            <i class="bi bi-broadcast"></i>
            <div>
                <div class="big">Report an emergency</div>
                <small>Flood, fire, accident, fallen tree, or medical. Your location is pinned automatically.</small>
            </div>
        </a>

        <section class="panel">
            <div class="panel-head"><h2>My recent reports</h2><a class="hint fw-semibold" href="<?= url('resident/reports.php') ?>">See all</a></div>
            <?php if (!$reports): ?>
                <div class="empty pt-0"><i class="bi bi-inbox" style="color:var(--muted)"></i>You haven't reported anything yet.</div>
            <?php else: ?>
                <ul class="queue">
                    <?php foreach ($reports as $r): ?>
                        <li>
                            <a class="q-row" href="<?= url('resident/report_view.php?id=' . (int)$r['incident_id']) ?>">
                                <div class="q-icon" style="background:var(--umber-2)"><i class="bi <?= e($r['icon_name']) ?>"></i></div>
                                <div class="min-w-0">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="q-title"><?= e($r['type_name']) ?> #<?= (int)$r['incident_id'] ?></span>
                                        <span class="st st-<?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span>
                                    </div>
                                    <div class="q-meta"><span><?= e($r['landmark'] ?: 'No landmark') ?></span><span>&middot;</span><span><?= e(time_ago($r['date_reported'])) ?></span></div>
                                    <div class="timeline-mini" aria-hidden="true">
                                        <?php for ($i = 1; $i <= 4; $i++): ?><span class="<?= $i <= $steps[$r['status']] ? 'on' : '' ?>"></span><?php endfor; ?>
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
        <section class="panel mb-4">
            <div class="panel-head"><h2>Announcements</h2></div>
            <div class="panel-body">
                <?php foreach ($news as $n): ?>
                    <article class="border-top pt-3 mt-3 first-no-border">
                        <div class="d-flex justify-content-between gap-2 small text-secondary mb-1">
                            <span class="text-capitalize"><?= e($n['category']) ?></span>
                            <span><?= e(time_ago($n['date_posted'])) ?></span>
                        </div>
                        <h3 class="fs-5 fw-bold mb-1"><?= e($n['title']) ?></h3>
                        <p class="mb-0 small"><?= nl2br(e($n['body'])) ?></p>
                    </article>
                <?php endforeach; ?>
                <?php if (!$news): ?><p class="text-secondary small mb-0">No announcements right now.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Nearby evacuation centers</h2><a class="hint fw-semibold" href="<?= url('resident/evacuation.php') ?>">See map</a></div>
            <div class="panel-body">
                <?php foreach ($centers as $c): ?>
                    <div class="resp-row">
                        <div>
                            <div class="fw-semibold"><?= e($c['center_name']) ?></div>
                            <div class="text-secondary small">
                                <span class="evac-status <?= e($c['status']) ?>"><?= e(ucfirst($c['status'])) ?></span>
                                &middot; fits about <?= (int)$c['capacity'] ?> people
                            </div>
                        </div>
                        <?php if ($c['meters'] !== null): ?>
                            <span class="dist"><?= $c['meters'] < 1000 ? round($c['meters'] / 10) * 10 . ' m' : number_format($c['meters'] / 1000, 1) . ' km' ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$centers): ?><p class="text-secondary small mb-0">No evacuation centers posted yet.</p><?php endif; ?>
                <?php if ($centers && !$hh): ?><p class="text-secondary small mt-2 mb-0">Set up your household pin to see the nearest centers first.</p><?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
