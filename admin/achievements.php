<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['achievements.view', 'achievements.manage']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM achievements WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    require_csrf();
    require_manage_on_post('achievements.manage');
    try {
        if (posted('action') === 'delete') {
            $id = request_int('id');
            $stmt = db()->prepare('SELECT image, title FROM achievements WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            delete_upload((string) ($row['image'] ?? ''));
            db()->prepare('DELETE FROM achievements WHERE id = ?')->execute([$id]);
            log_audit('achievement.delete', 'achievement', $id, $row['title'] ?? '');
            flash_set('success', 'Achievement deleted.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $image = $edit['image'] ?? null;
            if (!empty($_FILES['image']['name'])) {
                $upload = store_upload($_FILES['image'], 'achievements');
                if (!$upload['ok']) {
                    throw new InvalidArgumentException($upload['error']);
                }
                delete_upload($image);
                $image = $upload['path'];
            }
            $title = posted('title');
            if ($title === '') {
                throw new InvalidArgumentException('Title is required.');
            }
            $category = posted('category');
            if (!array_key_exists($category, achievement_categories())) {
                $category = 'class';
            }
            $params = [$title, posted('description'), posted('achieved_on') ?: null, $category, $image, posted('published') === '1' ? 1 : 0];
            if ($id) {
                $params[] = $id;
                db()->prepare('UPDATE achievements SET title=?, description=?, achieved_on=?, category=?, image=?, published=? WHERE id=?')->execute($params);
                log_audit('achievement.save', 'achievement', $id, $title);
                flash_set('success', 'Achievement updated.');
            } else {
                db()->prepare('INSERT INTO achievements (title, description, achieved_on, category, image, published) VALUES (?,?,?,?,?,?)')->execute($params);
                $id = (int) db()->lastInsertId();
                log_audit('achievement.save', 'achievement', $id, $title);
                flash_set('success', 'Achievement created.');
            }
            notify_if_published((int) ($edit['published'] ?? 0) === 1, posted('published') === '1', [
                'type' => 'achievement.published',
                'title' => 'New achievement',
                'body' => $title,
                'icon' => 'award',
                'target_type' => 'achievement',
                'target_id' => (int) $id,
                'url_student' => 'student/achievements.php',
                'url_public' => 'index.php',
            ]);
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/achievements.php');
}

$rows = db()->query('SELECT * FROM achievements ORDER BY id DESC')->fetchAll();
admin_header($edit ? 'Edit achievement' : 'Achievements', 'achievements');
admin_page_head('Record verified class achievements. Unpublished items stay off the public site.');
?>

<?php if (can('achievements.manage')): ?>
<div class="panel">
    <h2><?= $edit ? 'Update achievement' : 'Add achievement' ?></h2>
    <form method="post" enctype="multipart/form-data" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group full"><label>Title</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>"></div>
            <div class="form-group full"><label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Date</label><input type="date" name="achieved_on" value="<?= e($edit['achieved_on'] ?? '') ?>"></div>
            <div class="form-group">
                <label>Category</label>
                <select name="category">
                    <?php foreach (achievement_categories() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= (($edit['category'] ?? 'class') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full"><label>Image</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"></div>
            <div class="form-group full"><label class="check"><input type="checkbox" name="published" value="1" <?= !empty($edit['published']) ? 'checked' : '' ?>> Published</label></div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save achievement</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/achievements.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No achievements yet.', 'Add an achievement above. It stays private until it is published.'); ?>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Title</th><th>Category</th><th>Published</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= admin_title_cell((string) $row['title'], $row['image'] ?? null) ?></td>
            <td><?= e(status_label((string) $row['category'])) ?></td>
            <td><?= admin_published_badge($row['published'] ?? 0) ?></td>
            <td class="row-actions">
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete achievement?">Delete</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
