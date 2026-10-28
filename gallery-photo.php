<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$download = request_str('download') === '1';
$size = request_str('size');
if ($download) {
    $size = 'full';
} elseif (!in_array($size, ['thumb', 'view', 'full'], true)) {
    $size = 'view';
}

stream_gallery_photo(request_int('id'), $download, $size);
