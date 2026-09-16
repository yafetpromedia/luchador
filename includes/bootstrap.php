<?php

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/includes/helpers.php';
start_app_session();

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/accounts.php';
require_once APP_ROOT . '/includes/settings.php';
require_once APP_ROOT . '/includes/upload.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/migrate.php';
require_once APP_ROOT . '/includes/audit.php';
require_once APP_ROOT . '/includes/notifications.php';

if (!defined('APP_SETUP')) {
    ensure_schema();
}
