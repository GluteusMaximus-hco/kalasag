<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_layout.php';

if (current_user()) redirect(home_for(current_user()['role']));

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'birthdate' => '', 'contact_no' => '', 'email' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) $old[$k] = trim($_POST[$k] ?? '');
    $old['contact_no'] = clean_phone($old['contact_no']);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    // validation, one msg per field so the user knows exactly what to fix
    if ($old['first_name'] === '') $errors['first_name'] = 'Enter your first name.';
    if ($old['last_name'] === '')  $errors['last_name']  = 'Enter your last name.';

    // real date, not in the future, not before 1900 (string compare works for YYYY-MM-DD)
    if (!valid_date($old['birthdate']) || $old['birthdate'] > date('Y-m-d') || $old['birthdate'] < '1900-01-01') $errors['birthdate'] = 'Enter a valid birthdate.';

    if (!preg_match('/^09\d{9}$/', $old['contact_no'])) $errors['contact_no'] = 'Use an 11-digit mobile number starting with 09.';

    // email is optional, used for forgot password and email updates
    if ($old['email'] !== '') {
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email, like juan@gmail.com.';
        } else {
            $chk = $conn->prepare('SELECT 1 FROM users WHERE email = ?');
            $chk->bind_param('s', $old['email']);
            $chk->execute();
            if ($chk->get_result()->num_rows) $errors['email'] = 'That email is already used by another account.';
        }
    }

    if (!preg_match('/^[A-Za-z0-9_]{4,30}$/', $old['username'])) {
        $errors['username'] = 'Use 4 to 30 letters, numbers, or underscores.';
    } else {
        $chk = $conn->prepare('SELECT 1 FROM users WHERE username = ?');
        $chk->bind_param('s', $old['username']);
        $chk->execute();
        if ($chk->get_result()->num_rows) $errors['username'] = 'That username is taken. Try another one.';
    }

    if (strlen($password) < 8) $errors['password'] = 'Use at least 8 characters.';
    elseif ($password !== $confirm) $errors['confirm'] = 'Passwords don\'t match.';

    if (!$errors) {
        // users + residents rows go in together, if one fails both get undone
        $conn->begin_transaction();
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $full = $old['first_name'] . ' ' . $old['last_name'];

            $email = $old['email'] === '' ? null : $old['email'];
            $s1 = $conn->prepare("INSERT INTO users (username, password_hash, role, full_name, contact_no, email) VALUES (?, ?, 'resident', ?, ?, ?)");
            $s1->bind_param('sssss', $old['username'], $hash, $full, $old['contact_no'], $email);
            $s1->execute();
            $uid = $conn->insert_id;

            $s2 = $conn->prepare('INSERT INTO residents (user_id, first_name, last_name, birthdate, contact_no) VALUES (?, ?, ?, ?, ?)');
            $s2->bind_param('issss', $uid, $old['first_name'], $old['last_name'], $old['birthdate'], $old['contact_no']);
            $s2->execute();

            $conn->commit();
            flash('success', 'Account created. Log in to set up your household info.');
            redirect('login.php');
        } catch (mysqli_sql_exception $ex) {
            $conn->rollback();
            error_log('Register failed: ' . $ex->getMessage());
            $errors['form'] = 'Something went wrong while saving your account. Try again in a minute.';
        }
    }
}

// helper so each input shows its own error under it
function field_err($errors, $k) {
    return isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
}
function inv($errors, $k) { return isset($errors[$k]) ? ' is-invalid' : ''; }

auth_top('Create an account');
?>
<h2>Create an account</h2>
<p class="lead-sm">For residents of Barangay 172. The barangay will verify your account.</p>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Fix the highlighted fields below.</div>
<?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-sm-6">
            <label class="form-label" for="first_name">First name</label>
            <input class="form-control<?= inv($errors,'first_name') ?>" id="first_name" name="first_name" value="<?= e($old['first_name']) ?>" autocomplete="given-name" required>
            <?= field_err($errors, 'first_name') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="last_name">Last name</label>
            <input class="form-control<?= inv($errors,'last_name') ?>" id="last_name" name="last_name" value="<?= e($old['last_name']) ?>" autocomplete="family-name" required>
            <?= field_err($errors, 'last_name') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="birthdate">Birthdate</label>
            <input class="form-control<?= inv($errors,'birthdate') ?>" id="birthdate" name="birthdate" type="date" value="<?= e($old['birthdate']) ?>" max="<?= date('Y-m-d') ?>" required>
            <?= field_err($errors, 'birthdate') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="contact_no">Mobile number</label>
            <input class="form-control<?= inv($errors,'contact_no') ?>" id="contact_no" name="contact_no" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?= e($old['contact_no']) ?>" autocomplete="tel" required>
            <?= field_err($errors, 'contact_no') ?: '<div class="form-text">Updates on your reports are texted to this number.</div>' ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="email">Email <span class="text-secondary fw-normal">(optional)</span></label>
            <input class="form-control<?= inv($errors,'email') ?>" id="email" name="email" type="email" value="<?= e($old['email']) ?>" autocomplete="email">
            <?= field_err($errors, 'email') ?: '<div class="form-text">For password resets and email updates about your reports.</div>' ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="username">Username</label>
            <input class="form-control<?= inv($errors,'username') ?>" id="username" name="username" value="<?= e($old['username']) ?>" autocomplete="username" required>
            <?= field_err($errors, 'username') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="password">Password</label>
            <input class="form-control<?= inv($errors,'password') ?>" id="password" name="password" type="password" autocomplete="new-password" required>
            <?= field_err($errors, 'password') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="confirm">Confirm password</label>
            <input class="form-control<?= inv($errors,'confirm') ?>" id="confirm" name="confirm" type="password" autocomplete="new-password" required>
            <?= field_err($errors, 'confirm') ?>
        </div>
    </div>
    <button class="btn btn-umber btn-lg w-100 mt-4" type="submit">Create account</button>
</form>
<p class="text-center mt-3 mb-0 small">Already registered? <a href="<?= url('login.php') ?>" class="fw-semibold">Log in</a></p>
<?php auth_bottom(); ?>
