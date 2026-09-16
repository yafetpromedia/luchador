<?php

declare(strict_types=1);

function permission_catalog(): array
{
    return [
        'Overview' => [
            'dashboard.view' => 'View dashboard',
        ],
        'Students' => [
            'students.view' => 'View students',
            'students.create' => 'Add students',
            'students.edit' => 'Edit students',
            'students.delete' => 'Delete students',
            'students.import' => 'Import students',
        ],
        'Uniforms' => [
            'uniforms.view' => 'View uniforms',
            'uniforms.edit' => 'Update uniform status',
        ],
        'Payments' => [
            'payments.view' => 'View payments',
            'payments.manage' => 'Publish accounts and approve payments',
        ],
        'Calendar' => [
            'events.view' => 'View events',
            'events.create' => 'Create events',
            'events.edit' => 'Edit events',
            'events.delete' => 'Delete events',
        ],
        'Announcements' => [
            'announcements.view' => 'View announcements',
            'announcements.create' => 'Create announcements',
            'announcements.edit' => 'Edit announcements',
            'announcements.delete' => 'Delete announcements',
        ],
        'Gallery' => [
            'gallery.view' => 'View gallery',
            'gallery.upload' => 'Upload photos',
            'gallery.edit' => 'Change photo visibility',
            'gallery.delete' => 'Delete photos',
        ],
        'Class content' => [
            'timeline.view' => 'View journey',
            'timeline.manage' => 'Manage journey',
            'memories.view' => 'View memory wall',
            'memories.manage' => 'Manage memory wall',
            'spotlights.view' => 'View spotlight',
            'spotlights.manage' => 'Manage spotlight',
            'messages.view' => 'View class messages',
            'messages.manage' => 'Manage class messages',
            'achievements.view' => 'View achievements',
            'achievements.manage' => 'Manage achievements',
            'committee.view' => 'View committee',
            'committee.manage' => 'Manage committee',
            'graduation.view' => 'View graduation',
            'graduation.manage' => 'Manage graduation',
        ],
        'Interactions' => [
            'polls.view' => 'View polls',
            'polls.create' => 'Create polls',
            'polls.edit' => 'Edit polls',
            'polls.delete' => 'Delete polls',
            'polls.close' => 'Open or close polls',
            'polls.results' => 'View poll results and voters',
            'questions.view' => 'View questions',
            'questions.create' => 'Create class questions',
            'questions.moderate' => 'Moderate responses and anonymous asks',
            'questions.delete' => 'Delete questions',
            'questions.close' => 'Open or close questions',
        ],
        'Management' => [
            'reports.view' => 'View reports',
            'reports.export' => 'Export reports',
        ],
        'System' => [
            'activity.view' => 'View activity log',
            'settings.manage' => 'Manage class settings',
            'users.manage' => 'Manage users',
            'roles.manage' => 'Manage roles and permissions',
        ],
    ];
}

function student_permission_catalog(): array
{
    return [
        'Student portal' => [
            'students.view_own' => 'View own student record',
            'profile.view_own' => 'View own profile',
            'profile.edit_own' => 'Edit own profile',
            'payment.submit_own' => 'Submit payment proof',
            'password.change' => 'Change own password',
            'polls.view' => 'View class polls',
            'polls.vote' => 'Vote in class polls',
            'questions.view' => 'View class questions',
            'questions.respond' => 'Answer class questions',
            'questions.ask' => 'Ask the committee or send a suggestion',
        ],
    ];
}

function all_permissions(): array
{
    $keys = [];
    foreach (permission_catalog() as $group) {
        foreach ($group as $key => $_label) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function student_permissions(): array
{
    return [
        'dashboard.view',
        'students.view_own',
        'profile.view_own',
        'events.view',
        'announcements.view',
        'gallery.view',
        'achievements.view',
        'graduation.view',
        'timeline.view',
        'memories.view',
        'spotlights.view',
        'messages.view',
        'password.change',
        'profile.edit_own',
        'payment.submit_own',
        'polls.view',
        'polls.vote',
        'questions.view',
        'questions.respond',
        'questions.ask',
    ];
}

function super_admin_only_permissions(): array
{
    return [
        'settings.manage',
        'users.manage',
        'roles.manage',
        'activity.view',
    ];
}

function default_committee_permissions(): array
{
    return ['dashboard.view'];
}

function grantable_permissions(): array
{
    return array_values(array_diff(all_permissions(), super_admin_only_permissions()));
}

function grantable_permission_catalog(): array
{
    $blocked = array_flip(super_admin_only_permissions());
    $out = [];
    foreach (permission_catalog() as $group => $perms) {
        $keep = array_diff_key($perms, $blocked);
        if ($keep) {
            $out[$group] = $keep;
        }
    }
    return $out;
}

function user_roles(): array
{
    $roles = [
        'super_admin' => 'Super Admin',
        'committee' => 'Committee Member',
        'student' => 'Student',
    ];
    try {
        if (table_exists(db(), 'roles')) {
            $rows = db()->query('SELECT slug, name FROM roles ORDER BY is_system DESC, name ASC')->fetchAll();
            if ($rows) {
                $roles = [];
                foreach ($rows as $row) {
                    $roles[(string) $row['slug']] = (string) $row['name'];
                }
            }
        }
    } catch (Throwable $e) {
        // keep fallback
    }
    return $roles;
}

function system_role_slugs(): array
{
    return ['super_admin', 'committee', 'student'];
}

function role_label(string $role): string
{
    return user_roles()[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

function is_super_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user !== null && ($user['role'] ?? '') === 'super_admin';
}

function is_student(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user !== null && ($user['role'] ?? '') === 'student';
}

function is_committee(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user !== null && ($user['role'] ?? '') === 'committee';
}

function user_display_name(?array $user = null): string
{
    $user = $user ?? current_user();
    if ($user === null) {
        return 'system';
    }
    $name = trim((string) ($user['full_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return (string) ($user['username'] ?? 'Someone');
}

function permissions_for_role_slug(string $slug): array
{
    if (!table_exists(db(), 'roles') || !table_exists(db(), 'role_permissions')) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT p.name
         FROM role_permissions rp
         INNER JOIN roles r ON r.id = rp.role_id
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE r.slug = ?'
    );
    $stmt->execute([$slug]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function user_permissions_for(int $userId, string $role = 'committee'): array
{
    if ($role === 'super_admin') {
        return all_permissions();
    }
    if ($role === 'student') {
        return student_permissions();
    }
    if ($role === 'committee') {
        $assigned = [];
        if (table_exists(db(), 'user_permissions')) {
            $stmt = db()->prepare('SELECT permission FROM user_permissions WHERE user_id = ?');
            $stmt->execute([$userId]);
            $assigned = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        $assigned = array_values(array_diff($assigned, super_admin_only_permissions()));
        if (!in_array('dashboard.view', $assigned, true)) {
            $assigned[] = 'dashboard.view';
        }
        return array_values(array_unique($assigned));
    }
    $fromRole = array_values(array_diff(permissions_for_role_slug($role), super_admin_only_permissions()));
    if (!in_array('dashboard.view', $fromRole, true)) {
        $fromRole[] = 'dashboard.view';
    }
    return $fromRole;
}

function can(string $permission): bool
{
    $user = current_user();
    if ($user === null || empty($user['is_active'])) {
        return false;
    }
    if (($user['role'] ?? '') === 'super_admin') {
        return in_array($permission, all_permissions(), true)
            || in_array($permission, student_permissions(), true);
    }
    return in_array($permission, $user['permissions'] ?? [], true);
}

function can_any(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (can($permission)) {
            return true;
        }
    }
    return false;
}

function deny_access(string $message = 'You do not have permission to access this area.'): void
{
    if (wants_json() || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        json_response(['error' => $message], 403);
    }
    http_response_code(403);
    $home = 'login.php';
    $label = 'Sign in';
    if (is_logged_in()) {
        $home = is_student() ? 'student/dashboard.php' : 'admin/index.php';
        $label = 'Return to Dashboard';
    }
    $forbiddenMessage = $message;
    $forbiddenHome = url($home);
    $forbiddenLabel = $label;
    require APP_ROOT . '/includes/page-403.php';
    exit;
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        deny_access();
    }
}

function require_permission_any(array $permissions): void
{
    if (!can_any($permissions)) {
        deny_access();
    }
}

function require_content_write(string $create, string $edit, string $delete): void
{
    if (!is_post()) {
        return;
    }
    if (posted('action') === 'delete') {
        require_permission($delete);
        return;
    }
    require_permission(posted('id') !== '' ? $edit : $create);
}

function require_manage_on_post(string $permission): void
{
    if (is_post()) {
        require_permission($permission);
    }
}

function sanitize_permission_list(array $posted, string $role): array
{
    if ($role === 'super_admin' || $role === 'student') {
        return [];
    }
    $allowed = grantable_permissions();
    $clean = [];
    foreach ($posted as $permission) {
        $permission = trim((string) $permission);
        if (in_array($permission, $allowed, true)) {
            $clean[] = $permission;
        }
    }
    $clean[] = 'dashboard.view';
    return array_values(array_unique($clean));
}

function save_user_permissions(int $userId, array $permissions): void
{
    db()->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
    $stmt = db()->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
    foreach ($permissions as $permission) {
        $stmt->execute([$userId, $permission]);
    }
}

function save_role_permissions(int $roleId, array $permissionNames): void
{
    db()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
    if (!$permissionNames) {
        return;
    }
    $ids = [];
    $stmt = db()->prepare('SELECT id FROM permissions WHERE name = ? LIMIT 1');
    foreach ($permissionNames as $name) {
        $stmt->execute([$name]);
        $id = (int) $stmt->fetchColumn();
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $insert = db()->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
    foreach (array_unique($ids) as $permissionId) {
        $insert->execute([$roleId, $permissionId]);
    }
}

function fetch_role_by_slug(string $slug): ?array
{
    if (!table_exists(db(), 'roles')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM roles WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function active_super_admin_count(): int
{
    if (!column_exists(db(), 'users', 'role')) {
        return 1;
    }
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1")->fetchColumn();
}

function is_last_active_super_admin(int $userId): bool
{
    if (active_super_admin_count() !== 1) {
        return false;
    }
    $stmt = db()->prepare("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    return (int) $stmt->fetchColumn() === $userId;
}
