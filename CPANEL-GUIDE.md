# Install ERF Track with cPanel — no terminal required

Use PHP **8.2 or newer**. The upload ZIP already contains the built interface. You do not need Docker, Node.js, npm, Composer or command-line access on your server.

## 1. Create the subdomain

In **cPanel → Domains**, create `erftrack.totalclient.xyz` (or your chosen subdomain). Give it its own document root, for example `/public_html/erftrack`. Keep other projects' folders and domains in place.

If an older app is already using the domain, back it up first and use an empty folder for this installation. Do not extract the new app over unknown existing files.

Point the subdomain's DNS record at this hosting account's server. Use the IP provided by your hosting company. Enable **SSL/AutoSSL** for the subdomain using cPanel's SSL/TLS Status page. Open the installer through HTTPS. If Cloudflare proxies the domain, use Full (strict) after the origin certificate is valid.

## 2. Select PHP and the database extension

In **MultiPHP Manager** or **Select PHP Version**, choose PHP 8.2 or later for this subdomain.

You need PDO plus one database driver:

- **SQLite / pdo_sqlite:** easiest. The installer creates the database file automatically. No database user is needed.
- **MySQL / pdo_mysql:** use this if your host does not provide SQLite. MariaDB also works through this driver.

The installer shows the available drivers. If neither is available, enable the relevant extension in cPanel or ask the host to enable it.

### If using MySQL / MariaDB

Use **cPanel → MySQL Databases / Database Wizard**:

1. Create a new database for ERF Track.
2. Create a database user with a strong password.
3. Add that user to that database and grant **All Privileges**.
4. Note the **complete names including the cPanel prefix**, such as `account_erftrack` and `account_erfuser`.

You do not have to create any tables. The installer creates tables named `erf_users`, `erf_sessions`, `erf_boards` and `erf_login_attempts`.

The database host is usually `localhost`; confirm it with your host. Port 3306 is the default. Skip this entire database-account step for SQLite.

## 3. Upload the cPanel release

You need two separate files:

- The **ERF-Track-PHP-cPanel.zip** release.
- The private **installation-key.txt** supplied alongside it.

In **File Manager**:

1. Open the subdomain's document root.
2. Upload the cPanel ZIP.
3. Extract it **directly into that document root**.
4. Confirm that `index.php`, `install.php`, `index.html`, `.htaccess`, `assets/` and `app-private/` are immediately inside the root.
5. Enable **Show Hidden Files** in File Manager settings to see `.htaccess`.

Do not upload the installation-key text file to a public directory. Do not upload `node_modules`, an old `.env`, a database or any access-credentials file from another installation.

The GitHub source ZIP is for developers. Use the prepared **cPanel release ZIP** for this flat upload layout.

## 4. Run the browser installer

Open:

```text
https://erftrack.totalclient.xyz/install.php
```

Enter:

1. The private installation key.
2. The exact public URL, with **no trailing slash**.
3. SQLite or MySQL/MariaDB.
4. MySQL connection details, if applicable.
5. Your administrator name, email and password.

Choose an administrator password of **16–72 characters**. Use the same password in both confirmation fields.

For SQLite, keep the suggested private data directory unless your host requires a different allowed folder. It should be outside `public_html`, such as `/home/youraccount/erf-track-data`. The installer needs permission to create/write there.

Click **Install ERF Track**. Setup creates the database tables, default **Easy Retailflow** project and administrator account. It saves configuration in `app-private/config.local.php`. Your administrator password is stored as a hash in the database, not as plaintext in that file. MySQL connection credentials must remain in this protected private configuration file.

When installation completes, the installer is locked and its activation key is deleted. Sign in to the app using the administrator email and password you chose. You may also delete `install.php` through File Manager as final cleanup.

## 5. Use projects, Dev and Live

- The header project picker selects the current project. Use **Add new** for another project.
- **Dev / Live** switches between separate entries in the selected project.
- Each task or subtask has a workflow status: **Pending → Working on → Up for testing → Testing → Done**.
- Pause is separate from the workflow status. Resuming keeps the previous status.
- Tasks are categorised as **Issue** or **Feature**. Subtasks default to their parent's category but can be Issue or Feature independently; their client/internal origin is inherited.
- Marking any **Dev Issue** Done creates its linked Live entry in **Pending**. Promoting a main task includes its subtasks. The Dev entry and its history remain in place.
- A **Dev Feature** has a **Send feature to Live** checkbox. Selecting it creates linked Pending Live entries. It does not change the Dev status.
- Dev and Live then have independent statuses, owners, notes and deadlines. Use the linked-entry button to jump between them.
- Sending the same Dev entry again does not create duplicates or overwrite existing Live progress. If a new round of Live testing is needed, reopen/update the existing Live entry.
- A subtask sent first appears under its Live parent if one exists; otherwise it appears as a separate Live entry. Sending the parent later groups existing Live children without resetting their progress.
- Entries added directly in Live remain independent unless they came from Dev.

Meeting records also belong to the selected project and environment. Team members are shared across projects. Use **Team → Add member → Create login** to give a teammate access, then share their generated temporary credentials privately.

The Export menu provides an Excel workbook and JSON calendar backup. Exports cover all projects/environments, with those fields identified in the workbook.

## Backups and updates

Keep private configuration and database data when updating.

- **MySQL:** use cPanel Backup or phpMyAdmin Export, according to your host's instructions.
- **SQLite:** the database is `erf-track.sqlite` in the private data directory. The optional PHP backup helper can be scheduled in **cPanel → Cron Jobs** if your host permits it. Ask your host for the correct PHP binary path and use the absolute path to `app-private/bin/backup.php`. This does not require a terminal session. It writes consistent backups under the private data folder's `backups/` directory and keeps the newest 14 files.
- Excel and JSON exports are useful calendar snapshots. They do not contain account password hashes and are not full database restores.

Before updating, obtain a consistent database backup. Replace application files from the new cPanel release, retain `app-private/config.local.php` and the private data directory/MySQL database, and refresh the app. Never use the installer to reset an existing database or account.

## Existing Node-version data

This is a PHP backend, not the old Node server. Do not put the old Node SQLite file directly into the new installation: account hashing and database layout differ.

Calendar JSON records can be migrated using the included developer import script into an empty PHP calendar. That is a developer-assisted operation. Node login passwords cannot be automatically reused; create PHP logins and match members by email. Legacy tasks are assigned to the default project's Dev board as Features for review.

## Troubleshooting

| Problem | What to check in cPanel |
|---|---|
| Installer says setup is not activated | Use the prepared release ZIP. It includes a private hashed activation key; the matching key text file is provided separately. |
| Incorrect installation key | Use the key supplied with this exact ZIP, not a key from another release. |
| PHP code appears as text or downloads | Select an active PHP version for the subdomain and ask the host to correct the PHP handler before entering credentials. |
| White page or HTTP 500/503 | Check cPanel Errors / PHP error log. Confirm PHP 8.2+ and the selected PDO driver. |
| MySQL access denied | Check the full prefixed database/user names, password, host and user-to-database privileges. |
| SQLite cannot create a folder/file | Use a private folder allowed by the hosting account and writable by PHP. Do not use 777 permissions. |
| API requests return 404 | Ensure `.htaccess` was extracted into the document root and Apache/LiteSpeed rewrite rules are enabled. Ask the host to enable them if necessary. |
| Page is unstyled or assets are 404 | `assets/`, `index.html` and `index.php` must be together in the subdomain root, not inside an extra nested folder. |
| Invalid request origin / login fails | Check the exact HTTPS URL in `app-private/config.local.php`; it must match the URL used in the browser and have no trailing slash. |
| Setup says already installed | This is intentional. The installer cannot change an existing installation. Use the app to change passwords or ask the developer to repair private configuration. |
| Wrong SSL certificate | Enable AutoSSL for this exact subdomain and check its DNS destination. Do not bypass certificate warnings when entering passwords. |

No nginx editing is normally needed in cPanel. Your hosting provider manages the web server and PHP handler.
