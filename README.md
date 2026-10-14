# ERF Track

A small-team calendar for Easy Retailflow: one monthly timeline for internal features, client requests and meetings.

## Features

- Coloured task bars, client/internal labels, owners and month navigation.
- Added dates are recorded automatically; actual start dates remain empty until work begins.
- Expandable subtasks with optional planned dates and separate owners.
- Pause/resume periods, reasons, linked blocking tasks and parent/subtask behaviour.
- Deadline extensions preserve each prior commitment, reason and acting person.
- In-app overdue reminders; acknowledging a reminder keeps the overdue flag visible.
- Meetings, explicit Held markers, optional duration, minutes and task creation from notes.
- By-person view, overlapping-commitment warnings and personal/status filters.
- Responsive layout with pinned task names and a horizontally scrollable timeline on phones.
- Excel export for all records and a separate JSON calendar backup.
- Local accounts, administrator-created logins, mandatory initial password changes and 30-day remembered sessions.

React and Vite build the frontend. A Node.js server provides authentication, shared data and static files. SQLite stores records on disk. Docker is optional; nginx provides HTTPS and the public reverse proxy.

## Requirements

- Node.js **22.13 or newer** with `node:sqlite` support.
- npm, nginx and a valid HTTPS certificate for production.
- A persistent directory writable by the app's operating-system user.
- Python 3 only if using the optional bootstrap helper.

## Direct Node/nginx deployment

These instructions use `/opt/erf-track` and `erftrack.totalclient.xyz`. Adjust paths and domain to your server. This repository contains source code only; existing task and account data must be transferred separately.

### 1. Install and build

```sh
git clone https://github.com/hasindu-7/ERF-Track.git /opt/erf-track
cd /opt/erf-track
npm ci
npm run check
npm run build
```

### 2. Configure the app

Generate a private environment file and temporary administrator credentials:

```sh
python3 bootstrap.py --directory /opt/erf-track \
  --origin https://erftrack.totalclient.xyz \
  --email your-admin-email@example.com --name "Your Name"
```

The helper creates `.env`, a `data/` directory and `bootstrap-access.txt`, all locally. It does not overwrite an existing `.env`. Credentials are never printed. Open `bootstrap-access.txt` privately, then choose a new password on first sign-in. Do not commit or publish either file.

Alternatively, copy `.env.example` to `.env`, set your values and generate a unique `ADMIN_PASSWORD` of at least 16 characters. The initial administrator is created only for a fresh database; bootstrap settings do not reset existing users.

```env
APP_ORIGIN=https://erftrack.totalclient.xyz
HOST=127.0.0.1
PORT=3000
DATA_DIR=/opt/erf-track/data
```

`APP_ORIGIN` must match the browser's public origin exactly, without a trailing slash, because it controls cookies and request-origin checks. Use `http://127.0.0.1:3000` for a local test. `HOST=127.0.0.1` keeps a direct Node process behind nginx.

Start manually to check the configuration:

```sh
npm start
```

`npm start` loads `.env`. Confirm `http://127.0.0.1:3000/healthz` returns `{"ok":true}`. The frontend alone is not sufficient: **run `server.mjs`**, which serves the frontend and implements authentication and database access.

### 3. Keep Node running

Install the example [`deploy/erf-track.service`](deploy/erf-track.service), update its Node executable path if needed, and run the app as a dedicated unprivileged user. That user must be able to read the app and `.env` and write `DATA_DIR`.

```sh
sudo cp deploy/erf-track.service /etc/systemd/system/erf-track.service
sudo systemctl daemon-reload
sudo systemctl enable --now erf-track
sudo systemctl status erf-track
```

The example assumes a pre-created `erftrack` user and group. Provision them according to your server's account policy and set appropriate ownership before starting the service. If you use PM2 instead, run `server.mjs` with the same environment and configure PM2 startup persistence.

### 4. Configure nginx and HTTPS

[`nginx.conf`](nginx.conf) is a complete example for `erftrack.totalclient.xyz`, proxying to `127.0.0.1:3000`. Point the domain's A record at the target server, issue a certificate covering that hostname, then install and enable the nginx configuration.

```sh
sudo nginx -t
sudo systemctl reload nginx
```

Keep other projects' server blocks and ports in place. The certificate paths in the example must exist before nginx can load the HTTPS block. The ACME webroot example is `/var/www/retailflow-acme`. Configure your certificate tool's renewal timer on the target server. If Cloudflare proxying is enabled, use Full (strict) TLS mode.

## Docker deployment (optional)

Generate `.env` with the bootstrap helper, then:

```sh
docker compose build app
docker compose up -d app
docker compose ps
```

Compose overrides `HOST`, `PORT` and `DATA_DIR` for the container and publishes only `127.0.0.1:3086`. For Docker, change nginx's upstream from `127.0.0.1:3000` to `127.0.0.1:3086`. The bind-mounted database directory is `./data`. It must be writable by container UID/GID **1000**; set ownership appropriately before starting if your host user has a different ID.

Normal updates use `docker compose up -d --build --no-deps app`. Keep `.env` and the data directory. The included backup and certificate timer files are **Docker-specific examples** using `/opt/erf-track`; adapt them to your deployment or use the direct-Node backup command below.

## Migrating existing records

The repository excludes databases and credentials. To preserve existing tasks, meetings, users and passwords, move a **consistent SQLite backup** from the current deployment to `DATA_DIR/retailflow.sqlite` on the new server.

1. Back up the older system at the destination domain before replacing it.
2. Create a current SQLite backup using `backup.mjs` (see below), or stop writes before taking a final backup if you are switching the team to the new server.
3. Stop the destination app. Preserve any existing database and its WAL/SHM files separately before restoring.
4. Place the selected backup at `DATA_DIR/retailflow.sqlite`. Do not reuse unrelated WAL/SHM files with a restored database.
5. Set ownership for the app user and start the destination app with its new `APP_ORIGIN`.
6. Verify records, accounts, HTTPS and backups before switching DNS/team traffic. Users sign in again on the new domain.

Do not copy only a live `retailflow.sqlite` file while writes are active: current records may be in its WAL. Calendar JSON exports and Excel files are useful for review, but the UI does not currently provide a restore/import operation. For full account-preserving migration, use the consistent SQLite backup.

## Accounts and security

Open **Team**, add a name and email, then use **Create login** as the administrator. Share temporary credentials privately. Each user must change their temporary password on first sign-in. No emails are sent. Team names may exist for task assignment before a login is created.

All signed-in team members can edit the shared calendar. Only administrators can create logins. Passwords are salted and scrypt-hashed. Session tokens are stored as hashes, with HttpOnly/SameSite cookies and Secure cookies over HTTPS. Login attempts are rate limited; stale calendar writes are rejected through revision checks.

The sign-in form's **Remember me for 30 days** is checked by default. Unchecking it creates a browser-session cookie with a 24-hour server lifetime. Password changes invalidate the user's other sessions. Use the account menu to change a password and Sign out to invalidate the current session.

## Exports, backups and operations

- **Export → Export to Excel (.xlsx):** all tasks and subtasks, meetings, team records, activity and pause details, across all months. Dates and meeting times use Asia/Colombo. Passwords and session tokens are excluded.
- **Export → Download JSON backup:** calendar records and history; account password hashes are not part of this export.
- `backup.mjs`: a consistent SQLite backup, including accounts, with the newest 14 backup files retained in `DATA_DIR/backups`.

For direct Node deployment, load the same `.env` and schedule this command as the app user:

```sh
cd /opt/erf-track
node --env-file=.env backup.mjs
```

For Docker:

```sh
cd /opt/erf-track
docker compose exec -T app node backup.mjs
```

Configure a daily scheduler and copy backups off-server for recovery from server loss. Keep backups private because they contain account hashes and session data. The app's current limits are 2,000 tasks, 2,000 meetings and 1.5 MB of calendar JSON. Plan a row-level database migration before reaching those limits.

## Verification

```sh
npm ci
npm run check
npm run build
npm test
```

Tests cover task transitions, subtask scheduling, pause propagation, immutable deadline history, meeting states, authentication, account permissions, persistence across restart, CSRF protection, remembered-login expiry, and Excel export round trips. Test databases and generated build files stay outside Git.
