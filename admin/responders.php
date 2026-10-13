<?php
// responder teams: their login, what incidents they handle, and if they're free
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
$u = require_role('admin');

$page_title = 'Responders';
$active = 'responders';
$types = incident_types($conn);
$type_ids = array_map('intval', array_column($types, 'type_id'));
$errors = [];
$edit_id = (int)($_GET['edit'] ?? 0);

$blank = ['team_name' => '', 'full_name' => '', 'username' => '', 'contact_no' => '', 'availability' => 'available', 'caps' => []];
$d = $blank;

function save_caps($conn, $rid, $caps) {
    $conn->query('DELETE FROM responder_capabilities WHERE responder_id = ' . (int)$rid);
    $st = $conn->prepare('INSERT INTO responder_capabilities (responder_id, type_id) VALUES (?, ?)');
    foreach ($caps as $t) { $st->bind_param('ii', $rid, $t); $st->execute(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $rid = (int)($_POST['responder_id'] ?? 0);

    if ($action === 'toggle_active') {
        $st = $conn->prepare('UPDATE users us JOIN responders rp ON rp.user_id = us.user_id SET us.is_active = 1 - us.is_active WHERE rp.responder_id = ?');
        $st->bind_param('i', $rid);
        $st->execute();
        flash('success', 'Team account updated.');
        redirect('admin/responders.php');
    }
    if ($action === 'availability') {
        $a = $_POST['availability'] ?? '';
        if (in_array($a, ['available', 'deployed', 'off_duty'], true)) {
            $st = $conn->prepare('UPDATE responders SET availability = ? WHERE responder_id = ?');
            $st->bind_param('si', $a, $rid);
            $st->execute();
            flash('success', 'Availability updated.');
        }
        redirect('admin/responders.php');
    }

    if ($action === 'create' || $action === 'update') {
        foreach (['team_name', 'full_name', 'username', 'contact_no', 'availability'] as $k) $d[$k] = trim($_POST[$k] ?? '');
        $d['caps'] = array_values(array_intersect(array_map('intval', (array)($_POST['caps'] ?? [])), $type_ids));
        $pw = $_POST['password'] ?? '';

        if ($d['team_name'] === '' || mb_strlen($d['team_name']) > 80) $errors['team_name'] = 'Enter the team name (up to 80 characters).';
        if ($d['full_name'] === '' || mb_strlen($d['full_name']) > 120) $errors['full_name'] = 'Enter the team leader or contact person.';
        if (!preg_match('/^09\d{9}$/', $d['contact_no'])) $errors['contact_no'] = 'Use an 11-digit mobile number starting with 09.';
        if (!in_array($d['availability'], ['available', 'deployed', 'off_duty'], true)) $d['availability'] = 'available';
        if (!$d['caps']) $errors['caps'] = 'Pick at least one type of incident this team handles.';

        if ($action === 'create') {
            if (!preg_match('/^[A-Za-z0-9_]{4,30}$/', $d['username'])) $errors['username'] = 'Use 4 to 30 letters, numbers, or underscores.';
            else {
                $st = $conn->prepare('SELECT 1 FROM users WHERE username = ?');
                $st->bind_param('s', $d['username']); $st->execute();
                if ($st->get_result()->num_rows) $errors['username'] = 'That username is taken.';
            }
            if (strlen($pw) < 8) $errors['password'] = 'Use at least 8 characters.';
        } elseif ($pw !== '' && strlen($pw) < 8) {
            $errors['password'] = 'Use at least 8 characters, or leave it blank to keep the current one.';
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                if ($action === 'create') {
                    $hash = password_hash($pw, PASSWORD_DEFAULT);
                    $st = $conn->prepare("INSERT INTO users (username, password_hash, role, full_name, contact_no) VALUES (?, ?, 'responder', ?, ?)");
                    $st->bind_param('ssss', $d['username'], $hash, $d['full_name'], $d['contact_no']);
                    $st->execute();
                    $uid = $conn->insert_id;
                    $st = $conn->prepare('INSERT INTO responders (user_id, team_name, contact_no, availability) VALUES (?, ?, ?, ?)');
                    $st->bind_param('isss', $uid, $d['team_name'], $d['contact_no'], $d['availability']);
                    $st->execute();
                    $rid = $conn->insert_id;
                } else {
                    $st = $conn->prepare('UPDATE responders SET team_name = ?, contact_no = ?, availability = ? WHERE responder_id = ?');
                    $st->bind_param('sssi', $d['team_name'], $d['contact_no'], $d['availability'], $rid);
                    $st->execute();
                    $st = $conn->prepare('UPDATE users us JOIN responders rp ON rp.user_id = us.user_id SET us.full_name = ?, us.contact_no = ? WHERE rp.responder_id = ?');
                    $st->bind_param('ssi', $d['full_name'], $d['contact_no'], $rid);
                    $st->execute();
                    if ($pw !== '') {
                        $hash = password_hash($pw, PASSWORD_DEFAULT);
                        $st = $conn->prepare('UPDATE users us JOIN responders rp ON rp.user_id = us.user_id SET us.password_hash = ? WHERE rp.responder_id = ?');
                        $st->bind_param('si', $hash, $rid);
                        $st->execute();
                    }
                }
                save_caps($conn, $rid, $d['caps']);
                $conn->commit();
                flash('success', $action === 'create' ? 'Team added. They can log in with the username and password you set.' : 'Team updated.');
                redirect('admin/responders.php');
            } catch (Throwable $ex) {
                $conn->rollback();
                error_log('responder save: ' . $ex->getMessage());
                $errors['form'] = 'The team could not be saved. Try again.';
            }
        }
        if ($action === 'update') $edit_id = $rid;
    }
}

// load the team being edited (only on first open, not after a failed save)
if ($edit_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $conn->prepare('SELECT rp.*, us.full_name, us.username FROM responders rp JOIN users us ON us.user_id = rp.user_id WHERE rp.responder_id = ?');
    $st->bind_param('i', $edit_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if ($row) {
        $d = ['team_name' => $row['team_name'], 'full_name' => $row['full_name'], 'username' => $row['username'], 'contact_no' => $row['contact_no'], 'availability' => $row['availability'],
              'caps' => array_map('intval', array_column($conn->query('SELECT type_id FROM responder_capabilities WHERE responder_id = ' . $edit_id)->fetch_all(MYSQLI_ASSOC), 'type_id'))];
    } else $edit_id = 0;
}

$teams = $conn->query("SELECT rp.responder_id, rp.team_name, rp.contact_no, rp.availability, us.full_name, us.username, us.is_active,
        GROUP_CONCAT(t.type_name ORDER BY t.type_name SEPARATOR ', ') AS handles,
        (SELECT COUNT(*) FROM incident_responses r WHERE r.responder_id = rp.responder_id AND r.cleared_at IS NULL) AS open_jobs
    FROM responders rp JOIN users us ON us.user_id = rp.user_id
    LEFT JOIN responder_capabilities c ON c.responder_id = rp.responder_id
    LEFT JOIN incident_types t ON t.type_id = c.type_id
    GROUP BY rp.responder_id ORDER BY us.is_active DESC, rp.team_name")->fetch_all(MYSQLI_ASSOC);

$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$inv = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="panel">
            <div class="panel-head"><h2>Teams <span class="count-pill"><?= count($teams) ?></span></h2></div>
            <?php if (!$teams): ?>
                <div class="empty"><i class="bi bi-people" style="color:var(--muted)"></i>No responder teams yet. Add the barangay's teams (BPAT, tanod, rescue, BHERT) using the form.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table data-table">
                        <thead><tr><th>Team</th><th>Handles</th><th>Availability</th><th>Open jobs</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($teams as $t): ?>
                            <tr class="<?= $t['is_active'] ? '' : 'opacity-50' ?>">
                                <td><div class="fw-semibold"><?= e($t['team_name']) ?></div>
                                    <div class="small text-secondary"><?= e($t['full_name']) ?> &middot; <a href="tel:<?= e($t['contact_no']) ?>"><?= e($t['contact_no']) ?></a> &middot; @<?= e($t['username']) ?></div></td>
                                <td class="small"><?= e($t['handles'] ?: 'None set') ?></td>
                                <td>
                                    <form method="post" class="d-flex gap-1">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="availability"><input type="hidden" name="responder_id" value="<?= (int)$t['responder_id'] ?>">
                                        <label class="visually-hidden" for="av<?= (int)$t['responder_id'] ?>">Availability</label>
                                        <select class="form-select form-select-sm" id="av<?= (int)$t['responder_id'] ?>" name="availability" onchange="this.form.submit()">
                                            <?php foreach (['available' => 'Available', 'deployed' => 'Deployed', 'off_duty' => 'Off duty'] as $k => $lbl): ?>
                                                <option value="<?= $k ?>" <?= $t['availability'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td><?= (int)$t['open_jobs'] ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$t['responder_id'] ?>">Edit</a>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="responder_id" value="<?= (int)$t['responder_id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit" <?= $t['is_active'] ? 'data-confirm="Deactivate ' . e($t['team_name']) . '? They will not be able to log in or be dispatched."' : '' ?>><?= $t['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="panel">
            <div class="panel-head"><h2><?= $edit_id ? 'Edit team' : 'Add a team' ?></h2><?php if ($edit_id): ?><a class="hint" href="<?= url('admin/responders.php') ?>">Cancel</a><?php endif; ?></div>
            <div class="panel-body">
                <?php if (isset($errors['form'])): ?><div class="alert alert-danger"><?= e($errors['form']) ?></div><?php endif; ?>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $edit_id ? 'update' : 'create' ?>">
                    <input type="hidden" name="responder_id" value="<?= $edit_id ?>">
                    <div class="mb-3"><label class="form-label" for="team_name">Team name</label>
                        <input class="form-control<?= $inv('team_name') ?>" id="team_name" name="team_name" maxlength="80" placeholder="Example: BPAT Team 1" value="<?= e($d['team_name']) ?>"><?= $err('team_name') ?></div>
                    <div class="mb-3"><label class="form-label" for="full_name">Team leader / contact person</label>
                        <input class="form-control<?= $inv('full_name') ?>" id="full_name" name="full_name" maxlength="120" value="<?= e($d['full_name']) ?>"><?= $err('full_name') ?></div>
                    <div class="mb-3"><label class="form-label" for="contact_no">Team mobile number</label>
                        <input class="form-control<?= $inv('contact_no') ?>" id="contact_no" name="contact_no" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?= e($d['contact_no']) ?>"><?= $err('contact_no') ?: '<div class="form-text">Gets SMS alerts when the team is dispatched.</div>' ?></div>
                    <fieldset class="mb-3">
                        <legend class="form-label">Handles these incidents</legend>
                        <?php foreach ($types as $t): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="caps[]" id="cap<?= (int)$t['type_id'] ?>" value="<?= (int)$t['type_id'] ?>" <?= in_array((int)$t['type_id'], $d['caps'], true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="cap<?= (int)$t['type_id'] ?>"><?= e($t['type_name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?= $err('caps') ?>
                    </fieldset>
                    <div class="mb-3"><label class="form-label" for="availability">Availability</label>
                        <select class="form-select" id="availability" name="availability">
                            <?php foreach (['available' => 'Available', 'deployed' => 'Deployed', 'off_duty' => 'Off duty'] as $k => $lbl): ?><option value="<?= $k ?>" <?= $d['availability'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select></div>
                    <hr>
                    <p class="small text-secondary">Team login</p>
                    <div class="mb-3"><label class="form-label" for="username">Username</label>
                        <input class="form-control<?= $inv('username') ?>" id="username" name="username" autocomplete="off" value="<?= e($d['username']) ?>" <?= $edit_id ? 'disabled' : '' ?>><?= $err('username') ?></div>
                    <div class="mb-3"><label class="form-label" for="password"><?= $edit_id ? 'New password' : 'Password' ?></label>
                        <input class="form-control<?= $inv('password') ?>" id="password" name="password" type="password" autocomplete="new-password"><?= $err('password') ?: '<div class="form-text">' . ($edit_id ? 'Leave blank to keep the current password.' : 'At least 8 characters. Give it to the team leader.') . '</div>' ?></div>
                    <button class="btn btn-umber w-100" type="submit"><?= $edit_id ? 'Save changes' : 'Add team' ?></button>
                </form>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
