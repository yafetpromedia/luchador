<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

student_boot();

$items = table_exists(db(), 'announcements') ? published_announcements() : [];
student_header('Announcements', 'announcements', lead: 'Official class updates.');
?>

<?php if (!$items): ?>
    <div class="empty-state">
        <h2>No announcements yet</h2>
        <p>Class announcements appear here once they are published.</p>
    </div>
<?php else: ?>
    <ol class="editorial-list editorial-list-full">
        <?php foreach ($items as $i => $item): ?>
            <li>
                <span class="editorial-index"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                <div>
                    <strong><?= e($item['title']) ?></strong>
                    <p class="muted"><?= e(format_date($item['announced_on']) ?: format_relative($item['created_at'] ?? null)) ?></p>
                    <?php if (!empty($item['description'])): ?>
                        <p><?= nl2br(e($item['description'])) ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php student_footer('announcements'); ?>
