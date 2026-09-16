<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['spotlights.view', 'spotlights.manage']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM spotlights WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    require_csrf();
    require_manage_on_post('spotlights.manage');
    try {
        if (posted('action') === 'delete') {
            $id = request_int('id');
            $stmt = db()->prepare('SELECT photo, student_name FROM spotlights WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            delete_upload((string) ($row['photo'] ?? ''));
            db()->prepare('DELETE FROM spotlights WHERE id = ?')->execute([$id]);
            log_audit('spotlight.delete', 'spotlight', $id, $row['student_name'] ?? '');
            flash_set('success', 'Spotlight deleted.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $photo = $edit['photo'] ?? null;
            if (!empty($_FILES['photo']['name'])) {
                $upload = store_upload($_FILES['photo'], 'spotlights');
                if (!$upload['ok']) {
                    throw new InvalidArgumentException($upload['error']);
                }
                delete_upload($photo);
                $photo = $upload['path'];
            }
            $name = posted('student_name');
            if ($name === '') {
                throw new InvalidArgumentException('Student name is required.');
            }
            $category = posted('category');
            if (!array_key_exists($category, spotlight_categories())) {
                $category = 'student_of_month';
            }
            $params = [
                $name,
                $category,
                posted('title') ?: null,
                posted('description'),
                $photo,
                posted('featured_on') ?: null,
                posted('published') === '1' ? 1 : 0,
            ];
            if ($id) {
                $params[] = $id;
                db()->prepare('UPDATE spotlights SET student_name=?, category=?, title=?, description=?, photo=?, featured_on=?, published=? WHERE id=?')->execute($params);
                log_audit('spotlight.save', 'spotlight', $id, $name);
                flash_set('success', 'Spotlight updated.');
            } else {
                db()->prepare('INSERT INTO spotlights (student_name, category, title, description, photo, featured_on, published) VALUES (?,?,?,?,?,?,?)')->execute($params);
                log_audit('spotlight.save', 'spotlight', (int) db()->lastInsertId(), $name);
                flash_set('success', 'Spotlight created.');
            }
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/spotlights.php');
}

$rows = db()->query('SELECT * FROM spotlights ORDER BY id DESC')->fetchAll();
admin_header($edit ? 'Edit spotlight' : 'Spotlight', 'spotlights');
admin_page_head('Publish recognition by hand. This is not an automatic ranking and must not include phone numbers.');
?>

<?php if (can('spotlights.manage')): ?>
<div class="panel">
    <h2><?= $edit ? 'Update spotlight' : 'Add spotlight' ?></h2>
    <form method="post" enctype="multipart/form-data" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group"><label>Student name</label><input name="student_name" required value="<?= e($edit['student_name'] ?? '') ?>"></div>
            <div class="form-group">
                <label>Category</label>
                <select name="category">
                    <?php foreach (spotlight_categories() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= (($edit['category'] ?? 'student_of_month') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full"><label>Headline</label><input name="title" value="<?= e($edit['title'] ?? '') ?>" placeholder="Optional, e.g. Student of the Month"></div>
            <div class="form-group full"><label>Note</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Featured on</label><input type="date" name="featured_on" value="<?= e($edit['featured_on'] ?? '') ?>"></div>
            <div class="form-group"><label>Photo</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"></div>
            <div class="form-group full"><label class="check"><input type="checkbox" name="published" value="1" <?= !empty($edit['published']) ? 'checked' : '' ?>> Published</label></div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save spotlight</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/spotlights.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No spotlights yet.', 'Add recognition only when the class is ready to publish it.'); ?>
<?php else: ?>
<div class="people-grid">
    <?php foreach ($rows as $row): ?>
        <article class="people-card">
            <?php if (!empty($row['photo'])): ?>
                <span class="stu-avatar"><img src="<?= e(url($row['photo'])) ?>" alt=""></span>
            <?php else: ?>
                <span class="stu-avatar" aria-hidden="true"><?= e(person_initials((string) $row['student_name'])) ?></span>
            <?php endif; ?>
            <h3><?= e($row['student_name']) ?></h3>
            <p class="muted"><?= e(status_label((string) $row['category'])) ?><?= !empty($row['title']) ? ' · ' . e($row['title']) : '' ?></p>
            <?= admin_published_badge($row['published'] ?? 0) ?>
            <?php if (can('spotlights.manage')): ?>
            <div class="row-actions" style="margin-top:0.75rem">
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete spotlight?">Delete</button></form>
            </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
