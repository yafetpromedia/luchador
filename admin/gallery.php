<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['gallery.view', 'gallery.upload', 'gallery.edit', 'gallery.delete']);

if (is_post()) {
    require_csrf();
    try {
        if (posted('action') === 'delete') {
            require_permission('gallery.delete');
            $id = request_int('id');
            $stmt = db()->prepare('SELECT image_path, title FROM gallery WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            delete_upload((string) ($row['image_path'] ?? ''));
            db()->prepare('DELETE FROM gallery WHERE id = ?')->execute([$id]);
            log_audit('gallery.delete', 'gallery', $id, $row['title'] ?? '');
            flash_set('success', 'Image deleted.');
        } elseif (posted('action') === 'toggle') {
            require_permission('gallery.edit');
            $toggleId = request_int('id');
            $stmt = db()->prepare('SELECT published, title FROM gallery WHERE id = ?');
            $stmt->execute([$toggleId]);
            $row = $stmt->fetch() ?: [];
            db()->prepare('UPDATE gallery SET published = IF(published = 1, 0, 1) WHERE id = ?')->execute([$toggleId]);
            log_audit('gallery.toggle', 'gallery', $toggleId);
            notify_if_published((int) ($row['published'] ?? 0) === 1, (int) ($row['published'] ?? 0) !== 1, [
                'type' => 'gallery.published',
                'title' => 'New class photo',
                'body' => (string) ($row['title'] ?? 'Gallery'),
                'icon' => 'image',
                'target_type' => 'gallery',
                'target_id' => $toggleId,
                'url_student' => 'student/gallery.php',
                'url_public' => 'index.php#gallery',
            ]);
            flash_set('success', 'Visibility updated.');
        } else {
            require_permission('gallery.upload');
            $title = posted('title');
            if ($title === '') {
                throw new InvalidArgumentException('Title is required.');
            }
            if (empty($_FILES['image']['name'])) {
                throw new InvalidArgumentException('An image is required.');
            }
            $upload = store_upload($_FILES['image'], 'gallery');
            if (!$upload['ok']) {
                throw new InvalidArgumentException($upload['error']);
            }
            $category = posted('category');
            if (!array_key_exists($category, gallery_categories()) || $category === 'all') {
                $category = 'class';
            }
            db()->prepare('INSERT INTO gallery (title, caption, image_path, category, taken_on, published) VALUES (?,?,?,?,?,?)')
                ->execute([$title, posted('caption'), $upload['path'], $category, posted('taken_on') ?: null, posted('published') === '1' ? 1 : 0]);
            $newId = (int) db()->lastInsertId();
            log_audit('gallery.upload', 'gallery', $newId, $title);
            notify_if_published(false, posted('published') === '1', [
                'type' => 'gallery.published',
                'title' => 'New class photo',
                'body' => $title,
                'icon' => 'image',
                'target_type' => 'gallery',
                'target_id' => $newId,
                'url_student' => 'student/gallery.php',
                'url_public' => 'index.php#gallery',
            ]);
            flash_set('success', 'Image uploaded.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/gallery.php');
}

$rows = db()->query('SELECT * FROM gallery ORDER BY id DESC')->fetchAll();
admin_header('Gallery', 'gallery');
admin_page_head('Upload class photographs. Unpublished images stay off the public gallery.');
?>

<?php if (can('gallery.upload')): ?>
<div class="panel">
    <h2>Upload image</h2>
    <form method="post" enctype="multipart/form-data" data-loading>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-group"><label>Title</label><input name="title" required></div>
            <div class="form-group">
                <label>Category</label>
                <select name="category">
                    <?php foreach (gallery_categories() as $key => $label): if ($key === 'all') continue; ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full"><label>Caption</label><input name="caption"></div>
            <div class="form-group"><label>Date</label><input type="date" name="taken_on"></div>
            <div class="form-group"><label>Image</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" required></div>
            <div class="form-group full"><label class="check"><input type="checkbox" name="published" value="1" checked> Published</label></div>
        </div>
        <div class="form-actions" style="margin-top:1rem"><button class="btn" type="submit">Upload image</button></div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No images yet.', 'Upload a class photograph above. Unpublished images stay off the public gallery.'); ?>
<?php else: ?>
<div class="admin-mosaic">
    <?php foreach ($rows as $row): ?>
        <article class="admin-mosaic-card">
            <div class="admin-mosaic-img">
                <img src="<?= e(url($row['image_path'])) ?>" alt="<?= e($row['title']) ?>">
            </div>
            <div class="admin-mosaic-body">
                <div>
                    <strong><?= e($row['title']) ?></strong>
                    <p class="muted"><?= e(status_label((string) $row['category'])) ?><?= !empty($row['taken_on']) ? ' · ' . e(format_date($row['taken_on'])) : '' ?></p>
                </div>
                <?= admin_published_badge($row['published'] ?? 0) ?>
            </div>
            <div class="row-actions">
                <?php if (can('gallery.edit')): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit"><?= !empty($row['published']) ? 'Unpublish' : 'Publish' ?></button></form>
                <?php endif; ?>
                <?php if (can('gallery.delete')): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete image?">Delete</button></form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
