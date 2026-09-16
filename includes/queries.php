<?php

declare(strict_types=1);

function published_events(?int $limit = null): array
{
    $sql = "SELECT * FROM events WHERE published = 1 ORDER BY (event_date IS NULL), event_date ASC, id DESC";
    if ($limit) {
        return db()->query($sql . ' LIMIT ' . (int) $limit)->fetchAll();
    }
    return db()->query($sql)->fetchAll();
}

function find_published_event(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ? AND published = 1 LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function event_timing(array $event): string
{
    if (($event['status'] ?? '') === 'cancelled') {
        return 'cancelled';
    }
    $date = substr(trim((string) ($event['event_date'] ?? '')), 0, 10);
    if ($date === '') {
        return 'undated';
    }
    $today = date('Y-m-d');
    if (($event['status'] ?? '') === 'ongoing' || $date === $today) {
        return 'today';
    }
    return $date > $today ? 'upcoming' : 'past';
}

function event_days_away(?string $date): string
{
    $date = substr(trim((string) $date), 0, 10);
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date . ' 00:00:00');
    if ($ts === false) {
        return format_date($date);
    }
    $days = (int) round(($ts - strtotime('today')) / 86400);
    if ($days === 0) {
        return 'Today';
    }
    if ($days === 1) {
        return 'Tomorrow';
    }
    if ($days === -1) {
        return 'Yesterday';
    }
    if ($days > 1 && $days < 14) {
        return 'In ' . $days . ' days';
    }
    if ($days < -1 && $days > -14) {
        return abs($days) . ' days ago';
    }
    return format_date($date, 'D, M j');
}

function event_timing_label(array $event): string
{
    $t = event_timing($event);
    if ($t === 'cancelled') {
        return 'Cancelled';
    }
    if ($t === 'undated') {
        return 'Date to be announced';
    }
    if ($t === 'today') {
        return 'Today';
    }
    return event_days_away((string) ($event['event_date'] ?? '')) ?: (format_date($event['event_date'] ?? null) ?: '');
}

function event_hero_kicker(array $event): string
{
    return match (event_timing($event)) {
        'today' => 'Happening today',
        'upcoming' => 'Next up',
        'past' => 'Last event',
        'cancelled' => 'Cancelled',
        default => 'Class event',
    };
}

function event_meta_line(array $event, bool $includeTba = true): string
{
    $bits = [];
    $time = trim((string) ($event['event_time'] ?? ''));
    $loc = trim((string) ($event['location'] ?? ''));
    if ($time !== '') {
        $bits[] = $time;
    } elseif ($includeTba && !in_array(event_timing($event), ['past', 'cancelled'], true)) {
        $bits[] = 'Time to be announced';
    }
    if ($loc !== '') {
        $bits[] = $loc;
    }
    return implode(' · ', $bits);
}

function partition_student_events(array $items): array
{
    $upcoming = [];
    $past = [];
    $undated = [];
    foreach ($items as $item) {
        $t = event_timing($item);
        if ($t === 'undated') {
            $undated[] = $item;
        } elseif (in_array($t, ['upcoming', 'today'], true)) {
            $upcoming[] = $item;
        } else {
            $past[] = $item;
        }
    }
    usort($past, static function (array $a, array $b): int {
        return strcmp((string) ($b['event_date'] ?? ''), (string) ($a['event_date'] ?? ''));
    });
    $next = $upcoming[0] ?? null;
    return [
        'next' => $next,
        'upcoming' => $next ? array_slice($upcoming, 1) : [],
        'past' => $past,
        'undated' => $undated,
    ];
}

function published_announcements(?int $limit = null): array
{
    $sql = "SELECT * FROM announcements WHERE published = 1 ORDER BY (announced_on IS NULL), announced_on DESC, id DESC";
    if ($limit) {
        return db()->query($sql . ' LIMIT ' . (int) $limit)->fetchAll();
    }
    return db()->query($sql)->fetchAll();
}

function published_gallery(?string $category = null): array
{
    if ($category && $category !== 'all') {
        $stmt = db()->prepare('SELECT * FROM gallery WHERE published = 1 AND category = ? ORDER BY (taken_on IS NULL), taken_on DESC, id DESC');
        $stmt->execute([$category]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM gallery WHERE published = 1 ORDER BY (taken_on IS NULL), taken_on DESC, id DESC')->fetchAll();
}

function committee_list(): array
{
    return db()->query('SELECT * FROM committee_members ORDER BY display_order ASC, id ASC')->fetchAll();
}

function published_achievements(?int $limit = null): array
{
    $sql = "SELECT * FROM achievements WHERE published = 1 ORDER BY (achieved_on IS NULL), achieved_on DESC, id DESC";
    if ($limit) {
        return db()->query($sql . ' LIMIT ' . (int) $limit)->fetchAll();
    }
    return db()->query($sql)->fetchAll();
}

function gallery_categories(): array
{
    return ['all' => 'All', 'class' => 'Class', 'events' => 'Events', 'sports' => 'Sports', 'academic' => 'Academic', 'memories' => 'Memories', 'graduation' => 'Graduation'];
}

function achievement_categories(): array
{
    return [
        'academic' => 'Academic',
        'competition' => 'Competition',
        'sports' => 'Sports',
        'community' => 'Community',
        'class' => 'Class milestone',
    ];
}

function event_categories(): array
{
    return [
        'academic' => 'Academic',
        'class' => 'Class',
        'sports' => 'Sports',
        'social' => 'Social',
        'graduation' => 'Graduation',
        'important' => 'Important',
    ];
}

function timeline_stages(): array
{
    return [
        'start' => 'Start of Grade 12',
        'semester' => 'Semester',
        'event' => 'Major event',
        'academic' => 'Academic milestone',
        'exams' => 'Exams',
        'graduation' => 'Graduation',
        'next' => 'The next chapter',
        'milestone' => 'Milestone',
    ];
}

function spotlight_categories(): array
{
    return [
        'student_of_month' => 'Student of the Month',
        'academic' => 'Academic Excellence',
        'leadership' => 'Leadership',
        'sports' => 'Sports',
        'creative' => 'Creative Achievement',
        'community' => 'Community',
    ];
}

function message_roles(): array
{
    return [
        'president' => 'Class President',
        'committee' => 'Committee',
        'teacher' => 'Teacher',
        'graduation' => 'Graduation committee',
        'class' => 'From the class',
    ];
}

function published_milestones(): array
{
    if (!table_exists(db(), 'timeline_milestones')) {
        return [];
    }
    return db()->query(
        'SELECT * FROM timeline_milestones WHERE published = 1 ORDER BY display_order ASC, (occurred_on IS NULL), occurred_on ASC, id ASC'
    )->fetchAll();
}

function published_memories(?int $limit = null): array
{
    if (!table_exists(db(), 'memories')) {
        return [];
    }
    $sql = 'SELECT * FROM memories WHERE published = 1 ORDER BY (memory_on IS NULL), memory_on DESC, id DESC';
    if ($limit) {
        return db()->query($sql . ' LIMIT ' . (int) $limit)->fetchAll();
    }
    return db()->query($sql)->fetchAll();
}

function published_spotlights(?int $limit = null): array
{
    if (!table_exists(db(), 'spotlights')) {
        return [];
    }
    $sql = 'SELECT * FROM spotlights WHERE published = 1 ORDER BY (featured_on IS NULL), featured_on DESC, id DESC';
    if ($limit) {
        return db()->query($sql . ' LIMIT ' . (int) $limit)->fetchAll();
    }
    return db()->query($sql)->fetchAll();
}

function published_class_messages(?int $limit = null): array
{
    if (!table_exists(db(), 'class_messages')) {
        return [];
    }
    $sql = 'SELECT * FROM class_messages WHERE published = 1 ORDER BY display_order ASC, id DESC';
    if ($limit) {
        return db()->query($sql . ' LIMIT ' . (int) $limit)->fetchAll();
    }
    return db()->query($sql)->fetchAll();
}

function parse_year_month(?string $value): DateTimeImmutable
{
    $value = $value ?: date('Y-m');
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $value);
    if (!$dt || $dt->format('Y-m') !== $value) {
        return new DateTimeImmutable('first day of this month');
    }
    return $dt;
}

function default_calendar_month(): DateTimeImmutable
{
    $next = next_published_event();
    if ($next && !empty($next['event_date'])) {
        $stamp = substr((string) $next['event_date'], 0, 7);
        $dt = DateTimeImmutable::createFromFormat('!Y-m', $stamp);
        if ($dt && $dt->format('Y-m') === $stamp) {
            return $dt;
        }
    }
    return new DateTimeImmutable('first day of this month');
}

function event_day_number(string $eventDate): int
{
    if (preg_match('/^\d{4}-\d{2}-(\d{2})/', $eventDate, $match)) {
        return (int) $match[1];
    }
    $ts = strtotime($eventDate);
    return $ts ? (int) date('j', $ts) : 0;
}

function calendar_month_events(DateTimeImmutable $month, ?string $category = null): array
{
    $empty = ['rows' => [], 'by_day' => []];
    if (!table_exists(db(), 'events')) {
        return $empty;
    }
    $start = $month->format('Y-m-01');
    $end = $month->modify('first day of next month')->format('Y-m-d');
    $sql = 'SELECT * FROM events WHERE published = 1 AND event_date IS NOT NULL AND event_date >= ? AND event_date < ?';
    $params = [$start, $end];
    if ($category && $category !== 'all' && array_key_exists($category, event_categories()) && column_exists(db(), 'events', 'category')) {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    $sql .= ' ORDER BY event_date ASC, id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $byDay = [];
    foreach ($rows as $row) {
        $day = event_day_number((string) ($row['event_date'] ?? ''));
        if ($day < 1) {
            continue;
        }
        $byDay[$day][] = $row;
    }
    return ['rows' => $rows, 'by_day' => $byDay];
}

function published_events_by_category(string $category, ?int $limit = null): array
{
    if (!table_exists(db(), 'events') || !column_exists(db(), 'events', 'category') || !array_key_exists($category, event_categories())) {
        return [];
    }
    $sql = 'SELECT * FROM events WHERE published = 1 AND category = ? ORDER BY (event_date IS NULL), event_date ASC, id DESC';
    if ($limit) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$category]);
    return $stmt->fetchAll();
}

function fetch_students(array $filters = []): array
{
    $sql = 'SELECT * FROM uniforms WHERE 1=1';
    $params = [];
    if (!empty($filters['search'])) {
        $term = '%' . $filters['search'] . '%';
        $clauses = ['student_name LIKE ?', 'phone_number LIKE ?', 'student_code LIKE ?'];
        array_push($params, $term, $term, $term);
        foreach (['mother_name', 'mother_phone', 'father_name', 'father_phone'] as $col) {
            if (column_exists(db(), 'uniforms', $col)) {
                $clauses[] = $col . ' LIKE ?';
                $params[] = $term;
            }
        }
        $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
    }
    if (!empty($filters['size'])) {
        $sql .= ' AND size = ?';
        $params[] = $filters['size'];
    }
    if (!empty($filters['section'])) {
        $sql .= ' AND section = ?';
        $params[] = $filters['section'];
    }
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'missing') {
            $sql .= " AND (size IS NULL OR size = '')";
        } else {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        }
    }
    $sort = $filters['sort'] ?? 'name';
    $map = [
        'name' => 'student_name ASC',
        'newest' => 'created_at DESC',
        'size' => 'size ASC, student_name ASC',
    ];
    $sql .= ' ORDER BY ' . ($map[$sort] ?? $map['name']);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function student_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM uniforms WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dashboard_counts(): array
{
    $pdo = db();
    $empty = [
        'students' => 0,
        'collected' => 0,
        'pending' => 0,
        'events' => 0,
        'upcoming_events' => 0,
        'announcements' => 0,
        'achievements' => 0,
        'ordered' => 0,
        'ready' => 0,
        'missing' => 0,
        'sizes' => array_fill_keys(uniform_sizes(), 0),
        'recent_students' => [],
        'recent_announcements' => [],
        'upcoming' => [],
        'milestones' => 0,
        'memories' => 0,
        'spotlights' => 0,
        'messages' => 0,
    ];
    try {
        if (!table_exists($pdo, 'uniforms')) {
            return $empty;
        }
        $sizes = [];
        foreach (uniform_sizes() as $size) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM uniforms WHERE size = ?');
            $stmt->execute([$size]);
            $sizes[$size] = (int) $stmt->fetchColumn();
        }
        $data = $empty;
        $data['students'] = (int) $pdo->query('SELECT COUNT(*) FROM uniforms')->fetchColumn();
        $data['collected'] = column_exists($pdo, 'uniforms', 'status')
            ? (int) $pdo->query("SELECT COUNT(*) FROM uniforms WHERE status = 'collected'")->fetchColumn()
            : 0;
        $data['pending'] = column_exists($pdo, 'uniforms', 'status')
            ? (int) $pdo->query("SELECT COUNT(*) FROM uniforms WHERE status = 'pending'")->fetchColumn()
            : $data['students'];
        $data['ordered'] = column_exists($pdo, 'uniforms', 'status')
            ? (int) $pdo->query("SELECT COUNT(*) FROM uniforms WHERE status = 'ordered'")->fetchColumn()
            : 0;
        $data['ready'] = column_exists($pdo, 'uniforms', 'status')
            ? (int) $pdo->query("SELECT COUNT(*) FROM uniforms WHERE status = 'ready'")->fetchColumn()
            : 0;
        $data['missing'] = (int) $pdo->query("SELECT COUNT(*) FROM uniforms WHERE size IS NULL OR size = ''")->fetchColumn();
        $data['sizes'] = $sizes;
        $photoCol = column_exists($pdo, 'uniforms', 'photo') ? ', photo' : '';
        $data['recent_students'] = $pdo->query('SELECT id, student_name, student_code, phone_number, size, status' . $photoCol . ', created_at FROM uniforms ORDER BY student_name ASC LIMIT 8')->fetchAll();
        if (table_exists($pdo, 'events')) {
            $data['events'] = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
            $data['upcoming_events'] = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status IN ('upcoming','ongoing')")->fetchColumn();
            $data['upcoming'] = $pdo->query("SELECT * FROM events WHERE status IN ('upcoming','ongoing') ORDER BY (event_date IS NULL), event_date ASC LIMIT 5")->fetchAll();
        }
        if (table_exists($pdo, 'announcements')) {
            $data['announcements'] = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
            $data['recent_announcements'] = $pdo->query('SELECT * FROM announcements ORDER BY id DESC LIMIT 5')->fetchAll();
        }
        if (table_exists($pdo, 'achievements')) {
            $data['achievements'] = (int) $pdo->query('SELECT COUNT(*) FROM achievements')->fetchColumn();
        }
        if (table_exists($pdo, 'timeline_milestones')) {
            $data['milestones'] = (int) $pdo->query('SELECT COUNT(*) FROM timeline_milestones')->fetchColumn();
        }
        if (table_exists($pdo, 'memories')) {
            $data['memories'] = (int) $pdo->query('SELECT COUNT(*) FROM memories')->fetchColumn();
        }
        if (table_exists($pdo, 'spotlights')) {
            $data['spotlights'] = (int) $pdo->query('SELECT COUNT(*) FROM spotlights')->fetchColumn();
        }
        if (table_exists($pdo, 'class_messages')) {
            $data['messages'] = (int) $pdo->query('SELECT COUNT(*) FROM class_messages')->fetchColumn();
        }
        return $data;
    } catch (Throwable $e) {
        app_log('dashboard_counts: ' . $e->getMessage());
        return $empty;
    }
}

function next_published_event(): ?array
{
    if (!table_exists(db(), 'events')) {
        return null;
    }
    $row = db()->query(
        "SELECT * FROM events
         WHERE published = 1 AND event_date IS NOT NULL AND event_date >= CURDATE()
         ORDER BY event_date ASC, id ASC LIMIT 1"
    )->fetch();
    if ($row) {
        return $row;
    }
    $row = db()->query(
        "SELECT * FROM events WHERE published = 1 ORDER BY (event_date IS NULL), event_date DESC, id DESC LIMIT 1"
    )->fetch();
    return $row ?: null;
}

function unpublished_counts(): array
{
    $counts = ['events' => 0, 'announcements' => 0, 'gallery' => 0, 'uniforms_pending' => 0, 'profile_requests' => 0, 'payment_requests' => 0, 'question_pending' => 0, 'response_pending' => 0];
    try {
        if (table_exists(db(), 'events')) {
            $counts['events'] = (int) db()->query('SELECT COUNT(*) FROM events WHERE published = 0')->fetchColumn();
        }
        if (table_exists(db(), 'announcements')) {
            $counts['announcements'] = (int) db()->query('SELECT COUNT(*) FROM announcements WHERE published = 0')->fetchColumn();
        }
        if (table_exists(db(), 'gallery')) {
            $counts['gallery'] = (int) db()->query('SELECT COUNT(*) FROM gallery WHERE published = 0')->fetchColumn();
        }
        if (table_exists(db(), 'uniforms') && column_exists(db(), 'uniforms', 'status')) {
            $counts['uniforms_pending'] = (int) db()->query("SELECT COUNT(*) FROM uniforms WHERE status = 'pending'")->fetchColumn();
        }
        if (table_exists(db(), 'profile_requests')) {
            $counts['profile_requests'] = (int) db()->query("SELECT COUNT(*) FROM profile_requests WHERE status = 'pending'")->fetchColumn();
        }
        if (table_exists(db(), 'payment_requests')) {
            $counts['payment_requests'] = (int) db()->query("SELECT COUNT(*) FROM payment_requests WHERE status = 'pending'")->fetchColumn();
        }
        if (table_exists(db(), 'questions')) {
            $counts['question_pending'] = (int) db()->query("SELECT COUNT(*) FROM questions WHERE status = 'pending'")->fetchColumn();
        }
        if (table_exists(db(), 'question_responses')) {
            $counts['response_pending'] = (int) db()->query("SELECT COUNT(*) FROM question_responses WHERE status = 'pending'")->fetchColumn();
        }
    } catch (Throwable $e) {
        app_log('unpublished_counts: ' . $e->getMessage());
    }
    return $counts;
}

function this_year_timeline(): array
{
    $buckets = [];
    $add = static function (string $date, string $title, string $href) use (&$buckets): void {
        $ts = strtotime($date);
        if ($ts === false || $title === '') {
            return;
        }
        $key = date('Y-m', $ts);
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'key' => $key,
                'month' => date('F', $ts),
                'year' => date('Y', $ts),
                'items' => [],
            ];
        }
        $buckets[$key]['items'][] = [
            'title' => $title,
            'href' => $href,
            'date' => $date,
        ];
    };

    if (table_exists(db(), 'events')) {
        foreach (published_events() as $event) {
            if (!empty($event['event_date'])) {
                $add((string) $event['event_date'], (string) $event['title'], 'event.php?id=' . (int) $event['id']);
            }
        }
    }
    foreach (published_milestones() as $milestone) {
        if (!empty($milestone['occurred_on'])) {
            $add((string) $milestone['occurred_on'], (string) $milestone['title'], 'journey.php');
        }
    }
    $gradDate = setting('graduation_date');
    if ($gradDate) {
        $add($gradDate, setting('graduation_title', 'Graduation'), 'graduation.php');
    }
    ksort($buckets);
    return array_values($buckets);
}
