<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

student_boot();

$event = find_class_event(request_int('id'));
if (!$event) {
    flash_set('error', 'That event is not available.');
    redirect('student/events.php');
}

$others = [];
foreach (class_events() as $item) {
    if ((int) $item['id'] === (int) $event['id']) {
        continue;
    }
    if (in_array(event_timing($item), ['upcoming', 'today'], true)) {
        $others[] = $item;
    }
    if (count($others) >= 3) {
        break;
    }
}

student_header('Event', 'events', lead: (string) ($event['title'] ?? 'Class event'));
?>

<article class="stu-event-detail">
    <?php if (!empty($event['cover_image'])): ?>
        <div class="stu-event-detail-photo">
            <img src="<?= e(url($event['cover_image'])) ?>" alt="">
        </div>
    <?php endif; ?>

    <p class="eyebrow"><?= e(event_hero_kicker($event)) ?><?php if (!empty($event['category'])): ?> · <?= e(status_label((string) $event['category'])) ?><?php endif; ?></p>
    <h2><?= e($event['title']) ?></h2>

    <ul class="stu-event-facts">
        <li>
            <?= icon('calendar', 16) ?>
            <span><?= e(event_timing_label($event)) ?><?php if (!empty($event['event_date'])): ?> · <?= e(format_date($event['event_date'])) ?><?php endif; ?></span>
        </li>
        <li>
            <?= icon('clock', 16) ?>
            <span><?= trim((string) ($event['event_time'] ?? '')) !== '' ? e($event['event_time']) : 'Time to be announced' ?></span>
        </li>
        <?php if (trim((string) ($event['location'] ?? '')) !== ''): ?>
            <li>
                <?= icon('map-pin', 16) ?>
                <span><?= e($event['location']) ?></span>
            </li>
        <?php endif; ?>
        <?php if (($event['status'] ?? '') === 'cancelled'): ?>
            <li><span class="badge badge-cancelled">Cancelled</span></li>
        <?php endif; ?>
    </ul>

    <?php if (trim((string) ($event['description'] ?? '')) !== ''): ?>
        <div class="stu-event-body">
            <p><?= nl2br(e((string) $event['description'])) ?></p>
        </div>
    <?php endif; ?>
</article>

<?php if ($others): ?>
    <section class="stu-block">
        <div class="stu-block-head">
            <p class="eyebrow">Also coming up</p>
            <a class="text-link" href="<?= e(url('student/events.php')) ?>">All events</a>
        </div>
        <div class="stu-event-list">
            <?php foreach ($others as $item): ?>
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

<p class="stu-event-back">
    <a class="text-link" href="<?= e(url('student/events.php')) ?>">All events</a>
    ·
    <a class="text-link" href="<?= e(url('student/calendar.php' . (!empty($event['event_date']) ? '?month=' . substr((string) $event['event_date'], 0, 7) : ''))) ?>">Calendar</a>
</p>

<?php student_footer('events'); ?>
