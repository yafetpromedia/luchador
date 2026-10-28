<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

student_boot();

$requestedId = request_int('id');
$event = culture_day_published_event($requestedId > 0 ? $requestedId : null);
$published = culture_day_published_events();
$q = trim(request_str('q'));
$tab = request_str('tab', 'graph');
if (!in_array($tab, ['graph', 'lists'], true)) {
    $tab = 'graph';
}

student_header(
    'Culture Day',
    'culture-day',
    lead: $event
        ? ((string) $event['title'] . (!empty($event['event_date']) ? ' · ' . format_date($event['event_date']) : ''))
        : 'Published Culture Day details appear here for the class.'
);

if (!$event):
?>
<div class="empty-state">
    <h2>Not published yet</h2>
    <p>Culture Day stays private until an administrator publishes it. Check back after it goes live.</p>
</div>
<?php
    student_footer('culture-day');
    return;
endif;

$graph = culture_day_graph_data((int) $event['id']);
$lists = culture_day_gender_lists((int) $event['id']);
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

<?php if (count($published) > 1): ?>
<div class="filters">
    <?php foreach ($published as $row): ?>
        <a class="chip<?= (int) $row['id'] === (int) $event['id'] ? ' is-active' : '' ?>" href="<?= e(url('student/culture-day.php?id=' . (int) $row['id'] . '&tab=' . $tab)) ?>"><?= e($row['title']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<article class="stu-block">
    <p class="eyebrow">Culture Day</p>
    <h2><?= e($event['title']) ?></h2>
    <p class="muted">
        <?= e(format_date($event['event_date'] ?? null) ?: 'Date to be announced') ?>
        · <?= (int) ($graph['culture_count'] ?? 0) ?> cultures
        · <?= (int) ($graph['group_count'] ?? 0) ?> groups
        · <?= (int) ($graph['student_count'] ?? 0) ?> students
    </p>
    <?php if (trim((string) ($event['description'] ?? '')) !== ''): ?>
        <p><?= nl2br(e((string) $event['description'])) ?></p>
    <?php endif; ?>
</article>

<div class="filters">
    <a class="chip<?= $tab === 'graph' ? ' is-active' : '' ?>" href="<?= e(url('student/culture-day.php?id=' . (int) $event['id'] . '&tab=graph')) ?>">Relationship graph</a>
    <a class="chip<?= $tab === 'lists' ? ' is-active' : '' ?>" href="<?= e(url('student/culture-day.php?id=' . (int) $event['id'] . '&tab=lists')) ?>">Male / Female</a>
</div>

<form method="get" class="toolbar" style="margin-bottom:1rem">
    <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="form-group search-field" style="flex:1;margin:0">
        <input name="q" value="<?= e($q) ?>" placeholder="Search culture, group, name, or ID">
    </div>
    <button class="btn btn-ghost" type="submit">Search</button>
</form>

<?php if ($tab === 'graph'): ?>
<section class="stu-block culture-graph-panel">
    <div class="toolbar" style="margin-bottom:0.75rem">
        <button class="btn btn-ghost" type="button" data-culture-expand-all>Expand all</button>
        <button class="btn btn-ghost" type="button" data-culture-collapse-all>Collapse all</button>
    </div>
    <?php if (($graph['cultures'] ?? []) === []): ?>
        <div class="empty-state">
            <h2>No groups yet</h2>
            <p>Groups will appear here once they are entered and published.</p>
        </div>
    <?php else: ?>
        <div class="culture-graph" data-culture-graph>
            <div class="culture-node culture-node-root">
                <strong><?= e($event['title']) ?></strong>
                <span class="muted">Culture Day → Cultures → Groups → Students</span>
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
                            <details class="culture-node culture-node-group" open>
                                <summary>
                                    <span><?= e($group['name']) ?></span>
                                    <span class="muted"><?= (int) $group['member_count'] ?> members</span>
                                </summary>
                                <ul class="culture-node-students">
                                    <?php foreach ($group['members'] as $member): ?>
                                        <li>
                                            <?= e($member['name']) ?>
                                            <span class="muted"><?= e($member['code'] ?: 'No ID') ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'lists'): ?>
<section class="stu-block">
    <?php if ($lists === []): ?>
        <p class="muted">No lists yet.</p>
    <?php else: foreach ($lists as $culture): ?>
        <?php
        $listAsGraph = [
            'name' => $culture['name'],
            'groups' => array_map(static function (array $g) use ($culture): array {
                $members = [];
                foreach (array_merge($culture['male'], $culture['female'], $culture['unknown']) as $m) {
                    if (($m['group_name'] ?? '') === ($g['name'] ?? '')) {
                        $members[] = [
                            'name' => (string) ($m['name'] ?? ''),
                            'code' => (string) ($m['code'] ?? ''),
                        ];
                    }
                }
                return ['name' => (string) ($g['name'] ?? ''), 'members' => $members];
            }, $culture['groups']),
        ];
        if (!$filterMatch($listAsGraph)) {
            continue;
        }
        ?>
        <div class="culture-list-block">
            <h3><?= e($culture['name']) ?></h3>
            <p class="muted">
                <?= (int) $culture['group_count'] ?> groups ·
                <?= (int) $culture['student_count'] ?> students ·
                <?= (int) $culture['male_count'] ?> male ·
                <?= (int) $culture['female_count'] ?> female ·
                <?= (int) $culture['unknown_count'] ?> not set / unverified
            </p>
            <?php
            $blocks = [
                'Male' => $culture['male'],
                'Female' => $culture['female'],
                'Gender not set or unverified' => $culture['unknown'],
            ];
            foreach ($blocks as $title => $people):
            ?>
                <h4><?= e($title) ?></h4>
                <?php if (!$people): ?>
                    <p class="muted">None.</p>
                <?php else: ?>
                    <?php
                    $byGroup = [];
                    foreach ($people as $p) {
                        $byGroup[$p['group_name']][] = $p;
                    }
                    foreach ($byGroup as $gName => $rows):
                    ?>
                        <p><strong><?= e($gName) ?></strong></p>
                        <ul>
                            <?php foreach ($rows as $p): ?>
                                <li><?= e($p['name']) ?><?php if ($p['code'] !== ''): ?> <span class="muted">· <?= e($p['code']) ?></span><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; endif; ?>
</section>
<?php endif; ?>

<?php student_footer('culture-day'); ?>
