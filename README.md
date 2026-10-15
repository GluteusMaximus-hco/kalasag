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
| Forgot password by email | Done, waiting for the barangay's official Gmail |
| Emergency hotlines page | Done, waiting for the official numbers from the barangay |
| SMS notifications (Android phone + barangay SIM) | Back-end review |
| Email notifications | Back-end review |
| Online hosting with domain + HTTPS | Before the final defense |
| ML urgency tagging | Only if time permits |

## Setup (Ubuntu)

```bash
# 1. install apache, php, mysql
sudo apt update
sudo apt install -y apache2 mysql-server php libapache2-mod-php php-mysql php-gd php-mbstring

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

**Responders** (`responder/`)
- Assignments list + assignment page: directions, "We've arrived", "Mark as cleared" with notes, messages

**Everyone**
- Account page: edit name, mobile number, email, change password
- Forgot password by email (once `config/mail.php` is set up)
- Emergency hotlines page, open even when logged out

**Automatic**
- Reports within 100 m of a household with vulnerable members are flagged as priority
- Photos are checked (real images only), resized, and their GPS/EXIF data is removed
- Photos are only shown through `photo.php` to people allowed to see that report

**Security**
- Hashed passwords, CSRF tokens on every form, new session id on login
- 5 wrong logins = wait 60 secs
- Role checks on every page, residents only see their own reports
- CSV export blocks formula injection

Accounts are deactivated instead of deleted, and reports are cancelled instead of deleted, so the barangay keeps a full record for analytics and accountability.

## Forgot password by email (Gmail)

1. Make a separate Gmail for the system (not someone's personal one)
2. Turn on 2-Step Verification, then go to Google Account > Security > App passwords and make one
3. Copy the settings file and fill it in:

```bash
cp config/mail.example.php config/mail.php
nano config/mail.php
```

The Gmail goes in MAIL_USERNAME and the 16-letter app password (no spaces) goes in MAIL_PASSWORD.
`config/mail.php` is listed in .gitignore, so the password is never uploaded to GitHub.

**Status:** waiting for the barangay's official Gmail. Until it is set up, the forgot password page directs users to the barangay hall.

## Emergency hotlines

**Status:** waiting for the official hotline numbers from Barangay 172.

The numbers will be added to `config/hotlines.php` once the barangay provides them through our questionnaire. Until then, the hotlines page shows that the numbers are not yet available. Only official numbers from the barangay will be used, since a wrong hotline is dangerous during an emergency.

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

**Page looks old after an update**: the browser is showing a cached copy. Press Ctrl+Shift+R to reload.

## Folders

```
admin/       admin pages (dashboard, incidents, responders, residents, announcements, evacuation, analytics, export)
resident/    resident pages (home, report, my reports, household, evacuation centers)
responder/   responder pages (assignments)
api/         json for the live map
config/      db connection, mail settings template, hotlines list
includes/    auth helpers, shared layout, incident helpers, report form, mailer
lib/         PHPMailer (for sending emails)
assets/      css, js (map pin picker, photo previews), logo
uploads/     incident photos (served only through photo.php)
database/    schema.sql (clean, 16 tables) + migrations/
setup/       create_admin.php (run once in the terminal)
```
