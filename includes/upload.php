<?php

declare(strict_types=1);

function upload_allowed_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
}

function store_upload(array $file, string $folder, int $maxBytes = 5242880): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file was uploaded.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The file could not be uploaded.'];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'The file is too large. Maximum size is 5 MB.'];
    }

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = upload_allowed_types();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP, and GIF images are allowed.'];
    }
    if (@getimagesize($tmp) === false) {
        return ['ok' => false, 'error' => 'The file is not a valid image.'];
    }

    $ext = $allowed[$mime];
    $original = (string) ($file['name'] ?? '');
    $originalExt = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $extMap = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];
    if (!isset($extMap[$originalExt]) || $extMap[$originalExt] !== $ext) {
        // MIME is source of truth; extension mismatch is still rejected.
        return ['ok' => false, 'error' => 'The file type does not match the file extension.'];
    }

    $folder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'misc';
    $dir = APP_ROOT . '/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Upload folder could not be created.'];
    }
    if ($folder === 'payments') {
        $deny = $dir . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\n");
        }
    }

    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'The file could not be saved.'];
    }

    return [
        'ok' => true,
        'path' => 'uploads/' . $folder . '/' . $name,
        'filename' => $name,
    ];
}

function delete_upload(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $relativePath = str_replace('\\', '/', $relativePath);
    if (str_contains($relativePath, '..') || !str_starts_with($relativePath, 'uploads/')) {
        return;
    }
    $full = APP_ROOT . '/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

function media_url(?string $path, string $fallback = ''): string
{
    if ($path) {
        return url($path);
    }
    return $fallback;
}
