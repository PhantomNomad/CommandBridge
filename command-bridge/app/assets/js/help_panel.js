/*
 * In-app help side panel (see includes/help.php).
 * Anything with data-help-open="topic" or data-help-open="topic#section" opens that help in the panel; links inside
 * the panel with data-help-topic stay in the panel. Without JavaScript, the Help button opens the full help page.
 * Loading help is a background request, so it does not count as activity for the inactivity sign-out.
 */
(function () {
  'use strict';
  const panel = document.getElementById('help-panel');
  if (!panel) return;
  const body = panel.querySelector('.help-panel-body');
  const title = panel.querySelector('#help-panel-title');
  const newTab = panel.querySelector('.help-panel-newtab');
  const closeBtn = panel.querySelector('.help-panel-close');
  const base = panel.dataset.helpBase;
  let opener = null;
  let current = '';

  function pageUrl(topic, anchor) {
    return base + '?topic=' + encodeURIComponent(topic) + (anchor ? '#' + encodeURIComponent(anchor) : '');
  }

  function scrollToAnchor(anchor) {
    const target = anchor ? body.querySelector('#' + CSS.escape(anchor)) : null;
    if (target) {
      target.scrollIntoView({block: 'start'});
      target.classList.add('help-highlight');
      window.setTimeout(() => target.classList.remove('help-highlight'), 2000);
    } else {
      body.scrollTop = 0;
    }
  }

  async function openHelp(spec) {
    const [topic, anchor] = String(spec || 'index').split('#');
    newTab.href = pageUrl(topic, anchor);
    panel.hidden = false;
    document.body.classList.add('help-open');

    if (topic === current) {          // already loaded: just jump to the section
      scrollToAnchor(anchor);
      body.focus({preventScroll: true});
      return;
    }
    body.innerHTML = '<p class="help-loading">Loading help&hellip;</p>';
    try {
      const response = await fetch(base + '?topic=' + encodeURIComponent(topic) + '&panel=1', {
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        cache: 'no-store',
      });
      if (response.status === 401) {   // signed out for inactivity
        const info = await response.json().catch(() => ({}));
        window.location.href = info.login_url || '/login.php';
        return;
      }
      if (!response.ok) throw new Error('HTTP ' + response.status);
      body.innerHTML = await response.text();
      const doc = body.querySelector('[data-help-title]');
      title.textContent = doc ? doc.dataset.helpTitle : 'Help';
      current = topic;
      scrollToAnchor(anchor);
    } catch (error) {
      current = '';
      title.textContent = 'Help';
      body.innerHTML = '';
      const p = document.createElement('p');
      p.append('Help could not be loaded. ');
      const a = document.createElement('a');
      a.href = newTab.href;
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = 'Open it in a new tab';
      p.append(a, '.');
      body.append(p);
    }
    body.focus({preventScroll: true});
  }

  function closeHelp() {
    panel.hidden = true;
    document.body.classList.remove('help-open');
    if (opener && document.contains(opener)) opener.focus();
    opener = null;
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-help-open]');
    if (trigger) {
      event.preventDefault();
      opener = trigger;
      openHelp(trigger.dataset.helpOpen);
      return;
    }
    const inner = event.target.closest('#help-panel [data-help-topic]');
    if (inner) {
      event.preventDefault();
      openHelp(inner.dataset.helpTopic);
    }
  });
  closeBtn.addEventListener('click', closeHelp);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) closeHelp();
  });
})();
