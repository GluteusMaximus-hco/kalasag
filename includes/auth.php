<?php
// session + login helpers, include this at the top of every page
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,     // js can't read the session cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

// base url so links work even if the folder is named differently
$docroot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$approot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
define('BASE_URL', rtrim(substr($approot, strlen($docroot)), '/'));

// escape output, use this every time we echo user data
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function url($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect($path) {
    header('Location: ' . url($path));
    exit;
}

// csrf token for every form
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check() {
    // php throws away the whole form when the upload is bigger than post_max_size, so say that instead of "session expired"
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        exit('The photos were too big to send all at once. Go back and attach smaller or fewer photos.');
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Your session expired. Go back and reload the page, then try again.');
    }
}

// flash messages (shows once then disappears)
function flash($type, $msg) {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function show_flash() {
    if (empty($_SESSION['flash'])) return;
    foreach ($_SESSION['flash'] as $f) {
        echo '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show" role="alert">'
           . e($f['msg'])
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

// where each role lands after login
function home_for($role) {
    return [
        'admin'     => 'admin/dashboard.php',
        'resident'  => 'resident/home.php',
        'responder' => 'responder/home.php',
    ][$role] ?? 'login.php';
}

// kick out people who arent logged in or have the wrong role
function require_role(...$roles) {
    $u = current_user();
    if (!$u) {
        flash('warning', 'Log in first to open that page.');
        redirect('login.php');
    }
    if ($roles && !in_array($u['role'], $roles, true)) {
        http_response_code(403);
        redirect(home_for($u['role']));
    }
    return $u;
}

// "5 mins ago" style time for lists
function time_ago($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 172800) return 'yesterday';
    return date('M j', strtotime($datetime));
}

// status labels shown to users
function status_label($s) {
    return [
        'pending'      => 'Pending',
        'acknowledged' => 'Acknowledged',
        'dispatched'   => 'Responders dispatched',
        'resolved'     => 'Resolved',
        'cancelled'    => 'Cancelled',
    ][$s] ?? ucfirst($s);
}
