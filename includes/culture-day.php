<?php

declare(strict_types=1);

require_once __DIR__ . '/queries.php';

function culture_day_statuses(): array
{
    return [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ];
}

function culture_day_status_label(string $status): string
{
    return culture_day_statuses()[$status] ?? 'Draft';
}

function culture_day_normalize_status(string $raw): string
{
    $raw = strtolower(trim($raw));
    return isset(culture_day_statuses()[$raw]) ? $raw : 'draft';
}

function culture_day_name_key(string $name): string
{
    $key = strtolower(trim($name));
    $key = preg_replace('/\s+/', ' ', $key) ?? $key;
    return mb_substr($key, 0, 120);
}

function student_gender_options(): array
{
    return [
        '' => 'Not set',
        'male' => 'Male',
        'female' => 'Female',
    ];
}

function student_gender_of(array $row): string
{
    if (!array_key_exists('gender', $row) && !column_exists(db(), 'uniforms', 'gender')) {
        return 'unknown';
    }
    $raw = strtolower(trim((string) ($row['gender'] ?? '')));
    if ($raw === 'male' || $raw === 'm') {
        return 'male';
    }
    if ($raw === 'female' || $raw === 'f') {
        return 'female';
    }
    if ($raw === '') {
        return 'unknown';
    }
    return 'unverified';
}

/** Record gender on the student (uniforms) row. Never inferred from names. */
function set_student_gender_value(int $studentId, string $gender): void
{
    if ($studentId < 1) {
        throw new InvalidArgumentException('Choose a student.');
    }
    if (!column_exists(db(), 'uniforms', 'gender')) {
        throw new InvalidArgumentException('Gender field is not available yet. Refresh and try again.');
    }
    $gender = strtolower(trim($gender));
    if (!in_array($gender, ['male', 'female'], true)) {
        throw new InvalidArgumentException('Choose male or female.');
    }
    $student = student_by_id($studentId);
    if (!$student) {
        throw new InvalidArgumentException('Student not found.');
    }
    db()->prepare('UPDATE uniforms SET gender = ? WHERE id = ?')->execute([$gender, $studentId]);
}

/** Assign many students to male or female. Returns how many rows were updated. */
function assign_students_gender(array $studentIds, string $gender): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        throw new InvalidArgumentException('Select at least one student.');
    }
    $updated = 0;
    foreach ($ids as $id) {
        set_student_gender_value($id, $gender);
        $updated++;
    }
    return $updated;
}

function student_gender_label(string $key): string
{
    return match ($key) {
        'male' => 'Male',
        'female' => 'Female',
        'unverified' => 'Unverified',
        default => 'Not set',
    };
}

function can_view_culture_day(): bool
{
    return can_any(['culture_day.view', 'culture_day.manage', 'culture_day.publish']);
}

function can_manage_culture_day(): bool
{
    return can('culture_day.manage') || can('culture_day.publish');
}

function can_publish_culture_day(): bool
{
    return can('culture_day.publish') || can('culture_day.manage');
}

function culture_day_event_by_id(int $id): ?array
{
    if ($id < 1 || !table_exists(db(), 'culture_day_events')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM culture_day_events WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function culture_day_events(?string $status = null): array
{
    if (!table_exists(db(), 'culture_day_events')) {
        return [];
    }
    if ($status !== null && $status !== '') {
        $stmt = db()->prepare('SELECT * FROM culture_day_events WHERE status = ? ORDER BY (event_date IS NULL), event_date DESC, id DESC');
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM culture_day_events ORDER BY (event_date IS NULL), event_date DESC, id DESC')->fetchAll();
}

function culture_day_published_events(): array
{
    return culture_day_events('published');
}

function culture_day_published_event(?int $id = null): ?array
{
    if ($id !== null && $id > 0) {
        $event = culture_day_event_by_id($id);
        return $event && ($event['status'] ?? '') === 'published' ? $event : null;
    }
    $rows = culture_day_published_events();
    return $rows[0] ?? null;
}

function culture_day_students_can_view(?array $event): bool
{
    return $event !== null && ($event['status'] ?? '') === 'published';
}

function save_culture_day_event(array $src, ?int $id = null): array
{
    $title = trim((string) ($src['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('Event title is required.');
    }
    $description = trim((string) ($src['description'] ?? '')) ?: null;
    $eventDate = trim((string) ($src['event_date'] ?? '')) ?: null;
    if ($eventDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        throw new InvalidArgumentException('Event date is invalid.');
    }
    $userId = (int) (current_user()['id'] ?? 0) ?: null;

    if ($id) {
        $existing = culture_day_event_by_id($id);
        if (!$existing) {
            throw new InvalidArgumentException('Culture Day event not found.');
        }
        db()->prepare(
            'UPDATE culture_day_events SET title = ?, description = ?, event_date = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$title, $description, $eventDate, $id]);
        log_audit('culture_day.event_save', 'culture_day_event', $id, $title);
        $saved = culture_day_event_by_id($id);
    } else {
        db()->prepare(
            'INSERT INTO culture_day_events (title, description, event_date, status, created_by) VALUES (?,?,?,?,?)'
        )->execute([$title, $description, $eventDate, 'draft', $userId]);
        $id = (int) db()->lastInsertId();
        log_audit('culture_day.event_save', 'culture_day_event', $id, $title);
        $saved = culture_day_event_by_id($id);
    }
    if (!$saved) {
        throw new RuntimeException('Could not save the Culture Day event.');
    }
    return $saved;
}

function set_culture_day_status(int $id, string $status): array
{
    $event = culture_day_event_by_id($id);
    if (!$event) {
        throw new InvalidArgumentException('Culture Day event not found.');
    }
    $status = culture_day_normalize_status($status);
    $was = (string) ($event['status'] ?? 'draft');
    if ($status === 'published' && $was !== 'published') {
        $issues = culture_day_validation_issues($id);
        $blocking = array_values(array_filter($issues, static fn(array $i): bool => ($i['level'] ?? '') === 'error'));
        if ($blocking !== []) {
            throw new InvalidArgumentException('Resolve validation errors before publishing. ' . $blocking[0]['message']);
        }
    }
    $userId = (int) (current_user()['id'] ?? 0) ?: null;
    if ($status === 'published') {
        db()->prepare(
            'UPDATE culture_day_events SET status = ?, published_by = ?, published_at = COALESCE(published_at, NOW()), archived_at = NULL, updated_at = NOW() WHERE id = ?'
        )->execute([$status, $userId, $id]);
        log_audit('culture_day.publish', 'culture_day_event', $id, $event['title'] ?? '');
        notify_students([
            'type' => 'culture_day.published',
            'title' => 'Culture Day is live',
            'body' => (string) ($event['title'] ?? 'Culture Day'),
            'icon' => 'flag',
            'target_type' => 'culture_day_event',
            'target_id' => $id,
            'url' => 'student/culture-day.php?id=' . $id,
        ]);
    } elseif ($status === 'archived') {
        db()->prepare(
            'UPDATE culture_day_events SET status = ?, archived_at = NOW(), updated_at = NOW() WHERE id = ?'
        )->execute([$status, $id]);
        log_audit('culture_day.archive', 'culture_day_event', $id, $event['title'] ?? '');
    } else {
        db()->prepare(
            'UPDATE culture_day_events SET status = ?, published_at = NULL, published_by = NULL, archived_at = NULL, updated_at = NOW() WHERE id = ?'
        )->execute(['draft', $id]);
        log_audit('culture_day.unpublish', 'culture_day_event', $id, $event['title'] ?? '');
    }
    $saved = culture_day_event_by_id($id);
    if (!$saved) {
        throw new RuntimeException('Could not update Culture Day status.');
    }
    return $saved;
}

function find_or_create_culture(int $eventId, string $name): array
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Culture is required.');
    }
    $key = culture_day_name_key($name);
    $stmt = db()->prepare('SELECT * FROM culture_day_cultures WHERE event_id = ? AND name_key = ? LIMIT 1');
    $stmt->execute([$eventId, $key]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    db()->prepare('INSERT INTO culture_day_cultures (event_id, name, name_key) VALUES (?,?,?)')
        ->execute([$eventId, mb_substr($name, 0, 120), $key]);
    $id = (int) db()->lastInsertId();
    log_audit('culture_day.culture_save', 'culture_day_culture', $id, $name);
    $stmt->execute([$eventId, $key]);
    $created = $stmt->fetch();
    if (!$created) {
        throw new RuntimeException('Could not save the culture.');
    }
    return $created;
}

function culture_day_cultures(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM culture_day_cultures WHERE event_id = ? ORDER BY name ASC');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function culture_day_group_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT g.*, c.name AS culture_name FROM culture_day_groups g INNER JOIN culture_day_cultures c ON c.id = g.culture_id WHERE g.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function culture_day_groups(int $eventId): array
{
    $stmt = db()->prepare(
        'SELECT g.*, c.name AS culture_name,
            (SELECT COUNT(*) FROM culture_day_members m WHERE m.group_id = g.id) AS member_count
         FROM culture_day_groups g
         INNER JOIN culture_day_cultures c ON c.id = g.culture_id
         WHERE g.event_id = ?
         ORDER BY c.name ASC, g.name ASC'
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function culture_day_members(int $groupId): array
{
    $hasGender = column_exists(db(), 'uniforms', 'gender');
    $genderCol = $hasGender ? ', u.gender' : ', NULL AS gender';
    $stmt = db()->prepare(
        'SELECT m.id AS membership_id, m.group_id, m.student_id, m.event_id,
                u.student_name, u.student_code, u.section, u.photo' . $genderCol . '
         FROM culture_day_members m
         INNER JOIN uniforms u ON u.id = m.student_id
         WHERE m.group_id = ?
         ORDER BY u.student_name ASC'
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

function culture_day_event_members(int $eventId): array
{
    $hasGender = column_exists(db(), 'uniforms', 'gender');
    $genderCol = $hasGender ? ', u.gender' : ', NULL AS gender';
    $stmt = db()->prepare(
        'SELECT m.id AS membership_id, m.group_id, m.student_id, m.event_id,
                g.name AS group_name, c.id AS culture_id, c.name AS culture_name,
                u.student_name, u.student_code, u.section, u.photo' . $genderCol . '
         FROM culture_day_members m
         INNER JOIN culture_day_groups g ON g.id = m.group_id
         INNER JOIN culture_day_cultures c ON c.id = g.culture_id
         INNER JOIN uniforms u ON u.id = m.student_id
         WHERE m.event_id = ?
         ORDER BY c.name ASC, g.name ASC, u.student_name ASC'
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function save_culture_day_group(int $eventId, array $src, ?int $groupId = null): array
{
    $event = culture_day_event_by_id($eventId);
    if (!$event) {
        throw new InvalidArgumentException('Culture Day event not found.');
    }
    $name = trim((string) ($src['name'] ?? ''));
    $cultureName = trim((string) ($src['culture'] ?? ''));
    $notes = trim((string) ($src['notes'] ?? '')) ?: null;
    if ($name === '') {
        throw new InvalidArgumentException('Group name or number is required.');
    }
    if ($cultureName === '') {
        throw new InvalidArgumentException('Culture is required.');
    }

    $parseIds = static function ($raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            $sid = (int) $value;
            if ($sid > 0) {
                $ids[$sid] = $sid;
            }
        }
        return $ids;
    };
    $maleIds = $parseIds($src['male_ids'] ?? []);
    $femaleIds = $parseIds($src['female_ids'] ?? []);
    // Legacy single list still accepted.
    if ($maleIds === [] && $femaleIds === [] && isset($src['member_ids'])) {
        foreach ($parseIds($src['member_ids']) as $sid) {
            $stu = student_by_id($sid);
            $g = $stu ? student_gender_of($stu) : '';
            if ($g === 'female') {
                $femaleIds[$sid] = $sid;
            } else {
                $maleIds[$sid] = $sid;
            }
        }
    }
    $overlap = array_intersect_key($maleIds, $femaleIds);
    if ($overlap !== []) {
        throw new InvalidArgumentException('A student cannot be listed in both Male and Female for the same group.');
    }
    $memberIds = array_values($maleIds + $femaleIds);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $culture = find_or_create_culture($eventId, $cultureName);
        if ($groupId) {
            $existing = culture_day_group_by_id($groupId);
            if (!$existing || (int) $existing['event_id'] !== $eventId) {
                throw new InvalidArgumentException('Group not found.');
            }
            $pdo->prepare(
                'UPDATE culture_day_groups SET culture_id = ?, name = ?, notes = ?, updated_at = NOW() WHERE id = ? AND event_id = ?'
            )->execute([(int) $culture['id'], mb_substr($name, 0, 120), $notes, $groupId, $eventId]);
            log_audit('culture_day.group_save', 'culture_day_group', $groupId, $name);
        } else {
            $pdo->prepare(
                'INSERT INTO culture_day_groups (event_id, culture_id, name, notes) VALUES (?,?,?,?)'
            )->execute([$eventId, (int) $culture['id'], mb_substr($name, 0, 120), $notes]);
            $groupId = (int) $pdo->lastInsertId();
            log_audit('culture_day.group_save', 'culture_day_group', $groupId, $name);
        }

        $invalid = [];
        foreach ($memberIds as $sid) {
            if (!student_by_id($sid)) {
                $invalid[] = $sid;
            }
        }
        if ($invalid !== []) {
            throw new InvalidArgumentException('One or more student IDs are invalid.');
        }

        foreach ($maleIds as $sid) {
            set_student_gender_value($sid, 'male');
        }
        foreach ($femaleIds as $sid) {
            set_student_gender_value($sid, 'female');
        }

        $pdo->prepare('DELETE FROM culture_day_members WHERE group_id = ?')->execute([$groupId]);
        $insert = $pdo->prepare(
            'INSERT INTO culture_day_members (event_id, group_id, student_id) VALUES (?,?,?)'
        );
        foreach ($memberIds as $sid) {
            $insert->execute([$eventId, $groupId, $sid]);
        }
        log_audit('culture_day.members_save', 'culture_day_group', $groupId, $name . ' · ' . count($memberIds) . ' members');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $saved = culture_day_group_by_id($groupId);
    if (!$saved) {
        throw new RuntimeException('Could not save the group.');
    }
    return $saved;
}

function delete_culture_day_group(int $groupId): void
{
    $group = culture_day_group_by_id($groupId);
    if (!$group) {
        throw new InvalidArgumentException('Group not found.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM culture_day_members WHERE group_id = ?')->execute([$groupId]);
        $pdo->prepare('DELETE FROM culture_day_groups WHERE id = ?')->execute([$groupId]);
        $cultureId = (int) $group['culture_id'];
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM culture_day_groups WHERE culture_id = ?');
        $countStmt->execute([$cultureId]);
        if ((int) $countStmt->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM culture_day_cultures WHERE id = ?')->execute([$cultureId]);
        }
        $pdo->commit();
        log_audit('culture_day.group_delete', 'culture_day_group', $groupId, $group['name'] ?? '');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function culture_day_validation_issues(int $eventId): array
{
    $issues = [];
    $event = culture_day_event_by_id($eventId);
    if (!$event) {
        return [['level' => 'error', 'code' => 'missing_event', 'message' => 'Event not found.']];
    }
    if (trim((string) ($event['title'] ?? '')) === '') {
        $issues[] = ['level' => 'error', 'code' => 'title', 'message' => 'Event title is missing.'];
    }
    if (empty($event['event_date'])) {
        $issues[] = ['level' => 'warn', 'code' => 'date', 'message' => 'Event date is not set.'];
    }

    $groups = culture_day_groups($eventId);
    if ($groups === []) {
        $issues[] = ['level' => 'error', 'code' => 'no_groups', 'message' => 'No group papers have been entered yet.'];
        return $issues;
    }

    $seenNames = [];
    foreach ($groups as $group) {
        $gid = (int) $group['id'];
        $gname = trim((string) ($group['name'] ?? ''));
        $culture = trim((string) ($group['culture_name'] ?? ''));
        if ($gname === '') {
            $issues[] = ['level' => 'error', 'code' => 'group_name', 'message' => 'A group is missing a name or number.', 'group_id' => $gid];
        } else {
            $key = culture_day_name_key($gname);
            if (isset($seenNames[$key])) {
                $issues[] = [
                    'level' => 'warn',
                    'code' => 'duplicate_group_name',
                    'message' => 'Group name "' . $gname . '" is used more than once.',
                    'group_id' => $gid,
                ];
            }
            $seenNames[$key] = true;
        }
        if ($culture === '') {
            $issues[] = ['level' => 'error', 'code' => 'culture', 'message' => 'Group "' . ($gname ?: '#' . $gid) . '" has no culture.', 'group_id' => $gid];
        }
        $members = culture_day_members($gid);
        if ($members === []) {
            $issues[] = [
                'level' => 'error',
                'code' => 'empty_group',
                'message' => 'Group "' . ($gname ?: '#' . $gid) . '" has no students.',
                'group_id' => $gid,
            ];
        }
        $inGroup = [];
        foreach ($members as $member) {
            $sid = (int) $member['student_id'];
            if (isset($inGroup[$sid])) {
                $issues[] = [
                    'level' => 'error',
                    'code' => 'duplicate_in_group',
                    'message' => ($member['student_name'] ?? 'A student') . ' appears twice in group "' . $gname . '".',
                    'group_id' => $gid,
                    'student_id' => $sid,
                ];
            }
            $inGroup[$sid] = true;
        }
    }

    $all = culture_day_event_members($eventId);
    $byStudent = [];
    foreach ($all as $row) {
        $sid = (int) $row['student_id'];
        $byStudent[$sid][] = $row;
    }
    foreach ($byStudent as $sid => $rows) {
        if (count($rows) < 2) {
            continue;
        }
        $groupsList = array_unique(array_map(static fn(array $r): string => (string) $r['group_name'], $rows));
        $issues[] = [
            'level' => 'error',
            'code' => 'multi_group',
            'message' => ($rows[0]['student_name'] ?? 'A student') . ' is listed in multiple groups: ' . implode(', ', $groupsList) . '.',
            'student_id' => $sid,
            'groups' => $groupsList,
        ];
    }

    $unknownGender = 0;
    foreach ($all as $row) {
        if (student_gender_of($row) !== 'male' && student_gender_of($row) !== 'female') {
            $unknownGender++;
        }
    }
    if ($unknownGender > 0) {
        $issues[] = [
            'level' => 'warn',
            'code' => 'gender_missing',
            'message' => $unknownGender . ' student' . ($unknownGender === 1 ? ' has' : 's have') . ' missing or unverified gender. They will appear in a separate list.',
        ];
    }

    return $issues;
}

function culture_day_graph_data(int $eventId): array
{
    $event = culture_day_event_by_id($eventId);
    if (!$event) {
        return [];
    }
    $cultures = culture_day_cultures($eventId);
    $groups = culture_day_groups($eventId);
    $members = culture_day_event_members($eventId);

    $groupsByCulture = [];
    foreach ($groups as $group) {
        $groupsByCulture[(int) $group['culture_id']][] = $group;
    }
    $membersByGroup = [];
    foreach ($members as $member) {
        $membersByGroup[(int) $member['group_id']][] = $member;
    }

    $nodes = [];
    $uniqueAll = [];
    foreach ($cultures as $culture) {
        $cid = (int) $culture['id'];
        $cultureGroups = $groupsByCulture[$cid] ?? [];
        $uniqueStudents = [];
        $groupNodes = [];
        foreach ($cultureGroups as $group) {
            $gid = (int) $group['id'];
            $groupMembers = $membersByGroup[$gid] ?? [];
            foreach ($groupMembers as $m) {
                $uniqueStudents[(int) $m['student_id']] = true;
                $uniqueAll[(int) $m['student_id']] = true;
            }
            $groupNodes[] = [
                'id' => $gid,
                'name' => (string) $group['name'],
                'notes' => (string) ($group['notes'] ?? ''),
                'member_count' => count($groupMembers),
                'members' => array_map(static function (array $m): array {
                    return [
                        'id' => (int) $m['student_id'],
                        'name' => (string) $m['student_name'],
                        'code' => (string) ($m['student_code'] ?? ''),
                        'section' => (string) ($m['section'] ?? ''),
                        'gender' => student_gender_of($m),
                    ];
                }, $groupMembers),
            ];
        }
        $nodes[] = [
            'id' => $cid,
            'name' => (string) $culture['name'],
            'group_count' => count($cultureGroups),
            'student_count' => count($uniqueStudents),
            'groups' => $groupNodes,
        ];
    }

    return [
        'event' => [
            'id' => (int) $event['id'],
            'title' => (string) $event['title'],
            'date' => (string) ($event['event_date'] ?? ''),
            'status' => (string) ($event['status'] ?? 'draft'),
        ],
        'culture_count' => count($nodes),
        'group_count' => count($groups),
        'student_count' => count($uniqueAll),
        'cultures' => $nodes,
    ];
}

function culture_day_gender_lists(int $eventId): array
{
    $graph = culture_day_graph_data($eventId);
    $lists = [];
    foreach ($graph['cultures'] as $culture) {
        $male = [];
        $female = [];
        $unknown = [];
        foreach ($culture['groups'] as $group) {
            foreach ($group['members'] as $member) {
                $entry = $member + ['group_name' => $group['name'], 'group_id' => $group['id']];
                $g = $member['gender'];
                if ($g === 'male') {
                    $male[] = $entry;
                } elseif ($g === 'female') {
                    $female[] = $entry;
                } else {
                    $unknown[] = $entry;
                }
            }
        }
        $lists[] = [
            'id' => $culture['id'],
            'name' => $culture['name'],
            'group_count' => $culture['group_count'],
            'student_count' => $culture['student_count'],
            'groups' => array_map(static fn(array $g): array => ['id' => $g['id'], 'name' => $g['name']], $culture['groups']),
            'male' => $male,
            'female' => $female,
            'unknown' => $unknown,
            'male_count' => count($male),
            'female_count' => count($female),
            'unknown_count' => count($unknown),
        ];
    }
    return $lists;
}

function culture_day_csv_safe(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }
    return $value;
}

function export_culture_day_csv(int $eventId): void
{
    $event = culture_day_event_by_id($eventId);
    if (!$event) {
        throw new InvalidArgumentException('Event not found.');
    }
    $members = culture_day_event_members($eventId);
    $filename = 'culture-day-' . $eventId . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('Could not open export stream.');
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Event', 'Date', 'Status', 'Culture', 'Group', 'Student name', 'Student ID', 'Section', 'Gender']);
    foreach ($members as $row) {
        fputcsv($out, [
            culture_day_csv_safe((string) $event['title']),
            culture_day_csv_safe((string) ($event['event_date'] ?? '')),
            culture_day_csv_safe(culture_day_status_label((string) ($event['status'] ?? 'draft'))),
            culture_day_csv_safe((string) $row['culture_name']),
            culture_day_csv_safe((string) $row['group_name']),
            culture_day_csv_safe((string) $row['student_name']),
            culture_day_csv_safe((string) ($row['student_code'] ?? '')),
            culture_day_csv_safe((string) ($row['section'] ?? '')),
            culture_day_csv_safe(student_gender_label(student_gender_of($row))),
        ]);
    }
    fclose($out);
    log_audit('culture_day.export', 'culture_day_event', $eventId, $event['title'] ?? '');
    exit;
}
