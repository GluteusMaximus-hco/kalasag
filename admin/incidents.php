<?php
// every incident with filters, admin only
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/partials.php';
$u = require_role('admin');

$page_title = 'Incidents';
$active = 'incidents';
$types = incident_types($conn);

// read filters from the url so the page can be bookmarked/shared
$f = [
    'status' => $_GET['status'] ?? 'active',
    'type'   => (int)($_GET['type'] ?? 0),
    'prio'   => isset($_GET['prio']) ? 1 : 0,
    'from'   => $_GET['from'] ?? '',
    'to'     => $_GET['to'] ?? '',
    'q'      => trim($_GET['q'] ?? ''),
];
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;

$where = ['1=1']; $types_str = ''; $args = [];
if ($f['status'] === 'active') $where[] = "i.status IN ('pending','acknowledged','dispatched')";
elseif (in_array($f['status'], STATUSES, true)) { $where[] = 'i.status = ?'; $types_str .= 's'; $args[] = $f['status']; }
else $f['status'] = 'all';
if ($f['type']) { $where[] = 'i.type_id = ?'; $types_str .= 'i'; $args[] = $f['type']; }
if ($f['prio']) $where[] = 'i.is_priority = 1';
if (valid_date($f['from'])) { $where[] = 'i.date_reported >= ?'; $types_str .= 's'; $args[] = $f['from'] . ' 00:00:00'; } else $f['from'] = '';
if (valid_date($f['to']))   { $where[] = 'i.date_reported <= ?'; $types_str .= 's'; $args[] = $f['to'] . ' 23:59:59'; } else $f['to'] = '';
if ($f['q'] !== '') {
    $where[] = "(i.description LIKE ? OR i.landmark LIKE ? OR CONCAT(r.first_name,' ',r.last_name) LIKE ? OR g.guest_name LIKE ? OR g.tracking_code LIKE ? OR i.incident_id = ?)";
    $like = '%' . $f['q'] . '%';
    $types_str .= 'sssssi'; array_push($args, $like, $like, $like, $like, $like, (int)ltrim($f['q'], '#'));   // "12" or "#12" finds report 12
}
$w = implode(' AND ', $where);
$from_sql = "FROM incidents i
    JOIN incident_types t ON t.type_id = i.type_id
    LEFT JOIN incident_urgency u ON u.incident_id = i.incident_id
    LEFT JOIN residents r ON r.resident_id = i.resident_id
    LEFT JOIN guest_reports g ON g.incident_id = i.incident_id
    WHERE $w";

$st = $conn->prepare("SELECT COUNT(*) $from_sql");
if ($args) $st->bind_param($types_str, ...$args);
$st->execute();
$total = (int)$st->get_result()->fetch_row()[0];
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;

$st = $conn->prepare("SELECT i.incident_id, i.status, i.is_priority, i.date_reported, i.landmark, t.type_name, t.icon_name, u.urgency,
        COALESCE(CONCAT(r.first_name, ' ', r.last_name), g.guest_name, 'Guest') AS reporter, (g.guest_report_id IS NOT NULL) AS is_guest
    $from_sql
    ORDER BY (i.status IN ('pending','acknowledged','dispatched')) DESC, i.is_priority DESC,
             FIELD(COALESCE(u.urgency,'none'),'critical','moderate','low','none'), i.date_reported DESC
    LIMIT $per OFFSET $offset");
if ($args) $st->bind_param($types_str, ...$args);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

// keeps the current filters when changing pages
function qs($f, $extra = []) {
    $p = array_filter(array_merge($f, $extra), fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return '?' . http_build_query($p);
}

include __DIR__ . '/../includes/header.php';
?>
<section class="panel mb-4">
    <div class="panel-body pt-3">
        <form class="filter-bar" method="get">
            <div>
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Active (not closed)</option>
                    <option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach (STATUSES as $s): ?><option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="0">All types</option>
                    <?php foreach ($types as $t): ?><option value="<?= (int)$t['type_id'] ?>" <?= $f['type'] === (int)$t['type_id'] ? 'selected' : '' ?>><?= e($t['type_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?= e($f['from']) ?>">
            </div>
            <div>
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?= e($f['to']) ?>">
            </div>
            <div class="flex-grow-1">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" type="search" id="q" name="q" placeholder="Report #, description, landmark, reporter, code" value="<?= e($f['q']) ?>">
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="prio" name="prio" <?= $f['prio'] ? 'checked' : '' ?>>
                <label class="form-check-label small fw-semibold" for="prio">Priority only</label>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-umber" type="submit">Filter</button>
                <a class="btn btn-outline-secondary" href="<?= url('admin/incidents.php') ?>">Reset</a>
            </div>
        </form>
    </div>
</section>

<section class="panel">
    <div class="panel-head flex-wrap">
        <h2>Results <span class="count-pill"><?= $total ?></span></h2>
        <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/export.php' . qs($f)) ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><i class="bi bi-check-circle-fill"></i><?= $f['status'] === 'active' && !$f['q'] && !$f['type'] ? 'No active incidents right now.' : 'No incidents match these filters.' ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table data-table">
                <thead><tr><th>#</th><th>Type</th><th>Reported</th><th>Reporter</th><th>Landmark</th><th>Flags</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): $href = url('admin/incident.php?id=' . (int)$r['incident_id']); ?>
                        <tr class="clickable" onclick="location.href='<?= $href ?>'">
                            <td class="text-nowrap"><a class="fw-semibold" href="<?= $href ?>"><?= (int)$r['incident_id'] ?></a></td>
                            <td class="text-nowrap"><i class="bi <?= e($r['icon_name']) ?> me-1"></i><?= e($r['type_name']) ?></td>
                            <td class="text-nowrap"><?= e(date('M j, g:i A', strtotime($r['date_reported']))) ?><div class="small text-secondary"><?= e(time_ago($r['date_reported'])) ?></div></td>
                            <td><?= e($r['reporter']) ?><?= $r['is_guest'] ? ' <span class="badge text-bg-light border">guest</span>' : '' ?></td>
                            <td><div class="clip-2" title="<?= e($r['landmark'] ?: '') ?>"><?= e($r['landmark'] ?: '') ?></div></td>
                            <td><?= badge_urgency($r['urgency']) ?> <?= badge_priority($r['is_priority']) ?></td>
                            <td><?= badge_status($r['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="panel-body pt-3" aria-label="Pages">
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= qs($f, ['page' => $p]) ?>"><?= $p ?></a></li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
