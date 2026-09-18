<?php

declare(strict_types=1);

function notification_audiences(?array $user = null): array
{
    $user = $user ?? current_user();
    if (!$user) {
        return ['public'];
    }
    if (($user['role'] ?? '') === 'student') {
        return ['user', 'student', 'public'];
    }
    return ['user', 'staff'];
}

function user_id_for_student(int $studentId): ?int
{
    if ($studentId < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function notification_viewer_id(?array $user): int
{
    return (int) (($user ?? [])['id'] ?? 0);
}

function notify(array $payload): int
{
    try {
        if (!table_exists(db(), 'notifications')) {
            return 0;
        }
        $audience = (string) ($payload['audience'] ?? 'user');
        if (!in_array($audience, ['user', 'staff', 'student', 'public'], true)) {
            $audience = 'user';
        }
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        if ($audience === 'user' && $userId < 1) {
            return 0;
        }
        $type = excerpt((string) ($payload['type'] ?? 'notice'), 60);
        $title = excerpt((string) ($payload['title'] ?? ''), 180);
        if ($title === '') {
            return 0;
        }
        $targetType = excerpt((string) ($payload['target_type'] ?? ''), 40);
        $targetId = isset($payload['target_id']) ? (int) $payload['target_id'] : 0;
        if (notification_recent_duplicate($audience, $type, $targetType, $targetId, $userId)) {
            return 0;
        }
        $actor = notification_viewer_id(current_user());
        db()->prepare(
            'INSERT INTO notifications (audience, user_id, actor_id, type, title, body, url, icon, target_type, target_id)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $audience,
            $audience === 'user' ? $userId : null,
            $actor > 0 ? $actor : null,
            $type,
            $title,
            excerpt((string) ($payload['body'] ?? ''), 400) ?: null,
            excerpt((string) ($payload['url'] ?? ''), 255) ?: null,
            excerpt((string) ($payload['icon'] ?? ''), 40) ?: null,
            $targetType !== '' ? $targetType : null,
            $targetId > 0 ? $targetId : null,
        ]);
        return (int) db()->lastInsertId();
    } catch (Throwable $e) {
        app_log('notify: ' . $e->getMessage());
        return 0;
    }
}

function notification_recent_duplicate(string $audience, string $type, string $targetType, int $targetId, int $userId): bool
{
    if ($targetType === '' || $targetId < 1) {
        return false;
    }
    $sql = 'SELECT id FROM notifications
            WHERE audience = ? AND type = ? AND target_type = ? AND target_id = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)';
    $params = [$audience, $type, $targetType, $targetId];
    if ($audience === 'user') {
        $sql .= ' AND user_id = ?';
        $params[] = $userId;
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function notify_user(int $userId, array $payload): int
{
    $payload['audience'] = 'user';
    $payload['user_id'] = $userId;
    return notify($payload);
}

function notify_student_record(int $studentId, array $payload): int
{
    $userId = user_id_for_student($studentId);
    return $userId ? notify_user($userId, $payload) : 0;
}

function notify_staff(array $payload): int
{
    $payload['audience'] = 'staff';
    return notify($payload);
}

function notify_students(array $payload): int
{
    $payload['audience'] = 'student';
    return notify($payload);
}

function notify_public(array $payload): int
{
    $payload['audience'] = 'public';
    return notify($payload);
}

function notify_class(array $payload): void
{
    $student = $payload;
    $public = $payload;
    if (!empty($payload['url_student'])) {
        $student['url'] = $payload['url_student'];
    }
    if (!empty($payload['url_public'])) {
        $public['url'] = $payload['url_public'];
    }
    notify_students($student);
    notify_public($public);
}

function notify_if_published(bool $wasPublished, bool $nowPublished, array $payload): void
{
    notify_if_visibility($wasPublished ? 'public' : 'draft', $nowPublished ? 'public' : 'private', $payload);
}

function notify_if_visibility(string $was, string $now, array $payload): void
{
    $was = normalize_content_visibility($was !== '' ? $was : 'draft', 'draft');
    $now = normalize_content_visibility($now !== '' ? $now : 'draft', 'draft');
    $wasPublic = $was === 'public';
    $nowPublic = $now === 'public';
    $wasClass = in_array($was, ['private', 'public'], true);
    $nowClass = in_array($now, ['private', 'public'], true);
    if ($nowPublic && !$wasPublic) {
        notify_class($payload);
        return;
    }
    if ($nowClass && !$wasClass) {
        notify_students($payload);
    }
}

function notifications_visible_where(?array $user, string &$sql, array &$params): void
{
    $audiences = notification_audiences($user);
    $actor = notification_viewer_id($user);
    $in = implode(',', array_fill(0, count($audiences), '?'));
    $sql = "n.audience IN ($in)";
    $params = $audiences;
    if (in_array('user', $audiences, true) && $actor > 0) {
        $sql .= ' AND (n.audience != \'user\' OR n.user_id = ?)';
        $params[] = $actor;
    } else {
        $sql .= ' AND n.audience != \'user\'';
    }
    if ($actor > 0) {
        $sql .= ' AND (n.actor_id IS NULL OR n.actor_id != ? OR n.audience = \'user\')';
        $params[] = $actor;
    }
}

function notifications_for_viewer(int $limit = 30, int $sinceId = 0): array
{
    if (!table_exists(db(), 'notifications')) {
        return [];
    }
    $user = current_user();
    $userId = notification_viewer_id($user);
    $where = '';
    $params = [];
    notifications_visible_where($user, $where, $params);
    $sql = "SELECT n.*, CASE WHEN r.user_id IS NULL THEN 0 ELSE 1 END AS is_read
            FROM notifications n
            LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
            WHERE $where AND n.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)";
    array_unshift($params, $userId);
    if ($sinceId > 0) {
        $sql .= ' AND n.id > ?';
        $params[] = $sinceId;
    }
    $sql .= ' ORDER BY n.id DESC LIMIT ' . max(1, min(50, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function notification_unread_count(?array $user = null): int
{
    if (!table_exists(db(), 'notifications')) {
        return 0;
    }
    $user = $user ?? current_user();
    $userId = notification_viewer_id($user);
    $where = '';
    $params = [];
    notifications_visible_where($user, $where, $params);
    $sql = "SELECT COUNT(*) FROM notifications n
            LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
            WHERE $where AND r.user_id IS NULL AND n.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)";
    array_unshift($params, $userId);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function notification_latest_id(): int
{
    if (!table_exists(db(), 'notifications')) {
        return 0;
    }
    $user = current_user();
    $where = '';
    $params = [];
    notifications_visible_where($user, $where, $params);
    $stmt = db()->prepare("SELECT COALESCE(MAX(n.id), 0) FROM notifications n WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function notification_mark_read(int $id, int $userId): void
{
    if ($id < 1 || $userId < 1) {
        return;
    }
    db()->prepare(
        'INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at) VALUES (?, ?, NOW())'
    )->execute([$id, $userId]);
}

function notification_mark_all_read(int $userId): void
{
    if ($userId < 1) {
        return;
    }
    $rows = notifications_for_viewer(50);
    foreach ($rows as $row) {
        if (empty($row['is_read'])) {
            notification_mark_read((int) $row['id'], $userId);
        }
    }
}

function notification_present(array $row, string $portal = 'public'): array
{
    $path = (string) ($row['url'] ?? '');
    if ($path !== '' && $portal === 'public' && str_starts_with($path, 'student/')) {
        $map = [
            'student/announcements.php' => 'index.php',
            'student/events.php' => 'index.php#events',
            'student/event.php' => 'index.php#events',
            'student/calendar.php' => 'index.php#events',
            'student/gallery.php' => 'index.php#gallery',
            'student/graduation.php' => 'index.php#graduation',
        ];
        foreach ($map as $from => $to) {
            if (str_starts_with($path, $from)) {
                $path = $to;
                break;
            }
        }
    }
    return [
        'id' => (int) $row['id'],
        'type' => (string) ($row['type'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'url' => $path !== '' ? url($path) : '',
        'icon' => (string) ($row['icon'] ?? 'bell'),
        'read' => !empty($row['is_read']),
        'when' => format_relative($row['created_at'] ?? null) ?: 'Just now',
    ];
}

function notifications_payload(string $portal = 'public'): array
{
    $rows = notifications_for_viewer(20);
    $items = array_map(static fn (array $row): array => notification_present($row, $portal), $rows);
    $user = current_user();
    $unread = $user ? notification_unread_count($user) : count(array_filter($items, static fn (array $item): bool => empty($item['read'])));
    return [
        'items' => $items,
        'unread' => $unread,
        'latest_id' => $rows ? (int) $rows[0]['id'] : notification_latest_id(),
        'authenticated' => (bool) $user,
        'portal' => $portal,
        'poll' => url('api/notifications.php'),
        'stream' => url('api/notifications-stream.php'),
    ];
}

function render_notification_bell(string $portal): void
{
    $payload = notifications_payload($portal);
    $unread = (int) $payload['unread'];
    $panelId = 'notif-panel-' . $portal;
    $countHidden = $unread > 0 ? '' : ' hidden';
    $countText = $unread > 99 ? '99+' : (string) $unread;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';

    echo '<div class="notif-bell" data-notifications data-portal="' . e($portal) . '">';
    echo '<button type="button" class="notif-toggle" aria-expanded="false" aria-controls="' . e($panelId) . '" aria-label="Notifications">';
    echo icon('bell', 18);
    echo '<span class="notif-count"' . $countHidden . '>' . e($countText) . '</span>';
    echo '</button>';
    echo '<div class="notif-panel" id="' . e($panelId) . '" hidden>';
    echo '<div class="notif-head"><strong>Notifications</strong>';
    echo '<button type="button" class="notif-read-all" data-notif-read-all>Mark all read</button></div>';
    echo '<div class="notif-list" data-notif-list>';
    if (empty($payload['items'])) {
        echo '<p class="notif-empty">No notifications yet.</p>';
    } else {
        foreach ($payload['items'] as $item) {
            $href = $item['url'] !== '' ? $item['url'] : '#';
            $readClass = !empty($item['read']) ? ' is-read' : '';
            echo '<a class="notif-item' . $readClass . '" href="' . e($href) . '" data-notif-id="' . (int) $item['id'] . '">';
            echo '<span class="notif-item-title">' . e($item['title']) . '</span>';
            if ($item['body'] !== '') {
                echo '<span class="notif-item-body">' . e($item['body']) . '</span>';
            }
            echo '<span class="notif-item-when">' . e($item['when']) . '</span></a>';
        }
    }
    echo '</div></div>';
    echo '<script type="application/json" data-notif-bootstrap>' . $json . '</script>';
    echo '</div>';
}
