/* flatbb front-end. Vanilla JS, no build step. Everything hangs off data-* attributes so plugins
 * can reuse the same behaviours: data-ajax forms, data-dropdown, data-toggle, data-editor, data-confirm.
 * Plugins get window.FB (base, csrf, uid) and can listen for document events: fb:ajax, fb:editor, fb:content (rows added to a list).
 */
(function () {
  'use strict';
  var FB = window.FB || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- helpers ---------- */
  /* toast(message, type, action): action {label, run} adds a button (Undo) and keeps the toast up for 8 seconds */
  function toast(msg, type, action) {
    var el = document.createElement('div');
    el.className = 'toast toast-' + (type || 'info');
    el.textContent = msg;
    var close = function () { el.classList.remove('show'); setTimeout(function () { el.remove(); }, 300); };
    if (action && action.label) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'toast-action'; b.textContent = action.label;
      b.addEventListener('click', function () { close(); action.run(); });
      el.appendChild(b);
    }
    document.body.appendChild(el);
    setTimeout(function () { el.classList.add('show'); }, 10);
    setTimeout(close, action ? 8000 : 3500);
  }
  function request(url, opts) {
    opts = opts || {};
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': FB.csrf };
    if (opts.json) headers['Content-Type'] = 'application/json';
    return fetch(url, { method: opts.method || 'GET', headers: headers, body: opts.body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: FB.i18n.failed }; }); });
  }
  FB.request = request;
  FB.toast = toast;

  /* ---------- theme ---------- */
  var root = document.documentElement;
  function applyTheme(t) {
    root.setAttribute('data-theme', t);
    try { localStorage.setItem('fb_theme', t); } catch (e) {}
    // a member's choice belongs to the account: the server then paints the right theme on the first request, on every device
    if (FB.uid > 0) { var fd = new FormData(); fd.append('theme', t); fd.append('_token', FB.csrf); request(FB.base + '/api/theme', { method: 'POST', body: fd }); }
  }
  // a visitor's choice lives in their browser; the head of the page already applied it, this only keeps a stale value from
  // an older version in step. A member's theme comes from the server, so nothing overrides it here.
  try { var saved = localStorage.getItem('fb_theme'); if (saved && FB.uid <= 0) root.setAttribute('data-theme', saved); } catch (e) {}
  function currentDark() {
    var t = root.getAttribute('data-theme');
    if (t === 'auto') return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    return t === 'dark';
  }

  /* a select that navigates: <select data-jump><option value="/url"> (settings menu on phones) */
  document.addEventListener('change', function (e) { var s = e.target.closest('select[data-jump]'); if (s && s.value) window.location.href = s.value; });

  /* ---------- dropdowns that must escape a scrolling box (the admin tables scroll sideways) ---------- */
  function clippingParent(el) {
    for (var p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      var o = getComputedStyle(p);
      if (o.overflow !== 'visible' || o.overflowX !== 'visible' || o.overflowY !== 'visible') return p;
    }
    return null;
  }

  function closeDropdowns() {
    $$('[data-dropdown].open').forEach(function (d) {
      d.classList.remove('open');
      var m = d.querySelector('.dropdown-menu') || d.__fbMenu;
      if (m && m.parentElement === document.body) { d.appendChild(m); d.__fbMenu = null; } // back where the markup put it
      if (m) { m.classList.remove('floating'); m.style.top = m.style.left = ''; }
    });
  }

  /**
   * A menu inside a box that scrolls (an admin table) would be cut off by it, so while it is open the menu hangs from
   * the page itself, next to its button, and flips above when the bottom of the window is near. It scrolls with the page.
   */
  function placeDropdown(dd, toggle) {
    var menu = dd.querySelector('.dropdown-menu');
    if (!menu || !clippingParent(menu)) return;
    menu.classList.add('floating');
    document.body.appendChild(menu);
    dd.__fbMenu = menu;
    var t = toggle.getBoundingClientRect();
    var w = menu.offsetWidth, h = menu.offsetHeight, pad = 8;
    var rtl = document.documentElement.getAttribute('dir') === 'rtl';
    var left = rtl ? t.left : t.right - w;
    left = Math.max(pad, Math.min(left, window.innerWidth - w - pad));
    var top = t.bottom + 6;
    if (top + h > window.innerHeight - pad) top = Math.max(pad, t.top - h - 6);
    menu.style.left = Math.round(left + window.scrollX) + 'px';
    menu.style.top = Math.round(top + window.scrollY) + 'px';
  }

  window.addEventListener('resize', closeDropdowns);

  /* ---------- admin menu: remembers its scroll position between pages and keeps the active item in view ---------- */
  var adminNav = $('body.page-admin .col-left');
  if (adminNav) {
    try {
      var savedTop = sessionStorage.getItem('fb_admin_menu_scroll');
      if (savedTop !== null) adminNav.scrollTop = parseInt(savedTop, 10) || 0;
      var activeLink = $('.side-link.active', adminNav);
      if (activeLink) {
        var lr = activeLink.getBoundingClientRect(), nr = adminNav.getBoundingClientRect();
        if (lr.top < nr.top || lr.bottom > nr.bottom) activeLink.scrollIntoView({ block: 'nearest' });
      }
      adminNav.addEventListener('scroll', function () { sessionStorage.setItem('fb_admin_menu_scroll', String(adminNav.scrollTop)); }, { passive: true });
    } catch (e) {}
  }

  /* ---------- Admin → Widgets chips and Admin → Menus rows: drag the items of a list into a new order ---------- */
  var dragChip = null, SORTABLE = '.chip-item[draggable], tr[draggable][data-item]';
  document.addEventListener('dragstart', function (e) {
    var c = e.target.closest && e.target.closest(SORTABLE);
    if (!c) return;
    dragChip = c;
    c.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', c.getAttribute('data-item')); } catch (x) {}
  });
  document.addEventListener('dragover', function (e) {
    if (!dragChip) return;
    var c = e.target.closest && e.target.closest(SORTABLE);
    if (!c || c === dragChip || c.parentElement !== dragChip.parentElement) return;
    e.preventDefault();
    var r = c.getBoundingClientRect(), before = c.tagName === 'TR' ? e.clientY < r.top + r.height / 2 : e.clientX < r.left + r.width / 2; // rows stack, chips run in a line
    c.parentElement.insertBefore(dragChip, before ? c : c.nextSibling);
  });
  document.addEventListener('drop', function (e) { if (dragChip) e.preventDefault(); });
  document.addEventListener('dragend', function () {
    if (!dragChip) return;
    var box = dragChip.parentElement, region = box.getAttribute('data-sort-region');
    dragChip.classList.remove('dragging');
    dragChip = null;
    if (!region) return;
    var ids = Array.prototype.filter.call(box.children, function (c) { return c.hasAttribute('data-item'); }).map(function (c) { return c.getAttribute('data-item'); });
    var fd = new FormData();
    fd.append('action', 'item_order'); fd.append('region', region); fd.append('ids', ids.join(',')); fd.append('_token', FB.csrf);
    request(box.getAttribute('data-sort-url'), { method: 'POST', body: fd }).then(function (r) { if (!r.ok) toast(r.error || FB.i18n.failed, 'error'); });
  });

  /* ---------- icon field (icon_picker() in core/icons.php): a tile, an emoji or the search box sets the hidden value ---------- */
  function iconSet(p, value, preview) {
    p.querySelector('[data-icon-value]').value = value;
    var now = p.querySelector('.icon-field-now');
    if (now) now.innerHTML = preview;
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-icon-pick]');
    if (!b) return;
    var p = b.closest('[data-icon-picker]');
    iconSet(p, b.getAttribute('data-icon-pick'), b.classList.contains('icon-pick-text') ? '<span class="icon-field-empty">' + b.textContent + '</span>' : b.innerHTML);
    Array.prototype.forEach.call(p.querySelectorAll('.icon-pick'), function (x) { x.classList.toggle('active', x === b); });
    var em = p.querySelector('[data-icon-emoji]'); if (em) em.value = '';
    if (p.tagName === 'DETAILS') p.open = false;
  });
  document.addEventListener('input', function (e) {
    var p = e.target.closest && e.target.closest('[data-icon-picker]');
    if (!p) return;
    if (e.target.hasAttribute('data-icon-search')) { // filter the tiles by name
      var q = e.target.value.trim().toLowerCase();
      Array.prototype.forEach.call(p.querySelectorAll('.icon-field-grid .icon-pick'), function (x) { x.hidden = q !== '' && (x.getAttribute('data-name') || '').indexOf(q) < 0; });
    }
    if (e.target.hasAttribute('data-icon-emoji')) {
      var v = e.target.value.trim();
      if (v !== '') { iconSet(p, 'emoji:' + v, '<span class="icon icon-emoji">' + v.replace(/[<>&"]/g, '') + '</span>'); Array.prototype.forEach.call(p.querySelectorAll('.icon-pick'), function (x) { x.classList.remove('active'); }); }
    }
  });

  /* ---------- the right column follows the page like X (assets/app.css, body.right-follow) ----------
     Its sticky top moves with each scroll: down, it goes up with the page until its bottom shows, then stays; up, it comes
     back down until its top sits under the top bar. A column shorter than the window just stays under the top bar. */
  (function () {
    var col = document.querySelector('body.right-follow .col-right');
    if (!col) return;
    var wide = window.matchMedia('(min-width: 1201px)');
    var lastY = window.scrollY, top = null, ticking = false;
    function head() { return (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--topbar-h')) || 56) + 20; }
    function place() {
      ticking = false;
      var y = window.scrollY, dy = y - lastY; lastY = y;
      if (!wide.matches) { col.style.removeProperty('--right-top'); top = null; return; }
      var max = head(), min = Math.min(max, window.innerHeight - col.offsetHeight - 20);
      top = top === null ? max : Math.max(min, Math.min(max, top - dy));
      col.style.setProperty('--right-top', Math.round(top) + 'px');
    }
    function queue() { if (!ticking) { ticking = true; requestAnimationFrame(place); } }
    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue);
    if (window.ResizeObserver) new ResizeObserver(queue).observe(col); // cards that load late or grow
    place();
  })();

  /* ---------- phones: the top bar hides while the page scrolls down and returns on the way up (assets/app.css, body.topbar-away) ---------- */
  (function () {
    var narrow = window.matchMedia('(max-width: 640px)');
    var lastY = window.scrollY, ticking = false;
    function step() {
      ticking = false;
      var y = window.scrollY, dy = y - lastY;
      if (Math.abs(dy) < 8 && y > 0) return; // ignore jitter; small moves add up until they count
      lastY = y;
      var away = narrow.matches && dy > 0 && y > 120 && !document.body.classList.contains('drawer-open') && !document.querySelector('.dropdown.open');
      document.body.classList.toggle('topbar-away', away);
    }
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(step); } }, { passive: true });
  })();

  /* ---------- global click handling ---------- */
  document.addEventListener('click', function (e) {
    var pw = e.target.closest('[data-pw-toggle]');
    if (pw) { var inp = pw.parentElement.querySelector('input'); var show = inp.type === 'password'; inp.type = show ? 'text' : 'password'; pw.classList.toggle('on', show); inp.focus(); return; }
    var t = e.target.closest('[data-toggle]');
    if (t) {
      var what = t.getAttribute('data-toggle');
      if (what === 'drawer') document.body.classList.toggle('drawer-open');
      if (what === 'theme') applyTheme(currentDark() ? 'light' : 'dark');
      return;
    }
    var dt = e.target.closest('.dropdown-toggle');
    if (dt) {
      var dd = dt.closest('[data-dropdown]');
      var open = dd.classList.contains('open');
      closeDropdowns();
      if (!open) { dd.classList.add('open'); placeDropdown(dd, dt); }
      e.preventDefault();
      return;
    }
    if (!e.target.closest('.dropdown-menu')) closeDropdowns();

    var rp = e.target.closest('[data-reply-to-post]');
    if (rp) { setReplyTarget(rp.getAttribute('data-reply-to-post'), rp.getAttribute('data-username')); return; }
    var qp = e.target.closest('[data-quote-post]');
    if (qp) { quotePost(qp.getAttribute('data-quote-post')); return; }
    if (e.target.closest('[data-clear-reply]')) { setReplyTarget('', ''); return; }
    var ql = e.target.closest('[data-qp-langs]'); // theme and language on phones: the row of languages under the two round buttons
    if (ql) {
      var row = ql.closest('.um-top, .drawer-quick');
      row = row && row.nextElementSibling;
      if (row && row.classList.contains('qp-langs')) { row.hidden = !row.hidden; ql.setAttribute('aria-expanded', row.hidden ? 'false' : 'true'); }
      return;
    }
    var cp = e.target.closest('[data-copy]');
    if (cp && navigator.clipboard) {
      e.preventDefault();
      navigator.clipboard.writeText(cp.getAttribute('data-copy')).then(function () { toast(FB.i18n.copied, 'success'); }, function () { window.location.href = cp.href; });
    }
  });

  /* ---------- email verification codes ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-send-code]'); if (!b) return;
    var row = document.querySelector('[data-code-row]'); if (row) row.hidden = false;
    var form = b.closest('form'), input = form && form.querySelector('[name="' + (b.getAttribute('data-email') || 'email') + '"]');
    if (!input || !input.value) { if (input) input.focus(); return; }
    var fd = new FormData(); fd.append('email', input.value); fd.append('_token', FB.csrf);
    b.disabled = true;
    request(b.getAttribute('data-send-code'), { method: 'POST', body: fd }).then(function (r) {
      if (!r.ok) { b.disabled = false; toast(r.error || FB.i18n.failed, 'error'); return; }
      toast(r.message || 'Sent', 'success');
      var left = 60, label = b.textContent;
      var tick = setInterval(function () { left--; b.textContent = label + ' (' + left + ')'; if (left <= 0) { clearInterval(tick); b.textContent = label; b.disabled = false; } }, 1000);
    });
  });

  /* ---------- "See N new or updated topics" on a Latest list ---------- */
  (function () {
    var box = $('[data-new-topics]'); if (!box) return;
    var btn = box.querySelector('button'), url = box.getAttribute('data-new-topics');
    function check() {
      if (document.hidden) return;
      request(url).then(function (r) {
        var n = r && r.ok ? (r.count | 0) : 0;
        if (n <= 0) return;
        btn.textContent = n === 1 ? box.getAttribute('data-one') : box.getAttribute('data-many').replace('%d', n);
        box.hidden = false;
      });
    }
    btn.addEventListener('click', function () { btn.disabled = true; window.location.reload(); });
    setInterval(check, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
  })();

  /* ---------- ajax forms ---------- */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches('form[data-ajax]')) return;
    if (form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm') || FB.i18n.confirm)) { e.preventDefault(); return; }
    e.preventDefault();
    var btn = form.querySelector('[type=submit]');
    if (btn) btn.disabled = true;
    // getAttribute: a field named "action" would shadow form.action
    request(form.getAttribute('action'), { method: 'POST', body: new FormData(form) }).then(function (r) {
      if (btn) btn.disabled = false;
      document.dispatchEvent(new CustomEvent('fb:ajax', { detail: { form: form, response: r } }));
      if (!r.ok) { toast(r.error || FB.i18n.failed, 'error'); return; }
      if (form.hasAttribute('data-composer')) { form.querySelector('textarea').value = ''; }
      $$('[data-editor]', form).forEach(function (ed) { if (ed.__fbEditor) ed.__fbEditor.clearDraft(); });
      if (form.querySelector('[data-like]') && typeof r.liked !== 'undefined') {
        var b = form.querySelector('[data-like]');
        b.classList.toggle('active', r.liked);
        b.querySelector('[data-count]').textContent = r.count || '';
        return;
      }
      if (form.querySelector('[data-bookmark]') && typeof r.bookmarked !== 'undefined') {
        var bb = form.querySelector('[data-bookmark]');
        bb.classList.toggle('active', r.bookmarked);
        bb.title = bb.querySelector('span').textContent = r.bookmarked ? 'Bookmarked' : 'Bookmark'; // the name is also the tooltip of the icon-only button
        return;
      }
      if (r.redirect) {
        // a new reply: the old code changed the hash and reloaded at once, so the browser kept the old scroll position (same page)
        // or the reload cut the navigation short (next page). Same page: reload without restoring the scroll; another page: go there.
        var to = new URL(r.redirect, location.href);
        if (to.hash && to.pathname === location.pathname && to.search === location.search) {
          try { history.scrollRestoration = 'manual'; } catch (x) {}
          history.replaceState(null, '', to.pathname + to.search + to.hash);
          window.location.reload();
        } else {
          if (!to.hash && to.pathname === location.pathname && to.search === location.search) keepPlace(form);
          window.location.href = to.href;
        }
        return;
      }
      window.location.reload();
    });
  });

  /* ---------- plain forms with confirm ---------- */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches('form[data-confirm]:not([data-ajax])') && !window.confirm(f.getAttribute('data-confirm') || FB.i18n.confirm)) e.preventDefault();
  });

  /* ---------- a form posted back to its own page keeps the reader's place ---------- */
  // a switch or a row button posts, the server redirects back to the same page, and a new page load starts at the top.
  // Remember where the form sat on screen; the next load of this page scrolls it back there (see misc below).
  function keepPlace(form) {
    try {
      sessionStorage.setItem('fb_keep_place', JSON.stringify({ path: location.pathname + location.search, y: window.scrollY, form: [].indexOf.call(document.forms, form), action: form.getAttribute('action') || '', top: form.getBoundingClientRect().top, t: Date.now() }));
    } catch (x) {}
  }
  document.addEventListener('submit', function (e) {
    var f = e.target; // ajax forms called preventDefault already and keep the page themselves
    if (e.defaultPrevented || (f.getAttribute('method') || '').toLowerCase() !== 'post' || f.getAttribute('target') === '_blank') return;
    keepPlace(f);
  });

  /* ---------- reply target / quote ---------- */
  function composer() { return $('form[data-composer]'); }
  function setReplyTarget(id, name) {
    var f = composer(); if (!f) return;
    var input = f.querySelector('[data-reply-to]'), box = f.querySelector('[data-reply-target]');
    if (input) input.value = id || '';
    if (box) { box.classList.toggle('hidden', !id); box.querySelector('span').textContent = id ? '@' + name : ''; }
    if (id) { f.scrollIntoView({ behavior: 'smooth', block: 'start' }); var ta = f.querySelector('textarea'); if (ta) ta.focus(); }
  }
  function quotePost(id) {
    request(FB.base + (FB.rewrite ? '/post/' + id + '/raw' : '/index.php?r=' + encodeURIComponent('/post/' + id + '/raw'))).then(function (r) {
      if (!r.ok) return;
      var f = composer(); if (!f) return;
      var ta = f.querySelector('textarea');
      var q = '> **@' + r.username + '** wrote:\n> ' + r.body.split('\n').join('\n> ') + '\n\n';
      ta.value = (ta.value ? ta.value.replace(/\s*$/, '\n\n') : '') + q;
      f.scrollIntoView({ behavior: 'smooth', block: 'start' });
      ta.focus();
    });
  }

  /* ---------- editor ----------
   * Public API for plugins: FB.editor.register('cmd', function (api, arg) {...}) handles buttons declared with
   * that cmd in the composer.toolbar region; FB.editor.get(el) returns the api of an editor element.
   * Every command dispatches a cancelable "fb:editor" event first (detail: {editor, api, cmd, arg}). */
  /* ---------- clipboard HTML -> Markdown: pasting a rendered post (or a web page) keeps bold, links, lists, code, tables ---------- */
  function htmlToMd(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    function text(s) { return s.replace(/\s+/g, ' '); }
    function kids(node, ctx) { var out = ''; node.childNodes.forEach(function (n) { out += one(n, ctx); }); return out; }
    function one(n, ctx) {
      if (n.nodeType === 3) return ctx.pre ? n.nodeValue : text(n.nodeValue);
      if (n.nodeType !== 1) return '';
      var t = n.tagName.toLowerCase(), inner;
      switch (t) {
        case 'br': return '\n';
        case 'strong': case 'b': inner = kids(n, ctx).trim(); return inner ? '**' + inner + '**' : '';
        case 'em': case 'i': inner = kids(n, ctx).trim(); return inner ? '*' + inner + '*' : '';
        case 'del': case 's': case 'strike': inner = kids(n, ctx).trim(); return inner ? '~~' + inner + '~~' : '';
        case 'code': return ctx.pre ? kids(n, ctx) : '`' + kids(n, ctx) + '`';
        case 'pre': inner = preText(n).replace(/^\n+/, '').replace(/\s+$/, ''); return inner ? '\n\n```\n' + inner + '\n```\n\n' : '';
        case 'a': inner = kids(n, ctx).trim(); var href = n.getAttribute('href') || ''; return href && inner ? '[' + inner + '](' + href + ')' : inner;
        case 'img': return '![' + (n.getAttribute('alt') || '') + '](' + (n.getAttribute('src') || '') + ')';
        case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6': return '\n\n' + '#'.repeat(Math.max(1, +t[1] - 1)) + ' ' + kids(n, ctx).trim() + '\n\n';
        case 'p': case 'div': case 'section': case 'article': return '\n\n' + kids(n, ctx).trim() + '\n\n';
        case 'blockquote': return '\n\n' + kids(n, ctx).trim().split('\n').map(function (l) { return '> ' + l; }).join('\n') + '\n\n';
        case 'ul': case 'ol':
          // a highlighter numbering its lines with a list is code, not a list (the numbers are drawn by CSS, never text)
          if (/linenum|line-number|code-lines|hljs-ln/i.test(n.className || '')) { inner = preText(n).replace(/^\n+/, '').replace(/\s+$/, ''); return inner ? '\n\n```\n' + inner + '\n```\n\n' : ''; }
          return '\n\n' + list(n, t === 'ol', ctx, '') + '\n\n';
        case 'li': return preText(n).replace(/\s+$/, ''); // an <li> on its own: half a list, dragged out of a numbered code block (preText already opens the line)
        case 'hr': return '\n\n---\n\n';
        case 'table': return '\n\n' + table(n, ctx) + '\n\n';
        case 'script': case 'style': case 'button': return '';
        default: return kids(n, ctx);
      }
    }
    /**
     * The text of a code block, exactly as it stands. Nothing inside a <pre> becomes markdown: sites wrap each line in
     * an <li> or a <div> to number them, and treating those as a list turned the whole block into one long line.
     */
    function preText(n) {
      if (n.nodeType === 3) return n.nodeValue;
      if (n.nodeType !== 1) return '';
      var t = n.tagName.toLowerCase();
      if (t === 'br') return '\n';
      if (t === 'script' || t === 'style' || t === 'button') return '';
      // a gutter cell holding only the line number is part of the decoration, not of the code
      if ((t === 'td' || t === 'th') && /^\s*\d+\s*$/.test(n.textContent || '') && n.parentElement && n.parentElement.children.length > 1) return '';
      var out = '';
      n.childNodes.forEach(function (c) { out += preText(c); });
      // a line of its own: the line-number lists and the row-per-line tables that highlighters produce
      if (t === 'li' || t === 'div' || t === 'p' || t === 'tr') out = '\n' + out.replace(/^\n+/, '');
      return out;
    }

    function list(el, ordered, ctx, indent) {
      var lines = [], i = 0;
      el.childNodes.forEach(function (li) {
        if (li.nodeType !== 1 || li.tagName.toLowerCase() !== 'li') return;
        var nested = '', line = '';
        li.childNodes.forEach(function (c) {
          var tg = c.nodeType === 1 ? c.tagName.toLowerCase() : '';
          if (tg === 'ul' || tg === 'ol') nested += '\n' + list(c, tg === 'ol', ctx, indent + '  ');
          else line += one(c, ctx);
        });
        lines.push(indent + (ordered ? (++i) + '. ' : '- ') + line.trim().replace(/\n+/g, ' ') + nested);
      });
      return lines.join('\n');
    }
    function table(el, ctx) {
      var rows = [];
      el.querySelectorAll('tr').forEach(function (tr, r) {
        var cells = [];
        tr.querySelectorAll('th,td').forEach(function (c) { cells.push(kids(c, ctx).trim().replace(/\n+/g, ' ').replace(/\|/g, '\\|')); });
        rows.push('| ' + cells.join(' | ') + ' |');
        if (r === 0) rows.push('|' + cells.map(function () { return '---'; }).join('|') + '|');
      });
      return rows.join('\n');
    }
    return kids(doc.body, {}).replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
  }
  FB.htmlToMd = htmlToMd;

  var editorCommands = {};
  function editorInit(ed) {
    if (ed.__fbEditor) return ed.__fbEditor;
    var ta = ed.querySelector('textarea'), preview = ed.querySelector('[data-preview]'), status = ed.querySelector('[data-status]'), fileInput = ed.querySelector('[data-upload-input]');
    var emojiBox = ed.querySelector('[data-emoji]'), helpBox = ed.querySelector('[data-help]'), banner = ed.querySelector('[data-draft-banner]');
    var previewTimer, draftTimer, previewOn = false;
    var api = {
      el: ed, textarea: ta,
      value: function (v) { if (typeof v === 'string') { ta.value = v; ta.dispatchEvent(new Event('input')); } return ta.value; },
      selection: function () { return [ta.selectionStart, ta.selectionEnd, ta.value.slice(ta.selectionStart, ta.selectionEnd)]; },
      replace: function (start, end, text, cursor) { ta.setRangeText(text, start, end, 'end'); if (typeof cursor === 'number') ta.selectionStart = ta.selectionEnd = start + cursor; ta.focus(); ta.dispatchEvent(new Event('input')); },
      insert: function (text, cursor) { var s = api.selection(); api.replace(s[0], s[1], text, cursor); },
      wrap: function (before, after, placeholder) { after = after == null ? before : after; var s = api.selection(), v = s[2] || placeholder || 'text'; api.replace(s[0], s[1], before + v + after, before.length + v.length + after.length); },
      prefix: function (p) { var s = api.selection(), start = ta.value.lastIndexOf('\n', s[0] - 1) + 1; var block = ta.value.slice(start, s[1]); api.replace(start, s[1], p + block.split('\n').join('\n' + p)); },
      block: function (text) { var s = api.selection(); var pre = s[0] > 0 && ta.value[s[0] - 1] !== '\n' ? '\n\n' : ''; api.replace(s[0], s[1], pre + text + '\n'); },
      upload: function (files) { Array.prototype.forEach.call(files, uploadOne); },
      preview: function (on) { setPreview(typeof on === 'boolean' ? on : !previewOn); },
      fullscreen: function (on) { var v = typeof on === 'boolean' ? on : !ed.classList.contains('fullscreen'); ed.classList.toggle('fullscreen', v); document.body.classList.toggle('editor-fs', v); if (v) ta.focus(); },
      status: function (text) { if (status) status.textContent = text || ''; },
      run: function (cmd, arg) {
        var ev = new CustomEvent('fb:editor', { detail: { editor: ed, api: api, cmd: cmd, arg: arg }, cancelable: true });
        if (!document.dispatchEvent(ev)) return;
        var fn = editorCommands[cmd];
        if (fn) fn(api, arg, ed);
      }
    };
    function setPreview(on) {
      previewOn = on;
      ed.classList.toggle('preview-only', on);
      preview.hidden = !on;
      var b = ed.querySelector('[data-cmd=preview]'); if (b) b.classList.toggle('active', on);
      if (on) renderPreview(); else if (!ed.classList.contains('wysiwyg')) ta.focus();
      syncModes();
    }
    /* the mode tabs (composer.modes): Write, Preview and plugin modes that switch themselves on and off with a command
       and mark the editor with a class while they are on (a visual editor: cmd wysiwyg, class wysiwyg) */
    var tabs = ed.querySelectorAll('[data-mode]');
    function modeNow() {
      if (previewOn) return 'preview';
      for (var i = 0; i < tabs.length; i++) { var c = tabs[i].getAttribute('data-mode-class'); if (c && ed.classList.contains(c)) return tabs[i].getAttribute('data-mode'); }
      return 'write';
    }
    function syncModes() {
      var now = modeNow();
      Array.prototype.forEach.call(tabs, function (t) { var on = t.getAttribute('data-mode') === now; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    }
    function setMode(mode) {
      if (mode === modeNow()) return;
      if (mode === 'preview') { setPreview(true); return; }
      if (previewOn) setPreview(false);
      Array.prototype.forEach.call(tabs, function (t) { // leave the plugin mode that is on, enter the one asked for
        var c = t.getAttribute('data-mode-class'), cmd = t.getAttribute('data-mode-cmd'), m = t.getAttribute('data-mode');
        if (c && cmd && ed.classList.contains(c) !== (m === mode)) api.run(cmd);
      });
      syncModes();
    }
    api.mode = function (m) { if (m) setMode(m); return modeNow(); };
    if (tabs.length && window.MutationObserver) new MutationObserver(syncModes).observe(ed, { attributes: true, attributeFilter: ['class'] }); // a plugin switching itself on at load
    function renderPreview() {
      var fd = new FormData(); fd.append('body', ta.value); fd.append('_token', FB.csrf);
      request(FB.api, { method: 'POST', body: fd }).then(function (r) { preview.innerHTML = r.ok ? (r.html || '<p class="muted">' + FB.i18n.nothing + '</p>') : '<p class="muted">' + (r.error || '') + '</p>'; });
    }
    function uploadOne(file) {
      var fd = new FormData(); fd.append('file', file); fd.append('_token', FB.csrf);
      api.status(FB.i18n.uploading + ' ' + file.name);
      var placeholder = '[' + file.name + '…]()';
      api.insert(placeholder);
      request(FB.upload, { method: 'POST', body: fd }).then(function (r) {
        api.status('');
        if (!r.ok) { toast(r.error || FB.i18n.failed, 'error'); api.value(ta.value.replace(placeholder, '')); return; }
        api.value(ta.value.replace(placeholder, r.markdown));
      });
    }
    /* drafts: per user and scope, kept in localStorage for N days, restored on request */
    var scope = ed.getAttribute('data-scope'), days = parseInt(ed.getAttribute('data-draft-days') || '0', 10);
    var draftKey = scope && FB.uid && days > 0 ? 'fb_draft_' + FB.uid + '_' + scope : null;
    function draftRead() { try { var d = JSON.parse(localStorage.getItem(draftKey) || 'null'); if (d && Date.now() - d.t < days * 86400000) return d.v; localStorage.removeItem(draftKey); } catch (e) {} return null; }
    function draftWrite() { try { if (ta.value.trim()) localStorage.setItem(draftKey, JSON.stringify({ v: ta.value, t: Date.now() })); else localStorage.removeItem(draftKey); } catch (e) {} }
    api.clearDraft = function () { try { if (draftKey) localStorage.removeItem(draftKey); } catch (e) {} };
    if (draftKey) {
      var saved = draftRead();
      if (saved && saved !== ta.value && banner) {
        banner.classList.remove('hidden');
        banner.querySelector('[data-draft-restore]').addEventListener('click', function () { api.value(saved); banner.classList.add('hidden'); });
        banner.querySelector('[data-draft-discard]').addEventListener('click', function () { api.clearDraft(); banner.classList.add('hidden'); });
      }
      ta.addEventListener('input', function () { clearTimeout(draftTimer); draftTimer = setTimeout(draftWrite, 500); });
    }
    /* events */
    ed.addEventListener('click', function (e) {
      var tab = e.target.closest('[data-mode]');
      if (tab) { setMode(tab.getAttribute('data-mode')); return; }
      var b = e.target.closest('[data-cmd]');
      if (b) { api.run(b.getAttribute('data-cmd'), b.getAttribute('data-arg')); return; }
      var em = e.target.closest('[data-emoji-char]');
      if (em) { api.insert(em.getAttribute('data-emoji-char') + ' '); emojiBox.classList.add('hidden'); }
    });
    ta.addEventListener('input', function () { if (previewOn) { clearTimeout(previewTimer); previewTimer = setTimeout(renderPreview, 400); } });
    ta.addEventListener('paste', function (e) {
      var cd = e.clipboardData; if (!cd) return;
      var items = cd.items || [], files = [];
      for (var i = 0; i < items.length; i++) if (items[i].kind === 'file') files.push(items[i].getAsFile());
      if (files.length) { if (fileInput) { e.preventDefault(); api.upload(files); } return; }
      var html = cd.getData('text/html');
      if (html && /<(b|strong|em|i|a|img|h[1-6]|ul|ol|pre|code|blockquote|table)\b/i.test(html)) { var md = htmlToMd(html); if (md) { e.preventDefault(); api.insert(md); } }
    });
    if (fileInput) {
      fileInput.addEventListener('change', function () { api.upload(fileInput.files); fileInput.value = ''; });
      ta.addEventListener('dragover', function (e) { e.preventDefault(); ed.classList.add('dragover'); });
      ta.addEventListener('dragleave', function () { ed.classList.remove('dragover'); });
      ta.addEventListener('drop', function (e) { e.preventDefault(); ed.classList.remove('dragover'); if (e.dataTransfer.files.length) api.upload(e.dataTransfer.files); });
    }
    ta.addEventListener('keydown', function (e) {
      var mod = e.ctrlKey || e.metaKey;
      if (e.key === 'Escape') { if (emojiBox && !emojiBox.classList.contains('hidden')) emojiBox.classList.add('hidden'); else if (helpBox && !helpBox.classList.contains('hidden')) helpBox.classList.add('hidden'); else if (ed.classList.contains('fullscreen')) api.fullscreen(false); return; }
      if (!mod) return;
      var map = { b: 'bold', i: 'italic', k: 'link' };
      if (e.key === 'Enter') { var f = ta.closest('form'); if (f) { e.preventDefault(); f.requestSubmit ? f.requestSubmit() : f.submit(); } return; }
      if (e.shiftKey && (e.key === '7' || e.key === '&')) { e.preventDefault(); api.run('ol'); return; }
      if (e.shiftKey && (e.key === '8' || e.key === '*')) { e.preventDefault(); api.run('ul'); return; }
      if (e.shiftKey && e.key.toLowerCase() === 'p') { e.preventDefault(); api.run('preview'); return; }
      if (map[e.key.toLowerCase()] && !e.shiftKey) { e.preventDefault(); api.run(map[e.key.toLowerCase()]); }
    });
    /* @mention autocomplete */
    var menu = document.createElement('div'); menu.className = 'mention-menu hidden'; ed.appendChild(menu);
    var mentionTimer;
    ta.addEventListener('input', function () {
      clearTimeout(mentionTimer);
      var pos = ta.selectionStart, before = ta.value.slice(0, pos), m = before.match(/(?:^|\s)@([^\s@]{1,30})$/); // a username, or the start of a display name in any script
      if (!m || !FB.uid) { menu.classList.add('hidden'); return; }
      mentionTimer = setTimeout(function () {
        request(FB.users + (FB.users.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(m[1])).then(function (r) {
          if (!r.ok || !r.users.length) { menu.classList.add('hidden'); return; }
          menu.textContent = '';
          r.users.forEach(function (u) {
            var b = document.createElement('button'); b.type = 'button'; b.dataset.name = u.username;
            if (u.avatar) { var im = document.createElement('img'); im.src = u.avatar; im.alt = ''; b.appendChild(im); }
            b.appendChild(document.createTextNode(u.name || u.username));
            if (u.name && u.name !== u.username) { var hd = document.createElement('small'); hd.textContent = '@' + u.username; b.appendChild(hd); } // a display name: which account it is
            menu.appendChild(b);
          });
          menu.classList.remove('hidden');
        });
      }, 150);
    });
    menu.addEventListener('click', function (e) {
      var b = e.target.closest('[data-name]'); if (!b) return;
      var pos = ta.selectionStart, before = ta.value.slice(0, pos).replace(/@[\w.-]*$/, '@' + b.getAttribute('data-name') + ' ');
      ta.value = before + ta.value.slice(pos); ta.selectionStart = ta.selectionEnd = before.length; ta.focus();
      menu.classList.add('hidden');
    });
    ed.__fbEditor = api;
    return api;
  }
  /* built-in commands (plugins may override any of them with FB.editor.register) */
  editorCommands.bold = function (api) { api.wrap('**'); };
  editorCommands.italic = function (api) { api.wrap('*'); };
  editorCommands.strike = function (api) { api.wrap('~~'); };
  editorCommands.heading = function (api) { api.prefix('## '); };
  editorCommands.quote = function (api) { api.prefix('> '); };
  editorCommands.ul = function (api) { api.prefix('- '); };
  editorCommands.ol = function (api) { var s = api.selection(), lines = (s[2] || 'item').split('\n'); api.replace(s[0], s[1], lines.map(function (l, i) { return (i + 1) + '. ' + l; }).join('\n')); };
  editorCommands.code = function (api) { var s = api.selection(); if (s[2].indexOf('\n') > -1) api.block('```\n' + s[2] + '\n```'); else api.wrap('`', '`', 'code'); };
  editorCommands.code_block = function (api) { var s = api.selection(); api.block('```\n' + (s[2] || 'code') + '\n```'); };
  editorCommands.link = function (api) { var s = api.selection(); var u = window.prompt('URL', 'https://'); if (u) api.replace(s[0], s[1], '[' + (s[2] || u) + '](' + u + ')'); };
  editorCommands.image = function (api) { var u = window.prompt('Image URL', 'https://'); if (u) api.insert('![](' + u + ')'); };
  editorCommands.upload = function (api, arg, ed) { var i = ed.querySelector('[data-upload-input]'); if (i) i.click(); };
  editorCommands.table = function (api) { api.block('| Column | Column |\n|---|---|\n| a | b |\n| c | d |'); };
  editorCommands.hr = function (api) { api.block('---'); };
  editorCommands.emoji = function (api, arg, ed) { var box = ed.querySelector('[data-emoji]'); if (box) box.classList.toggle('hidden'); };
  editorCommands.help = function (api, arg, ed) { var box = ed.querySelector('[data-help]'); if (box) box.classList.toggle('hidden'); };
  editorCommands.preview = function (api) { api.preview(); };
  editorCommands.fullscreen = function (api) { api.fullscreen(); };
  FB.editor = { register: function (cmd, fn) { editorCommands[cmd] = fn; }, get: function (el) { return el && el.__fbEditor; }, commands: editorCommands, init: editorInit };
  function initEditor(ed) { return editorInit(ed); }
  $$('[data-editor]').forEach(editorInit);
  FB.initEditor = initEditor;
  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-emoji],[data-cmd=emoji]')) $$('[data-emoji]').forEach(function (b) { b.classList.add('hidden'); });
    if (!e.target.closest('[data-help],[data-cmd=help]')) $$('[data-help]').forEach(function (b) { b.classList.add('hidden'); });
  });

  /* ---------- admin drawer: open an editor URL beside the list without leaving the page ---------- */
  function drawerClose(push) {
    var d = $('#drawer'), b = $('[data-drawer-backdrop]');
    var back = d ? d.getAttribute('data-back') : null;
    if (d) d.remove(); if (b) b.remove();
    document.body.classList.remove('adrawer-open');
    if (push && back) history.pushState({}, '', back);
  }
  function drawerOpen(url, push) {
    fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var d = doc.querySelector('#drawer'), b = doc.querySelector('[data-drawer-backdrop]');
      if (!d) { window.location.href = url; return; }
      drawerClose(false);
      document.body.appendChild(b); document.body.appendChild(d);
      document.body.classList.add('adrawer-open');
      $$('[data-editor]', d).forEach(initEditor);
      if (push) history.pushState({}, '', url);
      var first = d.querySelector('input:not([type=hidden]),select,textarea'); if (first) first.focus();
    }).catch(function () { window.location.href = url; });
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-drawer]');
    if (a) { e.preventDefault(); drawerOpen(a.href, true); return; }
    if (e.target.closest('[data-drawer-close]')) { e.preventDefault(); drawerClose(true); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && $('#drawer')) drawerClose(true); });
  window.addEventListener('popstate', function () { if ($('#drawer')) window.location.reload(); });
  if ($('#drawer')) document.body.classList.add('adrawer-open');

  /* ---------- times in the visitor's own time zone: <time datetime data-fmt> (the server printed the site zone as a fallback) ---------- */
  function localTimes(scope) {
    var times = (scope || document).querySelectorAll('time[datetime][data-fmt]'); if (!times.length || !window.Intl || !Intl.DateTimeFormat) return;
    var loc = (root.getAttribute('lang') || 'en') + '-u-ca-gregory', now = Date.now(), day = 864e5;
    function fmt(d, o) { try { return new Intl.DateTimeFormat(loc, o).format(d); } catch (e) { return ''; } }
    times.forEach(function (el) {
      var d = new Date(el.getAttribute('datetime')); if (isNaN(d.getTime())) return;
      var full = fmt(d, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
      if (full) el.title = full;
      var f = el.getAttribute('data-fmt'), age = now - d.getTime(), text = '';
      if (f === 'full') text = full;
      else if (f === 'month') text = fmt(d, { year: 'numeric', month: 'short' });
      else if (f === 'date') text = fmt(d, { year: 'numeric', month: 'short', day: 'numeric' });
      else if (age > 30 * day) text = fmt(d, age > 365 * day ? { year: 'numeric', month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric' });
      if (text) el.textContent = text;
    });
  }
  localTimes(document);

  /* ---------- link cards (link_card_html()): a picture that does not load takes its box with it, the card reads as a text card ---------- */
  function dropCardPicture(img) {
    var box = img.closest('.link-card-img'), card = box && box.closest('.link-card');
    if (!box) return;
    box.parentNode.removeChild(box);
    if (card) { card.classList.remove('link-card-large', 'link-card-small'); card.classList.add('link-card-text'); }
  }
  // error does not bubble: listen while it goes down; the pictures that failed before this script ran are found by the check below
  document.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && t.closest && t.closest('.link-card-img')) dropCardPicture(t); }, true);
  $$('.link-card-img img').forEach(function (img) { if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) dropCardPicture(img); });

  /* ---------- review queue (/review): a decision goes out without a reload, the item fades, the counts follow ---------- */
  document.addEventListener('click', function (e) {
    var r = e.target.closest ? e.target.closest('[data-review-reject]') : null;
    if (!r) return;
    var note = r.form.querySelector('.review-note');
    note.hidden = false; r.hidden = true;
    note.querySelector('input').focus();
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-review]')) return;
    e.preventDefault();
    var b = e.submitter;
    if (!b || !b.name) return;
    if (b.hasAttribute('data-confirm') && !window.confirm(b.getAttribute('data-confirm'))) return;
    var fd = new FormData(f); fd.append(b.name, b.value);
    $$('button', f).forEach(function (x) { x.disabled = true; });
    request(f.getAttribute('action'), { method: 'POST', body: fd }).then(function (res) {
      if (!res.ok) { $$('button', f).forEach(function (x) { x.disabled = false; }); toast(res.error || FB.i18n.failed, 'error'); return; }
      toast(res.message, res.done ? 'success' : 'error');
      var item = f.closest('[data-review-item]');
      if (res.done && item) { item.classList.add('is-done'); setTimeout(function () { item.remove(); }, 300); }
      $$('[data-tab="waiting"] .badge, a[href$="/review"] .me-count').forEach(function (c) { c.textContent = res.count > 0 ? res.count : ''; });
      if (b.value === 'trust' || b.value === 'ban') setTimeout(function () { window.location.reload(); }, 600); // other items of the same member changed too
    });
  });

  /* ---------- picture fields and saved keys (image_field(), secret_field()): a change is saved at once, a removal can be undone ---------- */
  (function () {
    function send(box, what, file) {
      var fd = new FormData();
      fd.append('_token', FB.csrf); fd.append('field', box.getAttribute('data-name')); fd.append('do', what);
      if (file) fd.append('file', file);
      box.classList.add('is-busy');
      return request(box.getAttribute('data-action'), { method: 'POST', body: fd }).then(function (r) {
        box.classList.remove('is-busy');
        if (!r || !r.ok) { toast((r && r.error) || FB.i18n.failed, 'error'); return null; }
        return r;
      });
    }
    function paint(box, url) {
      if (box.hasAttribute('data-secret-field')) { box.classList.toggle('is-empty', !url); box.classList.remove('is-changing'); return; }
      var thumb = box.querySelector('.image-field-thumb'), tpl = box.querySelector('template');
      if (url) { var i = document.createElement('img'); i.alt = ''; i.src = url; thumb.replaceChildren(i); }
      else thumb.replaceChildren(tpl.content.cloneNode(true));
      box.classList.toggle('is-empty', !url);
      box.dispatchEvent(new CustomEvent('fb:image', { bubbles: true, detail: { name: box.getAttribute('data-name'), url: url } }));
    }
    document.addEventListener('change', function (e) {
      var input = e.target, box = input.closest && input.closest('[data-image-field][data-action]');
      if (!box || input.type !== 'file' || !input.files || !input.files[0]) return;
      send(box, 'upload', input.files[0]).then(function (r) { input.value = ''; if (r) { paint(box, r.url); toast(r.message, 'success'); } });
    });
    document.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-field-remove]');
      if (rm) {
        var box = rm.closest('[data-action]'); if (!box) return;
        e.preventDefault();
        send(box, 'remove').then(function (r) {
          if (!r) return;
          paint(box, '');
          toast(r.message, 'info', { label: FB.i18n.undo || 'Undo', run: function () { send(box, 'restore').then(function (x) { if (x) { paint(box, x.url || '1'); toast(x.message, 'success'); } }); } });
        });
        return;
      }
      var sw = e.target.closest('[data-secret-change],[data-secret-cancel]');
      if (sw) {
        var f = sw.closest('[data-secret-field]'), on = sw.hasAttribute('data-secret-change'), input = f.querySelector('input');
        f.classList.toggle('is-changing', on);
        if (on) input.focus(); else input.value = '';
      }
    });
  })();

  /* ---------- Settings → General → Logo: the preview follows the choices (and a picked file) before they are saved ---------- */
  (function () {
    var pv = $('[data-logo-preview]'); if (!pv) return;
    var form = pv.closest('form'), name = form.querySelector('[name=site_name]');
    form.addEventListener('change', function (e) {
      var t = e.target, n = t.getAttribute('data-logo-file');
      if (t.name === 'logo_style') pv.setAttribute('data-style', t.value);
      else if (t.name === 'logo_phone_name') pv.setAttribute('data-phone-name', t.checked ? '1' : '0');
      else if (n && t.files && t.files[0]) {
        var url = URL.createObjectURL(t.files[0]), img = function () { var i = document.createElement('img'); i.alt = ''; i.src = url; return i; };
        var thumb = $('[data-logo-thumb="' + n + '"]'); if (thumb) thumb.replaceChildren(img());
        if (n === 'site_icon') { pv.setAttribute('data-icon', '1'); $$('.pv-icon', pv).forEach(function (s) { s.replaceChildren(img()); }); }
        else if (n === 'site_logo') { pv.setAttribute('data-logo', '1'); $$('.pv-logo', pv).forEach(function (i) { i.src = url; }); }
        else if (n === 'site_logo_dark') { pv.setAttribute('data-dark', '1'); $$('.pv-logo-dark', pv).forEach(function (i) { i.src = url; }); }
      }
    });
    if (name) name.addEventListener('input', function () { $$('.pv-name', pv).forEach(function (s) { s.textContent = name.value; }); });
    // a picture saved, removed or brought back by its field (fb:image from the picture fields above)
    form.addEventListener('fb:image', function (e) {
      var n = e.detail.name, url = e.detail.url, box = e.target;
      var img = function () { var i = document.createElement('img'); i.alt = ''; i.src = url; return i; };
      if (n === 'site_icon') { pv.setAttribute('data-icon', url ? '1' : '0'); $$('.pv-icon', pv).forEach(function (s) { s.replaceChildren(url ? img() : box.querySelector('template').content.cloneNode(true)); }); }
      else if (n === 'site_logo' || n === 'site_logo_dark') { pv.setAttribute(n === 'site_logo' ? 'data-logo' : 'data-dark', url ? '1' : '0'); $$(n === 'site_logo' ? '.pv-logo' : '.pv-logo-dark', pv).forEach(function (i) { if (url) i.src = url; else i.removeAttribute('src'); }); }
    });
  })();

  /* ---------- topic lists that load while scrolling (setting list_paging): the next page joins the list near its end ----------
   * Follows the rel="next" link of the page numbers under the list and swaps them for the new page's, so they always say
   * where the reader is. Five pages load by themselves, then the button asks (the footer stays reachable); a press resets it.
   * New rows fire "fb:content" on document (detail: {root, nodes}) for plugins that set up rows one by one. */
  (function () {
    var more = $('[data-list-more]'), rows = $('.topic-rows'); if (!more || !rows) return;
    var btn = more.querySelector('button'), label = btn.textContent, busy = false, auto = 0, io = null;
    function nextUrl() { var a = $('.pagination a[rel=next]'); return a ? a.href : ''; }
    function load(byHand) {
      var url = nextUrl(); if (busy || !url) return;
      if (byHand) auto = 0; else if (++auto > 5) return;
      busy = true; btn.disabled = true; btn.textContent = more.getAttribute('data-loading');
      fetch(url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); }).then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html'), got = doc.querySelector('.topic-rows'), seen = {}, added = [];
        rows.querySelectorAll('[data-topic-id]').forEach(function (el) { seen[el.getAttribute('data-topic-id')] = 1; });
        if (got) Array.prototype.slice.call(got.querySelectorAll('[data-topic-id]')).forEach(function (el) {
          if (seen[el.getAttribute('data-topic-id')]) return; // a topic that moved up a page while reading is already here
          var node = document.importNode(el, true); rows.appendChild(node); added.push(node);
        });
        var pag = $('.pagination'), fresh = doc.querySelector('.pagination');
        if (pag && fresh) pag.replaceWith(document.importNode(fresh, true)); else if (pag) pag.remove();
        try { history.replaceState(history.state, '', url); } catch (e) {}
        added.forEach(localTimes);
        document.dispatchEvent(new CustomEvent('fb:content', { detail: { root: rows, nodes: added } }));
        btn.disabled = false; btn.textContent = label;
        if (!nextUrl()) { btn.remove(); more.classList.add('is-end'); more.textContent = more.getAttribute('data-end'); if (io) io.disconnect(); }
        else if (io) { io.unobserve(more); io.observe(more); } // still near the end (short rows, tall screen): observing again asks once more
      }).catch(function () { btn.disabled = false; btn.textContent = more.getAttribute('data-retry'); auto = 5; }).then(function () { busy = false; });
    }
    btn.addEventListener('click', function () { load(true); });
    if ('IntersectionObserver' in window) { io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) load(false); }, { rootMargin: '0px 0px 600px 0px' }); io.observe(more); }
  })();

  /* ---------- posting limits: the notice counts down and brings the form back by itself ---------- */
  (function () {
    var box = $('[data-hold-until]');
    if (!box) return;
    var until = parseInt(box.getAttribute('data-hold-until'), 10) || 0;
    var text = box.querySelector('[data-hold-text]');
    var words = function (left) {
      if (left < 60) return left + 's';
      if (left < 3600) return Math.ceil(left / 60) + 'm';
      return Math.floor(left / 3600) + 'h ' + Math.floor((left % 3600) / 60) + 'm';
    };
    var first = text ? text.textContent : '';
    var tick = function () {
      var left = until - Math.floor(Date.now() / 1000);
      if (left <= 0) { window.location.reload(); return; }
      if (left <= 600 && text) text.textContent = first.replace(/[\d]+\s*\S+(\s+[\d]+\s*\S+)?\.$/, words(left) + '.');
      setTimeout(tick, left <= 600 ? 1000 : 30000);
    };
    setTimeout(tick, until - Math.floor(Date.now() / 1000) <= 600 ? 1000 : 30000);
  })();

  /* ---------- misc ---------- */
  var flash = $('[data-flash]');
  var kept = null;
  try { kept = JSON.parse(sessionStorage.getItem('fb_keep_place') || 'null'); sessionStorage.removeItem('fb_keep_place'); } catch (x) {}
  if (kept && Date.now() - kept.t < 20000 && kept.path === location.pathname + location.search && !location.hash) {
    if (flash) { toast(flash.textContent, flash.classList.contains('flash-error') ? 'error' : 'success'); flash.remove(); flash = null; }
    // the same form (same position among the forms, same action) goes back to the same height on screen; otherwise the old scroll offset
    var keptMoved = false, backToPlace = function () {
      if (keptMoved) return;
      var f = document.forms[kept.form];
      if (f && (f.getAttribute('action') || '') === kept.action) window.scrollBy(0, f.getBoundingClientRect().top - kept.top);
      else window.scrollTo(0, kept.y);
    };
    ['wheel', 'touchmove', 'keydown'].forEach(function (ev) { window.addEventListener(ev, function () { keptMoved = true; }, { once: true, passive: true }); });
    backToPlace();
    window.addEventListener('load', backToPlace, { once: true });
  }
  if (flash) setTimeout(function () { flash.classList.add('fade'); }, 4000);
  var target = location.hash === '#new' ? $('#new') : (location.hash.indexOf('#post-') === 0 ? $(location.hash) : null);
  if (target) {
    {
      if (target.classList.contains('post')) target.classList.add('highlight'); // a linked or new post flashes; the "New replies" line is enough on its own
      // bring the post under the top bar now and again once pictures above it have loaded, unless the reader has scrolled meanwhile
      var moved = false, land = function () { if (!moved) target.scrollIntoView({ block: 'start' }); };
      ['wheel', 'touchmove', 'keydown'].forEach(function (ev) { window.addEventListener(ev, function () { moved = true; }, { once: true, passive: true }); });
      land();
      window.addEventListener('load', land, { once: true });
    }
  }
  // keep unread badge fresh every 60s
  if (FB.uid) setInterval(function () {
    request(FB.base + (FB.rewrite ? '/api/unread' : '/index.php?r=%2Fapi%2Funread')).then(function (r) {
      var b = $('[data-unread]'); if (!b || !r.ok) return;
      b.textContent = r.count; b.classList.toggle('hidden', !r.count);
    });
  }, 60000);
  /* ---------- mobile drawer: swipe left to close (the panel follows the finger, then snaps) ---------- */
  (function () {
    var sx = 0, sy = 0, dx = 0, tracking = false, panel = null;
    document.addEventListener('touchstart', function (e) {
      if (!document.body.classList.contains('drawer-open')) return;
      panel = e.target.closest('.col-left');
      if (!panel && !e.target.closest('.drawer-backdrop')) return;
      sx = e.touches[0].clientX; sy = e.touches[0].clientY; dx = 0; tracking = true;
    }, { passive: true });
    document.addEventListener('touchmove', function (e) {
      if (!tracking) return;
      dx = e.touches[0].clientX - sx;
      if (Math.abs(e.touches[0].clientY - sy) > Math.abs(dx)) { dx = 0; return; } // a vertical scroll, not a swipe
      var toward = document.documentElement.getAttribute('dir') === 'rtl' ? -dx : dx; // the drawer sits on the start edge
      if (panel && toward < 0) { panel.style.transition = 'none'; panel.style.transform = 'translateX(' + dx + 'px)'; }
    }, { passive: true });
    document.addEventListener('touchend', function () {
      if (!tracking) return;
      tracking = false;
      if (panel) { panel.style.transition = ''; panel.style.transform = ''; }
      if ((document.documentElement.getAttribute('dir') === 'rtl' ? -dx : dx) < -60) document.body.classList.remove('drawer-open');
    });
  })();
  // next tick: the plugin bundle is a deferred script after this one, so its listeners exist by then
  setTimeout(function () { document.dispatchEvent(new CustomEvent('fb:ready')); }, 0);
})();
