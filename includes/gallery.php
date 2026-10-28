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

function gallery_photo_url(int $id, bool $download = false): string
{
    return url('gallery-photo.php?id=' . $id . ($download ? '&download=1' : ''));
}

function gallery_lightbox_attrs(array $item, bool $includeCategory = false): string
{
    $id = (int) ($item['id'] ?? 0);
    $src = $id > 0 ? gallery_photo_url($id) : url((string) ($item['image_path'] ?? ''));
    $html = 'href="' . e($src) . '" data-lightbox data-caption="' . e((string) (($item['caption'] ?? '') !== '' ? $item['caption'] : ($item['title'] ?? ''))) . '"';
    if ($includeCategory) {
        $html .= ' data-category="' . e((string) ($item['category'] ?? 'class')) . '"';
    }
    if ($id > 0 && can_download_gallery_photo($item)) {
        $html .= ' data-download="' . e(gallery_photo_url($id, true)) . '"';
    }
    return $html;
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

function stream_gallery_photo(int $id, bool $download = false): void
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

    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($full);
    $allowed = upload_allowed_types();
    if (!isset($allowed[$mime])) {
        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    } else {
        $ext = $allowed[$mime];
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($full));
    header('X-Content-Type-Options: nosniff');
    if ($download) {
        header('Content-Disposition: attachment; filename="' . gallery_download_filename($row, $ext) . '"');
        header('Cache-Control: private, no-store');
    } else {
        header('Content-Disposition: inline; filename="' . gallery_download_filename($row, $ext) . '"');
        header('Cache-Control: ' . (content_visibility_of($row) === 'public' ? 'public, max-age=86400' : 'private, no-store'));
    }
    readfile($full);
    exit;
}
