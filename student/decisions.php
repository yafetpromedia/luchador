<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/interactions.php';

student_boot();

$items = can('polls.view') ? visible_polls(true) : [];
student_header('Decisions', 'decisions', lead: 'Official class votes. These count for the class, not casual polls.');
?>

<?php if (!can('polls.view')): ?>
    <div class="empty-state">
        <h2>Decisions are not available</h2>
        <p>Ask a class administrator if you should be able to vote.</p>
    </div>
<?php elseif (!$items): ?>
    <div class="empty-state">
        <h2>No class decisions yet</h2>
        <p>When the committee opens an official vote, it appears here. Nothing is invented.</p>
    </div>
<?php else: ?>
    <div class="stu-stack">
        <?php foreach ($items as $poll): ?>
            <?php
            $status = poll_effective_status($poll);
            $closes = interaction_closes_in($poll['ends_at'] ?? null);
            ?>
            <a class="panel interact-link" href="<?= e(url('student/poll.php?id=' . (int) $poll['id'])) ?>">
                <p class="eyebrow">Class decision</p>
                <h2><?= e($poll['title']) ?></h2>
                <p class="muted">
                    <?= e(status_label($status)) ?>
                    <?= $closes !== '' ? ' · ' . e($closes) : '' ?>
                    <?= !empty($poll['anonymous']) ? ' · Anonymous' : '' ?>
                </p>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php student_footer('decisions'); ?>
