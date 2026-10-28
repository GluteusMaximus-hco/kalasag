# KALASAG
**A Web-Based Barangay Disaster and Emergency Response System**
for Barangay 172, Zone 17, Malibay, Pasay City

Final project for Application Development and Emerging Technologies, Adamson University

| Member | Role |
|---|---|
| Negrete, Richbert Zepri | Project Manager |
| Ortiz, Hanz Christer | Lead Programmer & Full Stack Developer |
| Tejadilla, Francis Khonrad D. | Database Administrator |
| De Leon, John Aldrich | System Analyst |
| Gabriel, Ryle Xamuel T. | Documentation |

Stack: PHP 8 + MySQLi (no framework), MySQL/MariaDB, Bootstrap 5, Leaflet.js + OpenStreetMap, Chart.js, PHPMailer

## Progress

| Part | Status |
|---|---|
| Database (16 tables from our final ERD) | Done |
| Front end (every screen, desktop + phone) | Done |
| Core back end (saving, roles, reports, dispatch, analytics) | Done |
| Emergency hotlines page | Done, numbers from the official Barangay 172 hotline sign |
| SMS notifications (SMS Gateway for Android) | Done, demo uses our lead programmer's phone |
| Email notifications | Done, demo uses a project Gmail |
| Forgot password by email | Done |
| Online hosting with HTTPS | Guide below (InfinityFree, free) |
| ML urgency tagging | Only if time permits |

For the demo the texts are sent from our lead programmer's phone and the emails from a project Gmail. At handover the barangay puts in their own phone and Gmail, no code changes needed.

## Setup (Ubuntu)

```bash
# 1. install apache, php, mysql
sudo apt update
sudo apt install -y apache2 mysql-server php libapache2-mod-php php-mysql php-gd php-mbstring php-curl

# 2. let php accept phone photos (default is only 2 MB)
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 6M/; s/^post_max_size.*/post_max_size = 20M/' /etc/php/*/apache2/php.ini

# 3. copy the project to apache
sudo cp -r kalasag /var/www/html/
sudo chown -R www-data:www-data /var/www/html/kalasag

# 4. make the database + app user
sudo mysql < /var/www/html/kalasag/database/schema.sql
sudo mysql -e "CREATE USER IF NOT EXISTS 'kalasag'@'localhost' IDENTIFIED BY 'kalasag123'; GRANT ALL ON kalasag_db.* TO 'kalasag'@'localhost'; FLUSH PRIVILEGES;"

# 5. make the first admin account (asks for name, username, password)
cd /var/www/html/kalasag && sudo php setup/create_admin.php

# 6. start it
sudo systemctl restart apache2 mysql
```

Then open KALASAG on local host in your browser (the kalasag folder).

The database starts clean. The only starting data is the 5 incident types (flood, fire, vehicular accident, fallen tree, medical emergency) since the report form needs them. Residents sign up on the register page, responder teams are made by the admin.

**Note:** running `schema.sql` again deletes all data in the database, including the admin account.

Databases made with an older schema.sql are updated with this migration instead, which keeps the existing data:

```bash
sudo mysql kalasag_db < database/migrations/001_email_and_password_reset.sql
```

## Running it again

With Apache:
```bash
sudo systemctl start apache2 mysql
```
then open KALASAG on local host in your browser.

Or straight from the project folder, no copying to /var/www needed:
```bash
sudo systemctl start mysql
php -S localhost:8000
```
then open it on local host, port 8000, in your browser (Ctrl+C to stop).

Phones on the same Wi-Fi can open it using the laptop's IP address (`hostname -I`) followed by `/kalasag/`.

Phones only share GPS on https. While it runs locally on plain http, the report pin is placed by tapping the map. GPS will work once the system is hosted online with https.

## Running it on Windows (XAMPP)

1. Install XAMPP, then copy the `kalasag` folder into `C:\xampp\htdocs\`
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**
3. Open phpMyAdmin on local host in the browser, open the **Import** tab (without picking a database) and import `database\schema.sql`
4. In the **SQL** tab, run the same CREATE USER / GRANT line from step 4 of the Ubuntu setup
5. Make the admin account in Command Prompt:

```
cd C:\xampp\htdocs\kalasag
C:\xampp\php\php.exe setup\create_admin.php
```

6. Open KALASAG on local host in the browser (the kalasag folder)

For SMS on XAMPP, curl has to be on: in XAMPP, click **Config** next to Apache, open **PHP (php.ini)**, remove the `;` in front of `extension=curl`, then restart Apache. The `config\sms.php` and `config\mail.php` files are made with `copy` and edited in Notepad (with file name extensions shown, so they don't end up as `.php.txt`).

## What's built

**Residents** (`resident/`)
- Home: report button, recent reports, announcements, 3 nearest evacuation centers
- Report an emergency: incident type, GPS pin (drag to adjust), landmark, description, up to 3 photos
- My reports + report page: 4-step status, timeline, photos, map, messages with the barangay, cancel while still pending
- My household: address, map pin, elderly/PWD/pregnant/bedridden, Data Privacy Act (RA 10173) consent
- Nearby evacuation centers: sorted by distance from GPS or the household pin, directions links

**Guests** (no account)
- `guest_report.php`: same report form, gives a KLS tracking code (1 report per 2 minutes per browser)
- `track.php`: check status + message the barangay using the code (locks after 10 wrong codes)

**Admin** (`admin/`)
- Dashboard: live map (refreshes every 30 secs), counts, needs-attention queue
- Incidents: filters (status, type, date, priority, search), CSV export
- Incident page: status updates with remarks, urgency tag, priority flag, dispatch (matching teams first), messages, timeline
- Responders: make team logins, incident types they handle, availability, deactivate
- Residents & households: verify, reset password (for people without email), deactivate, household map
- Announcements, evacuation centers (with map pin and open/full/closed)
- Analytics: per month, by type, by status, response times, team table, print report, CSV
- SMS & email: shows if SMS and email are set up, check buttons, and a log of recent sends (numbers partly hidden)

**Responders** (`responder/`)
- Assignments list + assignment page: directions, "We've arrived", "Mark as cleared" with notes, messages

**Everyone**
- Account page: edit name, mobile number, email, change password
- Forgot password by email
- Emergency hotlines page, open even when logged out

**Automatic**
- SMS and email notifications (list below)
- Reports within 100 m of a household with vulnerable members are flagged as priority
- Photos are checked (real images only), resized, and their GPS/EXIF data is removed
- Photos are only shown through `photo.php` to people allowed to see that report

**Security**
- Hashed passwords, CSRF tokens on every form, new session id on login
- 5 wrong logins = wait 60 secs
- Role checks on every page, residents only see their own reports
- CSV export blocks formula injection
- Pages can't be loaded inside other sites (clickjacking), and tracking codes or reset links in the address never leak to the map or font servers
- On https the login cookie is secure-only

Accounts are deactivated instead of deleted, and reports are cancelled instead of deleted, so the barangay keeps a full record for analytics and accountability.

## SMS and email notifications

| When | Who gets it |
|---|---|
| New report | The reporter gets a confirmation (guests also get their KLS tracking code), every admin gets an alert |
| Acknowledged, resolved, closed | The reporter, with the admin's remark |
| Remark only | The reporter |
| Team dispatched | The reporter, and the team's contact number gets the assignment |
| Team arrived | The reporter |
| Message from the barangay or the team | The reporter |
| Announcement | Every active resident, only when "Also text and email all residents" is ticked |

Texts go to the mobile number on the account (or the guest's number). Emails only go to people who added an email. Texts are kept under 160 characters so each one counts as 1 SMS.

Everything is sent after the page finishes saving, so a slow phone or Gmail never slows down the admin or stops a report from being saved. Every send is written to `logs/notify.log` (numbers and emails partly hidden), which the admin sees on the SMS & email page. The `logs/` folder is blocked from the browser.

If SMS or email is not set up, the system still works normally, it just skips sending and the SMS & email page shows "Not set up".

### SMS setup (Android phone)

The texts are sent by the SMS Gateway for Android app (free and open source). The system sends the message to the app over the internet, then the phone texts it using its own SIM and load.

1. On the Android phone, open sms-gate.app in Chrome, tap **Download App**, and install the APK (allow "Install unknown apps" for Chrome when Android asks)
2. Open it, allow SMS permission, turn on **Cloud server**, then tap **Start** (some versions show an **Offline** button that turns **Online**)
3. The Cloud server part of the Home tab shows a **username** and **password** (made by the app, no sign up needed)
4. On the laptop:

```bash
cp config/sms.example.php config/sms.php
nano config/sms.php
```

5. Put the username in SMS_USERNAME and the password in SMS_PASSWORD, then save
6. Log in as admin, open **SMS & email**, and press **Send SMS check**

The phone needs mobile data or Wi-Fi, load or an unli text promo, and the app left running (turn off battery saver for the app). Android limits how many texts an app can send in a short time (about 30 per 30 minutes on many phones). When an announcement goes past that, the phone asks if the app may send a large number of messages, and **Always allow** lets the rest go out.

`config/sms.php` is listed in .gitignore, so the password is never uploaded to GitHub.

### Email setup (Gmail)

1. Make a separate Gmail for the system (not someone's personal one)
2. Turn on 2-Step Verification, then make an app password at myaccount.google.com/apppasswords. The app password is the 16-letter code Google shows, not the normal Gmail password
3. Copy the settings file and fill it in:

```bash
cp config/mail.example.php config/mail.php
nano config/mail.php
```

4. The Gmail goes in MAIL_USERNAME and the 16-letter app password (no spaces) goes in MAIL_PASSWORD
5. On the SMS & email page, press **Send email check**

Links in the emails (reset password, open your report) use whatever address the system was opened from, so they also work through a tunnel or a hosted domain. APP_URL in `config/mail.php` is only changed if the links come out wrong.

`config/mail.php` is listed in .gitignore, so the password is never uploaded to GitHub.

### Handover to the barangay

The barangay installs the app on their own Android phone and makes their own Gmail, then puts the new username, password and app password in `config/sms.php` and `config/mail.php`. Nothing else changes.

## Online hosting (InfinityFree, free with HTTPS)

Phones only share GPS on https, so hosting online also turns on the GPS pin.

1. Sign up at infinityfree.com and create an account with a free subdomain
2. In the control panel, turn on **Free SSL** for the subdomain
3. In **MySQL Databases**, make a database. Note the host, database name, username and password shown there
4. On the laptop, make the admin account locally first, then export the database (this file has no CREATE DATABASE, so it imports into any database name):

```bash
mysqldump -u root -p kalasag_db > kalasag_host.sql
```

5. Open **phpMyAdmin** from the control panel, pick the new database, then **Import** `kalasag_host.sql`
6. Edit `config/db.php` with the hosting database details (DB_HOST, DB_USER, DB_PASS, DB_NAME), and set APP_URL in `config/mail.php` to the site's https address so every link in emails and texts points to the real site
7. Upload everything inside the kalasag folder to `htdocs` using the File Manager or FileZilla, including `config/sms.php` and `config/mail.php`
8. Open the site with https, log in as admin, and press both check buttons on the SMS & email page

The `.htaccess` files in `uploads/` and `logs/` already work on InfinityFree, so photos and the send log stay private there.

`kalasag_host.sql` contains password hashes, so it is deleted after importing and never uploaded to GitHub.

## Emergency hotlines

The numbers in `config/hotlines.php` are copied from the official Barangay 172 Zone 17 emergency hotline sign: the barangay hotline (Smart and Globe), Pasay City DRRMO, BFP Pasay, PNP, Police Sub Station 6, Pasay City General Hospital, City Health Office, Pasay City Traffic, Meralco, and Maynilad.

Two numbers on the sign were hard to read and are not added yet (listed in the comments of `config/hotlines.php`). They will be added once the barangay confirms them. Only official numbers are used, since a wrong hotline is dangerous during an emergency.

## Blocking direct access to photos

Photos are saved in `uploads/incidents/`. The `.htaccess` there blocks direct access, but Apache on Ubuntu ignores .htaccess by default. It is turned on by adding this inside `/etc/apache2/sites-available/000-default.conf` (before `</VirtualHost>`) and restart Apache:

```
<Directory /var/www/html/kalasag/uploads>
    Require all denied
</Directory>
```

Without this setting, photos are still protected since their file names are random and the pages only show them through `photo.php`.

## Troubleshooting

**"Can't connect to the database"**: MySQL is not running. Start it with `sudo systemctl start mysql`

**Forgotten admin password**: a new one can be set from the terminal (replace newpass123 and the username)
```bash
NEWHASH=$(php -r 'echo password_hash("newpass123", PASSWORD_DEFAULT);')
sudo mysql kalasag_db -e "UPDATE users SET password_hash='$NEWHASH' WHERE username='hanzortiz';"
```

**Photos won't upload**: php-gd is missing or the upload limit is too low. Install php-gd, do step 2 of setup, then run `sudo systemctl restart apache2`

**SMS check says "wrong username/password"**: the app shows a new password when it is reinstalled. Copy it again into `config/sms.php`

**SMS check sent but no text arrived**: the phone has no load, no internet, or the app was closed by battery saver. Open the app and check its Messages tab

**Email check says "Gmail rejected the login"**: MAIL_PASSWORD has the normal Gmail password instead of the 16-letter app password, or the app password was deleted (turning off 2-Step Verification deletes them). Make a new app password and paste it again

**Email check says "Could not reach Gmail"**: no internet, or php-openssl is off

**SMS check says "php-curl is not installed"**: run `sudo apt install php-curl` then restart Apache (or the php -S command)

**Page looks old after an update**: the browser is showing a cached copy. Press Ctrl+Shift+R to reload.

## Folders

```
admin/       admin pages (dashboard, incidents, responders, residents, announcements, evacuation, analytics, export, sms & email)
resident/    resident pages (home, report, my reports, household, evacuation centers)
responder/   responder pages (assignments)
api/         json for the live map
config/      db connection, mail + sms settings templates, hotlines list
includes/    auth helpers, shared layout, incident helpers, report form, mailer, notifications
lib/         PHPMailer (for sending emails)
assets/      css, js (map pin picker, photo previews), logo
uploads/     incident photos (served only through photo.php)
logs/        notify.log, the SMS and email send log (blocked from the browser)
database/    schema.sql (clean, 16 tables) + migrations/
setup/       create_admin.php (run once in the terminal)
```
