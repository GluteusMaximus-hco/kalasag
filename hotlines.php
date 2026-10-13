<?php
// emergency hotlines, open to everyone (even if not logged in) since its for emergencies
require_once __DIR__ . '/includes/auth.php';

$hotlines = require __DIR__ . '/config/hotlines.php';
$u = current_user();

// one block of html used by both layouts
function hotline_list($hotlines) {
    if (!$hotlines) { ?>
        <div class="panel">
            <div class="empty"><i class="bi bi-telephone" style="color:var(--muted)"></i>The barangay hasn't added its hotline numbers yet.</div>
        </div>
    <?php return; } ?>
    <div class="hotline-list">
        <?php foreach ($hotlines as $h): ?>
            <?php $num = preg_replace('/[^0-9+]/', '', $h['number'] ?? ''); ?>
            <a class="hotline-card" href="tel:<?= e($num) ?>">
                <span class="hl-icon"><i class="bi bi-telephone-fill"></i></span>
                <span>
                    <span class="hl-name d-block"><?= e($h['name'] ?? '') ?></span>
                    <span class="hl-num d-block"><?= e($h['number'] ?? '') ?></span>
                    <?php if (!empty($h['note'])): ?><span class="hl-note d-block"><?= e($h['note']) ?></span><?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php }

if ($u) {
    // logged in: normal app layout with sidebar
    $page_title = 'Emergency hotlines';
    $active = 'hotlines';
    include __DIR__ . '/includes/header.php';
    echo '<p class="text-secondary mb-3">Tap a number to call. For life-threatening emergencies, call right away, then send a report so the barangay can track it.</p>';
    hotline_list($hotlines);
    include __DIR__ . '/includes/footer.php';
} else {
    // not logged in: same look as the login page
    require_once __DIR__ . '/includes/auth_layout.php';
    auth_top('Emergency hotlines');
    echo '<div class="wide-wrap">';
    echo '<h2>Emergency hotlines</h2>';
    echo '<p class="lead-sm">Tap a number to call. For life-threatening emergencies, call right away.</p>';
    hotline_list($hotlines);
    echo '<p class="text-center mt-4 mb-0 small"><a href="' . url('login.php') . '" class="fw-semibold">Back to log in</a></p>';
    echo '</div>';
    auth_bottom();
}
