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
    $list = $day > 0 ? $dayEvents : ($cal['rows'] ?? []);
    $next = next_published_event();
    $nextStamp = $next && !empty($next['event_date']) ? substr((string) $next['event_date'], 0, 7) : '';
    ?>
    <div class="cal-toolbar">
        <a class="btn btn-sm btn-ghost" href="<?= e(calendar_href($base, $prevMonth->format('Y-m'), 0, $category, $hash)) ?>"><?= icon('chevron-left', 16) ?> <?= e($prevMonth->format('M')) ?></a>
        <h3><?= e($month->format('F Y')) ?></h3>
        <a class="btn btn-sm btn-ghost" href="<?= e(calendar_href($base, $nextMonth->format('Y-m'), 0, $category, $hash)) ?>"><?= e($nextMonth->format('M')) ?> <?= icon('chevron-right', 16) ?></a>
    </div>
    <?php if (!$isThisMonth): ?>
        <p class="cal-jump">
            <a href="<?= e(calendar_href($base, date('Y-m'), 0, $category, $hash)) ?>">This month</a>
            <?php if ($nextStamp !== '' && $nextStamp !== $stamp): ?>
                · <a href="<?= e(calendar_href($base, $nextStamp, 0, $category, $hash)) ?>">Next event</a>
            <?php endif; ?>
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
            ?>
                <td>
                    <a class="<?= e($classes) ?>" href="<?= e($href) ?>">
                        <span class="cal-num"><?= $d ?></span>
                        <?php if ($has && $first): ?>
                            <span class="cal-title"><?= e($first['title']) ?><?php if (count($items) > 1): ?> <b>+<?= count($items) - 1 ?></b><?php endif; ?></span>
                        <?php endif; ?>
                    </a>
                </td>
            <?php endif; endfor; ?>
            </tr>
        </tbody>
    </table>

    <?php if ($day > 0): ?>
        <div class="cal-day-panel">
            <div class="panel-head">
                <h3><?= e($month->format('F')) ?> <?= (int) $day ?></h3>
                <a href="<?= e(calendar_href($base, $stamp, 0, $category, $hash)) ?>">All in <?= e($month->format('F')) ?></a>
            </div>
            <?php if (!$dayEvents): ?>
                <p class="muted">No class events on this day.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($list): ?>
        <?php $studentRows = str_contains($eventBase, 'student/'); ?>
        <?php if ($studentRows): ?>
            <div class="stu-event-list" style="margin-top:1rem">
                <?php foreach ($list as $event): ?>
                    <a class="stu-event-row" href="<?= e(url($eventBase . '?id=' . (int) $event['id'])) ?>">
                        <time class="stu-event-when" datetime="<?= e((string) ($event['event_date'] ?? '')) ?>">
                            <span><?= e(format_date($event['event_date'] ?? null, 'M') ?: 'TBA') ?></span>
                            <b><?= e(format_date($event['event_date'] ?? null, 'j') ?: '—') ?></b>
                        </time>
                        <div>
                            <strong><?= e($event['title']) ?></strong>
                            <p class="muted">
                                <?= !empty($event['event_date']) ? e(format_date($event['event_date'])) : 'Date to be announced' ?>
                                <?php if (!empty($event['event_time'])): ?> · <?= e($event['event_time']) ?><?php endif; ?>
                                <?php if (!empty($event['location'])): ?> · <?= e($event['location']) ?><?php endif; ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
        <ol class="editorial-list cal-agenda">
            <?php foreach ($list as $event): ?>
                <li>
                    <span class="editorial-index"><?= !empty($event['event_date']) ? e(format_date($event['event_date'], 'j')) : '—' ?></span>
                    <div>
                        <strong><a href="<?= e(url($eventBase . '?id=' . (int) $event['id'])) ?>"><?= e($event['title']) ?></a></strong>
                        <p class="muted">
                            <?= !empty($event['event_date']) ? e(format_date($event['event_date'])) : 'Date to be announced' ?>
                            <?php if (!empty($event['event_time'])): ?> · <?= e($event['event_time']) ?><?php endif; ?>
                            <?php if (!empty($event['location'])): ?> · <?= e($event['location']) ?><?php endif; ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    <?php elseif ($day === 0): ?>
        <p class="muted cal-empty-month">No published events in <?= e($month->format('F Y')) ?>.</p>
        <?php if ($next && !empty($next['event_date']) && $nextStamp !== $stamp): ?>
            <p class="cal-jump">
                <a href="<?= e(calendar_href($base, $nextStamp, 0, $category, $hash)) ?>">Go to <?= e(format_date($next['event_date'], 'F Y')) ?></a>
                · <?= e($next['title']) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}
