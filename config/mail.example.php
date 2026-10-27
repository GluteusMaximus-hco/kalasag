<?php
// email settings for forgot password and email notifications
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

// full link to the site for links inside emails and texts
// leave it as is, KALASAG figures out the link by itself (localhost, the tunnel, or hosting)
// only change it if links in emails come out wrong, like 'https://kalasag172.infinityfreeapp.com'
define('APP_URL', 'http://localhost/kalasag');
