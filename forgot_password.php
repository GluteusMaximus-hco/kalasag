<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_layout.php';
require_once __DIR__ . '/includes/mailer.php';

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

            $link = rtrim(APP_URL, '/') . '/reset_password.php?token=' . $token;
            $name = e($row['full_name']);
            $html = "<div style=\"font-family:Arial,sans-serif;max-width:480px\">
                <h2 style=\"color:#3A2317;margin-bottom:4px\">KALASAG</h2>
                <p style=\"color:#7B6658;margin-top:0\">Barangay 172, Zone 17, Malibay, Pasay City</p>
                <p>Hi {$name},</p>
                <p>Someone asked to reset the password of your KALASAG account. Click the button to set a new one. The link works for 30 minutes and only once.</p>
                <p><a href=\"{$link}\" style=\"display:inline-block;background:#B25A26;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold\">Set a new password</a></p>
                <p style=\"color:#7B6658;font-size:13px\">If you didn't ask for this, ignore this email. Your password stays the same.</p>
                </div>";
            $text = "Hi {$row['full_name']},\n\nSet a new KALASAG password here (works for 30 minutes, one time only):\n{$link}\n\nIf you didn't ask for this, ignore this email.";

            if (!send_mail($row['email'], $row['full_name'], 'Reset your KALASAG password', $html, $text)) {
                error_log('Reset email not sent for user_id ' . $row['user_id']);
            }
        }
        // same message whether the account exists or not, so nobody can fish for accounts
        $sent = true;
    }
}

auth_top('Forgot password');
?>
<h2>Forgot password</h2>

<?php if (!mail_ready()): ?>
    <p class="lead-sm">Email reset isn't set up on this system yet.</p>
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
