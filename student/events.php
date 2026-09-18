<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

student_boot();

$items = class_events();
$parts = partition_student_events($items);
$hero = $parts['next'] ?? ($parts['past'][0] ?? ($parts['undated'][0] ?? null));
$upcoming = $parts['upcoming'];
$past = $parts['past'];
$undated = $parts['undated'];
if ($hero && !$parts['next'] && $past && (int) ($hero['id'] ?? 0) === (int) ($past[0]['id'] ?? 0)) {
    $past = array_slice($past, 1);
}
if ($hero && !$parts['next'] && $undated && (int) ($hero['id'] ?? 0) === (int) ($undated[0]['id'] ?? 0)) {
    $undated = array_slice($undated, 1);
}

student_header('Events', 'events', lead: 'What the class is doing — upcoming first, then what already happened.');
?>

<div class="stu-event-nav">
    <a class="chip is-active" href="<?= e(url('student/events.php')) ?>">Schedule</a>
    <a class="chip" href="<?= e(url('student/calendar.php')) ?>">Calendar</a>
</div>

<?php if (!$items): ?>
    <div class="empty-state">
        <h2>Nothing on the calendar yet</h2>
        <p>Class events appear here once they are added for the class.</p>
    </div>
<?php else: ?>
    <?php if ($hero): ?>
        <?php $heroHref = url('student/event.php?id=' . (int) $hero['id']); ?>
        <article class="stu-event-hero<?= !empty($hero['cover_image']) ? ' has-photo' : '' ?>">
            <?php if (!empty($hero['cover_image'])): ?>
                <img src="<?= e(url($hero['cover_image'])) ?>" alt="">
            <?php endif; ?>
            <div class="stu-event-hero-copy">
                <p class="eyebrow"><?= e(event_hero_kicker($hero)) ?><?php if (!empty($hero['category'])): ?> · <?= e(status_label((string) $hero['category'])) ?><?php endif; ?></p>
                <h2><a href="<?= e($heroHref) ?>"><?= e($hero['title']) ?></a></h2>
                <p class="stu-event-hero-meta">
                    <?= e(event_timing_label($hero)) ?>
                    <?php $meta = event_meta_line($hero); ?>
                    <?= $meta !== '' ? ' · ' . e($meta) : '' ?>
                </p>
                <a class="btn btn-sm" href="<?= e($heroHref) ?>">Event details</a>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($upcoming): ?>
        <section class="stu-block">
            <div class="stu-block-head">
                <p class="eyebrow">Coming up</p>
            </div>
            <div class="stu-event-list">
                <?php foreach ($upcoming as $item): ?>
                    <a class="stu-event-row" href="<?= e(url('student/event.php?id=' . (int) $item['id'])) ?>">
                        <time class="stu-event-when" datetime="<?= e((string) ($item['event_date'] ?? '')) ?>">
                            <span><?= e(format_date($item['event_date'] ?? null, 'M') ?: 'TBA') ?></span>
                            <b><?= e(format_date($item['event_date'] ?? null, 'j') ?: '—') ?></b>
                        </time>
                        <div>
                            <strong><?= e($item['title']) ?></strong>
                            <p class="muted"><?= e(event_timing_label($item)) ?><?php $m = event_meta_line($item); ?><?= $m !== '' ? ' · ' . e($m) : '' ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($undated): ?>
        <section class="stu-block">
            <div class="stu-block-head">
                <p class="eyebrow">Date to be announced</p>
            </div>
            <div class="stu-event-list">
                <?php foreach ($undated as $item): ?>
                    <a class="stu-event-row" href="<?= e(url('student/event.php?id=' . (int) $item['id'])) ?>">
                        <span class="stu-event-when">
                            <span>TBA</span>
                            <b>—</b>
                        </span>
                        <div>
                            <strong><?= e($item['title']) ?></strong>
                            <p class="muted"><?= e(event_meta_line($item) ?: 'Details when published') ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($past): ?>
        <section class="stu-block">
            <div class="stu-block-head">
                <p class="eyebrow">Earlier</p>
            </div>
            <div class="stu-event-list">
                <?php foreach ($past as $item): ?>
                    <a class="stu-event-row" href="<?= e(url('student/event.php?id=' . (int) $item['id'])) ?>">
                        <time class="stu-event-when" datetime="<?= e((string) ($item['event_date'] ?? '')) ?>">
                            <span><?= e(format_date($item['event_date'] ?? null, 'M') ?: '') ?></span>
                            <b><?= e(format_date($item['event_date'] ?? null, 'j') ?: '—') ?></b>
                        </time>
                        <div>
                            <strong><?= e($item['title']) ?></strong>
                            <p class="muted"><?= e(event_timing_label($item)) ?><?php $m = event_meta_line($item, false); ?><?= $m !== '' ? ' · ' . e($m) : '' ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php student_footer('events'); ?>
