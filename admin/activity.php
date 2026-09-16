<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

admin_boot('activity.view');

$page = max(1, request_int('page', 1));
$perPage = 25;
$total = table_exists(db(), 'audit_logs')
    ? (int) db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()
    : 0;
$pageData = pagination($total, $page, $perPage);
$rows = [];
if ($total > 0) {
    $stmt = db()->prepare('SELECT * FROM audit_logs ORDER BY id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $pageData['offset']);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

admin_header('Activity', 'activity');
admin_page_head('Administrative changes are recorded here. Student records are never removed by this log.');
?>

<?php if (!$rows): ?>
    <?php admin_empty('No activity yet', 'Changes to students, uniforms, reports, and class content will appear here.'); ?>
<?php else: ?>
    <div class="activity-feed">
    <?php foreach ($rows as $row): ?>
        <article class="activity-item">
            <strong><?= e(audit_sentence($row)) ?></strong>
            <?php if (($row['target_type'] ?? '') === 'student' && !empty($row['target_id']) && can('students.view')): ?>
                <div><a href="<?= e(url('admin/students.php?view=' . (int) $row['target_id'])) ?>">View student</a></div>
            <?php endif; ?>
            <span class="muted"><?= e(format_when($row['created_at'])) ?></span>
        </article>
    <?php endforeach; ?>
    </div>
    <?php render_pagination($pageData); ?>
<?php endif; ?>

<?php admin_footer(); ?>
