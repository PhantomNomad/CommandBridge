/*
 * Command Bridge page frame on phones and tablets (see the "Phones and tablets" rules in assets/css/app.css).
 *  - Under 1024 px wide the side menu is hidden behind the Menu button and slides in over the page.
 *  - Wide tables get their own sideways scroll box, so the page itself never scrolls sideways.
 */
(function () {
  'use strict';
  var body = document.body;
  var toggle = document.querySelector('.nav-toggle');
  var sidebar = document.getElementById('app-sidebar');
  var backdrop = document.querySelector('.nav-backdrop');
  var closeBtn = document.querySelector('.nav-close');
  var narrow = window.matchMedia('(max-width: 1023px)');

  function setOpen(open) {
    if (!sidebar || !toggle) { return; }
    body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (backdrop) { backdrop.hidden = !open; }
    if (open) {
      var first = sidebar.querySelector('nav a, .nav-close');
      if (first) { first.focus(); }
    } else if (sidebar.contains(document.activeElement)) {
      toggle.focus();
    }
  }

  if (toggle && sidebar) {
    toggle.addEventListener('click', function () { setOpen(!body.classList.contains('nav-open')); });
    if (closeBtn) { closeBtn.addEventListener('click', function () { setOpen(false); }); }
    if (backdrop) { backdrop.addEventListener('click', function () { setOpen(false); }); }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && body.classList.contains('nav-open')) { setOpen(false); }
    });
    sidebar.addEventListener('click', function (e) {
      if (narrow.matches && e.target.closest('nav a')) { setOpen(false); }
    });
    // Rotating a tablet to the wide (desktop) layout: the menu is part of the page again.
    var onChange = function () { if (!narrow.matches) { setOpen(false); } };
    if (narrow.addEventListener) { narrow.addEventListener('change', onChange); } else if (narrow.addListener) { narrow.addListener(onChange); }
  }

  // Sideways scrolling for wide tables. Tables already inside a scroll box (help pages) or marked
  // .no-scroll-wrap are left alone.
  document.querySelectorAll('main.content table').forEach(function (table) {
    if (table.classList.contains('no-scroll-wrap') || table.closest('.table-scroll, .help-table-wrap, .no-scroll-wrap')) { return; }
    var wrap = document.createElement('div');
    wrap.className = 'table-scroll';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
  });
}());
