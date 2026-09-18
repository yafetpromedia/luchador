<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

admin_boot(['announcements.view', 'announcements.create', 'announcements.edit', 'announcements.delete']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM announcements WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    require_csrf();
    require_content_write('announcements.create', 'announcements.edit', 'announcements.delete');
    try {
        if (posted('action') === 'delete') {
            $id = request_int('id');
            $stmt = db()->prepare('SELECT image, title FROM announcements WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            delete_upload((string) ($row['image'] ?? ''));
            db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
            log_audit('announcement.delete', 'announcement', $id, $row['title'] ?? '');
            flash_set('success', 'Announcement deleted.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $image = $edit['image'] ?? null;
            if (!empty($_FILES['image']['name'])) {
                $upload = store_upload($_FILES['image'], 'announcements');
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
            $visibility = posted_visibility($edit);
            $published = published_flag_for_visibility($visibility);
            $params = [$title, posted('description'), posted('announced_on') ?: null, $image, $published, $visibility];
            if ($id) {
                $params[] = $id;
                db()->prepare('UPDATE announcements SET title=?, description=?, announced_on=?, image=?, published=?, visibility=? WHERE id=?')->execute($params);
                log_audit('announcement.save', 'announcement', $id, $title);
                flash_set('success', 'Announcement updated.');
            } else {
                db()->prepare('INSERT INTO announcements (title, description, announced_on, image, published, visibility) VALUES (?,?,?,?,?,?)')->execute($params);
                $id = (int) db()->lastInsertId();
                log_audit('announcement.save', 'announcement', $id, $title);
                flash_set('success', 'Announcement created.');
            }
            notify_if_visibility($edit ? content_visibility_of($edit) : 'draft', $visibility, [
                'type' => 'announcement.published',
                'title' => 'New announcement',
                'body' => $title,
                'icon' => 'megaphone',
                'target_type' => 'announcement',
                'target_id' => (int) $id,
                'url_student' => 'student/announcements.php',
                'url_public' => 'index.php',
            ]);
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/announcements.php');
}

$rows = db()->query('SELECT * FROM announcements ORDER BY id DESC')->fetchAll();
if ($edit && !can('announcements.edit')) {
    $edit = null;
}
$showForm = ($edit && can('announcements.edit')) || (!$edit && can('announcements.create'));
admin_header($edit ? 'Edit announcement' : 'Announcements', 'announcements');
admin_page_head('Class notices stay private until you set Public website.');
?>

<?php if ($showForm): ?>
<div class="panel">
    <h2><?= $edit ? 'Update announcement' : 'Add announcement' ?></h2>
    <form method="post" enctype="multipart/form-data" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group full"><label>Title</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>"></div>
            <div class="form-group full"><label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Date</label><input type="date" name="announced_on" value="<?= e($edit['announced_on'] ?? '') ?>"></div>
            <div class="form-group"><label>Image</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"></div>
            <?= visibility_select($edit) ?>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save announcement</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/announcements.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No announcements yet.', 'Add an announcement above. It stays private until it is published.'); ?>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Title</th><th>Date</th><th>Visibility</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= admin_title_cell((string) $row['title'], $row['image'] ?? null) ?></td>
            <td><?= e(format_date($row['announced_on'])) ?></td>
            <td><?= admin_visibility_badge($row) ?></td>
            <td class="row-actions">
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete announcement?">Delete</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
