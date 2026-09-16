<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $token = $token ?? ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || $token === '' || empty($_SESSION['_csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['_csrf'], $token);
}

function require_csrf(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($token) || $token === '') {
        $input = json_input();
        $token = $input['_csrf'] ?? null;
    }
    if (!verify_csrf(is_string($token) ? $token : null)) {
        if (wants_json()) {
            json_response(['error' => 'Your session expired. Please refresh and try again.'], 419);
        }
        flash_set('error', 'Your session expired. Please refresh and try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        if ($back === '' || !str_starts_with($back, (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://')) {
            $script = ltrim(str_replace(base_url(), '', $_SERVER['SCRIPT_NAME'] ?? 'login.php'), '/');
            $back = url($script !== '' ? $script : 'login.php');
        }
        header('Location: ' . $back);
        exit;
    }
}

function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    return str_contains($accept, 'application/json')
        || strcasecmp($xhr, 'XMLHttpRequest') === 0
        || str_contains($contentType, 'application/json');
}
