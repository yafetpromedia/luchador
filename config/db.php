<?php

require_once __DIR__ . '/env.php';
load_env_file();

function db_config(): array
{
    return [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'luchador_db'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
    ];
}

function db_connect(bool $withDatabase = true): PDO
{
    $cfg = db_config();
    $dsn = $withDatabase
        ? sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $cfg['charset'])
        : sprintf('mysql:host=%s;charset=%s', $cfg['host'], $cfg['charset']);

    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    try {
        $pdo = db_connect(true);
    } catch (PDOException $e) {
        $unknownDb = str_contains($e->getMessage(), 'Unknown database') || (string) $e->getCode() === '1049';
        if ($unknownDb) {
            $cfg = db_config();
            $name = preg_replace('/[^a-zA-Z0-9_]/', '', $cfg['name']) ?: 'luchador_db';
            $server = db_connect(false);
            $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = db_connect(true);
        } else {
            throw $e;
        }
    }
    return $pdo;
}

$pdo = null;
try {
    $pdo = db();
} catch (PDOException $e) {
    if (defined('APP_SETUP') && APP_SETUP) {
        $pdo = null;
    } else {
        error_log('Luchador DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('The application is temporarily unavailable.');
    }
}
