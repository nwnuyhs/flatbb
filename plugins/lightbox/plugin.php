<?php
/**
 * Lightbox — click an image in a post to see it full size, and step through the other images in the same post with
 * the arrows, the keyboard or a swipe.
 *
 * Pure front end: no tables, no routes, no server work. One document-level click handler (so images that arrive with
 * an ajax reply or the editor preview work too, with nothing to re-bind), and one overlay reused for every image.
 * Avatars, emoji and images that are themselves a link to somewhere else are left alone.
 */
if (!defined('FLATBB')) exit;

function lightbox_js(): string
{
    $conf = json_encode_value([
        'close' => t('Close'),
        'prev' => t('Previous'),
        'next' => t('Next'),
        'counter' => t('%1$d / %2$d'), // shown as "2 / 5"
    ]);
    return "var lightbox_conf = {$conf};\n" . <<<'JS'
(function () {
  var cfg = lightbox_conf, box = null, img = null, cap = null, count = null, list = [], at = 0, sx = 0;

  // every image inside a rendered post or the editor preview, except one wrapped in a link that points elsewhere
  var zoomable = function (el) {
    if (!el || el.tagName !== 'IMG') return false;
    if (!el.closest('.post-content, .editor-preview')) return false;
    var a = el.closest('a');
    if (a && a.getAttribute('href') && !/\.(png|jpe?g|gif|webp|avif|bmp|svg)(\?|#|$)/i.test(a.getAttribute('href'))) return false;
    return true;
  };

  var build = function () {
    if (box) return;
    box = document.createElement('div');
    box.className = 'lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML =
      '<button type="button" class="lightbox-close" aria-label="' + cfg.close + '">✕</button>' +
      '<button type="button" class="lightbox-nav lightbox-prev" aria-label="' + cfg.prev + '">‹</button>' +
      '<figure class="lightbox-stage"><img alt=""><figcaption class="lightbox-cap"></figcaption></figure>' +
      '<button type="button" class="lightbox-nav lightbox-next" aria-label="' + cfg.next + '">›</button>' +
      '<span class="lightbox-count" aria-live="polite"></span>';
    img = box.querySelector('img');
    cap = box.querySelector('.lightbox-cap');
    count = box.querySelector('.lightbox-count');
    document.body.appendChild(box);
    box.addEventListener('click', function (e) {
      if (e.target.closest('.lightbox-next')) { go(1); return; }
      if (e.target.closest('.lightbox-prev')) { go(-1); return; }
      if (e.target === img) return;           // a click on the picture itself keeps it open
      close();                                // the backdrop, the caption gap, the close button
    });
    box.addEventListener('touchstart', function (e) { sx = e.changedTouches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) {
      var dx = e.changedTouches[0].clientX - sx;
      if (Math.abs(dx) > 40) go(dx < 0 ? 1 : -1);
    }, { passive: true });
  };

  var show = function () {
    var el = list[at];
    img.src = el.currentSrc || el.src;
    var alt = el.getAttribute('alt') || '';
    cap.textContent = alt;
    cap.style.display = alt ? '' : 'none';
    var many = list.length > 1;
    count.textContent = many ? cfg.counter.replace('%1$d', at + 1).replace('%2$d', list.length) : '';
    box.classList.toggle('has-many', many);
  };

  var go = function (step) {
    if (list.length < 2) return;
    at = (at + step + list.length) % list.length;
    show();
  };

  var open = function (target) {
    build();
    var scope = target.closest('.post-content, .editor-preview');
    list = Array.prototype.filter.call(scope.querySelectorAll('img'), zoomable);
    at = Math.max(0, list.indexOf(target));
    show();
    box.classList.add('open');
    document.documentElement.classList.add('lightbox-lock');
  };

  var close = function () {
    if (!box) return;
    box.classList.remove('open');
    document.documentElement.classList.remove('lightbox-lock');
    img.src = '';
  };

  document.addEventListener('click', function (e) {
    if (!zoomable(e.target)) return;
    e.preventDefault();
    open(e.target);
  });
  document.addEventListener('keydown', function (e) {
    if (!box || !box.classList.contains('open')) return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowRight') go(1);
    else if (e.key === 'ArrowLeft') go(-1);
  });
})();
JS;
}

function lightbox_css(): string
{
    return '.post-content img,.editor-preview img{cursor:zoom-in}'
        . '.post-content a img,.editor-preview a img{cursor:pointer}'
        . 'html.lightbox-lock{overflow:hidden}'
        . '.lightbox{position:fixed;inset:0;z-index:210;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.88);opacity:0;transition:opacity .15s;touch-action:pan-y}'
        . '.lightbox.open{display:flex;opacity:1}'
        . '.lightbox-stage{margin:0;display:flex;flex-direction:column;align-items:center;gap:10px;max-width:94vw;max-height:92vh}'
        . '.lightbox-stage img{max-width:94vw;max-height:84vh;object-fit:contain;border-radius:var(--radius-sm);background:#fff;box-shadow:0 8px 40px rgba(0,0,0,.5);cursor:default}'
        . '.lightbox-cap{color:rgba(255,255,255,.82);font-size:var(--font-size-sm);max-width:80vw;text-align:center;word-break:break-word}'
        . '.lightbox-close{position:fixed;top:14px;inset-inline-end:16px;width:40px;height:40px;border:0;border-radius:50%;background:rgba(255,255,255,.12);color:#fff;font-size:20px;line-height:1;cursor:pointer}'
        . '.lightbox-close:hover{background:rgba(255,255,255,.22)}'
        . '.lightbox-nav{position:fixed;top:50%;transform:translateY(-50%);width:48px;height:48px;border:0;border-radius:50%;background:rgba(255,255,255,.12);color:#fff;font-size:30px;line-height:1;cursor:pointer;display:none}'
        . '.lightbox.has-many .lightbox-nav{display:inline-flex;align-items:center;justify-content:center}'
        . '.lightbox-nav:hover{background:rgba(255,255,255,.22)}'
        . '.lightbox-prev{inset-inline-start:16px}.lightbox-next{inset-inline-end:16px}'
        . '[dir="rtl"] .lightbox-nav{transform:translateY(-50%) scaleX(-1)}'
        . '.lightbox-count{position:fixed;top:18px;inset-inline-start:50%;transform:translateX(-50%);color:rgba(255,255,255,.82);font-size:var(--font-size-sm);font-variant-numeric:tabular-nums}'
        . '@media(max-width:640px){.lightbox-nav{width:40px;height:40px;font-size:24px}.lightbox-prev{inset-inline-start:6px}.lightbox-next{inset-inline-end:6px}}'
        . '@media(prefers-reduced-motion:reduce){.lightbox{transition:none}}';
}

return [
    'id' => 'lightbox',
    'name' => 'Lightbox',
    'version' => '1.0.0',
    'description' => 'Click an image in a post to see it full size on a dark backdrop, and step through the other images in the same post with the arrows, the keyboard or a swipe.',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.1'],
    'assets' => ['css' => ['lightbox_css'], 'js' => ['lightbox_js']],
];
