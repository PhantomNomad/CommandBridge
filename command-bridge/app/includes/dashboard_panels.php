<?php
declare(strict_types=1);
/*
 * Dashboard panels per position.
 *
 * The dashboard is made of panels. Each position sees a set of them: the panels for "Everyone" plus the panels for
 * every position the person holds in the incident (someone covering two positions gets both sets). A SysAdmin sees
 * them all. "Show everything" on the dashboard (?all=1) shows every panel to anyone; this decides what is shown
 * first, not what people may open (that is Form Permissions).
 *
 * The choices are kept in app_settings.dashboard_panel_map as JSON {"_ALL": [...], "PSC": [...], ...}. The defaults
 * below are used when the setting is empty; database/seed.sql and migration 0006 store the same defaults.
 * Edited on admin/dashboard_settings.php.
 */

const CB_DASHBOARD_EVERYONE = '_ALL';

/** Every panel, in the order they appear on the dashboard. */
function cb_dashboard_panel_catalogue(): array
{
    return [
        'action_items' => ['title' => 'My action items', 'help' => 'Unread notifications and approvals for you or your positions.'],
        'my_forms' => ['title' => 'My forms', 'help' => 'Forms waiting for your approval, your unsigned forms this operational period, and copied forms assigned to you.'],
        'my_214' => ['title' => 'My ICS 214', 'help' => 'Your own activity log for this operational period.'],
        'logistics_queue' => ['title' => 'Resource requests waiting for Logistics', 'help' => 'ICS 213RR requests that Logistics has to order.'],
        'resl_queue' => ['title' => 'Resources Unit intake', 'help' => 'Ordered resources expected, arrived or waiting to be put on the ICS 211 or 218.'],
        'finance_queue' => ['title' => 'Finance/Admin review', 'help' => 'ICS 213RR requests waiting for a Finance/Admin decision.'],
        'copied_review' => ['title' => 'Copied forms requiring review', 'help' => 'Every form copied forward from the last operational period that still needs review.'],
        'closeout' => ['title' => 'Forms to submit before the period ends', 'help' => 'Every form for this operational period that is not signed yet.'],
        'op_form_status' => ['title' => 'Operational period form status', 'help' => 'The status of every IAP form (ICS 202 to 208) and any other form started this period.'],
        'staffing' => ['title' => 'Staffing / position assignments', 'help' => 'Who holds each position this operational period.'],
        'meetings' => ['title' => 'Scheduled meetings', 'help' => 'Upcoming meetings this operational period, with the next one highlighted.'],
    ];
}

/** Built-in defaults (also stored by seed.sql / migration 0006). */
function cb_dashboard_default_panel_map(): array
{
    $command = ['op_form_status', 'closeout', 'staffing', 'copied_review'];
    return [
        CB_DASHBOARD_EVERYONE => ['action_items', 'my_forms', 'my_214', 'meetings'],
        'IC' => $command, 'UC' => $command, 'DIC' => $command, 'PSC' => $command,
        'SITL' => ['op_form_status', 'closeout', 'copied_review'],
        'DOCL' => ['op_form_status', 'closeout', 'copied_review'],
        'RESL' => ['resl_queue', 'staffing'],
        'OSC' => ['staffing'],
        'LSC' => ['logistics_queue'], 'SPUL' => ['logistics_queue'],
        'FSC' => ['finance_queue'], 'PROC' => ['finance_queue'], 'COST' => ['finance_queue'],
    ];
}

/** The saved map (falls back to the defaults). Unknown panel names are dropped. */
function cb_dashboard_panel_map(): array
{
    $valid = cb_dashboard_panel_catalogue();
    $map = null;
    try {
        $raw = get_app_setting('dashboard_panel_map', '');
        $map = $raw ? json_decode((string)$raw, true) : null;
    } catch (Throwable $e) { $map = null; }
    if (!is_array($map) || !$map) { $map = cb_dashboard_default_panel_map(); }
    $out = [];
    foreach ($map as $role => $panels) {
        $role = $role === CB_DASHBOARD_EVERYONE ? $role : strtoupper(trim((string)$role));
        if ($role === '' || !is_array($panels)) { continue; }
        $out[$role] = array_values(array_unique(array_filter(array_map('strval', $panels), static fn($p) => isset($valid[$p]))));
    }
    if (!isset($out[CB_DASHBOARD_EVERYONE])) { $out[CB_DASHBOARD_EVERYONE] = cb_dashboard_default_panel_map()[CB_DASHBOARD_EVERYONE]; }
    return $out;
}

/**
 * Panels for the signed-in user, in dashboard order: Everyone's panels plus those of each position they hold in the
 * incident. A SysAdmin, or ?all=1, gets every panel.
 */
function cb_dashboard_user_panels(int $incidentId, bool $showAll = false): array
{
    $catalogue = cb_dashboard_panel_catalogue();
    $user = current_user() ?: [];
    if ($showAll || !empty($user['is_sysadmin'])) { return array_keys($catalogue); }
    $map = cb_dashboard_panel_map();
    $wanted = $map[CB_DASHBOARD_EVERYONE] ?? [];
    if ($incidentId > 0) {
        foreach (get_user_role_codes($incidentId, (int)($user['id'] ?? 0)) as $role) {
            $wanted = array_merge($wanted, $map[strtoupper((string)$role)] ?? []);
        }
    }
    return array_values(array_filter(array_keys($catalogue), static fn($k) => in_array($k, $wanted, true)));
}
