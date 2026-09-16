<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/interactions.php';

admin_boot(['polls.view', 'polls.create', 'polls.edit', 'polls.delete', 'polls.close', 'polls.results']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $edit = poll_by_id($editId);
}

if (is_post()) {
    require_csrf();
    $action = posted('action');
    try {
        if ($action === 'delete') {
            require_permission('polls.delete');
            $id = request_int('id');
            $row = poll_by_id($id);
            delete_poll($id);
            log_audit('poll.delete', 'poll', $id, $row['title'] ?? '');
            flash_set('success', 'Poll deleted. Student records were not changed.');
        } elseif ($action === 'close' || $action === 'open') {
            require_permission('polls.close');
            $id = request_int('id');
            $row = poll_by_id($id);
            if (!$row) {
                throw new InvalidArgumentException('Poll not found.');
            }
            $status = $action === 'close' ? 'closed' : 'active';
            db()->prepare('UPDATE polls SET status = ? WHERE id = ?')->execute([$status, $id]);
            log_audit('poll.close', 'poll', $id, $row['title'] ?? '');
            if ($status === 'active' && (string) ($row['status'] ?? '') !== 'active') {
                $row['status'] = $status;
                notify_students(poll_open_notice($row));
            }
            flash_set('success', $status === 'closed' ? 'Poll closed.' : 'Poll is now open.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            require_permission($id ? 'polls.edit' : 'polls.create');
            $saved = save_poll($_POST, $id, (int) (current_user()['id'] ?? 0));
            log_audit('poll.save', 'poll', (int) ($saved['id'] ?? 0), $saved['title'] ?? '');
            flash_set('success', $id ? 'Poll updated.' : 'Poll saved.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save that poll.');
    }
    redirect('admin/polls.php' . ($editId ? '?edit=' . $editId : ''));
}

$rows = table_exists(db(), 'polls') ? db()->query('SELECT * FROM polls ORDER BY id DESC')->fetchAll() : [];
$filter = request_str('filter');
if ($filter === 'decisions') {
    $rows = array_values(array_filter($rows, static fn (array $row): bool => poll_is_decision($row)));
} elseif ($filter === 'polls') {
    $rows = array_values(array_filter($rows, static fn (array $row): bool => !poll_is_decision($row)));
}
$voteCount = [];
if ($rows) {
    foreach (db()->query('SELECT poll_id, COUNT(*) AS n FROM poll_votes GROUP BY poll_id')->fetchAll() ?: [] as $row) {
        $voteCount[(int) $row['poll_id']] = (int) $row['n'];
    }
}
if ($edit && !can('polls.edit') && !can('polls.results')) {
    $edit = null;
}
$showForm = ($edit && can('polls.edit')) || (!$edit && can('polls.create'));
$options = $edit ? poll_options((int) $edit['id']) : [];
$locked = $edit && ($voteCount[(int) $edit['id']] ?? 0) > 0;
$results = $edit && can('polls.results') ? poll_results((int) $edit['id']) : null;

admin_header($edit ? (poll_is_decision($edit) ? 'Edit decision' : 'Edit poll') : 'Polls', 'polls');
admin_page_head('Class polls and official class decisions. Students vote once; the database rejects duplicates.');
?>

<p class="admin-filters">
    <a class="chip <?= $filter === '' ? 'is-active' : '' ?>" href="<?= e(url('admin/polls.php')) ?>">All</a>
    <a class="chip <?= $filter === 'polls' ? 'is-active' : '' ?>" href="?filter=polls">Polls</a>
    <a class="chip <?= $filter === 'decisions' ? 'is-active' : '' ?>" href="?filter=decisions">Decisions</a>
</p>

<?php if ($showForm): ?>
<div class="panel">
    <h2><?= $edit ? (poll_is_decision($edit) ? 'Update class decision' : 'Update poll') : 'New vote' ?></h2>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group full"><label class="req">Title</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>" placeholder="Where should we hold our Grade 12 class event?"></div>
            <div class="form-group full"><label>Details</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
            <div class="form-group full">
                <label>Options<?= $locked ? ' (locked after the first vote)' : '' ?></label>
                <?php
                $labels = $options ? array_column($options, 'label') : ['', '', '', ''];
                while (count($labels) < 4) {
                    $labels[] = '';
                }
                if (!$locked) {
                    $labels[] = '';
                    $labels[] = '';
                }
                foreach ($labels as $label):
                ?>
                    <input name="options[]" value="<?= e((string) $label) ?>" <?= $locked ? 'readonly' : '' ?> placeholder="Option" style="margin-bottom:0.4rem">
                <?php endforeach; ?>
                <p class="muted">At least two options. One line each.</p>
            </div>
            <div class="form-group">
                <label>Choice</label>
                <select name="choice_type">
                    <option value="single" <?= ($edit['choice_type'] ?? 'single') === 'single' ? 'selected' : '' ?>>Single choice</option>
                    <option value="multiple" <?= ($edit['choice_type'] ?? '') === 'multiple' ? 'selected' : '' ?>>Multiple choice</option>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <?php foreach (['draft' => 'Draft', 'active' => 'Active', 'closed' => 'Closed'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($edit['status'] ?? 'draft') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Starts</label><input type="datetime-local" name="starts_at" value="<?= !empty($edit['starts_at']) ? e(date('Y-m-d\TH:i', strtotime((string) $edit['starts_at']))) : '' ?>"></div>
            <div class="form-group"><label>Ends</label><input type="datetime-local" name="ends_at" value="<?= !empty($edit['ends_at']) ? e(date('Y-m-d\TH:i', strtotime((string) $edit['ends_at']))) : '' ?>"></div>
            <div class="form-group">
                <label>Results</label>
                <select name="show_results">
                    <option value="immediate" <?= ($edit['show_results'] ?? 'immediate') === 'immediate' ? 'selected' : '' ?>>Show after voting</option>
                    <option value="after_close" <?= ($edit['show_results'] ?? '') === 'after_close' ? 'selected' : '' ?>>Show after poll closes</option>
                </select>
            </div>
            <div class="form-group full">
                <label class="check"><input type="checkbox" name="is_decision" value="1" <?= !empty($edit['is_decision']) || (!$edit && $filter === 'decisions') ? 'checked' : '' ?>> Class decision — this vote counts for the class, not a casual poll</label>
                <label class="check"><input type="checkbox" name="anonymous" value="1" <?= !empty($edit['anonymous']) ? 'checked' : '' ?>> Anonymous voting (names hidden from students; the roster id is still stored)</label>
                <label class="check"><input type="checkbox" name="allow_change" value="1" <?= !$edit || !empty($edit['allow_change']) ? 'checked' : '' ?>> Allow students to change their vote</label>
                <label class="check"><input type="checkbox" name="featured" value="1" <?= !empty($edit['featured']) ? 'checked' : '' ?>> Feature on the student home</label>
            </div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save poll</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/polls.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($edit && $results && can('polls.results')): ?>
<section class="panel">
    <h2>Results</h2>
    <p class="muted"><?= (int) $results['total'] ?> vote<?= (int) $results['total'] === 1 ? '' : 's' ?> recorded.</p>
    <ul class="poll-results">
        <?php foreach ($results['options'] as $option): ?>
            <li>
                <span><?= e($option['label']) ?></span>
                <span class="poll-bar"><i style="width: <?= (int) $option['percent'] ?>%"></i></span>
                <b><?= (int) $option['percent'] ?>%</b>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty($filter === 'decisions' ? 'No class decisions yet.' : 'No polls yet.', 'Create a vote above. Leave it as draft until the class should vote.'); ?>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Poll</th><th>Status</th><th>Votes</th><th>Closes</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td>
                <strong><?= e($row['title']) ?></strong>
                <?php if (poll_is_decision($row)): ?><span class="badge">Decision</span><?php endif; ?>
                <?php if (!empty($row['featured'])): ?><span class="badge">Home</span><?php endif; ?>
                <?php if (!empty($row['anonymous'])): ?><span class="badge">Anonymous</span><?php endif; ?>
            </td>
            <td><?= admin_status_badge(poll_effective_status($row)) ?></td>
            <td><?= (int) ($voteCount[(int) $row['id']] ?? 0) ?></td>
            <td><?= !empty($row['ends_at']) ? e(format_when($row['ends_at'])) : '—' ?></td>
            <td class="row-actions">
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Open</a>
                <?php if (can('polls.close')): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= poll_effective_status($row) === 'closed' ? 'open' : 'close' ?>">
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                        <button class="btn btn-sm" type="submit"><?= poll_effective_status($row) === 'closed' ? 'Reopen' : 'Close' ?></button>
                    </form>
                <?php endif; ?>
                <?php if (can('polls.delete')): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Votes for this poll will be removed. Student records stay." data-confirm-title="Delete poll?">Delete</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
