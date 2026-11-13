<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';


if (!function_exists('render_ics_form_status')) {
function render_ics_form_status(string $formCode, ?array $formRecord = null, ?array $preparer = null, ?array $signature = null, array $options = []): void {
    $status = (string)($options['status'] ?? ($formRecord['status'] ?? 'draft'));
    $formTitles = [
        'ICS201' => 'ICS201 - Incident Briefing',
        'ICS202' => 'ICS202 - Incident Objectives',
        'ICS203' => 'ICS203 - Organization Assignment List',
        'ICS204' => 'ICS204 - Assignment List',
        'ICS205' => 'ICS205 - Radio Communications Plan',
        'ICS205A' => 'ICS205A - Communications List',
        'ICS206' => 'ICS206 - Medical Plan',
        'ICS207' => 'ICS207 - Incident Organization Chart',
        'ICS208' => 'ICS208 - Safety Message / Plan',
        'ICS210' => 'ICS210 - Status Change',
        'ICS211' => 'ICS211 - Check-In List',
        'ICS213' => 'ICS213 - General Message',
        'ICS214' => 'ICS214 - Activity Log',
        'ICS215A' => 'ICS215A - Incident Action Plan Safety Analysis',
        'ICS218' => 'ICS218 - Support Vehicle/Equipment Inventory',
        'ICS230' => 'ICS230 - Daily Meeting Schedule',
    ];
    $title = (string)($options['title'] ?? ($formTitles[strtoupper($formCode)] ?? strtoupper($formCode)));
    $printUrl = (string)($options['print_url'] ?? ('/forms/' . strtolower($formCode) . '/index.php?view=print'));
    $pdfUrl = (string)($options['pdf_url'] ?? ('/forms/' . strtolower($formCode) . '/pdf.php'));
    $printUrl = is_file(dirname(__DIR__) . $printUrl) ? $printUrl : '/not_implemented.php';
    $pdfUrl = is_file(dirname(__DIR__) . $pdfUrl) ? $pdfUrl : '/not_implemented.php';
    $showPrint = (bool)($options['show_print'] ?? true);
    $showPdf = (bool)($options['show_pdf'] ?? true);
    $pdfLabel = (string)($options['pdf_label'] ?? 'Download PDF');
    $officialLabel = (string)($options['official_label'] ?? 'Download Blank Form');
    $officialFile = (string)($options['official_file'] ?? ('/assets/pdf/official/' . strtoupper($formCode) . '.pdf'));
    $officialPath = dirname(__DIR__) . $officialFile;
    $officialExists = is_file($officialPath);
    $note = (string)($options['note'] ?? '');
    echo '<div class="card no-print">';
    echo '<h2>' . e($title) . '</h2>';
    if (!empty($options['error'])) { echo '<div class="flash error">' . e((string)$options['error']) . '</div>'; }
    echo '<p><strong>Status:</strong> ' . e(ucwords(str_replace('_', ' ', $status))) . '</p>';
    if ($preparer) {
        echo '<p><strong>Prepared By:</strong> ' . e((string)($preparer['name'] ?? 'TBD')) . ' (' . e((string)($preparer['position_title'] ?? '')) . ')</p>';
    }
    if ($signature) {
        echo '<p><strong>Digitally Signed By:</strong> ' . e((string)$signature['signer_name']) . ' (' . e((string)$signature['signer_position_title']) . ') on ' . e((string)$signature['signed_at']) . '</p>';
    } elseif ($note !== '') {
        echo '<p class="small">' . e($note) . '</p>';
    }
    echo '<div class="actions" style="margin-top:12px;">';
    // Preview PDF / Download PDF are rendered once per page by render_ics_form_actions() (includes/form_action_buttons.php).
    if ($officialExists) {
        echo '<a class="btn btn-secondary" href="' . e(app_url($officialFile)) . '" target="_blank">' . e($officialLabel) . '</a>';
    } else {
        echo '<span class="small">Official ICS Canada blank PDF not loaded yet.</span>';
    }
    echo '</div></div>';
}
}



function render_ics_official_blank_pdf_button(string $formCode): void {
    $officialFile = '/assets/pdf/official/' . strtoupper($formCode) . '.pdf';
    $officialPath = dirname(__DIR__) . $officialFile;
    if (is_file($officialPath)) {
        echo '<a class="btn btn-secondary" href="' . e(app_url($officialFile)) . '" target="_blank">Download Blank Form</a>';
    } else {
        echo '<span class="small">Official ICS Canada blank PDF not loaded yet.</span>';
    }
}

/**
 * @param string|null $helpTopic Help file for this page (see includes/help.php). Leave null to use the page's own path,
 *                               e.g. /admin/smtp.php -> help/topics/admin/smtp.md.
 */
function render_header(string $title, ?string $helpTopic = null): void {
    require_once __DIR__ . '/help.php';
    $user = current_user();
    $helpTopic = ($helpTopic !== null && cb_help_topic_is_valid(explode('#', $helpTopic)[0])) ? $helpTopic : cb_help_topic_for_current_page();
    $showHelp = $user || in_array(explode('#', $helpTopic)[0], CB_HELP_PUBLIC_TOPICS, true);
    $canManageUsers = current_user_can_manage_users();
    $branding = branding_settings();
    $incident = null;
    $operationalPeriods = [];
    $currentOperationalPeriod = null;
    try {
        $incident = active_incident();
        if ($incident) {
            $incidentId = (int)($incident['id'] ?? 0);
            if ($incidentId > 0) {
                $operationalPeriods = get_operational_periods($incidentId);
                $currentOperationalPeriod = current_operational_period($incidentId);
            }
        }
    } catch (Throwable $e) {}
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title) ?> - <?= e($branding['application_name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="<?= e(app_url('/assets/img/favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css')) ?>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand brand-with-logos">
      <?php if ($branding['show_ics_logo']): ?><img class="brand-logo brand-logo-ics" src="<?= e(app_url(defined('APP_ICS_LOGO') ? APP_ICS_LOGO : '/assets/img/ics-canada-logo.svg')) ?>" alt="ICS Canada"><?php endif; ?>
      <div class="brand-text">
        <div class="brand-title"><?= e($branding['application_name']) ?></div>
        <div class="brand-subtitle"><?= e($branding['header_text'] ?: $branding['organization_name']) ?></div>
      </div>
      <img class="brand-logo brand-logo-custom" src="<?= e($branding['logo_file'] ? app_url('/brand_asset.php') : app_url(defined('APP_CUSTOM_LOGO') ? APP_CUSTOM_LOGO : '/assets/img/organization-logo.gif')) ?>" alt="Organization logo">
    </div>
    <?php
      $isAdminArea = str_starts_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), rtrim(APP_BASE_URL, '/') . '/admin/');
      $isSettingsLanding = (string)($_SERVER['SCRIPT_NAME'] ?? '') === rtrim(APP_BASE_URL, '/') . '/admin/settings.php';
      $isCheckInLocations = (string)($_SERVER['SCRIPT_NAME'] ?? '') === rtrim(APP_BASE_URL, '/') . '/admin/locations.php';
      $backLinkUrl = app_url('/dashboard/index.php');
      $backLinkLabel = '← Back to ICS';

      if ($isAdminArea && !$isSettingsLanding && !$isCheckInLocations) {
          $backLinkUrl = app_url('/admin/settings.php');
          $backLinkLabel = '← Back to Settings';
      }
    ?>
    <?php
      $activeIncidentId = (int)($incident['id'] ?? 0);
      $canAccessForm = static function (string $formCode) use ($user, $activeIncidentId): bool {
          if (!$user) {
              return false;
          }

          $permissions = get_current_user_form_permissions($formCode, $activeIncidentId ?: null);
          return !empty($permissions['can_view'])
              || !empty($permissions['can_create'])
              || !empty($permissions['can_edit'])
              || !empty($permissions['can_submit_final'])
              || !empty($permissions['can_manage_permissions']);
      };
      $formLinks = [
          ['code' => 'ICS201', 'path' => '/forms/ics201/index.php', 'label' => 'ICS 201 - Incident Briefing'],
          ['code' => 'ICS202', 'path' => '/forms/ics202/index.php', 'label' => 'ICS 202 - Objectives'],
          ['code' => 'ICS203', 'path' => '/forms/ics203/index.php', 'label' => 'ICS 203 - Org Assignments'],
          ['code' => 'ICS204', 'path' => '/forms/ics204/index.php', 'label' => 'ICS 204 - Assignment List'],
          ['code' => 'ICS205', 'path' => '/forms/ics205/index.php', 'label' => 'ICS 205 - Radio Plan'],
          ['code' => 'ICS205A', 'path' => '/forms/ics205a/index.php', 'label' => 'ICS 205A - Comms List'],
          ['code' => 'ICS206', 'path' => '/forms/ics206/index.php', 'label' => 'ICS 206 - Medical Plan'],
          ['code' => 'ICS207', 'path' => '/forms/ics207/index.php', 'label' => 'ICS 207 - Org Chart'],
          ['code' => 'ICS208', 'path' => '/forms/ics208/index.php', 'label' => 'ICS 208 - Safety Plan'],
          ['code' => 'ICS209', 'path' => '/forms/ics209/index.php', 'label' => 'ICS 209 - Incident Status Summary'],
          ['code' => 'ICS209WF', 'path' => '/forms/ics209wf/index.php', 'label' => 'ICS 209-WF - Wildfire Status Summary'],
          ['code' => 'ICS210', 'path' => '/forms/ics210/index.php', 'label' => 'ICS 210 - Resource Status Change'],
          ['code' => 'ICS211', 'path' => '/forms/ics211/index.php', 'label' => 'ICS 211 - Check-In'],
          ['code' => 'ICS213', 'path' => '/forms/ics213/index.php', 'label' => 'ICS 213 - General Message'],
          ['code' => 'ICS213RR', 'path' => '/forms/ics213rr_v2/index.php', 'label' => 'ICS 213RR - Resource Request'],
          ['code' => 'ICS214', 'path' => '/forms/ics214/index.php', 'label' => 'ICS 214 - Activity Log'],
          ['code' => 'ICS215A', 'path' => '/forms/ics215a/index.php', 'label' => 'ICS 215A - Safety Analysis'],
          ['code' => 'ICS218', 'path' => '/forms/ics218/index.php', 'label' => 'ICS 218 - Support Vehicle/Equipment Inventory'],
          ['code' => 'ICS230', 'path' => '/forms/ics230/index.php', 'label' => 'ICS 230 - Meeting Schedule'],
      ];
    ?>
    <nav>
      <?php if ($isAdminArea): ?>
        <a href="<?= e($backLinkUrl) ?>"><?= e($backLinkLabel) ?></a>
        <?php if ($isSettingsLanding && is_system_administrator()): ?>
          <a href="<?= e(app_url('/admin/organization.php')) ?>">Organization &amp; Branding</a>
          <a href="<?= e(app_url('/admin/users.php')) ?>">Users &amp; Roles</a>
          <a href="<?= e(app_url('/admin/form_permissions.php')) ?>">Form Permissions</a>
          <a href="<?= e(app_url('/admin/role_catalogue.php')) ?>">Roles Catalogue</a>
          <a href="<?= e(app_url('/admin/entra_settings.php')) ?>">Entra Settings</a>
          <a href="<?= e(app_url('/admin/smtp.php')) ?>">Email / SMTP</a>
          <a href="<?= e(app_url('/admin/signoff_settings.php')) ?>">Sign-off Approvers</a>
          <a href="<?= e(app_url('/admin/lookups.php')) ?>">Lookup Lists</a>
          <a href="<?= e(app_url('/admin/resource_catalogue.php')) ?>">Resource Catalogue</a>
          <a href="<?= e(app_url('/admin/ecc_display.php')) ?>">ECC Display</a>
          <a href="<?= e(app_url('/admin/sync_status.php')) ?>">Check-in Sync Status</a>
          <a href="<?= e(app_url('/admin/settings_placeholder.php?area=security')) ?>">Security &amp; Sessions</a>
          <a href="<?= e(app_url('/admin/settings_placeholder.php?area=retention')) ?>">Retention &amp; Archive</a>
          <a href="<?= e(app_url('/admin/backup_restore.php')) ?>">Backup &amp; Restore</a>
          <a href="<?= e(app_url('/admin/audit_log.php')) ?>">Audit Log</a>
          <a href="<?= e(app_url('/admin/system_information.php')) ?>">System Information</a>
        <?php endif; ?>
        <div class="nav-footer">
          <a href="<?= e(cb_help_url('index')) ?>">Help topics</a>
          <a href="<?= e(app_url('/logout.php')) ?>">Logout</a>
        </div>
      <?php elseif ($user): ?>
        <a href="<?= e(app_url('/dashboard/index.php')) ?>">Dashboard</a>
        <?php foreach ($formLinks as $formLink): ?>
          <?php if ($canAccessForm($formLink['code'])): ?>
            <a href="<?= e(app_url($formLink['path'])) ?>"><?= e($formLink['label']) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
        <a href="<?= e(app_url('/ecc_display/landscape.php')) ?>" target="_blank" rel="noopener">ECC Display - Landscape</a>
        <a href="<?= e(app_url('/ecc_display/portrait.php')) ?>" target="_blank" rel="noopener">ECC Display - Portrait</a>
        <span class="nav-spacer"></span>
        <?php if (current_user_can_resources_view($activeIncidentId)): ?>
        <a href="<?= e(app_url('/forms/resources/index.php')) ?>">RESL - Resources Unit</a>
        <?php endif; ?>
        <?php if (is_system_administrator()): ?>
        <a href="<?= e(app_url('/admin/locations.php')) ?>">Check-in Locations</a>
        <?php endif; ?>
        <?php if (current_user_can_finance_view($activeIncidentId)): ?>
        <a href="<?= e(app_url('/forms/finance/index.php')) ?>">FIN/Admin - 213RR Queue</a>
        <?php endif; ?>
        <div class="nav-footer">
          <a href="<?= e(cb_help_url('index')) ?>">Help topics</a>
          <?php if (is_system_administrator()): ?><a href="<?= e(app_url('/admin/settings.php')) ?>">Settings</a><?php endif; ?>
          <a href="<?= e(app_url('/logout.php')) ?>">Logout</a>
        </div>
      <?php else: ?>
        <a href="<?= e(app_url('/ecc_display/landscape.php')) ?>" target="_blank" rel="noopener">ECC Display - Landscape</a>
        <a href="<?= e(app_url('/ecc_display/portrait.php')) ?>" target="_blank" rel="noopener">ECC Display - Portrait</a>
        <div class="nav-footer">
          <a href="<?= e(app_url('/login.php')) ?>">Login</a>
        </div>
      <?php endif; ?>
    </nav>
  </aside>
  <main class="content">
    <header class="topbar">
      <div class="topbar-main">
        <div class="incident-banner">
          <div class="incident-banner-label">Incident</div>
          <div class="incident-banner-name">
            <?= e((string)($incident['name'] ?? 'No active incident')) ?>
            <?php if (!empty($incident['incident_number'])): ?>
              <span class="incident-banner-number">(<?= e((string)$incident['incident_number']) ?>)</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($incident && $operationalPeriods): ?>
          <form method="get" class="op-period-switcher">
            <?php foreach ($_GET as $key => $value): ?>
              <?php if ($key === 'op_id' || is_array($value)): continue; endif; ?>
              <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
            <?php endforeach; ?>
            <label for="topbar-op-id">Operational Period</label>
            <select id="topbar-op-id" name="op_id" onchange="this.form.submit()">
              <?php foreach ($operationalPeriods as $op): ?>
                <option value="<?= e((string)$op['id']) ?>" <?= ((int)($currentOperationalPeriod['id'] ?? 0) === (int)$op['id']) ? 'selected' : '' ?>>
                  <?= e(operational_period_label($op)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
        <div class="page-context"><?= e($title) ?></div>
      </div>
      <div class="topbar-side">
        <?php if ($showHelp): ?>
          <a class="btn btn-help no-print" href="<?= e(cb_help_url($helpTopic)) ?>" data-help-open="<?= e($helpTopic) ?>"
             aria-controls="help-panel" title="Help for this page">? Help</a>
        <?php endif; ?>
        <?php if ($user): ?>
          <div class="userbox">
            <a class="userbox-link" href="<?= e(app_url('/account.php')) ?>" title="Change password or PIN">
              <?= e($user['display_name']) ?><?= !empty($user['role_code']) ? ' - ' . e($user['role_code']) : '' ?>
            </a>
          </div>
        <?php endif; ?>
      </div>
    </header>
    <?php if ($user && $canManageUsers) { require_once __DIR__ . '/external_checkin_status.php'; echo external_checkin_sync_banner_html(); } ?>
    <?php foreach (get_flashes() as $flash): ?>
      <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
<?php
}

function render_footer(): void {
    $branding = branding_settings();
    ?>
<?php if (!empty($branding['footer_text'])): ?><div class="app-footer"><?= e($branding['footer_text']) ?></div><?php endif; ?>
<?php require_once __DIR__ . '/version.php'; ?>
<div class="app-footer no-print" style="font-size:12px;opacity:.75">Command Bridge <?= e(CB_VERSION) ?> &middot; free software under the GNU AGPL-3.0<?php if (CB_SOURCE_URL !== ''): ?> &middot; <a href="<?= e(CB_SOURCE_URL) ?>" rel="noopener">source code</a><?php endif; ?></div>
<?php require_once __DIR__ . '/signoff.php'; echo signoff_hidden_form_html(); ?>
<?php require_once __DIR__ . '/help.php'; ?>
<aside id="help-panel" class="help-panel no-print" hidden aria-labelledby="help-panel-title" role="complementary"
       data-help-base="<?= e(app_url('/help/view.php')) ?>">
  <div class="help-panel-head">
    <h2 id="help-panel-title">Help</h2>
    <a class="help-panel-newtab" href="<?= e(cb_help_url('index')) ?>" target="_blank" rel="noopener" title="Open this help in a new tab">Open in new tab</a>
    <button type="button" class="help-panel-close" aria-label="Close help">&times;</button>
  </div>
  <div class="help-panel-body help-doc" tabindex="-1"></div>
</aside>
<script src="<?= e(app_url('/assets/js/help_panel.js')) ?>" defer></script>
<?php
    ?>

<style>
.govai-dt-wrap{display:flex;align-items:flex-end;gap:8px;flex-wrap:nowrap;position:relative}
.govai-dt-display{min-width:180px}
.govai-dt-button{white-space:nowrap}
.govai-dt-popover{position:absolute;left:0;top:100%;z-index:1000;background:#fff;border:1px solid #999;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.18);padding:10px;margin-top:4px;width:292px;color:#111}
.govai-dt-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:6px}
.govai-dt-head button{padding:4px 8px}
.govai-dt-month{font-weight:700;text-align:center;flex:1}
.govai-dt-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.govai-dt-dow{font-size:12px;text-align:center;font-weight:700;color:#555;padding:3px 0}
.govai-dt-popover .govai-dt-grid button.govai-dt-day{
  appearance:none;-webkit-appearance:none;
  padding:6px 0 !important;
  border:1px solid #ddd !important;
  background:#fff !important;
  background-color:#fff !important;
  color:#111 !important;
  cursor:pointer;
  text-align:center;
  border-radius:3px;
  font:inherit;
  box-shadow:none !important;
  text-shadow:none !important;
}
.govai-dt-popover .govai-dt-grid button.govai-dt-day:hover,
.govai-dt-popover .govai-dt-grid button.govai-dt-day.selected{
  background:#1f5f99 !important;
  background-color:#1f5f99 !important;
  color:#fff !important;
}
.govai-dt-day.blank{visibility:hidden}
.govai-dt-time{display:flex;align-items:center;gap:6px;margin-top:10px}
.govai-dt-time select{width:auto}
.govai-dt-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
@media(max-width:640px){.govai-dt-wrap{flex-wrap:wrap}.govai-dt-popover{width:270px}}
</style>
<script>
(function(){
  function pad2(n){ return String(n).padStart(2,'0'); }
  function parseDateTime(v){
    var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/);
    if(!m) return null;
    return {y:+m[1], mo:+m[2]-1, d:+m[3], h:+m[4], mi:+m[5]};
  }
  function initGovAiDateTimePicker(wrap){
    var input = wrap.querySelector('.govai-dt-display');
    var button = wrap.querySelector('.govai-dt-button');
    if(!input || !button || wrap.dataset.govaiPickerBound === '1') return;
    wrap.dataset.govaiPickerBound = '1';
    var picked = parseDateTime(input.value);
    var now = new Date();
    var state = picked || {y:now.getFullYear(), mo:now.getMonth(), d:now.getDate(), h:now.getHours(), mi:Math.round(now.getMinutes()/5)*5};
    if(state.mi >= 60){ state.mi = 55; }
    var pop = null;
    function close(){ if(pop){ pop.remove(); pop=null; } }
    function setValue(){
      input.value = state.y+'-'+pad2(state.mo+1)+'-'+pad2(state.d)+' '+pad2(state.h)+':'+pad2(state.mi);
      close();
    }
    function draw(){
      close();
      pop = document.createElement('div');
      pop.className = 'govai-dt-popover';
      pop.addEventListener('click', function(ev){ ev.stopPropagation(); });
      var monthName = new Date(state.y,state.mo,1).toLocaleString('en-CA',{month:'long',year:'numeric'});
      pop.innerHTML = '<div class="govai-dt-head"><button type="button" data-prev>&lt;</button><div class="govai-dt-month"></div><button type="button" data-next>&gt;</button></div><div class="govai-dt-grid"></div><div class="govai-dt-time"><label>Time</label><select data-hour></select><span>:</span><select data-minute></select></div><div class="govai-dt-actions"><button type="button" data-clear>Clear</button><button type="button" data-apply>Apply</button></div>';
      pop.querySelector('.govai-dt-month').textContent = monthName;
      var grid = pop.querySelector('.govai-dt-grid');
      ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(d){ var e=document.createElement('div'); e.className='govai-dt-dow'; e.textContent=d; grid.appendChild(e); });
      for(var b=0;b<new Date(state.y,state.mo,1).getDay();b++){ var blank=document.createElement('div'); blank.className='govai-dt-day blank'; grid.appendChild(blank); }
      var days = new Date(state.y,state.mo+1,0).getDate();
      for(var day=1; day<=days; day++){
        var cell=document.createElement('button'); cell.type='button'; cell.className='govai-dt-day'+(day===state.d?' selected':''); cell.textContent=day;
        cell.addEventListener('click',(function(dd){return function(){state.d=dd; draw();};})(day));
        grid.appendChild(cell);
      }
      var hs=pop.querySelector('[data-hour]'), ms=pop.querySelector('[data-minute]');
      for(var h=0;h<24;h++){ hs.add(new Option(pad2(h),pad2(h),h===state.h,h===state.h)); }
      for(var mi=0;mi<60;mi+=5){ ms.add(new Option(pad2(mi),pad2(mi),mi===state.mi,mi===state.mi)); }
      hs.addEventListener('change',function(){state.h=+this.value;});
      ms.addEventListener('change',function(){state.mi=+this.value;});
      pop.querySelector('[data-prev]').onclick=function(ev){ev.stopPropagation();state.mo--; if(state.mo<0){state.mo=11;state.y--;} state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate()); draw();};
      pop.querySelector('[data-next]').onclick=function(ev){ev.stopPropagation();state.mo++; if(state.mo>11){state.mo=0;state.y++;} state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate()); draw();};
      pop.querySelector('[data-clear]').onclick=function(){input.value=''; close();};
      pop.querySelector('[data-apply]').onclick=setValue;
      wrap.appendChild(pop);
    }
    button.addEventListener('click',function(ev){ ev.stopPropagation(); var p=parseDateTime(input.value); if(p) state=p; pop ? close() : draw(); });
    document.addEventListener('click',function(ev){ if(pop && !wrap.contains(ev.target)) close(); });
  }
  document.addEventListener('DOMContentLoaded', function(){ document.querySelectorAll('[data-govai-datetime]').forEach(initGovAiDateTimePicker); });
})();
</script>

<?php
    echo "</main></div></body></html>";
}
