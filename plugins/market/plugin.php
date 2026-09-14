<?php
/**
 * Market — browse, install and update plugins from the flatbb marketplace at www.flatbb.com,
 * and publish your own plugins from Admin → Plugins.
 */
if (!defined('FLATBB')) exit;

const MARKET_CACHE_TTL = 900;

require_once __DIR__ . '/admin.php';

function market_endpoint(): string
{
    return rtrim((string)config('market_endpoint', FLATBB_MARKET_ENDPOINT), '/');
}

/** Identifies this forum to the marketplace (install statistics). */
function market_site_headers(): array
{
    return ['X-Flatbb-Site: ' . base_url(), 'X-Flatbb-Version: ' . FLATBB_VERSION];
}

/** GET a URL with curl (timeouts, size cap). Returns body or null. */
function market_http_get(string $url, int $max_bytes = 20971520, ?string &$error = null, array $headers = []): ?string
{
    if (!function_exists('curl_init')) { $error = 'curl extension missing'; return null; }
    if (http_self_request_blocked($url)) { $error = 'self request skipped on the dev server'; return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' market', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, application/zip'], market_site_headers(), $headers)]);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static fn($r, $dl_total, $dl): int => $dl > $max_bytes ? 1 : 0);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    $body = http_exec_prefer_local($ch, $url);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) {
        // the marketplace explains a refusal in JSON (a plugin that costs points and is not yours, a gated download): pass that on
        $said = is_string($body) ? (string)(json_decode_array($body)['error'] ?? '') : '';
        $error = $said !== '' ? $said : ($err !== '' ? $err : 'HTTP ' . $status);
        return null;
    }
    return (string)$body;
}

/** Marketplace listing, cached for 15 minutes in data/cache/market_list.json. */
function market_list(bool $refresh = false, string $q = ''): array
{
    $file = CACHE_DIR . '/market_list.json';
    if (!$refresh && $q === '' && is_file($file) && now() - (int)filemtime($file) < MARKET_CACHE_TTL) {
        $d = json_decode_array((string)file_get_contents($file));
        if ($d !== []) return $d;
    }
    $body = market_http_get(market_endpoint() . '/plugins' . ($q !== '' ? '?q=' . rawurlencode($q) : ''), 2097152, $error);
    if ($body === null) return ['error' => $error ?? 'unreachable', 'plugins' => [], 'core' => []];
    $d = json_decode_array($body);
    if (!isset($d['plugins'])) return ['error' => 'bad response', 'plugins' => [], 'core' => []];
    if ($q === '') @file_put_contents($file, $body, LOCK_EX);
    return $d;
}

/** "Authorization: Bearer …" for the connected account, or [] when none is connected. */
function market_auth_headers(): array
{
    $token = market_token();
    return $token !== '' ? ['Authorization: Bearer ' . $token] : [];
}

/** POST form fields to a marketplace API path with the account token. The decoded JSON, or ['ok' => false, 'error' => …]. */
function market_api_post(string $path, array $fields): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl extension missing'];
    $url = market_endpoint() . $path;
    if (http_self_request_blocked($url)) return ['ok' => false, 'error' => 'self request skipped on the dev server'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' market', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], market_site_headers(), market_auth_headers())]);
    $body = http_exec_prefer_local($ch, $url);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body)) return ['ok' => false, 'error' => $err !== '' ? $err : 'unreachable'];
    $d = json_decode_array($body);
    return isset($d['ok']) ? $d : ['ok' => false, 'error' => 'bad response'];
}

/** Get a plugin for points with the connected account. ['ok' => bool, 'message' => …]; the account cache is refreshed. */
function market_buy(string $id): array
{
    if (market_token() === '') return ['ok' => false, 'message' => t('Connect your www.flatbb.com account first (Marketplace → Account).')];
    $r = market_api_post('/buy', ['id' => $id]);
    if (!empty($r['ok'])) market_account(true);
    return ['ok' => !empty($r['ok']), 'message' => (string)($r['message'] ?? $r['error'] ?? '')];
}

/** Switch a freshly installed plugin on (an update keeps its state); answers the message to show. */
function market_enable_after_install(string $id, string $version): string
{
    if (plugin_enabled($id)) return t('%s %s installed.', $id, $version);
    try { plugin_enable($id); return t('%s %s installed and enabled.', $id, $version); }
    catch (Throwable $e) { return t('%s %s installed, but it could not be enabled: %s', $id, $version, $e->getMessage()); }
}

/** Download a plugin package from the marketplace and install it. Returns the installed version. */
function market_install(string $id, string $version = ''): string
{
    if (!plugin_id_valid($id)) throw new RuntimeException('Invalid plugin id');
    $url = market_endpoint() . '/plugins/' . $id . '/download' . ($version !== '' ? '?version=' . rawurlencode($version) : '');
    $zip_body = market_http_get($url, 20971520, $error, market_auth_headers()); // a plugin that costs points is released to the connected account
    if ($zip_body === null) throw new RuntimeException('Download failed: ' . $error);
    $tmp = CACHE_DIR . '/market_' . $id . '_' . random_token(4) . '.zip';
    file_put_contents($tmp, $zip_body, LOCK_EX);
    try {
        $installed = plugin_install_zip($tmp);
    } finally {
        @unlink($tmp);
    }
    if ($installed !== $id) throw new RuntimeException('Package id mismatch: ' . $installed);
    $m = plugin_read_manifest($id);
    fire('market.after_install', ['id' => $id, 'version' => (string)($m['version'] ?? '')]);
    return (string)($m['version'] ?? '');
}

/** Ids the marketplace runs itself or ships as an example: it refuses them, so no Publish button. */
function market_reserved_ids(): array
{
    return ['market', 'market_server', 'hello', 'quality'];
}

/** "Publish" button on Admin → Plugins rows (the form asks for the token the first time). */
function market_plugin_ops(string $ops, array $ctx): string
{
    $id = (string)($ctx['plugin']['id'] ?? '');
    if ($id === '' || in_array($id, market_reserved_ids(), true)) return $ops;
    return $ops . '<a class="btn btn-sm" href="' . h(url('/admin/ext/market/publish', ['id' => $id])) . '">' . icon('upload') . t('Publish') . '</a>';
}

/** GET: the publish form (changelog, screenshots, the token the first time). POST: package and upload. */
/** The marketplace account behind a token: username, is_admin, points, purchased (plugin ids); [] when it cannot be asked. */
function market_whoami(string $token): array
{
    $token = trim($token);
    if ($token === '' || !function_exists('curl_init')) return [];
    $url = market_endpoint() . '/whoami';
    if (http_self_request_blocked($url)) return [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'flatbb/' . FLATBB_VERSION . ' market', CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $token, 'Accept: application/json'], market_site_headers())]);
    $body = http_exec_prefer_local($ch, $url);
    curl_close($ch);
    $d = is_string($body) ? json_decode_array($body) : [];
    return !empty($d['ok']) && isset($d['username']) ? ['username' => (string)$d['username'], 'is_admin' => !empty($d['is_admin']), 'points' => (int)($d['points'] ?? 0), 'purchased' => array_values(array_map('strval', (array)($d['purchased'] ?? [])))] : [];
}

/**
 * Whether publishing $id from this forum means publishing someone else's plugin: ['confirm' => bool, 'text' => …] or null when it is
 * plainly the admin's own. Compares the marketplace account behind the token with the id's publisher (or, for a new id, the manifest author).
 */
function market_publish_other(string $id, array $m, string $token): ?array
{
    $listed = market_cached_plugin($id);
    $me = market_whoami($token);
    $mine = (string)($me['username'] ?? '');
    $author = trim((string)(plugin_read_manifest($id)['author'] ?? $m['author'] ?? '')); // the registry row has no author; the manifest file does
    if ($listed !== null) {
        $publisher = (string)($listed['publisher'] ?? '');
        if ($mine !== '' && $publisher !== '' && strcasecmp($publisher, $mine) === 0) return null;
        if ($mine !== '' && $publisher !== '') return ['confirm' => true, 'text' => t('On the marketplace, %1$s is published by %2$s, not by your account (%3$s).', $id, $publisher, $mine) . ' '
            . (!empty($me['is_admin']) ? t('Your account is a marketplace administrator, so this would go through and replace their published version.') : t('The marketplace refuses this unless you are one of its administrators.'))];
        $shown = $publisher !== '' ? $publisher : (string)($listed['author'] ?? '');
        return $shown !== '' && ($mine === '' || strcasecmp($shown, $mine) !== 0)
            ? ['confirm' => true, 'text' => t('%1$s is already on the marketplace, listed under %2$s. Publishing a version of a plugin or theme that is not yours replaces theirs.', $id, $shown)]
            : null;
    }
    if ($author === '' || ($mine !== '' && strcasecmp($author, $mine) === 0)) return null;
    return $mine !== ''
        ? ['confirm' => true, 'text' => t('The manifest names %1$s as the author, and your marketplace account is %2$s. Publish only plugins and themes you made or were given permission to publish.', $author, $mine)]
        : ['confirm' => false, 'text' => t('The manifest names %s as the author. The marketplace could not be asked whose account your token is, so make sure this plugin is yours to publish.', $author)];
}

function market_admin_publish(string $page): never
{
    need_admin();
    $id = is_post() ? post_str('id', 40) : get_str('id', 40);
    if (!isset(plugins()[$id])) fail(t('Plugin not found.'), url('/admin/plugins'));
    $is_theme = function_exists('plugin_is_theme') && plugin_is_theme(is_array(plugins()[$id]['manifest'] ?? null) ? plugins()[$id]['manifest'] : []);
    $list = $is_theme ? admin_url('themes') : url('/admin/plugins'); // where the admin came from and goes back to
    if (in_array($id, market_reserved_ids(), true)) fail(t('%s is part of the marketplace itself and cannot be published.', $id), $list);
    $saved = market_token();
    if (is_post()) {
        $pasted = trim(post_str('token', 120));
        if ($pasted !== '') plugin_save_settings('market', ['token' => $pasted] + plugin_settings('market')); // pasted once, kept in the plugin settings
        $token = $pasted !== '' ? $pasted : $saved;
        if ($token === '') fail(t('Publishing to the marketplace needs a developer token.'), url('/admin/ext/market/publish', ['id' => $id]));
        $confirm = post_str('confirm_other', 2) === '1';
        $other = market_publish_other($id, plugins()[$id], $token);
        if ($other !== null && $other['confirm'] && !$confirm) fail(t('Tick the confirmation first: you are about to publish something someone else made.'), url('/admin/ext/market/publish', ['id' => $id]));
        $shots = array_map(static fn(array $f): array => ['path' => $f['tmp_name'], 'name' => $f['name']], upload_files_list('images'));
        $r = plugin_publish($id, $token, post_str('changelog', 2000), '', false, $shots, $confirm);
        $category = $is_theme ? '' : post_str('category', 40);
        $note = '';
        if ($r['ok'] && $category !== '') { // the package carries no category: it is set on the marketplace right after the upload
            $c = market_api_post('/category', ['id' => $id, 'category' => $category]);
            if (empty($c['ok'])) $note = ' ' . t('The category could not be saved: %s', (string)($c['error'] ?? 'no answer'));
        }
        if ($r['ok']) market_list(true);
        flash($r['message'] . (!empty($r['url']) ? ' ' . $r['url'] : '') . $note, $r['ok'] && $note === '' ? 'success' : 'error');
        redirect($list);
    }
    $m = plugins()[$id];
    $body = '<form method="post" action="' . h(url('/admin/ext/market/publish')) . '" enctype="multipart/form-data" class="admin-form">' . csrf_field() . '<input type="hidden" name="id" value="' . h($id) . '">'
        . '<p class="muted">' . t('%s %s is packaged from plugins/%s and uploaded to the marketplace. Publishing may take a minute: the marketplace checks the package before it answers.', h((string)$m['name']), h((string)$m['version']), h($id)) . '</p>';
    if ($saved === '') {
        $body .= '<p class="muted">' . t('Publishing needs the token of your www.flatbb.com account. Create one at %s (Settings → Developer), paste it here once; it is kept under Marketplace → Account (or click Connect there).', '<a href="https://www.flatbb.com/settings/developer" target="_blank" rel="noopener">www.flatbb.com</a>') . '</p>'
            . form_row(t('Developer token'), input('token', '', ['required' => true, 'maxlength' => 120, 'autofocus' => true, 'autocomplete' => 'off', 'placeholder' => 'fbk_…']));
    }
    $other = $saved !== '' ? market_publish_other($id, $m, $saved) : null;
    if ($other !== null) {
        $body .= '<div class="market-other' . ($other['confirm'] ? ' market-other-stop' : '') . '">' . icon('alert') . '<div><p>' . h($other['text']) . '</p>'
            . ($other['confirm'] ? checkbox('confirm_other', false, t('Yes, publish %s although someone else made it', $id)) : '') . '</div></div>';
    }
    if (!$is_theme) $body .= market_category_field($id);
    $body .= form_row(t('Changelog for this version'), textarea('changelog', '', ['rows' => 6, 'maxlength' => 2000]), t('Shown in the version history on the marketplace page.'))
        . market_shots_field($is_theme
            ? t('The first screenshot is the cover in the Themes list; drag the pictures to change the order. A new theme needs at least one; 1200 × 800 works best. Up to 5, jpg / png / gif / webp, 2 MB each. New screenshots replace the current ones; add none to keep them.')
            : t('The first screenshot is the cover in the Plugins list; drag the pictures to change the order. Optional, up to 5, jpg / png / gif / webp, 2 MB each. New screenshots replace the current ones; add none to keep them.'))
        . '<div class="form-actions"><button type="submit" class="btn btn-primary">' . icon('upload') . t('Publish %s', $id) . '</button> <a class="btn" href="' . h($list) . '">' . t('Cancel') . '</a></div></form>';
    admin_page(t('Publish %s', $id), $body, $is_theme ? 'themes' : 'ext.market.market');
}

/** The marketplace categories (slug, name, description, count) from the cached listing; asked again once when the cache predates them. */
function market_categories(): array
{
    $cats = (array)(market_list()['categories'] ?? []);
    if ($cats === []) $cats = (array)(market_list(true)['categories'] ?? []);
    return array_values(array_filter($cats, static fn($c): bool => is_array($c) && isset($c['slug'], $c['name'])));
}

/** The Category select of the Publish form, preset with the plugin's category on the marketplace; '' when the marketplace has none. */
function market_category_field(string $id): string
{
    $cats = market_categories();
    if ($cats === []) return '';
    $options = ['' => t('Choose a category…')];
    foreach ($cats as $c) $options[(string)$c['slug']] = (string)$c['name'];
    return form_row(t('Category'), select('category', $options, (string)(market_cached_plugin($id)['category'] ?? ''), ['required' => true]), t('Where the plugin is listed on the marketplace. You can change it with any new version, or under Manage on the marketplace.'));
}

/** A category select on Marketplace submits itself (no inline handlers: the Content Security Policy forbids them). */
function market_js(): string
{
    return "document.addEventListener('change',function(e){var s=e.target.closest('select[data-market-submit]');if(s&&s.form)s.form.submit();});";
}

/**
 * The screenshot picker: thumbnails, the first marked as the cover, drag (or the arrow keys on the handle) to reorder, × to remove,
 * an Add tile. The chosen files stay in the images[] input, in the order shown; without JavaScript it is a plain file input.
 */
function market_shots_field(string $help): string
{
    $grip = '<button type="button" class="market-shot-grip" aria-label="' . h(t('Drag to reorder')) . '"><svg viewBox="0 0 10 16" width="10" height="16" fill="currentColor" aria-hidden="true"><circle cx="3" cy="3" r="1.5"/><circle cx="7" cy="3" r="1.5"/><circle cx="3" cy="8" r="1.5"/><circle cx="7" cy="8" r="1.5"/><circle cx="3" cy="13" r="1.5"/><circle cx="7" cy="13" r="1.5"/></svg></button>';
    $tiles = '';
    $data = ['max' => 5, 'max-bytes' => 2097152, 'cover' => t('Cover'), 'remove' => t('Remove'), 'reorder' => t('Drag to reorder'), 'err-type' => t('{name} is not a jpg, png, gif or webp image.'), 'err-size' => t('{name} is larger than 2 MB.'), 'err-count' => t('Up to %d screenshots.', 5)];
    $attr = '';
    foreach ($data as $k => $v) $attr .= ' data-' . $k . '="' . h((string)$v) . '"';
    return form_row(t('Screenshots'), '<div class="market-shots" data-market-shots' . $attr . '><div class="market-shots-grid">' . $tiles
        . '<label class="market-shot-add">' . icon('plus') . '<span>' . t('Add') . '</span><input type="file" name="images[]" accept="image/png,image/jpeg,image/gif,image/webp" multiple></label></div>'
        . '' . '<p class="market-shots-error" role="alert" hidden></p></div>', $help);
}

function market_shots_css(): string
{
    return '.market-shots-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:8px}'
        . '.market-shot{position:relative;aspect-ratio:3/2;border:1px solid var(--line);border-radius:var(--radius-sm);overflow:hidden;background:var(--panel-2);user-select:none;-webkit-user-select:none}.market-shot img{display:block;width:100%;height:100%;object-fit:cover;pointer-events:none;-webkit-user-drag:none}'
        . '.market-shots.is-js .market-shot{cursor:grab}.market-shot.is-dragging{opacity:.45;pointer-events:none;outline:2px dashed var(--brand);outline-offset:-2px}.market-shots.is-sorting,.market-shots.is-sorting *{cursor:grabbing}'
        . '.market-shot:first-child{border:2px solid var(--brand)}.market-shot-cover{display:none;position:absolute;left:5px;bottom:5px;padding:1px 7px;border-radius:4px;background:var(--brand);color:var(--brand-text,#fff);font-size:11px;font-weight:600;line-height:1.5}.market-shot:first-child .market-shot-cover{display:block}'
        . '.market-shot-x,.market-shot-grip{position:absolute;top:5px;display:flex;align-items:center;justify-content:center;width:22px;height:22px;padding:0;border:0;border-radius:50%;background:rgba(0,0,0,.62);color:#fff;font-size:16px;line-height:1;cursor:pointer}'
        . '.market-shot-x{right:5px}.market-shot-x:hover{background:var(--danger,#dc2626)}.market-shot-grip{left:5px;cursor:grab;touch-action:none}.market-shot-grip:hover{background:rgba(0,0,0,.8)}.market-shot-x:focus-visible,.market-shot-grip:focus-visible{outline:2px solid var(--brand);outline-offset:1px}'
        . '.market-shot-keep{position:absolute;left:5px;top:5px;display:flex;gap:4px;align-items:center;margin:0;padding:1px 6px;border-radius:4px;background:var(--panel);font-size:var(--font-size-xs)}.market-shots.is-js .market-shot-keep,.market-shots:not(.is-js) .market-shot-x,.market-shots:not(.is-js) .market-shot-grip{display:none}'
        . '.market-shot-add{position:relative;aspect-ratio:3/2;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;margin:0;border:1px dashed var(--text-subtle);border-radius:var(--radius-sm);color:var(--text-muted);font-size:var(--font-size-sm);font-weight:500;cursor:pointer}'
        . '.market-shot-add:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-soft)}.market-shot-add .icon{width:20px;height:20px}.market-shot-add[hidden],.market-shots-error[hidden]{display:none}'
        . '.market-shots.is-js .market-shot-add input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}'
        . '.market-shots:not(.is-js) .market-shot-add{grid-column:1/-1;aspect-ratio:auto;align-items:stretch;border:0;cursor:default}.market-shots:not(.is-js) .market-shot-add .icon,.market-shots:not(.is-js) .market-shot-add span{display:none}'
        . '.market-shots-error{margin:6px 0 0;color:var(--danger,#dc2626);font-size:var(--font-size-sm)}';
}

/** Previews, reordering, × and the file list behind them (no inline handlers: the Content Security Policy forbids them). */
function market_shots_js(): string
{
    return <<<'JS'
(function () {
  var GRIP = '<svg viewBox="0 0 10 16" width="10" height="16" fill="currentColor" aria-hidden="true"><circle cx="3" cy="3" r="1.5"/><circle cx="7" cy="3" r="1.5"/><circle cx="3" cy="8" r="1.5"/><circle cx="7" cy="8" r="1.5"/><circle cx="3" cy="13" r="1.5"/><circle cx="7" cy="13" r="1.5"/></svg>';
  var init = function (box) {
    var input = box.querySelector('input[type=file]'), grid = box.querySelector('.market-shots-grid'), add = box.querySelector('.market-shot-add'), err = box.querySelector('.market-shots-error'), orderBox = box.querySelector('.market-shots-order');
    if (!input || !grid || !add || !err || !window.DataTransfer || !window.URL) return;
    try { input.files = new DataTransfer().files; } catch (e) { return; } // no way to rewrite the file list: keep the plain input
    box.classList.add('is-js');
    var max = parseInt(box.getAttribute('data-max'), 10) || 5, maxBytes = parseInt(box.getAttribute('data-max-bytes'), 10) || 2097152;
    var text = function (k, name) { return (box.getAttribute('data-' + k) || '').replace('{name}', name || ''); };
    var say = function (m) { err.textContent = m || ''; err.hidden = !m; };
    var shots = function () { return grid.querySelectorAll('.market-shot'); };
    // the input holds the new files in the order shown; on Manage, order[] says where each kept and new screenshot goes
    var sync = function () {
      var dt = new DataTransfer(), n = 0;
      if (orderBox) orderBox.textContent = '';
      Array.prototype.forEach.call(shots(), function (t) {
        var token = '', keep = t.querySelector('input[name="keep[]"]');
        if (t.shot) { dt.items.add(t.shot.file); token = 'new:' + n++; } else if (keep) token = 'keep:' + keep.value;
        if (orderBox && token) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'order[]'; h.value = token; orderBox.appendChild(h); }
      });
      input.files = dt.files;
      add.hidden = shots().length >= max;
    };
    var button = function (cls, label, html) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = cls; b.setAttribute('aria-label', label); b.innerHTML = html;
      return b;
    };
    var tile = function (url) {
      var d = document.createElement('div'), img = document.createElement('img'), c = document.createElement('span');
      d.className = 'market-shot';
      img.src = url; img.alt = ''; img.draggable = false;
      c.className = 'market-shot-cover'; c.textContent = text('cover');
      d.appendChild(img); d.appendChild(c);
      d.appendChild(button('market-shot-grip', text('reorder'), GRIP));
      d.appendChild(button('market-shot-x', text('remove'), '&times;'));
      return d;
    };
    input.addEventListener('change', function () {
      var problem = '';
      Array.prototype.slice.call(input.files).forEach(function (f) {
        if (!/^image\/(png|jpe?g|gif|webp)$/.test(f.type)) { problem = problem || text('err-type', f.name); return; }
        if (f.size > maxBytes) { problem = problem || text('err-size', f.name); return; }
        if (shots().length >= max) { problem = problem || text('err-count'); return; }
        var el = tile(URL.createObjectURL(f));
        el.shot = { file: f, url: el.firstChild.src };
        grid.insertBefore(el, add);
      });
      sync();
      say(problem);
    });
    grid.addEventListener('click', function (e) {
      var x = e.target.closest('.market-shot-x');
      if (!x) return;
      var t = x.closest('.market-shot');
      if (t.shot) URL.revokeObjectURL(t.shot.url); // a current screenshot takes its Keep box with it
      t.parentNode.removeChild(t);
      say('');
      sync();
    });
    // drag to reorder: a mouse drags the whole tile, a finger the handle (so the page still scrolls)
    var drag = null;
    grid.addEventListener('pointerdown', function (e) {
      var t = e.target.closest('.market-shot');
      if (!t || e.button !== 0 || e.target.closest('.market-shot-x')) return;
      if (e.pointerType !== 'mouse' && !e.target.closest('.market-shot-grip')) return;
      drag = { el: t, id: e.pointerId, x: e.clientX, y: e.clientY, moved: false };
    });
    document.addEventListener('pointermove', function (e) {
      if (!drag || e.pointerId !== drag.id) return;
      if (!drag.moved) {
        if (Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 6) return;
        drag.moved = true;
        drag.el.classList.add('is-dragging');
        box.classList.add('is-sorting');
      }
      e.preventDefault();
      var over = document.elementFromPoint(e.clientX, e.clientY), t = over && over.closest ? over.closest('.market-shot') : null;
      if (!t || t === drag.el || t.parentNode !== grid) return;
      var r = t.getBoundingClientRect(), ref = e.clientX > r.left + r.width / 2 ? t.nextSibling : t;
      if (ref !== drag.el && ref !== drag.el.nextSibling) grid.insertBefore(drag.el, ref);
    });
    var drop = function (e) {
      if (!drag || e.pointerId !== drag.id) return;
      if (drag.moved) { drag.el.classList.remove('is-dragging'); box.classList.remove('is-sorting'); sync(); }
      drag = null;
    };
    document.addEventListener('pointerup', drop);
    document.addEventListener('pointercancel', drop);
    // the handle is a button: the arrow keys move its screenshot
    grid.addEventListener('keydown', function (e) {
      var g = e.target.closest('.market-shot-grip');
      if (!g) return;
      var t = g.closest('.market-shot'), prev = t.previousElementSibling, next = t.nextElementSibling;
      if ((e.key === 'ArrowLeft' || e.key === 'ArrowUp') && prev) grid.insertBefore(t, prev);
      else if ((e.key === 'ArrowRight' || e.key === 'ArrowDown') && next && next.classList.contains('market-shot')) grid.insertBefore(next, t);
      else return;
      e.preventDefault();
      g.focus();
      sync();
    });
    sync();
  };
  var run = function () { Array.prototype.forEach.call(document.querySelectorAll('[data-market-shots]'), init); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
JS;
}

function market_dashboard_cards(array $cards, array $ctx): array
{
    $file = CACHE_DIR . '/market_list.json';
    if (!is_file($file)) return $cards;
    $n = 0;
    foreach ((array)(json_decode_array((string)file_get_contents($file))['plugins'] ?? []) as $p) {
        $local = plugins()[(string)($p['id'] ?? '')] ?? null;
        if ($local !== null && version_compare((string)($p['version'] ?? '0'), (string)$local['version'], '>')) $n++;
    }
    if ($n > 0) $cards['market_updates'] = ['html' => card('', '<b>' . $n . '</b><span><a href="' . h(market_admin_url('browse', ['kind' => 'updates'])) . '">' . t('Plugin updates') . '</a></span>')];
    return $cards;
}

return [
    'id' => 'market',
    'name' => 'Plugin Market',
    'version' => '2.3.0',
    'description' => 'Browse plugins by category, install and update plugins and themes from www.flatbb.com, get the ones that cost points with your account, and publish your own with a changelog and screenshots.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.89'],
    'admin_pages' => [
        'market' => ['label' => 'Marketplace', 'callback' => 'market_admin_page'],
        'publish' => ['label' => '', 'callback' => 'market_admin_publish'],
        'themes' => ['label' => '', 'callback' => 'market_admin_themes'],
    ],
    'hooks' => [
        'admin.plugin_ops' => 'market_plugin_ops',
        'region.admin.plugins.tabs' => 'market_plugins_tabs',
        'region.admin.themes.tabs' => 'market_themes_tabs',
        'region.admin.dashboard.cards' => 'market_dashboard_cards',
    ],
    'assets' => ['css' => ['market_css', 'market_shots_css'], 'js' => ['market_js', 'market_shots_js']],
];
