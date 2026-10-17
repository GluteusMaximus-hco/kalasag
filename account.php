<?php
// account settings, works for every role (admin, resident, responder)
require_once __DIR__ . '/includes/auth.php';
$u = require_role();

$page_title = 'Account settings';
$active = 'account';
$errors = ['profile' => [], 'password' => []];

// load the current info
function load_me($conn, $u) {
    $st = $conn->prepare('SELECT user_id, username, full_name, contact_no, email, password_hash FROM users WHERE user_id = ?');
    $st->bind_param('i', $u['user_id']);
    $st->execute();
    $me = $st->get_result()->fetch_assoc();

    // residents have separate first/last name in their own table
    if ($u['role'] === 'resident') {
        $st = $conn->prepare('SELECT first_name, last_name FROM residents WHERE user_id = ?');
        $st->bind_param('i', $u['user_id']);
        $st->execute();
        $me += $st->get_result()->fetch_assoc() ?: ['first_name' => '', 'last_name' => ''];
    }
    return $me;
}

try {
    $me = load_me($conn, $u);
} catch (mysqli_sql_exception $ex) {
    error_log('account load: ' . $ex->getMessage());
    http_response_code(500);
    exit('Your account info could not load. Try again in a minute.');
}
$form = $me;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ---------- profile ----------
    if ($action === 'profile') {
        $E = &$errors['profile'];
        $form['contact_no'] = trim($_POST['contact_no'] ?? '');
        $form['email'] = trim($_POST['email'] ?? '');

        if ($u['role'] === 'resident') {
            $form['first_name'] = trim($_POST['first_name'] ?? '');
            $form['last_name'] = trim($_POST['last_name'] ?? '');
            if ($form['first_name'] === '') $E['first_name'] = 'Enter your first name.';
            if ($form['last_name'] === '') $E['last_name'] = 'Enter your last name.';
            $full = $form['first_name'] . ' ' . $form['last_name'];
        } else {
            $form['full_name'] = trim($_POST['full_name'] ?? '');
            if ($form['full_name'] === '') $E['full_name'] = 'Enter your full name.';
            $full = $form['full_name'];
        }

        if (!preg_match('/^09\d{9}$/', $form['contact_no'])) $E['contact_no'] = 'Use an 11-digit mobile number starting with 09.';

        // email is optional, but if they put one it has to be valid and not used by someone else
        if ($form['email'] !== '') {
            if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                $E['email'] = 'Enter a valid email, like juan@gmail.com.';
            } else {
                $chk = $conn->prepare('SELECT 1 FROM users WHERE email = ? AND user_id <> ?');
                $chk->bind_param('si', $form['email'], $u['user_id']);
                $chk->execute();
                if ($chk->get_result()->num_rows) $E['email'] = 'That email is already used by another account.';
            }
        }

        if (!$E) {
            $email = $form['email'] === '' ? null : $form['email'];
            $conn->begin_transaction();
            try {
                $s = $conn->prepare('UPDATE users SET full_name = ?, contact_no = ?, email = ? WHERE user_id = ?');
                $s->bind_param('sssi', $full, $form['contact_no'], $email, $u['user_id']);
                $s->execute();

                // keep the copies in residents / responders the same
                if ($u['role'] === 'resident') {
                    $s = $conn->prepare('UPDATE residents SET first_name = ?, last_name = ?, contact_no = ? WHERE user_id = ?');
                    $s->bind_param('sssi', $form['first_name'], $form['last_name'], $form['contact_no'], $u['user_id']);
                    $s->execute();
                } elseif ($u['role'] === 'responder') {
                    $s = $conn->prepare('UPDATE responders SET contact_no = ? WHERE user_id = ?');
                    $s->bind_param('si', $form['contact_no'], $u['user_id']);
                    $s->execute();
                }
                $conn->commit();

                $_SESSION['user']['full_name'] = $full;   // so the top bar shows the new name right away
                flash('success', 'Profile saved.');
                redirect('account.php');
            } catch (mysqli_sql_exception $ex) {
                $conn->rollback();
                error_log('profile save: ' . $ex->getMessage());
                $E['form'] = 'Your changes couldn\'t be saved. Try again in a minute.';
            }
        }
        unset($E);
    }

    // ---------- password ----------
    if ($action === 'password') {
        $E = &$errors['password'];
        $cur = $_POST['current'] ?? '';
        $new = $_POST['new'] ?? '';
        $confirm = $_POST['confirm'] ?? '';

        if (!password_verify($cur, $me['password_hash'])) $E['current'] = 'That\'s not your current password.';
        if (strlen($new) < 8) $E['new'] = 'Use at least 8 characters.';
        elseif ($new === $cur) $E['new'] = 'Use a password different from your current one.';
        elseif ($new !== $confirm) $E['confirm'] = 'Passwords don\'t match.';

        if (!$E) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            // also clears any old forgot-password link
            $s = $conn->prepare('UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_expires_at = NULL WHERE user_id = ?');
            $s->bind_param('si', $hash, $u['user_id']);
            $s->execute();
            session_regenerate_id(true);
            flash('success', 'Password changed.');
            redirect('account.php');
        }
        unset($E);
    }
}

// small helpers for showing errors under inputs
function ferr($arr, $k) { return isset($arr[$k]) ? '<div class="invalid-feedback d-block">' . e($arr[$k]) . '</div>' : ''; }
function finv($arr, $k) { return isset($arr[$k]) ? ' is-invalid' : ''; }
$P = $errors['profile'];
$W = $errors['password'];

include __DIR__ . '/includes/header.php';
?>

<div class="row g-4">
    <div class="col-lg-7">
        <section class="panel">
            <div class="panel-head"><h2>Profile</h2><span class="hint">Signed in as <?= e($me['username']) ?></span></div>
            <div class="panel-body">
                <?php if (isset($P['form'])): ?><div class="alert alert-danger"><?= e($P['form']) ?></div>
                <?php elseif ($P): ?><div class="alert alert-danger">Fix the highlighted fields below.</div><?php endif; ?>

                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-3">
                        <?php if ($u['role'] === 'resident'): ?>
                            <div class="col-sm-6">
                                <label class="form-label" for="first_name">First name</label>
                                <input class="form-control<?= finv($P,'first_name') ?>" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" autocomplete="given-name" required>
                                <?= ferr($P, 'first_name') ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="last_name">Last name</label>
                                <input class="form-control<?= finv($P,'last_name') ?>" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" autocomplete="family-name" required>
                                <?= ferr($P, 'last_name') ?>
                            </div>
                        <?php else: ?>
                            <div class="col-12">
                                <label class="form-label" for="full_name">Full name</label>
                                <input class="form-control<?= finv($P,'full_name') ?>" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" autocomplete="name" required>
                                <?= ferr($P, 'full_name') ?>
                            </div>
                        <?php endif; ?>
                        <div class="col-sm-6">
                            <label class="form-label" for="contact_no">Mobile number</label>
                            <input class="form-control<?= finv($P,'contact_no') ?>" id="contact_no" name="contact_no" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?= e($form['contact_no']) ?>" autocomplete="tel" required>
                            <?= ferr($P, 'contact_no') ?: '<div class="form-text">So the barangay can call or text you about your reports.</div>' ?>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="email">Email <span class="text-secondary fw-normal">(optional)</span></label>
                            <input class="form-control<?= finv($P,'email') ?>" id="email" name="email" type="email" value="<?= e($form['email'] ?? '') ?>" autocomplete="email">
                            <?= ferr($P, 'email') ?: '<div class="form-text">Lets you reset your password by email if you forget it.</div>' ?>
                        </div>
                    </div>
                    <button class="btn btn-umber mt-4" type="submit">Save profile</button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="panel">
            <div class="panel-head"><h2>Change password</h2></div>
            <div class="panel-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="mb-3">
                        <label class="form-label" for="current">Current password</label>
                        <input class="form-control<?= finv($W,'current') ?>" id="current" name="current" type="password" autocomplete="current-password" required>
                        <?= ferr($W, 'current') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new">New password</label>
                        <input class="form-control<?= finv($W,'new') ?>" id="new" name="new" type="password" autocomplete="new-password" required>
                        <?= ferr($W, 'new') ?: '<div class="form-text">At least 8 characters.</div>' ?>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="confirm">Confirm new password</label>
                        <input class="form-control<?= finv($W,'confirm') ?>" id="confirm" name="confirm" type="password" autocomplete="new-password" required>
                        <?= ferr($W, 'confirm') ?>
                    </div>
                    <button class="btn btn-sienna" type="submit">Change password</button>
                </form>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
