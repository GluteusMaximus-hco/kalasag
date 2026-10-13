<?php
require_once __DIR__ . '/includes/auth.php';
// wipe the session fully then start a clean one for the flash msg
$_SESSION = [];
session_regenerate_id(true);
session_destroy();
session_start();
flash('success', 'You are logged out.');
redirect('login.php');
