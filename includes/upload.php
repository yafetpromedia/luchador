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

function upload_max_bytes(): int
{
    return 20 * 1024 * 1024;
}

function upload_bytes_label(int $bytes): string
{
    return (string) (int) round($bytes / (1024 * 1024)) . ' MB';
}

function upload_max_label(): string
{
    return upload_bytes_label(upload_max_bytes());
}

function uploaded_file_list(string $key): array
{
    if (empty($_FILES[$key]) || !isset($_FILES[$key]['name'])) {
        return [];
    }
    $names = $_FILES[$key]['name'];
    if (!is_array($names)) {
        return [$_FILES[$key]];
    }
    $files = [];
    foreach ($names as $i => $name) {
        if ($name === '' || (int) ($_FILES[$key]['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => (string) $name,
            'type' => (string) ($_FILES[$key]['type'][$i] ?? ''),
            'tmp_name' => (string) ($_FILES[$key]['tmp_name'][$i] ?? ''),
            'error' => (int) ($_FILES[$key]['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($_FILES[$key]['size'][$i] ?? 0),
        ];
    }
    return $files;
}

function store_upload(array $file, string $folder, int $maxBytes = 0): array
{
    $maxBytes = $maxBytes > 0 ? $maxBytes : upload_max_bytes();
    $tooLarge = 'The file is too large. Maximum size is ' . upload_bytes_label($maxBytes) . '.';
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file was uploaded.'];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => $tooLarge];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The file could not be uploaded.'];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => $tooLarge];
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
    if (in_array($folder, ['payments', 'gallery'], true)) {
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
