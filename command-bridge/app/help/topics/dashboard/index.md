# Dashboard

The dashboard is your starting page for the active incident. It shows the panels that matter for **your position**: a few that everyone gets, plus the ones for each position you hold. If you cover two positions, you see both sets. It refreshes by itself every two minutes, or press **Refresh**.

## The panels

| Panel | Shows | Normally shown to |
|---|---|---|
| My action items | Your unread notifications: approvals, returned forms, meeting reminders, messages | Everyone |
| My forms | Forms waiting for **your** approval, forms you prepared that aren't signed yet, and copied forms assigned to you | Everyone |
| My ICS 214 | Your activity log for this operational period, and a button to open or start it | Everyone |
| Scheduled meetings | The upcoming meetings for this operational period; the next one is highlighted | Everyone |
| Resource requests waiting for Logistics | ICS 213RR requests that Logistics has to order | LSC, Supply |
| Resources Unit intake | How many ordered resources are expected or waiting to be put on the ICS 211 or 218 | RESL |
| Finance/Admin review | How many ICS 213RR requests are waiting for a Finance/Admin decision | FSC, Procurement, Cost |
| Copied forms requiring review | Every form copied forward from the last operational period that still needs review | IC/UC, Deputy IC, PSC, SITL, DOCL |
| Forms to submit before the period ends | Every form for this operational period that isn't signed yet | IC/UC, Deputy IC, PSC, SITL, DOCL |
| Operational period form status | The status of every IAP form (ICS 202 to 208) and any other form started this period | IC/UC, Deputy IC, PSC, SITL, DOCL |
| Staffing / position assignments | Who holds each position this operational period | IC/UC, Deputy IC, PSC, OSC, RESL |

A SysAdmin sees every panel. An administrator can change who sees what in [Dashboard Panels](help:admin/dashboard_settings).

## Show everything

**Show everything** at the top shows every panel, whatever your position. **Show my panels only** goes back. The panels only decide what is shown first; what you can open is set in [Form Permissions](help:admin/form_permissions).

## Operational period buttons

If you can manage operational periods, the buttons at the top let you **Edit Current Operational Period**, start a **New Operational Period** ([help](help:operational_periods/new)) and open **Position Assignments** ([help](help:operational_periods/assignments)).

## No active operational period

If the last operational period has ended and no new one has started, the dashboard says so. Forms are tied to an operational period, so start a new one before working on the next period's forms.
