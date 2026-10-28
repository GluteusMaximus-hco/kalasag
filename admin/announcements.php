<?php
// advisories that residents see on their home page
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
$u = require_role('admin');

$page_title = 'Announcements';
$active = 'announce';
$cats = ['advisory' => 'Advisory', 'weather' => 'Weather', 'evacuation' => 'Evacuation', 'general' => 'General'];
$errors = [];
$edit_id = (int)($_GET['edit'] ?? 0);
$d = ['title' => '', 'body' => '', 'category' => 'advisory', 'is_active' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $aid = (int)($_POST['announcement_id'] ?? 0);

    if ($action === 'toggle') {
        $st = $conn->prepare('UPDATE announcements SET is_active = 1 - is_active WHERE announcement_id = ?');
        $st->bind_param('i', $aid); $st->execute();
        flash('success', 'Announcement updated.');
        redirect('admin/announcements.php');
    }
    if ($action === 'delete') {
        $st = $conn->prepare('DELETE FROM announcements WHERE announcement_id = ?');
        $st->bind_param('i', $aid); $st->execute();
        flash('success', 'Announcement deleted.');
        redirect('admin/announcements.php');
    }
    if ($action === 'save') {
        $d['title'] = trim($_POST['title'] ?? '');
        $d['body'] = trim($_POST['body'] ?? '');
        $d['category'] = $_POST['category'] ?? '';
        $d['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        if ($d['title'] === '' || mb_strlen($d['title']) > 150) $errors['title'] = 'Enter a title (up to 150 characters).';
        if (mb_strlen($d['body']) < 5 || mb_strlen($d['body']) > 3000) $errors['body'] = 'Write the announcement (5 to 3000 characters).';
        if (!isset($cats[$d['category']])) $errors['category'] = 'Pick a category.';
        if (!$errors) {
            if ($aid) {
                $st = $conn->prepare('UPDATE announcements SET title = ?, body = ?, category = ?, is_active = ? WHERE announcement_id = ?');
                $st->bind_param('sssii', $d['title'], $d['body'], $d['category'], $d['is_active'], $aid);
            } else {
                $st = $conn->prepare('INSERT INTO announcements (posted_by, title, body, category, is_active) VALUES (?, ?, ?, ?, ?)');
                $st->bind_param('isssi', $u['user_id'], $d['title'], $d['body'], $d['category'], $d['is_active']);
            }
            $st->execute();
            // only when ticked (default on for new posts), so fixing a typo doesnt text everyone again
            $sent = $d['is_active'] && !empty($_POST['notify']);
            if ($sent) notify_announcement($conn, $d['title'], $d['body'], $d['category']);
            // say what really goes out, depends on what's set up
            $how = $sent ? array_filter([sms_ready() ? 'texted' : '', mail_ready() ? 'emailed' : '']) : [];
            flash('success', ($aid ? 'Announcement updated.' : 'Announcement posted. Residents will see it on their home page.')
                . ($how ? ' Residents are also being ' . implode(' and ', $how) . '.' : ''));
            redirect('admin/announcements.php');
        }
        $edit_id = $aid;
    }
}

if ($edit_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $conn->prepare('SELECT * FROM announcements WHERE announcement_id = ?');
    $st->bind_param('i', $edit_id); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if ($row) $d = $row; else $edit_id = 0;
}

$list = $conn->query("SELECT a.*, COALESCE(us.full_name, 'Removed user') AS poster FROM announcements a LEFT JOIN users us ON us.user_id = a.posted_by
                      ORDER BY a.is_active DESC, a.date_posted DESC")->fetch_all(MYSQLI_ASSOC);
$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-7 order-2 order-xl-1">
        <section class="panel">
            <div class="panel-head"><h2>Posted <span class="count-pill"><?= count($list) ?></span></h2></div>
            <div class="panel-body">
                <?php if (!$list): ?>
                    <div class="empty pt-0"><i class="bi bi-megaphone" style="color:var(--muted)"></i>Nothing posted yet.</div>
                <?php endif; ?>
                <?php foreach ($list as $a): ?>
                    <article class="border-top pt-3 mt-3 first-no-border <?= $a['is_active'] ? '' : 'opacity-50' ?>">
                        <div class="d-flex justify-content-between gap-2 small text-secondary mb-1">
                            <span><?= e($cats[$a['category']] ?? $a['category']) ?><?= $a['is_active'] ? '' : ' &middot; hidden from residents' ?></span>
                            <span><?= e(date('M j, Y g:i A', strtotime($a['date_posted']))) ?> &middot; <?= e($a['poster']) ?></span>
                        </div>
                        <h3 class="fs-5 fw-bold mb-1"><?= e($a['title']) ?></h3>
                        <p class="small mb-2"><?= nl2br(e($a['body'])) ?></p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$a['announcement_id'] ?>">Edit</a>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="announcement_id" value="<?= (int)$a['announcement_id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $a['is_active'] ? 'Hide' : 'Show again' ?></button></form>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="announcement_id" value="<?= (int)$a['announcement_id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Delete this announcement for good?">Delete</button></form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
    <div class="col-xl-5 order-1 order-xl-2">
        <section class="panel">
            <div class="panel-head"><h2><?= $edit_id ? 'Edit announcement' : 'New announcement' ?></h2><?php if ($edit_id): ?><a class="hint" href="<?= url('admin/announcements.php') ?>">Cancel</a><?php endif; ?></div>
            <div class="panel-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="announcement_id" value="<?= $edit_id ?>">
                    <div class="mb-3"><label class="form-label" for="title">Title</label>
                        <input class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" maxlength="150" placeholder="Example: Malibay Plaza is open for evacuees" value="<?= e($d['title']) ?>"><?= $err('title') ?></div>
                    <div class="mb-3"><label class="form-label" for="category">Category</label>
                        <select class="form-select" id="category" name="category">
                            <?php foreach ($cats as $k => $lbl): ?><option value="<?= $k ?>" <?= $d['category'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select><?= $err('category') ?></div>
                    <div class="mb-3"><label class="form-label" for="body">Message</label>
                        <textarea class="form-control<?= isset($errors['body']) ? ' is-invalid' : '' ?>" id="body" name="body" rows="6" maxlength="3000"><?= e($d['body']) ?></textarea><?= $err('body') ?></div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $d['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Show to residents</label>
                    </div>
                    <?php $notify_on = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['notify']) : !$edit_id; ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="notify" name="notify" <?= $notify_on ? 'checked' : '' ?>>
                        <label class="form-check-label" for="notify">Also text and email all residents</label>
                        <div class="form-text mt-0"><?= sms_ready() || mail_ready() ? 'Leave unticked when you\'re only fixing a typo.' : 'SMS and email are not set up yet, so this only posts it on the site.' ?></div>
                    </div>
                    <button class="btn btn-umber w-100" type="submit"><?= $edit_id ? 'Save changes' : 'Post announcement' ?></button>
                </form>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
