<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot('dashboard.view');
$d = dashboard_counts();
$activity = recent_audit(6);
$drafts = unpublished_counts();
$total = max(1, (int) $d['students']);
$committeeView = !is_super_admin();
admin_header('Overview', 'index');

$work = [];
if (can_any(['events.view', 'events.edit', 'events.create'])) {
    $work[] = ['n' => (int) $drafts['events'], 'label' => 'Draft events', 'href' => 'admin/events.php'];
}
if (can_any(['announcements.view', 'announcements.edit', 'announcements.create'])) {
    $work[] = ['n' => (int) $drafts['announcements'], 'label' => 'Draft announcements', 'href' => 'admin/announcements.php'];
}
if (can_any(['gallery.view', 'gallery.upload'])) {
    $work[] = ['n' => (int) $drafts['gallery'], 'label' => 'Gallery uploads in draft', 'href' => 'admin/gallery.php'];
}
if (can_any(['uniforms.view', 'uniforms.edit'])) {
    $work[] = ['n' => (int) $drafts['uniforms_pending'], 'label' => 'Uniforms still pending', 'href' => 'admin/uniforms.php?status=pending'];
}
if (can_any(['students.view', 'students.edit'])) {
    $work[] = ['n' => (int) ($drafts['profile_requests'] ?? 0), 'label' => 'Profile changes waiting', 'href' => 'admin/students.php#profile-requests'];
}
if (can_any(['payments.view', 'payments.manage'])) {
    $work[] = ['n' => (int) ($drafts['payment_requests'] ?? 0), 'label' => 'Payments to verify', 'href' => 'admin/payments.php#verify'];
}
if (can_any(['questions.view', 'questions.moderate'])) {
    $work[] = ['n' => (int) ($drafts['question_pending'] ?? 0), 'label' => 'Anonymous asks waiting', 'href' => 'admin/questions.php?filter=pending'];
}
if (can_any(['questions.view', 'questions.moderate'])) {
    $work[] = ['n' => (int) ($drafts['response_pending'] ?? 0), 'label' => 'Answers waiting for approval', 'href' => 'admin/questions.php?filter=responses'];
}
?>

<p class="work-hello"><?= e(greeting()) ?>, <?= e(first_name(user_display_name())) ?>.</p>

<?php if ($work): ?>
<nav class="uniform-tabs overview-work" aria-label="Needs attention">
    <?php foreach ($work as $item): ?>
        <a href="<?= e(url($item['href'])) ?>">
            <strong><?= (int) $item['n'] ?></strong>
            <span><?= e($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php elseif ($committeeView): ?>
<section class="panel">
    <p class="muted">No assigned areas yet. A Super Admin can add permissions for you.</p>
</section>
<?php endif; ?>

<?php if (!$committeeView && can_any(['uniforms.view', 'uniforms.edit'])): ?>
<section class="panel dash-metrics-panel">
    <div class="dash-metrics">
        <?php
        $strip = [
            ['Collected', $d['collected'], 'admin/uniforms.php?status=collected', 'collected'],
            ['Pending', $d['pending'], 'admin/uniforms.php?status=pending', 'pending'],
            ['Ordered', $d['ordered'], 'admin/uniforms.php?status=ordered', 'ordered'],
            ['Ready', $d['ready'], 'admin/uniforms.php?status=ready', 'ready'],
            ['Missing size', $d['missing'], 'admin/uniforms.php?status=missing', 'missing'],
        ];
        foreach ($strip as [$label, $count, $path, $key]):
            $pct = (int) round(((int) $count) / $total * 100);
        ?>
            <a class="dash-metric" href="<?= e(url($path)) ?>">
                <span class="dash-metric-label"><?= e($label) ?></span>
                <span class="dash-metric-value"><?= (int) $count ?></span>
                <span class="dash-metric-track"><span class="dash-metric-fill dash-metric-fill-<?= e($key) ?>" style="width: <?= $pct ?>%"></span></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php $showRoster = !$committeeView && can('students.view'); ?>
<?php if ($showRoster): ?><div class="two-col"><?php endif; ?>
    <?php if ($showRoster): ?>
    <section class="panel">
        <div class="panel-head">
            <h2>Roster</h2>
            <a href="<?= e(url('admin/students.php')) ?>">Open roster</a>
        </div>
        <?php if (!$d['recent_students']): ?>
            <p class="muted">No students yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>ID</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['recent_students'] as $s): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('admin/students.php?view=' . (int) $s['id'])) ?>" class="student-open" style="text-decoration:none">
                                    <?= admin_person_cell((string) $s['student_name'], $s['photo'] ?? null, (string) ($s['size'] ?: '')) ?>
                                </a>
                            </td>
                            <td><?= e($s['student_code'] ?: '—') ?></td>
                            <td><?= admin_status_badge((string) ($s['status'] ?? 'pending')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-head">
            <h2>Activity</h2>
            <?php if (can('activity.view')): ?><a href="<?= e(url('admin/activity.php')) ?>">All</a><?php endif; ?>
        </div>
        <?php if (!$activity): ?>
            <p class="muted">No administrative activity recorded yet.</p>
        <?php else: ?>
            <?php foreach ($activity as $item): ?>
                <div class="activity-item">
                    <strong><?= e(audit_sentence($item)) ?></strong>
                    <span class="muted"><?= e(format_when($item['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="quick-actions" style="margin-top:0.9rem">
            <?php if (can('events.create')): ?><a class="btn btn-sm" href="<?= e(url('admin/events.php')) ?>">New event</a><?php endif; ?>
            <?php if (can('announcements.create')): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('admin/announcements.php')) ?>">Announcement</a><?php endif; ?>
            <?php if (can('gallery.upload')): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('admin/gallery.php')) ?>">Upload photos</a><?php endif; ?>
            <?php if (can('polls.create')): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('admin/polls.php')) ?>">New poll</a><?php endif; ?>
            <?php if (can('polls.create')): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('admin/polls.php?filter=decisions')) ?>">Class decision</a><?php endif; ?>
            <?php if (can('questions.create')): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('admin/questions.php')) ?>">Class question</a><?php endif; ?>
            <?php if (can('students.view')): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('admin/students.php')) ?>">Students</a><?php endif; ?>
        </div>
    </section>
<?php if ($showRoster): ?></div><?php endif; ?>

<?php admin_footer(); ?>
