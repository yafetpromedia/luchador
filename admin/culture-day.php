<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['culture_day.view', 'culture_day.manage', 'culture_day.publish']);

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
            $payload['member_ids'] = $_POST['member_ids'] ?? [];
            $saved = save_culture_day_group($eid, $payload, $gid);
            flash_set('success', 'Group paper saved.');
            redirect('admin/culture-day.php?event=' . $eid . '&tab=groups');
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
        throw new InvalidArgumentException('Unknown action.');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save Culture Day changes.');
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
$selectedIds = [];
if ($group) {
    foreach (culture_day_members((int) $group['id']) as $m) {
        $selectedIds[(int) $m['student_id']] = true;
    }
}
$searchRows = $q !== '' ? fetch_students(['search' => $q, 'sort' => 'name']) : [];
if (count($searchRows) > 40) {
    $searchRows = array_slice($searchRows, 0, 40);
}
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

        <?php if ($selectedIds): ?>
            <p class="section-label" style="margin-top:1rem">Current members</p>
            <div class="culture-member-chips">
                <?php foreach (culture_day_members((int) ($group['id'] ?? 0)) as $m): ?>
                    <label class="chip is-active">
                        <input type="checkbox" name="member_ids[]" value="<?= (int) $m['student_id'] ?>" checked>
                        <?= e($m['student_name']) ?><?= !empty($m['student_code']) ? ' · ' . e($m['student_code']) : '' ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="muted">Uncheck to remove. Search below to add more existing students.</p>
        <?php endif; ?>

        <p class="section-label" style="margin-top:1rem">Add students from the class roster</p>
        <div class="toolbar" style="margin-bottom:0.75rem">
            <div class="form-group search-field" style="flex:1">
                <label class="sr-only" for="q">Search students</label>
                <input id="q" form="culture-search" name="q" value="<?= e($q) ?>" placeholder="Search name or student ID">
            </div>
            <button class="btn btn-ghost" form="culture-search" type="submit">Search</button>
        </div>
        <?php if ($q !== '' && !$searchRows): ?>
            <p class="muted">No students matched “<?= e($q) ?>”.</p>
        <?php elseif ($searchRows): ?>
            <div class="culture-student-pick">
                <?php foreach ($searchRows as $stu): ?>
                    <?php $sid = (int) $stu['id']; if (isset($selectedIds[$sid])) continue; ?>
                    <label class="check">
                        <input type="checkbox" name="member_ids[]" value="<?= $sid ?>">
                        <span><strong><?= e($stu['student_name']) ?></strong> <span class="muted"><?= e($stu['student_code'] ?: 'No ID') ?><?= !empty($stu['section']) ? ' · ' . e(class_section_label((string) $stu['section'])) : '' ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">Search by name or student ID, then select existing records. Duplicate profiles are not created.</p>
        <?php endif; ?>

        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save group</button>
            <?php if ($group): ?><a class="btn btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&tab=groups')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
    <form id="culture-search" method="get">
        <input type="hidden" name="event" value="<?= (int) $event['id'] ?>">
        <input type="hidden" name="tab" value="groups">
        <?php if ($group): ?><input type="hidden" name="group" value="<?= (int) $group['id'] ?>"><input type="hidden" name="edit" value="group"><?php else: ?><input type="hidden" name="edit" value="group"><?php endif; ?>
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
    <div class="toolbar">
        <form method="get" class="toolbar" style="gap:0.5rem;flex:1">
            <input type="hidden" name="event" value="<?= (int) $event['id'] ?>">
            <input type="hidden" name="tab" value="graph">
            <div class="form-group search-field" style="flex:1;margin:0">
                <input name="q" value="<?= e($q) ?>" placeholder="Search culture, group, name, or ID">
            </div>
            <button class="btn btn-ghost" type="submit">Search</button>
        </form>
        <button class="btn btn-ghost" type="button" data-culture-expand-all>Expand all</button>
        <button class="btn btn-ghost" type="button" data-culture-collapse-all>Collapse all</button>
    </div>
    <?php if (($graph['cultures'] ?? []) === []): ?>
        <div class="empty-state" style="margin-top:1rem">
            <h2>No groups yet</h2>
            <p>Enter group papers to build the Culture → Groups → Students graph.</p>
        </div>
    <?php else: ?>
        <div class="culture-graph" data-culture-graph>
            <div class="culture-node culture-node-root">
                <strong><?= e($event['title']) ?></strong>
                <span class="muted"><?= (int) $graph['culture_count'] ?> cultures · <?= (int) $graph['group_count'] ?> groups · <?= (int) $graph['student_count'] ?> students</span>
            </div>
            <?php foreach ($graph['cultures'] as $culture): ?>
                <?php if (!$filterMatch($culture)) continue; ?>
                <details class="culture-node culture-node-culture" open>
                    <summary>
                        <span><?= e($culture['name']) ?></span>
                        <span class="muted"><?= (int) $culture['group_count'] ?> groups · <?= (int) $culture['student_count'] ?> students</span>
                    </summary>
                    <div class="culture-node-children">
                        <?php foreach ($culture['groups'] as $group): ?>
                            <details class="culture-node culture-node-group">
                                <summary>
                                    <span><?= e($group['name']) ?></span>
                                    <span class="muted"><?= (int) $group['member_count'] ?> members</span>
                                </summary>
                                <ul class="culture-node-students">
                                    <?php foreach ($group['members'] as $member): ?>
                                        <li>
                                            <?= e($member['name']) ?>
                                            <span class="muted"><?= e($member['code'] ?: 'No ID') ?><?= $member['section'] !== '' ? ' · ' . e(class_section_label($member['section'])) : '' ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$group['members']): ?><li class="muted">No members</li><?php endif; ?>
                                </ul>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'lists' || $tab === 'print'): ?>
<div class="<?= $isPrint ? 'culture-print-sheet' : 'panel' ?>">
    <?php if ($isPrint): ?>
        <p class="eyebrow">Culture Day report</p>
        <h1><?= e($event['title']) ?></h1>
        <p class="muted"><?= e(format_date($event['event_date'] ?? null) ?: 'Date not set') ?> · <?= e(culture_day_status_label($status)) ?></p>
        <p class="culture-print-actions no-print">
            <button class="btn" type="button" onclick="window.print()">Print</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&tab=overview')) ?>">Back</a>
            <a class="btn btn-ghost" href="<?= e(url('admin/culture-day.php?event=' . (int) $event['id'] . '&export=csv')) ?>">CSV</a>
        </p>
    <?php else: ?>
        <h2>Male and female lists</h2>
        <p class="muted">Gender comes only from recorded student data. Missing values are listed separately — never guessed from names.</p>
    <?php endif; ?>

    <?php if ($lists === []): ?>
        <p class="muted">No cultures to list yet.</p>
    <?php else: foreach ($lists as $culture): ?>
        <section class="culture-list-block">
            <h3><?= e($culture['name']) ?></h3>
            <p class="muted">
                <?= (int) $culture['group_count'] ?> groups ·
                <?= (int) $culture['student_count'] ?> unique students ·
                <?= (int) $culture['male_count'] ?> male ·
                <?= (int) $culture['female_count'] ?> female ·
                <?= (int) $culture['unknown_count'] ?> not set / unverified
            </p>
            <p class="muted">Groups: <?= e(implode(', ', array_map(static fn(array $g): string => $g['name'], $culture['groups']))) ?></p>

            <?php
            $renderGenderBlock = static function (string $title, array $people): void {
                echo '<h4>' . e($title) . '</h4>';
                if ($people === []) {
                    echo '<p class="muted">None.</p>';
                    return;
                }
                $byGroup = [];
                foreach ($people as $p) {
                    $byGroup[$p['group_name']][] = $p;
                }
                foreach ($byGroup as $gName => $rows) {
                    echo '<p><strong>' . e($gName) . '</strong></p><ul>';
                    foreach ($rows as $p) {
                        echo '<li>' . e($p['name']);
                        if ($p['code'] !== '') {
                            echo ' <span class="muted">· ' . e($p['code']) . '</span>';
                        }
                        echo '</li>';
                    }
                    echo '</ul>';
                }
            };
            $renderGenderBlock('Male', $culture['male']);
            $renderGenderBlock('Female', $culture['female']);
            $renderGenderBlock('Gender not set or unverified', $culture['unknown']);
            ?>
        </section>
    <?php endforeach; endif; ?>
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
