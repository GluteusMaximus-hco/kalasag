<?php
// one job for a responder team: where to go, then mark arrived and cleared
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/partials.php';
$u = require_role('responder');

$rid = (int)($_GET['id'] ?? 0);
// only this team's own jobs
$st = $conn->prepare('SELECT r.*, rp.responder_id AS team_id, rp.team_name FROM incident_responses r
                      JOIN responders rp ON rp.responder_id = r.responder_id WHERE r.response_id = ? AND rp.user_id = ?');
$st->bind_param('ii', $rid, $u['user_id']);
$st->execute();
$job = $st->get_result()->fetch_assoc();
if (!$job) {
    flash('warning', 'That assignment was not found.');
    redirect('responder/home.php');
}
$iid = (int)$job['incident_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    // a job the barangay already closed cant be arrived at or messaged anymore
    if ($action === 'arrived' && !$job['arrived_at'] && !$job['cleared_at']) {
        $conn->query("UPDATE incident_responses SET arrived_at = NOW() WHERE response_id = $rid");
        add_message($conn, $iid, $u['user_id'], 'responder', $job['team_name'] . ' has arrived at the location.');
        flash('success', 'Marked as arrived. The barangay and the reporter can see it.');
    } elseif ($action === 'cleared' && !$job['cleared_at']) {
        $notes = trim($_POST['action_taken'] ?? '');
        if (mb_strlen($notes) < 5 || mb_strlen($notes) > 1000) {
            flash('danger', 'Write what the team did (5 to 1000 characters) before clearing.');
            redirect('responder/assignment.php?id=' . $rid);
        }
        $conn->begin_transaction();
        $st = $conn->prepare('UPDATE incident_responses SET cleared_at = NOW(), arrived_at = COALESCE(arrived_at, NOW()), action_taken = ? WHERE response_id = ?');
        $st->bind_param('si', $notes, $rid);
        $st->execute();
        // team is free again if this was their last open job
        // only if they were deployed, an off duty team stays off duty
        $conn->query("UPDATE responders SET availability = 'available' WHERE responder_id = " . (int)$job['team_id'] . "
                      AND availability = 'deployed' AND NOT EXISTS (SELECT 1 FROM incident_responses WHERE responder_id = " . (int)$job['team_id'] . " AND cleared_at IS NULL)");
        $conn->commit();
        flash('success', 'Cleared. The barangay will close the report once everything is done.');
        redirect('responder/home.php');
    } elseif ($action === 'message' && !$job['cleared_at']) {
        $msg = trim($_POST['message'] ?? '');
        if ($msg !== '' && mb_strlen($msg) <= 1000) { add_message($conn, $iid, $u['user_id'], 'responder', $msg); flash('success', 'Message sent.'); }
    }
    redirect('responder/assignment.php?id=' . $rid);
}

$inc = load_incident($conn, $iid);
$page_title = $inc['type_name'] . ' #' . $iid;
$active = 'home';
$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . '/../includes/header.php';
?>
<a class="small fw-semibold d-inline-block mb-3" href="<?= url('responder/home.php') ?>"><i class="bi bi-arrow-left me-1"></i>My assignments</a>

<div class="row g-4">
    <div class="col-lg-7">
        <section class="panel mb-4">
            <div class="panel-head flex-wrap"><h2>Go here</h2>
                <a class="btn btn-sm btn-sienna" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?= e($inc['latitude']) ?>,<?= e($inc['longitude']) ?>"><i class="bi bi-sign-turn-right-fill me-1"></i>Directions</a>
            </div>
            <div class="panel-body">
                <?php render_mini_map($inc['latitude'], $inc['longitude']); ?>
                <dl class="kv mt-3">
                    <dt>Landmark</dt><dd><?= e($inc['landmark'] ?: 'None given') ?></dd>
                    <dt>Reporter</dt><dd><?= e($inc['reporter_name'] ?: 'Guest') ?><?= $inc['reporter_contact'] ? ' &middot; <a href="tel:' . e($inc['reporter_contact']) . '">' . e($inc['reporter_contact']) . '</a>' : '' ?></dd>
                    <dt>Reported</dt><dd><?= e(date('M j, g:i A', strtotime($inc['date_reported']))) ?> (<?= e(time_ago($inc['date_reported'])) ?>)</dd>
                    <dt>What happened</dt><dd><?= nl2br(e($inc['description'])) ?></dd>
                    <?php if ($inc['household_id']): ?>
                        <dt>Watch out for</dt><dd><span class="prio-flag"><i class="bi bi-shield-fill-exclamation"></i><?= e(implode(', ', vuln_flags($inc))) ?></span> nearby</dd>
                    <?php endif; ?>
                </dl>
                <h3 class="fs-6 fw-bold mt-3 mb-1">Photos</h3>
                <?php render_photos(incident_photos($conn, $iid)); ?>
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="panel mb-4">
            <div class="panel-head"><h2>Your progress</h2><?= badge_priority($inc['is_priority']) ?></div>
            <div class="panel-body">
                <ol class="steps" style="grid-template-columns: repeat(3, 1fr)">
                    <?php foreach ([['Dispatched', $job['dispatched_at']], ['Arrived', $job['arrived_at']], ['Cleared', $job['cleared_at']]] as [$lbl, $t]): ?>
                        <li class="<?= $t ? 'done' : '' ?>"><span class="dot"><?= $t ? '<i class="bi bi-check-lg"></i>' : '' ?></span>
                            <span class="label"><?= $lbl ?></span><span class="when"><?= $t ? e(date('g:i A', strtotime($t))) : '' ?></span></li>
                    <?php endforeach; ?>
                </ol>
                <?php if (!$job['arrived_at'] && !$job['cleared_at']): ?>
                    <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="arrived">
                        <button class="btn btn-sienna btn-lg w-100" type="submit"><i class="bi bi-geo-alt-fill me-1"></i>We've arrived</button></form>
                <?php endif; ?>
                <?php if (!$job['cleared_at']): ?>
                    <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="cleared">
                        <label class="form-label" for="action_taken">What did the team do?</label>
                        <textarea class="form-control mb-2" id="action_taken" name="action_taken" rows="3" maxlength="1000" placeholder="Example: Moved 2 families to Malibay Plaza, no injuries" required></textarea>
                        <button class="btn btn-umber w-100" type="submit" data-confirm="Mark this job as cleared?">Mark as cleared</button></form>
                <?php else: ?>
                    <?php if ($job['action_taken'] !== null): ?>
                        <p class="small mt-3 mb-0"><strong>Action taken:</strong> <?= e($job['action_taken']) ?></p>
                    <?php else: ?>
                        <p class="small text-secondary mt-3 mb-0">The barangay closed this incident before the team marked it cleared.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Messages</h2><span class="hint">Barangay and reporter</span></div>
            <div class="panel-body">
                <?php render_messages(incident_messages($conn, $iid), 'responder'); ?>
                <?php if (!$job['cleared_at']) render_message_form('message', 'Update the barangay'); ?>
            </div>
        </section>
    </div>
</div>
<?php
$extra_scripts = '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>';
include __DIR__ . '/../includes/footer.php';
