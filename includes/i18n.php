<?php

declare(strict_types=1);

function supported_languages(): array
{
    return [
        'en' => ['name' => 'English', 'native' => 'English', 'short' => 'EN'],
        'am' => ['name' => 'Amharic', 'native' => 'አማርኛ', 'short' => 'አማ'],
    ];
}

function current_lang(): string
{
    $lang = (string) ($_SESSION['lang'] ?? '');
    if (isset(supported_languages()[$lang])) {
        return $lang;
    }
    return 'en';
}

function init_language(): void
{
    $supported = supported_languages();
    $lang = (string) ($_SESSION['lang'] ?? '');
    if (!isset($supported[$lang])) {
        $cookie = preg_replace('/[^a-z]/', '', strtolower((string) ($_COOKIE['luchador_lang'] ?? ''))) ?? '';
        $lang = isset($supported[$cookie]) ? $cookie : 'en';
        $_SESSION['lang'] = $lang;
    }
}

function set_language(string $code): void
{
    $code = strtolower(trim($code));
    if (!isset(supported_languages()[$code])) {
        $code = 'en';
    }
    $_SESSION['lang'] = $code;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $path = base_url();
    $path = $path === '' ? '/' : rtrim($path, '/') . '/';
    setcookie('luchador_lang', $code, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => $path,
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function translations(?string $lang = null): array
{
    static $cache = [];
    $lang = $lang ?? current_lang();
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }
    $file = APP_ROOT . '/lang/' . $lang . '.php';
    $rows = is_file($file) ? include $file : [];
    $cache[$lang] = is_array($rows) ? $rows : [];
    return $cache[$lang];
}

function t(string $key, array $replace = []): string
{
    $lang = translations();
    $fallback = current_lang() !== 'en' ? translations('en') : [];
    $text = $lang[$key] ?? $fallback[$key] ?? $key;
    foreach ($replace as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

function language_return_path(): string
{
    $next = trim((string) ($_GET['next'] ?? ''));
    if ($next === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $ref = parse_url((string) $_SERVER['HTTP_REFERER']);
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        $refHost = strtolower((string) ($ref['host'] ?? ''));
        if ($refHost === '' || $refHost === $host) {
            $path = (string) ($ref['path'] ?? '');
            $base = base_url();
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
            $next = ltrim($path, '/');
            $query = (string) ($ref['query'] ?? '');
            $fragment = (string) ($ref['fragment'] ?? '');
            if ($query !== '') {
                $next .= '?' . $query;
            }
            if ($fragment !== '') {
                $next .= '#' . $fragment;
            }
        }
    }
    $next = ltrim($next, '/');
    if ($next === '' || str_contains($next, '..') || str_contains($next, '://') || str_starts_with($next, '//') || str_starts_with($next, 'lang.php')) {
        return 'index.php';
    }
    return $next;
}

function language_url(string $code): string
{
    $here = (string) ($_SERVER['REQUEST_URI'] ?? 'index.php');
    $path = parse_url($here, PHP_URL_PATH) ?: 'index.php';
    $query = (string) (parse_url($here, PHP_URL_QUERY) ?? '');
    $base = base_url();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $next = ltrim($path, '/');
    if ($query !== '') {
        $next .= '?' . $query;
    }
    if ($next === '') {
        $next = 'index.php';
    }
    return url('lang.php') . '?set=' . rawurlencode($code) . '&next=' . rawurlencode($next);
}

function render_language_switcher(string $extraClass = ''): void
{
    $current = current_lang();
    $meta = supported_languages()[$current];
    $class = 'lang-switch' . ($extraClass !== '' ? ' ' . $extraClass : '');
    echo '<details class="' . e($class) . '">';
    echo '<summary aria-label="' . e(t('lang.label')) . ': ' . e($meta['native']) . '">';
    echo '<span>' . e($meta['short']) . '</span>';
    echo '</summary>';
    echo '<div class="lang-switch-panel">';
    foreach (supported_languages() as $code => $row) {
        $active = $code === $current ? ' aria-current="true"' : '';
        echo '<a href="' . e(language_url($code)) . '"' . $active . '>' . e($row['native']) . '</a>';
    }
    echo '</div></details>';
}
