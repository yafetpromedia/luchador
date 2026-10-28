<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$portal = (string) ($_GET['portal'] ?? $_POST['portal'] ?? 'public');
if (!in_array($portal, ['public', 'student', 'admin'], true)) {
    $portal = 'public';
}

if (is_post()) {
    require_csrf();
    $user = current_user();
    if (!$user) {
        json_response(['error' => 'Sign in to update notifications.'], 401);
    }
    $input = json_input();
    $action = (string) ($input['action'] ?? $_POST['action'] ?? '');
    $id = (int) ($input['id'] ?? $_POST['id'] ?? 0);
    $userId = (int) $user['id'];

    if ($action === 'read_all') {
        notification_mark_all_read($userId);
    } elseif ($action === 'delete' || $action === 'dismiss') {
        if ($id > 0 && notification_visible_to_user($id, $user)) {
            notification_dismiss($id, $userId);
        }
    } elseif ($action === 'read') {
        if ($id > 0 && notification_visible_to_user($id, $user)) {
            notification_mark_read($id, $userId);
        }
    } elseif ($id > 0) {
        // Backward-compatible default: treat bare id posts as mark-read.
        if (notification_visible_to_user($id, $user)) {
            notification_mark_read($id, $userId);
        }
    }
}

json_response(notifications_payload($portal));
