<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

admin_boot(null);

if (is_post()) {
    require_csrf();
    $action = posted('action');
    try {
        if ($action === 'password') {
            try {
                change_own_password(
                    (string) ($_POST['current_password'] ?? ''),
                    (string) ($_POST['new_password'] ?? ''),
                    (string) ($_POST['confirm_password'] ?? '')
                );
                flash_set('success', 'Password updated.');
            } catch (InvalidArgumentException $e) {
                flash_set('error', $e->getMessage());
            }
        } elseif ($action === 'profile') {
            require_permission('settings.manage');
            $keys = ['class_name','grade','school_name','academic_year','contact_email','contact_phone','contact_location','hero_tagline','hero_message','about_who','about_community','about_academic'];
            $values = [];
            foreach ($keys as $key) {
                $values[$key] = posted($key);
            }
            $values['grade'] = $values['grade'] !== '' ? $values['grade'] : '12';
            if (!empty($_FILES['hero_image']['name'])) {
                $upload = store_upload($_FILES['hero_image'], 'hero');
                if (!$upload['ok']) {
                    throw new InvalidArgumentException($upload['error']);
                }
                delete_upload(setting('hero_image'));
                $values['hero_image'] = $upload['path'];
            }
            save_settings($values);
            log_audit('settings.update', 'settings', null, 'Class identity');
            flash_set('success', 'Settings saved.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/settings.php');
}

admin_header('Settings', 'settings');
admin_page_head('Class identity, contact details, and the public homepage. Change your own password here.');
?>

<?php if (!empty(current_user()['must_change_password'])): ?>
    <div class="security-callout" role="alert">
        <h2>Security action required</h2>
        <p>You are using a temporary or default password. Change it before continuing.</p>
        <a class="btn" href="#account">Change password</a>
    </div>
<?php endif; ?>

<div class="panel" id="account">
    <h2>Account</h2>
    <p class="muted">Signed in as <?= e(user_display_name()) ?> (<?= e(current_user()['username'] ?? '') ?>). Change your password here. Use at least 10 characters.</p>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <div class="form-grid">
            <div class="form-group"><label class="req" for="current_password">Current password</label><input id="current_password" type="password" name="current_password" required autocomplete="current-password"></div>
            <div class="form-group"><label class="req" for="new_password">New password</label><input id="new_password" type="password" name="new_password" required minlength="10" autocomplete="new-password"></div>
            <div class="form-group"><label class="req" for="confirm_password">Confirm new password</label><input id="confirm_password" type="password" name="confirm_password" required minlength="10" autocomplete="new-password"></div>
        </div>
        <div class="form-actions" style="margin-top:1rem"><button class="btn" type="submit">Update password</button></div>
    </form>
</div>

<?php if (can('users.manage')): ?>
<div class="panel">
    <h2>Users & permissions</h2>
    <p class="muted">Create committee logins and choose what each person can manage. Super Admin access stays with you.</p>
    <a class="btn" href="<?= e(url('admin/users.php')) ?>">Open users & permissions</a>
</div>
<?php endif; ?>

<?php if (can('settings.manage')): ?>
<form method="post" enctype="multipart/form-data" data-loading>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="profile">

    <div class="panel">
        <h2>Class</h2>
        <div class="form-grid">
            <div class="form-group"><label class="req">Class name</label><input name="class_name" value="<?= e(setting('class_name')) ?>" required></div>
            <div class="form-group"><label class="req">Grade</label><input name="grade" value="<?= e(setting('grade')) ?>" required></div>
            <div class="form-group"><label class="req">School name</label><input name="school_name" value="<?= e(setting('school_name')) ?>" required></div>
            <div class="form-group"><label>Academic year</label><input name="academic_year" value="<?= e(setting('academic_year')) ?>" placeholder="Set when known"></div>
        </div>
    </div>

    <div class="panel">
        <h2>Contact</h2>
        <div class="form-grid">
            <div class="form-group"><label>Email</label><input name="contact_email" type="email" value="<?= e(setting('contact_email')) ?>"></div>
            <div class="form-group"><label>Phone</label><input name="contact_phone" value="<?= e(setting('contact_phone')) ?>"></div>
            <div class="form-group full"><label>Location</label><input name="contact_location" value="<?= e(setting('contact_location')) ?>" placeholder="Leave blank until the real location is set"></div>
        </div>
    </div>

    <div class="panel">
        <h2>Public site</h2>
        <div class="form-grid">
            <div class="form-group full"><label>Hero tagline</label><input name="hero_tagline" value="<?= e(setting('hero_tagline')) ?>"></div>
            <div class="form-group full"><label>Hero message</label><textarea name="hero_message"><?= e(setting('hero_message')) ?></textarea></div>
            <div class="form-group full"><label>Who we are</label><textarea name="about_who"><?= e(setting('about_who')) ?></textarea></div>
            <div class="form-group full"><label>Our community</label><textarea name="about_community"><?= e(setting('about_community')) ?></textarea></div>
            <div class="form-group full"><label>Academic excellence</label><textarea name="about_academic"><?= e(setting('about_academic')) ?></textarea></div>
            <div class="form-group full">
                <label>Hero photograph</label>
                <input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp,image/gif">
                <p class="muted">Optional. JPG, PNG, WEBP, or GIF up to 5 MB.</p>
            </div>
        </div>
        <div class="form-actions" style="margin-top:1rem"><button class="btn" type="submit">Save settings</button></div>
    </div>
</form>
<?php endif; ?>

<?php if (can('graduation.manage') || can('graduation.view')): ?>
<div class="panel">
    <h2>Graduation</h2>
    <p class="muted">Graduation date, title, and countdown are managed on the Graduation page. Do not invent a date.</p>
    <a class="btn btn-ghost" href="<?= e(url('admin/graduation.php')) ?>">Open graduation settings</a>
</div>
<?php endif; ?>

<?php if (can_any(['payments.view', 'payments.manage', 'payments.verify', 'payment_accounts.manage', 'payment_items.manage'])): ?>
<div class="panel">
    <h2>Payments</h2>
    <p class="muted">Manage payment items, class accounts, and receipt verification. Payment details stay private.</p>
    <a class="btn btn-ghost" href="<?= e(url('admin/payments.php')) ?>">Open payments</a>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
