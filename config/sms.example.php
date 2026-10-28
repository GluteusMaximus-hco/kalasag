<?php
// SMS settings, uses the free "SMS Gateway for Android" app (sms-gate.app)
// the app runs on an android phone with a SIM, KALASAG asks it to send the texts
//
// HOW TO USE:
//   1. install "SMS Gateway for Android" on the phone (download the apk from sms-gate.app)
//   2. open the app, turn on "Cloud server", then tap Start (some versions say Offline/Online)
//   3. copy this file:  cp config/sms.example.php config/sms.php
//   4. put the username and password shown on the app's Home tab into config/sms.php
//   5. NEVER push config/sms.php to github (it's in .gitignore already)
//
// the phone needs internet (data or wifi) and load / unli text on the SIM
// texts come from the phone's own number

define('SMS_API_URL', 'https://api.sms-gate.app/3rdparty/v1/messages');
define('SMS_USERNAME', 'XXXXXX');            // from the app's Home tab
define('SMS_PASSWORD', 'xxxxxxxxxxxxxx');    // from the app's Home tab

// false = turn texts off without deleting the settings
define('SMS_ENABLED', true);
