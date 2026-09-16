<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

admin_boot(['memories.view', 'memories.manage']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM memories WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    require_csrf();
    require_manage_on_post('memories.manage');
    try {
        if (posted('action') === 'delete') {
            $id = request_int('id');
            $stmt = db()->prepare('SELECT body FROM memories WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: [];
            db()->prepare('DELETE FROM memories WHERE id = ?')->execute([$id]);
            log_audit('memory.delete', 'memory', $id, $row['body'] ?? '');
            flash_set('success', 'Memory deleted.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $body = posted('body');
            if ($body === '') {
                throw new InvalidArgumentException('Memory text is required.');
            }
            $params = [
                $body,
                posted('attribution') ?: null,
                posted('context') ?: null,
                posted('memory_on') ?: null,
                posted('published') === '1' ? 1 : 0,
            ];
            if ($id) {
                $params[] = $id;
                db()->prepare('UPDATE memories SET body=?, attribution=?, context=?, memory_on=?, published=? WHERE id=?')->execute($params);
                log_audit('memory.save', 'memory', $id, $body);
                flash_set('success', 'Memory updated.');
            } else {
                db()->prepare('INSERT INTO memories (body, attribution, context, memory_on, published) VALUES (?,?,?,?,?)')->execute($params);
                log_audit('memory.save', 'memory', (int) db()->lastInsertId(), $body);
                flash_set('success', 'Memory created.');
            }
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/memories.php');
}

$rows = db()->query('SELECT * FROM memories ORDER BY id DESC')->fetchAll();
admin_header($edit ? 'Edit memory' : 'Memory wall', 'memories');
admin_page_head('Short written memories. Attribution is optional. Do not publish phone numbers or private details.');
?>

<?php if (can('memories.manage')): ?>
<div class="panel">
    <h2><?= $edit ? 'Update memory' : 'Add memory' ?></h2>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group full"><label>Memory</label><textarea name="body" required placeholder="That science fair night was unforgettable."><?= e($edit['body'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Attribution</label><input name="attribution" value="<?= e($edit['attribution'] ?? '') ?>" placeholder="Optional first name or “A classmate”"></div>
            <div class="form-group"><label>Context</label><input name="context" value="<?= e($edit['context'] ?? '') ?>" placeholder="Class trip, optional"></div>
            <div class="form-group"><label>Date</label><input type="date" name="memory_on" value="<?= e($edit['memory_on'] ?? '') ?>"></div>
            <div class="form-group full"><label class="check"><input type="checkbox" name="published" value="1" <?= !empty($edit['published']) ? 'checked' : '' ?>> Published</label></div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Save memory</button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/memories.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <?php admin_empty('No memories yet.', 'Add a short quote. It stays private until published.'); ?>
<?php else: ?>
<div class="quote-grid">
    <?php foreach ($rows as $row): ?>
        <article class="quote-card">
            <p><?= e($row['body']) ?></p>
            <footer>
                <span class="quote-who"><?= e($row['attribution'] ?: 'A classmate') ?><?= !empty($row['context']) ? ' · ' . e($row['context']) : '' ?></span>
                <div class="row-actions">
                    <?= admin_published_badge($row['published'] ?? 0) ?>
                    <?php if (can('memories.manage')): ?>
                    <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="This action cannot be undone." data-confirm-title="Delete memory?">Delete</button></form>
                    <?php endif; ?>
                </div>
            </footer>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
