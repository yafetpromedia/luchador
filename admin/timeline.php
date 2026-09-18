<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['timeline.view', 'timeline.manage']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM timeline_milestones WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    require_csrf();
    require_manage_on_post('timeline.manage');
    try {
        if (posted('action') === 'delete') {
            $id = request_int('id');
            $stmt = db()->prepare('SELECT title FROM timeline_milestones WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            db()->prepare('DELETE FROM timeline_milestones WHERE id = ?')->execute([$id]);
            log_audit('timeline.delete', 'timeline', $id, $row['title'] ?? '');
            flash_set('success', 'Milestone deleted.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $title = posted('title');
            if ($title === '') {
                throw new InvalidArgumentException('Title is required.');
            }
            $stage = posted('stage');
            if (!array_key_exists($stage, timeline_stages())) {
                $stage = 'milestone';
            }
            $visibility = posted_visibility($edit);
            $published = published_flag_for_visibility($visibility);
            $params = [
                $title,
                posted('description'),
                posted('occurred_on') ?: null,
                $stage,
                posted('highlight') === '1' ? 1 : 0,
                posted('display_order') !== '' ? (int) posted('display_order') : 0,
                $published,
                $visibility,
            ];
            if ($id) {
                $params[] = $id;
                db()->prepare('UPDATE timeline_milestones SET title=?, description=?, occurred_on=?, stage=?, highlight=?, display_order=?, published=?, visibility=? WHERE id=?')->execute($params);
                log_audit('timeline.save', 'timeline', $id, $title);
                flash_set('success', 'Milestone updated.');
            } else {
                db()->prepare('INSERT INTO timeline_milestones (title, description, occurred_on, stage, highlight, display_order, published, visibility) VALUES (?,?,?,?,?,?,?,?)')->execute($params);
                log_audit('timeline.save', 'timeline', (int) db()->lastInsertId(), $title);
                flash_set('success', 'Milestone created.');
            }
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/timeline.php');
}

$rows = db()->query('SELECT * FROM timeline_milestones ORDER BY display_order ASC, (occurred_on IS NULL), occurred_on ASC, id ASC')->fetchAll();
admin_header($edit ? 'Edit milestone' : 'Journey', 'timeline');
admin_page_head('Add real senior-year milestones only. New items stay class-only until you choose Public website.');
?>

<?php if (can('timeline.manage')): ?>
<div class="panel">
    <h2><?= $edit ? 'Update milestone' : 'Add milestone' ?></h2>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group full"><label>Title</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>"></div>
            <div class="form-group full"><label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Date</label><input type="date" name="occurred_on" value="<?= e($edit['occurred_on'] ?? '') ?>"></div>
            <div class="form-group">
                <label>Stage</label>
                <select name="stage">
                    <?php foreach (timeline_stages() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= (($edit['stage'] ?? 'milestone') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Display order</label><input type="number" name="display_order" value="<?= e((string) ($edit['display_order'] ?? '0')) ?>"></div>
            <div class="form-group full"><label class="check"><input type="checkbox" name="highlight" value="1" <?= !empty($edit['highlight']) ? 'checked' : '' ?>> Mark as “you are here”</label></div>
            <?= visibility_select($edit) ?>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save milestone</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/timeline.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No milestones yet.', 'Add the real Grade 12 path: start, events, exams, graduation. Leave unpublished until confirmed.'); ?>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Title</th><th>Stage</th><th>Date</th><th>Visibility</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= e($row['title']) ?><?php if (!empty($row['highlight'])): ?> <span class="badge">Here</span><?php endif; ?></td>
            <td><?= e(status_label((string) $row['stage'])) ?></td>
            <td><?= e(format_date($row['occurred_on'])) ?></td>
            <td><?= admin_visibility_badge($row) ?></td>
            <td class="row-actions">
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete milestone?">Delete</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
