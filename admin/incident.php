<?php
// one incident: everything the admin needs to handle it
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/partials.php';
require_once __DIR__ . '/../includes/notify.php';
$u = require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$inc = load_incident($conn, $id);
if (!$inc) {
    flash('warning', 'That incident was not found.');
    redirect('admin/incidents.php');
}

$st = $conn->prepare('SELECT admin_id FROM admins WHERE user_id = ?');
$st->bind_param('i', $u['user_id']);
$st->execute();
$admin_id = (int)($st->get_result()->fetch_assoc()['admin_id'] ?? 0) ?: null;

// frees responders whose job here is done, unless they still have other open jobs
function free_responders($conn, $incident_id) {
    $conn->query("UPDATE incident_responses SET cleared_at = COALESCE(cleared_at, NOW()) WHERE incident_id = " . (int)$incident_id);
    $conn->query("UPDATE responders rp SET availability = 'available'
                  WHERE availability = 'deployed' AND NOT EXISTS (
                    SELECT 1 FROM incident_responses r WHERE r.responder_id = rp.responder_id AND r.cleared_at IS NULL)");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $old = $inc['status'];
    try {
        if ($action === 'status') {
            $new = $_POST['status'] ?? '';
            $remarks = trim($_POST['remarks'] ?? '');
            // dispatched only happens through the dispatch panel so a team is always attached
            // picking the current status = just post a remark (status stays the same)
            if ($new !== $old && !in_array($new, ['pending', 'acknowledged', 'resolved', 'cancelled'], true)) {
                flash('warning', 'Pick a status.');
            } elseif (mb_strlen($remarks) > 500) {
                flash('danger', 'Keep the remarks under 500 characters.');
            } elseif ($new === $old) {
                if ($remarks === '') {
                    flash('warning', 'Write a remark, or pick a new status.');
                } else {
                    log_status($conn, $id, $u['user_id'], $old, $old, $remarks);
                    notify_reporter($conn, $id, 'update', ['remark' => $remarks]);
                    flash('success', 'Remark added.');
                }
            } else {
                $conn->begin_transaction();
                $resolved = $new === 'resolved' ? date('Y-m-d H:i:s') : null;
                $st = $conn->prepare('UPDATE incidents SET status = ?, date_resolved = ? WHERE incident_id = ?');
                $st->bind_param('ssi', $new, $resolved, $id);
                $st->execute();
                log_status($conn, $id, $u['user_id'], $old, $new, $remarks === '' ? null : $remarks);
                // closing it, or pulling it back from dispatched, sends the teams home
                if (in_array($new, ['resolved', 'cancelled'], true) || $old === 'dispatched') free_responders($conn, $id);
                $conn->commit();
                // text/email the reporter (back to pending only notifies if the admin wrote why)
                if ($new !== 'pending') notify_reporter($conn, $id, $new, ['remark' => $remarks]);
                elseif ($remarks !== '') notify_reporter($conn, $id, 'update', ['remark' => $remarks]);
                flash('success', 'Status changed to ' . status_label($new) . '.');
            }
        } elseif ($action === 'dispatch') {
            $rid = (int)($_POST['responder_id'] ?? 0);
            $st = $conn->prepare("SELECT rp.team_name FROM responders rp JOIN users us ON us.user_id = rp.user_id WHERE rp.responder_id = ? AND us.is_active = 1");
            $st->bind_param('i', $rid);
            $st->execute();
            $team = $st->get_result()->fetch_assoc();
            if (!$team) {
                flash('danger', 'That responder team was not found.');
            } elseif (in_array($old, ['resolved', 'cancelled'], true)) {
                flash('warning', 'Reopen the incident before dispatching a team.');
            } elseif (($chk = $conn->prepare('SELECT 1 FROM incident_responses WHERE incident_id = ? AND responder_id = ? AND cleared_at IS NULL'))
                      && $chk->bind_param('ii', $id, $rid) && $chk->execute() && $chk->get_result()->num_rows) {
                // two admin tabs open, or a double click
                flash('warning', $team['team_name'] . ' is already on this incident.');
            } else {
                $conn->begin_transaction();
                $st = $conn->prepare('INSERT INTO incident_responses (incident_id, responder_id, assigned_by) VALUES (?, ?, ?)');
                $st->bind_param('iii', $id, $rid, $admin_id);
                $st->execute();
                $st = $conn->prepare("UPDATE responders SET availability = 'deployed' WHERE responder_id = ?");
                $st->bind_param('i', $rid);
                $st->execute();
                if ($old !== 'dispatched') {
                    $conn->query("UPDATE incidents SET status = 'dispatched' WHERE incident_id = $id");
                    log_status($conn, $id, $u['user_id'], $old, 'dispatched', $team['team_name'] . ' dispatched');
                }
                $conn->commit();
                notify_reporter($conn, $id, 'dispatched', ['team' => $team['team_name']]);
                notify_team_dispatched($conn, $id, $rid);
                flash('success', $team['team_name'] . ' dispatched.');
            }
        } elseif ($action === 'urgency') {
            $urg = $_POST['urgency'] ?? '';
            if ($urg === 'none') {
                $conn->query("DELETE FROM incident_urgency WHERE incident_id = $id");
                flash('success', 'Urgency cleared.');
            } elseif (in_array($urg, URGENCIES, true)) {
                // manual tag by the admin, overridden = 1 so the ML (if added later) won't replace it
                $st = $conn->prepare("INSERT INTO incident_urgency (incident_id, urgency, urgency_confidence, urgency_overridden) VALUES (?, ?, NULL, 1)
                                      ON DUPLICATE KEY UPDATE urgency = VALUES(urgency), urgency_overridden = 1, classified_at = NOW()");
                $st->bind_param('is', $id, $urg);
                $st->execute();
                flash('success', 'Urgency set to ' . ucfirst($urg) . '.');
            }
        } elseif ($action === 'priority') {
            $p = $inc['is_priority'] ? 0 : 1;
            $conn->query("UPDATE incidents SET is_priority = $p WHERE incident_id = $id");
            flash('success', $p ? 'Marked as priority.' : 'Priority flag removed.');
        } elseif ($action === 'message') {
            $msg = trim($_POST['message'] ?? '');
            if ($msg === '' || mb_strlen($msg) > 1000) flash('danger', 'Write a message under 1000 characters.');
            else {
                add_message($conn, $id, $u['user_id'], 'admin', $msg);
                notify_reporter($conn, $id, 'message', ['message' => $msg, 'from' => 'Barangay 172']);
                flash('success', 'Message sent.');
            }
        }
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $e2) {}   // undo half-done changes
        error_log('incident action: ' . $ex->getMessage());
        flash('danger', 'That change could not be saved. Try again.');
    }
    redirect('admin/incident.php?id=' . $id);
}

$timeline = incident_timeline($conn, $id);
$messages = incident_messages($conn, $id);
$photos = incident_photos($conn, $id);

$st = $conn->prepare("SELECT r.*, rp.team_name, rp.contact_no FROM incident_responses r JOIN responders rp ON rp.responder_id = r.responder_id
                      WHERE r.incident_id = ? ORDER BY r.dispatched_at");
$st->bind_param('i', $id);
$st->execute();
$responses = $st->get_result()->fetch_all(MYSQLI_ASSOC);
// teams still working this incident, cleared ones can be dispatched again if it gets reopened
$on_scene = array_column(array_filter($responses, fn($r) => $r['cleared_at'] === null), 'responder_id');

// teams that handle this type first, then available ones first
$st = $conn->prepare("SELECT rp.responder_id, rp.team_name, rp.availability,
            MAX(c.type_id = ?) AS handles,
            GROUP_CONCAT(t.type_name ORDER BY t.type_name SEPARATOR ', ') AS types
        FROM responders rp
        JOIN users us ON us.user_id = rp.user_id AND us.is_active = 1
        LEFT JOIN responder_capabilities c ON c.responder_id = rp.responder_id
        LEFT JOIN incident_types t ON t.type_id = c.type_id
        GROUP BY rp.responder_id
        ORDER BY handles DESC, FIELD(rp.availability, 'available', 'deployed', 'off_duty'), rp.team_name");
$st->bind_param('i', $inc['type_id']);
$st->execute();
$teams = $st->get_result()->fetch_all(MYSQLI_ASSOC);
// the ones that can still be sent (not already working this incident)
$can_send = array_filter($teams, fn($t) => !in_array($t['responder_id'], $on_scene));

$closed = in_array($inc['status'], ['resolved', 'cancelled'], true);
$page_title = $inc['type_name'] . ' #' . $id;
$active = 'incidents';
$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a class="small fw-semibold" href="<?= url('admin/incidents.php') ?>"><i class="bi bi-arrow-left me-1"></i>All incidents</a>
    <div class="d-flex gap-2 align-items-center"><?= badge_urgency($inc['urgency']) ?> <?= badge_priority($inc['is_priority']) ?> <?= badge_status($inc['status']) ?></div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <section class="panel mb-4">
            <div class="panel-head"><h2>Report</h2><span class="hint"><?= e(time_ago($inc['date_reported'])) ?></span></div>
            <div class="panel-body">
                <dl class="kv">
                    <dt>Reporter</dt>
                    <dd><?= e($inc['reporter_name'] ?: 'Guest (no name)') ?><?= $inc['is_guest'] ? ' <span class="badge text-bg-light border">guest ' . e($inc['tracking_code']) . '</span>' : '' ?></dd>
                    <dt>Contact</dt>
                    <dd><?= $inc['reporter_contact'] ? '<a href="tel:' . e($inc['reporter_contact']) . '">' . e($inc['reporter_contact']) . '</a>' : 'None given' ?></dd>
                    <dt>Reported</dt><dd><?= e(date('M j, Y g:i A', strtotime($inc['date_reported']))) ?></dd>
                    <dt>Landmark</dt><dd><?= e($inc['landmark'] ?: 'None given') ?></dd>
                    <dt>GPS accuracy</dt><dd><?= $inc['gps_accuracy'] !== null ? 'About ' . (int)$inc['gps_accuracy'] . ' m' : 'Pinned by hand' ?></dd>
                    <dt>Description</dt><dd><?= nl2br(e($inc['description'])) ?></dd>
                    <?php if ($inc['household_id']): ?>
                        <dt>Near household</dt>
                        <dd><?= e($inc['hh_address']) ?> <span class="prio-flag"><?= e(implode(', ', vuln_flags($inc))) ?></span></dd>
                    <?php endif; ?>
                </dl>
                <h3 class="fs-6 fw-bold mt-3 mb-1">Photos</h3>
                <?php render_photos($photos); ?>
            </div>
        </section>

        <section class="panel mb-4">
            <div class="panel-head"><h2>Location</h2>
                <a class="hint fw-semibold" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?= e($inc['latitude']) ?>,<?= e($inc['longitude']) ?>"><i class="bi bi-sign-turn-right-fill me-1"></i>Directions</a>
            </div>
            <div class="panel-body"><?php render_mini_map($inc['latitude'], $inc['longitude']); ?></div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Timeline</h2></div>
            <div class="panel-body"><?php render_timeline($timeline, $inc['status']); ?></div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="panel mb-4">
            <div class="panel-head"><h2>Update status</h2></div>
            <div class="panel-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <label class="form-label" for="newStatus">Status</label>
                    <select class="form-select mb-2" id="newStatus" name="status">
                        <option value="<?= e($inc['status']) ?>" selected>Keep as <?= e(status_label($inc['status'])) ?> (just add a remark)</option>
                        <?php foreach (['acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', 'cancelled' => 'Cancelled (false or duplicate report)', 'pending' => 'Back to pending'] as $k => $lbl): ?>
                            <?php if ($k !== $inc['status']): ?><option value="<?= $k ?>"><?= e($lbl) ?></option><?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($inc['status'] === 'dispatched'): ?><div class="form-text mt-0 mb-2">Moving it back to acknowledged or pending calls the teams back.</div><?php endif; ?>
                    <label class="form-label" for="remarks">Remarks <span class="text-secondary fw-normal">(the reporter sees this and gets it by text)</span></label>
                    <input class="form-control mb-3" id="remarks" name="remarks" maxlength="500" placeholder="Example: Team checked, water is going down">
                    <button class="btn btn-umber w-100" type="submit">Save status</button>
                </form>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <form method="post" class="d-flex gap-2 flex-grow-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="urgency">
                        <label class="visually-hidden" for="urgency">Urgency</label>
                        <select class="form-select form-select-sm" id="urgency" name="urgency">
                            <option value="none">No urgency tag</option>
                            <?php foreach (URGENCIES as $g): ?><option value="<?= $g ?>" <?= $inc['urgency'] === $g ? 'selected' : '' ?>><?= ucfirst($g) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary text-nowrap" type="submit">Set urgency</button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="priority">
                        <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $inc['is_priority'] ? 'Remove priority' : 'Mark priority' ?></button>
                    </form>
                </div>
            </div>
        </section>

        <section class="panel mb-4">
            <div class="panel-head"><h2>Responders</h2><span class="hint">Teams for <?= e($inc['type_name']) ?> first</span></div>
            <div class="panel-body">
                <?php if ($responses): ?>
                    <?php foreach ($responses as $r): ?>
                        <div class="resp-row">
                            <div>
                                <div class="fw-semibold"><?= e($r['team_name']) ?></div>
                                <div class="small text-secondary">
                                    Sent <?= e(date(date('Y-m-d', strtotime($r['dispatched_at'])) === date('Y-m-d') ? 'g:i A' : 'M j, g:i A', strtotime($r['dispatched_at']))) ?>
                                    &middot; <?= $r['arrived_at'] ? 'arrived ' . e(date('g:i A', strtotime($r['arrived_at']))) : 'not yet arrived' ?>
                                    <?= $r['cleared_at'] ? '&middot; cleared ' . e(date('g:i A', strtotime($r['cleared_at']))) : '' ?>
                                </div>
                                <?php if ($r['action_taken']): ?><div class="small mt-1"><?= e($r['action_taken']) ?></div><?php endif; ?>
                            </div>
                            <?php if ($r['contact_no']): ?><a class="btn btn-sm btn-outline-secondary" href="tel:<?= e($r['contact_no']) ?>" aria-label="Call <?= e($r['team_name']) ?>"><i class="bi bi-telephone-fill"></i></a><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <hr>
                <?php endif; ?>

                <?php if ($closed): ?>
                    <p class="small text-secondary mb-0">This incident is closed. Set it back to pending or acknowledged to dispatch again.</p>
                <?php elseif (!$teams): ?>
                    <p class="small text-secondary mb-0">No responder teams yet. <a href="<?= url('admin/responders.php') ?>">Add a team</a>.</p>
                <?php elseif (!$can_send): ?>
                    <p class="small text-secondary mb-0">Every active team is already on this incident.</p>
                <?php else: ?>
                    <?php foreach ($can_send as $t): ?>
                        <form method="post" class="resp-row">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="dispatch">
                            <input type="hidden" name="responder_id" value="<?= (int)$t['responder_id'] ?>">
                            <div>
                                <div class="fw-semibold"><?= e($t['team_name']) ?> <?= $t['handles'] ? '<i class="bi bi-check-circle-fill text-success" title="Handles this type"></i>' : '' ?></div>
                                <div class="small text-secondary"><?= e($t['types'] ?: 'No incident types set') ?> &middot; <span class="avail avail-<?= e($t['availability']) ?>"><?= e(ucfirst(str_replace('_', ' ', $t['availability']))) ?></span></div>
                            </div>
                            <button class="btn btn-sm <?= $t['availability'] === 'available' ? 'btn-sienna' : 'btn-outline-secondary' ?>" type="submit"
                                <?= $t['availability'] !== 'available' ? 'data-confirm="' . e($t['team_name']) . ' is ' . e(str_replace('_', ' ', $t['availability'])) . '. Dispatch them anyway?"' : '' ?>>Dispatch</button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Messages</h2><span class="hint">With the reporter</span></div>
            <div class="panel-body">
                <?php render_messages($messages, 'admin'); ?>
                <?php render_message_form('message', 'Reply to the reporter'); ?>
            </div>
        </section>
    </div>
</div>
<?php
$extra_scripts = '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>';
include __DIR__ . '/../includes/footer.php';
