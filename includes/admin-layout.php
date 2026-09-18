<?php

declare(strict_types=1);

function admin_nav_groups(): array
{
    return [
        'Overview' => [
            'index' => ['label' => 'Overview', 'href' => 'admin/index.php', 'icon' => 'layout', 'can' => ['dashboard.view']],
        ],
        'Class' => [
            'students' => ['label' => 'Students', 'href' => 'admin/students.php', 'icon' => 'users', 'can' => ['students.view', 'students.create', 'students.edit']],
            'uniforms' => ['label' => 'Uniforms', 'href' => 'admin/uniforms.php', 'icon' => 'shirt', 'can' => ['uniforms.view', 'uniforms.edit']],
            'payments' => ['label' => 'Payments', 'href' => 'admin/payments.php', 'icon' => 'wallet', 'can' => ['payments.view', 'payments.manage']],
        ],
        'Content' => [
            'timeline' => ['label' => 'Journey', 'href' => 'admin/timeline.php', 'icon' => 'route', 'can' => ['timeline.view', 'timeline.manage']],
            'events' => ['label' => 'Calendar', 'href' => 'admin/events.php', 'icon' => 'calendar', 'can' => ['events.view', 'events.create', 'events.edit', 'events.delete']],
            'announcements' => ['label' => 'Announcements', 'href' => 'admin/announcements.php', 'icon' => 'megaphone', 'can' => ['announcements.view', 'announcements.create', 'announcements.edit', 'announcements.delete']],
            'gallery' => ['label' => 'Gallery', 'href' => 'admin/gallery.php', 'icon' => 'image', 'can' => ['gallery.view', 'gallery.upload', 'gallery.edit', 'gallery.delete']],
            'memories' => ['label' => 'Memory wall', 'href' => 'admin/memories.php', 'icon' => 'quote', 'can' => ['memories.view', 'memories.manage']],
            'spotlights' => ['label' => 'Spotlight', 'href' => 'admin/spotlights.php', 'icon' => 'star', 'can' => ['spotlights.view', 'spotlights.manage']],
            'messages' => ['label' => 'Messages', 'href' => 'admin/messages.php', 'icon' => 'message', 'can' => ['messages.view', 'messages.manage']],
            'achievements' => ['label' => 'Achievements', 'href' => 'admin/achievements.php', 'icon' => 'award', 'can' => ['achievements.view', 'achievements.manage']],
            'committee' => ['label' => 'Committee', 'href' => 'admin/committee.php', 'icon' => 'flag', 'can' => ['committee.view', 'committee.manage']],
        ],
        'Interactions' => [
            'polls' => ['label' => 'Polls', 'href' => 'admin/polls.php', 'icon' => 'bar-chart', 'can' => ['polls.view', 'polls.create', 'polls.edit', 'polls.delete', 'polls.close', 'polls.results']],
            'questions' => ['label' => 'Questions', 'href' => 'admin/questions.php', 'icon' => 'help', 'can' => ['questions.view', 'questions.create', 'questions.moderate', 'questions.delete', 'questions.close']],
        ],
        'Graduation' => [
            'graduation' => ['label' => 'Graduation', 'href' => 'admin/graduation.php', 'icon' => 'graduation', 'can' => ['graduation.view', 'graduation.manage']],
        ],
        'Management' => [
            'reports' => ['label' => 'Reports', 'href' => 'admin/reports.php', 'icon' => 'file', 'can' => ['reports.view', 'reports.export']],
            'activity' => ['label' => 'Activity', 'href' => 'admin/activity.php', 'icon' => 'clock', 'can' => ['activity.view']],
        ],
        'System' => [
            'users' => ['label' => 'Users', 'href' => 'admin/users.php', 'icon' => 'lock', 'can' => ['users.manage']],
            'roles' => ['label' => 'Roles & permissions', 'href' => 'admin/roles.php', 'icon' => 'flag', 'can' => ['roles.manage']],
            'student-accounts' => ['label' => 'Student accounts', 'href' => 'admin/student-accounts.php', 'icon' => 'users', 'can' => ['users.manage']],
            'settings' => ['label' => 'Settings', 'href' => 'admin/settings.php', 'icon' => 'settings'],
        ],
    ];
}

function admin_header(string $title, string $active = 'index'): void
{
    $user = current_user();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrf_meta() ?>
    <title><?= e($title) ?> · <?= e(class_name()) ?> Admin</title>
    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <?php site_font_links(); ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=49">
    <link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>?v=36">
</head>
<body class="admin-body">
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-sidebar" data-sidebar>
            <a class="brand admin-brand" href="<?= e(url('admin/index.php')) ?>">
                <span class="brand-text">
                    <strong><?= e(class_name()) ?></strong>
                    <span>Admin</span>
                </span>
            </a>
            <nav class="admin-nav">
                <?php foreach (admin_nav_groups() as $group => $items): ?>
                    <?php
                    $visible = [];
                    foreach ($items as $key => $item) {
                        if (!isset($item['can']) || can_any($item['can'])) {
                            $visible[$key] = $item;
                        }
                    }
                    if (!$visible) {
                        continue;
                    }
                    ?>
                    <p class="nav-group"><?= e($group) ?></p>
                    <?php foreach ($visible as $key => $item): ?>
                        <a href="<?= e(url($item['href'])) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
                            <?= icon($item['icon'], 18) ?>
                            <span><?= e($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="admin-sidebar-foot">
                <div class="admin-user">
                    <span class="admin-user-dot" aria-hidden="true"></span>
                    <div>
                        <p><?= e(user_display_name($user)) ?></p>
                        <span class="muted"><?= e(role_label((string) ($user['role'] ?? ''))) ?></span>
                    </div>
                </div>
                <a href="<?= e(url('admin/settings.php')) ?>#account"><?= icon('settings', 16) ?> Account</a>
                <a href="<?= e(url('index.php')) ?>"><?= icon('external', 16) ?> View site</a>
                <a href="<?= e(url('logout.php')) ?>"><?= icon('logout', 16) ?> Logout</a>
            </div>
        </aside>
        <div class="admin-main">
            <header class="admin-topbar">
                <button class="nav-toggle" type="button" data-sidebar-toggle aria-controls="admin-sidebar" aria-expanded="false">
                    <span class="sr-only">Open menu</span>
                    <?= icon('menu') ?>
                </button>
                <div class="admin-topbar-title">
                    <p class="eyebrow"><?= e(class_name()) ?> · Grade <?= e(class_grade()) ?></p>
                    <h1><?= e($title) ?></h1>
                </div>
                <?php render_notification_bell('admin'); ?>
                <details class="account-menu">
                    <summary>
                        <span class="account-menu-name"><?= e(user_display_name($user)) ?></span>
                        <span class="account-menu-role"><?= e(role_label((string) ($user['role'] ?? ''))) ?></span>
                    </summary>
                    <div class="account-menu-panel">
                        <a href="<?= e(url('admin/settings.php')) ?>#account">Account</a>
                        <a href="<?= e(url('admin/settings.php')) ?>#account">Change password</a>
                        <a href="<?= e(url('index.php')) ?>">Public site</a>
                        <a href="<?= e(url('logout.php')) ?>">Logout</a>
                    </div>
                </details>
            </header>
            <div class="admin-content" id="main">
                <?php
                $error = flash_get('error');
                $success = flash_get('success');
                if ($error): ?>
                    <div class="alert alert-error" role="alert"><?= icon('alert', 18) ?> <?= e($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success" role="status"><?= icon('check', 18) ?> <?= e($success) ?></div>
                <?php endif; ?>
    <?php
}

function admin_footer(): void
{
    ?>
            </div>
        </div>
    </div>
    <div class="nav-backdrop" data-sidebar-backdrop></div>
    <div class="modal-root" id="confirm-modal" hidden>
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-text">
            <button type="button" class="icon-btn modal-close" data-confirm-cancel aria-label="Close"><?= icon('x', 18) ?></button>
            <h2 id="confirm-title">Are you sure?</h2>
            <p id="confirm-text">This action cannot be undone.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-confirm-cancel>Cancel</button>
                <button type="button" class="btn btn-danger" data-confirm-ok>Delete</button>
            </div>
        </div>
    </div>
    <script src="<?= e(url('assets/js/app.js')) ?>?v=10"></script>
    <script src="<?= e(url('assets/js/admin.js')) ?>?v=8"></script>
</body>
</html>
    <?php
}

function admin_empty(string $title, string $text): void
{
    echo '<div class="empty-state"><h2>' . e($title) . '</h2><p>' . e($text) . '</p></div>';
}

function admin_published_badge(mixed $published): string
{
    return admin_visibility_badge(!empty($published) ? 'public' : 'private');
}

function admin_visibility_badge(array|string $row): string
{
    $vis = is_array($row) ? content_visibility_of($row) : normalize_content_visibility((string) $row);
    $class = match ($vis) {
        'public' => 'badge-published',
        'private' => 'badge-private',
        'archived' => 'badge-cancelled',
        default => '',
    };
    return '<span class="badge ' . $class . '">' . e(status_label($vis)) . '</span>';
}

function visibility_select(?array $row = null, string $name = 'visibility'): string
{
    $current = $row ? content_visibility_of($row) : 'private';
    $html = '<div class="form-group full"><label>Visibility</label><select name="' . e($name) . '">';
    foreach (content_visibilities() as $key => $label) {
        $html .= '<option value="' . e($key) . '"' . ($current === $key ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select><p class="muted">Class only is the default. Nothing appears on the public website unless you choose Public website.</p></div>';
    return $html;
}

function admin_status_badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(status_label($status)) . '</span>';
}

function admin_active_badge(mixed $active): string
{
    return !empty($active)
        ? '<span class="badge badge-collected">Active</span>'
        : '<span class="badge badge-cancelled">Disabled</span>';
}

function admin_thumb(?string $path, string $alt = '', string $class = 'admin-thumb'): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    return '<img class="' . e($class) . '" src="' . e(url($path)) . '" alt="' . e($alt) . '">';
}

function admin_person_cell(string $name, ?string $photo = null, string $sub = ''): string
{
    $html = '<div class="person-cell">';
    $photo = trim((string) $photo);
    if ($photo !== '') {
        $html .= '<span class="stu-avatar stu-avatar-sm"><img src="' . e(url($photo)) . '" alt=""></span>';
    } else {
        $html .= '<span class="stu-avatar stu-avatar-sm" aria-hidden="true">' . e(person_initials($name)) . '</span>';
    }
    $html .= '<span><strong>' . e($name) . '</strong>';
    if ($sub !== '') {
        $html .= '<span class="muted">' . e($sub) . '</span>';
    }
    $html .= '</span></div>';
    return $html;
}

function admin_title_cell(string $title, ?string $path = null): string
{
    $html = '<div class="title-cell">';
    $thumb = admin_thumb($path, '');
    if ($thumb !== '') {
        $html .= $thumb;
    }
    $html .= '<span>' . e($title) . '</span></div>';
    return $html;
}

function admin_page_head(string $lead, array $actions = []): void
{
    echo '<div class="page-head"><p class="page-head-lead">' . e($lead) . '</p>';
    if ($actions) {
        echo '<div class="page-head-actions">';
        foreach ($actions as $action) {
            echo $action;
        }
        echo '</div>';
    }
    echo '</div>';
}

function admin_boot(string|array|null $permission = 'dashboard.view'): void
{
    require_staff();
    require_password_change();
    if ($permission === null) {
        return;
    }
    if (is_array($permission)) {
        require_permission_any($permission);
        return;
    }
    if ($permission !== '') {
        require_permission($permission);
    }
}

function render_pagination(array $pageData, string $baseQuery = ''): void
{
    if ($pageData['pages'] <= 1) {
        return;
    }
    echo '<nav class="pagination" aria-label="Pagination">';
    for ($i = 1; $i <= $pageData['pages']; $i++) {
        $qs = $baseQuery === '' ? 'page=' . $i : $baseQuery . '&page=' . $i;
        $current = $i === $pageData['page'] ? ' aria-current="page"' : '';
        echo '<a href="?' . e($qs) . '"' . $current . '>' . $i . '</a>';
    }
    echo '</nav>';
}
