<?php

declare(strict_types=1);

function student_nav_groups(): array
{
    return [
        '' => [
            'dashboard' => ['label' => 'Home', 'href' => 'student/dashboard.php', 'icon' => 'home'],
        ],
        'Class' => [
            'announcements' => ['label' => 'Announcements', 'href' => 'student/announcements.php', 'icon' => 'megaphone'],
            'events' => ['label' => 'Events', 'href' => 'student/events.php', 'icon' => 'calendar'],
            'calendar' => ['label' => 'Calendar', 'href' => 'student/calendar.php', 'icon' => 'clock'],
            'polls' => ['label' => 'Polls', 'href' => 'student/polls.php', 'icon' => 'bar-chart'],
            'decisions' => ['label' => 'Decisions', 'href' => 'student/decisions.php', 'icon' => 'flag'],
            'questions' => ['label' => 'Questions', 'href' => 'student/questions.php', 'icon' => 'help'],
        ],
        'Memories' => [
            'gallery' => ['label' => 'Gallery', 'href' => 'student/gallery.php', 'icon' => 'image'],
            'achievements' => ['label' => 'Achievements', 'href' => 'student/achievements.php', 'icon' => 'award'],
        ],
        'Graduation' => [
            'graduation' => ['label' => 'Graduation', 'href' => 'student/graduation.php', 'icon' => 'graduation'],
        ],
        'You' => [
            'profile' => ['label' => 'Profile', 'href' => 'student/profile.php', 'icon' => 'users'],
            'payment' => ['label' => 'Pay', 'href' => 'student/payment.php', 'icon' => 'wallet'],
            'account' => ['label' => 'Account', 'href' => 'student/account.php', 'icon' => 'lock'],
        ],
    ];
}

function student_dock_items(): array
{
    return [
        'dashboard' => ['label' => 'Home', 'href' => 'student/dashboard.php', 'icon' => 'home'],
        'events' => ['label' => 'Events', 'href' => 'student/events.php', 'icon' => 'calendar'],
        'gallery' => ['label' => 'Gallery', 'href' => 'student/gallery.php', 'icon' => 'image'],
        'payment' => ['label' => 'Pay', 'href' => 'student/payment.php', 'icon' => 'wallet'],
        'profile' => ['label' => 'Me', 'href' => 'student/profile.php', 'icon' => 'users'],
    ];
}

function student_boot(): void
{
    require_student();
    require_password_change();
}

function student_header(string $title, string $active = 'dashboard', bool $home = false, string $lead = ''): void
{
    $user = current_user();
    $student = current_student();
    $display = user_display_name($user);
    $name = first_name($display);
    $initials = person_initials((string) ($student['student_name'] ?? $display));
    $code = trim((string) ($student['student_code'] ?? $user['username'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrf_meta() ?>
    <title><?= e($title) ?> · <?= e(class_name()) ?></title>
    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <?php site_font_links(); ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=45">
</head>
<body class="student-body<?= $home ? ' is-home' : '' ?>">
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="student-shell">
        <aside class="student-sidebar" id="student-sidebar" data-sidebar>
            <a class="brand student-brand" href="<?= e(url('student/dashboard.php')) ?>">
                <span class="brand-text">
                    <strong><?= e(class_name()) ?></strong>
                    <span>Grade <?= e(class_grade()) ?></span>
                </span>
            </a>
            <nav class="student-nav" aria-label="Class portal">
                <?php foreach (student_nav_groups() as $group => $items): ?>
                    <?php if ($group !== ''): ?>
                        <p class="nav-group"><?= e($group) ?></p>
                    <?php endif; ?>
                    <?php foreach ($items as $key => $item): ?>
                        <a href="<?= e(url($item['href'])) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
                            <?= icon($item['icon'], 18) ?>
                            <span><?= e($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="student-sidebar-foot">
                <a href="<?= e(url('index.php')) ?>"><?= icon('external', 16) ?> Public site</a>
                <a href="<?= e(url('logout.php')) ?>"><?= icon('logout', 16) ?> Logout</a>
            </div>
        </aside>
        <div class="student-frame">
            <header class="student-topbar">
                <button class="nav-toggle" type="button" data-sidebar-toggle aria-controls="student-sidebar" aria-expanded="false">
                    <span class="sr-only">Open menu</span>
                    <?= icon('menu') ?>
                </button>
                <div class="student-topbar-title">
                    <p class="eyebrow"><?= e(class_name()) ?> · Grade <?= e(class_grade()) ?></p>
                    <h1><?= e($title) ?></h1>
                    <?php if ($lead !== ''): ?>
                        <p class="student-lead"><?= e($lead) ?></p>
                    <?php endif; ?>
                </div>
                <?php render_notification_bell('student'); ?>
                <details class="account-menu student-account-menu">
                    <summary>
                        <span class="stu-avatar stu-avatar-sm" aria-hidden="true"><?= e($initials) ?></span>
                        <span class="account-menu-copy">
                            <span class="account-menu-name"><?= e($name) ?></span>
                            <span class="account-menu-role">Student</span>
                        </span>
                    </summary>
                    <div class="account-menu-panel">
                        <?php if ($code !== ''): ?><p><?= e($code) ?></p><?php endif; ?>
                        <a href="<?= e(url('student/profile.php')) ?>">Profile</a>
                        <a href="<?= e(url('student/payment.php')) ?>">Pay</a>
                        <a href="<?= e(url('student/account.php')) ?>">Password</a>
                        <a href="<?= e(url('index.php')) ?>">Public site</a>
                        <a href="<?= e(url('logout.php')) ?>">Logout</a>
                    </div>
                </details>
            </header>
            <main id="main" class="student-main">
                <div class="student-page">
                    <?php
                    $error = flash_get('error');
                    $success = flash_get('success');
                    if ($error): ?>
                        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success" role="status"><?= e($success) ?></div>
                    <?php endif; ?>
    <?php
}

function student_footer(string $active = 'dashboard'): void
{
    $dockActive = [
        'calendar' => 'events',
        'account' => 'profile',
        'announcements' => 'dashboard',
        'achievements' => 'dashboard',
        'graduation' => 'dashboard',
        'polls' => 'dashboard',
        'decisions' => 'dashboard',
        'questions' => 'dashboard',
    ][$active] ?? $active;
    ?>
                </div>
            </main>
        </div>
    </div>
    <div class="nav-backdrop" data-sidebar-backdrop></div>
    <nav class="student-dock" aria-label="Primary">
        <?php foreach (student_dock_items() as $key => $item): ?>
            <a href="<?= e(url($item['href'])) ?>" <?= $dockActive === $key ? 'aria-current="page"' : '' ?>>
                <?= icon($item['icon'], 20) ?>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <script src="<?= e(url('assets/js/app.js')) ?>?v=7"></script>
</body>
</html>
    <?php
}
