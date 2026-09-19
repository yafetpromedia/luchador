<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/public-layout.php';
require_once __DIR__ . '/includes/queries.php';
require_once __DIR__ . '/includes/calendar-view.php';

$stats = public_stats();
$announcements = table_exists(db(), 'announcements') ? published_announcements() : [];
$nextEvent = next_published_event();
$photos = table_exists(db(), 'gallery') ? published_gallery() : [];
$galleryCats = gallery_categories();
$timeline = this_year_timeline();
$milestones = published_milestones();
$memories = published_memories();
$messages = published_class_messages(4);
$achievements = table_exists(db(), 'achievements') ? published_achievements(6) : [];
$spotlights = published_spotlights(1);
$members = table_exists(db(), 'committee_members') ? committee_list() : [];
$heroImage = setting('hero_image');
$heroBg = $heroImage ?: (($photos[0]['image_path'] ?? '') ?: '');
$gradDate = setting('graduation_date');
$showEvents = public_section_is_open('events');
$showAnnouncements = $announcements !== [];
$showGallery = $photos !== [];
$showMemories = $memories || $messages;
$showGraduation = setting_bool('graduation_public');
$showCommittee = $members !== [];
$countdown = $showGraduation && setting_bool('countdown_enabled') && $gradDate;
$loginHref = is_logged_in() ? post_login_path(current_user()) : 'login.php';
$loginLabel = is_logged_in() ? (is_student() ? t('nav.class_home') : t('nav.dashboard')) : t('nav.sign_in');

$monthParam = request_str('month');
$month = $monthParam !== '' ? parse_year_month($monthParam) : default_calendar_month();
$category = request_str('category', 'all');
if ($category !== 'all' && !array_key_exists($category, event_categories())) {
    $category = 'all';
}
$cal = calendar_month_events($month, $category === 'all' ? null : $category);
$day = request_int('day');
if ($day < 1 || $day > (int) $month->format('t')) {
    $day = 0;
}

public_header(site_title(), 'home', setting('hero_message'), true);
?>

<section class="cover" id="home">
    <div class="cover-motion<?= $heroBg ? ' has-photo' : '' ?>" aria-hidden="true">
        <?php if ($heroBg): ?>
            <img class="cover-motion-photo" src="<?= e(url($heroBg)) ?>" alt="">
        <?php endif; ?>
        <span class="cover-motion-mark"><?= e(class_grade()) ?></span>
        <span class="cover-motion-glow cover-motion-glow-a"></span>
        <span class="cover-motion-glow cover-motion-glow-b"></span>
    </div>
    <div class="container cover-grid">
        <div class="cover-copy">
            <p class="cover-meta"><?= e(class_name()) ?> · <?= e(school_name()) ?></p>
            <h1><?= e(t('home.hero_title', ['grade' => class_grade()])) ?></h1>
            <p class="cover-lede"><?= e(t('home.hero_lede')) ?></p>
            <p class="hero-actions">
                <?php if ($showEvents): ?>
                    <a class="btn" href="#events"><?= e(t('home.see_events')) ?></a>
                <?php endif; ?>
                <a class="btn<?= $showEvents ? ' btn-ghost' : '' ?>" href="#journey"><?= e(t('home.our_story')) ?></a>
                <a class="btn btn-ghost" href="<?= e(url($loginHref)) ?>"><?= e($loginLabel) ?></a>
            </p>
        </div>
        <?php if ($nextEvent && !empty($nextEvent['title'])): ?>
            <aside class="cover-side">
                <p class="eyebrow"><?= e(t('home.next_up')) ?></p>
                <p class="cover-next-title"><?= e($nextEvent['title']) ?></p>
                <p class="muted">
                    <?= !empty($nextEvent['event_date']) ? e(format_date($nextEvent['event_date'])) : e(t('home.date_tba')) ?>
                    <?= !empty($nextEvent['event_time']) ? ' · ' . e($nextEvent['event_time']) : '' ?>
                </p>
                <a class="text-link" href="<?= e(url('event.php?id=' . (int) $nextEvent['id'])) ?>"><?= e(t('home.view_event')) ?></a>
            </aside>
        <?php endif; ?>
    </div>
</section>





<?php if ($timeline || $achievements): ?>
<section class="section" id="year">
    <div class="container">
        <p class="eyebrow"><?= e(t('home.our_year')) ?></p>
        <h2><?= e(setting('academic_year') ?: t('home.this_year')) ?></h2>
        <?php if ($timeline): ?>
            <div class="year-track">
                <?php foreach ($timeline as $bucket): ?>
                    <article class="year-month">
                        <p class="year-month-name"><?= e($bucket['month']) ?></p>
                        <ul>
                            <?php foreach ($bucket['items'] as $item): ?>
                                <?php
                                $href = (string) $item['href'];
                                if (str_starts_with($href, 'event.php')) {
                                    $link = url($href);
                                } elseif (str_starts_with($href, 'journey')) {
                                    $link = '#journey';
                                } elseif (str_starts_with($href, 'graduation')) {
                                    $link = '#graduation';
                                } else {
                                    $link = '#' . ltrim($href, '#');
                                }
                                ?>
                                <li><a href="<?= e($link) ?>"><?= e($item['title']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($achievements): ?>
            <ol class="editorial-list" style="margin-top:1.25rem">
                <?php foreach ($achievements as $i => $item): ?>
                    <li>
                        <span class="editorial-index"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <div>
                            <strong><?= e($item['title']) ?></strong>
                            <p class="muted"><?= e(format_date($item['achieved_on'] ?? null)) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($showEvents): ?>
<section class="section" id="events">
    <div class="container">
        <p class="eyebrow"><?= e(t('nav.events')) ?></p>
        <h2><?= e(t('home.class_calendar')) ?></h2>
        <div class="events-fit">
        <?php if ($nextEvent): ?>
            <article class="feature-block">
                <?php if (!empty($nextEvent['event_date'])): ?>
                    <p class="feature-date">
                        <span><?= e(format_date($nextEvent['event_date'], 'M')) ?></span>
                        <b><?= e(format_date($nextEvent['event_date'], 'j')) ?></b>
                    </p>
                <?php endif; ?>
                <div class="feature-copy">
                    <h3><?= e($nextEvent['title']) ?></h3>
                    <p class="muted">
                        <?= !empty($nextEvent['event_time']) ? e($nextEvent['event_time']) : e(t('home.time_tba')) ?>
                        <?= !empty($nextEvent['location']) ? ' · ' . e($nextEvent['location']) : '' ?>
                    </p>
                    <a class="text-link" href="<?= e(url('event.php?id=' . (int) $nextEvent['id'])) ?>"><?= e(t('home.details')) ?></a>
                </div>
            </article>
        <?php endif; ?>

        <?php
        render_class_calendar([
            'month' => $month,
            'cal' => $cal,
            'day' => $day,
            'base' => 'index.php',
            'hash' => '#events',
            'category' => $category,
            'event_base' => 'event.php',
        ]);
        ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($showAnnouncements): ?>
<section class="section" id="announcements">
    <div class="container">
        <div>
            <p class="eyebrow"><?= e(t('home.updates')) ?></p>
            <h2><?= e(t('home.announcements')) ?></h2>
        </div>
        <ol class="editorial-list editorial-list-full">
            <?php foreach ($announcements as $i => $item): ?>
                <li>
                    <span class="editorial-index"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <div>
                        <strong><?= e($item['title']) ?></strong>
                        <p class="muted"><?= e(format_date($item['announced_on']) ?: format_relative($item['created_at'] ?? null)) ?></p>
                        <?php if (!empty($item['description'])): ?>
                            <p><?= nl2br(e((string) $item['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
<?php endif; ?>

<?php if ($showGallery): ?>
<section class="section" id="gallery">
    <div class="container">
        <div>
            <p class="eyebrow"><?= e(t('home.photos')) ?></p>
            <h2><?= e(t('nav.gallery')) ?></h2>
        </div>
        <div class="filters" data-gallery-filters>
            <?php foreach ($galleryCats as $key => $label): ?>
                <button type="button" class="chip<?= $key === 'all' ? ' is-active' : '' ?>" data-gallery-filter="<?= e($key) ?>"><?= e($label) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="masonry" data-gallery-grid>
            <?php foreach ($photos as $item): ?>
                <a href="<?= e(url($item['image_path'])) ?>" data-lightbox data-caption="<?= e($item['caption'] ?: $item['title']) ?>" data-category="<?= e($item['category'] ?? 'class') ?>">
                    <img src="<?= e(url($item['image_path'])) ?>" alt="<?= e($item['title']) ?>" loading="lazy">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($showMemories): ?>
<section class="section" id="memories">
    <div class="container">
        <div>
            <p class="eyebrow"><?= e(t('home.words')) ?></p>
            <h2><?= e(t('home.memories')) ?></h2>
        </div>
        <div class="memory-wall">
            <?php foreach ($messages as $item): ?>
                <blockquote class="home-quote">
                    <p><?= e($item['body']) ?></p>
                    <footer class="muted"><?= e($item['author_name']) ?></footer>
                </blockquote>
            <?php endforeach; ?>
            <?php foreach ($memories as $item): ?>
                <article class="memory-card">
                    <blockquote><p><?= e($item['body']) ?></p></blockquote>
                    <?php if ($item['attribution']): ?><p class="memory-attr">— <?= e($item['attribution']) ?></p><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section" id="journey">
    <div class="container">
        <p class="eyebrow"><?= e(t('home.journey')) ?></p>
        <h2><?= e(t('home.who_we_are')) ?></h2>
        <div class="<?= $milestones ? 'split' : '' ?>">
            <article class="prose">
                <?php if (setting('about_who')): ?><p><?= e(setting('about_who')) ?></p><?php endif; ?>
                <?php if (setting('about_community')): ?><p><?= e(setting('about_community')) ?></p><?php endif; ?>
                <?php if (setting('about_academic')): ?><p><?= e(setting('about_academic')) ?></p><?php endif; ?>
                <?php if (!setting('about_who') && !setting('about_community') && !setting('about_academic')): ?>
                    <p class="muted"><?= e(t('home.story_placeholder')) ?></p>
                <?php endif; ?>
            </article>
            <?php if ($milestones): ?>
                <div>
                    <h3><?= e(t('home.the_path')) ?></h3>
                    <ol class="journey">
                        <?php foreach ($milestones as $item): ?>
                            <li class="<?= !empty($item['highlight']) ? 'here' : '' ?>">
                                <strong><?= e($item['title']) ?></strong>
                                <?php if ($item['occurred_on']): ?><p class="muted"><?= e(format_date($item['occurred_on'])) ?></p><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($spotlights): ?>
            <?php $spot = $spotlights[0]; ?>
            <article class="spotlight-inline">
                <p class="eyebrow"><?= e(t('home.spotlight')) ?></p>
                <h3><?= e($spot['student_name']) ?></h3>
                <?php if ($spot['title']): ?><p><?= e($spot['title']) ?></p><?php endif; ?>
                <?php if ($spot['description']): ?><p class="muted"><?= e($spot['description']) ?></p><?php endif; ?>
            </article>
        <?php endif; ?>
    </div>
</section>

<?php if ($showGraduation): ?>
<section class="section<?= ($gradDate || setting('graduation_message') || setting('class_message')) ? '' : ' is-compact' ?>" id="graduation">
    <div class="container<?= ($gradDate || setting('graduation_message') || setting('class_message')) ? '' : ' section-row' ?>">
        <div>
            <p class="eyebrow"><?= e(t('nav.graduation')) ?></p>
            <h2><?= e(setting('graduation_title', t('nav.graduation'))) ?></h2>
        </div>
        <div>
        <?php if ($countdown && $gradDate): ?>
            <?php render_countdown($gradDate); ?>
        <?php elseif ($gradDate): ?>
            <p class="countdown-date"><?= e(format_date($gradDate)) ?></p>
        <?php else: ?>
            <p class="muted"><?= e(t('home.grad_placeholder')) ?></p>
        <?php endif; ?>
        <?php if (setting('graduation_message')): ?>
            <p><?= nl2br(e(setting('graduation_message'))) ?></p>
        <?php endif; ?>
        <?php if (setting('class_message')): ?>
            <p><?= nl2br(e(setting('class_message'))) ?></p>
        <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($showCommittee): ?>
<section class="section" id="committee">
    <div class="container">
        <p class="eyebrow"><?= e(t('footer.leadership')) ?></p>
        <h2><?= e(t('home.committee')) ?></h2>
        <div class="people people-row">
            <?php foreach ($members as $member): ?>
                <article class="person">
                    <div class="avatar">
                        <?php if ($member['photo']): ?>
                            <img src="<?= e(url($member['photo'])) ?>" alt="<?= e($member['name']) ?>">
                        <?php else: ?>
                            <?= e(strtoupper(substr($member['name'], 0, 1))) ?>
                        <?php endif; ?>
                    </div>
                    <div class="person-body">
                        <h3><?= e($member['name']) ?></h3>
                        <p class="muted"><?= e($member['position']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section" id="contact">
    <div class="container">
        <p class="eyebrow"><?= e(t('nav.contact')) ?></p>
        <h2><?= e(t('home.get_in_touch')) ?></h2>
        <p class="muted"><?= e(t('home.contact_lede', ['class' => class_name()])) ?></p>

        <?php
        $contactEmail = setting('contact_email');
        $contactPhone = setting('contact_phone');
        $contactLocation = setting('contact_location');
        $telHref = $contactPhone !== '' ? contact_tel_href($contactPhone) : '';
        ?>
        <div class="contact-grid">
            <article class="contact-card">
                <span class="contact-icon"><?= icon('users', 18) ?></span>
                <p class="eyebrow"><?= e(t('home.class')) ?></p>
                <strong><?= e(school_name()) ?></strong>
                <p class="muted"><?= e(class_name()) ?> · <?= e(t('grade', ['grade' => class_grade()])) ?><?php if (setting('academic_year')): ?> · <?= e(setting('academic_year')) ?><?php endif; ?></p>
            </article>
            <?php if ($contactEmail !== ''): ?>
                <article class="contact-card">
                    <span class="contact-icon"><?= icon('mail', 18) ?></span>
                    <p class="eyebrow"><?= e(t('home.email')) ?></p>
                    <strong><a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></strong>
                    <div class="contact-card-actions">
                        <a class="btn btn-sm" href="mailto:<?= e($contactEmail) ?>"><?= e(t('home.write')) ?></a>
                        <button type="button" class="btn btn-sm btn-ghost" data-copy="<?= e($contactEmail) ?>"><?= e(t('home.copy')) ?></button>
                    </div>
                </article>
            <?php endif; ?>
            <?php if ($contactPhone !== ''): ?>
                <article class="contact-card">
                    <span class="contact-icon"><?= icon('phone', 18) ?></span>
                    <p class="eyebrow"><?= e(t('home.phone')) ?></p>
                    <strong><?php if ($telHref !== ''): ?><a href="<?= e($telHref) ?>"><?= e($contactPhone) ?></a><?php else: ?><?= e($contactPhone) ?><?php endif; ?></strong>
                    <div class="contact-card-actions">
                        <?php if ($telHref !== ''): ?><a class="btn btn-sm" href="<?= e($telHref) ?>"><?= e(t('home.call')) ?></a><?php endif; ?>
                        <button type="button" class="btn btn-sm btn-ghost" data-copy="<?= e($contactPhone) ?>"><?= e(t('home.copy')) ?></button>
                    </div>
                </article>
            <?php endif; ?>
            <?php if ($contactLocation !== ''): ?>
                <article class="contact-card">
                    <span class="contact-icon"><?= icon('map-pin', 18) ?></span>
                    <p class="eyebrow"><?= e(t('home.location')) ?></p>
                    <strong><?= e($contactLocation) ?></strong>
                    <div class="contact-card-actions">
                        <button type="button" class="btn btn-sm btn-ghost" data-copy="<?= e($contactLocation) ?>"><?= e(t('home.copy')) ?></button>
                    </div>
                </article>
            <?php endif; ?>
        </div>

        <p class="contact-sign">
            <?php if (is_logged_in()): ?>
                <a class="btn" href="<?= e(url(post_login_path(current_user()))) ?>"><?= is_student() ? e(t('nav.class_home')) : e(t('nav.dashboard')) ?></a>
            <?php else: ?>
                <a class="btn" href="<?= e(url('login.php')) ?>"><?= e(t('nav.sign_in')) ?></a>
            <?php endif; ?>
        </p>
    </div>
</section>

<?php public_footer(true); ?>
