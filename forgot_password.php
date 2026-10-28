<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_layout.php';
require_once __DIR__ . '/includes/notify.php';   // has app_url() and loads the mailer

if (current_user()) redirect(home_for(current_user()['role']));

$sent = false;
$error = '';
$who = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && mail_ready()) {
    csrf_check();
    $who = trim($_POST['who'] ?? '');

    // 1 request per minute per browser so nobody spams someone's inbox
    $last = $_SESSION['reset_req_at'] ?? 0;
    if (time() - $last < 60) {
        $error = 'Wait a minute before requesting another link.';
    } elseif ($who === '') {
        $error = 'Enter your username or email.';
    } else {
        $_SESSION['reset_req_at'] = time();

        $st = $conn->prepare('SELECT user_id, full_name, email FROM users
                              WHERE (username = ? OR email = ?) AND email IS NOT NULL AND is_active = 1 LIMIT 1');
        $st->bind_param('ss', $who, $who);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();

        if ($row) {
            // the real token goes in the email, only its hash goes in the db
            // so even if someone sees the db they cant use the link
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);

            $up = $conn->prepare('UPDATE users SET reset_token_hash = ?, reset_expires_at = NOW() + INTERVAL 30 MINUTE WHERE user_id = ?');
            $up->bind_param('si', $hash, $row['user_id']);
            $up->execute();

            $link = app_url('reset_password.php?token=' . $token);   // works on localhost, the tunnel, or a real domain
            // same email look as the other KALASAG emails, and it shows up in the admin's send log
            [$ok] = email_send($row['email'], $row['full_name'], 'Reset your KALASAG password', 'Reset your password', [
                'Hi ' . $row['full_name'] . ',',
                'Someone asked to reset the password of your KALASAG account. Click the button to set a new one. The link works for 30 minutes and only once.',
                "If you didn't ask for this, ignore this email. Your password stays the same.",
            ], $link, 'password reset', 'Set a new password');
            if (!$ok) error_log('Reset email not sent for user_id ' . $row['user_id']);
        }
        // same message whether the account exists or not, so nobody can fish for accounts
        $sent = true;
    }
}

auth_top('Forgot password');
?>
<h2>Forgot password</h2>

<?php if (!mail_ready()): ?>
    <p class="lead-sm">Password reset by email isn't available right now.</p>
    <div class="alert alert-info d-flex gap-2"><i class="bi bi-building"></i><div>Visit or call the Barangay 172 hall and the admin will reset your password.</div></div>

<?php elseif ($sent): ?>
    <p class="lead-sm">Check your email.</p>
    <div class="alert alert-success d-flex gap-2" role="status">
        <i class="bi bi-envelope-check-fill"></i>
        <div>If that account has an email saved, we sent a reset link to it. The link works for 30 minutes. Check your spam folder if you don't see it.</div>
    </div>
    <p class="small text-secondary">No email on your account? Visit or call the Barangay 172 hall and the admin will reset your password.</p>

<?php else: ?>
    <p class="lead-sm">Enter your username or the email on your account and we'll send you a reset link.</p>
    <?php if ($error): ?>
        <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-circle-fill"></i><div><?= e($error) ?></div></div>
    <?php endif; ?>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="mb-4">
            <label class="form-label" for="who">Username or email</label>
            <input class="form-control form-control-lg" id="who" name="who" value="<?= e($who) ?>" autocomplete="username" required autofocus>
        </div>
        <button class="btn btn-umber btn-lg w-100" type="submit">Send reset link</button>
    </form>
    <p class="small text-secondary mt-3 mb-0">No email on your account? Visit or call the Barangay 172 hall and the admin will reset your password.</p>
<?php endif; ?>

<p class="text-center mt-4 mb-0 small"><a href="<?= url('login.php') ?>" class="fw-semibold">Back to log in</a></p>
<?php auth_bottom(); ?>
