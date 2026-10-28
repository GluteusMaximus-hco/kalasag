<?php
// run this ONCE from the terminal to make the first admin account
//   php setup/create_admin.php
// only works in the terminal so nobody can open it from the browser

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

function ask($label, $hidden = false) {
    echo $label . ': ';
    // hide the password while typing (linux/mac only, windows cmd has no stty so it just shows)
    $can_hide = $hidden && PHP_OS_FAMILY !== 'Windows';
    if ($can_hide) shell_exec('stty -echo');
    $val = trim((string)fgets(STDIN));
    if ($can_hide) { shell_exec('stty echo'); echo PHP_EOL; }
    return $val;
}

echo "== KALASAG: create admin account ==" . PHP_EOL;

$full_name   = ask('Full name');
$contact     = ask('Mobile number (09XXXXXXXXX, new report alerts are texted here)');
$email       = ask('Email (optional, for forgot password and email alerts, press Enter to skip)');
$position    = ask('Position (ex. Barangay Secretary)');
$designation = ask('Designation (ex. BDRRM Focal Person)');
$username    = ask('Username');
$password    = ask('Password (8+ characters)', true);
$confirm     = ask('Confirm password', true);

// same rules as the register page
$errors = [];
if ($full_name === '') $errors[] = 'Full name is required.';
if (!preg_match('/^09\d{9}$/', $contact)) $errors[] = 'Mobile number must be 11 digits starting with 09.';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
if (!preg_match('/^[A-Za-z0-9_]{4,30}$/', $username)) $errors[] = 'Username must be 4 to 30 letters, numbers, or underscores.';
if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
elseif ($password !== $confirm) $errors[] = 'Passwords don\'t match.';

$chk = $conn->prepare('SELECT 1 FROM users WHERE username = ?');
$chk->bind_param('s', $username);
$chk->execute();
if ($chk->get_result()->num_rows) $errors[] = 'That username is already taken.';

if ($email !== '') {
    $chk = $conn->prepare('SELECT 1 FROM users WHERE email = ?');
    $chk->bind_param('s', $email);
    $chk->execute();
    if ($chk->get_result()->num_rows) $errors[] = 'That email is already used by another account.';
}

if ($errors) {
    echo PHP_EOL . 'Not saved:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $errors) . PHP_EOL;
    exit(1);
}

// users + admins row together
$conn->begin_transaction();
try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $email_val = $email === '' ? null : $email;
    $s1 = $conn->prepare("INSERT INTO users (username, password_hash, role, full_name, contact_no, email) VALUES (?, ?, 'admin', ?, ?, ?)");
    $s1->bind_param('sssss', $username, $hash, $full_name, $contact, $email_val);
    $s1->execute();
    $uid = $conn->insert_id;

    $s2 = $conn->prepare('INSERT INTO admins (user_id, position, designation) VALUES (?, ?, ?)');
    $s2->bind_param('iss', $uid, $position, $designation);
    $s2->execute();

    $conn->commit();
    echo PHP_EOL . "Admin account '{$username}' created. You can log in now." . PHP_EOL;
} catch (mysqli_sql_exception $ex) {
    $conn->rollback();
    echo PHP_EOL . 'Could not save the account: ' . $ex->getMessage() . PHP_EOL;
    exit(1);
}
