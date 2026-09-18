<?php

declare(strict_types=1);

function settings_all(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $defaults = setting_defaults();
    $cache = $defaults;

    try {
        if (!table_exists(db(), 'settings')) {
            return $cache;
        }
        $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        foreach ($rows as $row) {
            $cache[$row['setting_key']] = (string) $row['setting_value'];
        }
    } catch (Throwable $e) {
        app_log('settings_all: ' . $e->getMessage());
    }

    return $cache;
}

function setting(string $key, ?string $default = null): string
{
    $all = settings_all();
    if (array_key_exists($key, $all) && $all[$key] !== '') {
        return (string) $all[$key];
    }
    if ($default !== null) {
        return $default;
    }
    $defaults = setting_defaults();
    return (string) ($all[$key] ?? $defaults[$key] ?? '');
}

function setting_bool(string $key): bool
{
    $value = strtolower(setting($key, '0'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function save_settings(array $values): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($values as $key => $value) {
        $stmt->execute([(string) $key, (string) $value]);
    }
}

function class_name(): string
{
    return setting('class_name', 'LUCHADOR');
}

function class_grade(): string
{
    return setting('grade', '12');
}

function school_name(): string
{
    return setting('school_name', 'Bright Side International School');
}

function site_title(string $page = ''): string
{
    $base = class_name() . ' — Grade ' . class_grade() . ' | ' . school_name();
    return $page !== '' ? $page . ' · ' . $base : $base;
}

function public_stats(): array
{
    $stats = [
        'grade' => class_grade(),
        'students' => 0,
        'events' => 0,
        'achievements' => 0,
    ];

    try {
        $pdo = db();
        if (table_exists($pdo, 'events') && column_exists($pdo, 'events', 'visibility')) {
            $stats['events'] = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE visibility = 'public'")->fetchColumn();
        } elseif (table_exists($pdo, 'events')) {
            $stats['events'] = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE published = 1")->fetchColumn();
        }
        if (table_exists($pdo, 'achievements') && column_exists($pdo, 'achievements', 'visibility')) {
            $stats['achievements'] = (int) $pdo->query("SELECT COUNT(*) FROM achievements WHERE visibility = 'public'")->fetchColumn();
        } elseif (table_exists($pdo, 'achievements')) {
            $stats['achievements'] = (int) $pdo->query("SELECT COUNT(*) FROM achievements WHERE published = 1")->fetchColumn();
        }
    } catch (Throwable $e) {
        app_log('public_stats: ' . $e->getMessage());
    }

    return $stats;
}
