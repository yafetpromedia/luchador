<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

stream_gallery_photo(request_int('id'), request_str('download') === '1');
