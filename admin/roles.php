<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

admin_boot('roles.manage');

$editSlug = request_str('role') ?: 'committee';
$editRole = fetch_role_by_slug($editSlug);
if (!$editRole) {
    $editRole = fetch_role_by_slug('committee');
    $editSlug = 'committee';
}

if (is_post()) {
    require_csrf();
    try {
        $action = posted('action');
        if ($action === 'create') {
            $name = posted('name');
            $description = posted('description');
            if ($name === '') {
                throw new InvalidArgumentException('Role name is required.');
            }
            $slug = slugify_role_name($name);
            if (in_array($slug, system_role_slugs(), true)) {
                throw new InvalidArgumentException('That name is reserved.');
            }
            db()->prepare('INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 0)')
                ->execute([$slug, $name, $description !== '' ? $description : null]);
            $roleId = (int) db()->lastInsertId();
            $permissions = sanitize_permission_list(is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [], 'custom');
            save_role_permissions($roleId, $permissions);
            log_audit('role.create', 'role', $roleId, $name);
            flash_set('success', 'Role created. Assign it from Users.');
            redirect('admin/roles.php?role=' . urlencode($slug));
        }

        if ($action === 'delete') {
            $slug = posted('slug');
            $role = fetch_role_by_slug($slug);
            if (!$role) {
                throw new InvalidArgumentException('Role not found.');
            }
            if (!empty($role['is_system']) || in_array($slug, system_role_slugs(), true)) {
                throw new InvalidArgumentException('System roles cannot be deleted.');
            }
            $countStmt = db()->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
            $countStmt->execute([$slug]);
            if ((int) $countStmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('This role is assigned to users. Reassign them first.');
            }
            db()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([(int) $role['id']]);
            db()->prepare('DELETE FROM roles WHERE id = ?')->execute([(int) $role['id']]);
            log_audit('role.delete', 'role', (int) $role['id'], $role['name']);
            flash_set('success', 'Role deleted.');
            redirect('admin/roles.php');
        }

        $slug = posted('slug');
        $role = fetch_role_by_slug($slug);
        if (!$role) {
            throw new InvalidArgumentException('Role not found.');
        }
        if ($slug === 'super_admin' || $slug === 'student') {
            throw new InvalidArgumentException('This role’s permissions are fixed.');
        }
        $permissions = sanitize_permission_list(is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [], $slug === 'committee' ? 'committee' : 'custom');
        save_role_permissions((int) $role['id'], $permissions);
        log_audit('role.update', 'role', (int) $role['id'], $role['name']);
        flash_set('success', $slug === 'committee'
            ? 'Committee defaults updated. Existing members keep the permissions assigned on their user record.'
            : 'Role permissions updated.');
        redirect('admin/roles.php?role=' . urlencode($slug));
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save role changes.');
    }
    redirect('admin/roles.php?role=' . urlencode($editSlug));
}

$roles = db()->query('SELECT * FROM roles ORDER BY is_system DESC, name ASC')->fetchAll();
$assigned = permissions_for_role_slug($editSlug);
if ($editSlug === 'super_admin') {
    $assigned = all_permissions();
} elseif ($editSlug === 'student') {
    $assigned = student_permissions();
} elseif ($editSlug === 'committee' && !$assigned) {
    $assigned = default_committee_permissions();
}
$locked = in_array($editSlug, ['super_admin', 'student'], true);

admin_header('Roles & permissions', 'roles');
admin_page_head('Super Admin has full access. Committee members receive permissions individually. Students use a fixed portal role.');
?>

<div class="two-col">
    <section class="panel">
        <h2>Roles</h2>
        <ul class="role-list">
            <?php foreach ($roles as $row): ?>
                <li>
                    <a href="?role=<?= e($row['slug']) ?>" <?= $editSlug === $row['slug'] ? 'aria-current="page"' : '' ?>>
                        <strong><?= e($row['name']) ?></strong>
                        <span class="muted"><?= e($row['description'] ?: $row['slug']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="panel">
        <h2><?= e($editRole['name'] ?? role_label($editSlug)) ?></h2>
        <p class="muted"><?= e($editRole['description'] ?? '') ?></p>
        <?php if ($locked): ?>
            <p class="muted">This system role cannot be changed.</p>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="slug" value="<?= e($editSlug) ?>">
            <div class="perm-wrap">
                <?php foreach (grantable_permission_catalog() as $group => $perms): ?>
                    <details class="perm-group" open>
                        <summary><?= e($group) ?></summary>
                        <div class="perm-list">
                            <?php foreach ($perms as $key => $label): ?>
                                <label class="check">
                                    <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"
                                        <?= in_array($key, $assigned, true) ? 'checked' : '' ?>
                                        <?= $locked ? 'disabled' : '' ?>>
                                    <?= e($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <?php if (!$locked): ?>
                <div class="form-actions" style="margin-top:1rem">
                    <button class="btn" type="submit">Save permissions</button>
                    <?php if (empty($editRole['is_system'])): ?>
                        <button class="btn btn-danger" name="action" value="delete" data-confirm="Users with this role must be reassigned first." data-confirm-title="Delete this role?">Delete role</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </section>
</div>

<div class="panel">
    <h2>Create a custom role</h2>
    <p class="muted">Use this for a reusable permission set. Super Admin privileges cannot be included.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="form-group"><label class="req">Name</label><input name="name" required placeholder="Events team"></div>
            <div class="form-group full"><label>Description</label><input name="description" placeholder="Optional"></div>
        </div>
        <div class="perm-wrap">
            <?php foreach (grantable_permission_catalog() as $group => $perms): ?>
                <details class="perm-group">
                    <summary><?= e($group) ?></summary>
                    <div class="perm-list">
                        <?php foreach ($perms as $key => $label): ?>
                            <label class="check">
                                <input type="checkbox" name="permissions[]" value="<?= e($key) ?>">
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Create role</button>
        </div>
    </form>
</div>
<?php admin_footer(); ?>
