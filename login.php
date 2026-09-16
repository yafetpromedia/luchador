<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/queries.php';

if (is_logged_in()) {
    redirect(post_login_path(current_user(), (string) ($_GET['next'] ?? '')));
}

$error = flash_get('error', '');
$requested = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
$next = safe_redirect_path($requested, 'admin/index.php');

if (is_post()) {
    require_csrf();
    if (login_is_locked()) {
        $error = 'Too many attempts. Please wait and try again.';
    } else {
        try {
            $user = authenticate_credentials(posted('username'), (string) ($_POST['password'] ?? ''));
            login_user($user);
            if (hash_is_default_password((string) $user['password'])) {
                mark_password_change_required((int) $user['id']);
                $_SESSION['must_change_password'] = true;
                $user['must_change_password'] = true;
            }
            $detail = user_display_name($user);
            if (($user['role'] ?? '') === 'student' && !empty($user['student_id'])) {
                $linked = student_by_id((int) $user['student_id']);
                if ($linked && !empty($linked['student_code'])) {
                    $detail = 'Student ID ' . $linked['student_code'];
                }
            }
            log_audit('user.login', 'user', (int) $user['id'], $detail);
            redirect(post_login_path($user, $requested));
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'disabled') {
                $error = 'This account is currently disabled. Please contact the class administrator.';
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · <?= e(class_name()) ?></title>
    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <?php site_font_links(); ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=49">
</head>
<body class="auth-body">
    <div class="auth-motion" aria-hidden="true">
        <span class="auth-mark"><?= e(class_grade()) ?></span>
        <span class="auth-glow auth-glow-a"></span>
        <span class="auth-glow auth-glow-b"></span>
    </div>
    <main class="auth-shell">
        <section class="auth-intro">
            <a class="brand" href="<?= e(url('index.php')) ?>">
                <span class="brand-text">
                    <strong><?= e(class_name()) ?></strong>
                    <span>Grade <?= e(class_grade()) ?></span>
                </span>
            </a>
            <p class="auth-kicker"><?= e(school_name()) ?></p>
            <h1>Welcome back.</h1>
            <p class="auth-lede">Sign in to the Grade <?= e(class_grade()) ?> class portal.</p>
        </section>
        <section class="auth-card">
            <h2>Sign in</h2>
            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" data-loading>
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" autocomplete="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-field">
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-controls="password" aria-pressed="false" aria-label="Show password">
                            <span data-show><?= icon('eye', 18) ?></span>
                            <span data-hide hidden><?= icon('eye-off', 18) ?></span>
                        </button>
                    </div>
                </div>
                <button class="btn auth-submit" type="submit">Sign in</button>
            </form>
            <p class="auth-hint">Use the username from the class administrator. Change a temporary password after the first sign-in.</p>
            <p><a class="text-link" href="<?= e(url('index.php')) ?>">Back to the class <?= icon('arrow-right', 16) ?></a></p>
        </section>
    </main>
    <script src="<?= e(url('assets/js/app.js')) ?>?v=9"></script>
</body>
</html>
