<?php
// resident follows one of their reports: status, messages, add details they forgot
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/partials.php';
$u = require_role('resident');

$id = (int)($_GET['id'] ?? 0);
if (!can_view_incident($conn, $u, $id)) {
    flash('warning', 'That report was not found.');
    redirect('resident/reports.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'message') {
        $msg = trim($_POST['message'] ?? '');
        if ($msg === '' || mb_strlen($msg) > 1000) {
            flash('danger', 'Write a message under 1000 characters.');
        } else {
            add_message($conn, $id, $u['user_id'], 'resident', $msg);
            flash('success', 'Sent to the barangay.');
        }
    } elseif ($action === 'cancel') {
        // only allowed while nobody has picked it up yet
        $inc = load_incident($conn, $id);
        if ($inc['status'] === 'pending') {
            $st = $conn->prepare("UPDATE incidents SET status = 'cancelled' WHERE incident_id = ?");
            $st->bind_param('i', $id);
            $st->execute();
            log_status($conn, $id, $u['user_id'], 'pending', 'cancelled', 'Cancelled by the resident');
            flash('success', 'Report cancelled.');
        } else {
            flash('warning', 'The barangay is already handling this report, so it can no longer be cancelled. Send them a message instead.');
        }
    }
    redirect('resident/report_view.php?id=' . $id);
}

$inc = load_incident($conn, $id);
$timeline = incident_timeline($conn, $id);
$messages = incident_messages($conn, $id);
$photos = incident_photos($conn, $id);

$page_title = $inc['type_name'] . ' #' . $id;
$active = 'myreports';
$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . '/../includes/header.php';
?>
<a class="small fw-semibold d-inline-block mb-3" href="<?= url('resident/reports.php') ?>"><i class="bi bi-arrow-left me-1"></i>My reports</a>

<div class="row g-4">
    <div class="col-lg-7">
        <section class="panel mb-4">
            <div class="panel-head flex-wrap"><h2>Status</h2><?= badge_status($inc['status']) ?></div>
            <div class="panel-body"><?php render_timeline($timeline, $inc['status']); ?></div>
        </section>

        <section class="panel mb-4">
            <div class="panel-head"><h2>Your report</h2><?= badge_priority($inc['is_priority']) ?></div>
            <div class="panel-body">
                <dl class="kv">
                    <dt>Type</dt><dd><?= e($inc['type_name']) ?></dd>
                    <dt>Sent</dt><dd><?= e(date('M j, Y g:i A', strtotime($inc['date_reported']))) ?></dd>
                    <dt>Landmark</dt><dd><?= e($inc['landmark'] ?: 'None given') ?></dd>
                    <dt>Description</dt><dd><?= nl2br(e($inc['description'])) ?></dd>
                </dl>
                <h3 class="fs-6 fw-bold mt-3 mb-1">Photos</h3>
                <?php render_photos($photos); ?>
                <?php if ($inc['status'] === 'pending'): ?>
                    <form method="post" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <button class="btn btn-sm btn-outline-secondary" type="submit" data-confirm="Cancel this report? Only do this if it was sent by mistake or the emergency is over.">Cancel report</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="panel mb-4">
            <div class="panel-head"><h2>Location</h2></div>
            <div class="panel-body"><?php render_mini_map($inc['latitude'], $inc['longitude']); ?></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Messages</h2><span class="hint">With the barangay</span></div>
            <div class="panel-body">
                <?php render_messages($messages, 'resident'); ?>
                <?php if (!in_array($inc['status'], ['resolved', 'cancelled'], true)): ?>
                    <?php render_message_form('message', 'Add details you forgot, or reply to the barangay'); ?>
                <?php else: ?>
                    <p class="small text-secondary mt-3 mb-0">This report is closed. Send a new report if something else happens.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php
$extra_scripts = '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>';
include __DIR__ . '/../includes/footer.php';
