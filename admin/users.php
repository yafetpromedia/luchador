<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot('users.manage');

$edit = null;
$view = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
$viewId = request_int('view');
if ($editId) {
    $edit = fetch_user_by_id($editId);
}
if ($viewId) {
    $view = fetch_user_by_id($viewId);
}
if ($edit && ($edit['role'] ?? '') === 'student') {
    redirect('admin/student-accounts.php');
}
if ($view && ($view['role'] ?? '') === 'student') {
    redirect('admin/student-accounts.php');
}

if (is_post()) {
    require_csrf();
    try {
        $id = posted('id') !== '' ? (int) posted('id') : 0;
        $target = $id ? fetch_user_by_id($id) : null;
        if ($id && !$target) {
            throw new InvalidArgumentException('User not found.');
        }
        $action = posted('action');

        if ($action === 'clear_credentials') {
            clear_one_time_credentials();
            flash_set('success', 'Temporary credentials were cleared from this screen.');
            redirect('admin/users.php');
        }

        if ($action === 'deactivate' || $action === 'activate') {
            if (!$target) {
                throw new InvalidArgumentException('User not found.');
            }
            $active = $action === 'activate';
            if ((int) $target['id'] === (int) current_user()['id'] && !$active) {
                throw new InvalidArgumentException('You cannot disable your own account.');
            }
            if (!$active && $target['role'] === 'super_admin' && is_last_active_super_admin((int) $target['id'])) {
                throw new InvalidArgumentException('The last Super Admin cannot be disabled.');
            }
            db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, (int) $target['id']]);
            log_audit($active ? 'user.enable' : 'user.disable', 'user', (int) $target['id'], $target['full_name'] ?: $target['username']);
            flash_set('success', $active ? 'Account activated.' : 'Account disabled.');
            redirect('admin/users.php');
        }

        if ($action === 'reset_password') {
            if (!$target) {
                throw new InvalidArgumentException('User not found.');
            }
            $temp = generate_temp_password(12);
            db()->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?')
                ->execute([password_hash($temp, PASSWORD_DEFAULT), (int) $target['id']]);
            log_audit('user.password_reset', 'user', (int) $target['id'], $target['full_name'] ?: $target['username']);
            set_one_time_credentials([[
                'username' => $target['username'],
                'full_name' => $target['full_name'] ?: $target['username'],
                'student_code' => '',
                'password' => $temp,
            ]], 'Temporary password generated. Copy it now — it will not be stored.');
            flash_set('success', 'Password reset. The temporary password is shown once below.');
            redirect('admin/users.php?edit=' . (int) $target['id']);
        }

        $fullName = posted('full_name');
        $username = posted('username');
        $email = posted('email');
        $role = posted('role');
        $active = posted('is_active') === '1' || !$target;
        $password = (string) ($_POST['password'] ?? '');

        if ($fullName === '' || $username === '') {
            throw new InvalidArgumentException('Full name and username are required.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            throw new InvalidArgumentException('Usernames must be 3–50 characters: letters, numbers, dots, underscores, or hyphens.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email, or leave it blank.');
        }
        if (!array_key_exists($role, assignable_staff_roles())) {
            $role = 'committee';
        }
        if ($role === 'student') {
            throw new InvalidArgumentException('Create student logins from Student accounts.');
        }
        if ($role === 'super_admin' && !is_super_admin()) {
            throw new InvalidArgumentException('Only a Super Admin can assign that role.');
        }
        if ($target && $target['role'] === 'super_admin' && $role !== 'super_admin' && is_last_active_super_admin((int) $target['id'])) {
            throw new InvalidArgumentException('The last Super Admin cannot be demoted.');
        }
        if ($target && (int) $target['id'] === (int) current_user()['id'] && !$active) {
            throw new InvalidArgumentException('You cannot disable your own account.');
        }
        if ($target && $target['role'] === 'super_admin' && !$active && is_last_active_super_admin((int) $target['id'])) {
            throw new InvalidArgumentException('The last Super Admin cannot be disabled.');
        }

        if (username_taken($username, $id)) {
            throw new InvalidArgumentException('That username is already in use.');
        }
        if ($target && ($target['role'] ?? '') === 'student') {
            throw new InvalidArgumentException('Student logins are managed on Student accounts.');
        }
        if ($email !== '') {
            $dupEmail = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $dupEmail->execute([$email, $id]);
            if ($dupEmail->fetch()) {
                throw new InvalidArgumentException('That email is already in use.');
            }
        }

        $permissions = sanitize_permission_list(is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [], $role);

        if ($id) {
            db()->prepare('UPDATE users SET full_name=?, username=?, email=?, role=?, is_active=?, student_id=NULL WHERE id=?')
                ->execute([
                    $fullName,
                    $username,
                    $email !== '' ? $email : null,
                    $role,
                    $active ? 1 : 0,
                    $id,
                ]);
            if ($password !== '') {
                if (strlen($password) < password_min_length()) {
                    throw new InvalidArgumentException('Temporary passwords must be at least ' . password_min_length() . ' characters.');
                }
                db()->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            save_user_permissions($id, $role === 'committee' ? $permissions : []);
            log_audit('user.update', 'user', $id, $fullName);
            flash_set('success', 'User updated.');
        } else {
            if ($password === '') {
                $password = generate_temp_password(12);
                set_one_time_credentials([[
                    'username' => $username,
                    'full_name' => $fullName,
                    'student_code' => '',
                    'password' => $password,
                ]], 'Account created. Copy the temporary password now — it will not be stored.');
            } elseif (strlen($password) < password_min_length()) {
                throw new InvalidArgumentException('Temporary passwords must be at least ' . password_min_length() . ' characters.');
            }
            db()->prepare('INSERT INTO users (username, password, must_change_password, full_name, email, role, is_active, student_id) VALUES (?,?,1,?,?,?,?,NULL)')
                ->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $fullName,
                    $email !== '' ? $email : null,
                    $role,
                    1,
                ]);
            $newId = (int) db()->lastInsertId();
            save_user_permissions($newId, $role === 'committee' ? $permissions : []);
            log_audit('user.create', 'user', $newId, $fullName);
            flash_set('success', 'Account created. They must change the temporary password on first sign-in.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/users.php' . ($editId ? '?edit=' . $editId : ''));
}

$q = request_str('q');
$roleFilter = request_str('role');
$statusFilter = request_str('status');
$sql = 'SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.last_login_at, u.created_at, u.must_change_password
        FROM users u
        WHERE u.role != \'student\'';
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.full_name LIKE ? OR u.username LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like);
}
if ($roleFilter !== '' && array_key_exists($roleFilter, staff_roles())) {
    $sql .= ' AND u.role = ?';
    $params[] = $roleFilter;
}
if ($statusFilter === 'active') {
    $sql .= ' AND u.is_active = 1';
} elseif ($statusFilter === 'disabled') {
    $sql .= ' AND u.is_active = 0';
}
$sql .= ' ORDER BY u.role ASC, u.full_name ASC, u.username ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$editPerms = $edit && $edit['role'] === 'committee'
    ? user_permissions_for((int) $edit['id'], 'committee')
    : default_committee_permissions();
$credentials = one_time_credentials();

admin_header($edit ? 'Edit committee login' : ($view ? 'Committee login' : 'Committee logins'), 'users');
admin_page_head(
    'Create committee and Super Admin logins here. Student logins are created separately: pick a student on Student accounts, then print their slip.',
    [
        '<a class="btn" href="' . e(url('admin/student-accounts.php')) . '">Student accounts</a>',
        '<a class="btn btn-ghost" href="' . e(url('admin/roles.php')) . '">Roles</a>',
    ]
);
?>

<?php if ($credentials): ?>
    <div class="security-callout" role="status">
        <h2>Temporary credentials</h2>
        <p><?= e((string) ($credentials['notice'] ?? 'Copy these now. They are not stored.')) ?></p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Username</th><th>Temporary password</th></tr></thead>
                <tbody>
                <?php foreach ($credentials['rows'] as $row): ?>
                    <tr>
                        <td><?= e($row['full_name'] ?? '') ?></td>
                        <td><?= e($row['username'] ?? '') ?></td>
                        <td><code><?= e($row['password'] ?? '') ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" style="margin-top:0.8rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_credentials">
            <button class="btn btn-ghost" type="submit">I have copied these</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($view && !$edit): ?>
<div class="panel">
    <h2><?= e($view['full_name'] ?: $view['username']) ?></h2>
    <dl class="profile-list">
        <div><dt>Username</dt><dd><?= e($view['username']) ?></dd></div>
        <div><dt>Role</dt><dd><?= e(role_label((string) $view['role'])) ?></dd></div>
        <div><dt>Status</dt><dd><?= admin_active_badge($view['is_active'] ?? 0) ?></dd></div>
        <div><dt>Last login</dt><dd><?= $view['last_login_at'] ? e(format_when($view['last_login_at'])) : 'Never' ?></dd></div>
    </dl>
    <div class="form-actions" style="margin-top:1rem">
        <a class="btn" href="?edit=<?= (int) $view['id'] ?>">Edit</a>
        <a class="btn btn-ghost" href="<?= e(url('admin/users.php')) ?>">Back</a>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <h2><?= $edit ? 'Update committee login' : 'Add committee login' ?></h2>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group"><label class="req">Full name</label><input name="full_name" required value="<?= e($edit['full_name'] ?? '') ?>"></div>
            <div class="form-group"><label class="req">Username</label><input name="username" required value="<?= e($edit['username'] ?? '') ?>" autocomplete="off"></div>
            <div class="form-group"><label>Email</label><input name="email" type="email" value="<?= e($edit['email'] ?? '') ?>" placeholder="Optional"></div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="user-role">
                    <?php foreach (assignable_staff_roles() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= (($edit['role'] ?? 'committee') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><?= $edit ? 'New temporary password' : 'Temporary password' ?></label>
                <input name="password" type="password" minlength="<?= (int) password_min_length() ?>" autocomplete="new-password">
                <p class="muted"><?= $edit ? 'Leave blank to keep the current password.' : 'Leave blank to generate a secure temporary password.' ?></p>
            </div>
            <?php if ($edit): ?>
            <div class="form-group">
                <label class="check"><input type="checkbox" name="is_active" value="1" <?= !empty($edit['is_active']) ? 'checked' : '' ?>> Active</label>
            </div>
            <?php endif; ?>
        </div>

        <div id="permission-fields" class="perm-wrap">
            <h3>Permissions</h3>
            <p class="muted">Committee members start with no management access. Assign only what they need. Super Admin and Student roles use fixed permissions.</p>
            <?php foreach (grantable_permission_catalog() as $group => $perms): ?>
                <details class="perm-group" open>
                    <summary><?= e($group) ?></summary>
                    <div class="perm-list">
                        <?php foreach ($perms as $key => $label): ?>
                            <label class="check">
                                <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"
                                    <?= in_array($key, $editPerms, true) ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit"><?= $edit ? 'Save login' : 'Create login' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/users.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php if ($edit): ?>
        <form method="post" style="margin-top:0.75rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
            <button class="btn btn-ghost" type="submit">Generate temporary password</button>
        </form>
    <?php endif; ?>
</div>

<form class="toolbar panel" method="get">
    <div class="form-group"><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Name or username"></div>
    <div class="form-group">
        <label>Role</label>
        <select name="role">
            <option value="">All roles</option>
            <?php foreach (staff_roles() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $roleFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Status</label>
        <select name="status">
            <option value="">All</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
        </select>
    </div>
    <div class="form-group"><label>&nbsp;</label><button class="btn" type="submit">Filter</button></div>
</form>

<div class="table-wrap user-table">
<table>
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
    <tbody>
    <?php if (!$users): ?>
        <tr><td colspan="6">No committee logins match these filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($users as $row): ?>
        <tr>
            <td><?= e($row['full_name'] ?: $row['username']) ?></td>
            <td><?= e($row['username']) ?></td>
            <td><?= e(role_label((string) $row['role'])) ?></td>
            <td><?= admin_active_badge($row['is_active'] ?? 0) ?></td>
            <td><?= $row['last_login_at'] ? e(format_when($row['last_login_at'])) : 'Never' ?></td>
            <td class="row-actions">
                <a class="btn btn-sm btn-ghost" href="?view=<?= (int) $row['id'] ?>">View</a>
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                <?php if ((int) $row['id'] !== (int) current_user()['id']): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= !empty($row['is_active']) ? 'deactivate' : 'activate' ?>">
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                        <?php if (!empty($row['is_active'])): ?>
                            <button class="btn btn-sm btn-danger" data-confirm="They will lose access immediately. Student records are not affected." data-confirm-title="Disable this account?">Disable</button>
                        <?php else: ?>
                            <button class="btn btn-sm" type="submit">Enable</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="user-card-list">
    <?php foreach ($users as $row): ?>
        <article class="panel user-card">
            <h2><?= e($row['full_name'] ?: $row['username']) ?></h2>
            <p class="muted"><?= e($row['username']) ?> · <?= e(role_label((string) $row['role'])) ?></p>
            <p><?= admin_active_badge($row['is_active'] ?? 0) ?> · <?= $row['last_login_at'] ? e(format_when($row['last_login_at'])) : 'Never signed in' ?></p>
            <div class="form-actions">
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<script>
(function () {
    const role = document.getElementById('user-role');
    const fields = document.getElementById('permission-fields');
    const sync = () => {
        const value = role ? role.value : 'committee';
        if (fields) fields.hidden = value !== 'committee';
    };
    if (role) role.addEventListener('change', sync);
    sync();
})();
</script>
<?php admin_footer(); ?>
