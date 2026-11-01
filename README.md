# ERF Track — PHP edition

A small-team calendar for multiple projects, with separate Dev and Live task boards. This version runs on **PHP 8.2+**, including shared hosting with cPanel. No Node.js server, Docker or Composer is needed in production.

## Deploy with cPanel

Use the prepared **cPanel release ZIP** and its separate **private installation key**. Upload/extract the ZIP into an empty subdomain document root, enable PHP and HTTPS in cPanel, then open `https://your-subdomain/install.php`.

The browser installer checks database support, connects to your selected database, creates the first administrator and locks itself. Choose SQLite if the host offers `pdo_sqlite`, or create a MySQL/MariaDB database and user in cPanel and select that driver. No tables need to be created manually.

**Read [CPANEL-GUIDE.md](CPANEL-GUIDE.md) for the complete upload-and-install instructions.** The guide uses `erftrack.totalclient.xyz` as the example domain. Other subdomains work with their own exact public origin.

The release layout is intentionally different from the source checkout:

```text
Subdomain document root/
  index.php
  index.html
  install.php
  .htaccess
  assets/
  app-private/
    .htaccess
    php/
    bin/
    setup-key.php          # generated hash; removed after installation
    config.local.php       # created by installer; keep private
```

The raw GitHub source ZIP is for developers. For a ready-to-upload cPanel layout, use the packaged cPanel release. Never upload the installation-key text file into a public folder.

## Project and workflow behaviour

- The header project picker starts with **Easy Retailflow** and has **Add new**.
- The **Dev / Live** toggle chooses separate entries for that project.
- Every task/subtask is **Issue** or **Feature**. New subtasks default to their parent's category but can be classified independently. Client/internal origin is inherited.
- Both environments have the five statuses **Pending, Working on, Up for testing, Testing, Done**.
- **Dev Issue → Done:** a linked **Pending** Live entry is created automatically, ready for independent production verification. Dev completion/history is retained.
- **Dev Feature → Send feature to Live:** the checkbox creates its linked Pending Live entry without changing Dev progress.
- Promoting a parent includes its subtasks. A child sent first uses the linked Live parent if present; otherwise it starts as a standalone Live entry. Sending the parent later groups existing Live children without resetting them.
- Repeated sends are idempotent: no duplicate Live entries and no overwritten Live progress. Reopen the existing Live entry for another test cycle.
- The linked-entry control shows the opposite environment's current status and lets you jump to it.
- Entries created directly in Live can remain independent.

Meeting records also belong to a project/environment. Team members are shared across projects; this first version gives every signed-in team member access to the shared calendar and its projects. Only administrators can create logins.

## Other features retained

Coloured monthly task bars; owners and client labels; red unstarted age indicators; actual start dates recorded only when work begins; expandable subtasks; separate pause periods and reasons; immutable deadline-extension history; in-app overdue reminders; meeting minutes, optional duration and Held markers; by-person grouping; overlap warnings; mobile layouts; Excel export and JSON backup; 30-day remembered login.

Pause is separate from workflow status, so pausing Testing and resuming it returns to Testing. Pausing does not extend the deadline. Active subtasks pause with a parent; independently paused subtasks are not automatically resumed with it.

Excel exports include all projects/environments in Tasks, Meetings, Team, Activity, Pauses and Projects sheets. Login secrets are excluded. JSON and Excel exports are calendar records, not full account/database restores.

## Runtime requirements

- PHP **8.2 or newer** with PDO and **pdo_sqlite** or **pdo_mysql**.
- HTTPS for the public app; the exact public origin has no trailing slash.
- Apache/LiteSpeed rewrite support for the cPanel release's `.htaccess` (standard on most cPanel hosting).
- SQLite needs a writable private directory **outside public_html**. MySQL/MariaDB needs a database user with privileges to create/update the app's prefixed tables.

The PHP app uses native password hashing and hashed persistent session tokens. Browser installer credentials are protected by a separate high-entropy installation key and CSRF token. The installer requires HTTPS except on localhost and locks after private configuration is created. Teammate-generated temporary passwords must be changed on first sign-in.

MySQL/MariaDB tables are prefixed `erf_` to avoid ordinary generic table names. Prefer a dedicated database. The app's bounded document supports 50 projects, 5,000 task entries, 5,000 meetings and up to 1.5 MB of calendar JSON; plan a larger database design before those limits are reached.

## Private configuration and data

The installer saves `app-private/config.local.php` in a cPanel release. Keep it during updates. It stores connection settings, not the administrator's plaintext password. MySQL credentials necessarily remain in this protected configuration file. SQLite data defaults to a private folder outside `public_html`, normally `/home/account/erf-track-data`.

Do not commit configuration, installation keys, databases or backups. `.gitignore` excludes them. For MySQL backups, use cPanel Backup/phpMyAdmin. The included `bin/backup.php` makes consistent SQLite snapshots and retains the newest 14 backup files; it can be invoked through cPanel Cron Jobs using your host's PHP binary path. Full restore is a developer/hosting operation.

## Migrating from the Node version

The old Node source is preserved by the `node-v1-before-php` Git tag. Its SQLite authentication schema is not the PHP schema. Do not replace a PHP database with an old Node database file.

For developer-assisted migration, export the old calendar JSON, initialise an empty PHP calendar, then run `php bin/import-legacy.php /private/path/calendar-backup.json` in a controlled development/administrative environment. This is optional and is not required for a fresh cPanel install. It imports records/history, assigns legacy tasks to the default project's Dev board as Features, and keeps member IDs for task ownership. Recreate PHP login accounts and match members by email. Old scrypt login passwords are not automatically converted.

## Development only

Node.js **22.13+** and npm are needed only to modify/rebuild the frontend. The generated `public/index.html` and `public/assets/` are included in the source/release, so the hosting server does not need to build them.

```sh
npm ci
npm run check
npm run build
npm test
```

Tests also require PHP on PATH, or set `PHP_BIN` to the executable path. PHP tests cover state transitions, separate projects and environments, issue auto-promotion, explicit feature promotion, idempotency, subtasks, pauses, deadline history, authentication, account roles, request-origin checks, persistence, browser installer protection and legacy import. Excel tests cover actual XLSX round trips, types and timezone boundaries. The automated backend tests use SQLite; validate the MySQL connection and workflow on your actual hosting account when selecting MySQL.

To run a development checkout, copy `.env.example` to a private `.env`, set the local origin and a strong first-admin password, and use:

```sh
php bin/install.php
php -S 127.0.0.1:8080 -t public php/router.php
```

For source-layout browser installation instead, create a private root `setup-key.php` containing `<?php return hash('sha256', 'YOUR_UNIQUE_LONG_INSTALL_KEY');`. Keep the matching key private; never use a shared example key. This activation file is ignored by Git.

Build a ready-to-upload cPanel package and a separate private activation key:

```sh
python tools/package-cpanel.py --output /private/path/ERF-Track-PHP-cPanel.zip --key-output /private/path/installation-key.txt
```

Both output paths must be outside the public web root. The package contains the activation key's hash; the raw key stays in the separate text file. Use the key matching the package.

`nginx.conf` remains an optional PHP-FPM example for server administrators; it is not needed for ordinary cPanel installation. Production no longer uses `server.mjs` or a persistent Node service.

## Selected-task Google Sheets reporting

Open task details and select **Sync to Google Sheets** for each entry to include. Dev, Live and subtasks are independent. An administrator creates/revokes the read-only connection key in **Export → Google Sheets sync**. The supplied bound Google Apps Script refreshes a dedicated tab about every five minutes. See [GOOGLE-SHEETS.md](GOOGLE-SHEETS.md) for the existing-install update and Google authorization steps.

To package an existing-install update after building: `python tools/package-update.py --output /private/path/ERF-Track-Google-Sheets-Update.zip`. This package excludes configuration, databases, installation keys, installers and `.htaccess`.
