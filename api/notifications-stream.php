<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
ignore_user_abort(true);
set_time_limit(40);

$portal = (string) ($_GET['portal'] ?? 'public');
if (!in_array($portal, ['public', 'student', 'admin'], true)) {
    $portal = 'public';
}
$since = request_int('since');
if ($since < 1) {
    $since = notification_latest_id();
}

current_user();
session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    ob_end_flush();
}

echo "retry: 4000\n\n";
echo 'data: ' . json_encode(['ok' => true, 'latest_id' => $since]) . "\n\n";
flush();

for ($i = 0; $i < 12; $i++) {
    if (connection_aborted()) {
        break;
    }
    $latest = notification_latest_id();
    if ($latest > $since) {
        echo 'event: notifications' . "\n";
        echo 'data: ' . json_encode(['latest_id' => $latest]) . "\n\n";
        $since = $latest;
        flush();
    } else {
        echo ": keepalive\n\n";
        flush();
    }
    sleep(2);
}
