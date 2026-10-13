# KALASAG
Barangay Disaster and Emergency Response System for Barangay 172, Zone 17, Malibay, Pasay City.
Stack: PHP + MySQLi, Bootstrap 5, Leaflet.js + OpenStreetMap, Chart.js

## Setup (Ubuntu + Apache)

```bash
# 1. copy the project to apache
sudo cp -r kalasag /var/www/html/
sudo chown -R www-data:www-data /var/www/html/kalasag

# 2. make the database + app user
sudo mysql < /var/www/html/kalasag/database/schema.sql
sudo mysql -e "CREATE USER IF NOT EXISTS 'kalasag'@'localhost' IDENTIFIED BY 'kalasag123'; GRANT ALL ON kalasag_db.* TO 'kalasag'@'localhost'; FLUSH PRIVILEGES;"

# 3. make the first admin account (asks for name, username, password)
cd /var/www/html/kalasag && php setup/create_admin.php

# 4. open it
xdg-open http://localhost/kalasag/
```

No Apache? From inside the project folder: `php -S localhost:8000` then open http://localhost:8000

The database starts clean. The only starting data is the 5 incident types (flood, fire, vehicular accident, fallen tree, medical emergency) since the report form needs them. Residents sign up on the register page.

Heads up: re-running `schema.sql` wipes EVERYTHING in the db.

## Updating an existing database

If your db was made with an older schema.sql, run the migration instead of re-running schema.sql (this keeps your data):

```bash
sudo mysql kalasag_db < database/migrations/001_email_and_password_reset.sql
```

## Forgot password by email (Gmail)

1. Make a separate Gmail for the system (not someone's personal one)
2. Turn on 2-Step Verification, then go to Google Account > Security > App passwords and make one
3. Copy the settings file and fill it in:

```bash
cp config/mail.example.php config/mail.php
nano config/mail.php
```

Put the Gmail in MAIL_USERNAME and the 16-letter app password (no spaces) in MAIL_PASSWORD.
`config/mail.php` is in .gitignore so the password doesn't end up on GitHub.

Until mail.php exists, the forgot password page just tells people to go to the barangay hall.

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
database/    schema.sql (clean, 16 tables)
setup/       create_admin.php (run once in the terminal)
```

## Done so far (front end + core back end)

Every screen in the scope is built and saves to the real database.

**Residents** (`resident/`)
- Home: report button, recent reports, announcements, 3 nearest evacuation centers
- Report an emergency: incident type, GPS pin (drag to adjust), landmark, description, up to 3 photos
- My reports + report tracking page: 4-step status, timeline, photos, map, messages with the barangay, cancel while still pending
- My household: address, map pin, elderly/PWD/pregnant/bedridden, Data Privacy Act consent
- Nearby evacuation centers: sorted by distance from GPS or the household pin, directions links

**Guests** (no account)
- `guest_report.php`: same report form, gives a tracking code (1 report per 2 minutes per browser)
- `track.php`: check status + message the barangay with the code (locks after 10 wrong codes)

**Admin** (`admin/`)
- Dashboard: live map, counts, needs-attention queue (links to each incident)
- Incidents: filters (status, type, date, priority, search), CSV export
- Incident page: status updates with remarks, urgency tag, priority flag, dispatch (matching teams first), messages, timeline
- Responders: create team logins, incident types they handle, availability, deactivate
- Residents & households: verify, reset password (for people without email), deactivate, household map
- Announcements, evacuation centers (with map pin and quick open/full/closed)
- Analytics: per month, by type, by status, response times, team table, print report, CSV

**Responders** (`responder/`)
- Assignments list + assignment page: directions, "We've arrived", "Mark as cleared" with notes, messages

**Automatic**
- Reports within 100 m of a household with vulnerable members are flagged as priority
- Photos are checked (real images only), resized, and their GPS/EXIF data is removed
- Photos are only shown through `photo.php` to people allowed to see that report

**Still to do for the back-end review:** SMS + email notifications, ML urgency (if time permits)

## Extra setup for photos (do this once)

```bash
# lets PHP resize photos and strip their hidden GPS data
sudo apt install php-gd

# PHP only allows 2 MB uploads by default, phone photos are bigger
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 6M/; s/^post_max_size.*/post_max_size = 20M/' /etc/php/*/apache2/php.ini
sudo systemctl restart apache2
```

Photos are saved in `uploads/incidents/`. The `.htaccess` there blocks direct access, but Apache on Ubuntu ignores .htaccess by default. To turn it on, add this inside `/etc/apache2/sites-available/000-default.conf` (before `</VirtualHost>`) and restart Apache:

```
<Directory /var/www/html/kalasag/uploads>
    Require all denied
</Directory>
```

Even without it, photo file names are random and the page only ever links to `photo.php`.

## Emergency hotlines

Put the barangay's REAL numbers in `config/hotlines.php` (from the barangay questionnaire). Until then the page says the barangay hasn't added them yet. Don't guess numbers.

## Next up
SMS + email notifications, then the ML urgency tag if time permits.
