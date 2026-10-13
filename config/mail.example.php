<?php
// email settings for forgot password
// HOW TO USE:
//   1. copy this file:  cp config/mail.example.php config/mail.php
//   2. put the system's Gmail + app password in config/mail.php
//   3. NEVER push config/mail.php to github (it's in .gitignore already)
//
// gmail needs 2-Step Verification turned on, then make an app password at
// Google Account > Security > 2-Step Verification > App passwords
// the app password is 16 letters, paste it without spaces

define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_SECURE', 'tls');                 // 'tls' for port 587, 'ssl' for 465
define('MAIL_USERNAME', 'your.system.email@gmail.com');
define('MAIL_PASSWORD', 'xxxxxxxxxxxxxxxx');  // the app password, NOT the normal gmail password
define('MAIL_FROM_NAME', 'KALASAG Barangay 172');

// full link to the site, used inside the reset email
// change this when the system is on a real domain
define('APP_URL', 'http://localhost/kalasag');
