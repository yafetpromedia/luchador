<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['culture_day.view', 'culture_day.manage', 'culture_day.publish']);

// Self-heal if Culture Day was deployed before migrate_culture_day was wired into ensure_schema.
if (!table_exists(db(), 'culture_day_events')) {
    try {
        migrate_culture_day(db());
    } catch (Throwable $e) {
        app_log('culture-day boot migrate: ' . $e->getMessage());
    }
}

$eventId = request_int('event') ?: request_int('id');
$tab = request_str('tab', 'overview');
$allowedTabs = ['overview', 'groups', 'graph', 'lists', 'print', 'audit'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}
$editEvent = request_str('edit') === 'event' || request_str('new') === '1';
$groupId = request_int('group');
$editGroup = $groupId > 0 || request_str('edit') === 'group';
$q = trim(request_str('q'));

if (request_str('export') === 'csv' && $eventId > 0) {
    require_permission_any(['culture_day.manage', 'culture_day.publish', 'culture_day.view']);
    try {
        export_culture_day_csv($eventId);
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('admin/culture-day.php?event=' . $eventId);
    }
}

if (is_post()) {
    require_csrf();
    try {
        $action = posted('action');
        if ($action === 'save_event') {
            require_permission('culture_day.manage');
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $saved = save_culture_day_event($_POST, $id);
            flash_set('success', $id ? 'Event updated.' : 'Event created as draft. Students cannot see it until you publish.');
            redirect('admin/culture-day.php?event=' . (int) $saved['id']);
        }
        if ($action === 'set_status') {
            require_permission('culture_day.publish');
            $id = request_int('id') ?: (int) posted('id');
            $status = posted('status');
            $saved = set_culture_day_status($id, $status);
            flash_set('success', 'Status set to ' . culture_day_status_label((string) $saved['status']) . '.');
            redirect('admin/culture-day.php?event=' . (int) $saved['id'] . '&tab=overview');
        }
        if ($action === 'save_group') {
            require_permission('culture_day.manage');
            $eid = (int) posted('event_id');
            $gid = posted('group_id') !== '' ? (int) posted('group_id') : null;
            $payload = $_POST;
            $payload['male_ids'] = $_POST['male_ids'] ?? [];
            $payload['female_ids'] = $_POST['female_ids'] ?? [];
            $payload['member_ids'] = $_POST['member_ids'] ?? [];
            $saved = save_culture_day_group($eid, $payload, $gid);
            flash_set('success', 'Group paper saved. Male and female assignments were recorded.');
            redirect('admin/culture-day.php?event=' . $eid . '&tab=groups&group=' . (int) $saved['id'] . '&edit=group');
        }
        if ($action === 'delete_group') {
            require_permission('culture_day.manage');
            $gid = request_int('group_id') ?: (int) posted('group_id');
            $group = culture_day_group_by_id($gid);
            $eid = (int) ($group['event_id'] ?? 0);
            delete_culture_day_group($gid);
            flash_set('success', 'Group removed.');
            redirect('admin/culture-day.php?event=' . $eid . '&tab=groups');
        }
        if ($action === 'assign_gender') {
            require_permission('culture_day.manage');
            $eid = (int) posted('event_id');
            $gender = posted('gender');
            $ids = $_POST['student_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $count = assign_students_gender($ids, $gender);
            log_audit('culture_day.gender_assign', 'culture_day_event', $eid, student_gender_label($gender) . ' · ' . $count);
            flash_set('success', $count . ' student' . ($count === 1 ? '' : 's') . ' marked as ' . student_gender_label($gender) . '.');
            redirect('admin/culture-day.php?event=' . $eid . '&tab=lists');
        }
        throw new InvalidArgumentException('Unknown action.');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log('culture-day: ' . $e->getMessage());
        $msg = 'Unable to save Culture Day changes.';
        $detail = strtolower($e->getMessage());
        if (str_contains($detail, "doesn't exist") || str_contains($detail, 'does not exist') || str_contains($detail, 'unknown table') || str_contains($detail, 'no such table')) {
            try {
                migrate_culture_day(db());
                $msg = 'Culture Day database tables were missing and have been created. Please save again.';
            } catch (Throwable $migrateError) {
                app_log('culture-day migrate: ' . $migrateError->getMessage());
                $msg = 'Culture Day database tables are missing. Refresh the page once, then try saving again.';
            }
        }
        flash_set('error', $msg);
    }
    $back = $eventId > 0 ? 'admin/culture-day.php?event=' . $eventId . '&tab=' . rawurlencode($tab) : 'admin/culture-day.php';
    redirect($back);
}

$event = $eventId > 0 ? culture_day_event_by_id($eventId) : null;
if ($eventId > 0 && !$event) {
    flash_set('error', 'Culture Day event not found.');
    redirect('admin/culture-day.php');
}

$events = culture_day_events();
$isPrint = $tab === 'print' && $event;
$bodyClass = $isPrint ? 'culture-print' : '';
admin_header($event ? (string) $event['title'] : 'Culture Day', 'culture-day', $bodyClass);

if (!$event && !$editEvent):
    admin_page_head(
        'Enter group papers, review the relationship graph, then publish when the class should see it.',
        can_manage_culture_day()
            ? ['<a class="btn" href="' . e(url('admin/culture-day.php?new=1&edit=event')) . '">New Culture Day</a>']
            : []
    );
    if (!$events):
        admin_empty('No Culture Day events yet.', 'Create an event to start recording group papers. New events stay draft until you publish.');
    else:
?>
<div class="table-wrap">
<table>
    <thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Groups</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($events as $row): ?>
        <?php
        $gidCount = 0;
        try {
            $gidCount = count(culture_day_groups((int) $row['id']));
        } catch (Throwable $e) {
            $gidCount = 0;
        }
        ?>
        <tr>
            <td><strong><?= e($row['title']) ?></strong></td>
            <td><?= e(format_date($row['event_date'] ?? null) ?: '—') ?></td>
            <td><span class="badge badge-<?= e($row['status'] === 'published' ? 'collected' : ($row['status'] === 'archived' ? 'cancelled' : 'pending')) ?>"><?= e(culture_day_status_label((string) $row['status'])) ?></span></td>
            <td><?= (int) $gidCount ?></td>
            <td class="row-actions">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $row['id'])) ?>">Open</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php
    endif;
    admin_footer();
    return;
endif;

if ($editEvent && can_manage_culture_day()):
    $form = $event ?: ['title' => '', 'description' => '', 'event_date' => ''];
    admin_page_head($event ? 'Update Culture Day details.' : 'Create a Culture Day event. It stays private until you publish.');
?>
<div class="panel">
    <h2><?= $event ? 'Edit event' : 'New Culture Day' ?></h2>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_event">
        <input type="hidden" name="id" value="<?= e((string) ($event['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group full"><label class="req">Title</label><input name="title" required value="<?= e((string) ($form['title'] ?? '')) ?>"></div>
            <div class="form-group"><label>Date</label><input type="date" name="event_date" value="<?= e((string) ($form['event_date'] ?? '')) ?>"></div>
            <div class="form-group full"><label>Description</label><textarea name="description" rows="4"><?= e((string) ($form['description'] ?? '')) ?></textarea></div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save event</button>
            <a class="btn btn-ghost" href="<?= e(url($event ? 'admin/culture-day.php?event=' . (int) $event['id'] : 'admin/culture-day.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php
    admin_footer();
    return;
endif;

$issues = culture_day_validation_issues((int) $event['id']);
$errors = array_values(array_filter($issues, static fn(array $i): bool => ($i['level'] ?? '') === 'error'));
$warns = array_values(array_filter($issues, static fn(array $i): bool => ($i['level'] ?? '') === 'warn'));
$groups = culture_day_groups((int) $event['id']);
$graph = culture_day_graph_data((int) $event['id']);
$lists = culture_day_gender_lists((int) $event['id']);
$status = (string) ($event['status'] ?? 'draft');

if (!$isPrint) {
    admin_page_head(
        culture_day_status_label($status) . (!empty($event['event_date']) ? ' · ' . format_date($event['event_date']) : ''),
        array_filter([
            can_manage_culture_day() ? '<a class="btn btn-ghost" href="' . e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&edit=event')) . '">Edit details</a>' : '',
            '<a class="btn btn-ghost" href="' . e(url('admin/culture-day.php')) . '">All events</a>',
        ])
    );
}
?>

<?php if (!$isPrint): ?>
<nav class="filters culture-tabs" aria-label="Culture Day sections">
    <?php foreach (['overview' => 'Overview', 'groups' => 'Groups', 'graph' => 'Graph', 'lists' => 'Male / Female', 'print' => 'Print', 'audit' => 'Activity'] as $key => $label): ?>
        <a class="chip<?= $tab === $key ? ' is-active' : '' ?>" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&tab=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
<div class="pay-overview culture-overview">
    <div class="panel"><p class="eyebrow">Status</p><strong><?= e(culture_day_status_label($status)) ?></strong></div>
    <div class="panel"><p class="eyebrow">Cultures</p><strong><?= (int) ($graph['culture_count'] ?? 0) ?></strong></div>
    <div class="panel"><p class="eyebrow">Groups</p><strong><?= (int) ($graph['group_count'] ?? 0) ?></strong></div>
    <div class="panel"><p class="eyebrow">Students</p><strong><?= (int) ($graph['student_count'] ?? 0) ?></strong></div>
</div>

<?php if ($errors || $warns): ?>
<div class="panel">
    <h2>Validation</h2>
    <?php if ($errors): ?>
        <ul class="culture-issues culture-issues-error">
            <?php foreach ($errors as $issue): ?>
                <li><?= e($issue['message']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($warns): ?>
        <ul class="culture-issues culture-issues-warn">
            <?php foreach ($warns as $issue): ?>
                <li><?= e($issue['message']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p class="muted">Errors must be resolved before publishing. Warnings can stay, but review them.</p>
</div>
<?php else: ?>
<div class="panel"><p class="muted">No blocking validation issues.</p></div>
<?php endif; ?>

<div class="panel">
    <h2>Publication</h2>
    <p class="muted">Students only see this Culture Day while status is Published. Draft and Archived are private.</p>
    <?php if (can_publish_culture_day()): ?>
    <div class="row-actions" style="margin-top:0.75rem">
        <?php if ($status !== 'published'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="status" value="published"><button class="btn" type="submit"<?= $errors ? ' disabled' : '' ?>>Publish to class</button></form>
        <?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="status" value="draft"><button class="btn btn-ghost" type="submit">Unpublish</button></form>
        <?php endif; ?>
        <?php if ($status !== 'archived'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="status" value="archived"><button class="btn btn-ghost" type="submit" data-confirm="Archiving keeps history but hides the event from students." data-confirm-title="Archive event?">Archive</button></form>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&export=csv')) ?>">Export CSV</a>
        <a class="btn btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&tab=print')) ?>">Print view</a>
    </div>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Preview</h2>
    <p class="eyebrow"><?= e(format_date($event['event_date'] ?? null) ?: 'Date not set') ?></p>
    <p><?= nl2br(e((string) ($event['description'] ?? 'No description.'))) ?></p>
</div>
<?php endif; ?>

<?php if ($tab === 'groups'): ?>
<?php
$group = $groupId > 0 ? culture_day_group_by_id($groupId) : null;
if ($group && (int) $group['event_id'] !== (int) $event['id']) {
    $group = null;
}
$currentMembers = $group ? culture_day_members((int) $group['id']) : [];
$selectedIds = [];
$maleMembers = [];
$femaleMembers = [];
$unknownMembers = [];
foreach ($currentMembers as $m) {
    $sid = (int) $m['student_id'];
    $selectedIds[$sid] = true;
    $g = student_gender_of($m);
    if ($g === 'male') {
        $maleMembers[] = $m;
    } elseif ($g === 'female') {
        $femaleMembers[] = $m;
    } else {
        $unknownMembers[] = $m;
    }
}
$busyMap = culture_day_student_group_map((int) $event['id'], $group ? (int) $group['id'] : null);
$rosterPayload = [];
foreach (fetch_students(['sort' => 'name']) as $stu) {
    $sid = (int) $stu['id'];
    $busy = $busyMap[$sid] ?? null;
    $rosterPayload[] = [
        'id' => $sid,
        'name' => (string) ($stu['student_name'] ?? ''),
        'code' => (string) ($stu['student_code'] ?? ''),
        'section' => !empty($stu['section']) ? class_section_label((string) $stu['section']) : '',
        'busyGroup' => $busy ? (string) $busy['group_name'] : '',
    ];
}
$initialSelected = [
    'male' => array_map(static fn(array $m): array => [
        'id' => (int) $m['student_id'],
        'name' => (string) $m['student_name'],
        'code' => (string) ($m['student_code'] ?? ''),
    ], $maleMembers),
    'female' => array_map(static fn(array $m): array => [
        'id' => (int) $m['student_id'],
        'name' => (string) $m['student_name'],
        'code' => (string) ($m['student_code'] ?? ''),
    ], $femaleMembers),
    'pending' => array_map(static fn(array $m): array => [
        'id' => (int) $m['student_id'],
        'name' => (string) $m['student_name'],
        'code' => (string) ($m['student_code'] ?? ''),
    ], $unknownMembers),
];
?>
<?php if (can_manage_culture_day()): ?>
<div class="panel">
    <h2><?= $group ? 'Edit group paper' : 'Enter group paper' ?></h2>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_group">
        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
        <input type="hidden" name="group_id" value="<?= e((string) ($group['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group"><label class="req">Group name or number</label><input name="name" required value="<?= e((string) ($group['name'] ?? '')) ?>" placeholder="Group 1"></div>
            <div class="form-group"><label class="req">Culture</label><input name="culture" required value="<?= e((string) ($group['culture_name'] ?? '')) ?>" placeholder="Oromo" list="culture-suggestions"></div>
            <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2"><?= e((string) ($group['notes'] ?? '')) ?></textarea></div>
        </div>
        <datalist id="culture-suggestions">
            <?php foreach (culture_day_cultures((int) $event['id']) as $c): ?>
                <option value="<?= e($c['name']) ?>">
            <?php endforeach; ?>
        </datalist>

        <div
            class="culture-roster-block"
            data-culture-group-picker
            data-roster="<?= e(json_encode($rosterPayload, JSON_UNESCAPED_UNICODE)) ?>"
            data-selected="<?= e(json_encode($initialSelected, JSON_UNESCAPED_UNICODE)) ?>"
        >
            <p class="section-label">Students by gender</p>
            <p class="muted">Search without reloading — your group name, culture, notes, and checked students stay. Each student can only be in one group for this event.</p>
            <div class="culture-roster-search">
                <input type="search" data-culture-live-search placeholder="Search name or student ID" autocomplete="off">
                <button class="btn" type="button" data-culture-live-search-btn>Search</button>
            </div>
            <div class="culture-lane-switch">
                <button type="button" class="chip is-active" data-culture-lane="male">Add to Male</button>
                <button type="button" class="chip" data-culture-lane="female">Add to Female</button>
            </div>

            <div class="culture-gender-unknown" data-culture-pending-wrap hidden>
                <h3>Gender not set in this group</h3>
                <p class="muted">Choose Male or Female — no page reload.</p>
                <div class="culture-unknown-grid" data-culture-pending></div>
            </div>

            <div class="culture-gender-board culture-group-lanes">
                <?php foreach (['male' => 'Male', 'female' => 'Female'] as $genderKey => $genderTitle): ?>
                <section class="culture-gender-lane culture-gender-lane-<?= e($genderKey) ?>" data-culture-lane-panel="<?= e($genderKey) ?>">
                    <header>
                        <h3><?= e($genderTitle) ?></h3>
                        <span class="muted" data-culture-count>0 selected</span>
                    </header>
                    <div class="culture-student-pick" data-culture-selected></div>
                    <p class="muted" data-culture-empty>No <?= e(strtolower($genderTitle)) ?> students in this group yet.</p>
                    <p class="section-label" data-culture-results-label hidden>Search results — check to add</p>
                    <div class="culture-student-pick" data-culture-results></div>
                    <p class="muted" data-culture-results-empty hidden>No students matched.</p>
                </section>
                <?php endforeach; ?>
            </div>
            <div data-culture-hidden-inputs></div>
        </div>

        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save group</button>
            <?php if ($group): ?><a class="btn btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&tab=groups')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <h2>Groups</h2>
    <?php if (!$groups): ?>
        <p class="muted">No group papers yet.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Group</th><th>Culture</th><th>Members</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $row): ?>
            <tr>
                <td><strong><?= e($row['name']) ?></strong></td>
                <td><?= e($row['culture_name']) ?></td>
                <td><?= (int) ($row['member_count'] ?? 0) ?></td>
                <td><?= e(format_when((string) ($row['updated_at'] ?? $row['created_at'] ?? '')) ?: '—') ?></td>
                <td class="row-actions">
                    <?php if (can_manage_culture_day()): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&tab=groups&group=' . (int) $row['id'] . '&edit=group')) ?>">Edit</a>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_group"><input type="hidden" name="group_id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" type="submit" data-confirm="Remove this group and its memberships?" data-confirm-title="Delete group?">Delete</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'graph' || $tab === 'print' || $tab === 'lists'): ?>
<?php
$searchNeedle = strtolower($q);
$filterMatch = static function (array $culture) use ($searchNeedle): bool {
    if ($searchNeedle === '') {
        return true;
    }
    if (str_contains(strtolower($culture['name']), $searchNeedle)) {
        return true;
    }
    foreach ($culture['groups'] as $group) {
        if (str_contains(strtolower($group['name']), $searchNeedle)) {
            return true;
        }
        foreach ($group['members'] as $member) {
            if (str_contains(strtolower($member['name']), $searchNeedle) || str_contains(strtolower($member['code']), $searchNeedle)) {
                return true;
            }
        }
    }
    return false;
};
?>
<?php if ($tab === 'graph'): ?>
<div class="panel culture-graph-panel">
    <?php if (($graph['cultures'] ?? []) === []): ?>
        <div class="empty-state">
            <h2>No groups yet</h2>
            <p>Enter group papers to build the Culture → Groups → Students graph.</p>
        </div>
    <?php else: ?>
        <div
            class="culture-network"
            data-culture-network
            data-graph="<?= e(json_encode($graph, JSON_UNESCAPED_UNICODE)) ?>"
        >
            <div class="culture-network-toolbar">
                <div class="culture-roster-search" style="flex:1;margin:0">
                    <input type="search" data-culture-network-search placeholder="Filter culture, group, name, or ID" autocomplete="off">
                </div>
                <button class="btn btn-ghost is-active" type="button" data-culture-toggle-students>Hide students</button>
                <button class="btn btn-ghost" type="button" data-culture-fit>Reset view</button>
                <button class="btn btn-ghost" type="button" data-culture-fullscreen aria-pressed="false">Full screen</button>
            </div>
            <div class="culture-network-shell">
                <div class="culture-network-canvas" data-culture-canvas></div>
                <aside class="culture-network-focus" data-culture-network-focus aria-live="polite"></aside>
                <aside class="culture-network-legend" aria-label="Graph legend">
                    <p><span class="culture-dot culture-dot-event"></span> Culture Day</p>
                    <p><span class="culture-dot culture-dot-culture"></span> Culture</p>
                    <p><span class="culture-dot culture-dot-group"></span> Group</p>
                    <p><span class="culture-dot culture-dot-male"></span> Male</p>
                    <p><span class="culture-dot culture-dot-female"></span> Female</p>
                </aside>
                <button class="culture-network-fs-exit btn btn-ghost" type="button" data-culture-fullscreen-exit hidden>Exit full screen · Esc</button>
            </div>
            <p class="muted culture-network-status" data-culture-network-status>Preparing 3D graph…</p>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'lists' || $tab === 'print'): ?>
<?php
$listSearch = $tab === 'lists' ? trim(request_str('q')) : '';
$listSearchRows = ($tab === 'lists' && $listSearch !== '') ? fetch_students(['search' => $listSearch, 'sort' => 'name']) : [];
if (count($listSearchRows) > 40) {
    $listSearchRows = array_slice($listSearchRows, 0, 40);
}
$eventMembers = culture_day_event_members((int) $event['id']);
$uniqueMembers = [];
foreach ($eventMembers as $em) {
    $uniqueMembers[(int) $em['student_id']] = $em;
}
$maleMembers = [];
$femaleMembers = [];
$unknownMembers = [];
foreach ($uniqueMembers as $em) {
    $g = student_gender_of($em);
    if ($g === 'male') {
        $maleMembers[] = $em;
    } elseif ($g === 'female') {
        $femaleMembers[] = $em;
    } else {
        $unknownMembers[] = $em;
    }
}
?>
<div class="<?= $isPrint ? 'culture-print-sheet' : '' ?>">
    <?php if ($isPrint): ?>
        <header class="culture-print-hero">
            <p class="eyebrow">Culture Day report</p>
            <h1><?= e($event['title']) ?></h1>
            <p class="muted"><?= e(format_date($event['event_date'] ?? null) ?: 'Date not set') ?> · <?= e(culture_day_status_label($status)) ?></p>
            <p class="culture-print-actions no-print">
                <button class="btn" type="button" onclick="window.print()">Print</button>
                <a class="btn btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&tab=overview')) ?>">Back</a>
                <a class="btn btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&export=csv')) ?>">CSV</a>
            </p>
        </header>
    <?php elseif (can_manage_culture_day()): ?>
        <div class="panel culture-gender-assign">
            <h2>Set male and female</h2>
            <p class="muted">Add students into the Male space or the Female space. That records their gender on the class roster — never guessed from names.</p>
            <div class="culture-gender-board">
                <?php foreach (['male' => 'Male', 'female' => 'Female'] as $genderKey => $genderTitle): ?>
                <section class="culture-gender-lane culture-gender-lane-<?= e($genderKey) ?>">
                    <header>
                        <h3><?= e($genderTitle) ?></h3>
                        <span class="muted">Search, select, then save</span>
                    </header>
                    <form method="get" class="culture-roster-search">
                        <input type="hidden" name="event" value="<?= (int) $event['id'] ?>">
                        <input type="hidden" name="tab" value="lists">
                        <input type="hidden" name="lane" value="<?= e($genderKey) ?>">
                        <input name="q" value="<?= e(request_str('lane') === $genderKey ? $listSearch : '') ?>" placeholder="Search name or student ID" autocomplete="off">
                        <button class="btn btn-ghost" type="submit">Search</button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="assign_gender">
                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                        <input type="hidden" name="gender" value="<?= e($genderKey) ?>">
                        <?php
                        $laneActive = request_str('lane') === $genderKey && $listSearch !== '';
                        $laneRows = $laneActive ? $listSearchRows : [];
                        ?>
                        <?php if ($laneActive && !$laneRows): ?>
                            <p class="muted">No students matched.</p>
                        <?php elseif ($laneRows): ?>
                            <div class="culture-student-pick">
                                <?php foreach ($laneRows as $stu): ?>
                                    <label class="culture-pick-card">
                                        <input type="checkbox" name="student_ids[]" value="<?= (int) $stu['id'] ?>">
                                        <span class="culture-pick-main">
                                            <strong><?= e($stu['student_name']) ?></strong>
                                            <span class="muted"><?= e($stu['student_code'] ?: 'No ID') ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button class="btn" type="submit">Add to <?= e($genderTitle) ?></button>
                        <?php else: ?>
                            <p class="muted">Search the class roster, then add selected students here.</p>
                        <?php endif; ?>
                    </form>
                    <?php
                    $lanePeople = $genderKey === 'male' ? $maleMembers : $femaleMembers;
                    ?>
                    <div class="culture-lane-people">
                        <p class="section-label"><?= count($lanePeople) ?> in this Culture Day</p>
                        <?php if (!$lanePeople): ?>
                            <p class="muted">None yet.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($lanePeople as $p): ?>
                                    <li>
                                        <strong><?= e($p['student_name']) ?></strong>
                                        <span class="muted"><?= e($p['student_code'] ?: '') ?><?= !empty($p['group_name']) ? ' · ' . e($p['group_name']) : '' ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>

            <?php if ($unknownMembers): ?>
            <div class="culture-gender-unknown">
                <h3>Gender not set</h3>
                <p class="muted"><?= count($unknownMembers) ?> Culture Day member<?= count($unknownMembers) === 1 ? '' : 's' ?> still need a gender. Move them into Male or Female.</p>
                <div class="culture-unknown-grid">
                    <?php foreach ($unknownMembers as $p): ?>
                        <article class="culture-unknown-card">
                            <div>
                                <strong><?= e($p['student_name']) ?></strong>
                                <p class="muted"><?= e($p['student_code'] ?: 'No ID') ?><?= !empty($p['group_name']) ? ' · ' . e($p['group_name']) : '' ?></p>
                            </div>
                            <div class="row-actions">
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_gender"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="gender" value="male"><input type="hidden" name="student_ids[]" value="<?= (int) $p['student_id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Male</button></form>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_gender"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="gender" value="female"><input type="hidden" name="student_ids[]" value="<?= (int) $p['student_id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Female</button></form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="<?= $isPrint ? '' : 'panel' ?>">
        <?php if (!$isPrint): ?>
            <h2>Lists by culture</h2>
            <p class="muted">After gender is set above, each culture shows Male and Female side by side.</p>
        <?php endif; ?>

        <?php if ($lists === []): ?>
            <p class="muted">No cultures to list yet. Enter group papers first.</p>
        <?php else: foreach ($lists as $culture): ?>
            <section class="culture-list-card">
                <header class="culture-list-card-head">
                    <div>
                        <h3><?= e($culture['name']) ?></h3>
                        <p class="muted">
                            <?= (int) $culture['group_count'] ?> groups ·
                            <?= (int) $culture['student_count'] ?> students ·
                            <?= (int) $culture['male_count'] ?> male ·
                            <?= (int) $culture['female_count'] ?> female
                            <?php if ((int) $culture['unknown_count'] > 0): ?>
                                · <?= (int) $culture['unknown_count'] ?> not set
                            <?php endif; ?>
                        </p>
                    </div>
                </header>
                <div class="culture-list-split">
                    <?php
                    $renderModernGender = static function (string $title, string $key, array $people): void {
                        echo '<div class="culture-list-col culture-list-col-' . e($key) . '">';
                        echo '<h4>' . e($title) . ' <span>' . count($people) . '</span></h4>';
                        if ($people === []) {
                            echo '<p class="muted">None.</p></div>';
                            return;
                        }
                        $byGroup = [];
                        foreach ($people as $p) {
                            $byGroup[(string) ($p['group_name'] ?? 'Group')][] = $p;
                        }
                        foreach ($byGroup as $gName => $rows) {
                            echo '<p class="culture-list-group">' . e($gName) . '</p><ul>';
                            foreach ($rows as $p) {
                                echo '<li><strong>' . e((string) ($p['name'] ?? '')) . '</strong>';
                                if (($p['code'] ?? '') !== '') {
                                    echo '<span class="muted">' . e((string) $p['code']) . '</span>';
                                }
                                echo '</li>';
                            }
                            echo '</ul>';
                        }
                        echo '</div>';
                    };
                    $renderModernGender('Male', 'male', $culture['male']);
                    $renderModernGender('Female', 'female', $culture['female']);
                    ?>
                </div>
                <?php if ($culture['unknown'] !== []): ?>
                    <div class="culture-list-unknown">
                        <h4>Not set <span><?= count($culture['unknown']) ?></span></h4>
                        <ul>
                            <?php foreach ($culture['unknown'] as $p): ?>
                                <li>
                                    <strong><?= e((string) ($p['name'] ?? '')) ?></strong>
                                    <span class="muted"><?= e((string) ($p['group_name'] ?? '')) ?><?= ($p['code'] ?? '') !== '' ? ' · ' . e((string) $p['code']) : '' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'audit'): ?>
<div class="panel">
    <h2>Recent Culture Day activity</h2>
    <?php
    $logs = [];
    if (table_exists(db(), 'audit_logs')) {
        $stmt = db()->prepare(
            "SELECT * FROM audit_logs WHERE action LIKE 'culture_day.%' AND (target_id = ? OR detail LIKE ?) ORDER BY id DESC LIMIT 40"
        );
        $stmt->execute([(int) $event['id'], '%' . ($event['title'] ?? '') . '%']);
        $logs = $stmt->fetchAll();
    }
    ?>
    <?php if (!$logs): ?>
        <p class="muted">No Culture Day audit entries yet for this event.</p>
    <?php else: ?>
        <ul class="culture-audit">
            <?php foreach ($logs as $log): ?>
                <li>
                    <strong><?= e(audit_label($log)) ?></strong>
                    <span class="muted"><?= e($log['actor'] ?? '') ?> · <?= e(format_when((string) ($log['created_at'] ?? ''))) ?></span>
                    <?php if (!empty($log['detail'])): ?><p><?= e($log['detail']) ?></p><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
