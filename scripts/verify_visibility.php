<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/queries.php';

$fail = 0;
$report = [];

$check = static function (string $label, bool $ok) use (&$fail, &$report): void {
    $report[] = ($ok ? 'OK  ' : 'FAIL') . ' ' . $label;
    if (!$ok) {
        $fail++;
    }
};

foreach (['events', 'announcements', 'gallery', 'achievements', 'timeline_milestones', 'memories', 'spotlights', 'class_messages', 'committee_members'] as $table) {
    $check($table . ' has visibility', column_exists(db(), $table, 'visibility'));
}

$privateEvent = db()->query("SELECT id, title, visibility FROM events WHERE visibility = 'private' LIMIT 1")->fetch();
if ($privateEvent) {
    $id = (int) $privateEvent['id'];
    $check('private event hidden from public find', find_published_event($id) === null);
    $check('private event visible to class find', find_class_event($id) !== null);
} else {
    $report[] = 'SKIP no private event in database to probe';
}

$publicEvent = db()->query("SELECT id FROM events WHERE visibility = 'public' LIMIT 1")->fetch();
if ($publicEvent) {
    $id = (int) $publicEvent['id'];
    $check('public event visible on public find', find_published_event($id) !== null);
    $check('public event visible to class find', find_class_event($id) !== null);
} else {
    $report[] = 'SKIP no public event in database to probe';
}

$draftEvent = db()->query("SELECT id FROM events WHERE visibility = 'draft' LIMIT 1")->fetch();
if ($draftEvent) {
    $id = (int) $draftEvent['id'];
    $check('draft event hidden from public', find_published_event($id) === null);
    $check('draft event hidden from class', find_class_event($id) === null);
}

foreach (published_events() as $row) {
    $check('published_events row is public #' . $row['id'], content_visibility_of($row) === 'public');
}
foreach (class_events() as $row) {
    $vis = content_visibility_of($row);
    $check('class_events row is class-visible #' . $row['id'], in_array($vis, ['private', 'public'], true));
}

$check('graduation public default is off', !setting_bool('graduation_public'));
$check('posted_visibility defaults private', posted_visibility() === 'private');

echo implode(PHP_EOL, $report) . PHP_EOL;
echo ($fail === 0 ? 'PASS' : 'FAILED ' . $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
