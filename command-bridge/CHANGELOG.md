# Changelog

## Unreleased

* **Phones and tablets, part 2: lists as cards.** On phones, list tables turn into one card per row, and each value
  is labelled with its column name, so nothing needs sideways scrolling. This covers:
  - the dashboard lists
  - the ICS 214 log and everyone's logs
  - ICS 213 messages
  - ICS 230 meetings
  - ICS 211 check-ins
  - the ICS 213RR request lists and item table

  Tables opt in with `class="cb-cards"`; `assets/js/app_shell.js` adds the labels. Other phone fixes:
  - pop-up notifications fit the screen
  - the Form Actions buttons are spaced out
  - the ICS 213RR steps show two per row with their buttons wrapping
  - the form status steps stay in one row on upright tablets

  Tablets and desktops are unchanged. No database changes.
* **Phones and tablets, part 1: the page frame.** The app now has three layouts, chosen by screen width (a tablet
  switches as soon as it is turned):
  - **Desktop (1024 px and wider):** tablets held sideways, laptops and monitors. Same as before.
  - **Tablet (600-1023 px):** most tablets held upright. The side menu hides behind a **Menu** button in a bar at
    the top and slides in over the page; tap a link, the backdrop, the close button or Esc to close it. The page
    gets the full width.
  - **Phone (under 600 px):** the same menu, plus one-column forms, smaller headings, tighter cards, and the status
    banner and Form Actions buttons stacked.

  On phones and tablets, buttons are at least 44 px tall. Fields use 16 px text, so phones don't zoom in when one is
  tapped. Wide tables scroll sideways in their own box instead of making the whole page scroll sideways. New file:
  `assets/js/app_shell.js`. The ECC displays, printing and the PDFs are not affected. No database changes.
* **Signed forms are locked, and "Revise" is the only way to change them.** Before this, forms didn't follow one rule:
  the ICS 205, 206, 208, 210, 215A and 218 could still be changed after they were signed, and the 205A, 207, 211
  and 230 unlocked again once approved (so "Add Meeting" came back on an approved ICS 230). Now every form uses the
  same rule (`includes/form_lock.php`):
  - A form is locked from the moment its preparer signs it, while it waits for approval and after approval. Each form
    page refuses any save, add or delete on a locked form, and its inputs are greyed out.
  - **Revise** (reason required) clears both signatures and opens the form as a draft with the next revision
    number. The approver's "Return for changes" does the same. It replaces "Return for changes" and "Reopen form"
    on every form, including the ICS 202 and 204, which have their own approval steps.
    - Who can revise: the preparer, the approver, anyone who can edit or sign the form, and a SysAdmin. ICS 214:
      the owner, the approver while it waits, and a SysAdmin.
    - Before the change, a PDF of the version being replaced is saved by the new `bin/form_snapshot.php`
      (under `storage/uploads/incidents/<id>/form_revisions/`). A **Revision history** lists each earlier version:
      who revised it and why, who signed and approved it, and a PDF link (`forms/revision_pdf.php`). Revisions are
      audit logged, and the preparer and approver are notified.
  - Still allowed on a locked form:
    - marking an ICS 230 meeting held or cancelled
    - new ICS 213 messages and replies
    - Resources Unit adds to the ICS 211/218
  - The ICS 213RR keeps its own workflow (no change).
  - **New status banner** at the top of every form, in the ICS 213RR style:
    - status ("Waiting for Dana Whitford", "Approved")
    - steps (Prepare, Sign, Approval, Approved)
    - **Next action** box with the right buttons for the person looking: approve and return for the approver;
      change approver and revise for the preparer; just who approved it once it's approved
    - revision history

    A signed form that isn't approved yet says **Not approved yet** and names the approver. The dashboard shows
    "Signed, waiting for approval" instead of "Submitted". The old sign-off box under the form is gone.
  - The ICS 201 and ICS 230 had no way to sign them from their pages; they now get **Sign and submit** in the
    banner. The ICS 204 now records who submitted it.
  - Database: migration `0005_form_revisions.sql` adds `form_records.revision` and the `form_revisions` table.
    Smoke test: checks the migration and that every form page has the lock check, and runs a revise on its test
    form.
  - Style sheets and scripts are now loaded with a `?v=` version stamp, so browsers pick up new styles right after
    an update instead of showing a cached copy (the new banner showed up unstyled until the cache was cleared).
* **Meeting reminders can be answered.** The "Was this meeting held?" reminder said to mark the meeting on the ICS 230,
  but there was no way to do it once the ICS 230 was signed, for meetings in an earlier operational period, or for a
  chair who can't edit the ICS 230. The reminder now opens a new page, `forms/ics230/meeting_status.php`, with
  **Completed** and **Cancelled** buttons and an optional note. The ICS 230 list also shows a **Was it held?** button
  for past meetings. The chair, IC/UC, PSC, anyone who can edit the ICS 230 and a SysAdmin can use it; each change is
  written to the audit log. No database changes. Reminders already sent still link to the ICS 230.
* **ICS 201: clearer message when the form is locked.** A submitted or approved ICS 201 told the Incident Commander
  "You do not have permission to edit this form". It now says the form is submitted (or approved) and locked, and shows the Preview PDF and
  Download PDF buttons, which were hidden along with the edit form, so a locked ICS 201 can still be read.
* **Full help for every ICS form page.** The help for the ICS 201, 202, 203, 204, 205, 205A, 206, 207, 208, 209, 210,
  211, 213, 213RR, 215A, 218 and 230, the Finance/Admin 213RR queue and the Resources Unit queue is now complete. Each
  page covers what the form is for, who prepares it, who gets it, what goes in each block, and how Command Bridge fills
  it in, signs it and prints it. The form guidance is based on the ICS Canada Forms Catalogue (2022), written in our
  own words; no ICS Canada text is copied.
  - New help pages: "Working with ICS forms" (saving, sign-off and default approvers, printing) and the ICS 209-WF.
  - The "Help / Instructions" links on the forms now open the form's help page. The old short instruction pages are
    gone, and the broken link on the ICS 210 now works.
  - No database or code changes beyond the `forms/*/help.php` redirects.

* **ICS 202 now uses the ICS Canada form.** The bundled `assets/pdf/official/ICS202.pdf` was not the ICS Canada
  version (no ICS logo or "ICS 202-CAN", and US-only items). It is replaced with ICS Canada's Form-202.pdf, and the field
  map is updated for it:
  - Check boxes now line up with the ICS Canada items: ICS 208, Map/Chart, Weather, Blank ICS 214.
  - The operational period dates and the preparer's position now print. Both were blank before.
  - Uploaded attachments are listed under "Other Attachments" and still follow the form as extra pages.
  - Long objectives go on a continuation sheet.
  The smoke test checks that the ICS 202 template is the ICS Canada one.
* **ICS 208 and ICS 230 updated to ICS Canada's current files** (posted 2026). The ICS 208 is unchanged in layout and
  field names. The ICS 230 is the new fillable version with 12 rows and different field names; its field map is
  rewritten. Each row is taller now, so less text goes to the continuation sheet.
* **Every form's PDF is now the official ICS Canada form.** Before, only the ICS 202, 205, 205A, 207, 209, 209-WF,
  210, 211, 213 and 213-RR filled the official PDF; the ICS 201, 203, 204, 206, 208, 214, 215A, 218 and 230 were
  redrawn by the app. Now all of them fill the official ICS Canada template, so printouts look like the real forms.
  - Text that does not fit a box is cut at a word with "... (cont.)" and printed in full on a continuation sheet at
    the end. More rows than the form has (resources, check-ins, log entries, meetings, hospitals) continue on extra
    copies of the form, with "Page x of y" on the ICS 214.
  - Box sizes and fonts are read from `assets/pdf/official/fields/*.json`, made by the new developer tool
    `tools/pdf_field_metadata.py`. Run it again whenever an ICS Canada PDF is replaced (see
    `includes/pdf/PDF_EXPORT_METHOD.md`).
  - ICS 218 has no fillable fields on the official PDF, so its text is drawn at the form's own positions and stamped on.
  - ICS 201 maps/sketches and ICS 202 attachments now follow the official form as extra pages (the ICS 202 used to
    switch to the app-drawn version when it had attachments).
  - The ICS 230 "meeting posting" wall sheet is not an ICS form and keeps its own layout.
  - Removed the old app-drawn renderers for the ICS 203, 204, 205, 206, 208 and 218. `forms/ics201/pdf_preview.php` now
    opens the official blank ICS 201.
  - Smoke test: builds every form's PDF, checks that each field name exists on the official template, and checks the
    ICS 218 stamping.
  - The PDFs need `pdftk` on the server (the installer adds it). Without it, these forms show an error instead of a
    PDF.
* **TSC is now SysAdmin, and it is no longer an ICS position.** ICS Canada does not list a Technical Services
  Coordinator, so the application administrator is now called **SysAdmin** everywhere (screens, help, setup, scripts).
  A SysAdmin manages the application and can open and approve every form, as before, but is not part of the incident's
  command structure: it is gone from the position list and chain of command, can't be assigned on Users & Roles, and
  first-run setup no longer assigns the administrator a position or checks them in on the ICS 211.
  - Database: migration `0004_rename_tsc_to_sysadmin.sql` renames `users.is_tsc_admin` to `users.is_sysadmin`, ends
    current TSC position assignments, renames the `TSC` role to `SYSADMIN` and switches it off (kept for history),
    removes TSC from the position catalogue, and changes unread TSC notifications to go to SysAdmins.
    `schema.sql` and `seed.sql` match.
  - **Form security change:** the 20 `TSC` rows in Form Permissions are removed (ICS 201-230 and EXTERNAL_CHECKIN).
    SysAdmins don't need them; they always have full access. No other role changes.
  - The first-run setup page is now `setup/setup_sysadmin.php`. People signed in during the update keep their access.
* **ICS 214 is now one activity log per person per operational period.** Before, everyone shared one ICS 214 that
  showed every person's sign-ins and actions. Now:
  - Each person's log starts when their login is created (Users & Roles), or the first time they open it, and shows
    only their own sign-ins/sign-outs, account changes, form actions, and the meetings marked *Completed* on the ICS 230.
  - Entries are added, never edited or deleted. Each shows who added it. To correct one, add another entry.
  - **Who can open someone else's log:** the Incident Commander / Unified Command, SysAdmins and the person's
    supervisor can read and add entries; the Documentation Unit Leader can only read. All of them can send the owner an
    "update your log" request (notification + audit entry). Everyone else sees only their own.
  - New page **ICS 214 - Activity Logs** (`forms/ics214/logs.php`) lists the logs you may open for the selected period.
  - Only the owner can sign their log. Signing locks it; while waiting for approval it can be returned. **Once approved
    it stays locked**; only a SysAdmin can reopen it.
  - The PDF prints one person's log. Sign-off and approval notifications open the right person's log.
  - The dashboard's form-status table shows only your own ICS 214, and the end-of-period reminder for an unsigned
    ICS 214 goes only to its owner (not to IC/PSC/SysAdmin for every person).
  - **Form security change:** new permission row `ICS214` / `ALL_AUTHENTICATED` (view, create, edit, sign; no
    permission management), so every signed-in user can open and sign their own log. The existing ICS214 rows for IC, PSC,
    OSC and GENERAL_STAFF are unchanged.
  - Database: migration `0003_ics214_per_person.sql` adds `form_214_activity_log.added_by_user_id` and `added_by_name`,
    ties existing logs to the person who prepared them, and adds the permission row. `schema.sql` and `seed.sql` match.
    The old shared log becomes the log of whoever created it.
* **Meetings: "held" means marked Completed.** The ICS 214 used to show "Meeting held" for any meeting whose time had
  passed, even cancelled ones. Now only meetings marked *Completed* on the ICS 230 count. An hour after a meeting's
  start, if it is still Scheduled or Tentative, the chair, the IC/UC and the Planning Section Chief get a dashboard
  reminder to mark it; the reminder disappears once the status changes. Cancelled meetings no longer show as upcoming
  on the dashboard.
* **ECC display: meetings start at the last meeting held.** The Meetings tab scrolls to the most recent Completed
  meeting (labelled *Last meeting held*) so the upcoming meetings are on screen; older meetings are dimmed.
* **Audit Log viewer** (`audit/index.php`), for SysAdmins and IC/UC holders: filter by person, category
  (sign-ins, accounts, forms, settings/other), date range and text; show 25/50/100/250/All rows with paging; plain
  sentences instead of raw action codes. No export. It is in the left menu for IC/UC, and Settings > Audit Log now
  opens it (the old `admin/audit_log.php` redirects there).
* **Fixed: the Resources Unit and Finance/Admin pages could only be opened by SysAdmins.** Their access comes
  from form codes `RESOURCES_UNIT` and `FINANCE_ADMIN`, which were missing from the `forms` table, and no role had
  permission (the Form Permissions page could not save them either). Migration `0002_resources_and_finance_unit_access.sql`
  (applied by `update.sh`) and `seed.sql` add both codes and these default permissions (view, create, edit, submit; no
  permission management):
  - Resources Unit: `RESL` (Resources Unit Leader) and `RESOURCE_UNIT` (Resources Unit staff)
  - Finance/Admin queue: `FSC` (Finance/Admin Section Chief) and `FINANCE_ADMIN` (Finance/Admin staff)

  Other roles can be added in Settings > Form Permissions. The self-check now confirms both pages have a role with access.
* **Fixed: new installs showed a broken sidebar logo.** `seed.sql` set `branding_logo_file` to an uploaded file that only
  existed on the machine it came from. The row is removed. The app now also ignores a saved logo whose file is missing
  and shows the default icon instead. The self-check reports when that happens.
* **ICS Canada branding removed from the web pages.** The ICS Canada logo is no longer shown in the sidebar, and the
  *Display ICS Canada logo* option is gone from Organization & Branding. The browser-tab icon, which was the ICS Canada
  logo, is now a Command Bridge icon (`assets/img/command-bridge-icon.svg`). That icon is also the sidebar logo until an
  organization logo is uploaded; it replaces a blank placeholder image. Messages beside PDF buttons now say
  "Blank PDF template not loaded yet." The form instruction pages keep a short note, "Source: ICS Canada Forms Catalogue."
  (now on all nine). The ICS Canada form templates and the PDFs made from them are unchanged.
  The old setting `branding_show_ics_logo` is simply no longer read; no database change.

## 0.9.0-beta.4

* **In-app help.** Every page has a **? Help** button that opens help for that page in a side panel, so you can read
  it while filling in the form (or open it in a new tab). **Help topics** in the sidebar lists everything. Help is
  written as Markdown files in `app/help/topics/`, one per page, and shown through the app only (signed-in users;
  the sign-in page's help is public). The **Email Delivery** help is complete: which method to choose; step-by-step
  Microsoft Graph (including limiting the app to one mailbox), SMTP OAuth2 for Microsoft 365, Google Workspace/Gmail
  app passwords, hosting providers and local relays; and what each error message means. Every other page has a
  short summary for now. The self-check fails if a page has no help file. See "In-app help" in docs/DEVELOPMENT.md.
* **Email Delivery page:** hints under the fields, and a "How to set up" link for the chosen method.
  - **Settings are now checked before saving**, so a rejected form no longer half-saves (previously an invalid sender
    address was saved anyway). What you typed is kept when a check fails (never secrets).
  - **"No authentication" to a relay on another server** now needs a tick box confirming that the relay only accepts
    mail from this server, and the page shows a warning while such a setup is saved. Relays on this server
    (`localhost`) are unchanged. Sending is not affected, so existing setups keep working.
  - **A password or OAuth2 sign-in is refused with encryption "None"** unless the relay is on this server.
* **Sign-out after 1 hour without activity** (was about 24 minutes, PHP's default, and not reliably enforced). The app
  now enforces the limit itself. It comes from `session.gc_maxlifetime`, which the installer sets to 3600, and
  `update.sh` adds to existing servers (an existing value is kept). The dashboard's automatic notification refresh
  does not count as activity. When the hour is up, an open dashboard goes to the sign-in page instead of staying on
  screen. The sign-in page says why. The self-check shows the current limit. See "Sign-out after inactivity" in
  docs/INSTALL.md.
* **Friendlier message when the sign-in timed out.** Submitting a form after the session expired showed only
  "Invalid CSRF token". It now shows *Your session has timed out* (and that what was entered was not saved), with a
  **Sign in again** button, and goes to the sign-in page by itself after 10 seconds. If the sign-in page itself had
  expired, it simply reloads with a message. A page left over from before signing in again (for example another tab)
  shows *This page is out of date* with a link to reload it. JSON requests get `session_expired` / `page_expired`.
  The self-check (`--base-url`) tests the timed-out case.

No database changes. Update with `update.sh`; it also adds the 1-hour sign-out setting to PHP (an existing value is kept).
The Check-In Gateway is unchanged (0.9.0-beta.2).

## 0.9.0-beta.3

**Check-In Gateway setup** (needs Check-In Gateway 0.9.0-beta.2 for the connection test)
* `install.sh` now asks whether to connect a Check-In Gateway: its address, and whether to generate the shared key here or
  paste the one the gateway installer showed. At the end it tests the connection and asks whether to switch the sync timer
  on (default Yes only if the test passed). The final screen says exactly what to do on the gateway, including the key ID
  and secret when they were generated here (shown on the terminal only, never in the log).
* New `tools/setup_checkin.sh`: the same steps for servers that are already installed. `--test` runs the connection test
  and offers to switch sync on; `--disable` switches it off; with no option it shows a menu (test / change / off).
  Switching on queues the current locations, expected resources and CIR/SAM users (the one-time backfill), sets
  `EXTERNAL_CHECKIN_ENABLED=1` and enables the timer. If any step fails, sync is left fully off.
* New `tools/rotate_checkin_secret.sh`: replaces the shared secret. It pauses sync, creates a new key ID and secret, tells you
  to run `tools/rotate_secret.sh` on the gateway, then tests and resumes. It can also undo, or stop and finish later.
* New `bin/external_checkin_ping.php`: read-only connection test. It checks the settings, trusted HTTPS, the gateway's
  database, the clock difference and the signed key, and explains each failure in plain words. The self-check
  (`bin/smoke_test.php`) runs it when sync is configured, and also checks that the public check-in link is set.
* Both installers make sure network time is on (installing `systemd-timesyncd` if no time service exists), since signed
  requests more than 5 minutes off are refused. Shared check in `deploy/lib/timesync.sh`.
* `docs/EXTERNAL_CHECKIN.md` rewritten as one step-by-step guide, including rotation and what to do with stuck items.

**Fixes**
* **A fresh install did not open the new-incident setup after the first login.** After signing in (password or
  Microsoft), users were sent straight to the dashboard, skipping the check that sends an administrator with no incident
  yet to *Set up incident*. Sign-in now goes through the start page, which picks the right destination.
* **Location QR codes / public links were never shown.** Admin > Locations read `EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL`
  from the process environment instead of `/etc/commandbridge/external_checkin_sync.env`. It now reads the settings file
  (an Apache `SetEnv` still works). The fallback link format and the example file now use the gateway's real address
  format, `https://checkin.example.ca/?location=<token>`.
* **Self-signed installs could lock browsers out.** The app sent `Strict-Transport-Security` on every HTTPS page, so a
  browser that once trusted the site refused it after a certificate change, with no way to proceed. The header is now
  sent only by the Apache site, and only for trusted certificates: Let's Encrypt, and now also your-own-certificate
  installs (mode 3). To turn it back on for an existing install with a trusted certificate, see "Self-signed certificates" in docs/INSTALL.md.
* Installer and updater mark `tools/*.sh` executable; the repository now stores `install.sh` and `update.sh` as executable.

No database changes. Update with `update.sh`.

## 0.9.0-beta.2

* **Fixed: ICS 204 could not be approved.** The Planning Section Chief approval step existed in the code but had no button,
  and it was only processed for users with edit permission, which the standard permissions do not give Planning. A submitted
  ICS 204 now shows an **Approve ICS 204 (Planning Section Chief)** button to the PSC (and TSC/Admin). Approval no longer
  requires edit rights on the form (view access is enough), only works on a *submitted* form, records the approver and time,
  and is written to the audit log. The approver's name and approval time now show on the approved form.
* The Resources Unit Leader (RESL) now has ICS 204 view/edit/submit permission by default (new installs via `seed.sql`; existing installs via migration `0001_ics204_resl_permission.sql`, applied by `update.sh`), matching the on-screen help text.
* Update with `update.sh`; the Check-In Gateway is unchanged (still 0.9.0-beta.1).

## 0.9.0-beta.1 - first public beta

First public release. Earlier builds (1.0.2.x) were private; their history is folded into this baseline.

**Packaging**
* New installer (`install.sh`): installs all packages (including `pdftk-java`), asks for install folder, server name,
  TLS mode (Let's Encrypt / self-signed / own certificate / none), time zone and first administrator, writes the Apache
  site, PHP settings and database, then runs the self-check. Default location `/var/www/commandbridge`.
* Friendlier whiptail (ncurses) dialogs in `install.sh` and `update.sh`, shared code in `deploy/lib/ui.sh`; plain prompts remain for unattended runs.
* New updater (`update.sh`) with automatic database backup and numbered migrations (`database/migrations/`).
* Single `database/schema.sql` + `database/seed.sql` (reference data only: no users, incidents or forms).
* Database settings now live in `/etc/commandbridge/db.env`; names default to `commandbridge`.
* Time zone is configurable (`APP_TIMEZONE`) instead of fixed to America/Edmonton.
* Licensed AGPL-3.0; footer shows version and licence.

**Features carried from 1.0.2.x**
* Automatic approver selection through the chain of command and sign-off workflow.
* ICS 209 resource summary, ICS 209-WF fillable official template.
* Check-in sync status page with retry; login throttle and lockouts; smoke test (`bin/smoke_test.php`).

**Upgrading from a private 1.0.2.x build:** see docs/UPDATING.md (`APP_TIMEZONE` must be added to `db.env`).
