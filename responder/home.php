<?php
require_once __DIR__ . '/../includes/auth.php';
$u = require_role('responder');

$page_title = 'My assignments';
$active = 'home';

try {
    // responder team linked to this login + their open assignments
    $st = $conn->prepare("SELECT r.response_id, r.dispatched_at, r.arrived_at, r.action_taken,
                i.incident_id, i.description, i.landmark, i.status, i.is_priority, t.type_name, t.icon_name
            FROM incident_responses r
            JOIN responders rp ON rp.responder_id = r.responder_id
            JOIN incidents i ON i.incident_id = r.incident_id
            JOIN incident_types t ON t.type_id = i.type_id
            WHERE rp.user_id = ? AND r.cleared_at IS NULL
            ORDER BY i.is_priority DESC, r.dispatched_at ASC");
    $st->bind_param('i', $u['user_id']);
    $st->execute();
    $jobs = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    // last few jobs this team finished
    $st = $conn->prepare("SELECT r.response_id, r.cleared_at, r.action_taken, i.incident_id, t.type_name, t.icon_name
            FROM incident_responses r JOIN responders rp ON rp.responder_id = r.responder_id
            JOIN incidents i ON i.incident_id = r.incident_id JOIN incident_types t ON t.type_id = i.type_id
            WHERE rp.user_id = ? AND r.cleared_at IS NOT NULL ORDER BY r.cleared_at DESC LIMIT 10");
    $st->bind_param('i', $u['user_id']);
    $st->execute();
    $done = $st->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $ex) {
    error_log('responder home: ' . $ex->getMessage());
    http_response_code(500);
    exit('This page could not load. Try again in a minute.');
}

include __DIR__ . '/../includes/header.php';
?>
<section class="panel">
    <div class="panel-head"><h2>Open assignments</h2><span class="hint"><?= count($jobs) ?> open</span></div>
    <?php if (!$jobs): ?>
        <div class="empty pt-0"><i class="bi bi-check-circle-fill"></i>No open assignments. You're clear.</div>
    <?php else: ?>
        <ul class="queue">
            <?php foreach ($jobs as $j): ?>
                <li>
                    <a class="q-row" href="<?= url('responder/assignment.php?id=' . (int)$j['response_id']) ?>">
                        <div class="q-icon" style="background:var(--st-dispatched)"><i class="bi <?= e($j['icon_name']) ?>"></i></div>
                        <div class="min-w-0">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="q-title"><?= e($j['type_name']) ?> #<?= (int)$j['incident_id'] ?></span>
                                <?php if ($j['is_priority']): ?><span class="prio-flag"><i class="bi bi-shield-fill-exclamation"></i>Priority</span><?php endif; ?>
                            </div>
                            <div class="q-desc"><?= e($j['description']) ?></div>
                            <div class="q-meta">
                                <span><?= e($j['landmark'] ?: 'No landmark') ?></span><span>&middot;</span>
                                <span>Dispatched <?= e(time_ago($j['dispatched_at'])) ?></span><span>&middot;</span>
                                <span><?= $j['arrived_at'] ? 'Arrived ' . e(time_ago($j['arrived_at'])) : 'Not yet arrived' ?></span>
                            </div>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="panel mt-4">
    <div class="panel-head"><h2>Recently cleared</h2></div>
    <?php if (!$done): ?>
        <div class="empty pt-0"><i class="bi bi-clock-history" style="color:var(--muted)"></i>No finished jobs yet.</div>
    <?php else: ?>
        <ul class="queue">
            <?php foreach ($done as $d): ?>
                <li><a class="q-row" href="<?= url('responder/assignment.php?id=' . (int)$d['response_id']) ?>">
                    <div class="q-icon" style="background:var(--st-resolved)"><i class="bi <?= e($d['icon_name']) ?>"></i></div>
                    <div class="min-w-0">
                        <span class="q-title"><?= e($d['type_name']) ?> #<?= (int)$d['incident_id'] ?></span>
                        <div class="q-desc"><?= e($d['action_taken'] ?? 'Closed by the barangay') ?></div>
                        <div class="q-meta"><span>Cleared <?= e(time_ago($d['cleared_at'])) ?></span></div>
                    </div>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
