<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

student_boot();

$items = class_achievements();
student_header('Achievements', 'achievements', lead: 'What the class has recorded this year.');
?>

<?php if (!$items): ?>
    <div class="empty-state">
        <h2>No achievements yet</h2>
        <p>Published class achievements appear here.</p>
    </div>
<?php else: ?>
    <div class="stu-stack">
    <?php foreach ($items as $item): ?>
        <article class="panel">
            <?php if (!empty($item['image'])): ?>
                <div class="stu-feature-photo stu-feature-photo-sm">
                    <img src="<?= e(url($item['image'])) ?>" alt="">
                </div>
            <?php endif; ?>
            <p class="eyebrow"><?= e(status_label((string) ($item['category'] ?? 'class'))) ?></p>
            <h2><?= e($item['title']) ?></h2>
            <?php if (!empty($item['achieved_on'])): ?>
                <p class="muted"><?= e(format_date($item['achieved_on'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($item['description'])): ?>
                <p><?= nl2br(e($item['description'])) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php student_footer('achievements'); ?>
