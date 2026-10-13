<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_layout.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$user = null;
$errors = [];

// look up the account using the hash of the token from the link
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash = hash('sha256', $token);
    $st = $conn->prepare('SELECT user_id, username FROM users
                          WHERE reset_token_hash = ? AND reset_expires_at > NOW() AND is_active = 1 LIMIT 1');
    $st->bind_param('s', $hash);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (strlen($pw) < 8) $errors['password'] = 'Use at least 8 characters.';
    elseif ($pw !== $confirm) $errors['confirm'] = 'Passwords don\'t match.';

    if (!$errors) {
        $new = password_hash($pw, PASSWORD_DEFAULT);
        // save new password and kill the token so the link cant be used again
        $up = $conn->prepare('UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_expires_at = NULL WHERE user_id = ?');
        $up->bind_param('si', $new, $user['user_id']);
        $up->execute();

        flash('success', 'Password changed. Log in with your new password.');
        redirect('login.php');
    }
}

auth_top('Set a new password');
?>
<h2>Set a new password</h2>

<?php if (!$user): ?>
    <p class="lead-sm">This reset link doesn't work anymore.</p>
    <div class="alert alert-warning d-flex gap-2"><i class="bi bi-clock-history"></i><div>The link is wrong, already used, or older than 30 minutes. Request a new one.</div></div>
    <a class="btn btn-umber btn-lg w-100" href="<?= url('forgot_password.php') ?>">Request a new link</a>
<?php else: ?>
    <p class="lead-sm">For account <strong><?= e($user['username']) ?></strong>.</p>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="mb-3">
            <label class="form-label" for="password">New password</label>
            <input class="form-control form-control-lg<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="password" name="password" type="password" autocomplete="new-password" required autofocus>
            <?php if (isset($errors['password'])): ?><div class="invalid-feedback d-block"><?= e($errors['password']) ?></div><?php endif; ?>
        </div>
        <div class="mb-4">
            <label class="form-label" for="confirm">Confirm new password</label>
            <input class="form-control form-control-lg<?= isset($errors['confirm']) ? ' is-invalid' : '' ?>" id="confirm" name="confirm" type="password" autocomplete="new-password" required>
            <?php if (isset($errors['confirm'])): ?><div class="invalid-feedback d-block"><?= e($errors['confirm']) ?></div><?php endif; ?>
        </div>
        <button class="btn btn-umber btn-lg w-100" type="submit">Save new password</button>
    </form>
<?php endif; ?>
<?php auth_bottom(); ?>
