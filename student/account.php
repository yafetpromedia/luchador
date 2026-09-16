<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';

student_boot();

$mustChange = !empty(current_user()['must_change_password']);

if (is_post()) {
    require_csrf();
    try {
        change_own_password(
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        flash_set('success', 'Password updated.');
        redirect('student/dashboard.php');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
        redirect('student/account.php');
    }
}

student_header('Account', 'account', lead: 'Password and sign-in.');
?>

<?php if ($mustChange): ?>
    <div class="security-callout" role="alert">
        <h2>Welcome to Luchador</h2>
        <p>Please change your temporary password before continuing.</p>
    </div>
<?php endif; ?>

<section class="panel" id="account">
    <h2>Change password</h2>
    <p class="muted">Signed in as <?= e(user_display_name()) ?> (<?= e(current_user()['username'] ?? '') ?>). Use at least <?= (int) password_min_length() ?> characters.</p>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-group full"><label class="req" for="current_password">Current password</label><input id="current_password" type="password" name="current_password" required autocomplete="current-password" placeholder="Current password"></div>
            <div class="form-group"><label class="req" for="new_password">New password</label><input id="new_password" type="password" name="new_password" required minlength="<?= (int) password_min_length() ?>" autocomplete="new-password" placeholder="At least <?= (int) password_min_length() ?> characters"></div>
            <div class="form-group"><label class="req" for="confirm_password">Confirm new password</label><input id="confirm_password" type="password" name="confirm_password" required minlength="<?= (int) password_min_length() ?>" autocomplete="new-password" placeholder="Repeat new password"></div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Update password</button>
            <a class="btn btn-ghost" href="<?= e(url('logout.php')) ?>">Logout</a>
        </div>
    </form>
</section>

<?php student_footer('account'); ?>
