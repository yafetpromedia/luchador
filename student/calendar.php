<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/calendar-view.php';

student_boot();

$monthParam = request_str('month');
$month = $monthParam !== '' ? parse_year_month($monthParam) : default_calendar_month('class');
$cal = calendar_month_events($month, null, 'class');
$day = request_int('day');
if ($day < 1 || $day > (int) $month->format('t')) {
    $day = 0;
}

student_header('Calendar', 'calendar', lead: 'Days with a class event are marked. Open a day to read it.');
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
    'next' => next_class_event(),
]);
?>
</div>

<?php student_footer('calendar'); ?>
