<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

admin_boot(['committee.view', 'committee.manage']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM committee_members WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    require_csrf();
    require_manage_on_post('committee.manage');
    try {
        if (posted('action') === 'delete') {
            $id = request_int('id');
            $stmt = db()->prepare('SELECT photo, name FROM committee_members WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            delete_upload((string) ($row['photo'] ?? ''));
            db()->prepare('DELETE FROM committee_members WHERE id = ?')->execute([$id]);
            log_audit('committee.delete', 'committee', $id, $row['name'] ?? '');
            flash_set('success', 'Member deleted.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $photo = $edit['photo'] ?? null;
            if (!empty($_FILES['photo']['name'])) {
                $upload = store_upload($_FILES['photo'], 'committee');
                if (!$upload['ok']) {
                    throw new InvalidArgumentException($upload['error']);
                }
                delete_upload($photo);
                $photo = $upload['path'];
            }
            $name = posted('name');
            $position = posted('position');
            if ($name === '' || $position === '') {
                throw new InvalidArgumentException('Name and position are required.');
            }
            $visibility = posted_visibility($edit);
            $published = published_flag_for_visibility($visibility);
            $params = [$name, $position, posted('bio'), $photo, (int) posted('display_order'), $published, $visibility];
            if ($id) {
                $params[] = $id;
                db()->prepare('UPDATE committee_members SET name=?, position=?, bio=?, photo=?, display_order=?, published=?, visibility=? WHERE id=?')->execute($params);
                log_audit('committee.save', 'committee', $id, $name);
                flash_set('success', 'Member updated.');
            } else {
                db()->prepare('INSERT INTO committee_members (name, position, bio, photo, display_order, published, visibility) VALUES (?,?,?,?,?,?,?)')->execute($params);
                log_audit('committee.save', 'committee', (int) db()->lastInsertId(), $name);
                flash_set('success', 'Member added.');
            }
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/committee.php');
}

$rows = db()->query('SELECT * FROM committee_members ORDER BY display_order ASC, id ASC')->fetchAll();
admin_header($edit ? 'Edit committee member' : 'Committee', 'committee');
admin_page_head('Committee members stay class-only until you set Public website.');
?>

<?php if (can('committee.manage')): ?>
<div class="panel">
        <h2><?= $edit ? 'Update member' : 'Add member' ?></h2>
    <form method="post" enctype="multipart/form-data" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group"><label>Name</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
            <div class="form-group"><label>Position</label><input name="position" required value="<?= e($edit['position'] ?? '') ?>"></div>
            <div class="form-group full"><label>Short bio</label><textarea name="bio"><?= e($edit['bio'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Display order</label><input type="number" name="display_order" value="<?= e((string) ($edit['display_order'] ?? '0')) ?>"></div>
            <div class="form-group"><label>Photo</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"></div>
            <?= visibility_select($edit) ?>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save member</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/committee.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No committee members yet.', 'Add class officers above. Placeholder names can be replaced at any time.'); ?>
<?php else: ?>
<div class="people-grid">
    <?php foreach ($rows as $row): ?>
        <article class="people-card">
            <?php if (!empty($row['photo'])): ?>
                <span class="stu-avatar"><img src="<?= e(url($row['photo'])) ?>" alt=""></span>
            <?php else: ?>
                <span class="stu-avatar" aria-hidden="true"><?= e(person_initials((string) $row['name'])) ?></span>
            <?php endif; ?>
            <h3><?= e($row['name']) ?></h3>
            <p class="muted"><?= e($row['position']) ?></p>
            <?= admin_visibility_badge($row) ?>
            <?php if (can('committee.manage')): ?>
            <div class="row-actions">
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete member?">Delete</button></form>
            </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
