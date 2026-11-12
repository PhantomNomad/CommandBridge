<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$location=gateway_require_location_session();$identity=gateway_session_identity();
$stmt=gateway_db()->prepare('SELECT * FROM gateway_expected_resources WHERE gateway_location_id=? AND publication_status="published" ORDER BY reference_number, provider_name, id');
$stmt->execute([$location['id']]);$resources=$stmt->fetchAll();$status=gateway_checkin_status((int)$location['id']);
gateway_render_header('Resource Check-In');gateway_show_flash();
?>
<p class="muted"><strong><?=gateway_h($location['location_name'])?></strong> · Recorder: <?=gateway_h($identity['name'])?> (<?=gateway_h($identity['position'])?>)</p>
<style>
/* Keep check-in totals in one horizontal row even if a cached stylesheet is in use. */
#checkin-status .status-grid{display:flex!important;flex-flow:row nowrap!important;margin:0!important;padding:0!important;overflow-x:auto!important;border:1px solid #d7e0e4!important;border-radius:4px!important;background:#f8fafb!important}
#checkin-status .status-grid>div{display:block!important;flex:1 0 135px!important;margin:0!important;padding:10px 12px!important}
#checkin-status .status-grid>div+div{border-left:1px solid #d7e0e4!important}
#checkin-status .status-grid dt{color:#52626d!important;font-size:.82rem!important;font-weight:700!important}
#checkin-status .status-grid dd{display:block!important;margin:3px 0 0!important;font-size:1.65rem!important;font-weight:750!important;line-height:1.1!important}
#resource-update-prompt{position:fixed;right:16px;bottom:16px;z-index:20;width:min(440px,calc(100vw - 32px));padding:14px;background:#fff;border:1px solid #d7e0e4;border-radius:6px;box-shadow:0 4px 16px rgba(22,33,42,.18)}
#resource-update-prompt .status-change-notice{margin:0;padding:0;background:transparent;border:0}
#resource-update-prompt .status-refresh{margin:12px 0 0}
</style>
<section class="card checkin-status" id="checkin-status"
 data-manifest-updated="<?=gateway_h($status['manifest_updated_at'])?>"
 data-total-resources="<?=$status['total_resources']?>"
 data-awaiting-checkin="<?=$status['awaiting_checkin']?>"
 data-checked-in="<?=$status['checked_in']?>"
 data-withdrawn="<?=$status['withdrawn']?>"
 aria-live="polite">
<h2>Check-in status</h2>
<dl class="status-grid">
<div><dt>Awaiting check-in</dt><dd id="status-awaiting"><?=$status['awaiting_checkin']?></dd></div>
<div><dt>Checked in</dt><dd id="status-checked-in"><?=$status['checked_in']?></dd></div>
<div><dt>Total expected</dt><dd id="status-total"><?=$status['total_resources']?></dd></div>
<div><dt>Withdrawn</dt><dd id="status-withdrawn"><?=$status['withdrawn']?></dd></div>
</dl>
<p class="muted status-updated">Manifest last updated: <span id="status-manifest-updated"><?=gateway_h(gateway_format_local_datetime($status['manifest_updated_at']))?></span></p>
<p class="muted" id="resource-list-update-message">Expected-resource lists update automatically while this page is open.</p>
<noscript><p class="muted">Refresh this page to view recently added expected resources.</p></noscript>
</section>
<?php if(!$resources): ?><div class="notice">No expected resources are currently published for this location. You may report an unscheduled arrival.</div><?php endif; ?>
<section class="card"><h2>Record expected resource arrival</h2>
<form method="post" action="/submit.php"><input type="hidden" name="csrf" value="<?=gateway_h(gateway_csrf_token())?>"><input type="hidden" name="event_action" value="checkin.recorded">
<label>Expected resource <select name="expected_resource_id" required><option value="">Select resource</option><?php foreach($resources as $r): ?><option value="<?= (int)$r['id']?>"><?=gateway_h(trim(($r['reference_number']?:'Resource '.$r['id']).' — '.$r['provider_name'].' — '.$r['resource_type']))?></option><?php endforeach; ?></select></label>
<label>Crew Lead / Primary Contact <input name="crew_lead" required maxlength="160"></label><label>Mobile phone <input name="mobile_phone" type="tel" maxlength="50"></label><label>Radio call sign / alternate contact <input name="radio_call_sign" maxlength="100"></label>
<label>Number of personnel <input name="personnel_count" type="number" min="0" max="10000" required></label><label>Actual arrival time <input name="recorded_at" type="datetime-local" required></label><label>Email address (optional) <input name="email" type="email" maxlength="254"></label>
<label>Additional operational notes (optional)<textarea name="notes" maxlength="2000"></textarea></label><button type="submit">Record physical arrival</button></form></section>
<section class="card"><h2>Report an exception</h2><form method="post" action="/submit.php"><input type="hidden" name="csrf" value="<?=gateway_h(gateway_csrf_token())?>">
<label>Action <select name="event_action" required><option value="checkin.no_show">No show / not arrived</option><option value="checkin.exception">Arrived at wrong location</option><option value="checkin.unscheduled_arrival">Report unscheduled / unlisted arrival</option></select></label>
<label>Expected resource (if known) <select name="expected_resource_id"><option value="">Not listed / unknown</option><?php foreach($resources as $r): ?><option value="<?= (int)$r['id']?>"><?=gateway_h(trim(($r['reference_number']?:'Resource '.$r['id']).' — '.$r['provider_name'].' — '.$r['resource_type']))?></option><?php endforeach; ?></select></label>
<label>Arrival or report time <input name="recorded_at" type="datetime-local" required></label><label>Resource / equipment description <input name="resource_description" required maxlength="500"></label><label>Agency / owner (if known) <input name="agency_owner" maxlength="160"></label><label>Person spoken to (if known) <input name="person_spoken_to" maxlength="160"></label><label>Reason given / operational notes <textarea name="notes" required maxlength="2000"></textarea></label><button type="submit" class="secondary">Submit exception report</button></form></section>
<form method="post" action="/logout.php"><input type="hidden" name="csrf" value="<?=gateway_h(gateway_csrf_token())?>"><button class="link" type="submit">End session</button></form>
<script>
(() => {
    const status = document.getElementById('checkin-status');
    const resourceSelects = Array.from(document.querySelectorAll('select[name="expected_resource_id"]'));
    if (!status || !window.fetch) return;

    const values = {
        awaiting_checkin: document.getElementById('status-awaiting'),
        checked_in: document.getElementById('status-checked-in'),
        total_resources: document.getElementById('status-total'),
        withdrawn: document.getElementById('status-withdrawn'),
        manifest_updated_display: document.getElementById('status-manifest-updated')
    };
    let currentState = [
        status.dataset.manifestUpdated || '',
        status.dataset.totalResources || '0',
        status.dataset.awaitingCheckin || '0',
        status.dataset.checkedIn || '0',
        status.dataset.withdrawn || '0'
    ].join('|');
    let currentResourceState = '';
    let latestResources = [];
    let polling = false;

    const resourceLabel = (resource) => String(resource.label || `Resource ${resource.id}`);

    const refreshResourceSelect = (select, resources) => {
        const selectedValue = select.value;
        const selectedOption = select.options[select.selectedIndex];
        const selectedLabel = selectedOption ? selectedOption.textContent : '';
        const placeholder = select.options[0];
        const fragment = document.createDocumentFragment();
        const placeholderOption = document.createElement('option');
        placeholderOption.value = placeholder ? placeholder.value : '';
        placeholderOption.textContent = placeholder ? placeholder.textContent : 'Select resource';
        fragment.appendChild(placeholderOption);

        const availableIds = new Set(resources.map((resource) => String(resource.id)));
        if (selectedValue && !availableIds.has(selectedValue)) {
            const unavailable = document.createElement('option');
            unavailable.value = selectedValue;
            unavailable.textContent = `Previously selected — ${selectedLabel} (no longer available)`;
            unavailable.dataset.unavailable = 'true';
            fragment.appendChild(unavailable);
        }

        resources.forEach((resource) => {
            const option = document.createElement('option');
            option.value = String(resource.id);
            option.textContent = resourceLabel(resource);
            fragment.appendChild(option);
        });

        select.replaceChildren(fragment);
        select.value = selectedValue;

        if (select.required && selectedValue && !availableIds.has(selectedValue)) {
            select.setCustomValidity('This resource is no longer available. Select a current expected resource.');
        } else {
            select.setCustomValidity('');
        }
    };

    const syncResourceSelects = (resources) => {
        const nextState = JSON.stringify(resources.map((resource) => [String(resource.id), resourceLabel(resource)]));
        latestResources = resources;

        if (nextState === currentResourceState) return;
        currentResourceState = nextState;

        resourceSelects.forEach((select) => {
            // Do not redraw an open dropdown. It will update when the recorder
            // leaves the field, so the current selection is never interrupted.
            if (document.activeElement === select) {
                select.dataset.resourceUpdatePending = '1';
                return;
            }
            refreshResourceSelect(select, resources);
        });
    };

    resourceSelects.forEach((select) => {
        select.addEventListener('blur', () => {
            if (select.dataset.resourceUpdatePending === '1') {
                delete select.dataset.resourceUpdatePending;
                refreshResourceSelect(select, latestResources);
            }
        });
    });

    const updateStatus = (data) => {
        if (Array.isArray(data.resources)) {
            syncResourceSelects(data.resources);
        }

        const newState = [
            data.manifest_updated_at || '',
            data.total_resources || 0,
            data.awaiting_checkin || 0,
            data.checked_in || 0,
            data.withdrawn || 0
        ].join('|');

        if (newState === currentState) return;

        Object.entries(values).forEach(([key, element]) => {
            if (element && Object.prototype.hasOwnProperty.call(data, key)) {
                element.textContent = data[key];
            }
        });
        currentState = newState;
    };

    const pollStatus = () => {
        if (polling || document.visibilityState === 'hidden') return;
        polling = true;
        fetch('/checkin_status.php', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'application/json'}
        })
            .then((response) => response.ok ? response.json() : Promise.reject())
            .then(updateStatus)
            .catch(() => {})
            .then(() => { polling = false; });
    };

    window.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') pollStatus();
    });

    // Polling updates status counters and expected-resource lists in place.
    // It never reloads the page or clears in-progress form fields.
    window.setTimeout(pollStatus, 1000);
    window.setInterval(pollStatus, 10000);
})();
</script>
<?php gateway_render_footer(); ?>
