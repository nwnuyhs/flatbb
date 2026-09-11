<?php
/**
 * Uploads: attachments (images/files) and avatars.
 * Files live in uploads/YYYY/MM/<random>.<ext> and are web-served directly.
 * Never trust the client mime; we sniff with finfo and whitelist extensions.
 */

function upload_url(string $path): string
{
    if (preg_match('#^https?://#i', $path)) return $path;
    return base_path() . '/uploads/' . ltrim($path, '/');
}

/** Sniffed mime type. fileinfo is optional: without it, image files are identified by getimagesize() and everything else stays ''. */
function upload_mime(string $file): string
{
    if (class_exists('finfo')) return (string)(new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (function_exists('mime_content_type')) return (string)@mime_content_type($file);
    $info = @getimagesize($file);
    return is_array($info) ? (string)($info['mime'] ?? '') : '';
}

/** $_FILES[<key>] as a list of single-file arrays (a multi-file field is nested by property); files with upload errors are skipped. */
function upload_files_list(string $key): array
{
    $f = $_FILES[$key] ?? null;
    if (!is_array($f)) return [];
    if (!is_array($f['name'] ?? null)) return ($f['error'] ?? 1) === UPLOAD_ERR_OK ? [$f] : [];
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
        $out[] = ['name' => (string)$name, 'tmp_name' => (string)$f['tmp_name'][$i], 'size' => (int)$f['size'][$i], 'error' => 0];
    }
    return $out;
}

function upload_allowed_types(): array
{
    return array_values(array_filter(array_map('trim', explode(',', strtolower(setting('upload_types', 'jpg,jpeg,png,gif,webp'))))));
}

function upload_image_exts(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

/** POST /upload — returns JSON {ok,url,markdown,id,name,is_image}. */
function upload_handle(): never
{
    $me = need_login();
    require_post();
    if (!can('upload')) json_error(t('You are not allowed to upload files.'), 403);
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_error(t('No file received or the file is too large.'));
    try {
        $att = upload_store($me, (string)$f['tmp_name'], (string)$f['name']);
    } catch (RuntimeException $e) {
        json_error($e->getMessage());
    }
    $url = upload_url($att['path']);
    $md = $att['is_image'] ? '![' . str_replace(['[', ']'], '', $att['name']) . '](' . $url . ')' : '[' . str_replace(['[', ']'], '', $att['name']) . '](' . $url . ')';
    json_ok(['id' => $att['id'], 'url' => $url, 'markdown' => $md, 'name' => $att['name'], 'is_image' => $att['is_image']]);
}

/** Validate, move and register an uploaded file. Throws RuntimeException with a user message. */
function upload_store(array $user, string $tmp, string $original): array
{
    $max = (int)round((float)setting('upload_max_mb', '5') * 1048576); // the setting may be a fraction of a megabyte (0.3)
    $size = (int)@filesize($tmp);
    if ($size <= 0 || $size > $max) throw new RuntimeException(t('File must be smaller than %s.', human_size($max)));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if ($ext === '' || !in_array($ext, upload_allowed_types(), true) || in_array($ext, ['php', 'phtml', 'phar', 'htaccess', 'html', 'htm', 'svg', 'js'], true)) {
        throw new RuntimeException(t('File type .%s is not allowed.', $ext));
    }
    $mime = upload_mime($tmp);
    $is_image = in_array($ext, upload_image_exts(), true);
    $w = $h = 0;
    if ($is_image) {
        $info = @getimagesize($tmp);
        if ($info === false || !str_starts_with($mime, 'image/')) throw new RuntimeException(t('The image file is invalid.'));
        [$w, $h] = $info;
        upload_shrink_image($tmp, $ext, 1600);
        $info = @getimagesize($tmp);
        if ($info !== false) [$w, $h] = $info;
    } elseif (str_contains($mime, 'php') || str_contains($mime, 'html')) {
        throw new RuntimeException(t('File type is not allowed.'));
    }
    $rel = date('Y/m') . '/' . random_token(12) . '.' . $ext;
    $dest = UPLOAD_DIR . '/' . $rel;
    if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) throw new RuntimeException(t('Upload directory is not writable.'));
    if (!@move_uploaded_file($tmp, $dest) && !@rename($tmp, $dest)) throw new RuntimeException(t('Could not save the file.'));
    $name = cut(preg_replace('/[^\w. -]+/u', '_', $original) ?: 'file.' . $ext, 120, '');
    $id = db_insert('fb_attachments', [
        'user_id' => (int)$user['id'], 'post_id' => 0, 'name' => $name, 'path' => $rel, 'mime' => $mime,
        'size' => (int)filesize($dest), 'is_image' => $is_image ? 1 : 0, 'width' => (int)$w, 'height' => (int)$h,
        'hash' => (string)md5_file($dest), 'created_at' => now(),
    ]);
    return ['id' => $id, 'path' => $rel, 'name' => $name, 'is_image' => $is_image, 'width' => $w, 'height' => $h];
}

/** Downscale large images in place (keeps GIFs untouched). Silently skips when GD is missing. */
function upload_shrink_image(string $file, string $ext, int $max_side): void
{
    if ($ext === 'gif' || !function_exists('imagecreatefromstring')) return;
    $info = @getimagesize($file);
    if ($info === false || ($info[0] <= $max_side && $info[1] <= $max_side)) return;
    $src = @imagecreatefromstring((string)file_get_contents($file));
    if ($src === false) return;
    $ratio = min($max_side / $info[0], $max_side / $info[1]);
    $w = (int)($info[0] * $ratio); $h = (int)($info[1] * $ratio);
    $dst = imagecreatetruecolor($w, $h);
    if ($ext === 'png' || $ext === 'webp') { imagealphablending($dst, false); imagesavealpha($dst, true); }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
    match ($ext) {
        'png' => imagepng($dst, $file, 6),
        'webp' => imagewebp($dst, $file, 85),
        default => imagejpeg($dst, $file, 85),
    };
    imagedestroy($src); imagedestroy($dst);
}

/** Square avatar: uploads/avatars/<uid>.jpg. Returns the relative path stored on the user. */
function avatar_store(int $uid, string $tmp): string
{
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException(t('Image processing is not available on this server.'));
    $info = @getimagesize($tmp);
    if ($info === false) throw new RuntimeException(t('The image file is invalid.'));
    $src = @imagecreatefromstring((string)file_get_contents($tmp));
    if ($src === false) throw new RuntimeException(t('The image file is invalid.'));
    $side = min($info[0], $info[1]);
    $x = (int)(($info[0] - $side) / 2); $y = (int)(($info[1] - $side) / 2);
    $dst = imagecreatetruecolor(200, 200);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, $x, $y, 200, 200, $side, $side);
    $dir = UPLOAD_DIR . '/avatars';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $rel = 'avatars/' . $uid . '.jpg';
    imagejpeg($dst, UPLOAD_DIR . '/' . $rel, 88);
    imagedestroy($src); imagedestroy($dst);
    return $rel . '?v=' . now();
}

/** Link freshly uploaded attachments to a post (by their ids embedded in the markdown urls). */
function attachments_link_to_post(int $post_id, int $user_id, string $body): void
{
    preg_match_all('#/uploads/(\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5})#i', $body, $m);
    $paths = array_unique($m[1] ?? []);
    if ($paths === []) return;
    q('UPDATE fb_attachments SET post_id=? WHERE user_id=? AND post_id=0 AND path IN (' . sql_marks(count($paths)) . ')', array_merge([$post_id, $user_id], array_values($paths)));
}

/**
 * Store a site asset (logo, favicon) uploaded from the admin: uploads/site/<key>.<ext>.
 * Returns the relative path for setting(); throws RuntimeException with a user message.
 */
function upload_site_image(string $key, array $file, array $exts, int $max_bytes = 2097152): string
{
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException(t('No file received or the file is too large.'));
    if ((int)$file['size'] > $max_bytes) throw new RuntimeException(t('File must be smaller than %s.', human_size($max_bytes)));
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, $exts, true)) throw new RuntimeException(t('Allowed types: %s.', implode(', ', $exts)));
    $tmp = (string)$file['tmp_name'];
    $mime = upload_mime($tmp);
    if ($ext === 'svg') {
        $svg = (string)file_get_contents($tmp);
        if (!str_contains($svg, '<svg') || preg_match('/<script|on[a-z]+\s*=|javascript:|<foreignObject/i', $svg)) throw new RuntimeException(t('The SVG file is invalid or contains scripts.'));
    } elseif ($ext === 'ico') {
        $head = (string)@file_get_contents($tmp, false, null, 0, 4);
        if ($head !== "   " && !in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon', 'image/ico', 'application/octet-stream'], true)) throw new RuntimeException(t('The image file is invalid.'));
    } elseif (!str_starts_with($mime, 'image/') || @getimagesize($tmp) === false) {
        throw new RuntimeException(t('The image file is invalid.'));
    }
    $dir = UPLOAD_DIR . '/site';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException(t('Upload directory is not writable.'));
    foreach (glob($dir . '/' . $key . '.*') ?: [] as $old) @unlink($old);
    $rel = 'site/' . preg_replace('/[^a-z0-9_]/', '', $key) . '.' . $ext;
    if (!@move_uploaded_file($tmp, UPLOAD_DIR . '/' . $rel) && !@rename($tmp, UPLOAD_DIR . '/' . $rel)) throw new RuntimeException(t('Could not save the file.'));
    return $rel . '?v=' . now();
}

/**
 * Drop protection files into uploads/ and data/ when they are missing: PHP-FPM/CGI reads .user.ini (engine off), Apache
 * reads .htaccess. nginx needs the rules from nginx.conf.example; the Guard plugin's check-up tells whether they work.
 */
function upload_protect_dirs(): void
{
    $files = [
        UPLOAD_DIR . '/.user.ini' => "; flatbb: uploaded files are never executed\nengine = Off\n",
        UPLOAD_DIR . '/.htaccess' => "# flatbb: uploaded files are never executed\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n<FilesMatch \"\\.(php|phtml|phar|php[0-9]|phps)$\">\nRequire all denied\n</FilesMatch>\n",
        DATA_DIR . '/.htaccess' => "# flatbb: private data\nRequire all denied\n",
        DATA_DIR . '/.user.ini' => "engine = Off\n",
    ];
    foreach ($files as $path => $body) {
        if (!is_file($path) && is_dir(dirname($path))) @file_put_contents($path, $body);
    }
}
