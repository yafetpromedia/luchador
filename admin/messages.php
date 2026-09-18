<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot(['messages.view', 'messages.manage']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM class_messages WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    require_csrf();
    require_manage_on_post('messages.manage');
    try {
        if (posted('action') === 'delete') {
            $id = request_int('id');
            $stmt = db()->prepare('SELECT author_name FROM class_messages WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            db()->prepare('DELETE FROM class_messages WHERE id = ?')->execute([$id]);
            log_audit('message.delete', 'message', $id, $row['author_name'] ?? '');
            flash_set('success', 'Message deleted.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $body = posted('body');
            $name = posted('author_name');
            if ($name === '' || $body === '') {
                throw new InvalidArgumentException('Author and message are required.');
            }
            $role = posted('author_role');
            if (!array_key_exists($role, message_roles())) {
                $role = 'class';
            }
            $visibility = posted_visibility($edit);
            $published = published_flag_for_visibility($visibility);
            $params = [
                $name,
                $role,
                $body,
                $published,
                $visibility,
                posted('display_order') !== '' ? (int) posted('display_order') : 0,
            ];
            if ($id) {
                $params[] = $id;
                db()->prepare('UPDATE class_messages SET author_name=?, author_role=?, body=?, published=?, visibility=?, display_order=? WHERE id=?')->execute($params);
                log_audit('message.save', 'message', $id, $name);
                flash_set('success', 'Message updated.');
            } else {
                db()->prepare('INSERT INTO class_messages (author_name, author_role, body, published, visibility, display_order) VALUES (?,?,?,?,?,?)')->execute($params);
                log_audit('message.save', 'message', (int) db()->lastInsertId(), $name);
                flash_set('success', 'Message created.');
            }
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/messages.php');
}

$rows = db()->query('SELECT * FROM class_messages ORDER BY display_order ASC, id DESC')->fetchAll();
admin_header($edit ? 'Edit message' : 'Class messages', 'messages');
admin_page_head('Messages stay class-only until you set Public website.');
?>

<?php if (can('messages.manage')): ?>
<div class="panel">
    <h2><?= $edit ? 'Update message' : 'Add message' ?></h2>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group"><label>Author</label><input name="author_name" required value="<?= e($edit['author_name'] ?? '') ?>"></div>
            <div class="form-group">
                <label>Role</label>
                <select name="author_role">
                    <?php foreach (message_roles() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= (($edit['author_role'] ?? 'class') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full"><label>Message</label><textarea name="body" required><?= e($edit['body'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Display order</label><input type="number" name="display_order" value="<?= e((string) ($edit['display_order'] ?? '0')) ?>"></div>
            <?= visibility_select($edit) ?>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save message</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/messages.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No class messages yet.', 'Add a voice from the class when there is something real to say.'); ?>
<?php else: ?>
<div class="quote-grid">
    <?php foreach ($rows as $row): ?>
        <article class="quote-card">
            <p><?= e($row['body']) ?></p>
            <footer>
                <span class="quote-who"><?= e($row['author_name']) ?> · <?= e(status_label((string) $row['author_role'])) ?></span>
                <div class="row-actions">
                    <?= admin_visibility_badge($row) ?>
                    <?php if (can('messages.manage')): ?>
                    <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete message?">Delete</button></form>
                    <?php endif; ?>
                </div>
            </footer>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
