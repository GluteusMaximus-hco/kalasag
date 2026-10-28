# KALASAG
**A Web-Based Barangay Disaster and Emergency Response System**
for Barangay 172, Zone 17, Malibay, Pasay City

Final project for Application Development and Emerging Technologies, Adamson University

## About

KALASAG (Tanggulan ng Barangay) lets residents report floods, fires, vehicular accidents, fallen trees, and medical emergencies straight to the barangay, and see when help is on the way.

**Residents** report an emergency with a map pin, landmark, description, and up to 3 photos, then follow it through Received, Acknowledged, Responders dispatched, and Resolved. They can message the barangay, set up their household (elderly, PWD, pregnant, bedridden members), and find the nearest evacuation centers. People without an account can still report as a guest and track it with a code.

**Barangay admins** see every report on a live map, update the status, set urgency, dispatch responder teams, and message the reporter. They also manage residents and households, responder teams, announcements, and evacuation centers, and view analytics that can be printed or exported to CSV.

**Responder teams** get their assignments with the map, photos, and directions, then mark when they arrive and when the job is cleared.

**Automatic**
- Reports within 100 m of a household with vulnerable members are flagged as priority
- SMS and email updates: the reporter at every step, the admins on every new report, and the team when it is dispatched
- Forgot password by email
- Emergency hotlines page, open even without logging in

## Members

| Member | Role |
|---|---|
| Negrete, Richbert Zepri | Project Manager |
| Ortiz, Hanz Christer | Lead Programmer & Full Stack Developer |
| Tejadilla, Francis Khonrad D. | Database Administrator |
| De Leon, John Aldrich | System Analyst |
| Gabriel, Ryle Xamuel T. | Documentation |

## Tech stack

- **Back end:** PHP 8 with MySQLi (no framework), MySQL / MariaDB
- **Front end:** HTML, CSS, JavaScript, Bootstrap 5, Bootstrap Icons
- **Maps:** Leaflet.js with OpenStreetMap
- **Charts:** Chart.js
- **Email:** PHPMailer with Gmail
- **SMS:** SMS Gateway for Android
- **Tools:** Ubuntu, Apache, VS Code, Git / GitHub, phpMyAdmin

## How to run it

### Linux (Ubuntu)

```bash
# install php and mysql
sudo apt install -y mysql-server php php-mysql php-gd php-mbstring php-curl

# make the database and its user
cd kalasag
sudo mysql < database/schema.sql
sudo mysql -e "CREATE USER IF NOT EXISTS 'kalasag'@'localhost' IDENTIFIED BY 'kalasag123'; GRANT ALL ON kalasag_db.* TO 'kalasag'@'localhost'; FLUSH PRIVILEGES;"

# make the first admin account
php setup/create_admin.php

# run it
php -S localhost:8000
```

Then open KALASAG on local host, port 8000, in the browser.

### Windows (XAMPP)

1. Copy the `kalasag` folder into `C:\xampp\htdocs\`
2. Start **Apache** and **MySQL** in the XAMPP Control Panel
3. In phpMyAdmin, import `database\schema.sql`, then run the CREATE USER / GRANT line from the Linux steps in the SQL tab
4. Make the first admin account in Command Prompt:

```
cd C:\xampp\htdocs\kalasag
C:\xampp\php\php.exe setup\create_admin.php
```

5. Open KALASAG on local host in the browser (the kalasag folder)

### SMS and email

The system runs without them. To turn them on, copy `config/sms.example.php` to `config/sms.php` and `config/mail.example.php` to `config/mail.php`, then fill in the SMS Gateway login and the Gmail app password. Both files are kept off GitHub.
