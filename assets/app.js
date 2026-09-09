/* flatbb front-end. Vanilla JS, no build step. Everything hangs off data-* attributes so plugins
 * can reuse the same behaviours: data-ajax forms, data-dropdown, data-toggle, data-editor, data-confirm.
 * Plugins get window.FB (base, csrf, uid) and can listen for document events: fb:ajax, fb:editor.
 */
(function () {
  'use strict';
  var FB = window.FB || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- helpers ---------- */
  function toast(msg, type) {
    var el = document.createElement('div');
    el.className = 'toast toast-' + (type || 'info');
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function () { el.classList.add('show'); }, 10);
    setTimeout(function () { el.classList.remove('show'); setTimeout(function () { el.remove(); }, 300); }, 3500);
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
  function applyTheme(t) { root.setAttribute('data-theme', t); try { localStorage.setItem('fb_theme', t); } catch (e) {} }
  try { var saved = localStorage.getItem('fb_theme'); if (saved) root.setAttribute('data-theme', saved); } catch (e) {}
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

  /* ---------- icon picker: a tile sets the hidden input (Admin → Categories) ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-icon-pick]');
    if (!b) return;
    var p = b.closest('[data-icon-picker]');
    p.querySelector('input[name=icon]').value = b.getAttribute('data-icon-pick');
    Array.prototype.forEach.call(p.querySelectorAll('.icon-pick'), function (x) { x.classList.toggle('active', x === b); });
  });

  /* ---------- global click handling ---------- */
  document.addEventListener('click', function (e) {
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
    var cp = e.target.closest('[data-copy]');
    if (cp && navigator.clipboard) {
      e.preventDefault();
      navigator.clipboard.writeText(cp.getAttribute('data-copy')).then(function () { toast(FB.i18n.copied, 'success'); }, function () { window.location.href = cp.href; });
    }
  });

  /* ---------- email verification codes ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-send-code]'); if (!b) return;
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
        bb.querySelector('span').textContent = r.bookmarked ? 'Bookmarked' : 'Bookmark';
        return;
      }
      if (r.redirect) { window.location.href = r.redirect; if (r.redirect.indexOf('#') > -1) window.location.reload(); return; }
      window.location.reload();
    });
  });

  /* ---------- plain forms with confirm ---------- */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches('form[data-confirm]:not([data-ajax])') && !window.confirm(f.getAttribute('data-confirm') || FB.i18n.confirm)) e.preventDefault();
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
      ed.classList.toggle('split', on && window.innerWidth >= 768);
      ed.classList.toggle('preview-only', on && window.innerWidth < 768);
      preview.hidden = !on;
      var b = ed.querySelector('[data-cmd=preview]'); if (b) b.classList.toggle('active', on);
      if (on) renderPreview(); else ta.focus();
    }
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
      var pos = ta.selectionStart, before = ta.value.slice(0, pos), m = before.match(/(?:^|\s)@([\w.-]{1,30})$/);
      if (!m || !FB.uid) { menu.classList.add('hidden'); return; }
      mentionTimer = setTimeout(function () {
        request(FB.users + (FB.users.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(m[1])).then(function (r) {
          if (!r.ok || !r.users.length) { menu.classList.add('hidden'); return; }
          menu.textContent = '';
          r.users.forEach(function (u) {
            var b = document.createElement('button'); b.type = 'button'; b.dataset.name = u.username;
            if (u.avatar) { var im = document.createElement('img'); im.src = u.avatar; im.alt = ''; b.appendChild(im); }
            b.appendChild(document.createTextNode(u.username)); menu.appendChild(b);
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
  (function () {
    var times = $$('time[datetime][data-fmt]'); if (!times.length || !window.Intl || !Intl.DateTimeFormat) return;
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
  if (flash) setTimeout(function () { flash.classList.add('fade'); }, 4000);
  if (location.hash && location.hash.indexOf('#post-') === 0) {
    var target = $(location.hash); if (target) target.classList.add('highlight');
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
