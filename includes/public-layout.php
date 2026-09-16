<?php

declare(strict_types=1);

function public_nav_items(bool $onHome = false): array
{
    $base = $onHome ? '' : 'index.php';
    return [
        'home' => ['label' => 'Home', 'href' => $base . '#home'],
        'events' => ['label' => 'Events', 'href' => $base . '#events'],
        'gallery' => ['label' => 'Gallery', 'href' => $base . '#gallery'],
        'journey' => ['label' => 'Journey', 'href' => $base . '#journey'],
        'graduation' => ['label' => 'Graduation', 'href' => $base . '#graduation'],
        'contact' => ['label' => 'Contact', 'href' => $base . '#contact'],
    ];
}

function public_header(string $title, string $active = 'home', string $description = '', bool $onePage = false): void
{
    $desc = $description !== '' ? $description : setting('hero_message');
    $ogImage = setting('hero_image');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrf_meta() ?>
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e($desc) ?>">
    <meta property="og:type" content="website">
    <?php if ($ogImage): ?>
    <meta property="og:image" content="<?= e(url($ogImage)) ?>">
    <?php endif; ?>
    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <?php site_font_links(); ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=47">
</head>
<body class="<?= $onePage ? 'onepage' : '' ?>">
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="<?= e($onePage ? '#home' : url('index.php#home')) ?>">
                <span class="brand-text">
                    <strong><?= e(class_name()) ?></strong>
                    <span>Grade <?= e(class_grade()) ?></span>
                </span>
            </a>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
                <span class="sr-only">Open menu</span>
                <?= icon('menu') ?>
            </button>
            <nav class="site-nav" id="site-nav" data-nav>
                <div class="nav-drawer-head">
                    <span>Menu</span>
                    <button type="button" class="icon-btn" data-nav-close aria-label="Close menu"><?= icon('x') ?></button>
                </div>
                <?php foreach (public_nav_items($onePage) as $key => $item): ?>
                    <a href="<?= e($onePage ? $item['href'] : url($item['href'])) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                <?php endforeach; ?>
                <?php
                if (is_logged_in()) {
                    $portalHref = post_login_path(current_user());
                    $portalLabel = is_student() ? 'Class home' : 'Dashboard';
                    echo '<a class="nav-login" href="' . e(url($portalHref)) . '">' . e($portalLabel) . '</a>';
                } else {
                    echo '<a class="nav-login" href="' . e(url('login.php')) . '">Sign in</a>';
                }
                ?>
            </nav>
            <?php render_notification_bell('public'); ?>
        </div>
    </header>
    <div class="nav-backdrop" data-nav-backdrop></div>
    <main id="main">
    <?php
}

function public_footer(bool $onePage = false): void
{
    $base = $onePage ? '' : 'index.php';
    ?>
    </main>
    <footer class="site-footer">
        <div class="container footer-simple">
            <p><?= e(class_name()) ?> · Grade <?= e(class_grade()) ?> · <?= e(school_name()) ?></p>
            <p>
                <a href="<?= e($onePage ? '#contact' : url($base . '#contact')) ?>">Contact</a>
                ·
                <a href="<?= e($onePage ? '#committee' : url($base . '#committee')) ?>">Leadership</a>
                ·
                <a href="<?= e(url('login.php')) ?>">Sign in</a>
            </p>
            <p class="muted">&copy; <?= current_year() ?> <?= e(class_name()) ?></p>
        </div>
    </footer>
    <script src="<?= e(url('assets/js/app.js')) ?>?v=7"></script>
</body>
</html>
    <?php
}

function empty_state(string $title, string $text): void
{
    echo '<div class="empty-state"><h2>' . e($title) . '</h2><p>' . e($text) . '</p></div>';
}

function page_intro(string $eyebrow, string $title, string $lead = ''): void
{
    echo '<section class="page-intro"><div class="container">';
    if ($eyebrow !== '') {
        echo '<p class="eyebrow">' . e($eyebrow) . '</p>';
    }
    echo '<h1>' . e($title) . '</h1>';
    if ($lead !== '') {
        echo '<p class="lead">' . e($lead) . '</p>';
    }
    echo '</div></section>';
}
