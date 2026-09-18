<?php

declare(strict_types=1);

define('APP_SETUP', true);
define('APP_ROOT', __DIR__);

require_once __DIR__ . '/includes/helpers.php';
start_app_session();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/migrate.php';

if (app_is_production()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$messages = [];
$errors = [];
$createdAdmin = false;

try {
    $cfg = db_config();
    $server = db_connect(false);
    $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $cfg['name']) ?: 'luchador_db';
    $server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = db_connect(true);

    $hadUsers = table_exists($pdo, 'users')
        ? (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()
        : 0;

    run_migrations($pdo);

    $createdAdmin = $hadUsers === 0 && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    $messages[] = 'Database is ready. Existing student and uniform records were preserved.';
    if ($createdAdmin) {
        $messages[] = 'A first administrator account was created. Sign in, then change the password immediately in Settings.';
    } else {
        $messages[] = 'Existing administrator accounts were left unchanged.';
    }
} catch (Throwable $e) {
    app_log('Setup failed: ' . $e->getMessage());
    $errors[] = 'Setup could not finish. Check that MySQL is running and the database credentials in .env or config/db.php are correct.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luchador setup</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
    <main class="auth-card">
        <p class="eyebrow">LUCHADOR</p>
        <h1>Database setup</h1>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success"><?= e($message) ?></div>
        <?php endforeach; ?>
        <?php if (!$errors): ?>
            <p class="muted">This script is safe to run again. It will not wipe student data.</p>
            <p><a class="btn" href="login.php">Go to admin login</a> <a class="btn btn-ghost" href="index.php">View public site</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
