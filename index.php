<?php
// just sends people to the right place
require_once __DIR__ . '/includes/auth.php';
$u = current_user();
redirect($u ? home_for($u['role']) : 'login.php');
