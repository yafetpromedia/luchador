<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/calendar-view.php';
require_once dirname(__DIR__) . '/includes/interactions.php';
require_once dirname(__DIR__) . '/includes/payments.php';

student_boot();

$monthParam = request_str('month');
if ($monthParam !== '') {
    $month = parse_year_month($monthParam);
} else {
    $stamp = next_class_calendar_stamp();
    $month = parse_year_month($stamp);
}
$cal = class_calendar_month($month);
$day = request_int('day');
$daysInMonth = (int) $month->format('t');
if ($day < 1 || $day > $daysInMonth) {
    $day = 0;
}

// Prefer today when this month has something today and no day was chosen.
if ($day === 0 && $month->format('Y-m') === date('Y-m')) {
    $today = (int) date('j');
    if (!empty($cal['by_day'][$today])) {
        $day = $today;
    }
}

$nextItem = null;
$probeMonth = new DateTimeImmutable('first day of this month');
for ($i = 0; $i < 18; $i++) {
    foreach (class_calendar_month($probeMonth)['rows'] as $row) {
        if ((string) ($row['event_date'] ?? '') >= date('Y-m-d')) {
            $nextItem = $row;
            break 2;
        }
    }
    $probeMonth = $probeMonth->modify('+1 month');
}
if (!$nextItem) {
    $fallback = next_class_event();
    if ($fallback && !empty($fallback['event_date'])) {
        $nextItem = [
            'title' => (string) ($fallback['title'] ?? 'Next class event'),
            'event_date' => (string) $fallback['event_date'],
        ];
    }
}

student_header(
    'Calendar',
    'calendar',
    lead: 'Class month board — events, votes, payment deadlines, and graduation. Only real dates appear.'
);
?>

<div class="stu-event-nav">
    <a class="chip" href="<?= e(url('student/events.php')) ?>">Schedule</a>
    <a class="chip is-active" href="<?= e(url('student/calendar.php')) ?>">Calendar</a>
</div>

<div class="stu-cal">
<?php
render_class_calendar([
    'month' => $month,
    'cal' => $cal,
    'day' => $day,
    'base' => 'student/calendar.php',
    'hash' => '',
    'category' => 'all',
    'event_base' => 'student/event.php',
    'student_mode' => true,
    'legend' => true,
    'next' => $nextItem,
    'next_label' => 'Jump to next date',
    'empty_day' => 'Nothing for the class on this day.',
    'empty_month' => 'No dated class items in ' . $month->format('F Y') . '. When the committee publishes an event, vote deadline, payment due date, or graduation day, it shows here.',
]);
?>
</div>

<?php student_footer('calendar'); ?>
