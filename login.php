<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_layout.php';

// already logged in? go home
if (current_user()) redirect(home_for(current_user()['role']));

$error = '';
$username = '';

// simple brute force protection: 5 wrong tries = wait 60 secs
$_SESSION['login_fails'] = $_SESSION['login_fails'] ?? 0;
$locked_until = $_SESSION['login_lock'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (time() < $locked_until) {
        $error = 'Too many wrong tries. Wait ' . ($locked_until - time()) . ' seconds, then try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $stmt = $conn->prepare('SELECT user_id, username, password_hash, role, full_name, is_active FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        // same message for wrong user and wrong pass so nobody can guess usernames
        if (!$row || !password_verify($password, $row['password_hash'])) {
            $_SESSION['login_fails']++;
            if ($_SESSION['login_fails'] >= 5) {
                $_SESSION['login_lock'] = time() + 60;
                $_SESSION['login_fails'] = 0;
            }
            $error = 'Wrong username or password.';
        } elseif (!$row['is_active']) {
            $error = 'This account is deactivated. Contact the barangay hall to reactivate it.';
        } else {
            // login ok, new session id to prevent session fixation
            session_regenerate_id(true);
            unset($_SESSION['login_fails'], $_SESSION['login_lock']);
            $_SESSION['user'] = [
                'user_id'   => (int)$row['user_id'],
                'username'  => $row['username'],
                'role'      => $row['role'],
                'full_name' => $row['full_name'],
            ];
            redirect(home_for($row['role']));
        }
    }
}

auth_top('Log in');
?>
<h2>Log in</h2>
<p class="lead-sm">Use the account you registered with the barangay.</p>

<?php show_flash(); ?>
<?php if ($error): ?>
    <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-circle-fill"></i><div><?= e($error) ?></div></div>
<?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input class="form-control form-control-lg" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
    </div>
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-baseline">
            <label class="form-label" for="password">Password</label>
            <a class="small fw-semibold" href="<?= url('forgot_password.php') ?>">Forgot password?</a>
        </div>
        <div class="input-group">
            <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="btn btn-outline-secondary" type="button" id="togglePw" aria-label="Show password"><i class="bi bi-eye"></i></button>
        </div>
    </div>
    <button class="btn btn-umber btn-lg w-100" type="submit">Log in</button>
</form>

<p class="text-center mt-3 mb-0 small">New resident? <a href="<?= url('register.php') ?>" class="fw-semibold">Create an account</a></p>

<a class="guest-box" href="<?= url('guest_report.php') ?>">
    <i class="bi bi-broadcast"></i>
    <div>
        <strong>Emergency but no account?</strong>
        <p>Report it without logging in. You'll get a code to track it.</p>
    </div>
</a>
<p class="text-center small mt-2 mb-0">Sent a guest report? <a class="fw-semibold" href="<?= url('track.php') ?>">Track it with your code</a></p>

<script>
// show/hide password
document.getElementById('togglePw').addEventListener('click', function () {
    const pw = document.getElementById('password');
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    this.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
</script>
<?php auth_bottom(); ?>
