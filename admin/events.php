<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['events.view', 'events.create', 'events.edit', 'events.delete']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    require_csrf();
    require_content_write('events.create', 'events.edit', 'events.delete');
    try {
        if (posted('action') === 'delete') {
            $id = request_int('id');
            $stmt = db()->prepare('SELECT cover_image, title FROM events WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            delete_upload((string) ($row['cover_image'] ?? ''));
            db()->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
            log_audit('event.delete', 'event', $id, $row['title'] ?? '');
            flash_set('success', 'Event deleted.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $cover = $edit['cover_image'] ?? null;
            if (!empty($_FILES['cover_image']['name'])) {
                $upload = store_upload($_FILES['cover_image'], 'events');
                if (!$upload['ok']) {
                    throw new InvalidArgumentException($upload['error']);
                }
                delete_upload($cover);
                $cover = $upload['path'];
            }
            $title = posted('title');
            if ($title === '') {
                throw new InvalidArgumentException('Title is required.');
            }
            $category = posted('category');
            if (!array_key_exists($category, event_categories())) {
                $category = 'class';
            }
            $params = [
                $title,
                posted('description'),
                posted('event_date') ?: null,
                posted('event_time') ?: null,
                posted('location') ?: null,
                $cover,
                in_array(posted('status'), ['upcoming','ongoing','completed','cancelled'], true) ? posted('status') : 'upcoming',
                posted('published') === '1' ? 1 : 0,
                $category,
            ];
            if ($id) {
                $params[] = $id;
                db()->prepare('UPDATE events SET title=?, description=?, event_date=?, event_time=?, location=?, cover_image=?, status=?, published=?, category=? WHERE id=?')->execute($params);
                log_audit('event.save', 'event', $id, $title);
                flash_set('success', 'Event updated.');
            } else {
                db()->prepare('INSERT INTO events (title, description, event_date, event_time, location, cover_image, status, published, category) VALUES (?,?,?,?,?,?,?,?,?)')->execute($params);
                $id = (int) db()->lastInsertId();
                log_audit('event.save', 'event', $id, $title);
                flash_set('success', 'Event created.');
            }
            notify_if_published((int) ($edit['published'] ?? 0) === 1, posted('published') === '1', [
                'type' => 'event.published',
                'title' => 'New class event',
                'body' => $title,
                'icon' => 'calendar',
                'target_type' => 'event',
                'target_id' => (int) $id,
                'url_student' => 'student/event.php?id=' . (int) $id,
                'url_public' => 'index.php#events',
            ]);
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/events.php');
}

$rows = db()->query('SELECT * FROM events ORDER BY (event_date IS NULL), event_date DESC, id DESC')->fetchAll();
if ($edit && !can('events.edit')) {
    $edit = null;
}
$showEventForm = ($edit && can('events.edit')) || (!$edit && can('events.create'));
admin_header($edit ? 'Edit event' : 'Calendar', 'events');
admin_page_head('Publish class dates. Unpublished events stay off the public calendar.');
?>

<?php if ($showEventForm): ?>
<div class="panel">
    <h2><?= $edit ? 'Update event' : 'Add event' ?></h2>
    <form method="post" enctype="multipart/form-data" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group full"><label>Title</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>"></div>
            <div class="form-group full"><label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Date</label><input type="date" name="event_date" value="<?= e($edit['event_date'] ?? '') ?>"></div>
            <div class="form-group"><label>Time</label><input name="event_time" value="<?= e($edit['event_time'] ?? '') ?>" placeholder="Optional"></div>
            <div class="form-group"><label>Location</label><input name="location" value="<?= e($edit['location'] ?? '') ?>"></div>
            <div class="form-group">
                <label>Category</label>
                <select name="category">
                    <?php foreach (event_categories() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= (($edit['category'] ?? 'class') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <?php foreach (['upcoming','ongoing','completed','cancelled'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= (($edit['status'] ?? 'upcoming') === $st) ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full"><label>Cover image</label><input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp,image/gif"></div>
            <div class="form-group full"><label class="check"><input type="checkbox" name="published" value="1" <?= !empty($edit['published']) ? 'checked' : '' ?>> Published</label></div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save event</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/events.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No events yet.', 'Add an event above. It stays private until it is published.'); ?>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr><th>Title</th><th>Date</th><th>Category</th><th>Status</th><th>Published</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= admin_title_cell((string) $row['title'], $row['cover_image'] ?? null) ?></td>
            <td><?= e(format_date($row['event_date'])) ?></td>
            <td><?= e(status_label((string) ($row['category'] ?? 'class'))) ?></td>
            <td><?= admin_status_badge((string) $row['status']) ?></td>
            <td><?= admin_published_badge($row['published'] ?? 0) ?></td>
            <td class="row-actions">
                <?php if (can('events.edit')): ?><a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a><?php endif; ?>
                <?php if (can('events.delete')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete event?">Delete</button></form><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
