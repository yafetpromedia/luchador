<?php

declare(strict_types=1);

function calendar_href(string $base, string $month, int $day = 0, string $category = 'all', string $hash = ''): string
{
    $query = ['month' => $month];
    if ($day > 0) {
        $query['day'] = (string) $day;
    }
    if ($category !== '' && $category !== 'all') {
        $query['category'] = $category;
    }
    return url($base . '?' . http_build_query($query) . $hash);
}

function calendar_date_in_month(?string $date, string $start, string $endExclusive): bool
{
    $date = substr(trim((string) $date), 0, 10);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    return $date >= $start && $date < $endExclusive;
}

function calendar_entry(
    string $kind,
    string $title,
    string $date,
    string $href,
    ?string $time = null,
    ?string $location = null,
    string $detail = ''
): array {
    $labels = [
        'event' => 'Class event',
        'graduation' => 'Graduation',
        'decision' => 'Class decision',
        'vote' => 'Class poll',
        'payment' => 'Payment',
    ];
    return [
        'id' => $kind . ':' . md5($title . '|' . $date . '|' . $href),
        'kind' => $kind,
        'kind_label' => $labels[$kind] ?? 'Class date',
        'title' => $title,
        'event_date' => $date,
        'event_time' => $time,
        'location' => $location,
        'detail' => $detail,
        'href' => $href,
        'category' => $kind,
    ];
}

/**
 * Student class calendar: real dated class work only — events, votes, payments, graduation.
 */
function class_calendar_month(DateTimeImmutable $month): array
{
    $start = $month->format('Y-m-01');
    $endExclusive = $month->modify('first day of next month')->format('Y-m-d');
    $items = [];

    foreach (calendar_month_events($month, null, 'class')['rows'] as $row) {
        $date = substr((string) ($row['event_date'] ?? ''), 0, 10);
        if ($date === '') {
            continue;
        }
        $items[] = calendar_entry(
            'event',
            (string) ($row['title'] ?? 'Class event'),
            $date,
            'student/event.php?id=' . (int) ($row['id'] ?? 0),
            trim((string) ($row['event_time'] ?? '')) ?: null,
            trim((string) ($row['location'] ?? '')) ?: null,
            trim((string) ($row['category'] ?? '')) !== '' ? status_label((string) $row['category']) : ''
        );
    }

    $grad = trim((string) setting('graduation_date'));
    if (calendar_date_in_month($grad, $start, $endExclusive)) {
        $items[] = calendar_entry(
            'graduation',
            'Graduation day',
            substr($grad, 0, 10),
            'student/graduation.php',
            null,
            null,
            'Class milestone'
        );
    }

    if (!function_exists('student_visible_polls')) {
        require_once __DIR__ . '/interactions.php';
    }
    if (can('polls.view')) {
        foreach ([true, false] as $isDecision) {
            foreach (student_visible_polls($isDecision) as $poll) {
                $kind = $isDecision ? 'decision' : 'vote';
                $title = (string) ($poll['title'] ?? ($isDecision ? 'Class decision' : 'Class poll'));
                $href = 'student/poll.php?id=' . (int) ($poll['id'] ?? 0);
                $status = poll_effective_status($poll);
                foreach (['starts_at' => 'Voting opens', 'ends_at' => 'Voting closes'] as $field => $verb) {
                    $raw = (string) ($poll[$field] ?? '');
                    if ($raw === '') {
                        continue;
                    }
                    $date = date('Y-m-d', strtotime($raw));
                    if (!calendar_date_in_month($date, $start, $endExclusive)) {
                        continue;
                    }
                    if ($field === 'starts_at' && $status !== 'scheduled') {
                        continue;
                    }
                    if ($field === 'ends_at' && !in_array($status, ['active', 'scheduled', 'closed'], true)) {
                        continue;
                    }
                    $time = date('H:i', strtotime($raw));
                    if ($time === '00:00') {
                        $time = null;
                    }
                    $items[] = calendar_entry(
                        $kind,
                        $verb . ': ' . $title,
                        $date,
                        $href,
                        $time,
                        null,
                        $isDecision ? 'Counts for the class' : 'Class poll'
                    );
                }
            }
        }
    }

    if (!function_exists('payment_items')) {
        require_once __DIR__ . '/payments.php';
    }
    foreach (payment_items(true) as $item) {
        $end = substr((string) ($item['end_date'] ?? ''), 0, 10);
        if (!calendar_date_in_month($end, $start, $endExclusive)) {
            continue;
        }
        $items[] = calendar_entry(
            'payment',
            'Payment due: ' . (string) ($item['title'] ?? 'Class payment'),
            $end,
            'student/payment.php',
            null,
            null,
            !empty($item['target_amount']) ? (format_etb($item['target_amount']) ?: '') : 'Open fee'
        );
    }

    usort($items, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['event_date'] ?? ''), (string) ($b['event_date'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) ($a['event_time'] ?? ''), (string) ($b['event_time'] ?? ''));
    });

    $byDay = [];
    foreach ($items as $item) {
        $day = event_day_number((string) ($item['event_date'] ?? ''));
        if ($day < 1) {
            continue;
        }
        $byDay[$day][] = $item;
    }

    return ['rows' => $items, 'by_day' => $byDay];
}

function next_class_calendar_stamp(): string
{
    $today = date('Y-m-d');
    $candidates = [];

    $nextEvent = next_class_event();
    if ($nextEvent && !empty($nextEvent['event_date']) && (string) $nextEvent['event_date'] >= $today) {
        $candidates[] = substr((string) $nextEvent['event_date'], 0, 10);
    }

    $grad = trim((string) setting('graduation_date'));
    if ($grad !== '' && $grad >= $today) {
        $candidates[] = substr($grad, 0, 10);
    }

    if (!function_exists('student_visible_polls')) {
        require_once __DIR__ . '/interactions.php';
    }
    if (can('polls.view')) {
        foreach ([true, false] as $isDecision) {
            foreach (student_visible_polls($isDecision) as $poll) {
                foreach (['starts_at', 'ends_at'] as $field) {
                    $raw = (string) ($poll[$field] ?? '');
                    if ($raw === '') {
                        continue;
                    }
                    $date = date('Y-m-d', strtotime($raw));
                    if ($date >= $today) {
                        $candidates[] = $date;
                    }
                }
            }
        }
    }

    if (!function_exists('payment_items')) {
        require_once __DIR__ . '/payments.php';
    }
    foreach (payment_items(true) as $item) {
        $end = substr((string) ($item['end_date'] ?? ''), 0, 10);
        if ($end !== '' && $end >= $today) {
            $candidates[] = $end;
        }
    }

    if ($candidates === []) {
        return date('Y-m');
    }
    sort($candidates);
    return substr($candidates[0], 0, 7);
}

function render_class_calendar(array $opts): void
{
    $month = $opts['month'];
    $cal = $opts['cal'] ?? ['rows' => [], 'by_day' => []];
    $day = (int) ($opts['day'] ?? 0);
    $base = (string) ($opts['base'] ?? 'index.php');
    $hash = (string) ($opts['hash'] ?? '');
    $category = (string) ($opts['category'] ?? 'all');
    $eventBase = (string) ($opts['event_base'] ?? 'event.php');

    $stamp = $month->format('Y-m');
    $daysInMonth = (int) $month->format('t');
    $pad = (int) $month->format('N') - 1;
    $prevMonth = $month->modify('-1 month');
    $nextMonth = $month->modify('+1 month');
    $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $isThisMonth = $stamp === date('Y-m');
    $today = $isThisMonth ? (int) date('j') : 0;
    $dayEvents = $day > 0 ? ($cal['by_day'][$day] ?? []) : [];
    $monthRows = $cal['rows'] ?? [];
    $next = $opts['next'] ?? next_published_event();
    $nextStamp = $next && !empty($next['event_date']) ? substr((string) $next['event_date'], 0, 7) : '';
    $nextLabel = (string) ($opts['next_label'] ?? 'Next dated item');
    $emptyDay = (string) ($opts['empty_day'] ?? 'Nothing scheduled for the class on this day.');
    $emptyMonth = (string) ($opts['empty_month'] ?? ('No dated class items in ' . $month->format('F Y') . '.'));
    $showLegend = !empty($opts['legend']);
    $studentRows = !empty($opts['student_mode']) || str_contains($eventBase, 'student/');
    $count = count($monthRows);
    ?>
    <div class="class-cal" data-calendar data-cal-month="<?= e($month->format('F')) ?>">
    <div class="cal-toolbar">
        <a class="btn btn-sm btn-ghost" href="<?= e(calendar_href($base, $prevMonth->format('Y-m'), 0, $category, $hash)) ?>"><?= icon('chevron-left', 16) ?> <?= e($prevMonth->format('M')) ?></a>
        <h3><?= e($month->format('F Y')) ?></h3>
        <a class="btn btn-sm btn-ghost" href="<?= e(calendar_href($base, $nextMonth->format('Y-m'), 0, $category, $hash)) ?>"><?= e($nextMonth->format('M')) ?> <?= icon('chevron-right', 16) ?></a>
    </div>
    <?php if ($count > 0): ?>
        <p class="cal-summary"><?= (int) $count ?> dated class item<?= $count === 1 ? '' : 's' ?> this month. Tap a marked day.</p>
    <?php endif; ?>
    <?php if ($showLegend): ?>
        <ul class="cal-legend" aria-label="Calendar key">
            <li><span class="cal-dot cal-dot-event"></span> Event</li>
            <li><span class="cal-dot cal-dot-decision"></span> Decision</li>
            <li><span class="cal-dot cal-dot-vote"></span> Poll</li>
            <li><span class="cal-dot cal-dot-payment"></span> Payment</li>
            <li><span class="cal-dot cal-dot-graduation"></span> Graduation</li>
        </ul>
    <?php endif; ?>
    <?php if (!$isThisMonth): ?>
        <p class="cal-jump">
            <a href="<?= e(calendar_href($base, date('Y-m'), 0, $category, $hash)) ?>">This month</a>
            <?php if ($nextStamp !== '' && $nextStamp !== $stamp): ?>
                · <a href="<?= e(calendar_href($base, $nextStamp, 0, $category, $hash)) ?>"><?= e($nextLabel) ?></a>
            <?php endif; ?>
        </p>
    <?php elseif ($nextStamp !== '' && $nextStamp !== $stamp && $count === 0): ?>
        <p class="cal-jump">
            <a href="<?= e(calendar_href($base, $nextStamp, 0, $category, $hash)) ?>"><?= e($nextLabel) ?></a>
            <?php if (!empty($next['title'])): ?> · <?= e((string) $next['title']) ?><?php endif; ?>
        </p>
    <?php endif; ?>
    <table class="cal cal-fit">
        <caption class="sr-only"><?= e($month->format('F Y')) ?> class calendar</caption>
        <thead>
            <tr>
                <?php foreach ($weekdays as $wd): ?>
                    <th scope="col"><?= e($wd) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
            <?php
            $cells = $pad + $daysInMonth;
            $rows = (int) ceil($cells / 7) * 7;
            for ($i = 0; $i < $rows; $i++):
                if ($i > 0 && $i % 7 === 0) {
                    echo '</tr><tr>';
                }
                $d = $i - $pad + 1;
                if ($d < 1 || $d > $daysInMonth):
            ?>
                <td class="cal-empty"></td>
            <?php else:
                $items = $cal['by_day'][$d] ?? [];
                $has = $items !== [];
                $href = calendar_href($base, $stamp, $d, $category, $hash);
                $classes = 'cal-day';
                if ($has) {
                    $classes .= ' has-events';
                }
                if ($day === $d) {
                    $classes .= ' is-selected';
                }
                if ($today === $d) {
                    $classes .= ' is-today';
                }
                $first = $items[0] ?? null;
                $kinds = [];
                foreach ($items as $item) {
                    $kinds[(string) ($item['kind'] ?? $item['category'] ?? 'event')] = true;
                }
            ?>
                <td>
                    <a class="<?= e($classes) ?>" href="<?= e($href) ?>" data-cal-day="<?= (int) $d ?>">
                        <span class="cal-num"><?= $d ?></span>
                        <?php if ($has): ?>
                            <span class="cal-dots" aria-hidden="true">
                                <?php foreach (array_keys($kinds) as $kind): ?>
                                    <i class="cal-dot cal-dot-<?= e(preg_replace('/[^a-z]/', '', strtolower($kind)) ?: 'event') ?>"></i>
                                <?php endforeach; ?>
                            </span>
                            <?php if ($first): ?>
                                <span class="cal-title"><?= e((string) $first['title']) ?><?php if (count($items) > 1): ?> <b>+<?= count($items) - 1 ?></b><?php endif; ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </a>
                </td>
            <?php endif; endfor; ?>
            </tr>
        </tbody>
    </table>

        <div class="cal-day-panel" data-cal-panel <?= $day > 0 ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3 data-cal-heading><?= $day > 0 ? e($month->format('F') . ' ' . $day) : '' ?></h3>
                <a href="<?= e(calendar_href($base, $stamp, 0, $category, $hash)) ?>" data-cal-clear>All in <?= e($month->format('F')) ?></a>
            </div>
            <p class="muted" data-cal-empty <?= ($day > 0 && !$dayEvents) ? '' : 'hidden' ?>><?= e($emptyDay) ?></p>
        </div>

    <?php if ($monthRows): ?>
        <?php if ($studentRows): ?>
            <div class="stu-event-list cal-agenda-list">
                <?php foreach ($monthRows as $event): ?>
                    <?php
                    $eventDay = event_day_number((string) ($event['event_date'] ?? ''));
                    $itemHref = trim((string) ($event['href'] ?? ''));
                    if ($itemHref === '') {
                        $itemHref = $eventBase . '?id=' . (int) ($event['id'] ?? 0);
                    }
                    $kindLabel = (string) ($event['kind_label'] ?? '');
                    if ($kindLabel === '' && !empty($event['category'])) {
                        $kindLabel = status_label((string) $event['category']);
                    }
                    ?>
                    <a class="stu-event-row" data-cal-item="<?= (int) $eventDay ?>" <?= ($day > 0 && $eventDay !== $day) ? 'hidden' : '' ?> href="<?= e(url($itemHref)) ?>">
                        <time class="stu-event-when" datetime="<?= e((string) ($event['event_date'] ?? '')) ?>">
                            <span><?= e(format_date($event['event_date'] ?? null, 'M') ?: 'TBA') ?></span>
                            <b><?= e(format_date($event['event_date'] ?? null, 'j') ?: '—') ?></b>
                        </time>
                        <div>
                            <?php if ($kindLabel !== ''): ?>
                                <p class="eyebrow"><?= e($kindLabel) ?></p>
                            <?php endif; ?>
                            <strong><?= e((string) ($event['title'] ?? 'Class item')) ?></strong>
                            <p class="muted">
                                <?= !empty($event['event_date']) ? e(format_date($event['event_date'])) : 'Date to be announced' ?>
                                <?php if (!empty($event['event_time'])): ?> · <?= e((string) $event['event_time']) ?><?php endif; ?>
                                <?php if (!empty($event['location'])): ?> · <?= e((string) $event['location']) ?><?php endif; ?>
                                <?php if (!empty($event['detail'])): ?> · <?= e((string) $event['detail']) ?><?php endif; ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
        <ol class="editorial-list cal-agenda">
            <?php foreach ($monthRows as $event): ?>
                <?php
                $eventDay = event_day_number((string) ($event['event_date'] ?? ''));
                $itemHref = trim((string) ($event['href'] ?? ''));
                if ($itemHref === '') {
                    $itemHref = $eventBase . '?id=' . (int) ($event['id'] ?? 0);
                }
                ?>
                <li data-cal-item="<?= (int) $eventDay ?>" <?= ($day > 0 && $eventDay !== $day) ? 'hidden' : '' ?>>
                    <span class="editorial-index"><?= !empty($event['event_date']) ? e(format_date($event['event_date'], 'j')) : '—' ?></span>
                    <div>
                        <strong><a href="<?= e(url($itemHref)) ?>"><?= e((string) ($event['title'] ?? 'Event')) ?></a></strong>
                        <p class="muted">
                            <?= !empty($event['event_date']) ? e(format_date($event['event_date'])) : 'Date to be announced' ?>
                            <?php if (!empty($event['event_time'])): ?> · <?= e((string) $event['event_time']) ?><?php endif; ?>
                            <?php if (!empty($event['location'])): ?> · <?= e((string) $event['location']) ?><?php endif; ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    <?php endif; ?>
        <p class="muted cal-empty-month" data-cal-empty-month <?= $monthRows || $day > 0 ? 'hidden' : '' ?>><?= e($emptyMonth) ?></p>
        <?php if ($next && !empty($next['event_date']) && $nextStamp !== $stamp): ?>
            <p class="cal-jump" data-cal-next-month <?= $monthRows || $day > 0 ? 'hidden' : '' ?>>
                <a href="<?= e(calendar_href($base, $nextStamp, 0, $category, $hash)) ?>">Go to <?= e(format_date($next['event_date'], 'F Y')) ?></a>
                · <?= e((string) ($next['title'] ?? 'Next item')) ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}
