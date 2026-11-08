<?php

declare(strict_types=1);

function student_nav_groups(): array
{
    return [
        '' => [
            'dashboard' => ['label' => t('nav.home'), 'href' => 'student/dashboard.php', 'icon' => 'home'],
        ],
        t('student.group.class') => [
            'announcements' => ['label' => t('student.announcements'), 'href' => 'student/announcements.php', 'icon' => 'megaphone'],
            'events' => ['label' => t('student.events'), 'href' => 'student/events.php', 'icon' => 'calendar'],
            'calendar' => ['label' => t('student.calendar'), 'href' => 'student/calendar.php', 'icon' => 'clock'],
            'polls' => ['label' => t('student.polls'), 'href' => 'student/polls.php', 'icon' => 'bar-chart'],
            'decisions' => ['label' => t('student.decisions'), 'href' => 'student/decisions.php', 'icon' => 'gavel'],
            'questions' => ['label' => t('student.questions'), 'href' => 'student/questions.php', 'icon' => 'help'],
            'culture-day' => ['label' => t('student.culture_day'), 'href' => 'student/culture-day.php', 'icon' => 'globe'],
        ],
        t('student.group.memories') => [
            'gallery' => ['label' => t('student.gallery'), 'href' => 'student/gallery.php', 'icon' => 'image'],
            'achievements' => ['label' => t('student.achievements'), 'href' => 'student/achievements.php', 'icon' => 'award'],
        ],
        t('student.group.graduation') => [
            'graduation' => ['label' => t('student.graduation'), 'href' => 'student/graduation.php', 'icon' => 'graduation'],
        ],
        t('student.group.you') => [
            'profile' => ['label' => t('student.profile'), 'href' => 'student/profile.php', 'icon' => 'person'],
            'payment' => ['label' => t('student.pay'), 'href' => 'student/payment.php', 'icon' => 'wallet'],
            'account' => ['label' => t('student.account'), 'href' => 'student/account.php', 'icon' => 'lock'],
        ],
    ];
}

function student_dock_items(): array
{
    return [
        'dashboard' => ['label' => t('nav.home'), 'href' => 'student/dashboard.php', 'icon' => 'home'],
        'events' => ['label' => t('student.events'), 'href' => 'student/events.php', 'icon' => 'calendar'],
        'gallery' => ['label' => t('student.gallery'), 'href' => 'student/gallery.php', 'icon' => 'image'],
        'payment' => ['label' => t('student.pay'), 'href' => 'student/payment.php', 'icon' => 'wallet'],
        'profile' => ['label' => t('nav.me'), 'href' => 'student/profile.php', 'icon' => 'person'],
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
<html lang="<?= e(current_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrf_meta() ?>
    <title><?= e($title) ?> · <?= e(class_name()) ?></title>
    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <?php site_font_links(); ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=80">
</head>
<body class="student-body<?= $home ? ' is-home' : '' ?>">
    <a class="skip-link" href="#main"><?= e(t('skip')) ?></a>
    <div class="student-shell">
        <aside class="student-sidebar" id="student-sidebar" data-sidebar>
            <a class="brand student-brand" href="<?= e(url('student/dashboard.php')) ?>">
                <span class="brand-text">
                    <strong><?= e(class_name()) ?></strong>
                    <span><?= e(t('grade', ['grade' => class_grade()])) ?></span>
                </span>
            </a>
            <nav class="student-nav" aria-label="<?= e(t('student.nav')) ?>">
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
                <?php if (function_exists('has_staff_portal_access') && has_staff_portal_access()): ?>
                    <a href="<?= e(url('admin/index.php')) ?>"><?= icon('shield', 16) ?> Committee admin</a>
                <?php endif; ?>
                <a href="<?= e(url('index.php')) ?>"><?= icon('external', 16) ?> <?= e(t('nav.public_site')) ?></a>
                <a href="<?= e(url('logout.php')) ?>"><?= icon('logout', 16) ?> <?= e(t('nav.sign_out')) ?></a>
            </div>
        </aside>
        <div class="student-frame">
            <header class="student-topbar">
                <button class="nav-toggle" type="button" data-sidebar-toggle aria-controls="student-sidebar" aria-expanded="false">
                    <span class="sr-only"><?= e(t('nav.open_menu')) ?></span>
                    <?= icon('menu') ?>
                </button>
                <div class="student-topbar-title">
                    <p class="eyebrow"><?= e(class_name()) ?> · <?= e(t('grade', ['grade' => class_grade()])) ?></p>
                    <h1><?= e($title) ?></h1>
                    <?php if ($lead !== ''): ?>
                        <p class="student-lead"><?= e($lead) ?></p>
                    <?php endif; ?>
                </div>
                <?php render_language_switcher(); ?>
                <?php render_notification_bell('student'); ?>
                <details class="account-menu student-account-menu">
                    <summary>
                        <span class="stu-avatar stu-avatar-sm" aria-hidden="true"><?= e($initials) ?></span>
                        <span class="account-menu-copy">
                            <span class="account-menu-name"><?= e($name) ?></span>
                            <span class="account-menu-role"><?= e(function_exists('is_student_officer') && is_student_officer() ? 'Student · Committee' : t('student.role')) ?></span>
                        </span>
                    </summary>
                    <div class="account-menu-panel">
                        <?php if ($code !== ''): ?><p><?= e($code) ?></p><?php endif; ?>
                        <?php if (function_exists('has_staff_portal_access') && has_staff_portal_access()): ?>
                            <a href="<?= e(url('admin/index.php')) ?>">Committee admin</a>
                        <?php endif; ?>
                        <a href="<?= e(url('student/profile.php')) ?>"><?= e(t('nav.profile')) ?></a>
                        <a href="<?= e(url('student/payment.php')) ?>"><?= e(t('nav.pay')) ?></a>
                        <a href="<?= e(url('student/account.php')) ?>"><?= e(t('nav.password')) ?></a>
                        <a href="<?= e(url('index.php')) ?>"><?= e(t('nav.public_site')) ?></a>
                        <a href="<?= e(url('logout.php')) ?>"><?= e(t('nav.sign_out')) ?></a>
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

    require_once __DIR__ . '/interactions.php';
    $votePrompt = student_pending_open_vote(current_student());
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $onPromptPoll = $votePrompt
        && str_contains($script, '/student/poll.php')
        && request_int('id') === (int) ($votePrompt['id'] ?? 0);
    $autoMode = '';
    if ($votePrompt && !$onPromptPoll) {
        if (!empty($_SESSION['show_vote_prompt'])) {
            $autoMode = 'login';
            unset($_SESSION['show_vote_prompt']);
        } elseif (str_contains($script, '/student/dashboard.php')) {
            $autoMode = 'home';
        }
    } else {
        $votePrompt = null;
    }
    ?>
                </div>
            </main>
        </div>
    </div>
    <div class="nav-backdrop" data-sidebar-backdrop></div>
    <nav class="student-dock" aria-label="<?= e(t('student.dock')) ?>">
        <?php foreach (student_dock_items() as $key => $item): ?>
            <a href="<?= e(url($item['href'])) ?>" <?= $dockActive === $key ? 'aria-current="page"' : '' ?>>
                <?= icon($item['icon'], 20) ?>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php if ($votePrompt): ?>
        <?php
        $isDecision = poll_is_decision($votePrompt);
        $options = poll_options((int) $votePrompt['id']);
        $multi = ($votePrompt['choice_type'] ?? 'single') === 'multiple';
        $closes = interaction_closes_in($votePrompt['ends_at'] ?? null);
        $pollUrl = url('student/poll.php?id=' . (int) $votePrompt['id']);
        ?>
        <div
            class="vote-prompt<?= $isDecision ? ' is-decision' : '' ?>"
            id="vote-prompt"
            hidden
            data-vote-prompt
            data-poll-id="<?= (int) $votePrompt['id'] ?>"
            <?= $autoMode !== '' ? 'data-auto-open="' . e($autoMode) . '"' : '' ?>
        >
            <div class="vote-prompt-backdrop" data-vote-prompt-dismiss tabindex="-1" aria-hidden="true"></div>
            <div class="vote-prompt-sheet" role="dialog" aria-modal="true" aria-labelledby="vote-prompt-title" aria-describedby="vote-prompt-copy">
                <button type="button" class="vote-prompt-close icon-btn" data-vote-prompt-dismiss aria-label="Close"><?= icon('x', 18) ?></button>
                <p class="eyebrow"><?= $isDecision ? 'Class decision' : 'Class poll' ?></p>
                <h2 id="vote-prompt-title"><?= e((string) $votePrompt['title']) ?></h2>
                <p id="vote-prompt-copy" class="vote-prompt-copy">
                    <?= $isDecision ? 'This vote counts for the class. Cast yours before it closes.' : 'The class is voting — pick your answer.' ?>
                    <?= $closes !== '' ? ' ' . e($closes) . '.' : '' ?>
                </p>
                <?php if (!$multi && $options !== []): ?>
                    <form method="post" action="<?= e($pollUrl) ?>" class="vote-picks vote-prompt-picks" data-loading>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="vote">
                        <?php foreach ($options as $option): ?>
                            <button class="vote-pick" type="submit" name="option_id" value="<?= (int) $option['id'] ?>"><?= e((string) $option['label']) ?></button>
                        <?php endforeach; ?>
                    </form>
                <?php else: ?>
                    <div class="vote-prompt-actions">
                        <a class="btn" href="<?= e($pollUrl) ?>">Open ballot</a>
                    </div>
                <?php endif; ?>
                <div class="vote-prompt-foot">
                    <a class="text-link" href="<?= e($pollUrl) ?>">See full details</a>
                    <button type="button" class="text-link vote-prompt-later" data-vote-prompt-dismiss>Later</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script src="<?= e(url('assets/js/app.js')) ?>?v=22"></script>
    <script src="<?= e(url('assets/js/culture-graph.js')) ?>?v=6"></script>
</body>
</html>
    <?php
}
