<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$code = strtolower(trim((string) ($_GET['set'] ?? '')));
if ($code !== '') {
    set_language($code);
}

$next = language_return_path();
if (preg_match('#^https?://#i', $next)) {
    $next = 'index.php';
}
redirect($next);
