# Selected tasks → Google Sheets

## 1. Update the existing cPanel app

Use **ERF-Track-Google-Sheets-Update.zip**, not a fresh installation ZIP.

1. Back up the app folder and its database using your hosting backup. For SQLite, get a consistent snapshot using the existing PHP backup helper/hosting support; copying an active WAL database alone is not a full backup.
2. In File Manager open the existing subdomain document root (where `index.php` is).
3. Upload and extract the update ZIP directly there, replacing matching application files. Keep both `assets/` and `app-private/php/` at their existing levels. Do not put the ZIP inside a nested folder.
4. Keep `app-private/config.local.php`, your `.htaccess` and private database folder. The update ZIP contains none of those files, credentials, databases or installers. **Do not rerun install.php.**
5. Remove the uploaded ZIP, refresh the site with Ctrl+F5, and sign in as administrator.

Existing tasks default to unselected. The app creates the new `erf_integrations` table automatically when the connection is first used, with the existing database credentials. They need CREATE TABLE permission (already required by the app).

## 2. Create a read-only key

In ERF Track, open the **Export/download menu → Google Sheets sync → Create connection key**. Copy the key immediately; it is only shown once. Close the panel when finished.

Only administrators manage the key. Teammates can select/unselect tasks in their details. This is a shared team selection, not a separate per-user report. One connection covers the selected tasks across all projects and environments.

## 3. Connect the Google Sheet

Open the new Google Sheet using the Google account that will own the automatic refresh.

1. Open **Extensions → Apps Script**.
2. Replace the starter `Code.gs` content with the provided **ERF-Track-Google-Sheets.gs** script. It can also be downloaded from the app's sync panel.
3. Save. Choose **setupERFTrack** in the function picker and click Run.
4. Google asks for authorization limited to this bound spreadsheet, fetching the protected feed and scheduling refreshes. Review and authorize the script under your own account. If Workspace policy blocks it, ask your Google administrator to approve it.
5. Return to the sheet for the prompts. Enter `https://erftrack.aradhana.au` (or the exact origin of your installation), then the private connection key. The key belongs in the prompt, **never in a cell**.
6. The script creates its managed **ERF Track** tab, fetches selected tasks, and schedules refreshes about every five minutes. No Apps Script web-app deployment, public sheet, API project, cPanel cron or terminal is needed.

If a tab named ERF Track already exists but has never been managed by this script, rename it first. Setup refuses to overwrite an unrelated existing tab. Put manual notes in other tabs; the managed tab is replaced on each successful refresh. Run setup under one trusted Google account to avoid duplicate triggers from other accounts.

## 4. Choose exactly what is shared

- Open a task's details and tick **Sync to Google Sheets**.
- Parents and subtasks have independent checkboxes. Selecting a parent does not select children; a selected child does not expose its unselected parent's details.
- Dev and Live entries are independent. Newly promoted Live copies start **unchecked**, even if their Dev source is checked.
- Updates to selected tasks are reflected on the next successful refresh. Unchecking removes the row on that refresh. Finished tasks stay included until unchecked.
- All selected entries appear in one management report with exactly six columns: Date, Task, Deadline, Deadline extended, Status, Reason / blocker. Date is the added date; Deadline keeps the original commitment and Deadline extended shows the latest revised date. Status highlights overdue or paused work. Reasons show the active pause blocker, otherwise the latest deadline/reopening explanation. Full history, owners, client fields, general notes, IDs, emails and technical fields are excluded. Duplicate task names are qualified by project/environment so management can tell them apart. Unfinished entries appear before Done, ordered by current deadline.
- Hover the Task ID header cell for the last completed refresh time. Use **ERF Track → Refresh now** in the sheet to refresh manually after reopening it.

Sheet edits do not update ERF Track. Treat the sheet as a report and edit task data in the app.

## Privacy and maintenance

Anyone who can view the sheet can view the selected task names, dates, status and concise reasons. Restrict sharing accordingly. Trusted spreadsheet editors can inspect the bound Apps Script and its Script Properties, including the read-only key. Do not grant edit rights to people who should not have that key. Creating this report does not make your ERF Track site or the sheet public.

The server stores a SHA-256 hash of the connection key. It accepts the raw key only through the `X-ERF-Sheets-Key` HTTP header on `/api/google-sheets/feed`, never a URL parameter. This credential cannot read the main calendar API, change tasks, or manage users. It is independent of your sign-in session, so it does not expire with your 30-day login.

- **Replace key** in the app invalidates the old key immediately. Run setup again in the sheet using the new key to restore automatic refresh.
- **Disable connection** blocks future feed reads. **Stop automatic refresh** in the sheet removes the current Google user's refresh trigger. Neither operation erases previously copied rows; delete those rows/tab separately if required.
- Errors such as an unreachable app, revoked key, invalid feed or HTTP error preserve the previous rows. Check Apps Script **Executions** and the header refresh time before relying on the report. Unchecked rows remain in an old snapshot until the next successful refresh.
- The app panel's “Last successful feed request” is a server fetch timestamp, not proof that Google finished updating the sheet.

## Developer validation

`npm test` covers opt-in isolation, independently selected subtasks and Live entries, authenticated feed, admin-only keys, rotation/revocation, zero selected tasks, formula escaping, stale-row removal, and preservation on fetch failures. The script is also tested with mocked Google services; actual Google authorization and time-trigger execution must be confirmed in the linked Google account.

Official references: [URL Fetch](https://developers.google.com/apps-script/reference/url-fetch/url-fetch-app), [scheduled triggers](https://developers.google.com/apps-script/reference/script/clock-trigger-builder), [Script Properties](https://developers.google.com/apps-script/reference/properties/properties-service).
