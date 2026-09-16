<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

admin_boot(['graduation.view', 'graduation.manage']);

if (is_post()) {
    require_csrf();
    require_manage_on_post('graduation.manage');
    try {
        $oldDate = setting('graduation_date');
        save_settings([
            'graduation_date' => posted('graduation_date'),
            'graduation_title' => posted('graduation_title') ?: 'Graduation',
            'graduation_message' => posted('graduation_message'),
            'class_message' => posted('class_message'),
            'countdown_enabled' => posted('countdown_enabled') === '1' ? '1' : '0',
        ]);
        log_audit('settings.update', 'graduation', null, 'Graduation settings');
        $newDate = posted('graduation_date');
        if ($newDate !== '' && $newDate !== $oldDate) {
            notify_class([
                'type' => 'graduation.update',
                'title' => 'Graduation date set',
                'body' => format_date($newDate) ?: $newDate,
                'icon' => 'graduation',
                'target_type' => 'graduation',
                'target_id' => 1,
                'url_student' => 'student/graduation.php',
                'url_public' => 'index.php#graduation',
            ]);
        }
        flash_set('success', 'Graduation settings saved.');
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/graduation.php');
}

admin_header('Graduation', 'graduation');
admin_page_head('Leave the date empty until it is confirmed. Do not invent a date.');
$gradDate = setting('graduation_date');
$gradTitle = setting('graduation_title', 'Graduation');
?>

<div class="grad-preview">
    <p class="eyebrow"><?= e($gradTitle) ?></p>
    <?php if ($gradDate): ?>
        <?php render_countdown($gradDate); ?>
    <?php else: ?>
        <strong>Date not set yet</strong>
        <p>The public countdown stays off until a real date is saved.</p>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Graduation settings</h2>
    <?php if (can('graduation.manage')): ?>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-group"><label>Title</label><input name="graduation_title" value="<?= e(setting('graduation_title')) ?>"></div>
            <div class="form-group"><label>Graduation date</label><input type="date" name="graduation_date" value="<?= e(setting('graduation_date')) ?>"></div>
            <div class="form-group full"><label>Graduation message</label><textarea name="graduation_message"><?= e(setting('graduation_message')) ?></textarea></div>
            <div class="form-group full"><label>Class message</label><textarea name="class_message"><?= e(setting('class_message')) ?></textarea></div>
            <div class="form-group full">
                <label class="check"><input type="checkbox" name="countdown_enabled" value="1" <?= setting_bool('countdown_enabled') ? 'checked' : '' ?>> Enable countdown (requires a date)</label>
            </div>
        </div>
        <div class="form-actions" style="margin-top:1rem"><button class="btn" type="submit">Save graduation settings</button></div>
    </form>
    <?php else: ?>
        <p><strong><?= e(setting('graduation_title', 'Graduation')) ?></strong></p>
        <p><?= setting('graduation_date') ? e(format_date(setting('graduation_date'))) : 'Date not set yet.' ?></p>
        <p class="muted">You can view graduation details. Saving changes requires additional permission.</p>
    <?php endif; ?>
</div>

<?php admin_footer(); ?>
