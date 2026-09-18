<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

student_boot();

$date = setting('graduation_date');
$title = setting('graduation_title', 'Graduation');
$message = setting('graduation_message');
$events = class_events_by_category('graduation', 6);

student_header('Graduation', 'graduation', lead: 'Countdown and graduation events, once they are set.');
?>

<section class="panel stu-grad-card">
    <p class="eyebrow">Grade <?= e(class_grade()) ?></p>
    <h2><?= e($title) ?></h2>
    <?php if ($date): ?>
        <?php render_countdown($date); ?>
    <?php else: ?>
        <p class="muted">Graduation details appear here once they are set.</p>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
        <p><?= nl2br(e($message)) ?></p>
    <?php endif; ?>
</section>

<?php if ($events): ?>
<section class="panel">
    <h2>Graduation events</h2>
    <ol class="editorial-list">
        <?php foreach ($events as $i => $item): ?>
            <li>
                <a href="<?= e(url('student/event.php?id=' . (int) $item['id'])) ?>">
                    <span class="editorial-index"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <div>
                        <strong><?= e($item['title']) ?></strong>
                        <p class="muted"><?= e(format_date($item['event_date'] ?? null) ?: 'Date to be announced') ?></p>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<?php student_footer('graduation'); ?>
