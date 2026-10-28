<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['gallery.view', 'gallery.upload', 'gallery.edit', 'gallery.delete']);

$editId = request_int('edit');
$edit = $editId > 0 ? find_gallery_photo($editId) : null;
if ($edit && !can('gallery.edit')) {
    $edit = null;
}

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
            $stmt = db()->prepare('SELECT published, visibility, title FROM gallery WHERE id = ?');
            $stmt->execute([$toggleId]);
            $row = $stmt->fetch() ?: [];
            $was = content_visibility_of($row);
            $next = $was === 'public' ? 'private' : 'public';
            db()->prepare('UPDATE gallery SET visibility = ?, published = ? WHERE id = ?')
                ->execute([$next, published_flag_for_visibility($next), $toggleId]);
            log_audit('gallery.toggle', 'gallery', $toggleId);
            notify_if_visibility($was, $next, [
                'type' => 'gallery.published',
                'title' => 'New class photo',
                'body' => (string) ($row['title'] ?? 'Gallery'),
                'icon' => 'image',
                'target_type' => 'gallery',
                'target_id' => $toggleId,
                'url_student' => 'student/gallery.php',
                'url_public' => 'index.php#gallery',
            ]);
            flash_set('success', $next === 'public' ? 'Photo is now on the public website.' : 'Photo is class-only.');
        } elseif (posted('action') === 'download_for') {
            require_permission('gallery.edit');
            $id = request_int('id');
            $row = find_gallery_photo($id);
            if (!$row) {
                throw new InvalidArgumentException('Photo not found.');
            }
            $next = posted_download_for($row);
            db()->prepare('UPDATE gallery SET download_for = ? WHERE id = ?')->execute([$next, $id]);
            log_audit('gallery.download', 'gallery', $id, $row['title'] ?? '');
            flash_set('success', 'Download access updated.');
        } elseif (posted('action') === 'rotate') {
            require_permission('gallery.edit');
            $id = request_int('id');
            $degrees = (int) posted('degrees');
            if (!in_array($degrees, [90, 180, 270], true)) {
                $degrees = 90;
            }
            if (!gallery_rotate_photo($id, $degrees)) {
                throw new InvalidArgumentException('Could not rotate this photo. Enable PHP GD on the server.');
            }
            log_audit('gallery.rotate', 'gallery', $id);
            flash_set('success', 'Photo rotated.');
            if (request_str('edit') !== '' || posted('stay_edit') === '1') {
                redirect('admin/gallery.php?edit=' . $id);
            }
        } elseif (posted('action') === 'save') {
            require_permission('gallery.edit');
            $id = request_int('id');
            $row = find_gallery_photo($id);
            if (!$row) {
                throw new InvalidArgumentException('Photo not found.');
            }
            $title = posted('title');
            if ($title === '') {
                throw new InvalidArgumentException('Title is required.');
            }
            $category = posted('category');
            if (!array_key_exists($category, gallery_categories()) || $category === 'all') {
                $category = 'class';
            }
            $visibility = posted_visibility($row);
            $published = published_flag_for_visibility($visibility);
            $downloadFor = posted_download_for($row);
            $imagePath = (string) ($row['image_path'] ?? '');
            if (!empty($_FILES['image']['name'])) {
                $upload = store_upload($_FILES['image'], 'gallery');
                if (!$upload['ok']) {
                    throw new InvalidArgumentException($upload['error']);
                }
                delete_upload($imagePath);
                $imagePath = (string) $upload['path'];
            }
            db()->prepare(
                'UPDATE gallery SET title=?, caption=?, image_path=?, category=?, taken_on=?, published=?, visibility=?, download_for=? WHERE id=?'
            )->execute([
                $title,
                posted('caption'),
                $imagePath,
                $category,
                posted('taken_on') ?: null,
                $published,
                $visibility,
                $downloadFor,
                $id,
            ]);
            log_audit('gallery.update', 'gallery', $id, $title);
            notify_if_visibility(content_visibility_of($row), $visibility, [
                'type' => 'gallery.published',
                'title' => 'New class photo',
                'body' => $title,
                'icon' => 'image',
                'target_type' => 'gallery',
                'target_id' => $id,
                'url_student' => 'student/gallery.php',
                'url_public' => 'index.php#gallery',
            ]);
            flash_set('success', 'Photo updated.');
            redirect('admin/gallery.php?edit=' . $id);
        } elseif (posted('action') === 'move_to_event') {
            require_permission('gallery.edit');
            require_permission('events.edit');
            $eventId = (int) posted('event_id');
            $event = find_event_row($eventId);
            if (!$event) {
                throw new InvalidArgumentException('Choose a calendar event.');
            }
            $moved = move_gallery_photos_to_event([request_int('id')], $eventId);
            if ($moved < 1) {
                throw new InvalidArgumentException('Photo not found.');
            }
            log_audit('gallery.move_event', 'event', $eventId, (string) ($event['title'] ?? ''));
            flash_set('success', 'Photo moved into the ' . (string) $event['title'] . ' event album.');
            redirect('admin/events.php?edit=' . $eventId);
        } else {
            require_permission('gallery.upload');
            $files = uploaded_file_list('images');
            if ($files === []) {
                throw new InvalidArgumentException('Select at least one image.');
            }
            $eventId = (int) posted('event_id');
            if ($eventId > 0) {
                require_permission('events.edit');
                $event = find_event_row($eventId);
                if (!$event) {
                    throw new InvalidArgumentException('Choose a calendar event.');
                }
                $album = add_event_photos($eventId, $files);
                if ($album['added'] < 1) {
                    throw new InvalidArgumentException($album['errors'][0] ?? 'No images could be uploaded.');
                }
                log_audit('event.photos', 'event', $eventId, (string) ($event['title'] ?? ''));
                $message = $album['added'] === 1
                    ? '1 photo added to ' . (string) $event['title'] . '.'
                    : $album['added'] . ' photos added to ' . (string) $event['title'] . '.';
                if ($album['errors'] !== []) {
                    $message .= ' Some files could not be uploaded. ' . $album['errors'][0];
                }
                flash_set('success', $message);
                redirect('admin/events.php?edit=' . $eventId);
            }
            $title = posted('title');
            if ($title === '') {
                throw new InvalidArgumentException('Title is required for Gallery photos.');
            }
            $category = posted('category');
            if (!array_key_exists($category, gallery_categories()) || $category === 'all') {
                $category = 'class';
            }
            $visibility = posted_visibility();
            $published = published_flag_for_visibility($visibility);
            $downloadFor = posted_download_for();
            $caption = posted('caption');
            $takenOn = posted('taken_on') ?: null;
            $added = 0;
            $errors = [];
            $firstId = 0;
            $insert = db()->prepare(
                'INSERT INTO gallery (title, caption, image_path, category, taken_on, published, visibility, download_for) VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach ($files as $index => $file) {
                $upload = store_upload($file, 'gallery');
                if (!$upload['ok']) {
                    $errors[] = $upload['error'];
                    continue;
                }
                $photoTitle = $title;
                if (count($files) > 1) {
                    $photoTitle = $title . ' (' . ($index + 1) . ')';
                }
                $insert->execute([
                    $photoTitle,
                    $caption,
                    $upload['path'],
                    $category,
                    $takenOn,
                    $published,
                    $visibility,
                    $downloadFor,
                ]);
                $newId = (int) db()->lastInsertId();
                if ($firstId === 0) {
                    $firstId = $newId;
                }
                log_audit('gallery.upload', 'gallery', $newId, $photoTitle);
                $added++;
            }
            if ($added === 0) {
                throw new InvalidArgumentException($errors[0] ?? 'No images could be uploaded.');
            }
            if ($firstId > 0) {
                notify_if_visibility('draft', $visibility, [
                    'type' => 'gallery.published',
                    'title' => 'New class photo',
                    'body' => $title,
                    'icon' => 'image',
                    'target_type' => 'gallery',
                    'target_id' => $firstId,
                    'url_student' => 'student/gallery.php',
                    'url_public' => 'index.php#gallery',
                ]);
            }
            $message = $added === 1
                ? '1 photo uploaded.'
                : $added . ' photos uploaded.';
            if ($errors !== []) {
                $message .= ' Some files could not be uploaded. ' . $errors[0];
            }
            flash_set('success', $message);
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
        if (posted('action') === 'save' && request_int('id') > 0) {
            redirect('admin/gallery.php?edit=' . request_int('id'));
        }
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/gallery.php');
}

$rows = db()->query('SELECT * FROM gallery ORDER BY id ASC')->fetchAll();
$calendarEvents = can('events.edit') ? event_options_for_album() : [];
admin_header($edit ? 'Edit photo' : 'Gallery', 'gallery');
admin_page_head('Photos stay class-only until you set Public website. For Welcome Day and other class events, add photos to the calendar event album instead of separate Gallery cards.');
?>

<?php if ($edit): ?>
<div class="panel">
    <h2>Edit photo</h2>
    <form method="post" enctype="multipart/form-data" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <div class="form-grid">
            <div class="form-group"><label>Title</label><input name="title" required value="<?= e((string) $edit['title']) ?>"></div>
            <div class="form-group">
                <label>Category</label>
                <select name="category">
                    <?php foreach (gallery_categories() as $key => $label): if ($key === 'all') continue; ?>
                        <option value="<?= e($key) ?>"<?= ((string) ($edit['category'] ?? '') === $key) ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full"><label>Caption</label><input name="caption" value="<?= e((string) ($edit['caption'] ?? '')) ?>"></div>
            <div class="form-group"><label>Date</label><input type="date" name="taken_on" value="<?= e((string) ($edit['taken_on'] ?? '')) ?>"></div>
            <div class="form-group">
                <label>Replace image</label>
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                <p class="muted">Leave empty to keep the current photo. JPG, PNG, WEBP, or GIF up to <?= e(upload_max_label()) ?>.</p>
            </div>
            <?= visibility_select($edit) ?>
            <?= download_for_select($edit) ?>
        </div>
        <div class="admin-mosaic" style="margin-top:1rem;max-width:280px">
            <article class="admin-mosaic-card">
                <div class="admin-mosaic-img">
                    <img src="<?= e(gallery_photo_url((int) $edit['id'], false, 'view')) ?>" alt="<?= e((string) $edit['title']) ?>">
                </div>
            </article>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save photo</button>
            <a class="btn btn-ghost" href="#upload-photos">Add more photos</a>
            <a class="btn btn-ghost" href="<?= e(url('admin/gallery.php')) ?>">Done</a>
        </div>
    </form>
    <form method="post" style="margin-top:0.75rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rotate">
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <input type="hidden" name="stay_edit" value="1">
        <input type="hidden" name="degrees" value="90">
        <button class="btn btn-ghost" type="submit">Rotate 90°</button>
    </form>
</div>
<?php endif; ?>

<?php if (can('gallery.upload')): ?>
<?php
$uploadTitle = $edit ? (string) (preg_replace('/\s+\(\d+\)$/', '', (string) ($edit['title'] ?? '')) ?? '') : '';
$uploadCategory = (string) ($edit['category'] ?? 'class');
$uploadCaption = (string) ($edit['caption'] ?? '');
$uploadTakenOn = (string) ($edit['taken_on'] ?? '');
?>
<div class="panel" id="upload-photos">
    <h2><?= $edit ? 'Upload more photos' : 'Upload photos' ?></h2>
    <p class="muted">Hold Ctrl (or Cmd) and click to select many files at once. To keep Welcome Day (or any class day) together, choose that calendar event below — photos go into the event album instead of separate Gallery cards.</p>
    <form method="post" enctype="multipart/form-data" data-loading>
        <?= csrf_field() ?>
        <div class="form-grid">
            <?php if ($calendarEvents): ?>
            <div class="form-group full">
                <label>Add to calendar event</label>
                <select name="event_id">
                    <option value="0">Stay in Gallery (standalone photos)</option>
                    <?php foreach ($calendarEvents as $ev): ?>
                        <option value="<?= (int) $ev['id'] ?>">
                            <?= e((string) $ev['title']) ?><?= !empty($ev['event_date']) ? ' · ' . e(format_date($ev['event_date'])) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="muted">Pick the event (for example Welcome Day) to merge into that album. Leave as Gallery only for photos that are not tied to one event.</p>
            </div>
            <?php endif; ?>
            <div class="form-group"><label>Title</label><input name="title" placeholder="e.g. Class spirit week" value="<?= e($uploadTitle) ?>"></div>
            <div class="form-group">
                <label>Category</label>
                <select name="category">
                    <?php foreach (gallery_categories() as $key => $label): if ($key === 'all') continue; ?>
                        <option value="<?= e($key) ?>"<?= $uploadCategory === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full"><label>Caption</label><input name="caption" value="<?= e($uploadCaption) ?>"></div>
            <div class="form-group"><label>Date</label><input type="date" name="taken_on" value="<?= e($uploadTakenOn) ?>"></div>
            <div class="form-group full">
                <label>Images</label>
                <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
                <p class="muted">Select many at once — 24 or more is fine. Title/category/visibility apply only when staying in Gallery. JPG, PNG, WEBP, or GIF up to <?= e(upload_max_label()) ?> each.</p>
            </div>
            <?= visibility_select($edit) ?>
            <?= download_for_select($edit) ?>
        </div>
        <div class="form-actions" style="margin-top:1rem"><button class="btn" type="submit">Upload photos</button></div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No images yet.', 'Upload class photographs above. New photos stay class-only until you choose Public website.'); ?>
<?php else: ?>
<div class="admin-mosaic">
    <?php foreach ($rows as $row): ?>
        <article class="admin-mosaic-card">
            <div class="admin-mosaic-img">
                <img src="<?= e(gallery_photo_url((int) $row['id'], false, 'thumb')) ?>" alt="<?= e($row['title']) ?>" loading="lazy" decoding="async">
            </div>
            <div class="admin-mosaic-body">
                <div>
                    <strong><?= e($row['title']) ?></strong>
                    <p class="muted"><?= e(status_label((string) $row['category'])) ?><?= !empty($row['taken_on']) ? ' · ' . e(format_date($row['taken_on'])) : '' ?></p>
                </div>
                <?= admin_visibility_badge($row) ?>
            </div>
            <?php if (can('gallery.edit')): ?>
            <form method="post" class="mosaic-policy">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="download_for">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <label>Who can download</label>
                <select name="download_for" data-autosubmit>
                    <?php foreach (gallery_download_audiences() as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= gallery_download_for($row) === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php else: ?>
            <p class="muted mosaic-policy">Download: <?= e(gallery_download_label($row)) ?></p>
            <?php endif; ?>
            <?php if ($calendarEvents): ?>
            <form method="post" class="mosaic-policy">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move_to_event">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <label>Move to event album</label>
                <div class="row-actions" style="margin:0;align-items:center">
                    <select name="event_id" required>
                        <option value="">Choose event…</option>
                        <?php foreach ($calendarEvents as $ev): ?>
                            <option value="<?= (int) $ev['id'] ?>"><?= e((string) $ev['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm" type="submit">Move</button>
                </div>
            </form>
            <?php endif; ?>
            <div class="row-actions">
                <?php if (can('gallery.edit')): ?>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/gallery.php?edit=' . (int) $row['id'])) ?>">Edit</a>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rotate"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="degrees" value="90"><button class="btn btn-sm btn-ghost" type="submit">Rotate 90°</button></form>
                <?php endif; ?>
                <a class="btn btn-sm btn-ghost" href="<?= e(gallery_photo_url((int) $row['id'], true)) ?>"><?= icon('download', 16) ?> Download</a>
                <?php if (can('gallery.edit')): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit"><?= content_visibility_of($row) === 'public' ? 'Make class only' : 'Publish to website' ?></button></form>
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
