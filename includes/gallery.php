<?php

declare(strict_types=1);

function gallery_download_audiences(): array
{
    return [
        'none' => 'Nobody',
        'class' => 'Class only',
        'public' => 'Anyone who can see it',
    ];
}

function gallery_download_for(array $row): string
{
    $value = strtolower(trim((string) ($row['download_for'] ?? '')));
    return isset(gallery_download_audiences()[$value]) ? $value : 'class';
}

function gallery_download_label(array|string $rowOrKey): string
{
    $key = is_array($rowOrKey) ? gallery_download_for($rowOrKey) : strtolower(trim($rowOrKey));
    return gallery_download_audiences()[$key] ?? gallery_download_audiences()['class'];
}

function posted_download_for(?array $existing = null): string
{
    $raw = strtolower(trim(posted('download_for')));
    if (isset(gallery_download_audiences()[$raw])) {
        return $raw;
    }
    return $existing ? gallery_download_for($existing) : 'class';
}

function find_gallery_photo(int $id): ?array
{
    if ($id < 1 || !table_exists(db(), 'gallery')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM gallery WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function can_view_gallery_photo(array $row): bool
{
    if (is_logged_in() && !is_student()) {
        return true;
    }
    $visibility = content_visibility_of($row);
    if ($visibility === 'public') {
        return true;
    }
    if ($visibility === 'private') {
        return is_logged_in();
    }
    return false;
}

function can_download_gallery_photo(array $row): bool
{
    if (!can_view_gallery_photo($row)) {
        return false;
    }
    if (is_logged_in() && !is_student()) {
        return true;
    }
    $policy = gallery_download_for($row);
    if ($policy === 'none') {
        return false;
    }
    if ($policy === 'public') {
        return true;
    }
    return is_logged_in();
}

function gallery_photo_url(int $id, bool $download = false, string $size = 'view'): string
{
    if ($download) {
        return url('gallery-photo.php?id=' . $id . '&download=1');
    }
    $size = in_array($size, ['thumb', 'view', 'full'], true) ? $size : 'view';
    $query = 'gallery-photo.php?id=' . $id;
    if ($size !== 'full') {
        $query .= '&size=' . $size;
    }
    return url($query);
}

function gallery_lightbox_attrs(array $item, bool $includeCategory = false): string
{
    $id = (int) ($item['id'] ?? 0);
    $src = $id > 0 ? gallery_photo_url($id, false, 'view') : url((string) ($item['image_path'] ?? ''));
    $html = 'href="' . e($src) . '" data-lightbox data-caption="' . e((string) (($item['caption'] ?? '') !== '' ? $item['caption'] : ($item['title'] ?? ''))) . '"';
    if ($includeCategory) {
        $html .= ' data-category="' . e((string) ($item['category'] ?? 'class')) . '"';
    }
    if ($id > 0 && can_download_gallery_photo($item)) {
        $html .= ' data-download="' . e(gallery_photo_url($id, true)) . '"';
    }
    return $html;
}

/** Max width/height for display derivatives. Full originals stay for download. */
function gallery_photo_size_max(string $size): int
{
    return match ($size) {
        'thumb' => 640,
        'view' => 1600,
        default => 0,
    };
}

function gallery_cache_dir(): string
{
    $dir = APP_ROOT . '/uploads/gallery/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $deny = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($deny)) {
        @file_put_contents($deny, "Require all denied\n");
    }
    return $dir;
}

/**
 * Build or reuse a smaller JPEG for fast grid/lightbox viewing.
 * Returns absolute path to derivative, or null to stream the original.
 */
function gallery_photo_derivative(string $fullPath, string $relative, string $size): ?string
{
    $max = gallery_photo_size_max($size);
    if ($max < 1 || !is_file($fullPath) || !extension_loaded('gd')) {
        return null;
    }

    $mtime = (int) filemtime($fullPath);
    $key = substr(hash('sha256', $relative . '|' . $size . '|' . $mtime), 0, 40);
    $cachePath = gallery_cache_dir() . '/' . $key . '.jpg';
    if (is_file($cachePath) && filesize($cachePath) > 0) {
        return $cachePath;
    }

    $info = @getimagesize($fullPath);
    if ($info === false) {
        return null;
    }
    [$width, $height] = $info;
    if ($width < 1 || $height < 1) {
        return null;
    }
    if ($width <= $max && $height <= $max && ($info['mime'] ?? '') === 'image/jpeg') {
        return null;
    }

    $source = match ($info['mime'] ?? '') {
        'image/jpeg' => @imagecreatefromjpeg($fullPath),
        'image/png' => @imagecreatefrompng($fullPath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : false,
        'image/gif' => @imagecreatefromgif($fullPath),
        default => false,
    };
    if ($source === false) {
        return null;
    }

    $scale = min(1, $max / max($width, $height));
    $newW = max(1, (int) round($width * $scale));
    $newH = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($newW, $newH);
    if ($canvas === false) {
        imagedestroy($source);
        return null;
    }
    $white = imagecolorallocate($canvas, 255, 255, 255);
    if ($white !== false) {
        imagefill($canvas, 0, 0, $white);
    }
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagedestroy($source);

    $ok = imagejpeg($canvas, $cachePath, 82);
    imagedestroy($canvas);
    if (!$ok || !is_file($cachePath)) {
        return null;
    }
    return $cachePath;
}

function gallery_download_filename(array $row, string $ext): string
{
    $title = preg_replace('/[^a-zA-Z0-9]+/', '-', (string) ($row['title'] ?? 'photo')) ?? 'photo';
    $title = trim($title, '-') ?: 'photo';
    $ext = strtolower($ext);
    if ($ext === '') {
        $ext = 'jpg';
    }
    return $title . '-' . (int) ($row['id'] ?? 0) . '.' . $ext;
}

function stream_gallery_photo(int $id, bool $download = false, string $size = 'full'): void
{
    $row = find_gallery_photo($id);
    if (!$row) {
        http_response_code(404);
        echo 'Photo not found.';
        exit;
    }
    if (!can_view_gallery_photo($row)) {
        if (!is_logged_in()) {
            require_login();
        }
        deny_access('You cannot view this photo.');
    }
    if ($download && !can_download_gallery_photo($row)) {
        deny_access('You cannot download this photo.');
    }

    $relative = str_replace('\\', '/', trim((string) ($row['image_path'] ?? '')));
    if ($relative === '' || str_contains($relative, '..') || !str_starts_with($relative, 'uploads/gallery/')) {
        http_response_code(404);
        echo 'Photo not found.';
        exit;
    }
    $full = APP_ROOT . '/' . $relative;
    if (!is_file($full)) {
        http_response_code(404);
        echo 'Photo not found.';
        exit;
    }

    $size = $download ? 'full' : (in_array($size, ['thumb', 'view', 'full'], true) ? $size : 'view');
    $servePath = $full;
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($full);
    $allowed = upload_allowed_types();
    $ext = isset($allowed[$mime]) ? $allowed[$mime] : strtolower(pathinfo($full, PATHINFO_EXTENSION));

    if ($size !== 'full') {
        $derivative = gallery_photo_derivative($full, $relative, $size);
        if ($derivative !== null) {
            $servePath = $derivative;
            $mime = 'image/jpeg';
            $ext = 'jpg';
        }
    }

    if (!isset($allowed[$mime]) && $mime !== 'image/jpeg') {
        $mime = 'application/octet-stream';
    }

    $length = filesize($servePath);
    header('Content-Type: ' . $mime);
    if ($length !== false) {
        header('Content-Length: ' . (string) $length);
    }
    header('X-Content-Type-Options: nosniff');
    if ($download) {
        header('Content-Disposition: attachment; filename="' . gallery_download_filename($row, $ext) . '"');
        header('Cache-Control: private, no-store');
    } else {
        header('Content-Disposition: inline; filename="' . gallery_download_filename($row, $ext) . '"');
        $cache = content_visibility_of($row) === 'public' ? 'public, max-age=604800' : 'private, max-age=86400';
        header('Cache-Control: ' . $cache);
    }
    readfile($servePath);
    exit;
}
