<?php
// small html blocks reused on resident, guest, admin and responder pages

function badge_status($s) {
    return '<span class="st st-' . e($s) . '">' . e(status_label($s)) . '</span>';
}
function badge_urgency($u) {
    return $u ? '<span class="urg urg-' . e($u) . '">' . e(ucfirst($u)) . '</span>' : '';
}
function badge_priority($is) {
    return $is ? '<span class="prio-flag"><i class="bi bi-shield-fill-exclamation"></i>Priority</span>' : '';
}

// the 4-step progress + every logged change under it
function render_timeline($timeline, $status) {
    $steps = ['pending' => 'Received', 'acknowledged' => 'Acknowledged', 'dispatched' => 'Responders dispatched', 'resolved' => 'Resolved'];
    // first time each status was reached
    $when = [];
    foreach ($timeline as $t) if (!isset($when[$t['new_status']])) $when[$t['new_status']] = $t['changed_at'];
    $order = array_keys($steps);
    $cur = array_search($status, $order, true);
    ?>
    <?php if ($status === 'cancelled'): ?>
        <div class="alert alert-secondary py-2 small mb-3"><i class="bi bi-x-circle me-1"></i>This report was cancelled.</div>
    <?php endif; ?>
    <ol class="steps">
        <?php foreach ($steps as $key => $label):
            $i = array_search($key, $order, true);
            $done = $cur !== false && $i <= $cur; ?>
            <li class="<?= $done ? 'done' : '' ?> <?= $cur === $i ? 'current' : '' ?>">
                <span class="dot"><?= $done ? '<i class="bi bi-check-lg"></i>' : '' ?></span>
                <span class="label"><?= e($label) ?></span>
                <span class="when"><?= isset($when[$key]) ? e(date('M j, g:i A', strtotime($when[$key]))) : '' ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if ($timeline): ?>
        <ul class="activity mt-3">
            <?php foreach (array_reverse($timeline) as $t): ?>
                <li>
                    <time datetime="<?= e($t['changed_at']) ?>"><?= e(time_ago($t['changed_at'])) ?></time>
                    <div><?= badge_status($t['new_status']) ?>
                        <?php if ($t['remarks']): ?><div class="small mt-1"><?= e($t['remarks']) ?></div><?php endif; ?>
                        <div class="text-secondary small"><?= $t['who'] !== 'System' ? 'by ' . e($t['who']) : '' ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif;
}

// chat style thread. $mine = sender_type that shows on the right side
function render_messages($messages, $mine) {
    $labels = ['admin' => 'Barangay', 'resident' => 'Resident', 'responder' => 'Responder', 'guest' => 'Reporter'];
    if (!$messages) {
        echo '<p class="text-secondary small mb-0">No messages yet.</p>';
        return;
    }
    echo '<div class="thread" data-autoscroll>';
    foreach ($messages as $m) {
        $side = $m['sender_type'] === $mine ? 'me' : 'them';
        echo '<div class="bubble ' . $side . '">'
           . '<div class="who">' . e($labels[$m['sender_type']] ?? '') . ($m['sender_type'] !== 'guest' ? ' &middot; ' . e($m['who']) : '') . '</div>'
           . '<div>' . nl2br(e($m['message'])) . '</div>'
           . '<time>' . e(time_ago($m['sent_at'])) . '</time></div>';
    }
    echo '</div>';
}

function render_message_form($action_value = 'message', $placeholder = 'Type a message') { ?>
    <form method="post" class="msg-form mt-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= e($action_value) ?>">
        <label class="visually-hidden" for="msg">Message</label>
        <textarea class="form-control" id="msg" name="message" rows="2" maxlength="1000" placeholder="<?= e($placeholder) ?>" required></textarea>
        <button class="btn btn-umber" type="submit"><i class="bi bi-send-fill"></i><span class="d-none d-sm-inline ms-1">Send</span></button>
    </form>
<?php }

function render_photos($ids) {
    if (!$ids) { echo '<p class="text-secondary small mb-0">No photos attached.</p>'; return; }
    echo '<div class="photo-grid">';
    foreach ($ids as $id) {
        $src = url('photo.php?id=' . (int)$id);
        echo '<a href="' . $src . '" target="_blank" rel="noopener"><img src="' . $src . '" alt="Incident photo" loading="lazy"></a>';
    }
    echo '</div>';
}

// small static map for one incident
function render_mini_map($lat, $lng, $id = 'miniMap') {
    echo '<div class="mini-map" id="' . e($id) . '" data-lat="' . e($lat) . '" data-lng="' . e($lng) . '"></div>';
}
