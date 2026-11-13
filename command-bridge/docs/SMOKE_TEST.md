# Command Bridge smoke test and TRAINING scenarios (1.0.2.56)

## 1. Automatic smoke test

Run it after every update (and after restoring a backup):

    cd /var/www/commandbridge/app
    sudo -u www-data php bin/smoke_test.php --base-url=https://your-server/ [--insecure] [-v]
    sudo -u www-data php bin/smoke_test.php --only=approvers,signing     # just some sections

Sections: `env`, `approvers`, `signing`, `resources`, `sync`, `pdf`, `http` (needs `--base-url`; `--insecure` accepts a self-signed certificate).
Exit code 0 = all good, 1 = at least one FAIL. Output is `PASS` / `FAIL` / `SKIP` per check with a summary at the end.

**It is safe on the live server.** The TRAINING incident, four test users with positions (IC, PSC, LSC, COML), forms, check-in rows, 218 vehicles and
queue rows are all created inside one database transaction that is rolled back at the end. Nothing is saved, nobody else sees it, the real active
incident is unchanged, and nothing is e-mailed or sent to the gateway. (Database ID counters still move forward; that is harmless.) The script refuses
to run the write scenarios if any table it touches is not InnoDB.

What it checks
- Install: PHP version and extensions, required tables and columns, roles, chain-of-command catalogue (COML -> LSC -> IC), InnoDB, `db.env` permissions,
  official PDF templates, pdftk, sync worker heartbeat and queue health, the public check-in link, and (when sync is configured) a read-only
  connection test to the Check-In Gateway (`bin/external_checkin_ping.php`: trusted HTTPS, clocks, matching key).
- Approvers: every form resolves to the configured position; a vacant LSC climbs to the IC; the signer holding the approving position moves up a level;
  two holders means the signer chooses; nobody in the chain falls back to a TSC admin; IC/UC self-approval rules.
- Signing: a signed form gets its approver automatically, the approver is notified once, and the choice is audit-logged. The ICS 203 style (no signature
  row) is covered. If your Form Permissions do not let the test COML sign an ICS 205, the script says so and simulates the signature.
- Resources: ICS 211 + 218 collector for the 209 (duplicates ignored, released resources excluded, totals).
- Sync: "waiting" counts only what the worker will send; unreachable rows count as stuck; Retry resets them and sets the next attempt to now.
- PDFs: ICS 209, 209-WF (on its own fillable template, every field name checked, with continuation sheet) and 213RR build and are valid PDFs.
- Web: login page, security headers, protected pages need a login, `db.env`, `.sql`, `includes/`, `bin/`, `storage/` are blocked.

The approve / return / reopen buttons are web form posts, so they are covered by the manual scenarios below.

## 2. Manual TRAINING-incident scenarios (about 20 minutes)

Create an incident named `TRAINING - <date>` (it becomes the active incident, so do this out of hours or tell the team) and add one operational period.
Create these users and positions: Ivy (IC), Pat (PSC), Lou (LSC), Cam (COML), Sam (SPUL), Dee (DOCL). Remove the incident afterwards, or end it.

| # | Do this | Expect |
|---|---------|--------|
| 1 | As Cam, fill in and sign the ICS 205 | Approver preselected/assigned as Lou (LSC); Lou gets a notification |
| 2 | Lou opens it, clicks Approve and sign | Status approved; Cam is notified; signatures show both names |
| 3 | Lou clicks Reopen with a reason | Back to draft; both signatures cleared; Cam is notified |
| 4 | Remove Lou from the incident, Cam signs a 205 again | Approver becomes Ivy (IC) |
| 5 | Pat signs an ICS 203 | Approver is Ivy (Pat holds the PSC position, so it moves up) |
| 6 | Cam signs an ICS 213 and an ICS 214 | Approver is Cam's supervisor (LSC, or IC if vacant) |
| 7 | Ivy signs an ICS 201 | No one ranks above: approver is a TSC admin, or Ivy self-approves (logged) |
| 8 | Give two people the LSC position and sign a 205 | No automatic pick; the dropdown asks the signer to choose |
| 9 | Settings > Sign-off Approvers: set ICS 205 to PSC, sign another 205 | Approver is Pat; Reset to defaults restores LSC; both changes are in the Audit Log |
| 10 | ICS 211: check in two engines (same identifier twice), a crew and one person; release one engine in the demob tracker; open the ICS 209 blocks 48-53 and press Refresh | Duplicate counted once, released engine gone, totals right; Preview PDF matches |
| 11 | ICS 209-WF: choose each block 9 status radio and Report Version | Radios are small and select normally; PDF prints on the real 209-WF sheet (4 pages) with the status box and damage rows filled |
| 12 | ICS 213RR: create a request and use Preview PDF / Download PDF | Official form fills in; long text goes on a continuation sheet |
| 13 | Check-in Sync Status: with the gateway reachable press Retry failed items | Stuck items go to "waiting" and are sent on the next timer run (within a minute) |
