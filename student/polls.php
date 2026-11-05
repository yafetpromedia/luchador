<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/interactions.php';

student_boot();

$items = can('polls.view') ? student_visible_polls(false) : [];
$upcomingDecisions = can('polls.view') ? array_values(array_filter(upcoming_polls(), static fn (array $row): bool => poll_is_decision($row))) : [];
student_header('Polls', 'polls', lead: 'Casual class votes. Official decisions live under Class decisions.');
?>

<?php if (!can('polls.view')): ?>
    <div class="empty-state">
        <h2>Polls are not available</h2>
        <p>Ask a class administrator if you should be able to vote.</p>
    </div>
<?php elseif (!$items): ?>
    <div class="empty-state">
        <h2>No class polls yet</h2>
        <p>When the committee opens a poll, it appears here. Nothing is invented.</p>
        <?php if ($upcomingDecisions !== []): ?>
            <p><a class="text-link" href="<?= e(url('student/decisions.php')) ?>">There is a class decision waiting — open Class decisions</a></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="stu-stack">
        <?php foreach ($items as $poll): ?>
            <?php
            $status = poll_effective_status($poll);
            $closes = interaction_closes_in($poll['ends_at'] ?? null);
            $opens = $status === 'scheduled' && !empty($poll['starts_at']) ? format_when((string) $poll['starts_at']) : '';
            ?>
            <a class="panel interact-link" href="<?= e(url('student/poll.php?id=' . (int) $poll['id'])) ?>">
                <p class="eyebrow"><?= e(poll_kind_label($poll)) ?></p>
                <h2><?= e($poll['title']) ?></h2>
                <p class="muted">
                    <?= e(status_label($status)) ?>
                    <?php if ($opens !== ''): ?> · Opens <?= e($opens) ?><?php endif; ?>
                    <?= $closes !== '' ? ' · ' . e($closes) : '' ?>
                    <?= !empty($poll['anonymous']) ? ' · Anonymous' : '' ?>
                </p>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php student_footer('polls'); ?>
