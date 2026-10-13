<?php
// all reports the resident has sent
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/partials.php';
$u = require_role('resident');

$page_title = 'My reports';
$active = 'myreports';
$filter = $_GET['show'] ?? 'all';
if (!in_array($filter, ['all', 'open', 'closed'], true)) $filter = 'all';

$rid = my_resident_id($conn, $u['user_id']);
$where = ['open' => "AND i.status IN ('pending','acknowledged','dispatched')", 'closed' => "AND i.status IN ('resolved','cancelled')", 'all' => ''][$filter];
$st = $conn->prepare("SELECT i.incident_id, i.status, i.date_reported, i.landmark, i.description, i.is_priority, t.type_name, t.icon_name,
            (SELECT COUNT(*) FROM incident_messages m WHERE m.incident_id = i.incident_id AND m.sender_type <> 'resident') AS replies
        FROM incidents i JOIN incident_types t ON t.type_id = i.type_id
        WHERE i.resident_id = ? $where ORDER BY i.date_reported DESC");
$st->bind_param('i', $rid);
$st->execute();
$reports = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$steps = ['pending' => 1, 'acknowledged' => 2, 'dispatched' => 3, 'resolved' => 4, 'cancelled' => 0];
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <ul class="nav nav-pills small">
        <?php foreach (['all' => 'All', 'open' => 'Open', 'closed' => 'Closed'] as $k => $lbl): ?>
            <li class="nav-item"><a class="nav-link <?= $filter === $k ? 'active' : '' ?>" href="?show=<?= $k ?>"><?= $lbl ?></a></li>
        <?php endforeach; ?>
    </ul>
    <a class="btn btn-send" href="<?= url('resident/report.php') ?>"><i class="bi bi-broadcast me-1"></i>Report an emergency</a>
</div>

<section class="panel">
    <?php if (!$reports): ?>
        <div class="empty"><i class="bi bi-inbox" style="color:var(--muted)"></i><?= $filter === 'all' ? 'You haven\'t reported anything yet.' : 'Nothing here.' ?></div>
    <?php else: ?>
        <ul class="queue">
            <?php foreach ($reports as $r): ?>
                <li>
                    <a class="q-row" href="<?= url('resident/report_view.php?id=' . (int)$r['incident_id']) ?>">
                        <div class="q-icon" style="background:var(--umber-2)"><i class="bi <?= e($r['icon_name']) ?>"></i></div>
                        <div class="min-w-0">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="q-title"><?= e($r['type_name']) ?> #<?= (int)$r['incident_id'] ?></span>
                                <?= badge_status($r['status']) ?>
                            </div>
                            <div class="q-desc"><?= e($r['description']) ?></div>
                            <div class="q-meta">
                                <?= badge_priority($r['is_priority']) ?>
                                <span><?= e($r['landmark'] ?: 'No landmark') ?></span><span>&middot;</span><span><?= e(time_ago($r['date_reported'])) ?></span>
                                <?php if ($r['replies']): ?><span>&middot;</span><span><i class="bi bi-chat-dots-fill"></i> <?= (int)$r['replies'] ?></span><?php endif; ?>
                            </div>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
